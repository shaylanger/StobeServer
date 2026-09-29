<?php

/**
 * Supervised autonomy planner. The model proposes one catalog action; this
 * layer validates and normalizes it before the decision ledger can issue it.
 */

function stobeAutonomyPlannerActionContracts(): array
{
    $target = ['target'];
    return [
        'ATTACK' => $target,
        'SUICIDE' => [],
        'FOLLOW' => $target,
        'STOP_FOLLOW' => [],
        'STOP_ATTACK' => $target,
        'JOIN_PARTY' => [],
        'LEAVE' => [],
        'IDLE' => ['duration_ms'],
        'STOP_CARRYING' => [],
        'PICKUP_NPC' => $target,
        'GIVE_CATS' => ['target', 'amount'],
        'TAKE_CATS' => ['target', 'amount'],
        'TAKE_ITEM' => ['target', 'item', 'amount'],
        'EQUIP_ITEM' => ['item'],
        'GIVE_ITEM' => ['target', 'item', 'amount'],
        'DROP_ITEM' => ['item'],
        'ROLEPLAY_ACTION' => ['message'],
        'FACTION_RELATIONS' => ['target', 'amount'],
        'SET_BLOCK' => ['enabled'],
        'SET_HOLD' => ['enabled'],
        'SET_PASSIVE' => ['enabled'],
        'SET_JOBS' => ['enabled'],
        'SET_RANGED' => ['enabled'],
        'SET_TAUNT' => ['enabled'],
        'SET_SNEAK' => ['enabled'],
        'SET_RESOURCE' => ['enabled'],
        'SET_MEDIC' => ['enabled'],
        'REMOVE_LIMB' => ['target', 'limb'],
        'CUT_HORNS' => $target,
        'KNOCKOUT' => $target,
        'KILL' => $target,
        'USE_OBJECT' => ['object'],
        'USE_DRUGS' => ['item'],
        'DRINK' => ['item'],
        'FORCE_DRINK' => ['target', 'item'],
        'TRAVEL_LOCATION' => ['location_zone_id'],
        'MOVE_NEARBY' => ['direction', 'distance'],
        'FLEE' => [],
        'FIRST_AID' => ['target'],
        'REST' => [],
        'BUY_ITEM' => ['target', 'item', 'amount', 'max_total_price'],
        'SELL_ITEM' => ['target', 'item', 'amount', 'min_total_price'],
        'WORK_RESOURCE' => ['resource'],
        'PROSPECT' => ['resource'],
        'TALK' => ['message'],
    ];
}

function stobeAutonomyPlannerRequiredArguments(string $command): array
{
    return match ($command) {
        'TAKE_ITEM', 'BUY_ITEM', 'SELL_ITEM' => ['target', 'item'],
        'WORK_RESOURCE', 'PROSPECT' => ['resource'],
        'GIVE_ITEM' => ['target', 'item'],
        'FORCE_DRINK' => ['target'],
        'USE_OBJECT' => [],
        default => stobeAutonomyPlannerActionContracts()[$command] ?? [],
    };
}

function stobeAutonomyPlannerPolicy(array $session): array
{
    $policy = is_array($session['policy'] ?? null) ? $session['policy'] : [];
    $disabled = [];
    foreach (($policy['disabled_actions'] ?? []) as $command) {
        $normalized = strtoupper(trim(strval($command)));
        if ($normalized !== '') {
            $disabled[$normalized] = true;
        }
    }
    return [
        'mode' => strtolower(trim(strval($session['planner_mode'] ?? ($policy['planner_mode'] ?? 'llm')))) === 'pilot' ? 'pilot' : 'llm',
        'minimum_interval_seconds' => max(3, min(300, intval($policy['minimum_interval_seconds'] ?? 30))),
        'max_decisions_per_hour' => max(1, min(240, intval($policy['max_decisions_per_hour'] ?? 30))),
        'max_purchase_cats' => max(1, min(10000000, intval($policy['max_purchase_cats'] ?? 5000))),
        'minimum_sale_cats' => max(0, min(10000000, intval($policy['minimum_sale_cats'] ?? 1))),
        'disabled_actions' => $disabled,
    ];
}

function stobeAutonomyPlannerBackoffSeconds(int $failureCount, int $minimumIntervalSeconds): int
{
    $base = max(20, min(300, $minimumIntervalSeconds));
    $exponent = max(0, min(4, $failureCount - 1));
    return min(300, $base * (2 ** $exponent));
}

function stobeAutonomyPlannerShouldSuppressCompletedDuplicate(string $command): bool
{
    return !in_array(strtoupper(trim($command)), [
        'MOVE_NEARBY', 'FLEE', 'FIRST_AID', 'REST',
        'BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT',
    ], true);
}

function stobeAutonomyPlannerConnectorRequiresApiKey(array $config): bool
{
    $type = strtolower(trim(strval($config['connector_type'] ?? '')));
    if (in_array($type, ['player2', 'player2json', 'koboldcpp', 'koboldcppjson'], true)) {
        return false;
    }
    $host = strtolower(strval(parse_url(trim(strval($config['base_url'] ?? '')), PHP_URL_HOST) ?? ''));
    return !in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
}

