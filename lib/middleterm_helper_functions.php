<?php

/**
 * Middle-term memory generation for StobeServer.
 *
 * Herika-aligned behavior:
 * - Runs periodically from live traffic.
 * - Consumes completed global memory_summary rows (not raw eventlog rows).
 * - Updates NPC extended_data.middle_term_memory as the source of truth.
 */

function stobeMiddleTermNormalizeKeyToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return $normalized;
}

function stobeMiddleTermCursorKey(string $npcName): string
{
    // Legacy conf cursor key retained for backwards compatibility.
    return 'MIDDLETERM_CURSOR_ROWID_' . stobeMiddleTermNormalizeKeyToken($npcName);
}

function stobeMiddleTermAllowedEventType(string $eventType): bool
{
    $type = strtolower(trim($eventType));
    if ($type === '') {
        return false;
    }
    $blocked = [
        'location',
        'infoloc',
        'context',
        'setconf',
        'status_msg',
        'npc_snapshot',
        'playerinfo',
    ];
    return !in_array($type, $blocked, true);
}

function stobeMiddleTermShouldRunCycle(string $eventType, int $gamets): bool
{
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return false;
    }
    if (!stobeMiddleTermAllowedEventType($eventType)) {
        return false;
    }

    if ($gamets <= 0) {
        return false;
    }

    return true;
}

function stobeMiddleTermTryLock(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $row = $db->fetchOne("SELECT pg_try_advisory_lock(937461) AS locked");
    if (!is_array($row)) {
        return false;
    }
    $raw = $row['locked'] ?? false;
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_numeric($raw)) {
        return intval($raw) === 1;
    }
    $normalized = strtolower(trim(strval($raw)));
    return in_array($normalized, ['t', 'true', '1', 'yes', 'on'], true);
}

function stobeMiddleTermUnlock(): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return;
    }
    $db->exec("SELECT pg_advisory_unlock(937461)");
}

function stobeMiddleTermNpcEnabled(array $npcData): bool
{
    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    foreach (['middle_term_enabled', 'MIDDLE_TERM_MEMORY_ENABLED'] as $key) {
        if (!array_key_exists($key, $extended)) {
            continue;
        }
        $raw = $extended[$key];
        if ($raw !== '' && $raw !== null) {
            return coerceBoolean($raw);
        }
    }

    return getNpcProfileBoolSetting(
        $npcData,
        ['middle_term_enabled', 'MIDDLE_TERM_MEMORY_ENABLED'],
        'MIDDLE_TERM_MEMORY_ENABLED',
        false
    );
}

function stobeMiddleTermFetchCandidates(int $limit = 64): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 256) {
        $limit = 256;
    }

    // Fetch a bounded candidate set and apply enablement logic in PHP.
    // This preserves profile-level fallback behavior in stobeMiddleTermNpcEnabled()
    // instead of requiring per-NPC extended_data flags to be pre-seeded.
    return $db->fetchAll(
        "SELECT id, name, profile_id, metadata, extended_data, gamets_last_updated
         FROM core_npc
         WHERE COALESCE(TRIM(name), '') <> ''
            AND LOWER(name) <> 'the narrator'
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT " . intval($limit)
    );
}

