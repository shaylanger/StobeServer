<?php
// Item 91 (A13, run m16): early fight events must survive a long fight in the prompt.
require __DIR__ . '/../lib/bootstrap.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
check('item 91 helpers exist', function_exists('stobeSelectFightSoFarEvents') && function_exists('stobeBuildFightSoFarPromptBlock'));
if (!function_exists('stobeSelectFightSoFarEvents')) { echo "pass=$pass fail=$fail\n"; exit(1); }

// A13-shaped fight: opening KO of Kor Gast, then ~4 minutes of per-hit rows with 8 more bandits.
$rows = [];
$g = 517600;
$rows[] = ['type' => 'combat', 'data' => 'Malzin: Initiated attack (talking to: Kor Gast)', 'gamets' => $g];
$rows[] = ['type' => 'combat', 'data' => 'Shay: Initiated attack (talking to: Kor Gast)', 'gamets' => $g];
$rows[] = ['type' => 'knockout', 'data' => 'Kor Gast was Knocked Out.', 'gamets' => $g + 200];
$rows[] = ['type' => 'knockout', 'data' => 'Dust Bandit: Knocked out by an Iron Stick from Captain Zepp', 'gamets' => $g + 250]; // town brawl
$rows[] = ['type' => 'combat', 'data' => 'Captain Zepp: Initiated attack (talking to: Dust Bandit)', 'gamets' => $g + 240];
for ($i = 0; $i < 400; $i++) {
    $who = 'Hungry Bandit ' . ($i % 8);
    $rows[] = ['type' => 'combat', 'data' => ($i % 2 ? "Malzin: Initiated attack (talking to: $who)" : "$who: Initiated attack (talking to: Shay)"), 'gamets' => $g + 600 + $i * 15];
}
for ($k = 0; $k < 8; $k++) {
    $rows[] = ['type' => 'knockout', 'data' => "Hungry Bandit $k was Knocked Out.", 'gamets' => $g + 1500 + $k * 700];
}
$now = $g + 600 + 400 * 15 + 1200; // question ~1 min after the last hit
usort($rows, fn($a, $b) => $b['gamets'] <=> $a['gamets']);
$ev = stobeSelectFightSoFarEvents($rows, 'Malzin', $now);
$lines = array_column($ev, 'line');
check('opening attack kept', ($ev[0]['type'] ?? '') === 'opening' && str_contains($lines[0] ?? '', 'Kor Gast'), $lines);
check('first knockout (Kor Gast) kept after 400 hits', in_array('Kor Gast was Knocked Out.', $lines, true), $lines);
check('neighbouring town brawl left out', !in_array('Dust Bandit: Knocked out by an Iron Stick from Captain Zepp', $lines, true), $lines);
check('compact (<= 13 lines incl. gap marker)', count($ev) <= 13, count($ev));
check('latest knockout kept', in_array('Hungry Bandit 7 was Knocked Out.', $lines, true), $lines);
$block = stobeBuildFightSoFarPromptBlock(['metadata' => ['fight_so_far' => $ev]]);
check('prompt block names Kor Gast', str_contains($block, '<this_fight_so_far>') && str_contains($block, 'Kor Gast was Knocked Out.'), $block);
check('old fight (> recent window): nothing', stobeSelectFightSoFarEvents($rows, 'Malzin', $now + 20000) === []);
$quiet = [['type' => 'combat', 'data' => 'Malzin: Initiated attack (talking to: Rat)', 'gamets' => 1000]];
check('opening only, nothing notable: nothing', stobeSelectFightSoFarEvents($quiet, 'Malzin', 1100) === []);
$gapRows = [
    ['type' => 'knockout', 'data' => 'New Guy was Knocked Out.', 'gamets' => 50000],
    ['type' => 'combat', 'data' => 'Malzin: Initiated attack (talking to: New Guy)', 'gamets' => 49900],
    ['type' => 'knockout', 'data' => 'Old Guy was Knocked Out.', 'gamets' => 30000],
    ['type' => 'combat', 'data' => 'Malzin: Initiated attack (talking to: Old Guy)', 'gamets' => 29900],
];
$gl = array_column(stobeSelectFightSoFarEvents($gapRows, 'Malzin', 50100), 'line');
check('an earlier, separate fight is not this fight', in_array('New Guy was Knocked Out.', $gl, true) && !in_array('Old Guy was Knocked Out.', $gl, true), $gl);
// Item 91b (A13 v3): Malzin is knocked out for ~3500 game s mid-fight (absent from 'people'); the fight goes
// on around her. The opening and Kor Gast's knockout she saw must survive; what happened while she was out
// must not become a line; a town brawl in the same rows stays out.
$pp = static fn(array $names): string => json_encode(array_map(fn($n) => $n . '|hand_1', $names));
$v3 = [];
$v3[] = ['type' => 'combat', 'data' => 'Malzin: Initiated attack (talking to: Kor Gast)', 'gamets' => 516100, 'people' => $pp(['Malzin', 'Shay', 'Kor Gast'])];
$v3[] = ['type' => 'knockout', 'data' => 'Kor Gast was Knocked Out.', 'gamets' => 516271, 'people' => $pp(['Kor Gast', 'Malzin', 'Shay'])];
$v3[] = ['type' => 'combat', 'data' => 'Kolven [Hungry Bandit]: Initiated attack (talking to: Malzin)', 'gamets' => 516862, 'people' => $pp(['Kolven [Hungry Bandit]', 'Malzin', 'Shay'])];
$v3[] = ['type' => 'combat', 'data' => 'Kolven [Hungry Bandit]: Initiated attack (talking to: Shay)', 'gamets' => 516900, 'people' => $pp(['Kolven [Hungry Bandit]', 'Malzin', 'Shay'])];
$v3[] = ['type' => 'combat', 'data' => 'Shay: Initiated attack (talking to: Marr [Hungry Bandit])', 'gamets' => 516950, 'people' => $pp(['Shay', 'Marr [Hungry Bandit]', 'Malzin'])];
$v3[] = ['type' => 'knockout', 'data' => 'Malzin: Knocked out by an Iron Stick from Kolven [Hungry Bandit]', 'gamets' => 517156, 'people' => $pp(['Malzin', 'Shay'])];
for ($g = 517300; $g <= 520700; $g += 400) { // she is out: rows without her
    $v3[] = ['type' => 'combat', 'data' => 'Marr [Hungry Bandit]: Initiated attack (talking to: Shay)', 'gamets' => $g, 'people' => $pp(['Marr [Hungry Bandit]', 'Shay'])];
}
$v3[] = ['type' => 'knockout', 'data' => 'Marr [Hungry Bandit] was Knocked Out.', 'gamets' => 518144, 'people' => $pp(['Marr [Hungry Bandit]', 'Shay'])];
$v3[] = ['type' => 'knockout', 'data' => 'Dust Bandit: Knocked out by an Iron Stick from Captain Ophir', 'gamets' => 520491, 'people' => $pp(['Dust Bandit', 'Captain Ophir', 'Malzin (unconscious)'])];
$v3[] = ['type' => 'combat', 'data' => 'Captain Ophir: Initiated attack (talking to: Dust Bandit)', 'gamets' => 520400, 'people' => $pp(['Captain Ophir', 'Dust Bandit'])];
$v3[] = ['type' => 'combat', 'data' => 'Threkk [Hungry Bandit]: Initiated attack (talking to: Malzin)', 'gamets' => 520803, 'people' => $pp(['Threkk [Hungry Bandit]', 'Malzin', 'Shay'])];
$v3[] = ['type' => 'knockout', 'data' => 'Threkk [Hungry Bandit] was Knocked Out.', 'gamets' => 522129, 'people' => $pp(['Threkk [Hungry Bandit]', 'Malzin', 'Shay'])];
usort($v3, fn($a, $b) => $b['gamets'] <=> $a['gamets']);
$v3l = array_column(stobeSelectFightSoFarEvents($v3, 'Malzin', 522435), 'line');
check('91b: opening + Kor Gast KO survive her own knockout gap', in_array('Malzin: Initiated attack (talking to: Kor Gast)', $v3l, true) && in_array('Kor Gast was Knocked Out.', $v3l, true), $v3l);
check('91b: her own knockout is kept', in_array('Malzin: Knocked out by an Iron Stick from Kolven [Hungry Bandit]', $v3l, true), $v3l);
check('91b: unseen while she was out (Marr KO) left out', !in_array('Marr [Hungry Bandit] was Knocked Out.', $v3l, true), $v3l);
check('91b: town brawl left out', !in_array('Dust Bandit: Knocked out by an Iron Stick from Captain Ophir', $v3l, true), $v3l);
check('91b: latest knockout kept', in_array('Threkk [Hungry Bandit] was Knocked Out.', $v3l, true), $v3l);
check('91b: query has no audience filter', str_contains(file_get_contents(__DIR__ . '/../lib/chat_helper_functions.php'), 'SELECT type, data, gamets, people FROM eventlog'));
$chatSrc = file_get_contents(__DIR__ . '/../processor/chat.php');
$helperSrc = file_get_contents(__DIR__ . '/../lib/chat_helper_functions.php');
check('chat.php attaches the fight', str_contains($chatSrc, 'stobeAttachFightSoFarEvents('));
check('system prompt includes the block', str_contains($helperSrc, '$fightSoFarBlock = stobeBuildFightSoFarPromptBlock($npcData)'));
echo "pass=$pass fail=$fail\n";
exit($fail > 0 ? 1 : 0);
