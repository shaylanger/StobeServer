<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'tts_pronunciation.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'tts_filter_presets.php';

function stobePocketTtsDefaultSettings(): array {
    return [
        'stream_chunk_size' => 20,
        'temperature' => 0.9,
        'speed' => 1.0,
        'length_penalty' => 1.0,
        'repetition_penalty' => 5.0,
        'top_p' => 0.85,
        'top_k' => 50,
        'enable_text_splitting' => true,
    ];
}

function stobePocketTtsDecodeConfig(mixed $rawConfig): array {
    if (is_array($rawConfig)) {
        return $rawConfig;
    }
    if (!is_string($rawConfig)) {
        return [];
    }
    $decoded = json_decode($rawConfig, true);
    return is_array($decoded) ? $decoded : [];
}

function stobePocketTtsNormalizeSettingValue(string $key, mixed $rawValue): mixed {
    if ($key === 'enable_text_splitting') {
        if (is_bool($rawValue)) {
            return $rawValue;
        }
        return in_array(strtolower(trim(strval($rawValue))), ['1', 'true', 'yes', 'on'], true);
    }
    if ($key === 'stream_chunk_size' || $key === 'top_k') {
        return intval($rawValue);
    }
    return floatval($rawValue);
}

function stobeTtsBool(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim(strval($value))), ['1', 'true', 'yes', 'on'], true);
}

function stobeGetDefaultTtsConnector(): array|false {
    $db = $GLOBALS["db"];
    $connector = $db->fetchOne(
        "SELECT * FROM core_tts_connector
         WHERE is_default = TRUE
         ORDER BY id ASC
         LIMIT 1"
    );
    if ($connector) {
        return $connector;
    }
    return $db->fetchOne(
        "SELECT * FROM core_tts_connector
         ORDER BY id ASC
         LIMIT 1"
    );
}

function stobeGetProfileTtsConnectorForNpc(array|false $npcData): array|false {
    $profileId = 0;
    if (is_array($npcData)) {
        $profileId = intval($npcData['profile_id'] ?? 0);
    }
    if ($profileId <= 0 && function_exists('getDefaultNpcProfileId')) {
        $profileId = intval(getDefaultNpcProfileId());
    }
    if ($profileId <= 0) {
        return false;
    }

    $db = $GLOBALS["db"];
    $connector = $db->fetchOne(
        "SELECT t.*
         FROM core_profiles p
         LEFT JOIN core_tts_connector t ON t.id = p.tts_connector_id
         WHERE p.id = $1
         LIMIT 1",
        [$profileId]
    );
    if (!$connector || intval($connector['id'] ?? 0) <= 0) {
        return false;
    }
    return $connector;
}

function stobeResolveNpcDataForTts(string $npcName, array|false $npcData = false): array|false {
    if (is_array($npcData) && intval($npcData['profile_id'] ?? 0) > 0) {
        return $npcData;
    }
    $safeName = trim($npcName);
    if ($safeName === '') {
        return is_array($npcData) ? $npcData : false;
    }
    $db = $GLOBALS["db"];

    $isNarrator = strcasecmp($safeName, 'The Narrator') === 0;
    if (function_exists('stobeIsNarratorName')) {
        $isNarrator = stobeIsNarratorName($safeName);
    }
    if ($isNarrator) {
        $profileRow = $db->fetchOne(
            "SELECT value
             FROM core_narrator
             WHERE id = 'profile_id'
             LIMIT 1"
        );
        $voiceRow = $db->fetchOne(
            "SELECT value
             FROM core_narrator
             WHERE id = 'voiceid'
             LIMIT 1"
        );
        $filterRow = $db->fetchOne(
            "SELECT value
             FROM core_narrator
             WHERE id = 'tts_filter_preset'
             LIMIT 1"
        );
        $profileId = intval(trim(strval($profileRow['value'] ?? '0')));
        if ($profileId <= 0) {
            $profileId = intval($npcData['profile_id'] ?? 0);
        }
        $voiceId = trim(strval($voiceRow['value'] ?? ''));
        if ($voiceId === '') {
            $voiceId = trim(strval($npcData['voiceid'] ?? ''));
        }
        $resolvedName = $safeName;
        if (function_exists('stobeNarratorName')) {
            $resolvedName = stobeNarratorName();
        }
        return [
            'id' => 1,
            'name' => $resolvedName,
            'profile_id' => $profileId > 0 ? $profileId : 0,
            'voiceid' => $voiceId,
            'tts_filter_preset' => stobeNormalizeTtsFilterPresetId($filterRow['value'] ?? 'none'),
            'gender' => trim(strval($npcData['gender'] ?? '')),
        ];
    }

    $resolved = $db->fetchOne(
        "SELECT id, name, profile_id, voiceid, gender, metadata
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY
            CASE WHEN profile_id IS NOT NULL AND profile_id > 0 THEN 0 ELSE 1 END,
            CASE WHEN COALESCE(BTRIM(voiceid), '') <> '' THEN 0 ELSE 1 END,
            CASE WHEN COALESCE(metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
            gamets_last_updated DESC,
            updated_at DESC,
            id DESC
         LIMIT 1",
        [$safeName]
    );
    if ($resolved && is_array($resolved)) {
        return $resolved;
    }
    return is_array($npcData) ? $npcData : false;
}

function stobeIsFemaleGender(mixed $value): bool {
    $gender = strtolower(trim(strval($value)));
    if ($gender === '') {
        return false;
    }

    return in_array($gender, ['f', 'female', 'woman', 'girl', 'fem'], true)
        || str_contains($gender, 'female');
}

function stobeNormalizeConnectorFallbackVoice(string $voiceId, string $defaultVoice): string {
    $normalized = trim($voiceId);
    if ($normalized !== '') {
        return $normalized;
    }

    return $defaultVoice;
}

function stobeResolveConnectorFallbackVoices(array $connectorConfig): array {
    $legacyVoiceId = trim(strval($connectorConfig['voiceid'] ?? ''));
    $fallbackMale = stobeNormalizeConnectorFallbackVoice(
        trim(strval($connectorConfig['fallback_male'] ?? '')),
        $legacyVoiceId !== '' ? $legacyVoiceId : 'male1'
    );
    $fallbackFemale = stobeNormalizeConnectorFallbackVoice(
        trim(strval($connectorConfig['fallback_female'] ?? '')),
        $legacyVoiceId !== '' ? $legacyVoiceId : 'female1'
    );

    return [
        'male' => $fallbackMale,
        'female' => $fallbackFemale,
        'legacy_voiceid' => $legacyVoiceId,
    ];
}

function stobePocketTtsIsAudioCpp(string $endpoint): bool {
    return preg_match('/\:8086(?:\/|$)/', $endpoint) === 1 || strpos($endpoint, '/v1/audio/speech') !== false;
}

function stobePocketTtsProbeJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT_MS => 350,
        CURLOPT_TIMEOUT_MS => 1000,
    ]);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    return [
        'ok' => is_string($response) && $httpCode >= 200 && $httpCode < 300,
        'decoded' => is_string($response) ? json_decode($response, true) : null,
    ];
}

function stobePocketTtsDetectEndpointMode(string $endpoint): string {
    $endpoint = rtrim($endpoint, '/');
    $endpoint = preg_replace('#/(?:v1/audio/speech|tts_to_audio)/?$#', '', $endpoint);
    $health = stobePocketTtsProbeJson($endpoint . '/health');
    $models = $health['ok']
        ? stobePocketTtsProbeJson($endpoint . '/v1/models')
        : ['ok' => false, 'decoded' => null];
    if ($health['ok'] && $models['ok']) {
        foreach (($models['decoded']['data'] ?? []) as $model) {
            $modelId = strtolower(trim(strval($model['id'] ?? '')));
            $family = strtolower(trim(strval($model['family'] ?? '')));
            if ($modelId === 'pocket-tts' || $family === 'pocket_tts') {
                return 'audio_cpp';
            }
        }
    }

    $providerInfo = stobePocketTtsProbeJson($endpoint . '/provider_info');
    $provider = strtolower(trim(strval($providerInfo['decoded']['provider'] ?? '')));
    if ($providerInfo['ok'] && in_array($provider, ['pockettts', 'pocket_tts', 'pocket-tts'], true)) {
        return 'standard';
    }

    $openApi = stobePocketTtsProbeJson($endpoint . '/openapi.json');
    if (!$openApi['ok'] || !is_array($openApi['decoded'])) {
        return '';
    }
    $paths = array_keys(is_array($openApi['decoded']['paths'] ?? null) ? $openApi['decoded']['paths'] : []);
    if (in_array('/languages', $paths, true) || in_array('/get_models_list', $paths, true)) {
        return '';
    }
    if (in_array('/tts_to_audio_form', $paths, true)
        || (in_array('/tts_to_audio', $paths, true) && in_array('/voices/{voice_id}', $paths, true))) {
        return 'standard';
    }
    return '';
}

