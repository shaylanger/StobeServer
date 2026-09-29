<?php
// The game uses the same JSON transport as gamedata; browser cross-origin writes are rejected.
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
    || (isset($_SERVER['HTTP_ORIGIN']) && parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST)
        !== explode(':', $_SERVER['HTTP_HOST'] ?? '')[0])) { http_response_code(403); exit; }
$raw = file_get_contents('php://input', false, null, 0, 1025);
$request = json_decode($raw, true);
if (strlen($raw) > 1024 || !is_array($request)
    || (array_key_exists('enabled', $request) && (!is_bool($request['enabled'])
        || !is_int($request['generation'] ?? null)))) { http_response_code(400); exit; }
require_once __DIR__ . '/lib/stobe_interaction.php';
$handle = null;
try {
    $handle = stobeInteractionFile();
    if (!flock($handle, LOCK_EX)) throw new RuntimeException('State lock unavailable');
    $state = stobeInteractionRead($handle);
    if (array_key_exists('enabled', $request)) {
        if ($request['generation'] !== $state['generation']) {
            http_response_code(409);
        } elseif ($request['enabled'] !== $state['enabled']) {
            $state = ['enabled' => $request['enabled'], 'generation' => $state['generation'] + 1];
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (fwrite($handle, $encoded) !== strlen($encoded) || !ftruncate($handle, strlen($encoded)) || !fflush($handle)) {
                throw new RuntimeException('State write failed');
            }
        }
    }
    echo json_encode($state);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"error":"Could not update Stobe. Try again."}';
    error_log('Stobe interaction update failed: ' . $e->getMessage());
} finally { if (is_resource($handle)) fclose($handle); }
