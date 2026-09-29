<?php

// Preserve Stobe's voice selection and audio pipeline while using the Higgs API.
function stobeSynthesizeViaHiggs(string $speechText, array &$runtime): string|false {
    $endpoint = rtrim(trim(strval($runtime['endpoint'] ?? 'http://127.0.0.1:8025')), '/');
    if (!in_array(strtolower(strval(parse_url($endpoint, PHP_URL_SCHEME))), ['http', 'https'], true)) return false;
    $voice = trim(strval($runtime['voiceid'] ?? ''));
    if ($voice === '') $voice = trim(strval($runtime['fallback_voiceid'] ?? ''));
    $voice = stobeSanitizeVoiceToken($voice);
    if ($voice === '') return false;
    $model = trim(strval($runtime['model_id'] ?? '')) ?: 'higgs-v3';
    $payload = ['model' => $model, 'input' => $speechText, 'voice' => $voice];
    $host = strtolower(strval(parse_url($endpoint, PHP_URL_HOST)));
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
        $sample = stobeFindVoiceSamplePath($voice);
        if ($sample === '') $sample = '/home/dwemer/higgs-tts/voices/' . $voice . '.wav';
        if (!stobeIsRiffWavFile($sample)) $sample = stobePrepareAudioCppVoiceSample($sample, $voice);
        if ($sample !== '' && is_readable($sample)) {
            unset($payload['voice']);
            $payload['voice_ref'] = $sample;
        }
    }
    $url = str_ends_with($endpoint, '/v1/audio/speech') ? $endpoint : $endpoint . '/v1/audio/speech';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: audio/wav'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $audio = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    if ($status !== 200 || !is_string($audio) || strlen($audio) <= 44
        || substr($audio, 0, 4) !== 'RIFF' || substr($audio, 8, 4) !== 'WAVE') {
        stobeLogWarn('Higgs speech generation failed; check the service and selected voice', ['http_code' => $status]);
        $fallback = trim(strval($runtime['fallback_voiceid'] ?? ''));
        if (in_array($status, [400, 404, 422, 500], true) && $fallback !== '' && $fallback !== $voice) {
            $runtime['voiceid'] = $fallback;
            $runtime['fallback_voiceid'] = '';
            return stobeSynthesizeViaHiggs($speechText, $runtime);
        }
        return false;
    }
    return $audio;
}
