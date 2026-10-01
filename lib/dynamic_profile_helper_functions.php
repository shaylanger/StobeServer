<?php

/**
 * Dynamic profile generation runtime for StobeServer.
 *
 * Behavior:
 * - Periodically refreshes enabled NPC profile fields via LLM.
 * - Scheduling is owned by dynamic_profile_scheduler.php and web profile metadata.
 * - Interval uses Kenshi in-game gamets, not wall-clock time.
 * - Per-NPC real-time cooldown prevents bursty refresh loops.
 * - Respects NPC/profile layered setting DYNAMIC_PROFILE_ENABLED.
 */

function stobeDynamicProfileNormalizeKeyToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return $normalized;
}

function stobeDynamicProfileLastGametsKey(string $npcName): string
{
    return 'DYNAMIC_PROFILE_LAST_GAMETS_' . stobeDynamicProfileNormalizeKeyToken($npcName);
}

function stobeDynamicProfileLastRunTsKey(string $npcName): string
{
    return 'DYNAMIC_PROFILE_LAST_RUN_TS_' . stobeDynamicProfileNormalizeKeyToken($npcName);
}

function stobeDynamicProfileDeepIdentityKey(string $npcName): string
{
    return 'DYNAMIC_PROFILE_DEEP_IDENTITY_DONE_' . stobeDynamicProfileNormalizeKeyToken($npcName);
}

function stobeDynamicProfileAllowedEventType(string $eventType): bool
{
    $type = strtolower(trim($eventType));
    if ($type === '') {
        return false;
    }
    $allowed = ['chat', 'rechat', 'bored', 'inputtext', 'inputtext_s'];
    return in_array($type, $allowed, true);
}

function stobeDynamicProfileIntervalHours(): int
{
    $raw = trim(strval(getSetting('DYNAMIC_PROFILE_INTERVAL_HOURS', '')));
    if ($raw === '') {
        $raw = trim(strval(getConfOpt('DYNAMIC_PROFILE_INTERVAL_HOURS', '')));
    }

    // Backward compatibility: older plugin/runtime sent minutes.
    if ($raw === '') {
        $legacyRaw = trim(strval(getConfOpt('DYNAMIC_PROFILE_INTERVAL_MINUTES', '')));
        if ($legacyRaw === '') {
            $legacyRaw = trim(strval(getSetting('DYNAMIC_PROFILE_INTERVAL_MINUTES', '')));
        }
        $legacyMinutes = parseIntLike($legacyRaw, 1440);
        if ($legacyMinutes < 1) {
            $legacyMinutes = 60;
        }
        $hours = intval(ceil($legacyMinutes / 60));
    } else {
        $hours = parseIntLike($raw, 24);
    }

    if ($hours < 1) {
        $hours = 1;
    } elseif ($hours > 720) {
        $hours = 720;
    }
    return $hours;
}

function stobeDynamicProfileLoadGraceSeconds(): int
{
    $seconds = parseIntLike(getSetting('DYNAMIC_PROFILE_LOAD_GRACE_SECONDS', '60'), 60);
    if ($seconds < 5) {
        $seconds = 5;
    } elseif ($seconds > 300) {
        $seconds = 300;
    }
    return $seconds;
}

function stobeDynamicProfileRealtimeCooldownSeconds(): int
{
    $seconds = parseIntLike(
        getSetting('DYNAMIC_PROFILE_REALTIME_COOLDOWN_SECONDS', '900'),
        900
    );
    if ($seconds < 60) {
        $seconds = 60;
    } elseif ($seconds > 86400) {
        $seconds = 86400;
    }
    return $seconds;
}

function stobeDynamicProfileMarkLoadGrace(int $nowTs, int $seconds, string $reason): void
{
    if ($nowTs <= 0) {
        $nowTs = time();
    }
    if ($seconds < 1) {
        $seconds = 1;
    }
    $untilTs = $nowTs + $seconds;
    setConfOpt('DYNAMIC_PROFILE_LOAD_GRACE_UNTIL_TS', strval($untilTs), true);
    stobeLogInfo('Dynamic profile cooldown armed', [
        'reason' => $reason,
        'grace_seconds' => $seconds,
        'until_ts' => $untilTs,
    ]);
}

function stobeDynamicProfileHandleGlobalGametsRewind(int $gamets): bool
{
    if ($gamets <= 0) {
        return false;
    }
    $lastSeenGamets = intval(getConfOpt('DYNAMIC_PROFILE_LAST_SEEN_GAMETS', '0'));
    $nowTs = time();
    setConfOpt('DYNAMIC_PROFILE_LAST_SEEN_GAMETS', strval($gamets), true);
    setConfOpt('DYNAMIC_PROFILE_LAST_SEEN_TS', strval($nowTs), true);

    if ($lastSeenGamets > 0 && $gamets + 5 < $lastSeenGamets) {
        $graceSeconds = stobeDynamicProfileLoadGraceSeconds();
        stobeDynamicProfileMarkLoadGrace($nowTs, $graceSeconds, 'gamets_rewind_global');
        return true;
    }
    return false;
}

function stobeDynamicProfileInLoadGraceWindow(int $nowTs): bool
{
    if ($nowTs <= 0) {
        $nowTs = time();
    }
    $graceUntilTs = intval(getConfOpt('DYNAMIC_PROFILE_LOAD_GRACE_UNTIL_TS', '0'));
    return $graceUntilTs > 0 && $nowTs < $graceUntilTs;
}

function stobeDynamicProfileShouldRunCycle(string $eventType, int $gamets): bool
{
    if (!stobeDynamicProfileAllowedEventType($eventType)) {
        return false;
    }
    if ($gamets <= 0) {
        return false;
    }

    $nowTs = time();
    if (stobeDynamicProfileHandleGlobalGametsRewind($gamets)) {
        return false;
    }
    if (stobeDynamicProfileInLoadGraceWindow($nowTs)) {
        return false;
    }

    $lastRunTs = intval(getConfOpt('DYNAMIC_PROFILE_LAST_RUN_TS', '0'));
    if ($lastRunTs > 0 && ($nowTs - $lastRunTs) < 15) {
        return false;
    }

    return true;
}

