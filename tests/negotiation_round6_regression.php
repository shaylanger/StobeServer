<?php
// 2026-09-29 Malzin installments / Slant hat + fight report. STOBE_DB_NAME=stobe_test php tests/negotiation_round6_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$npc = 'RoundSixNpc';
$stobeLog = getenv('STOBE_NEG_STOBE_LOG');
$cleanup = function () use ($db, $npc) {
    $db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
    $db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name=$1", [$npc]);
    $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$npc]);
};
$cleanup();
$db->exec("INSERT INTO core_npc_master (name, metadata, equipment, faction, created_at, updated_at) VALUES ($1, '{}'::jsonb, 'Bucket Zukin x1', 'Bar Thugs', NOW(), NOW())", [$npc]);
$payLine = function (int $amount) use ($stobeLog, $player, $npc) {
    file_put_contents($stobeLog, '[' . date('Y-m-d H:i:s') . '.000] [Stobe] ACTION_EXEC: GIVE_CATS actor=' . $player . ' recipient=' . $npc . ' amount=' . $amount . "\n", FILE_APPEND);
    if (function_exists('stobeNegResetLogCache')) stobeNegResetLogCache();
};

// --- B: names and timing words are normalised.
$n = stobeDealNormalizeConditionalTerms([
    ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>$player],
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>$npc,'amount'=>1500,'when'=>'immediate'],
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>1000,'when'=>'within_a_day'],
    ['kind'=>'GIVE_CATS','by'=>'npc','to'=>'player','amount'=>300,'when'=>'if_you_lose'],
], $npc, $player);
check('player name target -> player', ($n[0]['target'] ?? '') === 'player', $n[0]);
check('npc name recipient -> npc', ($n[1]['to'] ?? '') === 'npc', $n[1]);
check('"immediate" -> now (real term)', ($n[1]['kind'] ?? '') === 'GIVE_CATS' && ($n[1]['when'] ?? '') === 'now', $n[1]);
check('"within a day" player payment -> after_npc', ($n[2]['kind'] ?? '') === 'GIVE_CATS' && ($n[2]['when'] ?? '') === 'after_npc', $n[2]);
check('bet condition still PROMISE', ($n[3]['kind'] ?? '') === 'PROMISE', $n[3]);
$v = stobeDealValidate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'combat', 'context'=>[], 'terms'=>[$n[0], $n[1]]]);
check('normalised ceasefire deal validates', !empty($v['ok']), $v);

// --- A + C: installments; one payment cannot cover two; NPC delivers after the first part.
$terms = [
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>150],
    ['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Bucket Zukin','when'=>'after_player'],
    ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>150,'when'=>'after_npc'],
];
$r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'social', 'context'=>[], 'terms'=>$terms]);
check('installment deal created', !empty($r['ok']), $r);
stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
stobeNegBeginPerformance($r['id'], [], $player, stobeNegLatestGamets(), '');
$payLine(150);
stobeNegTickDeal(stobeNegFetchDeal($r['id']), $player, time());
$d = stobeNegFetchDeal($r['id']);
$st = stobeNegDecode($d['term_state']);
check('first installment verified', ($st[0]['status'] ?? '') === 'VERIFIED', $st[0] ?? null);
check('second installment NOT verified by the same 150', ($st[2]['status'] ?? '') === 'AWAITING_PLAYER', $st[2] ?? null);
check('NPC delivery released after first part', in_array($st[1]['status'] ?? '', ['SETTLE_QUEUED','DISPATCHED'], true), $st[1] ?? null);
check('deal still open', ($d['status'] ?? '') === 'AWAITING_PERFORMANCE', $d['status'] ?? null);
check('owed now 150', stobeNegPlayerOwedCats($npc) === 150, stobeNegPlayerOwedCats($npc));
$note = stobeNegPlayerRefusal($npc, $player, "Whatever, I'm not paying you the hundred fifty that I owe you.");
check('refusal of second part is a breach', str_contains($note, 'refused'), $note);
check('refusal note demands a decision', str_contains($note, 'Decide right now'), $note);
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name=$1", [$npc]);

// --- D: gift gate respects the deal ledger.
$cfg = stobeBuildActionConfigForNpc('chat', false);
$cfg = array_merge($cfg, ['player_name'=>$player, 'allowlist'=>[], 'player_affinity'=>0, 'npc_name'=>$npc]);
check('no deal: gift blocked', normalizeActionTagToken('GIVE_ITEM@' . $player . '@Bucket Zukin@1', $cfg) === '');
$r2 = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'social', 'context'=>[],
    'terms'=>[['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Bucket Zukin','when'=>'after_player'], ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>1500]]]);
check('open deal: hand-over allowed', normalizeActionTagToken('GIVE_ITEM@' . $player . '@Bucket Zukin@1', $cfg) !== '');

// --- E: claimed payment with no deal and nothing moved.
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$p = stobeNegParseHandover("Okay, here's the extra one thousand.", $npc, $player);
check('claimed payment with nothing moved is flagged', in_array($p['reason'] ?? '', ['claimed_nothing_moved','insufficient_cats'], true), $p);

// --- F: accepting the NPC's last offer.
$r3 = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'combat', 'context'=>[],
    'terms'=>[['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>500], ['kind'=>'STOP_ATTACK','by'=>'npc','target'=>'player']]]);
stobeDealTransition($r3['id'], 'PROPOSED', 'COUNTERED');
check('"Five hundred deal." binds the NPC', str_contains(stobeNegPlayerAcceptsOfferNote($npc, 'Five hundred deal.'), 'binding'));
check('"Deal, stop attacking me." binds the NPC', str_contains(stobeNegPlayerAcceptsOfferNote($npc, 'Deal, stop attacking me.'), 'binding'));
check('a different number is a counter', stobeNegPlayerAcceptsOfferNote($npc, 'Okay, three hundred.') === '');
check('a refusal is not acceptance', stobeNegPlayerAcceptsOfferNote($npc, 'No deal.') === '');

// --- G: personal fight registration.
setConfOpt('STOBE_PERSONAL_FIGHTS', '{}', true);
stobeNegRegisterPersonalFight($npc, ['faction'=>'Bar Thugs'], "Alright. If that's how you want it settled, I'll oblige.");
$f = json_decode(strval(getConfOpt('STOBE_PERSONAL_FIGHTS', '{}')), true);
check('personal fight registered', isset($f[strtolower($npc)]), $f);
setConfOpt('STOBE_PERSONAL_FIGHTS', '{}', true);
stobeNegRegisterPersonalFight($npc, ['faction'=>'Bar Thugs'], 'Boys, get him!');
$f = json_decode(strval(getConfOpt('STOBE_PERSONAL_FIGHTS', '{}')), true);
check('calling for help is not a personal fight', count($f) === 0, $f);
setConfOpt('STOBE_PERSONAL_FIGHTS', '{}', true);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
