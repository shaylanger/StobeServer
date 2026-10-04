<?php
// Negotiation engine regression (Phases 1-8). Run ONLY against a test database:
//   STOBE_DB_NAME=stobe_test STOBE_NEG_STOBE_LOG=/tmp/negtest/stobe.log STOBE_NEG_KFP_LOG=/tmp/negtest/kfp.log php tests/negotiation_engine_regression.php
require __DIR__ . '/../lib/bootstrap.php';

$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}

$logDir = dirname(strval(getenv('STOBE_NEG_STOBE_LOG')));
@mkdir($logDir, 0777, true);
$stobeLog = strval(getenv('STOBE_NEG_STOBE_LOG'));
$kfpLog = strval(getenv('STOBE_NEG_KFP_LOG'));
file_put_contents($stobeLog, '');
file_put_contents($kfpLog, '');

// Game logs use the local clock; write them in a fake local zone (UTC-6) to prove the offset logic.
function stobeLine(string $body, int $ts): void {
    file_put_contents(getenv('STOBE_NEG_STOBE_LOG'), '[' . gmdate('Y-m-d H:i:s', $ts - 21600) . '.000] [Stobe] ' . $body . "\n", FILE_APPEND);
    touch(getenv('STOBE_NEG_STOBE_LOG'), $ts);
    stobeNegResetLogCache();
}
function kfpLine(string $body, int $ts): void {
    file_put_contents(getenv('STOBE_NEG_KFP_LOG'), '[' . gmdate('H:i:s', $ts - 21600) . '.000] [stobe] ' . $body . "\n", FILE_APPEND);
    touch(getenv('STOBE_NEG_KFP_LOG'), $ts);
    stobeNegResetLogCache();
}

// ---------------------------------------------------------------- fixtures
$player = 'NegTestPlayer';
$db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [$player]);
stobeNegEnsureSchema();
$db->exec("DELETE FROM stobe_social_contract WHERE player_name=$1", [$player]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE LOWER(player_name)=LOWER($1)", [$player]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTest%'");

function fixtureNpc(string $name, array $meta, string $inventory = '', string $personality = '', string $blood = '100/100', string $faction = 'Test Bandits'): void {
    $db = $GLOBALS['db'];
    $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$name]);
    $db->exec("DELETE FROM core_npc WHERE name=$1", [$name]);
    $db->exec(
        "INSERT INTO core_npc_master (name, metadata, inventory, equipment, personality, blood, faction, created_at, updated_at)
         VALUES ($1, $2::jsonb, $3, '', $4, $5, $6, NOW(), NOW())",
        [$name, json_encode($meta), $inventory, $personality, $blood, $faction]
    );
}
$now = time();
fixtureNpc($player, ['money'=>5000, 'money_observed_at'=>$now], '', '', '100/100', 'Nameless');
fixtureNpc('NegTestBandit', ['money'=>50, 'money_observed_at'=>$now, 'is_in_combat'=>true, 'current_action'=>'attacking'], 'Bread x2 value 10', 'A dishonest, greedy thug.');
fixtureNpc('NegTestTrader', ['money'=>900, 'money_observed_at'=>$now], 'Iron Hat x1 value 526', 'Calm and fair.');

function makeDeal(string $npc, array $terms, string $kind = 'combat'): string {
    $r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$GLOBALS['player']], 'terms'=>$terms, 'context'=>[], 'kind'=>$kind]);
    if (empty($r['ok'])) throw new RuntimeException('create failed ' . json_encode($r));
    stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
    return $r['id'];
}
function backdate(string $id, int $seconds): void {
    $d = stobeNegFetchDeal($id);
    $state = stobeNegDecode($d['term_state']);
    foreach ($state as &$t) {
        if (!empty($t['dispatched_unix'])) $t['dispatched_unix'] -= $seconds;
        if (!empty($t['deadline_unix'])) $t['deadline_unix'] -= $seconds;
    }
    $GLOBALS['db']->exec("UPDATE stobe_social_contract SET term_state=$2::jsonb WHERE contract_id=$1", [$id, json_encode($state)]);
}
/** Item 64: move the deal's game-time deadline back and make sure the game clock is seen at the deal's start time. */
function backdateGame(string $id, int $gamets): int {
    $d = stobeNegFetchDeal($id);
    $GLOBALS['db']->exec("UPDATE stobe_social_contract SET deadline_gamets=GREATEST(1, deadline_gamets-$2) WHERE contract_id=$1", [$id, $gamets]);
    $start = max(1, intval($d['deadline_gamets'] ?? 0) - STOBE_NEG_PAY_WINDOW_GAMETS);
    $GLOBALS['db']->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ('info',$1,$2,'NegTest game clock','pending',$1,'','')",
        [time(), $start]);
    return $start;
}
function status(string $id): string { return strval(stobeNegFetchDeal($id)['status']); }
function termStatus(string $id, int $i): string { return strval(stobeNegDecode(stobeNegFetchDeal($id)['term_state'])[$i]['status'] ?? ''); }

// ---------------------------------------------------------------- 1. validation
check('LEAVE_AREA rejected', stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>[['kind'=>'LEAVE_AREA','by'=>'npc']]])['ok'] === false);
check('SPARE must be by player', stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>[['kind'=>'SPARE','by'=>'npc','target'=>'npc']]])['ok'] === false);
check('PROMISE needs text', stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>[['kind'=>'PROMISE','by'=>'player']]])['ok'] === false);
check('valid social deal', stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>[
    ['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Iron Hat'],['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200]]])['ok'] === true);

// ---------------------------------------------------------------- 2. accept actions (ceasefire first, after-terms held back)
$acts = stobeNegAcceptActions([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>100],
    ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],
    ['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Bread','when'=>'after_player'],
], $player, 'combat');
check('accept actions = ceasefire only', $acts === ['STOP_ATTACK@' . $player], $acts);

// ---------------------------------------------------------------- 3. combat deal: truce verified + payment verified -> COMPLETE
$id = makeDeal('NegTestBandit', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>100], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
check('begin performance', stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, ''));
check('status awaiting', status($id) === 'AWAITING_PERFORMANCE', status($id));
check('player term awaiting', termStatus($id, 0) === 'AWAITING_PLAYER');
check('ceasefire dispatched', termStatus($id, 1) === 'DISPATCHED');
stobeLine("ACTION_EXEC: STOP_ATTACK actor=NegTestBandit target=$player speaker_faction='X' target_faction='Y' applied=1 truce_registered=1 reason=", time());
stobeNegTick();
check('truce not verified before 30s', termStatus($id, 1) === 'DISPATCHED', termStatus($id, 1));
backdate($id, STOBE_NEG_TRUCE_OBSERVE_SECONDS + 1);
stobeNegTick();
check('truce verified after quiet window', termStatus($id, 1) === 'VERIFIED', termStatus($id, 1));
stobeLine("ACTION_EXEC: GIVE_CATS actor=$player recipient=NegTestBandit amount=100", time());
stobeNegTick();
check('payment verified from game record', termStatus($id, 0) === 'VERIFIED', termStatus($id, 0));
check('deal COMPLETE', status($id) === 'COMPLETE', status($id));
$rep = $db->fetchOne("SELECT player_kept FROM stobe_negotiation_reputation WHERE player_name=$1", [strtolower($player)]); // item 50: lower-case key
check('reputation kept +1', intval($rep['player_kept'] ?? 0) === 1, $rep);
$mem = $db->fetchOne("SELECT data FROM eventlog WHERE type='injection' AND data LIKE 'NegTestBandit: [deal outcome]%' ORDER BY rowid DESC LIMIT 1");
check('memory event stored', is_array($mem), $mem);

// ---------------------------------------------------------------- 4. non-payment -> BREACHED_PLAYER + angry directive
$id = makeDeal('NegTestBandit', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>300], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
// Item 64: past the pay window AND the truce watch (120 s since bug 90; the breach waits for NPC terms in flight),
// and past the game-time pay window (hostile deals expire on game time too, bug 41).
backdate($id, max(61, STOBE_NEG_TRUCE_OBSERVE_SECONDS + 1));
backdateGame($id, STOBE_NEG_PAY_WINDOW_GAMETS + 1);
stobeNegTick();
check('unpaid -> BREACHED_PLAYER', status($id) === 'BREACHED_PLAYER', status($id));
$db->exec("DELETE FROM eventlog WHERE data='NegTest game clock'");
$dir = $db->fetchOne("SELECT kind, payload FROM stobe_negotiation_directive WHERE contract_id=$1", [$id]);
check('breach reaction queued with ATTACK', is_array($dir) && $dir['kind'] === 'breach_react' && str_contains($dir['payload'], 'ATTACK@'), $dir);
$db->exec("DELETE FROM stobe_negotiation_directive");

// ---------------------------------------------------------------- 5. truce re-acquired -> re-issue (x2) -> IMPOSSIBLE
$id = makeDeal('NegTestBandit', [['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
backdate($id, 10);
storeEvent('combat', time(), 1000, "NegTestBandit: Initiated attack (talking to: $player)");
stobeNegTick();
check('re-acquire queues reissue', termStatus($id, 0) === 'REISSUE_QUEUED', termStatus($id, 0));
$acts = stobeNegAttachPendingForChat('NegTestBandit', []);
check('reissue attached to chat reply', $acts === ['STOP_ATTACK@' . $player], $acts);
check('reissue dispatched', termStatus($id, 0) === 'DISPATCHED', termStatus($id, 0));
for ($attempt = 0; $attempt < STOBE_NEG_TRUCE_MAX_REISSUE; $attempt++) {
    backdate($id, 10);
    storeEvent('combat', time(), 1000, "NegTestBandit: Initiated attack (talking to: $player)");
    stobeNegTick();
    stobeNegAttachPendingForChat('NegTestBandit', []);
}
check('truce that never holds -> IMPOSSIBLE', status($id) === 'IMPOSSIBLE', [status($id), termStatus($id, 0)]);
$db->exec("DELETE FROM stobe_negotiation_directive");

// ---------------------------------------------------------------- 6. NPC cannot pay -> IMPOSSIBLE
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>5000]], 'social');
stobeNegBeginPerformance($id, ['GIVE_CATS@' . $player . '@5000'], $player, 1000, '');
stobeLine("ACTION_EXEC: GIVE_CATS actor=NegTestTrader skipped reason=no_money requested=5000", time());
stobeNegTick();
check('game refused NPC payment -> IMPOSSIBLE', status($id) === 'IMPOSSIBLE', [status($id), termStatus($id, 0)]);

