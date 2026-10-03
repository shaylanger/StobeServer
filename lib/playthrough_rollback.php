<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'settings.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_autosave.php');

function stobePlaythroughRollbackToleranceGamets(): int
{
    return 5;
}

/** Largest game-time step back (seconds) a delayed, non-init report may show without being treated as a reload. */
function stobePlaythroughLateEventMaxLagGamets(): int
{
    return max(3600, intval(getSettingInt('PLAYTHROUGH_LATE_EVENT_MAX_LAG_GAMETS', 43200)));
}

function stobePlaythroughRollbackEventIsAuthoritative(string $eventType): bool
{
    $event = strtolower(trim($eventType));
    if ($event === '') {
        return false;
    }

    // Keep regression tests exercising rollback paths.
    if (strpos($event, 'test_') === 0) {
        return true;
    }

    static $authoritativeEvents = [
        'init' => true,
        'gamedata' => true,
        'npc_snapshot' => true,
        'player_base_state' => true,
        'chat_json' => true,
        'item_image_upload' => true,
        'portrait_upload' => true,
        'faction_relations' => true,
    ];

    return isset($authoritativeEvents[$event]);
}

function stobePlaythroughNpcSnapshotIsSparse(array $row): bool
{
    $placeholderValues = ['', 'unknown', 'none', 'n/a', 'null', '[]', '{}'];
    foreach (['race', 'faction', 'gender', 'equipment', 'inventory', 'skills'] as $field) {
        $value = $row[$field] ?? null;
        if (is_array($value)) {
            if (count($value) > 0) {
                return false;
            }
            continue;
        }

        $normalized = strtolower(trim(strval($value)));
        if (!in_array($normalized, $placeholderValues, true)) {
            return false;
        }
    }

    return true;
}

function stobePlaythroughShouldSkipSparseNpcRestore(array $currentRow, array $historyRow): bool
{
    return !stobePlaythroughNpcSnapshotIsSparse($currentRow)
        && stobePlaythroughNpcSnapshotIsSparse($historyRow);
}

function stobePlaythroughRollbackLockKey(): int
{
    return 937463;
}

function stobePlaythroughAcquireRollbackLock(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $row = $db->fetchOne(
        'SELECT pg_try_advisory_lock($1) AS locked',
        [stobePlaythroughRollbackLockKey()]
    );
    if (!$row) {
        return false;
    }

    return stobePlaythroughToBool($row['locked'] ?? false);
}

function stobePlaythroughReleaseRollbackLock(): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return;
    }
    try {
        $db->exec('SELECT pg_advisory_unlock($1)', [stobePlaythroughRollbackLockKey()]);
    } catch (Throwable $exception) {
        stobeLogException($exception, 'PLAYTHROUGH: Failed to release rollback lock');
    }
}

function stobePlaythroughTableExists(string $tableName): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $row = $db->fetchOne(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = $2 LIMIT 1',
        ['public', strtolower(trim($tableName))]
    );

    return is_array($row);
}

function stobePlaythroughColumnExists(string $tableName, string $columnName): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $row = $db->fetchOne(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = $1
           AND table_name = $2
           AND column_name = $3
         LIMIT 1',
        ['public', strtolower(trim($tableName)), strtolower(trim($columnName))]
    );

    return is_array($row);
}

function stobePlaythroughDeleteCount(string $sql, array $params): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return 0;
    }

    $row = $db->fetchOne($sql, $params);
    return intval($row['c'] ?? 0);
}

function stobePlaythroughPruneFutureTimeline(int $cutoffGamets): array
{
    $cutoff = max(0, intval($cutoffGamets));
    $nowTs = time();

    $counts = [
        'eventlog' => 0,
        'diarylog' => 0,
        'memory' => 0,
        'memory_summary' => 0,
        'npc_history' => 0,
        'location_zones_deleted' => 0,
        'location_zones_rewound' => 0,
        'player_base_history_deleted' => 0,
        'player_bases_deleted' => 0,
        'player_bases_restored' => 0,
        'player_base_presence_cleared' => 0,
    ];

    if (stobePlaythroughTableExists('eventlog')) {
        $counts['eventlog'] = stobePlaythroughDeleteCount(
            'WITH deleted AS (
                DELETE FROM eventlog
                WHERE gamets >= $1 OR localts > $2
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM deleted',
            [$cutoff, $nowTs]
        );
    }

    if (stobePlaythroughTableExists('diarylog')) {
        $counts['diarylog'] = stobePlaythroughDeleteCount(
            'WITH deleted AS (
                DELETE FROM diarylog
                WHERE gamets >= $1 OR localts > $2
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM deleted',
            [$cutoff, $nowTs]
        );
    }

    if (stobePlaythroughTableExists('memory')) {
        $counts['memory'] = stobePlaythroughDeleteCount(
            'WITH deleted AS (
                DELETE FROM memory
                WHERE gamets > $1 OR localts > $2
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM deleted',
            [$cutoff, $nowTs]
        );
    }

    if (stobePlaythroughTableExists('memory_summary')) {
        $counts['memory_summary'] = stobePlaythroughDeleteCount(
            'WITH deleted AS (
                DELETE FROM memory_summary
                WHERE gamets_end > $1 OR localts > $2
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM deleted',
            [$cutoff, $nowTs]
        );
    }

    if (stobePlaythroughTableExists('core_npc_master_history')) {
        $counts['npc_history'] = stobePlaythroughDeleteCount(
            'WITH deleted AS (
                DELETE FROM core_npc_master_history
                WHERE gamets_last_updated > $1
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM deleted',
            [$cutoff]
        );
    }

    if (stobePlaythroughTableExists('location_zones')) {
        if (stobePlaythroughColumnExists('location_zones', 'first_game_ts')) {
            $counts['location_zones_deleted'] = stobePlaythroughDeleteCount(
                'WITH deleted AS (
                    DELETE FROM location_zones
                    WHERE COALESCE(first_game_ts, 0) > $1
                    RETURNING 1
                 ) SELECT COUNT(*)::int AS c FROM deleted',
                [$cutoff]
            );
        } else {
            // Legacy fallback: without first_game_ts, treat any forward-only zones as future discoveries.
            $counts['location_zones_deleted'] = stobePlaythroughDeleteCount(
                'WITH deleted AS (
                    DELETE FROM location_zones
                    WHERE COALESCE(last_game_ts, 0) > $1
                    RETURNING 1
                 ) SELECT COUNT(*)::int AS c FROM deleted',
                [$cutoff]
            );
        }

        $counts['location_zones_rewound'] = stobePlaythroughDeleteCount(
            'WITH updated AS (
                UPDATE location_zones
                SET last_game_ts = CASE
                        WHEN COALESCE(last_game_ts, 0) > $1 THEN $1
                        ELSE COALESCE(last_game_ts, 0)
                    END,
                    updated_at = NOW()
                WHERE COALESCE(last_game_ts, 0) > $1
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM updated',
            [$cutoff]
        );
    }

    if (stobePlaythroughTableExists('player_base_history')) {
        $counts['player_base_history_deleted'] = stobePlaythroughDeleteCount(
            'WITH deleted AS (
                DELETE FROM player_base_history
                WHERE game_ts > $1
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM deleted',
            [$cutoff]
        );
    }

    if (stobePlaythroughTableExists('player_bases')) {
        $counts['player_bases_deleted'] = stobePlaythroughDeleteCount(
            'WITH deleted AS (
                DELETE FROM player_bases
                WHERE first_game_ts > $1
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM deleted',
            [$cutoff]
        );

        if (stobePlaythroughTableExists('player_base_history')) {
            $counts['player_bases_restored'] = stobePlaythroughDeleteCount(
                'WITH latest AS (
                    SELECT DISTINCT ON (base_id)
                        base_id, name, power_generated, power_required,
                        battery_charge, battery_capacity, battery_drain, battery_charging,
                        battery_mode, has_spare_power, members_inside,
                        has_gates, gates_closed, details, game_ts, observed_at
                    FROM player_base_history
                    WHERE game_ts <= $1
                    ORDER BY base_id, game_ts DESC, id DESC
                 ),
                 updated AS (
                    UPDATE player_bases b
                    SET name = latest.name,
                        power_generated = latest.power_generated,
                        power_required = latest.power_required,
                        battery_charge = latest.battery_charge,
                        battery_capacity = latest.battery_capacity,
                        battery_drain = latest.battery_drain,
                        battery_charging = latest.battery_charging,
                        battery_mode = latest.battery_mode,
                        has_spare_power = latest.has_spare_power,
                        members_inside = latest.members_inside,
                        has_gates = latest.has_gates,
                        gates_closed = latest.gates_closed,
                        details = latest.details,
                        game_ts = latest.game_ts,
                        last_game_ts = latest.game_ts,
                        last_seen_at = latest.observed_at
                    FROM latest
                    WHERE b.base_id = latest.base_id
                    RETURNING 1
                 )
                 SELECT COUNT(*)::int AS c FROM updated',
                [$cutoff]
            );
        }
    }

    if (stobePlaythroughTableExists('player_base_presence')) {
        $counts['player_base_presence_cleared'] = stobePlaythroughDeleteCount(
            "WITH updated AS (
                UPDATE player_base_presence
                SET inside = FALSE,
                    base_id = NULL,
                    game_ts = $1,
                    observed_at = NOW()
                WHERE scope_key = 'selected_player'
                  AND (inside = TRUE OR base_id IS NOT NULL)
                RETURNING 1
             ) SELECT COUNT(*)::int AS c FROM updated",
            [$cutoff]
        );
    }

    return $counts;
}