function stobeMiddleTermFetchNpcSummaryChunk(string $npcName, array $npcData, int $afterGamets, int $limit = 100): array
{
    $db = $GLOBALS['db'] ?? null;
    if (
        !$db
        || !function_exists('stobeRegularMemoryTableAvailable')
        || !stobeRegularMemoryTableAvailable()
        || !function_exists('stobeRegularMemorySummaryTableAvailable')
        || !stobeRegularMemorySummaryTableAvailable()
    ) {
        return [];
    }

    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return [];
    }

    if ($limit < 5) {
        $limit = 5;
    } elseif ($limit > 300) {
        $limit = 300;
    }

    $peopleExact = json_encode([$safeNpcName], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($peopleExact) || trim($peopleExact) === '') {
        $peopleExact = '["' . addslashes($safeNpcName) . '"]';
    }

    $jsonQuotedNpc = strtolower(json_encode($safeNpcName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    if ($jsonQuotedNpc === '') {
        $jsonQuotedNpc = '"' . strtolower($safeNpcName) . '"';
    }

    $scopeClause = '';
    $params = [
        max(0, $afterGamets),
        $peopleExact,
        $jsonQuotedNpc,
    ];
    if (function_exists('stobeRegularMemorySummaryScopeColumnAvailable') && stobeRegularMemorySummaryScopeColumnAvailable()) {
        $individualEnabled = function_exists('stobeRegularMemoryIndividualEnabledForNpc')
            ? stobeRegularMemoryIndividualEnabledForNpc($npcData)
            : false;
        if ($individualEnabled) {
            $scopeClause = "AND LOWER(COALESCE(scope, '')) = LOWER($4)";
            $params[] = $safeNpcName;
        } else {
            $scopeClause = "AND (scope IS NULL OR BTRIM(scope) = '' OR LOWER(BTRIM(scope)) = 'global')";
        }
    }

    $rows = $db->fetchAll(
        "SELECT
            id,
            people,
            summary,
            packed_message,
            localts,
            gamets_start,
            gamets_end,
            source_from_memory_id,
            source_to_memory_id,
            created_at
         FROM memory_summary
         WHERE summary IS NOT NULL
           AND BTRIM(summary) <> ''
           AND COALESCE(gamets_end, 0) > $1
           AND (
                LOWER(people) = LOWER($2)
                OR POSITION($3 IN LOWER(COALESCE(people, ''))) > 0
            )
            {$scopeClause}
         ORDER BY COALESCE(gamets_end, 0) DESC, created_at DESC, id DESC
         LIMIT " . intval($limit),
        $params
    );

    if (!is_array($rows) || count($rows) === 0) {
        return [];
    }

    // Herika query is newest-first, then consumed oldest->newest.
    return array_values(array_reverse($rows));
}

function stobeMiddleTermLastSummaryFromExtended(array $npcData): string
{
    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $rawList = $extended['middle_term_memory'] ?? [];
    if (!is_array($rawList) || count($rawList) === 0) {
        return '';
    }

    $maxGamets = stobeMiddleTermLastGametsFromExtended($npcData);
    if ($maxGamets > 0) {
        $entry = $rawList[strval($maxGamets)] ?? null;
        if (is_scalar($entry) && $entry !== null) {
            $text = trim(sanitizeForKenshi(strval($entry)));
            if ($text !== '') {
                return $text;
            }
        }
    }

    $values = array_reverse(array_values($rawList));
    foreach ($values as $entry) {
        if (!is_scalar($entry) || $entry === null) {
            continue;
        }
        $text = trim(sanitizeForKenshi(strval($entry)));
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

function stobeMiddleTermLastGametsFromExtended(array $npcData): int
{
    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $rawList = $extended['middle_term_memory'] ?? [];
    if (!is_array($rawList) || count($rawList) === 0) {
        return 0;
    }

    $maxGamets = 0;
    foreach ($rawList as $key => $entry) {
        if (!is_scalar($entry) || $entry === null) {
            continue;
        }
        $text = trim(sanitizeForKenshi(strval($entry)));
        if ($text === '') {
            continue;
        }
        $keyText = trim(strval($key));
        if ($keyText === '' || preg_match('/^-?\d+$/', $keyText) !== 1) {
            continue;
        }
        $candidate = intval($keyText);
        if ($candidate > $maxGamets) {
            $maxGamets = $candidate;
        }
    }

    return max(0, $maxGamets);
}

function stobeMiddleTermBuildContextHistory(array $summaryRows): string
{
    $chunks = [];
    foreach ($summaryRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $summary = trim(sanitizeForKenshi(strval($row['summary'] ?? '')));
        if ($summary === '') {
            continue;
        }
        $gamets = intval($row['gamets_end'] ?? ($row['gamets_start'] ?? 0));
        $chunks[] = "===\nMemory entry, date " . stobeGametsDateLabel($gamets) . "\n" . $summary;
    }

    return implode("\n\n", $chunks);
}

function stobeMiddleTermResolvePromptTemplate(array $keys, string $fallback): string
{
    if (!function_exists('stobeGetPromptTemplateValue')) {
        return $fallback;
    }

    foreach ($keys as $candidateKey) {
        $key = trim(strval($candidateKey));
        if ($key === '') {
            continue;
        }
        $candidateValue = stobeGetPromptTemplateValue($key, '');
        if (trim($candidateValue) !== '') {
            return $candidateValue;
        }
    }

    return $fallback;
}

function stobeMiddleTermGenerateSummary(string $npcName, array $npcData, array $summaryRows): array
{
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return ['ok' => false, 'reason' => 'missing_npc_name'];
    }
    if (count($summaryRows) === 0) {
        return ['ok' => false, 'reason' => 'no_summary_rows'];
    }

    $history = stobeMiddleTermBuildContextHistory($summaryRows);
    if ($history === '') {
        return ['ok' => false, 'reason' => 'empty_history'];
    }

    $previousSummary = stobeMiddleTermLastSummaryFromExtended($npcData);

    $defaultSystemPrompt
        = "You are a long-term narrative continuity summarizer for an improvised Kenshi universe chronicle.\n"
        . "- Always read ALL provided materials.\n"
        . "- Treat any **Previous Context History Summary** as the canonical prior unless anything in the new Context History explicitly supersedes it.\n"
        . "- Maintain in-universe tone and correct chronology. Do not invent facts outside the supplied context.\n"
        . "- When combining prior and new histories, you may compress the earlier parts of the prior summary.\n"
        . "- Maintain roughly 20-25 bullet points total in **Notable Events**. Older portions should be condensed into broader, grouped statements unless they describe major quest milestones, major character life events (e.g., death, intimacy, severe injury, transformation), or other pivotal story turns.\n"
        . "- Preserve continuity and references to major quests even when compressing earlier material.";
    $systemPrompt = stobeMiddleTermResolvePromptTemplate(
        ['middleterm_narrative_summarizer', 'middleterm_memory_summarizer'],
        $defaultSystemPrompt
    );

    $defaultRequestPrompt
        = "Main character in this logbook is {HERIKA_NAME}.\n"
        . "Task: Read **Context History** (newest session) and, if present, the **Previous Context History Summary** (prior canon). "
        . "Integrate them to produce an updated broad narrative strokes summary that preserves continuity. Summary sections:\n\n"
        . "- **Notable Events in Chronological Order:**\n"
        . "  - Provide ~10 bullet points from earliest to latest, reflecting the story so far.\n"
        . "  - Prefer facts already established in the previous summary; only revise if the new context clearly changes them.\n\n"
        . "- **Current Quest Progression and background:**\n"
        . "  - Name questlines, stages/milestones if stated, objectives completed/active, and motivations.\n"
        . "When generating entries, ensure that {HERIKA_NAME} - the protagonist - is actively present in the scene. "
        . "Any narrative content that occurs before {HERIKA_NAME}'s arrival or outside {HERIKA_NAME}'s perspective should be omitted, "
        . "reflect only events {HERIKA_NAME} directly witness or participate in.\n"
        . "If the resulting summary would exceed roughly 25 bullet points, merge or generalise older entries into broader grouped events. "
        . "Always retain explicit entries for major quest milestones, major character life events, or turning points.";
    $requestPromptTemplate = stobeMiddleTermResolvePromptTemplate(
        ['middleterm_narrative_request', 'middleterm_memory_request'],
        $defaultRequestPrompt
    );
    $requestPrompt = str_replace(['{HERIKA_NAME}', '#NPC_NAME#'], $safeNpcName, $requestPromptTemplate);
    $requestPrompt = str_replace('#CONTEXT_HISTORY#', $history, $requestPrompt);
    $requestPrompt = str_replace('#PREVIOUS_SUMMARY_BLOCK#', $previousSummary, $requestPrompt);

    $messages = [];
    $messages[] = ['role' => 'system', 'content' => $systemPrompt];
    if ($previousSummary !== '') {
        $messages[] = ['role' => 'user', 'content' => "# Previous Context History Summary:\n{$previousSummary}"];
    }
    $messages[] = ['role' => 'user', 'content' => "# Context History\n{$history}"];
    $messages[] = ['role' => 'user', 'content' => $requestPrompt];
    $messages[] = [
        'role' => 'user',
        'content' => 'Begin your answer with `### Notable Events in Chronological Order` and complete sections as instructed.',
    ];

    $enginePath = $GLOBALS['ENGINE_PATH'] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php');

    $llmConfig = getLlmConfigForNpcPurpose($npcData, 'middleterm');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_api_key'];
    }

    $raw = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $safeNpcName,
        'event_type' => 'middleterm_generate',
    ]);
    if ($raw === false) {
        return ['ok' => false, 'reason' => 'llm_failed'];
    }

    $summary = trim(sanitizeForKenshi(strval($raw)));
    if ($summary === '') {
        return ['ok' => false, 'reason' => 'llm_empty'];
    }

    return [
        'ok' => true,
        'summary' => $summary,
        'history_length' => strlen($history),
    ];
}

