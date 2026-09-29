<?php
require_once __DIR__ . '/lib/stobe_interaction.php';
stobeInteractionRequire();


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once(__DIR__ . '/lib/bootstrap.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$payload = json_decode(strval(file_get_contents('php://input')), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$actor = trim(strval($payload['actor'] ?? ''));
$storageId = normalizeStorageIdToken($payload['storage_id'] ?? '');
$text = trim(strval($payload['text'] ?? ''));
if ($actor === '' || $text === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_actor_or_text']);
    exit;
}
if (strlen($actor) > 255 || strlen($storageId) > 255 || strlen($text) > 2000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
    exit;
}

$npcData = false;
if ($storageId !== '') {
    $npcData = $GLOBALS['db']->fetchOne(
        "SELECT id, name, profile_id, voiceid, gender, race, metadata
         FROM core_npc
         WHERE LOWER(COALESCE(metadata->>'storage_id', '')) = LOWER($1)
         ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
         LIMIT 1",
        [$storageId]
    );
}
if (!$npcData) {
    $npcData = stobeResolveNpcDataForTts($actor);
}

$resolvedActor = trim(strval($npcData['name'] ?? $actor));
$tts = stobeSynthesizeTtsLine($resolvedActor !== '' ? $resolvedActor : $actor, $text, $npcData);
$hash = trim(strval($tts['hash'] ?? ''));
if ($hash === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'tts_unavailable']);
    exit;
}

stobeLogInfo('Dialogue menu TTS prepared', [
    'npc_name' => $resolvedActor !== '' ? $resolvedActor : $actor,
    'storage_id' => $storageId,
    'hash' => $hash,
    'cached' => !empty($tts['cached']),
]);

echo json_encode([
    'ok' => true,
    'hash' => $hash,
    'duration_ms' => intval($tts['duration_ms'] ?? 0),
    'cached' => !empty($tts['cached']),
]);
