<?php
// Installation-wide interaction state is deliberately outside Playthrough Saves.
function stobeInteractionFile()
{
    $dir = dirname(__DIR__) . '/conf/stobe_interaction';
    if (!is_dir($dir) && !@mkdir($dir, 02775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot open Stobe interaction state.');
    }
    // Only the owner can chmod shared state; the other server account uses group access.
    if ((!function_exists('posix_geteuid') || fileowner($dir) === posix_geteuid())
        && (fileperms($dir) & 07777) !== 02775) @chmod($dir, 02775);
    $handle = @fopen($dir . '/state.json', 'c+e');
    if (!$handle) throw new RuntimeException('Cannot open Stobe interaction state.');
    $path = $dir . '/state.json';
    if ((!function_exists('posix_geteuid') || fileowner($path) === posix_geteuid())
        && (fileperms($path) & 07777) !== 0664) @chmod($path, 0664);
    return $handle;
}

function stobeInteractionRead($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);
    if ($raw === '') return ['enabled' => true, 'generation' => 0];
    $state = json_decode($raw, true);
    if (!is_array($state) || !is_bool($state['enabled'] ?? null) || !is_int($state['generation'] ?? null)) {
        throw new RuntimeException('Cannot read Stobe interaction state.');
    }
    return $state;
}

function stobeInteractionState(): array
{
    $handle = stobeInteractionFile();
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Cannot read Stobe interaction state.');
        return stobeInteractionRead($handle);
    } finally { fclose($handle); }
}

// Capture once per request, so Off/On cannot revive an old generator.
function stobeInteractionBegin(): void
{
    if (isset($GLOBALS['stobe_interaction_generation'])) return;
    try {
        $state = stobeInteractionState();
        $GLOBALS['stobe_interaction_generation'] = isset($_GET['interaction_generation']) || isset($_SERVER['HTTP_X_STOBE_GENERATION'])
            ? (int)($_GET['interaction_generation'] ?? $_SERVER['HTTP_X_STOBE_GENERATION']) : $state['generation'];
    } catch (Throwable $e) {
        $GLOBALS['stobe_interaction_generation'] = -1;
        error_log('Stobe interaction state unavailable; event recording remains active.');
    }
}

function stobeInteractionAllowed(): bool
{
    stobeInteractionBegin();
    try {
        $state = stobeInteractionState();
        return ($_GET['interaction_passive'] ?? $_SERVER['HTTP_X_STOBE_PASSIVE'] ?? '') !== '1' && $state['enabled']
            && $state['generation'] === $GLOBALS['stobe_interaction_generation'];
    } catch (Throwable $e) { return false; }
}

// Real Kenshi observations, including limb loss and narration, must still be recorded.
function stobeInteractionIsTrigger(string $type): bool
{
    return in_array(strtolower($type), ['inputtext', 'inputtext_s', 'injection',
        'bored', 'rechat', 'diary', 'diary_narrator'], true);
}

function stobeInteractionRequire(): void
{
    if (!stobeInteractionAllowed()) exit;
    $GLOBALS['stobe_interaction_generated'] = true;
}

stobeInteractionBegin();
