<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function chatJsonFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function chatJsonAssert(bool $condition, string $message): void
{
    if (!$condition) {
        chatJsonFail($message);
    }
}

function chatJsonAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        chatJsonFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function chatJsonAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        chatJsonFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

function chatJsonForceNoApiKey(): void
{
    $db = $GLOBALS['db'];
    $db->exec("UPDATE core_api_badge SET api_key = ''");
    try {
        $db->exec("UPDATE core_llm_connector SET api_key = ''");
    } catch (Throwable $exception) {
        // Older schemas may not persist connector-level API keys.
    }
}

function chatJsonFindFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($socket)) {
        chatJsonFail('failed to allocate local HTTP port: ' . $errstr);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $parts = explode(':', strval($name));
    return intval(end($parts));
}

function chatJsonStartServer(int $port)
{
    $root = dirname(__DIR__);
    $command = [
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $port,
    ];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $root);
    if (!is_resource($process)) {
        chatJsonFail('failed to start PHP built-in server');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $response = chatJsonHttpRequest($port, 'GET', '/chat.php');
        if (intval($response['status']) === 405) {
            return [$process, $pipes];
        }
        usleep(100000);
    }

    $diagnostic = trim(
        strval(stream_get_contents($pipes[1])) . "\n" .
        strval(stream_get_contents($pipes[2]))
    );
    proc_terminate($process);
    chatJsonFail(
        'PHP built-in server did not become ready' .
        ($diagnostic !== '' ? ': ' . $diagnostic : '')
    );
}

function chatJsonStopServer($process, array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

function chatJsonHttpRequest(int $port, string $method, string $path, string $body = '', array $headers = []): array
{
    $headerLines = $headers;
    if ($body !== '' && !array_key_exists('Content-Type', array_change_key_case($headers, CASE_LOWER))) {
        $headerLines[] = 'Content-Type: application/json';
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $url = 'http://127.0.0.1:' . $port . $path;
    $responseBody = file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', strval($header), $match) === 1) {
            $status = intval($match[1]);
            break;
        }
    }
    return [
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'headers' => $responseHeaders,
    ];
}

function chatJsonPostJson(int $port, array $payload): array
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        chatJsonFail('failed to encode JSON payload');
    }
    $response = chatJsonHttpRequest($port, 'POST', '/chat.php', $encoded);
    $decoded = json_decode(strval($response['body']), true);
    $response['json'] = is_array($decoded) ? $decoded : [];
    return $response;
}

function chatJsonEventRows(): array
{
    return $GLOBALS['db']->fetchAll('SELECT type, data, people FROM eventlog ORDER BY rowid ASC');
}

chatJsonForceNoApiKey();
$GLOBALS['db']->exec('DELETE FROM eventlog');

$port = chatJsonFindFreePort();
[$serverProcess, $serverPipes] = chatJsonStartServer($port);

