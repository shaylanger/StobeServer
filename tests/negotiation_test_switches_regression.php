<?php
// Test switches NEG_TEST_INJECT / NEG_TEST_FORCE_INITIATIVE (Shay 2026-10-03: rare LLM choices on purpose).
// Run ONLY against a test database:
//   STOBE_DB_NAME=stobe_test STOBE_NEG_TEST_NO_SIGNAL=1 STOBE_NEG_STOBE_LOG=/tmp/negtest/stobe.log STOBE_NEG_KFP_LOG=/tmp/negtest/kfp.log php tests/negotiation_test_switches_regression.php
// The payloads below are the ones the in-game wrappers (workspace tests/ingame/stobe/STOBE-C*.sh) send.
require __DIR__ . '/../lib/bootstrap.php';

$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
if (!in_array(strval(getenv('STOBE_DB_NAME')), ['stobe_test'], true)) { echo "FAIL refusing to run outside stobe_test\n"; exit(1); }
@mkdir(dirname(strval(getenv('STOBE_NEG_STOBE_LOG') ?: '/tmp/negtest/stobe.log')), 0777, true);

$player = 'NegSwPlayer';
$db->exec("DELETE FROM general_settings WHERE id IN ('PLAYER_NAME','NEG_TEST_INJECT','NEG_TEST_FORCE_INITIATIVE')");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [$player]);
stobeNegEnsureSchema();
$cleanup = static function () use ($db, $player): void {
    $db->exec("DELETE FROM stobe_social_contract WHERE player_name=$1 OR npc_name LIKE 'NegSw%' OR npc_name LIKE '%[NegSw%'", [$player]);
    $db->exec("DELETE FROM stobe_negotiation_directive WHERE npc_name LIKE 'NegSw%' OR npc_name LIKE '%NegSw%'");
    $db->exec("DELETE FROM eventlog WHERE data LIKE '%NegSw%'");
    $db->exec("DELETE FROM core_npc_master WHERE name LIKE 'NegSw%' OR name LIKE '%[NegSw%'");
    $db->exec("DELETE FROM core_npc WHERE name LIKE 'NegSw%' OR name LIKE '%[NegSw%'");
    $db->exec("DELETE FROM general_settings WHERE id IN ('NEG_TEST_INJECT','NEG_TEST_FORCE_INITIATIVE')");
};
$cleanup();
$db->exec("DELETE FROM stobe_negotiation_directive");
function swNpc(string $name, string $equipment = '', string $blood = '100/100', string $faction = 'Drifters', array $meta = []): void {
    $db = $GLOBALS['db'];
    $db->exec("DELETE FROM core_npc_master WHERE name=$1", [$name]);
    $db->exec(
        "INSERT INTO core_npc_master (name, metadata, inventory, equipment, personality, blood, faction, created_at, updated_at)
         VALUES ($1, $2::jsonb, '', $3, 'Calm and fair.', $4, $5, NOW(), NOW())",
        [$name, json_encode($meta + ['money'=>500, 'money_observed_at'=>time()]), $equipment, $blood, $faction]
    );
}
swNpc($player, '', '100/100', 'Nameless');
swNpc('NegSwVarn', 'Iron Hat [Shoddy] x1 value 526, Chisa Katana [Ancient] x1 value 2789, Black Rag Shirt [Shoddy] x1 value 96');
$varn = getNpcData('NegSwVarn') ?: [];
$set = static function (string $id, $value) use ($db): void {
    $db->exec("DELETE FROM general_settings WHERE id=$1", [$id]);
    $db->exec("INSERT INTO general_settings (id, value) VALUES ($1, $2)", [$id, is_string($value) ? $value : json_encode($value)]);
};
$take = static fn(string $ctx, string $npc) => stobeNegTestTakeInjection($ctx, $npc, getNpcData($npc) ?: false, $player);
// A model reply as it comes back, then with one injection step put in.
$model = json_encode(['character'=>'NegSwVarn', 'listener'=>$player, 'message'=>'Sure.', 'mood'=>'neutral', 'action'=>'Talk',
    'target'=>$player, 'deal_decision'=>'NONE', 'deal_terms'=>'']);