function stobePlaythroughNormalizeRollbackJsonObject(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobePlaythroughShouldClearCharacterState(mixed $value): bool
{
    $state = strtolower(trim(strval($value ?? '')));
    if ($state === '') {
        return false;
    }
    return in_array(
        $state,
        ['unconscious', 'ko', 'knockedout', 'knocked_out', 'incapacitated', 'passed_out', 'blackout'],
        true
    );
}

function stobePlaythroughSanitizeVolatileNpcState(array &$payload): bool
{
    $changed = false;
    $volatileKeys = [
        'is_drunk',
        'drunk_level',
        'drunk_status',
        'drunk_seconds_remaining',
        'is_high',
        'high_status',
        'high_seconds_remaining',
        'high_hunger_rate_multiplier',
    ];

    foreach ($volatileKeys as $key) {
        if (array_key_exists($key, $payload)) {
            unset($payload[$key]);
            $changed = true;
        }
    }

    if (array_key_exists('character_state', $payload) && stobePlaythroughShouldClearCharacterState($payload['character_state'])) {
        unset($payload['character_state']);
        $changed = true;
    }

    if (isset($payload['medical']) && is_array($payload['medical'])) {
        $medical = $payload['medical'];
        foreach (['is_unconscious', 'is_knocked_out', 'is_knockedout'] as $medicalKey) {
            if (array_key_exists($medicalKey, $medical)) {
                unset($medical[$medicalKey]);
                $changed = true;
            }
        }
        if (count($medical) === 0) {
            unset($payload['medical']);
            $changed = true;
        } else {
            $payload['medical'] = $medical;
        }
    }

    foreach ($payload as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $child = $value;
        if (stobePlaythroughSanitizeVolatileNpcState($child)) {
            $payload[$key] = $child;
            $changed = true;
        }
    }

    return $changed;
}

function stobePlaythroughClearFutureVolatileNpcStates(int $cutoffGamets): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobePlaythroughTableExists('core_npc')) {
        return ['scanned' => 0, 'updated' => 0, 'errors' => 0];
    }

    $cutoff = max(0, intval($cutoffGamets));
    $rows = $db->fetchAll(
        'SELECT id, metadata, extended_data
         FROM core_npc
         WHERE COALESCE(gamets_last_updated, 0) > $1
         ORDER BY id ASC',
        [$cutoff]
    );
    if (!is_array($rows) || count($rows) === 0) {
        return ['scanned' => 0, 'updated' => 0, 'errors' => 0];
    }

    $counts = ['scanned' => 0, 'updated' => 0, 'errors' => 0];
    foreach ($rows as $row) {
        $npcId = intval($row['id'] ?? 0);
        if ($npcId <= 0) {
            continue;
        }
        $counts['scanned']++;

        $metadata = function_exists('normalizeCoreNpcMetadata')
            ? normalizeCoreNpcMetadata($row['metadata'] ?? '{}')
            : stobePlaythroughNormalizeRollbackJsonObject($row['metadata'] ?? '{}');
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $extended = function_exists('normalizeCoreNpcExtendedData')
            ? normalizeCoreNpcExtendedData($row['extended_data'] ?? '{}')
            : stobePlaythroughNormalizeRollbackJsonObject($row['extended_data'] ?? '{}');
        if (!is_array($extended)) {
            $extended = [];
        }

        $metadataChanged = stobePlaythroughSanitizeVolatileNpcState($metadata);
        $extendedChanged = stobePlaythroughSanitizeVolatileNpcState($extended);
        if (!$metadataChanged && !$extendedChanged) {
            continue;
        }

        $metadataJson = function_exists('normalizeJsonString')
            ? normalizeJsonString($metadata)
            : json_encode($metadata);
        $extendedJson = function_exists('normalizeJsonString')
            ? normalizeJsonString($extended)
            : json_encode($extended);
        if (!is_string($metadataJson) || trim($metadataJson) === '') {
            $metadataJson = '{}';
        }
        if (!is_string($extendedJson) || trim($extendedJson) === '') {
            $extendedJson = '{}';
        }

        $ok = $db->exec(
            'UPDATE core_npc
             SET metadata = $1::jsonb,
                 extended_data = $2::jsonb,
                 gamets_last_updated = CASE
                    WHEN COALESCE(gamets_last_updated, 0) > $3 THEN $3
                    ELSE COALESCE(gamets_last_updated, 0)
                 END,
                 updated_at = NOW()
             WHERE id = $4',
            [$metadataJson, $extendedJson, $cutoff, $npcId]
        );
        if ($ok === false) {
            $counts['errors']++;
        } else {
            $counts['updated']++;
        }
    }

    return $counts;
}

function stobePlaythroughClearRelationshipQueues(): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return ['relationship_eval_queue' => 0, 'relationship_init_queue' => 0];
    }

    $counts = [
        'relationship_eval_queue' => 0,
        'relationship_init_queue' => 0,
    ];

    foreach (['relationship_eval_queue', 'relationship_init_queue'] as $table) {
        if (!stobePlaythroughTableExists($table)) {
            continue;
        }

        $counts[$table] = stobePlaythroughDeleteCount(
            'WITH deleted AS (DELETE FROM ' . $table . ' RETURNING 1) SELECT COUNT(*)::int AS c FROM deleted',
            []
        );
    }

    return $counts;
}