function stobeDynamicProfileTryLock(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $row = $db->fetchOne("SELECT pg_try_advisory_lock(937462) AS locked");
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

function stobeDynamicProfileUnlock(): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return;
    }
    $db->exec("SELECT pg_advisory_unlock(937462)");
}

function stobeDynamicProfileFetchCandidates(int $limit = 64): array
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

    return $db->fetchAll(
        "SELECT id, name, profile_id, metadata, extended_data, gamets_last_updated
         FROM core_npc
         WHERE COALESCE(TRIM(name), '') <> ''
           AND LOWER(name) <> 'the narrator'
         ORDER BY COALESCE(gamets_last_updated, 0) DESC, updated_at DESC
         LIMIT " . intval($limit)
    );
}

function stobeDynamicProfileProcessNarrator(
    int $intervalHours,
    string $eventType,
    int $gamets
): bool {
    if (!function_exists('stobeGetNarrator')) {
        return false;
    }

    $narrator = stobeGetNarrator();
    if (!$narrator) {
        return false;
    }
    if (!$narrator->getBool('dynamic_profile', false)) {
        return false;
    }

    $narratorFields = $narrator->getDynamicProfileFields();
    if (count($narratorFields) === 0) {
        return false;
    }

    $narratorName = function_exists('stobeNarratorName') ? stobeNarratorName() : 'The Narrator';
    if (!stobeDynamicProfileNpcDue($narratorName, $gamets, $intervalHours)) {
        return false;
    }

    $narratorData = function_exists('stobeBuildNarratorNpcData')
        ? stobeBuildNarratorNpcData()
        : [];
    if (!is_array($narratorData)) {
        $narratorData = [];
    }

    $metadata = normalizeCoreNpcMetadata($narratorData['metadata'] ?? []);
    $metadata['DYNAMIC_PROFILE_ENABLED'] = true;
    $metadata['DYNAMIC_PROFILE_FIELDS'] = array_values($narratorFields);
    $narratorData['metadata'] = $metadata;
    $narratorData['dynamic_profile'] = 1;
    $narratorData['profile_id'] = max(1, intval($narratorData['profile_id'] ?? 1));

    $rows = stobeDynamicProfileFetchRecentContext($narratorName, 30);
    $contextText = stobeDynamicProfileBuildContextText($rows);
    if ($contextText === '(none)') {
        return false;
    }

    $gen = stobeDynamicProfileGenerateUpdates($narratorName, $narratorData, $contextText);
    if (!boolval($gen['ok'] ?? false)) {
        stobeLogWarn('Dynamic profile generation skipped', [
            'npc_name' => $narratorName,
            'reason' => strval($gen['reason'] ?? 'unknown'),
        ]);
        return false;
    }

    $updates = is_array($gen['updates'] ?? null) ? $gen['updates'] : [];
    if (count($updates) === 0) {
        return false;
    }

    $payload = [];
    foreach ($updates as $field => $value) {
        if (!is_string($value)) {
            continue;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            continue;
        }
        if ($field === 'personality' || $field === 'speechstyle' || $field === 'goals') {
            $payload[$field] = $trimmed;
        } elseif ($field === 'backstory') {
            $payload['background'] = $trimmed;
        }
    }
    if (count($payload) === 0) {
        return false;
    }

    $payload['gamets_last_updated'] = strval($gamets > 0 ? $gamets : time());
    $narrator->setMultiple($payload);

    if ($gamets > 0) {
        setConfOpt(stobeDynamicProfileLastGametsKey($narratorName), strval($gamets), true);
    }
    setConfOpt(stobeDynamicProfileLastRunTsKey($narratorName), strval(time()), true);

    stobeLogInfo('Dynamic profile updated', [
        'npc_name' => $narratorName,
        'npc_id' => 'core_narrator',
        'fields_updated' => array_keys($payload),
        'allowed_fields' => $gen['allowed_fields'] ?? [],
        'interval_hours' => $intervalHours,
        'event_type' => $eventType,
        'gamets' => $gamets,
    ]);

    return true;
}

function stobeDynamicProfileNpcEnabled(array $npcData): bool
{
    return getNpcProfileBoolSetting(
        $npcData,
        ['dynamic_profile_enabled', 'DYNAMIC_PROFILE_ENABLED'],
        'DYNAMIC_PROFILE_ENABLED',
        true
    );
}

function stobeDynamicProfileNpcDue(string $npcName, int $currentGamets, int $intervalHours): bool
{
    if ($currentGamets <= 0) {
        return false;
    }

    $gametsKey = stobeDynamicProfileLastGametsKey($npcName);
    $lastRunTsKey = stobeDynamicProfileLastRunTsKey($npcName);
    $lastRunGamets = intval(getConfOpt($gametsKey, '0'));
    $lastRunTs = intval(getConfOpt($lastRunTsKey, '0'));
    $nowTs = time();

    // Seed missing per-NPC state to current time/gamets so newly discovered
    // faction NPCs do not backfill into an immediate LLM burst.
    if ($lastRunGamets <= 0) {
        $hadLegacyTs = $lastRunTs > 0;
        setConfOpt($gametsKey, strval($currentGamets), true);
        setConfOpt($lastRunTsKey, strval($nowTs), true);
        stobeLogDebug(
            $hadLegacyTs
                ? 'Dynamic profile NPC timer migrated from wall-clock to seeded gamets state'
                : 'Dynamic profile NPC timer seeded on first sight',
            [
                'npc_name' => $npcName,
                'legacy_last_run_ts' => $lastRunTs,
                'current_gamets' => $currentGamets,
                'interval_hours' => $intervalHours,
            ]
        );
        return false;
    }

    if ($currentGamets + 5 < $lastRunGamets) {
        setConfOpt($gametsKey, strval($currentGamets), true);
        setConfOpt($lastRunTsKey, strval($nowTs), true);
        stobeLogDebug('Dynamic profile NPC timer rebased after gamets rewind', [
            'npc_name' => $npcName,
            'last_run_gamets' => $lastRunGamets,
            'current_gamets' => $currentGamets,
            'interval_hours' => $intervalHours,
        ]);
        return false;
    }

    $realtimeCooldownSeconds = stobeDynamicProfileRealtimeCooldownSeconds();
    if ($lastRunTs > 0 && ($nowTs - $lastRunTs) < $realtimeCooldownSeconds) {
        return false;
    }

    $intervalGamets = max(3600, $intervalHours * 3600);
    return ($currentGamets - $lastRunGamets) >= $intervalGamets;
}