function stobePocketTtsShouldTryFallback(string $endpoint, int $httpCode): bool {
    return $httpCode === 0
        || (in_array($httpCode, [404, 405], true)
            && stobePocketTtsDetectEndpointMode($endpoint) === '');
}

// Finds a compatible PocketTTS service on the same host after a stale known port fails.
function stobePocketTtsFindFallbackEndpoint(string $configuredEndpoint): ?string {
    $normalized = rtrim(trim($configuredEndpoint), '/');
    $parts = parse_url($normalized);
    $configuredPort = intval($parts['port'] ?? 0);
    if (!in_array($configuredPort, [8020, 8024, 8086], true) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower(strval($parts['scheme'] ?? 'http'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    $host = strval($parts['host']);
    if (strpos($host, ':') !== false && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }
    $auth = '';
    if (isset($parts['user'])) {
        $auth = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
    }
    $path = preg_replace(
        '#/(?:v1/audio/speech|tts_to_audio)/?$#',
        '',
        strval($parts['path'] ?? '')
    );
    $path = $path === '/' ? '' : rtrim($path, '/');

    foreach ([8086, 8024, 8020] as $port) {
        if ($port === $configuredPort) {
            continue;
        }
        $candidate = $scheme . '://' . $auth . $host . ':' . $port . $path;
        $mode = stobePocketTtsDetectEndpointMode($candidate);
        if ($mode === '') {
            continue;
        }
        if ($mode === 'audio_cpp' && !stobePocketTtsIsAudioCpp($candidate)) {
            $candidate .= '/v1/audio/speech';
        }
        return $candidate;
    }
    return null;
}

function stobeIsRiffWavFile(string $path): bool {
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }

    $header = @file_get_contents($path, false, null, 0, 12);
    return is_string($header) && strlen($header) >= 12
        && substr($header, 0, 4) === 'RIFF'
        && substr($header, 8, 4) === 'WAVE';
}

function stobePrepareAudioCppVoiceSample(string $samplePath, string $voiceId): string {
    if (stobeIsRiffWavFile($samplePath)) {
        return $samplePath;
    }
    if ($samplePath === '' || !is_file($samplePath) || !is_readable($samplePath)) {
        return '';
    }

    $ffmpegPath = trim(strval(@shell_exec('command -v ffmpeg 2>/dev/null')));
    if ($ffmpegPath === '') {
        stobeLogWarn('Pocket TTS audio.cpp voice conversion requires ffmpeg', [
            'sample' => basename($samplePath),
            'voiceid' => trim($voiceId),
        ]);
        return '';
    }

    $enginePath = $GLOBALS['ENGINE_PATH'] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    $cacheDir = rtrim($enginePath, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'soundcache'
        . DIRECTORY_SEPARATOR . 'pockettts_voice_refs';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        stobeLogWarn('Pocket TTS audio.cpp voice cache could not be created', [
            'path' => $cacheDir,
        ]);
        return '';
    }

    $voiceToken = stobeSanitizeVoiceToken(pathinfo($voiceId, PATHINFO_FILENAME));
    if ($voiceToken === '') {
        $voiceToken = stobeSanitizeVoiceToken(pathinfo($samplePath, PATHINFO_FILENAME));
    }
    if ($voiceToken === '') {
        $voiceToken = 'voice';
    }
    $sourceHash = @hash_file('sha256', $samplePath);
    if (!is_string($sourceHash) || $sourceHash === '') {
        $sourceHash = md5($samplePath . '|' . strval(@filemtime($samplePath)) . '|' . strval(@filesize($samplePath)));
    }
    $targetPath = $cacheDir . DIRECTORY_SEPARATOR . $voiceToken . '-' . substr($sourceHash, 0, 16) . '.wav';
    if (stobeIsRiffWavFile($targetPath)) {
        return $targetPath;
    }

    $tempPath = @tempnam($cacheDir, '.stobe_voice_');
    if (!is_string($tempPath) || $tempPath === '') {
        return '';
    }
    $cmd = escapeshellarg($ffmpegPath)
        . ' -nostdin -hide_banner -loglevel error -y -i ' . escapeshellarg($samplePath)
        . ' -ac 1 -ar 22050 -c:a pcm_s16le -f wav ' . escapeshellarg($tempPath)
        . ' 2>&1';
    $output = [];
    $exitCode = 1;
    @exec($cmd, $output, $exitCode);
    if ($exitCode !== 0 || !stobeIsRiffWavFile($tempPath) || intval(@filesize($tempPath)) <= 44) {
        @unlink($tempPath);
        stobeLogWarn('Pocket TTS audio.cpp voice conversion failed', [
            'sample' => basename($samplePath),
            'voiceid' => trim($voiceId),
            'error' => trim(implode("\n", array_slice($output, -3))),
        ]);
        return '';
    }

    if (!@rename($tempPath, $targetPath)) {
        if (stobeIsRiffWavFile($targetPath)) {
            @unlink($tempPath);
            return $targetPath;
        }
        @unlink($tempPath);
        return '';
    }
    @chmod($targetPath, 0644);
    stobeLogInfo('Pocket TTS audio.cpp voice sample cached as WAV', [
        'sample' => basename($samplePath),
        'voiceid' => trim($voiceId),
        'cached_sample' => basename($targetPath),
    ]);
    return $targetPath;
}

function stobePocketTtsAudioCppVoicePayload(string $voiceId): array {
    $samplePath = stobeFindVoiceSamplePath($voiceId);
    $voiceToken = stobeSanitizeVoiceToken(pathinfo($voiceId, PATHINFO_FILENAME));
    if ($voiceToken === '' && $samplePath !== '') {
        $voiceToken = stobeSanitizeVoiceToken(pathinfo($samplePath, PATHINFO_FILENAME));
    }

    $wavCandidates = [$samplePath];
    if ($voiceToken !== '') {
        $wavCandidates[] = '/home/dwemer/pocket-tts/speakers/' . $voiceToken . '.wav';
        $wavCandidates[] = '/home/dwemer/audio.cpp/speakers/' . $voiceToken . '.wav';
    }
    foreach (array_unique($wavCandidates) as $candidate) {
        if (stobeIsRiffWavFile($candidate)) {
            return ['voice_ref' => $candidate];
        }
    }

    $preparedSample = stobePrepareAudioCppVoiceSample($samplePath, $voiceId);
    if ($preparedSample !== '') {
        return ['voice_ref' => $preparedSample];
    }

    return ['voice' => 'alba'];
}

function stobeResolveNpcVoiceIdByName(string $npcName): string {
    $safeName = trim($npcName);
    if ($safeName === '') {
        return '';
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return '';
    }

    $isNarrator = strcasecmp($safeName, 'The Narrator') === 0;
    if (function_exists('stobeIsNarratorName')) {
        $isNarrator = stobeIsNarratorName($safeName);
    }
    if ($isNarrator) {
        $narratorVoice = $db->fetchOne(
            "SELECT value
             FROM core_narrator
             WHERE id = 'voiceid'
             LIMIT 1"
        );
        $resolvedNarratorVoice = trim(strval($narratorVoice['value'] ?? ''));
        if ($resolvedNarratorVoice !== '') {
            return $resolvedNarratorVoice;
        }
    }

    $direct = $db->fetchOne(
        "SELECT voiceid
         FROM core_npc
         WHERE LOWER(name) = LOWER($1)
         ORDER BY
            CASE WHEN COALESCE(BTRIM(voiceid), '') <> '' THEN 0 ELSE 1 END,
            CASE WHEN COALESCE(metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
            gamets_last_updated DESC,
            updated_at DESC,
            id DESC
         LIMIT 1",
        [$safeName]
    );
    $directVoice = trim(strval($direct['voiceid'] ?? ''));
    if ($directVoice !== '') {
        return $directVoice;
    }

    $fallback = $db->fetchOne(
        "SELECT voiceid
         FROM core_npc
         WHERE LOWER(COALESCE(original_name, '')) = LOWER($1)
         ORDER BY
            CASE WHEN COALESCE(BTRIM(voiceid), '') <> '' THEN 0 ELSE 1 END,
            CASE WHEN COALESCE(metadata->>'storage_id', '') <> '' THEN 0 ELSE 1 END,
            gamets_last_updated DESC,
            updated_at DESC,
            id DESC
         LIMIT 1",
        [$safeName]
    );
    return trim(strval($fallback['voiceid'] ?? ''));
}

function stobeEnsureSoundCacheDir(): string {
    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    $soundCacheDir = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'soundcache';
    if (!is_dir($soundCacheDir)) {
        @mkdir($soundCacheDir, 0775, true);
    }
    return $soundCacheDir;
}

function stobeNormalizeSpeechTextForTts(string $line): string {
    $speech = trim($line);
    if ($speech === '') {
        return '';
    }

    $slashPos = strpos($speech, '/');
    if ($slashPos !== false) {
        $speech = trim(substr($speech, 0, $slashPos));
    }

    $speech = preg_replace('/\[(?:ACTION:[^\]]+|ATTACK)\]/i', '', $speech) ?? '';
    $speech = trim(preg_replace('/\s+/u', ' ', $speech) ?? '');
    return $speech;
}

function stobePocketTtsApplySettings(string $provider, string $endpoint, array $settings): void {
    static $appliedCache = [];
    if ($endpoint === '') {
        return;
    }
    if (!in_array($provider, ['pocket_tts', 'xtts'], true)) {
        return;
    }

    $payload = json_encode($settings);
    if (!is_string($payload) || $payload === '') {
        return;
    }

    $cacheKey = $endpoint . '|' . md5($payload);
    if (isset($appliedCache[$cacheKey])) {
        return;
    }

    $url = $endpoint . '/set_tts_settings';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        stobeLogWarn('PocketTTS settings push failed', [
            'endpoint' => $endpoint,
            'error' => curl_error($ch),
        ]);
    }
    curl_close($ch);
    $appliedCache[$cacheKey] = true;
}

function stobePostJsonToLocalTtsEndpoint(string $endpoint, string $payload, array $pathCandidates, string $accept = 'audio/wav'): array {
    $responseBody = '';
    $httpCode = 0;
    $curlError = '';
    $normalizedEndpoint = rtrim($endpoint, '/');
    $attemptedUrls = [];

    foreach ($pathCandidates as $pathCandidate) {
        $path = trim(strval($pathCandidate));
        if ($path === '') {
            continue;
        }
        if (substr($path, 0, 1) !== '/') {
            $path = '/' . $path;
        }

        $url = $normalizedEndpoint . $path;
        if (isset($attemptedUrls[$url])) {
            continue;
        }
        $attemptedUrls[$url] = true;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: ' . $accept],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 45,
        ]);
        $attemptBinary = curl_exec($ch);
        $attemptCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $attemptError = curl_error($ch);
        curl_close($ch);

        if (is_string($attemptBinary) && $attemptBinary !== '' && $attemptCode >= 200 && $attemptCode < 300) {
            return [
                'binary' => $attemptBinary,
                'http_code' => $attemptCode,
                'error' => $attemptError,
                'response' => '',
            ];
        }

        if (is_string($attemptBinary) && $attemptBinary !== '') {
            $responseBody = $attemptBinary;
        }
        if ($attemptCode > 0) {
            $httpCode = $attemptCode;
        }
        if ($attemptError !== '') {
            $curlError = $attemptError;
        }
    }

    return [
        'binary' => false,
        'http_code' => $httpCode,
        'error' => $curlError,
        'response' => $responseBody,
    ];
}

