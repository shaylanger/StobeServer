<?php
/**
 * Centralized logger for StobeServer.
 *
 * Log format (Herika-compatible):
 * [YYYY-mm-dd HH:ii:ss] [LEVEL] message | {"context":"..."}
 */

function stobeGetLogDirectory(): string
{
    $enginePath = $GLOBALS["ENGINE_PATH"] ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR);
    $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $logDir = $enginePath . 'log' . DIRECTORY_SEPARATOR;

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    return $logDir;
}

function stobeGetLogPath(string $filename = 'stobeserver.log'): string
{
    return stobeGetLogDirectory() . $filename;
}

function stobeNormalizeLogLevel(string $level): string
{
    $upper = strtoupper(trim($level));
    $allowed = ['TRACE', 'DEBUG', 'INFO', 'WARN', 'ERROR'];
    if (!in_array($upper, $allowed, true)) {
        return 'INFO';
    }
    return $upper;
}

function stobeLimitString(string $value, int $maxLen = 400): string
{
    if (strlen($value) <= $maxLen) {
        return $value;
    }
    return substr($value, 0, $maxLen) . '...';
}

function stobeNormalizeContextValue($value)
{
    if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if (is_string($value)) {
        return stobeLimitString($value);
    }

    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = stobeNormalizeContextValue($item);
        }
        return $normalized;
    }

    if (is_object($value)) {
        if (method_exists($value, '__toString')) {
            return stobeLimitString((string) $value);
        }
        return stobeLimitString(json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[object]');
    }

    return stobeLimitString(strval($value));
}

function stobeBuildLogLine(string $level, string $message, array $context = []): string
{
    $timestamp = gmdate('Y-m-d H:i:s');
    $normalizedLevel = stobeNormalizeLogLevel($level);
    $line = '[' . $timestamp . '] [' . $normalizedLevel . '] ' . trim($message);

    if (!array_key_exists('request_id', $context) && !empty($GLOBALS['__stobe_request_id'])) {
        $context['request_id'] = strval($GLOBALS['__stobe_request_id']);
    }

    if (!empty($context)) {
        $normalized = stobeNormalizeContextValue($context);
        $contextJson = json_encode($normalized, JSON_UNESCAPED_SLASHES);
        if ($contextJson !== false) {
            $line .= ' | ' . $contextJson;
        }
    }

    return $line . PHP_EOL;
}

function stobeAppendLogLine(string $filename, string $line): void
{
    @file_put_contents(stobeGetLogPath($filename), $line, FILE_APPEND | LOCK_EX);
}

function stobeLog(string $level, string $message, array $context = []): void
{
    $line = stobeBuildLogLine($level, $message, $context);
    stobeAppendLogLine('stobeserver.log', $line);
}

function stobeLogImport(string $message, array $context = [], string $level = 'INFO'): void
{
    $line = stobeBuildLogLine($level, $message, $context);
    stobeAppendLogLine('stobe_import.log', $line);
    stobeAppendLogLine('stobeserver.log', $line);
}

function stobeLogRelationship(string $level, string $message, array $context = []): void
{
    $line = stobeBuildLogLine($level, $message, $context);
    stobeAppendLogLine('relationship_worker.log', $line);
}

function stobeLogRelationshipDebug(string $message, array $context = []): void
{
    stobeLogRelationship('DEBUG', $message, $context);
}

function stobeLogRelationshipInfo(string $message, array $context = []): void
{
    stobeLogRelationship('INFO', $message, $context);
}

function stobeLogRelationshipWarn(string $message, array $context = []): void
{
    stobeLogRelationship('WARN', $message, $context);
}

function stobeLogRelationshipError(string $message, array $context = []): void
{
    stobeLogRelationship('ERROR', $message, $context);
}

function stobeLogDebug(string $message, array $context = []): void
{
    stobeLog('DEBUG', $message, $context);
}

function stobeLogInfo(string $message, array $context = []): void
{
    stobeLog('INFO', $message, $context);
}

function stobeLogWarn(string $message, array $context = []): void
{
    stobeLog('WARN', $message, $context);
}

function stobeLogError(string $message, array $context = []): void
{
    stobeLog('ERROR', $message, $context);
}

