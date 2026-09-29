<?php
// Regressions from the 2026-09-28 Malzin/Hax session. Run on a test DB:
//   STOBE_DB_NAME=stobe_test STOBE_NEG_TEST_NO_SIGNAL=1 STOBE_NEG_STOBE_LOG=/tmp/negtest/stobe.log \
//   STOBE_NEG_KFP_LOG=/tmp/negtest/kfp.log STOBE_NEG_ACTION_REQUEST=/tmp/negtest/stobe_action.request php tests/negotiation_hax_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = 'HaxTestPlayer';
$npc = 'HaxTestNpc';
$db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [$player]);
foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
$db->exec("INSERT INTO core_npc_master (name, metadata, faction, created_at, updated_at) VALUES ($1, $2::jsonb, 'Nameless', NOW(), NOW())", [$player, json_encode(['money'=>1840, 'money_observed_at'=>time()])]);
$db->exec("INSERT INTO core_npc_master (name, metadata, faction, created_at, updated_at) VALUES ($1, '{\"is_in_combat\":true}'::jsonb, 'Bar Thugs', NOW(), NOW())", [$npc]);
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$req = strval(getenv('STOBE_NEG_ACTION_REQUEST'));
@mkdir(dirname($req), 0777, true);
@unlink($req);
$p = fn(string $m) => stobeNegParseHandover($m, $npc, $player);

// 1. The Malzin line must never pay.
$malzin = "I mean I was just calling you ugly, I offered to pay you two hundred cats to take your hat off and then I seen your face and I was like there's no way I'm paying for that.";
check('reported/negated speech does not pay', $p($malzin)['cats'] === 0, $p($malzin));
check('"I don\'t want to pay you" does not pay', $p("Nah, I don't want to pay you 200 cats.")['cats'] === 0);
check('real hand-over still pays', $p("Here's your eight hundred cats.")['cats'] === 800);
check('two sentences: only the hand-over counts', $p("Fine. Here are 300 cats.")['cats'] === 300);

// 2. Bets become promises, never an immediate refund.
$bet = json_encode(['message'=>'Deal.','deal_decision'=>'ACCEPT','deal_terms'=>json_encode([
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>800],
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>800,'when'=>'after_player','condition'=>'if Hax loses the fight'],
])]);
$r = stobeDealCaptureResponse($bet, $npc, $player, ['name'=>$npc], 'bet', 'social');
$terms = $r['terms'] ?? [];
check('bet accepted', !empty($r['ok']) && ($r['decision'] ?? '') === 'ACCEPT', $r);
check('conditional refund stored as PROMISE', ($terms[1]['kind'] ?? '') === 'PROMISE' && str_contains(strval($terms[1]['text'] ?? ''), 'loses'), $terms);
check('no refund action at acceptance', stobeNegAcceptActions($terms, $player, 'social') === [], stobeNegAcceptActions($terms, $player, 'social'));
$invalidWhen = stobeDealNormalizeConditionalTerms([['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>300,'when'=>'if_player_wins']]);
check('unknown "when" becomes a promise instead of rejecting the deal', ($invalidWhen[0]['kind'] ?? '') === 'PROMISE', $invalidWhen);
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);

// 3. Combat acceptance without an explicit ceasefire term gets one.
$accept = json_encode(['message'=>'Fine.','deal_decision'=>'ACCEPT','deal_terms'=>json_encode([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>500]])]);
$r = stobeDealCaptureResponse($accept, $npc, $player, ['name'=>$npc], 'stop', 'combat');
$kinds = array_map(fn($t) => $t['kind'] ?? '', $r['terms'] ?? []);
check('combat accept gains STOP_ATTACK', !empty($r['ok']) && in_array('STOP_ATTACK', $kinds, true), $r);

// 4. "Here's your cats" after the deal is closed pays what is owed.
$db->exec("UPDATE stobe_social_contract SET npc_serial=55501 WHERE contract_id=$1", [$r['id']]);
stobeNegBeginPerformance($r['id'], ['STOP_ATTACK@' . $player], $player, 1000, '');
check('individual disengage sent with the ceasefire', is_file($req) && str_contains(file_get_contents($req), "55501\tSTOP_FIGHT"), is_file($req) ? file_get_contents($req) : 'no request file');
check('"Here\'s your cats." pays the 500 owed', $p("Here's your cats.")['cats'] === 500, $p("Here's your cats."));

// 5. Faction refusal + successful STOP_FIGHT = truce still counts.
$log = strval(getenv('STOBE_NEG_STOBE_LOG'));
$kfp = strval(getenv('STOBE_NEG_KFP_LOG'));
file_put_contents($log, '[' . gmdate('Y-m-d H:i:s', time() - 21600) . ".000] [Stobe] ACTION_EXEC: STOP_ATTACK actor=$npc target=$player speaker_faction='' target_faction='' applied=0 truce_registered=0 reason=faction_not_supported\n");
touch($log, time());
file_put_contents($kfp, '[' . gmdate('H:i:s', time() - 21600) . ".000] [stobe] ACTION_BRIDGE command=STOP_FIGHT actor=55501 target=0 arg= result=ok\n");
touch($kfp, time());
stobeNegResetLogCache();
stobeNegTick();
$state = stobeNegDecode(stobeNegFetchDeal($r['id'])['term_state']);
$stop = array_values(array_filter($state, fn($t) => ($t['kind'] ?? '') === 'STOP_ATTACK'))[0] ?? [];
check('faction refusal tolerated when STOP_FIGHT worked', ($stop['status'] ?? '') === 'DISPATCHED', $stop);

// 6. Re-acquired target: re-issue goes straight to STOP_FIGHT.
@unlink($req);
storeEvent('combat', time(), 1000, "$npc: Initiated attack (talking to: $player)");
$d = stobeNegFetchDeal($r['id']);
$st = stobeNegDecode($d['term_state']);
foreach ($st as &$t) { if (($t['kind'] ?? '') === 'STOP_ATTACK') $t['dispatched_unix'] = time() - 10; }
unset($t);
$db->exec("UPDATE stobe_social_contract SET term_state=$2::jsonb WHERE contract_id=$1", [$r['id'], json_encode($st)]);
stobeNegTick();
$state = stobeNegDecode(stobeNegFetchDeal($r['id'])['term_state']);
$stop = array_values(array_filter($state, fn($t) => ($t['kind'] ?? '') === 'STOP_ATTACK'))[0] ?? [];
check('re-acquire re-sends STOP_FIGHT at once', is_file($req) && ($stop['reissues'] ?? 0) === 1 && ($stop['status'] ?? '') === 'DISPATCHED', [$stop['status'] ?? null, $stop['reissues'] ?? null, is_file($req)]);

$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$db->exec("DELETE FROM eventlog WHERE data LIKE '%HaxTest%'");
foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
@unlink($req);
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