function stobeEnsureOmniVoiceVoiceSet(string $endpoint, string $language, array $voices): string {
    if ($language === '') {
        return 'ready';
    }

    $voiceList = [];
    foreach ($voices as $voice) {
        $voice = trim(strval($voice));
        if ($voice !== '' && !in_array($voice, $voiceList, true)) {
            $voiceList[] = $voice;
        }
    }

    $payload = json_encode([
        'language' => $language,
        'scope' => 'voice_set',
        'voices' => $voiceList,
        'make_active' => true,
        'start' => true,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($payload) || $payload === '') {
        return 'failed';
    }

    $result = stobePostJsonToLocalTtsEndpoint($endpoint, $payload, ['/ensure_language'], 'application/json');
    $httpCode = intval($result['http_code'] ?? 0);
    $body = '';
    if (is_string($result['binary'] ?? false) && ($result['binary'] ?? '') !== '') {
        $body = strval($result['binary']);
    } else {
        $body = strval($result['response'] ?? '');
    }
    $decoded = json_decode($body, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || !($decoded['ok'] ?? false)) {
        stobeLogWarn('OmniVoice language readiness check failed', [
            'endpoint' => $endpoint,
            'language' => $language,
            'http_code' => $httpCode,
            'response' => substr($body, 0, 220),
        ]);
        return 'failed';
    }

    $status = strtolower(trim(strval($decoded['status'] ?? '')));
    if ($status === 'ready') {
        return 'ready';
    }
    if (in_array($status, ['building', 'queued', 'running'], true)) {
        stobeLogWarn('OmniVoice voice library is preparing', [
            'endpoint' => $endpoint,
            'language' => $language,
            'voices' => $voiceList,
        ]);
        return 'building';
    }
    return $status !== '' ? $status : 'not_ready';
}

function stobeEstimateWavDurationMs(string $binary): int {
    $length = strlen($binary);
    if ($length < 12) {
        return 0;
    }
    if (substr($binary, 0, 4) !== 'RIFF' || substr($binary, 8, 4) !== 'WAVE') {
        return 0;
    }

    $channels = 0;
    $sampleRate = 0;
    $bitsPerSample = 0;
    $dataSize = 0;

    $chunkPos = 12;
    while ($chunkPos + 8 <= $length) {
        $chunkId = substr($binary, $chunkPos, 4);
        $chunkSizeBytes = substr($binary, $chunkPos + 4, 4);
        if (strlen($chunkSizeBytes) !== 4) {
            break;
        }
        $chunkSize = unpack('V', $chunkSizeBytes)[1] ?? 0;
        $chunkSize = max(0, intval($chunkSize));

        $chunkDataPos = $chunkPos + 8;
        $paddedChunkSize = $chunkSize + ($chunkSize % 2);
        if (($chunkDataPos + $paddedChunkSize) > $length) {
            break;
        }

        if ($chunkId === 'fmt ' && $chunkSize >= 16) {
            $channels = unpack('v', substr($binary, $chunkDataPos + 2, 2))[1] ?? 0;
            $sampleRate = unpack('V', substr($binary, $chunkDataPos + 4, 4))[1] ?? 0;
            $bitsPerSample = unpack('v', substr($binary, $chunkDataPos + 14, 2))[1] ?? 0;
        } elseif ($chunkId === 'data') {
            $dataSize = $chunkSize;
        }

        $chunkPos = $chunkDataPos + $paddedChunkSize;
    }

    if ($channels <= 0 || $sampleRate <= 0 || $bitsPerSample <= 0 || $dataSize <= 0) {
        return 0;
    }

    $bytesPerSecond = $channels * $sampleRate * ($bitsPerSample / 8.0);
    if ($bytesPerSecond <= 0) {
        return 0;
    }

    return intval(round(($dataSize / $bytesPerSecond) * 1000.0));
}

function stobeReadWavDurationMsFromFile(string $path): int {
    if (!is_file($path)) {
        return 0;
    }
    $binary = @file_get_contents($path);
    if (!is_string($binary) || $binary === '') {
        return 0;
    }
    return stobeEstimateWavDurationMs($binary);
}

function stobeSynthesizePocketTtsLine(string $npcName, string $line, array|false $npcData = false, ?string $filterPresetOverride = null): array {
    require_once dirname(__DIR__) . '/lib/stobe_interaction.php';
    stobeInteractionRequire();
    return stobeSynthesizeTtsLine($npcName, $line, $npcData, $filterPresetOverride);
}

function stobeNormalizeTtsConnectorType(string $type): string {
    $normalized = strtolower(trim($type));
    $normalized = str_replace('-', '_', $normalized);
    $normalized = preg_replace('/\s+/', '_', $normalized) ?? $normalized;

    return match ($normalized) {
        'pockettts', 'pocketts', 'pocket_tts' => 'pocket_tts',
        'xtts', 'xtts_fastapi' => 'xtts',
        'higgs' => 'higgs',
        'chatterbox' => 'chatterbox',
        'omnivoice', 'omni_voice', 'omni_tts' => 'omnivoice',
        'cartesia' => 'cartesia',
        'inworld' => 'inworld',
        default => 'pocket_tts',
    };
}

function stobeGetApiBadgeKeyByLabel(string $label): string {
    static $cache = [];
    $cacheKey = strtolower(trim($label));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT api_key
         FROM core_api_badge
         WHERE LOWER(label) = LOWER($1)
         LIMIT 1",
        [$label]
    );
    $value = trim(strval($row['api_key'] ?? ''));
    $cache[$cacheKey] = $value;
    return $value;
}