function stobeDynamicProfileDecodeJsonObject(string $raw): array
{
    $text = trim($raw);
    if ($text === '') {
        return [];
    }

    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $fenced) === 1) {
        $text = trim($fenced[1]);
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $text, $match) === 1) {
        $decoded = json_decode($match[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeDynamicProfileIsMeaningful(string $value): bool
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return false;
    }
    $collapsed = strtolower(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
    $blocked = ['unknown', 'none', 'n/a', 'na', 'null', '(none)', 'not specified', 'no data', '{}', '[]'];
    if (in_array($collapsed, $blocked, true)) {
        return false;
    }
    return !str_starts_with($collapsed, 'no notable ');
}

function stobeDynamicProfileResolveAllowedFields(array $npcData): array
{
    $raw = null;
    $source = '';
    $fields = [];
    if (stobeReadLayeredSettingRaw(
        $npcData,
        ['dynamic_profile_fields', 'DYNAMIC_PROFILE_FIELDS'],
        'DYNAMIC_PROFILE_FIELDS',
        $raw,
        $source
    )) {
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_scalar($entry)) {
                    continue;
                }
                $fields[] = strtolower(trim(strval($entry)));
            }
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!is_scalar($entry)) {
                        continue;
                    }
                    $fields[] = strtolower(trim(strval($entry)));
                }
            } else {
                foreach (explode(',', $raw) as $entry) {
                    $fields[] = strtolower(trim($entry));
                }
            }
        }
    }

    $canonical = [];
    foreach ($fields as $field) {
        if ($field === 'npc_static_bio' || $field === 'bio') {
            $field = 'backstory';
        }
        if (!in_array($field, ['backstory', 'personality', 'occupation', 'speechstyle', 'goals'], true)) {
            continue;
        }
        if (!in_array($field, $canonical, true)) {
            $canonical[] = $field;
        }
    }

    if (count($canonical) === 0) {
        return ['backstory', 'personality', 'occupation', 'speechstyle', 'goals'];
    }
    return $canonical;
}

function stobeDynamicProfileNormalizeGeneratedFields(array $parsed, array $allowedFields): array
{
    $aliases = [
        'backstory' => ['backstory', 'npc_static_bio', 'bio', 'npc_bio'],
        'personality' => ['personality'],
        'occupation' => ['occupation'],
        'speechstyle' => ['speechstyle', 'speech_style'],
        'goals' => ['goals'],
    ];

    $updates = [];
    foreach ($aliases as $targetField => $keys) {
        if (!in_array($targetField, $allowedFields, true)) {
            continue;
        }
        $value = '';
        foreach ($keys as $key) {
            if (!array_key_exists($key, $parsed)) {
                continue;
            }
            $candidate = is_string($parsed[$key]) ? $parsed[$key] : json_encode($parsed[$key], JSON_UNESCAPED_UNICODE);
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim(preg_replace('/\s+/u', ' ', sanitizeForKenshi($candidate)) ?? $candidate);
            if (!stobeDynamicProfileIsMeaningful($candidate)) {
                continue;
            }
            $value = $candidate;
            break;
        }
        if ($value === '') {
            continue;
        }
        if (strlen($value) > 6000) {
            $value = substr($value, 0, 6000);
        }
        $updates[$targetField] = $value;
    }
    return $updates;
}

function stobeDynamicProfileHasRecentPlayerInteraction(string $npcName): bool
{
    static $recentRows = null;

    $safeNpc = normalizeParticipantNameToken($npcName);
    $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', ''));
    if ($safeNpc === '' || $player === '' || strcasecmp($safeNpc, $player) === 0) {
        return false;
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    if ($recentRows === null) {
        try {
            $recentRows = $db->fetchAll(
                "SELECT people, data, type
                 FROM eventlog
                 WHERE LOWER(COALESCE(type, '')) IN (
                    'chat', 'inputtext', 'inputtext_s', 'trade', 'healing',
                    'recruit', 'relationship', 'carry'
                 )
                 ORDER BY rowid DESC
                 LIMIT 600"
            );
        } catch (Throwable $exception) {
            $recentRows = [];
        }
    }

    foreach ($recentRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $haystack = strval($row['people'] ?? '') . ' ' . strval($row['data'] ?? '');
        if (stripos($haystack, $player) !== false && stripos($haystack, $safeNpc) !== false) {
            return true;
        }
    }
    return false;
}

function stobeDynamicProfileFetchRecentContext(string $npcName, int $limit = 30): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return [];
    }
    if ($limit < 10) {
        $limit = 10;
    } elseif ($limit > 80) {
        $limit = 80;
    }

    $deliveryVisibilitySql = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';

    $params = [];
    $audienceSql = stobeEventAudienceSql($safeNpcName, $params);

    return $db->fetchAll(
        "SELECT rowid AS id, type, data, gamets, localts, ts, people, location
         FROM eventlog
         WHERE type NOT IN (
             'setconf',
             'status_msg',
             'npc_snapshot',
             'playerinfo',
             'infonpc',
             'infoloc'
         )
           AND {$deliveryVisibilitySql}
           AND {$audienceSql}
         ORDER BY rowid DESC
         LIMIT " . intval($limit),
        $params
    );
}

function stobeDynamicProfileBuildContextText(array $rows): string
{
    if (count($rows) === 0) {
        return '(none)';
    }
    $lines = [];
    foreach (array_reverse($rows) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = stobeFormatEventHistoryLine($row, true);
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\s+/u', ' ', trim($line)) ?? $line;
        if (strlen($line) > 420) {
            $line = substr($line, 0, 420) . '...';
        }
        $lines[] = $line;
        if (count($lines) >= 18) {
            break;
        }
    }
    return count($lines) > 0 ? implode("\n", $lines) : '(none)';
}

