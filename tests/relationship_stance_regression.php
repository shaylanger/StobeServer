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
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
