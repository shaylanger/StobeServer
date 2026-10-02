<?php

/**
 * Voice hand-over: "Here are your 200 cats", "Take this bread", "Two hundred cats"
 * (when that is what you owe) makes the PLAYER character really hand the Cats or
 * item to the NPC you are talking to. It uses the same action line STOBE's manual
 * "give cats"/"give item" chat actions use (actor = player), so the game performs
 * the transfer and logs ACTION_EXEC, which the negotiation engine verifies.
 *
 * Offers ("I'll give you 200 if…"), questions and future tense never pay.
 * Toggle: general_settings NEGOTIATION_VOICE_PAYMENT (default true).
 */

function stobeNegVoicePaymentEnabled(): bool {
    try {
        return getSettingBool('NEGOTIATION_VOICE_PAYMENT', true);
    } catch (Throwable $e) {
        return true;
    }
}

/** "two thousand three hundred" -> "2300" inside free text (speech-to-text writes numbers as words). */
function stobeNegWordsToNumbers(string $text): string {
    static $values = ['zero'=>0,'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,
        'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,
        'twenty'=>20,'thirty'=>30,'forty'=>40,'fifty'=>50,'sixty'=>60,'seventy'=>70,'eighty'=>80,'ninety'=>90,
        'hundred'=>100,'thousand'=>1000,'million'=>1000000];
    $word = '(?:' . implode('|', array_keys($values)) . ')';
    $pattern = '/\b(?:an?\s+)?' . $word . '(?:(?:\s+and)?[\s-]+' . $word . ')*\b/i';
    return preg_replace_callback($pattern, static function (array $m) use ($values): string {
        $total = 0;
        $current = 0;
        foreach (preg_split('/[\s-]+/', strtolower($m[0])) ?: [] as $token) {
            if ($token === 'and') continue;
            if ($token === 'a' || $token === 'an') { $current = 1; continue; }
            $v = $values[$token] ?? null;
            if ($v === null) continue;
            if ($v === 100) { $current = max(1, $current) * 100; }
            elseif ($v >= 1000) { $total += max(1, $current) * $v; $current = 0; }
            else { $current += $v; }
        }
        return strval($total + $current);
    }, $text) ?? $text;
}

/** Remaining Cats the player owes this NPC on an open deal, or 0. */
function stobeNegPlayerOwedCats(string $npc): int {
    try {
        $open = stobeDealOpenForNpc($npc);
    } catch (Throwable $e) {
        return 0;
    }
    if ($open === null) return 0;
    $owed = 0;
    $state = stobeNegDecode($open['term_state'] ?? []);
    if (count($state) > 0) {
        foreach ($state as $t) {
            if (($t['by'] ?? '') === 'player' && ($t['kind'] ?? '') === 'GIVE_CATS' && ($t['status'] ?? '') === 'AWAITING_PLAYER') {
                $owed += max(0, intval($t['amount'] ?? 0) - intval($t['paid_so_far'] ?? 0));
            }
        }
        return $owed;
    }
    foreach (stobeNegDecode($open['terms'] ?? []) as $t) {
        if (($t['by'] ?? '') === 'player' && ($t['kind'] ?? '') === 'GIVE_CATS') $owed += intval($t['amount'] ?? 0);
    }
    return $owed;
}

/** Items the player still owes this NPC on an open deal. */
function stobeNegPlayerOwedItems(string $npc): array {
    try {
        $open = stobeDealOpenForNpc($npc);
    } catch (Throwable $e) {
        return [];
    }
    if ($open === null) return [];
    $state = stobeNegDecode($open['term_state'] ?? []);
    $terms = count($state) > 0 ? $state : stobeNegDecode($open['terms'] ?? []);
    $owed = [];
    foreach ($terms as $t) {
        if (($t['by'] ?? '') !== 'player' || !in_array($t['kind'] ?? '', ['GIVE_ITEM','RETURN_ITEM'], true)) continue;
        if (count($state) > 0 && ($t['status'] ?? '') !== 'AWAITING_PLAYER') continue;
        $owed[] = $t;
    }
    return $owed;
}

