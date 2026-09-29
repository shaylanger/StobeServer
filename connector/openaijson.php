<?php
require_once dirname(__DIR__) . '/lib/stobe_interaction.php';


/**
 * OpenAI-compatible LLM connector.
 * Works with OpenRouter, OpenAI, Ollama, LM Studio, etc.
 */

function stobeIsReasoningModel(string $model): bool {
    $modelLower = strtolower($model);
    $needles = [
        'deepseek-r',
        'qwq-32b',
        'qwq-max',
        '-thinking',
        ':thinking',
        '-reasoning',
        'grok-3-mini',
        'sonar-deep-research',
        'r1-1776',
        'o1',
        'o3',
        'o4',
    ];

    foreach ($needles as $needle) {
        if (strpos($modelLower, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function stobeIsOpenAiModel(string $model): bool {
    $modelLower = strtolower(trim($model));
    if ($modelLower === '') {
        return false;
    }

    $openAiPrefixes = ['gpt-', 'o1', 'o3', 'o4', 'chatgpt-'];
    foreach ($openAiPrefixes as $prefix) {
        if (strpos($modelLower, $prefix) === 0) {
            return true;
        }
    }

    return strpos($modelLower, 'openai/') === 0;
}

function stobeNormalizeBooleanFlag(mixed $value, bool $fallback = false): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return floatval($value) !== 0.0;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $fallback;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }
    return $fallback;
}

function stobeDecodeConnectorConfig(mixed $rawConfig): array {
    if (is_array($rawConfig)) {
        return $rawConfig;
    }
    $decoded = json_decode(strval($rawConfig), true);
    return is_array($decoded) ? $decoded : [];
}

/** Reasoning is on when the model name says so or the connector is flagged reasoning_model. */
function stobeConnectorUsesReasoning(string $model, array $connectorConfig): bool {
    return stobeIsReasoningModel($model) || !empty($connectorConfig['reasoning_model']);
}

/**
 * Hidden reasoning shares max_tokens with the visible reply. Reserve headroom so
 * a long think cannot starve the JSON reply (finish_reason=length, empty content).
 */
function stobeReasoningAwareMaxTokens(int $configured, bool $usesReasoning): int {
    return $usesReasoning ? $configured + 1200 : $configured;
}

function stobeDecodeConnectorMetadata(array $connectorConfig): array {
    $metadata = $connectorConfig['metadata'] ?? [];
    if (is_array($metadata)) {
        return $metadata;
    }
    $decoded = json_decode(strval($metadata), true);
    return is_array($decoded) ? $decoded : [];
}

function stobeShouldUseStreamingTransport(array $connectorConfig): bool {
    $metadata = stobeDecodeConnectorMetadata($connectorConfig);
    $useStreaming = !stobeNormalizeBooleanFlag(
        $connectorConfig['disable_streaming'] ?? ($metadata['disable_streaming'] ?? false),
        false
    );

    $extraPayload = stobeParseConnectorExtras($connectorConfig);
    if (array_key_exists('stream', $extraPayload)) {
        $useStreaming = stobeNormalizeBooleanFlag($extraPayload['stream'], $useStreaming);
    }

    return $useStreaming;
}

function stobeResolveStreamFailure(
    string $streamError,
    string $finishReason,
    string $nativeFinishReason = ''
): string {
    $streamError = trim($streamError);
    if ($streamError !== '') {
        return $streamError;
    }

    $normalizedFinishReason = strtolower(trim($finishReason));
    if (in_array($normalizedFinishReason, ['length', 'content_filter', 'error'], true)) {
        return 'finish_reason_' . $normalizedFinishReason;
    }

    $normalizedNativeFinishReason = strtolower(trim($nativeFinishReason));
    if (in_array($normalizedNativeFinishReason, ['max_tokens', 'max_output_tokens', 'length'], true)) {
        return 'native_finish_reason_length';
    }
    if (in_array($normalizedNativeFinishReason, ['content_filter', 'safety', 'blocked', 'error'], true)) {
        return 'native_finish_reason_' . $normalizedNativeFinishReason;
    }

    return '';
}

function stobeExtractStreamError(mixed $errorPayload): string {
    if (is_array($errorPayload)) {
        $error = trim(strval($errorPayload['message'] ?? $errorPayload['code'] ?? 'stream_error'));
    } else {
        $error = trim(strval($errorPayload));
    }

    return $error !== '' ? $error : 'stream_error';
}

function stobeApplyConnectorExtraPayload(array &$payload, array $connectorConfig, bool $streamValue): void {
    $extraPayload = stobeParseConnectorExtras($connectorConfig);
    if (array_key_exists('stream', $extraPayload)) {
        unset($extraPayload['stream']);
    }

    foreach ($extraPayload as $key => $value) {
        $payload[$key] = $value;
    }

    $payload['stream'] = $streamValue;
}

function stobeParseConnectorExtras(mixed $rawConfig): array {
    $config = [];
    if (is_array($rawConfig)) {
        $config = $rawConfig;
    } else {
        $decoded = json_decode(strval($rawConfig), true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    $extras = [];

    $topP = $config['top_p'] ?? null;
    if ($topP !== null && is_numeric($topP)) {
        $extras['top_p'] = max(0.0, min(1.0, floatval($topP)));
    } else {
        $extras['top_p'] = 0.9;
    }

    $topK = $config['top_k'] ?? null;
    if ($topK !== null && is_numeric($topK)) {
        $extras['top_k'] = max(0, intval($topK));
    }

    $repetitionPenalty = $config['repetition_penalty'] ?? null;
    if ($repetitionPenalty !== null && is_numeric($repetitionPenalty)) {
        $extras['repetition_penalty'] = max(0.0, floatval($repetitionPenalty));
    }

    $frequencyPenalty = $config['frequency_penalty'] ?? null;
    if ($frequencyPenalty !== null && is_numeric($frequencyPenalty)) {
        $extras['frequency_penalty'] = floatval($frequencyPenalty);
    }

    $presencePenalty = $config['presence_penalty'] ?? null;
    if ($presencePenalty !== null && is_numeric($presencePenalty)) {
        $extras['presence_penalty'] = floatval($presencePenalty);
    }

    $extraParametersEnabled = true;
    if (array_key_exists('extra_parameters_enabled', $config)) {
        $rawEnabled = $config['extra_parameters_enabled'];
        if (is_bool($rawEnabled)) {
            $extraParametersEnabled = $rawEnabled;
        } elseif (is_numeric($rawEnabled)) {
            $extraParametersEnabled = intval($rawEnabled) !== 0;
        } else {
            $normalizedEnabled = strtolower(trim(strval($rawEnabled)));
            $extraParametersEnabled = in_array($normalizedEnabled, ['1', 'true', 'yes', 'on'], true);
        }
    }
    if ($extraParametersEnabled && isset($config['extra_parameters']) && is_array($config['extra_parameters'])) {
        foreach ($config['extra_parameters'] as $key => $value) {
            $paramName = trim(strval($key));
            if ($paramName === '') {
                continue;
            }
            $extras[$paramName] = $value;
        }
    }

    return $extras;
}

function stobeParseExtraHeaders(array $connectorConfig): array {
    $rawHeaders = $connectorConfig['extra_headers'] ?? ($connectorConfig['headers'] ?? []);
    if (is_string($rawHeaders)) {
        $decoded = json_decode($rawHeaders, true);
        if (is_array($decoded)) {
            $rawHeaders = $decoded;
        }
    }
    if (!is_array($rawHeaders)) {
        return [];
    }

    $parsed = [];
    foreach ($rawHeaders as $key => $value) {
        if (!is_int($key)) {
            $headerName = trim(strval($key));
            $headerValue = trim(strval($value));
            if ($headerName !== '' && $headerValue !== '') {
                $parsed[] = $headerName . ': ' . $headerValue;
            }
            continue;
        }

        $line = trim(strval($value));
        if ($line !== '' && strpos($line, ':') !== false) {
            $parsed[] = $line;
        }
    }

    return $parsed;
}

function stobeBuildLlmRequestHeaders(
    string $apiKey,
    array $connectorConfig,
    string $connectorType,
    bool $forStreaming
): array {
    $headers = ["Content-Type: application/json"];
    if ($forStreaming) {
        $headers[] = "Accept: text/event-stream";
        $headers[] = "Cache-Control: no-cache";
    }

    $disableBrandingHeaders = boolval($connectorConfig['disable_branding_headers'] ?? false);
    if (!$disableBrandingHeaders) {
        $headers[] = "HTTP-Referer: https://dwemerdynamics.com";
        $headers[] = "X-Title: Dwemer Dynamics";
    }

    $player2Key = trim(strval(
        $connectorConfig['player2_game_key'] ?? ($connectorConfig['game_key'] ?? '')
    ));
    if ($connectorType === 'player2json') {
        if ($player2Key === '' && $apiKey !== '') {
            $player2Key = $apiKey;
        }
        if ($player2Key !== '') {
            $headers[] = "player2-game-key: {$player2Key}";
        }
        if (boolval($connectorConfig['force_bearer_auth'] ?? false) && $apiKey !== '') {
            $headers[] = "Authorization: Bearer {$apiKey}";
        }
    } elseif ($apiKey !== '') {
        $headers[] = "Authorization: Bearer {$apiKey}";
    }

    foreach (stobeParseExtraHeaders($connectorConfig) as $headerLine) {
        $headers[] = $headerLine;
    }

    return $headers;
}

function stobeExtractMessageContent(array $choice): string {
    $message = $choice['message'] ?? [];
    $content = $message['content'] ?? null;

    if (is_string($content)) {
        return $content;
    }

    if (is_array($content)) {
        $segments = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $segments[] = $part;
                continue;
            }
            if (is_array($part)) {
                $segmentText = strval($part['text'] ?? '');
                if ($segmentText !== '') {
                    $segments[] = $segmentText;
                }
            }
        }
        return implode('', $segments);
    }

    $reasoningContent = $message['reasoning_content'] ?? null;
    if (is_string($reasoningContent)) {
        return $reasoningContent;
    }

    return '';
}

function stobeExtractDeltaContent(array $choice): string {
    $delta = $choice['delta'] ?? [];
    $content = $delta['content'] ?? null;

    if (is_string($content)) {
        return $content;
    }

    if (is_array($content)) {
        $segments = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $segments[] = $part;
                continue;
            }
            if (is_array($part)) {
                $segmentText = strval($part['text'] ?? '');
                if ($segmentText !== '') {
                    $segments[] = $segmentText;
                }
            }
        }
        return implode('', $segments);
    }

    return '';
}

