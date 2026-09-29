<?php
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard();
require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("portrait_upload");

/**
 * StobeServer - Portrait upload endpoint.
 *
 * Receives portrait image payloads (base64) from Stobe.dll, stores files under
 * data/portraits, and updates core_npc metadata.portrait for card rendering.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    stobeLogWarn('portrait_upload rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$entries = [];
if (isset($input['entries']) && is_array($input['entries'])) {
    foreach ($input['entries'] as $entry) {
        if (is_array($entry)) {
            $entries[] = $entry;
        }
    }
} else {
    $entries[] = $input;
}

if (count($entries) === 0) {
    stobeLogWarn('portrait_upload rejected: empty entries');
    http_response_code(400);
    echo json_encode(["error" => "No portrait entries"]);
    exit;
}

$db = $GLOBALS["db"];

/**
 * Normalize extension token.
 */
function stobePortraitNormalizeExtension(string $format): string
{
    $normalized = strtolower(trim($format));
    if ($normalized === 'jpeg') {
        $normalized = 'jpg';
    }
    $allowed = ['bmp', 'png', 'jpg', 'webp'];
    if (!in_array($normalized, $allowed, true)) {
        return 'bmp';
    }
    return $normalized;
}

/**
 * Resolve target NPC row by storage_id first, then by name.
 */
function stobePortraitResolveTargetRow(sql $db, string $name, string $storageId): array|false
{
    if ($storageId !== '') {
        $row = $db->fetchOne(
            "SELECT id, name
             FROM core_npc
             WHERE COALESCE(metadata->>'storage_id', '') = $1
             ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
             LIMIT 1",
            [$storageId]
        );
        if ($row) {
            return $row;
        }
    }

    if ($name !== '') {
        return $db->fetchOne(
            "SELECT id, name
             FROM core_npc
             WHERE LOWER(name) = LOWER($1)
             ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
             LIMIT 1",
            [$name]
        );
    }

    return false;
}

/**
 * Upsert metadata.portrait in core_npc (+ best-effort mirror into core_npc_master).
 */
function stobePortraitPersistMetadata(sql $db, int $npcId, array $portraitMeta): bool
{
    if ($npcId <= 0) {
        return false;
    }

    $portraitJson = normalizeJsonString($portraitMeta);
    $query = "UPDATE core_npc
              SET metadata = CASE
                    WHEN metadata IS NULL OR metadata = '[]'::jsonb OR jsonb_typeof(metadata) <> 'object'
                        THEN jsonb_build_object('portrait', $1::jsonb)
                    ELSE metadata || jsonb_build_object('portrait', $1::jsonb)
                  END,
                  updated_at = NOW()
              WHERE id = $2";
    $result = $db->exec($query, [$portraitJson, $npcId]);
    if ($result === false) {
        return false;
    }

    // Keep master table aligned where available.
    try {
        $db->exec(
            "UPDATE core_npc_master
             SET metadata = CASE
                    WHEN metadata IS NULL OR metadata = '[]'::jsonb OR jsonb_typeof(metadata) <> 'object'
                        THEN jsonb_build_object('portrait', $1::jsonb)
                    ELSE metadata || jsonb_build_object('portrait', $1::jsonb)
                  END,
                  updated_at = NOW()
             WHERE id = $2",
            [$portraitJson, $npcId]
        );
    } catch (Throwable $exception) {
        // Non-fatal: core_npc is the source used by UI cards.
    }

    return true;
}

$globalGamets = intval($input['game_ts'] ?? 0);
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($globalGamets, 'portrait_upload');
}

$saved = 0;
$skipped = 0;
$errors = [];

