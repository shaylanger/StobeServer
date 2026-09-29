<?php
require_once __DIR__ . '/lib/stobe_interaction.php';
stobeInteractionRequire();


require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/debug/db_updates.php';
require_once __DIR__ . '/lib/autonomy_release_gate.php';

stobeAutonomyRejectForRelease();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    stobeAutonomySendJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
    $payload = stobeAutonomyReadRequestPayload();
    $result = stobeAutonomyApplyPilotControl(strval($payload['action'] ?? ''), $payload);
    $status = intval($result['status'] ?? 500);
    unset($result['status']);
    stobeAutonomySendJson($result, $status);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Autonomy pilot endpoint failed');
    stobeAutonomySendJson(['ok' => false, 'error' => 'pilot_failed'], 500);
}
