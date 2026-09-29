<?php
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard();
require_once __DIR__ . '/lib/stobe_interaction.php';
stobeInteractionRequire();


/**
 * StobeServer - Speech-to-Text Endpoint
 * 
 * Receives audio file uploads from Stobe DLL and transcribes via
 * configured STT service (Parakeet, Whisper, etc.).
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");
require_once($path . "lib/stt_transcription.php");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stobeLogWarn('stt rejected request method', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
    http_response_code(405);
    echo json_encode(["error" => "POST required"]);
    exit;
}

try {
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || intval($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No audio file was uploaded.');
    }
    $size = intval($upload['size'] ?? 0);
    if ($size <= 44 || $size > 4 * 1024 * 1024 || !is_uploaded_file(strval($upload['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('Audio must be a WAV file smaller than 4 MB.');
    }
    $result = stobeTranscribeAudio(strval($upload['tmp_name']));
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    stobeLogException($exception, 'STT request failed');
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
}