function stobeAutonomyPlannerRecentActors(int $gameTs, int $limit = 30): array
{
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT people, data, type, gamets, location
         FROM eventlog
         WHERE gamets <= $1 AND gamets >= GREATEST(0, $1 - 21600)
         ORDER BY gamets DESC, rowid DESC LIMIT " . max(1, min(100, $limit)),
        [$gameTs]
    );
    $names = [];
    foreach ($rows as $row) {
        $people = preg_split('/[,;|]+/', strval($row['people'] ?? '')) ?: [];
        foreach ($people as $person) {
            $person = trim($person);
            if ($person !== '' && strlen($person) <= 120) {
                $names[strtolower($person)] = $person;
            }
        }
    }
    return array_values($names);
}

function stobeAutonomyPlannerRecentEvents(int $gameTs, int $limit = 18, string $npcName = ""): array
{
    $params = [$gameTs];
    $audienceSql = stobeEventAudienceSql($npcName, $params);
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT type, data, people, location, gamets
         FROM eventlog WHERE gamets <= $1 AND {$audienceSql}
         ORDER BY gamets DESC, rowid DESC LIMIT " . max(1, min(50, $limit)),
        $params
    );
    return array_map(static function (array $row): array {
        return [
            'type' => trim(strval($row['type'] ?? '')),
            'data' => mb_substr(trim(strval($row['data'] ?? '')), 0, 500),
            'people' => mb_substr(trim(strval($row['people'] ?? '')), 0, 300),
            'location' => mb_substr(trim(strval($row['location'] ?? '')), 0, 200),
            'game_ts' => intval($row['gamets'] ?? 0),
        ];
    }, $rows);
}

