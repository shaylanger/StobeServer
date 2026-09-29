<?php

/**
 * Regular memory pipeline for StobeServer.
 *
 * Provides Herika-style flow:
 * - Capture dialogue/events into `memory` rows.
 * - Generate embeddings through txt2vec (`/embed`).
 * - Periodically pack memory rows into summarized `memory_summary` records.
 * - Recall relevant summaries with vector search for prompt injection.
 */

// Bridge the digest and surviving dialogue with completed summaries, never trimming live messages.
function stobeBuildShortTermMemoryContext(
    array|false $npcData,
    string $npcName,
    array &$historyMessages,
    int $currentGamets,
    bool $compactHistory,
    string $systemPrompt = ''
): string {
    $historyFloor = $currentGamets;
    foreach ($historyMessages as &$message) {
        // Internal timestamps must never reach a provider, including on disabled paths.
        $historyFloor = min($historyFloor, intval($message['_stobe_gamets'] ?? 0));
        unset($message['_stobe_gamets']);
    }
    unset($message);

    $safeNpc = normalizeParticipantNameToken($npcName);
    if (!is_array($npcData) || $safeNpc === '' || stobeIsNarratorName($safeNpc) || $historyFloor <= 0) {
        return '';
    }
    $profile = getCoreProfileMetadataForNpc($npcData);
    $overrides = stobeGetNpcRuntimeOverrideMap($npcData);
    $settings = ['SHORT_TERM_MEMORY_ENABLED' => false, 'SHORT_TERM_MEMORY_MAX' => 10];
    foreach ($settings as $key => $default) {
        foreach ([$profile, $overrides] as $layer) {
            if (array_key_exists($key, $layer) && $layer[$key] !== null && $layer[$key] !== '') {
                $settings[$key] = $layer[$key];
            }
        }
    }
    // Party/squad membership is not an exclusion: every non-Narrator NPC can opt in.
    if (!coerceBoolean($settings['SHORT_TERM_MEMORY_ENABLED'])
        || ($compactHistory && !getSettingBool('SHORT_TERM_MEMORY_IN_COMPACT_CHAT', true))) {
        return '';
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return '';
    }

    $afterGamets = 0;
    $middleTermBlock = stobePromptCollapseBlankLines(stobeBuildMiddleTermMemoryPromptBlock($npcData, $safeNpc));
    if ($middleTermBlock !== '' && str_contains($systemPrompt, $middleTermBlock)) {
        $afterGamets = stobeMiddleTermLastGametsFromExtended($npcData);
    }
    if ($afterGamets >= $historyFloor) {
        return '';
    }

    $maxSummaries = max(1, min(50, parseIntLike($settings['SHORT_TERM_MEMORY_MAX'], 10)));
    $quotedNpc = json_encode($safeNpc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $params = [$safeNpc, $quotedNpc, $afterGamets, $historyFloor];
    $scopeClause = '';
    $individual = stobeRegularMemoryIndividualEnabledForNpc($npcData);
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        if ($individual) {
            $scopeClause = 'AND LOWER(COALESCE(scope, \'\')) = LOWER($5)';
            $params[] = $safeNpc;
        } else {
            $scopeClause = "AND (scope IS NULL OR BTRIM(scope) = '' OR LOWER(BTRIM(scope)) = 'global')";
        }
    } elseif ($individual) {
        // Old installations without scoped summaries cannot provide an individual bank safely.
        return '';
    }
    $rows = $db->fetchAll(
        "SELECT summary, gamets_end
         FROM memory_summary
         WHERE summary IS NOT NULL AND BTRIM(summary) <> ''
           AND (LOWER(people) = LOWER($1) OR POSITION(LOWER($2) IN LOWER(COALESCE(people, ''))) > 0)
           AND (COALESCE(gamets_start, 0) > $3 OR $3 = 0)
           AND gamets_end > $3 AND gamets_end < $4
           AND gamets_end >= COALESCE(gamets_start, 0)
           {$scopeClause}
         ORDER BY gamets_end DESC, id DESC
         LIMIT " . $maxSummaries,
        $params
    );
    $entries = [];
    $seen = [];
    foreach (array_reverse($rows) as $row) {
        $summary = trim(sanitizeForKenshi(strval($row['summary'] ?? '')));
        if ($summary === '' || isset($seen[$summary])) {
            continue;
        }
        $seen[$summary] = true;
        $entries[] = 'Memory entry, ' . stobeGametsDateLabel(intval($row['gamets_end'])) . "\n"
            . stobePromptXmlEscape(truncatePromptValue($summary, 1400));
    }
    if ($entries === []) {
        return '';
    }
    return "<short_term_memory>\nRecent past events (before the live conversation):\n"
        . implode("\n\n", $entries) . "\n</short_term_memory>";
}

function stobeRegularMemoryNormalizeKeyToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return $normalized;
}

function stobeRegularMemoryCursorKey(string $peopleKey): string
{
    $normalized = strtolower(trim($peopleKey));
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return 'MEMORY_CURSOR_ID_' . md5($normalized);
}

function stobeRegularMemoryAllowedEventType(string $eventType): bool
{
    $type = strtolower(trim($eventType));
    if ($type === '') {
        return false;
    }
    $allow = [
        'inputtext',
        'inputtext_s',
        'injection',
        'chat',
        'rechat',
        'bored',
        'action',
        'diary',
        'location',
        'combat_start',
        'combat_end',
        'major_damage',
        'limb_loss',
        'horn_cut',
        'knockout',
        'recovered',
        'death',
        'slavery',
        'enslaved',
        'freed_slave',
        'imprisonment',
        'healing',
        'trade',
        'item_pickup',
        'looting',
        'carry',
        'predation',
        'eat',
        'build',
        'dismantle',
        'lockpicked',
        'lockpiked',
    ];
    return in_array($type, $allow, true);
}

function stobeRegularMemoryRunIntervalGamets(): int
{
    // Kenshi gamets are TimeOfDay seconds, so 1 in-game hour = 3600 gamets.
    // Keep MEMORY_AUTO_CREATE_SUMMARY_INTERVAL semantics in in-game hours.
    $interval = getSettingInt('MEMORY_AUTO_CREATE_SUMMARY_INTERVAL', 6);
    if ($interval < 1) {
        $interval = 1;
    } elseif ($interval > 720) {
        $interval = 720;
    }
    return $interval * 3600;
}

function stobeRegularMemorySummaryMinEvents(): int
{
    $minEvents = getSettingInt('AUTO_CREATE_SUMMARY_MIN_EVENTS', 5);
    if ($minEvents < 1) {
        $minEvents = 1;
    } elseif ($minEvents > 50) {
        $minEvents = 50;
    }
    return $minEvents;
}

function stobeRegularMemoryOneHourGamets(): int
{
    return 3600;
}

function stobeRegularMemoryLatestCompletedGlobalSummaryGamets(): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return 0;
    }

    $scopeClause = '';
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $scopeClause = "AND (scope IS NULL OR BTRIM(scope) = '' OR LOWER(BTRIM(scope)) = 'global')";
    }

    $row = $db->fetchOne(
        "SELECT COALESCE(MAX(gamets_end), 0) AS max_gamets
         FROM memory_summary
         WHERE COALESCE(BTRIM(summary), '') <> ''
           {$scopeClause}"
    );
    return max(0, intval($row['max_gamets'] ?? 0));
}

function stobeRegularMemoryShouldRunCycle(string $eventType, int $gamets): bool
{
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return false;
    }
    if (!stobeRegularMemoryAllowedEventType($eventType)) {
        return false;
    }

    if ($gamets <= 0) {
        return false;
    }

    $lastSummaryGamets = stobeRegularMemoryLatestCompletedGlobalSummaryGamets();
    if ($gamets <= $lastSummaryGamets) {
        return false;
    }

    $pfi = stobeRegularMemoryRunIntervalGamets();
    return ($gamets - $lastSummaryGamets) > $pfi;
}

function stobeRegularMemoryTryLock(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $row = $db->fetchOne("SELECT pg_try_advisory_lock(937463) AS locked");
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

function stobeRegularMemoryUnlock(): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return;
    }
    $db->exec("SELECT pg_advisory_unlock(937463)");
}

function stobeRegularMemoryTableAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }
    $row = $db->fetchOne("SELECT to_regclass('public.memory') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeRegularMemorySummaryTableAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }
    $row = $db->fetchOne("SELECT to_regclass('public.memory_summary') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeRegularMemorySummaryScopeColumnAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        $available = false;
        return false;
    }

    $row = $db->fetchOne(
        "SELECT 1 AS ok
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'memory_summary'
           AND column_name = 'scope'
         LIMIT 1"
    );
    $available = is_array($row);
    return boolval($available);
}

function stobeRegularMemoryLocationColumnAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        $available = false;
        return false;
    }

    $row = $db->fetchOne(
        "SELECT 1 AS ok
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'memory'
           AND column_name = 'location'
         LIMIT 1"
    );
    $available = is_array($row);
    return boolval($available);
}

function stobeRegularMemoryIndividualSummaryThreshold(): int
{
    return 2;
}

function stobeRegularMemoryIndividualEnabledForNpc(array|false $npcData): bool
{
    if (!is_array($npcData)) {
        return false;
    }

    $metadata = normalizeCoreNpcMetadata($npcData['metadata'] ?? []);
    if (array_key_exists('INDIVIDUAL_MEMORY_ENABLED', $metadata)) {
        $raw = $metadata['INDIVIDUAL_MEMORY_ENABLED'];
        if ($raw !== '' && $raw !== null) {
            return coerceBoolean($raw);
        }
    }

    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    foreach (['individual_memory_enabled', 'INDIVIDUAL_MEMORY_ENABLED'] as $key) {
        if (!array_key_exists($key, $extended)) {
            continue;
        }
        $raw = $extended[$key];
        if ($raw !== '' && $raw !== null) {
            return coerceBoolean($raw);
        }
    }

    return false;
}

