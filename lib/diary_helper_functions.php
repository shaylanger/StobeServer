<?php

/**
 * Diary helper functions for StobeServer.
 * Profile-driven diary generation with per-NPC cooldown.
 */

$stobeBasePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($stobeBasePath . 'lib' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');

function stobeBuildDiaryCooldownKey(string $npcName): string {
    $normalized = strtolower(trim($npcName));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return 'DIARY_LAST_TIMESTAMP_' . $normalized;
}

function stobeAutoDiaryDaysSinceLastDiary(int $currentGamets, int $lastDiaryGamets): int {
    $currentDay = stobeResolveKenshiDayFromGamets($currentGamets);
    if ($currentDay < 0) {
        return 1;
    }
    if ($lastDiaryGamets <= 0) {
        return 1;
    }

    $lastDay = stobeResolveKenshiDayFromGamets($lastDiaryGamets);
    if ($lastDay < 0) {
        return 1;
    }

    return max(1, $currentDay - $lastDay);
}

function stobeDiaryBuildPromptTimeSpanBlock(
    int $summaryDayIndex,
    int $daysSinceLastDiary,
    int $summaryStartGamets,
    int $summaryEndGamets
): string {
    $startParts = stobeBuildKenshiDateFromGamets($summaryStartGamets);
    $endParts = stobeBuildKenshiDateFromGamets($summaryEndGamets);
    $dayNumber = intval($startParts['day_number'] ?? ($summaryDayIndex + 1));

    return "<diary_time_span>\n"
        . "  <summary_day_index>" . strval($summaryDayIndex) . "</summary_day_index>\n"
        . "  <summary_day_number>" . strval($dayNumber) . "</summary_day_number>\n"
        . "  <summary_start>" . stobePromptXmlEscape(strval($startParts['date_label'] ?? '')) . "</summary_start>\n"
        . "  <summary_end>" . stobePromptXmlEscape(strval($endParts['date_label'] ?? '')) . "</summary_end>\n"
        . "  <days_since_last_diary>" . strval(max(1, $daysSinceLastDiary)) . "</days_since_last_diary>\n"
        . "</diary_time_span>";
}

function stobeDiaryDayGametsRange(int $dayIndex): array {
    if ($dayIndex <= 0) {
        return [
            'start' => 0,
            'end' => 172799,
        ];
    }

    $start = ($dayIndex + 1) * 86400;
    return [
        'start' => $start,
        'end' => $start + 86399,
    ];
}

function stobeResolveAutoDiaryEligibleSummaryDay(int $currentGamets, int $triggerHour24): int {
    $parts = stobeBuildKenshiDateFromGamets($currentGamets);
    $currentDay = intval($parts['day_index'] ?? -1);
    $currentHour = intval($parts['hour_24'] ?? -1);
    $targetHour = max(0, min(23, $triggerHour24));

    if ($currentDay < 0 || $currentHour < 0) {
        return -1;
    }

    if ($currentHour >= $targetHour) {
        return $currentDay - 1;
    }

    return $currentDay - 2;
}

function stobeAutoDiaryRelevantEventExcludeTypes(): array {
    return [
        'prechat',
        'setconf',
        'status_msg',
        'user_input',
        'infonpc',
        'infoloc',
        'init',
        'inputtext',
        'inputtext_s',
        'diary',
        'auto_diary',
        'auto_diary_day',
        'backgroundlife_diary',
    ];
}

function stobeAutoDiaryEventTypeSqlList(array $types): string {
    $quoted = [];
    foreach ($types as $type) {
        $normalized = strtolower(trim(strval($type)));
        if ($normalized === '') {
            continue;
        }
        $quoted[] = "'" . str_replace("'", "''", $normalized) . "'";
    }
    if (count($quoted) === 0) {
        return "''";
    }
    return implode(',', array_values(array_unique($quoted)));
}

function stobeBuildDiaryHistoryTextForGametsRange(
    string $npcName,
    int $startGamets,
    int $endGamets,
    int $historyLimit
): string {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '' || $endGamets < $startGamets) {
        return '';
    }

    $limit = max(1, min(400, $historyLimit));
    $db = $GLOBALS["db"];
    $excludeSql = stobeAutoDiaryEventTypeSqlList(stobeAutoDiaryRelevantEventExcludeTypes());
    $params = [$startGamets, $endGamets];
    $audienceSql = stobeEventAudienceSql($safeNpcName, $params);
    $deliveryVisibilitySql = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';
    $rows = $db->fetchAll(
        "SELECT *
         FROM eventlog
         WHERE gamets >= $1
           AND gamets <= $2
           AND LOWER(COALESCE(type, '')) NOT IN ($excludeSql)
           AND {$deliveryVisibilitySql}
           AND {$audienceSql}
         ORDER BY COALESCE(NULLIF(localts, 0), ts, 0) ASC, ts ASC, rowid ASC
         LIMIT " . intval($limit),
        $params
    );
    $rows = stobeFilterNarratorRowsForContext($rows, $safeNpcName, 'diary');
    if (count($rows) === 0) {
        return '';
    }

    $historyLines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (function_exists('stobeEventRowActorHasAwarenessSuppressedTag')
            && stobeEventRowActorHasAwarenessSuppressedTag($row, $safeNpcName)) {
            continue;
        }
        $line = stobeFormatEventHistoryLine($row, true);
        if ($line === '') {
            continue;
        }
        $historyLines[] = $line;
    }
    return implode("\n", $historyLines);
}

