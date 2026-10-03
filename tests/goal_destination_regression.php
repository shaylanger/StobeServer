<?php
// Items 66/68: goal destinations without a stored base. Run ONLY against a test database:
//   STOBE_DB_NAME=stobe_test php tests/goal_destination_regression.php
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
$oldPlayer = $db->fetchOne("SELECT value FROM general_settings WHERE id='PLAYER_NAME'");
register_shutdown_function(static function () use ($db, $oldPlayer): void {
    $db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
    if (is_array($oldPlayer)) $db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', $1)", [strval($oldPlayer['value'])]);
    $db->exec("DELETE FROM eventlog WHERE data='GdTest area'");
    $db->exec("DELETE FROM player_base_locations WHERE base_id='gdtest-fortress'");
    $db->exec("DELETE FROM player_bases WHERE base_id='gdtest-fortress'");
});
$db->exec("DELETE FROM general_settings WHERE id='PLAYER_NAME'");
$db->exec("INSERT INTO general_settings (id, value) VALUES ('PLAYER_NAME', 'GdTestPlayer')");
$db->exec("DELETE FROM player_base_locations WHERE LOWER(base_name) IN ('home','gdtest fortress')");
$GLOBALS['CACHE_PEOPLE'] = '["GdTestPlayer|hand_11111","GdTestMate|hand_22222"]';
$here = static fn($r): bool => is_array($r) && strval($r['name'] ?? 'x') === '' && array_key_exists('x', $r) && $r['x'] === null;

// 66: "Home" with no stored base (the run m1 case) and other base words
check('item 66: "Home" without a stored base -> here', $here(stobeWorkGoalResolveDestination('Home')), stobeWorkGoalResolveDestination('Home'));
check('item 66: "our base" -> here', $here(stobeWorkGoalResolveDestination('our base')));
check('item 66: "the outpost" -> here', $here(stobeWorkGoalResolveDestination('the outpost')));
check('item 66: an unknown faraway town still fails', stobeWorkGoalResolveDestination('Gdtest Nowhere City') === false);
// 66: a stored base still wins
$db->exec("INSERT INTO player_bases (base_id, name) VALUES ('gdtest-fortress', 'GdTest Fortress') ON CONFLICT DO NOTHING");
$db->exec("INSERT INTO player_base_locations (base_id, base_name, x, y, z) VALUES ('gdtest-fortress', 'GdTest Fortress', 10, 20, 30)");
$f = stobeWorkGoalResolveDestination('GdTest Fortress');
check('item 66: a stored base resolves to its position', is_array($f) && floatval($f['x']) === 10.0, $f);
$db->exec("DELETE FROM player_base_locations WHERE base_name='GdTest Fortress'");
$db->exec("DELETE FROM player_bases WHERE base_id='gdtest-fortress'");
// 66: the area the game last reported
$db->exec("INSERT INTO eventlog (type, ts, gamets, data, sess, localts, people, location) VALUES ('info',$1,1000,'GdTest area','pending',$1,'','Gdtestshire, Border Zone')", [time()]);
check('item 66: the current area -> here', $here(stobeWorkGoalResolveDestination('Gdtestshire')));
$db->exec("DELETE FROM eventlog WHERE data='GdTest area'");

// 68: a person as destination (the run m1 TASK_GOAL@FETCH@...@Shay case)
check('item 68: the player name -> here (bring it back)', $here(stobeWorkGoalResolveDestination('GdTestPlayer')));
check('item 68: "me" -> here', $here(stobeWorkGoalResolveDestination('me')));
check('item 68: a live participant -> here', $here(stobeWorkGoalResolveDestination('GdTestMate')));
check('item 68: fallback reason is person', stobeGoalDestinationFallback('GdTestPlayer') === 'person');
// 68 part 2: the run m1 swap TASK_GOAL@FETCH@Shay@mead@1@General Camp Storage Chest@0
check('item 68: FETCH target=player, destination=chest -> swapped',
    stobeTaskGoalNormalizeTargetDestination('FETCH', 'GdTestPlayer', 'General Camp Storage Chest') === ['General Camp Storage Chest', 'GdTestPlayer']);
check('item 68: the right order stays', stobeTaskGoalNormalizeTargetDestination('FETCH', 'General Camp Storage Chest', 'GdTestPlayer') === ['General Camp Storage Chest', 'GdTestPlayer']);
check('item 68: other kinds untouched', stobeTaskGoalNormalizeTargetDestination('GUARD', 'GdTestPlayer', 'Storage Chest') === ['GdTestPlayer', 'Storage Chest']);
check('item 68: a container as destination is never a base lookup -> here', $here(stobeWorkGoalResolveDestination('General Camp Storage Chest')));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