function stobeAutonomyPlannerBuildAllowlist(array $session, array $snapshot, array $npc): array
{
    $contracts = stobeAutonomyPlannerActionContracts();
    $policy = stobeAutonomyPlannerPolicy($session);
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT UPPER(command) AS command, action_name, description
         FROM combined_core_action
         WHERE is_activated = TRUE ORDER BY action_name, command"
    );
    $locations = stobeAutonomyListVisitedLocations(120);
    $nearbyActors = is_array($snapshot['nearby_actors'] ?? null) ? $snapshot['nearby_actors'] : [];
    $nearbyResources = is_array($snapshot['nearby_resources'] ?? null) ? $snapshot['nearby_resources'] : [];
    $inventoryItems = is_array($snapshot['inventory_items'] ?? null) ? $snapshot['inventory_items'] : [];
    $economy = is_array($snapshot['economy'] ?? null) ? $snapshot['economy'] : [];
    $actors = array_values(array_unique(array_map(
        static fn(array $actor): string => strval($actor['name'] ?? ''),
        $nearbyActors
    )));
    $status = is_array($snapshot['status'] ?? null) ? $snapshot['status'] : [];
    $health = is_array($snapshot['health'] ?? null) ? $snapshot['health'] : [];
    $hostiles = array_values(array_filter($nearbyActors, static fn(array $actor): bool =>
        stobeAutonomyBool($actor['hostile'] ?? false) && !stobeAutonomyBool($actor['dead'] ?? false) &&
        floatval($actor['distance'] ?? 1000000) <= 70.0
    ));
    $patientActors = array_values(array_filter($nearbyActors, static fn(array $actor): bool =>
        stobeAutonomyBool($actor['player_character'] ?? false) &&
        !stobeAutonomyBool($actor['dead'] ?? false) &&
        floatval($actor['distance'] ?? 1000000) <= 50.0 &&
        max(floatval($actor['first_aid_need'] ?? 0), floatval($actor['robotic_aid_need'] ?? 0)) > 0.05
    ));
    $hasMedicalSupplies = preg_match(
        '/\b(first aid|medical|robotics|repair kit|splint)\b/i',
        strval($npc['inventory'] ?? '') . ' ' . strval($npc['equipment'] ?? '')
    ) === 1;
    $selfAidNeed = max(floatval($health['first_aid_need'] ?? 0), floatval($health['robotic_aid_need'] ?? 0));
    $criticalThreat = count($hostiles) > 0 &&
        (stobeAutonomyBool($status['probably_dying'] ?? false) || floatval($health['overall'] ?? 1.0) < 0.45);
    $allowlist = [];
    foreach ($rows as $row) {
        $command = strtoupper(trim(strval($row['command'] ?? '')));
        $commandActors = $actors;
        if (!isset($contracts[$command]) || isset($policy['disabled_actions'][$command])) {
            continue;
        }
        if ($command === 'TRAVEL_LOCATION' && count($locations) === 0) {
            continue;
        }
        if (in_array($command, [
            'MOVE_NEARBY', 'FLEE', 'FIRST_AID', 'REST', 'ATTACK',
            'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT', 'KILL',
            'REMOVE_LIMB', 'CUT_HORNS',
            'BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT',
        ], true) &&
            !stobeAutonomyBool($status['can_take_orders'] ?? false)) {
            continue;
        }
        if ($command === 'FLEE' && count($hostiles) === 0) {
            continue;
        }
        if ($command === 'STOP_ATTACK' &&
            (!stobeAutonomyBool($status['in_combat'] ?? false) || count($hostiles) === 0)) {
            continue;
        }
        if ($command === 'FIRST_AID' &&
            (!$hasMedicalSupplies || ($selfAidNeed <= 0.05 && count($patientActors) === 0))) {
            continue;
        }
        if ($command === 'REST' &&
            (stobeAutonomyBool($status['fully_rested'] ?? true) ||
             !stobeAutonomyBool($status['rest_bed_available'] ?? false) ||
             count($hostiles) > 0 || $selfAidNeed > 0.05)) {
            continue;
        }
        if ($command === 'BUY_ITEM' && intval($economy['cats'] ?? 0) <= 0) {
            continue;
        }
        if ($command === 'KNOCKOUT') {
            $commandActors[] = strval($npc['name'] ?? '');
            $commandActors = array_values(array_unique(array_filter($commandActors)));
        }
        if (in_array('target', stobeAutonomyPlannerRequiredArguments($command), true) &&
            count($commandActors) === 0 && $command !== 'FIRST_AID') {
            continue;
        }
        if (in_array($command, ['FOLLOW', 'STOP_FOLLOW', 'JOIN_PARTY'], true)) {
            // The selected character is already in the player faction. These
            // adapters intentionally reject player-faction actors.
            continue;
        }
        if ($command === 'STOP_CARRYING' && !stobeAutonomyBool($snapshot['status']['carrying'] ?? false)) {
            continue;
        }
        if (in_array($command, ['EQUIP_ITEM', 'GIVE_ITEM', 'DROP_ITEM', 'USE_DRUGS', 'DRINK', 'FORCE_DRINK'], true) &&
            trim(strval($npc['inventory'] ?? '')) === '' && trim(strval($npc['equipment'] ?? '')) === '') {
            continue;
        }
        $entry = [
            'command' => $command,
            'description' => trim(strval($row['description'] ?? '')),
            'arguments' => $contracts[$command],
            'required_arguments' => stobeAutonomyPlannerRequiredArguments($command),
            'survival_priority' => 0,
        ];
        if ($command === 'FLEE') {
            $entry['survival_priority'] = $criticalThreat ? 100 : 40;
            $entry['required_now'] = $criticalThreat;
            $entry['threat_count'] = count($hostiles);
        } elseif ($command === 'FIRST_AID') {
            $urgentAid = count($hostiles) === 0 &&
                (floatval($health['bleed_rate'] ?? 0) > 0.1 || stobeAutonomyBool($status['probably_dying'] ?? false));
            $entry['survival_priority'] = $urgentAid ? 90 : 50;
            $entry['required_now'] = $urgentAid;
        } elseif ($command === 'REST') {
            $entry['survival_priority'] = 30;
        } elseif ($command === 'MOVE_NEARBY') {
            $entry['directions'] = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
            $entry['distance_range'] = [10, 80];
            $entry['origin'] = $snapshot['position'] ?? [];
        }
        if ($command === 'FLEE') {
            $origin = is_array($snapshot['position'] ?? null) ? $snapshot['position'] : [];
            $awayX = 0.0;
            $awayZ = 0.0;
            foreach ($hostiles as $hostile) {
                $dx = floatval($origin['x'] ?? 0) - floatval($hostile['x'] ?? 0);
                $dz = floatval($origin['z'] ?? 0) - floatval($hostile['z'] ?? 0);
                $length = max(0.001, sqrt($dx * $dx + $dz * $dz));
                $weight = 1.0 / max(1.0, floatval($hostile['distance'] ?? $length));
                $awayX += ($dx / $length) * $weight;
                $awayZ += ($dz / $length) * $weight;
            }
            $awayLength = sqrt($awayX * $awayX + $awayZ * $awayZ);
            if ($awayLength < 0.001) {
                $awayX = 1.0;
                $awayZ = 0.0;
                $awayLength = 1.0;
            }
            $entry += [
                'x' => floatval($origin['x'] ?? 0) + ($awayX / $awayLength) * 80.0,
                'y' => floatval($origin['y'] ?? 0),
                'z' => floatval($origin['z'] ?? 0) + ($awayZ / $awayLength) * 80.0,
                'arrival_radius' => 6.0,
                'safe_radius' => 70.0,
            ];
        }
        if (in_array('target', $contracts[$command], true)) {
            $validTargets = $commandActors;
            if (in_array($command, ['PICKUP_NPC', 'TAKE_ITEM', 'REMOVE_LIMB', 'CUT_HORNS', 'KILL', 'FORCE_DRINK'], true)) {
                $validTargets = array_values(array_map(
                    static fn(array $actor): string => strval($actor['name'] ?? ''),
                    array_filter($nearbyActors, static fn(array $actor): bool =>
                        stobeAutonomyBool($actor['dead'] ?? false) || stobeAutonomyBool($actor['unconscious'] ?? false))
                ));
                if (count($validTargets) === 0) {
                    continue;
                }
            } elseif ($command === 'KNOCKOUT') {
                $validTargets = array_values(array_map(
                    static fn(array $actor): string => strval($actor['name'] ?? ''),
                    array_filter($nearbyActors, static fn(array $actor): bool =>
                        !stobeAutonomyBool($actor['dead'] ?? false) &&
                        stobeAutonomyBool($actor['unconscious'] ?? false))
                ));
                $selfName = trim(strval($npc['name'] ?? ''));
                if ($selfName !== '') {
                    $validTargets[] = $selfName;
                }
            } elseif ($command === 'STOP_ATTACK') {
                $validTargets = array_values(array_map(
                    static fn(array $actor): string => strval($actor['name'] ?? ''),
                    $hostiles
                ));
            } elseif ($command === 'ATTACK') {
                $validTargets = array_values(array_map(
                    static fn(array $actor): string => strval($actor['name'] ?? ''),
                    array_filter($nearbyActors, static fn(array $actor): bool => !stobeAutonomyBool($actor['dead'] ?? false))
                ));
                if (count($validTargets) === 0) {
                    continue;
                }
            }
            $entry['valid_targets'] = array_values(array_slice(array_unique(array_filter($validTargets)), 0, 30));
        }
        if (in_array($command, [
            'ATTACK', 'TAKE_ITEM', 'KNOCKOUT', 'KILL',
            'REMOVE_LIMB', 'CUT_HORNS',
        ], true)) {
            $serialCandidates = [];
            foreach ($nearbyActors as $actor) {
                $name = trim(strval($actor['name'] ?? ''));
                $serial = max(0, intval($actor['runtime_serial'] ?? 0));
                if ($name === '' || $serial <= 0) {
                    continue;
                }
                $key = strtolower($name);
                $serialCandidates[$key] ??= [];
                $serialCandidates[$key][$serial] = true;
            }
            if ($command === 'KNOCKOUT') {
                $selfName = trim(strval($npc['name'] ?? ''));
                $selfSerial = max(0, intval($snapshot['runtime_serial'] ?? 0));
                if ($selfName !== '' && $selfSerial > 0) {
                    $serialCandidates[strtolower($selfName)] = [$selfSerial => true];
                    $entry['valid_targets'][] = $selfName;
                }
            }
            $entry['valid_targets'] = array_values(array_unique(array_filter(
                $entry['valid_targets'] ?? [],
                static function (string $name) use ($serialCandidates): bool {
                    $serials = array_keys($serialCandidates[strtolower($name)] ?? []);
                    return count($serials) === 1;
                }
            )));
            $entry['target_runtime_serials'] = [];
            foreach ($entry['valid_targets'] as $name) {
                $serials = array_keys($serialCandidates[strtolower($name)] ?? []);
                if (count($serials) === 1) {
                    $entry['target_runtime_serials'][strtolower($name)] = intval($serials[0]);
                }
            }
            if (count($entry['valid_targets']) === 0) {
                continue;
            }
        }
        if ($command === 'FIRST_AID') {
            $patients = $patientActors;
            if ($selfAidNeed > 0.05) {
                array_unshift($patients, [
                    'name' => strval($npc['name'] ?? ''),
                    'runtime_serial' => intval($snapshot['runtime_serial'] ?? 0),
                ]);
            }
            $entry['valid_targets'] = [];
            $entry['target_runtime_serials'] = [];
            foreach ($patients as $patient) {
                $name = trim(strval($patient['name'] ?? ''));
                $serial = max(0, intval($patient['runtime_serial'] ?? 0));
                if ($name === '' || $serial <= 0) {
                    continue;
                }
                $entry['valid_targets'][] = $name;
                $entry['target_runtime_serials'][strtolower($name)] = $serial;
            }
            $entry['valid_targets'] = array_values(array_unique($entry['valid_targets']));
            if (count($entry['valid_targets']) === 0) {
                continue;
            }
        }
        if ($command === 'TRAVEL_LOCATION') {
            $entry['visited_locations'] = array_map(static fn(array $location): array => [
                'location_zone_id' => intval($location['id']),
                'zone_name' => strval($location['zone_name']),
                'city_name' => strval($location['city_name']),
            ], $locations);
        }
        if (in_array($command, ['BUY_ITEM', 'SELL_ITEM'], true)) {
            $traderCandidates = [];
            foreach ($nearbyActors as $actor) {
                if (!stobeAutonomyBool($actor['trader'] ?? false) ||
                    stobeAutonomyBool($actor['dead'] ?? false) ||
                    stobeAutonomyBool($actor['unconscious'] ?? false) ||
                    floatval($actor['distance'] ?? 1000000) > 15.0) {
                    continue;
                }
                $name = trim(strval($actor['name'] ?? ''));
                $serial = max(0, intval($actor['runtime_serial'] ?? 0));
                if ($name === '' || $serial <= 0) {
                    continue;
                }
                $traderCandidates[strtolower($name)] ??= [];
                $traderCandidates[strtolower($name)][] = $actor;
            }
            $entry['valid_targets'] = [];
            $entry['target_runtime_serials'] = [];
            $entry['valid_items_by_target'] = [];
            foreach ($traderCandidates as $key => $matches) {
                if (count($matches) !== 1) {
                    continue;
                }
                $actor = $matches[0];
                $name = strval($actor['name']);
                $items = $command === 'BUY_ITEM'
                    ? (is_array($actor['trader_items'] ?? null) ? $actor['trader_items'] : [])
                    : $inventoryItems;
                $validItems = [];
                foreach ($items as $item) {
                    if (!is_array($item) || intval($item['count'] ?? 0) !== 1) {
                        continue;
                    }
                    $itemName = trim(strval($item['name'] ?? ''));
                    $price = intval($item[$command === 'BUY_ITEM' ? 'buy_value_each' : 'sell_value_each'] ?? 0);
                    if ($itemName === '' || $price <= 0) {
                        continue;
                    }
                    if ($command === 'BUY_ITEM' &&
                        ($price > intval($policy['max_purchase_cats']) ||
                         $price > intval($economy['cats'] ?? 0))) {
                        continue;
                    }
                    if ($command === 'SELL_ITEM' &&
                        $price < intval($policy['minimum_sale_cats'])) {
                        continue;
                    }
                    $validItems[strtolower($itemName)] = [
                        'name' => $itemName,
                        'price' => $price,
                    ];
                }
                if (count($validItems) === 0) {
                    continue;
                }
                $entry['valid_targets'][] = $name;
                $entry['target_runtime_serials'][$key] = intval($actor['runtime_serial']);
                $entry['valid_items_by_target'][$key] = $validItems;
            }
            if (count($entry['valid_targets']) === 0) {
                continue;
            }
            $entry['amount_range'] = [1, 1];
            if ($command === 'BUY_ITEM') {
                $entry['max_purchase_cats'] = intval($policy['max_purchase_cats']);
                $entry['available_cats'] = intval($economy['cats'] ?? 0);
            } else {
                $entry['minimum_sale_cats'] = intval($policy['minimum_sale_cats']);
            }
        }
        if (in_array($command, ['WORK_RESOURCE', 'PROSPECT'], true)) {
            $resourceNames = [];
            foreach ($nearbyResources as $resource) {
                if (!is_array($resource) || !stobeAutonomyBool($resource['usable'] ?? false) ||
                    floatval($resource['distance'] ?? 1000000) > 250.0) {
                    continue;
                }
                $name = trim(strval($resource['name'] ?? ''));
                $serial = max(0, intval($resource['runtime_serial'] ?? 0));
                if ($name === '' || $serial <= 0) {
                    continue;
                }
                $resourceNames[strtolower($name)] ??= [];
                $resourceNames[strtolower($name)][$serial] = true;
            }
            $entry['valid_resources'] = [];
            $entry['resource_runtime_serials'] = [];
            foreach ($resourceNames as $key => $serials) {
                if (count($serials) !== 1) {
                    continue;
                }
                foreach ($nearbyResources as $resource) {
                    if (strtolower(trim(strval($resource['name'] ?? ''))) === $key) {
                        $entry['valid_resources'][] = strval($resource['name']);
                        break;
                    }
                }
                $entry['resource_runtime_serials'][$key] = intval(array_key_first($serials));
            }
            if (count($entry['valid_resources']) === 0) {
                continue;
            }
        }
        $allowlist[] = $entry;
    }
    return $allowlist;
}