/** lower-case base name -> the name as the game wrote it ("grog" -> "Grog"). */
function stobeNegInventoryDisplayNames(string $text): array {
    $names = [];
    foreach (preg_split('/,\s*(?=[^,]*\bx\d+)/', $text) ?: [] as $entry) {
        if (!preg_match('/^\s*(.+?)\s+x\d+\b/', $entry, $m)) continue;
        $display = trim(preg_replace('/\s*\[[^\]]*\]|\s*\([^)]*\)/', '', $m[1]) ?? '');
        if ($display !== '') $names[strtolower($display)] = $display;
    }
    return $names;
}

/** A carried inventory item satisfying a (possibly generic) wanted item, or ''. */
function stobeNegPickInventoryItem(array $inventory, string $wanted): string {
    foreach ($inventory as $name => $qty) {
        if ($qty > 0 && stobeNegItemMatchesTerm($name, $wanted)) return $name;
    }
    return '';
}

/**
 * Decide what (if anything) the player is handing over in this line.
 * Returns ['cats'=>int, 'items'=>[['name'=>..., 'qty'=>n]], 'note'=>string, 'reason'=>string].
 */
function stobeNegParseHandover(string $message, string $npc, string $player): array {
    $none = ['cats'=>0, 'items'=>[], 'note'=>'', 'reason'=>'no_handover'];
    $full = strtolower(stobeNegWordsToNumbers($message));
    $full = preg_replace('/(\d),(\d{3})/', '$1$2', $full) ?? $full;
    $trimmed = trim($full);
    if ($trimmed === '') return $none;
    // Judge each sentence on its own: a hand-over is a short, present-tense sentence.
    // "I offered to pay you 200 ... there's no way I'm paying for that" must never pay.
    $cuePattern = "/\b(here'?s|here is|here are|here you go|here,|(?<!you )take (it|this|these|them|that|the)|there you go|as promised|as agreed|i'?m (giving|paying|handing) you|i am (giving|paying|handing) you|i give you|i pay you|handing (you|it) over|have (it|these|this)|keep the change)\b/";
    $text = '';
    $fallback = '';
    $reason = 'no_handover';
    foreach (preg_split('/(?<=[.!?;])\s+|\n+/', $trimmed) ?: [] as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') continue;
        if (str_ends_with($sentence, '?')) { $reason = 'question'; continue; }
        if (preg_match("/\b(if|unless|i'?ll|i will|i would|i'?d|i can|i could|would you|will you|want|wanna|how about|what about|maybe|later|tomorrow|once you|after you|when you)\b/", $sentence)) { $reason = 'offer_or_future'; continue; }
        if (preg_match("/\b(no way|not|don'?t|doesn'?t|won'?t|never|nah|isn'?t|ain'?t|can'?t|cannot|refuse)\b/", $sentence)) { $reason = 'negated'; continue; }
        if (preg_match("/\b(offered|said|told|would have|earlier|last time|yesterday|supposed to)\b/", $sentence)) { $reason = 'reported_speech'; continue; }
        if (str_word_count($sentence) > 18) { $reason = 'too_long'; continue; }
        // The hand-over sentence itself ("Fine. Here are 300 cats." -> the second one).
        if (!preg_match($cuePattern, $sentence)) { $fallback = $fallback === '' ? $sentence : $fallback; continue; }
        $text = $sentence;
        break;
    }
    // A message that is only an amount ("Two hundred cats.") is judged by the debt rule below.
    if ($text === '' && $fallback !== '' && preg_match('/^\s*(ok(ay)?[, ]+|alright[, ]+|fine[, ]+)?\d+\s*(cats?|c)?\s*[.!]*\s*$/', $trimmed)) $text = $fallback;
    if ($text === '') return ['reason'=>$reason] + $none;
    // "..., I pay you 300. Deal?" asks for agreement: an offer, unless it's an explicit "here's".
    if (preg_match("/\b(deal|agreed|okay|ok|alright|sound good|fair|you in|yes|right)\s*\?\s*$/", $trimmed)
        && !preg_match("/\b(here'?s|here is|here are|here you go|there you go|as promised|as agreed)\b/", $text)) {
        return ['reason'=>'offer_or_future'] + $none;
    }
    $cue = preg_match($cuePattern, $text) === 1;

    $cats = 0;
    $owed = stobeNegPlayerOwedCats($npc);
    // Speech-to-text often hears "cats" as counts/caps/cads/kats/cuts.
    if (preg_match('/\b(\d{1,9})\s*(cats?|kats?|katz|counts?|caps?|cads?|cuts|cash|money|c)\b/', $text, $m)) {
        $cats = intval($m[1]);
    } elseif ($cue && $owed > 0 && preg_match('/\b(\d{1,9})\b/', $text, $m)) {
        // "Here's your five hundred" while you owe Cats on a deal.
        $cats = intval($m[1]);
    }
    if ($cats === 0 && $cue && $owed > 0 && preg_match('/\b(cats?|counts?|caps?|money|payment|pay|cash|what i owe|your (share|cut))\b/', $text)) {
        $cats = $owed; // "Here's your money" pays what is owed.
    }
    $isHandover = $cue || ($cats > 0 && $owed > 0 && $cats <= $owed && preg_match('/^\s*(ok(ay)?[, ]+|alright[, ]+|fine[, ]+)?\d+\s*(cats?|c)?\s*[.!]*\s*$/', $trimmed));

    $items = [];
    if ($cue) {
        $playerRow = stobeNegNpcRow($player);
        $inventory = stobeNegInventoryCounts(strval($playerRow['inventory'] ?? ''));
        // 1. Items named outright ("take this vodka").
        foreach ($inventory as $name => $have) {
            if (strlen($name) < 3 || !str_contains($text, $name)) continue;
            $qty = 1;
            if (preg_match('/\b(\d{1,3})\s+' . preg_quote($name, '/') . '/', $text, $q)) $qty = max(1, intval($q[1]));
            $items[] = ['name'=>$name, 'qty'=>min($qty, $have)];
        }
        // 1b. A distinctive word of the name ("this bread" -> Poppyseed Bread), if exactly one fits.
        if (count($items) === 0) {
            $generic = ['basic','standard','high','quality','specialist','masterwork','shoddy','small','large','dried','brown','veggie','regulars','with','your','this','that','some'];
            $fits = [];
            foreach ($inventory as $name => $have) {
                foreach (preg_split('/[^a-z]+/', $name) ?: [] as $word) {
                    if (strlen($word) < 4 || in_array($word, $generic, true)) continue;
                    if (preg_match('/\b' . preg_quote($word, '/') . 's?\b/', $text)) { $fits[$name] = $have; break; }
                }
            }
            if (count($fits) === 1) {
                $name = array_key_first($fits);
                $items[] = ['name'=>$name, 'qty'=>1];
            }
        }
        // 2. "Here's your drink" / "what I owe you": the item(s) this deal says you owe.
        if (count($items) === 0 && preg_match("/\\b(your (drink|food|meal|item|payment|share|cut)|a drink|the drink|the food|some food|what i owe|as promised|as agreed|your (booze|round|grub)|here'?s your)\\b/", $text)) {
            foreach (stobeNegPlayerOwedItems($npc) as $owed) {
                $pick = stobeNegPickInventoryItem($inventory, strval($owed['item']));
                if ($pick !== '') $items[] = ['name'=>$pick, 'qty'=>min(max(1, intval($owed['quantity'] ?? 1)), $inventory[$pick])];
            }
        }
        // 3. No deal but generic ("here, have a drink" / "here's some food"): hand over one you carry.
        // Not when a deal names what's owed: "here's your food" must not swap in some other food.
        if (count($items) === 0 && count(stobeNegPlayerOwedItems($npc)) === 0) {
            $generic = preg_match('/\b(a drink|this drink|your drink|some booze|a round)\b/', $text) ? 'drink'
                : (preg_match('/\b(some food|this food|your food|the food|a meal|something to eat|some grub)\b/', $text) ? 'food' : '');
            $pick = $generic !== '' ? stobeNegPickInventoryItem($inventory, $generic) : '';
            if ($pick !== '') $items[] = ['name'=>$pick, 'qty'=>1];
        }
    }
    if (!$isHandover || ($cats <= 0 && count($items) === 0)) {
        // "Here's the extra one thousand" with no deal on record still must not read as paid.
        if ($cue && ($owed > 0 || count(stobeNegPlayerOwedItems($npc)) > 0
            || preg_match('/\b(\d{1,9}|cats?|money|payment|cash|the rest|what i owe)\b/', $text))) {
            return ['reason'=>'claimed_nothing_moved'] + $none;
        }
        return $none;
    }

    $note = '';
    if ($cats > 0) {
        $money = stobeNegMoney(stobeNegNpcRow($player), true);
        if ($money['known'] && $money['value'] < $cats) {
            return ['cats'=>0, 'items'=>$items, 'reason'=>'insufficient_cats',
                'note'=>$player . ' reaches for ' . $cats . ' Cats but only has ' . $money['value'] . '. No money changed hands.'];
        }
    }
    return ['cats'=>$cats, 'items'=>$items, 'note'=>$note, 'reason'=>'handover'];
}