function stobePlaythroughMapHistoryRowToCoreNpcFields(array $historyRow): array
{
    return [
        'name' => trim(strval($historyRow['name'] ?? '')),
        'original_name' => trim(strval($historyRow['original_name'] ?? '')),
        'npc_favorite' => stobePlaythroughToBool($historyRow['npc_favorite'] ?? false),
        'prompt_head' => strval($historyRow['prompt_head'] ?? ''),
        'personality' => strval($historyRow['personality'] ?? ''),
        'backstory' => strval($historyRow['backstory'] ?? ''),
        'emote_moods' => strval($historyRow['emote_moods'] ?? ''),
        'occupation' => strval($historyRow['occupation'] ?? ''),
        'appearance' => strval($historyRow['appearance'] ?? ''),
        'equipment' => strval($historyRow['equipment'] ?? ''),
        'inventory' => strval($historyRow['inventory'] ?? ''),
        'skills' => strval($historyRow['skills'] ?? ''),
        'speechstyle' => strval($historyRow['speechstyle'] ?? ''),
        'goals' => strval($historyRow['goals'] ?? ''),
        'relationships' => strval($historyRow['relationships'] ?? ''),
        'voiceid' => strval($historyRow['voiceid'] ?? ''),
        'metadata' => normalizeJsonString(normalizeCoreNpcMetadata($historyRow['metadata'] ?? '{}')),
        'race' => strval($historyRow['race'] ?? ''),
        'faction' => strval($historyRow['faction'] ?? ''),
        'gender' => strval($historyRow['gender'] ?? ''),
        'profile_id' => (($historyRow['profile_id'] ?? '') === '' ? null : intval($historyRow['profile_id'])),
        'extended_data' => normalizeJsonString(normalizeCoreNpcExtendedData($historyRow['extended_data'] ?? '{}')),
        'plugin_extended_data' => $historyRow['plugin_extended_data'] ?? '{}',
        'md5' => strval($historyRow['md5'] ?? ''),
        'gamets_last_updated' => intval($historyRow['gamets_last_updated'] ?? 0),
        'bounty' => stobeNormalizeBountyJsonString($historyRow['bounty'] ?? '{}'),
        'limbs' => normalizeJsonString(stobeNormalizeJsonArrayValue($historyRow['limbs'] ?? '{}')),
        'blood' => strval($historyRow['blood'] ?? '0/0'),
        'hunger' => strval($historyRow['hunger'] ?? '300/300'),
        'tags' => strval($historyRow['tags'] ?? ''),
        'is_animal' => stobePlaythroughToBool($historyRow['is_animal'] ?? false),
        'is_slave' => stobePlaythroughToBool($historyRow['is_slave'] ?? false),
        'world_knowledge_tags' => strval($historyRow['world_knowledge_tags'] ?? ''),
    ];
}

function stobePlaythroughCoreNpcColumns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $columns = [];
        return $columns;
    }

    $rows = $db->fetchAll(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'core_npc'"
    );

    $normalized = [];
    foreach ($rows as $row) {
        $name = strtolower(trim(strval($row['column_name'] ?? '')));
        if ($name === '') {
            continue;
        }
        $normalized[$name] = true;
    }

    $columns = $normalized;
    return $columns;
}

function stobePlaythroughHistoryColumns(): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $columns = [];
        return $columns;
    }

    $rows = $db->fetchAll(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'core_npc_master_history'"
    );

    $normalized = [];
    foreach ($rows as $row) {
        $name = strtolower(trim(strval($row['column_name'] ?? '')));
        if ($name === '') {
            continue;
        }
        $normalized[$name] = true;
    }

    $columns = $normalized;
    return $columns;
}

function stobePlaythroughRestoreNpcFromHistory(int $npcId, array $historyRow, ?bool $preserveRelationships = null): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || $npcId <= 0) {
        return false;
    }

    $preserveRelationships ??= getSettingBool('NEVER_CLEAR_RELATIONSHIP_DATA', false);
    $fields = stobePlaythroughMapHistoryRowToCoreNpcFields($historyRow);

    if (trim(strval($fields['name'] ?? '')) === '') {
        return false;
    }

    $availableColumns = stobePlaythroughCoreNpcColumns();
    $setClauses = [];
    $params = [];
    $paramIndex = 1;

    $appendField = static function (string $column, mixed $value, string $type = 'text') use (&$setClauses, &$params, &$paramIndex, $preserveRelationships): void {
        if ($type === 'json') {
            $incomingExpression = '$' . $paramIndex . '::jsonb';
            if ($column === 'extended_data') {
                // Keep current affinities atomically while other profile fields rewind.
                $expression = $preserveRelationships
                    ? stobeRelationshipPreservingExtendedDataSql($incomingExpression, $column)
                    : stobeIndividualMemoryPreservingExtendedDataSql($incomingExpression, $column);
                $setClauses[] = $column . ' = ' . $expression;
            } else {
                $setClauses[] = $column . ' = ' . $incomingExpression;
            }
        } else {
            $setClauses[] = $column . ' = $' . $paramIndex;
        }
        $params[] = $value;
        $paramIndex++;
    };

    $fieldsMap = [
        'name' => ['value' => $fields['name'], 'type' => 'text'],
        'original_name' => ['value' => $fields['original_name'], 'type' => 'text'],
        'npc_favorite' => ['value' => $fields['npc_favorite'], 'type' => 'bool'],
        'lock_profile' => ['value' => false, 'type' => 'bool'],
        'prompt_head' => ['value' => $fields['prompt_head'], 'type' => 'text'],
        'personality' => ['value' => $fields['personality'], 'type' => 'text'],
        'backstory' => ['value' => $fields['backstory'], 'type' => 'text'],
        'emote_moods' => ['value' => $fields['emote_moods'], 'type' => 'text'],
        'occupation' => ['value' => $fields['occupation'], 'type' => 'text'],
        'appearance' => ['value' => $fields['appearance'], 'type' => 'text'],
        'equipment' => ['value' => $fields['equipment'], 'type' => 'text'],
        'inventory' => ['value' => $fields['inventory'], 'type' => 'text'],
        'skills' => ['value' => $fields['skills'], 'type' => 'text'],
        'speechstyle' => ['value' => $fields['speechstyle'], 'type' => 'text'],
        'goals' => ['value' => $fields['goals'], 'type' => 'text'],
        'relationships' => ['value' => $fields['relationships'], 'type' => 'text'],
        'voiceid' => ['value' => $fields['voiceid'], 'type' => 'text'],
        'metadata' => ['value' => $fields['metadata'], 'type' => 'json'],
        'race' => ['value' => $fields['race'], 'type' => 'text'],
        'faction' => ['value' => $fields['faction'], 'type' => 'text'],
        'gender' => ['value' => $fields['gender'], 'type' => 'text'],
        'profile_id' => ['value' => $fields['profile_id'], 'type' => 'int_or_null'],
        'extended_data' => ['value' => $fields['extended_data'], 'type' => 'json'],
        'plugin_extended_data' => ['value' => $fields['plugin_extended_data'], 'type' => 'json'],
        'md5' => ['value' => $fields['md5'], 'type' => 'text'],
        'gamets_last_updated' => ['value' => intval($fields['gamets_last_updated']), 'type' => 'int'],
        'bounty' => ['value' => $fields['bounty'], 'type' => 'json'],
        'limbs' => ['value' => $fields['limbs'], 'type' => 'json'],
        'blood' => ['value' => $fields['blood'], 'type' => 'text'],
        'hunger' => ['value' => $fields['hunger'], 'type' => 'text'],
        'tags' => ['value' => $fields['tags'], 'type' => 'text'],
        'is_animal' => ['value' => $fields['is_animal'], 'type' => 'bool'],
        'is_slave' => ['value' => $fields['is_slave'], 'type' => 'bool'],
        'world_knowledge_tags' => ['value' => $fields['world_knowledge_tags'], 'type' => 'text'],
    ];

    foreach ($fieldsMap as $column => $meta) {
        if (!isset($availableColumns[strtolower($column)])) {
            continue;
        }

        $value = $meta['value'];
        $type = strval($meta['type'] ?? 'text');
        if ($type === 'bool') {
            $value = stobePlaythroughToBool($value);
        } elseif ($type === 'int') {
            $value = intval($value);
        } elseif ($type === 'int_or_null') {
            $raw = strval($value);
            $value = ($raw === '') ? null : intval($value);
        } elseif ($type === 'text') {
            $value = strval($value);
        }

        $appendField($column, $value, $type);
    }

    if (count($setClauses) === 0) {
        return false;
    }

    $params[] = $npcId;
    $query = 'UPDATE core_npc SET ' . implode(', ', $setClauses) . ', updated_at = NOW() WHERE id = $' . $paramIndex;

    $result = $db->exec($query, $params);
    return $result !== false;
}