function stobeCountRelevantDiaryEventsForGametsRange(string $npcName, int $startGamets, int $endGamets): int {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '' || $endGamets < $startGamets) {
        return 0;
    }

    $db = $GLOBALS["db"];
    $excludeSql = stobeAutoDiaryEventTypeSqlList(stobeAutoDiaryRelevantEventExcludeTypes());
    $params = [$startGamets, $endGamets];
    $audienceSql = stobeEventAudienceSql($safeNpcName, $params);
    $deliveryVisibilitySql = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS total
         FROM eventlog
         WHERE gamets >= $1
           AND gamets <= $2
           AND LOWER(COALESCE(type, '')) NOT IN ($excludeSql)
           AND {$deliveryVisibilitySql}
           AND {$audienceSql}",
        $params
    );

    return intval($row['total'] ?? 0);
}

function stobeAutoDiaryState(array|false $npcData): array {
    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $state = $extended['auto_diary_state'] ?? [];
    if (!is_array($state)) {
        $state = [];
    }
    return [
        'last_evaluated_day' => intval($state['last_evaluated_day'] ?? -1),
        'last_written_day' => intval($state['last_written_day'] ?? -1),
    ];
}

function stobeUpdateAutoDiaryState(array $npcData, ?int $evaluatedDay = null, ?int $writtenDay = null): void {
    $npcId = intval($npcData['id'] ?? 0);
    if ($npcId <= 0) {
        return;
    }

    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $state = $extended['auto_diary_state'] ?? [];
    if (!is_array($state)) {
        $state = [];
    }
    if ($evaluatedDay !== null) {
        $state['last_evaluated_day'] = intval($evaluatedDay);
    }
    if ($writtenDay !== null) {
        $state['last_written_day'] = intval($writtenDay);
    }
    $extended['auto_diary_state'] = $state;
    updateNpcById($npcId, ['extended_data' => $extended]);
}

function stobeNpcIsDeadForAutoDiary(array|false $npcData): bool {
    if (!is_array($npcData)) {
        return true;
    }

    $state = stobeResolveNpcAwarenessState($npcData);
    if ($state === 'dead') {
        return true;
    }

    $metadata = normalizeCoreNpcMetadata($npcData['metadata'] ?? []);
    $currentAction = strtolower(trim(strval($metadata['current_action'] ?? '')));
    if ($currentAction !== '' && preg_match('/\bdead\b/iu', $currentAction) === 1) {
        return true;
    }

    return false;
}

function stobeAutoDiaryFetchCandidates(int $limit = 512): array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    $safeLimit = max(1, min(1024, $limit));
    return $db->fetchAll(
        "SELECT id, name, profile_id, metadata, extended_data, gamets_last_updated
         FROM core_npc
         WHERE COALESCE(TRIM(name), '') <> ''
           AND LOWER(name) <> 'the narrator'
         ORDER BY COALESCE(gamets_last_updated, 0) DESC, updated_at DESC
         LIMIT " . intval($safeLimit)
    );
}

function stobeResolveKenshiDayFromGamets(int $gamets): int {
    $normalizedGamets = stobeGametsNormalize($gamets);
    if ($normalizedGamets <= 0) {
        return -1;
    }
    $parts = stobeGametsToDateParts($normalizedGamets);
    return intval($parts['day_index'] ?? -1);
}