function stobeRegularMemoryBuildIndividualBioContext(array $npcRow): string
{
    $fields = [
        'core' => 'Core',
        'backstory' => 'Static Bio',
        'npc_static_bio' => 'Static Bio',
        'personality' => 'Personality',
        'goals' => 'Goals',
        'speechstyle' => 'Speech Style',
    ];

    $lines = [];
    $seenLabels = [];
    foreach ($fields as $key => $label) {
        $value = trim(strval($npcRow[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if ($label === 'Static Bio' && isset($seenLabels[$label])) {
            continue;
        }
        $seenLabels[$label] = true;
        $lines[] = "- {$label}: {$value}";
    }

    return implode("\n", $lines);
}

function stobeRegularMemoryParseDialogueData(string $eventData): array
{
    $source = trim($eventData);
    if ($source === '') {
        return ['speaker' => '', 'target' => '', 'message' => ''];
    }

    $target = '';
    if (preg_match('/\(talking to:\s*([^\)]+)\)/i', $source, $matches) === 1) {
        $target = normalizeParticipantNameToken(trim(strval($matches[1] ?? '')));
        $source = trim(preg_replace('/\s*\(talking to:\s*[^\)]+\)\s*/i', ' ', $source) ?? $source);
    }

    $speaker = '';
    $message = $source;
    $parts = explode(': ', $source, 2);
    if (count($parts) === 2) {
        $speaker = normalizeParticipantNameToken(strval($parts[0] ?? ''));
        $message = trim(strval($parts[1] ?? ''));
    } else {
        $colonPos = strpos($source, ':');
        if ($colonPos !== false && $colonPos > 0) {
            $speaker = normalizeParticipantNameToken(substr($source, 0, $colonPos));
            $message = trim(substr($source, $colonPos + 1));
        }
    }

    return [
        'speaker' => $speaker,
        'target' => $target,
        'message' => trim($message),
    ];
}

function stobeRegularMemoryDecodePeopleKey(string $peopleKey): array
{
    $value = trim($peopleKey);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        $namesByKey = [];
        foreach ($decoded as $entry) {
            if (!is_scalar($entry) || $entry === null) {
                continue;
            }
            $name = normalizeParticipantNameToken(strval($entry));
            if ($name === '') {
                continue;
            }
            $namesByKey[strtolower($name)] = $name;
        }
        if (count($namesByKey) > 0) {
            ksort($namesByKey);
            return array_values($namesByKey);
        }
    }

    $single = normalizeParticipantNameToken($value);
    return $single !== '' ? [$single] : [];
}

function stobeRegularMemoryBuildPeopleNames(string $peopleRaw, string $speaker, string $target): array
{
    $namesByKey = [];
    $addName = static function (string $rawName) use (&$namesByKey): void {
        $name = normalizeParticipantNameToken($rawName);
        if ($name === '' || strcasecmp($name, 'The Narrator') === 0) {
            return;
        }
        $namesByKey[strtolower($name)] = $name;
    };

    $addName($speaker);
    $addName($target);

    // Prefer direct conversation participants for stable grouping keys.
    // Fallback to raw people roster only when speaker/target are unavailable.
    if (count($namesByKey) === 0 && trim($peopleRaw) !== '') {
        $decoded = json_decode($peopleRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (!is_scalar($entry) || $entry === null) {
                    continue;
                }
                $addName(strval($entry));
            }
        } else {
            $addName($peopleRaw);
        }
    }

    if (count($namesByKey) === 0) {
        return [];
    }

    ksort($namesByKey);
    return array_values($namesByKey);
}

function stobeRegularMemoryBuildPeopleKey(array $names): string
{
    $clean = [];
    foreach ($names as $name) {
        if (!is_scalar($name) || $name === null) {
            continue;
        }
        $normalized = normalizeParticipantNameToken(strval($name));
        if ($normalized === '') {
            continue;
        }
        $clean[strtolower($normalized)] = $normalized;
    }

    if (count($clean) === 0) {
        return '[]';
    }

    ksort($clean);
    $ordered = array_values($clean);
    $encoded = json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || trim($encoded) === '') {
        return '[]';
    }
    return $encoded;
}

function stobeRegularMemoryPeopleLabel(string $peopleKey): string
{
    $names = stobeRegularMemoryDecodePeopleKey($peopleKey);
    if (count($names) === 0) {
        return '';
    }
    return implode(', ', $names);
}

function stobeRegularMemoryNormalizeLegacyPeopleKeys(int $limit = 1200): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        return 0;
    }

    if ($limit < 10) {
        $limit = 10;
    } elseif ($limit > 10000) {
        $limit = 10000;
    }

    $rows = $db->fetchAll(
        "SELECT id, people, content
         FROM memory
         WHERE id > 0
         ORDER BY id ASC
         LIMIT " . intval($limit)
    );

    $updated = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $currentPeople = trim(strval($row['people'] ?? ''));
        $content = trim(strval($row['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $parsed = stobeRegularMemoryParseDialogueData($content);
        $speaker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
        $target = normalizeParticipantNameToken(strval($parsed['target'] ?? ''));

        if ($speaker === '' && $target === '') {
            continue;
        }

        $normalized = stobeRegularMemoryBuildPeopleNames('', $speaker, $target);
        $newPeople = stobeRegularMemoryBuildPeopleKey($normalized);
        if ($newPeople === '' || $newPeople === '[]') {
            continue;
        }
        if (strcasecmp($currentPeople, $newPeople) === 0) {
            continue;
        }

        $ok = $db->exec(
            "UPDATE memory
             SET people = $1
             WHERE id = $2",
            [$newPeople, $id]
        );
        if ($ok !== false) {
            $updated++;
        }
    }

    if ($updated > 0) {
        stobeLogInfo('Normalized legacy memory people keys', [
            'updated' => $updated,
            'limit' => $limit,
        ]);
    }

    return $updated;
}

function stobeRegularMemoryNormalizeEmbeddingVector(mixed $decoded): array
{
    $vector = [];
    if (is_array($decoded)) {
        if (isset($decoded['embedding']) && is_array($decoded['embedding'])) {
            $vector = $decoded['embedding'];
        } else {
            $vector = $decoded;
        }
    }

    $normalized = [];
    foreach ($vector as $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $floatVal = floatval($value);
        if (!is_finite($floatVal)) {
            continue;
        }
        $normalized[] = $floatVal;
    }

    if (count($normalized) < 8) {
        return [];
    }
    return $normalized;
}

function stobeRegularMemoryEmbedText(string $text): array
{
    if (!getMemoryUseText2Vec()) {
        return [];
    }

    $payload = trim($text);
    if ($payload === '') {
        return [];
    }
    $payload = truncatePromptValue($payload, 2200);

    $url = rtrim(getMemoryTxtaiUrl(), '/') . '/embed';
    $body = json_encode(['text' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return [];
    }

    $timeout = isset($GLOBALS['HTTP_TIMEOUT']) ? intval($GLOBALS['HTTP_TIMEOUT']) : 15;
    if ($timeout < 4) {
        $timeout = 4;
    } elseif ($timeout > 30) {
        $timeout = 30;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || trim($raw) === '') {
        stobeLogWarn('Regular memory embedding request failed', ['url' => $url]);
        return [];
    }

    $decoded = json_decode($raw, true);
    $vector = stobeRegularMemoryNormalizeEmbeddingVector($decoded);
    if (count($vector) === 0) {
        stobeLogWarn('Regular memory embedding response empty/invalid', [
            'url' => $url,
            'response_preview' => substr($raw, 0, 180),
        ]);
    }
    return $vector;
}

function stobeRegularMemoryVectorLiteral(array $embedding): string
{
    if (count($embedding) === 0) {
        return '';
    }
    $parts = [];
    foreach ($embedding as $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $parts[] = rtrim(rtrim(sprintf('%.10F', floatval($value)), '0'), '.');
    }
    if (count($parts) === 0) {
        return '';
    }
    return '[' . implode(',', $parts) . ']';
}

function stobeRegularMemoryStoreRow(
    string $peopleKey,
    string $content,
    string $eventType,
    int $gamets,
    int $localts,
    string $location = ''
): bool {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        return false;
    }

    $safePeople = trim($peopleKey);
    $safeContent = trim(sanitizeForKenshi($content));
    $safeEventType = strtolower(trim($eventType));
    $safeLocation = trim(sanitizeForKenshi($location));
    if ($safePeople === '' || $safePeople === '[]' || $safeContent === '' || $safeEventType === '') {
        return false;
    }

    $dupeCutoff = max(0, $localts - 2);
    $duplicate = $db->fetchOne(
        "SELECT id
         FROM memory
         WHERE LOWER(people) = LOWER($1)
           AND event_type = $2
           AND content = $3
           AND localts >= $4
         ORDER BY id DESC
         LIMIT 1",
        [$safePeople, $safeEventType, $safeContent, $dupeCutoff]
    );
    if (is_array($duplicate) && intval($duplicate['id'] ?? 0) > 0) {
        return false;
    }

    $embedding = stobeRegularMemoryEmbedText($safeContent);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);

    if (stobeRegularMemoryLocationColumnAvailable()) {
        $result = $db->exec(
            "INSERT INTO memory (
                people,
                content,
                embedding,
                event_type,
                gamets,
                localts,
                location,
                created_at
            ) VALUES (
                $1,
                $2,
                CASE WHEN $3 = '' THEN NULL ELSE $3::vector END,
                $4,
                $5,
                $6,
                $7,
                NOW()
            )",
            [
                $safePeople,
                truncatePromptValue($safeContent, 2800),
                $vectorLiteral,
                $safeEventType,
                max(0, $gamets),
                max(0, $localts),
                truncatePromptValue($safeLocation, 512),
            ]
        );
    } else {
        $result = $db->exec(
            "INSERT INTO memory (
                people,
                content,
                embedding,
                event_type,
                gamets,
                localts,
                created_at
            ) VALUES (
                $1,
                $2,
                CASE WHEN $3 = '' THEN NULL ELSE $3::vector END,
                $4,
                $5,
                $6,
                NOW()
            )",
            [
                $safePeople,
                truncatePromptValue($safeContent, 2800),
                $vectorLiteral,
                $safeEventType,
                max(0, $gamets),
                max(0, $localts),
            ]
        );
    }

    return $result !== false;
}

