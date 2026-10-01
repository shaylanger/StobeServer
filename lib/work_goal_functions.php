<?php

/**
 * Persistent finite work goals for player-faction NPCs.
 *
 * Dialogue creates a goal here; KenshiFP executes and persists the live plan.
 * This layer stores human-readable status and resolves remembered destinations.
 */

function stobeWorkGoalEnsureSchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        throw new RuntimeException('Work-goal database unavailable');
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS player_base_locations (
            base_id TEXT PRIMARY KEY REFERENCES player_bases(base_id) ON DELETE CASCADE,
            base_name TEXT NOT NULL DEFAULT '',
            x DOUBLE PRECISION NOT NULL,
            y DOUBLE PRECISION NOT NULL,
            z DOUBLE PRECISION NOT NULL,
            zone_name TEXT NOT NULL DEFAULT '',
            city_name TEXT NOT NULL DEFAULT '',
            game_ts BIGINT NOT NULL DEFAULT 0,
            observed_at TIMESTAMP NOT NULL DEFAULT NOW()
        )",
        "CREATE INDEX IF NOT EXISTS idx_player_base_locations_name
            ON player_base_locations (LOWER(base_name))",
        "CREATE TABLE IF NOT EXISTS stobe_work_goal (
            goal_id TEXT PRIMARY KEY,
            actor_name TEXT NOT NULL,
            actor_serial BIGINT NOT NULL DEFAULT 0,
            item_name TEXT NOT NULL,
            quantity INT NOT NULL CHECK (quantity BETWEEN 1 AND 1000),
            destination_name TEXT NOT NULL DEFAULT '',
            destination_x DOUBLE PRECISION,
            destination_y DOUBLE PRECISION,
            destination_z DOUBLE PRECISION,
            status TEXT NOT NULL DEFAULT 'ACTIVE',
            completed INT NOT NULL DEFAULT 0,
            current_step TEXT NOT NULL DEFAULT '',
            reason TEXT NOT NULL DEFAULT '',
            created_game_ts BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            CONSTRAINT stobe_work_goal_status_check
                CHECK (status IN ('ACTIVE','COMPLETE','BLOCKED','CANCELLED','PAUSED'))
        )",
        "CREATE INDEX IF NOT EXISTS idx_stobe_work_goal_actor
            ON stobe_work_goal (LOWER(actor_name), updated_at DESC)",
    ];
    foreach ($statements as $statement) {
        if ($db->exec($statement) === false) {
            $error = method_exists($db, 'GetLastError') ? $db->GetLastError() : '';
            throw new RuntimeException('Failed to initialize work-goal schema: ' . $error);
        }
    }
    $ensured = true;
}

function stobeWorkGoalRememberBaseLocation(string $baseId, string $baseName, int $gameTs): void
{
    $baseId = trim($baseId);
    $baseName = trim($baseName);
    if ($baseId === '') {
        return;
    }
    stobeWorkGoalEnsureSchema();
    $db = $GLOBALS['db'];

    $location = $db->fetchOne(
        "SELECT zone_name, city_name, x, y, z
         FROM location_zones
         WHERE x IS NOT NULL AND y IS NOT NULL AND z IS NOT NULL
           AND metadata->>'knowledge_only' IS DISTINCT FROM 'true'
           AND last_seen_ts >= EXTRACT(EPOCH FROM NOW())::bigint - 60
         ORDER BY last_seen_ts DESC, id DESC
         LIMIT 1"
    );
    if (!is_array($location) ||
        !is_numeric($location['x'] ?? null) ||
        !is_numeric($location['y'] ?? null) ||
        !is_numeric($location['z'] ?? null)) {
        return;
    }
    $db->exec(
        "INSERT INTO player_base_locations (
            base_id, base_name, x, y, z, zone_name, city_name, game_ts, observed_at
         ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,NOW())
         ON CONFLICT (base_id) DO UPDATE SET
            base_name=EXCLUDED.base_name,
            x=EXCLUDED.x, y=EXCLUDED.y, z=EXCLUDED.z,
            zone_name=EXCLUDED.zone_name,
            city_name=EXCLUDED.city_name,
            game_ts=EXCLUDED.game_ts,
            observed_at=NOW()",
        [
            $baseId,
            $baseName !== '' ? $baseName : 'Player Base',
            floatval($location['x']),
            floatval($location['y']),
            floatval($location['z']),
            trim(strval($location['zone_name'] ?? '')),
            trim(strval($location['city_name'] ?? '')),
            max(0, $gameTs),
        ]
    );
}

