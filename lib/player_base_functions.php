<?php

function stobeNormalizePlayerBaseDetails(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        $value = [];
    }

    $clipInt = static fn (mixed $candidate): int => max(0, min(1000000000, intval($candidate)));
    $clipMetric = static function (mixed $candidate, float $maximum): float {
        if (!is_numeric($candidate)) {
            return 0.0;
        }
        $number = floatval($candidate);
        return is_finite($number) ? max(0.0, min($maximum, $number)) : 0.0;
    };
    $normalizeGroups = static function (
        mixed $candidate,
        array $integerFields,
        array $metricFields = []
    ) use ($clipInt, $clipMetric): array {
        if (!is_array($candidate)) {
            return [];
        }

        $groups = [];
        foreach (array_slice(array_values($candidate), 0, 24) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim(strval($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $group = [
                'name' => strlen($name) > 128 ? substr($name, 0, 128) : $name,
            ];
            foreach ($integerFields as $field) {
                $group[$field] = $clipInt($row[$field] ?? 0);
            }
            foreach ($metricFields as $field => $maximum) {
                $group[$field] = $clipMetric($row[$field] ?? 0, $maximum);
            }
            $groups[] = $group;
        }
        return $groups;
    };

    $securityInput = is_array($value['security'] ?? null) ? $value['security'] : [];
    $infrastructureInput = is_array($value['infrastructure'] ?? null) ? $value['infrastructure'] : [];
    $constructionInput = is_array($value['construction'] ?? null) ? $value['construction'] : [];
    $powerInput = is_array($value['power'] ?? null) ? $value['power'] : [];
    $suppliesInput = is_array($value['supplies'] ?? null) ? $value['supplies'] : [];
    $storageInput = is_array($value['storage'] ?? null) ? $value['storage'] : [];
    $productionInput = is_array($value['production'] ?? null) ? $value['production'] : [];
    $farmsInput = is_array($value['farms'] ?? null) ? $value['farms'] : [];
    $alarmState = strtolower(trim(strval($securityInput['alarm_state'] ?? 'none')));
    if (!in_array($alarmState, ['none', 'intruder', 'escape', 'attack'], true)) {
        $alarmState = 'none';
    }

    return [
        'available' => coerceBoolean($value['available'] ?? false),
        'scan_truncated' => coerceBoolean($value['scan_truncated'] ?? false),
        'security' => [
            'alarm_state' => $alarmState,
            'hostiles_inside' => $clipInt($securityInput['hostiles_inside'] ?? 0),
            'gates_total' => $clipInt($securityInput['gates_total'] ?? 0),
            'damaged_defenses' => $clipInt($securityInput['damaged_defenses'] ?? 0),
            'destroyed_defenses' => $clipInt($securityInput['destroyed_defenses'] ?? 0),
            'turrets_total' => $clipInt($securityInput['turrets_total'] ?? 0),
            'turrets_manned' => $clipInt($securityInput['turrets_manned'] ?? 0),
            'turrets_unpowered' => $clipInt($securityInput['turrets_unpowered'] ?? 0),
        ],
        'infrastructure' => [
            'damaged' => $clipInt($infrastructureInput['damaged'] ?? 0),
            'destroyed' => $clipInt($infrastructureInput['destroyed'] ?? 0),
            'broken' => $clipInt($infrastructureInput['broken'] ?? 0),
            'unpowered' => $clipInt($infrastructureInput['unpowered'] ?? 0),
            'issues' => $normalizeGroups(
                $infrastructureInput['issues'] ?? [],
                ['count', 'damaged', 'destroyed', 'broken', 'unpowered']
            ),
        ],
        'construction' => [
            'total' => $clipInt($constructionInput['total'] ?? 0),
            'paused' => $clipInt($constructionInput['paused'] ?? 0),
            'missing_materials' => $clipInt($constructionInput['missing_materials'] ?? 0),
            'average_progress' => $clipMetric($constructionInput['average_progress'] ?? 0, 100.0),
            'groups' => $normalizeGroups(
                $constructionInput['groups'] ?? [],
                ['count', 'paused', 'missing_materials'],
                ['average_progress' => 100.0]
            ),
        ],
        'power' => [
            'consumers' => $clipInt($powerInput['consumers'] ?? 0),
            'unpowered' => $clipInt($powerInput['unpowered'] ?? 0),
            'switched_off' => $clipInt($powerInput['switched_off'] ?? 0),
            'generators_total' => $clipInt($powerInput['generators_total'] ?? 0),
            'generators_active' => $clipInt($powerInput['generators_active'] ?? 0),
        ],
        'supplies' => [
            'food' => $clipInt($suppliesInput['food'] ?? 0),
            'medicine' => $clipInt($suppliesInput['medicine'] ?? 0),
            'building_materials' => $clipInt($suppliesInput['building_materials'] ?? 0),
            'iron_plates' => $clipInt($suppliesInput['iron_plates'] ?? 0),
            'fuel' => $clipInt($suppliesInput['fuel'] ?? 0),
            'water' => $clipInt($suppliesInput['water'] ?? 0),
            'ammunition' => $clipInt($suppliesInput['ammunition'] ?? 0),
        ],
        'storage' => [
            'total' => $clipInt($storageInput['total'] ?? 0),
            'empty' => $clipInt($storageInput['empty'] ?? 0),
            'full' => $clipInt($storageInput['full'] ?? 0),
            'item_units' => $clipInt($storageInput['item_units'] ?? 0),
            'groups' => $normalizeGroups(
                $storageInput['groups'] ?? [],
                ['total', 'empty', 'full', 'item_units']
            ),
        ],
        'production' => [
            'total' => $clipInt($productionInput['total'] ?? 0),
            'active' => $clipInt($productionInput['active'] ?? 0),
            'input_blocked' => $clipInt($productionInput['input_blocked'] ?? 0),
            'output_blocked' => $clipInt($productionInput['output_blocked'] ?? 0),
            'unpowered' => $clipInt($productionInput['unpowered'] ?? 0),
            'staffed' => $clipInt($productionInput['staffed'] ?? 0),
            'average_efficiency' => $clipMetric($productionInput['average_efficiency'] ?? 0, 1000.0),
            'groups' => $normalizeGroups(
                $productionInput['groups'] ?? [],
                ['total', 'active', 'input_blocked', 'output_blocked', 'unpowered', 'staffed'],
                ['average_efficiency' => 1000.0]
            ),
        ],
        'farms' => [
            'total' => $clipInt($farmsInput['total'] ?? 0),
            'active' => $clipInt($farmsInput['active'] ?? 0),
            'needs_water' => $clipInt($farmsInput['needs_water'] ?? 0),
            'output_full' => $clipInt($farmsInput['output_full'] ?? 0),
            'unpowered' => $clipInt($farmsInput['unpowered'] ?? 0),
            'staffed' => $clipInt($farmsInput['staffed'] ?? 0),
            'hydroponic' => $clipInt($farmsInput['hydroponic'] ?? 0),
            'average_yield' => $clipMetric($farmsInput['average_yield'] ?? 0, 100.0),
            'groups' => $normalizeGroups(
                $farmsInput['groups'] ?? [],
                ['total', 'active', 'needs_water', 'output_full', 'unpowered', 'staffed', 'hydroponic'],
                ['average_yield' => 100.0]
            ),
        ],
    ];
}

/**
 * Normalizes player-base telemetry shared by NPC context and presence ingest.
 */
function stobeNormalizePlayerBaseSnapshot(mixed $value, bool $stampObservation = false): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        return [];
    }

    $inside = coerceBoolean($value['inside'] ?? false);
    $observedGameTs = max(0, intval($value['observed_game_ts'] ?? ($value['game_ts'] ?? 0)));
    $normalized = [
        'inside' => $inside,
        'observed_game_ts' => $observedGameTs,
    ];
    if ($stampObservation) {
        $normalized['server_observed_at'] = time();
    } elseif (isset($value['server_observed_at'])) {
        $normalized['server_observed_at'] = max(0, intval($value['server_observed_at']));
    }
    if (!$inside) {
        return $normalized;
    }

    $clipText = static function (mixed $candidate, int $limit): string {
        $text = trim(strval($candidate));
        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    };
    $clipFloat = static function (mixed $candidate): float {
        if (!is_numeric($candidate)) {
            return 0.0;
        }
        $value = floatval($candidate);
        if (!is_finite($value)) {
            return 0.0;
        }
        return max(0.0, min(1000000000.0, $value));
    };

    $baseId = $clipText($value['base_id'] ?? '', 255);
    if ($baseId === '') {
        return [
            'inside' => false,
            'observed_game_ts' => $observedGameTs,
        ] + ($stampObservation ? ['server_observed_at' => time()] : []);
    }

    $normalized += [
        'base_id' => $baseId,
        'name' => $clipText($value['name'] ?? 'Player Base', 255),
        'power_generated' => $clipFloat($value['power_generated'] ?? 0),
        'power_required' => $clipFloat($value['power_required'] ?? 0),
        'battery_charge' => $clipFloat($value['battery_charge'] ?? 0),
        'battery_capacity' => $clipFloat($value['battery_capacity'] ?? 0),
        'battery_drain' => $clipFloat($value['battery_drain'] ?? 0),
        'battery_charging' => $clipFloat($value['battery_charging'] ?? 0),
        'battery_mode' => coerceBoolean($value['battery_mode'] ?? false),
        'has_spare_power' => coerceBoolean($value['has_spare_power'] ?? false),
        'members_inside' => max(0, min(1000, intval($value['members_inside'] ?? 0))),
        'has_gates' => coerceBoolean($value['has_gates'] ?? false),
        'gates_closed' => coerceBoolean($value['gates_closed'] ?? false),
        'details' => stobeNormalizePlayerBaseDetails($value['details'] ?? []),
    ];
    if ($normalized['name'] === '') {
        $normalized['name'] = 'Player Base';
    }
    return $normalized;
}

