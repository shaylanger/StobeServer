<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'stt_connector.class.php';

function stobeSttCurl(string $url, array $options): string
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for speech recognition.');
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10]);
    $body = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = trim(strval(curl_error($ch)));
    curl_close($ch);
    if ($body === false) throw new RuntimeException($error ?: 'Speech recognition request failed.');
    if ($status < 200 || $status >= 300) throw new RuntimeException('Speech recognition returned HTTP ' . $status . '.');
    return strval($body);
}

function stobeSttApiKey(array $connector): string
{
    $id = intval($connector['api_badge_id'] ?? 0);
    if ($id <= 0) return '';
    $row = $GLOBALS['db']->fetchOne('SELECT api_key FROM core_api_badge WHERE id = $1 LIMIT 1', [$id]);
    return trim(strval($row['api_key'] ?? ''));
}

function stobeSttMultipart(string $filePath, string $fieldName, array $fields): array
{
    $fields[$fieldName] = new CURLFile($filePath, 'audio/wav', basename($filePath));
    return $fields;
}

function stobeTranscribeAudio(string $filePath, ?array $connector = null): array
{
    $manager = new STTConnector();
    $connector = $connector ?: $manager->getActive();
    if (!$connector) throw new RuntimeException('No speech-to-text connector is configured.');
    $driver = $manager->normalizeDriverValue($connector['driver'] ?? 'none');
    if ($driver === 'none') throw new RuntimeException('Speech-to-text is disabled.');
    $meta = array_merge($manager->defaultsForDriver($driver), $manager->decodeMetadata($connector['metadata'] ?? '{}'));
    $timeout = max(5, min(120, intval($meta['TIMEOUT'] ?? 60)));
    $url = trim(strval($connector['url'] ?? '')) ?: $manager->getDefaultUrlForDriver($driver);
    $apiKey = stobeSttApiKey($connector);
    $text = '';
    $sttStartedAt = microtime(true);
    stobeLogInfo('Latency stage stt provider dispatch', [
        'provider' => $driver,
        'endpoint' => $url,
        'unix_ms' => intval(round($sttStartedAt * 1000)),
        'request_elapsed_ms' => isset($GLOBALS['__stobe_request_start']) ? intval(round(($sttStartedAt - $GLOBALS['__stobe_request_start']) * 1000)) : 0,
    ]);

    if (in_array($driver, ['parakeet', 'localwhisper', 'whisper'], true)) {
        if ($driver === 'whisper' && $apiKey === '') throw new RuntimeException('The selected OpenAI API badge has no key.');
        $field = $driver === 'localwhisper' ? trim(strval($meta['FORMFIELD'] ?? 'audio_file')) : 'file';
        $fields = ['language' => strval($meta['LANGUAGE'] ?? 'en')];
        if (!empty($meta['TRANSLATE'])) $fields['translate'] = 'true';
        if ($driver === 'whisper') $fields['model'] = strval($meta['MODEL'] ?? 'whisper-1');
        $headers = $driver === 'whisper' ? ['Authorization: Bearer ' . $apiKey] : [];
        $json = json_decode(stobeSttCurl($url, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => stobeSttMultipart($filePath, $field, $fields), CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout]), true);
        $text = trim(strval($json['text'] ?? $json['transcript'] ?? $json['transcription'] ?? ''));
    } elseif ($driver === 'deepgram') {
        if ($apiKey === '') throw new RuntimeException('The selected Deepgram API badge has no key.');
        $query = http_build_query(['model' => strval($meta['MODEL'] ?? 'nova-3'), 'language' => strval($meta['LANGUAGE'] ?? 'en'), 'smart_format' => !empty($meta['SMART_FORMAT']) ? 'true' : 'false', 'punctuate' => 'true']);
        $json = json_decode(stobeSttCurl($url . (str_contains($url, '?') ? '&' : '?') . $query, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => file_get_contents($filePath), CURLOPT_HTTPHEADER => ['Authorization: Token ' . $apiKey, 'Content-Type: audio/wav'], CURLOPT_TIMEOUT => $timeout]), true);
        $text = trim(strval($json['results']['channels'][0]['alternatives'][0]['transcript'] ?? ''));
    } elseif ($driver === 'gemini') {
        if ($apiKey === '') throw new RuntimeException('The selected Google API badge has no key.');
        $model = trim(strval($meta['MODEL'] ?? 'gemini-2.5-flash'));
        $endpoint = rtrim($url, '/') . '/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $payload = ['contents' => [['parts' => [['text' => 'Transcribe this audio accurately. Return only the spoken words. Language: ' . strval($meta['LANGUAGE'] ?? 'en')], ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode(file_get_contents($filePath))]]]]], 'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 1024]];
        $json = json_decode(stobeSttCurl($endpoint, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => $timeout]), true);
        $text = trim(strval($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    } elseif ($driver === 'azure') {
        if ($apiKey === '') throw new RuntimeException('The selected Azure API badge has no key.');
        $endpoint = 'https://' . trim(strval($meta['REGION'] ?? 'eastus')) . '.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?' . http_build_query(['language' => strval($meta['LANGUAGE'] ?? 'en-US'), 'profanity' => strval($meta['PROFANITY'] ?? 'raw')]);
        $json = json_decode(stobeSttCurl($endpoint, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => file_get_contents($filePath), CURLOPT_HTTPHEADER => ['Ocp-Apim-Subscription-Key: ' . $apiKey, 'Content-Type: audio/wav'], CURLOPT_TIMEOUT => $timeout]), true);
        $text = trim(strval($json['DisplayText'] ?? ''));
    } else {
        if ($apiKey === '') throw new RuntimeException('The selected Inworld API badge has no key.');
        $payload = ['transcribeConfig' => ['modelId' => strval($meta['MODEL_ID'] ?? 'groq/whisper-large-v3'), 'audioEncoding' => 'AUTO_DETECT', 'sampleRateHertz' => 16000, 'numberOfChannels' => 1, 'language' => strval($meta['LANGUAGE'] ?? 'en-US')], 'audioData' => ['content' => base64_encode(file_get_contents($filePath))]];
        $json = json_decode(stobeSttCurl($url, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $apiKey, 'Content-Type: application/json'], CURLOPT_TIMEOUT => $timeout]), true);
        $text = trim(strval($json['transcription']['transcript'] ?? ''));
    }
    if ($text === '') throw new RuntimeException('No speech was detected.');
    $sttCompletedAt = microtime(true);
    stobeLogInfo('STT transcription completed', [
        'provider' => $driver,
        'characters' => strlen($text),
        'text_sha1' => sha1($text),
        'stt_service_ms' => intval(round(($sttCompletedAt - $sttStartedAt) * 1000)),
        'unix_ms' => intval(round($sttCompletedAt * 1000)),
        'request_elapsed_ms' => isset($GLOBALS['__stobe_request_start']) ? intval(round(($sttCompletedAt - $GLOBALS['__stobe_request_start']) * 1000)) : 0,
    ]);
    return ['text' => $text, 'provider' => $driver];
}
