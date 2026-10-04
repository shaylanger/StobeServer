<?php

/**
 * Background processor helpers for StobeServer.
 *
 * Mirrors the Herika helper-daemon pattern:
 * - Lightweight TCP heartbeat listener on a dedicated port.
 * - `service/start.sh` bootstraps a loop that runs `service/manager.php`.
 */

function stobeBackgroundProcessorPort(): int
{
    return 12346;
}

function stobeBackgroundProcessorStaleThresholdSeconds(): int
{
    $seconds = parseIntLike(getSetting('BACKGROUND_PROCESSOR_STALE_SECONDS', '90'), 90);
    if ($seconds < 10) {
        $seconds = 10;
    } elseif ($seconds > 300) {
        $seconds = 300;
    }
    return $seconds;
}

function stobeBackgroundProcessorLastTickTs(): int
{
    return intval(getConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_TS', '0'));
}

function stobeBackgroundProcessorNeedsInlineFallback(): bool
{
    return !stobeBackgroundProcessorIsRunning(0.1);
}

function stobeBackgroundProcessorStartScriptPath(): string
{
    $enginePath = $GLOBALS['ENGINE_PATH'] ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR);
    $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return $enginePath . 'service' . DIRECTORY_SEPARATOR . 'start.sh';
}

function stobeBackgroundProcessorStateDirectory(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
}

function stobeBackgroundProcessorStartCooldownSeconds(): int
{
    $seconds = parseIntLike(getSetting('BACKGROUND_PROCESSOR_START_COOLDOWN_SECONDS', '30'), 30);
    if ($seconds < 5) {
        $seconds = 5;
    } elseif ($seconds > 300) {
        $seconds = 300;
    }
    return $seconds;
}

function stobeBackgroundProcessorSocketIsListening(float $timeoutSeconds = 0.15): bool
{
    $port = stobeBackgroundProcessorPort();
    if ($port < 1 || $port > 65535) {
        return false;
    }

    if ($timeoutSeconds < 0.1) {
        $timeoutSeconds = 0.1;
    } elseif ($timeoutSeconds > 2.0) {
        $timeoutSeconds = 2.0;
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeoutSeconds);
        if (is_resource($socket)) {
            @fclose($socket);
            return true;
        }
        if ($attempt < 2) {
            usleep(40000);
        }
    }

    return false;
}

/**
 * Report worker health from both the listener socket and manager heartbeat.
 *
 * Optional overrides provide a focused test seam without opening real sockets.
 */
function stobeBackgroundProcessorStatus(
    float $timeoutSeconds = 0.15,
    ?bool $socketListening = null,
    ?int $nowTs = null
): array {
    $nowTs = $nowTs ?? time();
    $socketListening = $socketListening ?? stobeBackgroundProcessorSocketIsListening($timeoutSeconds);
    $lastTickTs = stobeBackgroundProcessorLastTickTs();
    $lastTickAge = $lastTickTs > 0 ? max(0, $nowTs - $lastTickTs) : null;
    $staleAfter = stobeBackgroundProcessorStaleThresholdSeconds();

    if (!$socketListening) {
        $state = 'offline';
        $message = 'Heartbeat socket is unavailable.';
    } elseif ($lastTickTs <= 0) {
        $state = 'starting';
        $message = 'Listener is running, but the manager has not recorded a tick yet.';
    } elseif ($lastTickAge > $staleAfter) {
        $state = 'stalled';
        $message = 'Manager heartbeat is stale.';
    } else {
        $state = 'running';
        $message = 'Listener and manager heartbeat are healthy.';
    }

    return [
        'healthy' => $state === 'running',
        'state' => $state,
        'message' => $message,
        'socket_listening' => $socketListening,
        'last_tick_ts' => $lastTickTs,
        'last_tick_age_seconds' => $lastTickAge,
        'stale_after_seconds' => $staleAfter,
    ];
}

function stobeBackgroundProcessorIsRunning(float $timeoutSeconds = 0.15): bool
{
    $status = stobeBackgroundProcessorStatus($timeoutSeconds);
    return boolval($status['healthy'] ?? false);
}

function stobeBackgroundProcessorLiveDbName(): string
{
    $name = trim(strval(getenv('STOBE_LIVE_DB_NAME') ?: ''));
    return $name !== '' ? $name : 'stobe';
}

function stobeBackgroundProcessorDbName(): string
{
    $name = trim(strval(getenv('STOBE_DB_NAME') ?: ''));
    return $name !== '' ? $name : 'stobe';
}

/**
 * Only the live database may own the single background processor slot (lock + port 12346).
 * Test suites (STOBE_DB_NAME=stobe_test, php -S ingress tests) and STOBE_NO_BACKGROUND_PROCESSOR=1
 * must never start it: a test-DB loop holds the slot and the live processor stalls forever (STOBE 124).
 */