function stobeMiddleTermPersistSummary(string $npcName, array $npcData, array $summaryRows, string $summary): bool
{
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '' || trim($summary) === '' || count($summaryRows) === 0) {
        return false;
    }

    $last = $summaryRows[count($summaryRows) - 1];
    $lastLocalTs = intval($last['localts'] ?? time());
    if ($lastLocalTs <= 0) {
        $lastLocalTs = time();
    }

    $gametsEnd = intval($last['gamets_end'] ?? ($last['gamets_start'] ?? 0));
    if ($gametsEnd < 0) {
        $gametsEnd = 0;
    }

    $npcId = intval($npcData['id'] ?? 0);
    if ($npcId <= 0) {
        $lookup = getNpcData($safeNpcName);
        if (is_array($lookup)) {
            $npcId = intval($lookup['id'] ?? 0);
            if (!is_array($npcData) || count($npcData) === 0) {
                $npcData = $lookup;
            }
        }
    }
    if ($npcId <= 0) {
        stobeLogWarn('Middle-term summary generated but NPC id missing for extended_data update', [
            'npc_name' => $safeNpcName,
            'gamets_end' => $gametsEnd,
        ]);
        return false;
    }

    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $existing = $extended['middle_term_memory'] ?? [];
    $memoryMap = [];
    if (is_array($existing)) {
        foreach ($existing as $key => $entry) {
            if (!is_scalar($entry) || $entry === null) {
                continue;
            }
            $text = trim(sanitizeForKenshi(strval($entry)));
            if ($text === '') {
                continue;
            }
            $entryKey = strval($key);
            if ($entryKey === '' || strtolower($entryKey) === 'null') {
                $entryKey = strval(count($memoryMap) + 1);
            }
            $memoryMap[$entryKey] = truncatePromptValue($text, 3500);
        }
    }

    $summaryText = truncatePromptValue(trim(sanitizeForKenshi($summary)), 3500);
    $newKey = strval($gametsEnd > 0 ? $gametsEnd : max($lastLocalTs, time()));
    $memoryMap[$newKey] = $summaryText;

    $extended['middle_term_memory'] = $memoryMap;
    updateNpcById($npcId, ['extended_data' => $extended]);

    return true;
}

