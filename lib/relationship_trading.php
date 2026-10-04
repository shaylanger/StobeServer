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
    // Item 119: the recorded Cats are her relationship price both ways (not only a cheaper offer raised), when the
    // deal is plain Cats for known items; 'counter' marks a change against the player (she asks more).
    $onlyKinds = static fn(array $ts, array $kinds): bool => count($ts) > 0 && count(array_filter($ts, static fn($t) => !in_array(strval($t['kind'] ?? ''), $kinds, true))) === 0;
    $catsFromPlayer = 0; $catsIdx = -1; $catsIdxs = [];
    foreach ($terms as $i => $t) {
        if (is_array($t) && ($t['kind'] ?? '') === 'GIVE_CATS' && ($t['by'] ?? '') === 'player') { $catsFromPlayer += intval($t['amount'] ?? 0); if ($catsIdx < 0) $catsIdx = $i; $catsIdxs[] = $i; }
    }
    $required = 0; $known = true; $buyItems = 0;
    foreach ($npcTerms as $t) {
        if (($t['kind'] ?? '') !== 'GIVE_ITEM') continue;
        $buyItems++;
        $base = stobeRelItemBasePrice($npcData, strval($t['item'] ?? ''));
        if ($base === null) { $known = false; break; }
        $required += stobeRelBuyPrice($base * max(1, intval($t['quantity'] ?? 1)), $r);
    }
    $plainBuy = $onlyKinds($playerTerms, ['GIVE_CATS']) && $onlyKinds($npcTerms, ['GIVE_ITEM']);
    if ($buyItems > 0 && $known && $catsIdx >= 0 && $required > 0
        && ($catsFromPlayer < $required || ($plainBuy && $catsFromPlayer > $required))) {
        if ($catsFromPlayer < $required) {
            $terms[$catsIdx]['amount'] = intval($terms[$catsIdx]['amount']) + ($required - $catsFromPlayer);
        } else {
            // Lower: take the excess off the later instalments first.
            $excess = $catsFromPlayer - $required;
            foreach (array_reverse($catsIdxs) as $i) {
                if ($excess <= 0) break;
                $have = intval($terms[$i]['amount'] ?? 0);
                $cut = $i === $catsIdx ? min($excess, $have - 1) : min($excess, $have);
                $terms[$i]['amount'] = $have - $cut;
                $excess -= $cut;
            }
            $terms = array_values(array_filter($terms, static fn($t) => !(is_array($t) && ($t['kind'] ?? '') === 'GIVE_CATS' && ($t['by'] ?? '') === 'player' && intval($t['amount'] ?? 0) <= 0)));
        }
        $out['terms'] = $terms; $out['priced'] = true; $out['counter'] = $catsFromPlayer < $required;
        stobeRelLog('info', 'Deal price set by relationship (item 104/119)', ['npc' => $npc, 'player' => $player, 'r' => $r, 'side' => 'player buys', 'asked' => $catsFromPlayer, 'price' => $required]);
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
            $out['terms'] = $terms; $out['priced'] = true; $out['counter'] = true;
            stobeRelLog('info', 'Deal price set by relationship (item 104/119)', ['npc' => $npc, 'player' => $player, 'r' => $r, 'side' => 'player sells', 'asked' => $catsFromNpc, 'price' => $maxPay]);
        } elseif ($sellItems > 0 && $known && $catsFromNpc < $maxPay && $maxPay > 0
            && $onlyKinds($npcTerms, ['GIVE_CATS']) && $onlyKinds($playerTerms, ['GIVE_ITEM'])) {
            // Item 119: she pays her relationship price, not less (up to what she's known to carry).
            $purse = function_exists('stobeDealNpcPurse') ? stobeDealNpcPurse($npc) : -1;
            $pay = $purse >= 0 ? min($maxPay, max($catsFromNpc, $purse)) : $maxPay;
            if ($pay > $catsFromNpc) {
                $terms[$npcCatsIdx]['amount'] = intval($terms[$npcCatsIdx]['amount']) + ($pay - $catsFromNpc);
                $out['terms'] = $terms; $out['priced'] = true; $out['counter'] = false;
                stobeRelLog('info', 'Deal price set by relationship (item 104/119)', ['npc' => $npc, 'player' => $player, 'r' => $r, 'side' => 'player sells', 'asked' => $catsFromNpc, 'price' => $pay, 'purse' => $purse]);
            }
        }
    }
    return $out;
}