function stobeBuildKenshiDateFromGamets(int $gamets): array {
    return stobeGametsToDateParts($gamets);
}

function stobeApplyKenshiDiaryHeader(string $diaryContent, array $kenshiDate): string {
    $header = 'Day ' . strval(intval($kenshiDate['day_number'] ?? 1)) . ', '
        . strval($kenshiDate['time_label'] ?? 'Midday') . '.';
    $content = trim($diaryContent);
    if ($content === '') {
        return $header;
    }

    $parts = preg_split('/\R+/', $content, 2);
    $firstLine = trim(strval($parts[0] ?? ''));
    $rest = isset($parts[1]) ? trim(strval($parts[1])) : '';
    $looksLikeHeader = preg_match('/^Day\s+\d+\s*,/i', $firstLine) === 1
        || preg_match('/^Day\s+\d+\b/i', $firstLine) === 1;

    if ($looksLikeHeader) {
        if ($rest === '') {
            return $header;
        }
        return $header . "\n\n" . $rest;
    }

    return $header . "\n\n" . $content;
}

function stobeGetLastDiaryGametsForNpc(string $npcName): int {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return 0;
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT gamets
         FROM diarylog
         WHERE LOWER(people) = LOWER($1)
         ORDER BY gamets DESC, localts DESC, rowid DESC
         LIMIT 1",
        [$safeNpcName]
    );

    return intval($row['gamets'] ?? 0);
}

function stobeResolveDiaryLocationText(string $npcName): string {
    $geo = ['location' => '', 'city' => '', 'region' => ''];
    $geo = mergeEventGeoContext($geo, getEventGeoFromNpcName($npcName));
    $geo = mergeEventGeoContext($geo, getEventGeoFromPlayerSnapshot());
    $geo = mergeEventGeoContext($geo, getRecentEventGeoFallback($npcName, 86400));
    return composeEventLocationText($geo);
}

function stobeBuildDiaryHistoryText(string $npcName, int $historyLimit): string {
    $rows = DataEventLog($historyLimit, $npcName);
    $rows = stobeFilterNarratorRowsForContext($rows, $npcName, 'diary');
    if (count($rows) === 0) {
        return '';
    }

    $historyLines = [];
    foreach (array_reverse($rows) as $row) {
        $line = stobeFormatEventHistoryLine($row, true);
        if ($line === '') {
            continue;
        }
        $historyLines[] = $line;
    }
    return implode("\n", $historyLines);
}

/**
 * Recall one relevant diary for narrator conversations without exposing general NPC memory.
 */
function stobeBuildNarratorDiaryRecallBlock(string $queryText = '', int $currentGamets = 0): string
{
    $narrator = function_exists('stobeGetNarrator') ? stobeGetNarrator() : null;
    if (!$narrator || !$narrator->getBool('diary_enabled', false)) {
        return '';
    }

    $params = [];
    $where = ["COALESCE(BTRIM(content), '') <> ''"];
    if ($narrator->getBool('only_diary_access', false)) {
        $params[] = stobeNarratorName();
        $where[] = 'LOWER(BTRIM(people)) = LOWER($' . count($params) . ')';
    }
    if ($currentGamets > 0) {
        $params[] = $currentGamets;
        $where[] = 'gamets <= $' . count($params);
    }

    $query = trim(sanitizeForKenshi($queryText));
    $order = 'gamets DESC, localts DESC, rowid DESC';
    if ($query !== '') {
        $params[] = $query;
        $queryParam = '$' . count($params);
        $order = "ts_rank_cd(to_tsvector('simple', COALESCE(topic, '') || ' ' || COALESCE(content, '')), websearch_to_tsquery('simple', {$queryParam})) DESC, "
            . $order;
    }

    try {
        $row = $GLOBALS['db']->fetchOne(
            'SELECT topic, content, people, gamets
             FROM diarylog
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $order . '
             LIMIT 1',
            $params
        );
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Narrator diary recall failed');
        return '';
    }

    $content = trim(strval($row['content'] ?? ''));
    if ($content === '') {
        return '';
    }
    $author = normalizeParticipantNameToken(strval($row['people'] ?? ''));
    if (function_exists('stobeIsNarratorName') && stobeIsNarratorName($author)) {
        $author = stobeNarratorRoleplayName();
    }
    $topic = trim(strval($row['topic'] ?? ''));
    $headerParts = array_values(array_filter([$author, $topic], static fn(string $value): bool => $value !== ''));
    $header = count($headerParts) > 0 ? implode(' - ', $headerParts) . ': ' : '';
    return truncatePromptValue($header . $content, 1800);
}

