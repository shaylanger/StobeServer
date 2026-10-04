<?php
// Items 102-104: relationship-shaped trading (weapon guard, willingness lines, prices). Test DB only.
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/relationship_trading.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$near = static fn(float $a, float $b, float $eps = 0.0006): bool => abs($a - $b) < $eps;

// ---- 104 formula at Shay's listed points
check('buy r=0: vanilla', stobeRelBuyFactor(0) === 1.0 && stobeRelSellFactor(0) === 1.0);
check('buy r=+10: -2.4%', $near(1 - stobeRelBuyFactor(10), 0.0238), 1 - stobeRelBuyFactor(10));
check('buy r=+56: -15.8% (0.1585)', $near(1 - stobeRelBuyFactor(56), 0.1585), 1 - stobeRelBuyFactor(56));
check('buy r=+100: -30%', $near(1 - stobeRelBuyFactor(100), 0.30));
check('buy r=-10: +5% (0.0479)', $near(stobeRelBuyFactor(-10) - 1, 0.0479), stobeRelBuyFactor(-10) - 1);
check('buy r=-50: +200%', $near(stobeRelBuyFactor(-50) - 1, 2.003, 0.002), stobeRelBuyFactor(-50) - 1);
check('buy r=-100: +1000%', $near(stobeRelBuyFactor(-100) - 1, 10.0));
check('sell r=+100: +10%', $near(stobeRelSellFactor(100), 1.10));
check('sell r=-100: -75%', $near(stobeRelSellFactor(-100), 0.25));
check('clamped beyond +-100', stobeRelBuyFactor(150) === stobeRelBuyFactor(100) && stobeRelBuyFactor(-150) === stobeRelBuyFactor(-100));
// floor: never a buy/sell loop profit, at the default ratio and at a high one
$loop = true; $bad = null;
foreach ([0.5, 0.95] as $ratio) foreach ([10, 100, 1000, 7430] as $base) for ($r = -100; $r <= 100; $r++) {
    if (stobeRelBuyPrice($base, $r, $ratio) <= stobeRelSellPrice($base, $r, $ratio)) { $loop = false; $bad = [$ratio, $base, $r]; break 3; }
}
check('floor: buying back always costs more than selling pays (no loop profit)', $loop, $bad);
check('floor binds at a high trader ratio (0.95, r=+100)', stobeRelBuyPrice(100, 100, 0.95) === 105, stobeRelBuyPrice(100, 100, 0.95));
check('buy price r=+56 of 100 = 85', stobeRelBuyPrice(100, 56) === 85, stobeRelBuyPrice(100, 56));

// ---- r lookup (character, else persona)
$player = 'RelTradePlayer';
$GLOBALS['db']->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$GLOBALS['db']->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [$player]);
$npc = static fn(int $aff, string $faction = 'Test Traders', array $extra = []): array => [
    'name' => 'RelTrade Npc', 'faction' => $faction,
    'extended_data' => json_encode(['relationships' => [$player => ['aff' => $aff, 'type' => 'neutral']]]),
    'equipment' => 'Katana x1 value 900', 'inventory' => 'Bread x5 value 10',
    'metadata' => json_encode(['trader_inventory_items' => [['name' => 'Iron Hat', 'count' => 1, 'value_each' => 500]]]),
] + $extra;
check('r from the map', stobeRelValue($npc(42), $player) === 42);
check('r: no entry = 0', stobeRelValue(['name' => 'X', 'extended_data' => '{}'], $player) === 0);
check('r (item 100 b): a squad character without an entry reads 0, not the persona entry', stobeRelValue($npc(33), 'SomeSquadChar') === 0);
check('r (item 100 b): nobody speaking falls back to the persona entry', stobeRelValue($npc(33), '') === 33);
check('r: the persona entry is case-insensitive', stobeRelValue($npc(21), strtoupper($player)) === 21);