function stobeGetApiBadgeKeyById(int $id): string {
    if ($id <= 0) {
        return '';
    }
    static $cache = [];
    if (isset($cache[$id])) {
        return $cache[$id];
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $cache[$id] = '';
        return '';
    }

    $row = $db->fetchOne(
        "SELECT api_key
         FROM core_api_badge
         WHERE id = $1
         LIMIT 1",
        [$id]
    );
    $value = trim(strval($row['api_key'] ?? ''));
    $cache[$id] = $value;
    return $value;
}

function stobeResolveConnectorApiKey(array $config, string $provider): string {
    $candidates = [
        $config['api_key'] ?? '',
        $config['apiKey'] ?? '',
        $config['apikey'] ?? '',
        $config['credential'] ?? '',
        $config['api_credential'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $value = trim(strval($candidate));
        if ($value !== '') {
            return $value;
        }
    }

    $apiBadgeId = intval($config['api_badge_id'] ?? 0);
    if ($apiBadgeId > 0) {
        $byId = stobeGetApiBadgeKeyById($apiBadgeId);
        if ($byId !== '') {
            return $byId;
        }
    }

    if ($provider === 'inworld') {
        return stobeGetApiBadgeKeyByLabel('Inworld');
    }
    if ($provider === 'cartesia') {
        return stobeGetApiBadgeKeyByLabel('Cartesia');
    }
    return '';
}

function stobeResolveTtsRuntimeConfig(string $npcName, array|false $npcData = false): array {
    $resolvedNpcData = stobeResolveNpcDataForTts($npcName, $npcData);
    $connector = stobeGetProfileTtsConnectorForNpc($resolvedNpcData);
    if (!$connector) {
        $connector = stobeGetDefaultTtsConnector();
    }
    $connectorConfig = stobePocketTtsDecodeConfig(is_array($connector) ? ($connector['config'] ?? '{}') : '{}');

    $enabled = true;
    if (array_key_exists('enabled', $connectorConfig)) {
        $enabled = in_array(
            strtolower(trim(strval($connectorConfig['enabled']))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    $provider = stobeNormalizeTtsConnectorType(strval(is_array($connector) ? ($connector['connector_type'] ?? '') : ''));
    if (isset($connectorConfig['provider']) && trim(strval($connectorConfig['provider'])) !== '') {
        $provider = stobeNormalizeTtsConnectorType(strval($connectorConfig['provider']));
    }

    $endpoint = trim(strval(is_array($connector) ? ($connector['base_url'] ?? '') : ''));
    if ($endpoint === '') {
        $endpoint = trim(strval($connectorConfig['endpoint'] ?? ''));
    }
    if ($endpoint === '' && in_array($provider, ['pocket_tts', 'xtts', 'chatterbox', 'omnivoice', 'higgs'], true)) {
        $endpoint = match ($provider) {
            'omnivoice' => 'http://127.0.0.1:8021',
            'higgs' => 'http://127.0.0.1:8025',
            'chatterbox' => 'http://127.0.0.1:8023',
            'pocket_tts' => 'http://127.0.0.1:8024',
            default => 'http://127.0.0.1:8020',
        };
    }
    $endpoint = rtrim($endpoint, '/');
    $language = trim(strval($connectorConfig['language'] ?? ''));
    if ($language === '' && $provider !== 'omnivoice') {
        $language = 'en';
    }

    $resolvedGender = trim(strval($resolvedNpcData['gender'] ?? $npcData['gender'] ?? ''));
    $fallbackVoices = stobeResolveConnectorFallbackVoices($connectorConfig);
    $fallbackVoiceId = stobeIsFemaleGender($resolvedGender) ? $fallbackVoices['female'] : $fallbackVoices['male'];
    $voiceId = '';
    $voiceSource = 'hard_default';
    $isNarrator = strcasecmp(trim($npcName), 'The Narrator') === 0;
    if (function_exists('stobeIsNarratorName')) {
        $isNarrator = stobeIsNarratorName($npcName);
    }
    $filterPreset = stobeTtsFilterPresetFromMetadata($resolvedNpcData['metadata'] ?? []);
    if ($isNarrator) {
        $filterPreset = stobeNormalizeTtsFilterPresetId($resolvedNpcData['tts_filter_preset'] ?? 'none');
    }

    $dbVoiceId = stobeResolveNpcVoiceIdByName($npcName);
    if ($dbVoiceId !== '') {
        $voiceId = $dbVoiceId;
        $voiceSource = $isNarrator ? 'narrator_db' : 'npc_db';
    } else {
        $resolvedVoice = trim(strval($resolvedNpcData['voiceid'] ?? ''));
        if ($resolvedVoice !== '') {
            $voiceId = $resolvedVoice;
            $voiceSource = 'npc_payload';
        } else {
            if ($fallbackVoiceId !== '') {
                $voiceId = $fallbackVoiceId;
                $voiceSource = ($fallbackVoices['legacy_voiceid'] !== '' &&
                    !array_key_exists('fallback_male', $connectorConfig) &&
                    !array_key_exists('fallback_female', $connectorConfig))
                    ? 'connector_legacy_default'
                    : 'connector_gender_fallback';
            } else {
                $voiceId = stobeIsFemaleGender($resolvedGender) ? 'female1' : 'male1';
            }
        }
    }

    $settings = stobePocketTtsDefaultSettings();
    foreach (array_keys($settings) as $key) {
        if (!array_key_exists($key, $connectorConfig)) {
            continue;
        }
        $settings[$key] = stobePocketTtsNormalizeSettingValue($key, $connectorConfig[$key]);
    }

    return [
        'enabled' => $enabled,
        'provider' => $provider,
        'connector_id' => intval(is_array($connector) ? ($connector['id'] ?? 0) : 0),
        'connector_name' => strval(is_array($connector) ? ($connector['name'] ?? '') : ''),
        'endpoint' => $endpoint,
        'language' => $language,
        'voiceid' => $voiceId,
        'voiceid_source' => $voiceSource,
        'tts_filter_preset' => $filterPreset,
        'gender' => $resolvedGender,
        'fallback_voiceid' => $fallbackVoiceId,
        'fallback_male' => $fallbackVoices['male'],
        'fallback_female' => $fallbackVoices['female'],
        'settings' => $settings,
        'model_id' => trim(strval($connectorConfig['model_id'] ?? ($connectorConfig['model'] ?? ''))),
        'workspace' => trim(strval($connectorConfig['workspace'] ?? '')),
        'api_key' => stobeResolveConnectorApiKey($connectorConfig, $provider),
        'connector_config' => $connectorConfig,
    ];
}

function stobeConfOptGet(string $id): string {
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT value
         FROM conf_opts
         WHERE id = $1
         LIMIT 1",
        [$id]
    );
    return trim(strval($row['value'] ?? ''));
}

function stobeConfOptSet(string $id, string $value): void {
    $db = $GLOBALS["db"];
    $db->exec(
        "INSERT INTO conf_opts (id, value, updated_at)
         VALUES ($1, $2, NOW())
         ON CONFLICT (id) DO UPDATE SET
             value = EXCLUDED.value,
             updated_at = NOW()",
        [$id, $value]
    );
}

function stobeSanitizeVoiceToken(string $voice): string {
    $token = trim($voice);
    $token = preg_replace('/[^\w\-\.]+/u', '_', $token) ?? $token;
    return trim($token, '_');
}

function stobeResolveVoiceSampleFilenameFromDb(string $voiceId): string {
    $key = strtolower(trim($voiceId));
    if ($key === '') {
        return '';
    }

    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        $cache[$key] = '';
        return '';
    }

    $row = $db->fetchOne(
        "SELECT sample_file
         FROM combined_core_voiceid
         WHERE LOWER(voiceid) = LOWER($1)
         LIMIT 1",
        [$voiceId]
    );
    $sampleFile = basename(trim(strval($row['sample_file'] ?? '')));
    if ($sampleFile === '') {
        $cache[$key] = '';
        return '';
    }

    $cache[$key] = $sampleFile;
    return $sampleFile;
}

function stobeFindVoiceSamplePath(string $voiceId): string {
    $voiceId = trim($voiceId);
    if ($voiceId === '') {
        return '';
    }
    if (is_file($voiceId)) {
        return $voiceId;
    }

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    $voicesDir = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'voices';
    if (!is_dir($voicesDir)) {
        return '';
    }

    // First honor explicit Voice Manager mapping for this voiceid.
    $mappedSample = stobeResolveVoiceSampleFilenameFromDb($voiceId);
    if ($mappedSample !== '') {
        $mappedPath = $voicesDir . DIRECTORY_SEPARATOR . $mappedSample;
        if (is_file($mappedPath)) {
            return $mappedPath;
        }
    }

    $base = pathinfo($voiceId, PATHINFO_FILENAME);
    $base = stobeSanitizeVoiceToken($base);
    $exts = ['wav', 'mp3', 'flac', 'ogg', 'm4a'];
    foreach ($exts as $ext) {
        $candidate = $voicesDir . DIRECTORY_SEPARATOR . $base . '.' . $ext;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    $globMatches = glob($voicesDir . DIRECTORY_SEPARATOR . '*');
    if (is_array($globMatches)) {
        foreach ($globMatches as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }
            if (strtolower(pathinfo($candidate, PATHINFO_FILENAME)) === strtolower($base)) {
                return $candidate;
            }
        }
    }

    return '';
}

function stobeUploadVoiceSampleToLocalEndpoint(string $endpoint, string $samplePath, string $voiceId = ''): bool {
    $uploadSourcePath = $samplePath;
    $tempConvertedPath = '';
    $isRiffWav = stobeIsRiffWavFile($samplePath);

    if (!$isRiffWav) {
        $ffmpegPath = trim(strval(@shell_exec('command -v ffmpeg 2>/dev/null')));
        if ($ffmpegPath === '') {
            stobeLogWarn('Voice sample is not WAV and ffmpeg is unavailable', [
                'sample' => basename($samplePath),
                'voiceid' => trim($voiceId),
            ]);
            return false;
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'stobe_tts_');
        if (!is_string($tmpBase) || $tmpBase === '') {
            stobeLogWarn('Failed to allocate temp file for WAV conversion', [
                'sample' => basename($samplePath),
                'voiceid' => trim($voiceId),
            ]);
            return false;
        }

        @unlink($tmpBase);
        $tempConvertedPath = $tmpBase . '.wav';
        $cmd = escapeshellarg($ffmpegPath)
            . ' -y -i ' . escapeshellarg($samplePath)
            . ' -ac 1 -ar 22050 -f wav '
            . escapeshellarg($tempConvertedPath)
            . ' >/dev/null 2>&1';
        $exitCode = 1;
        @exec($cmd, $unused, $exitCode);
        if ($exitCode !== 0 || !is_file($tempConvertedPath) || intval(@filesize($tempConvertedPath)) <= 44) {
            if ($tempConvertedPath !== '' && is_file($tempConvertedPath)) {
                @unlink($tempConvertedPath);
            }
            stobeLogWarn('Failed to convert voice sample to WAV', [
                'sample' => basename($samplePath),
                'voiceid' => trim($voiceId),
            ]);
            return false;
        }

        $uploadSourcePath = $tempConvertedPath;
    }

    $uploadName = basename($samplePath);
    $voiceToken = stobeSanitizeVoiceToken($voiceId);
    if ($voiceToken !== '') {
        // Pocket-TTS bridge derives clone id from filename and strips only ".wav".
        // Force a stable "<voiceid>.wav" upload name so clone id matches requested voiceid.
        $uploadName = $voiceToken . '.wav';
    }

    $cfile = new CURLFile($uploadSourcePath, 'audio/wav', $uploadName);
    $postFields = ['wavFile' => $cfile, 'force' => 'true'];
    if ($voiceToken !== '') {
        $postFields['speaker_name'] = $voiceToken;
        $postFields['speaker_id'] = $voiceToken;
    }

    $ch = curl_init(rtrim($endpoint, '/') . '/upload_sample');
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'Content-Type: multipart/form-data'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if ($tempConvertedPath !== '' && is_file($tempConvertedPath)) {
        @unlink($tempConvertedPath);
    }

    if ($status >= 200 && $status < 300) {
        return true;
    }

    stobeLogWarn('Voice sample upload failed', [
        'endpoint' => $endpoint,
        'status' => $status,
        'error' => $error,
        'sample' => basename($samplePath),
    ]);
    return false;
}

function stobeMaybeSyncVoiceToLocalProvider(string $provider, string $endpoint, string $voiceId): void {
    if ($endpoint === '' || $voiceId === '') {
        return;
    }
    static $missingVoiceWarned = [];

    $samplePath = stobeFindVoiceSamplePath($voiceId);
    if ($samplePath === '' || !is_file($samplePath)) {
        $warnKey = strtolower(trim($provider . '|' . $voiceId));
        if ($warnKey !== '' && !isset($missingVoiceWarned[$warnKey])) {
            $missingVoiceWarned[$warnKey] = true;
            stobeLogWarn('Local TTS voice sample missing; provider may fallback to default voice', [
                'provider' => $provider,
                'endpoint' => $endpoint,
                'voiceid' => $voiceId,
            ]);
        }
        return;
    }

    $fileHash = md5_file($samplePath);
    if (!is_string($fileHash) || $fileHash === '') {
        return;
    }

    $voiceToken = stobeSanitizeVoiceToken($voiceId);
    if ($voiceToken === '') {
        return;
    }
    $confKeyV2 = 'tts_sync_v2_' . $provider . '_' . md5(strtolower($voiceToken));
    if (stobeConfOptGet($confKeyV2) === $fileHash) {
        return;
    }

    if (stobeUploadVoiceSampleToLocalEndpoint($endpoint, $samplePath, $voiceId)) {
        stobeConfOptSet($confKeyV2, $fileHash);
    }
}

function stobeRecordSpeechCacheForProvider(
    string $npcName,
    string $text,
    string $hash,
    string $audioPath,
    string $provider,
    string $voiceModel,
    int $durationMs
): void {
    $db = $GLOBALS["db"];
    $db->exec(
        "INSERT INTO speech (
            npc_name,
            text_hash,
            text,
            audio_path,
            tts_engine,
            voice_model,
            duration_ms
        ) VALUES (
            $1, $2, $3, $4, $5, $6, $7
        )
        ON CONFLICT (npc_name, text_hash) DO UPDATE SET
            text = EXCLUDED.text,
            audio_path = EXCLUDED.audio_path,
            tts_engine = EXCLUDED.tts_engine,
            voice_model = EXCLUDED.voice_model,
            duration_ms = EXCLUDED.duration_ms",
        [$npcName, $hash, $text, $audioPath, $provider, $voiceModel, max(0, $durationMs)]
    );
}

function stobeBuildWavFromPcm16(string $pcmData, int $sampleRate = 22050, int $channels = 1): string {
    $bitsPerSample = 16;
    $byteRate = intval($sampleRate * $channels * ($bitsPerSample / 8));
    $blockAlign = intval($channels * ($bitsPerSample / 8));
    $dataSize = strlen($pcmData);
    $riffSize = 36 + $dataSize;

    $header = 'RIFF'
        . pack('V', $riffSize)
        . 'WAVE'
        . 'fmt '
        . pack('V', 16)
        . pack('v', 1)
        . pack('v', $channels)
        . pack('V', $sampleRate)
        . pack('V', $byteRate)
        . pack('v', $blockAlign)
        . pack('v', $bitsPerSample)
        . 'data'
        . pack('V', $dataSize);

    return $header . $pcmData;
}

function stobeMapLanguageToInworld(string $langCode): string {
    $map = [
        'en' => 'EN_US',
        'es' => 'ES_ES',
        'fr' => 'FR_FR',
        'de' => 'DE_DE',
        'it' => 'IT_IT',
        'pt' => 'PT_BR',
        'ja' => 'JA_JP',
        'ko' => 'KO_KR',
        'zh' => 'ZH_CN',
        'ru' => 'RU_RU',
        'ar' => 'AR_SA',
        'pl' => 'PL_PL',
        'nl' => 'NL_NL',
        'hi' => 'HI_IN',
        'he' => 'HE_IL',
    ];

    $langCode = trim($langCode);
    if (preg_match('/^[A-Z]{2}_[A-Z]{2}$/', $langCode) === 1) {
        return $langCode;
    }
    return $map[strtolower($langCode)] ?? 'EN_US';
}

function stobeLooksLikeInworldVoiceId(string $voiceId): bool {
    $v = trim($voiceId);
    if ($v === '') {
        return false;
    }
    return str_contains($v, '__') || str_starts_with($v, 'voices/');
}

function stobeLooksLikeCartesiaVoiceId(string $voiceId): bool {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($voiceId)) === 1;
}