function stobeAutonomyPlannerContext(array $session, array $snapshot, array $allowlist): array
{
    $npc = getNpcById(intval($session['npc_id'] ?? 0));
    if (!$npc) {
        throw new RuntimeException('Selected autonomy NPC no longer exists.');
    }
    $metadata = stobeAutonomyDecodeJsonColumn($npc['metadata'] ?? '{}');
    $extended = stobeAutonomyDecodeJsonColumn($npc['extended_data'] ?? '{}');
    return [
        'npc' => [
            'id' => intval($npc['id'] ?? 0),
            'name' => strval($npc['name'] ?? ''),
            'race' => strval($npc['race'] ?? ''),
            'faction' => strval($npc['faction'] ?? ''),
            'personality' => mb_substr(strval($npc['personality'] ?? ''), 0, 1800),
            'backstory' => mb_substr(strval($npc['backstory'] ?? ''), 0, 1800),
            'occupation' => mb_substr(strval($npc['occupation'] ?? ''), 0, 500),
            'goals' => mb_substr(strval($npc['goals'] ?? ''), 0, 1200),
            'relationships' => mb_substr(strval($npc['relationships'] ?? ''), 0, 1200),
            'inventory' => mb_substr(strval($npc['inventory'] ?? ''), 0, 1800),
            'equipment' => mb_substr(strval($npc['equipment'] ?? ''), 0, 1200),
            'hunger' => strval($npc['hunger'] ?? ''),
            'blood' => strval($npc['blood'] ?? ''),
            'limbs' => stobeAutonomyDecodeJsonColumn($npc['limbs'] ?? '{}'),
            'runtime' => array_intersect_key($metadata + $extended, array_flip([
                'current_goal', 'current_order', 'current_task', 'location', 'weather',
                'carrying', 'cats', 'health', 'conscious', 'combat',
            ])),
        ],
        'directive' => strval($session['long_term_directive'] ?? ''),
        'persistent_goal' => $session['current_goal'] ?? [],
        'snapshot' => $snapshot,
        'recent_events' => stobeAutonomyPlannerRecentEvents(intval($snapshot['game_ts'] ?? 0), 18, strval($npc['name'] ?? '')),
        'recent_autonomy' => array_map(static fn(array $event): array => [
            'event_type' => strval($event['event_type'] ?? ''),
            'command' => strval($event['command'] ?? ''),
            'outcome' => strval($event['outcome'] ?? ''),
            'reason' => mb_substr(strval($event['reason'] ?? ''), 0, 300),
            'game_ts' => intval($event['game_ts'] ?? 0),
        ], stobeAutonomyListEvents(12)),
        'town_economy' => function_exists('stobeAutonomyEconomySummary')
            ? stobeAutonomyEconomySummary() : [],
        'allowlist' => $allowlist,
    ];
}

