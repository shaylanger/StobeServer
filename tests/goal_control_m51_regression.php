<?php
// STOBE items 137/138/139/143 (m51, Shay's 2026-10-07 play). Run ONLY against a test database:
//   STOBE_DB_NAME=stobe_test php tests/goal_control_m51_regression.php
require __DIR__ . '/../lib/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, $detail = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== null ? ' :: ' . json_encode($detail) : '') . "\n"; }
}
if (strval(getenv('STOBE_DB_NAME')) !== 'stobe_test') { echo "refusing: STOBE_DB_NAME must be stobe_test\n"; exit(2); }

$tmp = sys_get_temp_dir() . '/m51goal-' . getmypid();
@mkdir($tmp);
putenv("STOBE_WORK_GOAL_STATUS_FILE=$tmp/work.status");
putenv("STOBE_TASK_GOAL_STATUS_FILE=$tmp/task.status");
putenv("STOBE_WORK_GOAL_CONTROL_FILE=$tmp/work.control");
putenv("STOBE_TASK_GOAL_CONTROL_FILE=$tmp/task.control");
putenv("STOBE_WORK_GOAL_REQUEST_FILE=$tmp/work.request");
putenv("STOBE_PRODUCTION_CATALOG_FILE=$tmp/none.txt");
file_put_contents("$tmp/work.status", '');
file_put_contents("$tmp/task.status", '');

$db = $GLOBALS['db'];
stobeWorkGoalEnsureSchema();
if (function_exists('stobeTaskGoalEnsureSchema')) stobeTaskGoalEnsureSchema();
$actor = 'M51GoalAvarek';
$cleanup = static function () use ($db, $actor, $tmp): void {
    $db->exec("DELETE FROM stobe_work_goal WHERE actor_name=$1", [$actor]);
    $db->exec("DELETE FROM stobe_task_goal_runtime WHERE actor_name=$1", [$actor]);
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
};
register_shutdown_function($cleanup);
$db->exec("DELETE FROM stobe_work_goal WHERE actor_name=$1", [$actor]);
$db->exec("DELETE FROM stobe_task_goal_runtime WHERE actor_name=$1", [$actor]);

function addWorkGoal(string $id, string $actor, string $item, string $status, int $ageS): void {
    $GLOBALS['db']->exec(
        "INSERT INTO stobe_work_goal (goal_id, actor_name, actor_serial, item_name, quantity, destination_name, status, completed, current_step, created_game_ts, created_at, updated_at)
         VALUES ($1,$2,1780395520,$3,1,'',$4,0,'',0,NOW() - ($5 || ' seconds')::interval, NOW() - ($5 || ' seconds')::interval)",
        [$id, $actor, $item, $status, strval($ageS)]
    );
}
function ctl(string $f): string { $p = getenv($f === 'work' ? 'STOBE_WORK_GOAL_CONTROL_FILE' : 'STOBE_TASK_GOAL_CONTROL_FILE'); $s = is_file($p) ? strval(file_get_contents($p)) : ''; @unlink($p); return $s; }
function st(string $id): string { $r = $GLOBALS['db']->fetchOne("SELECT status FROM stobe_work_goal WHERE goal_id=$1", [$id]); return is_array($r) ? strval($r['status']) : ''; }

// ---------------------------------------------------------------- 138: RESUME matches the live ACTIVE goal
addWorkGoal('wg-m51-a', $actor, 'Bread', 'ACTIVE', 600);   // the running one (wg-7d14 in Shay's run)
$r = stobeAnyGoalControl($actor, 'RESUME', 'Bread', 1, 'Campfire');
$c = ctl('work');
check('138: RESUME@Bread@1@Campfire finds the ACTIVE bread goal (was "could not find a matching goal")', !empty($r['ok']) && str_contains($c, "wg-m51-a\tRESUME"), [$r, $c]);
$r = stobeAnyGoalControl($actor, 'RESUME', '', 0, '');
check('138: RESUME with no selector re-kicks the live goal', !empty($r['ok']) && str_contains(ctl('work'), "wg-m51-a\tRESUME"), $r);

// ---------------------------------------------------------------- 138: a second "make 1 bread" resumes, no duplicate goal
$q = stobeQueueWorkGoalRequest($actor, 'bread', 1, 'Campfire');
$cnt = $db->fetchOne("SELECT COUNT(*) AS n FROM stobe_work_goal WHERE actor_name=$1", [$actor]);
check('138: WORK_GOAL for an output with a live goal merges into it (no duplicate that never runs)',
    !empty($q['ok']) && !empty($q['merged']) && strval($q['goal_id'] ?? '') === 'wg-m51-a' && intval($cnt['n'] ?? 0) === 1, [$q, $cnt]);