function stobeRecordLlmAudit(string $npcName, string $model, int $promptTokens, int $completionTokens): void {
    try {
        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }
        $db->exec(
            "INSERT INTO audit_llm (npc_name, model, prompt_tokens, completion_tokens, localts)
             VALUES ($1, $2, $3, $4, $5)",
            [$npcName, $model, $promptTokens, $completionTokens, time()]
        );
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to record LLM audit row', [
            'error' => $exception->getMessage(),
            'model' => $model,
            'npc_name' => $npcName,
        ]);
    }
}

function stobeBuildPromptLogPayload(array $payload): string {
    $wrapped = ['full' => $payload];
    $export = var_export($wrapped, true);
    if (is_string($export) && trim($export) !== '') {
        return $export;
    }
    $fallback = json_encode($wrapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($fallback) ? $fallback : '{}';
}

function stobeBuildHttpRequestLogField(
    string $url,
    string $model,
    array $usage,
    int $httpCode,
    string $connectorType,
    array $meta = []
): string {
    $parts = [];
    $parts[] = $url;
    if ($model !== '') {
        $parts[] = 'model=' . $model;
    }
    if ($connectorType !== '') {
        $parts[] = 'connector=' . $connectorType;
    }
    if ($httpCode > 0) {
        $parts[] = 'http=' . strval($httpCode);
    }
    $requestId = strval($GLOBALS['__stobe_request_id'] ?? '');
    if ($requestId !== '') {
        $parts[] = 'request_id=' . $requestId;
    }
    $eventType = trim(strval($meta['event_type'] ?? ''));
    if ($eventType !== '') {
        $parts[] = 'event_type=' . $eventType;
    }
    $promptTokens = intval($usage['prompt_tokens'] ?? 0);
    $completionTokens = intval($usage['completion_tokens'] ?? 0);
    if ($promptTokens > 0 || $completionTokens > 0) {
        $parts[] = '[prompt_tokens]=' . strval($promptTokens);
        $parts[] = '[completion_tokens]=' . strval($completionTokens);
        $parts[] = '[total_tokens]=' . strval($promptTokens + $completionTokens);
    }
    return implode(' | ', $parts);
}

function stobeRecordLlmResponseLog(string $promptPayload, string $responseText, string $urlField): void {
    try {
        $db = $GLOBALS["db"] ?? null;
        if (!$db) {
            return;
        }
        $db->exec(
            "INSERT INTO log (localts, prompt, response, url)
             VALUES ($1, $2, $3, $4)",
            [time(), $promptPayload, $responseText, $urlField]
        );
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to record LLM response log row', [
            'error' => $exception->getMessage(),
        ]);
    }
}