function stobeRunMiddleTermDaemonEntrypoint(
    int $timestamp,
    int $gamets,
    string $eventData = '[background_processor_tick]'
): void {
    stobeMaybeRunMiddleTermCycle('middleterm_daemon', $timestamp, $gamets, $eventData);
}

function stobeMaybeRunMiddleTermCycle(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData = ''
): void {
    if (!stobeMiddleTermShouldRunCycle($eventType, $gamets)) {
        return;
    }

    if (!stobeMiddleTermTryLock()) {
        return;
    }

    try {
        $processedCount = 0;
        $maxRows = 100;
        $candidates = stobeMiddleTermFetchCandidates(64);

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $npcName = normalizeParticipantNameToken(strval($candidate['name'] ?? ''));
            if ($npcName === '') {
                continue;
            }

            $npcData = getNpcData($npcName);
            if (!is_array($npcData)) {
                continue;
            }
            if (!stobeMiddleTermNpcEnabled($npcData)) {
                continue;
            }

            $lastGamets = stobeMiddleTermLastGametsFromExtended($npcData);
            $summaryRows = stobeMiddleTermFetchNpcSummaryChunk($npcName, $npcData, $lastGamets, $maxRows);
            $steadyRequiredRows = getSettingInt('MIDDLE_TERM_MIN_SUMMARY_ROWS', 20);
            $steadyRequiredRows = max(10, min(50, $steadyRequiredRows));
            $bootstrapRequiredRows = max(5, intval(ceil($steadyRequiredRows / 2)));
            $requiredRows = $lastGamets > 0 ? $steadyRequiredRows : $bootstrapRequiredRows;
            if (count($summaryRows) < $requiredRows) {
                continue;
            }

            $gen = stobeMiddleTermGenerateSummary($npcName, $npcData, $summaryRows);
            if (!boolval($gen['ok'] ?? false)) {
                stobeLogWarn('Middle-term summary generation skipped', [
                    'npc_name' => $npcName,
                    'reason' => strval($gen['reason'] ?? 'unknown'),
                    'summary_rows' => count($summaryRows),
                    'last_gamets' => $lastGamets,
                ]);
                continue;
            }

            $summary = strval($gen['summary'] ?? '');
            if ($summary === '') {
                continue;
            }

            $persisted = stobeMiddleTermPersistSummary($npcName, $npcData, $summaryRows, $summary);
            if (!$persisted) {
                stobeLogWarn('Middle-term summary persist failed', [
                    'npc_name' => $npcName,
                    'summary_rows' => count($summaryRows),
                    'last_gamets' => $lastGamets,
                ]);
                continue;
            }

            $lastSummaryRow = $summaryRows[count($summaryRows) - 1];
            $lastSummaryGamets = intval($lastSummaryRow['gamets_end'] ?? 0);

            stobeLogInfo('Middle-term summary generated', [
                'npc_name' => $npcName,
                'summary_rows' => count($summaryRows),
                'required_rows' => $requiredRows,
                'cursor_before' => $lastGamets,
                'cursor_after' => $lastSummaryGamets,
                'summary_length' => strlen($summary),
                'history_length' => intval($gen['history_length'] ?? 0),
                'event_type' => $eventType,
                'gamets' => $gamets,
            ]);

            $processedCount++;
        }

        if ($processedCount === 0) {
            stobeLogDebug('Middle-term cycle completed with no eligible NPC work', [
                'event_type' => $eventType,
                'gamets' => $gamets,
                'candidate_count' => count($candidates),
            ]);
        } else {
            stobeLogInfo('Middle-term cycle processed enabled NPCs', [
                'event_type' => $eventType,
                'gamets' => $gamets,
                'candidate_count' => count($candidates),
                'processed_count' => $processedCount,
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Middle-term cycle failed', [
            'event_type' => $eventType,
            'gamets' => $gamets,
        ]);
    } finally {
        stobeMiddleTermUnlock();
    }
}
