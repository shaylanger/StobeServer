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
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE player_name=$1", [$player]);
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
$rep = $db->fetchOne("SELECT player_kept FROM stobe_negotiation_reputation WHERE player_name=$1", [$player]);
check('reputation kept +1', intval($rep['player_kept'] ?? 0) === 1, $rep);
$mem = $db->fetchOne("SELECT data FROM eventlog WHERE type='injection' AND data LIKE 'NegTestBandit: [deal outcome]%' ORDER BY rowid DESC LIMIT 1");
check('memory event stored', is_array($mem), $mem);

// ---------------------------------------------------------------- 4. non-payment -> BREACHED_PLAYER + angry directive
$id = makeDeal('NegTestBandit', [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>300], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
backdate($id, 61);
stobeNegTick();
check('unpaid -> BREACHED_PLAYER', status($id) === 'BREACHED_PLAYER', status($id));
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

// ---------------------------------------------------------------- 6c. bug 127: the squad gets 10 s to stop swinging after sparing
$id = makeDeal('NegTestBandit', [['kind'=>'SPARE','by'=>'player','target'=>'npc']], 'surrender');
stobeNegBeginPerformance($id, [], $player, 1000, '');
backdate($id, 5);
storeEvent('combat', time(), 1000, "$player: Initiated attack (talking to: NegTestBandit)");
stobeNegTick();
check('bug 127: a swing 5 s after sparing is not a breach', termStatus($id, 0) !== 'UNMET', [status($id), termStatus($id, 0)]);
backdate($id, 7);
storeEvent('combat', time(), 1000, "$player: Initiated attack (talking to: NegTestBandit)");
stobeNegTick();
check('bug 127: an attack 12 s after sparing is', termStatus($id, 0) === 'UNMET', [status($id), termStatus($id, 0)]);

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
check('item 49: "Weth [Dust Bandit]" matches the deal\'s "Dust Bandit"', stobeNegCharMatches('Weth [Dust Bandit]', 'Dust Bandit') === true);
check('item 49: and the other way round', stobeNegCharMatches('Dust Bandit', 'Weth [Dust Bandit]') === true);
check('item 49: a different generic name does not match', stobeNegCharMatches('Weth [Dust Bandit]', 'Dust Bandit Bowman') === false);
check('item 49: two different named NPCs do not match', stobeNegCharMatches('Weth [Dust Bandit]', 'Yarel [Dust Bandit]') === false);

// ---------------------------------------------------------------- 13. toggles
$db->exec("DELETE FROM general_settings WHERE id='NEGOTIATION_PHASE_6'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('NEGOTIATION_PHASE_6', 'false')");
check('phase 6 toggle off disables social offers', stobeNegLooksLikeSocialOffer("I'll give you 200 cats for your hat") === false);
$db->exec("UPDATE general_settings SET value='true' WHERE id='NEGOTIATION_PHASE_6'");
check('phase 6 toggle on enables social offers', stobeNegLooksLikeSocialOffer("I'll give you 200 cats for your hat") === true);

// ---------------------------------------------------------------- cleanup
$db->exec("DELETE FROM stobe_social_contract WHERE player_name=$1", [$player]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE player_name=$1", [$player]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%NegTest%'");
foreach ([$player, 'NegTestBandit', 'NegTestTrader', 'NegTestBandit130'] as $n) {
    $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]);
    $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]);
}
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
