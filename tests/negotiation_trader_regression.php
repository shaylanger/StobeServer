<?php
// 2026-09-29 Namura/Seblete test report fixes. Run on a test DB: STOBE_DB_NAME=stobe_test php tests/negotiation_trader_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = 'TraderTestPlayer';
$npc = 'TraderTestNpc';
$cleanup = function () use ($db, $player, $npc) {
    $db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
    foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
};
$cleanup();
$db->exec("INSERT INTO core_npc_master (name, metadata, inventory, faction, created_at, updated_at) VALUES ($1, $2::jsonb, 'Dried Fish x2 value 10', 'Nameless', NOW(), NOW())",
    [$player, json_encode(['money'=>3000, 'money_observed_at'=>time()])]);
$db->exec("INSERT INTO core_npc_master (name, metadata, inventory, faction, created_at, updated_at) VALUES ($1, '{}'::jsonb, 'Oat Straw x5', 'Tech Traders', NOW(), NOW())", [$npc]);

// --- Offer detection.
foreach ([
    "Here's a deal, I'll buy one oat straw off you for two hundred cats." => true,
    "I'll buy one oat straw for two hundred cats" => true,
    "Can I buy some oat straw from you?" => true,
    "Sell me your hat for 50 counts" => true,
    "I'll give you some dried fish for your pants" => true,
    "Thanks for your help" => false,
    "Do you have oat straw?" => false,
    "Hey Namura." => false,
] as $line => $want) {
    check(($want ? 'offer: ' : 'not an offer: ') . $line, stobeNegLooksLikeSocialOffer($line) === $want);
}

// --- Bracketed item names match what was really sent.
$state = stobeNegBuildTermState(
    [['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Rag Loincloth [Prototype]','quantity'=>1]],
    ['GIVE_ITEM@shay@Rag Loincloth Prototype@1'], 'Shay', time(), 'social', time() + 60);
check('bracketed item counts as dispatched', ($state[0]['status'] ?? '') === 'DISPATCHED', $state);

// --- Saying "I'm not gonna pay" while owing breaks the deal.
$mk = function (array $terms) use ($npc, $player) {
    $r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'social', 'context'=>[], 'terms'=>$terms]);
    stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
    stobeNegBeginPerformance($r['id'], [], $player, 1000, '');
    return $r['id'];
};
$id = $mk([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200], ['kind'=>'PROMISE','by'=>'npc','text'=>'already handed over the oat straw']]);
check('question is not a refusal', stobeNegPlayerRefusal($npc, $player, "Didn't I say I was gonna pay you for it and then now I said no I won't?") === '');
check('reported speech is not a refusal', stobeNegPlayerRefusal($npc, $player, "He said he won't pay you.") === '');
$note = stobeNegPlayerRefusal($npc, $player, "Cool, I'm not gonna pay you.");
check('refusal produces NPC note', str_contains($note, 'refused') && str_contains($note, '200 Cats'), $note);
$row = $db->fetchOne('SELECT status FROM stobe_social_contract WHERE contract_id=$1', [$id]);
check('refusal breaks the deal (BREACHED_PLAYER)', ($row['status'] ?? '') === 'BREACHED_PLAYER', $row);
check('no open debt: refusal does nothing', stobeNegPlayerRefusal($npc, $player, "I'm not gonna pay you.") === '');

// --- A finished deal is not re-opened by a sign-off line with the same terms.
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$terms = [['kind'=>'GIVE_ITEM','by'=>'player','to'=>'npc','item'=>'dried fish','quantity'=>1], ['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Rag Loincloth','quantity'=>1]];
$first = stobeDealCaptureResponse(json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>json_encode($terms)]), $npc, $player, ['name'=>$npc], 'fish for your pants', 'social');
$db->exec("UPDATE stobe_social_contract SET status='COMPLETE', resolved_at=NOW(), updated_at=NOW() WHERE contract_id=$1", [$first['id']]);
$again = stobeDealCaptureResponse(json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>json_encode($terms)]), $npc, $player, ['name'=>$npc], 'Pleasure doing business.', 'social');
check('same deal right after finishing is ignored', ($again['decision'] ?? '') === 'NONE' && ($again['duplicate_of'] ?? '') === $first['id'], $again);
$count = $db->fetchOne('SELECT COUNT(*) AS n FROM stobe_social_contract WHERE npc_name=$1', [$npc]);
check('only one contract stored', intval($count['n'] ?? 0) === 1, $count);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
