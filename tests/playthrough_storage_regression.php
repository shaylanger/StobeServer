<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';
require_once __DIR__ . '/../lib/playthrough_storage.php';
require_once __DIR__ . '/../lib/playthrough_schema.php';

$db = $GLOBALS['db'];

function ptStorageFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function ptStorageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        ptStorageFail($message);
    }
}

function ptStorageConfBackup(string $id): array
{
    $db = $GLOBALS['db'];
    $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1 LIMIT 1', [$id]);
    if (!$row) {
        return ['exists' => false, 'value' => ''];
    }
    return ['exists' => true, 'value' => strval($row['value'] ?? '')];
}

function ptStorageRestoreConf(string $id, array $backup): void
{
    $db = $GLOBALS['db'];
    if (!($backup['exists'] ?? false)) {
        $db->exec('DELETE FROM conf_opts WHERE id = $1', [$id]);
        return;
    }
    $db->exec(
        'INSERT INTO conf_opts (id, value, updated_at)
         VALUES ($1, $2, NOW())
         ON CONFLICT (id) DO UPDATE
         SET value = EXCLUDED.value,
             updated_at = NOW()',
        [$id, strval($backup['value'] ?? '')]
    );
}

function ptStorageCleanupProfiles(string $prefix): void
{
    $profiles = stobePlaythroughListProfiles(5000);
    foreach ($profiles as $profile) {
        $name = strval($profile['name'] ?? '');
        if (str_starts_with($name, $prefix)) {
            // Only deactivate this test's fixture before removing its protected playthrough.
            $GLOBALS['db']->exec('UPDATE stobe_meta.playthrough_profiles SET is_active=false WHERE id=$1 AND name=$2', [intval($profile['id'] ?? 0), $name]);
            stobePlaythroughDeleteProfile(intval($profile['id'] ?? 0));
        }
    }
}

function ptStorageProfileIdSet(array $profiles): array
{
    $ids = [];
    foreach ($profiles as $profile) {
        $ids[intval($profile['id'] ?? 0)] = true;
    }
    return $ids;
}

function ptStorageSchemaExists(string $schemaName): bool
{
    $adminConn = stobePlaythroughConnectAdmin();
    if (!$adminConn) {
        ptStorageFail('playthrough admin connection should succeed for schema inspection');
    }

    try {
        return pts_schema_exists($adminConn, $schemaName);
    } finally {
        @pg_close($adminConn);
    }
}

function ptStorageSchemaContainsValue(string $schemaName, string $tableName, string $columnName, string $value): bool
{
    $adminConn = stobePlaythroughConnectAdmin();
    if (!$adminConn) {
        ptStorageFail('playthrough admin connection should succeed for cloned row inspection');
    }
    try {
        $query = sprintf(
            'SELECT 1 FROM %s.%s WHERE %s = $1 LIMIT 1',
            pg_escape_identifier($adminConn, $schemaName),
            pg_escape_identifier($adminConn, $tableName),
            pg_escape_identifier($adminConn, $columnName)
        );
        $result = @pg_query_params($adminConn, $query, [$value]);
        return $result !== false && pg_fetch_assoc($result) !== false;
    } finally {
        @pg_close($adminConn);
    }
}

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));
$prefix = 'UT_PLAYTHROUGH_STORAGE_' . $seed;
$baseName = $prefix . '_Profile';
$trackedConfKeys = ['PLAYER_SQUADS', 'SQUAD_ALPHA', 'SQUAD_BETA'];
$confBackup = [];
foreach ($trackedConfKeys as $trackedKey) {
    $confBackup[$trackedKey] = ptStorageConfBackup($trackedKey);
}

$createdProfileIds = [];
$createdSchemas = [];
$autonomyDecisionId = 'ut-playthrough-' . $seed;

