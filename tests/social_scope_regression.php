<?php
// Social scope with and without Playthrough Saves (automatic switching). Regression for game run m4:
// with switching off the game had no campaign id and every social event was skipped.
declare(strict_types=1);
if (getenv('STOBE_DB_NAME') !== 'stobe_social_phase1_test') throw new RuntimeException('Dedicated social DB required');
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/social_runtime.php';
$db = $GLOBALS['db']; $n = 0;
function ok(bool $c, string $what): void { global $n; if (!$c) throw new RuntimeException('FAIL: ' . $what); ++$n; }
function sql(string $s, array $p = []): mixed { $r = $GLOBALS['db']->exec($s, $p); if ($r === false) throw new RuntimeException('SQL failed: ' . $s); return $r; }
function meta(string $key, ?string $value): void {
    if ($value === null) sql('DELETE FROM stobe_meta.settings WHERE key=$1', [$key]);
    else sql('INSERT INTO stobe_meta.settings(key,value) VALUES($1,$2) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value', [$key, $value]);
}
function refuses(callable $f): bool { try { $f(); return false; } catch (DomainException $e) { return true; } }
function ev(string $campaign, string $epoch, int $seq, string $client = 'clientA'): array {
    return ['schema_version'=>1, 'event_id'=>"$client:$epoch:$seq", 'incident_id'=>"$client:$epoch:$seq", 'campaign_id'=>$campaign, 'timeline_epoch'=>$epoch,
        'native_session_id'=>$client, 'sequence'=>$seq, 'game_ts'=>100 + $seq, 'event_kind'=>'combat', 'origin'=>'gameplay',
        'actor'=>['entity_key'=>"$client:$epoch:1", 'serial'=>1, 'name'=>'A'], 'target'=>null, 'state_before'=>[], 'state_after'=>[], 'witnesses'=>[], 'facts'=>['message'=>'x']];
}
$saved = [];
foreach (['PLAYTHROUGH_SESSION', 'PLAYTHROUGH_AUTO_SWITCH'] as $k) { $r = $db->fetchOne('SELECT value FROM stobe_meta.settings WHERE key=$1', [$k]); $saved[$k] = is_array($r) ? $r['value'] : null; }
try {
    sql("DELETE FROM social_event_inbox WHERE campaign_id='legacy'");
    $store = new SocialStore($db);
    // Playthrough Saves off (the live setup of run m4): the game sends campaign "legacy".
    meta('PLAYTHROUGH_AUTO_SWITCH', null); meta('PLAYTHROUGH_SESSION', null);
    $e = ev('legacy', '5', 1);
    $scope = stobeSocialScope($db, SocialEventContract::validate($e));
    ok($scope === ['campaign_id'=>'legacy', 'timeline_epoch'=>'5', 'native_session_id'=>'clientA'], 'switching off: event scope accepted');
    ok($store->ingest($e, $scope, 'shadow')['status'] === 'captured', 'switching off: captured');
    ok(refuses(fn() => stobeSocialScope($db, SocialEventContract::validate(ev('abc', '5', 2)))), 'switching off: other campaign ids refused');
    $e6 = ev('legacy', '6', 3);
    $store->ingest($e6, stobeSocialScope($db, SocialEventContract::validate($e6)), 'shadow');
    ok(refuses(fn() => stobeSocialScope($db, SocialEventContract::validate(ev('legacy', '5', 4)))), 'switching off: an older load after a newer one is stale');
    ok(stobeSocialScope($db, SocialEventContract::validate(ev('legacy', '1', 1, 'clientB')))['native_session_id'] === 'clientB', 'switching off: a new game process starts its own load ids');
    ok(stobeSocialScope($db)['timeline_epoch'] === '6', 'switching off: server-owned events use the newest load');
    meta('PLAYTHROUGH_AUTO_SWITCH', 'false');
    ok(stobeSocialScope($db, SocialEventContract::validate(ev('legacy', '6', 5)))['campaign_id'] === 'legacy', 'switching explicitly false: legacy scope');
    // Playthrough Saves on: only the authenticated handshake counts.
    meta('PLAYTHROUGH_AUTO_SWITCH', 'true');
    ok(refuses(fn() => stobeSocialScope($db, SocialEventContract::validate(ev('legacy', '6', 6)))), 'switching on, no handshake: refused');
    meta('PLAYTHROUGH_SESSION', json_encode(['status'=>'ready', 'character_id'=>'0123456789abcdef0123456789abcdef', 'load_id'=>9, 'client_id'=>'clientA']));
    ok(stobeSocialScope($db, SocialEventContract::validate(ev('legacy', '6', 7)))['campaign_id'] === '0123456789abcdef0123456789abcdef', 'switching on: handshake scope (a legacy event then mismatches in ingest)');
} finally {
    foreach ($saved as $k => $v) meta($k, $v);
    sql("DELETE FROM social_event_inbox WHERE campaign_id='legacy'");
}
echo "$n scope regression checks passed\n";
