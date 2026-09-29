<?php
// Full-review regressions (2026-09-29). Run on a test DB:
//   STOBE_DB_NAME=stobe_test STOBE_NEG_TEST_NO_SIGNAL=1 STOBE_NEG_STOBE_LOG=/tmp/negtest/stobe.log \
//   STOBE_NEG_KFP_LOG=/tmp/negtest/kfp.log STOBE_NEG_ACTION_REQUEST=/tmp/negtest/stobe_action.request php tests/negotiation_review_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = 'RevTestPlayer';
$npc = 'RevTestNpc';
$log = strval(getenv('STOBE_NEG_STOBE_LOG'));
$kfp = strval(getenv('STOBE_NEG_KFP_LOG'));
@mkdir(dirname($log), 0777, true);
file_put_contents($log, ''); file_put_contents($kfp, '');
function stobeLine(string $body, int $ts): void {
    $log = strval(getenv('STOBE_NEG_STOBE_LOG'));
    file_put_contents($log, '[' . gmdate('Y-m-d H:i:s', $ts - 21600) . '.000] [Stobe] ' . $body . "\n", FILE_APPEND);
    touch($log, time());
    stobeNegResetLogCache();
}
$db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [$player]);
foreach ([$player, $npc, 'RevTestNpcley'] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
$db->exec("INSERT INTO core_npc_master (name, metadata, inventory, faction, created_at, updated_at) VALUES ($1, $2::jsonb, 'Grog x2 value 40', 'Nameless', NOW(), NOW())", [$player, json_encode(['money'=>1000,'money_observed_at'=>time()])]);
$db->exec("INSERT INTO core_npc_master (name, metadata, faction, created_at, updated_at) VALUES ($1, $2::jsonb, 'Bar Thugs', NOW(), NOW())", [$npc, json_encode(['storage_id'=>'hand_4242','money'=>10,'money_observed_at'=>time()])]);
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name IN ($1, 'RevTestNpcley')", [$npc]);
$db->exec("DELETE FROM stobe_negotiation_directive");
function mk(array $terms, string $kind = 'combat'): string {
    global $npc, $player;
    $r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'terms'=>$terms, 'context'=>[], 'kind'=>$kind]);
    stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
    return $r['id'];
}
function st(string $id): array { return stobeNegDecode(stobeNegFetchDeal($id)['term_state']); }
function back(string $id, int $s): void {
    $state = st($id);
    foreach ($state as &$t) { if (!empty($t['dispatched_unix'])) $t['dispatched_unix'] -= $s; if (!empty($t['deadline_unix'])) $t['deadline_unix'] -= $s; }
    unset($t);
    $GLOBALS['db']->exec("UPDATE stobe_social_contract SET term_state=$2::jsonb, performance_started_unix=performance_started_unix-$3 WHERE contract_id=$1", [$id, json_encode($state), $s]);
}

// F1 names
check('F1 exact name', stobeNegCharMatches('Hax', 'hax'));
check('F1 no substring', !stobeNegCharMatches('Haxley', 'Hax'));
check('F1 bracket title', stobeNegCharMatches('Trella [Slaver Guard Resule]', 'Trella'));
$id = mk([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>50]], 'social');
stobeNegBeginPerformance($id, [], $player, 1000, '');
stobeLine("ACTION_EXEC: GIVE_CATS actor=$player recipient=RevTestNpcley amount=50", time());
stobeNegTick();
check('F1 payment to a similarly named NPC does not count', (st($id)[0]['status'] ?? '') === 'AWAITING_PLAYER', st($id)[0] ?? null);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE contract_id=$1", [$id]);

