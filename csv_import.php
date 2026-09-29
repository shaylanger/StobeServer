<?php
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard();

/**
 * CSV Import endpoint for Stobe plugin startup imports.
 * Accepts multipart/form-data "file" with ?type=<import_type>.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json; charset=utf-8');

function stobeCsvExitJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function stobeCsvTrim($value): string
{
    return trim(strval($value));
}

function stobeCsvHeaderMap(array $header): array
{
    $map = [];
    foreach ($header as $idx => $name) {
        $key = stobeCsvNormalizeHeader($name);
        if ($key === '') {
            continue;
        }
        $map[$key] = intval($idx);
    }
    return $map;
}

function stobeCsvNormalizeHeader($value): string
{
    $key = preg_replace('/^\xEF\xBB\xBF/', '', strval($value));
    $key = strtolower(trim(strval($key)));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    return trim(strval($key), '_');
}

function stobeCsvPick(array $row, array $map, array $aliases, int $fallback = -1): string
{
    foreach ($aliases as $alias) {
        $k = stobeCsvNormalizeHeader($alias);
        if ($k !== '' && array_key_exists($k, $map)) {
            return trim(strval($row[intval($map[$k])] ?? ''));
        }
    }
    if ($fallback >= 0) {
        return trim(strval($row[$fallback] ?? ''));
    }
    return '';
}

function stobeCsvContains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return false;
    }
    return strpos($haystack, $needle) !== false;
}

function stobeCsvEndsWith(string $value, string $suffix): bool
{
    if ($suffix === '') {
        return true;
    }
    if (strlen($suffix) > strlen($value)) {
        return false;
    }
    return substr($value, -strlen($suffix)) === $suffix;
}

function stobeCsvWorldUpdateNativeVector(sql $db, int $id): void
{
    if ($id <= 0) {
        return;
    }
    $db->exec(
        "UPDATE world_knowledge
         SET native_vector =
            setweight(to_tsvector('simple', COALESCE(topic, '')), 'A')
            || setweight(to_tsvector('simple', COALESCE(topic_desc, '')), 'B')
            || setweight(to_tsvector('simple', COALESCE(topic_desc_basic, '')), 'C')
         WHERE id = $1",
        [$id]
    );
}

function stobeCsvWorldUpsert(sql $db, array $payload): int
{
    $topic = stobeCsvTrim($payload['topic'] ?? '');
    if ($topic === '') {
        return 0;
    }

    $topicDesc = stobeCsvTrim($payload['topic_desc'] ?? '');
    $topicDescBasic = stobeCsvTrim($payload['topic_desc_basic'] ?? '');
    $knowledgeClass = stobeCsvTrim($payload['knowledge_class'] ?? '');
    $knowledgeClassBasic = stobeCsvTrim($payload['knowledge_class_basic'] ?? '');
    $aliases = stobeCsvTrim($payload['aliases'] ?? '');
    $tags = stobeCsvTrim($payload['tags'] ?? '');

    $existing = $db->fetchOne(
        "SELECT id FROM world_knowledge WHERE LOWER(topic) = LOWER($1) LIMIT 1",
        [$topic]
    );
    if ($existing) {
        $id = intval($existing['id'] ?? 0);
        if ($id > 0) {
            $db->exec(
                "UPDATE world_knowledge
                 SET topic = $1,
                     topic_desc = $2,
                     topic_desc_basic = $3,
                     knowledge_class = $4,
                     knowledge_class_basic = $5,
                     aliases = $6,
                     tags = $7
                 WHERE id = $8",
                [$topic, $topicDesc, $topicDescBasic, $knowledgeClass, $knowledgeClassBasic, $aliases, $tags, $id]
            );
            stobeCsvWorldUpdateNativeVector($db, $id);
            return $id;
        }
    }

    $inserted = $db->fetchOne(
        "INSERT INTO world_knowledge (
            topic, topic_desc, topic_desc_basic, knowledge_class,
            knowledge_class_basic, aliases, tags
         ) VALUES ($1,$2,$3,$4,$5,$6,$7)
         RETURNING id",
        [$topic, $topicDesc, $topicDescBasic, $knowledgeClass, $knowledgeClassBasic, $aliases, $tags]
    );
    $id = intval($inserted['id'] ?? 0);
    if ($id > 0) {
        stobeCsvWorldUpdateNativeVector($db, $id);
    }
    return $id;
}

function stobeCsvInferType(string $filename, array $map): string
{
    $lower = strtolower($filename);

    if (stobeCsvContains($lower, 'world_knowledge') || stobeCsvContains($lower, 'worldstate') ||
        stobeCsvContains($lower, 'oghma')) {
        return 'world_knowledge_import';
    }
    if (stobeCsvContains($lower, 'bio_unique')) {
        return 'bio_unique_import';
    }
    if (stobeCsvContains($lower, 'bio_random') || stobeCsvEndsWith($lower, '_bios.csv')) {
        return 'bio_random_import';
    }
    if (stobeCsvContains($lower, 'bio_token') || stobeCsvContains($lower, 'rename_token')) {
        return 'bio_token_import';
    }
    if (stobeCsvContains($lower, 'description')) {
        return 'description_import';
    }

    if (isset($map['topic']) || isset($map['topic_desc']) || isset($map['topic_desc_basic'])) {
        return 'world_knowledge_import';
    }
    if (isset($map['token']) || isset($map['rename_token'])) {
        return 'bio_token_import';
    }
    if ((isset($map['stringid']) || isset($map['baseid']) || isset($map['type'])) &&
        isset($map['name']) && isset($map['description'])) {
        if (stobeCsvContains($lower, 'unique')) {
            return 'bio_unique_import';
        }
        if (stobeCsvContains($lower, 'bio')) {
            return 'bio_random_import';
        }
        return 'description_import';
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    stobeCsvExitJson(405, ['success' => false, 'error' => 'POST required']);
}

$requestedType = strtolower(stobeCsvTrim($_GET['type'] ?? 'auto'));
$filename = stobeCsvTrim($_GET['filename'] ?? '');
if ($filename === '') {
    $filename = stobeCsvTrim($_FILES['file']['name'] ?? 'import.csv');
}

$allowedTypes = [
    'auto',
    'bio_random_import',
    'bio_unique_import',
    'bio_token_import',
    'description_import',
    'world_knowledge_import',
];
if (!in_array($requestedType, $allowedTypes, true)) {
    stobeCsvExitJson(400, ['success' => false, 'error' => 'Invalid import type']);
}

if (!isset($_FILES['file']) || intval($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    stobeCsvExitJson(400, ['success' => false, 'error' => 'No file uploaded or upload error']);
}

$uploadName = stobeCsvTrim($_FILES['file']['name'] ?? '');
$ext = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    stobeCsvExitJson(400, ['success' => false, 'error' => 'Only .csv files are allowed']);
}

$maxBytes = 10 * 1024 * 1024;
$uploadSize = intval($_FILES['file']['size'] ?? 0);
if ($uploadSize > $maxBytes) {
    stobeCsvExitJson(400, ['success' => false, 'error' => 'File too large (max 10MB)']);
}

$tmpPath = stobeCsvTrim($_FILES['file']['tmp_name'] ?? '');
$csvData = @file_get_contents($tmpPath);
if ($csvData === false) {
    stobeCsvExitJson(500, ['success' => false, 'error' => 'Failed to read uploaded CSV']);
}

if (substr($csvData, 0, 3) === "\xEF\xBB\xBF") {
    $csvData = substr($csvData, 3);
}
if (strpos($csvData, "\x00") !== false) {
    $csvData = mb_convert_encoding($csvData, 'UTF-8', 'UTF-16');
} elseif (!mb_check_encoding($csvData, 'UTF-8')) {
    $csvData = mb_convert_encoding($csvData, 'UTF-8', 'Windows-1252');
}

$stream = fopen('php://memory', 'r+');
fwrite($stream, $csvData);
rewind($stream);

$header = fgetcsv($stream, 0, ',');
if (!is_array($header) || count($header) === 0) {
    fclose($stream);
    stobeCsvExitJson(400, ['success' => false, 'error' => 'CSV header missing or invalid']);
}
$map = stobeCsvHeaderMap($header);

$resolvedType = $requestedType;
if ($resolvedType === 'auto') {
    $resolvedType = stobeCsvInferType($filename, $map);
    if ($resolvedType === '') {
        fclose($stream);
        stobeCsvExitJson(400, ['success' => false, 'error' => 'Could not infer import type from filename/header']);
    }
}

$db = $GLOBALS['db'];
$validBioTypes = ['personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals'];
$imported = 0;
$skipped = 0;

while (($row = fgetcsv($stream, 0, ',')) !== false) {
    if (!is_array($row) || count($row) === 0) {
        continue;
    }
    if (count(array_filter($row, static function ($value): bool {
        return trim(strval($value)) !== '';
    })) === 0) {
        continue;
    }

    if ($resolvedType === 'bio_random_import') {
        $type = strtolower(stobeCsvPick($row, $map, ['type', 'stringid', 'baseid'], 0));
        $name = stobeCsvPick($row, $map, ['name'], 1);
        $description = stobeCsvPick($row, $map, ['description'], 2);
        $race = stobeCsvPick($row, $map, ['race']);
        $gender = stobeCsvPick($row, $map, ['gender']);
        $faction = stobeCsvPick($row, $map, ['faction']);
        if (!in_array($type, $validBioTypes, true) || $description === '') {
            $skipped++;
            continue;
        }
        $ok = $db->exec(
            "INSERT INTO bio_random_custom (type, description, name, race, gender, faction)
             VALUES ($1, $2, $3, $4, $5, $6)
             ON CONFLICT (type, description, name)
             DO UPDATE SET
                race = EXCLUDED.race,
                gender = EXCLUDED.gender,
                faction = EXCLUDED.faction,
                updated_at = NOW()",
            [$type, $description, $name, $race, $gender, $faction]
        );
        if ($ok !== false) {
            $imported++;
        } else {
            $skipped++;
        }
        continue;
    }

    if ($resolvedType === 'bio_unique_import') {
        $type = strtolower(stobeCsvPick($row, $map, ['type', 'stringid', 'baseid'], 0));
        $name = stobeCsvPick($row, $map, ['name'], 1);
        $description = stobeCsvPick($row, $map, ['description'], 2);
        if ($name === '' || !in_array($type, $validBioTypes, true) || $description === '') {
            $skipped++;
            continue;
        }
        $ok = $db->exec(
            "INSERT INTO bio_unique_custom (name, type, description)
             VALUES ($1, $2, $3)
             ON CONFLICT (name, type)
             DO UPDATE SET
                description = EXCLUDED.description,
                updated_at = NOW()",
            [$name, $type, $description]
        );
        if ($ok !== false) {
            $imported++;
        } else {
            $skipped++;
        }
        continue;
    }

    if ($resolvedType === 'bio_token_import') {
        $token = stobeCsvPick($row, $map, ['token', 'stringid', 'name', 'rename_token'], 0);
        if ($token === '') {
            $skipped++;
            continue;
        }
        $ok = $db->exec(
            "INSERT INTO rename_token_global_custom (token)
             VALUES ($1)
             ON CONFLICT (token)
             DO UPDATE SET updated_at = NOW()",
            [$token]
        );
        if ($ok !== false) {
            $imported++;
        } else {
            $skipped++;
        }
        continue;
    }

    if ($resolvedType === 'description_import') {
        $stringid = stobeCsvPick($row, $map, ['stringid', 'baseid'], 0);
        $name = stobeCsvPick($row, $map, ['name'], 1);
        $description = stobeCsvPick($row, $map, ['description'], 2);
        if (strlen($stringid) > 128) {
            $stringid = substr($stringid, 0, 128);
        }
        if ($stringid === '') {
            $skipped++;
            continue;
        }
        $ok = $db->exec(
            "INSERT INTO descriptions_custom (stringid, name, description)
             VALUES ($1, $2, $3)
             ON CONFLICT (stringid)
             DO UPDATE SET
                name = EXCLUDED.name,
                description = EXCLUDED.description",
            [$stringid, $name, $description]
        );
        if ($ok !== false) {
            $imported++;
        } else {
            $skipped++;
        }
        continue;
    }

    if ($resolvedType === 'world_knowledge_import') {
        $payload = [
            'topic' => stobeCsvPick($row, $map, ['topic', 'stringid', 'baseid'], 0),
            'topic_desc' => stobeCsvPick($row, $map, ['topic_desc', 'description'], 2),
            'topic_desc_basic' => stobeCsvPick($row, $map, ['topic_desc_basic', 'basic_description'], 3),
            'knowledge_class' => stobeCsvPick($row, $map, ['knowledge_class']),
            'knowledge_class_basic' => stobeCsvPick($row, $map, ['knowledge_class_basic']),
            'aliases' => stobeCsvPick($row, $map, ['aliases']),
            'tags' => stobeCsvPick($row, $map, ['tags', 'category', 'name'], 1),
        ];
        $topic = stobeCsvTrim($payload['topic'] ?? '');
        $hasDesc = stobeCsvTrim($payload['topic_desc'] ?? '') !== '' ||
                   stobeCsvTrim($payload['topic_desc_basic'] ?? '') !== '';
        if ($topic === '' || !$hasDesc) {
            $skipped++;
            continue;
        }
        $savedId = stobeCsvWorldUpsert($db, $payload);
        if ($savedId > 0) {
            $imported++;
        } else {
            $skipped++;
        }
    }
}

fclose($stream);

if (function_exists('stobeLogInfo')) {
    stobeLogInfo('csv_import completed', [
        'filename' => $filename,
        'requested_type' => $requestedType,
        'resolved_type' => $resolvedType,
        'imported' => $imported,
        'skipped' => $skipped,
    ]);
}

stobeCsvExitJson(200, [
    'success' => true,
    'filename' => $filename,
    'type' => $resolvedType,
    'imported' => $imported,
    'skipped' => $skipped,
]);