// ---------------------------------------------------------------- 6b. bug 126: payment with no record (she was down) -> re-sent on her next line, x2, then IMPOSSIBLE
file_put_contents($stobeLog, ''); stobeNegResetLogCache(); // section 6's refusal is for the same NPC
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>30]], 'social');
stobeNegBeginPerformance($id, ['GIVE_CATS@' . $player . '@30'], $player, 1000, '');
backdate($id, STOBE_NEG_EXECUTION_TIMEOUT_SECONDS + 1);
stobeNegTick();
check('bug 126: unexecuted payment queued again', termStatus($id, 0) === 'REISSUE_QUEUED' && status($id) === 'AWAITING_PERFORMANCE', [status($id), termStatus($id, 0)]);
$db->exec("UPDATE stobe_negotiation_directive SET created_unix=created_unix-300 WHERE contract_id=$1", [$id]);
stobeNegTick();
check('bug 126: reissue still waits after 5 min', termStatus($id, 0) === 'REISSUE_QUEUED', termStatus($id, 0));
// Bug 131: the bored path claimed it and is still generating her line: not "never delivered".
$db->exec("UPDATE stobe_negotiation_directive SET consumed_unix=$2 WHERE contract_id=$1 AND consumed_unix=0", [$id, time()]);
stobeNegTick();
check('bug 131: a reissue being delivered stays queued', termStatus($id, 0) === 'REISSUE_QUEUED' && status($id) === 'AWAITING_PERFORMANCE', [status($id), termStatus($id, 0)]);
$db->exec("UPDATE stobe_negotiation_directive SET consumed_unix=0 WHERE contract_id=$1 AND outcome=''", [$id]);
$acts = stobeNegAttachPendingForChat('NegTestTrader', []);
check('bug 126: payment rides on her next line', $acts === ['GIVE_CATS@' . $player . '@30'] && termStatus($id, 0) === 'DISPATCHED', [$acts, termStatus($id, 0)]);
stobeLine("ACTION_EXEC: GIVE_CATS actor=NegTestTrader recipient=$player amount=30", time());
stobeNegTick();
check('bug 126: re-sent payment verified', status($id) === 'COMPLETE', [status($id), termStatus($id, 0)]);
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>31]], 'social');
stobeNegBeginPerformance($id, ['GIVE_CATS@' . $player . '@31'], $player, 1000, '');
for ($attempt = 0; $attempt < 3; $attempt++) {
    backdate($id, STOBE_NEG_EXECUTION_TIMEOUT_SECONDS + 1);
    stobeNegTick();
    stobeNegAttachPendingForChat('NegTestTrader', []);
}
check('bug 126: never executed after 2 reissues -> IMPOSSIBLE', status($id) === 'IMPOSSIBLE', [status($id), termStatus($id, 0)]);
// Item 96 (m16 Rel Krag): she paid with the accepting reply, 4 s before the performance start was recorded.
file_put_contents($stobeLog, ''); stobeNegResetLogCache();
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name='NegTestTrader' AND player_name=$1", [$player]);
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>33]], 'social');
stobeLine("ACTION_EXEC: GIVE_CATS actor=NegTestTrader recipient=$player amount=33", time() - 5);
stobeNegBeginPerformance($id, ['GIVE_CATS@' . $player . '@33'], $player, 1000, '');
stobeNegTick();
check('item 96: payment made just before the performance start is verified', status($id) === 'COMPLETE' && termStatus($id, 0) === 'VERIFIED', [status($id), termStatus($id, 0)]);

// ---------------------------------------------------------------- 6c. bug 127: the squad gets 10 s to stop swinging after sparing
$id = makeDeal('NegTestBandit', [['kind'=>'SPARE','by'=>'player','target'=>'npc']], 'surrender');
stobeNegBeginPerformance($id, [], $player, 1000, '');
backdate($id, 5);
storeEvent('combat', time(), 1000, "$player: Initiated attack (talking to: NegTestBandit)");
stobeNegTick();
check('bug 127: a swing 5 s after sparing is not a breach', termStatus($id, 0) !== 'UNMET', [status($id), termStatus($id, 0)]);
backdate($id, 7);
$db->exec("DELETE FROM eventlog WHERE data LIKE 'NegTestBandit: Initiated attack%'"); // item 86b: he doesn't fight back here (m8)
storeEvent('combat', time(), 1000, "$player: Initiated attack (talking to: NegTestBandit)");
stobeNegTick();
// Item 86 (run m8): the game re-reports "Initiated attack" while a personal truce holds; no hit, no breach.
check('item 86: an "Initiated attack" 12 s after sparing with no hit is not a breach', termStatus($id, 0) !== 'UNMET', [status($id), termStatus($id, 0)]);
storeEvent('major_damage', time(), 1000, "NegTestBandit: took a major hit from $player using Machete");
stobeNegTick();
check('bug 127: an attack 12 s after sparing is (with a real hit, item 86)', termStatus($id, 0) === 'UNMET', [status($id), termStatus($id, 0)]);
// Item 86 (b): a load of an older save cancels in-flight deals created after it, no consequences.
$id86 = makeDeal('NegTestBandit', [['kind'=>'SPARE','by'=>'player','target'=>'npc']], 'surrender');
$db->exec("UPDATE stobe_social_contract SET baseline = jsonb_set(COALESCE(baseline,'{}'::jsonb), '{gamets}', '900000') WHERE contract_id=$1", [$id86]);
$rep86 = $db->fetchOne("SELECT player_kept, player_broken FROM stobe_negotiation_reputation WHERE player_name=$1", [strtolower($player)]);
$n86 = stobeNegCancelDealsAfterRollback(800000);
$row86 = stobeNegFetchDeal($id86);
check('item 86: a deal from after the loaded save is cancelled (rolled_back_by_load)',
    $n86 >= 1 && $row86['status'] === 'CANCELLED' && str_contains(strval($row86['evidence']), 'rolled_back_by_load'), [$n86, $row86['status'] ?? null]);
check('item 86: reputation unchanged by the rollback',
    $db->fetchOne("SELECT player_kept, player_broken FROM stobe_negotiation_reputation WHERE player_name=$1", [strtolower($player)]) == $rep86);
$id86b = makeDeal('NegTestBandit', [['kind'=>'SPARE','by'=>'player','target'=>'npc']], 'surrender');
$db->exec("UPDATE stobe_social_contract SET baseline = jsonb_set(COALESCE(baseline,'{}'::jsonb), '{gamets}', '700000') WHERE contract_id=$1", [$id86b]);
stobeNegCancelDealsAfterRollback(800000);
check('item 86: a deal from before the loaded save stays', stobeNegFetchDeal($id86b)['status'] !== 'CANCELLED');
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE contract_id=$1", [$id86b]);

// ---------------------------------------------------------------- 6d. bug 128: a directive follows an NPC named mid-fight
$db->exec("DELETE FROM stobe_negotiation_directive");
stobeNegQueueDirective('NegTest Bowman', 'surrender', '', ['actions'=>[]], false);
check('bug 128: another titled NPC does not take it', stobeNegClaimDirective(['Gost [NegTest Archer]']) === null);
$claimed = stobeNegClaimDirective(['Shay', 'Gost [NegTest Bowman]']);
check('bug 128: "Gost [NegTest Bowman]" takes the "NegTest Bowman" directive', is_array($claimed) && $claimed['npc_name'] === 'Gost [NegTest Bowman]', $claimed);
$db->exec("DELETE FROM stobe_negotiation_directive");
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE contract_id=$1", [$id]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTestBandit%' AND type='combat'");
$db->exec("DELETE FROM stobe_negotiation_directive");

// ---------------------------------------------------------------- 7. social: unequip for Cats, verified via KenshiFP
$db->exec("UPDATE stobe_social_contract SET npc_serial=4242 WHERE npc_name='NegTestTrader'");
$id = makeDeal('NegTestTrader', [['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Iron Hat'], ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200]], 'social');
$db->exec("UPDATE stobe_social_contract SET npc_serial=4242 WHERE contract_id=$1", [$id]);
stobeNegBeginPerformance($id, ['UNEQUIP_ITEM@Iron Hat'], $player, 1000, '');
kfpLine("UNEQUIP_ITEM serial=4242 query=Iron Hat matched=Iron Hat result=ok", time());
stobeNegTick();
check('unequip verified from bridge log', termStatus($id, 0) === 'VERIFIED', termStatus($id, 0));
// Payment via trade: NPC balance rises (money delta), no GIVE_CATS record.
$db->exec("UPDATE core_npc_master SET metadata = metadata || $2::jsonb WHERE name=$1", ['NegTestTrader', json_encode(['money'=>1100, 'money_observed_at'=>time() + 1])]);
stobeNegTick();
check('trade payment verified by Cats delta', termStatus($id, 1) === 'VERIFIED', termStatus($id, 1));
check('social deal COMPLETE', status($id) === 'COMPLETE', status($id));

// ---------------------------------------------------------------- 8. after-player settlement via directive
fixtureNpc('NegTestTrader', ['money'=>900, 'money_observed_at'=>time()], 'Iron Hat x1 value 526', 'Calm and fair.');
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>50], ['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Iron Hat','when'=>'after_player']], 'social');
stobeNegBeginPerformance($id, [], $player, 1000, '');
check('after-term waits for player', termStatus($id, 1) === 'WAITING_FOR_PLAYER', termStatus($id, 1));
stobeLine("ACTION_EXEC: GIVE_CATS actor=$player recipient=NegTestTrader amount=50", time());
stobeNegTick();
check('settle queued after payment', termStatus($id, 1) === 'SETTLE_QUEUED', termStatus($id, 1));
$d = stobeNegClaimDirective(['NegTestTrader']);
check('settle directive claimable by bored path', is_array($d) && $d['kind'] === 'settle', $d);
$out = stobeNegCompleteDirective($d, '', 'NegTestTrader', $player, getNpcData('NegTestTrader') ?: [], 'Here.', []);
check('settle action forced into reply', in_array('GIVE_ITEM@' . $player . '@Iron Hat@1', $out['actions'], true), $out);
check('settle term dispatched', termStatus($id, 1) === 'DISPATCHED', termStatus($id, 1));
stobeLine("ACTION_EXEC: GIVE_ITEM actor=NegTestTrader recipient=$player requested=1 transferred=1 item='Iron Hat' source=inventory", time());
stobeNegTick();
check('item delivery verified', status($id) === 'COMPLETE', [status($id), termStatus($id, 1)]);

// ---------------------------------------------------------------- 9. betrayal (forced roll) -> BREACHED_NPC
putenv('STOBE_NEG_TEST_FORCE_BETRAYAL=1');
$npcData = getNpcData('NegTestBandit');
if (is_array($npcData)) {
    $applied = stobeApplyRelationshipUpdatesMap(stobeGetNpcRelationshipMap($npcData), [['target'=>$player,'aff_delta'=>-30,'type'=>'rival','note'=>'test']], [$player]);
    stobePersistNpcRelationshipMap('NegTestBandit', $applied['map'], $npcData);
}
$id = makeDeal('NegTestBandit', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>100], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
$b = stobeNegDecode(stobeNegFetchDeal($id)['betrayal']);
check('betrayal planned for dishonest low-trust NPC', !empty($b['planned']), $b);
// Betrayal waits until KenshiFP's ~15 s STOP_FIGHT window has passed.
$db->exec("UPDATE stobe_social_contract SET performance_started_unix = performance_started_unix - 25 WHERE contract_id=$1", [$id]);
stobeLine("ACTION_EXEC: GIVE_CATS actor=$player recipient=NegTestBandit amount=100", time());
stobeNegTick();
check('paid then betrayed -> BREACHED_NPC', status($id) === 'BREACHED_NPC', status($id));
$dir = $db->fetchOne("SELECT kind, payload FROM stobe_negotiation_directive WHERE contract_id=$1 AND kind='betray'", [$id]);
check('betrayal directive re-attacks', is_array($dir) && str_contains($dir['payload'], 'ATTACK@'), $dir);
putenv('STOBE_NEG_TEST_FORCE_BETRAYAL');
$db->exec("DELETE FROM stobe_negotiation_directive");
// STOBE 18: the general_settings test switch forces a betrayal even for an honest, neutral NPC; off = no betrayal.
$db->exec("DELETE FROM general_settings WHERE id='NEG_TEST_FORCE_BETRAYAL'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('NEG_TEST_FORCE_BETRAYAL', 'true')");
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>40], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
$b = stobeNegDecode(stobeNegFetchDeal($id)['betrayal']);
check('STOBE 18: test switch forces the betrayal (honest NPC)', !empty($b['planned']) && str_contains(strval($b['reason'] ?? ''), 'test_switch'), $b);
$db->exec("UPDATE stobe_social_contract SET performance_started_unix = performance_started_unix - 25 WHERE contract_id=$1", [$id]);
stobeLine("ACTION_EXEC: GIVE_CATS actor=$player recipient=NegTestTrader amount=40", time());
stobeNegTick();
check('STOBE 18: forced betrayal -> BREACHED_NPC', status($id) === 'BREACHED_NPC', status($id));
check('STOBE 18: pay-now deal: the dispatched STOP_ATTACK is marked intentional_betrayal', termStatus($id, 1) === 'BETRAYED'
    && str_contains(json_encode(stobeNegFetchDeal($id)['term_state']), 'intentional_betrayal'), termStatus($id, 1));