$inj = static fn(array $step, string $raw = '') => stobeNegTestApplyInjection($raw !== '' ? $raw : $GLOBALS['model'], ['npc'=>'NegSwVarn', 'player'=>$GLOBALS['player'], 'step'=>$step]);
$capture = static fn(string $raw, string $msg, string $npc = 'NegSwVarn') => stobeDealCaptureResponse($raw, $npc, $player, getNpcData($npc) ?: [], $msg, 'social');
$open = static fn(string $npc = 'NegSwVarn') => stobeDealOpenForNpc($npc);
$closeDeals = static function () use ($db): void {
    $db->exec("UPDATE stobe_social_contract SET status='CANCELLED' WHERE npc_name LIKE 'NegSw%' AND status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')");
};

// ---------------------------------------------------------------- 1. NEG_TEST_INJECT: off by default, filters, steps
check('switch missing -> no injection', $take('chat', 'NegSwVarn') === null);
$set('NEG_TEST_INJECT', 'false');
check('switch "false" -> no injection', $take('chat', 'NegSwVarn') === null);
$set('NEG_TEST_INJECT', ['row'=>'27', 'npc'=>'NegSwVarn', 'steps'=>[
    ['deal_decision'=>'COUNTER', 'deal_terms'=>''],
    ['message'=>'Second for {player}, my {weapon} and {worn}.'],
]]);
check('another NPC -> no injection', $take('chat', 'NegSwOther') === null);
check('directive turn -> no injection (context chat)', $take('directive', 'NegSwVarn') === null);
$i1 = $take('chat', 'NegSwVarn');
check('step 1 taken first', is_array($i1) && ($i1['step']['deal_decision'] ?? '') === 'COUNTER' && $i1['row'] === '27' && $i1['left'] === 1, $i1);
$i2 = $take('chat', 'NegSwVarn');
check('step 2 with placeholders', is_array($i2) && ($i2['step']['message'] ?? '') === "Second for $player, my Chisa Katana and Iron Hat.", $i2);
check('steps used up -> no injection', $take('chat', 'NegSwVarn') === null);
$v = json_decode(strval($db->fetchOne("SELECT value FROM general_settings WHERE id='NEG_TEST_INJECT'")['value'] ?? ''), true);
check('used-up switch says fired', !empty($v['fired']) && intval($v['used'] ?? 0) === 2, $v);
$set('NEG_TEST_INJECT', ['row'=>'25', 'npc'=>'NegSw Bandit', 'context'=>'directive', 'deal_decision'=>'PROPOSE']);
check('top-level single step, renamed NPC "X [npc]" matches', ($take('directive', 'Gost [NegSw Bandit]')['step']['deal_decision'] ?? '') === 'PROPOSE');
$set('NEG_TEST_INJECT', ['row'=>'x', 'context'=>'any', 'steps'=>[['message'=>'a'], ['message'=>'b']]]);
check('context any: chat and directive turns', ($take('chat', 'NegSwA')['step']['message'] ?? '') === 'a' && ($take('directive', 'NegSwB')['step']['message'] ?? '') === 'b');
$db->exec("DELETE FROM general_settings WHERE id='NEG_TEST_INJECT'");

// ---------------------------------------------------------------- 2. applying an injection / the stream wrapper
$out = json_decode($inj(['deal_decision'=>'COUNTER', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>80]]]), true);
check('injection overrides only its fields', ($out['deal_decision'] ?? '') === 'COUNTER' && ($out['message'] ?? '') === 'Sure.'
    && is_array(json_decode(strval($out['deal_terms'] ?? ''), true)), $out);
$plain = json_decode(stobeNegTestApplyInjection('Just words, no JSON.', ['npc'=>'NegSwVarn', 'player'=>$player, 'step'=>['action'=>'Attack', 'target'=>$player]]), true);
check('plain-text reply becomes a structured one', ($plain['message'] ?? '') === 'Just words, no JSON.' && ($plain['action'] ?? '') === 'Attack', $plain);
$deltas = '';
$streamed = stobeNegTestStreamInjected(['npc'=>'NegSwVarn', 'player'=>$player, 'step'=>['deal_decision'=>'REJECT']],
    static function (callable $c): string { $c('{"message":"No.",'); $c('"deal_decision":"ACCEPT"}'); return '{"message":"No.","deal_decision":"ACCEPT"}'; },
    static function (string $d) use (&$deltas): void { $deltas .= $d; });
check('stream wrapper: caller gets the injected reply as deltas', $deltas === $streamed && (json_decode($streamed, true)['deal_decision'] ?? '') === 'REJECT'
    && (json_decode($streamed, true)['message'] ?? '') === 'No.', [$deltas, $streamed]);
