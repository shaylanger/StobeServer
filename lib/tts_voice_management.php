<?php

function stobeVoiceProviderNormalize(string $provider): string
{
    $provider = strtolower(trim($provider));
    return match ($provider) {
        'pockettts', 'pocket-tts', 'pocket_tts' => 'pocket_tts',
        'xtts', 'xtts-fastapi' => 'xtts',
        'higgs' => 'higgs',
        'chatterbox' => 'chatterbox',
        'omnivoice' => 'omnivoice',
        'cartesia' => 'cartesia',
        'inworld' => 'inworld',
        default => '',
    };
}

function stobeVoiceProviderNormalizeId(string $voiceId): string
{
    $voiceId = preg_replace('/\.wav$/i', '', trim($voiceId)) ?? '';
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $voiceId) === 1 ? $voiceId : '';
}

function stobeVoiceProviderIsAudioCpp(string $provider, string $endpoint): bool
{
    return stobeVoiceProviderNormalize($provider) === 'higgs'
        || stobeVoiceProviderNormalize($provider) === 'pocket_tts'
        && (preg_match('/\:8086(?:\/|$)/', $endpoint) === 1 || str_contains($endpoint, '/v1/audio/speech'));
}

function stobeVoiceProviderTarget(array $connector): array
{
    $config = $connector['config'] ?? [];
    if (!is_array($config)) {
        $decoded = json_decode(strval($config), true);
        $config = is_array($decoded) ? $decoded : [];
    }
    $provider = stobeVoiceProviderNormalize(strval($config['provider'] ?? $connector['connector_type'] ?? ''));
    $endpoint = trim(strval($connector['base_url'] ?? ''));
    if ($endpoint === '') {
        $endpoint = trim(strval($config['endpoint'] ?? ''));
    }
    if ($endpoint === '' && !in_array($provider, ['cartesia', 'inworld'], true) && $provider !== '') {
        $endpoint = match ($provider) {
            'omnivoice' => 'http://127.0.0.1:8021',
            'higgs' => 'http://127.0.0.1:8025',
            'chatterbox' => 'http://127.0.0.1:8023',
            'pocket_tts' => 'http://127.0.0.1:8024',
            default => 'http://127.0.0.1:8020',
        };
    }
    $apiKey = trim(strval($connector['api_badge_key'] ?? ($connector['api_key'] ?? ($config['api_key'] ?? ''))));
    $workspace = trim(strval($config['workspace'] ?? ''));
    $isCloud = in_array($provider, ['cartesia', 'inworld'], true);
    $modelId = trim(strval($config['model_id'] ?? ''));
    if ($modelId === '' && $provider === 'cartesia') {
        $modelId = 'sonic-3';
    } elseif ($modelId === '' && $provider === 'inworld') {
        $modelId = 'inworld-tts-1';
    }
    return [
        'id' => intval($connector['id'] ?? 0),
        'name' => trim(strval($connector['name'] ?? '')),
        'provider' => $provider,
        'endpoint' => rtrim($endpoint, '/'),
        'language' => strtolower(trim(strval($config['language'] ?? ''))),
        'api_key' => $apiKey,
        'workspace' => $workspace,
        'model_id' => $modelId,
        'config' => $config,
        'cloud' => $isCloud,
        'can_manage' => $provider !== ''
            && ($isCloud ? ($apiKey !== '' && ($provider !== 'inworld' || $workspace !== '')) : $endpoint !== '')
            && !stobeVoiceProviderIsAudioCpp($provider, $endpoint),
    ];
}

function stobeVoiceCloudMetadataKey(array $target, string $voiceId): string
{
    return 'tts_cloud_voice_meta_' . strval($target['provider'] ?? '') . '_'
        . intval($target['id'] ?? 0) . '_' . md5(strtolower($voiceId));
}

function stobeVoiceCloudCacheKey(array $target, string $voiceId): string
{
    return stobeCloudVoiceCacheKey(
        strval($target['provider'] ?? ''),
        stobeSanitizeVoiceToken($voiceId),
        intval($target['id'] ?? 0)
    );
}

function stobeVoiceCloudGetMetadata(array $target, string $voiceId): array
{
    $decoded = json_decode(stobeConfOptGet(stobeVoiceCloudMetadataKey($target, $voiceId)), true);
    return is_array($decoded) ? $decoded : [];
}