function stobeGenerateDiaryEntryForNpc(
    string $npcName,
    int $timestamp,
    int $gamets,
    string $triggerType = 'diary',
    bool $respectAutoFlags = false,
    bool $enforceDiaryDays = false,
    bool $skipCooldown = false,
    array $options = []
): array {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return ['ok' => false, 'reason' => 'missing_npc_name'];
    }

    $isNarrator = function_exists('stobeIsNarratorName') && stobeIsNarratorName($safeNpcName);
    $npcData = $isNarrator && function_exists('stobeBuildNarratorNpcData')
        ? stobeBuildNarratorNpcData()
        : getNpcData($safeNpcName);
    if (!$npcData && !$isNarrator) {
        storeNpcProfile($safeNpcName, []);
        $npcData = getNpcData($safeNpcName);
    }
    if (!$npcData) {
        return ['ok' => false, 'reason' => 'npc_profile_missing', 'npc_name' => $safeNpcName];
    }

    $narrator = $isNarrator && function_exists('stobeGetNarrator') ? stobeGetNarrator() : null;
    $manualDiaryEnabled = !$isNarrator || ($narrator && $narrator->getBool('diary_enabled', false));
    if (!$respectAutoFlags && !$manualDiaryEnabled) {
        return ['ok' => false, 'reason' => 'narrator_diary_disabled', 'npc_name' => $safeNpcName];
    }
    $autoDiaryEnabled = $isNarrator
        ? boolval($narrator && $narrator->getBool('auto_diary_enabled', false))
        : getNpcProfileBoolSetting(
            $npcData,
            ['AUTO_DIARY_ENABLED'],
            'AUTO_DIARY_ENABLED',
            false
        );
    if ($respectAutoFlags && !$autoDiaryEnabled) {
        return ['ok' => false, 'reason' => 'auto_diary_disabled', 'npc_name' => $safeNpcName];
    }

    if ($enforceDiaryDays) {
        $diaryDays = getNpcProfileIntegerSetting(
            $npcData,
            ['DIARY_DAYS'],
            'DIARY_DAYS',
            1,
            1,
            365
        );
        $currentDay = stobeResolveKenshiDayFromGamets($gamets);
        if ($currentDay < 0) {
            return ['ok' => false, 'reason' => 'missing_gamets', 'npc_name' => $safeNpcName];
        }

        $lastDiaryGamets = stobeGetLastDiaryGametsForNpc($safeNpcName);
        if ($lastDiaryGamets > 0) {
            $lastDay = stobeResolveKenshiDayFromGamets($lastDiaryGamets);
            if ($lastDay >= 0) {
                $daysSinceLastDiary = $currentDay - $lastDay;
                if ($daysSinceLastDiary < $diaryDays) {
                    return [
                        'ok' => false,
                        'reason' => 'diary_days_not_elapsed',
                        'npc_name' => $safeNpcName,
                        'current_day' => $currentDay,
                        'last_day' => $lastDay,
                        'required_days' => $diaryDays,
                    ];
                }
            }
        }
    }

    $cooldownKey = stobeBuildDiaryCooldownKey($safeNpcName);
    $nowTs = time();
    if (!$skipCooldown) {
        $cooldownSeconds = getNpcProfileIntegerSetting(
            $npcData,
            ['DIARY_COOLDOWN'],
            'DIARY_COOLDOWN',
            120,
            10,
            3600
        );
        $lastDiaryTs = intval(getConfOpt($cooldownKey, '0'));
        if ($lastDiaryTs > 0) {
            $elapsed = $nowTs - $lastDiaryTs;
            if ($elapsed >= 0 && $elapsed < $cooldownSeconds) {
                return [
                    'ok' => false,
                    'reason' => 'cooldown_active',
                    'npc_name' => $safeNpcName,
                    'remaining_seconds' => $cooldownSeconds - $elapsed,
                ];
            }
        }
    }

    $historyLimit = getNpcProfileIntegerSetting(
        $npcData,
        ['CONTEXT_HISTORY_DIARY'],
        '',
        100,
        0,
        400
    );
    if ($historyLimit <= 0) {
        $historyLimit = getNpcProfileIntegerSetting(
            $npcData,
            ['CONTEXT_HISTORY'],
            '',
            75,
            10,
            400
        );
    }
    $historyLimitOverride = intval($options['history_limit_override'] ?? 0);
    if ($historyLimitOverride > 0) {
        $historyLimit = max(1, min(400, $historyLimitOverride));
    }

    $historyText = trim(strval($options['history_text'] ?? ''));
    if ($historyText === '') {
        $historyText = stobeBuildDiaryHistoryText($safeNpcName, $historyLimit);
    }
    $kenshiDate = is_array($options['kenshi_date_override'] ?? null)
        ? $options['kenshi_date_override']
        : stobeBuildKenshiDateFromGamets($gamets);
    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    if ($playerName === '') {
        $playerName = 'Drifter';
    }

    $lastDiaryGametsForSpan = intval($options['last_diary_gamets'] ?? stobeGetLastDiaryGametsForNpc($safeNpcName));
    $daysSinceLastDiary = intval($options['days_since_last_diary'] ?? stobeAutoDiaryDaysSinceLastDiary($gamets, $lastDiaryGametsForSpan));
    $summaryDayIndex = intval($options['summary_day_index'] ?? ($kenshiDate['day_index'] ?? -1));
    $summaryStartGamets = intval($options['summary_start_gamets'] ?? $gamets);
    $summaryEndGamets = intval($options['summary_end_gamets'] ?? $gamets);

    $promptNpcName = $isNarrator && function_exists('stobeNarratorRoleplayName')
        ? stobeNarratorRoleplayName()
        : $safeNpcName;
    $systemPrompt = stobeBuildGameTimePromptBlock($gamets, $npcData)
        . "\n\n"
        . buildSystemPrompt($safeNpcName, $npcData, $playerName, '', false, 'chat', intval($gamets));
    $defaultDiaryModeRules = "<diary_mode>\n"
        . "  <rule>Write as #NPC_NAME# in first person.</rule>\n"
        . "  <rule>Focus on meaningful events, emotions, and observations.</rule>\n"
        . "  <rule>Keep a concise diary tone and avoid action tags.</rule>\n"
        . "  <rule>Use Kenshi timeline only, never real-world calendar dates.</rule>\n"
        . "  <current_ingame_datetime>#CURRENT_INGAME_DATETIME#</current_ingame_datetime>\n"
        . "  <rule>Output plain diary text only.</rule>\n"
        . "</diary_mode>";
    $diaryModeRulesTemplate = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('diary_mode_rules', $defaultDiaryModeRules)
        : $defaultDiaryModeRules;
    $diaryModeRules = strtr($diaryModeRulesTemplate, [
        '#NPC_NAME#' => stobePromptXmlEscape($promptNpcName),
        '#CURRENT_INGAME_DATETIME#' => stobePromptXmlEscape(strval($kenshiDate['date_label'] ?? '')),
    ]);
    $systemPrompt .= "\n\n" . $diaryModeRules;
    $systemPrompt .= "\n\n" . stobeDiaryBuildPromptTimeSpanBlock(
        $summaryDayIndex,
        $daysSinceLastDiary,
        $summaryStartGamets,
        $summaryEndGamets
    );

    $defaultDiaryPrompt = "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with exactly this header: \"#KENSHI_DIARY_HEADER#\".";
    $globalDiaryPrompt = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('DIARY_PROMPT', $defaultDiaryPrompt)
        : $defaultDiaryPrompt;
    $diaryPromptTemplate = getNpcProfileStringSetting(
        $npcData,
        ['DIARY_PROMPT'],
        'DIARY_PROMPT',
        $globalDiaryPrompt
    );
    $diaryPrompt = strtr($diaryPromptTemplate, [
        '#PLAYER_NAME#' => $playerName,
        '#NPC_NAME#' => $promptNpcName,
        '#HERIKA_NAME#' => $promptNpcName,
        '#KENSHI_DAY#' => strval(intval($kenshiDate['day_number'] ?? 1)),
        '#KENSHI_TIME_LABEL#' => strval($kenshiDate['time_label'] ?? 'Midday'),
        '#KENSHI_TIME_24#' => strval($kenshiDate['clock_24'] ?? '00:00'),
        '#KENSHI_TIME_12#' => strval($kenshiDate['clock_12'] ?? '12:00 AM'),
        '#KENSHI_DATE_LABEL#' => strval($kenshiDate['date_label'] ?? 'Day 1, Midday (00:00)'),
        '#DAYS_SINCE_LAST_DIARY#' => strval($daysSinceLastDiary),
        '#KENSHI_DIARY_HEADER#' => 'Day ' . strval(intval($kenshiDate['day_number'] ?? 1))
            . ', ' . strval($kenshiDate['time_label'] ?? 'Midday') . '.',
    ]);

    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt,
        ],
    ];
    if ($historyText !== '') {
        $messages[] = [
            'role' => 'user',
            'content' => stobeBuildRecentContextPromptBlock($historyText, 'recent_context'),
        ];
    }
    $messages[] = [
        'role' => 'user',
        'content' => "<diary_request>\n"
            . "  <instruction>" . stobePromptXmlEscape($diaryPrompt) . "</instruction>\n"
            . "</diary_request>",
    ];

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector/llm_dispatcher.php');

    $llmConfig = getLlmConfigForNpcPurpose($npcData, 'diary');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_api_key', 'npc_name' => $safeNpcName];
    }

    $rawResponse = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $safeNpcName,
        'event_type' => 'diary',
        'trigger_type' => strtolower(trim($triggerType)),
    ]);
    if ($rawResponse === false) {
        return ['ok' => false, 'reason' => 'llm_failed', 'npc_name' => $safeNpcName];
    }

    $diaryContent = trim(strval($rawResponse));
    if ($diaryContent === '') {
        return ['ok' => false, 'reason' => 'llm_empty', 'npc_name' => $safeNpcName];
    }
    $diaryContent = stobeApplyKenshiDiaryHeader($diaryContent, $kenshiDate);

    $location = trim(strval($options['location_text'] ?? ''));
    if ($location === '') {
        $location = stobeResolveDiaryLocationText($safeNpcName);
    }
    $tags = 'diary,' . strtolower(trim($triggerType));
    $topic = 'Diary Entry';
    $db = $GLOBALS["db"];
    $db->exec(
        "INSERT INTO diarylog (ts, sess, topic, content, tags, people, localts, location, gamets)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)",
        [
            strval($timestamp),
            'diary_' . strval($nowTs),
            $topic,
            $diaryContent,
            $tags,
            $safeNpcName,
            $nowTs,
            $location,
            $gamets,
        ]
    );
    if (function_exists('stobeRegularMemoryStoreRow')) {
        stobeRegularMemoryStoreRow(
            $safeNpcName,
            $diaryContent,
            'diary',
            intval($gamets),
            intval($nowTs),
            $location
        );
    }

    setConfOpt($cooldownKey, strval($nowTs));

    stobeLogInfo('Diary entry generated', [
        'npc_name' => $safeNpcName,
        'trigger_type' => strtolower(trim($triggerType)),
        'gamets' => intval($gamets),
        'kenshi_date' => strval($kenshiDate['date_label'] ?? ''),
        'history_limit' => $historyLimit,
        'days_since_last_diary' => $daysSinceLastDiary,
        'content_length' => strlen($diaryContent),
    ]);

    return [
        'ok' => true,
        'npc_name' => $safeNpcName,
        'content_length' => strlen($diaryContent),
        'history_limit' => $historyLimit,
        'days_since_last_diary' => $daysSinceLastDiary,
    ];
}