// ---------------------------------------------------------------- Item 120: free asks outside deal terms

/** Item 120: what the player asks an outsider for with this line: 'heal', 'favour', 'gift' or ''. */
function stobeRelFreeAskKind(string $message): string {
    $m = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($message)));
    if ($m === '') return '';
    // A request: after "you"/"please" (would you, can you just ...) or at the start of a sentence ("Vel, fetch ...").
    $ask = "(?:\\b(?:you|please)\\b[^.?!]{0,25}?|(?:^|[.!?]\\s+)(?:[a-z']+,\\s*)?)";
    $heal = "\\b(?:bandage|patch\\s+(?:me|it|this|that|my|up)|give\\s+me\\s+first\\s+aid|first\\s+aid|heal\\s+(?:me|my|it|this|that|up)|stitch|splint|tend\\s+(?:to\\s+)?(?:me|my|this|that)|treat\\s+(?:me|my|this|that)|(?:look\\s+at|see\\s+to|fix)\\s+(?:my|this|that)\\s+(?:arm|leg|wound|cut|cuts|injur\\w*|head|chest|stomach|bleeding))";
    if (preg_match('/' . $ask . $heal . '/', $m)) return 'heal';
    $favour = "\\b(?:fetch|carry|haul|guard|protect|escort|watch\\s+(?:my|over)|keep\\s+watch|work\\s+for\\s+me|build|repair|cook\\s+(?:me|for\\s+me)|bring\\s+me|get\\s+me|follow\\s+me|come\\s+with\\s+me|walk\\s+me|take\\s+me\\s+to|deliver|rescue)\\b";
    if (preg_match('/' . $ask . $favour . '/', $m)) return 'favour';
    $gift = "\\b(?:give|hand|spare|toss|throw|gift|lend|loan)\\s+(?:me|us)\\b";
    if (preg_match('/' . $ask . $gift . '/', $m) || preg_match("/\\b(?:can|could|may)\\s+i\\s+(?:just\\s+)?(?:have|get|take)\\b/", $m)) return 'gift';
    return '';
}

/**
 * Item 120: does the ask offer payment? ['paid'=>bool, 'later_refused'=>bool]. "For free" / "nothing to pay
 * with" is never payment; paying later is payment only at r >= 0 (item 103 pay-later line).
 */
function stobeRelFreeAskPayment(string $message, int $r): array {
    $m = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", $message));
    if (preg_match("/\\b(for\\s+free|free\\s+of\\s+charge|nothing\\s+to\\s+pay|(?:can'?t|cannot|couldn'?t)\\s+pay|no\\s+(?:money|cats|coin)|(?:i'?m|i\\s+am)\\s+broke|on\\s+the\\s+house|for\\s+nothing)\\b/", $m)) {
        return ['paid' => false, 'later_refused' => false];
    }
    $pays = preg_match("/\\b(pay|paid|payment|\\d[\\d,]*\\s*(?:cats?|c)\\b|cats|coin|in\\s+exchange|in\\s+return|trade|swap|barter|owe\\s+you|reward)\\b/", $m) === 1;
    if (!$pays) return ['paid' => false, 'later_refused' => false];
    $later = preg_match("/\\b(later|tomorrow|next\\s+time|owe\\s+you|when\\s+i\\s+(?:can|get|have))\\b/", $m) === 1;
    if ($later && $r < STOBE_REL_PAY_LATER_MIN) return ['paid' => false, 'later_refused' => true];
    return ['paid' => true, 'later_refused' => false];
}