function stobeVoiceCloudDeleteConfOpt(string $key): void
{
    $GLOBALS['db']->exec('DELETE FROM conf_opts WHERE id = $1', [$key]);
}

function stobeVoiceCloudDeleteRemote(array $target, string $remoteId): array
{
    $provider = strval($target['provider'] ?? '');
    $apiKey = trim(strval($target['api_key'] ?? ''));
    $remoteId = trim($remoteId);
    if ($apiKey === '' || $remoteId === '') {
        return ['success' => false, 'message' => 'Cloud API credential or remote voice ID is missing.'];
    }

    $url = $provider === 'cartesia'
        ? 'https://api.cartesia.ai/voices/' . rawurlencode($remoteId)
        : 'https://api.inworld.ai/voices/v1/voices/' . rawurlencode($remoteId);
    $headers = $provider === 'cartesia'
        ? ['X-API-Key: ' . $apiKey, 'Cartesia-Version: 2026-03-01', 'Accept: application/json']
        : ['Authorization: Basic ' . $apiKey, 'Accept: application/json'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['success' => false, 'message' => 'Delete request failed: ' . $error];
    }
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'message' => stobeVoiceProviderResponseMessage(strval($response), $httpCode),
    ];
}

function stobeVoiceCloudClone(array $target, string $voiceId, string $samplePath): array
{
    $provider = strval($target['provider'] ?? '');
    $apiKey = trim(strval($target['api_key'] ?? ''));
    $voiceToken = stobeSanitizeVoiceToken($voiceId);
    if ($apiKey === '' || $voiceToken === '' || !is_file($samplePath)) {
        return ['success' => false, 'message' => 'A cloud API credential, voice ID, and WAV sample are required.'];
    }

    if ($provider === 'cartesia') {
        $payload = [
            'clip' => new CURLFile($samplePath, 'audio/wav', basename($samplePath)),
            'name' => $voiceToken,
            'description' => 'Stobe managed voice for ' . $voiceToken,
            'language' => strtolower(trim(strval($target['language'] ?? 'en'))) ?: 'en',
            'mode' => 'similarity',
        ];
        $ch = curl_init('https://api.cartesia.ai/voices/clone');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Cartesia-Version: 2026-03-01'],
            CURLOPT_TIMEOUT => 90,
        ]);
    } else {
        $audio = @file_get_contents($samplePath);
        if (!is_string($audio) || $audio === '') {
            return ['success' => false, 'message' => 'Could not read the normalized WAV sample.'];
        }
        $payload = json_encode([
            'displayName' => $voiceToken . '_r' . gmdate('YmdHis'),
            'langCode' => stobeMapLanguageToInworld(strval($target['language'] ?? 'en')),
            'voiceSamples' => [['audioData' => base64_encode($audio)]],
            'description' => 'Stobe managed voice for ' . $voiceToken,
            'tags' => ['stobe-managed'],
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://api.inworld.ai/voices/v1/voices:clone');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 90,
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($response) || $response === '' || $httpCode < 200 || $httpCode >= 300) {
        return ['success' => false, 'message' => $error !== '' ? $error : stobeVoiceProviderResponseMessage(strval($response), $httpCode)];
    }
    $decoded = json_decode($response, true);
    $remoteId = $provider === 'cartesia'
        ? trim(strval($decoded['id'] ?? ''))
        : trim(strval($decoded['voice']['voiceId'] ?? ''));
    return $remoteId !== ''
        ? ['success' => true, 'voice_id' => $remoteId, 'message' => 'Cloud clone created.']
        : ['success' => false, 'message' => 'The cloud provider did not return a voice ID.'];
}

function stobeVoiceCloudValidate(array $target, string $remoteId): bool
{
    $runtime = [
        'provider' => strval($target['provider'] ?? ''),
        'connector_id' => intval($target['id'] ?? 0),
        'voiceid' => $remoteId,
        'api_key' => strval($target['api_key'] ?? ''),
        'workspace' => strval($target['workspace'] ?? ''),
        'language' => strval($target['language'] ?? 'en'),
        'model_id' => strval($target['model_id'] ?? ''),
        'connector_config' => is_array($target['config'] ?? null) ? $target['config'] : [],
    ];
    $audio = ($target['provider'] ?? '') === 'cartesia'
        ? stobeSynthesizeViaCartesia('Voice synchronization test.', $runtime)
        : stobeSynthesizeViaInworld('Voice synchronization test.', $runtime);
    return is_string($audio) && $audio !== '';
}

function stobeVoiceProviderDeleteUrl(array $target, string $voiceId): string
{
    $voiceId = stobeVoiceProviderNormalizeId($voiceId);
    if ($voiceId === '' || empty($target['endpoint'])) {
        return '';
    }
    $url = rtrim(strval($target['endpoint']), '/') . '/voices/' . rawurlencode($voiceId);
    if (($target['provider'] ?? '') === 'omnivoice') {
        $language = strtolower(trim(strval($target['language'] ?? '')));
        if ($language !== '') {
            $url .= '?language=' . rawurlencode($language);
        }
    }
    return $url;
}

function stobeVoiceProviderResponseMessage(string $response, int $httpCode): string
{
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        $messageValue = $decoded['detail'] ?? $decoded['message'] ?? $decoded['error'] ?? '';
        if (is_array($messageValue)) {
            $message = implode(': ', array_filter([
                trim(strval($messageValue['error'] ?? '')),
                trim(strval($messageValue['reason'] ?? '')),
                trim(strval($messageValue['hint'] ?? '')),
            ]));
        } else {
            $message = trim(strval($messageValue));
        }
        if ($message !== '') {
            return $message;
        }
    }
    $response = trim($response);
    return $response !== '' ? $response : 'Provider returned HTTP ' . $httpCode . '.';
}

