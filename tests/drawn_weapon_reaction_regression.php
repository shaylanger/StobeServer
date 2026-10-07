<?php
// Drawn-weapon reactions (Shay 2026-10-06): the bored processor turns &react=drawn_weapon into a speech-only
// reaction turn (listener = the player character, no chance gate, no director, no negotiation takeover,
// NEG_TEST_INJECT context "react"), with an instruction per kind and a stored context event.
require __DIR__ . '/../lib/bootstrap.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
check('helpers exist', function_exists('stobeReactRequest') && function_exists('stobeReactInstruction')
    && function_exists('stobeReactContextEvent') && function_exists('stobeReactFilterActions'));
if (!function_exists('stobeReactRequest')) { echo "pass=$pass fail=$fail\n"; exit(1); }

check('not a reaction turn', stobeReactRequest([]) === null && stobeReactRequest(['react' => 'other', 'react_kind' => 'warn']) === null);
check('unknown kind refused', stobeReactRequest(['react' => 'drawn_weapon', 'react_kind' => 'attack']) === null);
$r = stobeReactRequest(['react' => 'drawn_weapon', 'react_kind' => 'warn', 'react_detail' => 'Katana', 'react_player' => 'Shay', 'react_dist' => '54']);
check('parsed', is_array($r) && $r['kind'] === 'warn' && $r['weapon'] === 'Katana' && $r['player'] === 'Shay' && $r['meters'] === 5, $r);
$r2 = stobeReactRequest(['react' => 'drawn_weapon', 'react_kind' => 'friendly', 'react_detail' => '', 'react_dist' => '3']);
check('defaults', is_array($r2) && $r2['weapon'] === 'weapon' && $r2['meters'] === 1, $r2);

$warn = stobeReactInstruction($r, 'Varn', 'Shay');
check('warn: warning, no attack yet', str_contains($warn, 'warning') && str_contains($warn, 'Katana') && str_contains($warn, 'do not attack yet'), $warn);
$guard = stobeReactInstruction(['kind' => 'guard'] + $r, 'Town Guard', 'Shay');
check('guard: put it away', str_contains($guard, 'put the weapon away') && str_contains($guard, 'guard'), $guard);
$friendly = stobeReactInstruction(['kind' => 'friendly'] + $r, 'Varn', 'Shay');
check('friendly: asks why', str_contains($friendly, 'why the weapon is out') && str_contains($friendly, 'do not attack'), $friendly);
$ev = stobeReactContextEvent($r, 'Varn', 'Shay');
check('context event', $ev === 'Shay came within about 5 m of Varn with a drawn Katana in hand.', $ev);
check('speech only', stobeReactFilterActions([['command' => 'Attack', 'target' => 'Shay'], ['command' => 'Talk']]) === []);

// Wiring in processor/bored.php (the processor runs inside main.php, so check the source).
$src = file_get_contents(__DIR__ . '/../processor/bored.php');
check('bored: reaction turn read', str_contains($src, '$reactTurn = function_exists(\'stobeReactRequest\') ? stobeReactRequest($_GET) : null;'));
check('bored: skips chance gate and director', (bool)preg_match('/if \(is_array\(\$reactTurn\)\) \{\s*\$forceDirectiveTurn = true;\s*\$forceDirectorMode = false;/', $src));
check('bored: no negotiation takeover', str_contains($src, '(!is_array($reactTurn) && function_exists(\'stobeNegClaimDirective\'))'));
check('bored: listener is the player character', str_contains($src, '$listener = $reactTurn[\'player\'] !== \'\' ? $reactTurn[\'player\'] : $playerName;'));
check('bored: instruction', str_contains($src, '$boredInstruction = stobeReactInstruction($reactTurn, $speakerNpc, $listener);'));
check('bored: injection context react', str_contains($src, "stobeNegTestTakeInjection('react', \$speakerNpc, \$speakerData, \$listener)"));
check('bored: speech only', str_contains($src, '$responseActions = stobeReactFilterActions($responseActions);'));
check('bored: context event stored', str_contains($src, "storeEvent('infoaction', intval(\$timestamp), intval(\$gamets), \$reactEvent);"));
// The react block must run after the chance gate's inputs but before the gate itself.
$posReact = strpos($src, '$forceDirectiveTurn = true;');
$posGate = strpos($src, "if (!\$forceDirectorMode && !\$forceDirectiveTurn && \$roll >= \$boredChance)");
check('bored: react flag set before the chance gate', $posReact !== false && $posGate !== false && $posReact < $posGate);

echo "pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