function stobeDynamicProfileIsActualPlayer(array $npcData): bool
{
    $npcName = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
    $actualPlayerName = function_exists('getSetting')
        ? normalizeParticipantNameToken(getSetting('PLAYER_NAME', ''))
        : '';
    return $npcName !== ''
        && $actualPlayerName !== ''
        && strcasecmp($npcName, $actualPlayerName) === 0;
}

function stobeDynamicProfileDeepIdentityMatrixKey(string $npcName): string
{
    return 'DYNAMIC_PROFILE_IDENTITY_MATRIX_DONE_' . stobeDynamicProfileNormalizeKeyToken($npcName);
}

function stobeDynamicProfileHasDeepIdentityMatrix(array $npcData): bool
{
    $extended = function_exists('normalizeCoreNpcExtendedData')
        ? normalizeCoreNpcExtendedData($npcData['extended_data'] ?? [])
        : (is_array($npcData['extended_data'] ?? null) ? $npcData['extended_data'] : []);
    $matrix = $extended['deep_identity'] ?? null;
    return is_array($matrix) && count($matrix) >= 8;
}

function stobeDynamicProfileNormalizeIdentityMatrix(mixed $value): array
{
    $decoded = is_array($value) ? $value : [];
    if (!is_array($value) && is_string($value)) {
        $try = json_decode($value, true);
        if (is_array($try)) {
            $decoded = $try;
        }
    }
    if (count($decoded) === 0) {
        return [];
    }

    $allowed = [
        'roots_and_family',
        'formative_history',
        'everyday_tastes',
        'social_style',
        'values_and_beliefs',
        'fears_and_insecurities',
        'desires_and_wants',
        'romantic_preferences',
        'intimacy_preferences',
        'boundaries',
        'habits_and_quirks',
        'faction_and_place_opinions',
        'secrets',
        'contradictions',
    ];
    $out = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $decoded)) {
            continue;
        }
        $v = $decoded[$key];
        if (is_string($v)) {
            $clean = trim(sanitizeForKenshi($v));
            if ($clean !== '') {
                $out[$key] = truncatePromptValue($clean, 2200);
            }
            continue;
        }
        if (is_array($v)) {
            // Keep structured arrays/objects but sanitize scalar leaves and cap
            // size so one generated identity cannot dominate every chat prompt.
            $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || trim($json) === '') {
                continue;
            }
            if (strlen($json) > 5000) {
                $json = substr($json, 0, 5000);
            }
            $roundTrip = json_decode($json, true);
            if (is_array($roundTrip)) {
                $out[$key] = $roundTrip;
            }
        }
    }
    return $out;
}

