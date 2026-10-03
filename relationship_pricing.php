<?php
/**
 * Item 104 interface for Stobe.dll's shop-window hook: relationship values and the price formula.
 * GET relationship_pricing.php?player=<player character>&npcs=<name or serial>|<name or serial>|...
 *   (serial = the character's hand serial; up to 64 entries)
 * -> {"ok":true,"player":"Beaks","constants":{...},"entries":[{"query":"...","npc":"Apothecary Abia","found":true,
 *     "r":12,"buy_factor":0.9711,"sell_factor":1.0097}, ...]}
 * Unknown NPC = r 0 (vanilla prices). Read-only.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
require_once(__DIR__ . '/lib/bootstrap.php');
require_once(__DIR__ . '/lib/relationship_trading.php');

$player = normalizeParticipantNameToken(strval($_GET['player'] ?? ''));
if ($player === '') $player = normalizeParticipantNameToken(strval(getSetting('PLAYER_NAME', 'Drifter')));
$queries = array_slice(array_values(array_filter(array_map('trim', explode('|', strval($_GET['npcs'] ?? ''))), 'strlen')), 0, 64);
$db = $GLOBALS['db'];
$entries = [];
foreach ($queries as $q) {
    $name = $q;
    if (preg_match('/^(?:hand_)?(\d+)$/', $q, $m)) {
        $row = $db->fetchOne("SELECT name FROM core_npc_master WHERE LOWER(COALESCE(metadata->>'storage_id','')) = LOWER($1) LIMIT 1", ['hand_' . $m[1]]);
        $name = is_array($row) ? strval($row['name']) : '';
    }
    $npcData = $name !== '' ? getNpcData($name) : false;
    $r = is_array($npcData) ? stobeRelValue($npcData, $player) : 0;
    $entries[] = ['query' => $q, 'npc' => $name, 'found' => is_array($npcData), 'r' => $r,
        'buy_factor' => round(stobeRelBuyFactor($r), 4), 'sell_factor' => round(stobeRelSellFactor($r), 4)];
}
echo json_encode(['ok' => true, 'player' => $player, 'constants' => [
    'buy_discount_max' => STOBE_REL_BUY_DISCOUNT_MAX, 'buy_discount_exp' => STOBE_REL_BUY_DISCOUNT_EXP,
    'buy_increase_max' => STOBE_REL_BUY_INCREASE_MAX, 'buy_increase_exp' => STOBE_REL_BUY_INCREASE_EXP,
    'sell_bonus_max' => STOBE_REL_SELL_BONUS_MAX, 'sell_bonus_exp' => STOBE_REL_SELL_BONUS_EXP,
    'sell_cut_max' => STOBE_REL_SELL_CUT_MAX, 'sell_cut_exp' => STOBE_REL_SELL_CUT_EXP,
    'trader_buy_ratio' => STOBE_REL_TRADER_BUY_RATIO,
], 'entries' => $entries], JSON_UNESCAPED_UNICODE);