function stobeLogException(Throwable $exception, string $message = 'Unhandled exception', array $context = []): void
{
    $exceptionContext = [
        'exception_class' => get_class($exception),
        'exception_message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ];
    if (!empty($context)) {
        $exceptionContext['context'] = $context;
    }
    stobeLogError($message, $exceptionContext);
}

function stobeConfigurePhpErrorLogging(): void
{
    $phpErrorPath = stobeGetLogPath('php_error.log');
    ini_set('log_errors', '1');
    ini_set('error_log', $phpErrorPath);
}

function stobeShouldSuppressRequestLifecycleLogs(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $script = strtolower(strval($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') {
        return false;
    }

    if (str_ends_with($script, '/ui/logs.php') || str_ends_with($script, '/health.php')) {
        return true;
    }

    return false;
}

function stobeIsRequestLogSuppressed(): bool
{
    return !empty($GLOBALS['__stobe_request_log_suppressed']);
}

function stobeRegisterErrorHandlers(): void
{
    if (!empty($GLOBALS['__stobe_handlers_registered'])) {
        return;
    }

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        // Rollback must stop even when legacy SQL warnings are suppressed with @.
        if (!empty($GLOBALS['pgr_operation']) && str_contains($message, 'pg_')) $GLOBALS['pgr_sql_failed'] = true;
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $severityMap = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARN',
            E_PARSE => 'ERROR',
            E_NOTICE => 'INFO',
            E_CORE_ERROR => 'ERROR',
            E_CORE_WARNING => 'WARN',
            E_COMPILE_ERROR => 'ERROR',
            E_COMPILE_WARNING => 'WARN',
            E_USER_ERROR => 'ERROR',
            E_USER_WARNING => 'WARN',
            E_USER_NOTICE => 'INFO',
            E_STRICT => 'DEBUG',
            E_RECOVERABLE_ERROR => 'ERROR',
            E_DEPRECATED => 'DEBUG',
            E_USER_DEPRECATED => 'DEBUG',
        ];

        $level = $severityMap[$severity] ?? 'ERROR';
        stobeLog($level, 'PHP runtime error', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]);

        // Keep PHP's default error handling active too.
        return false;
    });

    register_shutdown_function(static function (): void {
        $lastError = error_get_last();
        if (is_array($lastError)) {
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (in_array($lastError['type'] ?? 0, $fatalTypes, true)) {
                stobeLogError('Fatal shutdown error', [
                    'type' => $lastError['type'] ?? null,
                    'message' => $lastError['message'] ?? '',
                    'file' => $lastError['file'] ?? '',
                    'line' => $lastError['line'] ?? 0,
                ]);
            }
        }

        if (isset($GLOBALS['__stobe_request_start']) && is_float($GLOBALS['__stobe_request_start'])) {
            if (stobeIsRequestLogSuppressed()) {
                return;
            }
            $durationMs = (int) round((microtime(true) - $GLOBALS['__stobe_request_start']) * 1000);
            $statusCode = function_exists('http_response_code') ? http_response_code() : 200;
            stobeLogInfo('Request completed', [
                'request_id' => $GLOBALS['__stobe_request_id'] ?? '',
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
            ]);
        }
    });

    $GLOBALS['__stobe_handlers_registered'] = true;
}

function stobeLogRequestStart(): void
{
    $requestId = uniqid('req_', false);
    $GLOBALS['__stobe_request_id'] = $requestId;
    $GLOBALS['__stobe_request_start'] = microtime(true);
    $GLOBALS['__stobe_request_log_suppressed'] = stobeShouldSuppressRequestLifecycleLogs();

    if (PHP_SAPI === 'cli') {
        $argv = $GLOBALS['argv'] ?? [];
        stobeLogInfo('CLI request started', [
            'request_id' => $requestId,
            'script' => $argv[0] ?? '',
            'args' => $argv,
        ]);
        return;
    }

    if (stobeIsRequestLogSuppressed()) {
        return;
    }

    stobeLogInfo('HTTP request started', [
        'request_id' => $requestId,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'script' => $_SERVER['SCRIPT_NAME'] ?? '',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => stobeLimitString($_SERVER['HTTP_USER_AGENT'] ?? '', 180),
    ]);
}