$db->exec("UPDATE general_settings SET value='false' WHERE id='NEG_TEST_FORCE_BETRAYAL'");
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>41], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
$b = stobeNegDecode(stobeNegFetchDeal($id)['betrayal']);
check('STOBE 18: switch off -> honest NPC does not betray', empty($b['planned']), $b);
$db->exec("DELETE FROM general_settings WHERE id='NEG_TEST_FORCE_BETRAYAL'");
$db->exec("DELETE FROM stobe_negotiation_directive");

// ---------------------------------------------------------------- 10. counter -> accept same row; rounds exhausted
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestBandit' AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE')");
$counter = json_encode(['message'=>'x','deal_decision'=>'COUNTER','deal_terms'=>json_encode([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>2000],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']])]);
$r1 = stobeDealCaptureResponse($counter, 'NegTestBandit', $player, getNpcData('NegTestBandit') ?: [], 'offer', 'combat');
$accept = json_encode(['message'=>'x','deal_decision'=>'ACCEPT','deal_terms'=>json_encode([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>2000],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']])]);
$r2 = stobeDealCaptureResponse($accept, 'NegTestBandit', $player, getNpcData('NegTestBandit') ?: [], 'ok 2000', 'combat');
check('counter then accept reuses the same deal', ($r1['id'] ?? 'a') === ($r2['id'] ?? 'b') && ($r2['status'] ?? '') === 'ACCEPTED', [$r1, $r2]);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE contract_id=$1", [$r2['id']]);
$last = null;
for ($i = 0; $i < 8; $i++) {
    $last = stobeDealCaptureResponse($counter, 'NegTestBandit', $player, getNpcData('NegTestBandit') ?: [], 'haggle', 'combat');
    if (!empty($last['exhausted'])) break;
}
check('endless haggling ends', !empty($last['exhausted']), $last);

// ---------------------------------------------------------------- 11. surrender initiative (Phase 4)
fixtureNpc('NegTestBandit', ['money'=>50,'money_observed_at'=>time(),'is_in_combat'=>true], 'Bread x2', 'A timid coward.', '20/100');
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestBandit' AND status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')");
storeEvent('combat', time(), 1000, "NegTestBandit: Initiated attack (talking to: $player)");
@unlink(stobeNegThrottleMarker('initiative'));
stobeNegConsiderInitiatives('combat', "NegTestBandit: Initiated attack (talking to: $player)", "[\"$player|hand_1\",\"NegTestBandit|hand_2\"]", 1000);
$dir = $db->fetchOne("SELECT kind FROM stobe_negotiation_directive WHERE npc_name='NegTestBandit' ORDER BY id DESC LIMIT 1");
check('losing hostile NPC queues surrender offer', ($dir['kind'] ?? '') === 'surrender', [$dir, is_array(getNpcData('NegTestBandit')), stobeDealOpenForNpc('NegTestBandit')['contract_id'] ?? null, $db->fetchAll("SELECT kind, npc_name, created_unix FROM stobe_negotiation_directive")]);
@unlink(stobeNegThrottleMarker('initiative'));
$before = intval($db->fetchOne("SELECT COUNT(*) AS c FROM stobe_negotiation_directive")['c']);
stobeNegConsiderInitiatives('combat', "NegTestBandit: Initiated attack (talking to: $player)", "[\"$player|hand_1\"]", 1000);
check('initiative cooldown holds', intval($db->fetchOne("SELECT COUNT(*) AS c FROM stobe_negotiation_directive")['c']) === $before);
// NPC-proposed deal captured as PROPOSED by npc.
$propose = json_encode(['message'=>'Mercy!','deal_decision'=>'PROPOSE','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>40],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],['kind'=>'SPARE','by'=>'player','target'=>'npc']])]);
$r = stobeDealCaptureResponse($propose, 'NegTestBandit', $player, getNpcData('NegTestBandit') ?: [], '', 'surrender', 'npc');
$row = stobeNegFetchDeal(strval($r['id'] ?? ''));
check('NPC offer recorded as PROPOSED by npc', ($row['status'] ?? '') === 'PROPOSED' && ($row['proposer'] ?? '') === 'npc', $row ? [$row['status'], $row['proposer']] : $r);
// Bug 96: "We're done here." doesn't accept an NPC's own offer even if the model says ACCEPT; "Deal." does.
fixtureNpc('NegTestBandit96', ['money'=>1000,'money_observed_at'=>time(),'is_in_combat'=>true], 'Bread x2', 'A timid coward.', '20/100');
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestBandit96' AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE')");
$r96 = stobeDealCaptureResponse($propose, 'NegTestBandit96', $player, getNpcData('NegTestBandit96') ?: [], '', 'surrender', 'npc');
$acceptNpc = json_encode(['message'=>'Done, then.','deal_decision'=>'ACCEPT','deal_terms'=>'']);
$rd = stobeDealCaptureResponse($acceptNpc, 'NegTestBandit96', $player, getNpcData('NegTestBandit96') ?: [], "We're done here.", 'surrender');
$rowd = stobeNegFetchDeal(strval($r96['id'] ?? ''));
check("conversation-ender doesn't accept an NPC offer (bug 96)", ($rd['decision'] ?? '') === 'NONE' && ($rowd['status'] ?? '') === 'PROPOSED', [$rd, $rowd['status'] ?? null]);
$ra = stobeDealCaptureResponse($acceptNpc, 'NegTestBandit96', $player, getNpcData('NegTestBandit96') ?: [], 'Deal.', 'surrender');
check('"Deal." accepts an NPC offer (bug 96)', ($ra['decision'] ?? '') === 'ACCEPT', $ra);
// Bug 129: "Make it 45" -> she accepts with 45: his counter, accepted.
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestBandit96' AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE')");
$r129 = stobeDealCaptureResponse($propose, 'NegTestBandit96', $player, getNpcData('NegTestBandit96') ?: [], '', 'surrender', 'npc');
$acceptCounter = json_encode(['message'=>'Forty-five. Fine.','deal_decision'=>'ACCEPT','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>45],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],['kind'=>'SPARE','by'=>'player','target'=>'npc']])]);
$rc = stobeDealCaptureResponse($acceptCounter, 'NegTestBandit96', $player, getNpcData('NegTestBandit96') ?: [], "Make it 45 and we're done.", 'surrender');
$rowc = stobeNegFetchDeal(strval($r129['id'] ?? ''));
check('she accepts the player counter (bug 129)', ($rc['decision'] ?? '') === 'ACCEPT' && ($rowc['status'] ?? '') === 'ACCEPTED', [$rc, $rowc['status'] ?? null]);
// Bug 130: rich common bandit (cap 300) accepts "make it 400": recorded as his counter at 300, words follow.
fixtureNpc('NegTestBandit130', ['money'=>10000,'money_observed_at'=>time(),'is_in_combat'=>true], 'Bread x2', 'A timid coward.', '20/100');
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestBandit130' AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE')");
$offer300 = json_encode(['message'=>'300 cats, let me go.','deal_decision'=>'PROPOSE','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>300],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],['kind'=>'SPARE','by'=>'player','target'=>'npc']])]);
$r130 = stobeDealCaptureResponse($offer300, 'NegTestBandit130', $player, getNpcData('NegTestBandit130') ?: [], '', 'surrender', 'npc');
$say400 = 'Four hundred, then. Cats are yours, and I walk.';
$accept400 = json_encode(['message'=>$say400,'deal_decision'=>'ACCEPT','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>400],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],['kind'=>'SPARE','by'=>'player','target'=>'npc']])]);
$rk = stobeDealCaptureResponse($accept400, 'NegTestBandit130', $player, getNpcData('NegTestBandit130') ?: [], "Make it 400 and we're done.", 'surrender');
$rowk = stobeNegFetchDeal(strval($r130['id'] ?? ''));
check('accept above the cap is a counter at the cap (bug 130)', ($rk['decision'] ?? '') === 'COUNTER' && ($rowk['status'] ?? '') === 'COUNTERED'
    && str_contains(strval($rowk['terms'] ?? ''), '300') && !str_contains(strval($rowk['terms'] ?? ''), '400'), [$rk, $rowk['status'] ?? null, $rowk['terms'] ?? null]);
$ak = stobeDealSpeechAmountCheck($say400, 'NegTestBandit130', $rk);
check('her "Four hundred" is rewritten to the capped terms (bug 130)', is_array($ak) && str_contains($ak['line'], '300') && !str_contains($ak['line'], '400'), $ak);
check('"Four hundred, then." is a spoken amount (bug 130)', in_array(400, stobeDealSpokenCatsAmounts($say400), true), stobeDealSpokenCatsAmounts($say400));
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestBandit130' AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE')");
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestBandit96' AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE')");
// Bug 98: stored health says 100 %, the live "(health N%)" event decides; it isn't throttled.
fixtureNpc('NegTestBandit98', ['money'=>50,'money_observed_at'=>time(),'is_in_combat'=>true], 'Bread x2', 'A timid coward.', '100/100');
$db->exec("UPDATE stobe_negotiation_directive SET created_unix = created_unix - 5000 WHERE npc_name LIKE 'NegTestBandit%'");
storeEvent('combat', time(), 1000, "NegTestBandit98: Initiated attack (talking to: $player)");
@touch(stobeNegThrottleMarker('initiative')); // a fresh check just ran
stobeNegConsiderInitiatives('major_damage', 'NegTestBandit98: took a major hit (health 20%)', "[\"$player|hand_1\",\"NegTestBandit98|hand_3\"]", 1000);
$dir98 = $db->fetchOne("SELECT kind FROM stobe_negotiation_directive WHERE npc_name='NegTestBandit98' ORDER BY id DESC LIMIT 1");
check('live health event triggers a surrender offer (bug 98)', ($dir98['kind'] ?? '') === 'surrender', $dir98);
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name='NegTestBandit98'");
// Bug 98: the player side only "defending against" the NPC still counts as fighting the player.
fixtureNpc('NegTestBandit98b', ['money'=>50,'money_observed_at'=>time(),'is_in_combat'=>true], 'Bread x2', 'A timid coward.', '100/100');
$db->exec("UPDATE stobe_negotiation_directive SET created_unix = created_unix - 5000 WHERE kind='surrender' AND npc_name LIKE 'NegTestBandit%'");
storeEvent('combat', time(), 1000, "$player: Defending against (talking to: NegTestBandit98b)");
stobeNegConsiderInitiatives('major_damage', 'NegTestBandit98b: took a major hit (health 15%)', "[\"$player|hand_1\",\"NegTestBandit98b|hand_4\"]", 1000);
$dir98b = $db->fetchOne("SELECT kind FROM stobe_negotiation_directive WHERE npc_name='NegTestBandit98b' ORDER BY id DESC LIMIT 1");
check('defending against counts as a fight with the player (bug 98)', ($dir98b['kind'] ?? '') === 'surrender', $dir98b);
check('a defence is not an attack for deals (SPARE/truce)', count(stobeNegCombatEvents(time() - 60, 'NegTestBandit98b')) === 0 && count(stobeNegCombatEvents(time() - 60, 'NegTestBandit98b', true)) === 1);
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name='NegTestBandit98b'");
// Bug 116: a gang-mate of an NPC with a completed paid ceasefire can't attack the player; after the player attacks, he can.
storeEvent('combat', time() - 30, 1000, "NegTestBandit98b: Initiated attack (talking to: $player)"); // he fought in it
fixtureNpc('NegTestBandit116', ['money'=>50,'money_observed_at'=>time()], '', 'A timid coward.', '100/100'); // same faction, not in that fight
$db->exec("UPDATE stobe_social_contract SET status='COMPLETE', updated_at=NOW() WHERE contract_id=$1", [strval($r96['id'] ?? '')]);
check('another squad of the same faction is not covered (bug 116)', stobeNegCeasefireBlocksAttack('NegTestBandit116', 'ATTACK@' . $player) === false);
check('paid ceasefire blocks a gang-mate attacking the player (bug 116)', stobeNegCeasefireBlocksAttack('NegTestBandit98b', 'ATTACK@' . $player) === true);
storeEvent('combat', time(), 1001, "$player: Initiated attack (talking to: NegTestBandit98b)");
check('...but not after the player attacks him (bug 116)', stobeNegCeasefireBlocksAttack('NegTestBandit98b', 'ATTACK@' . $player) === false);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE contract_id=$1", [strval($r96['id'] ?? '')]);

// ---------------------------------------------------------------- 12. partner lock (Phase 3)
$db->exec("UPDATE stobe_social_contract SET updated_at=NOW() WHERE contract_id=$1", [$r['id']]);
$people = "[\"$player|hand_1\",\"NegTestBandit|hand_2\",\"Trella [Slaver Guard]|hand_3\"]";
check('unnamed line routed to partner', stobeNegPartnerForUnnamedLine('Trella [Slaver Guard]', 'Do we have a deal?', $people) === 'NegTestBandit');
check('named line respected', stobeNegPartnerForUnnamedLine('Trella [Slaver Guard]', 'Trella, back off.', $people) === '');

// ---------------------------------------------------------------- 12b. bug 119: putting on what she already wears
$wornNpc = ['equipment'=>'Black Cloth Shirt [Shoddy] x1 value 202, Black Rag Shirt [Shoddy] x1 value 96, Iron Hat [Shoddy] x1 value 526',
    'inventory'=>'Basic First Aid Kit x1 value 67'];
check('bug 119: worn item named in an equip action', stobeWornItemsMatching('Black Rag Shirt', $wornNpc) === ['Black Rag Shirt'], stobeWornItemsMatching('Black Rag Shirt', $wornNpc));
check('bug 119: an unworn copy can be equipped', stobeWornItemsMatching('Black Rag Shirt', array_merge($wornNpc, ['inventory'=>'Black Rag Shirt x1 value 96'])) === []);
check('bug 119: something she does not wear is not matched', stobeWornItemsMatching('Leather Vest', $wornNpc) === []);
$note119 = stobeWornItemRequestNote('Put your black rag shirt back on.', $wornNpc);
check('bug 119: note for "put your black rag shirt back on"', str_contains($note119, 'Black Rag Shirt') && !str_contains($note119, 'Iron Hat'), $note119);
check('bug 119: no note for taking it off', stobeWornItemRequestNote('Take your black rag shirt off.', $wornNpc) === '');
check('bug 119: no note for an item she does not wear', stobeWornItemRequestNote('Put on the leather vest.', $wornNpc) === '');

// ---------------------------------------------------------------- 12c. bug 110: loot orders run as goals
check('bug 110: loot order recognised', stobeLootOrderLine("Malzin, loot everything from the Hungry Bandit's body.") && stobeLootOrderLine('Search the bodies.'));
check('bug 110: questions and stops are not orders', !stobeLootOrderLine('Did you loot him already?') && !stobeLootOrderLine("Don't loot that one."));
check('bug 110: loot action detected', stobeLootActionPresent(['LOOT_TARGET@Hungry Bandit']) && stobeLootActionPresent(['TASK_GOAL@LOOT_AREA@all']) && !stobeLootActionPresent(['FOLLOW@Shay']));

// ---------------------------------------------------------------- 12d. bug 117: a generic name doesn't map to another load's NPC
fixtureNpc('Garvtest [Gen117 Bandit]', ['money'=>10, 'storage_id'=>'hand_111'], '', '', '100/100');
$db->exec("DELETE FROM core_npc_master WHERE LOWER(name)=LOWER('Gen117 Bandit')");
$db->exec("UPDATE core_npc_master SET original_name='Gen117 Bandit' WHERE name='Garvtest [Gen117 Bandit]'");
$g117 = getNpcData('Gen117 Bandit');
check('bug 117: without a serial the old fallback stays', is_array($g117) && $g117['name'] === 'Garvtest [Gen117 Bandit]', $g117['name'] ?? $g117);
$g117 = getNpcData('Gen117 Bandit', 111);
check('bug 117: same serial maps to the renamed NPC', is_array($g117) && $g117['name'] === 'Garvtest [Gen117 Bandit]', $g117['name'] ?? $g117);
check('bug 117: another serial does not', getNpcData('Gen117 Bandit', 222) === false);
check('bug 117: snapshot with another serial is not matched to him', (resolveSnapshotTargetNpcName('Gen117 Bandit', 'hand_222')['name'] ?? '') === 'Gen117 Bandit');
check('bug 117: snapshot with his serial is', (resolveSnapshotTargetNpcName('Gen117 Bandit', 'hand_111')['name'] ?? '') === 'Garvtest [Gen117 Bandit]');
check('bug 117: snapshot without a serial keeps the old match', (resolveSnapshotTargetNpcName('Gen117 Bandit', '')['name'] ?? '') === 'Garvtest [Gen117 Bandit]');
$db->exec("DELETE FROM core_npc_master WHERE name='Garvtest [Gen117 Bandit]'");

// ---------------------------------------------------------------- 12e. player name typed lowercase in settings: in-game casing
$savedPlayerName = $db->fetchOne("SELECT value FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [strtolower($player)]);
check('player name in-game casing from a lowercase setting', getSetting('PLAYER_NAME') === $player, getSetting('PLAYER_NAME'));
$db->exec("UPDATE general_settings SET value=$1 WHERE id='PLAYER_NAME'", [strval($savedPlayerName['value'] ?? $player)]);

// ---------------------------------------------------------------- 12f. REJECT that names her own price is a COUNTER
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestTrader' AND status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')");
$rejPrice = json_encode(['message'=>'Not for 500. 2000 cats for the hat.','deal_decision'=>'REJECT','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>2000],['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Iron Hat']])]);
$rr = stobeDealCaptureResponse($rejPrice, 'NegTestTrader', $player, getNpcData('NegTestTrader') ?: [], "I'll give you 500 cats for your hat.", 'social');
check('REJECT that names her own price is recorded as COUNTER', ($rr['decision'] ?? '') === 'COUNTER' && ($rr['status'] ?? '') === 'COUNTERED', $rr);
$rejPlain = json_encode(['message'=>'No. The hat stays.','deal_decision'=>'REJECT','deal_terms'=>'']);
check('a plain REJECT stays REJECT', (stobeDealCaptureResponse($rejPlain, 'NegTestTrader', $player, getNpcData('NegTestTrader') ?: [], 'Then 600?', 'social')['decision'] ?? '') === 'REJECT');
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestTrader' AND status IN ('PROPOSED','COUNTERED','REJECTED','ACCEPTED','AWAITING_PERFORMANCE')");

// ---------------------------------------------------------------- 12g. a take-off dropped at her feet (full pack) is known later
fixtureNpc('NegTestDrop', ['money'=>10, 'storage_id'=>'hand_555'], 'Bread x1 value 10', '', '100/100');
kfpLine('UNEQUIP_ITEM no carried section has room; dropped at feet item=0000 qty=1 source=head result=dropped', time());
kfpLine('UNEQUIP_ITEM serial=555 query=Iron Hat matched=Iron Hat result=ok', time());
$dropBlock = stobeRemovedClothingPromptBlock('NegTestDrop', getNpcData('NegTestDrop') ?: []);
check('dropped at your feet (full pack): the next turn knows', str_contains($dropBlock, 'Iron Hat') && str_contains($dropBlock, 'at your feet'), $dropBlock);
$db->exec("DELETE FROM core_npc_master WHERE name='NegTestDrop'");

// ---------------------------------------------------------------- 12h. bug 39: "Already done." to a take-off order
$npc39 = ['equipment'=>'Black Cloth Shirt [Shoddy] x1 value 202 (A shirt), Chisa Katana [Ancient] x1 value 2789 (A katana)'];
check('bug 39: done-claim without action gets UNEQUIP_ITEM',
    stobeTakeOffOrderGuard([], 'Yes. Take the katana off and keep it in your pack.', "Already done. It's in the pack, edge wrapped.", $npc39) === ['UNEQUIP_ITEM@Chisa Katana']);
check('bug 39: stow in pack -> SHEATHE replaced by UNEQUIP_ITEM',
    stobeTakeOffOrderGuard(['SHEATHE_WEAPON@'], 'Malzin, stow your katana in your pack for now.', "Fine. I'll put it away.", $npc39) === ['UNEQUIP_ITEM@Chisa Katana']);
check('bug 39: a refusal adds nothing',
    stobeTakeOffOrderGuard([], 'Take your katana off.', 'No. The katana stays on my hip.', $npc39) === null);
check('bug 39: an UNEQUIP already there is left alone',
    stobeTakeOffOrderGuard(['UNEQUIP_ITEM@Chisa Katana'], 'Take your katana off.', "Fine, I'll take it off.", $npc39) === null);
check('bug 39: an item she is not wearing adds nothing',
    stobeTakeOffOrderGuard([], 'Take your hat off.', 'Already done.', $npc39) === null);

// ---------------------------------------------------------------- 12h2. item 41: a false claim about her gear
$npc41 = ['equipment'=>'Black Cloth Shirt [Shoddy] x1 value 202, Iron Hat [Shoddy] x1 value 526, Chisa Katana [Ancient] x1 value 2789, Wooden Sandals [Shoddy] x1 value 45'];
check('item 41: "you\'ve got my blade" while she wears the katana is false',
    stobeFalseGearClaim("You've got my larder and my blade both, and I'm still standing here empty-handed.", $npc41, 'Now give me all your dried meat.') === 'Chisa Katana');
check('item 41: "you took my hat" while she wears it is false',
    stobeFalseGearClaim('You took my hat.', $npc41) === 'Iron Hat');
check('item 41: "my katana is yours" while she wears it is false',
    stobeFalseGearClaim('My katana is yours now.', $npc41) === 'Chisa Katana');
check('item 41: the player asked for the katana this turn: not checked',
    stobeFalseGearClaim("There. You've got my katana.", $npc41, 'Give me your katana.') === '');
check('item 41: an item she no longer wears is fine',
    stobeFalseGearClaim("You've got my boots.", $npc41) === '');
check('item 41: idioms are fine',
    stobeFalseGearClaim("You have my word. You've got my back.", $npc41) === '');
$fix41 = stobeDropFalseGearClaims("You've got my larder and my blade both, and I'm still standing here empty-handed. So what's the test for?", $npc41, '');
check('item 41: the false sentence is dropped, the rest kept',
    $fix41['text'] === "So what's the test for?" && count($fix41['dropped']) === 1, $fix41);
check('item 41: a reply that is only the false claim becomes "Hm."',
    stobeDropFalseGearClaims('You took my hat.', $npc41)['text'] === 'Hm.');
check('item 41: a clean reply is unchanged',
    stobeDropFalseGearClaims('Fine. The hat stays on.', $npc41)['dropped'] === []);

// ---------------------------------------------------------------- 12h3. item 43: a two-part hand-over
$npc43 = ['inventory'=>'Bread x2 value 10, Dried Meat x10 value 40, Ration Pack x1 value 60', 'equipment'=>''];
check('item 43: "all your bread and all your dried meat", bread given -> dried meat added',
    stobeInferMissingHandovers('Give me all your bread and all your dried meat.', $npc43, ['GIVE_ITEM@Shay@Bread@2'], 'You took my food.', 'Shay')
    === ['GIVE_ITEM@Shay@Dried Meat@10']);
check('item 43: "both breads", meat given -> 2 bread added',
    stobeInferMissingHandovers('Malzin, put your katana back on, and give me all your dried meat and both breads.', $npc43,
        ['GIVE_ITEM@Shay@Dried Meat@10'], 'All of it, then.', 'Shay') === ['GIVE_ITEM@Shay@Bread@2']);
check('item 43: three items, one given -> two added',
    stobeInferMissingHandovers('Hand over your bread, 5 dried meat and the ration pack.', $npc43, ['GIVE_ITEM@Shay@Bread@1'], 'Fine.', 'Shay')
    === ['GIVE_ITEM@Shay@Dried Meat@5', 'GIVE_ITEM@Shay@Ration Pack@1']);
check('item 43: she keeps one ("the meat stays") -> nothing added',
    stobeInferMissingHandovers('Give me all your bread and all your dried meat.', $npc43, ['GIVE_ITEM@Shay@Bread@2'], 'Bread, fine. The meat stays with me.', 'Shay') === []);
check('item 43: no hand-over at all (a refusal) -> nothing added',
    stobeInferMissingHandovers('Give me all your bread and all your dried meat.', $npc43, [], 'No.', 'Shay') === []);
check('item 43: both already given -> nothing added',
    stobeInferMissingHandovers('Give me all your bread and all your dried meat.', $npc43, ['GIVE_ITEM@Shay@Bread@2', 'GIVE_ITEM@Shay@Dried Meat@10'], 'Here.', 'Shay') === []);
check('item 43: something she does not carry -> nothing added',
    stobeInferMissingHandovers('Give me your bread and your katana.', $npc43, ['GIVE_ITEM@Shay@Bread@1'], 'Here.', 'Shay') === []);
check('item 43: a one-item order -> nothing added',
    stobeInferMissingHandovers('Give me your bread.', $npc43, ['GIVE_ITEM@Shay@Bread@1'], 'Here.', 'Shay') === []);

// ---------------------------------------------------------------- 12h3b. item 67: a squad member agrees but sends no GIVE_ITEM
$line67 = 'Malzin, please hand me all your bread and all your dried meat, I need it for the trip.';
check('item 67: the run m1 reply (squad member agrees, no action) -> bread + dried meat added',
    stobeInferMissingHandovers($line67, $npc43, [], "Bread and dried meat - let me check what I've actually got on me. I'm not carrying much, but whatever's there is yours.", 'Shay', true)
    === ['GIVE_ITEM@Shay@Bread@2', 'GIVE_ITEM@Shay@Dried Meat@10']);
check('item 67: one item, "here you go" -> added',
    stobeInferMissingHandovers('Give me 3 dried meat.', $npc43, [], 'Here you go.', 'Shay', true) === ['GIVE_ITEM@Shay@Dried Meat@3']);
check('item 67: a pure question ("All of it?") -> nothing added',
    stobeInferMissingHandovers($line67, $npc43, [], 'All of it?', 'Shay', true) === []);
check('item 67: a refusal -> nothing added',
    stobeInferMissingHandovers($line67, $npc43, [], "Not giving you my food. Get your own.", 'Shay', true) === []);
check('item 67: "sure" but the meat stays -> nothing (a refusal word)',
    stobeInferMissingHandovers($line67, $npc43, [], 'Sure, the bread. The meat I keep.', 'Shay', true) === []);
check('item 67: a non-faction NPC agreeing in words -> nothing added (deal/gift rules)',
    stobeInferMissingHandovers($line67, $npc43, [], "Whatever's there is yours.", 'Shay', false) === []);
check('item 67: something she does not carry -> nothing added',
    stobeInferMissingHandovers('Give me your katana.', $npc43, [], 'Here you go.', 'Shay', true) === []);

// ---------------------------------------------------------------- 12h3c. item 69: "nothing left" while she carries it
$npc69 = ['inventory'=>'Dried Meat x5 value 40, Bread x1 value 10', 'equipment'=>''];
$line69 = 'Malzin, give me all your dried meat again.';
$reply69 = "I already handed you all five strips, remember? There's nothing left in my pack but dry bread.";
$fix69 = stobeDropFalseGearClaims($reply69, $npc69, $line69);
check('item 69: the run m1 denial sentences are dropped', count($fix69['dropped']) === 2, $fix69);
check('item 69: "no dried meat left" while she has 5 is false', stobeFalseEmptyClaim('No more dried meat, sorry.', $npc69, $line69) === 'Dried Meat');
check('item 69: "Again? I already handed over what I had." (run m2) is dropped while she has the meat',
    stobeDropFalseGearClaims('Again? I already handed over what I had.', ['inventory'=>'Dried Meat x4 value 40', 'equipment'=>''], $line69)['text'] === 'Again?');
check('item 69: "I already gave you it all." is false while she has it', stobeFalseEmptyClaim('I already gave you it all.', $npc69, $line69) === 'Dried Meat');
check('item 69: "You already took the last of it - three strips, and I had none to spare after." (run m3) is false while she has it',
    stobeFalseEmptyClaim('You already took the last of it - three strips, and I had none to spare after.', $npc69, $line69) === 'Dried Meat');
check('item 69: "You\'ve had the last of it twice over now." (run m3) is false while she has it',
    stobeFalseEmptyClaim("You've had the last of it twice over now.", $npc69, $line69) === 'Dried Meat');
// item 69 (semantic): every m1-m3 denial sentence from a squad member carrying the requested meat
$npc69s = ['inventory'=>'Dried Meat x3 value 40, Bread x1 value 10', 'equipment'=>''];
foreach ([
    'I already handed you all five strips, remember?',
    "There's nothing left in my pack but dry bread.",
    'Again? I already handed over what I had.',
    'You already took the last of it - three strips, and I had none to spare after.',
    "You've had the last of it twice over now.",
    "If the hunger's still gnawing, say so plain and I'll find you something, but I can't give what I don't carry.",
    "I don't have any meat left.",
    "I'm not carrying any more.",
] as $s69) {
    check('item 69 (semantic, squad member): "' . $s69 . '" is dropped', stobeFalseEmptyClaim($s69, $npc69s, $line69, true) === 'Dried Meat', $s69);
}
check('item 69 (semantic): an ordinary sentence stays', stobeFalseEmptyClaim("Here, take them. Eat slowly.", $npc69s, $line69, true) === '');
check('item 69 (semantic): a denial about another item she names stays', stobeFalseEmptyClaim('No more bread for you though.', $npc69s, $line69, true) === '');
check('item 69 (semantic): a non-faction NPC keeps the narrow patterns', stobeFalseEmptyClaim("I can't give what I don't carry.", $npc69s, $line69, false) === '');
check('item 69: a denial about something not asked for is fine', stobeFalseEmptyClaim('No more bread.', $npc69, $line69) === '');
check('item 69: true when she really has none', stobeFalseEmptyClaim("There's nothing left.", ['inventory'=>'Bread x1 value 10'], $line69) === '');
check('item 69: no check without a hand-over request', stobeFalseEmptyClaim("There's nothing left.", $npc69, 'How are you?') === '');
unset($GLOBALS['STOBE_FALSE_EMPTY_CLAIM_ITEMS']);
check('item 69: for a squad member the false denial still hands the items over (item 67 path)',
    stobeInferMissingHandovers($line69, $npc69, [], $reply69, 'Shay', true) === ['GIVE_ITEM@Shay@Dried Meat@5'],
    stobeInferMissingHandovers($line69, $npc69, [], $reply69, 'Shay', true));
check('item 69: a non-faction NPC: nothing added', stobeInferMissingHandovers($line69, $npc69, [], $reply69, 'Shay', false) === []);
unset($GLOBALS['STOBE_FALSE_EMPTY_CLAIM_ITEMS']);

// ---------------------------------------------------------------- 12h4. item 48: knocked out or dead NPCs don't negotiate
$koEvent = static function (string $type, string $data, string $people, int $at) use ($db): void {
    $db->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ($1,$2,1000,$3,'pending',$2,$4,'')",
        [$type, $at, $data, $people]);
};
$db->exec("DELETE FROM eventlog WHERE data LIKE 'NegTestKo%' OR people LIKE '%hand_4294967000%'");
fixtureNpc('NegTestKo', ['money'=>500, 'money_observed_at'=>time(), 'storage_id'=>'hand_-296', 'is_in_combat'=>true], 'Bread x1', 'A tough raider.', '20/100');
$t48 = time() - 30;
check('item 48: no events -> conscious', stobeNegNpcOutState('NegTestKo') === '');
$koEvent('knockout', 'Shay: Knocked out by a Chisa Katana from NegTestKo', '["Shay|hand_1","NegTestKo|hand_4294967000"]', $t48);
check('item 48: knocking someone else out leaves her conscious', stobeNegNpcOutState('NegTestKo') === '');
$koEvent('knockout', 'NegTestKo: was Knocked Out.', '["NegTestKo|hand_4294967000"]', $t48 + 1);
check('item 48: knockout -> unconscious', stobeNegNpcOutState('NegTestKo') === 'unconscious');
@unlink(stobeNegThrottleMarker('initiative'));
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name='NegTestKo'");
stobeNegConsiderInitiatives('major_damage', 'NegTestKo: took a major hit (health 10%)', '["NegTestKo|hand_4294967000"]', 1000);
check('item 48: no surrender offer from a knocked-out NPC',
    $db->fetchOne("SELECT COUNT(*) AS c FROM stobe_negotiation_directive WHERE npc_name='NegTestKo'")['c'] == 0);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestKo' AND status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')");
$propose48 = json_encode(['message'=>'Pay me.','deal_decision'=>'PROPOSE','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']])]);
$d48 = stobeDealCaptureResponse($propose48, 'NegTestKo', $player, getNpcData('NegTestKo') ?: [], '', 'combat', 'npc');
$db->exec("UPDATE stobe_social_contract SET updated_at=NOW() - INTERVAL '11 minutes' WHERE contract_id=$1", [strval($d48['id'] ?? '')]);
stobeNegTick();
check('item 48: an offer does not expire while she is knocked out',
    ($db->fetchOne("SELECT status FROM stobe_social_contract WHERE contract_id=$1", [strval($d48['id'] ?? '')])['status'] ?? '') === 'PROPOSED', $d48);
stobeNegQueueDirective('NegTestKo', 'assist', '', ['actions'=>[], 'instruction'=>'x'], false);
check('item 48: a queued directive waits while she is knocked out', stobeNegClaimDirective(['NegTestKo']) === null);
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name='NegTestKo'");
$koEvent('recovered', 'NegTestKo: regained consciousness', '["NegTestKo|hand_4294967000"]', $t48 + 2);
check('item 48: recovered -> conscious again', stobeNegNpcOutState('NegTestKo') === '');
stobeNegResumeAfterKnockout('NegTestKo: regained consciousness');
$resume = $db->fetchOne("SELECT payload FROM stobe_negotiation_directive WHERE npc_name='NegTestKo' AND kind='resume_deal' ORDER BY id DESC LIMIT 1");
check('item 48: on waking she brings the open offer up again',
    is_array($resume) && str_contains(strval($resume['payload']), '200') && str_contains(strval($resume['payload']), 'knocked out'), $resume);
check('item 48: the resumed offer is open again', (stobeDealOpenForNpc('NegTestKo')['contract_id'] ?? '') === ($d48['id'] ?? 'x'));
$claimed48 = stobeNegClaimDirective(['NegTestKo']);
check('item 48: the resume directive is claimed once she is awake', ($claimed48['kind'] ?? '') === 'resume_deal', $claimed48);
$koEvent('knockout', 'NegTestKo: was Knocked Out.', '["NegTestKo|hand_4294967000"]', $t48 + 3);
$koEvent('death', 'NegTestKo: has died', '["NegTestKo|hand_4294967000"]', $t48 + 3);
check('item 48: knockout then death in the same second -> dead', stobeNegNpcOutState('NegTestKo') === 'dead');
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='NegTestKo' AND status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')");
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name='NegTestKo'");
$db->exec("DELETE FROM eventlog WHERE data LIKE 'NegTestKo%' OR people LIKE '%hand_4294967000%'");
$db->exec("DELETE FROM core_npc_master WHERE name='NegTestKo'");

// ---------------------------------------------------------------- 12h4b. item 71: the fight with the player is seen
// Run m1: Shay attacked "Dust Bandit" (named "Torek [Dust Bandit]" after); he never swung back
// under his new name, so hostile_to_player stayed false and no surrender offer came.
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTest71%' OR people LIKE '%hand_4100000071%' OR people LIKE '%hand_4100000072%'");
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name LIKE '%NegTest71%'");
$ev71 = static function (string $type, string $data, string $people) use ($db): void {
    $db->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ($1,$2,1000,$3,'pending',$2,$4,'')",
        [$type, time() - 5, $data, $people]);
};
$readyAll71 = static function () use ($db): void {
    $db->exec("UPDATE stobe_negotiation_directive SET created_unix = created_unix - 5000 WHERE kind IN ('surrender','assist')");
};
// (a) only "took a major hit from <player>" under his new name
fixtureNpc('Torek71 [NegTest71 Bandit]', ['money'=>50,'money_observed_at'=>time(),'is_in_combat'=>true,'storage_id'=>'hand_4100000071'], 'Bread x1', 'A timid coward.', '100/100');
$ev71('major_damage', "Torek71 [NegTest71 Bandit]: took a major hit from $player using Machete", "[\"Torek71 [NegTest71 Bandit]|hand_4100000071\",\"$player|hand_1\"]");
$readyAll71();
stobeNegConsiderInitiatives('major_damage', 'Torek71 [NegTest71 Bandit]: took a major hit (health 25%)', "[\"Torek71 [NegTest71 Bandit]|hand_4100000071\",\"$player|hand_1\"]", 1000);
$d71a = $db->fetchOne("SELECT kind FROM stobe_negotiation_directive WHERE npc_name='Torek71 [NegTest71 Bandit]' ORDER BY id DESC LIMIT 1");
check('item 71: "took a major hit from <player>" is a fight with the player -> surrender offer', ($d71a['kind'] ?? '') === 'surrender', $d71a);
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name LIKE '%NegTest71%'");
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTest71%'");
// (b) the fight logged under his generic name, his serial
fixtureNpc('Varn71 [NegTest71 Bandit]', ['money'=>50,'money_observed_at'=>time(),'is_in_combat'=>true,'storage_id'=>'hand_4100000072'], 'Bread x1', 'A timid coward.', '100/100');
$ev71('combat', "$player: Initiated attack (talking to: NegTest71 Bandit)", "[\"$player|hand_1\",\"NegTest71 Bandit|hand_4100000072\"]");
$ev71('combat', "NegTest71 Bandit: Initiated attack (talking to: $player)", "[\"NegTest71 Bandit|hand_4100000072\",\"$player|hand_1\"]");
$readyAll71();
stobeNegConsiderInitiatives('major_damage', 'Varn71 [NegTest71 Bandit]: took a major hit (health 25%)', "[\"Varn71 [NegTest71 Bandit]|hand_4100000072\",\"$player|hand_1\"]", 1000);
$d71b = $db->fetchOne("SELECT kind FROM stobe_negotiation_directive WHERE npc_name='Varn71 [NegTest71 Bandit]' ORDER BY id DESC LIMIT 1");
check('item 71: a fight logged under his pre-naming generic name (same serial) counts', ($d71b['kind'] ?? '') === 'surrender', $d71b);
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name LIKE '%NegTest71%'");
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTest71%'");
// another bandit with the same generic name (other serial) fighting the player: not his fight
$ev71('combat', "$player: Initiated attack (talking to: NegTest71 Bandit)", "[\"$player|hand_1\",\"NegTest71 Bandit|hand_4100000099\"]");
$ev71('combat', "NegTest71 Bandit: Initiated attack (talking to: $player)", "[\"NegTest71 Bandit|hand_4100000099\",\"$player|hand_1\"]");
$f71 = stobeNegFightEventsForNpc('Varn71 [NegTest71 Bandit]', getNpcData('Varn71 [NegTest71 Bandit]'), time() - 90);
check('item 71: a same-named bandit with another serial is not his fight', count(array_filter($f71, static fn($e) => $e['attacker'] === 'Varn71 [NegTest71 Bandit]' || $e['target'] === 'Varn71 [NegTest71 Bandit]')) === 0, $f71);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTest71%'");
foreach (['Torek71 [NegTest71 Bandit]', 'Varn71 [NegTest71 Bandit]'] as $n71) {
    $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n71]);
    $db->exec("DELETE FROM core_npc WHERE name=$1", [$n71]);
}

// ---------------------------------------------------------------- 12h4c. item 72: his offer survives his "no" to a counter
$n72 = 'Weth72 [NegTest72 Bandit]';
fixtureNpc($n72, ['money'=>500,'money_observed_at'=>time(),'is_in_combat'=>true], 'Bread x1', 'A tough raider.', '30/100');
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$n72]);
$offer72 = json_encode(['message'=>'Two hundred cats and I walk away.','deal_decision'=>'PROPOSE','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>200],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],['kind'=>'SPARE','by'=>'player','target'=>'npc']])]);
$p72 = stobeDealCaptureResponse($offer72, $n72, $player, getNpcData($n72) ?: [], '', 'surrender', 'npc');
$reject72 = json_encode(['message'=>"There's no third fifty hiding anywhere. Two hundred, and I stop swinging. That's the whole offer.",'deal_decision'=>'REJECT','deal_terms'=>'']);
$r72 = stobeDealCaptureResponse($reject72, $n72, $player, getNpcData($n72) ?: [], "Weth, two hundred isn't enough. Make it 350.");
check('item 72: his REJECT of the counter keeps his offer on the table',
    ($r72['offer_kept'] ?? false) === true && ($db->fetchOne("SELECT status FROM stobe_social_contract WHERE contract_id=$1", [strval($p72['id'] ?? '')])['status'] ?? '') === 'PROPOSED', [$p72, $r72]);
$accept72 = json_encode(['message'=>"Good. Two hundred, and we both stop. I'll get the cats out.",'deal_decision'=>'ACCEPT','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']])]);
$a72 = stobeDealCaptureResponse($accept72, $n72, $player, getNpcData($n72) ?: [], 'Fine Weth, two hundred. Deal, you can walk.');
$cats72 = array_values(array_filter($a72['terms'] ?? [], static fn($t) => ($t['kind'] ?? '') === 'GIVE_CATS'));
check('item 72: "fine, two hundred, deal" accepts HIS offer (same deal, he pays)',
    ($a72['id'] ?? '') === ($p72['id'] ?? 'x') && ($a72['status'] ?? '') === 'ACCEPTED' && ($cats72[0]['by'] ?? '') === 'npc', $a72);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name=$1 AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE','REJECTED')", [$n72]);