check('138: the merge writes RESUME for the live goal', str_contains(ctl('work'), "wg-m51-a\tRESUME"));
$q = stobeQueueWorkGoalRequest($actor, 'Bread', 3, '');
check('138: a larger quantity on the merge raises the goal quantity', !empty($q['merged']) && str_contains(ctl('work'), "wg-m51-a\tQUANTITY\t3"), $q);
$db->exec("UPDATE stobe_work_goal SET quantity=1 WHERE goal_id='wg-m51-a'");

// ---------------------------------------------------------------- 139: PAUSE / CANCEL hit every matching goal
addWorkGoal('wg-m51-b', $actor, 'Bread', 'ACTIVE', 300);   // duplicates accepted in Shay's run
addWorkGoal('wg-m51-c', $actor, 'Bread', 'PAUSED', 100);
addWorkGoal('wg-m51-d', $actor, 'Steel Bar', 'ACTIVE', 50);
$r = stobeAnyGoalControl($actor, 'PAUSE', 'Bread', 0, '');
$c = ctl('work');
check('139: PAUSE@Bread pauses every ACTIVE bread goal incl. the running one',
    !empty($r['ok']) && str_contains($c, "wg-m51-a\tPAUSE") && str_contains($c, "wg-m51-b\tPAUSE") && !str_contains($c, 'wg-m51-d'), $c);
$r = stobeAnyGoalControl($actor, 'CANCEL', 'the bread goal', 1, '');
$c = ctl('work');
check('139: CANCEL@Bread cancels all bread goals (wg-7d14 kept hauling in Shay\'s run)',
    !empty($r['ok']) && str_contains($c, "wg-m51-a\tCANCEL") && str_contains($c, "wg-m51-b\tCANCEL") && str_contains($c, "wg-m51-c\tCANCEL")
    && !str_contains($c, 'wg-m51-d') && st('wg-m51-a') === 'CANCELLED', $c);
check('139: the other output is untouched', st('wg-m51-d') === 'ACTIVE');
$r = stobeAnyGoalControl($actor, 'CANCEL', 'all', 0, '');
check('139: CANCEL@all cancels every live goal', !empty($r['ok']) && str_contains(ctl('work'), "wg-m51-d\tCANCEL") && st('wg-m51-d') === 'CANCELLED', $r);
$r = stobeAnyGoalControl($actor, 'CANCEL', 'all', 0, '');
check('139: CANCEL@all with nothing live -> no_matching_goal', empty($r['ok']) && strval($r['error'] ?? '') === 'no_matching_goal', $r);
addWorkGoal('wg-m51-e', $actor, 'Bread', 'ACTIVE', 20);
$r = stobeAnyGoalControl($actor, 'CLEAR', 'all', 0, '');
$c = ctl('work');
check('139: CLEAR cancels every goal and asks Stobe to drop her jobs (CLEARJOBS)',
    !empty($r['ok']) && str_contains($c, "wg-m51-e\tCANCEL") && preg_match("/^\\*\tCLEARJOBS\t\\d+\\^M51GoalAvarek$/m", $c) === 1 && !empty($r['jobs_cleared']), [$r, $c]);
$r = stobeAnyGoalControl($actor, 'CLEAR', '', 0, '');
check('139: CLEAR with no goals still clears her jobs', !empty($r['ok']) && str_contains(ctl('work'), 'CLEARJOBS'), $r);
check('139: selector "all your tasks, goals and jobs" means all', stobeGoalSelectorIsAll('all your tasks, goals and jobs') && stobeGoalSelectorIsAll('everything') && !stobeGoalSelectorIsAll('Bread'));

// ---------------------------------------------------------------- 139: the player's words fix the control action
$live = static fn(string $who): bool => true;
$none = static fn(string $who): bool => false;
$fx = stobeFixGoalControlFromOrder('clear all your tasks and goals please as wlel asa you jobs you ahve', false, ['TASK_CONTROL@PAUSE@Bread@0@'], true, $live, $actor);
check('139: "clear all your tasks and goals ... jobs" + PAUSE -> CLEAR@all', $fx === ['TASK_CONTROL@CLEAR@all@0@'], $fx);
$fx = stobeFixGoalControlFromOrder('cancel all your goals', false, [], true, $live, $actor);
check('139: "cancel all your goals" with no action -> CANCEL@all', $fx === ['TASK_CONTROL@CANCEL@all@0@'], $fx);
$fx = stobeFixGoalControlFromOrder('it still seems like you have a goal to make 1 bread can you remove that / forget it / clear or cancle it i do not want that bread anymore', false, ['TASK_CONTROL@PAUSE@Bread@1@'], true, $live, $actor);
check('139: "forget it / i do not want that bread anymore" turns PAUSE into CANCEL', $fx === ['TASK_CONTROL@CANCEL@Bread@1@'], $fx);
$fx = stobeFixGoalControlFromOrder('stop your current tasks please', false, ['TASK_CONTROL@PAUSE@Bread@0@'], true, $live, $actor);
check('139: "stop your current tasks" stays a PAUSE', $fx === ['TASK_CONTROL@PAUSE@Bread@0@'], $fx);
$fx = stobeFixGoalControlFromOrder("don't clear all your jobs", false, [], true, $live, $actor);
check('139: a negated clear does nothing', $fx === [], $fx);
$fx = stobeFixGoalControlFromOrder('clear all your tasks', false, [], false, $live, $actor);
check('139: not a squad member -> untouched', $fx === [], $fx);

