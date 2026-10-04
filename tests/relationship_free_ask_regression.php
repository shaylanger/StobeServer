<?php
// Item 120: willingness lines outside deal terms (free favour < +30, free gift < gift threshold, pay-later < 0),
// an agreed heal is real first aid, and a cancelled deal is no gift licence. Test DB only.
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/relationship_trading.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
foreach (['stobeRelFreeAskGuard', 'stobeRelFreeAskKind', 'stobeRelFreeAskPayment', 'stobeRelFirstAidForPlayerAllowed'] as $fn) {
    if (!function_exists($fn)) { echo "FAIL item 120: $fn missing\npass=$pass fail=1\n"; exit(1); }
}
$player = 'FreeAskPlayer';
$GLOBALS['db']->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$GLOBALS['db']->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [$player]);
$GLOBALS['STOBE_PLAYER_ACTOR'] = $player;
$npc = static fn(int $aff, string $name = 'FreeAsk Vel', string $faction = 'Test Traders'): array => [
    'name' => $name, 'faction' => $faction,
    'extended_data' => json_encode(['relationships' => [$player => ['aff' => $aff, 'type' => 'neutral']]]),
    'inventory' => 'Bread x2 value 10', 'equipment' => '', 'metadata' => '{}',
];

// ---- what is asked
$bandage = "Vel, would you bandage my arm for me, for free? I'm hurt.";
$bread = "Vel, could you just give me one of your bread? I've nothing to pay with.";
check('kind: bandage ask = heal', stobeRelFreeAskKind($bandage) === 'heal', stobeRelFreeAskKind($bandage));
check('kind: bread ask = gift', stobeRelFreeAskKind($bread) === 'gift', stobeRelFreeAskKind($bread));
check('kind: "Vel, fetch me two bread from the chest." = favour', stobeRelFreeAskKind('Vel, fetch me two bread from the chest.') === 'favour');
check('kind: "Could you guard me tonight?" = favour', stobeRelFreeAskKind('Could you guard me tonight?') === 'favour');
check('kind: a question about the guard is no ask', stobeRelFreeAskKind('Did the guard say anything about the raid?') === '');
check('kind: small talk is no ask', stobeRelFreeAskKind('Hello there, Vel.') === '');
check('pay: "for free" / "nothing to pay with" is not payment', !stobeRelFreeAskPayment($bandage, 29)['paid'] && !stobeRelFreeAskPayment($bread, 55)['paid']);
check('pay: "I\'ll pay you 50 cats" is payment', stobeRelFreeAskPayment("Bandage my arm, I'll pay you 50 cats.", 10)['paid']);
check('pay: "pay you later" at r=-5 is refused (pay-later line)', stobeRelFreeAskPayment("Give me a bread, I'll pay you later.", -5)['later_refused']);
check('pay: "pay you later" at r=0 is payment', stobeRelFreeAskPayment("Give me a bread, I'll pay you later.", 0)['paid']);

// ---- m19 case 1: free bandage at r=29 (agreed with a roleplay notice) -> refused in character
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(29), $player, $bandage, "Show me the arm. I've got a kit and steady hands.", ['ROLEPLAY_ACTION@' . $player]);
check('heal r=29: refused (rule free favours)', str_contains($g['rule'], 'free favours') && $g['text'] === stobeRelFavourRefusalLine('heal') && $g['actions'] === [], $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(29), $player, $bandage, "Show me the arm.", ['ROLEPLAY_ACTION@' . $player], ['already_streamed' => true]);
check('heal r=29 already spoken: words kept, actions still dropped', $g['text'] === 'Show me the arm.' && $g['actions'] === [] && $g['rule'] !== '', $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(29), $player, $bandage, "No. Find a doctor.", []);
check('heal r=29: her own refusal stands', $g['rule'] === '' && $g['text'] === 'No. Find a doctor.', $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(10), $player, "Vel, would you bandage my arm? I'll pay you 50 cats.", "Hold still.", []);
check('heal r=10 with payment: not refused, no free first aid added', $g['rule'] === '' && $g['added'] === [], $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(10), $player, $bandage, "Hold still.", ['ROLEPLAY_ACTION@' . $player], ['decision' => 'ACCEPT']);
check('heal r=10 under a deal decision: untouched (deal gate decides)', $g['rule'] === '' && $g['actions'] === ['ROLEPLAY_ACTION@' . $player], $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(10), $player, $bandage, "Hold still.", [], ['open_deal' => true]);
check('heal r=10 with an open deal: untouched', $g['rule'] === '', $g);

// ---- m19 case 2: free bandage at r=30 -> real first aid
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(30), $player, $bandage, "Hold still. Let me see it.", ['ROLEPLAY_ACTION@' . $player]);
check('heal r=30: FIRST_AID@player added next to her words', $g['rule'] === '' && in_array('FIRST_AID@' . $player, $g['actions'], true) && $g['text'] === 'Hold still. Let me see it.', $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(30), $player, $bandage, "Hold still.", ['FIRST_AID@' . $player]);
check('heal r=30: an existing FIRST_AID is not doubled', count(array_filter($g['actions'], static fn($a) => str_starts_with($a, 'FIRST_AID@'))) === 1, $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(30), $player, $bandage, "Not a chance.", []);
check('heal r=30: a refusal adds nothing', $g['added'] === [], $g);