function stobePlaythroughFindHistoryRowForNpcIdentityAtCutoff(int $cutoffGamets, array $npcRow): array|false
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $cutoff = max(0, intval($cutoffGamets));
    $rowName = trim(strval($npcRow['name'] ?? ''));
    $rowOriginalName = trim(strval($npcRow['original_name'] ?? ''));
    $rowMetadata = function_exists('normalizeCoreNpcMetadata')
        ? normalizeCoreNpcMetadata($npcRow['metadata'] ?? '{}')
        : stobePlaythroughNormalizeRollbackJsonObject($npcRow['metadata'] ?? '{}');
    $rowStorageId = function_exists('normalizeStorageIdToken')
        ? normalizeStorageIdToken($rowMetadata['storage_id'] ?? '')
        : trim(strval($rowMetadata['storage_id'] ?? ''));

    $params = [$cutoff];
    $paramIndex = 2;
    $identityClauses = [];

    $storageClause = '';
    if ($rowStorageId !== '') {
        $storageClause = "LOWER(COALESCE(metadata->>'storage_id', '')) = LOWER($" . $paramIndex . ")";
        $params[] = $rowStorageId;
        $paramIndex++;
        $identityClauses[] = $storageClause;
    }

    $nameClause = '';
    if ($rowName !== '') {
        $nameClause = "LOWER(name) = LOWER($" . $paramIndex . ")";
        $params[] = $rowName;
        $paramIndex++;
        $identityClauses[] = $nameClause;
    }

    $originalClause = '';
    if ($rowOriginalName !== '') {
        $originalClause = "LOWER(COALESCE(original_name, '')) = LOWER($" . $paramIndex . ")";
        $params[] = $rowOriginalName;
        $paramIndex++;
        $identityClauses[] = $originalClause;
    }

    if (count($identityClauses) === 0) {
        return false;
    }

    $priorityWhen = [];
    if ($storageClause !== '') {
        $priorityWhen[] = 'WHEN ' . $storageClause . ' THEN 0';
    }
    if ($nameClause !== '') {
        $priorityWhen[] = 'WHEN ' . $nameClause . ' THEN 1';
    }
    if ($originalClause !== '') {
        $priorityWhen[] = 'WHEN ' . $originalClause . ' THEN 2';
    }
    $priorityCase = 'CASE ' . implode(' ', $priorityWhen) . ' ELSE 3 END';

    $historyColumns = stobePlaythroughHistoryColumns();
    $worldKnowledgeSelect = isset($historyColumns['world_knowledge_tags'])
        ? 'world_knowledge_tags'
        : "''::text AS world_knowledge_tags";

    $query = 'SELECT
            history_id,
            npc_id,
            name,
            original_name,
            npc_favorite,
            lock_profile,
            prompt_head,
            personality,
            backstory,
            emote_moods,
            occupation,
            appearance,
            equipment,
            inventory,
            skills,
            speechstyle,
            goals,
            relationships,
            voiceid,
            metadata,
            race,
            faction,
            gender,
            profile_id,
            dynamic_profile,
            extended_data,
            plugin_extended_data,
            md5,
            gamets_last_updated,
            bounty,
            limbs,
            blood,
            hunger,
            tags,
            is_animal,
            is_slave,
            ' . $worldKnowledgeSelect . ',
            snapshot_reason,
            snapshot_hash
         FROM core_npc_master_history
         WHERE gamets_last_updated <= $1
           AND (' . implode(' OR ', $identityClauses) . ')
         ORDER BY ' . $priorityCase . ',
                  gamets_last_updated DESC,
                  CASE WHEN snapshot_reason = \'rollback_restore_applied\' THEN 1 ELSE 0 END ASC,
                  history_id DESC
         LIMIT 1';

    $row = $db->fetchOne($query, $params);
    return is_array($row) ? $row : false;
}

