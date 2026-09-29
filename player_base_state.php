<?php
require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("player_base_state");

/**
 * Receives selected player-character base presence from Stobe.dll.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . 'lib/bootstrap.php');

header('Content-Type: application/json');

if (strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'POST')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !is_array($input['player_base'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid player base payload']);
    exit;
}

$gamets = max(0, intval($input['game_ts'] ?? 0));
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($gamets, 'player_base_state');
}

try {
    $result = stobeStorePlayerBaseState($input);
    echo json_encode(['status' => 'ok'] + $result);
} catch (InvalidArgumentException $exception) {
    stobeLogWarn('player_base_state rejected', ['error' => $exception->getMessage()]);
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    stobeLogException($exception, 'player_base_state failed');
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store player base state']);
}
