<?php
// Item barter by voice ("a drink for your hat"). Run on a test DB:
//   STOBE_DB_NAME=stobe_test STOBE_NEG_TEST_NO_SIGNAL=1 STOBE_NEG_STOBE_LOG=/tmp/negtest/stobe.log STOBE_NEG_KFP_LOG=/tmp/negtest/kfp.log php tests/negotiation_barter_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$player = 'BarterTestPlayer';
$npc = 'BarterTestMalzin';
$db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [$player]);
foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
$db->exec("INSERT INTO core_npc_master (name, metadata, inventory, faction, created_at, updated_at) VALUES ($1, $2::jsonb, 'Grog x2 value 40, Bread x1 value 10', 'Nameless', NOW(), NOW())",
    [$player, json_encode(['money'=>100, 'money_observed_at'=>time()])]);
$db->exec("INSERT INTO core_npc_master (name, metadata, equipment, faction, created_at, updated_at) VALUES ($1, '{}'::jsonb, 'Iron Hat [Shoddy] x1 value 526', 'Bar Thugs', NOW(), NOW())", [$npc]);
$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$log = strval(getenv('STOBE_NEG_STOBE_LOG'));
@mkdir(dirname($log), 0777, true);
file_put_contents($log, '');
file_put_contents(strval(getenv('STOBE_NEG_KFP_LOG')), '');

// The offer starts a social negotiation, and never pays.
check('offer is recognised as a deal', stobeNegLooksLikeSocialOffer("I'll buy you a drink if you take off your hat"));
check('offer does not hand anything over', stobeNegParseHandover("I'll buy you a drink if you take off your hat", $npc, $player)['reason'] === 'offer_or_future');

// Category matching.
check('Grog satisfies "drink"', stobeNegItemMatchesTerm('Grog', 'drink'));
check('Bread does not satisfy "drink"', !stobeNegItemMatchesTerm('Bread', 'drink'));
check('named item still exact', stobeNegItemMatchesTerm('Vodka', 'Vodka') && !stobeNegItemMatchesTerm('Vodka', 'Bread'));

// Food: generic "food" accepts any food; a named item needs exactly that item.
check('Bread satisfies "food"', stobeNegItemMatchesTerm('Bread', 'food'));
check('Dried Meat satisfies "food"', stobeNegItemMatchesTerm('Dried Meat', 'food'));
check('Grog does not satisfy "food"', !stobeNegItemMatchesTerm('Grog', 'food'));
check('Dried Meat does not satisfy "Bread"', !stobeNegItemMatchesTerm('Dried Meat', 'Bread'));
check('pick food from inventory', stobeNegPickInventoryItem(['grog'=>2,'bread'=>1], 'food') === 'bread');
check('named bread is required', stobeNegPickInventoryItem(['grog'=>2,'dried meat'=>1], 'Bread') === '');

// She accepted: hat comes off once she has the drink.
$r = stobeDealCreate(['parties'=>['npc'=>$npc,'player'=>$player], 'kind'=>'social', 'context'=>[], 'terms'=>[
    ['kind'=>'GIVE_ITEM','by'=>'player','to'=>'npc','item'=>'drink'],
    ['kind'=>'UNEQUIP_ITEM','by'=>'npc','item'=>'Iron Hat','when'=>'after_player'],
]]);
check('deal created', !empty($r['ok']), $r);
stobeDealTransition($r['id'], 'PROPOSED', 'ACCEPTED');
$db->exec("UPDATE stobe_social_contract SET npc_serial=777 WHERE contract_id=$1", [$r['id']]);
stobeNegBeginPerformance($r['id'], [], $player, 1000, '');

$h = stobeNegParseHandover("Here's your drink", $npc, $player);
check('"Here\'s your drink" resolves to the Grog you carry', ($h['items'][0]['name'] ?? '') === 'grog', $h);
$h2 = stobeNegParseHandover('Here, take this grog', $npc, $player);
check('"take this grog" names it directly', ($h2['items'][0]['name'] ?? '') === 'grog', $h2);

$wire = '';
ob_start(function (string $chunk) use (&$wire): string { $wire .= $chunk; return ''; });
stobeNegVoiceHandover($npc, getNpcData($npc) ?: ['name'=>$npc], $player, "Here's your drink", 1000);
ob_end_flush();
check('player hands over Grog (wire line)', str_contains($wire, $player . '|ActionQueue|GIVE_ITEM@' . $npc . '@Grog'), $wire);

// Game confirms the hand-over -> term verified -> she is told to take the hat off.
file_put_contents($log, '[' . gmdate('Y-m-d H:i:s', time() - 21600) . ".000] [Stobe] ACTION_EXEC: GIVE_ITEM actor=$player recipient=$npc requested=1 transferred=1 item='Grog' source=inventory\n", FILE_APPEND);
touch($log, time());
stobeNegResetLogCache();
stobeNegTick();
$state = stobeNegDecode(stobeNegFetchDeal($r['id'])['term_state']);
check('drink verified', ($state[0]['status'] ?? '') === 'VERIFIED', $state[0] ?? null);
check('hat removal queued after the drink', ($state[1]['status'] ?? '') === 'SETTLE_QUEUED', $state[1] ?? null);
$d = stobeNegClaimDirective([$npc]);
$out = stobeNegCompleteDirective($d ?? [], '', $npc, $player, getNpcData($npc) ?: [], 'Alright, a deal is a deal.', []);
check('settle reply carries UNEQUIP_ITEM', in_array('UNEQUIP_ITEM@Iron Hat', $out['actions'] ?? [], true), $out);
file_put_contents(strval(getenv('STOBE_NEG_KFP_LOG')), '[' . gmdate('H:i:s', time() - 21600) . ".000] [stobe] UNEQUIP_ITEM serial=777 query=Iron Hat matched=Iron Hat result=ok\n");
touch(strval(getenv('STOBE_NEG_KFP_LOG')), time());
stobeNegResetLogCache();
stobeNegTick();
check('deal COMPLETE', stobeNegFetchDeal($r['id'])['status'] === 'COMPLETE', stobeNegFetchDeal($r['id'])['status']);

$db->exec("DELETE FROM stobe_social_contract WHERE npc_name=$1", [$npc]);
$db->exec("DELETE FROM stobe_negotiation_directive");
$db->exec("DELETE FROM stobe_negotiation_reputation WHERE player_name=$1", [$player]);
$db->exec("DELETE FROM eventlog WHERE data LIKE '%BarterTest%'");
foreach ([$player, $npc] as $n) { $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$n]); $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]); }
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