function stobePlaythroughRestoreUnlockedNpcs(int $cutoffGamets): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return ['restored' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];
    }

    $cutoff = max(0, intval($cutoffGamets));
    $preserveRelationships = getSettingBool('NEVER_CLEAR_RELATIONSHIP_DATA', false);

    $currentRows = $db->fetchAll(
        'SELECT * FROM core_npc WHERE COALESCE(lock_profile, FALSE) = FALSE ORDER BY id ASC'
    );
    if (!is_array($currentRows) || count($currentRows) === 0) {
        return ['restored' => 0, 'deleted' => 0, 'skipped' => 0, 'errors' => 0];
    }

    $historyColumns = stobePlaythroughHistoryColumns();
    $worldKnowledgeSelect = isset($historyColumns['world_knowledge_tags'])
        ? 'world_knowledge_tags'
        : "''::text AS world_knowledge_tags";

    $historyRows = $db->fetchAll(
        'SELECT DISTINCT ON (npc_id)
            history_id,
            npc_id,
            name,
            original_name,
            npc_favorite,
            lock_profile,
            prompt_head,
            personality,
            backstory,
            emote_moods,
            occupation,
            appearance,
            equipment,
            inventory,
            skills,
            speechstyle,
            goals,
            relationships,
            voiceid,
            metadata,
            race,
            faction,
            gender,
            profile_id,
            dynamic_profile,
            extended_data,
            plugin_extended_data,
            md5,
            gamets_last_updated,
            bounty,
            limbs,
            blood,
            hunger,
            tags,
            is_animal,
            is_slave,
            ' . $worldKnowledgeSelect . ',
            snapshot_reason,
            snapshot_hash
         FROM core_npc_master_history
         WHERE gamets_last_updated <= $1
         ORDER BY npc_id, gamets_last_updated DESC,
                  CASE WHEN snapshot_reason = \'rollback_restore_applied\' THEN 1 ELSE 0 END ASC,
                  history_id DESC',
        [$cutoff]
    );

    $historyByNpcId = [];
    foreach ($historyRows as $historyRow) {
        $npcId = intval($historyRow['npc_id'] ?? 0);
        if ($npcId <= 0) {
            continue;
        }
        $historyByNpcId[$npcId] = $historyRow;
    }

    $restored = 0;
    $deleted = 0;
    $skipped = 0;
    $errors = 0;

    // First delete unlocked NPCs that did not exist yet at cutoff.
    foreach ($currentRows as $row) {
        $npcId = intval($row['id'] ?? 0);
        if ($npcId <= 0) {
            continue;
        }

        if (array_key_exists($npcId, $historyByNpcId)) {
            continue;
        }

        $fallbackHistory = stobePlaythroughFindHistoryRowForNpcIdentityAtCutoff($cutoff, $row);
        if (is_array($fallbackHistory)) {
            $historyByNpcId[$npcId] = $fallbackHistory;
            stobeLogInfo('PLAYTHROUGH: rollback identity fallback matched history', [
                'npc_id' => $npcId,
                'name' => strval($row['name'] ?? ''),
                'history_npc_id' => intval($fallbackHistory['npc_id'] ?? 0),
                'history_name' => strval($fallbackHistory['name'] ?? ''),
                'history_gamets' => intval($fallbackHistory['gamets_last_updated'] ?? 0),
            ]);
            continue;
        }

        $rowGamets = intval($row['gamets_last_updated'] ?? 0);
        if ($rowGamets > $cutoff) {
            // Deleting a future-only NPC would also delete its preserved relationships.
            if ($preserveRelationships) {
                $skipped++;
                continue;
            }
            if (function_exists('stobeInsertNpcHistorySnapshotFromRow')) {
                stobeInsertNpcHistorySnapshotFromRow($row, 'rollback_delete_future_npc');
            }
            $ok = $db->exec('DELETE FROM core_npc WHERE id = $1', [$npcId]);
            if ($ok !== false) {
                $deleted++;
            } else {
                $errors++;
            }
            continue;
        }

        $skipped++;
    }

    // Then restore unlocked NPCs that have historical state at cutoff.
    foreach ($currentRows as $row) {
        $npcId = intval($row['id'] ?? 0);
        if ($npcId <= 0 || !array_key_exists($npcId, $historyByNpcId)) {
            continue;
        }

        $historyRow = $historyByNpcId[$npcId];

        if (stobePlaythroughShouldSkipSparseNpcRestore($row, $historyRow)) {
            $skipped++;
            stobeLogWarn('PLAYTHROUGH: skipped sparse NPC rollback downgrade', [
                'npc_id' => $npcId,
                'name' => strval($row['name'] ?? ''),
                'current_gamets' => intval($row['gamets_last_updated'] ?? 0),
                'history_id' => intval($historyRow['history_id'] ?? 0),
                'history_gamets' => intval($historyRow['gamets_last_updated'] ?? 0),
            ]);
            continue;
        }

        $currentHash = function_exists('stobeBuildNpcHistoryHashFromRow')
            ? stobeBuildNpcHistoryHashFromRow($row)
            : '';
        $targetHash = trim(strval($historyRow['snapshot_hash'] ?? ''));

        if ($currentHash !== '' && $targetHash !== '' && hash_equals($currentHash, $targetHash)) {
            $skipped++;
            continue;
        }

        if (function_exists('stobeInsertNpcHistorySnapshotFromRow')) {
            stobeInsertNpcHistorySnapshotFromRow($row, 'rollback_pre_restore');
        }

        $ok = stobePlaythroughRestoreNpcFromHistory($npcId, $historyRow, $preserveRelationships);
        if (!$ok) {
            $errors++;
            continue;
        }

        $restoredRow = function_exists('stobeFetchNpcRowForHistoryById')
            ? stobeFetchNpcRowForHistoryById($npcId)
            : false;
        if ($restoredRow && function_exists('stobeInsertNpcHistorySnapshotFromRow')) {
            stobeInsertNpcHistorySnapshotFromRow($restoredRow, 'rollback_restore_applied');
        }

        $restored++;
    }

    return [
        'restored' => $restored,
        'deleted' => $deleted,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

// Restore only relationship-owned keys so profile locks do not retain future affinity state.
function stobePlaythroughRestoreRelationshipStates(int $cutoffGamets): array
{
    if (getSettingBool('NEVER_CLEAR_RELATIONSHIP_DATA', false)) {
        return ['restored' => 0, 'cleared' => 0, 'errors' => 0, 'sample_names' => ''];
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !function_exists('stobeRelationshipExtendedDataKeys')) {
        return ['restored' => 0, 'cleared' => 0, 'errors' => 1, 'sample_names' => ''];
    }

    $cutoff = max(0, intval($cutoffGamets));
    $keys = stobeRelationshipExtendedDataKeys();
    $stripCurrent = "COALESCE(c.extended_data, '{}'::jsonb)";
    $restoredPairs = [];
    $currentPairs = [];
    $historyPairs = [];
    $keyLiterals = [];
    foreach ($keys as $key) {
        $stripCurrent .= " - '" . $key . "'";
        $restoredPairs[] = "'" . $key . "', latest.extended_data -> '" . $key . "'";
        $currentPairs[] = "'" . $key . "', c.extended_data -> '" . $key . "'";
        $historyPairs[] = "'" . $key . "', latest.extended_data -> '" . $key . "'";
        $keyLiterals[] = "'" . $key . "'";
    }

    $restoredState = 'jsonb_strip_nulls(jsonb_build_object(' . implode(', ', $restoredPairs) . '))';
    $currentState = 'jsonb_strip_nulls(jsonb_build_object(' . implode(', ', $currentPairs) . '))';
    $historyState = 'jsonb_strip_nulls(jsonb_build_object(' . implode(', ', $historyPairs) . '))';
    $relationshipKeyArray = 'ARRAY[' . implode(', ', $keyLiterals) . ']';

    try {
        $result = $db->fetchOne(
            "WITH latest AS (
                SELECT DISTINCT ON (npc_id)
                    npc_id,
                    COALESCE(extended_data, '{}'::jsonb) AS extended_data
                FROM core_npc_master_history
                WHERE (gamets_last_updated <= $1 OR gamets_last_updated IS NULL)
                  -- only snapshots that carry relationship data (most snapshots don't)
                  AND jsonb_typeof(COALESCE(extended_data, '{}'::jsonb) -> 'relationships') = 'object'
                ORDER BY npc_id,
                         gamets_last_updated DESC NULLS LAST,
                         CASE WHEN snapshot_reason = 'relationship' THEN 1 ELSE 0 END DESC,
                         history_id DESC
            ),
            restored AS (
                UPDATE core_npc c
                SET extended_data = (" . $stripCurrent . ") || " . $restoredState . ",
                    updated_at = NOW()
                FROM latest
                WHERE c.id = latest.npc_id
                  AND " . $currentState . " IS DISTINCT FROM " . $historyState . "
                RETURNING c.id, c.name
            ),
            cleared AS (
                UPDATE core_npc c
                SET extended_data = " . $stripCurrent . ",
                    updated_at = NOW()
                WHERE NOT EXISTS (SELECT 1 FROM latest WHERE latest.npc_id = c.id)
                  -- clear only NPCs first seen after the save (they didn't exist then)
                  AND NOT EXISTS (SELECT 1 FROM core_npc_master_history h
                                   WHERE h.npc_id = c.id AND h.gamets_last_updated <= $1 AND h.gamets_last_updated > 0)
                  AND (c.gamets_last_updated > $1 OR c.gamets_last_updated IS NULL)
                  AND COALESCE(c.extended_data, '{}'::jsonb) ?| " . $relationshipKeyArray . "
                RETURNING c.id, c.name
            )
            SELECT
                (SELECT COUNT(*)::int FROM restored) AS restored,
                (SELECT COUNT(*)::int FROM cleared) AS cleared,
                COALESCE((
                    SELECT string_agg(sample.name, ', ')
                    FROM (SELECT name FROM cleared ORDER BY name LIMIT 10) sample
                ), '') AS sample_names",
            [$cutoff]
        );
        if (!is_array($result)) {
            throw new RuntimeException('relationship restore query did not return a result');
        }

        $counts = [
            'restored' => intval($result['restored'] ?? 0),
            'cleared' => intval($result['cleared'] ?? 0),
            'errors' => 0,
            'sample_names' => trim(strval($result['sample_names'] ?? '')),
        ];
        // Item 70: restored maps can be older than R1/R3: drop template keys, map old types.
        if ($counts['restored'] > 0 && function_exists('stobeRelationshipCleanStoredMaps')) {
            try {
                $counts['cleaned'] = stobeRelationshipCleanStoredMaps();
            } catch (Throwable $cleanError) {
                stobeLogWarn('PLAYTHROUGH: relationship map cleanup failed (item 70)', ['error' => $cleanError->getMessage()]);
            }
        }
        stobeLogInfo('PLAYTHROUGH: restored relationship timeline state', [
            'cutoff_gamets' => $cutoff,
            'restored' => $counts['restored'],
            'cleared' => $counts['cleared'],
            'cleared_sample' => $counts['sample_names'],
        ]);
        return $counts;
    } catch (Throwable $exception) {
        stobeLogException($exception, 'PLAYTHROUGH: relationship timeline restore failed');
        return ['restored' => 0, 'cleared' => 0, 'errors' => 1, 'sample_names' => ''];
    }
}

function stobePlaythroughRecordLastSeenGamets(int $gamets): void
{
    $safeGamets = max(0, intval($gamets));
    if ($safeGamets <= 0) {
        return;
    }

    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($safeGamets), true);
    setConfOpt('PLAYTHROUGH_LAST_SEEN_TS', strval(time()), true);
}

function stobePlaythroughPruneOnRollbackEnabled(): bool
{
    return getSettingBool('PLAYTHROUGH_PRUNE_ON_ROLLBACK_ENABLED', true);
}

function stobePlaythroughZeroPruneCounts(): array
{
    return [
        'eventlog' => 0,
        'diarylog' => 0,
        'memory' => 0,
        'memory_summary' => 0,
        'npc_history' => 0,
        'location_zones_deleted' => 0,
        'location_zones_rewound' => 0,
        'player_base_history_deleted' => 0,
        'player_bases_deleted' => 0,
        'player_bases_restored' => 0,
        'player_base_presence_cleared' => 0,
    ];
}

function stobePlaythroughZeroRestoreCounts(): array
{
    return [
        'restored' => 0,
        'deleted' => 0,
        'skipped' => 0,
        'errors' => 0,
        'relationships_restored' => 0,
        'relationships_cleared' => 0,
        'relationship_errors' => 0,
    ];
}

function stobePlaythroughZeroQueueCounts(): array
{
    return [
        'relationship_eval_queue' => 0,
        'relationship_init_queue' => 0,
    ];
}

function stobePlaythroughAutoLoadEnabled(): bool
{
    return getSettingBool('PLAYTHROUGH_AUTOLOAD_ENABLED', false);
}

function stobePlaythroughAutoLoadAllowedEvent(string $eventType): bool
{
    $event = strtolower(trim($eventType));
    return in_array($event, ['gamedata', 'npc_snapshot'], true);
}

function stobePlaythroughAutoLoadFreshSquadMaxAgeSeconds(): int
{
    $value = getSettingInt('PLAYTHROUGH_AUTOLOAD_FRESH_SQUAD_MAX_AGE_SECONDS', 90);
    if ($value < 10) {
        $value = 10;
    } elseif ($value > 3600) {
        $value = 3600;
    }
    return $value;
}

function stobePlaythroughAutoLoadMinScore(): float
{
    $value = getSettingFloat('PLAYTHROUGH_AUTOLOAD_MIN_SCORE', 0.78);
    if ($value < 0.0) {
        $value = 0.0;
    } elseif ($value > 1.0) {
        $value = 1.0;
    }
    return $value;
}

function stobePlaythroughAutoLoadMinSquadOverlap(): float
{
    $value = getSettingFloat('PLAYTHROUGH_AUTOLOAD_MIN_SQUAD_OVERLAP', 0.60);
    if ($value < 0.0) {
        $value = 0.0;
    } elseif ($value > 1.0) {
        $value = 1.0;
    }
    return $value;
}

function stobePlaythroughAutoLoadMaxGametsDelta(): int
{
    $value = getSettingInt('PLAYTHROUGH_AUTOLOAD_MAX_GAMETS_DELTA', 172800);
    if ($value < 60) {
        $value = 60;
    } elseif ($value > 31536000) {
        $value = 31536000;
    }
    return $value;
}

function stobePlaythroughAutoLoadCooldownSeconds(): int
{
    $value = getSettingInt('PLAYTHROUGH_AUTOLOAD_COOLDOWN_SECONDS', 45);
    if ($value < 5) {
        $value = 5;
    } elseif ($value > 3600) {
        $value = 3600;
    }
    return $value;
}

function stobePlaythroughAutoLoadCooldownActive(): bool
{
    $untilTs = intval(getConfOpt('PLAYTHROUGH_AUTOLOAD_COOLDOWN_UNTIL_TS', '0'));
    return ($untilTs > time());
}

function stobePlaythroughSetAutoLoadCooldown(int $seconds): int
{
    $safeSeconds = max(5, intval($seconds));
    $until = time() + $safeSeconds;
    setConfOpt('PLAYTHROUGH_AUTOLOAD_COOLDOWN_UNTIL_TS', strval($until), true);
    return $until;
}

function stobePlaythroughConfOptAgeSeconds(string $id): ?float
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return null;
    }

    try {
        $row = $db->fetchOne(
            "SELECT EXTRACT(EPOCH FROM (NOW() - updated_at)) AS age
             FROM conf_opts
             WHERE id = $1
             LIMIT 1",
            [$id]
        );
    } catch (Throwable $exception) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $age = floatval($row['age'] ?? 0.0);
    if ($age < 0) {
        $age = 0;
    }
    return $age;
}