function stobeGetLlmDebugLogPath(string $filename): string {
    if (function_exists('stobeGetLogPath')) {
        return stobeGetLogPath($filename);
    }
    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    $logDir = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    return $logDir . $filename;
}

function stobeShouldLogContextPayload(): bool {
    if (function_exists('getSettingBool')) {
        // Default ON for debugging parity with Herika-style context tracing.
        return getSettingBool('LOG_CONTEXT_PAYLOAD', true);
    }
    return true;
}

function stobeAppendLlmDebugLog(string $filename, string $label, array $payload): void {
    $logPath = stobeGetLlmDebugLogPath($filename);
    $stamp = date(DATE_ATOM);

    // Keep context logs in Herika-style var_export blocks so multiline XML prompt
    // content remains readable instead of escaped JSON.
    if ($filename === 'context_sent_to_llm.log') {
        if (!stobeShouldLogContextPayload()) {
            return;
        }
        $body = var_export($payload, true);
        if (!is_string($body) || $body === '') {
            $body = "array (\n  '__export_error' => 'context_export_failed',\n)";
        }
        $header = $stamp . "\n=\n";
        if (trim($label) !== '') {
            $header .= "label: " . $label . "\n";
        }
        @file_put_contents($logPath, $header . $body . "\n=\n", FILE_APPEND | LOCK_EX);
        return;
    }

    // Mirror Herika-style output log framing so raw responses are easier to scan.
    if ($filename === 'output_from_llm.log') {
        $header = "\n== " . $stamp . " START";
        if (trim($label) !== '') {
            $header .= " [" . $label . "]";
        }
        $header .= "\n\n";
        $body = var_export($payload, true);
        $footer = "\n\n== " . $stamp . " END\n\n";
        @file_put_contents($logPath, $header . $body . $footer, FILE_APPEND | LOCK_EX);
        return;
    }

    $timestamp = gmdate('Y-m-d H:i:s');
    $line = '[' . $timestamp . '] [INFO] ' . $label;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (is_string($json) && $json !== '') {
        $line .= ' | ' . $json;
    }
    $line .= PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
}