function stobeMemoryTrackEvent(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData,
    string $people = '',
    string $location = '',
    int $localts = 0
): int {
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return 0;
    }
    if (!stobeRegularMemoryAllowedEventType($eventType)) {
        return 0;
    }

    $safeType = strtolower(trim($eventType));
    $parsed = stobeRegularMemoryParseDialogueData($eventData);
    $speaker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
    $target = normalizeParticipantNameToken(strval($parsed['target'] ?? ''));
    $message = trim(strval($parsed['message'] ?? ''));
    if ($message === '') {
        $message = trim(sanitizeForKenshi($eventData));
    }
    if ($message === '') {
        return 0;
    }

    $line = $message;
    if ($speaker !== '') {
        $line = $speaker . ': ' . $message;
    }
    if ($target !== '') {
        $line .= ' (talking to: ' . $target . ')';
    }

    $peopleNames = stobeRegularMemoryBuildPeopleNames($people, $speaker, $target);
    if (count($peopleNames) === 0) {
        return 0;
    }
    $peopleKey = stobeRegularMemoryBuildPeopleKey($peopleNames);
    if ($peopleKey === '' || $peopleKey === '[]') {
        return 0;
    }

    $safeLocalTs = $localts > 0 ? $localts : time();
    $stored = stobeRegularMemoryStoreRow($peopleKey, $line, $safeType, $gamets, $safeLocalTs, $location) ? 1 : 0;

    if ($stored > 0) {
        stobeLogDebug('Regular memory rows stored from event', [
            'event_type' => $safeType,
            'stored' => $stored,
            'people' => $peopleKey,
            'people_count' => count($peopleNames),
            'speaker' => $speaker,
            'target' => $target,
            'gamets' => $gamets,
        ]);
    }

    return $stored;
}

function stobeRegularMemoryFetchPeopleCandidates(int $limit = 64): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        return [];
    }
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 256) {
        $limit = 256;
    }

    return $db->fetchAll(
        "SELECT people, MAX(id) AS last_memory_id, COUNT(*) AS row_count
         FROM memory
         WHERE COALESCE(BTRIM(people), '') <> ''
           AND BTRIM(people) <> '[]'
         GROUP BY people
         ORDER BY MAX(id) DESC
         LIMIT " . intval($limit)
    );
}

function stobeRegularMemoryFetchRowsAfterCursor(string $peopleKey, int $afterId, int $limit = 240): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        return [];
    }
    $safePeople = trim($peopleKey);
    if ($safePeople === '' || $safePeople === '[]') {
        return [];
    }
    if ($limit < 10) {
        $limit = 10;
    } elseif ($limit > 500) {
        $limit = 500;
    }

    return $db->fetchAll(
        "SELECT id, people, content, event_type, gamets, localts, created_at
         FROM memory
         WHERE LOWER(people) = LOWER($1)
           AND id > $2
         ORDER BY id ASC
         LIMIT " . intval($limit),
        [$safePeople, max(0, $afterId)]
    );
}

function stobeRegularMemoryBuildPackedMessage(array $rows): string
{
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $content = trim(sanitizeForKenshi(strval($row['content'] ?? '')));
        if ($content === '') {
            continue;
        }
        $eventType = strtolower(trim(strval($row['event_type'] ?? 'memory')));
        $gamets = intval($row['gamets'] ?? 0);
        $line = '[' . $eventType . ' @ ' . stobeGametsDateLabel($gamets) . '] ' . $content;
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function stobeRegularMemoryFetchLatestCompletedSummary(string $peopleKey, int $beforeId = 0): string
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return '';
    }
    $safePeople = trim($peopleKey);
    if ($safePeople === '' || $safePeople === '[]') {
        return '';
    }
    $scopeClause = '';
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $scopeClause = "AND (scope IS NULL OR BTRIM(scope) = '' OR LOWER(BTRIM(scope)) = 'global')";
    }
    $beforeClause = '';
    $params = [$safePeople];
    if ($beforeId > 0) {
        $beforeClause = "AND id < $2";
        $params[] = $beforeId;
    }
    $prevRow = $db->fetchOne(
        "SELECT summary
         FROM memory_summary
         WHERE LOWER(people) = LOWER($1)
           {$scopeClause}
           AND summary IS NOT NULL
           AND BTRIM(summary) <> ''
           {$beforeClause}
         ORDER BY id DESC
         LIMIT 1",
        $params
    );
    if (!is_array($prevRow)) {
        return '';
    }
    return trim(sanitizeForKenshi(strval($prevRow['summary'] ?? '')));
}

function stobeRegularMemoryNormalizePackedForLlm(string $packed): string
{
    $chunks = preg_split('/\n\s*\n/u', trim($packed)) ?: [];
    $out = [];
    $seenSpeech = [];
    $lastKey = '';
    $lastIndex = -1;
    $repeatCount = 1;

    foreach ($chunks as $chunk) {
        $line = trim(preg_replace('/\s+/u', ' ', strval($chunk)) ?? strval($chunk));
        if ($line === '') {
            continue;
        }
        $eventType = 'memory';
        $content = $line;
        if (preg_match('/^\[([^\]@]+)(?:\s+@[^\]]+)?\]\s*(.*)$/iu', $line, $m) === 1) {
            $eventType = strtolower(trim(strval($m[1] ?? 'memory')));
            $content = trim(strval($m[2] ?? ''));
        }
        $contentKey = strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if (in_array($eventType, ['chat', 'inputtext', 'inputtext_s'], true)) {
            if (isset($seenSpeech[$contentKey])) {
                continue;
            }
            $seenSpeech[$contentKey] = true;
        }
        $key = $eventType . '|' . $contentKey;
        if ($lastIndex >= 0 && $key === $lastKey) {
            $repeatCount++;
            $out[$lastIndex] = '[' . $eventType . '] ' . $content . ' (repeated ' . $repeatCount . ' times)';
            continue;
        }
        $repeatCount = 1;
        $lastKey = $key;
        $out[] = '[' . $eventType . '] ' . $content;
        $lastIndex = count($out) - 1;
    }
    return implode("\n\n", $out);
}

function stobeRegularMemoryDeterministicRoutineSummary(string $packed, string $previousSummary = ''): string
{
    $chunks = preg_split('/\n\s*\n/u', trim($packed)) ?: [];
    if (count($chunks) === 0) {
        return '';
    }
    $routineTypes = ['healing', 'eat', 'item_pickup', 'looting', 'location', 'build', 'dismantle', 'lockpicked', 'lockpiked'];
    $unique = [];
    foreach ($chunks as $chunk) {
        $line = trim(strval($chunk));
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/iu', $line, $m) !== 1) {
            return '';
        }
        $eventType = strtolower(trim(strval($m[1] ?? '')));
        if (!in_array($eventType, $routineTypes, true)) {
            return '';
        }
        $content = trim(strval($m[2] ?? ''));
        $key = $eventType . '|' . strtolower($content);
        $unique[$key] = '[' . $eventType . '] ' . $content;
    }
    if ((count($chunks) < 4 && preg_match('/\(repeated\s+[4-9][0-9]*\s+times\)/i', $packed) !== 1) || count($unique) > 3) {
        return '';
    }
    $routine = 'Recent routine events: ' . implode('; ', array_values($unique)) . '.';
    $previous = trim($previousSummary);
    if ($previous !== '') {
        if (stripos($previous, $routine) !== false) {
            return truncatePromptValue($previous, 3500);
        }
        return truncatePromptValue($previous . ' ' . $routine, 3500);
    }
    return truncatePromptValue($routine, 3500);
}