function stobePlaythroughValidateFreshSquadSync(int $maxAgeSeconds): array
{
    $squadNames = stobePlaythroughDecodeJsonStringList(getConfOpt('PLAYER_SQUADS', ''));
    if (count($squadNames) === 0) {
        return [
            'ok' => false,
            'reason' => 'no_player_squads',
            'members' => [],
            'max_age' => 0.0,
        ];
    }

    $keys = ['PLAYER_SQUADS'];
    foreach ($squadNames as $squadName) {
        $trimmed = trim($squadName);
        if ($trimmed !== '') {
            $keys[] = $trimmed;
        }
    }

    $seen = [];
    $maxAge = 0.0;
    foreach ($keys as $key) {
        $lower = strtolower($key);
        if (isset($seen[$lower])) {
            continue;
        }
        $seen[$lower] = true;

        $age = stobePlaythroughConfOptAgeSeconds($key);
        if ($age === null) {
            return [
                'ok' => false,
                'reason' => 'missing_conf_opt:' . $key,
                'members' => [],
                'max_age' => 0.0,
            ];
        }
        if ($age > $maxAge) {
            $maxAge = $age;
        }
        if ($age > $maxAgeSeconds) {
            return [
                'ok' => false,
                'reason' => 'stale_conf_opt:' . $key,
                'members' => [],
                'max_age' => $maxAge,
            ];
        }
    }

    $members = stobePlaythroughCollectCurrentPlayerFactionMembers();
    if (count($members) === 0) {
        return [
            'ok' => false,
            'reason' => 'no_player_faction_members',
            'members' => [],
            'max_age' => $maxAge,
        ];
    }

    return [
        'ok' => true,
        'reason' => '',
        'members' => $members,
        'max_age' => $maxAge,
    ];
}

function stobePlaythroughNameSetMap(array $names): array
{
    $map = [];
    foreach ($names as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $trimmed = trim($entry);
        if ($trimmed === '') {
            continue;
        }
        $key = strtolower($trimmed);
        if (!isset($map[$key])) {
            $map[$key] = $trimmed;
        }
    }
    return $map;
}

function stobePlaythroughDiceSimilarity(array $leftNames, array $rightNames): float
{
    $left = stobePlaythroughNameSetMap($leftNames);
    $right = stobePlaythroughNameSetMap($rightNames);

    $leftCount = count($left);
    $rightCount = count($right);
    if ($leftCount === 0 || $rightCount === 0) {
        return 0.0;
    }

    $intersection = 0;
    foreach ($left as $key => $_value) {
        if (isset($right[$key])) {
            $intersection++;
        }
    }

    if ($intersection <= 0) {
        return 0.0;
    }

    $score = (2.0 * floatval($intersection)) / floatval($leftCount + $rightCount);
    if ($score < 0.0) {
        $score = 0.0;
    } elseif ($score > 1.0) {
        $score = 1.0;
    }
    return $score;
}

function stobePlaythroughListAutoLoadCandidates(int $incomingGamets, int $limit = 200): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }

    $safeLimit = max(10, min(2000, intval($limit)));
    try {
        return $db->fetchAll(
            "SELECT
                id,
                name,
                is_active,
                last_gamets,
                player_faction_members,
                created_at
             FROM stobe_meta.playthrough_profiles
             WHERE COALESCE(storage_type, 'schema') = 'schema'
               AND COALESCE(schema_name, '') <> ''
             ORDER BY ABS(COALESCE(last_gamets, 0) - $1), created_at DESC
             LIMIT {$safeLimit}",
            [max(0, intval($incomingGamets))]
        );
    } catch (Throwable $exception) {
        return [];
    }
}

