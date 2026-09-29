<?php

/**
 * Chatterbox cold-start warm-up.
 *
 * The first synthesis after Chatterbox starts spends ~17 s initialising its
 * voice-conditioning pipeline (lazy model parts and JIT compilation); every
 * later request takes ~0.1 s for the same step. Without a warm-up, the player's
 * first line of the session pays that cost. As soon as any game event arrives
 * (loading a save sends many), the server fires one tiny background synthesis
 * per Chatterbox process, so the pipeline is warm before the player speaks.
 *
 * Disable with general_settings TTS_WARMUP_DISABLED=true.
 */

/** "pid:start_epoch" of the running Chatterbox server, or '' if none. */
function stobeChatterboxProcessKey(): string
{
    $btime = 0;
    foreach (@file('/proc/stat') ?: [] as $line) {
        if (str_starts_with($line, 'btime ')) {
            $btime = intval(substr($line, 6));
            break;
        }
    }
    foreach (glob('/proc/[0-9]*', GLOB_NOSORT) ?: [] as $proc) {
        $cmd = @file_get_contents($proc . '/cmdline');
        if (!is_string($cmd) || !str_contains($cmd, 'restapi.py')) {
            continue;
        }
        $stat = @file_get_contents($proc . '/stat');
        if (!is_string($stat) || ($close = strrpos($stat, ')')) === false) {
            continue;
        }
        // Fields after "(comm) ": index 0 is field 3 (state), so field 22 (starttime) is index 19.
        $fields = explode(' ', substr($stat, $close + 2));
        $startTicks = intval($fields[19] ?? 0);
        return basename($proc) . ':' . strval($btime + intdiv($startTicks, 100));
    }
    return '';
}

function stobeChatterboxWarmupMaybe(): void
{
    try {
        if (getSettingBool('TTS_WARMUP_DISABLED', false)) {
            return;
        }
        $marker = sys_get_temp_dir() . '/stobe_chatterbox_warm.json';
        $last = @filemtime($marker);
        if (is_int($last) && (time() - $last) < 30) {
            return; // checked recently; events arrive many times per second
        }
        $state = json_decode(strval(@file_get_contents($marker)), true);
        $state = is_array($state) ? $state : [];
        $key = stobeChatterboxProcessKey();
        if ($key === '' || strval($state['key'] ?? '') === $key) {
            @file_put_contents($marker, json_encode(['key' => strval($state['key'] ?? ''), 'at' => intval($state['at'] ?? 0)]));
            return; // not running, or this process is already warm
        }

        $url = 'http://127.0.0.1:8023';
        $voice = 'male1';
        $row = $GLOBALS['db']->fetchOne(
            "SELECT base_url, config FROM core_tts_connector WHERE connector_type='chatterbox' ORDER BY is_default DESC, id LIMIT 1"
        );
        if (is_array($row)) {
            if (trim(strval($row['base_url'] ?? '')) !== '') {
                $url = rtrim(trim(strval($row['base_url'])), '/');
            }
            $config = json_decode(strval($row['config'] ?? ''), true);
            foreach (['fallback_male', 'fallback_female'] as $key2) {
                $candidate = trim(strval($config[$key2] ?? ''));
                if ($candidate !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $candidate)
                    && is_file('/home/dwemer/chatterbox/voices/' . $candidate . '.wav')) {
                    $voice = $candidate;
                    break;
                }
            }
        }
        if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?$#', $url)) {
            return;
        }

        // Mark first so concurrent requests do not start a second warm-up.
        @file_put_contents($marker, json_encode(['key' => $key, 'at' => time()]));
        $payload = json_encode(['text' => 'Hm.', 'speaker_wav' => $voice, 'language' => 'en']);
        exec('curl -s -o /dev/null -m 120 -X POST -H ' . escapeshellarg('Content-Type: application/json')
            . ' --data ' . escapeshellarg($payload) . ' ' . escapeshellarg($url . '/tts_to_audio/')
            . ' > /dev/null 2>&1 &');
        stobeLogInfo('Chatterbox warm-up started', ['process' => $key, 'voice' => $voice, 'url' => $url]);
    } catch (Throwable $e) {
        // Never let a warm-up problem affect event handling.
    }
}
