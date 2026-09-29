<?php

/**
 * StobeServer bootstrap.
 * Canonical runtime bootstrap (moved from lib/bootstrap.php).
 *
 * Loads shared libraries, initializes DB handle, and exposes a few
 * compatibility shims used by legacy Herika-style pages.
 */

require_once __DIR__ . '/stobe_interaction.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $enginePath;

if (!isset($GLOBALS['DBDRIVER']) || !is_string($GLOBALS['DBDRIVER']) || $GLOBALS['DBDRIVER'] === '') {
    $GLOBALS['DBDRIVER'] = 'postgresql';
}

if (!function_exists('stobeBootstrapIsRunningTestScript')) {
    function stobeBootstrapIsRunningTestScript(): bool
    {
        $script = str_replace('\\', '/', strval($_SERVER['SCRIPT_FILENAME'] ?? ''));
        return str_starts_with(ltrim($script, '/'), 'tests/')
            || strpos($script, '/tests/') !== false;
    }
}

if (!function_exists('stobeBootstrapAssertSafeTestDatabase')) {
    function stobeBootstrapAssertSafeTestDatabase(): void
    {
        if (!stobeBootstrapIsRunningTestScript()) {
            return;
        }

        $dbName = trim(strval(getenv('STOBE_DB_NAME') ?: 'stobe'));
        $allowLive = strtolower(trim(strval(getenv('STOBE_ALLOW_LIVE_TEST_DB') ?: '')));
        $explicitlyAllowed = in_array($allowLive, ['1', 'true', 'yes', 'on'], true);
        $looksLikeTestDb = preg_match('/(?:^|[_-])(test|tests|ci)(?:$|[_-])/i', $dbName) === 1
            || preg_match('/(?:test|tests|ci)$/i', $dbName) === 1;

        if ($explicitlyAllowed || $looksLikeTestDb) {
            return;
        }

        $message = 'Refusing to run StobeServer tests against database "' . $dbName . '". '
            . 'Set STOBE_DB_NAME to a dedicated test database such as stobe_test, '
            . 'or set STOBE_ALLOW_LIVE_TEST_DB=1 if you intentionally want to risk that database.';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
        }
        throw new RuntimeException($message);
    }
}

stobeBootstrapAssertSafeTestDatabase();

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'postgresql.class.php');
if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof sql)) {
    $GLOBALS['db'] = new sql();
}
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'database_encoding.php');
if (empty($GLOBALS['STOBE_DATABASE_UPGRADE_IN_PROGRESS'])) {
    stobeRequireDatabaseReady($GLOBALS['db']);
}

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'narrator.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'settings.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'server_logger.php');
if (function_exists('stobeConfigurePhpErrorLogging')) {
    stobeConfigurePhpErrorLogging();
}
if (function_exists('stobeRegisterErrorHandlers')) {
    stobeRegisterErrorHandlers();
}
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'background_processor.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'player2_health.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-pockettts.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-xtts.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-chatterbox.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-higgs.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-omnivoice.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-cartesia.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-inworld.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'player_base_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'work_goal_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'task_goal_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'negotiation_phase1.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'negotiation_engine.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'negotiation_voice.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'tts_warmup.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'world_state_runtime.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'memory_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'chat_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'lifelike_npc.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'player_mood_prompts.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'compact_context_history.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'diary_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'middleterm_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'dynamic_profile_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'narrator_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'autonomy_planner_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'autonomy_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_schema.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_storage.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_autosave.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_rollback.php');

if (!function_exists('extract_assignments')) {
    function extract_assignments(string $filePath): array
    {
        // Stobe does not use Herika conf assignment parsing; keep compat API.
        return [];
    }
}

if (!function_exists('convert_gamets2skyrim_long_date2')) {
    function convert_gamets2skyrim_long_date2(mixed $gamets): string
    {
        return stobeGametsDateLabel($gamets);
    }
}

if (!function_exists('gamets2str_format_gregorian_date')) {
    function gamets2str_format_gregorian_date(mixed $gamets, string $format = 'Y-m-d H:i'): string
    {
        $ts = stobeGametsNormalize($gamets);
        if ($ts <= 0) {
            return '';
        }
        return gmdate($format, $ts);
    }
}