// (b) his offer was already rejected (old server): the accept takes it up, not a player-pays combat deal
$p72b = stobeDealCaptureResponse($offer72, $n72, $player, getNpcData($n72) ?: [], '', 'surrender', 'npc');
stobeDealTransition(strval($p72b['id'] ?? ''), 'PROPOSED', 'REJECTED', ['rejected_by'=>'npc']);
$accept72b = json_encode(['message'=>'Good. Two hundred, and we both stop.','deal_decision'=>'ACCEPT','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']])]);
$a72b = stobeDealCaptureResponse($accept72b, $n72, $player, getNpcData($n72) ?: [], 'Fine Weth, two hundred. Deal, you can walk.');
$cats72b = array_values(array_filter($a72b['terms'] ?? [], static fn($t) => ($t['kind'] ?? '') === 'GIVE_CATS'));
check('item 72: accepting a just-rejected offer of his: he pays, kind surrender',
    ($a72b['kind'] ?? '') === 'surrender' && ($cats72b[0]['by'] ?? '') === 'npc', $a72b);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name=$1 AND status NOT IN ('COMPLETE','BREACHED_PLAYER','BREACHED_NPC','IMPOSSIBLE','REJECTED')", [$n72]);
// (a) a REJECT that ends the talks still kills his offer
$p72c = stobeDealCaptureResponse($offer72, $n72, $player, getNpcData($n72) ?: [], '', 'surrender', 'npc');
stobeDealCaptureResponse(json_encode(['message'=>'Forget it. No deal.','deal_decision'=>'REJECT','deal_terms'=>'']), $n72, $player, getNpcData($n72) ?: [], 'Make it 350.');
check('item 72: "Forget it. No deal." withdraws his offer',
    ($db->fetchOne("SELECT status FROM stobe_social_contract WHERE contract_id=$1", [strval($p72c['id'] ?? '')])['status'] ?? '') === 'REJECTED');