function stobeDynamicProfileGenerateIdentityMatrix(
    string $npcName,
    array $npcData,
    string $recentContext
): array {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '' || stobeDynamicProfileIsActualPlayer($npcData)) {
        return ['ok' => false, 'reason' => 'not_npc', 'identity' => []];
    }
    if (function_exists('npcIsAnimal') && npcIsAnimal($npcData)) {
        return ['ok' => false, 'reason' => 'animal', 'identity' => []];
    }

    $profile = [
        'race' => trim(strval($npcData['race'] ?? '')),
        'faction' => trim(strval($npcData['faction'] ?? '')),
        'gender' => trim(strval($npcData['gender'] ?? '')),
        'backstory' => trim(strval($npcData['backstory'] ?? '')),
        'personality' => trim(strval($npcData['personality'] ?? '')),
        'occupation' => trim(strval($npcData['occupation'] ?? '')),
        'speechstyle' => trim(strval($npcData['speechstyle'] ?? '')),
        'goals' => trim(strval($npcData['goals'] ?? '')),
    ];

    $system = implode("\n", [
        'Create a persistent private identity matrix for one adult-style Kenshi NPC. This is background canon for future roleplay, not dialogue to be spoken now.',
        'Return STRICT JSON only. Use exactly these top-level keys: roots_and_family, formative_history, everyday_tastes, social_style, values_and_beliefs, fears_and_insecurities, desires_and_wants, romantic_preferences, intimacy_preferences, boundaries, habits_and_quirks, faction_and_place_opinions, secrets, contradictions.',
        'Ground every invention in the established profile and Kenshi material conditions. Do not contradict race, faction, known history, relationships, current goals, injuries, or recent events.',
        'Build a broad person, not a job archetype and not a summary of the latest conversation. Include ordinary tastes, comforts, annoyances, routines, humor, social preferences, moral limits, fears, private wants, long-term dreams, opinions about relevant places/factions, quirks, secrets, and internal contradictions.',
        'For formative_history use several concrete past-life hooks that fit the existing backstory. Do not invent current events.',
        'For clearly adult sapient characters, romantic_preferences and intimacy_preferences may include non-explicit attraction patterns, libido/interest level, preferred relationship or intimacy dynamics, emotional prerequisites, turn-ons in broad non-graphic terms, dislikes, and boundaries. If adulthood is uncertain, keep those fields non-sexual and relationship-focused. Never sexualize animals or minors.',
        'Private preferences and secrets must remain plausible and internally consistent. Avoid making every character unusually traumatic, kinky, heroic, famous, or exceptional.',
        'Use concise but specific values. Lists are encouraged where useful. This identity should supply many different conversational hooks over a long playthrough.',
    ]);

    $user = "<npc>\n"
        . "  <name>" . stobePromptXmlEscape($safeNpc) . "</name>\n"
        . "  <established_profile>" . stobePromptXmlEscape(json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</established_profile>\n"
        . "  <recent_context>" . stobePromptXmlEscape($recentContext) . "</recent_context>\n"
        . "</npc>";

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php');
    // Use the NPC's primary dialogue model for this one-time identity build.
    // It is a richer creative/consistency task than the routine dynamic-profile
    // refresh, and it runs in the background only once per NPC.
    $config = getLlmConfigForNpc($npcData);
    if (trim(strval($config['api_key'] ?? '')) === '') {
        $config = getLlmConfigForNpcPurpose($npcData, 'dynamic');
    }
    if (trim(strval($config['api_key'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_api_key', 'identity' => []];
    }
    if (intval($config['max_tokens'] ?? 0) < 2600) {
        $config['max_tokens'] = 2600;
    }

    $raw = stobeCallLLM(
        [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        $config,
        [
            'npc_name' => $safeNpc,
            'event_type' => 'deep_identity_generate',
            'response_format' => ['type' => 'json_object'],
        ]
    );
    if ($raw === false || trim(strval($raw)) === '') {
        return ['ok' => false, 'reason' => 'llm_failed', 'identity' => []];
    }
    $decoded = stobeDynamicProfileDecodeJsonObject(strval($raw));
    $identity = stobeDynamicProfileNormalizeIdentityMatrix($decoded);
    if (count($identity) < 8) {
        return ['ok' => false, 'reason' => 'identity_too_sparse', 'identity' => $identity];
    }
    return ['ok' => true, 'reason' => '', 'identity' => $identity];
}

function stobeDynamicProfilePersistIdentityMatrix(
    int $npcId,
    array $npcData,
    array $identity
): bool {
    if ($npcId <= 0 || count($identity) < 8) {
        return false;
    }
    $extended = function_exists('normalizeCoreNpcExtendedData')
        ? normalizeCoreNpcExtendedData($npcData['extended_data'] ?? [])
        : (is_array($npcData['extended_data'] ?? null) ? $npcData['extended_data'] : []);
    $extended['deep_identity'] = $identity;
    return updateNpcById($npcId, ['extended_data' => $extended]);
}

function stobeDynamicProfileNeedsDeepIdentity(array $npcData): bool
{
    if (function_exists('npcIsAnimal') && npcIsAnimal($npcData)) {
        return false;
    }

    // Never invent a lifetime, romantic preferences, secrets, etc. for the
    // human player's own character. Deep identity generation is NPC-only.
    if (stobeDynamicProfileIsActualPlayer($npcData)) {
        return false;
    }

    $backstory = trim(strval($npcData['backstory'] ?? ''));
    $personality = trim(strval($npcData['personality'] ?? ''));
    $goals = trim(strval($npcData['goals'] ?? ''));
    $speech = trim(strval($npcData['speechstyle'] ?? ''));

    return strlen($backstory) < 1000
        || strlen($personality) < 900
        || strlen($goals) < 550
        || strlen($speech) < 150;
}

function stobeDynamicProfileGenerateUpdates(string $npcName, array $npcData, string $recentContext): array
{
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return ['ok' => false, 'reason' => 'missing_npc_name'];
    }

    $allowedFields = stobeDynamicProfileResolveAllowedFields($npcData);
    if (count($allowedFields) === 0) {
        return ['ok' => false, 'reason' => 'no_allowed_fields'];
    }

    $currentState = [
        'backstory' => trim(strval($npcData['backstory'] ?? '')),
        'personality' => trim(strval($npcData['personality'] ?? '')),
        'occupation' => trim(strval($npcData['occupation'] ?? '')),
        'speechstyle' => trim(strval($npcData['speechstyle'] ?? '')),
        'goals' => trim(strval($npcData['goals'] ?? '')),
    ];

    $deepIdentityBootstrap = stobeDynamicProfileNeedsDeepIdentity($npcData);
    if ($deepIdentityBootstrap) {
        // The ordinary per-NPC dynamic-field allowlist is useful after a
        // character is established, but it should not strand a newly-created
        // NPC with a one-line biography forever. During the one-time bootstrap,
        // temporarily allow any sparse core identity field to be filled.
        $bootstrapThresholds = [
            'backstory' => 1000,
            'personality' => 900,
            'goals' => 550,
            'speechstyle' => 150,
        ];
        foreach ($bootstrapThresholds as $field => $minLength) {
            if (strlen($currentState[$field] ?? '') < $minLength
                && !in_array($field, $allowedFields, true)) {
                $allowedFields[] = $field;
            }
        }
    }

    $fieldList = implode(', ', $allowedFields);
    $defaultSystemPrompt = implode("\n", [
        'You create and maintain a deep, persistent identity for a Kenshi NPC as if they lived an entire life before the player met them.',
        'Return STRICT JSON only (no markdown, no prose outside the JSON object).',
        'Allowed keys: {"backstory":"","personality":"","occupation":"","speechstyle":"","goals":""}',
        'Respect every established fact in the current profile and recent context. Never contradict known race, faction, current role, relationships, injuries, location history, or events.',
        'When an existing field is sparse, enrich it substantially even if recent_context is minimal. When a field is already rich, preserve its established facts and evolve it conservatively.',
        'Treat established tastes, attraction patterns, boundaries, fears, values, habits, family facts, old relationships, faction opinions, place opinions, and core personality as persistent canon. Do not rewrite them because of one ordinary conversation. Change them only when recent context contains a genuinely significant experience that plausibly changes that trait.',
        'Give the NPC specific opinions about some relevant factions, places, lifestyles, foods, work, weapons, customs, or social behavior when plausible. Prefer a few concrete opinions over generic likes/dislikes, and preserve those opinions on later refreshes unless experience changes them.',
        'BACKSTORY: write a concrete life history, not a job description. Include plausible birthplace or region, family/upbringing, childhood conditions, formative incidents, prior work, travel, friendships or past relationships, losses, injuries, faction encounters, mentors/rivals, mistakes, accomplishments, and a believable chain of events leading to the current role. Give roughly 8-15 distinct life-history hooks the character could later reference.',
        'PERSONALITY: include temperament plus mundane tastes/dislikes, food/drink preferences when plausible, preferred environments and routines, humor, habits/quirks, values, moral boundaries, fears, insecurities, emotional needs, social style, relationship style, private contradictions, and one or two secrets or sensitive topics. For clearly adult sapient characters, you may also establish non-explicit romantic/attraction/intimacy preferences, desires, boundaries, and relationship expectations. Never sexualize animals or anyone not clearly adult.',
        'GOALS: include immediate concerns, medium-term wants, and long-term life ambitions. Allow goals to conflict realistically with fears, loyalties, relationships, or survival needs.',
        'OCCUPATION: keep the current real role accurate while allowing relevant former occupations in the backstory.',
        'SPEECHSTYLE: describe cadence, vocabulary, humor, emotional tells, and how speech changes under stress or intimacy; avoid caricature.',
        'Ground invented personal history in Kenshi lore and material conditions. Do not make the NPC secretly world-famous, uniquely chosen, implausibly wealthy, or connected to major lore figures without evidence.',
        'Private details are background truth, not things the NPC blurts out to strangers. The dialogue system will reveal them only when context and trust make sense.',
        'Avoid placeholders such as unknown/none and avoid repetitive generic survivor language.',
    ]);
    $systemPromptTemplate = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('dynamic_profile_generator', $defaultSystemPrompt)
        : $defaultSystemPrompt;
    if (strpos($systemPromptTemplate, '#ALLOWED_FIELDS#') !== false) {
        $systemPrompt = str_replace('#ALLOWED_FIELDS#', $fieldList, $systemPromptTemplate);
    } else {
        $systemPrompt = rtrim($systemPromptTemplate) . "\nFields currently editable for this NPC: " . $fieldList;
    }

    // These rules are appended even when the UI/database contains an older
    // custom generator prompt, so the lifelike identity behavior cannot be
    // silently bypassed by a persisted stock template.
    $systemPrompt .= "\n\nDEEP IDENTITY REQUIREMENTS:\n"
        . "- Treat this NPC as a person who lived a full Kenshi life before meeting the player; never reduce the profile to a job label.\n"
        . "- Backstory should contain a plausible chain of 8-15 concrete life hooks: upbringing/family, formative hardship, former work, travel, friendships or past relationships, losses/injuries, faction experiences, mentors/rivals, mistakes, accomplishments, and how they reached the current role.\n"
        . "- Personality should include concrete tastes/dislikes, routines, humor, habits, values, moral boundaries, fears, insecurities, emotional needs, social/relationship style, contradictions, and private topics or secrets. For clearly adult sapient characters, non-explicit romantic/attraction/intimacy preferences, desires, and boundaries may be established. Never sexualize animals or anyone not clearly adult.\n"
        . "- Goals should include immediate concerns, medium-term wants, and long-term ambitions, including believable conflicts between desires, fears, loyalties, and survival.\n"
        . "- Add a few specific opinions about relevant factions, places, lifestyles, foods, work, weapons, customs, or social behavior when plausible.\n"
        . "- Existing tastes, attraction patterns, boundaries, fears, family facts, relationships, faction/place opinions, habits, and core personality are persistent canon. Do not rewrite them after ordinary conversation; evolve them only after genuinely significant events.\n"
        . "- Respect all known Kenshi facts and current profile facts. Keep inventions grounded and ordinary enough to fit the setting; no chosen-one, secret-celebrity, or unsupported major-lore connections.\n"
        . "- Private details are background truth, not information the NPC automatically volunteers.";

    $deepIdentityBootstrap = stobeDynamicProfileNeedsDeepIdentity($npcData);
    if ($deepIdentityBootstrap) {
        $systemPrompt .= "\n\nIDENTITY BOOTSTRAP MODE:\n"
            . "This profile is currently too thin. Build a BROAD identity across an entire lifetime rather than overfitting to the latest conversation cluster. Recent_context is evidence, not the whole personality.\n"
            . "For every allowed sparse field, return substantial usable text. Suggested sizes: backstory 1200-2200 characters, personality 900-1600 characters, goals 550-1000 characters, speechstyle 180-450 characters.\n"
            . "Balance intimate/romantic material with ordinary tastes, family/history, work, morality, fears, humor, routines, social behavior, faction/place opinions, hobbies or comforts, ambitions, and contradictions. A recent sexual, romantic, combat, or traumatic scene must not become the NPC's entire identity.";
    }

    $userSections = [];
    $userSections[] = '<npc_name>' . stobePromptXmlEscape($safeNpcName) . '</npc_name>';
    $userSections[] = '<race>' . stobePromptXmlEscape(trim(strval($npcData['race'] ?? 'Unknown'))) . '</race>';
    $userSections[] = '<faction>' . stobePromptXmlEscape(trim(strval($npcData['faction'] ?? 'Unknown'))) . '</faction>';
    $userSections[] = '<current_profile_fields>';
    foreach ($currentState as $key => $value) {
        $safeValue = $value === '' ? '(empty)' : $value;
        $userSections[] = '  <' . $key . '>' . stobePromptXmlEscape($safeValue) . '</' . $key . '>';
    }
    $userSections[] = '</current_profile_fields>';

    if (function_exists('stobeBuildNpcRelationshipsText')) {
        $playerNameForProfile = function_exists('getSetting')
            ? normalizeParticipantNameToken(getSetting('PLAYER_NAME', ''))
            : '';
        $relationshipText = trim(stobeBuildNpcRelationshipsText(
            $safeNpcName,
            $playerNameForProfile,
            $npcData
        ));
        if ($relationshipText !== '') {
            $userSections[] = '<established_relationships>';
            $userSections[] = stobePromptXmlEscape($relationshipText);
            $userSections[] = '</established_relationships>';
        }
    }
    if (function_exists('normalizeNpcMetadataPayload') && function_exists('stobeBuildNpcConditionText')) {
        $profileMeta = normalizeNpcMetadataPayload($npcData['metadata'] ?? []);
        $profileCondition = trim(stobeBuildNpcConditionText($npcData, $profileMeta));
        if ($profileCondition !== '') {
            $userSections[] = '<current_condition>';
            $userSections[] = stobePromptXmlEscape($profileCondition);
            $userSections[] = '</current_condition>';
        }
    }

    $userSections[] = '<recent_context>';
    $userSections[] = stobePromptXmlEscape($recentContext);
    $userSections[] = '</recent_context>';

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => implode("\n", $userSections)],
    ];

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php');

    $llmConfig = getLlmConfigForNpcPurpose($npcData, 'dynamic');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_api_key'];
    }

    $llmConfigForGeneration = $llmConfig;
    $configuredMax = intval($llmConfigForGeneration['max_tokens'] ?? 0);
    // Some OpenRouter profile models spend a large part of max_tokens on
    // hidden reasoning before emitting JSON. A one-time deep bootstrap needs
    // enough headroom to finish the biography instead of returning truncated,
    // unparsable JSON. Routine refreshes remain smaller/cheaper.
    $minimumGenerationTokens = $deepIdentityBootstrap ? 5200 : 2600;
    if ($configuredMax < $minimumGenerationTokens) {
        $llmConfigForGeneration['max_tokens'] = $minimumGenerationTokens;
    }

    $callOptions = [
        'npc_name' => $safeNpcName,
        'event_type' => 'dynamic_profile_generate',
        'response_format' => ['type' => 'json_object'],
    ];
    $raw = stobeCallLLM($messages, $llmConfigForGeneration, $callOptions);
    if ($raw === false || trim(strval($raw)) === '') {
        // Dynamic/profile models can be unavailable even while the NPC's main
        // dialogue model is healthy. Fall back once to that primary model so a
        // sparse character does not remain one-dimensional indefinitely.
        $fallbackConfig = getLlmConfigForNpc($npcData);
        $fallbackKey = trim(strval($fallbackConfig['api_key'] ?? ''));
        $sameModel = strtolower(trim(strval($fallbackConfig['model'] ?? ''))) === strtolower(trim(strval($llmConfigForGeneration['model'] ?? '')));
        $sameBase = strtolower(trim(strval($fallbackConfig['base_url'] ?? ''))) === strtolower(trim(strval($llmConfigForGeneration['base_url'] ?? '')));
        if ($fallbackKey !== '' && !($sameModel && $sameBase)) {
            if (intval($fallbackConfig['max_tokens'] ?? 0) < 2600) {
                $fallbackConfig['max_tokens'] = 2600;
            }
            stobeLogInfo('Dynamic profile generation retrying on primary NPC model', [
                'npc_name' => $safeNpcName,
                'dynamic_model' => strval($llmConfigForGeneration['model'] ?? ''),
                'fallback_model' => strval($fallbackConfig['model'] ?? ''),
            ]);
            $raw = stobeCallLLM($messages, $fallbackConfig, $callOptions);
        }
    }
    if ($raw === false || trim(strval($raw)) === '') {
        return ['ok' => false, 'reason' => 'llm_failed'];
    }

    $parsed = stobeDynamicProfileDecodeJsonObject(strval($raw));
    if (count($parsed) === 0) {
        return ['ok' => false, 'reason' => 'parse_failed', 'response_preview' => substr(strval($raw), 0, 300)];
    }

    $updates = stobeDynamicProfileNormalizeGeneratedFields($parsed, $allowedFields);
    if (count($updates) === 0) {
        return ['ok' => false, 'reason' => 'no_usable_updates'];
    }

    return [
        'ok' => true,
        'updates' => $updates,
        'allowed_fields' => $allowedFields,
    ];
}