$parsed = stobeParseStructuredDialogueResponse($inj(['action'=>'Attack', 'target'=>'Dorn Vale', 'message'=>'On it.']), 'chat');
check('injected Attack parses to an ATTACK action (row 47)', stripos(strval($parsed['action_tag'] ?? ''), 'ATTACK') === 0 && str_contains(strval($parsed['action_tag'] ?? ''), 'Dorn Vale'), $parsed);
$root = dirname(__DIR__);
check('wired: dispatcher, chat and bored paths', str_contains(file_get_contents($root . '/connector/llm_dispatcher.php'), 'stobeNegTestStreamInjected')
    && str_contains(file_get_contents($root . '/processor/chat.php'), "stobeNegTestTakeInjection('chat'")
    && str_contains(file_get_contents($root . '/processor/bored.php'), "stobeNegTestTakeInjection('directive'"));

// ---------------------------------------------------------------- 3. the wrappers' payloads reach the guards
$promise = ['kind'=>'PROMISE', 'by'=>'npc', 'text'=>'tell you where the nearest bar is'];
// row 27: COUNTER without terms
$r = $capture($inj(['deal_decision'=>'COUNTER', 'deal_terms'=>'', 'message'=>'Eighty cats, not fifty.']), "NegSwVarn, I'll pay you 50 cats to tell me where the nearest bar is.");
check('row 27: COUNTER without terms -> invalid_terms_json (reminder path)', empty($r['ok']) && ($r['error'] ?? '') === 'invalid_terms_json' && ($r['decision'] ?? '') === 'COUNTER', $r);
// row 26: agreed in words, nothing recorded
$r = $capture($inj(['deal_decision'=>'NONE', 'deal_terms'=>'', 'message'=>"Fine, you've got a deal."]), "NegSwVarn, I'll pay you 50 cats to tell me where the nearest bar is.");
check('row 26: NONE + "Fine, deal" -> nothing recorded, speech agrees', ($r['decision'] ?? '') === 'NONE' && stobeDealSpeechAgrees("Fine, you've got a deal.") && $open() === null, $r);
// row 32: REJECT naming her price
$r = $capture($inj(['deal_decision'=>'REJECT', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>2000], ['kind'=>'PROMISE', 'by'=>'npc', 'text'=>'tell you a secret']],
    'message'=>'Five? No. 2000 cats, or nothing.']), "NegSwVarn, I'll pay you 5 cats to tell me a secret.");
check('row 32: REJECT with her price -> COUNTERED', ($r['decision'] ?? '') === 'COUNTER' && ($open()['status'] ?? '') === 'COUNTERED', $r);
$closeDeals();
// row 45: an extra 0-Cats term
$r = $capture($inj(['deal_decision'=>'ACCEPT', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>100], ['kind'=>'GIVE_CATS', 'by'=>'npc', 'to'=>'player', 'amount'=>0], $promise],
    'message'=>"Deal. 100 cats and I'll tell you."]), "NegSwVarn, I'll pay you 100 cats to tell me where the nearest bar is.");
check('row 45: 0-Cats term dropped, deal recorded', ($r['decision'] ?? '') === 'ACCEPT' && count($r['terms'] ?? []) === 2, $r);
$closeDeals();
// row 35: her weapon without trust
$raw35 = stobeNegTestApplyInjection($model, ['npc'=>'NegSwVarn', 'player'=>$player, 'step'=>['deal_decision'=>'ACCEPT', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>3000], ['kind'=>'GIVE_ITEM', 'by'=>'npc', 'to'=>'player', 'item'=>stobeNegTestPlaceholders('{weapon}', 'NegSwVarn', $varn, $player)]]]]);
$r = $capture($raw35, "NegSwVarn, I'll pay you 3000 cats for your weapon.");
check('row 35: weapon hand-over refused without trust', empty($r['ok']) && ($r['error'] ?? '') === 'weapon_not_negotiable', $r);
$closeDeals();
// row 28: "take off X" recorded as UNEQUIP_ITEM
$r = $capture($inj(['deal_decision'=>'ACCEPT', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>50], ['kind'=>'GIVE_ITEM', 'by'=>'npc', 'to'=>'player', 'item'=>'Iron Hat']], 'message'=>'Fine.']),
    "NegSwVarn, take off your Iron Hat and I'll pay you 50 cats.");
