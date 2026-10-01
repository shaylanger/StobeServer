<?php
// Unpaid gifts from outsiders need high trust or an agreed deal. STOBE_DB_NAME=stobe_test php tests/negotiation_gift_regression.php
require __DIR__ . '/../lib/bootstrap.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$base = stobeBuildActionConfigForNpc('chat', false);
$base['player_name'] = 'Shay';
$base['allowlist'] = [];
$cfg = fn(array $o) => array_merge($base, $o);
$n = fn(string $tag, array $c) => normalizeActionTagToken($tag, $c);
check('stranger gift of item blocked', $n('GIVE_ITEM@Shay@Oat Straw@1', $cfg(['player_affinity'=>0])) === '');
check('stranger gift of cats blocked', $n('GIVE_CATS@Shay@200', $cfg(['player_affinity'=>10])) === '');
check('friendly (40) still blocked', $n('GIVE_ITEM@Shay@Oat Straw@1', $cfg(['player_affinity'=>40])) === '');
check('fond (60) allowed', $n('GIVE_ITEM@Shay@Oat Straw@1', $cfg(['player_affinity'=>60])) !== '', $n('GIVE_ITEM@Shay@Oat Straw@1', $cfg(['player_affinity'=>60])));
check('agreed deal allowed', $n('GIVE_ITEM@Shay@Oat Straw@1', $cfg(['player_affinity'=>0, 'deal_sanctioned_give'=>true])) !== '');
check('squadmate allowed', $n('GIVE_ITEM@Shay@Oat Straw@1', $cfg(['player_affinity'=>0, 'in_player_faction'=>true])) !== '');
check('gift to another NPC not affected', $n('GIVE_ITEM@Hax@Oat Straw@1', $cfg(['player_affinity'=>0])) !== '');
check('take/other actions unaffected', $n('FACE_TARGET@Shay', $cfg(['player_affinity'=>0])) !== '');
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
