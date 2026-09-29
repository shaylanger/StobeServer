<?php
// 2026-09-29 Malzin test report fixes. Run on a test DB: STOBE_DB_NAME=stobe_test php tests/negotiation_malzin_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = 'MalzinTestPlayer';
$npc = 'MalzinTestNpc';
$cleanup = function () use ($db, $player, $npc) {
    $db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
    foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
};
$cleanup();
$db->exec("INSERT INTO core_npc_master (name, metadata, inventory, faction, created_at, updated_at) VALUES ($1, $2::jsonb, 'Bread x2 value 10', 'Nameless', NOW(), NOW())",
    [$player, json_encode(['money'=>3000, 'money_observed_at'=>time()])]);
$db->exec("INSERT INTO core_npc_master (name, metadata, faction, created_at, updated_at) VALUES ($1, '{}'::jsonb, 'Bar Thugs', NOW(), NOW())", [$npc]);
$p = fn(string $m) => stobeNegParseHandover($m, $npc, $player);

// --- Speech-to-text hears "cats" as "counts"/"caps".
$npcData = ['name'=>$npc, 'equipment'=>'Black Cloth Shirt [Shoddy] x1, Worn-out Shorts x1, Black Rag Shirt [Shoddy] x1', 'inventory'=>''];
$r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'social', 'context'=>[],
    'terms'=>[['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>500], ['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Worn-out Shorts','when'=>'after_player']]]);
stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
stobeNegBeginPerformance($r['id'], [], $player, 1000, '');
check('"five hundred counts" pays 500', $p("Here's your five hundred counts.")['cats'] === 500, $p("Here's your five hundred counts."));
check('"500 caps" pays 500', $p('Here are 500 caps.')['cats'] === 500, $p('Here are 500 caps.'));
check('"Here\'s your five hundred" pays owed amount', $p("Here's your five hundred.")['cats'] === 500, $p("Here's your five hundred."));
check('offer with "counts" still does not pay', $p("I'll give you 500 counts if you strip")['cats'] === 0, $p("I'll give you 500 counts if you strip"));
$c = $p("Here you go, take it.");
check('hand-over with nothing matched is flagged', ($c['reason'] ?? '') === 'claimed_nothing_moved', $c);
$note = stobeNegVoiceHandover($npc, getNpcData($npc) ?: ['name'=>$npc], $player, 'Here you go, take it.', 1000);
check('NPC is told nothing changed hands', str_contains($note, 'nothing actually changed hands'), $note);
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
check('no debt: bare number does not pay', $p("Here's your five hundred.")['cats'] === 0, $p("Here's your five hundred."));

// --- Clothing promised in words becomes real UNEQUIP_ITEM terms.
$terms = [['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>500],
          ['kind'=>'PROMISE','by'=>'npc','text'=>'Malzin will remove one article of clothing (her black cloth shirt)']];
$out = stobeDealPromoteClothingPromises($terms, $npcData);
$un = array_values(array_filter($out, fn($t) => ($t['kind'] ?? '') === 'UNEQUIP_ITEM'));
check('promise to remove shirt -> UNEQUIP_ITEM', count($un) === 1 && strtolower($un[0]['item']) === 'black cloth shirt', $out);
check('promoted term waits for payment', ($un[0]['when'] ?? '') === 'after_player', $un);
$out2 = stobeDealPromoteClothingPromises([['kind'=>'PROMISE','by'=>'npc','text'=>'Malzin will dance for you']], $npcData);
check('unrelated promise untouched', count($out2) === 1, $out2);
$out3 = stobeDealPromoteClothingPromises([['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Black Cloth Shirt'],
    ['kind'=>'PROMISE','by'=>'npc','text'=>'remove the black cloth shirt']], $npcData);
check('no duplicate when already an UNEQUIP_ITEM', count(array_filter($out3, fn($t) => ($t['kind'] ?? '') === 'UNEQUIP_ITEM')) === 1, $out3);

// --- 'when' synonyms.
$n = stobeDealNormalizeConditionalTerms([['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Worn-out Shorts','when'=>'after_payment']]);
check('when=after_payment -> after_player', ($n[0]['kind'] ?? '') === 'UNEQUIP_ITEM' && ($n[0]['when'] ?? '') === 'after_player', $n);
$n = stobeDealNormalizeConditionalTerms([['kind'=>'GIVE_CATS','by'=>'npc','amount'=>300,'when'=>'if_you_lose']]);
check('bet condition still a PROMISE', ($n[0]['kind'] ?? '') === 'PROMISE', $n);

// --- Duplicate reply with empty terms keeps the good deal; renegotiation replaces an untouched deal.
$raw = json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>json_encode([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>500],['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Worn-out Shorts','when'=>'after_player']])]);
$a = stobeDealCaptureResponse($raw, $npc, $player, $npcData, 'I will pay 500', 'social');
$b = stobeDealCaptureResponse(json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>'']), $npc, $player, $npcData, 'I will pay 500', 'social');
check('empty duplicate ACCEPT keeps deal', !empty($b['ok']) && ($b['id'] ?? '') === ($a['id'] ?? 'x'), [$a, $b]);
$c2 = stobeDealCaptureResponse(json_encode(['deal_decision'=>'ACCEPT','deal_terms'=>json_encode([['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>800],['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Black Cloth Shirt','when'=>'after_player']])]), $npc, $player, $npcData, 'make it 800 and the shirt too', 'social');
$old = $db->fetchOne('SELECT status FROM stobe_social_contract WHERE contract_id=$1', [$a['id']]);
check('different new agreement supersedes untouched deal', ($old['status'] ?? '') === 'CANCELLED' && ($c2['id'] ?? '') !== $a['id'] && !empty($c2['ok']), [$old, $c2]);

// --- Removed-clothing memory block (reads KenshiFP log lines by serial).
$serial = 2740478464;
$db->exec("UPDATE core_npc_master SET metadata=metadata || $2::jsonb WHERE name=$1", [$npc, json_encode(['storage_id'=>'hand_' . $serial])]);
$kfp = getenv('STOBE_NEG_KFP_LOG');
$hms = date('H:i:s');
file_put_contents($kfp, "[$hms.100] [stobe] UNEQUIP_ITEM section move result item=1 qty=1 source=armour dest=main sourceHas=0 destHas=1 equippedAfter=0\n"
    . "[$hms.100] [stobe] UNEQUIP_ITEM serial=$serial query=Black Rag Shirt matched=Black Rag Shirt result=ok\n", FILE_APPEND);
unset($GLOBALS['__stobe_neg_bridge_cache']);
$serialFound = stobeNegSerialFromStorage($npc);
$block = stobeRemovedClothingPromptBlock($npc, ['equipment'=>'Worn-out Shorts x1', 'inventory'=>'Black Rag Shirt [Shoddy] x1, Chewing Tobacco x1']);
check('prompt names removed vest', $serialFound === $serial && str_contains($block, 'Black Rag Shirt') && str_contains($block, 'vest'), ['serial'=>$serialFound, 'block'=>$block]);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
