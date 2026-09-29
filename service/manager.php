<?php

/**
 * Stobe background manager tick.
 *
 * Runs periodic non-interactive cycles that should not block
 * foreground request latency.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');
ptr_runtime_ready();

/**
 * Fetch latest known in-game timestamp from eventlog.
 */
function stobeBackgroundLatestGamets(): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return 0;
    }

    try {
        $row = $db->fetchOne("SELECT COALESCE(MAX(gamets), 0) AS max_gamets FROM eventlog");
        return intval($row['max_gamets'] ?? 0);
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Background manager gamets lookup failed');
        return 0;
    }
}

/**
 * Refresh the manager heartbeat between potentially slow maintenance tasks.
 */
function stobeBackgroundRecordTick(int $gamets): void
{
    setConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_TS', strval(time()), true);
    setConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_GAMETS', strval(max(0, $gamets)), true);
}

$tickEventType = 'chat';
$tickTimestamp = time();
$tickGamets = stobeBackgroundLatestGamets();
$tickPayload = '[background_processor_tick]';

stobeBackgroundRecordTick($tickGamets);

if (function_exists('stobePlayer2HealthTick')) {
    stobePlayer2HealthTick($tickTimestamp);
}
stobeBackgroundRecordTick($tickGamets);

// Retention is independent of game activity and remains opt-in.
require_once $enginePath . 'lib/playthrough_retention.php';
$retentionConn = ptp_connect();
if ($retentionConn) {
    try { ptr_tick($retentionConn); }
    catch (Throwable $e) { stobeLogWarn('Playthrough Save cleanup skipped: ' . $e->getMessage()); }
    finally { pg_close($retentionConn); }
}

require_once $enginePath . 'lib/dynamic_profile_scheduler.php';
dps_run();

if (!stobeInteractionAllowed()) exit(0);

if ($tickGamets <= 0) {
    stobeLogDebug('Background manager skipped: no gamets yet');
    exit(0);
}

// Keep service health alive, but leave scheduled AI work pending until Kenshi returns.
if (!stobeHasRecentGameActivity()) {
    exit(0);
}

try {
    if (function_exists('stobeRunMiddleTermDaemonEntrypoint')) {
        stobeRunMiddleTermDaemonEntrypoint($tickTimestamp, $tickGamets, $tickPayload);
    } elseif (function_exists('stobeMaybeRunMiddleTermCycle')) {
        stobeMaybeRunMiddleTermCycle($tickEventType, $tickTimestamp, $tickGamets, $tickPayload);
    }
    stobeBackgroundRecordTick($tickGamets);
    if (function_exists('stobeMaybeRunRegularMemoryCycle')) {
        stobeMaybeRunRegularMemoryCycle($tickEventType, $tickTimestamp, $tickGamets, $tickPayload);
    }
    stobeBackgroundRecordTick($tickGamets);
    stobeBackgroundRecordTick($tickGamets);
    if (function_exists('stobeMaybeRunAutoDiaryCycle')) {
        stobeMaybeRunAutoDiaryCycle($tickTimestamp, $tickGamets);
    }
    stobeBackgroundRecordTick($tickGamets);
    setConfOpt('BACKGROUND_PROCESSOR_LAST_SUCCESS_TS', strval(time()), true);
} catch (Throwable $exception) {
    stobeBackgroundRecordTick($tickGamets);
    setConfOpt('BACKGROUND_PROCESSOR_LAST_ERROR_TS', strval(time()), true);
    stobeLogException($exception, 'Background manager tick failed', [
        'gamets' => $tickGamets,
        'event_type' => $tickEventType,
    ]);
    exit(1);
}

exit(0);