// Legacy callers use the same server scheduler; foreground requests never generate automatically.
function stobeMaybeRunDynamicProfileCycle(string $eventType, int $timestamp, int $gamets, string $eventData = ''): void {
    if (PHP_SAPI !== 'cli') return;
    require_once __DIR__ . '/dynamic_profile_scheduler.php';
    dps_run();
    stobeLifelikeIdentityCycle($eventType, $timestamp, $gamets, $eventData);
}

/**
 * Lifelike identity bootstrap (deep identity text + identity matrix) for NPCs the
 * player actually deals with. Runs from the background manager after the
 * upstream profile scheduler; regular profile refreshes are left to that scheduler.
 */
function stobeLifelikeIdentityCycle(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData = ''
): void {
    if (!stobeDynamicProfileShouldRunCycle($eventType, $gamets)) {
        return;
    }

    if (!stobeDynamicProfileTryLock()) {
        return;
    }

    try {
        $intervalHours = stobeDynamicProfileIntervalHours();
        $processed = false;
        if (stobeDynamicProfileProcessNarrator($intervalHours, $eventType, $gamets)) {
            $processed = true;
        }
        $candidates = stobeDynamicProfileFetchCandidates(64);

        foreach ($candidates as $candidate) {
            if ($processed) {
                break;
            }
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
            if (!stobeDynamicProfileNpcEnabled($npcData)) {
                continue;
            }
            $deepIdentityDone = intval(getConfOpt(stobeDynamicProfileDeepIdentityKey($npcName), '0')) > 0;
            $deepIdentityNeeded = !$deepIdentityDone && stobeDynamicProfileNeedsDeepIdentity($npcData);
            $deepAttemptKey = stobeDynamicProfileDeepIdentityKey($npcName) . '_LAST_ATTEMPT_TS';
            $deepLastAttemptTs = intval(getConfOpt($deepAttemptKey, '0'));
            $deepRetryReady = $deepLastAttemptTs <= 0 || (time() - $deepLastAttemptTs) >= 600;
            $needsDeepIdentity = $deepIdentityNeeded && $deepRetryReady;

            $matrixDone = intval(getConfOpt(stobeDynamicProfileDeepIdentityMatrixKey($npcName), '0')) > 0
                || stobeDynamicProfileHasDeepIdentityMatrix($npcData);
            $matrixAttemptKey = stobeDynamicProfileDeepIdentityMatrixKey($npcName) . '_LAST_ATTEMPT_TS';
            $matrixLastAttemptTs = intval(getConfOpt($matrixAttemptKey, '0'));
            $matrixRetryReady = $matrixLastAttemptTs <= 0 || (time() - $matrixLastAttemptTs) >= 600;
            $matrixInteractionEvent = in_array(strtolower(trim($eventType)), [
                'chat', 'inputtext', 'rechat', 'bored', 'trade', 'healing',
                'carry', 'recruit', 'relationship'
            ], true);
            $matrixMentionedInEvent = $eventData !== ''
                && stripos($eventData, $npcName) !== false;
            $matrixRelevantNpc = (function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npcData))
                || !empty($npcData['npc_favorite'])
                || stobeDynamicProfileHasRecentPlayerInteraction($npcName)
                || ($matrixInteractionEvent && $matrixMentionedInEvent);
            // Keep the richer text-profile bootstrap scoped to the same set so
            // telemetry from random world NPCs cannot cause a wave of LLM calls.
            $needsDeepIdentity = $needsDeepIdentity && $matrixRelevantNpc;
            $needsIdentityMatrix = !$matrixDone
                && $matrixRelevantNpc
                && !stobeDynamicProfileIsActualPlayer($npcData)
                && !(function_exists('npcIsAnimal') && npcIsAnimal($npcData))
                && $matrixRetryReady;

            $regularDue = false; // regular profile updates run in dynamic_profile_scheduler.php
            if ($regularDue && !$matrixRelevantNpc) {
                $regularDue = false;
            }
            if (!$regularDue && !$needsDeepIdentity && !$needsIdentityMatrix) {
                continue;
            }
            if ($needsDeepIdentity) {
                setConfOpt($deepAttemptKey, strval(time()), true);
            }
            if ($needsIdentityMatrix) {
                setConfOpt($matrixAttemptKey, strval(time()), true);
            }

            $rows = stobeDynamicProfileFetchRecentContext($npcName, 30);
            if ($regularDue && !$needsDeepIdentity && !$needsIdentityMatrix) {
                $lastProfileGamets = intval(getConfOpt(stobeDynamicProfileLastGametsKey($npcName), '0'));
                $hasFreshContext = false;
                foreach ($rows as $contextRow) {
                    if (is_array($contextRow) && intval($contextRow['gamets'] ?? 0) > $lastProfileGamets) {
                        $hasFreshContext = true;
                        break;
                    }
                }
                if (!$hasFreshContext) {
                    setConfOpt(stobeDynamicProfileLastGametsKey($npcName), strval($gamets), true);
                    setConfOpt(stobeDynamicProfileLastRunTsKey($npcName), strval(time()), true);
                    continue;
                }
            }
            $contextText = stobeDynamicProfileBuildContextText($rows);
            if ($contextText === '(none)' && !$needsDeepIdentity && !$needsIdentityMatrix) {
                continue;
            }
            if ($contextText === '(none)' && ($needsDeepIdentity || $needsIdentityMatrix)) {
                $contextText = '(No meaningful recent events yet. Build from established profile facts and grounded Kenshi context without inventing current events.)';
            }

            $npcId = intval($npcData['id'] ?? 0);
            if ($npcId <= 0) {
                continue;
            }

            $matrixApplied = false;
            if ($needsIdentityMatrix) {
                $matrixGen = stobeDynamicProfileGenerateIdentityMatrix($npcName, $npcData, $contextText);
                if (boolval($matrixGen['ok'] ?? false)) {
                    $identity = is_array($matrixGen['identity'] ?? null) ? $matrixGen['identity'] : [];
                    if (stobeDynamicProfilePersistIdentityMatrix($npcId, $npcData, $identity)) {
                        setConfOpt(stobeDynamicProfileDeepIdentityMatrixKey($npcName), '1', true);
                        $matrixApplied = true;
                        $npcData = getNpcData($npcName) ?: $npcData;
                        stobeLogInfo('Deep NPC identity matrix created', [
                            'npc_name' => $npcName,
                            'npc_id' => $npcId,
                            'sections' => array_keys($identity),
                        ]);
                    }
                } else {
                    stobeLogInfo('Deep NPC identity matrix generation deferred', [
                        'npc_name' => $npcName,
                        'reason' => strval($matrixGen['reason'] ?? 'unknown'),
                        'retry_after_seconds' => 600,
                    ]);
                }
            }

            // If this cycle was only needed to create the private identity
            // matrix, stop here; do not make a second LLM call unnecessarily.
            if (!$regularDue && !$needsDeepIdentity) {
                if ($matrixApplied) {
                    setConfOpt('DYNAMIC_PROFILE_LAST_RUN_TS', strval(time()), true);
                    $processed = true;
                    break;
                }
                continue;
            }

            $gen = stobeDynamicProfileGenerateUpdates($npcName, $npcData, $contextText);
            if (!boolval($gen['ok'] ?? false)) {
                stobeLogWarn('Dynamic profile generation skipped', [
                    'npc_name' => $npcName,
                    'reason' => strval($gen['reason'] ?? 'unknown'),
                ]);
                continue;
            }

            $updates = is_array($gen['updates'] ?? null) ? $gen['updates'] : [];
            if (count($updates) === 0) {
                continue;
            }

            updateNpcById($npcId, $updates);

            if ($needsDeepIdentity) {
                $projectedProfile = $npcData;
                foreach ($updates as $field => $value) {
                    $projectedProfile[$field] = $value;
                }
                if (!stobeDynamicProfileNeedsDeepIdentity($projectedProfile)) {
                    setConfOpt(stobeDynamicProfileDeepIdentityKey($npcName), '1', true);
                    stobeLogInfo('Deep NPC identity bootstrap completed', [
                        'npc_name' => $npcName,
                        'npc_id' => $npcId,
                        'fields_updated' => array_keys($updates),
                    ]);
                } else {
                    stobeLogInfo('Deep NPC identity bootstrap improved profile but remains sparse', [
                        'npc_name' => $npcName,
                        'npc_id' => $npcId,
                        'retry_after_seconds' => 600,
                        'fields_updated' => array_keys($updates),
                    ]);
                }
            }

            if ($gamets > 0) {
                setConfOpt(stobeDynamicProfileLastGametsKey($npcName), strval($gamets), true);
            }
            setConfOpt(stobeDynamicProfileLastRunTsKey($npcName), strval(time()), true);

            stobeLogInfo('Dynamic profile updated', [
                'npc_name' => $npcName,
                'npc_id' => $npcId,
                'fields_updated' => array_keys($updates),
                'allowed_fields' => $gen['allowed_fields'] ?? [],
                'interval_hours' => $intervalHours,
                'event_type' => $eventType,
                'gamets' => $gamets,
            ]);

            $processed = true;
            break; // One NPC per cycle to keep request latency bounded.
        }

        setConfOpt('DYNAMIC_PROFILE_LAST_RUN_TS', strval(time()), true);
        if ($gamets > 0) {
            setConfOpt('DYNAMIC_PROFILE_LAST_RUN_GAMETS', strval($gamets), true);
        }

        if (!$processed) {
            stobeLogDebug('Dynamic profile cycle completed with no eligible NPC work', [
                'event_type' => $eventType,
                'gamets' => $gamets,
                'candidate_count' => count($candidates),
                'interval_hours' => $intervalHours,
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Dynamic profile cycle failed', [
            'event_type' => $eventType,
            'gamets' => $gamets,
        ]);
    } finally {
        stobeDynamicProfileUnlock();
    }
}