$kinds28 = array_map(static fn($t) => $t['kind'] ?? '', $r['terms'] ?? []);
check('row 28: take-off request -> UNEQUIP_ITEM, no GIVE_ITEM', in_array('UNEQUIP_ITEM', $kinds28, true) && !in_array('GIVE_ITEM', $kinds28, true), $r);
$closeDeals();
// row 31: counter-offer, misquoted amounts
$terms31 = [['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>300], ['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>200, 'when'=>'after_npc'], ['kind'=>'PROMISE', 'by'=>'npc', 'text'=>'tell you where the bandit camp is']];
$msg31 = "NegSwVarn, I'll pay you 300 now and 200 after if you tell me where the bandit camp is.";
$text31 = "Make it 400 now and 250 after, and I'll tell you.";
$r = $capture($inj(['deal_decision'=>'COUNTER', 'deal_terms'=>$terms31, 'message'=>$text31]), $msg31);
$a31 = stobeDealSpeechAmountCheck($text31, 'NegSwVarn', $r, $msg31);
check('row 31: misquoted counter amounts are rewritten', ($r['decision'] ?? '') === 'COUNTER' && is_array($a31)
    && array_values(array_intersect([400, 250], $a31['wrong'])) === [400, 250] && str_contains($a31['line'], '300'), [$r, $a31]);
$closeDeals();
// row 51: she repeats his offer, then names hers
$msg51 = "NegSwVarn, I'll pay you 50 cats to sing me a song.";
$text51 = "Fifty cats? Tell you what - eighty, and I'll sing.";
$r = $capture($inj(['deal_decision'=>'COUNTER', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>80], ['kind'=>'PROMISE', 'by'=>'npc', 'text'=>'sing you a song']], 'message'=>$text51]), $msg51);
check('row 51: echo + her price -> no rewrite', ($r['decision'] ?? '') === 'COUNTER' && stobeDealSpeechAmountCheck($text51, 'NegSwVarn', $r, $msg51) === null, $r);
$closeDeals();
// row 44: the deal on the table has her paying; his acceptance comes back reversed
$terms44 = [['kind'=>'GIVE_CATS', 'by'=>'npc', 'to'=>'player', 'amount'=>350], ['kind'=>'PROMISE', 'by'=>'player', 'text'=>'keep quiet about what I saw']];
$r = $capture($inj(['deal_decision'=>'PROPOSE', 'deal_terms'=>$terms44, 'message'=>'350 cats and you keep quiet about what you saw.']), 'NegSwVarn, how much would you pay me to keep quiet about what I saw?');
check('row 44 setup: her offer on the table', ($r['decision'] ?? '') === 'PROPOSE', $r);
$rev = $terms44; $rev[0]['by'] = 'player'; $rev[0]['to'] = 'npc';
$r = $capture($inj(['deal_decision'=>'ACCEPT', 'deal_terms'=>$rev, 'message'=>'Done.']), '350 and I keep quiet. Deal.');
$cats44 = array_values(array_filter($r['terms'] ?? [], static fn($t) => ($t['kind'] ?? '') === 'GIVE_CATS'));
check('row 44: reversed Cats term turned round from the table', ($r['decision'] ?? '') === 'ACCEPT' && ($cats44[0]['by'] ?? '') === 'npc', $r);
$closeDeals();
// row 29: an underway deal, a wrong amount in a longer reply: only that sentence goes
$r = $capture($inj(['deal_decision'=>'ACCEPT', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>100], $promise], 'message'=>'Deal.']),
    "NegSwVarn, I'll pay you 100 cats to tell me where the nearest bar is.");
$text29 = "Sure thing. The road north is quiet tonight. You owe me 700 cats for that. Then we're done.";
$p29 = stobeDealProgressAmountCheck($text29, 'NegSwVarn', 'NegSwVarn, how much do I owe you again?');
check('row 29: only the misquoting sentence is dropped', is_array($p29) && str_contains($p29['line'], "Then we're done.") && str_contains($p29['line'], 'road north')
    && !str_contains($p29['line'], '700'), $p29);
$closeDeals();
// row 36: endless counters end the talks
$last = null;
for ($i = 0; $i < 9; $i++) {
    $last = $capture($inj(['deal_decision'=>'COUNTER', 'deal_terms'=>[['kind'=>'GIVE_CATS', 'by'=>'player', 'to'=>'npc', 'amount'=>200 + 10 * $i], $promise], 'message'=>'Make it ' . (200 + 10 * $i) . ' cats.']),
        'How about ' . (100 + 10 * $i) . ' cats?');
    if (!empty($last['exhausted'])) break;
}
check('row 36: injected counters run out the bargaining rounds', !empty($last['exhausted']) && $i <= 7, [$i, $last]);
$closeDeals();