check('item 72: "I\'ll get the cats out" flips a player-paid term to her',
    (stobeDealFixCatsDirectionFromHerWords([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200]], "I'll get the cats out.")[0]['by'] ?? '') === 'npc');
check('item 72: "you\'ll pay me, I\'ll count the cats" does not flip',
    (stobeDealFixCatsDirectionFromHerWords([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200]], "You'll pay me first, then I'll count the cats.")[0]['by'] ?? '') === 'player');
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$n72]);
$db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n72]);

// ---------------------------------------------------------------- 12h4d. item 85: reputation shapes a stranger's reply
$line85 = "Hey you. I've got a job for you, I'll pay 200 cats afterwards. Interested?";
$d85 = stobeNegReputationReplyDirective('Maeza [Hungry Bandit]', [], 'Shay', $line85, false, [2, 10]);
check('item 85: broken 10 / kept 2, a pay-later job offer -> distrust directive (up front or no deal)',
    str_contains($d85, '<reply_reputation>') && str_contains($d85, 'broken 10 deals') && str_contains($d85, 'up front'), $d85);
check('item 85: a good reputation -> nothing', stobeNegReputationReplyDirective('X', [], 'Shay', $line85, false, [46, 12]) === '');
check('item 85: a squad member -> nothing', stobeNegReputationReplyDirective('Malzin', [], 'Shay', $line85, true, [2, 10]) === '');
check('item 85: small talk -> nothing', stobeNegReputationReplyDirective('X', [], 'Shay', 'Nice weather today.', false, [2, 10]) === '');
$chat85 = file_get_contents(__DIR__ . '/../processor/chat.php');
check('item 85: the directive is added at the end of the system message, before item 77\'s',
    ($p85 = strpos($chat85, 'stobeNegReputationReplyDirective(')) !== false && $p85 < strpos($chat85, 'stobeRelationshipReplyToneDirective(')
    && $p85 > strpos($chat85, 'stobeApplyCompactChatHistory('));