function stobeRegularMemoryGenerateSummaryFromPacked(
    string $peopleKey,
    string $packedMessage,
    string $previousSummary = '',
    int $gametsStart = 0,
    int $gametsEnd = 0
): string {
    $packed = stobeRegularMemoryNormalizePackedForLlm($packedMessage);
    if ($packed === '') {
        return '';
    }

    $deterministicRoutine = stobeRegularMemoryDeterministicRoutineSummary($packed, $previousSummary);
    if ($deterministicRoutine !== '') {
        stobeLogDebug('Regular memory routine pack compacted without LLM', [
            'people' => $peopleKey,
            'packed_length' => strlen($packed),
            'summary_length' => strlen($deterministicRoutine),
        ]);
        return $deterministicRoutine;
    }

    $peopleLabel = stobeRegularMemoryPeopleLabel($peopleKey);
    if ($peopleLabel === '') {
        $peopleLabel = '(unknown group)';
    }

    $defaultSystemPrompt = "Create selective long-term memory for Kenshi characters. "
        . "Preserve events that would actually matter later: deaths, severe injuries, rescues, betrayals, recruitment or departures, relationship changes, promises and plans, debts, arguments, discoveries, important trades or gifts, faction encounters, major travel milestones, personal revelations, repeated preferences, fears, goals, and emotionally unusual moments. "
        . "Preserve who was involved and where it happened when that place matters. Keep unresolved plans, promises, grudges, obligations, and goals explicit so they can be recalled later. "
        . "Compress or omit routine greetings, repetitive small talk, ordinary status spam, repeated movement updates, and mundane events unless repetition itself establishes a habit or preference. "
        . "Do not make every event equally important. Maintain chronological continuity, never invent events, and do not foreshadow events that have not happened.";
    $systemPrompt = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('regular_memory_summarizer', $defaultSystemPrompt)
        : $defaultSystemPrompt;
    $systemPrompt = rtrim($systemPrompt) . "\n\nMemory selection rules: preserve emotionally significant/unusual events, relationship changes, deaths, injuries, rescues, betrayals, promises, plans, obligations, arguments, discoveries, meaningful gifts/trades, faction encounters, important places, personal revelations, repeated preferences/habits, fears and goals. Keep unresolved commitments explicit. Compress routine greetings, repetitive small talk, ordinary status/movement spam and low-value repetition unless repetition itself is meaningful. Never invent events or future outcomes.";

    $userPrompt = "<regular_memory_request>\n"
        . "  <people>" . (function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($peopleLabel) : $peopleLabel) . "</people>\n";
    $startGamets = max(0, intval($gametsStart));
    $endGamets = max($startGamets, intval($gametsEnd));
    if ($startGamets > 0 || $endGamets > 0) {
        $windowStart = $startGamets > 0 ? stobeGametsDateLabel($startGamets) : '';
        $windowEnd = $endGamets > 0 ? stobeGametsDateLabel($endGamets) : '';
        $windowStartEscaped = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($windowStart) : $windowStart;
        $windowEndEscaped = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($windowEnd) : $windowEnd;
        $userPrompt .= "  <memory_window>\n";
        if ($windowStartEscaped !== '') {
            $userPrompt .= "    <start>{$windowStartEscaped}</start>\n";
        }
        if ($windowEndEscaped !== '') {
            $userPrompt .= "    <end>{$windowEndEscaped}</end>\n";
        }
        $userPrompt .= "  </memory_window>\n";
    }
    if ($previousSummary !== '') {
        $escapedPrev = function_exists('stobePromptXmlEscape')
            ? stobePromptXmlEscape($previousSummary)
            : $previousSummary;
        $userPrompt .= "  <previous_summary>{$escapedPrev}</previous_summary>\n";
    }
    $escapedPacked = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($packed) : $packed;
    $userPrompt .= "  <packed_events>{$escapedPacked}</packed_events>\n"
        . "  <instruction>Create an updated selective memory summary for future retrieval. Keep durable personal facts, relationship changes, important places, unresolved plans/promises, injuries, conflicts, rescues, losses, discoveries, and meaningful preferences. Compress routine or repetitive chatter aggressively. Keep chronology using memory_window and event order, and never include raw gamets or numeric timestamp IDs in the summary text.</instruction>\n"
        . "</regular_memory_request>";

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php');
    $llmConfig = getLlmConfigForNpcPurpose(false, 'middleterm');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return truncatePromptValue($packed, 2500);
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];
    $raw = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $peopleLabel,
        'event_type' => 'memory_summary_generate',
    ]);
    if ($raw === false) {
        return truncatePromptValue($packed, 2500);
    }

    $summary = trim(sanitizeForKenshi(strval($raw)));
    if ($summary === '') {
        return truncatePromptValue($packed, 2500);
    }
    $summary = preg_replace('/\bat\s+gamet[s]?\s*[:=]?\s*\d+\b/iu', '', $summary) ?? $summary;
    $summary = preg_replace('/\bgamet[s]?\s*[:=]\s*\d+\b/iu', '', $summary) ?? $summary;
    $summary = preg_replace('/\(\s*gamet[s]?\s*[:=]?\s*\d+\s*\)/iu', '', $summary) ?? $summary;
    $summary = preg_replace('/\s{2,}/u', ' ', $summary) ?? $summary;
    $summary = trim($summary);
    if ($summary === '') {
        return truncatePromptValue($packed, 2500);
    }
    return truncatePromptValue($summary, 3500);
}

function stobeRegularMemoryGenerateSummary(string $peopleKey, array $rows): string
{
    $packed = trim(stobeRegularMemoryBuildPackedMessage($rows));
    if ($packed === '') {
        return '';
    }
    $previous = stobeRegularMemoryFetchLatestCompletedSummary($peopleKey, 0);
    $first = $rows[0] ?? [];
    $last = $rows[count($rows) - 1] ?? [];
    $gametsStart = max(0, intval($first['gamets'] ?? 0));
    $gametsEnd = max($gametsStart, intval($last['gamets'] ?? $gametsStart));
    return stobeRegularMemoryGenerateSummaryFromPacked(
        $peopleKey,
        $packed,
        $previous,
        $gametsStart,
        $gametsEnd
    );
}

function stobeRegularMemoryQueuePendingSummary(string $peopleKey, array $rows): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return false;
    }
    if (count($rows) === 0) {
        return false;
    }

    $safePeople = trim($peopleKey);
    if ($safePeople === '' || $safePeople === '[]') {
        return false;
    }

    $first = $rows[0];
    $last = $rows[count($rows) - 1];
    $fromId = intval($first['id'] ?? 0);
    $toId = intval($last['id'] ?? 0);
    if ($fromId <= 0 || $toId <= 0 || $toId < $fromId) {
        return false;
    }

    $scopePendingClause = '';
    $scopeInsertColumn = '';
    $scopeInsertParam = [];
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $scopePendingClause = "AND LOWER(COALESCE(scope, '')) = 'global'";
        $scopeInsertColumn = ", scope";
        $scopeInsertParam[] = 'global';
    }

    $existing = $db->fetchOne(
        "SELECT id
         FROM memory_summary
         WHERE LOWER(people) = LOWER($1)
           AND source_from_memory_id = $2
           AND source_to_memory_id = $3
           {$scopePendingClause}
         LIMIT 1",
        [$safePeople, $fromId, $toId]
    );
    if (is_array($existing) && intval($existing['id'] ?? 0) > 0) {
        return true;
    }

    $firstLocalTs = intval($first['localts'] ?? time());
    $lastLocalTs = intval($last['localts'] ?? $firstLocalTs);
    if ($firstLocalTs <= 0) {
        $firstLocalTs = time();
    }
    if ($lastLocalTs <= 0) {
        $lastLocalTs = $firstLocalTs;
    }

    $periodStart = gmdate('Y-m-d H:i:s', $firstLocalTs);
    $periodEnd = gmdate('Y-m-d H:i:s', $lastLocalTs);
    $gametsStart = max(0, intval($first['gamets'] ?? 0));
    $gametsEnd = max($gametsStart, intval($last['gamets'] ?? $gametsStart));
    $packedMessage = truncatePromptValue(stobeRegularMemoryBuildPackedMessage($rows), 12000);
    if ($packedMessage === '') {
        return false;
    }

    if ($scopeInsertColumn !== '') {
        $result = $db->exec(
            "INSERT INTO memory_summary (
                people
                {$scopeInsertColumn},
                summary,
                embedding,
                period_start,
                period_end,
                n,
                packed_message,
                source_from_memory_id,
                source_to_memory_id,
                localts,
                gamets_start,
                gamets_end,
                created_at
            ) VALUES (
                $1,
                $2,
                '',
                NULL,
                $3,
                $4,
                $5,
                $6,
                $7,
                $8,
                $9,
                $10,
                $11,
                NOW()
            )",
            array_merge(
                [$safePeople],
                $scopeInsertParam,
                [
                    $periodStart,
                    $periodEnd,
                    count($rows),
                    $packedMessage,
                    $fromId,
                    $toId,
                    $lastLocalTs,
                    $gametsStart,
                    $gametsEnd,
                ]
            )
        );
    } else {
        $result = $db->exec(
            "INSERT INTO memory_summary (
                people,
                summary,
                embedding,
                period_start,
                period_end,
                n,
                packed_message,
                source_from_memory_id,
                source_to_memory_id,
                localts,
                gamets_start,
                gamets_end,
                created_at
            ) VALUES (
                $1,
                '',
                NULL,
                $2,
                $3,
                $4,
                $5,
                $6,
                $7,
                $8,
                $9,
                $10,
                NOW()
            )",
            [
                $safePeople,
                $periodStart,
                $periodEnd,
                count($rows),
                $packedMessage,
                $fromId,
                $toId,
                $lastLocalTs,
                $gametsStart,
                $gametsEnd,
            ]
        );
    }

    if ($result === false) {
        return false;
    }

    return true;
}

function stobeRegularMemoryFetchPendingSummaryRows(int $limit = 72): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return [];
    }
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 256) {
        $limit = 256;
    }

    $scopeClause = '';
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $scopeClause = "AND LOWER(COALESCE(scope, '')) = 'global'";
    }

    return $db->fetchAll(
        "SELECT
            id,
            people,
            packed_message,
            n,
            source_from_memory_id,
            source_to_memory_id,
            localts,
            gamets_start,
            gamets_end,
            created_at
         FROM memory_summary
         WHERE BTRIM(COALESCE(summary, '')) = ''
           AND COALESCE(BTRIM(packed_message), '') <> ''
           {$scopeClause}
         ORDER BY id ASC
         LIMIT " . intval($limit)
    );
}

function stobeRegularMemoryFinalizePendingSummary(int $summaryId, string $summary): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return false;
    }
    if ($summaryId <= 0) {
        return false;
    }

    $safeSummary = trim(sanitizeForKenshi($summary));
    if ($safeSummary === '') {
        return false;
    }
    $safeSummary = truncatePromptValue($safeSummary, 3500);
    $embedding = stobeRegularMemoryEmbedText($safeSummary);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);

    $result = $db->exec(
        "UPDATE memory_summary
         SET summary = $2,
             embedding = CASE WHEN $3 = '' THEN NULL ELSE $3::vector END
         WHERE id = $1
           AND BTRIM(COALESCE(summary, '')) = ''",
        [$summaryId, $safeSummary, $vectorLiteral]
    );
    return $result !== false;
}