/**
 * Chat hook. Emits the player's transfer actions before the NPC replies and returns
 * a context line for the NPC. Never throws.
 */
function stobeNegVoiceHandover(string $npc, array|false $npcData, string $player, string $message, int $gamets): string {
    if (!stobeNegVoicePaymentEnabled() || !is_array($npcData) || $npc === '' || strcasecmp($npc, $player) === 0) return '';
    try {
        $parsed = stobeNegParseHandover($message, $npc, $player);
        if ($parsed['reason'] === 'insufficient_cats') {
            stobeLogInfo('Voice payment skipped: not enough Cats', ['npc'=>$npc, 'message'=>$message]);
            return strval($parsed['note']);
        }
        if ($parsed['reason'] === 'claimed_nothing_moved') {
            stobeLogInfo('Voice hand-over claimed but nothing matched', ['npc'=>$npc, 'message'=>$message]);
            return $player . ' talks as if handing something over, but nothing actually changed hands (no Cats or items were transferred). Do not act as if you were paid.';
        }
        if ($parsed['reason'] !== 'handover') return '';
        $actions = [];
        if ($parsed['cats'] > 0) $actions[] = 'GIVE_CATS@' . $npc . '@' . intval($parsed['cats']);
        $display = stobeNegInventoryDisplayNames(strval(stobeNegNpcRow($player)['inventory'] ?? ''));
        foreach ($parsed['items'] as $item) {
            $name = $display[strtolower(strval($item['name']))] ?? strval($item['name']);
            $actions[] = 'GIVE_ITEM@' . $npc . '@' . $name . (intval($item['qty']) > 1 ? '@' . intval($item['qty']) : '');
        }
        foreach ($actions as $action) {
            $wire = formatResponse($player, 'ActionQueue', $action);
            echo $wire;
            stobeLogOutputToPlugin($player, 'ActionQueue', $action, $wire);
        }
        if (ob_get_length()) @ob_flush();
        @flush();
        stobeLogInfo('Voice hand-over dispatched', ['player'=>$player, 'npc'=>$npc, 'actions'=>$actions, 'message'=>$message]);
        $GLOBALS['STOBE_VOICE_HANDOVER_NPC'] = $npc; // bug 34: settle her side before this request ends
        $GLOBALS['STOBE_VOICE_HANDOVER_CATS'] = intval($parsed['cats']); // bug 122
        $GLOBALS['STOBE_VOICE_HANDOVER_MESSAGE'] = $message;
        $parts = [];
        if ($parsed['cats'] > 0) $parts[] = intval($parsed['cats']) . ' Cats';
        foreach ($parsed['items'] as $item) $parts[] = intval($item['qty']) . ' x ' . $item['name'];
        return $player . ' is handing you ' . implode(' and ', $parts) . ' right now (the game is carrying out the transfer; it will show in deal progress once confirmed).';
    } catch (Throwable $e) {
        stobeLogWarn('Voice hand-over failed', ['npc'=>$npc, 'error'=>$e->getMessage()]);
        return '';
    }
}