// ---- favour (errand) below +30
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(20), $player, 'Vel, fetch me two bread from the chest.', "Sure, I'll get them.", ['TASK_GOAL@FetchItems@Bread@2']);
check('favour r=20: refused, goal dropped', str_contains($g['rule'], 'free favours') && $g['actions'] === [], $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(30), $player, 'Vel, fetch me two bread from the chest.', "Sure, I'll get them.", []);
check('favour r=30: allowed', $g['rule'] === '', $g);

// ---- gift (the coordinator's second case): bread at r=55 / 56
$breadReply = "You're in luck. I've got two... Here.";
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(55), $player, $bread, $breadReply, ['GIVE_ITEM@' . $player . '@Bread@1']);
check('gift r=55: refused, GIVE_ITEM dropped', str_contains($g['rule'], 'free items') && $g['actions'] === [] && $g['text'] === stobeRelFavourRefusalLine('gift'), $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(55), $player, $bread, "Here.", [], ['blocked_gift' => true]);
check('gift r=55: action already blocked by the gift filter -> words refused too', $g['rule'] !== '' && $g['text'] === stobeRelFavourRefusalLine('gift'), $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(56), $player, $bread, $breadReply, ['GIVE_ITEM@' . $player . '@Bread@1']);
check('gift r=56: given', $g['rule'] === '' && $g['actions'] === ['GIVE_ITEM@' . $player . '@Bread@1'], $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(40), $player, 'Tell me about the Hub.', 'Here, take it.', ['GIVE_CATS@' . $player . '@50']);
check('gift r=40: Cats handed over unasked are refused too', $g['rule'] !== '' && $g['actions'] === [], $g);
$g = stobeRelFreeAskGuard('FreeAsk Vel', $npc(-5), $player, "Give me a bread, I'll pay you later.", 'Fine, here.', ['GIVE_ITEM@' . $player . '@Bread@1']);
check('pay-later r=-5 outside a deal: refused with the pay-later line', str_contains($g['rule'], 'pay-later') && $g['text'] === stobeRelFavourRefusalLine('pay_later') && $g['actions'] === [], $g);

// ---- squadmates exempt
$pf = function_exists('getCurrentPlayerFactionIdentity') ? strval(getCurrentPlayerFactionIdentity()['name'] ?? '') : '';
if ($pf !== '') {
    $g = stobeRelFreeAskGuard('FreeAsk Mate', $npc(0, 'FreeAsk Mate', $pf), $player, $bread, $breadReply, ['GIVE_ITEM@' . $player . '@Bread@1']);
    check('squadmate: exempt', $g['rule'] === '' && count($g['actions']) === 1, $g);
} else {
    echo "SKIP squadmate exemption (no player faction in this DB)\n";
}

// ---- the outsider order gate: FIRST_AID on the player follows the +30 line (it was always refused)
check('order gate: FIRST_AID@player at r=29 refused with the heal line', stobePlayerOrderGate('FreeAsk Vel', $npc(29), 'FIRST_AID@' . $player) === stobeRelFavourRefusalLine('heal'));
check('order gate: FIRST_AID@player at r=30 allowed', stobePlayerOrderGate('FreeAsk Vel', $npc(30), 'FIRST_AID@' . $player) === '');
check('order gate: FIRST_AID on someone else stays a work order', stobePlayerOrderGate('FreeAsk Vel', $npc(90), 'FIRST_AID@Some Stranger') !== '');

// ---- deal context: a cancelled deal is no licence for gifts; a completed one still is (settlement)
$db = $GLOBALS['db'];
$db->exec("DELETE FROM stobe_social_contract WHERE player_name=$1", [$player]);
$ins = static function (string $id, string $npcName, string $status) use ($db, $player): void {
    $db->exec("INSERT INTO stobe_social_contract (contract_id, npc_name, player_name, status, kind, terms, resolved_at)
               VALUES ($1, $2, $3, $4, 'social', '[]'::jsonb, NOW() - INTERVAL '8 minutes')", [$id, $npcName, $player, $status]);
};
$ins('deal-freeask-cancel', 'FreeAsk Cancelled', 'CANCELLED');
$ins('deal-freeask-expired', 'FreeAsk Expired', 'EXPIRED');
$ins('deal-freeask-done', 'FreeAsk Done', 'COMPLETE');
check('deal context: CANCELLED 8 min ago does not count', !stobeNegNpcHasDealContext('FreeAsk Cancelled'));
check('deal context: EXPIRED 8 min ago does not count', !stobeNegNpcHasDealContext('FreeAsk Expired'));
check('deal context: COMPLETE 8 min ago still counts', stobeNegNpcHasDealContext('FreeAsk Done'));
$cfg = ['npc_name' => 'FreeAsk Cancelled', 'player_name' => $player, 'player_affinity' => 55, 'gift_trust_threshold' => 56];
check('gift filter: bread at r=55 after a cancelled deal is blocked', stobeUnpaidGiftWouldBeBlocked('GIVE_ITEM', $player . '@Bread@1', $cfg));
$db->exec("DELETE FROM stobe_social_contract WHERE player_name=$1", [$player]);
$GLOBALS['db']->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
echo "pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