function stobeRegularMemoryPersistSummary(string $peopleKey, array $rows, string $summary): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return false;
    }
    if (trim($summary) === '' || count($rows) === 0) {
        return false;
    }

    $first = $rows[0];
    $last = $rows[count($rows) - 1];
    $fromId = intval($first['id'] ?? 0);
    $toId = intval($last['id'] ?? 0);
    $firstLocalTs = intval($first['localts'] ?? time());
    $lastLocalTs = intval($last['localts'] ?? $firstLocalTs);
    if ($firstLocalTs <= 0) {
        $firstLocalTs = time();
    }
    if ($lastLocalTs <= 0) {
        $lastLocalTs = $firstLocalTs;
    }
    $periodStart = gmdate('Y-m-d H:i:s', $firstLocalTs);
    $periodEnd = gmdate('Y-m-d H:i:s', $lastLocalTs);
    $gametsStart = max(0, intval($first['gamets'] ?? 0));
    $gametsEnd = max($gametsStart, intval($last['gamets'] ?? $gametsStart));
    $packedMessage = stobeRegularMemoryBuildPackedMessage($rows);
    $packedMessage = truncatePromptValue($packedMessage, 12000);
    $embedding = stobeRegularMemoryEmbedText($summary);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);

    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $result = $db->exec(
            "INSERT INTO memory_summary (
                people,
                scope,
                summary,
                embedding,
                period_start,
                period_end,
                n,
                packed_message,
                source_from_memory_id,
                source_to_memory_id,
                localts,
                gamets_start,
                gamets_end,
                created_at
            ) VALUES (
                $1,
                $2,
                $3,
                CASE WHEN $4 = '' THEN NULL ELSE $4::vector END,
                $5,
                $6,
                $7,
                $8,
                $9,
                $10,
                $11,
                $12,
                $13,
                NOW()
            )",
            [
                $peopleKey,
                'global',
                $summary,
                $vectorLiteral,
                $periodStart,
                $periodEnd,
                count($rows),
                $packedMessage,
                $fromId,
                $toId,
                $lastLocalTs,
                $gametsStart,
                $gametsEnd,
            ]
        );
    } else {
        $result = $db->exec(
            "INSERT INTO memory_summary (
                people,
                summary,
                embedding,
                period_start,
                period_end,
                n,
                packed_message,
                source_from_memory_id,
                source_to_memory_id,
                localts,
                gamets_start,
                gamets_end,
                created_at
            ) VALUES (
                $1,
                $2,
                CASE WHEN $3 = '' THEN NULL ELSE $3::vector END,
                $4,
                $5,
                $6,
                $7,
                $8,
                $9,
                $10,
                $11,
                $12,
                NOW()
            )",
            [
                $peopleKey,
                $summary,
                $vectorLiteral,
                $periodStart,
                $periodEnd,
                count($rows),
                $packedMessage,
                $fromId,
                $toId,
                $lastLocalTs,
                $gametsStart,
                $gametsEnd,
            ]
        );
    }

    return $result !== false;
}

function stobeRegularMemoryFetchIndividualNpcCandidates(int $limit = 96): array
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

    $rows = $db->fetchAll(
        "SELECT id, name, backstory, personality, goals, speechstyle, profile_id, metadata, extended_data
         FROM core_npc
         WHERE COALESCE(BTRIM(name), '') <> ''
         ORDER BY name ASC
         LIMIT " . intval($limit)
    );

    $enabled = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $npcName = normalizeParticipantNameToken(strval($row['name'] ?? ''));
        if ($npcName === '' || strcasecmp($npcName, 'The Narrator') === 0) {
            continue;
        }
        if (!stobeRegularMemoryIndividualEnabledForNpc($row)) {
            continue;
        }
        $row['name'] = $npcName;
        $enabled[] = $row;
    }

    return $enabled;
}

function stobeRegularMemoryFetchGlobalSummaryRowsForNpc(string $npcName, int $afterGamets, int $limit = 180): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return [];
    }

    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return [];
    }

    if ($limit < 5) {
        $limit = 5;
    } elseif ($limit > 500) {
        $limit = 500;
    }

    $peopleExact = json_encode([$safeNpc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($peopleExact) || trim($peopleExact) === '') {
        $peopleExact = '["' . addslashes($safeNpc) . '"]';
    }
    $jsonQuotedNpc = strtolower(json_encode($safeNpc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    if ($jsonQuotedNpc === '') {
        $jsonQuotedNpc = '"' . strtolower($safeNpc) . '"';
    }

    $scopeClause = '';
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $scopeClause = "AND (scope IS NULL OR BTRIM(scope) = '' OR LOWER(BTRIM(scope)) = 'global')";
    }

    return $db->fetchAll(
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
           AND COALESCE(BTRIM(packed_message), '') <> ''
           AND COALESCE(gamets_end, 0) > $1
           AND (
                LOWER(people) = LOWER($2)
                OR POSITION($3 IN LOWER(COALESCE(people, ''))) > 0
               )
           {$scopeClause}
         ORDER BY COALESCE(gamets_end, 0) ASC, id ASC
         LIMIT " . intval($limit),
        [max(0, $afterGamets), $peopleExact, $jsonQuotedNpc]
    );
}

function stobeRegularMemoryBuildIndividualHistoryFromGlobalRows(array $rows): string
{
    $chunks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slice = trim(strval($row['packed_message'] ?? ''));
        if ($slice === '') {
            $slice = trim(strval($row['summary'] ?? ''));
        }
        if ($slice === '') {
            continue;
        }
        $slice = trim(sanitizeForKenshi($slice));
        if ($slice === '') {
            continue;
        }
        $gamets = intval($row['gamets_end'] ?? ($row['gamets_start'] ?? 0));
        $chunks[] = "===\nMemory entry, date " . stobeGametsDateLabel($gamets) . "\n" . $slice;
    }
    return implode("\n\n", $chunks);
}

function stobeRegularMemoryGenerateIndividualSummary(array $npcRow, array $globalRows): string
{
    if (count($globalRows) === 0) {
        return '';
    }

    $npcName = normalizeParticipantNameToken(strval($npcRow['name'] ?? ''));
    if ($npcName === '') {
        return '';
    }

    $history = trim(stobeRegularMemoryBuildIndividualHistoryFromGlobalRows($globalRows));
    if ($history === '') {
        return '';
    }

    $bioContext = stobeRegularMemoryBuildIndividualBioContext($npcRow);
    $defaultSystemPrompt = "You are writing an individual long-term memory bank for NPC {$npcName} in Kenshi roleplay.\n"
        . "Write from {$npcName}'s viewpoint, personality, values, fears, preferences, and relationships.\n"
        . "Only include events where {$npcName} is directly involved or that materially affect them.\n"
        . "Be selective. Preserve emotionally significant or unusual events, relationship changes, injuries, rescues, betrayals, losses, promises, plans, debts, arguments, discoveries, meaningful gifts/trades, faction experiences, important places, personal revelations, and repeated behavior that establishes a preference or habit.\n"
        . "Keep unresolved goals, promises, obligations, grudges, fears, and plans explicit until later events resolve them. Preserve useful place associations such as what happened in a town or region when it could produce a future callback.\n"
        . "Compress ordinary greetings, repetitive small talk, routine travel/status updates, and low-value repetition unless the repetition itself becomes personally meaningful.\n"
        . "Do not invent events, feelings, relationships, or outcomes. Ignore engine/system noise.\n"
        . "Character reference:\n{$bioContext}\n\n"
        . "Output plain text only.";
    $systemPrompt = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('regular_memory_individual_summarizer', $defaultSystemPrompt)
        : $defaultSystemPrompt;

    $db = $GLOBALS['db'] ?? null;
    $previousSummary = '';
    if ($db && stobeRegularMemorySummaryTableAvailable() && stobeRegularMemorySummaryScopeColumnAvailable()) {
        $prev = $db->fetchOne(
            "SELECT summary
             FROM memory_summary
             WHERE LOWER(COALESCE(scope, '')) = LOWER($1)
               AND summary IS NOT NULL
               AND BTRIM(summary) <> ''
             ORDER BY COALESCE(gamets_end, 0) DESC, id DESC
             LIMIT 1",
            [$npcName]
        );
        if (is_array($prev)) {
            $previousSummary = trim(sanitizeForKenshi(strval($prev['summary'] ?? '')));
        }
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];
    if ($previousSummary !== '') {
        $messages[] = [
            'role' => 'user',
            'content' => "#PREVIOUS NPC MEMORY SUMMARY#\n{$previousSummary}\n#END OF PREVIOUS NPC MEMORY SUMMARY#",
        ];
    }
    $messages[] = [
        'role' => 'user',
        'content' => "#NPC EVENT HISTORY#\n{$history}\n#END OF NPC EVENT HISTORY#",
    ];
    $messages[] = [
        'role' => 'user',
        'content' => "Write one memory summary for {$npcName} using this format:\n"
            . "#Summary: {summary from {$npcName}'s viewpoint}\n\n"
            . "#Tags: {hashtags for people, places, and events}",
    ];

    $llmConfig = getLlmConfigForNpcPurpose($npcRow, 'middleterm');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return truncatePromptValue($history, 3500);
    }
    $raw = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $npcName,
        'event_type' => 'memory_summary_generate_individual',
    ]);
    if ($raw === false) {
        return truncatePromptValue($history, 3500);
    }

    $summary = trim(sanitizeForKenshi(strval($raw)));
    if ($summary === '') {
        return truncatePromptValue($history, 3500);
    }
    if (stripos($summary, '#Summary:') === false) {
        $summary = "#Summary: " . $summary;
    }

    return truncatePromptValue($summary, 4500);
}

function stobeRegularMemoryPersistIndividualSummary(string $npcName, array $globalRows, string $summary): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (
        !$db
        || !stobeRegularMemorySummaryTableAvailable()
        || !stobeRegularMemorySummaryScopeColumnAvailable()
    ) {
        return false;
    }

    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '' || trim($summary) === '' || count($globalRows) === 0) {
        return false;
    }

    $first = $globalRows[0];
    $last = $globalRows[count($globalRows) - 1];

    $firstLocalTs = intval($first['localts'] ?? time());
    $lastLocalTs = intval($last['localts'] ?? $firstLocalTs);
    if ($firstLocalTs <= 0) {
        $firstLocalTs = time();
    }
    if ($lastLocalTs <= 0) {
        $lastLocalTs = $firstLocalTs;
    }

    $gametsStart = max(0, intval($first['gamets_start'] ?? ($first['gamets_end'] ?? 0)));
    $gametsEnd = max($gametsStart, intval($last['gamets_end'] ?? $gametsStart));
    $fromMemoryId = max(0, intval($first['source_from_memory_id'] ?? 0));
    $toMemoryId = max($fromMemoryId, intval($last['source_to_memory_id'] ?? $fromMemoryId));
    $periodStart = gmdate('Y-m-d H:i:s', $firstLocalTs);
    $periodEnd = gmdate('Y-m-d H:i:s', $lastLocalTs);
    $peopleKey = json_encode([$safeNpc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($peopleKey) || trim($peopleKey) === '') {
        $peopleKey = '["' . addslashes($safeNpc) . '"]';
    }
    $packedMessage = truncatePromptValue(
        stobeRegularMemoryBuildIndividualHistoryFromGlobalRows($globalRows),
        16000
    );
    $embedding = stobeRegularMemoryEmbedText($summary);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);

    $result = $db->exec(
        "INSERT INTO memory_summary (
            people,
            scope,
            summary,
            embedding,
            period_start,
            period_end,
            n,
            packed_message,
            source_from_memory_id,
            source_to_memory_id,
            localts,
            gamets_start,
            gamets_end,
            created_at
        ) VALUES (
            $1,
            $2,
            $3,
            CASE WHEN $4 = '' THEN NULL ELSE $4::vector END,
            $5,
            $6,
            $7,
            $8,
            $9,
            $10,
            $11,
            $12,
            $13,
            NOW()
        )",
        [
            $peopleKey,
            $safeNpc,
            $summary,
            $vectorLiteral,
            $periodStart,
            $periodEnd,
            count($globalRows),
            $packedMessage,
            $fromMemoryId,
            $toMemoryId,
            $lastLocalTs,
            $gametsStart,
            $gametsEnd,
        ]
    );

    return $result !== false;
}