function stobeAutonomyPlannerAuditContext(array $session, array $snapshot, array $allowlist): array
{
    $policy = stobeAutonomyPlannerPolicy($session);
    return [
        'context_hash' => strval($snapshot['context_hash'] ?? ''),
        'allowlist_count' => count($allowlist),
        'allowlist_commands' => array_values(array_map(
            static fn(array $entry): string => strval($entry['command'] ?? ''),
            array_slice($allowlist, 0, 100)
        )),
        'policy' => [
            'preset' => strval(($session['policy']['preset'] ?? 'full_autonomy')),
            'minimum_interval_seconds' => intval($policy['minimum_interval_seconds']),
            'max_decisions_per_hour' => intval($policy['max_decisions_per_hour']),
            'disabled_actions' => array_values(array_keys($policy['disabled_actions'])),
        ],
        'planner_connector_id' => max(0, intval($session['planner_connector_id'] ?? 0)),
    ];
}

function stobeAutonomyPlannerDecodeResponse(string $raw): array|false
{
    $text = trim($raw);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
    }
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : false;
}

function stobeAutonomyPlannerCleanText(mixed $value, int $maxLength): string
{
    $text = trim(strval($value));
    $text = str_replace(["\r", "\n", '@'], [' ', ' ', ' '], $text);
    return mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, $maxLength);
}

