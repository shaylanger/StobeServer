<?php
/**
 * Relationship baseline (command line only), StobeServer tools/relationship_baseline.php.
 *
 *   php tools/relationship_baseline.php            dry run: how many NPCs would get a baseline
 *   php tools/relationship_baseline.php apply      store one 'relationship_baseline' history
 *                                                  snapshot per NPC with a relationship map,
 *                                                  at game time 0
 *
 * Why: relationships now follow the loaded save (playthrough rollback), but before
 * 2026-10-02 relationship changes were rarely snapshotted. A baseline at game time 0
 * makes a load of any older save fall back to today's map instead of to nothing.
 * Run once after enabling the rollback (NEVER_CLEAR_RELATIONSHIP_DATA=false).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';
$db = $GLOBALS['db'];
$apply = ($argv[1] ?? '') === 'apply';
$rows = $db->fetchAll(
    "SELECT id FROM core_npc
      WHERE jsonb_typeof(COALESCE(extended_data, '{}'::jsonb) -> 'relationships') = 'object'
        AND extended_data -> 'relationships' <> '{}'::jsonb
        AND NOT EXISTS (SELECT 1 FROM core_npc_master_history h
                         WHERE h.npc_id = core_npc.id AND h.snapshot_reason = 'relationship_baseline')"
);
$n = 0; $failed = 0;
foreach (is_array($rows) ? $rows : [] as $r) {
    $id = intval($r['id']);
    if (!$apply) { $n++; continue; }
    $row = stobeFetchNpcRowForHistoryById($id);
    if (!$row) { $failed++; continue; }
    $row['gamets_last_updated'] = 0;
    if (stobeInsertNpcHistorySnapshotFromRow($row, 'relationship_baseline')) {
        $db->exec("UPDATE core_npc_master_history SET gamets_last_updated = 0
                    WHERE history_id = (SELECT MAX(history_id) FROM core_npc_master_history WHERE npc_id = $1 AND snapshot_reason = 'relationship_baseline')", [$id]);
        $n++;
    } else {
        $failed++;
    }
}
echo ($apply ? "baseline stored for $n NPCs" : "$n NPCs would get a baseline") . ($failed ? ", $failed failed" : '') . "\n";
