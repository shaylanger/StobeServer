<?php
require_once __DIR__ . '/lib/playthrough_switching.php';
pas_http_guard();
require_once __DIR__ . '/lib/stobe_interaction.php';
stobeInteractionRequire();


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once(__DIR__ . '/lib/bootstrap.php');

// Split long-form diary narration into provider-safe, sentence-aligned requests.
function stobeDiaryAudioChunks(string $text, int $maxCharacters = 240): array {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '' || mb_strlen($text, 'UTF-8') <= $maxCharacters) {
        return $text === '' ? [] : [$text];
    }

    $sentences = preg_split('/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
    $chunks = [];
    $current = '';

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        while (mb_strlen($sentence, 'UTF-8') > $maxCharacters) {
            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }

            $candidate = mb_substr($sentence, 0, $maxCharacters, 'UTF-8');
            $breakAt = mb_strrpos($candidate, ' ', 0, 'UTF-8');
            if ($breakAt === false || $breakAt < intval($maxCharacters * 0.6)) {
                $breakAt = $maxCharacters;
            }
            $chunks[] = trim(mb_substr($sentence, 0, $breakAt, 'UTF-8'));
            $sentence = trim(mb_substr($sentence, $breakAt, null, 'UTF-8'));
        }

        if ($sentence === '') {
            continue;
        }
        $combined = $current === '' ? $sentence : ($current . ' ' . $sentence);
        if (mb_strlen($combined, 'UTF-8') > $maxCharacters) {
            $chunks[] = $current;
            $current = $sentence;
        } else {
            $current = $combined;
        }
    }

    if ($current !== '') {
        $chunks[] = $current;
    }
    return array_values(array_filter($chunks, static fn($chunk) => trim(strval($chunk)) !== ''));
}

// Combine compatible PCM WAV segments without requiring an external media tool.
function stobeCombineDiaryWavSegments(array $paths): string|false {
    $formatChunk = null;
    $audioData = '';

    foreach ($paths as $path) {
        $wav = @file_get_contents($path);
        if (!is_string($wav) || strlen($wav) < 44 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            return false;
        }

        $offset = 12;
        $segmentFormat = null;
        $segmentData = null;
        $wavLength = strlen($wav);
        while ($offset + 8 <= $wavLength) {
            $chunkId = substr($wav, $offset, 4);
            $sizeData = unpack('Vsize', substr($wav, $offset + 4, 4));
            $chunkSize = intval($sizeData['size'] ?? -1);
            $dataOffset = $offset + 8;
            if ($chunkSize < 0 || $dataOffset + $chunkSize > $wavLength) {
                return false;
            }
            if ($chunkId === 'fmt ') {
                $segmentFormat = substr($wav, $dataOffset, $chunkSize);
            } elseif ($chunkId === 'data') {
                $segmentData = substr($wav, $dataOffset, $chunkSize);
            }
            $offset = $dataOffset + $chunkSize + ($chunkSize % 2);
        }

        if (!is_string($segmentFormat) || strlen($segmentFormat) < 16 || !is_string($segmentData)) {
            return false;
        }
        if ($formatChunk === null) {
            $formatChunk = $segmentFormat;
        } elseif ($formatChunk !== $segmentFormat) {
            return false;
        }
        $audioData .= $segmentData;
    }

    if (!is_string($formatChunk) || $formatChunk === '' || $audioData === '') {
        return false;
    }

    $formatSection = 'fmt ' . pack('V', strlen($formatChunk)) . $formatChunk;
    if (strlen($formatChunk) % 2 !== 0) {
        $formatSection .= "\0";
    }
    $dataSection = 'data' . pack('V', strlen($audioData)) . $audioData;
    if (strlen($audioData) % 2 !== 0) {
        $dataSection .= "\0";
    }
    return 'RIFF' . pack('V', 4 + strlen($formatSection) + strlen($dataSection)) . 'WAVE' . $formatSection . $dataSection;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$payload = json_decode(strval(file_get_contents('php://input')), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$rowId = intval($payload['rowid'] ?? 0);
if ($rowId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_diary_entry']);
    exit;
}

$entry = $GLOBALS['db']->fetchOne(
    "SELECT rowid, content, people
     FROM diarylog
     WHERE rowid = $1
     LIMIT 1",
    [$rowId]
);
if (!$entry) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'diary_entry_not_found']);
    exit;
}

