<?php
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard();
require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("item_image_upload");

/**
 * StobeServer - Item image upload endpoint.
 *
 * Receives item icon payloads (base64) from Stobe.dll, stores files under
 * data/item_images, and upserts mapping rows in description_images.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    stobeLogWarn('item_image_upload rejected: invalid JSON payload');
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
    stobeLogWarn('item_image_upload rejected: empty entries');
    http_response_code(400);
    echo json_encode(["error" => "No item image entries"]);
    exit;
}

$db = $GLOBALS["db"];

function stobeItemImageEnsureTable(sql $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS description_images (
            stringid VARCHAR(128) PRIMARY KEY,
            image_path TEXT NOT NULL DEFAULT '',
            image_hash VARCHAR(64) DEFAULT '',
            format VARCHAR(16) DEFAULT '',
            width INT DEFAULT 0,
            height INT DEFAULT 0,
            updated_at TIMESTAMP DEFAULT NOW()
        )"
    );
    $db->exec(
        "CREATE INDEX IF NOT EXISTS idx_description_images_stringid_lower
         ON description_images (LOWER(stringid))"
    );
}

function stobeItemImageNormalizeExtension(string $format): string
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

function stobeItemImageResolveStringId(array $entry): string
{
    $itemId = trim(strval(
        $entry['stringid']
            ?? ($entry['string_id']
            ?? ($entry['item_id']
            ?? ($entry['itemId']
            ?? ($entry['sid']
            ?? ($entry['baseid']
            ?? ($entry['id'] ?? ''))))))
    ));
    if ($itemId !== '') {
        if (strlen($itemId) > 128) {
            $itemId = substr($itemId, 0, 128);
        }
        return $itemId;
    }

    $name = trim(strval($entry['name'] ?? ''));
    if ($name === '') {
        return '';
    }
    if (function_exists('stobeBuildSyntheticItemStringId')) {
        $itemId = strval(stobeBuildSyntheticItemStringId($name));
    } else {
        $fallback = preg_replace('/[^a-z0-9]+/i', '_', strtolower($name));
        $fallback = trim(strval($fallback), '_');
        $itemId = $fallback !== '' ? ('item_' . $fallback) : '';
    }
    if (strlen($itemId) > 128) {
        $itemId = substr($itemId, 0, 128);
    }
    return trim($itemId);
}

stobeItemImageEnsureTable($db);

$globalGamets = intval($input['game_ts'] ?? 0);
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($globalGamets, 'item_image_upload');
}

$saved = 0;
$skipped = 0;
$errors = [];

foreach ($entries as $entry) {
    $stringId = stobeItemImageResolveStringId($entry);
    if ($stringId === '') {
        $errors[] = 'missing_stringid';
        $skipped++;
        continue;
    }

    $format = stobeItemImageNormalizeExtension(strval($entry['format'] ?? 'bmp'));
    $rawBase64 = trim(strval($entry['image_base64'] ?? ($entry['item_image_base64'] ?? '')));
    if ($rawBase64 === '') {
        $errors[] = 'missing_image_base64';
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

    $imageRoot = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'item_images';
    $imageDir = $imageRoot . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($imageDir)) {
        @mkdir($imageDir, 0775, true);
    }
    if (!is_dir($imageDir)) {
        $errors[] = 'mkdir_failed';
        $skipped++;
        continue;
    }

    $fileName = $hash . '.' . $format;
    $filePath = $imageDir . DIRECTORY_SEPARATOR . $fileName;
    if (!file_exists($filePath)) {
        $bytesWritten = @file_put_contents($filePath, $binary);
        if (!is_int($bytesWritten) || $bytesWritten <= 0) {
            $errors[] = 'write_failed';
            $skipped++;
            continue;
        }
    }

    $webPath = '/StobeServer/data/item_images/' . $subdir . '/' . $fileName;
    $width = max(0, intval($entry['width'] ?? 0));
    $height = max(0, intval($entry['height'] ?? 0));
    $gamets = intval($entry['game_ts'] ?? $globalGamets);
    if ($gamets < 0) {
        $gamets = 0;
    }

    $result = $db->exec(
        "INSERT INTO description_images (stringid, image_path, image_hash, format, width, height, updated_at)
         VALUES ($1, $2, $3, $4, $5, $6, NOW())
         ON CONFLICT (stringid) DO UPDATE
         SET image_path = EXCLUDED.image_path,
             image_hash = EXCLUDED.image_hash,
             format = EXCLUDED.format,
             width = EXCLUDED.width,
             height = EXCLUDED.height,
             updated_at = NOW()",
        [
            $stringId,
            $webPath,
            $hash,
            $format,
            $width,
            $height,
        ]
    );
    if ($result === false) {
        $errors[] = 'db_upsert_failed';
        $skipped++;
        continue;
    }

    $saved++;
    stobeLogImport('item_image_upload stored', [
        'stringid' => $stringId,
        'hash' => $hash,
        'format' => $format,
        'width' => $width,
        'height' => $height,
        'gamets' => $gamets,
    ], 'DEBUG');
}

$errors = array_values(array_unique($errors));
if ($saved <= 0) {
    stobeLogWarn('item_image_upload processed with no saves', [
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

