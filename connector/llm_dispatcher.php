<?php

/**
 * LLM connector dispatcher.
 * Routes calls to Herika-style JSON connector adapters while reusing
 * Stobe's OpenAI-compatible transport/runtime implementation.
 */

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'openaijson.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'openrouterjson.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'google_openaijson.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'groqjson.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'koboldcppjson.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'player2json.php');

function stobeNormalizeLlmConnectorType(string $rawType): string {
    $normalized = strtolower(trim($rawType));
    if ($normalized === '') {
        return 'openrouterjson';
    }

    $aliases = [
        'openrouter' => 'openrouterjson',
        'openrouterjson' => 'openrouterjson',
        'openai' => 'openaijson',
        'openaijson' => 'openaijson',
        'custom' => 'openaijson',
        'google' => 'google_openaijson',
        'google_openaijson' => 'google_openaijson',
        'google-openaijson' => 'google_openaijson',
        'groq' => 'groqjson',
        'groqjson' => 'groqjson',
        'koboldcpp' => 'koboldcppjson',
        'koboldcppjson' => 'koboldcppjson',
        'player2' => 'player2json',
        'player2json' => 'player2json',
    ];

    return $aliases[$normalized] ?? 'openaijson';
}

function stobeNormalizeLlmBaseUrl(string $baseUrl): string {
    $value = trim($baseUrl);
    if ($value === '') {
        return '';
    }

    $value = rtrim($value, "/ \t\n\r\0\x0B");
    $value = preg_replace('#/chat/completions$#i', '', $value) ?? $value;
    $value = preg_replace('#/v1/chat$#i', '/v1', $value) ?? $value;
    return rtrim($value, "/ \t\n\r\0\x0B");
}

function stobePrepareLlmRuntimeConfig(array $config): array {
    $runtime = $config;
    $runtime['connector_type'] = stobeNormalizeLlmConnectorType(strval($runtime['connector_type'] ?? ''));
    $runtime['base_url'] = stobeNormalizeLlmBaseUrl(strval($runtime['base_url'] ?? ''));
    return $runtime;
}

function stobeCallLLM(array $messages, array $config, array $meta = []): string|false {
    $runtime = stobePrepareLlmRuntimeConfig($config);
    $connectorType = strval($runtime['connector_type'] ?? 'openaijson');

    return match ($connectorType) {
        'openrouterjson' => stobeCallLLMOpenrouterjson($messages, $runtime, $meta),
        'google_openaijson' => stobeCallLLMGoogleOpenaijson($messages, $runtime, $meta),
        'groqjson' => stobeCallLLMGroqjson($messages, $runtime, $meta),
        'koboldcppjson' => stobeCallLLMKoboldcppjson($messages, $runtime, $meta),
        'player2json' => stobeCallLLMPlayer2json($messages, $runtime, $meta),
        default => callLLM($messages, $runtime, $meta),
    };
}

function stobeCallLLMStream(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    if (is_array($meta['stobe_test_inject'] ?? null) && function_exists('stobeNegTestStreamInjected')) {
        // Test switch NEG_TEST_INJECT (off by default): the model's reply with the injected fields.
        $inject = $meta['stobe_test_inject'];
        unset($meta['stobe_test_inject']);
        return stobeNegTestStreamInjected($inject,
            static fn(callable $collector) => stobeCallLLMStream($messages, $config, $collector, $meta), $onTextDelta);
    }
    unset($meta['stobe_test_inject']);
    $runtime = stobePrepareLlmRuntimeConfig($config);
    $connectorType = strval($runtime['connector_type'] ?? 'openaijson');

    return match ($connectorType) {
        'openrouterjson' => stobeCallLLMStreamOpenrouterjson($messages, $runtime, $onTextDelta, $meta),
        'google_openaijson' => stobeCallLLMStreamGoogleOpenaijson($messages, $runtime, $onTextDelta, $meta),
        'groqjson' => stobeCallLLMStreamGroqjson($messages, $runtime, $onTextDelta, $meta),
        'koboldcppjson' => stobeCallLLMStreamKoboldcppjson($messages, $runtime, $onTextDelta, $meta),
        'player2json' => stobeCallLLMStreamPlayer2json($messages, $runtime, $onTextDelta, $meta),
        default => callLLMStream($messages, $runtime, $onTextDelta, $meta),
    };
}
