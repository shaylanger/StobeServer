<?php

/**
 * Relationship-shaped trading (Shay, 2026-10-03; items 102-104). One smooth formula on r, the NPC's relationship
 * value toward the player character doing the deal (-100..100; no history = 0 = vanilla).
 *  - Weapon guard: an outsider gives up / drops / stows her weapon only at r >= 70 (squad and surrender exempt).
 *  - Prices: player buys: r>0 discount 30%*(r/100)^1.1, r<0 increase 1000%*(|r|/100)^2.32;
 *            player sells: r>0 they pay up to +10% (1.1 shape), r<0 up to -90% (2.32 shape).
 *            Floor: a discounted buy never goes below what the trader pays for it, nor below what they'd pay the
 *            player for it right now (no buy/sell loop profit).
 *  - Willingness: no trade at r <= -80; pay-later only at r >= 0; free favours at r >= 30; free items/Cats at the
 *    gift threshold (GIFT_TRUST_THRESHOLD, 56).
 * The constants are also served to Stobe.dll's shop-window hook (relationship_pricing.php).
 */

const STOBE_REL_BUY_DISCOUNT_MAX = 0.30;   // at r = +100
const STOBE_REL_BUY_DISCOUNT_EXP = 1.1;
const STOBE_REL_BUY_INCREASE_MAX = 10.0;   // +1000% at r = -100
const STOBE_REL_BUY_INCREASE_EXP = 2.32;
const STOBE_REL_SELL_BONUS_MAX = 0.10;     // they pay +10% at r = +100
const STOBE_REL_SELL_BONUS_EXP = 1.1;
const STOBE_REL_SELL_CUT_MAX = 0.90;       // they pay -90% at r = -100 (Shay, 2026-10-03, item 104)
const STOBE_REL_SELL_CUT_EXP = 2.32;
const STOBE_REL_TRADER_BUY_RATIO = 0.5;    // what a trader pays for an item, as a share of its sell price
const STOBE_REL_WEAPON_MIN = 70;
const STOBE_REL_NO_TRADE_MAX = -80;
const STOBE_REL_PAY_LATER_MIN = 0;
const STOBE_REL_FREE_FAVOUR_MIN = 30;

function stobeRelClamp(int $r): int { return max(-100, min(100, $r)); }

/**
 * r for (NPC, player character). The live relationship map (core_npc_master extended_data->relationships; the REL
 * system writes into the same map) by the character's name (item 100 (b): each squad character has its own
 * history); the PLAYER_NAME persona only when no character is given. No entry = 0.
 */
function stobeRelValue(array|false $npcData, string $player): int {
    if (!is_array($npcData) || !function_exists('stobeGetNpcRelationshipMap')) return 0;
    $map = stobeGetNpcRelationshipMap($npcData);
    // Item 100 (b): the speaking character's own entry; the persona only when nobody speaks.
    $names = [normalizeParticipantNameToken($player)];
    if ($names[0] === '' && function_exists('getSetting')) $names[] = normalizeParticipantNameToken(strval(getSetting('PLAYER_NAME', '')));
    foreach ($names as $want) {
        if ($want === '') continue;
        foreach ($map as $target => $entry) {
            if (strcasecmp(normalizeParticipantNameToken(strval($target)), $want) === 0) {
                return stobeRelClamp(intval(is_array($entry) ? ($entry['aff'] ?? 0) : 0));
            }
        }
    }
    return 0;
}

/** Price multiplier when the player buys from her. */
function stobeRelBuyFactor(int $r): float {
    $r = stobeRelClamp($r);
    if ($r > 0) return 1.0 - STOBE_REL_BUY_DISCOUNT_MAX * pow($r / 100.0, STOBE_REL_BUY_DISCOUNT_EXP);
    if ($r < 0) return 1.0 + STOBE_REL_BUY_INCREASE_MAX * pow(-$r / 100.0, STOBE_REL_BUY_INCREASE_EXP);
    return 1.0;
}

/** Price multiplier when the player sells to her (applied to what she normally pays). */
function stobeRelSellFactor(int $r): float {
    $r = stobeRelClamp($r);
    if ($r > 0) return 1.0 + STOBE_REL_SELL_BONUS_MAX * pow($r / 100.0, STOBE_REL_SELL_BONUS_EXP);
    if ($r < 0) return 1.0 - STOBE_REL_SELL_CUT_MAX * pow(-$r / 100.0, STOBE_REL_SELL_CUT_EXP);
    return 1.0;
}

