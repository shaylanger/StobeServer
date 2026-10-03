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
// columns both tables share (minus the ones set here)
$colRows = $db->fetchAll(
    "SELECT c.column_name FROM information_schema.columns c
      JOIN information_schema.columns h ON h.table_name = 'core_npc_master_history' AND h.column_name = c.column_name AND h.table_schema = c.table_schema
     WHERE c.table_name = 'core_npc' AND c.table_schema = 'public'
       AND c.column_name NOT IN ('id', 'npc_id', 'history_id', 'snapshot_reason', 'gamets_last_updated', 'created', 'created_at', 'updated_at')
     GROUP BY c.column_name ORDER BY c.column_name"
);
$cols = implode(', ', array_map(static fn($r) => '"' . $r['column_name'] . '"', is_array($colRows) ? $colRows : []));
if ($cols === '') { echo "no shared columns found
"; exit(1); }
$n = 0; $failed = 0;
foreach (is_array($rows) ? $rows : [] as $r) {
    $id = intval($r['id']);
    if (!$apply) { $n++; continue; }
    // Copy the live row straight into history (the normal snapshot path skips
    // identical or recent snapshots, which is exactly what a baseline needs to bypass).
    $ok = $db->exec(
        "INSERT INTO core_npc_master_history (npc_id, {$cols}, snapshot_reason, gamets_last_updated, created)
         SELECT id, {$cols}, 'relationship_baseline', 0, NOW() FROM core_npc WHERE id = $1",
        [$id]
    );
    if ($ok !== false) $n++; else $failed++;
}
echo ($apply ? "baseline stored for $n NPCs" : "$n NPCs would get a baseline") . ($failed ? ", $failed failed" : '') . "\n";
