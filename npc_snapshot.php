<?php
require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("npc_snapshot");

/**
 * StobeServer - NPC snapshot ingest endpoint.
 *
 * Receives JSON snapshots from Stobe.dll and updates core_npc with
 * high/medium-confidence identity, faction, state, and stats metadata.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    stobeLogWarn('npc_snapshot rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$snapshot = [];
if (isset($input['npc']) && is_array($input['npc'])) {
    $snapshot = $input['npc'];
} else {
    $snapshot = $input;
}

$name = trim(strval($snapshot['name'] ?? ''));
if ($name === '') {
    stobeLogWarn('npc_snapshot rejected: missing npc.name');
    http_response_code(400);
    echo json_encode(["error" => "Missing npc.name"]);
    exit;
}

$source = trim(strval($input['source'] ?? ($snapshot['source'] ?? 'npc_snapshot')));
$gamets = intval($input['game_ts'] ?? 0);
if ($gamets <= 0) {
    $gamets = intval($snapshot['game_ts'] ?? 0);
}
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($gamets, 'npc_snapshot');
}
$isInventoryLiveSync = (strcasecmp($source, 'inventory_live_sync') === 0);

$stored = storeNpcSnapshot($snapshot, $gamets);
if ($stored && function_exists('stobeNegTickThrottled')) {
    try {
        stobeNegTickThrottled('npc_snapshot', '', '', $gamets);
    } catch (Throwable $negError) {
        stobeLogWarn('Negotiation tick failed', ['error' => $negError->getMessage()]);
    }
}
if (!$stored) {
    stobeLogError('npc_snapshot failed to persist', [
        'name' => $name,
        'source' => $source,
        'gamets' => $gamets,
    ]);
    http_response_code(500);
    echo json_encode(["error" => "Failed to store snapshot"]);
    exit;
}

$nearbyObserved = 0;
$nearbyEntries = $snapshot['nearby'] ?? [];
if (is_array($nearbyEntries)) {
    foreach ($nearbyEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $nearName = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
        if ($nearName === '' || strcasecmp($nearName, $name) === 0) {
            continue;
        }

        $nearRace = trim(strval($entry['race'] ?? ''));
        $nearFaction = trim(strval($entry['faction'] ?? ''));
        $nearFactionId = trim(strval($entry['faction_id'] ?? ($entry['factionID'] ?? '')));
        $nearFaction = composeFactionWithId($nearFaction, $nearFactionId);
        $nearGender = trim(strval($entry['gender'] ?? ''));
        $nearHealth = trim(strval($entry['health'] ?? ''));
        $nearEquipment = trim(strval($entry['equipment'] ?? ''));
        $nearStorageId = trim(strval($entry['storage_id'] ?? ''));
        $nearDist = trim(strval($entry['dist'] ?? ''));
        $nearMedical = $entry['medical'] ?? [];
        if (!is_array($nearMedical) && is_string($nearMedical)) {
            $decodedNearMedical = json_decode($nearMedical, true);
            if (is_array($decodedNearMedical)) {
                $nearMedical = $decodedNearMedical;
            }
        }

        // Keep imported nearby NPC snapshots minimal; appearance remains blank.
        $appearance = '';

        $nearOrders = [];
        if (isset($entry['orders']) && is_array($entry['orders'])) {
            $nearOrders = $entry['orders'];
        } elseif (isset($entry['orders']) && is_string($entry['orders'])) {
            $decodedNearOrders = json_decode($entry['orders'], true);
            if (is_array($decodedNearOrders)) {
                $nearOrders = $decodedNearOrders;
            }
        }

        $storedNearbySnapshot = storeNpcSnapshot([
            'name' => $nearName,
            'race' => $nearRace,
            'faction' => $nearFaction,
            'gender' => $nearGender,
            'storage_id' => $nearStorageId,
            'equipment' => $nearEquipment,
            'health' => $nearHealth,
            'dist' => $nearDist,
            'is_player_character' => false,
            'bounty' => $entry['bounty'] ?? 0,
            'bounty_info' => $entry['bounty_info'] ?? ($entry['bounty_details'] ?? []),
            'stats' => $entry['stats'] ?? [],
            'medical' => $nearMedical,
            'orders' => $nearOrders,
            'block' => $entry['block'] ?? ($nearOrders['block'] ?? null),
            'hold' => $entry['hold'] ?? ($nearOrders['hold'] ?? null),
            'passive' => $entry['passive'] ?? ($nearOrders['passive'] ?? null),
            'jobs' => $entry['jobs'] ?? ($nearOrders['jobs'] ?? null),
            'job_list' => $entry['job_list'] ?? ($nearOrders['job_list'] ?? []),
            'ranged' => $entry['ranged'] ?? ($nearOrders['ranged'] ?? null),
            'taunt' => $entry['taunt'] ?? ($nearOrders['taunt'] ?? null),
            'sneak' => $entry['sneak'] ?? ($nearOrders['sneak'] ?? null),
            'resource' => $entry['resource'] ?? ($nearOrders['resource'] ?? null),
            'medic' => $entry['medic'] ?? ($nearOrders['medic'] ?? null),
            'current_action' => $entry['current_action'] ?? '',
            'is_moving' => $entry['is_moving'] ?? null,
            'is_running' => $entry['is_running'] ?? null,
            'is_sneaking' => $entry['is_sneaking'] ?? null,
            'is_in_combat' => $entry['is_in_combat'] ?? null,
            'is_attacking' => $entry['is_attacking'] ?? null,
            'movement_speed' => $entry['movement_speed'] ?? null,
            'attack_target' => $entry['attack_target'] ?? '',
            'is_carrying' => $entry['is_carrying'] ?? null,
            'carrying_target_name' => $entry['carrying_target_name'] ?? '',
            'is_being_carried' => $entry['is_being_carried'] ?? null,
            'carried_by_name' => $entry['carried_by_name'] ?? '',
            'metadata' => [],
            'extended_data' => [
                'environment' => (isset($entry['environment']) && is_array($entry['environment']))
                    ? $entry['environment']
                    : [],
            ],
        ], max(0, $gamets));

        if (!$storedNearbySnapshot) {
            $nearOccupation = stobeBuildFactionOccupationText($nearFaction);
            storeNpcProfile($nearName, [
                'race' => $nearRace,
                'faction' => $nearFaction,
                'gender' => $nearGender,
                'appearance' => $appearance,
                'equipment' => $nearEquipment,
                'occupation' => $nearOccupation,
                'personality' => 'Observed nearby in active gameplay context.',
                'backstory' => 'Imported from nearby world snapshot.',
                'tags' => '',
                'metadata' => [],
                'extended_data' => [
                    'environment' => (isset($entry['environment']) && is_array($entry['environment']))
                        ? $entry['environment']
                        : [],
                ],
            ]);
        }
        ensureOriginalName($nearName, $nearName);
        $nearbyObserved++;
    }
}

$squadNames = $snapshot['squad'] ?? [];
if (is_array($squadNames) && count($squadNames) > 0) {
    ensureNpcProfilesFromParticipants($squadNames);
}

$summary = $name . '|source=' . $source;
$town = trim(strval($snapshot['town'] ?? ''));
if ($town !== '') {
    $summary .= '|town=' . $town;
}
$faction = trim(strval($snapshot['faction'] ?? ''));
if ($faction !== '') {
    $summary .= '|faction=' . $faction;
}
$race = trim(strval($snapshot['race'] ?? ''));
if ($race !== '') {
    $summary .= '|race=' . $race;
}

if (!$isInventoryLiveSync) {
    storeEvent('npc_snapshot', time(), max(0, $gamets), $summary);
}

echo json_encode([
    "status" => "ok",
    "name" => $name,
    "source" => $source,
    "nearby_observed" => $nearbyObserved
]);