function stobeAutonomyPlannerNormalizeProposal(array $response, array $allowlist): array
{
    foreach (array_keys($response) as $field) {
        if (!in_array($field, ['goal', 'decision', 'reason'], true)) {
            throw new InvalidArgumentException('planner_unexpected_top_level_field');
        }
    }
    if (!array_key_exists('goal', $response) || !is_array($response['goal'])) {
        throw new InvalidArgumentException('planner_goal_not_object');
    }
    if (!array_key_exists('decision', $response)) {
        throw new InvalidArgumentException('planner_decision_missing');
    }
    $goalInput = is_array($response['goal'] ?? null) ? $response['goal'] : [];
    foreach (array_keys($goalInput) as $field) {
        if (!in_array($field, ['summary', 'status'], true)) {
            throw new InvalidArgumentException('planner_unexpected_goal_field');
        }
    }
    if (!array_key_exists('summary', $goalInput) || !array_key_exists('status', $goalInput)) {
        throw new InvalidArgumentException('planner_goal_fields_missing');
    }
    $goalStatus = strtolower(trim(strval($goalInput['status'] ?? 'active')));
    if (!in_array($goalStatus, ['active', 'complete', 'blocked'], true)) {
        throw new InvalidArgumentException('planner_goal_status_invalid');
    }
    $goal = [
        'summary' => stobeAutonomyPlannerCleanText($goalInput['summary'] ?? '', 500),
        'status' => $goalStatus,
        'updated_at' => time(),
    ];
    $requiredNow = array_values(array_filter($allowlist, static fn(array $entry): bool =>
        stobeAutonomyBool($entry['required_now'] ?? false)
    ));
    if (($response['decision'] ?? null) === null && count($requiredNow) > 0) {
        throw new InvalidArgumentException('planner_survival_action_required');
    }
    if (($response['decision'] ?? null) === null) {
        return ['goal' => $goal, 'decision' => null, 'reason' => stobeAutonomyPlannerCleanText($response['reason'] ?? '', 500)];
    }
    if (!is_array($response['decision'] ?? null)) {
        throw new InvalidArgumentException('planner_decision_not_object_or_null');
    }
    $decision = $response['decision'];
    $command = strtoupper(trim(strval($decision['command'] ?? '')));
    $allowed = [];
    foreach ($allowlist as $entry) {
        $allowed[strtoupper(strval($entry['command'] ?? ''))] = $entry;
    }
    if ($command === '' || !isset($allowed[$command])) {
        throw new InvalidArgumentException('planner_command_not_allowed');
    }
    if (count($requiredNow) > 0 && !stobeAutonomyBool($allowed[$command]['required_now'] ?? false)) {
        throw new InvalidArgumentException('planner_survival_action_required');
    }
    $contracts = stobeAutonomyPlannerActionContracts();
    $validDecisionFields = array_merge(['command'], $contracts[$command]);
    foreach (array_keys($decision) as $field) {
        if (!in_array($field, $validDecisionFields, true)) {
            throw new InvalidArgumentException('planner_unexpected_decision_field');
        }
    }
    foreach (stobeAutonomyPlannerRequiredArguments($command) as $field) {
        if (!array_key_exists($field, $decision)) {
            throw new InvalidArgumentException('planner_missing_' . $field);
        }
    }
    $args = [];
    foreach ($contracts[$command] as $field) {
        if ($field === 'amount') {
            $defaultAmount = in_array($command, ['TAKE_ITEM', 'GIVE_ITEM'], true) ? 1 : 0;
            $args[$field] = intval($decision[$field] ?? $defaultAmount);
        } elseif (in_array($field, ['max_total_price', 'min_total_price'], true)) {
            $args[$field] = max(0, min(10000000, intval($decision[$field] ?? 0)));
        } elseif ($field === 'duration_ms') {
            $args[$field] = max(500, min(30000, intval($decision[$field] ?? 1500)));
        } elseif ($field === 'location_zone_id') {
            $args[$field] = intval($decision[$field] ?? 0);
        } elseif ($field === 'distance') {
            if (!is_numeric($decision[$field] ?? null)) {
                throw new InvalidArgumentException('planner_distance_invalid');
            }
            $args[$field] = max(10.0, min(80.0, floatval($decision[$field] ?? 25.0)));
        } elseif ($field === 'direction') {
            $args[$field] = strtoupper(stobeAutonomyPlannerCleanText($decision[$field] ?? '', 3));
        } elseif ($field === 'enabled') {
            $args[$field] = stobeAutonomyBool($decision[$field] ?? false);
        } else {
            $args[$field] = stobeAutonomyPlannerCleanText($decision[$field] ?? '', 500);
        }
    }
    foreach (stobeAutonomyPlannerRequiredArguments($command) as $field) {
        if (in_array($field, ['target', 'item', 'message', 'limb', 'object'], true) && $args[$field] === '') {
            throw new InvalidArgumentException('planner_missing_' . $field);
        }
        if ($field === 'amount' && $args[$field] === 0) {
            throw new InvalidArgumentException('planner_invalid_amount');
        }
    }
    if (isset($args['item']) && preg_match('/[,;]/', strval($args['item'])) === 1) {
        throw new InvalidArgumentException('planner_item_must_be_single');
    }
    if ($command === 'TAKE_ITEM') {
        $args['amount'] = max(1, min(20, intval($args['amount'] ?? 1)));
        if (in_array(strtolower(trim(strval($args['item'] ?? ''))), [
            'equipment', 'equip', 'all', 'everything', 'inventory', 'items', 'loot',
        ], true)) {
            throw new InvalidArgumentException('planner_loot_item_must_be_specific');
        }
    }
    if ($command === 'EQUIP_ITEM') {
        $args['amount'] = 1;
    }
    if (in_array($command, ['BUY_ITEM', 'SELL_ITEM'], true)) {
        $args['amount'] = 1;
        $targetKey = strtolower(strval($args['target'] ?? ''));
        $itemKey = strtolower(strval($args['item'] ?? ''));
        $items = is_array($allowed[$command]['valid_items_by_target'][$targetKey] ?? null)
            ? $allowed[$command]['valid_items_by_target'][$targetKey] : [];
        $observed = $items[$itemKey] ?? null;
        if (!is_array($observed)) {
            throw new InvalidArgumentException('planner_trade_item_not_observed');
        }
        $observedPrice = max(0, intval($observed['price'] ?? 0));
        if ($command === 'BUY_ITEM') {
            $policyCap = max(1, intval($allowed[$command]['max_purchase_cats'] ?? 1));
            if ($args['max_total_price'] <= 0) {
                throw new InvalidArgumentException('planner_buy_limit_required');
            }
            $args['max_total_price'] = min($args['max_total_price'], $policyCap);
            if ($observedPrice > $args['max_total_price']) {
                throw new InvalidArgumentException('planner_buy_price_limit_exceeded');
            }
        } else {
            $policyFloor = max(0, intval($allowed[$command]['minimum_sale_cats'] ?? 0));
            $args['min_total_price'] = max($args['min_total_price'], $policyFloor);
            if ($observedPrice < $args['min_total_price']) {
                throw new InvalidArgumentException('planner_sell_price_below_minimum');
            }
        }
        $args['observed_price'] = $observedPrice;
    }
    if ($command === 'REMOVE_LIMB' &&
        !in_array(strtoupper(strval($args['limb'] ?? '')), [
            'LEFT_ARM', 'RIGHT_ARM', 'LEFT_LEG', 'RIGHT_LEG',
        ], true)) {
        throw new InvalidArgumentException('planner_limb_invalid');
    }
    if (trim(strval($args['target'] ?? '')) !== '' && isset($allowed[$command]['valid_targets'])) {
        $validTargets = array_map('strtolower', $allowed[$command]['valid_targets']);
        if (!in_array(strtolower($args['target']), $validTargets, true) &&
            !($command === 'KNOCKOUT' && strtolower($args['target']) === strtolower(strval($GLOBALS['stobe_autonomy_npc_name'] ?? '')))) {
            throw new InvalidArgumentException('planner_target_not_observed');
        }
    }
    if ($command === 'TRAVEL_LOCATION') {
        $location = stobeAutonomyGetVisitedLocation(intval($args['location_zone_id'] ?? 0));
        if (!$location) {
            throw new InvalidArgumentException('planner_location_not_visited');
        }
        $args += $location;
        $args['arrival_radius'] = 8.0;
    }
    if ($command === 'MOVE_NEARBY') {
        $vectors = [
            'N' => [0.0, -1.0], 'NE' => [0.7071, -0.7071],
            'E' => [1.0, 0.0], 'SE' => [0.7071, 0.7071],
            'S' => [0.0, 1.0], 'SW' => [-0.7071, 0.7071],
            'W' => [-1.0, 0.0], 'NW' => [-0.7071, -0.7071],
        ];
        if (!isset($vectors[$args['direction'] ?? ''])) {
            throw new InvalidArgumentException('planner_direction_invalid');
        }
        $position = $allowed[$command]['origin'] ?? ($GLOBALS['stobe_autonomy_snapshot_position'] ?? []);
        if (!is_array($position) || !isset($position['x'], $position['y'], $position['z'])) {
            throw new InvalidArgumentException('planner_origin_missing');
        }
        $args['x'] = floatval($position['x']) + $vectors[$args['direction']][0] * $args['distance'];
        $args['y'] = floatval($position['y']);
        $args['z'] = floatval($position['z']) + $vectors[$args['direction']][1] * $args['distance'];
        $args['arrival_radius'] = 4.0;
    }
    if ($command === 'FLEE') {
        foreach (['x', 'y', 'z', 'arrival_radius', 'safe_radius'] as $field) {
            if (!isset($allowed[$command][$field]) || !is_numeric($allowed[$command][$field])) {
                throw new InvalidArgumentException('planner_flee_destination_missing');
            }
            $args[$field] = floatval($allowed[$command][$field]);
        }
    }
    if ($command === 'FIRST_AID' || in_array($command, [
        'ATTACK', 'TAKE_ITEM', 'KNOCKOUT', 'KILL',
        'REMOVE_LIMB', 'CUT_HORNS', 'BUY_ITEM', 'SELL_ITEM',
    ], true)) {
        $serials = is_array($allowed[$command]['target_runtime_serials'] ?? null)
            ? $allowed[$command]['target_runtime_serials'] : [];
        $serial = max(0, intval($serials[strtolower(strval($args['target'] ?? ''))] ?? 0));
        if ($serial <= 0) {
            throw new InvalidArgumentException('planner_target_serial_missing');
        }
        $args['target_runtime_serial'] = $serial;
    }
    if (in_array($command, ['WORK_RESOURCE', 'PROSPECT'], true)) {
        $serials = is_array($allowed[$command]['resource_runtime_serials'] ?? null)
            ? $allowed[$command]['resource_runtime_serials'] : [];
        $serial = max(0, intval($serials[strtolower(strval($args['resource'] ?? ''))] ?? 0));
        if ($serial <= 0) {
            throw new InvalidArgumentException('planner_resource_not_observed');
        }
        $args['resource_runtime_serial'] = $serial;
    }
    $args['legacy_argument'] = stobeAutonomyPlannerLegacyArgument($command, $args);
    return [
        'goal' => $goal,
        'decision' => ['command' => $command, 'arguments' => $args],
        'reason' => stobeAutonomyPlannerCleanText($response['reason'] ?? '', 500),
    ];
}