/** What she pays the player for something she sells for $baseBuy, at r. */
function stobeRelSellPrice(int $baseBuy, int $r, float $ratio = STOBE_REL_TRADER_BUY_RATIO): int {
    return max(0, (int)floor($baseBuy * $ratio * stobeRelSellFactor($r)));
}

/**
 * What the player pays for something she sells for $baseBuy, at r. Never below what she pays for it, nor below
 * what she would pay the player for it now (no loop profit).
 */
function stobeRelBuyPrice(int $baseBuy, int $r, float $ratio = STOBE_REL_TRADER_BUY_RATIO): int {
    $price = (int)ceil($baseBuy * stobeRelBuyFactor($r));
    $floor = max((int)ceil($baseBuy * $ratio), stobeRelSellPrice($baseBuy, $r, $ratio) + ($baseBuy > 0 ? 1 : 0));
    return max($price, $floor);
}

/** Her sell price for one unit of $item: her shop list (trader_inventory_items value_each), else her inventory. */
function stobeRelItemBasePrice(array|false $npcData, string $item): ?int {
    if (!is_array($npcData)) return null;
    $want = strtolower(trim(preg_replace('/\s*\[[^\]]*\]/', '', $item) ?? $item));
    if ($want === '') return null;
    $meta = is_array($npcData['metadata'] ?? null) ? $npcData['metadata'] : (json_decode(strval($npcData['metadata'] ?? '{}'), true) ?: []);
    $shop = $meta['trader_inventory_items'] ?? [];
    if (is_string($shop)) $shop = json_decode($shop, true) ?: [];
    foreach (is_array($shop) ? $shop : [] as $e) {
        if (!is_array($e)) continue;
        $n = strtolower(trim(strval($e['name'] ?? '')));
        if ($n !== '' && ($n === $want || str_contains($n, $want)) && intval($e['value_each'] ?? 0) > 0) return intval($e['value_each']);
    }
    foreach (['inventory', 'equipment'] as $col) {
        foreach (preg_split('/,\s*/', strval($npcData[$col] ?? '')) ?: [] as $part) {
            if (preg_match('/^\s*(.+?)\s+x\d+\s+value\s+(\d+)/i', $part, $m)) {
                $n = strtolower(trim($m[1]));
                if ($n === $want || str_contains($n, $want)) return intval($m[2]);
            }
        }
    }
    return null;
}

/** Log through the deal logger when present. */
function stobeRelLog(string $level, string $message, array $context): void {
    if (function_exists('stobeDealLog')) stobeDealLog($level, $message, $context);
    elseif (function_exists('stobeLogInfo')) stobeLogInfo($message, $context);
}

/**
 * Items 103/104: willingness lines and relationship prices for a deal the NPC is about to record.
 * Returns ['ok'=>true, 'terms'=>..., 'priced'=>bool, 'r'=>r] or ['ok'=>false, 'error'=>..., 'refusal_line'=>...].
 */
