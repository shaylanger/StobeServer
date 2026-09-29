<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';
require_once __DIR__ . '/../lib/playthrough_storage.php';
require_once __DIR__ . '/../lib/playthrough_autosave.php';
require_once __DIR__ . '/../lib/playthrough_rollback.php';
require_once __DIR__ . '/../lib/player_base_functions.php';

$db = $GLOBALS['db'];

function ptFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function ptAssert(bool $condition, string $message): void
{
    if (!$condition) {
        ptFail($message);
    }
}

function ptConfOptGetRaw(string $id): array
{
    global $db;
    $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1 LIMIT 1', [$id]);
    if (!$row) {
        return ['exists' => false, 'value' => ''];
    }
    return ['exists' => true, 'value' => strval($row['value'] ?? '')];
}

function ptConfOptRestore(string $id, array $backup): void
{
    global $db;
    if (!($backup['exists'] ?? false)) {
        $db->exec('DELETE FROM conf_opts WHERE id = $1', [$id]);
        return;
    }
    $db->exec(
        'INSERT INTO conf_opts (id, value, updated_at)
         VALUES ($1, $2, NOW())
         ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()',
        [$id, strval($backup['value'] ?? '')]
    );
}

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));
$prefix = 'UT_ROLLBACK_' . $seed;

$trackedConfKeys = [
    'PLAYTHROUGH_LAST_SEEN_GAMETS',
    'PLAYTHROUGH_LAST_SEEN_TS',
    'PLAYTHROUGH_LAST_ROLLBACK_GAMETS',
    'PLAYTHROUGH_LAST_ROLLBACK_TS',
    'PLAYTHROUGH_LAST_ROLLBACK_FROM_GAMETS',
    'PLAYTHROUGH_LAST_ROLLBACK_DELTA_GAMETS',
    'DYNAMIC_PROFILE_LOAD_GRACE_UNTIL_TS',
];

$confBackup = [];
foreach ($trackedConfKeys as $key) {
    $confBackup[$key] = ptConfOptGetRaw($key);
}

$dragonBreakEnabledBackup = $GLOBALS['DRAGON_BREAK_AUTO_PLAYTHROUGH'] ?? null;
$dragonBreakMinDaysBackup = $GLOBALS['DRAGON_BREAK_MIN_DAYS'] ?? null;
$preserveRelationshipsBackup = getSetting('NEVER_CLEAR_RELATIONSHIP_DATA', 'false');
setSetting('NEVER_CLEAR_RELATIONSHIP_DATA', 'false');

$createdPlaythroughIds = [];
$zoneKeepName = 'UT_ROLLBACK_ZONE_KEEP_' . $seed;
$zoneDropName = 'UT_ROLLBACK_ZONE_DROP_' . $seed;
$baseKeepId = 'ut-rollback-base-keep-' . $seed;
$baseDropId = 'ut-rollback-base-drop-' . $seed;
$baseLegacyId = 'ut-rollback-base-legacy-' . $seed;
$lockedRelationshipNpc = $prefix . '_LOCKED_REL';
$futureRelationshipNpc = $prefix . '_FUTURE_REL';
$individualMemoryNpc = $prefix . '_INDIVIDUAL_MEMORY';
$presenceBackup = $db->fetchOne(
    "SELECT scope_key, session_id, observer_serial, observer_name, inside, base_id, game_ts, observed_at
     FROM player_base_presence
     WHERE scope_key = 'selected_player'
     LIMIT 1"
);