function stobeAutonomyPlannerLegacyArgument(string $command, array $args): string
{
    $target = strval($args['target'] ?? '');
    $item = strval($args['item'] ?? '');
    $amount = intval($args['amount'] ?? 1);
    return match ($command) {
        'GIVE_CATS', 'TAKE_CATS', 'FACTION_RELATIONS' => $target . '@' . $amount,
        'GIVE_ITEM', 'TAKE_ITEM', 'BUY_ITEM', 'SELL_ITEM' =>
            ($target !== '' ? $target . '@' : '') . $item . '@' . max(1, $amount),
        'DROP_ITEM' => $item,
        'REMOVE_LIMB' => $target . '@' . strval($args['limb'] ?? ''),
        'FORCE_DRINK' => $target . '@' . ($item !== '' ? $item : 'Cactus Rum'),
        'TRAVEL_LOCATION' => implode('@', [
            strval($args['x']), strval($args['y']), strval($args['z']),
            strval($args['city_name'] ?: $args['zone_name']),
        ]),
        'SET_BLOCK', 'SET_HOLD', 'SET_PASSIVE', 'SET_JOBS', 'SET_RANGED',
        'SET_TAUNT', 'SET_SNEAK', 'SET_RESOURCE', 'SET_MEDIC' => ($args['enabled'] ?? false) ? 'ON' : 'OFF',
        'ROLEPLAY_ACTION', 'TALK' => strval($args['message'] ?? ''),
        'USE_OBJECT' => strval($args['object'] ?? ''),
        'WORK_RESOURCE', 'PROSPECT' => strval($args['resource'] ?? ''),
        'USE_DRUGS', 'DRINK' => $item,
        default => $target !== '' ? $target : $item,
    };
}