function stobeIsFastLlmEventType(string $eventType): bool {
    $normalized = strtolower(trim($eventType));
    if ($normalized === '') {
        return false;
    }

    $knownFastTypes = [
        'autochat_rewrite',
        'relationship_eval',
        'relationship_llm',
        'relationship_analyze',
        'relationship_batch',
        'relationship_infer',
    ];
    if (in_array($normalized, $knownFastTypes, true)) {
        return true;
    }

    return strpos($normalized, 'fast') !== false || strpos($normalized, 'relationship') === 0;
}

function stobeAppendContextRequestLog(string $label, array $payload, bool $forceFastLog = false): void {
    stobeAppendLlmDebugLog('context_sent_to_llm.log', $label, $payload);
    $eventType = strval($payload['event_type'] ?? '');
    if ($forceFastLog || stobeIsFastLlmEventType($eventType)) {
        stobeAppendLlmDebugLog('context_sent_to_llm_fast.log', $label, $payload);
    }
}

function stobeSerializeAuditPayload(mixed $value, int $maxLen = 24000): string {
    if (is_string($value)) {
        $serialized = $value;
    } else {
        $serialized = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($serialized) || $serialized === '') {
            $serialized = var_export($value, true);
            if (!is_string($serialized) || $serialized === '') {
                $serialized = '';
            }
        }
    }

    if ($maxLen > 0 && strlen($serialized) > $maxLen) {
        $serialized = substr($serialized, 0, $maxLen) . '...';
    }
    return $serialized;
}

function stobeRecordAuditRequest(array $entry): void {
    $requestId = trim(strval($entry['request_id'] ?? ($GLOBALS['__stobe_request_id'] ?? '')));
    $eventType = trim(strval($entry['event_type'] ?? ''));
    $npcName = trim(strval($entry['npc_name'] ?? ''));
    $connectorType = trim(strval($entry['connector_type'] ?? ''));
    $model = trim(strval($entry['model'] ?? ''));
    $url = trim(strval($entry['url'] ?? ''));
    $httpCode = intval($entry['http_code'] ?? 0);
    $durationMs = intval($entry['duration_ms'] ?? 0);
    if ($durationMs < 0) {
        $durationMs = 0;
    }
    $isStream = boolval($entry['is_stream'] ?? false);
    $status = strtolower(trim(strval($entry['status'] ?? 'ok')));
    if (!in_array($status, ['ok', 'error'], true)) {
        $status = 'ok';
    }
    $error = trim(strval($entry['error'] ?? ''));
    $usage = is_array($entry['usage'] ?? null) ? $entry['usage'] : [];
    $promptTokens = intval($usage['prompt_tokens'] ?? 0);
    $completionTokens = intval($usage['completion_tokens'] ?? 0);
    $totalTokens = intval($usage['total_tokens'] ?? ($promptTokens + $completionTokens));
    if ($totalTokens <= 0 && ($promptTokens > 0 || $completionTokens > 0)) {
        $totalTokens = $promptTokens + $completionTokens;
    }

    $requestPayload = stobeSerializeAuditPayload($entry['request_payload'] ?? [], 24000);
    $resultPayload = stobeSerializeAuditPayload($entry['result_payload'] ?? '', 24000);

    try {
        $db = $GLOBALS['db'] ?? null;
        if ($db) {
            $db->exec(
                "INSERT INTO audit_request (
                    localts,
                    request_id,
                    event_type,
                    npc_name,
                    connector,
                    model,
                    url,
                    request,
                    result,
                    http_code,
                    duration_ms,
                    is_stream,
                    prompt_tokens,
                    completion_tokens,
                    total_tokens,
                    status,
                    error
                ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17
                )",
                [
                    time(),
                    $requestId,
                    $eventType,
                    $npcName,
                    $connectorType,
                    $model,
                    $url,
                    $requestPayload,
                    $resultPayload,
                    $httpCode,
                    $durationMs,
                    $isStream,
                    $promptTokens,
                    $completionTokens,
                    $totalTokens,
                    $status,
                    $error,
                ]
            );
        }
    } catch (Throwable $exception) {
        stobeLogWarn('Failed to persist audit_request row', [
            'error' => $exception->getMessage(),
            'request_id' => $requestId,
            'event_type' => $eventType,
            'npc_name' => $npcName,
            'model' => $model,
        ]);
    }

    $filePayload = [
        'request_id' => $requestId,
        'event_type' => $eventType,
        'npc_name' => $npcName,
        'connector_type' => $connectorType,
        'model' => $model,
        'status' => $status,
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'is_stream' => $isStream,
        'usage' => [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ],
        'error' => $error,
        'url' => $url,
        'request' => $requestPayload,
        'result' => $resultPayload,
    ];
    stobeAppendLlmDebugLog('audit_request.log', 'audit_request', $filePayload);
}