// ---------------------------------------------------------------- 4. NEG_TEST_FORCE_INITIATIVE
$ev = static function (string $type, string $data, string $people) use ($db): void {
    $db->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ($1,$2,1000,$3,'pending',$2,$4,'')", [$type, time() - 5, $data, $people]);
};
// row 37: a neutral NPC at full health fighting someone else next to the player
swNpc('NegSwPell', 'Black Rag Shirt [Shoddy] x1 value 96', '100/100', 'Drifters', ['is_in_combat'=>true]);
swNpc('NegSwThug', '', '100/100', 'Drifters', ['is_in_combat'=>true]);
$people37 = "[\"$player|hand_1\",\"NegSwPell|hand_4100000371\",\"NegSwThug|hand_4100000372\"]";
$ev('combat', 'NegSwThug: Initiated attack (talking to: NegSwPell)', $people37);
$ev('combat', 'NegSwPell: Defending against (talking to: NegSwThug)', $people37);
$db->exec("UPDATE stobe_negotiation_directive SET created_unix = created_unix - 5000");
$count37 = static fn() => intval($db->fetchOne("SELECT COUNT(*) AS c FROM stobe_negotiation_directive WHERE npc_name='NegSwPell' AND kind='assist'")['c'] ?? 0);
@unlink(stobeNegThrottleMarker('initiative'));
stobeNegConsiderInitiatives('combat', 'NegSwThug: Initiated attack (talking to: NegSwPell)', $people37, 1000);
check('row 37: switch off -> a healthy NPC does not ask for help', $count37() === 0);
$set('NEG_TEST_FORCE_INITIATIVE', ['row'=>'37', 'kind'=>'assist', 'npc'=>'NegSwPell']);
@unlink(stobeNegThrottleMarker('initiative'));
stobeNegConsiderInitiatives('combat', 'NegSwThug: Initiated attack (talking to: NegSwPell)', $people37, 1000);
check('row 37: switch on -> assist offer queued', $count37() === 1, $db->fetchAll("SELECT npc_name, kind FROM stobe_negotiation_directive"));
$v = json_decode(strval($db->fetchOne("SELECT value FROM general_settings WHERE id='NEG_TEST_FORCE_INITIATIVE'")['value'] ?? ''), true);
check('row 37: one-shot (marked fired)', !empty($v['fired']) && ($v['fired_npc'] ?? '') === 'NegSwPell', $v);
// row 25: named mid-fight; the offer is queued under his old name and follows the new one
swNpc('Gost [NegSw Bandit]', '', '100/100', 'Starving Bandits', ['is_in_combat'=>true, 'storage_id'=>'hand_4100000251']);
$people25 = "[\"$player|hand_1\",\"Gost [NegSw Bandit]|hand_4100000251\"]";
$ev('combat', "Gost [NegSw Bandit]: Initiated attack (talking to: $player)", $people25);
$set('NEG_TEST_FORCE_INITIATIVE', ['row'=>'25', 'kind'=>'surrender', 'npc'=>'NegSw Bandit', 'queue_as_old_name'=>true]);
$db->exec("UPDATE stobe_negotiation_directive SET created_unix = created_unix - 5000");
@unlink(stobeNegThrottleMarker('initiative'));
stobeNegConsiderInitiatives('combat', "Gost [NegSw Bandit]: Initiated attack (talking to: $player)", $people25, 1000);
$d25 = $db->fetchOne("SELECT npc_name, kind FROM stobe_negotiation_directive WHERE kind='surrender' AND npc_name LIKE '%NegSw Bandit%' ORDER BY id DESC LIMIT 1");
check('row 25: surrender offer queued under his old name', ($d25['npc_name'] ?? '') === 'NegSw Bandit', $d25);
$claimed = stobeNegClaimDirective([$player, 'Gost [NegSw Bandit]']);
check('row 25: the directive follows his new name when claimed', is_array($claimed) && ($claimed['npc_name'] ?? '') === 'Gost [NegSw Bandit]' && ($claimed['kind'] ?? '') === 'surrender', $claimed);

$cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