// ---------------------------------------------------------------- 12h5. deal-offer cap tiers
$tierOf = static fn(string $n, array $d = []) => stobeNegWealthTier($n, $d)['tier'];
check('cap tiers: Hungry Bandit is tier 0', $tierOf('Karric [Hungry Bandit]') === 0);
check('cap tiers: Dust Bandit is tier 1', $tierOf('Haze [Dust Bandit]') === 1);
check('cap tiers: Samurai is tier 2', $tierOf('Samurai') === 2);
check('cap tiers: Shop Guard stays tier 1', $tierOf('Shop Guard Abilene') === 1);
check('cap tiers: Samurai Sergeant is tier 3', $tierOf('Ryo [Samurai Sergeant]') === 3);
check('cap tiers: Dust King (35k bounty) is tier 4', $tierOf('Dust King') === 4);
check('cap tiers: a boss with a 15k bounty is tier 3', $tierOf('Boss Whip', ['bounty'=>15000]) === 3);
check('cap tiers: Holy Lord Phoenix is tier 5', $tierOf('Holy Lord Phoenix') === 5);
// Item 53 / bug "carried reads the squad purse": 10,000 in the purse, Dust Bandit template max 200.
$capRich = stobeNegOfferCap('Vorl [Dust Bandit]', ['metadata'=>['money'=>10000]]);
check('cap tiers: a Dust Bandit with a 10,000 squad purse carries 200 (template max), offers 200',
    $capRich[0] === 200 && $capRich[1] === 200 && $capRich[3] === false, $capRich);