foreach ($entries as $entry) {
    $name = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
    $storageId = normalizeStorageIdToken(strval($entry['storage_id'] ?? ''));
    $format = stobePortraitNormalizeExtension(strval($entry['format'] ?? 'bmp'));
    $rawBase64 = trim(strval($entry['image_base64'] ?? ($entry['portrait_base64'] ?? '')));
    $width = intval($entry['width'] ?? 0);
    $height = intval($entry['height'] ?? 0);
    $gamets = intval($entry['game_ts'] ?? $globalGamets);
    if ($gamets < 0) {
        $gamets = 0;
    }

    if ($rawBase64 === '') {
        $skipped++;
        continue;
    }

    $binary = base64_decode($rawBase64, true);
    if (!is_string($binary) || $binary === '') {
        $errors[] = 'invalid_base64';
        $skipped++;
        continue;
    }
    if (strlen($binary) > 2 * 1024 * 1024) {
        $errors[] = 'payload_too_large';
        $skipped++;
        continue;
    }

    $rawHash = strtolower(trim(strval($entry['image_hash'] ?? '')));
    $rawHash = preg_replace('/[^a-f0-9]/', '', $rawHash ?? '');
    if (!is_string($rawHash) || strlen($rawHash) < 12) {
        $rawHash = hash('sha256', $binary);
    }
    $hash = substr($rawHash, 0, 64);
    if ($hash === '') {
        $hash = hash('sha256', $binary);
    }

    $subdir = substr($hash, 0, 2);
    if ($subdir === '' || preg_match('/^[a-f0-9]{2}$/', $subdir) !== 1) {
        $subdir = '00';
    }

    $portraitRoot = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'portraits';
    $portraitDir = $portraitRoot . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($portraitDir)) {
        @mkdir($portraitDir, 0775, true);
    }
    if (!is_dir($portraitDir)) {
        $errors[] = 'mkdir_failed';
        $skipped++;
        continue;
    }

    $fileName = $hash . '.' . $format;
    $filePath = $portraitDir . DIRECTORY_SEPARATOR . $fileName;
    if (!file_exists($filePath)) {
        $bytesWritten = @file_put_contents($filePath, $binary);
        if (!is_int($bytesWritten) || $bytesWritten <= 0) {
            $errors[] = 'write_failed';
            $skipped++;
            continue;
        }
    }

    $targetRow = stobePortraitResolveTargetRow($db, $name, $storageId);
    if (!$targetRow) {
        $errors[] = 'npc_not_found';
        $skipped++;
        continue;
    }
    $npcId = intval($targetRow['id'] ?? 0);
    if ($npcId <= 0) {
        $errors[] = 'invalid_npc_id';
        $skipped++;
        continue;
    }

    $webPath = '/StobeServer/data/portraits/' . $subdir . '/' . $fileName;
    $portraitMeta = [
        'url' => $webPath,
        'web_path' => $webPath,
        'hash' => $hash,
        'format' => $format,
        'width' => max(0, $width),
        'height' => max(0, $height),
        'updated_at' => gmdate('c'),
        'source' => trim(strval($entry['source'] ?? 'portrait_upload')),
    ];
    if ($storageId !== '') {
        $portraitMeta['storage_id'] = $storageId;
    }
    if ($gamets > 0) {
        $portraitMeta['game_ts'] = $gamets;
    }

    if (!stobePortraitPersistMetadata($db, $npcId, $portraitMeta)) {
        $errors[] = 'metadata_update_failed';
        $skipped++;
        continue;
    }

    $saved++;
    stobeLogImport('portrait_upload stored', [
        'npc_id' => $npcId,
        'name' => $targetRow['name'] ?? $name,
        'storage_id' => $storageId,
        'hash' => $hash,
        'format' => $format,
        'width' => max(0, $width),
        'height' => max(0, $height),
        'gamets' => $gamets,
    ], 'DEBUG');
}

$errors = array_values(array_unique($errors));
if ($saved <= 0) {
    stobeLogWarn('portrait_upload processed with no saves', [
        'entries' => count($entries),
        'skipped' => $skipped,
        'errors' => $errors,
    ]);
}

echo json_encode([
    'status' => 'ok',
    'saved' => $saved,
    'skipped' => $skipped,
    'entries' => count($entries),
    'errors' => $errors,
]);

