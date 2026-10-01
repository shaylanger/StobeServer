<?php
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard();
require_once __DIR__ . '/lib/stobe_interaction.php';
$interactionPacket = base64_decode(strval($_GET['DATA'] ?? ''), true);
$interactionType = explode('|', $interactionPacket ?: '', 2)[0];
if (stobeInteractionIsTrigger($interactionType)) stobeInteractionRequire();

require_once __DIR__ . "/lib/playthrough_guard.php";
pgr_http_preflight("main");

/**
 * StobeServer - Main Entry Point
 * 
 * Kenshi AI NPC backend. Receives game events from the Stobe DLL plugin,
 * processes them through LLM/TTS pipelines, and returns NPC responses.
 *
 * All configuration loaded from general_settings table. No conf.php.
 */

error_reporting(E_ALL);

@define("MAXIMUM_SENTENCE_SIZE", 125);
@define("MINIMUM_SENTENCE_SIZE", 75);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");
if (function_exists('stobeLogRequestStart')) {
    stobeLogRequestStart();
}
stobeLogInfo('Latency stage chat request ingress', [
    'unix_ms' => intval(round(microtime(true) * 1000)),
    'request_elapsed_ms' => 0,
]);
if (!headers_sent() && function_exists('stobeNarratorDisplayNameHeaderValue')) {
    header('X-Narrator-Display-Name: ' . stobeNarratorDisplayNameHeaderValue());
}

$GLOBALS["AVOID_TTS_CACHE"] = true;
$GLOBALS["SEMAPHORES_TIMEOUT"] = 300;

// Parse incoming request
if (php_sapi_name() === "cli") {
    $playerName = getSetting('PLAYER_NAME', 'Drifter');
    $receivedData = "inputtext|" . time() . "|0|{$playerName}: {$argv[1]}";
} else {
    if (strpos($_SERVER["QUERY_STRING"], "&") === false) {
        $receivedData = mb_scrub(base64_decode(rawurldecode(substr($_SERVER["QUERY_STRING"], 5))));
    } else {
        $receivedData = mb_scrub(base64_decode(rawurldecode(substr($_SERVER["QUERY_STRING"], 5, strpos($_SERVER["QUERY_STRING"], "&") - 5))));
    }
}

while (ob_get_length() && ob_end_clean());
ignore_user_abort(true);
set_time_limit(1200);

$GLOBALS["runid"] = uniqid("run_", false);

// First game event after Chatterbox starts: warm its voice pipeline in the background
// so the player's first line does not pay the ~17 s cold start.
if (function_exists('stobeChatterboxWarmupMaybe')) {
    stobeChatterboxWarmupMaybe();
}

if (!function_exists('stobeAppendSttLog')) {
    function stobeAppendSttLog(array $payload): void
    {
        $logPath = function_exists('stobeGetLogPath')
            ? stobeGetLogPath('stt.log')
            : (dirname(__FILE__) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'stt.log');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json) || $json === '') {
            return;
        }
        $line = '[' . gmdate('Y-m-d H:i:s') . '] [INFO] stt_input | ' . $json . PHP_EOL;
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('stobeResolveLatestGametsForInlineMaintenance')) {
    function stobeResolveLatestGametsForInlineMaintenance(int $candidateGamets): int
    {
        $candidate = intval($candidateGamets);
        if ($candidate > 0) {
            return $candidate;
        }

        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return 0;
        }

        try {
            $row = $db->fetchOne("SELECT COALESCE(MAX(gamets), 0) AS max_gamets FROM eventlog");
            return max(0, intval($row['max_gamets'] ?? 0));
        } catch (Throwable $exception) {
            stobeLogException($exception, 'Inline maintenance gamets lookup failed');
            return 0;
        }
    }
}