$capPoor = stobeNegOfferCap('Vorl [Dust Bandit]', ['metadata'=>['money'=>50]]);
check('cap tiers: no 35 % rule: 50 carried -> offers 50', $capPoor[0] === 50, $capPoor);
$capHungry = stobeNegOfferCap('Karric [Hungry Bandit]', ['metadata'=>['money'=>5000]]);
check('cap tiers: tier 0 is capped at 100', $capHungry[0] === 100, $capHungry);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='Ryo [Samurai Sergeant]'");
$capSgt = stobeNegOfferCap('Ryo [Samurai Sergeant]', ['metadata'=>['money'=>300]]);
check('cap tiers: tier 3 offers up to 10,000 with a top-up', $capSgt[0] === 10000 && $capSgt[3] === true, $capSgt);
// A tier 3 surrender offer above what he carries gets the top-up purse mode, then a cooldown.
fixtureNpc('Ryo [Samurai Sergeant]', ['money'=>300,'money_observed_at'=>time(),'is_in_combat'=>true], 'Bread x1', 'A proud soldier.', '20/100', 'United Cities');
$offerSgt = json_encode(['message'=>'Twelve thousand, spare me.','deal_decision'=>'PROPOSE','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>12000],['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],['kind'=>'SPARE','by'=>'player','target'=>'npc']])]);
$rSgt = stobeDealCaptureResponse($offerSgt, 'Ryo [Samurai Sergeant]', $player, getNpcData('Ryo [Samurai Sergeant]') ?: [], '', 'surrender', 'npc');
$rowSgt = stobeNegFetchDeal(strval($rSgt['id'] ?? ''));
check('cap tiers: tier 3 offer capped at 10,000 and marked topup',
    str_contains(strval($rowSgt['terms'] ?? ''), '10000') && str_contains(strval($rowSgt['terms'] ?? ''), 'topup'), [$rSgt, $rowSgt['terms'] ?? null]);
$tokTopup = stobeNegTermActionToken(['kind'=>'GIVE_CATS','by'=>'npc','amount'=>10000,'purse'=>'topup'], $player);
check('cap tiers: no purse suffix while NEG_CATS_PURSE_MODES is off', $tokTopup === 'GIVE_CATS@' . $player . '@10000', $tokTopup);
$db->exec("DELETE FROM general_settings WHERE id='NEG_CATS_PURSE_MODES'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('NEG_CATS_PURSE_MODES', 'true')");
$tokTopup = stobeNegTermActionToken(['kind'=>'GIVE_CATS','by'=>'npc','amount'=>10000,'purse'=>'topup'], $player);
check('cap tiers: purse suffix with NEG_CATS_PURSE_MODES on', $tokTopup === 'GIVE_CATS@' . $player . '@10000@topup', $tokTopup);
$db->exec("DELETE FROM general_settings WHERE id='NEG_CATS_PURSE_MODES'");
$normCfg = getActionRuntimeConfig('chat');
$normCfg['deal_sanctioned_give'] = true;
check('cap tiers: the action normalizer keeps @topup', normalizeActionTagToken('GIVE_CATS@' . $player . '@10000@topup', $normCfg) === 'GIVE_CATS@' . $player . '@10000@topup');
check('cap tiers: the action normalizer keeps @exact', normalizeActionTagToken('GIVE_CATS@' . $player . '@200@exact', $normCfg) === 'GIVE_CATS@' . $player . '@200@exact');
$db->exec("UPDATE stobe_social_contract SET status='COMPLETE' WHERE contract_id=$1", [strval($rSgt['id'] ?? '')]);
check('cap tiers: after a top-up deal the same NPC is on cooldown', stobeNegTopupOnCooldown('Ryo [Samurai Sergeant]') === true);
$capCool = stobeNegOfferCap('Ryo [Samurai Sergeant]', ['metadata'=>['money'=>300]]);
check('cap tiers: on cooldown he offers only what he carries', $capCool[0] === 300 && $capCool[3] === false, $capCool);
$extras = stobeNegDealPromptExtras('Vorl [Dust Bandit]', ['metadata'=>['money'=>10000]]);
check('cap tiers / item 53: her prompt names the limit and forbids "I have no money"',
    str_contains($extras, 'the most you will pay in a deal is 200') && str_contains($extras, 'do not claim you have no money'), $extras);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name='Ryo [Samurai Sergeant]'");
$db->exec("DELETE FROM core_npc_master WHERE name='Ryo [Samurai Sergeant]'");

// ---------------------------------------------------------------- 12i. item 45: a 0-Cats term doesn't void the deal
$t45 = stobeDealDropZeroCatsTerms([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>0],
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>50],
    ['kind'=>'SPARE','by'=>'player','target'=>'npc'],
]);
check('item 45: 0-Cats term dropped, the rest kept', count($t45) === 2 && ($t45[0]['by'] ?? '') === 'npc', $t45);
check('item 45: the deal then validates', stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>$t45])['ok'] === true);
check('item 45: a lone 0-Cats term is left for validation to refuse',
    count(stobeDealDropZeroCatsTerms([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>0]])) === 1);

// ---------------------------------------------------------------- 12j. item 44: her GiveCats action decides who pays
$t44 = stobeDealFixCatsDirection(
    [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>50], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']],
    ['action'=>'GiveCats','target'=>$player,'amount'=>50], $player);
check('item 44: her GiveCats to the player turns the term round', ($t44[0]['by'] ?? '') === 'npc' && ($t44[0]['to'] ?? '') === 'player', $t44);
$t44b = stobeDealFixCatsDirection(
    [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>50]], ['action'=>'Talk','target'=>$player], $player);
check('item 44: no GiveCats action, terms unchanged', ($t44b[0]['by'] ?? '') === 'player');
$t44c = stobeDealFixCatsDirection(
    [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>300]], ['action'=>'GiveCats','target'=>$player,'amount'=>50], $player);
check('item 44: a different amount is left alone', ($t44c[0]['by'] ?? '') === 'player');

// ---------------------------------------------------------------- 12k. item 46: SPARE with "to" instead of "target"
$t46 = stobeDealFixTargetField([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>50],
    ['kind'=>'SPARE','by'=>'player','to'=>'npc'],
]);
check('item 46: SPARE target taken from "to"', ($t46[1]['target'] ?? '') === 'npc' && !isset($t46[1]['to']), $t46);
check('item 46: the deal then validates', stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>$t46])['ok'] === true);
check('item 46: GIVE_CATS keeps its "to"', ($t46[0]['to'] ?? '') === 'player');
$t46b = stobeDealFixTargetField([['kind'=>'STOP_ATTACK','by'=>'npc']]);
check('item 46: an NPC STOP_ATTACK without target targets the player', ($t46b[0]['target'] ?? '') === 'player');

$t44d = stobeDealFixCatsDirectionFromWords(
    [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>50], ['kind'=>'SPARE','by'=>'player','target'=>'npc']],
    "Quarl, let's settle it: you give me 50 cats and I let you go.");
check('item 44: "you give me 50 cats" turns the term round', ($t44d[0]['by'] ?? '') === 'npc' && ($t44d[0]['to'] ?? '') === 'player', $t44d);
$t44e = stobeDealFixCatsDirectionFromWords(
    [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>50]], "I'll give you 50 cats for your hat.");
check('item 44: "I\'ll give you 50" is left alone', ($t44e[0]['by'] ?? '') === 'player');

$t46c = stobeDealFixTargetField([
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>100],
    ['kind'=>'STOP_ATTACK','by'=>'player','target'=>'npc'],
]);
check("item 46: the player's STOP_ATTACK becomes SPARE", ($t46c[1]['kind'] ?? '') === 'SPARE' && ($t46c[1]['target'] ?? '') === 'npc', $t46c);
check('item 46: that deal validates', stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>$t46c])['ok'] === true);