function stobeAutoDiaryTryLock(): bool {
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $row = $db->fetchOne("SELECT pg_try_advisory_lock(937463) AS locked");
    return !empty($row['locked']);
}

function stobeAutoDiaryUnlock(): void {
    $db = $GLOBALS['db'] ?? null;
    if ($db) {
        $db->exec("SELECT pg_advisory_unlock(937463)");
    }
}

function stobeMaybeRunAutoDiaryCycle(int $timestamp, int $gamets): void {
    if ($gamets <= 0) {
        return;
    }

    $currentParts = stobeBuildKenshiDateFromGamets($gamets);
    $currentDay = intval($currentParts['day_index'] ?? -1);
    $currentHour = intval($currentParts['hour_24'] ?? -1);
    if ($currentDay <= 0 || $currentHour < 0) {
        return;
    }

    if (!stobeAutoDiaryTryLock()) {
        return;
    }

    try {
        $candidates = stobeAutoDiaryFetchCandidates(512);
        $processed = 0;
        $attempted = 0;
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if ($processed >= 5) {
                break;
            }

            $npcName = normalizeParticipantNameToken(strval($candidate['name'] ?? ''));
            if ($npcName === '') {
                continue;
            }
            $npcData = getNpcData($npcName);
            if (!is_array($npcData)) {
                continue;
            }

            $autoDiaryHour = getNpcProfileIntegerSetting(
                $npcData,
                ['AUTO_DIARY_HOUR'],
                'AUTO_DIARY_HOUR',
                21,
                0,
                23
            );
            $summaryDay = stobeResolveAutoDiaryEligibleSummaryDay($gamets, $autoDiaryHour);
            if ($summaryDay < 0) {
                continue;
            }

            $state = stobeAutoDiaryState($npcData);
            if (intval($state['last_evaluated_day'] ?? -1) >= $summaryDay) {
                continue;
            }

            $summaryDayRange = stobeDiaryDayGametsRange($summaryDay);
            $attempted++;

            if (!getNpcProfileBoolSetting($npcData, ['AUTO_DIARY_ENABLED'], 'AUTO_DIARY_ENABLED', false)) {
                stobeUpdateAutoDiaryState($npcData, $summaryDay, null);
                $skipped++;
                $processed++;
                continue;
            }

            if (stobeNpcIsDeadForAutoDiary($npcData)) {
                stobeUpdateAutoDiaryState($npcData, $summaryDay, null);
                $skipped++;
                $processed++;
                continue;
            }

            $minimumEvents = getNpcProfileIntegerSetting(
                $npcData,
                ['AUTO_DIARY_MIN_EVENTS'],
                'AUTO_DIARY_MIN_EVENTS',
                50,
                1,
                1000
            );
            $relevantEventCount = stobeCountRelevantDiaryEventsForGametsRange($npcName, $summaryDayRange['start'], $summaryDayRange['end']);
            if ($relevantEventCount < $minimumEvents) {
                stobeUpdateAutoDiaryState($npcData, $summaryDay, null);
                $skipped++;
                $processed++;
                continue;
            }

            $lastDiaryGamets = stobeGetLastDiaryGametsForNpc($npcName);
            $windowDays = stobeAutoDiaryDaysSinceLastDiary($summaryDayRange['end'], $lastDiaryGamets);
            $historyStartDay = max(0, $summaryDay - ($windowDays - 1));
            $historyEndDay = $summaryDay;
            $historyStartRange = stobeDiaryDayGametsRange($historyStartDay);
            $historyEndRange = stobeDiaryDayGametsRange($historyEndDay);
            $historyRange = [
                'start' => intval($historyStartRange['start'] ?? 0),
                'end' => intval($historyEndRange['end'] ?? 0),
            ];

            $historyLimit = getNpcProfileIntegerSetting($npcData, ['CONTEXT_HISTORY_DIARY'], '', 100, 0, 400);
            if ($historyLimit <= 0) {
                $historyLimit = getNpcProfileIntegerSetting($npcData, ['CONTEXT_HISTORY'], '', 75, 10, 400);
            }

            $historyText = stobeBuildDiaryHistoryTextForGametsRange($npcName, $historyRange['start'], $historyRange['end'], $historyLimit);
            if ($historyText === '') {
                stobeUpdateAutoDiaryState($npcData, $summaryDay, null);
                $skipped++;
                $processed++;
                continue;
            }

            $summaryGamets = $summaryDayRange['end'];
            $result = stobeGenerateDiaryEntryForNpc(
                $npcName,
                $timestamp,
                $summaryGamets,
                'auto_diary_day',
                true,
                true,
                true,
                [
                    'history_text' => $historyText,
                    'history_limit_override' => $historyLimit,
                    'kenshi_date_override' => stobeBuildKenshiDateFromGamets($summaryGamets),
                    'summary_day_index' => $summaryDay,
                    'summary_start_gamets' => $historyRange['start'],
                    'summary_end_gamets' => $historyRange['end'],
                    'last_diary_gamets' => $lastDiaryGamets,
                    'days_since_last_diary' => $windowDays,
                ]
            );

            if (boolval($result['ok'] ?? false)) {
                stobeUpdateAutoDiaryState($npcData, $summaryDay, $summaryDay);
                $generated++;
            } else {
                stobeUpdateAutoDiaryState($npcData, $summaryDay, null);
                $reason = strtolower(trim(strval($result['reason'] ?? 'unknown')));
                if (
                    $reason === 'auto_diary_disabled' ||
                    $reason === 'diary_days_not_elapsed' ||
                    $reason === 'cooldown_active' ||
                    $reason === 'missing_gamets'
                ) {
                    $skipped++;
                } else {
                    $failed++;
                }
            }

            $processed++;
        }

        $narrator = function_exists('stobeGetNarrator') ? stobeGetNarrator() : null;
        if ($narrator && $narrator->getBool('auto_diary_enabled', false)) {
            $summaryDay = stobeResolveAutoDiaryEligibleSummaryDay($gamets, 21);
            $lastNarratorDay = intval(getConfOpt('NARRATOR_AUTO_DIARY_LAST_DAY', '-1'));
            if ($summaryDay >= 0 && $lastNarratorDay < $summaryDay) {
                $summaryRange = stobeDiaryDayGametsRange($summaryDay);
                $narratorName = stobeNarratorName();
                $historyText = stobeBuildDiaryHistoryTextForGametsRange(
                    $narratorName,
                    intval($summaryRange['start'] ?? 0),
                    intval($summaryRange['end'] ?? 0),
                    200
                );
                $attempted++;
                if ($historyText !== '') {
                    $result = stobeGenerateDiaryEntryForNpc(
                        $narratorName,
                        $timestamp,
                        intval($summaryRange['end'] ?? $gamets),
                        'auto_diary_day',
                        true,
                        false,
                        true,
                        [
                            'history_text' => $historyText,
                            'history_limit_override' => 200,
                            'kenshi_date_override' => stobeBuildKenshiDateFromGamets(
                                intval($summaryRange['end'] ?? $gamets)
                            ),
                            'summary_day_index' => $summaryDay,
                            'summary_start_gamets' => intval($summaryRange['start'] ?? 0),
                            'summary_end_gamets' => intval($summaryRange['end'] ?? $gamets),
                        ]
                    );
                    if (boolval($result['ok'] ?? false)) {
                        $generated++;
                    } else {
                        $failed++;
                    }
                } else {
                    $skipped++;
                }
                setConfOpt('NARRATOR_AUTO_DIARY_LAST_DAY', strval($summaryDay), true);
            }
        }

        setConfOpt('AUTO_DIARY_LAST_RUN_TS', strval(time()), true);
        setConfOpt('AUTO_DIARY_LAST_RUN_GAMETS', strval($gamets), true);

        if ($attempted > 0 || $generated > 0 || $failed > 0) {
            stobeLogInfo('Auto diary cycle processed', [
                'current_day' => $currentDay,
                'current_hour' => $currentHour,
                'attempted' => $attempted,
                'generated' => $generated,
                'skipped' => $skipped,
                'failed' => $failed,
                'processed' => $processed,
                'minimum_events_setting' => 'AUTO_DIARY_MIN_EVENTS',
                'hour_setting' => 'AUTO_DIARY_HOUR',
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Auto diary cycle failed', [
            'gamets' => $gamets,
        ]);
    } finally {
        stobeAutoDiaryUnlock();
    }
}

