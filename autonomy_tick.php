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
    $result = stobeAutonomyApplyTick(stobeAutonomyReadRequestPayload());
    $status = intval($result['status'] ?? 500);
    unset($result['status']);
    stobeAutonomySendJson($result, $status);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Autonomy tick endpoint failed');
    stobeAutonomySendJson(['ok' => false, 'error' => 'tick_failed'], 500);
}