function stobePlayerBaseExecOrThrow(object $db, string $query, array $params = []): void
{
    if ($db->exec($query, $params) !== false) {
        return;
    }
    $error = method_exists($db, 'GetLastError') ? trim(strval($db->GetLastError())) : '';
    throw new RuntimeException($error !== '' ? $error : 'Player base database write failed');
}

/**
 * Stores every encountered base separately while tracking one current selection.
 */
function stobeStorePlayerBaseState(array $payload): array
{
    $db = $GLOBALS['db'];
    $sessionId = substr(trim(strval($payload['session_id'] ?? '')), 0, 128);
    $observerSerial = max(0, intval($payload['observer_serial'] ?? 0));
    $observerName = substr(trim(strval($payload['observer_name'] ?? '')), 0, 255);
    $gameTs = max(0, intval($payload['game_ts'] ?? 0));
    $base = stobeNormalizePlayerBaseSnapshot($payload['player_base'] ?? [], false);
    $inside = boolval($base['inside'] ?? false);
    if ($sessionId === '') {
        throw new InvalidArgumentException('Missing session_id');
    }

    stobePlayerBaseExecOrThrow($db, 'BEGIN');
    try {
        $baseId = null;
        if ($inside) {
            $baseId = strval($base['base_id']);
            $detailsJson = json_encode($base['details'], JSON_UNESCAPED_SLASHES);
            if (!is_string($detailsJson)) {
                $detailsJson = '{}';
            }
            stobePlayerBaseExecOrThrow(
                $db,
                "INSERT INTO player_bases (
                    base_id, name, power_generated, power_required,
                    battery_charge, battery_capacity, battery_drain, battery_charging,
                    battery_mode, has_spare_power, members_inside,
                    has_gates, gates_closed, details, game_ts,
                    first_game_ts, last_game_ts, last_seen_at
                 ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8,
                    $9::boolean, $10::boolean, $11,
                    $12::boolean, $13::boolean, $14::jsonb, $15,
                    $15, $15, NOW()
                 )
                 ON CONFLICT (base_id) DO UPDATE SET
                    name = EXCLUDED.name,
                    power_generated = EXCLUDED.power_generated,
                    power_required = EXCLUDED.power_required,
                    battery_charge = EXCLUDED.battery_charge,
                    battery_capacity = EXCLUDED.battery_capacity,
                    battery_drain = EXCLUDED.battery_drain,
                    battery_charging = EXCLUDED.battery_charging,
                    battery_mode = EXCLUDED.battery_mode,
                    has_spare_power = EXCLUDED.has_spare_power,
                    members_inside = EXCLUDED.members_inside,
                    has_gates = EXCLUDED.has_gates,
                    gates_closed = EXCLUDED.gates_closed,
                    details = EXCLUDED.details,
                    game_ts = EXCLUDED.game_ts,
                    first_game_ts = CASE
                        WHEN player_bases.first_game_ts = 0 THEN 0
                        WHEN EXCLUDED.first_game_ts <= 0 THEN player_bases.first_game_ts
                        ELSE LEAST(player_bases.first_game_ts, EXCLUDED.first_game_ts)
                    END,
                    last_game_ts = EXCLUDED.last_game_ts,
                    last_seen_at = NOW()",
                [
                    $baseId,
                    strval($base['name']),
                    strval($base['power_generated']),
                    strval($base['power_required']),
                    strval($base['battery_charge']),
                    strval($base['battery_capacity']),
                    strval($base['battery_drain']),
                    strval($base['battery_charging']),
                    boolval($base['battery_mode']) ? 'true' : 'false',
                    boolval($base['has_spare_power']) ? 'true' : 'false',
                    intval($base['members_inside']),
                    boolval($base['has_gates']) ? 'true' : 'false',
                    boolval($base['gates_closed']) ? 'true' : 'false',
                    $detailsJson,
                    $gameTs,
                ]
            );

            $latestHistory = $db->fetchOne(
                'SELECT
                    name, power_generated, power_required,
                    battery_charge, battery_capacity, battery_drain, battery_charging,
                    battery_mode, has_spare_power, members_inside,
                    has_gates, gates_closed, details, game_ts
                 FROM player_base_history
                 WHERE base_id = $1
                 ORDER BY game_ts DESC, id DESC
                 LIMIT 1',
                [$baseId]
            );
            $latestGameTs = intval($latestHistory['game_ts'] ?? -1);
            $historyChanged = !is_array($latestHistory)
                || strval($latestHistory['name'] ?? '') !== strval($base['name'])
                || floatval($latestHistory['power_generated'] ?? 0) !== floatval($base['power_generated'])
                || floatval($latestHistory['power_required'] ?? 0) !== floatval($base['power_required'])
                || floatval($latestHistory['battery_charge'] ?? 0) !== floatval($base['battery_charge'])
                || floatval($latestHistory['battery_capacity'] ?? 0) !== floatval($base['battery_capacity'])
                || floatval($latestHistory['battery_drain'] ?? 0) !== floatval($base['battery_drain'])
                || floatval($latestHistory['battery_charging'] ?? 0) !== floatval($base['battery_charging'])
                || coerceBoolean($latestHistory['battery_mode'] ?? false) !== boolval($base['battery_mode'])
                || coerceBoolean($latestHistory['has_spare_power'] ?? false) !== boolval($base['has_spare_power'])
                || intval($latestHistory['members_inside'] ?? 0) !== intval($base['members_inside'])
                || coerceBoolean($latestHistory['has_gates'] ?? false) !== boolval($base['has_gates'])
                || coerceBoolean($latestHistory['gates_closed'] ?? false) !== boolval($base['gates_closed'])
                || stobeNormalizePlayerBaseDetails($latestHistory['details'] ?? []) !== $base['details'];
            $historyIntervalReached = $latestGameTs < 0
                || $gameTs <= $latestGameTs
                || ($gameTs - $latestGameTs) >= 300;
            if ($historyChanged && $historyIntervalReached) {
                stobePlayerBaseExecOrThrow(
                    $db,
                    "INSERT INTO player_base_history (
                        base_id, name, power_generated, power_required,
                        battery_charge, battery_capacity, battery_drain, battery_charging,
                        battery_mode, has_spare_power, members_inside,
                        has_gates, gates_closed, details, game_ts, observed_at
                     ) VALUES (
                        $1, $2, $3, $4, $5, $6, $7, $8,
                        $9::boolean, $10::boolean, $11,
                        $12::boolean, $13::boolean, $14::jsonb, $15, NOW()
                     )
                     ON CONFLICT (base_id, game_ts) DO UPDATE SET
                        name = EXCLUDED.name,
                        power_generated = EXCLUDED.power_generated,
                        power_required = EXCLUDED.power_required,
                        battery_charge = EXCLUDED.battery_charge,
                        battery_capacity = EXCLUDED.battery_capacity,
                        battery_drain = EXCLUDED.battery_drain,
                        battery_charging = EXCLUDED.battery_charging,
                        battery_mode = EXCLUDED.battery_mode,
                        has_spare_power = EXCLUDED.has_spare_power,
                        members_inside = EXCLUDED.members_inside,
                        has_gates = EXCLUDED.has_gates,
                        gates_closed = EXCLUDED.gates_closed,
                        details = EXCLUDED.details,
                        observed_at = NOW()",
                    [
                        $baseId,
                        strval($base['name']),
                        strval($base['power_generated']),
                        strval($base['power_required']),
                        strval($base['battery_charge']),
                        strval($base['battery_capacity']),
                        strval($base['battery_drain']),
                        strval($base['battery_charging']),
                        boolval($base['battery_mode']) ? 'true' : 'false',
                        boolval($base['has_spare_power']) ? 'true' : 'false',
                        intval($base['members_inside']),
                        boolval($base['has_gates']) ? 'true' : 'false',
                        boolval($base['gates_closed']) ? 'true' : 'false',
                        $detailsJson,
                        $gameTs,
                    ]
                );
            }
        }

        stobePlayerBaseExecOrThrow(
            $db,
            "INSERT INTO player_base_presence (
                scope_key, session_id, observer_serial, observer_name,
                inside, base_id, game_ts, observed_at
             ) VALUES ('selected_player', $1, $2, $3, $4::boolean, $5, $6, NOW())
             ON CONFLICT (scope_key) DO UPDATE SET
                session_id = EXCLUDED.session_id,
                observer_serial = EXCLUDED.observer_serial,
                observer_name = EXCLUDED.observer_name,
                inside = EXCLUDED.inside,
                base_id = EXCLUDED.base_id,
                game_ts = EXCLUDED.game_ts,
                observed_at = NOW()",
            [
                $sessionId,
                $observerSerial,
                $observerName,
                $inside ? 'true' : 'false',
                $inside ? $baseId : null,
                $gameTs,
            ]
        );
        stobePlayerBaseExecOrThrow($db, 'COMMIT');
    } catch (Throwable $exception) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $exception;
    }

    if ($inside && function_exists('stobeWorkGoalRememberBaseLocation')) {
        try {
            stobeWorkGoalRememberBaseLocation(
                strval($base['base_id'] ?? ''),
                strval($base['name'] ?? 'Player Base'),
                $gameTs
            );
        } catch (Throwable $exception) {
            if (function_exists('stobeLogWarn')) {
                stobeLogWarn('work-goal base location snapshot failed', [
                    'base_id' => strval($base['base_id'] ?? ''),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    return [
        'inside' => $inside,
        'base_id' => $inside ? strval($base['base_id'] ?? '') : '',
    ];
}

/**
 * Returns current selected-character base state only while telemetry is fresh.
 */
function stobeGetCurrentPlayerBaseState(int $maxAgeSeconds = 30): array
{
    $maxAgeSeconds = max(5, min(300, $maxAgeSeconds));
    $row = $GLOBALS['db']->fetchOne(
        "SELECT
            p.session_id, p.observer_serial, p.observer_name, p.game_ts,
            EXTRACT(EPOCH FROM (NOW() - p.observed_at))::int AS age_seconds,
            b.base_id, b.name, b.power_generated, b.power_required,
            b.battery_charge, b.battery_capacity, b.battery_drain, b.battery_charging,
            b.battery_mode, b.has_spare_power, b.members_inside,
            b.has_gates, b.gates_closed, b.details
         FROM player_base_presence p
         INNER JOIN player_bases b ON b.base_id = p.base_id
         WHERE p.scope_key = 'selected_player'
           AND p.inside = TRUE
           AND p.observed_at >= NOW() - ($1::int * INTERVAL '1 second')
         LIMIT 1",
        [$maxAgeSeconds]
    );
    return is_array($row) ? $row : [];
}