function stobeRegularMemoryInsertPackedGlobalSummaryRows(
    int $afterGamets,
    int $beforeGamets,
    int $pfi,
    int $minEvents
): int {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable() || !stobeRegularMemorySummaryTableAvailable()) {
        return 0;
    }
    if ($pfi < 1 || $beforeGamets <= $afterGamets) {
        return 0;
    }

    $safeAfter = max(0, $afterGamets);
    $safeBefore = max(0, $beforeGamets);
    $safePfi = max(1, $pfi);
    $safeMinEvents = max(1, $minEvents);

    $scopeInsertColumn = '';
    $scopeInsertValue = '';
    $scopeJoinClause = '';
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $scopeInsertColumn = ', scope';
        $scopeInsertValue = ", 'global'::text";
        $scopeJoinClause = "AND LOWER(COALESCE(ms.scope, '')) = 'global'";
    }

    $locationExpr = "NULL::text";
    if (stobeRegularMemoryLocationColumnAvailable()) {
        $locationExpr = "NULLIF(LOWER(BTRIM(COALESCE(location, ''))), '')";
    }

    $rows = $db->fetchAll(
        "WITH source_rows AS (
            SELECT
                id,
                people,
                gamets,
                COALESCE(localts, 0) AS localts,
                content,
                LOWER(COALESCE(NULLIF(BTRIM(event_type), ''), 'memory')) AS event_type,
                ROUND(gamets::numeric / $3::numeric, 0) AS time_bucket,
                {$locationExpr} AS location_key
            FROM memory
            WHERE gamets > $1
              AND gamets < $2
              AND COALESCE(BTRIM(people), '') <> ''
              AND BTRIM(people) <> '[]'
              AND LOWER(COALESCE(event_type, '')) NOT IN ('diary', 'auto_diary', 'backgroundlife_diary')
        ),
        queue_boundaries AS (
            SELECT
                id,
                people,
                gamets,
                localts,
                content,
                event_type,
                time_bucket,
                location_key,
                LAG(location_key) OVER (PARTITION BY people ORDER BY gamets ASC, localts ASC, id ASC) AS prev_location_key,
                LAG(time_bucket) OVER (PARTITION BY people ORDER BY gamets ASC, localts ASC, id ASC) AS prev_time_bucket
            FROM source_rows
        ),
        queued_rows AS (
            SELECT
                id,
                people,
                gamets,
                localts,
                content,
                event_type,
                CASE
                    WHEN prev_time_bucket IS NULL THEN 1
                    WHEN location_key IS NULL THEN 1
                    WHEN prev_location_key IS NULL THEN 1
                    WHEN location_key <> prev_location_key THEN 1
                    WHEN time_bucket <> prev_time_bucket THEN 1
                    ELSE 0
                END AS is_new_queue
            FROM queue_boundaries
        ),
        grouped_rows AS (
            SELECT
                id,
                people,
                gamets,
                localts,
                content,
                event_type,
                SUM(is_new_queue) OVER (
                    PARTITION BY people
                    ORDER BY gamets ASC, localts ASC, id ASC
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS queue_id
            FROM queued_rows
        ),
        aggregated AS (
            SELECT
                people,
                MIN(id) AS source_from_memory_id,
                MAX(id) AS source_to_memory_id,
                MIN(gamets) AS gamets_start,
                MAX(gamets) AS gamets_end,
                MIN(localts) AS localts_start,
                MAX(localts) AS localts_end,
                COUNT(*) AS n,
                STRING_AGG(
                    '[' || event_type || '] ' || COALESCE(content, ''),
                    E'\n\n'
                    ORDER BY gamets ASC, localts ASC, id ASC
                ) AS packed_message
            FROM grouped_rows
            GROUP BY people, queue_id
            HAVING COUNT(*) >= $4
        )
        INSERT INTO memory_summary (
            people
            {$scopeInsertColumn},
            summary,
            period_start,
            period_end,
            n,
            packed_message,
            source_from_memory_id,
            source_to_memory_id,
            localts,
            gamets_start,
            gamets_end,
            created_at
        )
        SELECT
            a.people
            {$scopeInsertValue},
            ''::text,
            TO_TIMESTAMP(CASE WHEN a.localts_start > 0 THEN a.localts_start ELSE EXTRACT(EPOCH FROM NOW()) END),
            TO_TIMESTAMP(CASE WHEN a.localts_end > 0 THEN a.localts_end ELSE EXTRACT(EPOCH FROM NOW()) END),
            a.n,
            COALESCE(a.packed_message, ''),
            a.source_from_memory_id,
            a.source_to_memory_id,
            a.localts_end,
            a.gamets_start,
            a.gamets_end,
            NOW()
        FROM aggregated a
        LEFT JOIN memory_summary ms
            ON LOWER(ms.people) = LOWER(a.people)
           AND COALESCE(ms.source_from_memory_id, 0) = COALESCE(a.source_from_memory_id, 0)
           AND COALESCE(ms.source_to_memory_id, 0) = COALESCE(a.source_to_memory_id, 0)
           {$scopeJoinClause}
        WHERE ms.id IS NULL
          AND COALESCE(BTRIM(COALESCE(a.packed_message, '')), '') <> ''
        RETURNING id",
        [$safeAfter, $safeBefore, $safePfi, $safeMinEvents]
    );

    return is_array($rows) ? count($rows) : 0;
}

function stobeRegularMemoryInsertDiarySummaryRows(int $afterGamets, int $beforeGamets): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable() || !stobeRegularMemorySummaryTableAvailable()) {
        return 0;
    }
    if ($beforeGamets <= $afterGamets) {
        return 0;
    }

    $scopeInsertColumn = '';
    $scopeInsertValue = '';
    $scopeJoinClause = '';
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        $scopeInsertColumn = ', scope';
        $scopeInsertValue = ", 'global'::text";
        $scopeJoinClause = "AND LOWER(COALESCE(ms.scope, '')) = 'global'";
    }

    $rows = $db->fetchAll(
        "INSERT INTO memory_summary (
            people
            {$scopeInsertColumn},
            summary,
            period_start,
            period_end,
            n,
            packed_message,
            source_from_memory_id,
            source_to_memory_id,
            localts,
            gamets_start,
            gamets_end,
            created_at
        )
        SELECT
            m.people
            {$scopeInsertValue},
            COALESCE(BTRIM(m.content), ''),
            TO_TIMESTAMP(CASE WHEN COALESCE(m.localts, 0) > 0 THEN m.localts ELSE EXTRACT(EPOCH FROM NOW()) END),
            TO_TIMESTAMP(CASE WHEN COALESCE(m.localts, 0) > 0 THEN m.localts ELSE EXTRACT(EPOCH FROM NOW()) END),
            1,
            COALESCE(BTRIM(m.content), ''),
            m.id,
            m.id,
            COALESCE(m.localts, 0),
            COALESCE(m.gamets, 0),
            COALESCE(m.gamets, 0),
            NOW()
        FROM memory m
        LEFT JOIN memory_summary ms
            ON LOWER(ms.people) = LOWER(m.people)
           AND COALESCE(ms.source_from_memory_id, 0) = m.id
           AND COALESCE(ms.source_to_memory_id, 0) = m.id
           {$scopeJoinClause}
        WHERE COALESCE(BTRIM(m.people), '') <> ''
          AND BTRIM(m.people) <> '[]'
          AND COALESCE(BTRIM(m.content), '') <> ''
          AND COALESCE(m.gamets, 0) > $1
          AND COALESCE(m.gamets, 0) < $2
          AND LOWER(COALESCE(m.event_type, '')) IN ('diary', 'auto_diary', 'backgroundlife_diary')
          AND ms.id IS NULL
        RETURNING id",
        [max(0, $afterGamets), max(0, $beforeGamets)]
    );

    return is_array($rows) ? count($rows) : 0;
}

function stobeRegularMemoryProcessOneGlobalPackBatch(string $eventType, int $gamets): bool
{
    $safeEventType = strtolower(trim($eventType));
    if ($safeEventType === 'manual_sync') {
        stobeRegularMemoryNormalizeLegacyPeopleKeys(1600);
    }

    $pfi = stobeRegularMemoryRunIntervalGamets();
    $lastSummaryGamets = stobeRegularMemoryLatestCompletedGlobalSummaryGamets();
    if ($safeEventType !== 'manual_sync' && ($gamets - $lastSummaryGamets) <= $pfi) {
        return false;
    }

    // Herika-style compaction uses the current max gamets window.
    // Do not delay packing by an extra in-game hour; that can starve
    // memory_summary generation when active conversations are recent.
    $cutoffGamets = max(0, intval($gamets));
    if ($cutoffGamets <= $lastSummaryGamets) {
        return false;
    }

    $minEvents = stobeRegularMemorySummaryMinEvents();
    $packedInserted = stobeRegularMemoryInsertPackedGlobalSummaryRows(
        $lastSummaryGamets,
        $cutoffGamets,
        $pfi,
        $minEvents
    );
    $diaryInserted = stobeRegularMemoryInsertDiarySummaryRows($lastSummaryGamets, $cutoffGamets);
    $totalInserted = $packedInserted + $diaryInserted;

    if ($totalInserted > 0) {
        stobeLogInfo('Regular memory summary pack queued (Herika-style)', [
            'packed_inserted' => $packedInserted,
            'diary_inserted' => $diaryInserted,
            'total_inserted' => $totalInserted,
            'after_gamets' => $lastSummaryGamets,
            'before_gamets' => $cutoffGamets,
            'pfi' => $pfi,
            'min_events' => $minEvents,
            'gamets' => $gamets,
            'event_type' => $eventType,
        ]);
    }

    return $totalInserted > 0;
}

