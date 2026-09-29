<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stobeVoiceFilterPreviewRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    stobeVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Use POST to generate a preview.'], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}
$now = microtime(true);
$recentRequests = array_values(array_filter(
    is_array($_SESSION['stobe_voice_filter_previews'] ?? null) ? $_SESSION['stobe_voice_filter_previews'] : [],
    static fn($timestamp): bool => is_numeric($timestamp) && floatval($timestamp) > $now - 30
));
if (count($recentRequests) >= 8) {
    stobeVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Too many previews. Wait a moment and try again.'], 429);
}
$recentRequests[] = $now;
$_SESSION['stobe_voice_filter_previews'] = $recentRequests;
session_write_close();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$requestedPreset = strtolower(trim(strval($_POST['tts_filter_preset'] ?? 'none')));
$catalog = stobeTtsFilterPresetCatalog();
if (!array_key_exists($requestedPreset, $catalog)) {
    stobeVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Choose a valid voice filter preset.'], 400);
}
$presetId = stobeNormalizeTtsFilterPresetId($requestedPreset);
$profileId = intval($_POST['profile_id'] ?? 0);
$voiceId = trim(strval($_POST['voiceid'] ?? ''));
$speaker = trim(strval($_POST['speaker'] ?? ''));
$sample = 'The road ahead is uncertain, but we will face it together.';

try {
    if ($profileId > 0 || $voiceId !== '') {
        if ($profileId <= 0 || $voiceId === '') {
            stobeVoiceFilterPreviewRespond(['ok' => false, 'error' => 'Choose a profile and enter a Voice ID.'], 400);
        }
        $connector = stobeGetProfileTtsConnectorForNpc(['profile_id' => $profileId]);
        if (!is_array($connector)) {
            stobeVoiceFilterPreviewRespond(['ok' => false, 'error' => 'The selected profile has no TTS connector.'], 404);
        }
        $result = stobeSynthesizeTtsFromConnector($connector, $sample, $voiceId, $presetId);
    } else {
        if ($speaker === '') {
            $speaker = trim(strval(getSetting('PLAYER_NAME', 'Player'))) ?: 'Player';
        }
        $result = stobeSynthesizeTtsLine($speaker, $sample, false, $presetId);
    }

    $hash = trim(strval($result['hash'] ?? ''));
    $cachePath = $enginePath . 'soundcache' . DIRECTORY_SEPARATOR . $hash . '.wav';
    if (preg_match('/^[a-f0-9]{32}$/i', $hash) !== 1 || !is_file($cachePath) || intval(filesize($cachePath)) <= 44) {
        throw new RuntimeException('No preview audio was generated.');
    }

    $scriptPath = str_replace('\\', '/', strval($_SERVER['SCRIPT_NAME'] ?? ''));
    $uiPosition = strpos($scriptPath, '/ui/');
    $webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
    if ($webRoot === '/') {
        $webRoot = '';
    }
    stobeVoiceFilterPreviewRespond([
        'ok' => true,
        'audio_url' => rtrim($webRoot, '/') . '/soundcache/' . rawurlencode($hash . '.wav') . '?ts=' . time(),
    ]);
} catch (Throwable $exception) {
    if (function_exists('stobeLogException')) {
        stobeLogException($exception, 'Voice filter preview failed');
    }
    stobeVoiceFilterPreviewRespond([
        'ok' => false,
        'error' => 'TTS preview could not be generated. Check the selected connector and voice.',
    ], 502);
}