function stobeVoiceProviderDelete(array $target, string $voiceId): array
{
    if (empty($target['can_manage'])) {
        return ['success' => false, 'message' => !empty($target['cloud'])
            ? 'This cloud connector is missing its API credential or workspace configuration.'
            : 'This connector uses the local WAV directly and has no uploaded provider copy to remove.'];
    }
    if (!empty($target['cloud'])) {
        $metadata = stobeVoiceCloudGetMetadata($target, $voiceId);
        $remoteId = trim(strval($metadata['voice_id'] ?? ''));
        if (empty($metadata['managed']) || $remoteId === '') {
            return ['success' => false, 'message' => 'This cloud voice is not marked as managed by this Stobe installation.'];
        }
        $result = stobeVoiceCloudDeleteRemote($target, $remoteId);
        if ($result['success']) {
            stobeVoiceCloudDeleteConfOpt(stobeVoiceCloudCacheKey($target, $voiceId));
            stobeVoiceCloudDeleteConfOpt(stobeVoiceCloudMetadataKey($target, $voiceId));
        }
        return $result;
    }
    $url = stobeVoiceProviderDeleteUrl($target, $voiceId);
    if ($url === '') {
        return ['success' => false, 'message' => 'Invalid voice ID or connector endpoint.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['success' => false, 'message' => 'Delete request failed: ' . $error];
    }
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'message' => stobeVoiceProviderResponseMessage($response, $httpCode),
    ];
}