// ---- 102 weapon guard at +69/+70, exemptions
check('weapon: r=69 blocked', !stobeDealWeaponReleaseAllowed($npc(69), $player, 'social'));
check('weapon: r=70 allowed', stobeDealWeaponReleaseAllowed($npc(70), $player, 'social'));
check('weapon: surrendering exempt', stobeDealWeaponReleaseAllowed($npc(-50), $player, 'surrender'));
$pf = function_exists('getCurrentPlayerFactionIdentity') ? strval(getCurrentPlayerFactionIdentity()['name'] ?? '') : '';
if ($pf !== '') check('weapon: squadmate (player faction) exempt', stobeDealWeaponReleaseAllowed($npc(-50, $pf), $player, 'social'));
else echo "SKIP squadmate exemption (no player faction in this DB)\n";
[$kept, $removed] = stobeDealFilterWeaponActions(['DROP_WEAPON', 'SHEATHE_WEAPON@into my pack', 'UNEQUIP_ITEM@Katana', 'SHEATHE_WEAPON', 'GIVE_ITEM@' . $player . '@Bread@1'], $npc(69), $player, 'social');
check('weapon r=69: drop, stow-to-pack and unequip removed; plain sheathe and bread kept',
    count($removed) === 3 && in_array('SHEATHE_WEAPON', $kept, true) && in_array('GIVE_ITEM@' . $player . '@Bread@1', $kept, true), [$kept, $removed]);
[$kept70, $removed70] = stobeDealFilterWeaponActions(['DROP_WEAPON'], $npc(70), $player, 'social');
check('weapon r=70: drop allowed', $removed70 === [] && $kept70 === ['DROP_WEAPON']);

// ---- 103 willingness edges
$gate = static fn(int $r, array $terms, string $kind = 'social') => stobeRelTradeGate('RelTrade Npc', $npc($r), $player, $kind, $terms);
$payLater = [['kind' => 'GIVE_ITEM', 'by' => 'npc', 'to' => 'player', 'item' => 'Bread', 'quantity' => 1], ['kind' => 'GIVE_CATS', 'by' => 'player', 'to' => 'npc', 'amount' => 20, 'when' => 'after_npc']];
check('pay-later r=-1 refused', ($gate(-1, $payLater)['error'] ?? '') === 'relationship_no_pay_later');
check('pay-later r=0 allowed', !empty($gate(0, $payLater)['ok']));
$favour = [['kind' => 'FIRST_AID', 'by' => 'npc', 'target' => 'player']];
check('free favour r=29 refused', ($gate(29, $favour)['error'] ?? '') === 'relationship_no_free_favour');
check('free favour r=30 allowed', !empty($gate(30, $favour)['ok']));
$gift = [['kind' => 'GIVE_ITEM', 'by' => 'npc', 'to' => 'player', 'item' => 'Bread', 'quantity' => 1]];
check('free item r=55 refused (gift threshold 56)', ($gate(55, $gift)['error'] ?? '') === 'relationship_no_free_gift');
check('free item r=56 allowed', !empty($gate(56, $gift)['ok']));
$buy = [['kind' => 'GIVE_ITEM', 'by' => 'npc', 'to' => 'player', 'item' => 'Iron Hat', 'quantity' => 1], ['kind' => 'GIVE_CATS', 'by' => 'player', 'to' => 'npc', 'amount' => 500]];
check('no trade at r=-80', ($gate(-80, $buy)['error'] ?? '') === 'relationship_no_trade');
check('trade at r=-79 (priced, not refused)', !empty($gate(-79, $buy)['ok']));
check('combat deals keep their own rules (no no-trade line at -90)', !empty($gate(-90, [['kind' => 'STOP_ATTACK', 'by' => 'npc', 'target' => 'player'], ['kind' => 'GIVE_CATS', 'by' => 'player', 'to' => 'npc', 'amount' => 50]], 'combat')['ok']));
if ($pf !== '') check('squadmate exempt from the lines', !empty(stobeRelTradeGate('RelTrade Npc', $npc(-90, $pf), $player, 'social', $payLater)['ok']));

// ---- 104 deal prices (Iron Hat sells for 500)
foreach ([[-50, 1502], [-10, 524], [0, 500], [10, 489], [56, 421], [100, 350]] as [$r, $want]) {
    $g = $gate($r, $buy);
    $amount = intval($g['terms'][1]['amount'] ?? 0);
    $expect = max(500, $want); // the player offered 500: raised to her price when hers is higher, kept otherwise
    check("price r=$r: player pays " . $expect, !empty($g['ok']) && $amount === $expect && (!empty($g['priced']) === ($want > 500)), [$amount, $g['priced'] ?? null]);
}
$cheap = $buy; $cheap[1]['amount'] = 100;
$g = $gate(56, $cheap);
check('price r=+56: an offer of 100 for a 500 hat becomes her price 421', intval($g['terms'][1]['amount'] ?? 0) === 421 && !empty($g['priced']), $g);
$GLOBALS['db']->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
echo "pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
