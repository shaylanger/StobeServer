<?php
// Player healing as a deal term; heal-for-rags offers detected. STOBE_DB_NAME=stobe_test php tests/negotiation_heal_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$npc = 'HealTestNpc';
$cleanup = function () use ($db, $npc) {
    $db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
    $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$npc]);
    $db->exec("DELETE FROM eventlog WHERE data LIKE $1", ['%heal ' . $npc . '%']);
};
$cleanup();
$db->exec("INSERT INTO core_npc_master (name, metadata, equipment, faction, created_at, updated_at) VALUES ($1, '{}'::jsonb, 'Rag Loincloth x1', 'Slaves', NOW(), NOW())", [$npc]);

foreach ([
    "Okay, what if I bandage you up and give you food?" => true,
    "Uh, here's the deal. You give me your rag loincloth and I will heal you up now that you're bleeding out." => true,
    "If you attack this guy, I'll free you from your shackles." => true,
    "I'll heal you if you give me your rags" => true,
    "Thanks for your help" => false,
] as $line => $want) {
    check(($want ? 'offer: ' : 'not an offer: ') . substr($line, 0, 50), stobeNegLooksLikeSocialOffer($line) === $want);
}

$terms = [['kind'=>'FIRST_AID','by'=>'player','target'=>'npc'], ['kind'=>'GIVE_ITEM','by'=>'npc','to'=>'player','item'=>'Rag Loincloth','quantity'=>1,'when'=>'after_player']];
$v = stobeDealValidate(['parties'=>['npc'=>$npc,'player'=>$player], 'terms'=>$terms, 'kind'=>'social', 'context'=>[]]);
check('player FIRST_AID term is valid', !empty($v['ok']), $v);
$r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'social', 'context'=>[], 'terms'=>$terms]);
stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
stobeNegBeginPerformance($r['id'], [], $player, 1000, '');
$deal = stobeNegFetchDeal($r['id']);
stobeNegTickDeal($deal, $player, time());
$st = stobeNegDecode(stobeNegFetchDeal($r['id'])['term_state']);
check('no heal yet: still awaiting', ($st[0]['status'] ?? '') === 'AWAITING_PLAYER', $st[0] ?? null);
storeEvent('healing', time(), 1001, $player . ': is using (Basic First Aid Kit) to heal ' . $npc);
$deal = stobeNegFetchDeal($r['id']);
stobeNegTickDeal($deal, $player, time());
$st = stobeNegDecode(stobeNegFetchDeal($r['id'])['term_state']);
check('player heal verified from healing event', ($st[0]['status'] ?? '') === 'VERIFIED', $st[0] ?? null);
check('NPC hand-over then queued', in_array($st[1]['status'] ?? '', ['SETTLE_QUEUED','DISPATCHED'], true), $st[1] ?? null);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