try {
    ptAssert(
        !stobePlaythroughRollbackEventIsAuthoritative('world_state'),
        'Delayed world-state scans must not trigger rollback handling'
    );
    ptAssert(
        stobePlaythroughRollbackEventIsAuthoritative('npc_snapshot'),
        'NPC snapshots should remain authoritative for rollback handling'
    );

    $richNpc = [
        'race' => 'Greenlander',
        'faction' => 'Mercenary Guild',
        'gender' => 'male',
        'equipment' => '[]',
        'inventory' => '[]',
        'skills' => '{}',
    ];
    $sparseNpc = [
        'race' => 'Unknown',
        'faction' => '',
        'gender' => '',
        'equipment' => '[]',
        'inventory' => [],
        'skills' => '{}',
    ];
    $richHistory = $richNpc;
    $richHistory['race'] = 'Shek';

    ptAssert(
        stobePlaythroughShouldSkipSparseNpcRestore($richNpc, $sparseNpc),
        'Rollback must not replace a populated NPC with a sparse history row'
    );
    ptAssert(
        !stobePlaythroughShouldSkipSparseNpcRestore($richNpc, $richHistory),
        'Rollback should allow restoration between populated NPC states'
    );
    ptAssert(
        !stobePlaythroughShouldSkipSparseNpcRestore($sparseNpc, $sparseNpc),
        'Sparse NPCs should not be treated as populated rollback downgrades'
    );

    // Location zone pruning behavior on fallback rollback:
    // - zones first discovered after cutoff should be removed
    // - previously discovered zones should be kept and rewound to cutoff
    $db->exec('DELETE FROM location_zones WHERE zone_name IN ($1, $2)', [$zoneKeepName, $zoneDropName]);
    $db->exec(
        'INSERT INTO location_zones (zone_name, city_name, first_game_ts, last_game_ts, first_seen_ts, last_seen_ts, metadata, updated_at)
         VALUES ($1, $2, $3, $4, $5, $5, $6::jsonb, NOW())',
        [$zoneKeepName, 'UT City', 100, 400, time(), '{}']
    );
    $db->exec(
        'INSERT INTO location_zones (zone_name, city_name, first_game_ts, last_game_ts, first_seen_ts, last_seen_ts, metadata, updated_at)
         VALUES ($1, $2, $3, $4, $5, $5, $6::jsonb, NOW())',
        [$zoneDropName, 'UT City', 320, 350, time(), '{}']
    );

    $basePayload = [
        'session_id' => 'ut-rollback-session-' . $seed,
        'observer_serial' => 42,
        'observer_name' => 'Rollback Tester',
        'game_ts' => 100,
        'player_base' => [
            'inside' => true,
            'base_id' => $baseKeepId,
            'name' => 'Rollback Keep Base',
            'members_inside' => 2,
            'has_gates' => true,
            'gates_closed' => true,
            'details' => [
                'available' => true,
                'construction' => [
                    'total' => 1,
                    'average_progress' => 20,
                    'groups' => [
                        ['name' => 'Wall', 'count' => 1, 'average_progress' => 20],
                    ],
                ],
                'supplies' => ['food' => 10],
            ],
        ],
    ];
    stobeStorePlayerBaseState($basePayload);

    $basePayload['game_ts'] = 500;
    $basePayload['player_base']['members_inside'] = 5;
    $basePayload['player_base']['details']['construction']['total'] = 3;
    $basePayload['player_base']['details']['construction']['average_progress'] = 80;
    $basePayload['player_base']['details']['construction']['groups'] = [
        ['name' => 'Wall', 'count' => 3, 'average_progress' => 80],
    ];
    $basePayload['player_base']['details']['supplies']['food'] = 50;
    stobeStorePlayerBaseState($basePayload);

    $basePayload['game_ts'] = 350;
    $basePayload['player_base']['base_id'] = $baseDropId;
    $basePayload['player_base']['name'] = 'Rollback Future Base';
    stobeStorePlayerBaseState($basePayload);

    $basePayload['game_ts'] = 500;
    $basePayload['player_base']['base_id'] = $baseLegacyId;
    $basePayload['player_base']['name'] = 'Rollback Legacy Base';
    stobeStorePlayerBaseState($basePayload);
    $db->exec('UPDATE player_bases SET first_game_ts = 0 WHERE base_id = $1', [$baseLegacyId]);
    $db->exec('UPDATE player_base_history SET game_ts = 0 WHERE base_id = $1', [$baseLegacyId]);

    $zonePrune = stobePlaythroughPruneFutureTimeline(250);
    ptAssert(intval($zonePrune['location_zones_deleted'] ?? 0) >= 1, 'Rollback prune should delete post-cutoff discovered zones');
    ptAssert(intval($zonePrune['location_zones_rewound'] ?? 0) >= 1, 'Rollback prune should rewind forward location game timestamps');
    ptAssert(intval($zonePrune['player_base_history_deleted'] ?? 0) >= 2, 'Rollback prune should delete future base snapshots');
    ptAssert(intval($zonePrune['player_bases_deleted'] ?? 0) >= 1, 'Rollback prune should delete bases first discovered after cutoff');
    ptAssert(intval($zonePrune['player_bases_restored'] ?? 0) >= 1, 'Rollback prune should restore known bases to their latest pre-cutoff state');
    ptAssert(intval($zonePrune['player_base_presence_cleared'] ?? 0) >= 1, 'Rollback prune should clear stale current-base presence');

    $keepRow = $db->fetchOne('SELECT first_game_ts, last_game_ts FROM location_zones WHERE zone_name = $1 LIMIT 1', [$zoneKeepName]);
    $dropRow = $db->fetchOne('SELECT zone_name FROM location_zones WHERE zone_name = $1 LIMIT 1', [$zoneDropName]);
    ptAssert(is_array($keepRow), 'Rollback prune should keep zones discovered before cutoff');
    ptAssert(intval($keepRow['first_game_ts'] ?? 0) === 100, 'Kept zone should preserve first discovery game timestamp');
    ptAssert(intval($keepRow['last_game_ts'] ?? 0) === 250, 'Kept zone should rewind last_game_ts to cutoff');
    ptAssert(!is_array($dropRow), 'Rollback prune should remove zones discovered only after cutoff');

    $keptBase = $db->fetchOne(
        'SELECT members_inside, details, game_ts, first_game_ts, last_game_ts
         FROM player_bases
         WHERE base_id = $1
         LIMIT 1',
        [$baseKeepId]
    );
    $droppedBase = $db->fetchOne('SELECT base_id FROM player_bases WHERE base_id = $1 LIMIT 1', [$baseDropId]);
    $legacyBase = $db->fetchOne(
        'SELECT first_game_ts, game_ts, last_game_ts
         FROM player_bases
         WHERE base_id = $1
         LIMIT 1',
        [$baseLegacyId]
    );
    $presence = $db->fetchOne(
        "SELECT inside, base_id, game_ts
         FROM player_base_presence
         WHERE scope_key = 'selected_player'
         LIMIT 1"
    );
    ptAssert(is_array($keptBase), 'Rollback prune should retain bases discovered before cutoff');
    $restoredDetails = stobeNormalizePlayerBaseDetails($keptBase['details'] ?? []);
    ptAssert(intval($keptBase['members_inside'] ?? 0) === 2, 'Retained base should restore pre-cutoff members');
    ptAssert(intval($keptBase['game_ts'] ?? 0) === 100, 'Retained base should restore its pre-cutoff game timestamp');
    ptAssert(intval($keptBase['first_game_ts'] ?? 0) === 100, 'Retained base should preserve first discovery timestamp');
    ptAssert(intval($keptBase['last_game_ts'] ?? 0) === 100, 'Retained base should rewind last game timestamp');
    ptAssert(intval($restoredDetails['supplies']['food'] ?? 0) === 10, 'Retained base should restore pre-cutoff supplies');
    ptAssert(intval($restoredDetails['construction']['total'] ?? 0) === 1, 'Retained base should restore pre-cutoff construction');
    ptAssert(!is_array($droppedBase), 'Rollback prune should remove future-only bases');
    ptAssert(is_array($legacyBase), 'Rollback prune should retain bases migrated with an unknown first discovery time');
    ptAssert(intval($legacyBase['first_game_ts'] ?? -1) === 0, 'Migrated base should preserve its legacy discovery sentinel');
    ptAssert(intval($legacyBase['game_ts'] ?? -1) === 0, 'Migrated base should restore its seeded baseline snapshot');
    ptAssert(intval($legacyBase['last_game_ts'] ?? -1) === 0, 'Migrated base should rewind its last game timestamp to baseline');
    ptAssert(is_array($presence), 'Rollback prune should retain the selected-player presence row');
    ptAssert(!coerceBoolean($presence['inside'] ?? true), 'Rollback prune should mark current presence as outside');
    ptAssert(($presence['base_id'] ?? null) === null, 'Rollback prune should detach current presence from the future base');
    ptAssert(intval($presence['game_ts'] ?? 0) === 250, 'Rollback prune should rewind presence to cutoff');

    // Relationship state follows the game timeline even when the profile itself is locked.
    storeNpcProfile($lockedRelationshipNpc, [
        'race' => 'Greenlander',
        'faction' => 'Player Faction',
        'gender' => 'female',
        'personality' => 'Original locked profile text.',
        'extended_data' => [
            'relationships' => ['Beep' => ['aff' => 15, 'type' => 'friend']],
        ],
    ]);
    $lockedRow = $db->fetchOne('SELECT * FROM core_npc WHERE name = $1 LIMIT 1', [$lockedRelationshipNpc]);
    $lockedNpcId = intval($lockedRow['id'] ?? 0);
    ptAssert($lockedNpcId > 0, 'Locked relationship test NPC should exist');
    $db->exec('DELETE FROM core_npc_master_history WHERE npc_id = $1', [$lockedNpcId]);
    $db->exec(
        "UPDATE core_npc
         SET lock_profile = TRUE,
             gamets_last_updated = 1000,
             extended_data = $1::jsonb,
             updated_at = NOW()
         WHERE id = $2",
        [normalizeJsonString([
            'relationships' => ['Beep' => ['aff' => 15, 'type' => 'friend']],
            'environment' => ['town_name' => 'The Hub'],
        ]), $lockedNpcId]
    );
    $lockedHistoryRow = stobeFetchNpcRowForHistoryById($lockedNpcId);
    ptAssert(
        is_array($lockedHistoryRow) && stobeInsertNpcHistorySnapshotFromRow($lockedHistoryRow, 'relationship'),
        'Locked NPC relationship snapshot should be stored'
    );

    // A later generic snapshot at the same game time must not outrank the relationship snapshot.
    $db->exec(
        "UPDATE core_npc
         SET extended_data = $1::jsonb,
             updated_at = NOW()
         WHERE id = $2",
        [normalizeJsonString([
            'relationships' => ['Beep' => ['aff' => 30, 'type' => 'trusted']],
            'environment' => ['town_name' => 'Squin'],
        ]), $lockedNpcId]
    );
    $db->exec(
        "UPDATE core_npc
         SET personality = 'Locked profile text must remain current.',
             gamets_last_updated = 2000,
             extended_data = $1::jsonb,
             updated_at = NOW()
         WHERE id = $2",
        [normalizeJsonString([
            'relationships' => ['Beep' => ['aff' => 80, 'type' => 'devoted']],
            'environment' => ['town_name' => 'Stack'],
        ]), $lockedNpcId]
    );

    storeNpcProfile($futureRelationshipNpc, [
        'race' => 'Shek',
        'faction' => 'Bandits',
        'gender' => 'male',
    ]);
    $futureRow = $db->fetchOne('SELECT * FROM core_npc WHERE name = $1 LIMIT 1', [$futureRelationshipNpc]);
    $futureNpcId = intval($futureRow['id'] ?? 0);
    ptAssert($futureNpcId > 0, 'Future-only relationship test NPC should exist');
    $db->exec('DELETE FROM core_npc_master_history WHERE npc_id = $1', [$futureNpcId]);
    $db->exec(
        "UPDATE core_npc
         SET lock_profile = TRUE,
             gamets_last_updated = 2000,
             extended_data = $1::jsonb,
             updated_at = NOW()
         WHERE id = $2",
        [normalizeJsonString([
            'relationships' => ['Locked NPC' => ['aff' => -60, 'type' => 'enemy']],
            'environment' => ['town_name' => 'Stack'],
        ]), $futureNpcId]
    );

    setSetting('NEVER_CLEAR_RELATIONSHIP_DATA', 'true');
    $preserved = stobePlaythroughRestoreRelationshipStates(1500);
    ptAssert($preserved['restored'] === 0 && $preserved['cleared'] === 0, 'Enabled preservation should skip affinity rewind and clearing');
    $lockedPreserved = $db->fetchOne('SELECT extended_data FROM core_npc WHERE id = $1', [$lockedNpcId]);
    $lockedPreservedData = normalizeCoreNpcExtendedData($lockedPreserved['extended_data']);
    ptAssert(intval($lockedPreservedData['relationships']['Beep']['aff'] ?? 0) === 80, 'Enabled preservation should retain current locked affinity');
    $futurePreserved = $db->fetchOne('SELECT extended_data FROM core_npc WHERE id = $1', [$futureNpcId]);
    ptAssert(array_key_exists('relationships', normalizeCoreNpcExtendedData($futurePreserved['extended_data'])), 'Enabled preservation should keep future-only affinities');
    setSetting('NEVER_CLEAR_RELATIONSHIP_DATA', 'false');
    $relationshipRestore = stobePlaythroughRestoreRelationshipStates(1500);
    ptAssert(intval($relationshipRestore['errors'] ?? 0) === 0, 'Relationship-only rollback should succeed');
    ptAssert(intval($relationshipRestore['restored'] ?? 0) >= 1, 'Locked NPC relationship state should be restored');
    ptAssert(intval($relationshipRestore['cleared'] ?? 0) >= 1, 'Future-only relationship state should be cleared');

    $lockedAfterRestore = $db->fetchOne(
        'SELECT lock_profile, personality, extended_data FROM core_npc WHERE id = $1 LIMIT 1',
        [$lockedNpcId]
    );
    $lockedExtended = normalizeCoreNpcExtendedData($lockedAfterRestore['extended_data'] ?? '{}');
    ptAssert(coerceBoolean($lockedAfterRestore['lock_profile'] ?? false), 'Relationship rollback should keep the profile locked');
    ptAssert(
        strval($lockedAfterRestore['personality'] ?? '') === 'Locked profile text must remain current.',
        'Relationship rollback should not replace locked profile fields'
    );
    ptAssert(
        intval($lockedExtended['relationships']['Beep']['aff'] ?? 0) === 15,
        'Locked NPC affinity should rewind to the relationship snapshot'
    );
    ptAssert(
        strval($lockedExtended['environment']['town_name'] ?? '') === 'Stack',
        'Relationship rollback should preserve unrelated extended data'
    );

    $futureAfterRestore = $db->fetchOne('SELECT extended_data FROM core_npc WHERE id = $1 LIMIT 1', [$futureNpcId]);
    $futureExtended = normalizeCoreNpcExtendedData($futureAfterRestore['extended_data'] ?? '{}');
    ptAssert(!array_key_exists('relationships', $futureExtended), 'Future-only affinities should be removed');
    ptAssert(
        strval($futureExtended['environment']['town_name'] ?? '') === 'Stack',
        'Clearing future affinities should preserve unrelated extended data'
    );

    storeNpcProfile($individualMemoryNpc, [
        'race' => 'Greenlander',
        'faction' => 'Tech Hunters',
        'gender' => 'female',
        'extended_data' => ['environment' => ['town_name' => 'The Hub']],
    ]);
    $individualMemoryRow = $db->fetchOne('SELECT * FROM core_npc WHERE name = $1 LIMIT 1', [$individualMemoryNpc]);
    $individualMemoryNpcId = intval($individualMemoryRow['id'] ?? 0);
    ptAssert($individualMemoryNpcId > 0, 'Individual Memory rollback test NPC should exist');
    $db->exec('DELETE FROM core_npc_master_history WHERE npc_id = $1', [$individualMemoryNpcId]);
    $db->exec(
        "UPDATE core_npc
         SET gamets_last_updated = 1000,
             extended_data = $1::jsonb,
             updated_at = NOW()
         WHERE id = $2",
        [normalizeJsonString(['environment' => ['town_name' => 'The Hub']]), $individualMemoryNpcId]
    );
    $individualMemoryBaseline = stobeFetchNpcRowForHistoryById($individualMemoryNpcId);
    ptAssert(is_array($individualMemoryBaseline), 'Individual Memory rollback baseline row should resolve');
    ptAssert(
        stobeInsertNpcHistorySnapshotFromRow($individualMemoryBaseline, 'rollback_test_baseline'),
        'Individual Memory rollback baseline should be stored'
    );
    $individualMemoryHistory = $db->fetchOne(
        'SELECT * FROM core_npc_master_history WHERE npc_id = $1 ORDER BY history_id DESC LIMIT 1',
        [$individualMemoryNpcId]
    );
    $db->exec(
        "UPDATE core_npc
         SET gamets_last_updated = 2000,
             extended_data = $1::jsonb,
             updated_at = NOW()
         WHERE id = $2",
        [normalizeJsonString([
            'environment' => ['town_name' => 'Stack'],
            'individual_memory_enabled' => 1,
        ]), $individualMemoryNpcId]
    );
    ptAssert(
        is_array($individualMemoryHistory)
            && stobePlaythroughRestoreNpcFromHistory($individualMemoryNpcId, $individualMemoryHistory),
        'Individual Memory rollback restore should succeed'
    );
    $individualMemoryAfterRestore = $db->fetchOne(
        'SELECT extended_data FROM core_npc WHERE id = $1 LIMIT 1',
        [$individualMemoryNpcId]
    );
    $individualMemoryExtended = normalizeCoreNpcExtendedData($individualMemoryAfterRestore['extended_data'] ?? '{}');
    ptAssert(
        strval($individualMemoryExtended['environment']['town_name'] ?? '') === 'The Hub',
        'Rollback should still restore timeline-owned extended data'
    );
    ptAssert(
        intval($individualMemoryExtended['individual_memory_enabled'] ?? 0) === 1,
        'Rollback should preserve the current Individual Memory Bank setting'
    );

    // Full profile restoration must retain relationships but still rewind unrelated fields.
    setSetting('NEVER_CLEAR_RELATIONSHIP_DATA', 'true');
    $db->exec('UPDATE core_npc SET extended_data = $1::jsonb, gamets_last_updated = 2000 WHERE id = $2', [
        normalizeJsonString([
            'environment' => ['town_name' => 'Stack'],
            'individual_memory_enabled' => 1,
            'relationships' => ['Beep' => ['aff' => 85, 'custom_info' => 'Trusted friend']],
            'relationships_model' => 'test-model',
        ]), $individualMemoryNpcId,
    ]);
    $db->exec('UPDATE core_npc SET lock_profile = FALSE, gamets_last_updated = 2000 WHERE id = $1', [$futureNpcId]);
    $db->exec('DELETE FROM core_npc_master_history WHERE npc_id = $1', [$futureNpcId]);
    $restorePreserved = stobePlaythroughRestoreUnlockedNpcs(1500);
    ptAssert($restorePreserved['errors'] === 0, 'Preserving full rollback should succeed');
    ptAssert((bool)$db->fetchOne('SELECT id FROM core_npc WHERE id = $1', [$futureNpcId]), 'Enabled preservation must not delete future-only NPCs');
    $fullPreserved = $db->fetchOne('SELECT extended_data FROM core_npc WHERE id = $1', [$individualMemoryNpcId]);
    $fullPreservedData = normalizeCoreNpcExtendedData($fullPreserved['extended_data']);
    ptAssert(intval($fullPreservedData['relationships']['Beep']['aff'] ?? 0) === 85, 'Full rollback should keep current affinity');
    ptAssert(($fullPreservedData['relationships']['Beep']['custom_info'] ?? '') === 'Trusted friend', 'Full rollback should keep relationship notes');
    ptAssert(($fullPreservedData['relationships_model'] ?? '') === 'test-model', 'Full rollback should keep relationship metadata');
    ptAssert(($fullPreservedData['environment']['town_name'] ?? '') === 'The Hub', 'Preservation must not keep unrelated future context');
    ptAssert(intval($fullPreservedData['individual_memory_enabled'] ?? 0) === 1, 'Preservation must retain the Individual Memory setting');
    setSetting('NEVER_CLEAR_RELATIONSHIP_DATA', 'false');
    stobePlaythroughRestoreUnlockedNpcs(1500);
    ptAssert(!$db->fetchOne('SELECT id FROM core_npc WHERE id = $1', [$futureNpcId]), 'Disabled preservation must retain future-only NPC deletion');

    // Pure threshold helper checks.
    ptAssert(stobeDragonBreakDaysRollback(200000, 200000) === 0, 'Equal gamets should be zero rollback days');
    ptAssert(stobeDragonBreakDaysRollback(200000, 199999) === 0, 'Sub-day rollback should be zero days');
    ptAssert(stobeDragonBreakDaysRollback(200000, 113600) === 1, '86400 rollback should map to 1 day');

    // Force deterministic dragonbreak config.
$GLOBALS['DRAGON_BREAK_AUTO_PLAYTHROUGH'] = true;
    $GLOBALS['DRAGON_BREAK_MIN_DAYS'] = 1;

    $futureBase = 900000000 + random_int(10000, 99999);

    // Case 1: rollback detected but below 1-day threshold => no Dragon Break playthrough.
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($futureBase + 1000));
    setConfOpt('PLAYTHROUGH_LAST_SEEN_TS', strval(time()));
    $resultSmall = stobeHandlePotentialGametsRollback($futureBase + 500, 'test_small_rewind');
    ptAssert(boolval($resultSmall['triggered'] ?? false), 'Small rewind should still trigger rollback handling');
    ptAssert(intval($resultSmall['playthrough_id'] ?? 0) === 0, 'Small rewind should not create a Dragon Break playthrough');

    // Case 2: rollback >= 1 day => playthrough expected.
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($futureBase + 200000));
    setConfOpt('PLAYTHROUGH_LAST_SEEN_TS', strval(time()));
    $resultLarge = stobeHandlePotentialGametsRollback($futureBase + 100000, 'test_large_rewind');
    ptAssert(boolval($resultLarge['triggered'] ?? false), 'Large rewind should trigger rollback handling');
    $playthroughId = intval($resultLarge['playthrough_id'] ?? 0);
    ptAssert($playthroughId > 0, 'Large rewind should create a Dragon Break playthrough');

    $createdPlaythroughIds[] = $playthroughId;
    $profile = stobePlaythroughGetProfileById($playthroughId);
    ptAssert(is_array($profile), 'Dragon Break playthrough profile should exist');
    ptAssert(intval($profile['rollback_delta_days'] ?? 0) >= 1, 'Playthrough should record rollback_delta_days >= 1');

    echo 'All playthrough rollback regression tests passed.' . PHP_EOL;
} finally {
    setSetting('NEVER_CLEAR_RELATIONSHIP_DATA', $preserveRelationshipsBackup);
    // Cleanup created playthroughs.
    foreach ($createdPlaythroughIds as $playthroughId) {
        if ($playthroughId > 0) {
            stobePlaythroughDeleteProfile($playthroughId);
        }
    }

    // Cleanup any residual test playthroughs by name prefix (safety net).
    $rows = stobePlaythroughListProfiles(2000);
    foreach ($rows as $row) {
        $name = strval($row['name'] ?? '');
        if (str_starts_with($name, $prefix)) {
            stobePlaythroughDeleteProfile(intval($row['id'] ?? 0));
        }
    }

    // Restore conf opts.
    foreach ($confBackup as $key => $backup) {
        ptConfOptRestore($key, $backup);
    }

    // Restore globals.
    if ($dragonBreakEnabledBackup === null) {
        unset($GLOBALS['DRAGON_BREAK_AUTO_PLAYTHROUGH']);
    } else {
        $GLOBALS['DRAGON_BREAK_AUTO_PLAYTHROUGH'] = $dragonBreakEnabledBackup;
    }
    if ($dragonBreakMinDaysBackup === null) {
        unset($GLOBALS['DRAGON_BREAK_MIN_DAYS']);
    } else {
        $GLOBALS['DRAGON_BREAK_MIN_DAYS'] = $dragonBreakMinDaysBackup;
    }

    // Cleanup location zone test rows.
    $db->exec('DELETE FROM location_zones WHERE zone_name IN ($1, $2)', [$zoneKeepName, $zoneDropName]);

    $db->exec(
        "DELETE FROM player_base_presence
         WHERE scope_key = 'selected_player'
           AND session_id = $1",
        ['ut-rollback-session-' . $seed]
    );
    $db->exec(
        'DELETE FROM player_bases WHERE base_id IN ($1, $2, $3)',
        [$baseKeepId, $baseDropId, $baseLegacyId]
    );
    $relationshipTestRows = $db->fetchAll(
        'SELECT id FROM core_npc WHERE name IN ($1, $2, $3)',
        [$lockedRelationshipNpc, $futureRelationshipNpc, $individualMemoryNpc]
    );
    foreach ($relationshipTestRows as $relationshipTestRow) {
        $relationshipTestNpcId = intval($relationshipTestRow['id'] ?? 0);
        if ($relationshipTestNpcId <= 0) {
            continue;
        }
        $db->exec('DELETE FROM core_npc_master_history WHERE npc_id = $1', [$relationshipTestNpcId]);
        $db->exec('DELETE FROM core_npc WHERE id = $1', [$relationshipTestNpcId]);
    }
    if (is_array($presenceBackup)) {
        $db->exec(
            "INSERT INTO player_base_presence (
                scope_key, session_id, observer_serial, observer_name,
                inside, base_id, game_ts, observed_at
             ) VALUES (
                $1, $2, $3, $4, $5::boolean, $6, $7, $8::timestamp
             )
             ON CONFLICT (scope_key) DO UPDATE SET
                session_id = EXCLUDED.session_id,
                observer_serial = EXCLUDED.observer_serial,
                observer_name = EXCLUDED.observer_name,
                inside = EXCLUDED.inside,
                base_id = EXCLUDED.base_id,
                game_ts = EXCLUDED.game_ts,
                observed_at = EXCLUDED.observed_at",
            [
                strval($presenceBackup['scope_key'] ?? 'selected_player'),
                strval($presenceBackup['session_id'] ?? ''),
                intval($presenceBackup['observer_serial'] ?? 0),
                strval($presenceBackup['observer_name'] ?? ''),
                coerceBoolean($presenceBackup['inside'] ?? false) ? 'true' : 'false',
                $presenceBackup['base_id'] ?? null,
                intval($presenceBackup['game_ts'] ?? 0),
                strval($presenceBackup['observed_at'] ?? date('Y-m-d H:i:s')),
            ]
        );
    }
}

exit(0);