function stobePlaythroughScoreAutoLoadCandidate(
    array $candidateRow,
    array $currentMembers,
    int $incomingGamets,
    int $maxGametsDelta
): array {
    $candidateMembers = stobePlaythroughDecodeJsonStringList(strval($candidateRow['player_faction_members'] ?? ''));
    $squadScore = stobePlaythroughDiceSimilarity($currentMembers, $candidateMembers);

    $candidateGamets = intval($candidateRow['last_gamets'] ?? 0);
    $delta = abs(max(0, $candidateGamets) - max(0, intval($incomingGamets)));
    $safeMaxDelta = max(60, intval($maxGametsDelta));
    $timeScore = 1.0 - min(1.0, floatval($delta) / floatval($safeMaxDelta));

    $totalScore = (0.70 * $squadScore) + (0.30 * $timeScore);
    if ($totalScore < 0.0) {
        $totalScore = 0.0;
    } elseif ($totalScore > 1.0) {
        $totalScore = 1.0;
    }

    return [
        'id' => intval($candidateRow['id'] ?? 0),
        'name' => strval($candidateRow['name'] ?? ''),
        'is_active' => stobePlaythroughToBool($candidateRow['is_active'] ?? false),
        'last_gamets' => $candidateGamets,
        'delta' => $delta,
        'members' => $candidateMembers,
        'squad_score' => $squadScore,
        'time_score' => $timeScore,
        'score' => $totalScore,
    ];
}

function stobePlaythroughTryAutoLoadOnRollback(
    int $incomingGamets,
    int $lastSeenGamets,
    string $eventType
): array {
    $event = strtolower(trim($eventType));
    if (!stobePlaythroughAutoLoadEnabled()) {
        return ['attempted' => false, 'switched' => false, 'reason' => 'disabled'];
    }
    if (!stobePlaythroughAutoLoadAllowedEvent($event)) {
        return ['attempted' => false, 'switched' => false, 'reason' => 'event_not_allowed'];
    }
    if (stobePlaythroughAutoLoadCooldownActive()) {
        return ['attempted' => false, 'switched' => false, 'reason' => 'cooldown_active'];
    }

    $fresh = stobePlaythroughValidateFreshSquadSync(stobePlaythroughAutoLoadFreshSquadMaxAgeSeconds());
    if (!boolval($fresh['ok'] ?? false)) {
        return [
            'attempted' => true,
            'switched' => false,
            'reason' => strval($fresh['reason'] ?? 'stale_squad_sync'),
        ];
    }

    $currentMembers = is_array($fresh['members'] ?? null) ? $fresh['members'] : [];
    $candidates = stobePlaythroughListAutoLoadCandidates($incomingGamets, 250);
    if (count($candidates) === 0) {
        return ['attempted' => true, 'switched' => false, 'reason' => 'no_candidates'];
    }

    $minScore = stobePlaythroughAutoLoadMinScore();
    $minSquadOverlap = stobePlaythroughAutoLoadMinSquadOverlap();
    $maxGametsDelta = stobePlaythroughAutoLoadMaxGametsDelta();

    $best = null;
    $debugTop = [];
    foreach ($candidates as $row) {
        $scored = stobePlaythroughScoreAutoLoadCandidate(
            is_array($row) ? $row : [],
            $currentMembers,
            $incomingGamets,
            $maxGametsDelta
        );
        if ($scored['id'] <= 0) {
            continue;
        }
        if (intval($scored['delta']) > $maxGametsDelta) {
            continue;
        }

        if (count($debugTop) < 5) {
            $debugTop[] = [
                'id' => intval($scored['id']),
                'name' => strval($scored['name']),
                'score' => round(floatval($scored['score']), 4),
                'squad_score' => round(floatval($scored['squad_score']), 4),
                'time_score' => round(floatval($scored['time_score']), 4),
                'delta' => intval($scored['delta']),
                'is_active' => boolval($scored['is_active']),
            ];
        }

        if (floatval($scored['squad_score']) < $minSquadOverlap) {
            continue;
        }
        if (floatval($scored['score']) < $minScore) {
            continue;
        }

        if ($best === null || floatval($scored['score']) > floatval($best['score'])) {
            $best = $scored;
        }
    }

    if ($best === null) {
        stobeLogInfo('PLAYTHROUGH_AUTOLOAD: no confident candidate', [
            'event_type' => $event,
            'incoming_gamets' => $incomingGamets,
            'last_seen_gamets' => $lastSeenGamets,
            'members' => $currentMembers,
            'min_score' => $minScore,
            'min_squad_overlap' => $minSquadOverlap,
            'max_gamets_delta' => $maxGametsDelta,
            'top_candidates' => $debugTop,
        ]);
        return ['attempted' => true, 'switched' => false, 'reason' => 'no_confident_match'];
    }

    $switch = stobePlaythroughSwitchToProfile(intval($best['id']), true);
    if (!boolval($switch['success'] ?? false)) {
        stobeLogWarn('PLAYTHROUGH_AUTOLOAD: switch failed', [
            'event_type' => $event,
            'incoming_gamets' => $incomingGamets,
            'candidate' => $best,
            'error' => strval($switch['error'] ?? 'unknown'),
        ]);
        return [
            'attempted' => true,
            'switched' => false,
            'reason' => 'switch_failed',
            'error' => strval($switch['error'] ?? 'unknown'),
            'candidate' => $best,
        ];
    }

    $cooldownUntil = stobePlaythroughSetAutoLoadCooldown(stobePlaythroughAutoLoadCooldownSeconds());
    setConfOpt('PLAYTHROUGH_LAST_AUTOLOAD_PROFILE_ID', strval(intval($best['id'])), true);
    setConfOpt('PLAYTHROUGH_LAST_AUTOLOAD_TS', strval(time()), true);
    setConfOpt('PLAYTHROUGH_LAST_AUTOLOAD_SCORE', number_format(floatval($best['score']), 4, '.', ''), true);

    stobeLogInfo('PLAYTHROUGH_AUTOLOAD: switched active playthrough', [
        'event_type' => $event,
        'incoming_gamets' => $incomingGamets,
        'last_seen_gamets' => $lastSeenGamets,
        'candidate' => $best,
        'autosave_id' => intval($switch['autosave_id'] ?? 0),
        'cooldown_until_ts' => $cooldownUntil,
        'squad_sync_max_age_seconds' => round(floatval($fresh['max_age'] ?? 0.0), 2),
        'members' => $currentMembers,
    ]);

    return [
        'attempted' => true,
        'switched' => true,
        'reason' => 'ok',
        'profile_id' => intval($best['id']),
        'profile_name' => strval($best['name']),
        'score' => floatval($best['score']),
        'squad_score' => floatval($best['squad_score']),
        'time_score' => floatval($best['time_score']),
        'delta' => intval($best['delta']),
        'autosave_id' => intval($switch['autosave_id'] ?? 0),
    ];
}