function stobeCloudVoiceCacheKey(string $provider, string $voiceToken, int $connectorId = 0): string {
    $provider = strtolower(trim($provider));
    $voiceToken = strtolower(trim($voiceToken));
    return $connectorId > 0
        ? $provider . '_voice_scope_' . $connectorId . '__' . $voiceToken
        : $provider . '_voice_id_' . $voiceToken;
}

function stobeGetScopedCloudVoiceId(string $provider, string $voiceToken, array $runtime): string {
    $connectorId = intval($runtime['connector_id'] ?? 0);
    $scopedKey = stobeCloudVoiceCacheKey($provider, $voiceToken, $connectorId);
    $cached = stobeConfOptGet($scopedKey);
    if ($cached !== '' || $connectorId <= 0) {
        return $cached;
    }
    $legacy = stobeConfOptGet(stobeCloudVoiceCacheKey($provider, $voiceToken, 0));
    if ($legacy !== '') {
        stobeConfOptSet($scopedKey, $legacy);
    }
    return $legacy;
}

function stobeGetOrCreateInworldVoiceId(string $voiceId, array $runtime): string {
    $voiceId = trim($voiceId);
    if ($voiceId === '') {
        return '';
    }
    if (stobeLooksLikeInworldVoiceId($voiceId)) {
        return $voiceId;
    }

    $voiceToken = stobeSanitizeVoiceToken($voiceId);
    if ($voiceToken === '') {
        return '';
    }
    $cacheKey = stobeCloudVoiceCacheKey('inworld', $voiceToken, intval($runtime['connector_id'] ?? 0));
    $cached = stobeGetScopedCloudVoiceId('inworld', $voiceToken, $runtime);
    if ($cached !== '') {
        return $cached;
    }

    $samplePath = stobeFindVoiceSamplePath($voiceId);
    if ($samplePath === '') {
        return $voiceId;
    }

    $apiCredential = trim(strval($runtime['api_key'] ?? ''));
    if ($apiCredential === '') {
        return $voiceId;
    }
    $workspace = trim(strval($runtime['workspace'] ?? ''));
    if ($workspace === '') {
        stobeLogWarn('Inworld voice clone skipped: workspace missing', ['voiceid' => $voiceId]);
        return $voiceId;
    }
    if (!str_starts_with($workspace, 'workspaces/')) {
        $workspace = 'workspaces/' . $workspace;
    }

    $audio = @file_get_contents($samplePath);
    if (!is_string($audio) || $audio === '') {
        return $voiceId;
    }

    $payload = json_encode([
        'displayName' => $voiceToken,
        'langCode' => stobeMapLanguageToInworld(strval($runtime['language'] ?? 'en')),
        'voiceSamples' => [['audioData' => base64_encode($audio)]],
        'description' => 'Stobe cloned voice for ' . $voiceToken,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($payload) || $payload === '') {
        return $voiceId;
    }

    $ch = curl_init('https://api.inworld.ai/voices/v1/' . $workspace . '/voices:clone');
    if ($ch === false) {
        return $voiceId;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $apiCredential, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode(is_string($response) ? $response : '', true);
    $remoteId = trim(strval($decoded['voice']['voiceId'] ?? ''));
    if ($remoteId === '') {
        stobeLogWarn('Inworld voice clone failed', [
            'voiceid' => $voiceId,
            'status' => $status,
            'error' => $error,
        ]);
        return $voiceId;
    }

    stobeConfOptSet($cacheKey, $remoteId);
    return $remoteId;
}

function stobeGetOrCreateCartesiaVoiceId(string $voiceId, array $runtime): string {
    $voiceId = trim($voiceId);
    if ($voiceId === '') {
        return '';
    }
    if (stobeLooksLikeCartesiaVoiceId($voiceId)) {
        return $voiceId;
    }

    $voiceToken = stobeSanitizeVoiceToken($voiceId);
    if ($voiceToken === '') {
        return $voiceId;
    }
    $cacheKey = stobeCloudVoiceCacheKey('cartesia', $voiceToken, intval($runtime['connector_id'] ?? 0));
    $cached = stobeGetScopedCloudVoiceId('cartesia', $voiceToken, $runtime);
    if ($cached !== '') {
        return $cached;
    }

    $samplePath = stobeFindVoiceSamplePath($voiceId);
    if ($samplePath === '') {
        return $voiceId;
    }

    $apiKey = trim(strval($runtime['api_key'] ?? ''));
    if ($apiKey === '') {
        return $voiceId;
    }
    $mime = function_exists('mime_content_type') ? strval(@mime_content_type($samplePath)) : '';
    if ($mime === '') {
        $mime = 'audio/wav';
    }

    $payload = [
        'clip' => new CURLFile($samplePath, $mime, basename($samplePath)),
        'name' => $voiceToken,
        'description' => 'Stobe cloned voice for ' . $voiceToken,
        'language' => strtolower(trim(strval($runtime['language'] ?? 'en'))),
        'mode' => 'similarity',
    ];

    $ch = curl_init('https://api.cartesia.ai/voices/clone');
    if ($ch === false) {
        return $voiceId;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'X-API-Key: ' . $apiKey,
            'Cartesia-Version: 2024-11-13',
            'Content-Type: multipart/form-data',
        ],
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode(is_string($response) ? $response : '', true);
    $remoteId = trim(strval($decoded['id'] ?? ''));
    if ($remoteId === '') {
        stobeLogWarn('Cartesia voice clone failed', [
            'voiceid' => $voiceId,
            'status' => $status,
            'error' => $error,
        ]);
        return $voiceId;
    }

    stobeConfOptSet($cacheKey, $remoteId);
    return $remoteId;
}

function stobeSynthesizeViaLocalProviderCore(
    string $provider,
    string $speechText,
    array $runtime,
    bool $allowPocketTtsPortFallback = true
): string|false {
    $endpoint = trim(strval($runtime['endpoint'] ?? ''));
    if ($endpoint === '') {
        return false;
    }
    $voiceId = trim(strval($runtime['voiceid'] ?? ''));
    if ($voiceId === '') {
        $voiceId = trim(strval($runtime['fallback_voiceid'] ?? 'male1'));
    }
    $language = strtolower(trim(strval($runtime['language'] ?? '')));
    if ($language === '' && $provider !== 'omnivoice') {
        $language = 'en';
    }

    $isAudioCppPocketTts = $provider === 'pocket_tts'
        && stobePocketTtsIsAudioCpp($endpoint);

    if ($provider === 'omnivoice' && $language !== '') {
        $fallbackVoiceId = trim(strval($runtime['fallback_voiceid'] ?? ''));
        if ($fallbackVoiceId === '') {
            $fallbackVoiceId = stobeIsFemaleGender($runtime['gender'] ?? '') ?
                trim(strval($runtime['fallback_female'] ?? 'female1')) :
                trim(strval($runtime['fallback_male'] ?? 'male1'));
        }
        $ensureStatus = stobeEnsureOmniVoiceVoiceSet($endpoint, $language, [$voiceId, $fallbackVoiceId]);
        if ($ensureStatus !== 'ready') {
            return false;
        }
        $languagePayload = json_encode(['language' => $language], JSON_UNESCAPED_UNICODE);
        if (is_string($languagePayload) && $languagePayload !== '') {
            $switchResult = stobePostJsonToLocalTtsEndpoint($endpoint, $languagePayload, ['/active_language'], 'application/json');
            $switchCode = intval($switchResult['http_code'] ?? 0);
            if ($switchCode < 200 || $switchCode >= 300) {
                stobeLogWarn('OmniVoice active language switch failed', [
                    'endpoint' => $endpoint,
                    'language' => $language,
                    'http_code' => $switchCode,
                ]);
                return false;
            }
        }
    } elseif (!$isAudioCppPocketTts) {
        stobePocketTtsApplySettings($provider, $endpoint, $runtime['settings'] ?? stobePocketTtsDefaultSettings());
        stobeMaybeSyncVoiceToLocalProvider($provider, $endpoint, $voiceId);
    }
    stobeLogInfo('Local TTS request prepared', [
        'provider' => $provider,
        'endpoint' => $endpoint,
        'voiceid' => $voiceId,
        'language' => $language,
    ]);

    if ($isAudioCppPocketTts) {
        $modelId = trim(strval($runtime['model_id'] ?? ''));
        if ($modelId === '') {
            $modelId = 'pocket-tts';
        }
        $payloadData = [
            'model' => $modelId,
            'input' => $speechText,
            'language' => $language,
            'response_format' => 'wav',
        ];
        $payload = json_encode(array_merge($payloadData, stobePocketTtsAudioCppVoicePayload($voiceId)), JSON_UNESCAPED_UNICODE);
    } else {
        $payloadData = [
            'text' => $speechText,
            'speaker_wav' => $voiceId,
        ];
        if ($language !== '') {
            $payloadData['language'] = $language;
        }
        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);
    }
    if (!is_string($payload) || $payload === '') {
        return false;
    }

    $pathCandidates = $isAudioCppPocketTts
        ? ['/v1/audio/speech']
        : (in_array($provider, ['xtts', 'chatterbox', 'omnivoice'], true)
            ? ['/tts_to_audio/', '/tts_to_audio']
            : ['/tts_to_audio', '/tts_to_audio/']);
    $requestResult = stobePostJsonToLocalTtsEndpoint($endpoint, $payload, $pathCandidates);
    $binary = $requestResult['binary'] ?? false;
    $httpCode = intval($requestResult['http_code'] ?? 0);
    $curlError = strval($requestResult['error'] ?? '');
    $responseBody = strval($requestResult['response'] ?? '');

    if (!is_string($binary) || $binary === '' || $httpCode < 200 || $httpCode >= 300) {
        if ($provider === 'pocket_tts'
            && $allowPocketTtsPortFallback
            && stobePocketTtsShouldTryFallback($endpoint, $httpCode)) {
            $fallbackEndpoint = stobePocketTtsFindFallbackEndpoint($endpoint);
            if ($fallbackEndpoint !== null) {
                $logEndpoint = preg_replace('#//[^/@]+@#', '//', $endpoint);
                $logFallbackEndpoint = preg_replace('#//[^/@]+@#', '//', $fallbackEndpoint);
                stobeLogWarn('PocketTTS endpoint unavailable; retrying compatible endpoint', [
                    'configured_endpoint' => $logEndpoint,
                    'fallback_endpoint' => $logFallbackEndpoint,
                ]);
                $runtime['endpoint'] = $fallbackEndpoint;
                return stobeSynthesizeViaLocalProviderCore(
                    $provider,
                    $speechText,
                    $runtime,
                    false
                );
            }
        }

        if (in_array($provider, ['xtts', 'omnivoice'], true)) {
            $responseDetail = '';
            if ($responseBody !== '') {
                $decodedError = json_decode($responseBody, true);
                if (is_array($decodedError)) {
                    $responseDetail = trim(strval($decodedError['detail'] ?? $decodedError['error'] ?? ''));
                }
                if ($responseDetail === '') {
                    $responseDetail = trim($responseBody);
                }
            }

            if (
                $responseDetail !== '' &&
                stripos($responseDetail, 'speaker') !== false &&
                stripos($responseDetail, 'not found') !== false
            ) {
                $fallbackVoiceId = trim(strval($runtime['fallback_voiceid'] ?? ''));
                if ($fallbackVoiceId === '') {
                    $fallbackVoiceId = stobeIsFemaleGender($runtime['gender'] ?? '') ?
                        trim(strval($runtime['fallback_female'] ?? 'female1')) :
                        trim(strval($runtime['fallback_male'] ?? 'male1'));
                }
                if (strcasecmp($fallbackVoiceId, $voiceId) !== 0) {
                    $fallbackPayloadData = [
                        'text' => $speechText,
                        'speaker_wav' => $fallbackVoiceId,
                    ];
                    if ($language !== '') {
                        $fallbackPayloadData['language'] = $language;
                    }
                    $fallbackPayload = json_encode($fallbackPayloadData, JSON_UNESCAPED_UNICODE);

                    if (is_string($fallbackPayload) && $fallbackPayload !== '') {
                        stobeLogWarn('Local TTS speaker missing, retrying fallback voice', [
                            'provider' => $provider,
                            'requested_voiceid' => $voiceId,
                            'fallback_voiceid' => $fallbackVoiceId,
                            'endpoint' => $endpoint,
                        ]);
                        $fallbackResult = stobePostJsonToLocalTtsEndpoint($endpoint, $fallbackPayload, $pathCandidates);
                        $fallbackBinary = $fallbackResult['binary'] ?? false;
                        $fallbackCode = intval($fallbackResult['http_code'] ?? 0);
                        $fallbackError = strval($fallbackResult['error'] ?? '');
                        if (is_string($fallbackBinary) && $fallbackBinary !== '' && $fallbackCode >= 200 && $fallbackCode < 300) {
                            return $fallbackBinary;
                        }
                        if (is_string($fallbackBinary) && $fallbackBinary !== '') {
                            $responseBody = $fallbackBinary;
                        } else {
                            $responseBody = strval($fallbackResult['response'] ?? $responseBody);
                        }
                        if ($fallbackCode > 0) {
                            $httpCode = $fallbackCode;
                        }
                        if ($fallbackError !== '') {
                            $curlError = $fallbackError;
                        }
                    }
                }
            }
        }

        stobeLogWarn('Local TTS synthesis failed', [
            'provider' => $provider,
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'error' => $curlError,
            'response' => substr(strval($responseBody), 0, 220),
        ]);
        return false;
    }

    return $binary;
}

if (!function_exists('stobeSynthesizeViaPocketTts')) {
    function stobeSynthesizeViaPocketTts(string $speechText, array &$runtime): string|false {
        return stobeSynthesizeViaLocalProviderCore('pocket_tts', $speechText, $runtime);
    }
}

function stobeSynthesizeTtsLine(string $npcName, string $line, array|false $npcData = false, ?string $filterPresetOverride = null): array {
    $pronunciationRows = stobeTtsPronunciationRows();
    if (!empty($pronunciationRows)) {
        $pronunciationScope = stobeTtsPronunciationSpeakerScope($npcName, $npcData);
        $line = stobeApplyTtsPronunciationDictionary(
            $line,
            $pronunciationRows,
            $pronunciationScope['knowledge_tags'],
            $pronunciationScope['npc_name'],
            $pronunciationScope['race']
        );
    }
    $speechText = stobeNormalizeSpeechTextForTts($line);
    if ($speechText === '') {
        return [];
    }

    $runtime = stobeResolveTtsRuntimeConfig($npcName, $npcData);
    if (!($runtime['enabled'] ?? false)) {
        return [];
    }

    $provider = trim(strval($runtime['provider'] ?? 'pocket_tts'));
    $voiceId = trim(strval($runtime['voiceid'] ?? ''));
    if ($voiceId === '') {
        $voiceId = trim(strval($runtime['fallback_voiceid'] ?? 'male1'));
    }
    $language = strtolower(trim(strval($runtime['language'] ?? '')));
    if ($language === '' && $provider !== 'omnivoice') {
        $language = 'en';
    }
    $modelId = trim(strval($runtime['model_id'] ?? ''));
    $endpoint = trim(strval($runtime['endpoint'] ?? ''));
    $voiceSource = trim(strval($runtime['voiceid_source'] ?? ''));
    $filterPreset = $filterPresetOverride === null
        ? stobeNormalizeTtsFilterPresetId($runtime['tts_filter_preset'] ?? 'none')
        : stobeNormalizeTtsFilterPresetId($filterPresetOverride);

    stobeLogInfo('TTS runtime resolved', [
        'npc_name' => $npcName,
        'provider' => $provider,
        'voiceid' => $voiceId,
        'voiceid_source' => $voiceSource,
        'connector' => strval($runtime['connector_name'] ?? ''),
        'tts_filter_preset' => $filterPreset,
    ]);

    $hashSource = implode('|', [$provider, $endpoint, $voiceId, $language, $modelId, $filterPreset, STOBE_TTS_FILTER_PRESET_VERSION, trim($speechText)]);
    $hash = md5($hashSource);
    $soundCacheDir = stobeEnsureSoundCacheDir();
    $localPath = $soundCacheDir . DIRECTORY_SEPARATOR . $hash . '.wav';
    $relativePath = 'soundcache/' . $hash . '.wav';

    if (is_file($localPath) && filesize($localPath) > 44) {
        $durationMs = stobeReadWavDurationMsFromFile($localPath);
        stobeRecordSpeechCacheForProvider($npcName, $speechText, $hash, $relativePath, $provider, $voiceId, $durationMs);
        stobeLogInfo('TTS cache hit', [
            'npc_name' => $npcName,
            'provider' => $provider,
            'voiceid' => $voiceId,
            'hash' => $hash,
            'duration_ms' => $durationMs,
        ]);
        return ['hash' => $hash, 'audio_path' => $relativePath, 'duration_ms' => $durationMs, 'cached' => true];
    }

    $binary = false;
    if ($provider === 'pocket_tts') {
        $binary = stobeSynthesizeViaPocketTts($speechText, $runtime);
    } elseif ($provider === 'xtts') {
        $binary = stobeSynthesizeViaXtts($speechText, $runtime);
    } elseif ($provider === 'higgs') {
        $binary = stobeSynthesizeViaHiggs($speechText, $runtime);
    } elseif ($provider === 'chatterbox') {
        $binary = stobeSynthesizeViaChatterbox($speechText, $runtime);
    } elseif ($provider === 'omnivoice') {
        $binary = stobeSynthesizeViaOmniVoice($speechText, $runtime);
    } elseif ($provider === 'cartesia') {
        $binary = stobeSynthesizeViaCartesia($speechText, $runtime);
        $voiceId = trim(strval($runtime['voiceid'] ?? $voiceId));
    } elseif ($provider === 'inworld') {
        $binary = stobeSynthesizeViaInworld($speechText, $runtime);
        $voiceId = trim(strval($runtime['voiceid'] ?? $voiceId));
    }

    if (!is_string($binary) || $binary === '') {
        return [];
    }
    if (substr($binary, 0, 4) !== "RIFF") {
        stobeLogWarn('TTS synthesis returned non-wav payload', [
            'provider' => $provider,
            'npc_name' => $npcName,
            'voiceid' => $voiceId,
        ]);
        return [];
    }
    $binary = stobeApplyTtsFilterPresetToWavBinary($binary, $filterPreset);

    $bytesWritten = @file_put_contents($localPath, $binary);
    if ($bytesWritten === false || intval($bytesWritten) <= 44) {
        stobeLogWarn('TTS cache write failed', [
            'provider' => $provider,
            'path' => $localPath,
            'npc_name' => $npcName,
        ]);
        return [];
    }

    $durationMs = stobeEstimateWavDurationMs($binary);
    stobeRecordSpeechCacheForProvider($npcName, $speechText, $hash, $relativePath, $provider, $voiceId, $durationMs);
    stobeLogInfo('TTS audio synthesized', [
        'npc_name' => $npcName,
        'provider' => $provider,
        'voiceid' => $voiceId,
        'hash' => $hash,
        'duration_ms' => $durationMs,
    ]);
    return ['hash' => $hash, 'audio_path' => $relativePath, 'duration_ms' => $durationMs, 'cached' => false];
}

function stobeResolveTtsRuntimeFromConnector(array $connector, string $voiceOverride = ''): array {
    $connectorConfig = stobePocketTtsDecodeConfig($connector['config'] ?? '{}');

    $enabled = true;
    if (array_key_exists('enabled', $connectorConfig)) {
        $enabled = in_array(
            strtolower(trim(strval($connectorConfig['enabled']))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    $provider = stobeNormalizeTtsConnectorType(strval($connector['connector_type'] ?? ''));
    if (isset($connectorConfig['provider']) && trim(strval($connectorConfig['provider'])) !== '') {
        $provider = stobeNormalizeTtsConnectorType(strval($connectorConfig['provider']));
    }

    $endpoint = trim(strval($connector['base_url'] ?? ''));
    if ($endpoint === '') {
        $endpoint = trim(strval($connectorConfig['endpoint'] ?? ''));
    }
    if ($endpoint === '' && in_array($provider, ['pocket_tts', 'xtts', 'chatterbox', 'omnivoice', 'higgs'], true)) {
        $endpoint = match ($provider) {
            'omnivoice' => 'http://127.0.0.1:8021',
            'higgs' => 'http://127.0.0.1:8025',
            'chatterbox' => 'http://127.0.0.1:8023',
            'pocket_tts' => 'http://127.0.0.1:8024',
            default => 'http://127.0.0.1:8020',
        };
    }
    $endpoint = rtrim($endpoint, '/');
    $language = trim(strval($connectorConfig['language'] ?? ''));
    if ($language === '' && $provider !== 'omnivoice') {
        $language = 'en';
    }

    $fallbackVoices = stobeResolveConnectorFallbackVoices($connectorConfig);
    $voiceId = trim($voiceOverride);
    if ($voiceId === '') {
        $voiceId = $fallbackVoices['male'];
    }

    $settings = stobePocketTtsDefaultSettings();
    foreach (array_keys($settings) as $key) {
        if (!array_key_exists($key, $connectorConfig)) {
            continue;
        }
        $settings[$key] = stobePocketTtsNormalizeSettingValue($key, $connectorConfig[$key]);
    }

    return [
        'enabled' => $enabled,
        'provider' => $provider,
        'connector_id' => intval($connector['id'] ?? 0),
        'connector_name' => strval($connector['name'] ?? ''),
        'endpoint' => $endpoint,
        'language' => $language,
        'voiceid' => $voiceId,
        'fallback_voiceid' => $voiceId,
        'fallback_male' => $fallbackVoices['male'],
        'fallback_female' => $fallbackVoices['female'],
        'voiceid_source' => $voiceOverride !== '' ? 'test_override' : 'connector_default',
        'settings' => $settings,
        'model_id' => trim(strval($connectorConfig['model_id'] ?? ($connectorConfig['model'] ?? ''))),
        'workspace' => trim(strval($connectorConfig['workspace'] ?? '')),
        'api_key' => stobeResolveConnectorApiKey($connectorConfig, $provider),
        'connector_config' => $connectorConfig,
    ];
}

function stobeSynthesizeTtsFromConnector(array $connector, string $text, string $voiceOverride = '', string $filterPreset = 'none'): array {
    $speechText = stobeNormalizeSpeechTextForTts($text);
    if ($speechText === '') {
        return [];
    }

    $runtime = stobeResolveTtsRuntimeFromConnector($connector, $voiceOverride);
    if (!($runtime['enabled'] ?? false)) {
        return [];
    }

    $provider = trim(strval($runtime['provider'] ?? 'pocket_tts'));
    $voiceId = trim(strval($runtime['voiceid'] ?? ''));
    if ($voiceId === '') {
        $voiceId = trim(strval($runtime['fallback_voiceid'] ?? 'male1'));
    }
    $language = strtolower(trim(strval($runtime['language'] ?? '')));
    if ($language === '' && $provider !== 'omnivoice') {
        $language = 'en';
    }
    $modelId = trim(strval($runtime['model_id'] ?? ''));
    $endpoint = trim(strval($runtime['endpoint'] ?? ''));
    $filterPreset = stobeNormalizeTtsFilterPresetId($filterPreset);

    $hashSource = implode('|', ['test', $provider, $endpoint, $voiceId, $language, $modelId, $filterPreset, STOBE_TTS_FILTER_PRESET_VERSION, trim($speechText)]);
    $hash = md5($hashSource);
    $soundCacheDir = stobeEnsureSoundCacheDir();
    $localPath = $soundCacheDir . DIRECTORY_SEPARATOR . $hash . '.wav';
    $relativePath = 'soundcache/' . $hash . '.wav';

    if (is_file($localPath) && filesize($localPath) > 44) {
        $durationMs = stobeReadWavDurationMsFromFile($localPath);
        return [
            'hash' => $hash,
            'audio_path' => $relativePath,
            'duration_ms' => $durationMs,
            'cached' => true,
            'provider' => $provider,
            'voiceid' => $voiceId,
            'connector_name' => strval($runtime['connector_name'] ?? ''),
        ];
    }

    $binary = false;
    if ($provider === 'pocket_tts') {
        $binary = stobeSynthesizeViaPocketTts($speechText, $runtime);
    } elseif ($provider === 'xtts') {
        $binary = stobeSynthesizeViaXtts($speechText, $runtime);
    } elseif ($provider === 'higgs') {
        $binary = stobeSynthesizeViaHiggs($speechText, $runtime);
    } elseif ($provider === 'chatterbox') {
        $binary = stobeSynthesizeViaChatterbox($speechText, $runtime);
    } elseif ($provider === 'omnivoice') {
        $binary = stobeSynthesizeViaOmniVoice($speechText, $runtime);
    } elseif ($provider === 'cartesia') {
        $binary = stobeSynthesizeViaCartesia($speechText, $runtime);
        $voiceId = trim(strval($runtime['voiceid'] ?? $voiceId));
    } elseif ($provider === 'inworld') {
        $binary = stobeSynthesizeViaInworld($speechText, $runtime);
        $voiceId = trim(strval($runtime['voiceid'] ?? $voiceId));
    }

    if (!is_string($binary) || $binary === '') {
        return [];
    }
    if (substr($binary, 0, 4) !== 'RIFF') {
        return [];
    }
    $binary = stobeApplyTtsFilterPresetToWavBinary($binary, $filterPreset);

    $bytesWritten = @file_put_contents($localPath, $binary);
    if ($bytesWritten === false || intval($bytesWritten) <= 44) {
        return [];
    }
    $durationMs = stobeEstimateWavDurationMs($binary);
    return [
        'hash' => $hash,
        'audio_path' => $relativePath,
        'duration_ms' => $durationMs,
        'cached' => false,
        'provider' => $provider,
        'voiceid' => $voiceId,
        'connector_name' => strval($runtime['connector_name'] ?? ''),
    ];
}