function stobeExtractDiaryCandidates(string $eventType, string $eventData): array {
    $profileName = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
    $dialogueData = parseDialogueEventData($eventData);
    $speaker = normalizeParticipantNameToken(strval($dialogueData['speaker'] ?? ''));
    $target = normalizeParticipantNameToken(strval($dialogueData['target'] ?? ''));

    $participants = extractParticipantNames([
        'people' => strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? '')),
        'profile' => $profileName,
        'speaker' => $speaker,
        'npcs' => [$target],
    ]);

    $eventNpc = normalizeParticipantNameToken($eventData);
    if (
        $eventNpc !== '' &&
        strpos($eventNpc, ':') === false &&
        strpos($eventNpc, '{') === false &&
        strpos($eventNpc, '[') !== 0
    ) {
        $participants[] = $eventNpc;
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $namesByKey = [];
    foreach ($participants as $participant) {
        $name = normalizeParticipantNameToken($participant);
        if ($name === '') {
            continue;
        }
        if ($playerName !== '' && strcasecmp($name, $playerName) === 0) {
            continue;
        }
        $key = strtolower($name);
        if (!isset($namesByKey[$key])) {
            $namesByKey[$key] = $name;
        }
    }

    if (count($namesByKey) === 0 && $profileName !== '') {
        $namesByKey[strtolower($profileName)] = $profileName;
    }

    return array_values($namesByKey);
}
