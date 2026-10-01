<?php

/**
 * Persistent STOBE training-data capture.
 *
 * These records are intentionally stored outside StobeServer/log so normal log
 * rotation and game/server restarts do not remove them.
 */
function stobeTrainingArchiveRoot(): string
{
    $configured = trim(strval(getenv('STOBE_TRAINING_ARCHIVE_DIR') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/\\');
    }

    return '/mnt/c/KenshiModding/training-data/live';
}

function stobeTrainingCapture(string $eventType, array $payload): bool
{
    $eventType = trim($eventType);
    if ($eventType === '') {
        return false;
    }

    $root = stobeTrainingArchiveRoot();
    $day = date('Y-m-d');
    $directory = $root . DIRECTORY_SEPARATOR . $day;
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $record = [
        'capture_version' => 1,
        'captured_at' => date(DATE_ATOM),
        'unix_ms' => intval(round(microtime(true) * 1000)),
        'event_type' => $eventType,
        'request_id' => strval($payload['request_id'] ?? ($GLOBALS['__stobe_request_id'] ?? '')),
        'payload' => $payload,
    ];

    $json = json_encode(
        $record,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    if (!is_string($json) || $json === '') {
        return false;
    }

    $path = $directory . DIRECTORY_SEPARATOR . 'events.jsonl';
    return @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

function stobeShadowQueueRoot(): string
{
    return '/mnt/c/KenshiModding/training-data/shadow-queue';
}

function stobeShadowEnabled(): bool
{
    return is_file('/mnt/c/KenshiModding/training-data/shadow-enabled.flag');
}

function stobeQueueShadowLlm(array $payload): bool
{
    if (!stobeShadowEnabled()) {
        return false;
    }

    $root = stobeShadowQueueRoot();
    $pending = $root . DIRECTORY_SEPARATOR . 'pending';
    if (!is_dir($pending) && !@mkdir($pending, 0775, true) && !is_dir($pending)) {
        return false;
    }

    $requestId = preg_replace('/[^A-Za-z0-9_.-]+/', '_', strval($payload['request_id'] ?? ''));
    if ($requestId === '') {
        $requestId = 'request';
    }
    $record = [
        'queue_version' => 1,
        'queued_at' => date(DATE_ATOM),
        'queued_unix_ms' => intval(round(microtime(true) * 1000)),
        'request_id' => $requestId,
        'payload' => $payload,
    ];

    $json = json_encode(
        $record,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    if (!is_string($json) || $json === '') {
        return false;
    }

    $suffix = sprintf('%06d', random_int(0, 999999));
    $base = $requestId . '-' . intval(round(microtime(true) * 1000)) . '-' . $suffix . '.json';
    $tmp = $pending . DIRECTORY_SEPARATOR . '.' . $base . '.tmp';
    $dest = $pending . DIRECTORY_SEPARATOR . $base;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $dest);
}