/** Item 120: her reply takes the ask on (words or actions) and turns nothing down. */
function stobeRelReplyTakesOn(string $reply, array $actions, string $player): bool {
    $t = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($reply)));
    if (function_exists('stobeReplyRefusesOrder') && $t !== '' && stobeReplyRefusesOrder($reply)) return false;
    if (preg_match("/\\b(no(?!\\s+(?:problem|worries|trouble))|nope|not\\s+for\\s+free|nothing'?s\\s+free|what'?s\\s+in\\s+it|pay\\s+(?:me|first|up)|costs?|price|cats\\s+first|for\\s+nothing)\\b/", $t)) return false;
    foreach ($actions as $a) {
        $parts = explode('@', strval($a));
        $cmd = strtoupper(trim($parts[0]));
        $target = trim(strval($parts[1] ?? ''));
        if (in_array($cmd, ['FIRST_AID','BODYGUARD','GUARD_TARGET','FOLLOW','MOVE_TO_TARGET','RESCUE','PUT_IN_BED','TASK_GOAL','WORK_GOAL','REPAIR','BUILD','GIVE_ITEM','GIVE_CATS'], true)) return true;
        if ($cmd === 'ROLEPLAY_ACTION' && $target !== '' && stobeRelIsPlayerSide($target, $player)) return true;
    }
    return preg_match("/\\b(i'?ll|i\\s+will|let\\s+me|sure|fine|alright|all\\s+right|okay|ok|here(?:'?s|\\s+you\\s+go)?|take\\s+it|hold\\s+still|show\\s+me|sit\\s+(?:down|still)|of\\s+course|right\\s+away|on\\s+it|in\\s+luck|go\\s+on\\s+then)\\b/", $t) === 1;
}

function stobeRelIsPlayerSide(string $name, string $player): bool {
    if (function_exists('stobeIsPlayerSideName')) return stobeIsPlayerSideName($name, $player);
    return strcasecmp(normalizeParticipantNameToken($name), normalizeParticipantNameToken($player)) === 0;
}

/** Item 120: her in-character refusal for a free ask below the line. */
function stobeRelFavourRefusalLine(string $kind): string {
    return match ($kind) {
        'heal' => "Kits cost money. Pay me and I'll patch you up.",
        'gift' => "Nothing's free out here. Pay for it, or go without.",
        'pay_later' => "Pay first. I don't trust you to pay me later.",
        default => "Why would I do that for nothing? Pay me and we'll talk.",
    };
}

/** Item 120: an outsider patches the player up at r >= +30, or under a deal. Squadmates always. */
function stobeRelFirstAidForPlayerAllowed(string $npc, array|false $npcData, string $player): bool {
    if (!is_array($npcData)) return false;
    if (function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npcData)) return true;
    if (stobeRelValue($npcData, $player) >= STOBE_REL_FREE_FAVOUR_MIN) return true;
    try {
        if (function_exists('stobeDealOpenForNpc') && stobeDealOpenForNpc($npc) !== null) return true;
        if (function_exists('stobeNegNpcHasDealContext') && stobeNegNpcHasDealContext($npc)) return true;
    } catch (Throwable $e) {
    }
    return false;
}

/**
 * Item 120: the willingness lines on a plain chat turn (no deal recorded). A non-squad NPC who takes on the
 * player's ask for a free favour below r +30, a free item/Cats below the gift threshold, or pay-later below 0
 * refuses in character instead (her service/gift actions dropped). An agreed heal at r >= +30 becomes real
 * first aid (FIRST_AID@player) instead of a roleplay notice.
 * $ctx: decision (deal decision this turn), open_deal (bool), blocked_gift (bool), already_streamed (bool).
 */
