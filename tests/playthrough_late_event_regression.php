<?php
// Late (lagged) reports must not be mistaken for a save load. STOBE_DB_NAME=stobe_test php tests/playthrough_late_event_regression.php
require __DIR__ . '/../lib/bootstrap.php';
if (strval(getenv('STOBE_DB_NAME')) !== 'stobe_test') { fwrite(STDERR, "needs stobe_test\n"); exit(2); }
$fail = 0;
$prev = getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '0');
setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '283580', true);
$r = stobeHandlePotentialGametsRollback(281807, 'player_base_state');
if (!empty($r['triggered']) || ($r['reason'] ?? '') !== 'late_event_not_rollback') { $fail++; echo "FAIL late player_base_state treated as rollback: " . json_encode($r) . "\n"; }
$r = stobeHandlePotentialGametsRollback(283100, 'npc_snapshot');
if (!empty($r['triggered'])) { $fail++; echo "FAIL late npc_snapshot treated as rollback\n"; }
if (intval(getConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '0')) !== 283580) { $fail++; echo "FAIL last seen changed by late event\n"; }
$r = stobeHandlePotentialGametsRollback(283580 - 90000, 'player_base_state');
if (($r['reason'] ?? '') === 'late_event_not_rollback') { $fail++; echo "FAIL huge jump back ignored\n"; }
setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', $prev, true);
echo $fail === 0 ? "All late-event rollback checks passed.\n" : "$fail failed\n";
exit($fail > 0 ? 1 : 0);
