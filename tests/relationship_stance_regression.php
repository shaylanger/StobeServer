<?php
// Relationship stance (Shay, 2026-10-02): the relationship shapes how she talks to the speaker.
require __DIR__ . '/../lib/bootstrap.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$hate = stobeRelationshipStanceText('Shay', -80, 'enemy', false);
check('hateful: hostile tone and may start a fight', str_contains($hate, 'Hateful') && str_contains($hate, 'Attack'), $hate);
$hateSquad = stobeRelationshipStanceText('Shay', -95, 'nemesis', true);
check('hostile squadmate: no attack, may leave', str_contains($hateSquad, 'you do not attack them') && str_contains($hateSquad, 'nemesis'), $hateSquad);
$cold = stobeRelationshipStanceText('Shay', -40, 'neutral', false);
check('cold: unfriendly, no type line for neutral', str_contains($cold, 'Cold') && !str_contains($cold, '<relationship>'), $cold);
$neutral = stobeRelationshipStanceText('Shay', 0, 'neutral', false);
check('neutral: reserved', str_contains($neutral, 'Neutral') && str_contains($neutral, 'reserved'), $neutral);
$fond = stobeRelationshipStanceText('Shay', 60, 'platonic', true);
check('fond: warm, missed them', str_contains($fond, 'Fond') && str_contains($fond, 'missed them'), $fond);
$love = stobeRelationshipStanceText('Shay', 96, 'romantic', true);
check('bonded + romantic: love', str_contains($love, 'Bonded') && str_contains($love, 'love'), $love);
check('every reply, even a greeting', str_contains($love, 'Hey, how are you?'));
$npc = ['extended_data' => json_encode(['relationships' => ['Shay' => ['aff' => -60, 'type' => 'rival', 'note' => 'stole her bread']]])];
$block = stobeBuildRelationshipStanceBlock('Malzin', $npc, 'shay', false);
check('stored entry found case-insensitively, note included', str_contains($block, 'Resentful') && str_contains($block, 'stole her bread') && str_contains($block, 'rival'), $block);
check('no entry: no block', stobeBuildRelationshipStanceBlock('Malzin', ['extended_data' => '{}'], 'Shay', false) === '');
check('tier edges', stobeRelationshipStanceTier(-91)[0] === 'Hostile' && stobeRelationshipStanceTier(-90)[0] === 'Hateful'
    && stobeRelationshipStanceTier(5)[0] === 'Neutral' && stobeRelationshipStanceTier(6)[0] === 'Acquaintance'
    && stobeRelationshipStanceTier(91)[0] === 'Bonded' && stobeRelationshipStanceTier(90)[0] === 'Devoted');
// R1: types onto the list
check('R1: ally -> platonic, annoyed -> wary, distrust -> suspicious', stobeCanonicalRelationshipType('ally') === 'platonic'
    && stobeCanonicalRelationshipType('Annoyed') === 'wary' && stobeCanonicalRelationshipType('distrust') === 'suspicious');
check('R1: unknown type is empty (keeps the old one)', stobeCanonicalRelationshipType('whatever') === '');
$r1 = stobeApplyRelationshipUpdatesMap(['Shay' => ['aff' => 60, 'type' => 'romantic']], [['target' => 'Shay', 'aff_delta' => 1, 'type' => 'zzz']]);
check('R1: an unknown type does not wipe "romantic"', ($r1['map']['Shay']['type'] ?? '') === 'romantic', $r1['map']);
// R3: no entries for generic names
$GLOBALS['db']->exec("DELETE FROM core_npc_master WHERE name IN ('Rex3 [Rel3 Bandit]')");
$GLOBALS['db']->exec("INSERT INTO core_npc_master (name) VALUES ('Rex3 [Rel3 Bandit]')");
$r3 = stobeApplyRelationshipUpdatesMap([], [['target' => 'Rel3 Bandit', 'aff_delta' => -5], ['target' => 'Rex3 [Rel3 Bandit]', 'aff_delta' => -5]]);
check('R3: "Rel3 Bandit" (generic) skipped, "Rex3 [Rel3 Bandit]" kept', !isset($r3['map']['Rel3 Bandit']) && isset($r3['map']['Rex3 [Rel3 Bandit]']), $r3['map']);
$GLOBALS['db']->exec("DELETE FROM core_npc_master WHERE name IN ('Rex3 [Rel3 Bandit]')");
// R4: a fight counts
$GLOBALS['db']->exec("DELETE FROM core_npc_master WHERE name IN ('Ann4 [Rel4]','Bob4 [Rel4]')");
$GLOBALS['db']->exec("INSERT INTO core_npc_master (name, extended_data) VALUES ('Ann4 [Rel4]', '{}'::jsonb), ('Bob4 [Rel4]', '{}'::jsonb)");
$GLOBALS['db']->exec("DELETE FROM conf_opts WHERE id LIKE 'STOBE_REL_FIGHT_%'");
$r4 = stobeRelationshipOnAttack('Ann4 [Rel4]: Initiated attack (talking to: Bob4 [Rel4])', 1000000);
$bob = stobeRelationshipEntryFor(getNpcData('Bob4 [Rel4]'), 'Ann4 [Rel4]');
$ann = stobeRelationshipEntryFor(getNpcData('Ann4 [Rel4]'), 'Bob4 [Rel4]');
check('R4: the victim likes the attacker 10 less, the attacker the victim 4 less', intval($bob['aff'] ?? 0) === -10 && intval($ann['aff'] ?? 0) === -4, [$r4, $bob, $ann]);
$again = stobeRelationshipOnAttack('Ann4 [Rel4]: Initiated attack (talking to: Bob4 [Rel4])', 1000100);
check('R4: once per pair per 15 min', $again === []);
check('R4: generic names are skipped', stobeRelationshipOnAttack('Rel4: Initiated attack (talking to: Bob4 [Rel4])', 1000200) === []);
$GLOBALS['db']->exec("DELETE FROM core_npc_master WHERE name IN ('Ann4 [Rel4]','Bob4 [Rel4]')");
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
