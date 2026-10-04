<?php
// C30 (bug 38): nearby-people lines label the player's squad for strangers ("player's squad"), "squadmate" only
// for members. Run: php tests/nearby_squad_label_regression.php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$fail = 0;
function c30check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; }
$GLOBALS['db']->exec('BEGIN');
try {
    setSetting('PROMPT_CONTEXT_OPTIONS', json_encode(stobeGetDefaultPromptContextOptions()));
    $pf = getCurrentPlayerFactionIdentity();
    $pfName = trim(strval($pf['name'] ?? '')) !== '' ? strval($pf['name']) : 'Nameless';
    $pfToken = trim(strval($pf['id'] ?? '')) !== '' ? $pfName . '|' . strval($pf['id']) : $pfName;
    $actor = static fn(string $name, string $gender, string $faction) => [
        'name' => $name, 'race' => 'Greenlander', 'gender' => $gender, 'faction' => $faction,
        'current_action' => 'standing', 'is_animal' => false, 'is_dead' => false,
        'is_knocked_out' => false, 'is_unconscious' => false,
    ];
    $actors = [$actor('UT_C30_SQUAD_A', 'male', $pfToken), $actor('UT_C30_SQUAD_B', 'female', $pfToken),
               $actor('UT_C30_STRANGER', 'male', 'UT_C30_Drifters')];
    $lineOf = static function (string $block, string $name): string {
        foreach (preg_split('/\R/', $block) as $l) if (preg_match('/^\s*(?:#+|-)\s*' . preg_quote($name, '/') . ' \(/', $l)) return $l;
        return '';
    };
    $stranger = stobeBuildNearbyActorsPromptBlock(['name' => 'UT_C30_LISTENER', 'faction' => 'UT_C30_Drifters',
        'extended_data' => ['nearby_actors' => $actors]], 'UT_C30_LISTENER');
    $a = $lineOf($stranger, 'UT_C30_SQUAD_A'); $b = $lineOf($stranger, 'UT_C30_SQUAD_B'); $o = $lineOf($stranger, 'UT_C30_STRANGER');
    c30check('C30 stranger sees squad member A as player\'s squad', $a !== '' && stripos($a, "player's squad") !== false);
    c30check('C30 stranger sees squad member B (no Faction: repeat) as player\'s squad', $b !== '' && stripos($b, "player's squad") !== false);
    c30check('C30 stranger never sees squadmate', stripos($stranger, 'squadmate') === false);
    c30check('C30 non-squad NPC unlabelled', $o !== '' && stripos($o, "player's squad") === false);
    $member = stobeBuildNearbyActorsPromptBlock(['name' => 'UT_C30_MEMBER', 'faction' => $pfToken,
        'extended_data' => ['nearby_actors' => $actors]], 'UT_C30_MEMBER');
    $ma = $lineOf($member, 'UT_C30_SQUAD_A');
    c30check('C30 member sees squad member as squadmate', $ma !== '' && stripos($ma, 'squadmate') !== false && stripos($ma, "player's squad") === false);
} finally {
    $GLOBALS['db']->exec('ROLLBACK');
}
echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
