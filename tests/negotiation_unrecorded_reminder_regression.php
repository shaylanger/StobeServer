<?php
// C26 (bug 30): after "agreed in words but recorded no deal" the next turn with that NPC is a negotiation turn,
// so the one-shot reminder reaches the prompt even when the player's follow-up is no offer.
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$fail = 0;
function c26check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; }
$npc = 'UT_C26_Varn_' . getmypid();
$npcData = ['name' => $npc, 'faction' => 'UT_C26_Drifters'];
$follow = $npc . ', so what do you say?';
$key = 'STOBE_NEG_UNRECORDED_' . strtolower($npc);
try {
    setConfOpt($key, '');
    c26check('C26 plain follow-up is no negotiation without a pending agreement', !stobeDealShouldNegotiate($npc, $npcData, $follow));
    stobeDealRememberUnrecordedAgreement($npc, $npc . ", I'll pay you 50 cats to tell me where the nearest bar is.", "Fine, you've got a deal.");
    c26check('C26 pending unrecorded agreement keeps the follow-up a negotiation turn', stobeDealShouldNegotiate($npc, $npcData, $follow));
    $note = stobeDealTakeUnrecordedAgreement($npc, 'UT_C26_Player');
    c26check('C26 reminder text produced', strpos($note, 'It was not recorded as a deal') !== false);
    c26check('C26 reminder is one-shot', !stobeDealShouldNegotiate($npc, $npcData, $follow));
    setConfOpt($key, json_encode(['offer' => 'x', 'reply' => 'y', 'at' => time() - 600, 'decision' => 'ACCEPT']));
    c26check('C26 stale pending agreement does not force negotiation', !stobeDealShouldNegotiate($npc, $npcData, $follow));
} finally {
    setConfOpt($key, '');
}
echo $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
