<?php
require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("faction_relations");

/**
 * StobeServer - Faction relation ingest endpoint.
 *
 * Receives relation snapshot/delta payloads from Stobe.dll and persists:
 * - latest directed relation state per faction pair
 * - append-only history for changed/deleted rows
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    stobeLogWarn('faction_relations rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$payload = $input;
if (isset($input['faction_relations']) && is_array($input['faction_relations'])) {
    $payload = $input['faction_relations'];
}

if (!is_array($payload)) {
    stobeLogWarn('faction_relations rejected: malformed faction_relations envelope');
    http_response_code(400);
    echo json_encode(["error" => "Malformed faction_relations payload"]);
    exit;
}

$gamets = intval($payload['game_ts'] ?? ($input['game_ts'] ?? 0));
if ($gamets < 0) {
    $gamets = 0;
}
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($gamets, 'faction_relations');
}

$result = storeFactionRelationEntries($payload);
if (!is_array($result)) {
    stobeLogError('faction_relations failed to persist', [
        'gamets' => $gamets,
        'keys' => array_keys($payload),
    ]);
    http_response_code(500);
    echo json_encode(["error" => "Failed to store faction relations"]);
    exit;
}

stobeLogInfo('faction_relations stored', [
    'gamets' => $gamets,
    'changed' => intval($result['changed'] ?? 0),
    'unchanged' => intval($result['unchanged'] ?? 0),
    'removed' => intval($result['removed'] ?? 0),
    'scanned_pairs' => intval($payload['scanned_pairs'] ?? 0),
    'truncated' => boolval($payload['truncated'] ?? false),
]);

echo json_encode([
    "status" => "ok",
    "gamets" => $gamets,
    "changed" => intval($result['changed'] ?? 0),
    "unchanged" => intval($result['unchanged'] ?? 0),
    "removed" => intval($result['removed'] ?? 0),
]);