function callLLM(array $messages, array $config, array $meta = []): string|false {
    stobeInteractionRequire();
    $apiKey = $config['api_key'] ?? '';
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $model = $config['model'] ?? '';
    $maxTokens = $config['max_tokens'] ?? 2048;
    $temperature = $config['temperature'] ?? 0.8;
    $connectorConfig = stobeDecodeConnectorConfig($config['config'] ?? []);
    $connectorType = strtolower(trim(strval($config['connector_type'] ?? 'openaijson')));

    if ($baseUrl === '') {
        $baseUrl = 'https://openrouter.ai/api/v1';
    }
    if ($model === '') {
        $model = 'openrouter/auto';
    }

    $requestStartedAt = microtime(true);
    $url = "{$baseUrl}/chat/completions";
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'stream' => false,
    ];

    $usesReasoning = stobeConnectorUsesReasoning(strval($model), $connectorConfig);
    $maxTokens = stobeReasoningAwareMaxTokens(intval($maxTokens), $usesReasoning);
    if (stobeIsOpenAiModel($model)) {
        $payload['max_completion_tokens'] = intval($maxTokens);
    } else {
        $payload['max_tokens'] = intval($maxTokens);
    }
    if (is_array($meta['response_format'] ?? null)) {
        $payload['response_format'] = $meta['response_format'];
    }

    stobeApplyConnectorExtraPayload($payload, $connectorConfig, false);

    // NPC dialogue is latency-sensitive. Keep the model/context unchanged, but
    // prefer a stable low-latency provider so GLM's repeated prompt prefix can hit its cache.
    if (
        $connectorType === 'openrouterjson'
        && strtolower(trim(strval($model))) === 'z-ai/glm-5.2'
        && !isset($payload['provider'])
    ) {
        $payload['provider'] = ['order' => ['deepinfra'], 'allow_fallbacks' => true];
    }

    if ($usesReasoning) {
        $payload['reasoning'] = !empty($GLOBALS['STOBE_REASONING_OFF'])
            ? ['enabled' => false, 'exclude' => true]
            : ['exclude' => true];
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson) || $payloadJson === '') {
        stobeLogError('Failed to encode LLM payload', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['error' => 'payload_encode_failed'],
            'http_code' => 0,
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'status' => 'error',
            'error' => 'payload_encode_failed',
        ]);
        return false;
    }

    stobeLogInfo('LLM request dispatch', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'message_count' => count($messages),
        'max_tokens' => intval($maxTokens),
        'response_format' => is_array($meta['response_format'] ?? null)
            ? strval($meta['response_format']['type'] ?? 'custom')
            : '',
    ]);

    $forceFastLog = boolval($meta['__stobe_force_fast_log'] ?? false);
    stobeAppendContextRequestLog('llm_request', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'url' => $url,
        'payload' => $payload,
    ], $forceFastLog);

    $headers = stobeBuildLlmRequestHeaders($apiKey, $connectorConfig, $connectorType, false);

    $timeoutSeconds = function_exists('getSettingInt') ? getSettingInt('HTTP_TIMEOUT', 60) : 60;
    if ($timeoutSeconds < 5) {
        $timeoutSeconds = 5;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
    ]);

    $response = curl_exec($ch);
    stobeInteractionRequire();
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (($httpCode !== 200 || $response === false) && isset($payload['response_format'])) {
        stobeLogWarn('LLM request failed with response_format; retrying without response_format', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
        ]);

        unset($payload['response_format']);
        $retryPayloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($retryPayloadJson) && $retryPayloadJson !== '') {
            stobeAppendContextRequestLog('llm_request_retry_no_response_format', [
                'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
                'event_type' => strval($meta['event_type'] ?? ''),
                'npc_name' => strval($meta['npc_name'] ?? ''),
                'connector_type' => $connectorType,
                'model' => $model,
                'base_url' => $baseUrl,
                'url' => $url,
                'payload' => $payload,
            ], $forceFastLog);

            $retryCh = curl_init($url);
            curl_setopt_array($retryCh, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $retryPayloadJson,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSeconds,
            ]);
            $response = curl_exec($retryCh);
            stobeInteractionRequire();
            $httpCode = curl_getinfo($retryCh, CURLINFO_HTTP_CODE);
            $curlError = curl_error($retryCh);
            curl_close($retryCh);
        }
    }

    if ($httpCode !== 200 || $response === false) {
        $responsePreview = is_string($response) ? substr($response, 0, 400) : '';
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_error', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response' => is_string($response) ? $response : '',
        ]);
        stobeLogError('LLM request failed', [
            'http_code' => $httpCode,
            'base_url' => $baseUrl,
            'model' => $model,
            'curl_error' => $curlError,
            'response_preview' => $responsePreview,
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => [
                'curl_error' => $curlError,
                'response_preview' => $responsePreview,
            ],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'status' => 'error',
            'error' => $curlError !== '' ? $curlError : 'http_error',
        ]);
        return false;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_invalid_json', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'response' => $response,
        ]);
        stobeLogError('LLM response was not valid JSON', [
            'model' => $model,
            'response_preview' => substr($response, 0, 400),
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['response_preview' => substr($response, 0, 1000)],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'status' => 'error',
            'error' => 'invalid_json_response',
        ]);
        return false;
    }

    $usage = $data['usage'] ?? [];
    $promptTokens = intval($usage['prompt_tokens'] ?? 0);
    $completionTokens = intval($usage['completion_tokens'] ?? 0);
    stobeRecordLlmAudit(
        strval($meta['npc_name'] ?? ''),
        $model,
        $promptTokens,
        $completionTokens
    );

    $choices = $data['choices'] ?? [];
    if (empty($choices)) {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_no_choices', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'response_json' => $data,
        ]);
        stobeLogWarn('LLM response had no choices', [
            'http_code' => $httpCode,
            'model' => $model,
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['response_json' => $data],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'usage' => is_array($usage) ? $usage : [],
            'status' => 'error',
            'error' => 'no_choices',
        ]);
        return false;
    }

    $content = stobeExtractMessageContent($choices[0]);

    if (trim($content) === '') {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response_empty_content', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'response_json' => $data,
        ]);
        stobeLogWarn('LLM content was empty after parse', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['response_json' => $data],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => false,
            'usage' => is_array($usage) ? $usage : [],
            'status' => 'error',
            'error' => 'empty_content',
        ]);
        return false;
    }

    stobeAppendLlmDebugLog('output_from_llm.log', 'llm_response', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'usage' => is_array($usage) ? $usage : [],
        'response_text' => $content,
        'response_json' => $data,
    ]);

    $speaker = trim(strval($meta['npc_name'] ?? ''));
    $loggedResponseText = $speaker !== '' ? ($speaker . ': ' . $content) : $content;
    stobeRecordLlmResponseLog(
        stobeBuildPromptLogPayload($payload),
        $loggedResponseText,
        stobeBuildHttpRequestLogField($url, $model, is_array($usage) ? $usage : [], intval($httpCode), $connectorType, $meta)
    );

    stobeRecordAuditRequest([
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'url' => $url,
        'request_payload' => $payload,
        'result_payload' => ['response_text' => $content],
        'http_code' => intval($httpCode),
        'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
        'is_stream' => false,
        'usage' => is_array($usage) ? $usage : [],
        'status' => 'ok',
    ]);

    return $content;
}

