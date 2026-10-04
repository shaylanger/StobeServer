<?php
// Item 121: ended goals are past history in the prompt; their old blockers must not read as current
// facts (a stale "Grain Silo has no power" made Malzin refuse a feasible "make 2 bread").
// Run ONLY against a test database:  STOBE_DB_NAME=stobe_test php tests/ended_goal_history_regression.php
require __DIR__ . '/../lib/bootstrap.php';

$db = $GLOBALS['db'];
$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
if (strval(getenv('STOBE_DB_NAME')) !== 'stobe_test') { echo "refusing: STOBE_DB_NAME must be stobe_test\n"; exit(2); }

stobeWorkGoalEnsureSchema();
stobeTaskGoalEnsureSchema();
$cleanup = static function () use ($db): void {
    $db->exec("DELETE FROM stobe_work_goal WHERE actor_name LIKE 'R121%'");
    $db->exec("DELETE FROM stobe_task_goal_runtime WHERE actor_name LIKE 'R121%'");
};
$cleanup();
register_shutdown_function($cleanup);
$wg = static function (string $id, string $actor, string $item, string $status, string $step, string $reason, int $ageMin) use ($db): void {
    $db->exec("INSERT INTO stobe_work_goal (goal_id, actor_name, item_name, quantity, destination_name, status, current_step, reason, updated_at)
               VALUES ($1,$2,$3,2,'Home',$4,$5,$6,NOW() - ($7 || ' minutes')::interval)", [$id, $actor, $item, $status, $step, $reason, strval($ageMin)]);
};
// The run m19 prompt: two cancelled Bread goals (one with the silo-power blocker), one done goal.
$wg('r121-a', 'R121Malzin', 'Bread', 'CANCELLED', 'Waiting for power at Grain Silo',
    'cannot make Bread because Grain Silo has no power (check generators, batteries and wiring)', 3);
$wg('r121-b', 'R121Malzin', 'Bread', 'CANCELLED', 'Obtaining Wheatstraw for Strawflour',
    'production stalled while Obtaining Wheatstraw for Strawflour', 20);
$wg('r121-c', 'R121Malzin', 'Building Material', 'COMPLETE', 'Completed 2/2 Building Material', '', 40);
$b = stobeBuildWorkGoalStateBlock('R121Malzin');
check('cancelled goal blocker is not shown', !str_contains($b, 'has no power') && !str_contains($b, 'Waiting for power'), $b);
check('older superseded Bread goal dropped', substr_count($b, 'output="Bread"') === 1, $b);
check('ended goal carries its age', str_contains($b, 'ended_minutes_ago="3"'), $b);
check('rule: never turn down a new order because of an ended goal', str_contains($b, 'Never turn down a new order because of an ended goal'), $b);

// A current (ACTIVE) goal still shows its step and blocker.
$wg('r121-d', 'R121Active', 'Bread', 'ACTIVE', 'Waiting for power at Grain Silo', 'Grain Silo has no power', 1);
$a = stobeBuildWorkGoalStateBlock('R121Active');
check('active goal keeps current_step and blocker', str_contains($a, '<current_step>Waiting for power') && str_contains($a, '<blocker>Grain Silo has no power'), $a);

// BLOCKED: fresh -> past-tense history; old -> nothing.
$wg('r121-e', 'R121Fresh', 'Bread', 'BLOCKED', 'Waiting for power', 'the Grain Silo has no power', 5);
$f = stobeBuildWorkGoalStateBlock('R121Fresh');
check('fresh BLOCKED keeps reason as past history', str_contains($f, '<history>Past, may no longer be true: 5 min ago') && !str_contains($f, '<blocker>'), $f);
$wg('r121-f', 'R121Old', 'Bread', 'BLOCKED', 'Waiting for power', 'the Grain Silo has no power', 30);
$o = stobeBuildWorkGoalStateBlock('R121Old');
check('old BLOCKED reason dropped', !str_contains($o, 'Grain Silo'), $o);

// task_goals: same treatment.
$db->exec("INSERT INTO stobe_task_goal_runtime (goal_id, actor_name, kind, item_name, target_name, quantity, status, current_step, reason, updated_at)
           VALUES ('r121-t1','R121Task','FETCH','Wheatstraw','Storage Chest',20,'CANCELLED','Walking to Storage Chest','Storage Chest has no Wheatstraw', NOW() - interval '4 minutes')");
$t = stobeBuildTaskGoalStateBlock('R121Task');
check('task_goals: cancelled reason not shown, age shown', !str_contains($t, 'has no Wheatstraw') && str_contains($t, 'ended_minutes_ago="4"'), $t);

echo "SUMMARY pass=$pass fail=$fail\n";
echo 'RESULT item121 ' . ($fail === 0 ? 'PASS' : 'FAIL') . " ended-goal history pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