function stobeWorkGoalResolveDestination(string $requested): array|false
{
    $requested = trim(preg_replace('/[\r\n\t]+/', ' ', $requested) ?? '');
    if ($requested === '' || in_array(strtolower($requested), ['here','current','current location'], true)) {
        return [
            'name' => '',
            'x' => null,
            'y' => null,
            'z' => null,
        ];
    }
    stobeWorkGoalEnsureSchema();
    $db = $GLOBALS['db'];
    $row = $db->fetchOne(
        "SELECT base_name, x, y, z
         FROM player_base_locations
         WHERE LOWER(base_name)=LOWER($1)
         ORDER BY observed_at DESC
         LIMIT 1",
        [$requested]
    );
    if (!$row) {
        $row = $db->fetchOne(
            "SELECT base_name, x, y, z
             FROM player_base_locations
             WHERE LOWER(base_name) LIKE $1
             ORDER BY observed_at DESC
             LIMIT 1",
            ['%' . strtolower($requested) . '%']
        );
    }
    if (is_array($row) && is_numeric($row['x'] ?? null) &&
        is_numeric($row['y'] ?? null) && is_numeric($row['z'] ?? null)) {
        return [
            'name' => trim(strval($row['base_name'] ?? $requested)),
            'x' => floatval($row['x']),
            'y' => floatval($row['y']),
            'z' => floatval($row['z']),
        ];
    }

    if (function_exists('stobeResolveTravelLocationFromVisitedZones')) {
        $zone = stobeResolveTravelLocationFromVisitedZones($requested);
        if (is_array($zone)) {
            return [
                'name' => trim(strval($zone['label'] ?? $requested)),
                'x' => floatval($zone['x'] ?? 0),
                'y' => floatval($zone['y'] ?? 0),
                'z' => floatval($zone['z'] ?? 0),
            ];
        }
    }
    return false;
}
function stobeWorkGoalId(): string
{
    try {
        return 'wg-' . bin2hex(random_bytes(12));
    } catch (Throwable) {
        return 'wg-' . str_replace('.', '', uniqid('', true));
    }
}

function stobeQueueWorkGoalRequest(
    string $actor,
    string $item,
    int $quantity,
    string $destination = '',
    int $gameTs = 0
): array {
    stobeWorkGoalEnsureSchema();

    $safeActor = normalizeParticipantNameToken($actor);
    $safeItem = trim(preg_replace('/[\r\n\t|]+/', ' ', $item) ?? '');
    $safeDestination = trim(preg_replace('/[\r\n\t|]+/', ' ', $destination) ?? '');
    $quantity = max(1, min(1000, $quantity));
    if ($safeActor === '' || $safeItem === '') {
        return ['ok' => false, 'error' => 'missing_actor_or_item'];
    }
    if (strlen($safeItem) > 160) {
        $safeItem = substr($safeItem, 0, 160);
    }
    if (strlen($safeDestination) > 160) {
        $safeDestination = substr($safeDestination, 0, 160);
    }

    $serial = function_exists('stobeResolveLiveParticipantSerial')
        ? stobeResolveLiveParticipantSerial($safeActor, true)
        : 0;
    if ($serial <= 0) {
        return ['ok' => false, 'error' => 'actor_serial_unavailable'];
    }
    $resolved = stobeWorkGoalResolveDestination($safeDestination);
    if ($resolved === false) {
        return ['ok' => false, 'error' => 'destination_not_known', 'destination' => $safeDestination];
    }

    $goalId = stobeWorkGoalId();
    $destName = trim(strval($resolved['name'] ?? ''));
    $x = $resolved['x'] ?? null;
    $y = $resolved['y'] ?? null;
    $z = $resolved['z'] ?? null;

    $GLOBALS['db']->exec(
        "INSERT INTO stobe_work_goal (
            goal_id, actor_name, actor_serial, item_name, quantity,
            destination_name, destination_x, destination_y, destination_z,
            status, completed, current_step, created_game_ts
         ) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,'ACTIVE',0,$10,$11)",
        [
            $goalId, $safeActor, $serial, $safeItem, $quantity,
            $destName, $x, $y, $z,
            'Accepted goal: make/obtain ' . $quantity . ' ' . $safeItem,
            max(0, $gameTs),
        ]
    );

    $requestPath = '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_work_goal.request';
    $line = implode("\t", [
        $goalId,
        strval($serial),
        $safeActor,
        $safeItem,
        strval($quantity),
        $destName,
        $x === null ? '' : strval($x),
        $y === null ? '' : strval($y),
        $z === null ? '' : strval($z),
    ]) . "\n";
    if (@file_put_contents($requestPath, $line, FILE_APPEND | LOCK_EX) === false) {
        $GLOBALS['db']->exec(
            "UPDATE stobe_work_goal SET status='BLOCKED',
                    reason='Could not queue goal to KenshiFP', updated_at=NOW()
             WHERE goal_id=$1",
            [$goalId]
        );
        return ['ok' => false, 'error' => 'request_file_write_failed', 'goal_id' => $goalId];
    }

    stobeLogInfo('Queued persistent work goal', [
        'goal_id' => $goalId,
        'actor' => $safeActor,
        'serial' => $serial,
        'item' => $safeItem,
        'quantity' => $quantity,
        'destination' => $destName,
    ]);
    return [
        'ok' => true,
        'goal_id' => $goalId,
        'actor' => $safeActor,
        'item' => $safeItem,
        'quantity' => $quantity,
        'destination' => $destName,
    ];
}

