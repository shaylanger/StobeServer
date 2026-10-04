<?php
// Item 110 / to-do 20: the playthrough rollback never runs without its advisory lock.
declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/playthrough_storage.php';
require_once __DIR__ . '/../lib/playthrough_autosave.php';
require_once __DIR__ . '/../lib/playthrough_rollback.php';
require_once __DIR__ . '/../lib/playthrough_guard.php';

$db = $GLOBALS['db'];

function lkFail(string $m): void { fwrite(STDERR, 'FAIL: ' . $m . PHP_EOL); exit(1); }
function lkAssert(bool $c, string $m): void { if (!$c) lkFail($m); }

$confKeys = ['PLAYTHROUGH_LAST_SEEN_GAMETS', 'PLAYTHROUGH_LAST_SEEN_TS', 'PLAYTHROUGH_LAST_ROLLBACK_GAMETS',
    'PLAYTHROUGH_LAST_ROLLBACK_TS', 'PLAYTHROUGH_LAST_ROLLBACK_FROM_GAMETS', 'PLAYTHROUGH_LAST_ROLLBACK_DELTA_GAMETS',
    'DYNAMIC_PROFILE_LOAD_GRACE_UNTIL_TS'];
$confBackup = [];
foreach ($confKeys as $k) {
    $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [$k]);
    $confBackup[$k] = $row ? strval($row['value']) : null;
}
$waitBackup = getSetting('PLAYTHROUGH_ROLLBACK_LOCK_WAIT_MS', '');
$minDaysBackup = $GLOBALS['DRAGON_BREAK_MIN_DAYS'] ?? null;
$GLOBALS['DRAGON_BREAK_MIN_DAYS'] = 1;

$other = pg_connect(implode(' ', [
    'host=' . (getenv('STOBE_DB_HOST') ?: 'localhost'),
    'dbname=' . (getenv('STOBE_DB_NAME') ?: 'stobe'),
    'user=' . (getenv('STOBE_DB_USER') ?: 'dwemer'),
    'password=' . (getenv('STOBE_DB_PASSWORD') ?: 'dwemer'),
]), PGSQL_CONNECT_FORCE_NEW);
lkAssert($other !== false, 'second DB connection (the "other request")');
$key = stobePlaythroughRollbackLockKey();
$base = 900000000 + random_int(10000, 99999) * 10;

try {
    // 1. Own key: the memory-summary / auto-diary cycles hold 937463 during LLM calls.
    lkAssert($key !== 937463, 'rollback lock key must differ from the memory/diary lock 937463');

    // 2. A memory-summary cycle holding its lock does not block a rollback.
    pg_query($other, 'SELECT pg_advisory_lock(937463)');
    setSetting('PLAYTHROUGH_ROLLBACK_LOCK_WAIT_MS', '300');
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($base + 1000));
    $r = stobeHandlePotentialGametsRollback($base + 500, 'test_lock_free');
    pg_query($other, 'SELECT pg_advisory_unlock(937463)');
    lkAssert(!empty($r['triggered']), 'rollback runs while the memory lock is held: ' . json_encode($r));
    echo 'PASS rollback not blocked by the memory/diary lock' . PHP_EOL;

    // 3. Another request holds the rollback lock for the whole wait: skip cleanly, clock untouched.
    pg_query($other, 'SELECT pg_advisory_lock(' . $key . ')');
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($base + 3000));
    $t0 = microtime(true);
    $r = stobeHandlePotentialGametsRollback($base + 2000, 'test_lock_busy');
    $waited = microtime(true) - $t0;
    pg_query($other, 'SELECT pg_advisory_unlock(' . $key . ')');
    lkAssert(empty($r['triggered']) && ($r['reason'] ?? '') === 'rollback_lock_busy', 'busy lock skips the rollback: ' . json_encode($r));
    lkAssert($waited >= 0.25, 'busy lock is waited for before skipping (waited ' . round($waited, 3) . ' s)');
    lkAssert(getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '') === strval($base + 3000), 'skipped rollback leaves the clock for the next load event');
    echo 'PASS busy rollback lock: waited, then skipped without running unlocked' . PHP_EOL;

    // 4. The other request finishes its rollback while we wait: we take the lock, see its clock, don't roll back again.
    setSetting('PLAYTHROUGH_ROLLBACK_LOCK_WAIT_MS', '5000');
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($base + 6000));
    pg_query($other, 'SELECT pg_advisory_lock(' . $key . ')');
    pg_send_query($other, "SELECT pg_sleep(0.6); UPDATE conf_opts SET value = '" . ($base + 4000)
        . "' WHERE id = 'PLAYTHROUGH_LAST_SEEN_GAMETS'; SELECT pg_advisory_unlock(" . $key . ')');
    $t0 = microtime(true);
    $r = stobeHandlePotentialGametsRollback($base + 4000, 'test_lock_wait');
    $waited = microtime(true) - $t0;
    while (pg_get_result($other) !== false) {}
    lkAssert(empty($r['triggered']) && ($r['reason'] ?? '') === 'forward_or_same_after_lock', 'waiter sees the finished rollback: ' . json_encode($r));
    lkAssert($waited >= 0.4, 'waiter waited for the other rollback (' . round($waited, 3) . ' s)');
    echo 'PASS concurrent rollback: second request waits and does not roll back twice' . PHP_EOL;

    // 5. The handler releases its lock.
    $free = pg_fetch_result(pg_query($other, 'SELECT pg_try_advisory_lock(' . $key . ')'), 0, 0);
    lkAssert($free === 't', 'rollback lock released after the handler');
    pg_query($other, 'SELECT pg_advisory_unlock(' . $key . ')');
    echo 'PASS rollback lock released' . PHP_EOL;
} finally {
    @pg_query($other, 'SELECT pg_advisory_unlock_all()');
    pg_close($other);
    setSetting('PLAYTHROUGH_ROLLBACK_LOCK_WAIT_MS', $waitBackup !== '' ? $waitBackup : '15000');
    $GLOBALS['DRAGON_BREAK_MIN_DAYS'] = $minDaysBackup;
    foreach ($confBackup as $k => $v) {
        if ($v === null) $db->exec('DELETE FROM conf_opts WHERE id = $1', [$k]);
        else setConfOpt($k, $v);
    }
}
echo 'All playthrough rollback lock tests passed.' . PHP_EOL;
exit(0);