$content = trim(html_entity_decode(strip_tags(strval($entry['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$author = normalizeParticipantNameToken(trim(strval($entry['people'] ?? ''), " \t\n\r\0\x0B|"));
if ($content === '' || $author === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $content === '' ? 'empty_diary_entry' : 'missing_diary_author']);
    exit;
}

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stobe-diary-audio-' . md5(strval($rowId)) . '.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'diary_audio_busy']);
    exit;
}
if (!flock($lockHandle, LOCK_EX)) {
    fclose($lockHandle);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'diary_audio_busy']);
    exit;
}

$tts = [];
$segmentCount = 0;
$synthesisError = null;
try {
    $npcData = stobeResolveNpcDataForTts($author);
    $textChunks = stobeDiaryAudioChunks($content);
    $segmentCount = count($textChunks);
    if ($segmentCount < 1) {
        throw new RuntimeException('Diary entry has no readable TTS text.');
    }

    $segments = [];
    foreach ($textChunks as $textChunk) {
        $segment = stobeSynthesizeTtsLine($author, $textChunk, $npcData);
        $segmentHash = trim(strval($segment['hash'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/i', $segmentHash) !== 1) {
            throw new RuntimeException('Diary audio segment synthesis failed.');
        }
        $segments[] = $segment;
    }

    if ($segmentCount === 1) {
        $tts = $segments[0];
    } else {
        $segmentHashes = array_map(static fn($segment) => strval($segment['hash']), $segments);
        $combinedHash = md5('stobe-diary-audio-v2|' . implode('|', $segmentHashes));
        $soundCacheDir = stobeEnsureSoundCacheDir();
        $combinedPath = $soundCacheDir . DIRECTORY_SEPARATOR . $combinedHash . '.wav';
        $wasCached = is_file($combinedPath) && filesize($combinedPath) > 44;

        if (!$wasCached) {
            $segmentPaths = array_map(
                static fn($segment) => $soundCacheDir . DIRECTORY_SEPARATOR . strval($segment['hash']) . '.wav',
                $segments
            );
            $combinedWav = stobeCombineDiaryWavSegments($segmentPaths);
            if (!is_string($combinedWav) || strlen($combinedWav) <= 44) {
                throw new RuntimeException('Diary audio segments could not be combined.');
            }
            if (@file_put_contents($combinedPath, $combinedWav, LOCK_EX) === false) {
                throw new RuntimeException('Combined diary audio could not be cached.');
            }
        }

        $tts = [
            'hash' => $combinedHash,
            'audio_path' => 'soundcache/' . $combinedHash . '.wav',
            'duration_ms' => stobeReadWavDurationMsFromFile($combinedPath),
            'cached' => $wasCached,
        ];
    }
} catch (Throwable $exception) {
    $synthesisError = $exception;
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

if ($synthesisError !== null) {
    http_response_code(503);
    stobeLogException($synthesisError, 'Diary audio synthesis failed');
    echo json_encode(['ok' => false, 'error' => 'tts_unavailable']);
    exit;
}

$hash = trim(strval($tts['hash'] ?? ''));
if (preg_match('/^[a-f0-9]{32}$/i', $hash) !== 1) {
    http_response_code(503);
    stobeLogWarn('Diary audio synthesis failed', [
        'rowid' => $rowId,
        'npc_name' => $author,
    ]);
    echo json_encode(['ok' => false, 'error' => 'tts_unavailable']);
    exit;
}

$scriptPath = str_replace('\\', '/', strval($_SERVER['SCRIPT_NAME'] ?? ''));
$webRoot = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.');
$audioUrl = $webRoot . '/soundcache/' . rawurlencode($hash . '.wav');
$displayAuthor = function_exists('stobeIsNarratorName') && stobeIsNarratorName($author)
    ? stobeNarratorRoleplayName()
    : $author;

stobeLogInfo('Diary audio prepared', [
    'rowid' => $rowId,
    'npc_name' => $author,
    'hash' => $hash,
    'cached' => !empty($tts['cached']),
    'segments' => $segmentCount,
]);

echo json_encode([
    'ok' => true,
    'rowid' => $rowId,
    'author' => $displayAuthor,
    'hash' => $hash,
    'audio_url' => $audioUrl,
    'duration_ms' => intval($tts['duration_ms'] ?? 0),
    'cached' => !empty($tts['cached']),
    'segments' => $segmentCount,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