function stobeWorkGoalSyncStatusFile(): void
{
    stobeWorkGoalEnsureSchema();
    $path = '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_work_goal.status';
    if (!is_file($path) || filesize($path) <= 0) {
        return;
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }
    foreach (array_slice($lines, 0, 64) as $line) {
        $parts = explode("\t", strval($line));
        if (count($parts) < 9) {
            continue;
        }
        [$goalId, $actor, $status, $item, $quantity, $completed,
            $destination, $step, $reason] = array_slice($parts, 0, 9);
        $status = strtoupper(trim($status));
        if (!in_array($status, ['ACTIVE','COMPLETE','BLOCKED','CANCELLED','PAUSED'], true)) {
            continue;
        }
        $goalId = trim($goalId);
        $existing = $GLOBALS['db']->fetchOne(
            "SELECT goal_id FROM stobe_work_goal WHERE goal_id=$1",
            [$goalId]
        );
        if ($existing) {
            $GLOBALS['db']->exec(
                "UPDATE stobe_work_goal
                 SET status=$2, completed=$3, current_step=$4, reason=$5, updated_at=NOW()
                 WHERE goal_id=$1",
                [
                    $goalId,
                    $status,
                    max(0, intval($completed)),
                    substr(trim($step), 0, 500),
                    substr(trim($reason), 0, 700),
                ]
            );
        } else {
            $safeActor = normalizeParticipantNameToken($actor);
            $safeItem = substr(trim(strval($item)), 0, 160);
            $safeDestination = substr(trim(strval($destination)), 0, 160);
            $safeQuantity = max(1, min(1000, intval($quantity)));
            if ($safeActor !== '' && $safeItem !== '') {
                $GLOBALS['db']->exec(
                    "INSERT INTO stobe_work_goal (
                        goal_id,actor_name,actor_serial,item_name,quantity,
                        destination_name,status,completed,current_step,reason
                     ) VALUES ($1,$2,0,$3,$4,$5,$6,$7,$8,$9)
                     ON CONFLICT (goal_id) DO NOTHING",
                    [
                        $goalId,$safeActor,$safeItem,$safeQuantity,$safeDestination,
                        $status,max(0,intval($completed)),
                        substr(trim($step),0,500),substr(trim($reason),0,700),
                    ]
                );
            }
        }
    }
}

function stobeBuildWorkGoalStateBlock(string $npcName): string
{
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return '';
    }
    try {
        stobeWorkGoalSyncStatusFile();
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT goal_id, item_name, quantity, destination_name,
                    status, completed, current_step, reason
             FROM stobe_work_goal
             WHERE LOWER(actor_name)=LOWER($1)
             ORDER BY CASE WHEN status='ACTIVE' THEN 0 ELSE 1 END,
                      updated_at DESC
             LIMIT 6",
            [$safeNpc]
        );
    } catch (Throwable $exception) {
        return '';
    }
    if (!is_array($rows) || count($rows) === 0) {
        return '';
    }

    $lines = ['<work_goals>'];
    $lines[] = '  <rule>These are real persistent Kenshi work goals owned by this NPC.</rule>';
    $lines[] = '  <rule>Do not claim a blocked or incomplete goal succeeded. If a goal is blocked, explain its recorded reason naturally when relevant.</rule>';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = strtoupper(trim(strval($row['status'] ?? 'ACTIVE')));
        $item = trim(strval($row['item_name'] ?? ''));
        $qty = max(1, intval($row['quantity'] ?? 1));
        $done = max(0, intval($row['completed'] ?? 0));
        $dest = trim(strval($row['destination_name'] ?? ''));
        $step = trim(strval($row['current_step'] ?? ''));
        $reason = trim(strval($row['reason'] ?? ''));
        $attrs = ' status="' . stobePromptXmlEscape($status) . '"'
            . ' output="' . stobePromptXmlEscape($item) . '"'
            . ' requested="' . $qty . '" completed="' . $done . '"';
        if ($dest !== '') {
            $attrs .= ' destination="' . stobePromptXmlEscape($dest) . '"';
        }
        $lines[] = '  <goal' . $attrs . '>';
        if ($step !== '') {
            $lines[] = '    <current_step>' . stobePromptXmlEscape($step) . '</current_step>';
        }
        if ($reason !== '') {
            $lines[] = '    <blocker>' . stobePromptXmlEscape($reason) . '</blocker>';
        }
        $lines[] = '  </goal>';
    }
    $lines[] = '</work_goals>';
    return implode("\n", $lines);
}