function stobeVoiceProviderUpload(array $target, string $voiceId, string $samplePath): array
{
    $voiceId = stobeVoiceProviderNormalizeId($voiceId);
    if (empty($target['can_manage'])) {
        return ['success' => false, 'message' => !empty($target['cloud'])
            ? 'This cloud connector is missing its API credential or workspace configuration.'
            : 'This connector uses the local WAV directly; replacing the local sample is sufficient.'];
    }
    if ($voiceId === '' || !is_file($samplePath) || !is_readable($samplePath)) {
        return ['success' => false, 'message' => 'A valid voice ID and readable local audio sample are required.'];
    }

    $uploadPath = $samplePath;
    $tempConvertedPath = '';
    $header = @file_get_contents($samplePath, false, null, 0, 12);
    $isRiffWav = is_string($header)
        && strlen($header) >= 12
        && substr($header, 0, 4) === 'RIFF'
        && substr($header, 8, 4) === 'WAVE';
    if (!$isRiffWav) {
        $ffmpegPath = trim(strval(@shell_exec('command -v ffmpeg 2>/dev/null')));
        if ($ffmpegPath === '') {
            return ['success' => false, 'message' => 'ffmpeg is required to convert this sample to WAV.'];
        }
        $tempBase = tempnam(sys_get_temp_dir(), 'stobe_voice_provider_');
        if (!is_string($tempBase) || $tempBase === '') {
            return ['success' => false, 'message' => 'Could not allocate a temporary WAV file.'];
        }
        @unlink($tempBase);
        $tempConvertedPath = $tempBase . '.wav';
        $command = escapeshellarg($ffmpegPath)
            . ' -y -i ' . escapeshellarg($samplePath)
            . ' -ac 1 -ar 22050 -f wav ' . escapeshellarg($tempConvertedPath)
            . ' >/dev/null 2>&1';
        $exitCode = 1;
        @exec($command, $unused, $exitCode);
        if ($exitCode !== 0 || !is_file($tempConvertedPath) || intval(@filesize($tempConvertedPath)) <= 44) {
            @unlink($tempConvertedPath);
            return ['success' => false, 'message' => 'Failed to convert the local sample to WAV.'];
        }
        $uploadPath = $tempConvertedPath;
    }

    if (!empty($target['cloud'])) {
        $cacheKey = stobeVoiceCloudCacheKey($target, $voiceId);
        $metadataKey = stobeVoiceCloudMetadataKey($target, $voiceId);
        $oldRemoteId = stobeConfOptGet($cacheKey);
        $oldMetadata = stobeVoiceCloudGetMetadata($target, $voiceId);
        $clone = stobeVoiceCloudClone($target, $voiceId, $uploadPath);
        if (empty($clone['success'])) {
            if ($tempConvertedPath !== '' && is_file($tempConvertedPath)) @unlink($tempConvertedPath);
            return $clone;
        }
        $newRemoteId = trim(strval($clone['voice_id'] ?? ''));
        if (!stobeVoiceCloudValidate($target, $newRemoteId)) {
            stobeVoiceCloudDeleteRemote($target, $newRemoteId);
            if ($tempConvertedPath !== '' && is_file($tempConvertedPath)) @unlink($tempConvertedPath);
            return ['success' => false, 'message' => 'The new cloud clone failed synthesis validation; the existing voice remains active.'];
        }
        stobeConfOptSet($cacheKey, $newRemoteId);
        stobeConfOptSet($metadataKey, json_encode([
            'voice_id' => $newRemoteId,
            'managed' => true,
            'sample_sha256' => hash_file('sha256', $uploadPath),
            'created_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES));
        if (
            $oldRemoteId !== '' &&
            $oldRemoteId !== $newRemoteId &&
            !empty($oldMetadata['managed']) &&
            trim(strval($oldMetadata['voice_id'] ?? '')) === $oldRemoteId
        ) {
            stobeVoiceCloudDeleteRemote($target, $oldRemoteId);
        }
        if ($tempConvertedPath !== '' && is_file($tempConvertedPath)) @unlink($tempConvertedPath);
        return ['success' => true, 'message' => 'Cloud voice rebuilt and validated.', 'voice_id' => $newRemoteId];
    }

    $postFields = [
        'wavFile' => new CURLFile($uploadPath, 'audio/wav', $voiceId . '.wav'),
        'force' => 'true',
        'speaker_name' => $voiceId,
        'speaker_id' => $voiceId,
    ];
    if (($target['provider'] ?? '') === 'omnivoice') {
        $postFields['language'] = strval($target['language'] ?? '');
        $postFields['display_name'] = $voiceId;
    }

    $ch = curl_init(rtrim(strval($target['endpoint']), '/') . '/upload_sample');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'Content-Type: multipart/form-data'],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if ($tempConvertedPath !== '' && is_file($tempConvertedPath)) {
        @unlink($tempConvertedPath);
    }
    if ($response === false) {
        return ['success' => false, 'message' => 'Upload request failed: ' . $error];
    }
    $success = $httpCode >= 200 && $httpCode < 300;
    if ($success && ($target['provider'] ?? '') === 'omnivoice') {
        $decoded = json_decode($response, true);
        $status = is_array($decoded)
            ? strtolower(trim(strval($decoded['import_status'] ?? $decoded['status'] ?? '')))
            : '';
        $success = in_array($status, ['runtime_ready', 'ready', 'ok'], true);
        if (!$success && is_array($decoded)) {
            $transcriptionError = trim(strval($decoded['transcription_error'] ?? ''));
            if ($transcriptionError !== '') {
                return ['success' => false, 'message' => $transcriptionError];
            }
        }
    }
    return [
        'success' => $success,
        'message' => stobeVoiceProviderResponseMessage($response, $httpCode),
    ];
}