try {
    $methodResponse = chatJsonHttpRequest($port, 'GET', '/chat.php');
    chatJsonAssertSameInt(405, intval($methodResponse['status']), 'chat.php should reject non-POST requests');
    chatJsonAssertSame('POST required', strval(json_decode($methodResponse['body'], true)['error'] ?? ''), 'method rejection should explain the error');

    $invalidResponse = chatJsonHttpRequest($port, 'POST', '/chat.php', '{bad json');
    chatJsonAssertSameInt(400, intval($invalidResponse['status']), 'chat.php should reject malformed JSON');
    chatJsonAssertSame('Invalid JSON payload', strval(json_decode($invalidResponse['body'], true)['error'] ?? ''), 'invalid JSON should explain the error');

    $missingResponse = chatJsonPostJson($port, ['npc' => '', 'message' => '']);
    chatJsonAssertSameInt(400, intval($missingResponse['status']), 'chat.php should reject missing npc/message payloads');
    chatJsonAssertSame('Missing npc or message', strval($missingResponse['json']['error'] ?? ''), 'missing payload response should explain the error');

    $speaker = 'UT_JSON_ENDPOINT_SPEAKER';
    $target = 'UT_JSON_ENDPOINT_TARGET';
    $nearby = 'UT_JSON_ENDPOINT_NEARBY';
    $validResponse = chatJsonPostJson($port, [
        'npc' => '',
        'player' => $speaker,
        'message' => 'Hello from JSON (talking to: ' . $target . ')',
        'mode' => 'invalid-mode',
        'gamets' => 888001,
        'people' => [$speaker . '|1001', $target . '|1002'],
        'context' => [
            'name' => $target,
            'storage_id' => '1002',
            'race' => 'Shek',
            'gender' => 'female',
            'faction' => 'Shek Kingdom',
        ],
        'nearby' => [
            [
                'name' => $nearby,
                'storage_id' => '1003',
                'race' => 'Hive',
                'gender' => 'male',
                'faction' => 'Nomads',
            ],
        ],
    ]);
    chatJsonAssertSameInt(200, intval($validResponse['status']), 'valid chat JSON request should return HTTP 200');
    chatJsonAssert(boolval($validResponse['json']['ok'] ?? false) === true, 'valid chat JSON response should set ok=true');
    chatJsonAssertSame($target, strval($validResponse['json']['npc'] ?? ''), 'inline talking-to target should be used when npc is blank');
    chatJsonAssertSame('talk', strval($validResponse['json']['mode'] ?? ''), 'invalid mode should normalize to talk');
    chatJsonAssertSame('No OpenRouter API key configured yet.', strval($validResponse['json']['text'] ?? ''), 'missing API key should return deterministic fallback text');
    chatJsonAssert(is_array($validResponse['json']['actions'] ?? null) && count($validResponse['json']['actions']) === 0, 'fallback JSON response should not emit actions');

    $rows = chatJsonEventRows();
    chatJsonAssertSameInt(3, count($rows), 'valid JSON request should store input, mirrored chat, and fallback reply');
    chatJsonAssertSame('inputtext', strval($rows[0]['type'] ?? ''), 'first JSON endpoint row should be inputtext');
    chatJsonAssertSame($speaker . ': Hello from JSON (talking to: ' . $target . ')', strval($rows[0]['data'] ?? ''), 'input row should use cleaned message and extracted target');
    chatJsonAssertSame('chat', strval($rows[1]['type'] ?? ''), 'second JSON endpoint row should be mirrored chat');
    chatJsonAssertSame($speaker . ': Hello from JSON (talking to: ' . $target . ')', strval($rows[1]['data'] ?? ''), 'mirrored row should preserve cleaned target payload');
    chatJsonAssertSame('chat', strval($rows[2]['type'] ?? ''), 'third JSON endpoint row should be fallback chat reply');
    chatJsonAssertSame($target . ': No OpenRouter API key configured yet. (talking to: ' . $speaker . ')', strval($rows[2]['data'] ?? ''), 'fallback JSON endpoint reply should be stored');

    foreach ($rows as $row) {
        chatJsonAssertSame('["' . $speaker . '|hand_1001","' . $target . '|hand_1002"]', $row['people'], 'JSON events must retain only the supplied audience');
    }
    chatJsonAssertSameInt(0, count(DataEventLog(10, $nearby)), 'snapshot discovery alone must not grant event awareness');

    chatJsonAssert(is_array(getNpcData($speaker)), 'JSON endpoint should JIT-create speaker participant profile');
    chatJsonAssert(is_array(getNpcData($target)), 'JSON endpoint should JIT-create target participant profile');
    chatJsonAssert(is_array(getNpcData($nearby)), 'JSON endpoint should ingest nearby NPC snapshot profile');

    $GLOBALS['db']->exec('DELETE FROM eventlog');
    $moodResponse = chatJsonPostJson($port, [
        'npc' => $target, 'player' => $speaker, 'message' => 'Good morning',
        'mode' => 'shout', 'player_mood' => 'happy',
    ]);
    chatJsonAssertSameInt(200, intval($moodResponse['status']), 'mood request should succeed');
    $moodRows = chatJsonEventRows();
    chatJsonAssertSame($speaker . ': Good morning [Player tone: speaks in a happy tone.] (talking to: ' . $target . ')', strval($moodRows[0]['data'] ?? ''), 'JSON path should store the mood cue');
    chatJsonAssertSame(strval($moodRows[0]['data']), strval($moodRows[1]['data'] ?? ''), 'mirrored JSON dialogue should retain the mood cue');

    $GLOBALS['db']->exec('DELETE FROM eventlog');
    storeNpcProfile('UT_JSON_DEAD_SPEAKER', []);
    $statePayload = json_encode(['character_state' => 'dead'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $GLOBALS['db']->exec(
        'UPDATE core_npc_master SET metadata = $1::jsonb, extended_data = $1::jsonb WHERE LOWER(name) = LOWER($2)',
        [$statePayload, 'UT_JSON_DEAD_SPEAKER']
    );
    $deadSpeakerResponse = chatJsonPostJson($port, [
        'npc' => 'UT_JSON_ENDPOINT_TARGET',
        'player' => 'UT_JSON_DEAD_SPEAKER',
        'message' => 'I should not be able to speak',
        'gamets' => 888002,
    ]);
    chatJsonAssertSameInt(200, intval($deadSpeakerResponse['status']), 'incapacitated JSON speaker branch should return HTTP 200 with ok=false payload');
    chatJsonAssert(boolval($deadSpeakerResponse['json']['ok'] ?? true) === false, 'incapacitated JSON speaker should return ok=false');
    chatJsonAssertSame('Speaker cannot speak while incapacitated.', strval($deadSpeakerResponse['json']['error'] ?? ''), 'incapacitated JSON speaker should explain the rejection');
    chatJsonAssertSameInt(0, count(chatJsonEventRows()), 'incapacitated JSON speaker should not write conversation rows');
} finally {
    chatJsonStopServer($serverProcess, $serverPipes);
}

echo "All chat JSON endpoint regression tests passed.\n";