// ---------------------------------------------------------------- 138: "resume" / "get back to work" without an action
$fx = stobeFixGoalControlFromOrder('so do it then, get back to work', false, [], true, $live, $actor);
check('138: "get back to work" with a live goal and no action -> RESUME', $fx === ['TASK_CONTROL@RESUME@@0@'], $fx);
$fx = stobeFixGoalControlFromOrder('resume your task please', false, ['TASK_CONTROL@RESUME@Bread@1@Campfire'], true, $live, $actor);
check('138: an existing RESUME is kept as is', $fx === ['TASK_CONTROL@RESUME@Bread@1@Campfire'], $fx);
$fx = stobeFixGoalControlFromOrder('get back to work', false, [], true, $none, $actor);
check('138: no live goal -> nothing added', $fx === [], $fx);

// ---------------------------------------------------------------- 137: production chain + polite order
$inferred = stobeInferWorkGoalFromOrder('Hey Avarek can you make me 1 bread?', false, [],
    "Bread. Let me check what the campfire's got to work with - last I recall the grain was thin.", true);
check('137: "Hey Avarek can you make me 1 bread?" -> WORK_GOAL@Bread@1 (she sent none at 19:55)', $inferred === 'WORK_GOAL@Bread@1', $inferred);
check('137: "why are you not makeing the bread i asked for?" infers nothing',
    stobeInferWorkGoalFromOrder('why are you not makeing the bread i asked for?', false, [], '', true) === '');
check('137: "did you make 2 bread?" infers nothing', stobeInferWorkGoalFromOrder('did you make 2 bread?', false, [], '', true) === '');
check('137: "could you please bake 2 bread for us?" -> WORK_GOAL@Bread@2',
    stobeInferWorkGoalFromOrder('could you please bake 2 bread for us?', false, [], '', true) === 'WORK_GOAL@Bread@2');
check('137: item 90 still holds (a real refusal stays)', stobeInferWorkGoalFromOrder('Malzin, make 2 bread.', false, [],
    "Bread's a no-go, Shay. I won't.", true) === '');
check('137: plain order unchanged', stobeInferWorkGoalFromOrder('Malzin, make 2 bread.', false, [], '', true) === 'WORK_GOAL@Bread@2');

$cat = "$tmp/catalog.txt";
file_put_contents($cat, "# stobe_production_catalog v1\n"
    . "P\tBread Oven\tBread\tStrawflour|Water\n"
    . "P\tGrain Silo\tStrawflour\tWheatstraw\n"
    . "P\tWheat Farm L\tWheatstraw\tWater\n"
    . "P\tWheat Farm S\tWheatstraw\tWater\n"
    . "P\tAuto Water Pump\tWater\t\n"
    . "C\tCampfire\tDried Meat\n"
    . "C\tElectrical Workbench\tElectrical Components\n");
$block = stobeBuildProductionChainBlock('Hey Avarek can you make me 1 bread?', true, $cat);
check('137: chain block names Bread Oven <- Strawflour + Water', str_contains($block, 'output="Bread" made_at="Bread Oven" inputs="Strawflour, Water"'), $block);
check('137: chain block names Grain Silo <- Wheatstraw and the farms', str_contains($block, 'made_at="Grain Silo" inputs="Wheatstraw"') && str_contains($block, 'Wheat Farm L or Wheat Farm S'), $block);
check('137: chain block reaches the water source', str_contains($block, 'output="Water" made_at="Auto Water Pump"'), $block);
check('137: chain block never names the Campfire for bread', !str_contains($block, 'Campfire'), $block);
check('137: chain block tells her to emit WorkGoal and leave target blank', str_contains($block, 'emit WorkGoal with item "Bread" and leave target blank'), $block);
check('137: no block for small talk', stobeBuildProductionChainBlock('tell me about yourself', true, $cat) === '');
check('137: no block for a non-squad NPC', stobeBuildProductionChainBlock('make me 1 bread', false, $cat) === '');
$fb = stobeBuildProductionChainBlock('make me 2 breads', true, "$tmp/missing.txt");
check('137: without the game catalog the vanilla bread chain is used', str_contains($fb, 'made_at="Bread Oven"') && str_contains($fb, 'made_at="Grain Silo"'), $fb);
check('137: a Campfire target is no destination (stays here)', stobeGoalDestinationFallback('Campfire') === 'workstation');

// ---------------------------------------------------------------- 143: stale request rule
$rules = implode(' ', stobeEndedGoalRules());
check('143: rule keeps old orders out of unrelated talk', str_contains($rules, 'never tack an old request onto an unrelated answer'), $rules);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