function stobeRegularMemoryProcessOneGlobalBatch(string $eventType, int $gamets): bool
{
    $pendingRows = stobeRegularMemoryFetchPendingSummaryRows(72);
    foreach ($pendingRows as $pending) {
        if (!is_array($pending)) {
            continue;
        }

        $summaryId = intval($pending['id'] ?? 0);
        $peopleKey = trim(strval($pending['people'] ?? ''));
        $packed = trim(strval($pending['packed_message'] ?? ''));
        if ($summaryId <= 0 || $peopleKey === '' || $peopleKey === '[]' || $packed === '') {
            continue;
        }

        $previous = stobeRegularMemoryFetchLatestCompletedSummary($peopleKey, $summaryId);
        $summary = stobeRegularMemoryGenerateSummaryFromPacked(
            $peopleKey,
            $packed,
            $previous,
            intval($pending['gamets_start'] ?? 0),
            intval($pending['gamets_end'] ?? 0)
        );
        if ($summary === '') {
            continue;
        }

        if (!stobeRegularMemoryFinalizePendingSummary($summaryId, $summary)) {
            stobeLogWarn('Regular memory pending summary finalize failed', [
                'summary_id' => $summaryId,
                'people' => $peopleKey,
                'packed_length' => strlen($packed),
            ]);
            continue;
        }

        stobeLogInfo('Regular memory summary generated', [
            'summary_id' => $summaryId,
            'people' => $peopleKey,
            'people_count' => count(stobeRegularMemoryDecodePeopleKey($peopleKey)),
            'packed_length' => strlen($packed),
            'summary_length' => strlen($summary),
            'source_from_memory_id' => intval($pending['source_from_memory_id'] ?? 0),
            'source_to_memory_id' => intval($pending['source_to_memory_id'] ?? 0),
            'gamets_start' => intval($pending['gamets_start'] ?? 0),
            'gamets_end' => intval($pending['gamets_end'] ?? 0),
            'gamets' => $gamets,
            'event_type' => $eventType,
        ]);

        return true;
    }

    return false;
}

function stobeRegularMemoryProcessOneIndividualBatch(string $eventType, int $gamets): bool
{
    if (
        !stobeRegularMemorySummaryTableAvailable()
        || !stobeRegularMemorySummaryScopeColumnAvailable()
    ) {
        return false;
    }

    $threshold = stobeRegularMemoryIndividualSummaryThreshold();
    $enabledNpcs = stobeRegularMemoryFetchIndividualNpcCandidates(96);
    if (count($enabledNpcs) === 0) {
        return false;
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    foreach ($enabledNpcs as $npcRow) {
        $npcName = normalizeParticipantNameToken(strval($npcRow['name'] ?? ''));
        if ($npcName === '') {
            continue;
        }

        $lastScopedRow = $db->fetchOne(
            "SELECT COALESCE(MAX(gamets_end), 0) AS max_gamets
             FROM memory_summary
             WHERE LOWER(COALESCE(scope, '')) = LOWER($1)",
            [$npcName]
        );
        $lastScopedGamets = intval($lastScopedRow['max_gamets'] ?? 0);
        $pending = stobeRegularMemoryFetchGlobalSummaryRowsForNpc($npcName, $lastScopedGamets, 200);
        if (count($pending) < $threshold) {
            continue;
        }

        $batch = array_slice($pending, 0, $threshold);
        $summary = stobeRegularMemoryGenerateIndividualSummary($npcRow, $batch);
        if ($summary === '') {
            continue;
        }

        if (!stobeRegularMemoryPersistIndividualSummary($npcName, $batch, $summary)) {
            stobeLogWarn('Individual memory summary persist failed', [
                'npc_name' => $npcName,
                'rows' => count($batch),
                'threshold' => $threshold,
            ]);
            continue;
        }

        $lastBatch = $batch[count($batch) - 1];
        stobeLogInfo('Individual memory summary generated', [
            'npc_name' => $npcName,
            'rows' => count($batch),
            'threshold' => $threshold,
            'last_gamets' => intval($lastBatch['gamets_end'] ?? 0),
            'summary_length' => strlen($summary),
            'gamets' => $gamets,
            'event_type' => $eventType,
        ]);

        return true; // keep per-cycle latency bounded
    }

    return false;
}

function stobeMaybeRunRegularMemoryCycle(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData = ''
): void {
    if (!stobeRegularMemoryShouldRunCycle($eventType, $gamets)) {
        return;
    }
    if (!stobeRegularMemoryTryLock()) {
        return;
    }

    try {
        $processedPack = stobeRegularMemoryProcessOneGlobalPackBatch($eventType, $gamets);
        $processedGlobal = stobeRegularMemoryProcessOneGlobalBatch($eventType, $gamets);
        $processedIndividual = stobeRegularMemoryProcessOneIndividualBatch($eventType, $gamets);

        setConfOpt('MEMORY_LAST_RUN_TS', strval(time()), true);
        if ($gamets > 0) {
            setConfOpt('MEMORY_LAST_RUN_GAMETS', strval($gamets), true);
        }

        if (!$processedPack && !$processedGlobal && !$processedIndividual) {
            stobeLogDebug('Regular memory cycle completed with no eligible NPC work', [
                'event_type' => $eventType,
                'gamets' => $gamets,
                'pack_processed' => false,
                'global_processed' => false,
                'individual_processed' => false,
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Regular memory cycle failed', [
            'event_type' => $eventType,
            'gamets' => $gamets,
        ]);
    } finally {
        stobeRegularMemoryUnlock();
    }
}

function stobeRunRegularMemorySyncNow(int $gamets, int $maxPasses = 16): array
{
    $passes = $maxPasses;
    if ($passes < 1) {
        $passes = 1;
    } elseif ($passes > 64) {
        $passes = 64;
    }

    $result = [
        'passes' => 0,
        'packed' => 0,
        'global' => 0,
        'individual' => 0,
    ];

    for ($i = 0; $i < $passes; $i++) {
        if (!stobeRegularMemoryTryLock()) {
            break;
        }

        $processedPack = false;
        $processedGlobal = false;
        $processedIndividual = false;
        try {
            $processedPack = stobeRegularMemoryProcessOneGlobalPackBatch('manual_sync', $gamets);
            $processedGlobal = stobeRegularMemoryProcessOneGlobalBatch('manual_sync', $gamets);
            $processedIndividual = stobeRegularMemoryProcessOneIndividualBatch('manual_sync', $gamets);

            $result['passes']++;
            if ($processedPack) {
                $result['packed']++;
            }
            if ($processedGlobal) {
                $result['global']++;
            }
            if ($processedIndividual) {
                $result['individual']++;
            }

            setConfOpt('MEMORY_LAST_RUN_TS', strval(time()), true);
            if ($gamets > 0) {
                setConfOpt('MEMORY_LAST_RUN_GAMETS', strval($gamets), true);
            }
        } catch (Throwable $exception) {
            stobeLogException($exception, 'Regular memory manual sync failed', [
                'gamets' => $gamets,
                'pass' => $i + 1,
            ]);
            break;
        } finally {
            stobeRegularMemoryUnlock();
        }

        if (!$processedPack && !$processedGlobal && !$processedIndividual) {
            break;
        }
    }

    return $result;
}

function stobeRegularMemoryNormalizeSearchText(string $value): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\([^)]+\)/u', ' ', $text) ?? $text;
    $text = preg_replace('/[^a-z0-9_]+/iu', ' ', $text) ?? $text;
    $text = strtolower(trim($text));
    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

function stobeRegularMemoryExtractSearchTokens(string $value): array
{
    $normalized = stobeRegularMemoryNormalizeSearchText($value);
    if ($normalized === '') {
        return [];
    }

    $parts = preg_split('/\s+/u', $normalized) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        $token = trim(strval($part));
        if ($token === '' || strlen($token) < 3) {
            continue;
        }
        $token = preg_replace('/[^a-z0-9_]/u', '', $token) ?? $token;
        if ($token === '' || strlen($token) < 3) {
            continue;
        }
        $tokens[$token] = $token;
    }

    return array_values($tokens);
}

function stobeRegularMemoryBuildTsQueryStrings(array $tokens): array
{
    $clean = [];
    foreach ($tokens as $token) {
        if (!is_scalar($token) || $token === null) {
            continue;
        }
        $value = strtolower(trim(strval($token)));
        $value = preg_replace('/[^a-z0-9_]/u', '', $value) ?? $value;
        if ($value === '' || strlen($value) < 3) {
            continue;
        }
        $clean[$value] = $value;
    }

    if (count($clean) === 0) {
        $clean['memory'] = 'memory';
    }

    $ordered = array_values($clean);
    return [
        'any' => implode(' | ', $ordered),
        'all' => implode(' & ', $ordered),
        'keywords' => implode(' ', $ordered),
    ];
}

function stobeRegularMemoryFetchContextTokens(string $npcName, int $limit = 5): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return [];
    }

    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 20) {
        $limit = 20;
    }
    $deliveryVisibilitySql = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';

    $rows = $db->fetchAll(
        "SELECT data
         FROM eventlog
         WHERE type = 'chat'
           AND {$deliveryVisibilitySql}
           AND LOWER(COALESCE(people, '')) LIKE LOWER($1)
         ORDER BY gamets DESC
         LIMIT " . intval($limit),
        ['%' . $safeNpc . '%']
    );

    $tokens = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = trim(strval($row['data'] ?? ''));
        if ($line === '') {
            continue;
        }
        $rowTokens = stobeRegularMemoryExtractSearchTokens($line);
        foreach ($rowTokens as $token) {
            $tokens[$token] = $token;
        }
    }

    return array_values($tokens);
}