// F3 serial recovery
check('F3 serial from storage_id', stobeNegSerialFromStorage($npc) === 4242);
$id = mk([['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
@unlink(strval(getenv('STOBE_NEG_ACTION_REQUEST')));
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
check('F3 deal without serial still sends STOP_FIGHT', str_contains(@file_get_contents(strval(getenv('STOBE_NEG_ACTION_REQUEST'))) ?: '', "4242\tSTOP_FIGHT"));
check('F3 serial saved on the deal', intval(stobeNegFetchDeal($id)['npc_serial']) === 4242);

// N4 squad hits inside the 10 s grace period do not count
back($id, 5);
storeEvent('combat', time(), 1000, "$player: Initiated attack (talking to: $npc)");
stobeNegTick();
check('N4 squad attack in grace period ignored', stobeNegFetchDeal($id)['status'] === 'AWAITING_PERFORMANCE' && empty(st($id)[0]['player_broke_truce']), [stobeNegFetchDeal($id)['status'], st($id)[0] ?? null]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%RevTest%'");
// F6 player restarts the fight -> player breach, not NPC failure
back($id, 12);
storeEvent('combat', time(), 1000, "$player: Initiated attack (talking to: $npc)");
storeEvent('combat', time() + 1, 1000, "$npc: Initiated attack (talking to: $player)");
stobeNegTick();
check('F6 player attacking first = BREACHED_PLAYER', stobeNegFetchDeal($id)['status'] === 'BREACHED_PLAYER', [stobeNegFetchDeal($id)['status'], st($id)]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%RevTest%'");
$db->exec("DELETE FROM stobe_negotiation_directive");

// F11 faction mates ignored for a personal brawl
$db->exec("INSERT INTO core_npc_master (name, metadata, faction, created_at, updated_at) VALUES ('RevTestNpcley', '{}'::jsonb, 'Bar Thugs', NOW(), NOW())");
$id = mk([['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]);
stobeNegBeginPerformance($id, ['STOP_ATTACK@' . $player], $player, 1000, '');
back($id, 5);
stobeLine("ACTION_EXEC: STOP_ATTACK actor=$npc target=$player applied=0 truce_registered=0 reason=faction_not_supported", time() - 4);
file_put_contents($kfp, '[' . gmdate('H:i:s', time() - 4 - 21600) . ".000] [stobe] ACTION_BRIDGE command=STOP_FIGHT actor=4242 target=0 arg= result=ok\n");
touch($kfp, time()); stobeNegResetLogCache();
storeEvent('combat', time(), 1000, "RevTestNpcley: Initiated attack (talking to: $player)");
stobeNegTick();
check('F11 faction mate attack ignored when only STOP_FIGHT applied', (st($id)[0]['status'] ?? '') === 'DISPATCHED' && intval(st($id)[0]['reissues'] ?? 0) === 0, st($id)[0] ?? null);
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE contract_id=$1", [$id]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%RevTest%'");

// F7 payment made just before the deal was recorded counts
$db->exec("UPDATE stobe_social_contract SET updated_at = NOW() - INTERVAL '10 minutes' WHERE npc_name=$1", [$npc]);
stobeLine("ACTION_EXEC: GIVE_CATS actor=$player recipient=$npc amount=200", time() - 20);
$id = mk([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200], ['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Iron Hat','when'=>'after_player']], 'social');
stobeNegBeginPerformance($id, [], $player, 1000, '');
stobeNegTick();
check('F7 pre-deal payment counted', (st($id)[0]['status'] ?? '') === 'VERIFIED', st($id)[0] ?? null);

// F4 settle retries are bounded
for ($i = 0; $i < 10; $i++) {
    if (stobeNegFetchDeal($id)['status'] !== 'AWAITING_PERFORMANCE') break;
    $db->exec("UPDATE stobe_negotiation_directive SET created_unix = created_unix - 100 WHERE contract_id=$1", [$id]);
    stobeNegTick();
}
check('F4 undeliverable settle gives up', (st($id)[1]['status'] ?? '') === 'IMPOSSIBLE', st($id)[1] ?? null);
check('F4 capped directive count', intval($db->fetchOne("SELECT COUNT(*) AS c FROM stobe_negotiation_directive WHERE contract_id=$1 AND kind='settle'", [$id])['c']) <= 3);

// F8 refund when the NPC's side fell through after the player paid
check('F8 deal impossible', stobeNegFetchDeal($id)['status'] === 'IMPOSSIBLE', stobeNegFetchDeal($id)['status']);
$refund = $db->fetchOne("SELECT payload FROM stobe_negotiation_directive WHERE contract_id=$1 AND kind='refund'", [$id]);
check('F8 refund of 200 queued', is_array($refund) && str_contains($refund['payload'], 'GIVE_CATS@' . $player . '@200'), $refund);

// F9 refund/breach ride on a chat reply
$acts = stobeNegAttachPendingForChat($npc, []);
$db->exec("UPDATE stobe_negotiation_directive SET created_unix = created_unix - 300 WHERE kind='refund'");
check('F9 refund attached to chat reply (even minutes later)', in_array('GIVE_CATS@' . $player . '@200', $acts, true), $acts);
$db->exec("DELETE FROM stobe_negotiation_directive");

// F10 partner lock ignores calm social deals
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name=$1 AND status NOT IN ('COMPLETE','IMPOSSIBLE','BREACHED_PLAYER','BREACHED_NPC','EXPIRED')", [$npc]);
$sid = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'terms'=>[['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>5]], 'context'=>[], 'kind'=>'social']);
check('F10 social deal does not hijack other conversations', stobeNegPartnerForUnnamedLine('Slant', 'How are you?', "[\"$npc|hand_4242\",\"Slant|hand_5\"]") === '');
$db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE contract_id=$1", [$sid['id']]);

// F11 offer filter
check('F11 everyday phrase is not an offer', !stobeNegLooksLikeSocialOffer('Thanks for your help back there.'));
check('F11 "I promise" alone is not an offer', !stobeNegLooksLikeSocialOffer('I promise I will be careful.'));
check('F11 real offer still recognised', stobeNegLooksLikeSocialOffer("I'll give you 200 cats to take off your hat"));
check('F11 drink offer recognised', stobeNegLooksLikeSocialOffer("I'll buy you a drink if you take off your hat"));

// F12 display names
$names = stobeNegInventoryDisplayNames('Grog x2 value 40, Dried Meat [Shoddy] x1 value 5');
check('F12 keeps game spelling', ($names['grog'] ?? '') === 'Grog' && ($names['dried meat'] ?? '') === 'Dried Meat', $names);

foreach ([$player, $npc, 'RevTestNpcley'] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE player_name=$1", [$player]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%RevTest%'");
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