function stobeRelTradeGate(string $npc, array|false $npcData, string $player, string $kind, array $terms, ?array $playerRow = null): array {
    $out = ['ok' => true, 'terms' => $terms, 'priced' => false, 'r' => 0];
    if (!is_array($npcData)) return $out;
    if (function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npcData)) return $out; // squadmates exempt
    $r = stobeRelValue($npcData, $player);
    $out['r'] = $r;
    $hostile = in_array($kind, ['combat', 'surrender', 'assist'], true);
    $refuse = static function (string $error, string $line, string $rule) use ($npc, $player, $r, $kind): array {
        stobeRelLog('warn', 'Negotiation refused by relationship: ' . $rule . ' (item 103)', ['npc' => $npc, 'player' => $player, 'r' => $r, 'kind' => $kind]);
        return ['ok' => false, 'error' => $error, 'refusal_line' => $line, 'r' => $r];
    };
    $playerTerms = array_values(array_filter($terms, static fn($t) => is_array($t) && ($t['by'] ?? '') === 'player'));
    $npcTerms = array_values(array_filter($terms, static fn($t) => is_array($t) && ($t['by'] ?? '') === 'npc'));
    if (!$hostile && $r <= STOBE_REL_NO_TRADE_MAX) {
        return $refuse('relationship_no_trade', "I'm not trading with you. Not for any price.", 'no trade at r <= ' . STOBE_REL_NO_TRADE_MAX);
    }
    if ($r < STOBE_REL_PAY_LATER_MIN) {
        foreach ($playerTerms as $t) {
            if (strval($t['when'] ?? '') === 'after_npc') {
                return $refuse('relationship_no_pay_later', "Pay first. I don't trust you to pay me later.", 'pay-later needs r >= ' . STOBE_REL_PAY_LATER_MIN);
            }
        }
    }
    if (count($playerTerms) === 0 && $kind !== 'surrender') {
        $gives = array_filter($npcTerms, static fn($t) => in_array(strval($t['kind'] ?? ''), ['GIVE_ITEM', 'GIVE_CATS', 'LOAN_ITEM'], true));
        $services = array_filter($npcTerms, static fn($t) => in_array(strval($t['kind'] ?? ''), ['FIRST_AID', 'PROTECT', 'SAFE_PASSAGE', 'RELEASE_PRISONER'], true));
        $gift = function_exists('getSettingInt') ? max(0, min(100, intval(getSettingInt('GIFT_TRUST_THRESHOLD', 56)))) : 56;
        if (count($gives) > 0 && $r < $gift) {
            return $refuse('relationship_no_free_gift', "Nothing's free. What do I get for it?", 'free items/Cats need r >= ' . $gift);
        }
        if (count($services) > 0 && $r < STOBE_REL_FREE_FAVOUR_MIN) {
            return $refuse('relationship_no_free_favour', "Why would I do that for nothing?", 'free favours need r >= ' . STOBE_REL_FREE_FAVOUR_MIN);
        }
    }
    if ($hostile) return $out;

    // Item 104: prices. Player buys items she sells for Cats.
    $catsFromPlayer = 0; $catsIdx = -1;
    foreach ($terms as $i => $t) {
        if (is_array($t) && ($t['kind'] ?? '') === 'GIVE_CATS' && ($t['by'] ?? '') === 'player') { $catsFromPlayer += intval($t['amount'] ?? 0); if ($catsIdx < 0) $catsIdx = $i; }
    }
    $required = 0; $known = true; $buyItems = 0;
    foreach ($npcTerms as $t) {
        if (($t['kind'] ?? '') !== 'GIVE_ITEM') continue;
        $buyItems++;
        $base = stobeRelItemBasePrice($npcData, strval($t['item'] ?? ''));
        if ($base === null) { $known = false; break; }
        $required += stobeRelBuyPrice($base * max(1, intval($t['quantity'] ?? 1)), $r);
    }
    if ($buyItems > 0 && $known && $catsIdx >= 0 && $catsFromPlayer < $required) {
        $terms[$catsIdx]['amount'] = intval($terms[$catsIdx]['amount']) + ($required - $catsFromPlayer);
        $out['terms'] = $terms; $out['priced'] = true;
        stobeRelLog('info', 'Deal price set by relationship (item 104)', ['npc' => $npc, 'player' => $player, 'r' => $r, 'side' => 'player buys', 'offered' => $catsFromPlayer, 'price' => $required]);
        return $out;
    }
    // Player sells items to her for Cats.
    $catsFromNpc = 0; $npcCatsIdx = -1;
    foreach ($terms as $i => $t) {
        if (is_array($t) && ($t['kind'] ?? '') === 'GIVE_CATS' && ($t['by'] ?? '') === 'npc') { $catsFromNpc += intval($t['amount'] ?? 0); if ($npcCatsIdx < 0) $npcCatsIdx = $i; }
    }
    if ($npcCatsIdx >= 0) {
        $maxPay = 0; $sellItems = 0; $known = true;
        $row = $playerRow ?? (function_exists('stobeNegNpcRow') ? stobeNegNpcRow($player) : []);
        foreach ($playerTerms as $t) {
            if (($t['kind'] ?? '') !== 'GIVE_ITEM') continue;
            $sellItems++;
            $base = stobeRelItemBasePrice(is_array($row) ? $row : false, strval($t['item'] ?? ''));
            if ($base === null) { $known = false; break; }
            $maxPay += stobeRelSellPrice($base * max(1, intval($t['quantity'] ?? 1)), $r);
        }
        if ($sellItems > 0 && $known && $catsFromNpc > $maxPay && $maxPay > 0) {
            $terms[$npcCatsIdx]['amount'] = max(1, intval($terms[$npcCatsIdx]['amount']) - ($catsFromNpc - $maxPay));
            $out['terms'] = $terms; $out['priced'] = true;
            stobeRelLog('info', 'Deal price set by relationship (item 104)', ['npc' => $npc, 'player' => $player, 'r' => $r, 'side' => 'player sells', 'asked' => $catsFromNpc, 'price' => $maxPay]);
        }
    }
    return $out;
}