if (!function_exists('stobeTryInlineMemoryMaintenanceFallback')) {
    function stobeTryInlineMemoryMaintenanceFallback(
        int $timestamp,
        int $gamets,
        string $eventType,
        string $eventData = ''
    ): void {
        if ($gamets <= 0) {
            return;
        }

        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return;
        }

        $lockId = 937469; // dedicated lock for inline fallback maintenance
        $locked = false;
        try {
            $row = $db->fetchOne("SELECT pg_try_advisory_lock($1) AS locked", [$lockId]);
            $raw = $row['locked'] ?? false;
            if (is_bool($raw)) {
                $locked = $raw;
            } elseif (is_numeric($raw)) {
                $locked = (intval($raw) === 1);
            } else {
                $normalized = strtolower(trim(strval($raw)));
                $locked = in_array($normalized, ['t', 'true', '1', 'yes', 'on'], true);
            }
        } catch (Throwable $exception) {
            stobeLogException($exception, 'Inline maintenance lock acquisition failed', [
                'gamets' => $gamets,
                'event_type' => $eventType,
            ]);
            return;
        }

        if (!$locked) {
            return;
        }

        try {
            $tickPayload = '[inline_fallback_tick] ' . trim($eventData);
            if (function_exists('stobeRunMiddleTermDaemonEntrypoint')) {
                stobeRunMiddleTermDaemonEntrypoint($timestamp, $gamets, $tickPayload);
            } elseif (function_exists('stobeMaybeRunMiddleTermCycle')) {
                stobeMaybeRunMiddleTermCycle('middleterm_daemon', $timestamp, $gamets, $tickPayload);
            }

            if (function_exists('stobeMaybeRunRegularMemoryCycle')) {
                // Use manager-equivalent event type so regular-memory allowlist applies.
                stobeMaybeRunRegularMemoryCycle('chat', $timestamp, $gamets, $tickPayload);
            }
            if (function_exists('stobeMaybeRunAutoDiaryCycle')) {
                stobeMaybeRunAutoDiaryCycle($timestamp, $gamets);
            }
        } catch (Throwable $exception) {
            stobeLogException($exception, 'Inline maintenance fallback failed', [
                'gamets' => $gamets,
                'event_type' => $eventType,
            ]);
        } finally {
            try {
                $db->exec("SELECT pg_advisory_unlock($1)", [$lockId]);
            } catch (Throwable $exception) {
                stobeLogException($exception, 'Inline maintenance lock release failed', [
                    'gamets' => $gamets,
                    'event_type' => $eventType,
                ]);
            }
        }
    }
}

// Parse event: type|timestamp|gamets|data
$gameRequest = explode("|", $receivedData);
$eventType = $gameRequest[0] ?? '';
$timestamp = $gameRequest[1] ?? time();
$gamets = $gameRequest[2] ?? 0;
$eventData = $gameRequest[3] ?? '';

if (PHP_SAPI !== 'cli' && strtolower(strval($eventType)) !== 'request') {
    stobePlayer2HealthMarkGameActivity();
}

if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback(intval($gamets), strval($eventType));
}

$incomingPeople = trim((string)($_GET['people'] ?? ''));
if ($incomingPeople === '' &&
    ($eventType === 'inputtext' || $eventType === 'inputtext_s' || $eventType === 'injection')) {
    // Keep people IDs authoritative from client payloads; do not synthesize "|player".
    $incomingPeople = '[]';
}
if (function_exists('stobeRecoverSparsePeopleForCriticalEvent')) {
    $incomingPeople = stobeRecoverSparsePeopleForCriticalEvent(
        strval($eventType),
        strval($eventData),
        strval($incomingPeople)
    );
}
if (function_exists('stobeAnnotatePeopleTokensWithNpcStates')) {
    $incomingPeople = stobeAnnotatePeopleTokensWithNpcStates($incomingPeople);
}

$GLOBALS["CACHE_PEOPLE"] = $incomingPeople;

$speakerName = '';
if ($eventType === 'inputtext' || $eventType === 'inputtext_s' || $eventType === 'injection') {
    $speakerParts = explode(': ', $eventData, 2);
    $speakerName = trim((string)($speakerParts[0] ?? ''));
}

$jitEligibleTypes = ['inputtext', 'inputtext_s', 'injection', 'chat', 'rechat', 'bored', 'infonpc'];
if (in_array($eventType, $jitEligibleTypes, true)) {
    $participantIdentities = extractParticipantIdentities([
        'people' => $incomingPeople,
        'profile' => trim((string)($_GET['profile'] ?? '')),
        'speaker' => $speakerName,
    ]);
    if (count($participantIdentities) > 0) {
        $ensureResult = ensureNpcProfilesFromParticipantIdentities($participantIdentities);
        stobeLogDebug('Participant JIT ensure (stream)', [
            'event_type' => $eventType,
            'participants' => intval($ensureResult['parsed'] ?? 0),
            'created' => intval($ensureResult['created'] ?? 0),
            'updated' => intval($ensureResult['updated'] ?? 0),
        ]);
    }
}

$eventDataPreview = $eventData;
if (strlen($eventDataPreview) > 220) {
    $eventDataPreview = substr($eventDataPreview, 0, 220) . '...';
}

stobeLogInfo('Game event received', [
    'event_type' => $eventType,
    'timestamp' => intval($timestamp),
    'gamets' => intval($gamets),
    'profile' => $_GET['profile'] ?? '',
    'people' => $incomingPeople,
    'data_preview' => $eventDataPreview,
]);

