<?php
/**
 * STOBE test switches (Shay, 2026-10-03): plan rows that need a rare LLM choice, or a malformed reply the
 * guards must handle, can be triggered on purpose in game. All are general_settings rows, OFF by default
 * (missing, empty, "false" or "off"), each logs a WARN line when it fires, and the in-game wrappers in
 * tests/ingame/stobe/ (workspace repo) turn them on and off again (EXIT trap).
 *
 * NEG_TEST_INJECT (JSON): overrides fields of the model's structured reply for the next matching turn(s),
 *   before anything is streamed or parsed, so every guard downstream sees it like a real model reply.
 *     {"row":"27","npc":"Varn Oddie","context":"chat","steps":[{"deal_decision":"COUNTER","deal_terms":""}, ...]}
 *   - npc: optional; the speaker's name (also matches "Name [npc]" after a rename, and npc "X" vs "X [Y]").
 *   - context: chat (the player talks to her; default) | directive (her negotiation directive turn)
 *     | react (a drawn-weapon reaction turn, StobeDrawnWeapon.cpp) | relationship (the relationship evaluator's JSON
 *     reply after her turn, e.g. {"disclosed":[{"fact":"...","category":"background"}]}; only when the real gate runs it) | any.
 *   - each step = keys of the reply JSON to override: message, deal_decision, deal_terms (string or array),
 *     action, target, item, amount, ... Placeholders in strings: {player} {npc} {weapon} {worn}.
 *   - one step per turn, in order; the switch fires no more once all steps are used ("fired" is set).
 *   Log: `NEG_TEST_INJECT fired (test switch, row N)`.
 * NEG_TEST_FORCE_INITIATIVE (JSON): {"row":"37","kind":"assist|surrender","npc":"<optional>","queue_as_old_name":false}
 *   the next initiative check for a matching NPC queues that offer without the health/courage/cooldown
 *   gates (the situation itself - fighting the player / fighting others near the player - is still required).
 *   queue_as_old_name (row 25): an NPC named "Gost [Dust Bandit]" gets the directive under "Dust Bandit",
 *   the state a mid-fight naming leaves behind. One-shot. Log: `NEG_TEST_FORCE_INITIATIVE fired (test switch, row N)`.
 */

/** A test switch's JSON value, or null when it is off, unreadable or already used up. */
function stobeNegTestSwitchRead(string $id): ?array {
    try {
        $row = $GLOBALS['db']->fetchOne("SELECT value FROM general_settings WHERE id=$1", [$id]);
    } catch (Throwable $e) {
        return null;
    }
    $raw = trim(strval($row['value'] ?? ''));
    if ($raw === '' || in_array(strtolower($raw), ['false', 'off', '0', 'no'], true)) return null;
    $v = json_decode($raw, true);
    if (!is_array($v)) {
        if (function_exists('stobeLogWarn')) stobeLogWarn('Test switch value is not JSON; ignored', ['id'=>$id, 'value'=>substr($raw, 0, 200)]);
        return null;
    }
    if (!empty($v['fired'])) return null;
    $v['_raw'] = strval($row['value']); // exact stored text, for the compare-and-swap
    return $v;
}