function stobeRegularMemorySearchByVector(
    array|false $npcData,
    string $npcName,
    string $queryText,
    int $timeThreshold = 0,
    bool $useContextKw = false
): ?array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return null;
    }

    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return null;
    }

    $tokens = stobeRegularMemoryExtractSearchTokens($queryText);
    if ($useContextKw) {
        $contextTokens = stobeRegularMemoryFetchContextTokens($safeNpc, 5);
        foreach ($contextTokens as $token) {
            $tokens[$token] = $token;
        }
        $tokens = array_values($tokens);
    }

    $tsQuery = stobeRegularMemoryBuildTsQueryStrings($tokens);
    $keywords = trim(strval($tsQuery['keywords'] ?? ''));
    if ($keywords === '') {
        $keywords = trim($queryText) !== '' ? trim($queryText) : 'memory';
    }

    $embedding = stobeRegularMemoryEmbedText($keywords);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);
    if ($vectorLiteral === '') {
        return null;
    }

    $jsonQuotedNpc = strtolower(json_encode($safeNpc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    if ($jsonQuotedNpc === '') {
        $jsonQuotedNpc = '"' . strtolower($safeNpc) . '"';
    }

    $scopeClause = '';
    $params = [
        $safeNpc,
        $vectorLiteral,
        $jsonQuotedNpc,
        strval($tsQuery['any'] ?? 'memory'),
        strval($tsQuery['all'] ?? 'memory'),
        max(0, intval($timeThreshold)),
    ];
    if (stobeRegularMemorySummaryScopeColumnAvailable()) {
        if (stobeRegularMemoryIndividualEnabledForNpc($npcData)) {
            $scopeClause = "AND LOWER(COALESCE(scope, '')) = LOWER($7)";
            $params[] = $safeNpc;
        } else {
            $scopeClause = "AND (scope IS NULL OR BTRIM(scope) = '' OR LOWER(BTRIM(scope)) = 'global')";
        }
    }

    try {
        $rows = $db->fetchAll(
            "SELECT
                id,
                summary,
                gamets_end,
                created_at,
                embedding <-> $2::vector AS distance,
                ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $4)) AS rank_any_fts_raw,
                ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $5)) AS rank_all_fts_raw,
                ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $4))
                  + ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $5)) AS rank_any_fts,
                ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $4))
                  + ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $5)) AS rank_all_fts,
                (embedding <-> $2::vector)
                  - (
                        ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $4))
                        + ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $5))
                    ) AS mixed_distance
             FROM memory_summary
             WHERE embedding IS NOT NULL
               AND summary IS NOT NULL
               AND BTRIM(summary) <> ''
               AND (
                    LOWER(people) = LOWER($1)
                    OR POSITION($3 IN LOWER(COALESCE(people, ''))) > 0
               )
               {$scopeClause}
               AND (COALESCE(gamets_end, 0) < $6 OR $6 = 0)
             ORDER BY
               ROUND((embedding <-> $2::vector)::numeric, 2) ASC,
               (
                    ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $4))
                    + ts_rank(to_tsvector('simple', COALESCE(summary, '')), to_tsquery('simple', $5))
               ) DESC
             LIMIT 50",
            $params
        );
    } catch (Throwable $exception) {
        stobeLogWarn('Regular memory vector search query failed', [
            'npc_name' => $safeNpc,
            'error' => $exception->getMessage(),
        ]);
        return null;
    }

    if (!is_array($rows) || count($rows) === 0) {
        return null;
    }

    $singleMemory = null;
    $maxRankAny = -INF;
    foreach ($rows as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $candidateRankAny = floatval($entry['rank_any_fts'] ?? -INF);
        if ($singleMemory === null || $candidateRankAny > $maxRankAny) {
            $maxRankAny = $candidateRankAny;
            $singleMemory = $entry;
        }
    }

    if (!is_array($singleMemory)) {
        return null;
    }

    $singleMemory['rank_any'] = floatval($singleMemory['rank_any_fts'] ?? 0.0)
        + (floatval($singleMemory['rank_all_fts'] ?? 0.0) / 2.0);
    $singleMemory['rank_all'] = floatval($singleMemory['rank_all_fts'] ?? 0.0);
    return $singleMemory;
}

function stobeRegularMemoryRecallRows(
    array|false $npcData,
    string $npcName,
    string $queryText,
    int $currentGamets,
    int $maxEntries
): array {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return [];
    }

    $timeThreshold = 0;
    if ($currentGamets > 0) {
        $hoursWindow = stobeRegularMemoryGetGametsLimitFor($safeNpc);
        $timeThreshold = max(0, intval(round($currentGamets - ($hoursWindow / 0.0000024), 0) - 1));
    }

    $resWithContext = stobeRegularMemorySearchByVector($npcData, $safeNpc, $queryText, $timeThreshold, true);
    $resWithoutContext = stobeRegularMemorySearchByVector($npcData, $safeNpc, $queryText, $timeThreshold, false);

    $selected = null;
    if (is_array($resWithContext) && is_array($resWithoutContext)) {
        $rankA = floatval($resWithContext['rank_any'] ?? -INF);
        $rankB = floatval($resWithoutContext['rank_any'] ?? -INF);
        $selected = ($rankA >= $rankB) ? $resWithContext : $resWithoutContext;
    } elseif (is_array($resWithContext)) {
        $selected = $resWithContext;
    } elseif (is_array($resWithoutContext)) {
        $selected = $resWithoutContext;
    }

    if (!is_array($selected)) {
        return [];
    }

    return [$selected];
}

function stobeRegularMemoryGetGametsLimitFor(string $npcName): float
{
    static $cache = [];

    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return 72.0;
    }
    $cacheKey = strtolower($safeNpc);
    if (isset($cache[$cacheKey])) {
        return floatval($cache[$cacheKey]);
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $cache[$cacheKey] = 72.0;
        return 72.0;
    }

    $limit = getSettingInt('CONTEXT_HISTORY', 75);
    if ($limit < 5) {
        $limit = 5;
    } elseif ($limit > 300) {
        $limit = 300;
    }

    $row = $db->fetchOne(
        "SELECT (MAX(gamets) - MIN(gamets)) * 0.0000024 AS hour_threshold
         FROM (
             SELECT gamets
             FROM eventlog
             WHERE type = 'chat'
               AND LOWER(COALESCE(people, '')) LIKE LOWER($1)
             ORDER BY gamets DESC
             LIMIT " . intval($limit) . "
         ) AS recent_events",
        ['%' . $safeNpc . '%']
    );

    $hours = floatval($row['hour_threshold'] ?? 0.0);
    if (!is_finite($hours) || $hours <= 0.0) {
        $hours = 72.0;
    }

    $cache[$cacheKey] = $hours;
    return $hours;
}

function stobeBuildRegularMemoryPromptBlock(
    array $npcData,
    string $npcName,
    string $queryText = '',
    int $currentGamets = 0
): string {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '' || strcasecmp($safeNpc, 'The Narrator') === 0) {
        return '';
    }
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return '';
    }

    // Herika-style memory offering: inject one best memory line, not a list block.
    $rows = stobeRegularMemoryRecallRows($npcData, $safeNpc, $queryText, $currentGamets, 1);
    if (count($rows) === 0) {
        return '';
    }

    $row = $rows[0] ?? [];
    if (!is_array($row)) {
        return '';
    }

    $rankAny = array_key_exists('rank_any', $row) ? floatval($row['rank_any']) : null;
    $rankAll = array_key_exists('rank_all', $row) ? floatval($row['rank_all']) : null;
    $thresholdModifier = getSettingFloat('MEMORY_THRESHOLD_MODIFIER', 0.0);
    $accepted = false;
    if ($rankAny !== null && $rankAll !== null) {
        if (abs($rankAny - $rankAll) < 0.00001 && $rankAny > (0.25 + $thresholdModifier)) {
            $accepted = true;
        } elseif ((($rankAll + $rankAny) / 2.0) > (0.25 + $thresholdModifier)) {
            $accepted = true;
        } elseif (($rankAny > (0.50 + $thresholdModifier)) && array_key_exists('mixed_distance', $row)) {
            $accepted = true;
        }
    }
    if (!$accepted) {
        return '';
    }

    $summary = trim(sanitizeForKenshi(strval($row['summary'] ?? '')));
    if ($summary === '') {
        return '';
    }

    // Keep summary content, but strip compacted tag payloads to match Herika memory style.
    $summary = trim(preg_replace('/#Tags:.*$/mi', '', $summary) ?? $summary);
    if ($summary === '') {
        return '';
    }

    $rowGamets = max(0, intval($row['gamets_end'] ?? 0));
    $safeCurrentGamets = max(0, intval($currentGamets));
    $prefix = '';
    if ($safeCurrentGamets > 0 && $rowGamets > 0 && $safeCurrentGamets >= $rowGamets) {
        $hoursAgo = round(max(0.0, ($safeCurrentGamets - $rowGamets) * 0.0000024), 0);
        $limitHours = stobeRegularMemoryGetGametsLimitFor($safeNpc);
        if ($hoursAgo <= $limitHours) {
            // Herika-style: suppress "too recent" memories.
            return '';
        }

        $daysAgo = max(0, intval(floor(max(0.0, ($safeCurrentGamets - $rowGamets) * 0.0000001))));
        $dateLabel = function_exists('gamets2str_format_gregorian_date')
            ? trim(strval(gamets2str_format_gregorian_date($rowGamets, 'Y-m-d')))
            : '';
        if ($dateLabel !== '') {
            $prefix = strval($daysAgo) . ' days ago, on ' . $dateLabel . ' ... ';
        } else {
            $prefix = strval($daysAgo) . ' days ago ... ';
        }
    }

    return $prefix . truncatePromptValue($summary, 2200);
}