function callLLMStream(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    stobeInteractionRequire();
    $apiKey = $config['api_key'] ?? '';
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $model = $config['model'] ?? '';
    $maxTokens = $config['max_tokens'] ?? 2048;
    $temperature = $config['temperature'] ?? 0.8;
    $connectorConfig = stobeDecodeConnectorConfig($config['config'] ?? []);
    $connectorType = strtolower(trim(strval($config['connector_type'] ?? 'openaijson')));
    if (!stobeShouldUseStreamingTransport($connectorConfig)) {
        $fallbackConfig = $config;
        $fallbackConfig['config'] = $connectorConfig;
        $fullResponse = callLLM($messages, $fallbackConfig, $meta);
        if (is_string($fullResponse) && $fullResponse !== '') {
            try {
                $onTextDelta($fullResponse);
            } catch (Throwable $callbackException) {
                stobeLogWarn('LLM stream fallback callback threw exception', [
                    'error' => $callbackException->getMessage(),
                ]);
            }
        }
        return $fullResponse;
    }

    if ($baseUrl === '') {
        $baseUrl = 'https://openrouter.ai/api/v1';
    }
    if ($model === '') {
        $model = 'openrouter/auto';
    }

    $requestStartedAt = microtime(true);
    $url = "{$baseUrl}/chat/completions";
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'stream' => true,
    ];

    $usesReasoning = stobeConnectorUsesReasoning(strval($model), $connectorConfig);
    $maxTokens = stobeReasoningAwareMaxTokens(intval($maxTokens), $usesReasoning);
    if (stobeIsOpenAiModel($model)) {
        $payload['max_completion_tokens'] = intval($maxTokens);
    } else {
        $payload['max_tokens'] = intval($maxTokens);
    }

    if (is_array($meta['response_format'] ?? null)) {
        $payload['response_format'] = $meta['response_format'];
    }

    stobeApplyConnectorExtraPayload($payload, $connectorConfig, true);

    // See non-streaming path above. Keeping the provider stable also improves prompt-cache reuse.
    if (
        $connectorType === 'openrouterjson'
        && strtolower(trim(strval($model))) === 'z-ai/glm-5.2'
        && !isset($payload['provider'])
    ) {
        $payload['provider'] = ['order' => ['deepinfra'], 'allow_fallbacks' => true];
    }

    if ($usesReasoning) {
        $payload['reasoning'] = !empty($GLOBALS['STOBE_REASONING_OFF'])
            ? ['enabled' => false, 'exclude' => true]
            : ['exclude' => true];
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson) || $payloadJson === '') {
        stobeLogError('Failed to encode streaming LLM payload', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['error' => 'payload_encode_failed'],
            'http_code' => 0,
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => true,
            'status' => 'error',
            'error' => 'payload_encode_failed',
        ]);
        return false;
    }

    stobeLogInfo('LLM stream request dispatch', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'message_count' => count($messages),
        'max_tokens' => intval($maxTokens),
        'response_format' => is_array($meta['response_format'] ?? null)
            ? strval($meta['response_format']['type'] ?? 'custom')
            : '',
    ]);

    $forceFastLog = boolval($meta['__stobe_force_fast_log'] ?? false);
    stobeAppendContextRequestLog('llm_stream_request', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'base_url' => $baseUrl,
        'url' => $url,
        'payload' => $payload,
    ], $forceFastLog);
    $headers = stobeBuildLlmRequestHeaders($apiKey, $connectorConfig, $connectorType, true);

    $timeoutSeconds = function_exists('getSettingInt') ? getSettingInt('HTTP_TIMEOUT', 60) : 60;
    if ($timeoutSeconds < 15) {
        $timeoutSeconds = 15;
    }

    $streamBuffer = '';
    $rawStream = '';
    $fullContent = '';
    $usage = [];
    $streamDone = false;
    $streamError = '';
    $finishReason = '';
    $nativeFinishReason = '';
    $writeCallback = function ($curlHandle, string $data) use (
        &$streamBuffer,
        &$rawStream,
        &$fullContent,
        &$usage,
        &$streamDone,
        &$streamError,
        &$finishReason,
        &$nativeFinishReason,
        $onTextDelta
    ): int {
        if (!stobeInteractionAllowed()) return 0;
        $rawStream .= $data;
        $streamBuffer .= $data;

        while (true) {
            $newlinePos = strpos($streamBuffer, "\n");
            if ($newlinePos === false) {
                break;
            }

            $line = substr($streamBuffer, 0, $newlinePos);
            $streamBuffer = substr($streamBuffer, $newlinePos + 1);
            $line = trim(strval($line), "\r\n");
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'data:') !== 0) {
                continue;
            }

            $chunkPayload = trim(substr($line, 5));
            if ($chunkPayload === '' || $chunkPayload === '[DONE]') {
                if ($chunkPayload === '[DONE]') {
                    $streamDone = true;
                }
                continue;
            }

            $chunkData = json_decode($chunkPayload, true);
            if (!is_array($chunkData)) {
                continue;
            }

            if (is_array($chunkData['usage'] ?? null)) {
                $usage = $chunkData['usage'];
            }

            if (array_key_exists('error', $chunkData)) {
                $streamError = stobeExtractStreamError($chunkData['error']);
            }

            $choices = $chunkData['choices'] ?? [];
            if (!is_array($choices) || count($choices) === 0 || !is_array($choices[0] ?? null)) {
                continue;
            }

            $choice = $choices[0];
            $chunkFinishReason = trim(strval($choice['finish_reason'] ?? ''));
            if ($chunkFinishReason !== '') {
                $finishReason = $chunkFinishReason;
            }
            $chunkNativeFinishReason = trim(strval($choice['native_finish_reason'] ?? ''));
            if ($chunkNativeFinishReason !== '') {
                $nativeFinishReason = $chunkNativeFinishReason;
            }

            $deltaText = stobeExtractDeltaContent($choice);
            if ($deltaText === '') {
                $messageContent = stobeExtractMessageContent($choice);
                if ($messageContent !== '') {
                    $deltaText = $messageContent;
                }
            }
            if ($deltaText === '') {
                continue;
            }

            $fullContent .= $deltaText;
            try {
                $onTextDelta($deltaText);
            } catch (Throwable $callbackException) {
                stobeLogWarn('LLM stream text callback threw exception', [
                    'error' => $callbackException->getMessage(),
                ]);
            }
        }

        return strlen($data);
    };

    $runStreamRequest = static function (string $requestPayloadJson) use (
        $url,
        $headers,
        $timeoutSeconds,
        $writeCallback,
        &$httpCode,
        &$curlError
    ) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestPayloadJson,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_WRITEFUNCTION => $writeCallback,
        ]);

        $execResultLocal = curl_exec($ch);
        stobeInteractionRequire();
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $execResultLocal;
    };

    $httpCode = 0;
    $curlError = '';
    $execResult = $runStreamRequest($payloadJson);

    if (($httpCode !== 200 || $execResult === false) && isset($payload['response_format'])) {
        stobeLogWarn('LLM stream request failed with response_format; retrying without response_format', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
        ]);

        unset($payload['response_format']);
        $retryPayloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($retryPayloadJson) && $retryPayloadJson !== '') {
            stobeAppendContextRequestLog('llm_stream_request_retry_no_response_format', [
                'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
                'event_type' => strval($meta['event_type'] ?? ''),
                'npc_name' => strval($meta['npc_name'] ?? ''),
                'connector_type' => $connectorType,
                'model' => $model,
                'base_url' => $baseUrl,
                'url' => $url,
                'payload' => $payload,
            ], $forceFastLog);

            $streamBuffer = '';
            $rawStream = '';
            $fullContent = '';
            $usage = [];
            $streamDone = false;
            $streamError = '';
            $finishReason = '';
            $nativeFinishReason = '';
            $execResult = $runStreamRequest($retryPayloadJson);
        }
    }

    if ($httpCode !== 200 || $execResult === false) {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_stream_response_error', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'raw_stream_preview' => substr($rawStream, 0, 4000),
        ]);
        stobeLogError('LLM stream request failed', [
            'http_code' => $httpCode,
            'base_url' => $baseUrl,
            'model' => $model,
            'curl_error' => $curlError,
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['raw_stream_preview' => substr($rawStream, 0, 2000)],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => true,
            'status' => 'error',
            'error' => $curlError !== '' ? $curlError : 'http_error',
        ]);
        return false;
    }

    // Fallback: some providers may ignore stream and return a single JSON body.
    if (trim($fullContent) === '' && trim($rawStream) !== '') {
        $maybeJson = json_decode($rawStream, true);
        if (is_array($maybeJson)) {
            if (array_key_exists('error', $maybeJson)) {
                $streamError = stobeExtractStreamError($maybeJson['error']);
            }
            $choices = $maybeJson['choices'] ?? [];
            if (is_array($choices) && count($choices) > 0 && is_array($choices[0] ?? null)) {
                $choice = $choices[0];
                $finishReason = trim(strval($choice['finish_reason'] ?? $finishReason));
                $nativeFinishReason = trim(strval($choice['native_finish_reason'] ?? $nativeFinishReason));
                $fullContent = stobeExtractMessageContent($choice);
                if (trim($fullContent) !== '') {
                    try {
                        $onTextDelta($fullContent);
                    } catch (Throwable $callbackException) {
                        stobeLogWarn('LLM stream fallback callback threw exception', [
                            'error' => $callbackException->getMessage(),
                        ]);
                    }
                }
            }
            if (is_array($maybeJson['usage'] ?? null)) {
                $usage = $maybeJson['usage'];
            }
        }
    }

    $streamFailure = stobeResolveStreamFailure($streamError, $finishReason, $nativeFinishReason);
    if ($streamFailure !== '') {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_stream_response_incomplete', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'finish_reason' => $finishReason,
            'native_finish_reason' => $nativeFinishReason,
            'error' => $streamFailure,
            'response_text' => $fullContent,
        ]);
        stobeLogWarn('LLM stream ended before a complete response', [
            'model' => $model,
            'finish_reason' => $finishReason,
            'native_finish_reason' => $nativeFinishReason,
            'error' => $streamFailure,
        ]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => [
                'response_text' => $fullContent,
                'finish_reason' => $finishReason,
                'native_finish_reason' => $nativeFinishReason,
            ],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => true,
            'usage' => is_array($usage) ? $usage : [],
            'status' => 'error',
            'error' => $streamFailure,
        ]);
        if (empty($meta['__stobe_length_retry'])
            && strpos($streamFailure, 'length') !== false
            && trim($fullContent) === '') {
            // Nothing reached the caller, so one retry with more room cannot duplicate speech.
            $retryConfig = $config;
            $retryConfig['max_tokens'] = max(256, intval($config['max_tokens'] ?? 2048)) * 2;
            $retryMeta = $meta;
            $retryMeta['__stobe_length_retry'] = true;
            stobeLogWarn('LLM stream hit length limit with no content; retrying once', [
                'model' => $model,
                'retry_max_tokens' => $retryConfig['max_tokens'],
            ]);
            return callLLMStream($messages, $retryConfig, $onTextDelta, $retryMeta);
        }
        return false;
    }

    if (trim($fullContent) === '') {
        stobeAppendLlmDebugLog('output_from_llm.log', 'llm_stream_response_empty_content', [
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'stream_done' => $streamDone,
            'raw_stream_preview' => substr($rawStream, 0, 4000),
        ]);
        stobeLogWarn('LLM stream content was empty after parse', ['model' => $model]);
        stobeRecordAuditRequest([
            'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
            'event_type' => strval($meta['event_type'] ?? ''),
            'npc_name' => strval($meta['npc_name'] ?? ''),
            'connector_type' => $connectorType,
            'model' => $model,
            'url' => $url,
            'request_payload' => $payload,
            'result_payload' => ['raw_stream_preview' => substr($rawStream, 0, 2000)],
            'http_code' => intval($httpCode),
            'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
            'is_stream' => true,
            'usage' => is_array($usage) ? $usage : [],
            'status' => 'error',
            'error' => 'empty_content',
        ]);
        return false;
    }

    stobeRecordLlmAudit(
        strval($meta['npc_name'] ?? ''),
        $model,
        intval($usage['prompt_tokens'] ?? 0),
        intval($usage['completion_tokens'] ?? 0)
    );

    stobeAppendLlmDebugLog('output_from_llm.log', 'llm_stream_response', [
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'usage' => is_array($usage) ? $usage : [],
        'finish_reason' => $finishReason,
        'native_finish_reason' => $nativeFinishReason,
        'response_text' => $fullContent,
    ]);

    $speaker = trim(strval($meta['npc_name'] ?? ''));
    $loggedResponseText = $speaker !== '' ? ($speaker . ': ' . $fullContent) : $fullContent;
    stobeRecordLlmResponseLog(
        stobeBuildPromptLogPayload($payload),
        $loggedResponseText,
        stobeBuildHttpRequestLogField($url, $model, is_array($usage) ? $usage : [], intval($httpCode), $connectorType, $meta)
    );

    stobeRecordAuditRequest([
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => strval($meta['event_type'] ?? ''),
        'npc_name' => strval($meta['npc_name'] ?? ''),
        'connector_type' => $connectorType,
        'model' => $model,
        'url' => $url,
        'request_payload' => $payload,
        'result_payload' => [
            'response_text' => $fullContent,
            'finish_reason' => $finishReason,
            'native_finish_reason' => $nativeFinishReason,
        ],
        'http_code' => intval($httpCode),
        'duration_ms' => intval(round((microtime(true) - $requestStartedAt) * 1000)),
        'is_stream' => true,
        'usage' => is_array($usage) ? $usage : [],
        'status' => 'ok',
    ]);

    return $fullContent;
}