function stobeHandlePotentialGametsRollback(mixed $incomingGamets, string $eventType = ''): array
{
    if (!empty($GLOBALS['pgr_skip_rollback'])) return ['triggered'=>false,'reason'=>'snapshot_failed'];
    $incoming = stobeGametsNormalize($incomingGamets);
    $event = strtolower(trim($eventType));

    if (!stobePlaythroughRollbackEventIsAuthoritative($event)) {
        return ['triggered' => false, 'reason' => 'event_not_authoritative'];
    }

    if ($incoming <= 0) {
        return ['triggered' => false, 'reason' => 'no_gamets'];
    }

    $lastSeen = max(intval(getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '0')), (int)($GLOBALS['pgr_operation']['state']['previous'] ?? 0));
    if ($lastSeen <= 0) {
        stobePlaythroughRecordLastSeenGamets($incoming);
        return ['triggered' => false, 'reason' => 'seeded'];
    }

    $tolerance = stobePlaythroughRollbackToleranceGamets();
    if (($incoming + $tolerance) >= $lastSeen) {
        if ($incoming > $lastSeen) {
            stobePlaythroughRecordLastSeenGamets($incoming);
        }
        return ['triggered' => false, 'reason' => 'forward_or_same'];
    }

    // Only a save load ("init") proves a rollback. Other reports travel through the
    // game's serial event queue and arrive late in busy fights, carrying an older
    // game time; treating them as a reload pruned fresh events and memories and
    // restored stale NPC/relationship snapshots mid-conversation. A non-init event
    // counts only when it jumps back further than lag ever could.
    if ($event !== 'init' && strpos($event, 'test_') !== 0
        && ($lastSeen - $incoming) < stobePlaythroughLateEventMaxLagGamets()) {
        static $lateLogged = 0;
        if (time() - $lateLogged >= 30) {
            $lateLogged = time();
            stobeLogInfo('PLAYTHROUGH: late event ignored (not a rollback)', [
                'event_type' => $event,
                'incoming_gamets' => $incoming,
                'last_seen_gamets' => $lastSeen,
                'lag_gamets' => $lastSeen - $incoming,
            ]);
        }
        return ['triggered' => false, 'reason' => 'late_event_not_rollback'];
    }

    $lockAcquired = stobePlaythroughAcquireRollbackLock();
    if (!$lockAcquired) {
        stobeLogWarn('PLAYTHROUGH: rollback lock busy, continuing without lock', [
            'incoming_gamets' => $incoming,
            'last_seen_gamets' => $lastSeen,
            'event_type' => $event,
        ]);
    }

    try {
        $latestLastSeen = max(intval(getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '0')), (int)($GLOBALS['pgr_operation']['state']['previous'] ?? 0));
        if ($latestLastSeen <= 0) {
            stobePlaythroughRecordLastSeenGamets($incoming);
            return ['triggered' => false, 'reason' => 'seeded_after_lock'];
        }

        if (($incoming + $tolerance) >= $latestLastSeen) {
            if ($incoming > $latestLastSeen) {
                stobePlaythroughRecordLastSeenGamets($incoming);
            }
            return ['triggered' => false, 'reason' => 'forward_or_same_after_lock'];
        }

        $rollbackDelta = $latestLastSeen - $incoming;
        $rollbackDays = intdiv(max(0, $rollbackDelta), 86400);

        stobeLogWarn('PLAYTHROUGH: Rollback detected', [
            'event_type' => $event,
            'incoming_gamets' => $incoming,
            'last_seen_gamets' => $latestLastSeen,
            'delta_gamets' => $rollbackDelta,
            'delta_days' => $rollbackDays,
            'prune_enabled' => stobePlaythroughPruneOnRollbackEnabled(),
        ]);

        $autoLoad = !empty($GLOBALS['pgr_operation']) ? ['switched'=>false] : stobePlaythroughTryAutoLoadOnRollback($incoming, $latestLastSeen, $event);
        if (boolval($autoLoad['switched'] ?? false)) {
            setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_GAMETS', strval($incoming), true);
            setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_TS', strval(time()), true);
            setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_FROM_GAMETS', strval($latestLastSeen), true);
            setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_DELTA_GAMETS', strval($rollbackDelta), true);
            stobePlaythroughRecordLastSeenGamets($incoming);

            if (function_exists('stobeDynamicProfileLoadGraceSeconds') && function_exists('stobeDynamicProfileMarkLoadGrace')) {
                $grace = stobeDynamicProfileLoadGraceSeconds();
                stobeDynamicProfileMarkLoadGrace(time(), $grace, 'playthrough_autoload');
            }

            return [
                'triggered' => true,
                'autoload_switched' => true,
                'playthrough_id' => intval($autoLoad['profile_id'] ?? 0),
                'delta_gamets' => $rollbackDelta,
                'delta_days' => $rollbackDays,
                'pruned' => stobePlaythroughZeroPruneCounts(),
                'restored' => stobePlaythroughZeroRestoreCounts(),
                'queues_cleared' => stobePlaythroughZeroQueueCounts(),
            ];
        }

        $playthroughId = stobeDragonBreakPlaythroughIfNeeded($latestLastSeen, $incoming);
        if ($playthroughId < 0) return ['triggered'=>false,'reason'=>'snapshot_failed'];
        $pruneEnabled = stobePlaythroughPruneOnRollbackEnabled();
        $pruneCounts = $pruneEnabled
            ? stobePlaythroughPruneFutureTimeline($incoming)
            : stobePlaythroughZeroPruneCounts();
        $restoreCounts = stobePlaythroughRestoreUnlockedNpcs($incoming);
        $relationshipRestoreCounts = stobePlaythroughRestoreRelationshipStates($incoming);
        $restoreCounts['relationships_restored'] = intval($relationshipRestoreCounts['restored'] ?? 0);
        $restoreCounts['relationships_cleared'] = intval($relationshipRestoreCounts['cleared'] ?? 0);
        $restoreCounts['relationship_errors'] = intval($relationshipRestoreCounts['errors'] ?? 0);
        $restoreCounts['errors'] += $restoreCounts['relationship_errors'];
        $queueCounts = stobePlaythroughClearRelationshipQueues();
        $volatileStateCounts = stobePlaythroughClearFutureVolatileNpcStates($incoming);

        if (!$pruneEnabled) {
            stobeLogWarn('PLAYTHROUGH: prune skipped by setting PLAYTHROUGH_PRUNE_ON_ROLLBACK_ENABLED=false', [
                'event_type' => $event,
                'incoming_gamets' => $incoming,
                'last_seen_gamets' => $latestLastSeen,
            ]);
        }

        setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_GAMETS', strval($incoming), true);
        setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_TS', strval(time()), true);
        setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_FROM_GAMETS', strval($latestLastSeen), true);
        setConfOpt('PLAYTHROUGH_LAST_ROLLBACK_DELTA_GAMETS', strval($rollbackDelta), true);

        stobePlaythroughRecordLastSeenGamets($incoming);

        if (function_exists('stobeDynamicProfileLoadGraceSeconds') && function_exists('stobeDynamicProfileMarkLoadGrace')) {
            $grace = stobeDynamicProfileLoadGraceSeconds();
            stobeDynamicProfileMarkLoadGrace(time(), $grace, 'playthrough_rollback');
        }

        if (!pgr_complete(empty($restoreCounts['errors']) && empty($volatileStateCounts['errors']))) return ['triggered'=>false,'reason'=>'rollback_failed'];

        stobeLogInfo('PLAYTHROUGH: Rollback completed', [
            'event_type' => $event,
            'playthrough_id' => $playthroughId,
            'incoming_gamets' => $incoming,
            'last_seen_gamets' => $latestLastSeen,
            'delta_gamets' => $rollbackDelta,
            'delta_days' => $rollbackDays,
            'prune_enabled' => $pruneEnabled,
            'pruned' => $pruneCounts,
            'restored' => $restoreCounts,
            'queues_cleared' => $queueCounts,
            'volatile_state_cleared' => $volatileStateCounts,
        ]);

        return [
            'triggered' => true,
            'playthrough_id' => $playthroughId,
            'delta_gamets' => $rollbackDelta,
            'delta_days' => $rollbackDays,
            'prune_enabled' => $pruneEnabled,
            'pruned' => $pruneCounts,
            'restored' => $restoreCounts,
            'queues_cleared' => $queueCounts,
        ];
    } catch (Throwable $exception) {
        if (!empty($GLOBALS['pgr_operation'])) {
            pgr_fail($exception->getMessage());
            return ['triggered'=>false,'reason'=>'rollback_failed'];
        }
        stobeLogException($exception, 'PLAYTHROUGH: Rollback handling failed', [
            'event_type' => $event,
            'incoming_gamets' => $incoming,
            'last_seen_gamets' => intval(getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '0')),
        ]);

        return [
            'triggered' => false,
            'reason' => 'exception',
            'error' => $exception->getMessage(),
        ];
    } finally {
        if ($lockAcquired) {
            stobePlaythroughReleaseRollbackLock();
        }
    }
}

?>
