<?php
require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("gamedata");

/**
 * StobeServer - Game Data Endpoint
 * 
 * Receives JSON POST with NPC stats, equipment, inventory, skills
 * from the Stobe DLL. No LLM calls - pure data storage.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    stobeLogWarn('gamedata rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

// Store NPC/player game data
$type = $input['type'] ?? '';
$name = trim(strval($input['name'] ?? ''));
$data = $input['data'] ?? [];
$incomingGamets = intval($input['game_ts'] ?? ($input['data']['game_ts'] ?? 0));
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($incomingGamets, 'gamedata');
}

if ($type && ($name !== '' || is_array($data))) {
    $snapshotTypes = ['player', 'npc', 'snapshot'];
    if (in_array($type, $snapshotTypes, true) && is_array($data)) {
        $snapshotName = trim(strval($data['name'] ?? $name));
        if ($snapshotName === '') {
            stobeLogWarn('gamedata rejected: snapshot missing name', ['type' => $type]);
            http_response_code(400);
            echo json_encode(["error" => "Snapshot missing name"]);
            exit;
        }

        $snapshot = $data;
        $snapshot['name'] = $snapshotName;
        if (!array_key_exists('is_player_character', $snapshot)) {
            $snapshot['is_player_character'] = ($type === 'player');
        }
        stobeLogImport('gamedata snapshot ingress', [
            'name' => $snapshotName,
            'type' => $type,
            'keys' => array_keys($snapshot),
            'has_medical' => is_array($snapshot['medical'] ?? null),
            'has_stats' => is_array($snapshot['stats'] ?? null),
        ]);

        $gamets = intval($input['game_ts'] ?? ($snapshot['game_ts'] ?? 0));
        $stored = storeNpcSnapshot($snapshot, $gamets);
        if ($stored) {
            storeEvent('playerinfo', time(), max(0, $gamets), $snapshotName . '|snapshot|' . $type);
            stobeLogInfo('gamedata snapshot stored', [
                'name' => $snapshotName,
                'type' => $type,
                'gamets' => max(0, $gamets),
            ]);
            stobeLogImport('gamedata snapshot persisted', [
                'name' => $snapshotName,
                'type' => $type,
                'gamets' => max(0, $gamets),
            ]);
            echo json_encode(["status" => "ok", "mode" => "snapshot"]);
        } else {
            stobeLogWarn('gamedata rejected: snapshot store failed', [
                'name' => $snapshotName,
                'type' => $type
            ]);
            http_response_code(500);
            echo json_encode(["error" => "Snapshot store failed"]);
        }
        exit;
    }

    $stored = storeGameData($name, $type, is_array($data) ? $data : []);
    if ($stored) {
        storeEvent('playerinfo', time(), 0, $name . '|' . $type . '|' . json_encode($data));
        stobeLogInfo('gamedata stored', [
            'name' => $name,
            'type' => $type,
            'data_keys' => is_array($data) ? array_keys($data) : [],
        ]);
        echo json_encode(["status" => "ok"]);
    } else {
        stobeLogWarn('gamedata rejected: unsupported type', ['name' => $name, 'type' => $type]);
        http_response_code(400);
        echo json_encode(["error" => "Unsupported type"]);
    }
} else {
    stobeLogWarn('gamedata rejected: missing type or name');
    http_response_code(400);
    echo json_encode(["error" => "Missing type or name"]);
}