// Urgent world events can interrupt currently-playing casual speech immediately.
if (function_exists('stobeLifelikeSignalForIncomingEvent')) {
    stobeLifelikeSignalForIncomingEvent(strval($eventType), strval($eventData), strval($incomingPeople));
}

if ($eventType === 'inputtext_s') {
    $speaker = '';
    $text = trim(strval($eventData));
    $speakerParts = explode(': ', $text, 2);
    if (count($speakerParts) === 2) {
        $speaker = trim(strval($speakerParts[0]));
        $text = trim(strval($speakerParts[1]));
    }

    stobeAppendSttLog([
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => $eventType,
        'speaker' => $speaker,
        'text' => $text,
        'gamets' => intval($gamets),
        'profile' => strval($_GET['profile'] ?? ''),
    ]);
}

// Auto-diary runs from the background manager / inline maintenance fallback.
// Manual in-game "Write Diary" events still use the direct diary processor below.

// Route event to appropriate processor
try {
    switch ($eventType) {
        case 'inputtext':
        case 'inputtext_s':
        case 'injection':
            require_once($path . "processor/chat.php");
            break;

        case 'rechat':
            require_once($path . "processor/rechat.php");
            break;

        case 'limb_loss':
            // Limb-loss events should trigger an immediate forced victim reaction.
            require_once($path . "processor/rechat.php");
            break;

        case 'location':
            require_once($path . "processor/location.php");
            break;

        case 'infoloc':
            require_once($path . "processor/infoloc.php");
            break;

        case 'infonpc':
            require_once($path . "processor/infonpc.php");
            break;

        case 'action':
        case 'infoaction':
            require_once($path . "processor/infoaction.php");
            break;

        case 'lockpicked':
        case 'lockpiked':
            require_once($path . "processor/lockpicked.php");
            break;

        case 'context':
            require_once($path . "processor/context.php");
            break;

        case 'bored':
            require_once($path . "processor/bored.php");
            break;

        case 'diary':
        case 'diary_narrator':
            require_once($path . "processor/diary.php");
            break;

        case 'init':
            require_once($path . "processor/init.php");
            break;

        case 'combat_start':
        case 'combat_end':
        case 'major_damage':
        case 'knockout':
        case 'recovered':
        case 'death':
        case 'slavery':
        case 'item_pickup':
        case 'chat':
        case 'healing':
        case 'carry':
        case 'predation':
        case 'eat':
        case 'narration':
        case 'trade':
            require_once($path . "processor/combat.php");
            break;

        default:
            storeEvent($eventType, $timestamp, $gamets, $eventData);
            stobeLogWarn('Unhandled event type stored only', ['event_type' => $eventType]);
            echo "ok";
            break;
    }

    // Once the event has been persisted/processed, meaningful aftermath can
    // provoke contextual NPC initiative through Stobe's existing bored-event path.
    if (function_exists('stobeLifelikeSignalForCompletedEvent')) {
        stobeLifelikeSignalForCompletedEvent(strval($eventType), strval($eventData), strval($incomingPeople));
    }
    if (function_exists('stobeNegTickThrottled')) {
        try {
            stobeNegTickThrottled(strval($eventType), strval($eventData), strval($incomingPeople), intval($gamets));
        } catch (Throwable $negError) {
            stobeLogWarn('Negotiation tick failed', ['error' => $negError->getMessage()]);
        }
    }

    // Daemon-style behavior: periodic cycles run in service/manager.php.
    // Fallback: if daemon is down, run memory cycles inline to avoid stalled sync.
    $needsInlineMaintenance = false;
    if (function_exists('stobeBackgroundProcessorNeedsInlineFallback')) {
        $needsInlineMaintenance = stobeBackgroundProcessorNeedsInlineFallback();
    } else {
        $backgroundRunning = true;
        if (function_exists('stobeBackgroundProcessorIsRunning')) {
            $backgroundRunning = stobeBackgroundProcessorIsRunning(0.1);
        }
        $needsInlineMaintenance = !$backgroundRunning;
    }

    if ($needsInlineMaintenance && stobeInteractionAllowed()) {
        $maintenanceGamets = stobeResolveLatestGametsForInlineMaintenance(intval($gamets));
        if ($maintenanceGamets > 0) {
            stobeTryInlineMemoryMaintenanceFallback(
                intval($timestamp),
                $maintenanceGamets,
                strval($eventType),
                strval($eventData)
            );
        }
    }

    if (function_exists('stobeEnsureBackgroundProcessorRunning')) {
        stobeEnsureBackgroundProcessorRunning(true);
    }
} catch (Throwable $exception) {
    stobeLogException($exception, 'Event routing failed', [
        'event_type' => $eventType,
        'gamets' => intval($gamets),
    ]);
    http_response_code(500);
    echo "error";
}