/** Writes the switch back only if nobody changed it meanwhile (two requests must not fire one step twice). */
function stobeNegTestSwitchSwap(string $id, string $oldRaw, array $new): bool {
    unset($new['_raw']);
    $res = $GLOBALS['db']->exec("UPDATE general_settings SET value=$3 WHERE id=$1 AND value=$2",
        [$id, $oldRaw, json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    return $res !== false && $GLOBALS['db']->affectedRows($res) === 1;
}

/** "Malzin" matches "Malzin", "Gost [Dust Bandit]" matches "Dust Bandit" and "Gost". */
function stobeNegTestNameMatches(string $want, string $npc): bool {
    $want = strtolower(trim($want));
    $npc = strtolower(trim($npc));
    if ($want === '') return true;
    if ($npc === '') return false;
    if ($want === $npc) return true;
    $strip = static fn(string $s): string => trim(preg_replace('/\s*\[[^\]]*\]\s*/', ' ', $s) ?? $s);
    if (preg_match('/\[\s*(.+?)\s*\]$/', $npc, $m) && $m[1] === $want) return true;
    return $strip($npc) !== '' && $strip($npc) === $strip($want);
}

/** Placeholders in an injected string: {player} {npc} {weapon} (her equipped weapon) {worn} (something else she wears). */
function stobeNegTestPlaceholders(string $s, string $npc, array|false $npcData, string $player): string {
    if (!str_contains($s, '{')) return $s;
    $weapon = '';
    $worn = '';
    if (is_array($npcData) && function_exists('stobeNegInventoryDisplayNames')) {
        foreach (stobeNegInventoryDisplayNames(strval($npcData['equipment'] ?? '')) as $lower => $display) {
            $isWeapon = function_exists('stobeDealIsWeaponName') && stobeDealIsWeaponName($lower);
            if ($isWeapon && $weapon === '') $weapon = $display;
            if (!$isWeapon && $worn === '') $worn = $display;
        }
    }
    return strtr($s, ['{player}'=>$player, '{npc}'=>$npc, '{weapon}'=>$weapon, '{worn}'=>$worn]);
}

/**
 * The injection for this turn, or null. Takes (and removes) the first step of NEG_TEST_INJECT when the
 * switch is on and npc/context match. $context: chat | directive.
 */
function stobeNegTestTakeInjection(string $context, string $npc, array|false $npcData = false, string $player = ''): ?array {
    $v = stobeNegTestSwitchRead('NEG_TEST_INJECT');
    if ($v === null) return null;
    $want = strtolower(trim(strval($v['context'] ?? 'chat')));
    if ($want !== 'any' && $want !== strtolower($context)) return null;
    if (!stobeNegTestNameMatches(strval($v['npc'] ?? ''), $npc)) return null;
    $steps = is_array($v['steps'] ?? null) ? array_values($v['steps']) : [];
    if (count($steps) === 0) {
        // A single step written at the top level: everything except the switch's own keys.
        $single = array_diff_key($v, array_flip(['row', 'npc', 'context', 'steps', 'fired', 'used', '_raw']));
        if (count($single) === 0) return null;
        $steps = [$single];
    }
    $step = array_shift($steps);
    if (!is_array($step)) return null;
    $new = $v;
    $new['steps'] = $steps;
    $new['used'] = intval($v['used'] ?? 0) + 1;
    if (count($steps) === 0) $new['fired'] = time();
    foreach (array_keys($new) as $k) {
        if (!in_array($k, ['row', 'npc', 'context', 'steps', 'fired', 'used'], true)) unset($new[$k]); // top-level step consumed
    }
    if (!stobeNegTestSwitchSwap('NEG_TEST_INJECT', strval($v['_raw']), $new)) return null;
    if ($player === '' && function_exists('getSetting')) $player = function_exists('normalizeParticipantNameToken')
        ? normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter')) : getSetting('PLAYER_NAME', 'Drifter');
    foreach ($step as $k => $val) {
        if (is_string($val)) $step[$k] = stobeNegTestPlaceholders($val, $npc, $npcData, $player);
        elseif (is_array($val)) {
            array_walk_recursive($val, static function (&$x) use ($npc, $npcData, $player): void {
                if (is_string($x)) $x = stobeNegTestPlaceholders($x, $npc, $npcData, $player);
            });
            $step[$k] = $val;
        }
    }
    $inject = ['row'=>strval($v['row'] ?? ''), 'npc'=>$npc, 'context'=>$context, 'step'=>$step,
        'step_no'=>intval($new['used']), 'left'=>count($steps), 'player'=>$player];
    if (function_exists('stobeLogWarn')) {
        stobeLogWarn('NEG_TEST_INJECT fired (test switch, row ' . $inject['row'] . ')', [
            'npc'=>$npc, 'context'=>$context, 'step'=>$inject['step_no'], 'left'=>$inject['left'], 'override'=>$step,
        ]);
    }
    return $inject;
}

/** The model's raw structured reply with the injected fields put in (a plain-text reply becomes the message). */
function stobeNegTestApplyInjection(string $raw, array $inject): string {
    $decoded = function_exists('stobeDecodeStructuredDialoguePayload') ? stobeDecodeStructuredDialoguePayload($raw) : json_decode($raw, true);
    if (!is_array($decoded) || count($decoded) === 0) {
        $decoded = ['character'=>strval($inject['npc'] ?? ''), 'listener'=>strval($inject['player'] ?? ''),
            'message'=>trim($raw) !== '' ? trim($raw) : '...', 'mood'=>'neutral', 'action'=>'Talk',
            'target'=>strval($inject['player'] ?? ''), 'lang'=>'en'];
    }
    foreach ((array)($inject['step'] ?? []) as $k => $val) {
        if ($k === 'deal_terms' && is_array($val)) $val = json_encode($val, JSON_UNESCAPED_SLASHES);
        $decoded[$k] = $val;
    }
    return json_encode($decoded, JSON_UNESCAPED_SLASHES);
}

/** Relationship-evaluator reply (context "relationship") with the injected keys put in, e.g. "disclosed". */
function stobeNegTestApplyRelationshipInjection(string $raw, array $inject): string {
    $d = json_decode(trim($raw), true);
    if (!is_array($d) && preg_match('/\{[\s\S]*\}/', $raw, $m)) $d = json_decode($m[0], true);
    if (!is_array($d)) $d = [];
    if (!is_array($d['updates'] ?? null)) $d['updates'] = [];
    foreach ((array)($inject['step'] ?? []) as $k => $val) $d[$k] = $val;
    return json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Stream wrapper: runs the real model call into a buffer, puts the injection in, then feeds the result to
 * the caller's delta callback in pieces (so streaming, held-back speech and every check run as usual).
 * $realCall(callable $collector): string|false.
 */
function stobeNegTestStreamInjected(array $inject, callable $realCall, callable $onTextDelta): string {
    $collected = '';
    try {
        $real = $realCall(static function (string $d) use (&$collected): void { $collected .= $d; });
        if (is_string($real) && trim($real) !== '') $collected = $real;
    } catch (Throwable $e) {
        if (function_exists('stobeLogWarn')) stobeLogWarn('NEG_TEST_INJECT: model call failed; injecting on an empty reply', ['error'=>$e->getMessage()]);
    }
    $out = stobeNegTestApplyInjection($collected, $inject);
    foreach (str_split($out, 48) as $piece) $onTextDelta($piece);
    return $out;
}

/** NEG_TEST_FORCE_INITIATIVE for this NPC, or null. */
function stobeNegTestForcedInitiative(string $name): ?array {
    $v = stobeNegTestSwitchRead('NEG_TEST_FORCE_INITIATIVE');
    if ($v === null) return null;
    $kind = strtolower(trim(strval($v['kind'] ?? '')));
    if (!in_array($kind, ['surrender', 'assist'], true)) return null;
    if (!stobeNegTestNameMatches(strval($v['npc'] ?? ''), $name)) return null;
    $v['kind'] = $kind;
    return $v;
}

/** The name the forced directive is queued under: his old (bracketed) name when asked for (row 25). */
function stobeNegTestInitiativeQueueName(?array $forced, string $name): string {
    if ($forced === null || empty($forced['queue_as_old_name'])) return $name;
    return preg_match('/^.+\[\s*(.+?)\s*\]$/', trim($name), $m) ? $m[1] : $name;
}

/** Marks the forced initiative used (one-shot) and logs it. */
function stobeNegTestInitiativeFired(array $forced, string $name, string $queuedAs): void {
    $new = $forced;
    $new['fired'] = time();
    $new['fired_npc'] = $name;
    stobeNegTestSwitchSwap('NEG_TEST_FORCE_INITIATIVE', strval($forced['_raw'] ?? ''), $new);
    if (function_exists('stobeLogWarn')) {
        stobeLogWarn('NEG_TEST_FORCE_INITIATIVE fired (test switch, row ' . strval($forced['row'] ?? '') . ')', [
            'npc'=>$name, 'kind'=>strval($forced['kind'] ?? ''), 'queued_as'=>$queuedAs,
        ]);
    }
}