function stobeBackgroundProcessorStartAllowed(): bool
{
    $off = strtolower(trim(strval(getenv('STOBE_NO_BACKGROUND_PROCESSOR') ?: '')));
    if ($off !== '' && $off !== '0' && $off !== 'false' && $off !== 'no') {
        return false;
    }
    return stobeBackgroundProcessorDbName() === stobeBackgroundProcessorLiveDbName();
}

function stobeBackgroundProcessorOwnerPath(): string
{
    return '/tmp/stobe_background_processor.owner';
}

function stobeBackgroundProcessorOwnerInfo(): string
{
    $path = stobeBackgroundProcessorOwnerPath();
    if (!is_file($path)) {
        return '';
    }
    return trim(strval(@file_get_contents($path, false, null, 0, 200)));
}

function stobeEnsureBackgroundProcessorRunning(bool $logFailures = true): bool
{
    $status = stobeBackgroundProcessorStatus();
    if (boolval($status['healthy'] ?? false)) {
        return true;
    }

    if (!stobeBackgroundProcessorStartAllowed()) {
        if ($logFailures) {
            stobeLogDebug('Background processor start skipped for non-live database', [
                'db' => stobeBackgroundProcessorDbName(),
                'live_db' => stobeBackgroundProcessorLiveDbName(),
            ]);
        }
        return false;
    }

    $startScript = stobeBackgroundProcessorStartScriptPath();
    if (!is_file($startScript)) {
        if ($logFailures) {
            stobeLogWarn('Background processor start script missing', [
                'path' => $startScript,
            ]);
        }
        return false;
    }

    if (!function_exists('shell_exec')) {
        if ($logFailures) {
            stobeLogWarn('shell_exec unavailable; cannot auto-start background processor');
        }
        return false;
    }

    $stateDirectory = stobeBackgroundProcessorStateDirectory();
    if ($stateDirectory === ''
        || (!is_dir($stateDirectory) && !@mkdir($stateDirectory, 0775, true) && !is_dir($stateDirectory))
    ) {
        if ($logFailures) {
            stobeLogWarn('Background processor state directory is unavailable', [
                'path' => $stateDirectory,
            ]);
        }
        return false;
    }

    $lockPath = $stateDirectory . DIRECTORY_SEPARATOR . 'stobe_background_processor_start.lock';
    $attemptPath = $stateDirectory . DIRECTORY_SEPARATOR . 'stobe_background_processor_start.attempt';
    $lockHandle = @fopen($lockPath, 'c');
    if (is_resource($lockHandle)) {
        @chmod($lockPath, 0666);
    }
    if (!is_resource($lockHandle) || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            @fclose($lockHandle);
        }
        return false;
    }

    try {
        $status = stobeBackgroundProcessorStatus(0.1);
        if (boolval($status['healthy'] ?? false)) {
            return true;
        }

        $cooldownSeconds = stobeBackgroundProcessorStartCooldownSeconds();
        $lastAttempt = is_file($attemptPath) ? intval(@filemtime($attemptPath)) : 0;
        if ($lastAttempt > 0 && (time() - $lastAttempt) < $cooldownSeconds) {
            return false;
        }

        @touch($attemptPath);
        @chmod($attemptPath, 0666);
        if ($logFailures) {
            $message = ($status['state'] ?? '') === 'stalled'
                ? 'Background processor is stalled; requesting recovery'
                : 'Background processor is unavailable; requesting start';
            stobeLogWarn($message, [
                'state' => strval($status['state'] ?? 'unknown'),
                'socket_listening' => boolval($status['socket_listening'] ?? false),
                'last_tick_ts' => intval($status['last_tick_ts'] ?? 0),
                'last_tick_age_seconds' => $status['last_tick_age_seconds'] ?? null,
                'stale_after_seconds' => intval($status['stale_after_seconds'] ?? 0),
                'port' => stobeBackgroundProcessorPort(),
            ]);
        }

        $command = 'nohup bash ' . escapeshellarg($startScript) . ' > /dev/null 2>&1 < /dev/null &';
        @shell_exec($command);

        $running = false;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            usleep(100000);
            $status = stobeBackgroundProcessorStatus(0.1);
            if (boolval($status['healthy'] ?? false)) {
                $running = true;
                break;
            }
        }

        if ($running) {
            stobeLogInfo('Background processor started', [
                'port' => stobeBackgroundProcessorPort(),
                'script' => $startScript,
                'cooldown_seconds' => $cooldownSeconds,
            ]);
            return true;
        }

        if ($logFailures) {
            stobeLogWarn('Background processor remains unhealthy after start request', [
                'state' => strval($status['state'] ?? 'unknown'),
                'socket_listening' => boolval($status['socket_listening'] ?? false),
                'last_tick_ts' => intval($status['last_tick_ts'] ?? 0),
                'last_tick_age_seconds' => $status['last_tick_age_seconds'] ?? null,
                'stale_after_seconds' => intval($status['stale_after_seconds'] ?? 0),
                'port' => stobeBackgroundProcessorPort(),
                'script' => $startScript,
                'cooldown_seconds' => $cooldownSeconds,
                'slot_owner' => stobeBackgroundProcessorOwnerInfo(),
            ]);
        }
        return false;
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