try {
    ptStorageCleanupProfiles($prefix);

    setConfOpt('PLAYER_SQUADS', '["SQUAD_ALPHA","SQUAD_BETA"]');
    setConfOpt('SQUAD_ALPHA', '["Ruka|sid:123","Beep",""]');
    setConfOpt('SQUAD_BETA', '["Agnu|sid:999","Ruka"]');

    $members = stobePlaythroughCollectCurrentPlayerFactionMembers();
    ptStorageAssert($members === ['Agnu', 'Beep', 'Ruka'], 'player faction member collector should dedupe, normalize, and sort squad members');

    ptStorageAssert(stobePlaythroughEnsureMetaSchemaOnDemand(), 'playthrough meta schema should be creatable on demand');
    $adminConn = stobePlaythroughConnectAdmin();
    ptStorageAssert($adminConn !== false, 'playthrough admin connection should succeed');
    $nestedConn = stobePlaythroughConnectAdmin();
    ptStorageAssert($nestedConn !== false && $nestedConn !== $adminConn, 'nested autosave must own a separate admin connection');
    pg_close($nestedConn);
    ptStorageAssert(pg_query($adminConn, 'SELECT 1') !== false, 'closing an autosave connection must leave the restore connection usable');
    @pg_close($adminConn);

    stobeAutonomyEnsureSchema();
    $db->exec(
        "INSERT INTO autonomy_decision (
            decision_id, control_revision, npc_id, npc_storage_id, command,
            status, dispatch_deadline_at, action_deadline_at, terminal_at
         ) VALUES ($1, 999, 999, 'hand_999', 'IDLE', 'COMPLETED', NOW(), NOW(), NOW())",
        [$autonomyDecisionId]
    );
    $db->exec(
        "INSERT INTO autonomy_pilot_step (
            control_revision, command, status, decision_id
         ) VALUES (999, 'IDLE', 'COMPLETED', $1)",
        [$autonomyDecisionId]
    );

    $first = stobePlaythroughCreate($baseName, 'first playthrough', [
        'mark_active' => true,
        'player_name' => 'UT Player',
        'game' => 'Kenshi',
    ]);
    ptStorageAssert(boolval($first['success'] ?? false), 'first playthrough should be created');
    ptStorageAssert(intval($first['id'] ?? 0) > 0, 'first playthrough should return a profile id');
    ptStorageAssert(trim(strval($first['schema_name'] ?? '')) !== '', 'first playthrough should return a schema name');
    $createdProfileIds[] = intval($first['id'] ?? 0);
    $createdSchemas[] = strval($first['schema_name'] ?? '');

    ptStorageAssert(ptStorageSchemaExists(strval($first['schema_name'] ?? '')), 'first playthrough schema should exist');
    $playthroughViews = $db->fetchOne(
        'SELECT count(*) AS count FROM pg_views WHERE schemaname = $1',
        [strval($first['schema_name'])]
    );
    ptStorageAssert(intval($playthroughViews['count'] ?? -1) === 0, 'playthrough views must not retain references to live tables');
    ptStorageAssert(
        ptStorageSchemaContainsValue(strval($first['schema_name'] ?? ''), 'autonomy_decision', 'decision_id', $autonomyDecisionId),
        'playthrough should clone autonomy decision rows'
    );
    ptStorageAssert(
        ptStorageSchemaContainsValue(strval($first['schema_name'] ?? ''), 'autonomy_pilot_step', 'decision_id', $autonomyDecisionId),
        'playthrough should clone autonomy pilot rows'
    );

    $firstProfile = stobePlaythroughGetProfileById(intval($first['id'] ?? 0));
    ptStorageAssert(is_array($firstProfile), 'first playthrough profile should be queryable');
    ptStorageAssert(stobePlaythroughToBool($firstProfile['is_active'] ?? false), 'first playthrough should be marked active');
    ptStorageAssert(strval($firstProfile['player_name'] ?? '') === 'UT Player', 'first playthrough should persist explicit player name');
    $storedMembers = json_decode(strval($firstProfile['player_faction_members'] ?? '[]'), true);
    ptStorageAssert(is_array($storedMembers), 'first playthrough should store player faction member JSON');
    ptStorageAssert($storedMembers === ['Agnu', 'Beep', 'Ruka'], 'first playthrough should persist normalized player faction members');
    ptStorageAssert(stobePlaythroughCurrentActiveProfileName() === strval($first['name'] ?? ''), 'active profile helper should resolve first playthrough name');

    $second = stobePlaythroughCreate($baseName, 'second playthrough', [
        'mark_active' => false,
        'player_name' => 'UT Player',
        'game' => 'Kenshi',
    ]);
    ptStorageAssert(boolval($second['success'] ?? false), 'second playthrough should be created');
    ptStorageAssert(intval($second['id'] ?? 0) > 0, 'second playthrough should return a profile id');
    ptStorageAssert(strval($second['name'] ?? '') !== strval($first['name'] ?? ''), 'duplicate playthrough names should be made unique');
    $createdProfileIds[] = intval($second['id'] ?? 0);
    $createdSchemas[] = strval($second['schema_name'] ?? '');

    $profileIds = ptStorageProfileIdSet(stobePlaythroughListProfiles(100));
    ptStorageAssert(isset($profileIds[intval($first['id'] ?? 0)]), 'profile listing should include first playthrough');
    ptStorageAssert(isset($profileIds[intval($second['id'] ?? 0)]), 'profile listing should include second playthrough');
    ptStorageAssert(stobePlaythroughCurrentActiveProfileName() === strval($first['name'] ?? ''), 'creating inactive playthrough should not replace active profile');

    $deleteSecond = stobePlaythroughDeleteProfile(intval($second['id'] ?? 0));
    ptStorageAssert(boolval($deleteSecond['success'] ?? false), 'second playthrough should be deletable');
    ptStorageAssert(stobePlaythroughGetProfileById(intval($second['id'] ?? 0)) === false, 'deleted second playthrough should no longer resolve');
    ptStorageAssert(!ptStorageSchemaExists(strval($second['schema_name'] ?? '')), 'deleted second playthrough schema should be dropped');

    $deleteFirst = stobePlaythroughDeleteProfile(intval($first['id'] ?? 0));
    ptStorageAssert(!boolval($deleteFirst['success'] ?? false), 'loaded playthrough must not be deletable');
    ptStorageAssert(is_array(stobePlaythroughGetProfileById(intval($first['id'] ?? 0))), 'protected playthrough metadata should remain');
    ptStorageAssert(ptStorageSchemaExists(strval($first['schema_name'] ?? '')), 'protected playthrough schema should remain');
    ptStorageAssert(stobePlaythroughCurrentActiveProfileName() === strval($first['name'] ?? ''), 'rejected deletion must preserve the loaded marker');

    echo 'All playthrough storage regression tests passed.' . PHP_EOL;
} finally {
    $db->exec('DELETE FROM autonomy_pilot_step WHERE decision_id = $1', [$autonomyDecisionId]);
    $db->exec('DELETE FROM autonomy_decision WHERE decision_id = $1', [$autonomyDecisionId]);
    ptStorageCleanupProfiles($prefix);
    foreach ($trackedConfKeys as $trackedKey) {
        ptStorageRestoreConf($trackedKey, $confBackup[$trackedKey]);
    }
}

exit(0);
