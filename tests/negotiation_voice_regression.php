<?php
// Voice hand-over parser. Run on a test DB: STOBE_DB_NAME=stobe_test php tests/negotiation_voice_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = 'VoiceTestPlayer';
$npc = 'VoiceTestNpc';
foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
$db->exec("INSERT INTO core_npc_master (name, metadata, inventory, faction, created_at, updated_at) VALUES ($1, $2::jsonb, 'Bread x2 value 10, Iron Hat [Shoddy] x1 value 526', 'Nameless', NOW(), NOW())",
    [$player, json_encode(['money'=>3000, 'money_observed_at'=>time()])]);
$db->exec("INSERT INTO core_npc_master (name, metadata, faction, created_at, updated_at) VALUES ($1, '{}'::jsonb, 'Bar Thugs', NOW(), NOW())", [$npc]);
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);

check('words: two thousand three hundred', stobeNegWordsToNumbers('two thousand three hundred cats') === '2300 cats', stobeNegWordsToNumbers('two thousand three hundred cats'));
check('words: a hundred and fifty', stobeNegWordsToNumbers('here is a hundred and fifty cats') === 'here is 150 cats', stobeNegWordsToNumbers('here is a hundred and fifty cats'));
check('words: forty-five', stobeNegWordsToNumbers('forty-five cats') === '45 cats');

$p = fn(string $m) => stobeNegParseHandover($m, $npc, $player);
check('"Here are your 200 cats"', $p('Here are your 200 cats')['cats'] === 200, $p('Here are your 200 cats'));
check('"Here are your two hundred cats."', $p('Here are your two hundred cats.')['cats'] === 200);
check('"I give you two thousand three hundred cats."', $p('I give you two thousand three hundred cats.')['cats'] === 2300, $p('I give you two thousand three hundred cats.'));
check('"Take these 50 cats"', $p('Take these 50 cats, Malzin.')['cats'] === 50);
check('offer does not pay', $p("I'll give you 200 cats to take off your hat")['reason'] === 'offer_or_future');
check('conditional does not pay', $p('Here, 200 cats if you stop')['reason'] === 'offer_or_future');
check('question does not pay', $p('Would you take 200 cats?')['reason'] === 'question');
check('bare amount without a debt does not pay', $p('Two hundred cats.')['cats'] === 0);
check('not enough Cats', $p('Here are 5000 cats')['reason'] === 'insufficient_cats', $p('Here are 5000 cats'));
check('item by voice', ($p('Here, take this bread')['items'][0]['name'] ?? '') === 'bread', $p('Here, take this bread'));
check('item quantity', ($p('Here are 2 bread')['items'][0]['qty'] ?? 0) === 2, $p('Here are 2 bread'));
// Item 118: offers and ultimatums never pay; hostile NPCs (r <= -80) get nothing by voice.
$o = $p("Vel, I'll buy your Iron Hat. One cat, take it or leave it.");
check('item 118: "I\'ll buy your Iron Hat. One cat, take it or leave it." does not pay', $o['cats'] === 0 && $o['reason'] === 'offer_or_future', $o);
check('item 118: "20 cats, take it or leave it" does not pay', $p('20 cats, take it or leave it.')['cats'] === 0, $p('20 cats, take it or leave it.'));
check('item 118: "I\'ll buy your hat. 5 cats, take it." does not pay', $p("I'll buy your hat. 5 cats, take it.")['cats'] === 0, $p("I'll buy your hat. 5 cats, take it."));
check('item 118: "I\'ll pay you 30 cats for it" does not pay', $p("I'll pay you 30 cats for it.")['cats'] === 0);
check('item 118: "Sell me the hat, 10 cats" does not pay', $p('Sell me the hat, 10 cats.')['cats'] === 0);
check('item 118: a gift with a clear cue still pays', $p('Here are 50 cats, friend.')['cats'] === 50, $p('Here are 50 cats, friend.'));
$db->exec("UPDATE core_npc_master SET extended_data=$1::jsonb WHERE name=$2", [json_encode(['relationships'=>[$player=>['aff'=>-80,'type'=>'enemy']]]), $npc]);
$wire = '';
ob_start(function (string $chunk) use (&$wire): string { $wire .= $chunk; return ''; });
$note = stobeNegVoiceHandover($npc, getNpcData($npc) ?: ['name'=>$npc], $player, 'Here are 50 cats', 1000);
ob_end_flush();
check('item 118: r=-80 and no deal: nothing dispatched', !str_contains($wire, 'GIVE_CATS'), $wire);
check('item 118: r=-80 note says nothing changed hands', str_contains($note, 'nothing changed hands'), $note);
$db->exec("UPDATE core_npc_master SET extended_data='{}'::jsonb WHERE name=$1", [$npc]);

// With a deal where the player owes 200: bare amount and "your money" pay what is owed.
$r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'social', 'context'=>[],
    'terms'=>[['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Wooden Sandals'], ['kind'=>'GIVE_CATS','by'=>'player','to'=>'npc','amount'=>200]]]);
stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
stobeNegBeginPerformance($r['id'], ['UNEQUIP_ITEM@Wooden Sandals'], $player, 1000, '');
check('owed amount read from deal', stobeNegPlayerOwedCats($npc) === 200, stobeNegPlayerOwedCats($npc));
check('"Two hundred cats." pays the debt', $p('Two hundred cats.')['cats'] === 200, $p('Two hundred cats.'));
check('"Here\'s your money" pays the debt', $p("Here's your money")['cats'] === 200, $p("Here's your money"));

// Dispatch writes a player-actor action line.
$wire = '';
ob_start(function (string $chunk) use (&$wire): string { $wire .= $chunk; return ''; });
$note = stobeNegVoiceHandover($npc, getNpcData($npc) ?: ['name'=>$npc], $player, 'Here are your 200 cats', 1000);
ob_end_flush();
check('wire line uses player as actor', str_contains($wire, $player . '|ActionQueue|GIVE_CATS@' . $npc . '@200'), $wire);
check('NPC gets a context note', str_contains($note, '200 Cats'), $note);
$o = $p("Here are your 20 cats.");
check('item 118: "Here are your 20 cats." with an accepted deal pays', $o['cats'] === 20 && $o['reason'] === 'handover', $o);
check('item 118: offer with an accepted deal still does not pay', $p("I'll buy your hat. 1 cat, take it or leave it.")['cats'] === 0);

$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