$t46d = stobeDealFixTargetField([['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>100], ['kind'=>'SAFE_PASSAGE','by'=>'player','to'=>'npc']]);
check("item 46: the player's SAFE_PASSAGE becomes SPARE", ($t46d[1]['kind'] ?? '') === 'SPARE' && stobeDealValidate(['parties'=>['npc'=>'a','player'=>'b'],'terms'=>$t46d])['ok'] === true, $t46d);

// ---------------------------------------------------------------- 12l. item 49: paid after being named
fixtureNpc('Weth49 [Dust Bandit]', ['money'=>10, 'storage_id'=>'hand_4949'], 'Bread x1 value 10', '', '100/100');
check('item 49: named after the deal, same serial: his payment counts', stobeNegRecipientMatches('Weth49 [Dust Bandit]', 'Dust Bandit', 4949) === true);
check('item 49: another serial (a gang-mate or an old deal) does not', stobeNegRecipientMatches('Weth49 [Dust Bandit]', 'Dust Bandit', 4950) === false);
check('item 49: no serial, no rename match', stobeNegRecipientMatches('Weth49 [Dust Bandit]', 'Dust Bandit', 0) === false);
check('item 49: attacks/actor matching stays exact', stobeNegCharMatches('Yarel [Dust Bandit]', 'Dust Bandit') === false);
$db->exec("DELETE FROM core_npc_master WHERE name='Weth49 [Dust Bandit]'");

// ---------------------------------------------------------------- 12m. item 50: reputation key ignores case
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE LOWER(player_name)='negtestcase50'");
$db->exec("INSERT INTO stobe_negotiation_reputation (player_name, player_kept, player_broken) VALUES ('negtestcase50', 1, 5)");
check('item 50: reputation read for "NegTestCase50" finds the lower-case row', str_contains(stobeNegReputationLine('NegTestCase50'), 'breaking deals'));
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE LOWER(player_name)='negtestcase50'");

// ---------------------------------------------------------------- 12n. item 51: quoting the player's offer is not a misquote
$r51 = ['decision'=>'COUNTER', 'terms'=>[['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>80], ['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Black Rag Shirt']]];
check('item 51: "Sixty cats...? Tell you what - eighty" is left alone',
    stobeDealSpeechAmountCheck("Sixty cats for the black rag? That's generous. Tell you what - eighty, and I'll hand it over.", 'NegTest51', $r51, "I'll give you 60 cats for that rag shirt. Deal?") === null);
check('item 51: without the player line it is still flagged',
    stobeDealSpeechAmountCheck("Sixty cats for the black rag? Tell you what - eighty.", 'NegTest51', $r51) !== null);
check('item 51: only the player\'s number, never hers: still rewritten',
    stobeDealSpeechAmountCheck("Sixty cats? For this? Insult money.", 'NegTest51', $r51, "I'll give you 60 cats for that rag shirt.") !== null);

// ---------------------------------------------------------------- 12o. item 52: "gives 50 cats now" is not an item
foreach (['npc gives 50 cats up front', 'npc returns 50 cats'] as $x52) {
    $t52 = stobeDealParseTermsText($x52);
    $items52 = array_filter(is_array($t52) ? $t52 : [], static fn($t) => in_array($t['kind'] ?? '', ['GIVE_ITEM','RETURN_ITEM'], true));
    check('item 52: "' . $x52 . '" is not an item term', count($items52) === 0, $t52);
}

$open44 = ['terms'=>json_encode([['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>300], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']])];
$t44f = stobeDealFixCatsDirectionFromTable([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>350], ['kind'=>'SPARE','by'=>'player','target'=>'npc']], $open44, 'Alright, 350 and you go free.');
check('item 44: "350 and you go free" with her paying on the table: she pays', ($t44f[0]['by'] ?? '') === 'npc', $t44f);
$t44g = stobeDealFixCatsDirectionFromTable([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>350]], $open44, "Fine, I'll pay you 350 instead.");
check('item 44: "I\'ll pay you 350" stays the player paying', ($t44g[0]['by'] ?? '') === 'player');
check('item 44: no deal on the table, unchanged', (stobeDealFixCatsDirectionFromTable([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>350]], null, '350.')[0]['by'] ?? '') === 'player');

// ---------------------------------------------------------------- 13. toggles
$db->exec("DELETE FROM general_settings WHERE id='NEGOTIATION_PHASE_6'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('NEGOTIATION_PHASE_6', 'false')");
check('phase 6 toggle off disables social offers', stobeNegLooksLikeSocialOffer("I'll give you 200 cats for your hat") === false);
$db->exec("UPDATE general_settings SET value='true' WHERE id='NEGOTIATION_PHASE_6'");
check('phase 6 toggle on enables social offers', stobeNegLooksLikeSocialOffer("I'll give you 200 cats for your hat") === true);

// ---------------------------------------------------------------- Item 100: another squad than the PLAYER_NAME persona
// Speaker "NegTestBeaks" (a player-faction character), persona $player: the item 43 helper must not add a second
// GIVE_ITEM for the persona; "me" is the speaking character; deal actions target it; the reputation key stays the persona.
fixtureNpc('NegTestBeaks', ['money'=>800, 'money_observed_at'=>time()], '', '', '100/100', 'Nameless');
fixtureNpc('NegTestAvarek', ['money'=>10, 'money_observed_at'=>time()], 'Bread x2 value 10', 'Loyal.', '100/100', 'Nameless');
$GLOBALS['STOBE_PLAYER_ACTOR'] = 'NegTestBeaks';
$avarek = getNpcData('NegTestAvarek');
$extra = stobeInferMissingHandovers('NegTestAvarek, give me one of your bread.', $avarek, ['GIVE_ITEM@NegTestBeaks@Bread@1'],
    'Here you go.', 'NegTestBeaks', true);
check('item 100: GIVE_ITEM to the speaking character counts (no extra GIVE_ITEM@persona)', $extra === [], $extra);
$extra2 = stobeInferMissingHandovers('NegTestAvarek, give me one of your bread.', $avarek, ['GIVE_ITEM@' . $player . '@Bread@1'],
    'Here you go.', 'NegTestBeaks', true);
check('item 100: GIVE_ITEM to the persona also counts', $extra2 === [], $extra2);
check('item 100: "me" is the speaking character', stobeGoalPersonName('me') === 'NegTestBeaks', stobeGoalPersonName('me'));
$chatSrc100 = file_get_contents(__DIR__ . '/../processor/chat.php');
check('item 100: chat.php makes an inputtext speaker the player', str_contains($chatSrc100, "\$GLOBALS['STOBE_PLAYER_ACTOR'] = \$playerName;")
    && str_contains($chatSrc100, "['inputtext', 'inputtext_s']"));
$id = makeDeal('NegTestBandit', [['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>20]], 'social');
$db->exec("UPDATE stobe_social_contract SET player_name='NegTestBeaks' WHERE contract_id=$1", [$id]);
stobeNegBeginPerformance($id, ['GIVE_CATS@NegTestBeaks@20'], 'NegTestBeaks', 1000, '');
check('item 100: the deal payment is dispatched to the speaking character', termStatus($id, 0) === 'DISPATCHED', termStatus($id, 0));
$repBefore = intval($db->fetchOne("SELECT COALESCE(MAX(npc_kept),0) AS k FROM stobe_negotiation_reputation WHERE player_name=LOWER($1)", [$player])['k'] ?? 0);
stobeLine("ACTION_EXEC: GIVE_CATS actor=NegTestBandit recipient=NegTestBeaks amount=20", time());
stobeNegTick();
check('item 100: deal with the squad character completes', status($id) === 'COMPLETE', [status($id), termStatus($id, 0)]);
$beaksRep = $db->fetchOne("SELECT 1 AS x FROM stobe_negotiation_reputation WHERE player_name='negtestbeaks'");
check('item 100: reputation stays on the persona (no row for the character)', !$beaksRep);
unset($GLOBALS['STOBE_PLAYER_ACTOR']);
check('item 100: without a speaking character, "me" is the persona (Shay/Malzin unchanged)', strcasecmp(stobeGoalPersonName('me'), $player) === 0, stobeGoalPersonName('me'));
foreach (['NegTestBeaks', 'NegTestAvarek'] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }

// ---------------------------------------------------------------- Item 106: a term on the wrong side doesn't sink the deal
$fixed = stobeDealFixWrongPerformer([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200],
    ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player'],
    ['kind'=>'SPARE','by'=>'npc','target'=>'player'],
], 'NegTestBandit');
check('item 106: SPARE by npc folds into her STOP_ATTACK (m16 18)', count($fixed) === 2
    && stobeDealValidate(['parties'=>['npc'=>'NegTestBandit','player'=>$player], 'terms'=>$fixed])['ok'] === true, $fixed);
$fixed2 = stobeDealFixWrongPerformer([['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>50], ['kind'=>'STOP_ATTACK','by'=>'player','target'=>'npc']], 'NegTestBandit');
check('item 106: STOP_ATTACK by player becomes SPARE by player', ($fixed2[1]['kind'] ?? '') === 'SPARE' && ($fixed2[1]['by'] ?? '') === 'player', $fixed2);
$fixed3 = stobeDealFixWrongPerformer([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>50], ['kind'=>'LOAN_ITEM','by'=>'player','to'=>'npc','item'=>'Katana']], 'NegTestBandit');
check('item 106: an unclear wrong-side term is dropped, the rest stands', count($fixed3) === 1 && ($fixed3[0]['kind'] ?? '') === 'GIVE_CATS', $fixed3);

// STOBE 18 m18: a verified truce (player SPARE watched 120 s first) is marked betrayed too.
$db->exec("DELETE FROM general_settings WHERE id='NEG_TEST_FORCE_BETRAYAL'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('NEG_TEST_FORCE_BETRAYAL', 'true')");
$id = makeDeal('NegTestTrader', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>42], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
$st18 = stobeNegDecode(stobeNegFetchDeal($id)['term_state']);
$st18[1]['status'] = 'VERIFIED';
$db->exec("UPDATE stobe_social_contract SET term_state=$2::jsonb, performance_started_unix = performance_started_unix - 25 WHERE contract_id=$1", [$id, json_encode($st18)]);
stobeLine("ACTION_EXEC: GIVE_CATS actor=$player recipient=NegTestTrader amount=42", time());
stobeNegTick();
check('STOBE 18 m18: a verified STOP_ATTACK is marked intentional_betrayal', status($id) === 'BREACHED_NPC' && termStatus($id, 1) === 'BETRAYED', [status($id), termStatus($id, 1)]);
$db->exec("DELETE FROM general_settings WHERE id='NEG_TEST_FORCE_BETRAYAL'");
$db->exec("DELETE FROM stobe_negotiation_directive");

// Item 108: a template never seen named is generic; recruitables, uniques and players are not.
check('item 108: "Berserker" (template, never seen named) is generic', stobeIsGenericNpcName('Berserker') === true);
check('item 108: "Kral\'s Chosen" is generic', stobeIsGenericNpcName("Kral's Chosen") === true);
check('item 108: unique "Dust King" and recruitable "Ruka" are not generic', stobeIsGenericNpcName('Dust King') === false && stobeIsGenericNpcName('Ruka') === false);
check('item 108: the player is not generic', stobeIsGenericNpcName($player) === false);

// ---------------------------------------------------------------- Item 107: a fight with any squad member makes a combat deal
fixtureNpc('NegTestBeaks107', ['money'=>800, 'money_observed_at'=>time()], '', '', '100/100', 'Nameless');
fixtureNpc('NegTestRaider107', ['money'=>50, 'money_observed_at'=>time()], '', 'A raider.');
$raider107 = getNpcData('NegTestRaider107');
check('item 107: no fight seen -> social', stobeDealKindFor($raider107) === 'social', stobeDealKindFor($raider107));
$db->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ('combat',$1,1000,$2,'pending',$1,'','')",
    [time(), 'NegTestRaider107: Initiated attack (talking to: NegTestBeaks107)']);
check('item 107: raider attacking a non-persona squad member -> combat deal', stobeDealKindFor($raider107) === 'combat', stobeDealKindFor($raider107));
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTestRaider107%'");
$db->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ('combat',$1,1000,$2,'pending',$1,'','')",
    [time(), 'NegTestRaider107: Initiated attack (talking to: NegTestTrader)']);
check('item 107: attacking an outsider is not a fight with the player', stobeDealKindFor($raider107) === 'social', stobeDealKindFor($raider107));
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTestRaider107%'");
foreach (['NegTestBeaks107', 'NegTestRaider107'] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }

// ---------------------------------------------------------------- item 90: no inferred work goal when her reply refuses
fixtureNpc('NegTestMalzin90', ['money'=>20, 'money_observed_at'=>time()], '', 'Calm.', '100/100', 'Nameless');
$malzin90 = getNpcData('NegTestMalzin90') ?: [];
check('item 90: fixture is a squad member', npcIsInPlayerFaction($malzin90));
check('item 90: "no-go" reply -> no inferred goal', stobeInferWorkGoalFromOrder('Malzin, make 2 bread.', $malzin90, [],
    "Bread's a no-go, Shay. Last time I tried, the grain silo had no power and the whole thing stalled out - unless you've got that sorted, I can't promise anything. I can try again if you want.") === '');
check('item 90: an agreeing reply still gets the goal', stobeInferWorkGoalFromOrder('Malzin, make 2 bread.', $malzin90, [],
    "Right, I'll make them. Can't promise it'll be quick.") === 'WORK_GOAL@Bread@2');
check('item 90: no reply text -> goal as before (bug 76)', stobeInferWorkGoalFromOrder('Malzin, make 2 bread.', $malzin90, []) === 'WORK_GOAL@Bread@2');
$db->exec("DELETE FROM core_npc_master WHERE name='NegTestMalzin90'");

// ---------------------------------------------------------------- STOBE A12: refusing to heal breaks a heal deal
fixtureNpc('NegTestSenlinA12', ['money'=>20, 'money_observed_at'=>time()], 'Rag Loincloth x1 value 10', 'Calm.');
$idA12 = makeDeal('NegTestSenlinA12', [['kind'=>'FIRST_AID', 'by'=>'player', 'target'=>'npc', 'when'=>'after_npc'],
    ['kind'=>'GIVE_ITEM', 'by'=>'npc', 'to'=>'player', 'item'=>'Rag Loincloth']], 'social');
stobeNegBeginPerformance($idA12, ['GIVE_ITEM@' . $player . '@Rag Loincloth@1'], $player, 1000, '');
$noteA12 = stobeNegPlayerRefusal('NegTestSenlinA12', $player, "Thanks. I'm not going to heal you, though.");
check('A12: "not going to heal you" is a refusal of the heal term', $noteA12 !== '' && termStatus($idA12, 0) === 'UNMET', [$noteA12, termStatus($idA12, 0)]);
$db->exec("DELETE FROM core_npc_master WHERE name='NegTestSenlinA12'");

// ---------------------------------------------------------------- item 29: a corrected held-back line doesn't repeat the spoken start
check('item 29: spoken sentences are not said again', function_exists('stobeDealDropSpokenSentences')
    && stobeDealDropSpokenSentences("Sure thing. The road north is quiet tonight. Then we're done.", 'Sure thing.  The road north is quiet tonight.') === "Then we're done.");
check('item 29: nothing spoken yet -> the whole line', function_exists('stobeDealDropSpokenSentences')
    && stobeDealDropSpokenSentences('You still owe me 100 Cats.', '') === 'You still owe me 100 Cats.');
check('item 29: chat.php speaks only the unspoken part', str_contains(file_get_contents(dirname(__DIR__) . '/processor/chat.php'), 'stobeDealDropSpokenSentences('));

// ---------------------------------------------------------------- cleanup
$db->exec("DELETE FROM stobe_social_contract WHERE player_name=$1", [$player]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE LOWER(player_name)=LOWER($1)", [$player]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTest%'");
foreach ([$player, 'NegTestBandit', 'NegTestTrader', 'NegTestBandit130'] as $n) {
    $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]);
    $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]);
}
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