function stobeRelFreeAskGuard(string $npc, array|false $npcData, string $player, string $message, string $reply, array $actions, array $ctx = []): array {
    $out = ['text' => $reply, 'actions' => $actions, 'rule' => '', 'kind' => '', 'r' => 0, 'added' => [], 'removed' => []];
    if (!is_array($npcData) || trim($player) === '') return $out;
    if (function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npcData)) return $out; // squadmates exempt
    if (in_array(strval($ctx['decision'] ?? ''), ['ACCEPT', 'COUNTER', 'PROPOSE'], true) || !empty($ctx['open_deal'])) return $out;
    $kind = stobeRelFreeAskKind($message);
    $gives = []; $services = [];
    foreach ($actions as $i => $a) {
        $parts = explode('@', strval($a));
        $cmd = strtoupper(trim($parts[0]));
        $target = trim(strval($parts[1] ?? ''));
        $toPlayer = $target !== '' && stobeRelIsPlayerSide($target, $player);
        if (in_array($cmd, ['GIVE_ITEM', 'GIVE_CATS'], true) && ($toPlayer || $target === '')) $gives[] = $i;
        elseif (in_array($cmd, ['FIRST_AID','BODYGUARD','GUARD_TARGET','FOLLOW','MOVE_TO_TARGET','RESCUE','PUT_IN_BED','ROLEPLAY_ACTION'], true) && $toPlayer) $services[] = $i;
        elseif (in_array($cmd, ['TASK_GOAL','WORK_GOAL','REPAIR','BUILD'], true)) $services[] = $i;
    }
    if ($kind === '' && count($gives) === 0) return $out;
    $r = stobeRelValue($npcData, $player);
    $out['r'] = $r; $out['kind'] = $kind;
    $takesOn = stobeRelReplyTakesOn($reply, $actions, $player) || ($kind === 'gift' && !empty($ctx['blocked_gift']));
    if (!$takesOn && count($gives) === 0) return $out; // her own refusal or question stands
    $pay = stobeRelFreeAskPayment($message, $r);
    $giftMin = function_exists('getSettingInt') ? max(0, min(100, intval(getSettingInt('GIFT_TRUST_THRESHOLD', 56)))) : 56;
    $rule = ''; $line = ''; $log = '';
    if ($pay['later_refused']) {
        $rule = 'pay-later needs r >= ' . STOBE_REL_PAY_LATER_MIN; $line = stobeRelFavourRefusalLine('pay_later'); $log = 'Pay-later refused by relationship (item 120)';
    } elseif (in_array($kind, ['heal', 'favour'], true) && !$pay['paid'] && $r < STOBE_REL_FREE_FAVOUR_MIN) {
        $rule = 'free favours need r >= ' . STOBE_REL_FREE_FAVOUR_MIN; $line = stobeRelFavourRefusalLine($kind); $log = 'Free favour refused by relationship (item 120)';
    } elseif (($kind === 'gift' || count($gives) > 0) && !$pay['paid'] && $r < $giftMin) {
        $rule = 'free items/Cats need r >= ' . $giftMin; $line = stobeRelFavourRefusalLine('gift'); $log = 'Free gift refused by relationship (item 120)';
    }
    if ($rule !== '') {
        $drop = array_merge($gives, $services);
        foreach ($drop as $i) $out['removed'][] = $actions[$i];
        $out['actions'] = array_values(array_diff_key($actions, array_flip($drop)));
        if (empty($ctx['already_streamed'])) $out['text'] = $line;
        $out['rule'] = $rule;
        stobeRelLog('warn', $log, ['npc' => $npc, 'player' => $player, 'r' => $r, 'kind' => $kind, 'ask' => $message,
            'reply' => $reply, 'removed' => $out['removed'], 'already_streamed' => !empty($ctx['already_streamed'])]);
        return $out;
    }
    // Agreed heal: real first aid, not just a roleplay notice.
    if ($kind === 'heal' && $takesOn && $r >= STOBE_REL_FREE_FAVOUR_MIN) {
        foreach ($actions as $a) {
            if (preg_match('/^FIRST_AID@/i', strval($a))) return $out;
        }
        $out['actions'][] = 'FIRST_AID@' . $player;
        $out['added'][] = 'FIRST_AID@' . $player;
        stobeRelLog('info', 'Agreed first aid without action: FIRST_AID added (item 120)', ['npc' => $npc, 'player' => $player, 'r' => $r, 'reply' => $reply]);
    }
    return $out;
}
