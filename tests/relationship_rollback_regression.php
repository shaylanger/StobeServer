<?php
// Relationships follow the save (Shay, 2026-10-02): loading an older save restores
// the relationship maps of that time.
require __DIR__ . '/../lib/bootstrap.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
$db = $GLOBALS['db'];
$db->exec("DELETE FROM general_settings WHERE id='NEVER_CLEAR_RELATIONSHIP_DATA'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('NEVER_CLEAR_RELATIONSHIP_DATA', 'false')");
foreach (['RelRb Malzin', 'RelRb Old', 'RelRb New'] as $n) {
    $db->exec("DELETE FROM core_npc_master_history WHERE name=$1", [$n]);
    $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]);
}
$db->exec("INSERT INTO core_npc (name, extended_data) VALUES ('RelRb Malzin', '{}'::jsonb), ('RelRb Old', '{}'::jsonb)");
$rel = static fn(string $n) => (getNpcData($n) ?: [])['extended_data'] ?? [];
$aff = static function (string $n, string $t): ?int {
    $e = stobeRelationshipEntryFor(getNpcData($n), $t);
    return $e === null ? null : intval($e['aff'] ?? 0);
};
// t=1000: Bonded with Shay
setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '1000');
stobePersistNpcRelationshipMap('RelRb Malzin', ['Shay' => ['aff' => 96, 'type' => 'platonic']], getNpcData('RelRb Malzin'));
// t=2000: Shay attacked her, she hates Shay now
setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '2000');
stobePersistNpcRelationshipMap('RelRb Malzin', ['Shay' => ['aff' => -80, 'type' => 'enemy']], getNpcData('RelRb Malzin'));
check('before the load she hates Shay', $aff('RelRb Malzin', 'Shay') === -80);
// an NPC whose map was never snapshotted (old data): a plain snapshot without relationships at t=500
$db->exec("UPDATE core_npc SET extended_data = '{\"relationships\": {\"Shay\": {\"aff\": 40, \"type\": \"platonic\"}}}'::jsonb, gamets_last_updated = 2000 WHERE name='RelRb Old'");
$old = getNpcData('RelRb Old');
$db->exec("INSERT INTO core_npc_master_history (npc_id, name, extended_data, gamets_last_updated, snapshot_reason) VALUES ($1, 'RelRb Old', '{}'::jsonb, 500, 'snapshot')", [intval($old['id'] ?? 0)]);
// an NPC first met after the save
$db->exec("INSERT INTO core_npc (name, extended_data, gamets_last_updated) VALUES ('RelRb New', '{\"relationships\": {\"Shay\": {\"aff\": 10}}}'::jsonb, 1800)");
// load a save from t=1500
$r = stobePlaythroughRestoreRelationshipStates(1500);
check('load a save from before the fight: Bonded again', $aff('RelRb Malzin', 'Shay') === 96, [$r, $rel('RelRb Malzin')]);
check('a snapshot without relationship data does not wipe her map', $aff('RelRb Old', 'Shay') === 40, $rel('RelRb Old'));
check('an NPC first met after the save is cleared', $aff('RelRb New', 'Shay') === null, $rel('RelRb New'));
// the switch still works
$db->exec("UPDATE general_settings SET value='true' WHERE id='NEVER_CLEAR_RELATIONSHIP_DATA'");
setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '2500');
stobePersistNpcRelationshipMap('RelRb Malzin', ['Shay' => ['aff' => -80, 'type' => 'enemy']], getNpcData('RelRb Malzin'));
stobePlaythroughRestoreRelationshipStates(1500);
check('NEVER_CLEAR_RELATIONSHIP_DATA=true keeps today\'s map', $aff('RelRb Malzin', 'Shay') === -80);
foreach (['RelRb Malzin', 'RelRb Old', 'RelRb New'] as $n) {
    $db->exec("DELETE FROM core_npc_master_history WHERE name=$1", [$n]);
    $db->exec("DELETE FROM core_npc WHERE name=$1", [$n]);
}
$db->exec("DELETE FROM general_settings WHERE id='NEVER_CLEAR_RELATIONSHIP_DATA'");
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