function stobeAutonomyPlannerCall(array $context, array $npc, array $session = []): array
{
    $promptJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($promptJson)) {
        throw new RuntimeException('planner_context_encoding_failed');
    }
    $messages = [
        ['role' => 'system', 'content' => 'You control one Kenshi squad NPC. Choose at most one action from allowlist. Survival comes first: if any allowlist entry has required_now=true, choose one such entry. Otherwise prefer higher survival_priority when the NPC or squad needs immediate safety, treatment, or recovery. Never invent a command, target, item, or location. Do not repeat a command with identical arguments when recent_autonomy shows it already completed; use decision:null unless current context clearly requires a distinct new action. Return only JSON: {"goal":{"summary":"...","status":"active|complete|blocked"},"decision":null|{"command":"...", plus fields listed for that command},"reason":"..."}. Include every required_arguments field and no unlisted fields. Use decision:null when waiting is best.'],
        ['role' => 'user', 'content' => $promptJson],
    ];
    $started = microtime(true);
    if (isset($GLOBALS['stobe_autonomy_planner_test_callback']) && is_callable($GLOBALS['stobe_autonomy_planner_test_callback'])) {
        $raw = call_user_func($GLOBALS['stobe_autonomy_planner_test_callback'], $messages, $context);
    } else {
        require_once dirname(__DIR__) . '/connector/llm_dispatcher.php';
        $connectorId = max(0, intval($session['planner_connector_id'] ?? 0));
        if ($connectorId > 0) {
            $connector = getLlmConnectorById($connectorId);
            if (!$connector) {
                throw new RuntimeException('planner_connector_not_found');
            }
            $config = stobeBuildLlmConfigFromConnector($connector);
        } else {
            $config = getLlmConfigForNpcPurpose($npc, 'response');
        }
        if (trim(strval($config['api_key'] ?? '')) === '' && stobeAutonomyPlannerConnectorRequiresApiKey($config)) {
            throw new RuntimeException('planner_missing_api_key');
        }
        $config['max_tokens'] = max(300, min(900, intval($config['max_tokens'] ?? 500)));
        $raw = stobeCallLLM($messages, $config, [
            'npc_name' => strval($npc['name'] ?? ''),
            'event_type' => 'autonomy_planner',
            'response_format' => ['type' => 'json_object'],
        ]);
    }
    $latencyMs = max(0, intval(round((microtime(true) - $started) * 1000)));
    if ($raw === false || trim(strval($raw)) === '') {
        throw new RuntimeException('planner_connector_failed');
    }
    $raw = strval($raw);
    $decoded = stobeAutonomyPlannerDecodeResponse($raw);
    if (!$decoded) {
        throw new RuntimeException('planner_malformed_json');
    }
    return [
        'decoded' => $decoded,
        'raw' => $raw,
        'prompt_hash' => hash('sha256', $promptJson),
        'response_hash' => hash('sha256', $raw),
        'latency_ms' => $latencyMs,
        'prompt_tokens' => intval(ceil(strlen($promptJson) / 4)),
        'completion_tokens' => intval(ceil(strlen($raw) / 4)),
    ];
}
