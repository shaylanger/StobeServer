<?php
// STOBE 124 regression: the background processor must never be started for a non-live DB
// (a test suite's php -S ingress started a stobe_test loop that held the live lock + port 12346).
// Uses a temp TMPDIR, fake engine path, fake start.sh / flock / nc: never touches the real lock or port.
declare(strict_types=1);

$tmp = '/tmp' . '/bgproc_guard_' . getmypid();
@mkdir($tmp . '/engine/service', 0777, true);
@mkdir($tmp . '/state', 0777, true);
@mkdir($tmp . '/bin', 0777, true);
putenv('TMPDIR=' . $tmp . '/state');

function getSetting($k, $d = null) { return $d; }
function getConfOpt($k, $d = null) { return '0'; }
function parseIntLike($v, $d) { return is_numeric($v) ? intval($v) : $d; }
function stobeLogWarn(string $m, array $c = []): void {}
function stobeLogInfo(string $m, array $c = []): void {}
function stobeLogDebug(string $m, array $c = []): void {}
require __DIR__ . '/../lib/background_processor.php';

$fail = 0;
function check(bool $ok, string $what): void {
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    if (!$ok) { $fail++; }
}

// --- PHP start function ---
$marker = $tmp . '/start_called';
file_put_contents($tmp . '/engine/service/start.sh', "#!/bin/bash\ntouch " . escapeshellarg($marker) . "\n");
$GLOBALS['ENGINE_PATH'] = $tmp . '/engine/';
$attempt = $tmp . '/state/stobe_background_processor_start.attempt';

$runPhp = function (array $env) use ($marker, $attempt): bool {
    foreach (['STOBE_DB_NAME', 'STOBE_LIVE_DB_NAME', 'STOBE_NO_BACKGROUND_PROCESSOR'] as $k) {
        putenv(array_key_exists($k, $env) ? "$k=" . $env[$k] : $k);
    }
    @unlink($marker);
    @unlink($attempt);
    stobeEnsureBackgroundProcessorRunning(false);
    for ($i = 0; $i < 20 && !is_file($marker); $i++) { usleep(50000); }
    return is_file($marker);
};
check(!$runPhp(['STOBE_DB_NAME' => 'stobe_test']), 'php: STOBE_DB_NAME=stobe_test does not run start.sh');
check(!$runPhp(['STOBE_DB_NAME' => 'stobe', 'STOBE_NO_BACKGROUND_PROCESSOR' => '1']), 'php: STOBE_NO_BACKGROUND_PROCESSOR=1 does not run start.sh');
check($runPhp(['STOBE_DB_NAME' => 'stobe']), 'php: live DB still runs start.sh (control)');
check($runPhp([]), 'php: unset DB (= live) still runs start.sh (control)');

// --- start.sh (copy with its /tmp paths redirected, fake flock + nc on PATH) ---
$real = file_get_contents(__DIR__ . '/../service/start.sh');
file_put_contents($tmp . '/engine/service/start.sh', str_replace('/tmp/', $tmp . '/state/', $real));
$flockMarker = $tmp . '/flock_called';
file_put_contents($tmp . '/bin/flock', "#!/bin/bash\ntouch " . escapeshellarg($flockMarker) . "\nexit 1\n");
file_put_contents($tmp . '/bin/nc', "#!/bin/bash\ntouch " . escapeshellarg($tmp . '/nc_called') . "\nexit 1\n");
chmod($tmp . '/bin/flock', 0755);
chmod($tmp . '/bin/nc', 0755);
$runSh = function (string $envPrefix) use ($tmp, $flockMarker): array {
    @unlink($flockMarker);
    $cmd = 'env -u STOBE_DB_NAME -u STOBE_NO_BACKGROUND_PROCESSOR -u STOBE_LIVE_DB_NAME ' . $envPrefix
        . ' PATH=' . escapeshellarg($tmp . '/bin:' . getenv('PATH'))
        . ' bash ' . escapeshellarg($tmp . '/engine/service/start.sh') . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc, is_file($flockMarker), implode(' ', $out)];
};
[$rc, $fl, $out] = $runSh('STOBE_DB_NAME=stobe_test');
check($rc === 3 && !$fl, "start.sh: stobe_test refused before the lock (rc=$rc flock=" . intval($fl) . " out=$out)");
[$rc, $fl, $out] = $runSh('STOBE_DB_NAME=stobe STOBE_NO_BACKGROUND_PROCESSOR=1');
check($rc === 3 && !$fl, "start.sh: STOBE_NO_BACKGROUND_PROCESSOR=1 refused (rc=$rc flock=" . intval($fl) . ")");
[$rc, $fl, $out] = $runSh('STOBE_DB_NAME=stobe');
check($rc === 0 && $fl, "start.sh: live DB reaches the lock (control, rc=$rc flock=" . intval($fl) . ")");
check(strpos($real, '9>&-') !== false, 'start.sh: nc listener does not inherit lock fd 9');

exec('rm -rf ' . escapeshellarg($tmp));
echo $fail === 0 ? "background_processor_guard_regression: all passed\n" : "background_processor_guard_regression: $fail failed\n";
exit($fail === 0 ? 0 : 1);
