<?php

/**
 * In-game AI NPC browser endpoint.
 *
 * POST JSON:
 *   {"action":"list"}
 *   {"action":"detail","sid":"<storage_id|id:123|name>"}
 *   {"action":"player_view","storage_id":"hand_<serial>","serial":1,"name":"","speaker":"","gamets":0,
 *    "live_activity":"","live_faction":"","trader":false,"key":""}  (NPC info panel: read-only, no LLM call)
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    return;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    return;
}

$action = strtolower(trim(strval($payload['action'] ?? 'list')));
$db = $GLOBALS["db"];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

function stobeAiNpcFieldValue(array $row, string $key, string $fallback = 'Unknown'): string {
    $value = trim(strval($row[$key] ?? ''));
    return $value === '' ? $fallback : $value;
}

function stobeAiNpcSnapshotValue(array $row, string $key): string {
    $value = trim(strval($row[$key] ?? ''));
    if ($value === '') {
        return '(none recorded)';
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        if (count($decoded) === 0) {
            return '(none recorded)';
        }
        return json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
    return $value;
}

function stobeAiNpcBoolValue(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim(strval($value))), ['1', 't', 'true', 'yes', 'on'], true);
}

function stobeAiNpcJsonObject(mixed $value): array {
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode(strval($value), true);
    return is_array($decoded) ? $decoded : [];
}

function stobeAiNpcRelationshipSummary(mixed $rawRelationships): string {
    $relationshipMap = stobeNormalizeRelationshipMap($rawRelationships);
    if (count($relationshipMap) === 0) {
        return 'No relationship data.';
    }

    $entries = [];
    foreach ($relationshipMap as $targetName => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $target = normalizeParticipantNameToken(strval($targetName));
        if ($target === '') {
            continue;
        }
        $aff = intval($entry['aff'] ?? 0);
        $type = stobeNormalizeRelationshipTypeToken(strval($entry['type'] ?? 'neutral'));
        $tier = stobeRelationshipTierLabel($aff);
        $entries[] = [
            'target' => $target,
            'aff' => $aff,
            'type' => $type,
            'tier' => $tier,
        ];
    }

    if (count($entries) === 0) {
        return 'No relationship data.';
    }

    usort($entries, static function (array $a, array $b): int {
        if ($a['aff'] === $b['aff']) {
            return strcasecmp(strval($a['target']), strval($b['target']));
        }
        return intval($b['aff']) <=> intval($a['aff']);
    });

    $lines = [];
    $max = min(count($entries), 12);
    for ($i = 0; $i < $max; $i++) {
        $entry = $entries[$i];
        $lines[] = '- ' . strval($entry['target']) . ': '
            . strval($entry['tier']) . ' (' . strval($entry['type'])
            . ', aff ' . strval(intval($entry['aff'])) . ')';
    }
    return implode("\n", $lines);
}

function stobeAiNpcResolveProfile(array $row): array {
    $db = $GLOBALS["db"];
    $profileId = intval($row['profile_id'] ?? 0);
    $attached = $profileId > 0;

    if (!$attached) {
        $profileId = getDefaultNpcProfileId();
    }

    if ($profileId <= 0) {
        return [
            'label' => $attached ? ('Profile #' . strval(intval($row['profile_id'] ?? 0))) : 'Unassigned',
            'metadata' => [],
        ];
    }

    $profileRow = $db->fetchOne(
        "SELECT label, metadata
         FROM core_profiles
         WHERE id = $1
         LIMIT 1",
        [$profileId]
    );
    $label = trim(strval($profileRow['label'] ?? ''));
    if ($label === '') {
        $label = $attached ? ('Profile #' . strval($profileId)) : 'Default Profile';
    } elseif (!$attached) {
        $label .= ' (inherited default)';
    }

    return [
        'label' => $label,
        'metadata' => stobeAiNpcJsonObject($profileRow['metadata'] ?? []),
    ];
}

function stobeAiNpcSettingsSummary(array $row, array $profileMetadata): string {
    $metadata = stobeAiNpcJsonObject($row['metadata'] ?? []);
    $extendedData = stobeAiNpcJsonObject($row['extended_data'] ?? []);

    $resolveInheritedToggle = static function (
        string $key,
        bool $profileDefault
    ) use ($metadata): string {
        if (array_key_exists($key, $metadata)
            && $metadata[$key] !== null
            && trim(strval($metadata[$key])) !== '') {
            return (stobeAiNpcBoolValue($metadata[$key]) ? 'On' : 'Off') . ' (NPC override)';
        }
        return ($profileDefault ? 'On' : 'Off') . ' (profile)';
    };

    $dynamicProfile = $resolveInheritedToggle(
        'DYNAMIC_PROFILE_ENABLED',
        stobeAiNpcBoolValue($profileMetadata['DYNAMIC_PROFILE_ENABLED'] ?? false)
    );
    $middleTermMemory = $resolveInheritedToggle(
        'MIDDLE_TERM_MEMORY_ENABLED',
        stobeAiNpcBoolValue($profileMetadata['MIDDLE_TERM_MEMORY_ENABLED'] ?? false)
    );
    if (array_key_exists('middle_term_enabled', $extendedData)
        && $extendedData['middle_term_enabled'] !== null
        && trim(strval($extendedData['middle_term_enabled'])) !== '') {
        $middleTermMemory = (stobeAiNpcBoolValue($extendedData['middle_term_enabled']) ? 'On' : 'Off')
            . ' (NPC override)';
    }
    $autoDiary = $resolveInheritedToggle(
        'AUTO_DIARY_ENABLED',
        stobeAiNpcBoolValue($profileMetadata['AUTO_DIARY_ENABLED'] ?? false)
    );

    return implode("\n", [
        'Favorite: ' . (stobeAiNpcBoolValue($row['npc_favorite'] ?? false) ? 'Yes' : 'No'),
        'Profile Locked: ' . (stobeAiNpcBoolValue($row['lock_profile'] ?? false) ? 'Yes' : 'No'),
        'Dynamic Profile: ' . $dynamicProfile,
        'Middle Term Memory: ' . $middleTermMemory,
        'Individual Memory Bank: '
            . (stobeAiNpcBoolValue($extendedData['individual_memory_enabled'] ?? false) ? 'On' : 'Off'),
        'Auto Diary: ' . $autoDiary,
    ]);
}

function stobeAiNpcRecentEvents(array $row): string {
    $metadata = stobeAiNpcJsonObject($row['metadata'] ?? []);
    $storageId = normalizeStorageIdToken(strval($metadata['storage_id'] ?? ($metadata['refid'] ?? '')));
    $name = trim(strval($row['name'] ?? ''));
    $filter = $storageId !== '' ? $storageId : $name;
    $events = $filter !== '' ? DataEventLog(12, $filter) : [];
    if (count($events) === 0 && $storageId !== '' && $name !== '') {
        $events = DataEventLog(12, $name);
    }

    $lines = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $line = trim(stobeFormatEventHistoryLine($event, true));
        if ($line === '') {
            continue;
        }
        if (strlen($line) > 700) {
            $line = substr($line, 0, 697) . '...';
        }
        $lines[] = $line;
        $location = trim(strval($event['location'] ?? ''));
        if ($location !== '') {
            $lines[] = 'Location: ' . $location;
        }
        $lines[] = '';
    }

    return count($lines) > 0
        ? rtrim(implode("\n", $lines))
        : 'No recent events found for this NPC.';
}

function stobeAiNpcResolveVoiceId(array $row): string {
    $voiceId = trim(strval($row['voiceid'] ?? ''));
    if ($voiceId !== '') {
        return $voiceId;
    }

    $name = trim(strval($row['name'] ?? ''));
    if ($name === '') {
        return 'Unassigned';
    }

    $resolved = stobeSelectVoiceIdForNpc(
        $name,
        trim(strval($row['race'] ?? '')),
        trim(strval($row['gender'] ?? '')),
        trim(strval($row['faction'] ?? '')),
        $row
    );
    if ($resolved !== '') {
        return $resolved . ' (auto)';
    }
    return 'Unassigned';
}

if ($action === 'list') {
    $rows = $db->fetchAll(
        "SELECT
            id,
            name,
            COALESCE(metadata->>'storage_id', '') AS storage_id,
            gamets_last_updated,
            updated_at
         FROM core_npc
         WHERE COALESCE(BTRIM(name), '') <> ''
         ORDER BY LOWER(name) ASC, gamets_last_updated DESC, updated_at DESC, id DESC"
    );

    $nameCounts = [];
    foreach ($rows as $row) {
        $name = trim(strval($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        $nameCounts[$key] = intval($nameCounts[$key] ?? 0) + 1;
    }

    $entries = [];
    foreach ($rows as $row) {
        $name = trim(strval($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $storageId = normalizeStorageIdToken(strval($row['storage_id'] ?? ''));
        $sid = $storageId !== '' ? $storageId : ('id:' . strval(intval($row['id'] ?? 0)));
        if ($sid === 'id:0') {
            continue;
        }

        $display = $name;
        $nameKey = strtolower($name);
        if (intval($nameCounts[$nameKey] ?? 0) > 1) {
            $suffix = $storageId !== '' ? $storageId : ('id:' . strval(intval($row['id'] ?? 0)));
            if (strlen($suffix) > 24) {
                $suffix = substr($suffix, 0, 24) . '...';
            }
            $display .= ' [' . $suffix . ']';
        }

        $entries[] = $display . "|" . $sid;
    }

    echo json_encode(
        [
            'ok' => true,
            'count' => count($entries),
            'names' => $entries,
        ],
        $jsonFlags
    );
    return;
}

if ($action === 'detail') {
    $sid = trim(strval($payload['sid'] ?? ''));
    if ($sid === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing sid']);
        return;
    }

    $row = false;
    if (strtolower(substr($sid, 0, 3)) === 'id:') {
        $id = intval(substr($sid, 3));
        if ($id > 0) {
            $row = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE id = $1
                 LIMIT 1",
                [$id]
            );
        }
    } else {
        $normalizedSid = normalizeStorageIdToken($sid);
        if ($normalizedSid !== '') {
            $row = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE COALESCE(metadata->>'storage_id', '') = $1
                 ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
                 LIMIT 1",
                [$normalizedSid]
            );
        }
        if (!$row) {
            $row = $db->fetchOne(
                "SELECT *
                 FROM core_npc
                 WHERE LOWER(name) = LOWER($1)
                    OR LOWER(COALESCE(original_name, '')) = LOWER($1)
                 ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
                 LIMIT 1",
                [$sid]
            );
        }
    }

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'NPC not found']);
        return;
    }

    $relationshipsRaw = trim(strval($row['relationships'] ?? ''));
    $relationshipMap = stobeNormalizeRelationshipMap($relationshipsRaw);
    if (count($relationshipMap) > 0) {
        $relationships = stobeAiNpcRelationshipSummary($relationshipMap);
    } else {
        $relationships = $relationshipsRaw;
    }

    $name = stobeAiNpcFieldValue($row, 'name');
    $originalName = trim(strval($row['original_name'] ?? ''));
    if ($originalName === '') {
        $originalName = '(none)';
    }
    $profile = stobeAiNpcResolveProfile($row);
    $profileLabel = strval($profile['label'] ?? 'Unassigned');
    $profileMetadata = is_array($profile['metadata'] ?? null) ? $profile['metadata'] : [];
    $voiceIdLabel = stobeAiNpcResolveVoiceId($row);

    $lines = [];
    $lines[] = "IDENTITY";
    $lines[] = "Name: " . $name;
    $lines[] = "Original Name: " . $originalName;
    $lines[] = "Current Profile: " . $profileLabel;
    $lines[] = "Voice ID: " . $voiceIdLabel;
    $lines[] = "Race: " . stobeAiNpcFieldValue($row, 'race');
    $lines[] = "Gender: " . stobeAiNpcFieldValue($row, 'gender');
    $lines[] = "Faction: " . stobeAiNpcFieldValue($row, 'faction');
    $lines[] = "";
    $lines[] = "SETTINGS";
    $lines[] = stobeAiNpcSettingsSummary($row, $profileMetadata);
    $lines[] = "";
    $lines[] = "LIVE STATUS";
    $lines[] = "Blood: " . stobeAiNpcFieldValue($row, 'blood');
    $lines[] = "Hunger: " . stobeAiNpcFieldValue($row, 'hunger');
    $lines[] = "Animal: " . (stobeAiNpcBoolValue($row['is_animal'] ?? false) ? 'Yes' : 'No');
    $lines[] = "Slave: " . (stobeAiNpcBoolValue($row['is_slave'] ?? false) ? 'Yes' : 'No');
    $lines[] = "";
    $lines[] = "Equipment:";
    $lines[] = stobeAiNpcSnapshotValue($row, 'equipment');
    $lines[] = "";
    $lines[] = "Inventory:";
    $lines[] = stobeAiNpcSnapshotValue($row, 'inventory');
    $lines[] = "";
    $lines[] = "Skills:";
    $lines[] = stobeAiNpcSnapshotValue($row, 'skills');
    $lines[] = "";
    $lines[] = "Limbs:";
    $lines[] = stobeAiNpcSnapshotValue($row, 'limbs');
    $lines[] = "";
    $lines[] = "Bounty:";
    $lines[] = stobeAiNpcSnapshotValue($row, 'bounty');
    $lines[] = "";
    $lines[] = "BIOGRAPHY";
    $lines[] = "";
    $lines[] = "Backstory:";
    $lines[] = stobeAiNpcFieldValue($row, 'backstory', '(empty)');
    $lines[] = "";
    $lines[] = "Personality:";
    $lines[] = stobeAiNpcFieldValue($row, 'personality', '(empty)');
    $lines[] = "";
    $lines[] = "Speech Style:";
    $lines[] = stobeAiNpcFieldValue($row, 'speechstyle', '(empty)');
    $lines[] = "";
    $lines[] = "Occupation:";
    $lines[] = stobeAiNpcFieldValue($row, 'occupation', '(empty)');
    $lines[] = "";
    $lines[] = "Appearance:";
    $lines[] = stobeAiNpcFieldValue($row, 'appearance', '(empty)');
    $lines[] = "";
    $lines[] = "Goals:";
    $lines[] = stobeAiNpcFieldValue($row, 'goals', '(empty)');
    $lines[] = "";
    $lines[] = "RELATIONSHIPS";
    $lines[] = $relationships === '' ? 'No relationship data.' : $relationships;
    $lines[] = "";
    $lines[] = "RECENT EVENTS";
    $lines[] = stobeAiNpcRecentEvents($row);
    $lines[] = "";
    $lines[] = "LAST UPDATED";
    $lines[] = "Game TS Last Updated: " . strval(intval($row['gamets_last_updated'] ?? 0));
    $lines[] = "Updated At: " . trim(strval($row['updated_at'] ?? ''));

    echo json_encode(
        [
            'ok' => true,
            'text' => implode("\n", $lines),
        ],
        $jsonFlags
    );
    return;
}

if ($action === 'player_view') {
    require_once $path . 'lib/npc_player_view.php';
    try {
        $view = stobeNpcPlayerViewText($payload);
    } catch (Throwable $e) {
        stobeLogWarn('NPC info panel: player_view failed', ['error' => $e->getMessage()]);
        echo json_encode(['ok' => false, 'error' => 'player_view failed', 'key' => strval($payload['key'] ?? '')], $jsonFlags);
        return;
    }
    // card fields (name, job, faction, race_line, relation_label/line, talked_line, about, bio, bio_state,
    // bio_stale, deals, now, body) + 'text' (whole card as plain text, read by the harness)
    echo json_encode(array_merge(['ok' => true, 'key' => strval($payload['key'] ?? '')], $view), $jsonFlags);
    return;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);

