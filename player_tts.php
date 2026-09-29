<?php
require_once __DIR__ . '/lib/stobe_interaction.php';
stobeInteractionRequire();


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once(__DIR__ . '/lib/bootstrap.php');

$textRaw = trim(strval($_GET['text'] ?? ''));
$actor = trim(strval($_GET['actor'] ?? getSetting('PLAYER_NAME', 'Player')));
if ($actor === '') {
    $actor = 'Player';
}

if ($textRaw === '') {
    echo json_encode(['ok' => false, 'error' => 'empty_text']);
    exit;
}

$requestTtsRaw = strtolower(trim(strval($_GET['tts_enabled'] ?? '1')));
$requestTtsEnabled = !in_array($requestTtsRaw, ['0', 'false', 'off', 'no', 'disabled'], true);
if (!$requestTtsEnabled) {
    echo json_encode(['ok' => false, 'error' => 'tts_disabled']);
    exit;
}

if (!getSettingBool('PLAYER_DIALOGUE_AUDIO_ENABLED', true)) {
    echo json_encode(['ok' => false, 'error' => 'player_dialogue_audio_disabled']);
    exit;
}

$text = sanitizeForKenshi($textRaw);
$tts = stobeSynthesizePocketTtsLine(
    $actor,
    $text,
    false,
    getSetting('PLAYER_TTS_FILTER_PRESET', 'none')
);
$hash = trim(strval($tts['hash'] ?? ''));
$durationMs = intval($tts['duration_ms'] ?? 0);

if ($hash === '') {
    echo json_encode(['ok' => false, 'error' => 'tts_unavailable']);
    exit;
}

echo json_encode([
    'ok' => true,
    'hash' => $hash,
    'duration_ms' => $durationMs,
]);

