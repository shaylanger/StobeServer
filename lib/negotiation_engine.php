<?php

/**
 * STOBE negotiation engine (Phases 1-8).
 *
 * The ledger (negotiation_phase1.php) records what was agreed. This module makes
 * agreements real: it dispatches each party's terms through existing game
 * actions, verifies them from observed evidence (the game's own ACTION_EXEC /
 * ACTION_BRIDGE execution records, Cats balances, inventories and combat
 * events), and resolves the deal. Nothing is marked done because an LLM said so.
 *
 * Phase 1 (performance + verification) is always on. Phases 2-8 each read a
 * general_settings toggle NEGOTIATION_PHASE_<n> (default true).
 */

const STOBE_NEG_PAY_WINDOW_GAMETS = 1950;        // bug 41: ~60 s of game time at 1x (about 32.5 gamets/s)
const STOBE_NEG_PAY_WINDOW_REAL_CAP = 1800;      // bug 41: no game time seen at all (paused/closed): give up after 30 min
const STOBE_NEG_PAY_WINDOW_SECONDS = 60;          // combat: player pays within 1 minute
const STOBE_NEG_SOCIAL_DEADLINE_GAMETS = 86400;   // social: 1 in-game day
const STOBE_NEG_TRUCE_OBSERVE_SECONDS = 120;     // a paid ceasefire must outlast the gang's re-aggro (bug 90)
const STOBE_NEG_SAFE_PASSAGE_SECONDS = 120;
const STOBE_NEG_PROTECT_QUIET_SECONDS = 45;
const STOBE_NEG_PROTECT_DEADLINE_SECONDS = 300;
const STOBE_NEG_EXECUTION_TIMEOUT_SECONDS = 45;   // a dispatched NPC action must show an execution record
const STOBE_NEG_TRUCE_MAX_REISSUE = 4;
const STOBE_NEG_MAX_COUNTER_ROUNDS = 6;
const STOBE_NEG_DIRECTIVE_TTL_SECONDS = 45;
const STOBE_NEG_INITIATIVE_GLOBAL_COOLDOWN = 120;
const STOBE_NEG_INITIATIVE_NPC_COOLDOWN = 1800;   // "once per NPC per fight" (a long fight outlasted 600 s, bug 91)
const STOBE_NEG_BETRAYAL_MAX_CHANCE = 0.15;

// ------------------------------------------------------------------ toggles

function stobeNegPhaseEnabled(int $phase): bool {
    if ($phase <= 1) return true;
    try {
        return getSettingBool('NEGOTIATION_PHASE_' . $phase, true);
    } catch (Throwable $e) {
        return true;
    }
}

function stobeNegAllowedTermKinds(): array {
    $kinds = ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM','STOP_ATTACK','FIRST_AID','PROMISE'];
    if (stobeNegPhaseEnabled(4)) { $kinds[] = 'SURRENDER'; $kinds[] = 'SPARE'; }
    if (stobeNegPhaseEnabled(5)) { $kinds[] = 'PROTECT'; }
    if (stobeNegPhaseEnabled(6)) { array_push($kinds, 'LOAN_ITEM', 'UNEQUIP_ITEM', 'EQUIP_ITEM'); }
    if (stobeNegPhaseEnabled(8)) { array_push($kinds, 'SAFE_PASSAGE', 'RELEASE_PRISONER'); }
    return $kinds;
}

/** Kinds only a specific side can perform. */
function stobeNegTermPerformer(string $kind): string {
    if (in_array($kind, ['STOP_ATTACK','SURRENDER','LOAN_ITEM','UNEQUIP_ITEM','EQUIP_ITEM','SAFE_PASSAGE','RELEASE_PRISONER'], true)) return 'npc';
    if (in_array($kind, ['SPARE','PROTECT'], true)) return 'player';
    return '';
}

// ------------------------------------------------------------------ schema

function stobeNegEnsureSchema(): void {
    static $done = false;
    if ($done) return;
    stobeDealEnsureSchema();
    $db = $GLOBALS['db'];
    $sql = [
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS kind TEXT NOT NULL DEFAULT 'combat'",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS proposer TEXT NOT NULL DEFAULT 'player'",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS term_state JSONB NOT NULL DEFAULT '[]'::jsonb",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS baseline JSONB NOT NULL DEFAULT '{}'::jsonb",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS rounds INT NOT NULL DEFAULT 0",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS betrayal JSONB NOT NULL DEFAULT '{}'::jsonb",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS performance_started_unix BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS deadline_unix BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS deadline_gamets BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP",
        "ALTER TABLE stobe_social_contract ADD COLUMN IF NOT EXISTS consequences_applied BOOLEAN NOT NULL DEFAULT FALSE",
        "CREATE INDEX IF NOT EXISTS idx_social_contract_status ON stobe_social_contract (status)",
        "CREATE TABLE IF NOT EXISTS stobe_negotiation_directive (
            id BIGSERIAL PRIMARY KEY,
            npc_name TEXT NOT NULL,
            kind TEXT NOT NULL,
            contract_id TEXT NOT NULL DEFAULT '',
            payload JSONB NOT NULL DEFAULT '{}'::jsonb,
            created_unix BIGINT NOT NULL,
            consumed_unix BIGINT NOT NULL DEFAULT 0,
            outcome TEXT NOT NULL DEFAULT '')",
        "CREATE INDEX IF NOT EXISTS idx_neg_directive_pending ON stobe_negotiation_directive (consumed_unix, created_unix)",
        "CREATE TABLE IF NOT EXISTS stobe_negotiation_reputation (
            player_name TEXT PRIMARY KEY,
            player_kept INT NOT NULL DEFAULT 0,
            player_broken INT NOT NULL DEFAULT 0,
            npc_kept INT NOT NULL DEFAULT 0,
            npc_broken INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT NOW())",
    ];
    foreach ($sql as $statement) {
        if ($db->exec($statement) === false) throw new RuntimeException('Negotiation schema update failed');
    }
    $done = true;
}

function stobeNegDecode(mixed $value, mixed $default = []): mixed {
    if (is_array($value)) return $value;
    $decoded = json_decode(strval($value ?? ''), true);
    return is_array($decoded) ? $decoded : $default;
}

function stobeNegFetchDeal(string $id): ?array {
    stobeNegEnsureSchema();
    $row = $GLOBALS['db']->fetchOne("SELECT * FROM stobe_social_contract WHERE contract_id=$1", [$id]);
    return is_array($row) ? $row : null;
}

function stobeNegSaveDeal(string $id, string $expectedStatus, array $fields): bool {
    stobeNegEnsureSchema();
    $sets = [];
    $params = [$id, $expectedStatus];
    foreach ($fields as $column => $value) {
        if (!preg_match('/^[a-z_]+$/', $column)) continue;
        // Timestamps compared against NOW() must come from NOW(): the DB clock is not UTC.
        if ($column === 'resolved_at' && $value === true) { $sets[] = 'resolved_at=NOW()'; continue; }
        $params[] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        $placeholder = '$' . count($params);
        $cast = in_array($column, ['term_state','baseline','betrayal','evidence','terms','conflict_context'], true) ? '::jsonb' : '';
        $sets[] = $column . '=' . $placeholder . $cast;
    }
    $sets[] = 'updated_at=NOW()';
    $result = $GLOBALS['db']->exec(
        'UPDATE stobe_social_contract SET ' . implode(',', $sets) . ' WHERE contract_id=$1 AND status=$2',
        $params
    );
    return $result !== false && $GLOBALS['db']->affectedRows($result) === 1;
}

// ------------------------------------------------------------------ world evidence

function stobeNegNpcRow(string $name, int $serial = 0): array {
    // Generic names ("Slavemonger Guard") are shared; the live serial is not.
    if ($serial > 0) {
        $row = $GLOBALS['db']->fetchOne(
            "SELECT name, metadata, inventory, equipment, blood, limbs, personality, faction FROM core_npc_master WHERE metadata->>'storage_id' = $1 LIMIT 1",
            ['hand_' . $serial]
        );
        if (is_array($row)) return $row;
    }
    $row = $GLOBALS['db']->fetchOne(
        "SELECT name, metadata, inventory, equipment, blood, limbs, personality, faction FROM core_npc_master WHERE LOWER(name)=LOWER($1) LIMIT 1",
        [$name]
    );
    return is_array($row) ? $row : [];
}

/** Live serial for an NPC from its stored storage_id (hand_<serial>), or 0. */
function stobeNegSerialFromStorage(string $name): int {
    $row = $GLOBALS['db']->fetchOne(
        "SELECT metadata->>'storage_id' AS sid FROM core_npc_master WHERE LOWER(name)=LOWER($1) AND metadata ? 'storage_id' LIMIT 2",
        [$name]
    );
    $sid = strval($row['sid'] ?? '');
    if (!preg_match('/^hand_(-?\d+)$/', $sid, $m)) return 0;
    $serial = intval($m[1]);
    if ($serial < 0) $serial += 4294967296;
    return ($serial > 0 && $serial <= 4294967295) ? $serial : 0;
}

/** The deal's NPC serial, recovering (and saving) it when the deal was made without one. */
function stobeNegDealSerial(array &$deal): int {
    $serial = intval($deal['npc_serial'] ?? 0);
    if ($serial > 0) return $serial;
    $serial = stobeNegSerialFromStorage(strval($deal['npc_name'] ?? ''));
    if ($serial > 0) {
        $deal['npc_serial'] = $serial;
        $GLOBALS['db']->exec("UPDATE stobe_social_contract SET npc_serial=$2 WHERE contract_id=$1 AND npc_serial=0", [strval($deal['contract_id'] ?? ''), $serial]);
    }
    return $serial;
}

function stobeNegMoney(array $row, bool $isPlayer = false): array {
    $meta = stobeNegDecode($row['metadata'] ?? []);
    if (!array_key_exists('money', $meta)) {
        // The DLL syncs the player's balance into conf_opts PLAYER_CATS, not onto the character row.
        $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
        if ($isPlayer || ($player !== '' && strcasecmp(normalizeParticipantNameToken(strval($row['name'] ?? '')), $player) === 0)) {
            $cats = trim(strval(function_exists('getConfOpt') ? getConfOpt('PLAYER_CATS', '') : ''));
            if ($cats !== '' && is_numeric($cats)) return ['known'=>true, 'value'=>max(0, intval($cats)), 'observed_at'=>time()];
        }
        return ['known'=>false, 'value'=>0, 'observed_at'=>0];
    }
    return ['known'=>true, 'value'=>intval($meta['money']), 'observed_at'=>intval($meta['money_observed_at'] ?? 0)];
}

/** "Chewing Tobacco x1 value 112, Wooden Sandals [Shoddy] x1 value 45" -> [lower base name => qty]. */
function stobeNegInventoryCounts(string $text): array {
    $counts = [];
    foreach (preg_split('/,\s*(?=[^,]*\bx\d+)/', $text) ?: [] as $entry) {
        if (!preg_match('/^\s*(.+?)\s+x(\d+)\b/', $entry, $m)) continue;
        $name = strtolower(trim(preg_replace('/\s*\[[^\]]*\]|\s*\([^)]*\)/', '', $m[1]) ?? ''));
        if ($name === '') continue;
        $counts[$name] = ($counts[$name] ?? 0) + intval($m[2]);
    }
    return $counts;
}

/** Generic words a deal may use for "any item of this kind". */
function stobeNegItemCategories(): array {
    return [
        'drink' => ['grog','rum','sake','vodka','whiskey','whisky','beer','ale','wine','spirits','liquor','mead','brandy','gin','booze'],
        // Kenshi food items (and common modded ones). A specific name in a deal ("bread") still means exactly that.
        'food' => ['bread','cabbage','chewstick','chewsticks','dried fish','dried meat','dustwich','foodcube','gohan','meatwrap',
            'ration','rations','ration pack','raw meat','raw fish','rice bowl','sandwich','vegetables','vegetable','veggies',
            'greenfruit','fruit','fungus','meat','fish','stew','noodles','dumplings','cake','biscuit','hardtack','jerky'],
    ];
}

/** Category a (possibly generic) term item refers to, or ''. */
function stobeNegItemCategory(string $item): string {
    $item = strtolower(trim($item));
    $aliases = ['drink'=>'drink','a drink'=>'drink','drinks'=>'drink','booze'=>'drink','alcohol'=>'drink','liquor'=>'drink','a round'=>'drink','round'=>'drink',
        'food'=>'food','some food'=>'food','a meal'=>'food','meal'=>'food','grub'=>'food','something to eat'=>'food','eats'=>'food','provisions'=>'food'];
    return $aliases[$item] ?? '';
}

/** Does a concrete item name satisfy a term item (exact, partial, or category)? */
function stobeNegItemMatchesTerm(string $concrete, string $termItem): bool {
    if (stobeNegNameMatches($concrete, $termItem)) return true;
    $category = stobeNegItemCategory($termItem);
    if ($category === '') return false;
    $concrete = strtolower($concrete);
    foreach (stobeNegItemCategories()[$category] ?? [] as $word) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $concrete)) return true;
    }
    return false;
}

function stobeNegItemCount(array $counts, string $item): int {
    if (stobeNegItemCategory($item) !== '') {
        $total = 0;
        foreach ($counts as $name => $qty) {
            if (stobeNegItemMatchesTerm($name, $item)) $total += $qty;
        }
        return $total;
    }
    $needle = strtolower(trim(preg_replace('/\s*\[[^\]]*\]|\s*\([^)]*\)/', '', $item) ?? ''));
    if ($needle === '') return 0;
    $total = 0;
    foreach ($counts as $name => $qty) {
        if ($name === $needle || str_contains($name, $needle) || str_contains($needle, $name)) $total += $qty;
    }
    return $total;
}

function stobeNegHealthRatio(array $row): float {
    $ratios = [];
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)/', strval($row['blood'] ?? ''), $m) && floatval($m[2]) > 0) {
        $ratios[] = max(0.0, floatval($m[1]) / floatval($m[2]));
    }
    $limbs = stobeNegDecode($row['limbs'] ?? []);
    foreach (['head','stomach','chest'] as $part) {
        if (isset($limbs[$part], $limbs[$part . '_max']) && floatval($limbs[$part . '_max']) > 0) {
            $ratios[] = max(0.0, floatval($limbs[$part]) / floatval($limbs[$part . '_max']));
        }
    }
    $disabled = 0;
    foreach (['left_arm','right_arm','left_leg','right_leg'] as $part) {
        if (isset($limbs[$part]) && floatval($limbs[$part]) <= 0) $disabled++;
    }
    if (count($ratios) === 0) return 1.0;
    return max(0.0, min($ratios) - 0.15 * $disabled);
}

function stobeNegIsPlayerSide(string $name, string $player): bool {
    static $cache = [];
    $name = normalizeParticipantNameToken($name);
    if ($name === '') return false;
    if (strcasecmp($name, $player) === 0) return true;
    $key = strtolower($name);
    if (!array_key_exists($key, $cache)) {
        $data = getNpcData($name);
        $cache[$key] = is_array($data) && npcIsInPlayerFaction($data);
    }
    return $cache[$key];
}

/** Recent "A: Initiated attack (talking to: B)" events since $sinceUnix. */
function stobeNegCombatEvents(int $sinceUnix, string $involving = '', bool $includeDefending = false): array {
    $params = [$sinceUnix];
    $filter = '';
    if ($involving !== '') {
        $params[] = '%' . $involving . '%';
        $filter = ' AND data LIKE $2';
    }
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT localts, data FROM eventlog WHERE type='combat' AND localts >= $1" . $filter . " ORDER BY localts DESC LIMIT 400",
        $params
    );
    $events = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        // Bug 98: for "is this NPC fighting the player?" a defence counts too; a defence
        // is never the player breaking a deal (SPARE, truces), so it's opt-in.
        $pattern = $includeDefending
            ? '/^(.+?):\s*(?:Initiated attack|Defending against)\s*\(talking to:\s*(.+?)\)\s*$/'
            : '/^(.+?):\s*Initiated attack\s*\(talking to:\s*(.+?)\)\s*$/';
        if (!preg_match($pattern, trim(strval($row['data'] ?? '')), $m)) continue;
        $events[] = ['ts'=>intval($row['localts']), 'attacker'=>normalizeParticipantNameToken($m[1]), 'target'=>normalizeParticipantNameToken($m[2])];
    }
    return $events;
}

/**
 * Item 71: fight events involving this NPC since $sinceUnix, as [ts, attacker, target]:
 * combat rows under his name (defences included); combat rows under his pre-naming generic
 * name ("Dust Bandit" for "Torek [Dust Bandit]") when that side's serial in the row's people
 * list is his; and "took a major hit from X" (X attacked him).
 */
function stobeNegFightEventsForNpc(string $name, array|false $npcData, int $sinceUnix): array {
    $events = stobeNegCombatEvents($sinceUnix, $name, true);
    $serial = function_exists('stobeNegNpcHandSerial') ? stobeNegNpcHandSerial($name, $npcData) : '';
    $serials = [];
    if ($serial !== '') {
        $serials[] = $serial;
        $signed = intval($serial) - 4294967296;
        if ($signed < 0) $serials[] = strval($signed);
    }
    $sideSerial = static function (string $people, int $index): string {
        $list = json_decode($people, true);
        if (!is_array($list) || !isset($list[$index])) return '';
        return preg_match('/\|hand_(-?\d+)\s*$/', strval($list[$index]), $m) ? $m[1] : '';
    };
    $generic = preg_match('/\[([^\]]+)\]\s*$/', $name, $gm) ? trim($gm[1]) : '';
    if ($generic !== '' && count($serials) > 0) {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT localts, data, people FROM eventlog WHERE type='combat' AND localts >= $1 AND data LIKE $2 ORDER BY localts DESC LIMIT 400",
            [$sinceUnix, '%' . $generic . '%']
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!preg_match('/^(.+?):\s*(?:Initiated attack|Defending against)\s*\(talking to:\s*(.+?)\)\s*$/', trim(strval($row['data'] ?? '')), $m)) continue;
            $attacker = normalizeParticipantNameToken($m[1]);
            $target = normalizeParticipantNameToken($m[2]);
            $people = strval($row['people'] ?? '');
            if (strcasecmp($attacker, $generic) === 0 && in_array($sideSerial($people, 0), $serials, true)) $attacker = $name;
            elseif (strcasecmp($target, $generic) === 0 && in_array($sideSerial($people, 1), $serials, true)) $target = $name;
            else continue;
            $events[] = ['ts'=>intval($row['localts']), 'attacker'=>$attacker, 'target'=>$target];
        }
    }
    $hitNames = [$name];
    if ($generic !== '' && count($serials) > 0) $hitNames[] = $generic;
    foreach ($hitNames as $hitName) {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT localts, data, people FROM eventlog WHERE type='major_damage' AND localts >= $1 AND data LIKE $2 ORDER BY localts DESC LIMIT 100",
            [$sinceUnix, $hitName . ': took a major hit from %']
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ($hitName !== $name && !in_array($sideSerial(strval($row['people'] ?? ''), 0), $serials, true)) continue;
            if (!preg_match('/:\s*took a major hit from\s+(.+?)(?:\s+using\s+.+)?\s*$/', trim(strval($row['data'] ?? '')), $m)) continue;
            $events[] = ['ts'=>intval($row['localts']), 'attacker'=>normalizeParticipantNameToken($m[1]), 'target'=>$name];
        }
    }
    return $events;
}

/**
 * Item 86: did the player side really hurt $npc between $fromUnix and $toUnix? A major hit from the
 * player side, a knockout by the player side, or his death. "Initiated attack" alone is not harm:
 * the game re-reports it while a personal truce holds.
 */
function stobeNegPlayerHarmedNpc(string $npc, string $player, int $fromUnix, int $toUnix): bool {
    try {
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT type, data FROM eventlog WHERE type IN ('major_damage','knockout','death','combat')
               AND localts >= $1 AND localts <= $2 AND data LIKE $3 ORDER BY localts LIMIT 40",
            [$fromUnix, $toUnix, '%' . $npc . '%']);
    } catch (Throwable $e) {
        return true; // can't tell: keep the old behaviour
    }
    foreach (is_array($rows) ? $rows : [] as $row) {
        $data = trim(strval($row['data'] ?? ''));
        if (!str_starts_with(strtolower($data), strtolower($npc) . ':')) continue;
        if ($row['type'] === 'death') return true;
        // item 86b: he fights back right after (a restarted fight, not a re-reported attack)
        if ($row['type'] === 'combat' && preg_match('/:\s*Initiated attack\s*\(talking to:\s*(.+?)\)\s*$/', $data, $m)
            && stobeNegIsPlayerSide(trim($m[1]), $player)) return true;
        if (preg_match('/took a major hit from\s+(.+?)(?:\s+using\s+.+)?$/', $data, $m) && stobeNegIsPlayerSide(trim($m[1]), $player)) return true;
        if (preg_match('/Knocked out by .+? from\s+(.+?)\s*$/i', $data, $m) && stobeNegIsPlayerSide(trim($m[1]), $player)) return true;
    }
    return false;
}

/** Item 86: a load of an older save cancels in-flight deals created after the loaded game time. */
function stobeNegCancelDealsAfterRollback(int $cutoffGamets): int {
    if ($cutoffGamets <= 0) return 0;
    try {
        stobeNegEnsureSchema();
        $rows = $GLOBALS['db']->fetchAll(
            "UPDATE stobe_social_contract SET status='CANCELLED', consequences_applied=TRUE, resolved_at=NOW(), updated_at=NOW(),
                    evidence=(CASE WHEN jsonb_typeof(evidence)='object' THEN evidence ELSE '{}'::jsonb END) || jsonb_build_object('note','rolled_back_by_load','cutoff_gamets',$1::bigint)
              WHERE status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')
                AND COALESCE(NULLIF(baseline->>'gamets','')::bigint, 0) > $1
            RETURNING contract_id", [$cutoffGamets]);
        $ids = array_map(static fn($r) => strval($r['contract_id']), is_array($rows) ? $rows : []);
        if (count($ids) > 0) {
            $GLOBALS['db']->exec("DELETE FROM stobe_negotiation_directive WHERE contract_id = ANY($1::text[])", ['{' . implode(',', $ids) . '}']);
            stobeLogInfo('Deals from after the loaded save cancelled (item 86)', ['cutoff_gamets'=>$cutoffGamets, 'deals'=>$ids]);
        }
        return count($ids);
    } catch (Throwable $e) {
        stobeLogWarn('Deal rollback failed (item 86)', ['error'=>$e->getMessage()]);
        return 0;
    }
}

function stobeNegNpcDied(string $npc, int $sinceUnix): bool {
    $row = $GLOBALS['db']->fetchOne(
        "SELECT 1 AS hit FROM eventlog WHERE type='death' AND localts >= $1 AND data LIKE $2 LIMIT 1",
        [$sinceUnix, '%' . $npc . '%']
    );
    return is_array($row);
}

// ------------------------------------------------------------------ execution records (game logs)

function stobeNegStobeLogPath(): string {
    return strval(getenv('STOBE_NEG_STOBE_LOG') ?: '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe.log');
}

function stobeNegKenshiFpLogPath(): string {
    return strval(getenv('STOBE_NEG_KFP_LOG') ?: '/mnt/d/Steam/steamapps/common/Kenshi/KenshiFP.log');
}

/** Log records are read once per request; tests reset between steps. */
function stobeNegResetLogCache(): void {
    unset($GLOBALS['__stobe_neg_exec_cache'], $GLOBALS['__stobe_neg_bridge_cache']);
}

function stobeNegTailLines(string $path, int $bytes = 524288): array {
    if (!is_readable($path)) return [];
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0) return [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    if ($size > $bytes) fseek($fh, $size - $bytes);
    $data = stream_get_contents($fh);
    fclose($fh);
    $lines = preg_split('/\r?\n/', strval($data)) ?: [];
    if ($size > $bytes) array_shift($lines); // partial first line
    return $lines;
}

/**
 * ACTION_EXEC records from Stobe.dll with absolute unix times. The log uses the
 * game PC's local clock, so the offset is derived from the file mtime and the
 * newest timestamped line rather than from a configured time zone.
 */
function stobeNegStobeActionRecords(int $sinceUnix): array {
    $cache =& $GLOBALS['__stobe_neg_exec_cache'];
    if (!is_array($cache)) {
        $cache = [];
        $path = stobeNegStobeLogPath();
        $lines = stobeNegTailLines($path, 2097152); // 2 MB: stobe.log is very chatty in combat
        $mtime = @filemtime($path);
        $lastLocal = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $lines[$i], $m)) {
                $lastLocal = intval(strtotime($m[1] . ' UTC'));
                break;
            }
        }
        $offset = ($lastLocal > 0 && is_int($mtime)) ? ($mtime - $lastLocal) : 0;
        foreach ($lines as $line) {
            if (!str_contains($line, 'ACTION_EXEC: ')) continue;
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})[^\]]*\].*?ACTION_EXEC: ([A-Z_]+) (.*)$/', $line, $m)) continue;
            $cache[] = ['ts'=>intval(strtotime($m[1] . ' UTC')) + $offset, 'cmd'=>$m[2], 'body'=>$m[3]];
        }
    }
    return array_values(array_filter($cache, static fn($r) => $r['ts'] >= $sinceUnix));
}

/** KenshiFP bridge records. That log has only HH:MM:SS, so anchor on mtime. */
function stobeNegBridgeRecords(int $sinceUnix): array {
    $cache =& $GLOBALS['__stobe_neg_bridge_cache'];
    if (!is_array($cache)) {
        $cache = [];
        $path = stobeNegKenshiFpLogPath();
        $lines = stobeNegTailLines($path, 1048576);
        $mtime = @filemtime($path);
        $toSecs = static fn(string $hms) => intval(substr($hms, 0, 2)) * 3600 + intval(substr($hms, 3, 2)) * 60 + intval(substr($hms, 6, 2));
        $lastSecs = -1;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/^\[(\d{2}:\d{2}:\d{2})/', $lines[$i], $m)) { $lastSecs = $toSecs($m[1]); break; }
        }
        if ($lastSecs >= 0 && is_int($mtime)) {
            foreach ($lines as $line) {
                if (!str_contains($line, '[stobe] ')) continue;
                if (!preg_match('/^\[(\d{2}:\d{2}:\d{2})[^\]]*\]\s*\[stobe\]\s*(.*)$/', $line, $m)) continue;
                $delta = $lastSecs - $toSecs($m[1]);
                if ($delta < 0) $delta += 86400;
                $cache[] = ['ts'=>$mtime - $delta, 'body'=>$m[2]];
            }
        }
    }
    return array_values(array_filter($cache, static fn($r) => $r['ts'] >= $sinceUnix));
}

/** Loose match, for ITEM names only ("Iron Hat" vs "Iron Hat [Shoddy]"). */
function stobeNegNameMatches(string $logged, string $name): bool {
    $logged = strtolower(trim($logged));
    $name = strtolower(trim($name));
    if ($logged === '' || $name === '') return false;
    return $logged === $name || str_contains($logged, $name) || str_contains($name, $logged);
}

/**
 * Character names: exact, or equal after dropping a bracketed title
 * ("Trella [Slaver Guard Resule]" == "Trella"). Never substring: "Hax" is not "Haxley".
 */
function stobeNegCharMatches(string $a, string $b): bool {
    $norm = static fn(string $s) => strtolower(trim(preg_replace('/\s*\[[^\]]*\]\s*/', ' ', $s) ?? $s));
    $a1 = strtolower(trim($a));
    $b1 = strtolower(trim($b));
    if ($a1 === '' || $b1 === '') return false;
    if ($a1 === $b1) return true;
    return $norm($a) !== '' && $norm($a) === $norm($b);
}

/**
 * Item 49: the recipient in a game record is the deal's NPC: same name, or the name he
 * was given after the deal ("Weth [Dust Bandit]" for "Dust Bandit") when that name's
 * stored serial is the deal's serial.
 */
function stobeNegRecipientMatches(string $recipient, string $npc, int $serial = 0): bool {
    if (stobeNegCharMatches($recipient, $npc)) return true;
    if ($serial <= 0 || !preg_match('/^.+\[\s*(.+?)\s*\]$/', trim($recipient), $bm)) return false;
    if (strcasecmp($bm[1], trim($npc)) !== 0) return false;
    $row = $GLOBALS['db']->fetchOne(
        "SELECT metadata->>'storage_id' AS sid FROM core_npc_master WHERE LOWER(name)=LOWER($1) LIMIT 1", [trim($recipient)]);
    return is_array($row) && strval($row['sid'] ?? '') === 'hand_' . $serial;
}

/** Cats moved from $from to $to per the game's GIVE_CATS execution records. */
function stobeNegCatsExecuted(string $from, string $to, int $sinceUnix, int $toSerial = 0): array {
    $total = 0;
    $skipped = '';
    foreach (stobeNegStobeActionRecords($sinceUnix) as $r) {
        if ($r['cmd'] !== 'GIVE_CATS') continue;
        if (preg_match('/^actor=(.+?) recipient=(.+?) amount=(\d+)/', $r['body'], $m)
            && stobeNegCharMatches($m[1], $from) && stobeNegRecipientMatches($m[2], $to, $toSerial)) {
            $total += intval($m[3]);
        } elseif (preg_match('/^actor=(.+?) skipped reason=(\S+)/', $r['body'], $m) && stobeNegCharMatches($m[1], $from)) {
            $skipped = $m[2];
        }
    }
    return ['amount'=>$total, 'skipped'=>$skipped];
}

function stobeNegItemExecuted(string $from, string $to, string $item, int $sinceUnix, int $toSerial = 0): array {
    $transferred = 0;
    $blocked = '';
    foreach (stobeNegStobeActionRecords($sinceUnix) as $r) {
        if ($r['cmd'] !== 'GIVE_ITEM') continue;
        if (preg_match("/^actor=(.+?) recipient=(.+?) requested=\\d+ transferred=(\\d+) item='([^']*)'/", $r['body'], $m)
            && stobeNegCharMatches($m[1], $from) && stobeNegRecipientMatches($m[2], $to, $toSerial)
            && stobeNegItemMatchesTerm($m[4], $item)) {
            $transferred += intval($m[3]);
        } elseif (preg_match('/^blocked actor=(.+?) reason=(\S+)/', $r['body'], $m) && stobeNegCharMatches($m[1], $from)) {
            $blocked = $m[2];
        }
    }
    return ['transferred'=>$transferred, 'blocked'=>$blocked];
}

function stobeNegStopAttackRecord(string $npc, int $sinceUnix): ?array {
    $found = null;
    foreach (stobeNegStobeActionRecords($sinceUnix) as $r) {
        if ($r['cmd'] !== 'STOP_ATTACK') continue;
        if (!preg_match('/^actor=(.+?) target=/', $r['body'], $m) || !stobeNegCharMatches($m[1], $npc)) continue;
        preg_match('/applied=(\d)/', $r['body'], $a);
        preg_match('/truce_registered=(\d)/', $r['body'], $t);
        preg_match('/reason=(\S*)/', $r['body'], $why);
        $found = ['ts'=>$r['ts'], 'applied'=>intval($a[1] ?? 0) === 1, 'truce'=>intval($t[1] ?? 0) === 1, 'reason'=>strval($why[1] ?? '')];
    }
    return $found;
}

/** KenshiFP bridge result for a command performed by the NPC's serial. */
function stobeNegBridgeResult(string $command, int $serial, int $sinceUnix, string $item = ''): string {
    $result = '';
    $base = static fn(string $n): string => strtolower(trim(preg_replace('/\s*\[[^\]]*\]|\s+(shoddy|standard|high quality|specialist|masterwork|mk ?[ivx]+)$/i', '', $n) ?? $n));
    $wanted = $base($item);
    foreach (stobeNegBridgeRecords($sinceUnix) as $r) {
        $body = $r['body'];
        if ($command === 'UNEQUIP_ITEM') {
            if (preg_match('/^UNEQUIP_ITEM serial=(\d+) query=(.*?) matched=(.*?) result=(\w+)/', $body, $m) && intval($m[1]) === $serial) {
                // Each term is verified by its own item's result, not by any unequip.
                if ($wanted !== '') {
                    $got = $base($m[3]) !== '' ? $base($m[3]) : $base($m[2]);
                    if ($got === '' || (!str_contains($wanted, $got) && !str_contains($got, $wanted))) continue;
                }
                $result = $m[4];
            } elseif ($wanted === '' && preg_match('/^UNEQUIP_ITEM serial=(\d+) .*result=(\w+)/', $body, $m) && intval($m[1]) === $serial) {
                $result = $m[2];
            }
            continue;
        }
        if (preg_match('/^ACTION_BRIDGE command=' . preg_quote($command, '/') . ' actor=(\d+) .*result=(\w+)/', $body, $m) && intval($m[1]) === $serial) {
            $result = $m[2];
        }
    }
    return $result;
}

// ------------------------------------------------------------------ performance (Phase 1)

/** Queue a KenshiFP bridge command for a character by serial. */
function stobeNegQueueBridgeBySerial(int $serial, string $command, int $targetSerial = 0): bool {
    if ($serial <= 0) return false;
    $path = strval(getenv('STOBE_NEG_ACTION_REQUEST') ?: '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/stobe_action.request');
    $temp = $path . '.tmp.' . strval(getmypid());
    for ($i = 0; $i < 8 && file_exists($path); $i++) usleep(50000); // one-slot mailbox, polled every 50 ms
    $payload = strval($serial) . "\t" . $command . "\t" . strval(max(0, $targetSerial)) . "\t\n";
    if (@file_put_contents($temp, $payload, LOCK_EX) === false) return false;
    if (!@rename($temp, $path)) { @unlink($temp); return false; }
    stobeLogInfo('Queued KenshiFP bridge by serial', ['serial'=>$serial, 'command'=>$command]);
    return true;
}

function stobeNegTermActionToken(array $term, string $player): string {
    $kind = strval($term['kind'] ?? '');
    $item = trim(strval($term['item'] ?? ''));
    $qty = max(1, intval($term['quantity'] ?? 1));
    return match ($kind) {
        'STOP_ATTACK', 'SAFE_PASSAGE' => 'STOP_ATTACK@' . $player,
        'GIVE_CATS' => 'GIVE_CATS@' . $player . '@' . max(1, intval($term['amount'] ?? 0))
            . (in_array(strval($term['purse'] ?? ''), ['topup','exact'], true) && getSettingBool('NEG_CATS_PURSE_MODES', false)
                ? '@' . strval($term['purse']) : ''), // cap tiers: Stobe.dll tops up / pays exactly
        'GIVE_ITEM', 'RETURN_ITEM', 'LOAN_ITEM' => $item !== '' ? 'GIVE_ITEM@' . $player . '@' . $item . '@' . $qty : '',
        'FIRST_AID' => 'FIRST_AID@' . $player,
        'SURRENDER' => 'SURRENDER@' . $player,
        'UNEQUIP_ITEM' => $item !== '' ? 'UNEQUIP_ITEM@' . $item : '',
        'EQUIP_ITEM' => $item !== '' ? 'EQUIP_ITEM@' . $item : '',
        'RELEASE_PRISONER' => trim(strval($term['subject'] ?? '')) !== '' ? 'RELEASE_PRISONER@' . trim(strval($term['subject'])) : '',
        default => '',
    };
}

/** Comparison key for action tokens: case, brackets, spaces and punctuation ignored. */
function stobeNegTokenKey(string $token): string {
    return strtolower(preg_replace('/[^A-Za-z0-9@]+/', '', $token) ?? $token);
}

/**
 * The player openly refuses to pay what they owe ("I'm not gonna pay you").
 * Marks their outstanding terms UNMET so the deal resolves as broken by the
 * player, and returns a note so the NPC reacts to it. '' when not a refusal.
 */
function stobeNegPlayerRefusal(string $npc, string $player, string $message): string {
    try {
        $text = function_exists('stobeNegWordsToNumbers') ? stobeNegWordsToNumbers(strtolower($message)) : strtolower($message);
        $refused = false;
        foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '' || str_ends_with($sentence, '?')) continue;
            if (preg_match("/\\b(he|she|they|you) (said|says|told)\\b/", $sentence)) continue;
            if (preg_match("/\\b(not|never|won'?t|wont|ain'?t|no way|refuse to)\\b[^.!?]{0,25}\\b(pay|paying|give|giving|hand|handing)\\b/", $sentence)
                || preg_match("/\\b(keep|keeping|take|taking) (it|this|that|them|the [a-z ]{1,30}) for free\\b/", $sentence)
                || preg_match("/\\byou(?:'re| are) not getting (paid|anything|a thing|your (cats|money))\\b/", $sentence)) {
                $refused = true;
                break;
            }
        }
        if (!$refused) return '';
        foreach (stobeDealRecentForNpc($npc) as $row) {
            if (strval($row['status'] ?? '') !== 'AWAITING_PERFORMANCE') continue;
            $deal = stobeNegFetchDeal(strval($row['contract_id']));
            if (!$deal) continue;
            $state = stobeNegDecode($deal['term_state'] ?? []);
            $owed = [];
            foreach ($state as $idx => $t) {
                if (($t['by'] ?? '') === 'player' && ($t['status'] ?? '') === 'AWAITING_PLAYER' && ($t['kind'] ?? '') !== 'PROMISE') {
                    $state[$idx]['status'] = 'UNMET';
                    $state[$idx]['evidence'][] = ['at'=>time(), 'note'=>'player_refused_by_voice', 'message'=>truncatePromptValue($message, 160)];
                    $owed[] = ($t['kind'] ?? '') === 'GIVE_CATS' ? intval($t['amount'] ?? 0) . ' Cats' : strval($t['item'] ?? 'item');
                }
            }
            if (count($owed) === 0) continue;
            if (!stobeNegSaveDeal(strval($deal['contract_id']), 'AWAITING_PERFORMANCE', ['term_state'=>$state])) continue;
            stobeLogInfo('Player refused to pay on a deal', ['contract_id'=>$deal['contract_id'], 'npc'=>$npc, 'owed'=>$owed]);
            $deal = stobeNegFetchDeal(strval($deal['contract_id'])) ?? $deal;
            stobeNegTickDeal($deal, $player, time());
            return $player . ' has just openly refused to hand over what they agreed to give you (' . implode(', ', $owed)
                . '). The deal is broken by them, not a gift and not free. Decide right now what you do about it and do it: back a demand with a real threat, take it by force (Attack), or write it off and remember it. Do not just keep talking about it.';
        }
    } catch (Throwable $e) {
        stobeLogInfo('Player refusal check failed', ['npc'=>$npc, 'error'=>$e->getMessage()]);
    }
    return '';
}

function stobeNegDealKind(array $deal): string {
    return strval($deal['kind'] ?? 'combat') ?: 'combat';
}

/** Actions to send with the accepting reply: NPC terms due now, ceasefire first. */
function stobeNegAcceptActions(array $terms, string $player, string $kind): array {
    $actions = [];
    if (in_array($kind, ['combat','surrender'], true)) $actions[] = 'STOP_ATTACK@' . $player;
    foreach ($terms as $term) {
        if (!is_array($term) || strval($term['by'] ?? '') !== 'npc') continue;
        if (strval($term['when'] ?? 'now') === 'after_player') continue;
        $token = stobeNegTermActionToken($term, $player);
        if ($token !== '' && !in_array($token, $actions, true)) $actions[] = $token;
    }
    return $actions;
}

function stobeNegBuildTermState(array $terms, array $dispatched, string $player, int $now, string $kind, int $deadlineUnix): array {
    $state = [];
    foreach (array_values($terms) as $i => $term) {
        if (!is_array($term)) continue;
        $by = strval($term['by'] ?? '');
        $termKind = strval($term['kind'] ?? '');
        $entry = $term + ['i'=>$i, 'status'=>'PENDING', 'dispatched_unix'=>0, 'reissues'=>0, 'evidence'=>[]];
        if ($termKind === 'PROMISE') {
            $entry['status'] = 'RECORDED';
        } elseif ($by === 'npc') {
            $token = stobeNegTermActionToken($term, $player);
            if (strval($term['when'] ?? 'now') === 'after_player') {
                $entry['status'] = 'WAITING_FOR_PLAYER';
            } elseif ($token !== '' && in_array(stobeNegTokenKey($token), array_map('stobeNegTokenKey', $dispatched), true)) {
                $entry['status'] = 'DISPATCHED';
                $entry['dispatched_unix'] = $now;
            } else {
                $entry['status'] = 'IMPOSSIBLE';
                $entry['evidence'][] = ['at'=>$now, 'note'=>$token === '' ? 'no_executor_for_term' : 'action_blocked_by_policy'];
            }
        } else {
            $entry['status'] = 'AWAITING_PLAYER';
            $entry['dispatched_unix'] = $now;
            $entry['deadline_unix'] = $termKind === 'PROTECT' ? $now + STOBE_NEG_PROTECT_DEADLINE_SECONDS : $deadlineUnix;
        }
        $state[] = $entry;
    }
    return $state;
}

/**
 * Called after the accepting reply's actions are final (after policy filtering).
 * Moves ACCEPTED -> AWAITING_PERFORMANCE and records what was actually sent.
 */
function stobeNegBeginPerformance(string $id, array $finalActions, string $player, int $gamets, string $peopleRaw = ''): bool {
    try {
        stobeNegEnsureSchema();
        $deal = stobeNegFetchDeal($id);
        if (!$deal || strval($deal['status']) !== 'ACCEPTED') return false;
        $terms = stobeNegDecode($deal['terms'] ?? []);
        $kind = stobeNegDealKind($deal);
        $now = time();
        $deadlineUnix = in_array($kind, ['social'], true)
            ? $now + 86400 * 7 // real-time safety cap; the game-day deadline below is authoritative
            : $now + STOBE_NEG_PAY_WINDOW_SECONDS;
        // Bug 41: combat windows also run on game time, so a pause doesn't count against the player.
        $deadlineGamets = $kind === 'social' ? max(0, $gamets) + STOBE_NEG_SOCIAL_DEADLINE_GAMETS
            : ($gamets > 0 ? $gamets + STOBE_NEG_PAY_WINDOW_GAMETS : 0);
        $npcRow = stobeNegNpcRow(strval($deal['npc_name']), intval($deal['npc_serial'] ?? 0));
        $playerRow = stobeNegNpcRow($player);
        $baseline = [
            'at'=>$now,
            'gamets'=>$gamets,
            'npc_money'=>stobeNegMoney($npcRow),
            'player_money'=>stobeNegMoney($playerRow, true),
            'npc_inventory'=>stobeNegInventoryCounts(strval($npcRow['inventory'] ?? '') . ', ' . strval($npcRow['equipment'] ?? '')),
            'player_inventory'=>stobeNegInventoryCounts(strval($playerRow['inventory'] ?? '') . ', ' . strval($playerRow['equipment'] ?? '')),
            'witnesses_people'=>$peopleRaw,
        ];
        $state = stobeNegBuildTermState($terms, $finalActions, $player, $now, $kind, $deadlineUnix);
        // Look back for a hand-over made moments before the deal was recorded, but never
        // past the previous deal with this NPC (so one payment cannot count twice).
        $prev = $GLOBALS['db']->fetchOne(
            "SELECT EXTRACT(EPOCH FROM (NOW() - MAX(updated_at)))::int AS age FROM stobe_social_contract
              WHERE LOWER(npc_name)=LOWER($1) AND contract_id<>$2 AND status NOT IN ('PROPOSED','COUNTERED','ACCEPTED')",
            [strval($deal['npc_name']), $id]
        );
        // Payment searches start 3 s before dispatch_unix; keep clear of the previous deal's payment.
        $lookback = min(120, max(0, intval($prev['age'] ?? 120) - 6));
        foreach ($state as $idx => $t) {
            if (($t['by'] ?? '') === 'player' && in_array($t['kind'] ?? '', ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM'], true)) {
                $state[$idx]['dispatched_unix'] = $now - $lookback;
            }
            // Item 96: her hand-over went out with the accepting reply and can land before this start.
            if (($t['by'] ?? '') === 'npc' && ($t['status'] ?? '') === 'DISPATCHED'
                && in_array($t['kind'] ?? '', ['GIVE_CATS','GIVE_ITEM','RETURN_ITEM'], true)) {
                $state[$idx]['evidence_since_unix'] = $now - min(30, $lookback);
            }
        }
        // STOBE's STOP_ATTACK is a faction ceasefire and refuses personal brawls
        // (reason=faction_not_supported); KenshiFP STOP_FIGHT stops this one NPC.
        $serial = stobeNegDealSerial($deal);
        foreach ($state as $idx => $t) {
            if (in_array($t['kind'] ?? '', ['STOP_ATTACK','SAFE_PASSAGE'], true) && ($t['status'] ?? '') === 'DISPATCHED') {
                if (stobeNegQueueBridgeBySerial($serial, 'STOP_FIGHT')) {
                    $state[$idx]['evidence'][] = ['at'=>$now, 'note'=>'individual_disengage_sent'];
                }
                break;
            }
        }
        $betrayal = stobeNegPhaseEnabled(8) ? stobeNegDecideBetrayal($deal, $player, $state) : [];
        $ok = stobeNegSaveDeal($id, 'ACCEPTED', [
            'status'=>'AWAITING_PERFORMANCE',
            'term_state'=>$state,
            'baseline'=>$baseline,
            'betrayal'=>$betrayal,
            'performance_started_unix'=>$now,
            'deadline_unix'=>$deadlineUnix,
            'deadline_gamets'=>$deadlineGamets,
        ]);
        stobeLogInfo('Deal performance started', [
            'contract_id'=>$id, 'npc'=>$deal['npc_name'], 'kind'=>$kind, 'dispatched'=>$finalActions,
            'terms'=>array_map(static fn($t) => ($t['by'] ?? '') . ':' . ($t['kind'] ?? '') . ':' . ($t['status'] ?? ''), $state),
            'betrayal_planned'=>!empty($betrayal['planned']),
        ]);
        return $ok;
    } catch (Throwable $e) {
        stobeLogWarn('Deal performance start failed', ['contract_id'=>$id, 'error'=>$e->getMessage()]);
        return false;
    }
}

// ------------------------------------------------------------------ verification tick

/**
 * Bug 126: a payment that left no execution record (she was knocked out, out of
 * reach) is sent again when she next speaks, up to twice, before IMPOSSIBLE.
 */
function stobeNegReissuePayment(array $term, array $deal, string $player, int $now): array {
    $token = stobeNegTermActionToken($term, $player);
    if ($token === '' || intval($term['payment_reissues'] ?? 0) >= 2 || !stobeNegPhaseEnabled(2)) {
        $term['status'] = 'IMPOSSIBLE';
        $term['evidence'][] = ['at'=>$now, 'note'=>'no_execution_record'];
        return $term;
    }
    $term['payment_reissues'] = intval($term['payment_reissues'] ?? 0) + 1;
    $term['status'] = 'REISSUE_QUEUED';
    $term['evidence'][] = ['at'=>$now, 'note'=>'payment_reissue_queued', 'attempt'=>$term['payment_reissues']];
    stobeNegQueueDirective(strval($deal['npc_name']), 'reissue_payment', strval($deal['contract_id']), [
        'actions'=>[$token],
        'term_indexes'=>[intval($term['i'])],
        'instruction'=>'You agreed to hand this over to ' . $player . ' and have not yet. Do it now, in one short line.',
    ], true);
    return $term;
}

function stobeNegEvaluateTerm(array $term, array $deal, string $player, int $now): array {
    $status = strval($term['status'] ?? '');
    if (in_array($status, ['VERIFIED','IMPOSSIBLE','UNMET','RECORDED','WAITING_FOR_PLAYER','BETRAYED'], true)) return $term;
    $npc = strval($deal['npc_name']);
    $serial = stobeNegDealSerial($deal);
    $kind = strval($term['kind'] ?? '');
    $by = strval($term['by'] ?? '');
    $since = max(0, intval($term['dispatched_unix'] ?? 0) - 3);
    if (isset($term['evidence_since_unix'])) $since = min($since, max(0, intval($term['evidence_since_unix']) - 3)); // Item 96
    $baseline = stobeNegDecode($deal['baseline'] ?? []);
    $note = static function (array &$t, string $text, array $extra = []) use ($now): void {
        $t['evidence'][] = ['at'=>$now, 'note'=>$text] + $extra;
    };

    if ($by === 'npc') {
        if ($status !== 'DISPATCHED') return $term;
        if ($kind === 'UNEQUIP_ITEM') {
            // Handing the item over takes it off: a verified GIVE_ITEM of the same item covers this.
            $itemBase = static fn($s): string => strtolower(trim(preg_replace('/\s*\[[^\]]*\]/', '', strval($s)) ?? ''));
            foreach (stobeNegDecode($deal['term_state'] ?? []) as $other) {
                if (is_array($other) && ($other['kind'] ?? '') === 'GIVE_ITEM' && ($other['by'] ?? '') === 'npc'
                    && ($other['status'] ?? '') === 'VERIFIED' && $itemBase($term['item'] ?? '') !== ''
                    && $itemBase($other['item'] ?? '') === $itemBase($term['item'] ?? '')) {
                    $term['status'] = 'VERIFIED';
                    $note($term, 'satisfied_by_hand_over');
                    return $term;
                }
            }
        }
        $age = $now - intval($term['dispatched_unix']);
        if (in_array($kind, ['STOP_ATTACK','SAFE_PASSAGE'], true)) {
            $record = stobeNegStopAttackRecord($npc, $since);
            $fight = $serial > 0 ? stobeNegBridgeResult('STOP_FIGHT', $serial, $since) : '';
            $factionRefused = $record !== null && !$record['applied'] && !in_array($record['reason'], ['speaker_not_in_combat'], true);
            if ($factionRefused && $fight !== 'ok') {
                if ($fight === '' && $age < 10) return $term; // individual disengage still on its way
                $note($term, 'stop_attack_not_applied', ['reason'=>$record['reason'], 'stop_fight'=>$fight]);
                return stobeNegTruceFailed($term, $deal, $player, $now, 'not_applied:' . $record['reason']);
            }
            // Re-acquisition. Faction mates only count when a faction truce was actually
            // applied; a personal brawl stopped with STOP_FIGHT concerns this NPC alone.
            // If the player's side attacks first, the player broke the ceasefire.
            $npcFaction = ($record !== null && $record['applied']) ? strval(stobeNegNpcRow($npc, $serial)['faction'] ?? '') : '';
            $events = stobeNegCombatEvents(intval($term['dispatched_unix']) + 3);
            usort($events, static fn($x, $y) => $x['ts'] <=> $y['ts']);
            foreach ($events as $ev) {
                // Grace period: the player's squad keeps swinging until the player calls them off.
                if ($ev['ts'] < intval($term['dispatched_unix']) + 10) {
                    if (stobeNegIsPlayerSide($ev['attacker'], $player)) continue;
                }
                if (stobeNegCharMatches($ev['target'], $npc) && stobeNegIsPlayerSide($ev['attacker'], $player)
                    && stobeNegPlayerHarmedNpc($npc, $player, intval($ev['ts']) - 2, intval($ev['ts']) + 8)) { // item 86
                    $term['status'] = 'VOID';
                    $term['player_broke_truce'] = true;
                    $note($term, 'player_resumed_fight', ['attacker'=>$ev['attacker'], 'ts'=>$ev['ts']]);
                    return $term;
                }
                if (!stobeNegIsPlayerSide($ev['target'], $player)) continue;
                $attackerMatches = stobeNegCharMatches($ev['attacker'], $npc);
                if (!$attackerMatches && $npcFaction !== '') {
                    $attackerRow = stobeNegNpcRow($ev['attacker']);
                    $attackerMatches = strval($attackerRow['faction'] ?? '') === $npcFaction && !stobeNegIsPlayerSide($ev['attacker'], $player);
                }
                if ($attackerMatches) {
                    $note($term, 'attack_resumed', ['attacker'=>$ev['attacker'], 'target'=>$ev['target'], 'ts'=>$ev['ts']]);
                    return stobeNegTruceFailed($term, $deal, $player, $now, 'reacquired');
                }
            }
            $window = $kind === 'SAFE_PASSAGE' ? STOBE_NEG_SAFE_PASSAGE_SECONDS : STOBE_NEG_TRUCE_OBSERVE_SECONDS;
            if ($age >= $window) {
                $term['status'] = 'VERIFIED';
                $note($term, 'no_attacks_observed', ['window_seconds'=>$window, 'execution'=>$record]);
            }
            return $term;
        }
        if ($kind === 'GIVE_CATS') {
            $exec = stobeNegCatsExecuted($npc, $player, $since);
            if ($exec['amount'] >= intval($term['amount'] ?? 0)) {
                $term['status'] = 'VERIFIED';
                $note($term, 'game_executed_transfer', ['amount'=>$exec['amount']]);
            } elseif ($exec['skipped'] !== '') {
                $term['status'] = 'IMPOSSIBLE';
                $note($term, 'game_refused_transfer', ['reason'=>$exec['skipped']]);
            } elseif ($age >= STOBE_NEG_EXECUTION_TIMEOUT_SECONDS) {
                $term = stobeNegReissuePayment($term, $deal, $player, $now); // bug 126
            }
            return $term;
        }
        if (in_array($kind, ['GIVE_ITEM','RETURN_ITEM','LOAN_ITEM'], true)) {
            $qty = max(1, intval($term['quantity'] ?? 1));
            $exec = stobeNegItemExecuted($npc, $player, strval($term['item'] ?? ''), $since);
            if ($exec['transferred'] >= $qty) {
                $term['status'] = 'VERIFIED';
                $note($term, 'game_executed_item_transfer', ['transferred'=>$exec['transferred']]);
            } elseif ($exec['blocked'] !== '') {
                $term['status'] = 'IMPOSSIBLE';
                $note($term, 'game_blocked_item_transfer', ['reason'=>$exec['blocked']]);
            } elseif ($age >= STOBE_NEG_EXECUTION_TIMEOUT_SECONDS) {
                $term = stobeNegReissuePayment($term, $deal, $player, $now); // bug 126
            }
            return $term;
        }
        $bridge = ['FIRST_AID'=>'FIRST_AID','SURRENDER'=>'SURRENDER','RELEASE_PRISONER'=>'RELEASE_PRISONER','UNEQUIP_ITEM'=>'UNEQUIP_ITEM','EQUIP_ITEM'=>'EQUIP_ITEM'];
        if (isset($bridge[$kind])) {
            $result = $serial > 0 ? stobeNegBridgeResult($bridge[$kind], $serial, $since, $kind === 'UNEQUIP_ITEM' ? strval($term['item'] ?? '') : '') : '';
            if ($result === 'ok') {
                $term['status'] = 'VERIFIED';
                $note($term, 'bridge_result_ok');
            } elseif ($result !== '' || $age >= STOBE_NEG_EXECUTION_TIMEOUT_SECONDS) {
                $term['status'] = 'IMPOSSIBLE';
                $note($term, $result !== '' ? 'bridge_result_' . $result : 'no_execution_record');
            }
        }
        return $term;
    }

    // Player-side obligations.
    if ($status !== 'AWAITING_PLAYER') return $term;
    $start = intval($term['dispatched_unix'] ?? 0);
    $deadline = intval($term['deadline_unix'] ?? 0);
    $gametsDeadline = intval($deal['deadline_gamets'] ?? 0);
    if ($kind === 'GIVE_CATS') {
        $need = intval($term['amount'] ?? 0);
        $before = max(0, intval($term['cats_before'] ?? 0)); // earlier installments take the first Cats
        $exec = stobeNegCatsExecuted($player, $npc, $start - 3, intval($serial)); // item 49
        $money = stobeNegMoney(stobeNegNpcRow($npc, $serial));
        $base = $baseline['npc_money'] ?? ['known'=>false];
        $delta = (!empty($base['known']) && $money['known'] && $money['observed_at'] > $start)
            ? $money['value'] - intval($base['value']) : 0;
        $exec['amount'] = max(0, $exec['amount'] - $before);
        $delta = max(0, $delta - $before);
        if ($exec['amount'] >= $need || $delta >= $need) {
            $term['status'] = 'VERIFIED';
            $note($term, 'player_paid', ['executed'=>$exec['amount'], 'npc_money_delta'=>$delta]);
            return $term;
        }
        $term['paid_so_far'] = max($exec['amount'], $delta);
    } elseif (in_array($kind, ['GIVE_ITEM','RETURN_ITEM'], true)) {
        $qty = max(1, intval($term['quantity'] ?? 1));
        $item = strval($term['item'] ?? '');
        $exec = stobeNegItemExecuted($player, $npc, $item, $start - 3, intval($serial)); // item 49
        $row = stobeNegNpcRow($npc, $serial);
        $nowCount = stobeNegItemCount(stobeNegInventoryCounts(strval($row['inventory'] ?? '') . ', ' . strval($row['equipment'] ?? '')), $item);
        $baseCount = stobeNegItemCount(is_array($baseline['npc_inventory'] ?? null) ? $baseline['npc_inventory'] : [], $item);
        if ($exec['transferred'] >= $qty || ($nowCount - $baseCount) >= $qty) {
            $term['status'] = 'VERIFIED';
            $note($term, 'player_delivered_item', ['executed'=>$exec['transferred'], 'npc_count_delta'=>$nowCount - $baseCount]);
            return $term;
        }
    } elseif ($kind === 'FIRST_AID') {
        // "Shay: is using (Basic First Aid Kit) to heal Senlin" -- the player or their squad,
        // counting healing done shortly before the deal was struck.
        $rows = $GLOBALS['db']->fetchAll(
            "SELECT data FROM eventlog WHERE type='healing' AND localts >= $1 AND data LIKE $2 ORDER BY localts DESC LIMIT 20",
            [$start - 120, '%heal ' . $npc . '%']
        );
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (preg_match('/^(.+?):\s*is using .* to heal (.+)$/', trim(strval($r['data'] ?? '')), $hm)
                && stobeNegCharMatches(trim($hm[2]), $npc) && stobeNegIsPlayerSide(trim($hm[1]), $player)) {
                $term['status'] = 'VERIFIED';
                $note($term, 'player_healed_npc', ['healer'=>trim($hm[1])]);
                return $term;
            }
        }
    } elseif ($kind === 'SPARE') {
        foreach (stobeNegCombatEvents($start + 3, $npc) as $ev) {
            // Bug 127: the same 10 s as the truce for the squad to stop swinging.
            if (intval($ev['ts'] ?? 0) < $start + 10) continue;
            if (stobeNegCharMatches($ev['target'], $npc) && stobeNegIsPlayerSide($ev['attacker'], $player)
                && stobeNegPlayerHarmedNpc($npc, $player, intval($ev['ts'] ?? 0) - 2, intval($ev['ts'] ?? 0) + 8)) { // item 86
                $term['status'] = 'UNMET';
                $note($term, 'player_side_attacked_after_sparing', ['attacker'=>$ev['attacker']]);
                return $term;
            }
        }
        if ($now - $start >= STOBE_NEG_TRUCE_OBSERVE_SECONDS) {
            $term['status'] = 'VERIFIED';
            $note($term, 'no_player_attacks_observed');
        }
        return $term;
    } elseif ($kind === 'PROTECT') {
        if (stobeNegNpcDied($npc, $start)) {
            $term['status'] = 'UNMET';
            $note($term, 'protected_npc_died');
            return $term;
        }
        $recent = stobeNegCombatEvents($now - STOBE_NEG_PROTECT_QUIET_SECONDS, $npc);
        if ($now - $start >= STOBE_NEG_PROTECT_QUIET_SECONDS && count($recent) === 0) {
            $term['status'] = 'VERIFIED';
            $note($term, 'fight_over_npc_alive');
            return $term;
        }
    }
    $expired = ($deadline > 0 && $now > $deadline);
    if ($gametsDeadline > 0 && stobeNegDealKindIsHostile($deal)) {
        // Bug 41: both clocks must run out (real time AND game time); a paused game stops the window.
        if ($expired) {
            $latest = stobeNegLatestGamets();
            $expired = $latest > 0 ? $latest > $gametsDeadline : ($now > $deadline + STOBE_NEG_PAY_WINDOW_REAL_CAP);
        }
    } elseif (!$expired && $gametsDeadline > 0) {
        $latest = stobeNegLatestGamets();
        $expired = $latest > 0 && $latest > $gametsDeadline;
    }
    if ($expired) {
        $term['status'] = 'UNMET';
        $note($term, 'deadline_passed');
    }
    return $term;
}

function stobeNegLatestGamets(): int {
    $row = $GLOBALS['db']->fetchOne("SELECT MAX(gamets) AS g FROM eventlog WHERE localts >= $1", [time() - 600]);
    return intval($row['g'] ?? 0);
}

/** Phase 2: a truce that did not hold is re-issued a bounded number of times. */
function stobeNegTruceFailed(array $term, array $deal, string $player, int $now, string $why): array {
    if (stobeNegPhaseEnabled(2) && intval($term['reissues'] ?? 0) < STOBE_NEG_TRUCE_MAX_REISSUE
        && stobeNegQueueBridgeBySerial(intval($deal['npc_serial'] ?? 0), 'STOP_FIGHT')) {
        $term['reissues'] = intval($term['reissues'] ?? 0) + 1;
        $term['status'] = 'DISPATCHED';
        $term['dispatched_unix'] = $now;
        $term['evidence'][] = ['at'=>$now, 'note'=>'truce_reissued_individual', 'why'=>$why, 'attempt'=>$term['reissues']];
        return $term;
    }
    if (stobeNegPhaseEnabled(2) && intval($term['reissues'] ?? 0) < STOBE_NEG_TRUCE_MAX_REISSUE) {
        $term['reissues'] = intval($term['reissues'] ?? 0) + 1;
        $term['status'] = 'REISSUE_QUEUED';
        $term['evidence'][] = ['at'=>$now, 'note'=>'truce_reissue_queued', 'why'=>$why, 'attempt'=>$term['reissues']];
        stobeNegQueueDirective(strval($deal['npc_name']), 'reissue_truce', strval($deal['contract_id']), [
            'actions'=>['STOP_ATTACK@' . $player],
            'term_indexes'=>[intval($term['i'])],
            'instruction'=>'You agreed to stop fighting ' . $player . '. Keep your word now: call off the fight in one short line.',
        ], true);
        return $term;
    }
    $term['status'] = 'IMPOSSIBLE';
    $term['evidence'][] = ['at'=>$now, 'note'=>'truce_did_not_hold', 'why'=>$why];
    return $term;
}

function stobeNegTickDeal(array $deal, string $player, int $now): void {
    $id = strval($deal['contract_id']);
    $state = stobeNegDecode($deal['term_state'] ?? []);
    if (count($state) === 0) return;
    $betrayal = stobeNegDecode($deal['betrayal'] ?? []);
    $changed = false;
    // Installments: each player GIVE_CATS term is covered only by Cats beyond the earlier ones,
    // all measured from the first installment's start.
    $catsBefore = 0;
    $firstStart = 0;
    foreach ($state as $idx => $term) {
        if (($term['by'] ?? '') !== 'player' || ($term['kind'] ?? '') !== 'GIVE_CATS') continue;
        if ($firstStart === 0) $firstStart = intval($term['dispatched_unix'] ?? 0);
        $state[$idx]['cats_before'] = $catsBefore;
        if ($firstStart > 0) $state[$idx]['dispatched_unix'] = $firstStart;
        $catsBefore += intval($term['amount'] ?? 0);
    }
    foreach ($state as $idx => $term) {
        $before = json_encode($term);
        $state[$idx] = stobeNegEvaluateTerm($term, $deal, $player, $now);
        if (json_encode($state[$idx]) !== $before) $changed = true;
    }

    $playerTerms = array_filter($state, static fn($t) => ($t['by'] ?? '') === 'player' && ($t['kind'] ?? '') !== 'PROMISE'
        && strval($t['when'] ?? '') !== 'after_npc');
    $playerDone = count($playerTerms) === 0 || count(array_filter($playerTerms, static fn($t) => ($t['status'] ?? '') === 'VERIFIED')) === count($playerTerms);
    $playerFailed = count(array_filter($playerTerms, static fn($t) => ($t['status'] ?? '') === 'UNMET')) > 0;

    // NPC "after the player pays" terms: dispatch, or betray (Phase 8).
    if ($playerDone && !$playerFailed) {
        $waiting = [];
        foreach ($state as $idx => $term) {
            if (($term['status'] ?? '') === 'WAITING_FOR_PLAYER') $waiting[] = $idx;
        }
        $sinceStart = $now - intval($deal['performance_started_unix'] ?? 0);
        if (!empty($betrayal['planned']) && empty($betrayal['executed']) && $sinceStart < 20) {
            // Wait: KenshiFP keeps the NPC disengaged for ~15 s after STOP_FIGHT.
        } elseif (!empty($betrayal['planned']) && empty($betrayal['executed'])) {
            foreach ($waiting as $idx) {
                $state[$idx]['status'] = 'BETRAYED';
                $state[$idx]['evidence'][] = ['at'=>$now, 'note'=>'intentional_betrayal'];
            }
            $betrayal['executed'] = $now;
            $actions = stobeNegDealKindIsHostile($deal) ? ['ATTACK@' . $player] : [];
            stobeNegQueueDirective(strval($deal['npc_name']), 'betray', $id, [
                'actions'=>$actions,
                'instruction'=>'You got what you wanted from ' . $player . ' and never meant to hold up your end. Act on that now, in character, in one or two short lines.',
            ], true);
            $changed = true;
        } elseif (count($waiting) > 0) {
            $actions = [];
            foreach ($waiting as $idx) {
                $state[$idx]['settle_attempts'] = intval($state[$idx]['settle_attempts'] ?? 0) + 1;
                if ($state[$idx]['settle_attempts'] > 3) {
                    $state[$idx]['status'] = 'IMPOSSIBLE';
                    $state[$idx]['evidence'][] = ['at'=>$now, 'note'=>'settle_never_delivered'];
                    continue;
                }
                $token = stobeNegTermActionToken($state[$idx], $player);
                if ($token === '') {
                    $state[$idx]['status'] = 'IMPOSSIBLE';
                    $state[$idx]['evidence'][] = ['at'=>$now, 'note'=>'no_executor_for_term'];
                    continue;
                }
                $actions[] = $token;
                $state[$idx]['status'] = 'SETTLE_QUEUED';
            }
            $queuedIdx = array_values(array_filter($waiting, static fn($i) => ($state[$i]['status'] ?? '') === 'SETTLE_QUEUED'));
            if (count($actions) > 0) {
                stobeNegQueueDirective(strval($deal['npc_name']), 'settle', $id, [
                    'actions'=>$actions,
                    'term_indexes'=>$queuedIdx,
                    'instruction'=>$player . ' has done their part. Hand over what you promised now, in one short line.',
                ], true);
            }
            $changed = true;
        }
    }

    // Queued dispatches that never found a channel go back to waiting (retry next tick).
    foreach ($state as $idx => $term) {
        if (in_array($term['status'] ?? '', ['SETTLE_QUEUED','REISSUE_QUEUED'], true) && !stobeNegDirectivePending($id, intval($term['i'] ?? $idx))) {
            if (($term['status'] ?? '') === 'REISSUE_QUEUED') {
                $state[$idx]['status'] = 'IMPOSSIBLE';
                $state[$idx]['evidence'][] = ['at'=>$now, 'note'=>isset($term['payment_reissues']) ? 'payment_reissue_never_delivered' : 'truce_reissue_never_delivered'];
            } else {
                $state[$idx]['status'] = 'WAITING_FOR_PLAYER';
            }
            $changed = true;
        }
    }

    $final = stobeNegResolveStatus($state, $betrayal);
    $fields = ['term_state'=>$state, 'betrayal'=>$betrayal];
    if ($final !== '') {
        $fields['status'] = $final;
        $fields['resolved_at'] = true; // database clock, see stobeNegSaveDeal
    } elseif (!$changed) {
        return;
    }
    if (!stobeNegSaveDeal($id, 'AWAITING_PERFORMANCE', $fields)) return;
    if ($final !== '') {
        stobeLogInfo('Deal resolved', [
            'contract_id'=>$id, 'npc'=>$deal['npc_name'], 'status'=>$final,
            'terms'=>array_map(static fn($t) => ($t['by'] ?? '') . ':' . ($t['kind'] ?? '') . ':' . ($t['status'] ?? ''), $state),
        ]);
        $deal['status'] = $final;
        $deal['term_state'] = $state;
        $deal['betrayal'] = $betrayal;
        stobeNegApplyConsequences($deal, $player);
    }
}

function stobeNegDealKindIsHostile(array $deal): bool {
    return in_array(stobeNegDealKind($deal), ['combat','surrender'], true);
}

/** Final status from per-term state, or '' while anything is still in flight. */
function stobeNegResolveStatus(array $state, array $betrayal): string {
    // An executed betrayal ends the deal at once; later attacks are the betrayal, not a failed truce.
    if (!empty($betrayal['executed'])) return 'BREACHED_NPC';
    $required = array_values(array_filter($state, static fn($t) => ($t['status'] ?? '') !== 'RECORDED'));
    if (count($required) === 0) return 'COMPLETE';
    $statuses = array_map(static fn($t) => strval($t['status'] ?? ''), $required);
    if (in_array('BETRAYED', $statuses, true)) return 'BREACHED_NPC';
    foreach ($required as $t) {
        if (!empty($t['player_broke_truce'])) return 'BREACHED_PLAYER';
    }
    $playerUnmet = false;
    foreach ($required as $t) {
        if (($t['by'] ?? '') === 'player' && ($t['status'] ?? '') === 'UNMET') $playerUnmet = true;
    }
    $inFlight = array_intersect($statuses, ['PENDING','DISPATCHED','AWAITING_PLAYER','WAITING_FOR_PLAYER','SETTLE_QUEUED','REISSUE_QUEUED']);
    // The NPC can't do their part: the deal fails (anything paid is refunded), and an
    // unpaid or refused player side is not the player's breach.
    $npcImpossible = false;
    foreach ($required as $t) {
        if (($t['by'] ?? '') === 'npc' && ($t['status'] ?? '') === 'IMPOSSIBLE') $npcImpossible = true;
    }
    if ($npcImpossible) {
        $npcInFlight = array_filter($required, static fn($t) => ($t['by'] ?? '') === 'npc' && in_array($t['status'] ?? '', ['DISPATCHED','REISSUE_QUEUED'], true));
        return count($npcInFlight) > 0 ? '' : 'IMPOSSIBLE';
    }
    if ($playerUnmet) {
        // Wait for NPC actions already in flight so evidence is complete, then call it.
        $npcInFlight = array_filter($required, static fn($t) => ($t['by'] ?? '') === 'npc' && in_array($t['status'] ?? '', ['DISPATCHED','REISSUE_QUEUED'], true));
        return count($npcInFlight) > 0 ? '' : 'BREACHED_PLAYER';
    }
    if (count($inFlight) > 0) return '';
    if (in_array('IMPOSSIBLE', $statuses, true)) return 'IMPOSSIBLE';
    return 'COMPLETE';
}

/**
 * The NPC announces putting on / taking off clothing ("let me put the sandals back on")
 * but sent no EQUIP/UNEQUIP action. Returns the one action that fits, or ''.
 */
function stobeInferClothingAction(string $text, array|false $npcData, array $actions): string {
    if (!is_array($npcData) || trim($text) === '') return '';
    foreach ($actions as $action) {
        if (preg_match('/^(UN)?EQUIP_ITEM@/i', strval($action))) return '';
    }
    $lower = strtolower($text);
    $sentence = '';
    $mode = '';
    foreach (preg_split('/(?<=[.!?])\s+/', $lower) ?: [] as $s) {
        $on = preg_match("/\b(put|putting|get|getting|strap|strapping|pull|pulling|slip|slipping)\b[^.!?]{0,40}\bon\b/", $s);
        $off = preg_match("/\b(take|taking|get|getting|pull|pulling|strip|stripping|slip|slipping)\b[^.!?]{0,40}\boff\b/", $s);
        if ($on xor $off) {
            // Negated, conditional or later: not an action now.
            if (preg_match("/\b(not|never|won'?t|don'?t|can'?t|if|unless|until|once|when|after|first|then|later|pay)\b/", $s)) return '';
            if ($mode !== '' && $mode !== ($on ? 'equip' : 'unequip')) return '';
            $mode = $on ? 'equip' : 'unequip';
            $sentence .= ' ' . $s;
        }
    }
    if ($mode === '') return '';
    $source = strval($mode === 'equip' ? ($npcData['inventory'] ?? '') : ($npcData['equipment'] ?? ''));
    $names = function_exists('stobeNegInventoryDisplayNames') ? stobeNegInventoryDisplayNames($source) : [];
    $synonyms = ['hat'=>['hat','helm','cap','zukin','hood'], 'shoes'=>['sandal','boot','shoe'], 'sandals'=>['sandal'],
        'boots'=>['boot'], 'shirt'=>['shirt'], 'vest'=>['rag shirt','vest'], 'pants'=>['pants','shorts','trousers'],
        'shorts'=>['shorts'], 'trousers'=>['trousers','pants'], 'coat'=>['coat'], 'gloves'=>['glove'], 'mask'=>['mask']];
    $best = [];
    $bestScore = 0;
    foreach ($names as $base => $display) {
        $score = 0;
        foreach (preg_split('/[^a-z]+/', $base) ?: [] as $word) {
            if (strlen($word) >= 4 && preg_match('/\b' . preg_quote($word, '/') . 's?\b/', $sentence)) $score += 2;
        }
        foreach ($synonyms as $said => $matches) {
            if (!preg_match('/\b' . $said . '\b/', $sentence)) continue;
            foreach ($matches as $m) {
                if (str_contains($base, $m)) { $score += 1; break; }
            }
        }
        if (preg_match('/\b(katana|sword|sabre|saber|blade|knife|bow|crossbow|club|hammer|spear|axe|polearm|nodachi|wakizashi|machete)\b/', $base)) $score = 0;
        if ($score > $bestScore) { $best = [$display]; $bestScore = $score; }
        elseif ($score > 0 && $score === $bestScore) { $best[] = $display; }
    }
    if (count($best) !== 1) return '';
    $item = trim(preg_replace('/\s*\[[^\]]*\]/', '', strval($best[0])) ?? strval($best[0]));
    return ($mode === 'equip' ? 'EQUIP_ITEM@' : 'UNEQUIP_ITEM@') . $item;
}

/** Run verification for open deals. Cheap when nothing is open; throttled by caller. */
function stobeNegTick(string $onlyNpc = ''): void {
    stobeNegEnsureSchema();
    $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $now = time();
    $params = [];
    $where = "status='AWAITING_PERFORMANCE'";
    if ($onlyNpc !== '') {
        $params[] = $onlyNpc;
        $where .= ' AND LOWER(npc_name)=LOWER($1)';
    }
    $rows = $GLOBALS['db']->fetchAll("SELECT * FROM stobe_social_contract WHERE " . $where . " ORDER BY updated_at LIMIT 20", $params);
    foreach (is_array($rows) ? $rows : [] as $deal) {
        try {
            // Item 100: action targets go to the character the deal was made with (deal player_name)
            $dealPlayer = normalizeParticipantNameToken(strval($deal['player_name'] ?? ''));
            stobeNegTickDeal($deal, $dealPlayer !== '' ? $dealPlayer : $player, $now);
        } catch (Throwable $e) {
            stobeLogWarn('Deal tick failed', ['contract_id'=>$deal['contract_id'] ?? '', 'error'=>$e->getMessage()]);
        }
    }
    // Item 48: an offer waits while its NPC is knocked out (it resumes when they wake).
    $quiet = $GLOBALS['db']->fetchAll(
        "SELECT contract_id, npc_name FROM stobe_social_contract
          WHERE status IN ('PROPOSED','COUNTERED') AND updated_at < NOW() - INTERVAL '10 minutes' LIMIT 20"
    );
    foreach (is_array($quiet) ? $quiet : [] as $q) {
        if (stobeNegNpcOutState(strval($q['npc_name'] ?? '')) === 'unconscious') {
            $GLOBALS['db']->exec("UPDATE stobe_social_contract SET updated_at=NOW() - INTERVAL '9 minutes' WHERE contract_id=$1", [strval($q['contract_id'])]);
        }
    }
    // Bargaining that went quiet expires (Phase 3), accepted-but-unstarted deals are cleaned up.
    $GLOBALS['db']->exec(
        "UPDATE stobe_social_contract SET status='EXPIRED', resolved_at=NOW(), updated_at=NOW()
          WHERE status IN ('PROPOSED','COUNTERED') AND updated_at < NOW() - INTERVAL '10 minutes'"
    );
    $GLOBALS['db']->exec(
        "UPDATE stobe_social_contract SET status='IMPOSSIBLE', resolved_at=NOW(), updated_at=NOW(),
                evidence = (CASE WHEN jsonb_typeof(evidence)='object' THEN evidence ELSE '{}'::jsonb END) || '{\"note\":\"accepted_but_never_dispatched\"}'::jsonb
          WHERE status='ACCEPTED' AND updated_at < NOW() - INTERVAL '2 minutes'"
    );
}

/**
 * Throttle marker, one per OS user: a marker left by a CLI/test run as root must
 * never block the web server (www-data) from updating it, or the throttle stops
 * working and every game event pays for a full negotiation scan.
 */
function stobeNegThrottleMarker(string $name): string {
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    return sys_get_temp_dir() . '/stobe_negotiation_' . $name . '.' . intval($uid) . '.ts';
}

/** Deal context that makes an NPC's hand-over to the player legitimate (not an unpaid gift). */
function stobeNegNpcHasDealContext(string $npc): bool {
    static $cache = [];
    $key = strtolower(trim($npc));
    if ($key === '') return false;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $row = $GLOBALS['db']->fetchOne(
            "SELECT 1 AS hit FROM stobe_social_contract WHERE LOWER(npc_name)=LOWER($1)
               AND (status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')
                    OR COALESCE(resolved_at, updated_at) > NOW() - INTERVAL '10 minutes') LIMIT 1",
            [$npc]
        );
        $hit = is_array($row);
        if (!$hit) {
            $dir = $GLOBALS['db']->fetchOne(
                "SELECT 1 AS hit FROM stobe_negotiation_directive WHERE LOWER(npc_name)=LOWER($1) AND consumed_unix=0 LIMIT 1", [$npc]);
            $hit = is_array($dir);
        }
    } catch (Throwable $e) {
        $hit = false;
    }
    if ($hit) $cache[$key] = true; // only positive answers are stable within a request
    return $hit;
}

/**
 * Personal fights: when an NPC attacks the player over a private matter (their own
 * chat decision, not a call for help), faction-mates who pile in are stood down so
 * a grudge does not turn the whole bar hostile. Toggle PERSONAL_FIGHTS (default on).
 */
/**
 * Bug 96: does this player line accept an open offer? Clear acceptance words
 * anywhere, or a short yes-type reply; never a conversation-ender ("we're done").
 */
function stobeNegLooksLikeAcceptance(string $text): bool {
    $text = strtolower(trim($text));
    if ($text === '' || str_ends_with($text, '?')) return false;
    if (preg_match("/\b(no deal|not|don'?t|won'?t|never|nah|no way)\b/", $text)) return false;
    if (preg_match("/\b(we'?re|we are|i'?m|i am) done\b|\bdone (here|talking|with)\b|\bthat'?s (it|enough)\b/", $text)) return false;
    if (preg_match("/\b(deal|agreed|agree|accept|accepted|you got it|you'?re on)\b/", $text)) return true;
    $words = array_values(array_filter(preg_split('/\s+/', trim(preg_replace("/[^a-z0-9' ]+/", ' ', $text))) ?: []));
    return count($words) > 0 && count($words) <= 4
        && preg_match("/\b(yes|yeah|yep|sure|ok|okay|fine|done)\b/", $text) === 1;
}

function stobeNegPlayerAcceptsOfferNote(string $npc, string $message): string {
    try {
        $open = stobeDealOpenForNpc($npc);
        if (!is_array($open) || !in_array(strval($open['status'] ?? ''), ['PROPOSED','COUNTERED'], true)) return '';
        $text = function_exists('stobeNegWordsToNumbers') ? stobeNegWordsToNumbers(strtolower(trim($message))) : strtolower(trim($message));
        if (!stobeNegLooksLikeAcceptance($text)) return ''; // bug 96
        $terms = json_decode(strval($open['terms'] ?? '[]'), true);
        $terms = is_array($terms) ? $terms : [];
        $amounts = [];
        $parts = [];
        foreach ($terms as $t) {
            if (!is_array($t)) continue;
            if (isset($t['amount'])) $amounts[] = intval($t['amount']);
            $parts[] = strval($t['by'] ?? '') . ' ' . strtolower(str_replace('_', ' ', strval($t['kind'] ?? '')))
                . (isset($t['amount']) ? ' ' . intval($t['amount']) . ' Cats' : '')
                . (!empty($t['item']) ? ' ' . strval($t['item']) : '');
        }
        if (count($parts) === 0) return '';
        preg_match_all('/\b\d{1,9}\b/', $text, $nums);
        foreach ($nums[0] as $n) {
            if (!in_array(intval($n), $amounts, true)) return ''; // a different number is a counter, not acceptance
        }
        return 'The player has just accepted your current offer (' . implode('; ', $parts) . '). That is binding: answer with deal_decision ACCEPT and exactly these terms, and act on them now. Do not raise the price or add new conditions.';
    } catch (Throwable $e) {
        return '';
    }
}

function stobeNegPersonalFightsEnabled(): bool {
    return function_exists('getSettingBool') ? getSettingBool('PERSONAL_FIGHTS', true) : true;
}

/** The NPC's line calls her friends into the fight (J3: then they may join). */
function stobeNegCalledForHelp(string $replyText): bool {
    return preg_match("/\b(help me|get (him|her|them)|guards?|boys|lads|friends|everyone|all of you|grab (him|her|them))\b/i", $replyText) === 1;
}

function stobeNegRegisterPersonalFight(string $npc, array $npcData, string $replyText): void {
    if (!stobeNegPersonalFightsEnabled()) return;
    if (stobeNegCalledForHelp($replyText)) {
        stobeLogInfo('Personal fight not registered: NPC called for help', ['npc'=>$npc]);
        return;
    }
    $faction = trim(strval($npcData['faction'] ?? ''));
    $fights = json_decode(strval(getConfOpt('STOBE_PERSONAL_FIGHTS', '{}')), true);
    $fights = is_array($fights) ? $fights : [];
    foreach ($fights as $k => $f) {
        if (time() - intval($f['since'] ?? 0) > 180) unset($fights[$k]);
    }
    $fights[strtolower($npc)] = ['npc'=>$npc, 'faction'=>$faction, 'since'=>time(), 'stood_down'=>[]];
    setConfOpt('STOBE_PERSONAL_FIGHTS', json_encode($fights), true);
    stobeLogInfo('Personal fight registered', ['npc'=>$npc, 'faction'=>$faction]);
}

function stobeNegPersonalFightJoiner(string $eventData): void {
    if (!stobeNegPersonalFightsEnabled()) return;
    if (!preg_match('/^(.+?):\s*Initiated attack\s*\(talking to:\s*(.+?)\)/', trim($eventData), $m)) return;
    $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $attacker = normalizeParticipantNameToken($m[1]);
    if (!stobeNegIsPlayerSide(normalizeParticipantNameToken($m[2]), $player) || stobeNegIsPlayerSide($attacker, $player)) return;
    $raw = strval(getConfOpt('STOBE_PERSONAL_FIGHTS', '{}'));
    if ($raw === '{}' || $raw === '' || $raw === '[]') return;
    $fights = json_decode($raw, true);
    if (!is_array($fights) || count($fights) === 0) return;
    $row = stobeNegNpcRow($attacker);
    $faction = trim(strval($row['faction'] ?? ''));
    $changed = false;
    foreach ($fights as $k => $f) {
        if (time() - intval($f['since'] ?? 0) > 180) { unset($fights[$k]); $changed = true; continue; }
        if (stobeNegCharMatches($attacker, strval($f['npc'] ?? ''))) return; // the grudge holder herself
        if ($faction === '' || strcasecmp($faction, strval($f['faction'] ?? '')) !== 0) continue;
        $last = intval($f['stood_down'][strtolower($attacker)] ?? 0);
        if (time() - $last < 20) continue;
        $serial = stobeNegSerialFromStorage($attacker);
        if ($serial > 0 && stobeNegQueueBridgeBySerial($serial, 'STOP_FIGHT')) {
            $fights[$k]['stood_down'][strtolower($attacker)] = time();
            $changed = true;
            stobeLogInfo('Personal fight: faction-mate stood down', ['fight'=>$f['npc'], 'joiner'=>$attacker, 'faction'=>$faction]);
        }
    }
    if ($changed) setConfOpt('STOBE_PERSONAL_FIGHTS', json_encode($fights), true);
}

function stobeNegTickThrottled(string $eventType = '', string $eventData = '', string $peopleRaw = '', int $gamets = 0): void {
    $marker = stobeNegThrottleMarker('tick');
    $last = @filemtime($marker);
    if (!is_int($last) || (time() - $last) >= 2) {
        @touch($marker);
        stobeNegTick();
    }
    $type = strtolower(trim($eventType));
    if ($type === 'recovered') {
        try { stobeNegResumeAfterKnockout($eventData); } catch (Throwable $e) {} // item 48
    }
    if ($type === 'combat') {
        try { stobeNegPersonalFightJoiner($eventData); } catch (Throwable $e) {}
        if (function_exists('stobeRelationshipOnAttack')) {
            try { stobeRelationshipOnAttack($eventData); } catch (Throwable $e) {} // R4: fights count
        }
    }
    if (in_array($type, ['combat','major_damage','knockout','combat_start'], true)) {
        stobeNegConsiderInitiatives($type, $eventData, $peopleRaw, $gamets);
    }
}

// ------------------------------------------------------------------ directives (NPC-initiated speech / dispatch)

function stobeNegQueueDirective(string $npc, string $kind, string $contractId, array $payload, bool $raiseInitiative): void {
    stobeNegEnsureSchema();
    $GLOBALS['db']->exec(
        "INSERT INTO stobe_negotiation_directive (npc_name,kind,contract_id,payload,created_unix) VALUES ($1,$2,$3,$4::jsonb,$5)",
        [$npc, $kind, $contractId, json_encode($payload, JSON_UNESCAPED_UNICODE), time()]
    );
    stobeLogInfo('Negotiation directive queued', ['npc'=>$npc, 'kind'=>$kind, 'contract_id'=>$contractId, 'actions'=>$payload['actions'] ?? []]);
    if ($raiseInitiative && getenv('STOBE_NEG_TEST_NO_SIGNAL') !== '1' && function_exists('stobeLifelikeSignalRuntime')) {
        stobeLifelikeSignalRuntime('initiative', 'negotiation_' . $kind, 0);
    }
}

function stobeNegDirectivePending(string $contractId, int $termIndex): bool {
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT payload FROM stobe_negotiation_directive WHERE contract_id=$1
            AND (consumed_unix=0 OR (consumed_unix >= $4::bigint AND outcome=''))
            AND created_unix >= (CASE kind WHEN 'reissue_payment' THEN $3::bigint ELSE $2::bigint END)",
        // Claimed by the bored path but not delivered yet: still in flight (bug 131).
        [$contractId, time() - STOBE_NEG_DIRECTIVE_TTL_SECONDS, time() - 600, time() - 60]
    );
    foreach (is_array($rows) ? $rows : [] as $row) {
        $payload = stobeNegDecode($row['payload'] ?? []);
        if (in_array($termIndex, array_map('intval', $payload['term_indexes'] ?? []), true)) return true;
    }
    return false;
}

/**
 * Bug 34: the player just handed something over in this chat request. Tick the deal until the
 * payment is confirmed and her waiting terms are queued (or nothing waits), up to $maxMs.
 */
/** Bug 122: cats paid "for" something with no deal behind it come back. */
function stobeNegRefundUnearnedPrepayment(string $npc, string $player): void {
    $cats = intval($GLOBALS['STOBE_VOICE_HANDOVER_CATS'] ?? 0);
    $message = strtolower(strval($GLOBALS['STOBE_VOICE_HANDOVER_MESSAGE'] ?? ''));
    if ($cats <= 0 || trim($npc) === '' || trim($player) === '') return;
    // A plain gift stays given; only a payment tied to a request is refundable.
    if (!preg_match('/\b(now|for|if|so that|in exchange|in return|and you|then you)\b/', $message)) return;
    $deal = $GLOBALS['db']->fetchOne(
        "SELECT contract_id FROM stobe_social_contract
          WHERE LOWER(npc_name)=LOWER($1)
            AND status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE','COMPLETE')
            AND updated_at > NOW() - interval '120 seconds' LIMIT 1",
        [$npc]
    );
    if (is_array($deal)) return; // a deal covers it, with its own refund rules
    stobeNegQueueDirective($npc, 'refund', '', [
        'actions'=>['GIVE_CATS@' . $player . '@' . $cats],
        'instruction'=>'You did not agree to what ' . $player . ' asked, so you hand back the ' . $cats . ' Cats they just gave you. Say so in one short line.',
    ], true);
    stobeLogInfo('Prepayment refunded: no deal behind it (bug 122)', ['npc'=>$npc, 'cats'=>$cats]);
}

function stobeNegSettleAfterHandover(string $npc, int $maxMs = 4000): void {
    $until = microtime(true) + $maxMs / 1000;
    do {
        stobeNegTick($npc);
        $deal = $GLOBALS['db']->fetchOne(
            "SELECT term_state FROM stobe_social_contract WHERE LOWER(npc_name)=LOWER($1) AND status='AWAITING_PERFORMANCE' ORDER BY updated_at DESC LIMIT 1",
            [$npc]
        );
        if (!is_array($deal)) return;
        $waiting = false;
        foreach (stobeNegDecode($deal['term_state'] ?? []) as $t) {
            if (($t['by'] ?? '') === 'npc' && ($t['status'] ?? '') === 'WAITING_FOR_PLAYER') { $waiting = true; break; }
        }
        if (!$waiting) return;
        usleep(400000);
    } while (microtime(true) < $until);
    stobeLogInfo('Settle after hand-over: payment not confirmed in time; her side waits', ['npc'=>$npc]);
}

/** Bug 42: instructions of directives about to ride on this NPC's chat reply (same windows as the attach). */
function stobeNegPendingDirectiveNotes(string $npc): array {
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT kind, payload FROM stobe_negotiation_directive WHERE consumed_unix=0 AND created_unix >= $1 - (CASE WHEN kind IN ('refund','reissue_payment') THEN 600 ELSE 45 END) AND LOWER(npc_name)=LOWER($2) AND kind = ANY($3::text[]) ORDER BY id",
        [time(), $npc, '{settle,reissue_truce,reissue_payment,betray,breach_react,refund}']
    );
    $notes = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $text = trim(strval(stobeNegDecode($row['payload'] ?? [])['instruction'] ?? ''));
        if ($text !== '' && !in_array($text, $notes, true)) $notes[] = $text;
    }
    return $notes;
}

/** Take the oldest fresh directive for one of the nearby NPCs (bored path). */
function stobeNegClaimDirective(array $candidateNames, string $onlyNpc = ''): ?array {
    try {
        stobeNegEnsureSchema();
        $rows = $GLOBALS['db']->fetchAll(
            // Refunds wait for the player to come back (10 min); everything else is time-critical.
            "SELECT * FROM stobe_negotiation_directive WHERE consumed_unix=0 AND created_unix >= $1 - (CASE WHEN kind IN ('refund','reissue_payment') THEN 600 WHEN kind='resume_deal' THEN 120 ELSE 45 END) ORDER BY id LIMIT 20",
            [time()]
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $npc = strval($row['npc_name']);
            if ($onlyNpc !== '' && strcasecmp($npc, $onlyNpc) !== 0) continue;
            $present = $onlyNpc !== '';
            $renamed = '';
            foreach ($candidateNames as $candidate) {
                $candidate = normalizeParticipantNameToken(strval($candidate));
                if (strcasecmp($candidate, $npc) === 0) $present = true;
                // Bug 128: named mid-fight: "Dust Bandit Bowman" is now "Gost [Dust Bandit Bowman]".
                elseif (preg_match('/^.+\[\s*(.+?)\s*\]$/', $candidate, $bm) && strcasecmp($bm[1], $npc) === 0) {
                    $present = true;
                    $renamed = $candidate;
                }
            }
            if (!$present) continue;
            if (stobeNegNpcOutState($renamed !== '' ? $renamed : $npc) !== '') continue; // item 48
            $claimed = $GLOBALS['db']->exec(
                "UPDATE stobe_negotiation_directive SET consumed_unix=$2 WHERE id=$1 AND consumed_unix=0",
                [intval($row['id']), time()]
            );
            if ($claimed === false || $GLOBALS['db']->affectedRows($claimed) !== 1) continue;
            if ($renamed !== '') {
                $GLOBALS['db']->exec("UPDATE stobe_negotiation_directive SET npc_name=$2 WHERE id=$1", [intval($row['id']), $renamed]);
                stobeLogInfo('Directive follows the NPC\'s new name (bug 128)', ['from'=>$npc, 'to'=>$renamed, 'kind'=>$row['kind'] ?? '']);
                $row['npc_name'] = $renamed;
            }
            $row['payload'] = stobeNegDecode($row['payload'] ?? []);
            return $row;
        }
    } catch (Throwable $e) {
        stobeLogWarn('Negotiation directive claim failed', ['error'=>$e->getMessage()]);
    }
    return null;
}

/** Mark queued settle / reissue terms as dispatched once their actions are really sent. */
function stobeNegMarkDirectiveDispatched(array $directive, array $finalActions): void {
    $id = strval($directive['contract_id'] ?? '');
    if ($id === '') return;
    $deal = stobeNegFetchDeal($id);
    if (!$deal || strval($deal['status']) !== 'AWAITING_PERFORMANCE') return;
    $state = stobeNegDecode($deal['term_state'] ?? []);
    $player = normalizeParticipantNameToken(strval($deal['player_name'] ?? '')); // Item 100: the deal's character
    if ($player === '') $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $sent = array_map('strtolower', $finalActions);
    $now = time();
    foreach (array_map('intval', $directive['payload']['term_indexes'] ?? []) as $idx) {
        if (!isset($state[$idx])) continue;
        $token = strtolower(stobeNegTermActionToken($state[$idx], $player));
        if ($token !== '' && in_array($token, $sent, true)) {
            $state[$idx]['status'] = 'DISPATCHED';
            $state[$idx]['dispatched_unix'] = $now;
            $state[$idx]['evidence'][] = ['at'=>$now, 'note'=>'dispatched_by_' . strval($directive['kind'])];
        }
    }
    stobeNegSaveDeal($id, 'AWAITING_PERFORMANCE', ['term_state'=>$state]);
}

/**
 * Chat path: if the player is talking to an NPC that owes a dispatch
 * (settle / truce reissue), attach those actions to this reply.
 */
function stobeNegAttachPendingForChat(string $npc, array $responseActions): array {
    $dispatchKinds = ['settle','reissue_truce','reissue_payment','betray','breach_react','refund'];
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT id FROM stobe_negotiation_directive WHERE consumed_unix=0 AND created_unix >= $1 - (CASE WHEN kind IN ('refund','reissue_payment') THEN 600 ELSE 45 END) AND LOWER(npc_name)=LOWER($2) AND kind = ANY($3::text[]) ORDER BY id",
        [time(), $npc, '{' . implode(',', $dispatchKinds) . '}']
    );
    foreach (is_array($rows) ? $rows : [] as $row) {
        $claimed = $GLOBALS['db']->exec(
            "UPDATE stobe_negotiation_directive SET consumed_unix=$2, outcome='attached_to_chat' WHERE id=$1 AND consumed_unix=0",
            [intval($row['id']), time()]
        );
        if ($claimed === false || $GLOBALS['db']->affectedRows($claimed) !== 1) continue;
        $directive = $GLOBALS['db']->fetchOne("SELECT * FROM stobe_negotiation_directive WHERE id=$1", [intval($row['id'])]);
        if (!is_array($directive)) continue;
        $directive['payload'] = stobeNegDecode($directive['payload'] ?? []);
        foreach ($directive['payload']['actions'] ?? [] as $action) {
            if (!in_array($action, $responseActions, true)) $responseActions[] = strval($action);
        }
        if (in_array(strval($directive['kind']), ['settle','reissue_truce','reissue_payment'], true)) {
            stobeNegMarkDirectiveDispatched($directive, $responseActions);
        }
        stobeLogInfo('Negotiation directive attached to chat reply', ['npc'=>$npc, 'kind'=>$directive['kind'], 'actions'=>$responseActions]);
    }
    return $responseActions;
}

/** Bored-path completion: forced actions, NPC-proposed deal capture. */
function stobeNegCompleteDirective(array $directive, string $raw, string $npc, string $player, array $npcData, string $text, array $actions): array {
    $kind = strval($directive['kind'] ?? '');
    try {
        if (in_array($kind, ['settle','reissue_truce','reissue_payment','betray','breach_react','refund'], true)) {
            foreach ($directive['payload']['actions'] ?? [] as $action) {
                if (!in_array($action, $actions, true)) $actions[] = strval($action);
            }
            if (in_array($kind, ['settle','reissue_truce','reissue_payment'], true)) stobeNegMarkDirectiveDispatched($directive, $actions);
        } elseif (in_array($kind, ['surrender','assist'], true)) {
            $result = stobeDealCaptureResponse($raw, $npc, $player, $npcData, '', $kind === 'surrender' ? 'surrender' : 'assist', 'npc');
            stobeLogInfo('NPC-initiated negotiation', ['npc'=>$npc, 'kind'=>$kind, 'result'=>$result]);
            if (!empty($result['ok']) && function_exists('stobeDealSpeechAmountCheck')) {
                $amountCheck = stobeDealSpeechAmountCheck($text, $npc, $result);
                if ($amountCheck !== null) {
                    stobeLogWarn('Negotiation speech amounts differ from recorded terms; rewritten', [
                        'npc'=>$npc, 'decision'=>strval($result['decision'] ?? ''), 'text'=>$text,
                        'spoken'=>$amountCheck['spoken'], 'wrong'=>$amountCheck['wrong'], 'line'=>$amountCheck['line'],
                    ]);
                    $text = $amountCheck['line'];
                }
            }
            if (empty($result['ok']) || !in_array(strval($result['decision'] ?? ''), ['PROPOSE','ACCEPT'], true)) {
                // No valid offer: keep the line only if it does not promise terms.
                if (function_exists('stobeDealSpeechClaimsCeasefire') && stobeDealSpeechClaimsCeasefire($text)) $text = '';
            }
        }
        $GLOBALS['db']->exec("UPDATE stobe_negotiation_directive SET outcome=$2 WHERE id=$1", [intval($directive['id']), 'delivered']);
    } catch (Throwable $e) {
        stobeLogWarn('Negotiation directive completion failed', ['npc'=>$npc, 'kind'=>$kind, 'error'=>$e->getMessage()]);
    }
    return ['text'=>$text, 'actions'=>$actions];
}

// ------------------------------------------------------------------ Phases 4 & 5: NPC-initiated offers

function stobeNegCourageThreshold(string $personality, float $base): float {
    $p = strtolower($personality);
    if (preg_match('/\b(coward|timid|cautious|nervous|fearful|meek|survivor|pragmatic)\b/', $p)) $base += 0.15;
    if (preg_match('/\b(brave|fearless|fanatic|zealot|berserk|reckless|proud|stubborn|bloodthirsty)\b/', $p)) $base -= 0.15;
    return max(0.1, min(0.8, $base));
}

/** Bug 98: "X: took a major hit" events against $name since $sinceUnix. */
function stobeNegMajorHitsOn(string $name, int $sinceUnix): int {
    try {
        $row = $GLOBALS['db']->fetchOne(
            "SELECT COUNT(*) AS n FROM eventlog WHERE type='major_damage' AND localts >= $1 AND data LIKE $2",
            [$sinceUnix, $name . ': took a major hit%']
        );
        return intval($row['n'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

// ---- Item 48: knocked out or dead NPCs don't negotiate ------------------------------

/** Unsigned hand serial of an NPC ("hand_<serial>" in her metadata), or ''. */
function stobeNegNpcHandSerial(string $npc, array|false $npcData): string {
    $sid = '';
    if (is_array($npcData)) {
        $sid = strval($npcData['storage_id'] ?? '');
        if ($sid === '') {
            $meta = $npcData['metadata'] ?? [];
            if (is_string($meta)) $meta = json_decode($meta, true);
            $sid = is_array($meta) ? strval($meta['storage_id'] ?? '') : '';
        }
    }
    if (preg_match('/^hand_(-?\d+)$/', $sid, $m)) {
        $serial = intval($m[1]);
        if ($serial < 0) $serial += 4294967296;
        return $serial > 0 ? strval($serial) : '';
    }
    $serial = function_exists('stobeNegSerialFromStorage') ? stobeNegSerialFromStorage($npc) : 0;
    return $serial > 0 ? strval($serial) : '';
}

/**
 * 'dead' or 'unconscious' from the NPC's newest knockout / death / recovered event (she
 * is the event's actor; matched on her serial when known, else her name), '' when she is
 * conscious. Dying but conscious counts as conscious. No events: her stored state decides.
 */
function stobeNegNpcOutState(string $npc, array|false $npcData = false): string {
    $npc = trim($npc);
    if ($npc === '') return '';
    if ($npcData === false && function_exists('getNpcData')) $npcData = getNpcData($npc);
    try {
        $serial = stobeNegNpcHandSerial($npc, $npcData);
        $row = $serial !== ''
            ? $GLOBALS['db']->fetchOne(
                "SELECT type FROM eventlog WHERE type IN ('knockout','death','recovered') AND people ~ $1
                  ORDER BY localts DESC, rowid DESC LIMIT 1",
                ['^\["[^"]*\|hand_' . $serial . '"'])
            : $GLOBALS['db']->fetchOne(
                "SELECT type FROM eventlog WHERE type IN ('knockout','death','recovered') AND data LIKE $1
                  ORDER BY localts DESC, rowid DESC LIMIT 1",
                [$npc . ':%']);
        if (is_array($row)) {
            $type = strval($row['type'] ?? '');
            return $type === 'death' ? 'dead' : ($type === 'knockout' ? 'unconscious' : '');
        }
    } catch (Throwable $e) {
        // fall through to the stored state
    }
    if (is_array($npcData) && function_exists('stobeResolveNpcAwarenessState')) {
        $state = stobeResolveNpcAwarenessState($npcData);
        if ($state === 'dead') return 'dead';
        if (in_array($state, ['unconscious', 'knocked_out'], true)) return 'unconscious';
    }
    return '';
}

/** On "X: regained consciousness": her open offer is refreshed and she brings it up again. */
function stobeNegResumeAfterKnockout(string $eventData): void {
    if (!preg_match('/^(.+?):\s*regained consciousness/i', trim($eventData), $m)) return;
    $npc = normalizeParticipantNameToken($m[1]);
    $base = preg_match('/\[\s*(.+?)\s*\]$/', $npc, $bm) ? $bm[1] : $npc; // "Hesk [Dust Bandit Bowman]"
    $deal = $GLOBALS['db']->fetchOne(
        "SELECT * FROM stobe_social_contract WHERE LOWER(npc_name) IN (LOWER($1), LOWER($2))
            AND status IN ('PROPOSED','COUNTERED') AND updated_at > NOW() - INTERVAL '2 hours'
          ORDER BY updated_at DESC LIMIT 1",
        [$npc, $base]
    );
    if (!is_array($deal)) return;
    $GLOBALS['db']->exec("UPDATE stobe_social_contract SET updated_at=NOW() WHERE contract_id=$1", [strval($deal['contract_id'])]);
    $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $terms = stobeNegDecode($deal['terms'] ?? []);
    $line = function_exists('stobeDealPlainTermsLine') && is_array($terms) && count($terms) > 0
        ? trim(stobeDealPlainTermsLine($terms, 'COUNTER')) : '';
    stobeNegQueueDirective(strval($deal['npc_name']), 'resume_deal', strval($deal['contract_id']), [
        'actions'=>[],
        'instruction'=>'You just came to after being knocked out. Before that you were negotiating with ' . $player
            . ($line !== '' ? ' (the offer on the table: ' . $line . ')' : '')
            . '. You remember it: bring the deal up again in one short line.',
    ], true);
    stobeLogInfo('Deal resumes after a knockout (item 48)', ['npc'=>$npc, 'contract_id'=>$deal['contract_id'] ?? '', 'status'=>$deal['status'] ?? '']);
}

/** Bug 98: newest live health from the fight ("took a major hit (health N%)"), or null. */
function stobeNegLiveHealthRatio(string $name, int $sinceUnix): ?float {
    try {
        $row = $GLOBALS['db']->fetchOne(
            "SELECT data FROM eventlog WHERE type='major_damage' AND localts >= $1 AND data LIKE $2 ORDER BY localts DESC LIMIT 1",
            [$sinceUnix, $name . ': took a major hit (health %']
        );
        if (is_array($row) && preg_match('/\(health (\d+)%\)/', strval($row['data'] ?? ''), $m)) {
            return max(0.0, min(1.0, intval($m[1]) / 100.0));
        }
    } catch (Throwable $e) {
    }
    return null;
}

/** Bug 98: does the stored row carry real health numbers? */
function stobeNegHasHealthData(array $row): bool {
    if (preg_match('/^\s*-?\d+(?:\.\d+)?\s*\/\s*[1-9]/', strval($row['blood'] ?? ''))) return true;
    $limbs = stobeNegDecode($row['limbs'] ?? []);
    return is_array($limbs) && count($limbs) > 0;
}

function stobeNegConsiderInitiatives(string $eventType, string $eventData, string $peopleRaw, int $gamets): void {
    if (!stobeNegPhaseEnabled(4) && !stobeNegPhaseEnabled(5)) return;
    try {
        // Bug 98: a health drop ("took a major hit (health N%)") is rare and decisive;
        // only the frequent "Initiated attack" checks are throttled.
        $healthEvent = strpos($eventData, 'took a major hit (health ') !== false;
        if (!$healthEvent) {
            $marker = stobeNegThrottleMarker('initiative');
            $last = @filemtime($marker);
            if (is_int($last) && (time() - $last) < 3) return;
            @touch($marker);
        }
        stobeNegEnsureSchema();
        $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
        $now = time();
        // Bug 98: one cooldown per kind, so a "help me" offer doesn't block surrenders.
        $recentSurrender = $GLOBALS['db']->fetchOne(
            "SELECT MAX(created_unix) AS t FROM stobe_negotiation_directive WHERE kind='surrender'"
        );
        $recentAssist = $GLOBALS['db']->fetchOne(
            "SELECT MAX(created_unix) AS t FROM stobe_negotiation_directive WHERE kind='assist'"
        );
        $surrenderReady = ($now - intval($recentSurrender['t'] ?? 0)) >= STOBE_NEG_INITIATIVE_GLOBAL_COOLDOWN;
        $assistReady = ($now - intval($recentAssist['t'] ?? 0)) >= STOBE_NEG_INITIATIVE_GLOBAL_COOLDOWN;
        if (!$surrenderReady && !$assistReady) return;

        $names = [];
        if (preg_match('/^(.+?):\s*Initiated attack\s*\(talking to:\s*(.+?)\)/', trim($eventData), $m)) {
            $names = [normalizeParticipantNameToken($m[1]), normalizeParticipantNameToken($m[2])];
        } elseif (preg_match('/^(.+?):\s*took a major hit/', trim($eventData), $m)) {
            $names = [normalizeParticipantNameToken($m[1])]; // bug 98: re-check as health drops
        }
        $playerNearby = stripos($peopleRaw, $player) !== false;
        foreach (array_unique(array_filter($names)) as $name) {
            if (stobeNegIsPlayerSide($name, $player)) continue;
            $data = getNpcData($name);
            if (!is_array($data)) continue;
            if (stobeNegNpcOutState($name, $data) !== '') continue; // item 48: knocked out or dead
            $recentNpc = $GLOBALS['db']->fetchOne(
                "SELECT MAX(created_unix) AS t FROM stobe_negotiation_directive WHERE kind IN ('surrender','assist') AND LOWER(npc_name)=LOWER($1)",
                [$name]
            );
            if (($now - intval($recentNpc['t'] ?? 0)) < STOBE_NEG_INITIATIVE_NPC_COOLDOWN) continue;
            if (stobeDealOpenForNpc($name) !== null) continue;
            $row = stobeNegNpcRow($name);
            $ratio = stobeNegHealthRatio($row);
            if (!stobeNegHasHealthData($row)) { // bug 98: no numbers -> judge by major hits
                $ratio = max(0.1, 1.0 - 0.35 * stobeNegMajorHitsOn($name, $now - 90));
            }
            $live = stobeNegLiveHealthRatio($name, $now - 90); // bug 98: stored health is stale mid-fight
            // The event being handled isn't in the event log yet: read its own "(health N%)".
            if (count($names) === 1 && preg_match('/\(health (\d+)%\)/', $eventData, $hm)) {
                $eventLive = max(0.0, min(1.0, intval($hm[1]) / 100.0));
                if ($live === null || $eventLive < $live) $live = $eventLive;
            }
            if ($live !== null && $live < $ratio) $ratio = $live;
            $events = stobeNegFightEventsForNpc($name, $data, $now - 90); // item 71
            $hostileToPlayer = false;
            $fightingOthers = false;
            foreach ($events as $ev) {
                $attackerIsNpc = stobeNegNameMatches($ev['attacker'], $name);
                $other = $attackerIsNpc ? $ev['target'] : $ev['attacker'];
                if (!$attackerIsNpc && !stobeNegNameMatches($ev['target'], $name)) continue;
                if (stobeNegIsPlayerSide($other, $player)) $hostileToPlayer = true; else $fightingOthers = true;
            }
            $personality = strval($row['personality'] ?? '');
            [$offerCap, $offerCarried, , $offerTopup] = (function_exists('stobeNegOfferCap') ? stobeNegOfferCap($name, $data) : [0, 0]) + [3=>false];
            $offerLine = $offerTopup
                ? 'You carry about ' . $offerCarried . ' Cats but can have more brought; if you offer Cats, offer at most ' . $offerCap . '. '
                : ($offerCap > 0
                ? 'You carry about ' . $offerCarried . ' Cats; if you offer Cats, offer at most ' . $offerCap . ' (keep it modest). '
                : 'You have next to no Cats: offer an item, information or just beg - do not offer Cats. ');
            stobeLogDebug('Initiative check', ['npc'=>$name, 'ratio'=>round($ratio, 2), 'hostile_to_player'=>$hostileToPlayer, 'fighting_others'=>$fightingOthers, 'surrender_ready'=>$surrenderReady, 'threshold'=>round(stobeNegCourageThreshold($personality, 0.35), 2)]);
            if ($surrenderReady && $hostileToPlayer && stobeNegPhaseEnabled(4) && $ratio < stobeNegCourageThreshold($personality, 0.35)) {
                stobeNegQueueDirective($name, 'surrender', '', [
                    'health_ratio'=>round($ratio, 2),
                    'instruction'=>'You are losing this fight against ' . $player . ' (your health is about ' . intval($ratio * 100) . '%). '
                        . 'In character, decide whether to beg for your life or offer something (Cats, items, surrender) in exchange for being spared. '
                        . $offerLine
                        . 'If you make an offer, set deal_decision to PROPOSE and list the terms (include your own STOP_ATTACK and the player SPARE). '
                        . 'If you would rather fight to the end, just say so and use deal_decision NONE.',
                ], true);
                return;
            }
            if ($assistReady && !$hostileToPlayer && $fightingOthers && $playerNearby && stobeNegPhaseEnabled(5)
                && !npcIsInPlayerFaction($data) && $ratio < stobeNegCourageThreshold($personality, 0.6)) {
                stobeNegQueueDirective($name, 'assist', '', [
                    'health_ratio'=>round($ratio, 2),
                    'instruction'=>'You are losing a fight (health about ' . intval($ratio * 100) . '%) and ' . $player . ' is nearby. '
                        . 'In character, call out to ' . $player . ' for help. You may promise a reward that you can actually give. '
                        . $offerLine
                        . 'If you offer a deal, set deal_decision to PROPOSE with terms: player PROTECT (target npc), and your reward terms with "when":"after_player".',
                ], true);
                return;
            }
        }
    } catch (Throwable $e) {
        stobeLogWarn('Negotiation initiative check failed', ['error'=>$e->getMessage()]);
    }
}

// ------------------------------------------------------------------ Phase 3: partner lock

/**
 * While a negotiation is live, a line without an explicit name goes to the
 * negotiation partner rather than whichever combatant the client targeted.
 */
function stobeNegPartnerForUnnamedLine(string $targetNpc, string $message, string $peopleRaw): string {
    if (!stobeNegPhaseEnabled(3)) return '';
    try {
        stobeNegEnsureSchema();
        $row = $GLOBALS['db']->fetchOne(
            "SELECT npc_name FROM stobe_social_contract
              WHERE status IN ('PROPOSED','COUNTERED','ACCEPTED','AWAITING_PERFORMANCE')
                AND kind IN ('combat','surrender')
                AND updated_at > NOW() - INTERVAL '120 seconds'
              ORDER BY updated_at DESC LIMIT 1"
        );
        $partner = normalizeParticipantNameToken(strval($row['npc_name'] ?? ''));
        if ($partner === '' || strcasecmp($partner, $targetNpc) === 0) return '';
        if (stripos($peopleRaw, $partner) === false) return '';
        $targetBase = function_exists('baseNameWithoutBracketSuffix') ? baseNameWithoutBracketSuffix($targetNpc) : $targetNpc;
        foreach (array_filter([$targetNpc, $targetBase, strtok($targetBase, ' ')]) as $alias) {
            if (strlen(strval($alias)) >= 3 && stripos($message, strval($alias)) !== false) return '';
        }
        return $partner;
    } catch (Throwable $e) {
        return '';
    }
}

// ------------------------------------------------------------------ Phase 6: social deals

function stobeNegLooksLikeSocialOffer(string $message): bool {
    if (!stobeNegPhaseEnabled(6)) return false;
    $message = function_exists('stobeNegWordsToNumbers') ? stobeNegWordsToNumbers(strtolower($message)) : $message;
    // Bug 36: a price with "deal" ("Four hundred then... Deal?"), or "<number> then / it is".
    if (preg_match_all('/\b(\d[\d,]*)\b/', $message, $numMatches)) {
        $bigNumber = false;
        foreach ($numMatches[1] as $n) { if (intval(str_replace(',', '', $n)) >= 10) { $bigNumber = true; break; } }
        if ($bigNumber && preg_match("/\bdeal\b|\b\d[\d,]*\s*(then|it is|it'?s a deal|and we'?re square)\b/", $message)) return true;
    }
    // Asking for her price is an offer too (bug 28).
    if (preg_match("/\b(name your price|what'?s your (price|counter|offer)|what is your (price|counter|offer)|your counter\b|how much (for|to|would|do you want|you want)|what would (it|that) (take|cost)|what'?d (it|that) take|what do you want for|make me an offer|what would you take)/", strtolower($message))) {
        return true;
    }
    if (preg_match("/\\bwhat if i\\b|\\b(you give me|give me|trade me|hand me) [^.?!]{1,60}\\b(and|then) i('?ll| will)\\b|\\bi('?ll| will) (heal|bandage|patch|free|feed|help|protect|carry|rescue)\\b[^.?!]{0,60}\\b(if|for) you\\b|\\bif you [^.?!]{1,60}\\bi('?ll| will) (heal|bandage|patch|free|feed|give|pay|help|protect)\\b/i", $message) === 1) {
        return true;
    }
    if (preg_match("/\\b(\\d[\\d,]*\\s*(counts?|caps?|cads?)\\b|(i'?ll|i will|let me|can i|could i|i want to|i'?d like to) (buy|purchase|trade|swap|sell)\\b|\\b(buy|purchase|get) [^.?!]{1,40}\\b(off|from) you\\b|\\bsell (me|it to me|them to me)\\b|here'?s (a|my) (deal|offer)|(make|strike|cut) (you )?a deal|\\bfor \\d[\\d,]*\\s*(cats?|counts?|caps?|c)\\b)/i", $message) === 1) {
        return true;
    }
    return preg_match("/\\b(\\d[\\d,]*\\s*(cats?|c)\\b|i'?ll (give|pay|buy|get|bring) you|i will (give|pay|buy|get|bring) you|(buy|get) you a (drink|round|meal)|pay you|trade you|in exchange|in return for|lend me|loan (me|you)|borrow your|owe you|i'?ll owe you|do we have a deal|(it'?s|that'?s) a deal\\?)/i", $message) === 1;
}

// ------------------------------------------------------------------ Phase 7: consequences

function stobeNegApplyConsequences(array $deal, string $player): void {
    if (!stobeNegPhaseEnabled(7)) return;
    $id = strval($deal['contract_id']);
    $npc = strval($deal['npc_name']);
    $status = strval($deal['status']);
    try {
        $claim = $GLOBALS['db']->exec(
            "UPDATE stobe_social_contract SET consequences_applied=TRUE WHERE contract_id=$1 AND consequences_applied=FALSE",
            [$id]
        );
        if ($claim === false || $GLOBALS['db']->affectedRows($claim) !== 1) return;

        $state = stobeNegDecode($deal['term_state'] ?? []);
        $summary = stobeNegTermsSummary($state, $npc, $player);
        // Item 100 (Shay's call (a)): reputation and relationship history stay keyed by the PLAYER_NAME persona.
        // Only a player-squad character other than the persona is mapped (a deal made as Beaks counts for "shay").
        $persona = normalizeParticipantNameToken(strval(getSetting('PLAYER_NAME', '')));
        if ($persona === '' || strcasecmp($persona, $player) === 0 || !stobeNegIsPlayerSide($player, $persona)) $persona = $player;
        [$delta, $memory, $repColumn] = match ($status) {
            'COMPLETE' => [4, $player . ' kept their word on our deal (' . $summary . ').', 'player_kept'],
            'BREACHED_PLAYER' => [-15, $player . ' broke our deal and never delivered (' . $summary . ').', 'player_broken'],
            'BREACHED_NPC' => [0, 'I took what ' . $player . ' gave and went back on our deal (' . $summary . ').', 'npc_broken'],
            'IMPOSSIBLE' => [0, 'Our deal fell through; it could not be carried out (' . $summary . ').', ''],
            default => [0, '', ''],
        };
        // REL (phase 5): with SOCIAL_RELATIONSHIP_MODE=enabled the relationship system scores the outcome
        // (kept/broken promise, betrayal) and the legacy delta is skipped; off/shadow keep the legacy delta.
        require_once __DIR__ . '/social_agreements.php';
        if (stobeSocialAgreementOutcome($deal, $player, $status, is_array($state) ? $state : [])) $delta = 0;
        if ($delta !== 0 && function_exists('stobeGetNpcRelationshipMap')) {
            $npcData = getNpcData($npc);
            if (is_array($npcData)) {
                $applied = stobeApplyRelationshipUpdatesMap(stobeGetNpcRelationshipMap($npcData), [[
                    'target'=>$persona, 'aff_delta'=>$delta, 'type'=>'',
                    'note'=>$status === 'COMPLETE' ? 'Kept a deal' : 'Broke a deal',
                ]], [$persona]);
                // The apply helper returns {map, applied, updated}; persist only the map.
                if (is_array($applied['map'] ?? null) && intval($applied['updated'] ?? 0) > 0) {
                    stobePersistNpcRelationshipMap($npc, $applied['map'], $npcData);
                }
            }
        }
        if ($repColumn !== '') {
            $GLOBALS['db']->exec(
                "INSERT INTO stobe_negotiation_reputation (player_name, {$repColumn}) VALUES ($1, 1)
                 ON CONFLICT (player_name) DO UPDATE SET {$repColumn}=stobe_negotiation_reputation.{$repColumn}+1, updated_at=NOW()", // item 50: key is lower-case
                [strtolower($persona)] /* item 50; Item 100: persona */
            );
        }
        if ($memory !== '') {
            // Memory row for the NPC, visible to the people who were present (witnesses).
            $baseline = stobeNegDecode($deal['baseline'] ?? []);
            $previousPeople = $GLOBALS['CACHE_PEOPLE'] ?? null;
            $GLOBALS['CACHE_PEOPLE'] = strval($baseline['witnesses_people'] ?? '');
            storeEvent('injection', time(), max(0, stobeNegLatestGamets()), $npc . ': [deal outcome] ' . $memory . ' (talking to: ' . $player . ')');
            $GLOBALS['CACHE_PEOPLE'] = $previousPeople;
        }
        if ($status === 'IMPOSSIBLE') {
            // The player delivered but the NPC's side could not happen: give it back.
            $refund = [];
            foreach ($state as $t) {
                if (($t['by'] ?? '') !== 'player' || ($t['status'] ?? '') !== 'VERIFIED') continue;
                if (($t['kind'] ?? '') === 'GIVE_CATS') $refund[] = 'GIVE_CATS@' . $player . '@' . max(1, intval($t['amount'] ?? 0));
                if (in_array($t['kind'] ?? '', ['GIVE_ITEM','RETURN_ITEM'], true) && !empty($t['item']) && stobeNegItemCategory(strval($t['item'])) === '') {
                    $refund[] = 'GIVE_ITEM@' . $player . '@' . $t['item'] . '@' . max(1, intval($t['quantity'] ?? 1));
                }
            }
            if (count($refund) > 0) {
                stobeNegQueueDirective($npc, 'refund', $id, [
                    'actions'=>$refund,
                    'instruction'=>'Your side of the deal with ' . $player . ' could not happen, so you are handing back what they gave you. Say so in one short line.',
                ], true);
            }
        }
        if ($status === 'BREACHED_PLAYER' && stobeNegDealKindIsHostile($deal)) {
            // They stopped fighting for a payment that never came.
            stobeNegQueueDirective($npc, 'breach_react', $id, [
                'actions'=>['ATTACK@' . $player],
                'instruction'=>$player . ' never paid what they promised for your ceasefire. React now in one short, angry line: the deal is off.',
            ], true);
        }
    } catch (Throwable $e) {
        stobeLogWarn('Deal consequences failed', ['contract_id'=>$id, 'error'=>$e->getMessage()]);
    }
}

function stobeNegTermsSummary(array $state, string $npc, string $player): string {
    $parts = [];
    foreach ($state as $t) {
        $who = ($t['by'] ?? '') === 'npc' ? $npc : $player;
        $what = strtolower(str_replace('_', ' ', strval($t['kind'] ?? '')));
        if (($t['kind'] ?? '') === 'GIVE_CATS') $what = 'pay ' . intval($t['amount'] ?? 0) . ' Cats';
        elseif (!empty($t['item'])) $what .= ' ' . $t['item'];
        elseif (!empty($t['text'])) $what .= ': ' . $t['text'];
        $parts[] = $who . ' ' . $what . ' [' . strtolower(strval($t['status'] ?? '')) . ']';
    }
    return implode('; ', $parts);
}

function stobeNegReputationLine(string $player): string {
    try {
        stobeNegEnsureSchema();
        $row = $GLOBALS['db']->fetchOne("SELECT player_kept, player_broken FROM stobe_negotiation_reputation WHERE LOWER(player_name)=LOWER($1)", [$player]); // item 50
        $kept = intval($row['player_kept'] ?? 0);
        $broken = intval($row['player_broken'] ?? 0);
        if ($kept + $broken === 0) return '';
        if ($broken >= 2 && $broken > $kept) return 'Word gets around: ' . $player . ' has a reputation for breaking deals.';
        if ($kept >= 2 && $kept > $broken * 2) return 'Word gets around: ' . $player . ' is known to honor deals.';
        return '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Item 85: the closing reputation directive for a non-squad NPC when the player's line is about a
 * deal, job or payment and the player breaks more deals than they keep ('' otherwise).
 * $counts = [kept, broken] for tests; else stobe_negotiation_reputation.
 */
function stobeNegReputationReplyDirective(string $npcName, array|false $npcData, string $player, string $message, ?bool $squadMember = null, ?array $counts = null): string {
    if (trim($player) === '' || trim($message) === '') return '';
    if (!preg_match('/\b(deal|job|work\s+for|pay|paid|payment|cats?|reward|offer|trade|afterwards|later|owe|promise|hire|contract|split|share|bounty)\b/i', $message)) return '';
    if ($squadMember === null) $squadMember = is_array($npcData) && function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npcData);
    if ($squadMember) return '';
    if ($counts === null) {
        try {
            stobeNegEnsureSchema();
            $row = $GLOBALS['db']->fetchOne("SELECT player_kept, player_broken FROM stobe_negotiation_reputation WHERE LOWER(player_name)=LOWER($1)", [$player]);
            $counts = [intval($row['player_kept'] ?? 0), intval($row['player_broken'] ?? 0)];
        } catch (Throwable $e) {
            return '';
        }
    }
    [$kept, $broken] = [intval($counts[0] ?? 0), intval($counts[1] ?? 0)];
    if (!($broken >= 2 && $broken > $kept)) return '';
    $who = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($player) : $player;
    return "<reply_reputation>\n"
        . '  <heard>Word gets around: ' . $who . ' has broken ' . $broken . ' deals and kept only ' . $kept . '. You have heard this and you believe it.</heard>' . "\n"
        . '  <how>Be openly distrustful about any deal, job or payment ' . $who . ' brings up: let them know you have heard they do not pay or keep their word. '
        . 'Payment later, "afterwards" or promises are worthless from them: demand the Cats up front (all of it, or a large part before you lift a finger), or refuse.</how>' . "\n"
        . '  <rule>Show this in your first words. Do not accept a pay-later offer from ' . $who . ' (no deal_decision ACCEPT unless the payment comes first).</rule>' . "\n"
        . '</reply_reputation>';
}

// ------------------------------------------------------------------ Phase 8: betrayal

function stobeNegDecideBetrayal(array $deal, string $player, array $state): array {
    $hasAfterTerms = count(array_filter($state, static fn($t) => ($t['status'] ?? '') === 'WAITING_FOR_PLAYER')) > 0;
    $playerPays = count(array_filter($state, static fn($t) => ($t['by'] ?? '') === 'player')) > 0;
    if (!$playerPays) return [];
    $npc = strval($deal['npc_name']);
    // STOBE 18 test switch (general_settings NEG_TEST_FORCE_BETRAYAL, off by default): betray every eligible paid deal.
    $forceSwitch = false;
    try { $forceSwitch = getSettingBool('NEG_TEST_FORCE_BETRAYAL', false); } catch (Throwable $e) { $forceSwitch = false; }
    if ($forceSwitch && ($hasAfterTerms || stobeNegDealKindIsHostile($deal))) {
        stobeLogWarn('Negotiation: betrayal forced by test switch NEG_TEST_FORCE_BETRAYAL (turn it off after the test)', ['contract_id'=>strval($deal['contract_id'] ?? ''), 'npc'=>$npc]);
        return ['considered'=>true, 'planned'=>true, 'chance'=>1.0, 'roll'=>0.0, 'reason'=>'test_switch NEG_TEST_FORCE_BETRAYAL'];
    }
    $row = stobeNegNpcRow($npc);
    $personality = strtolower(strval($row['personality'] ?? ''));
    $hits = preg_match_all('/\b(dishonest|liar|lies|treacher\w*|deceit\w*|manipulat\w*|greedy|ruthless|cruel|untrustworthy|backstab\w*|scheming|thief|opportunist\w*)\b/', $personality);
    if ($hits < 1) return [];
    $affinity = 0;
    $npcData = getNpcData($npc);
    if (is_array($npcData) && function_exists('stobeGetNpcRelationshipMap')) {
        $map = stobeGetNpcRelationshipMap($npcData);
        $key = function_exists('stobeFindRelationshipEntryKey') ? stobeFindRelationshipEntryKey($map, $player) : '';
        if ($key !== '' && isset($map[$key]['aff'])) $affinity = intval($map[$key]['aff']);
    }
    if ($affinity >= 0) return [];
    $chance = min(STOBE_NEG_BETRAYAL_MAX_CHANCE, 0.03 + 0.03 * $hits + ($affinity <= -20 ? 0.04 : 0.0));
    // Combat deals can be betrayed by re-attacking after payment even without "after" terms.
    if (!$hasAfterTerms && !stobeNegDealKindIsHostile($deal)) return [];
    $roll = getenv('STOBE_NEG_TEST_FORCE_BETRAYAL') === '1' ? 0.0 : mt_rand(0, 9999) / 10000;
    return [
        'considered'=>true, 'planned'=>$roll < $chance, 'chance'=>round($chance, 3), 'roll'=>round($roll, 4),
        'reason'=>'personality_markers=' . $hits . ' affinity=' . $affinity,
    ];
}

// ------------------------------------------------------------------ clothing memory

/**
 * Items this NPC took off through STOBE (from KenshiFP's section-move log, which
 * records the slot) that are still carried and not worn again. Lets the NPC map
 * "your vest" to the Black Rag Shirt it removed from its armour slot.
 */
function stobeRemovedClothingPromptBlock(string $npc, array $npcData): string {
    try {
        $serial = stobeNegSerialFromStorage($npc);
        if ($serial <= 0) return '';
        $removed = [];
        $dropped = [];
        $pendingSlot = '';
        $pendingDrop = false;
        foreach (stobeNegBridgeRecords(time() - 6 * 3600) as $r) {
            $body = $r['body'];
            if (preg_match('/^UNEQUIP_ITEM section move result .* source=(\S+) .*equippedAfter=0/', $body, $m)) { $pendingSlot = $m[1]; continue; }
            if (str_starts_with($body, 'UNEQUIP_ITEM no carried section has room; dropped at feet') && str_contains($body, 'result=dropped')) { $pendingDrop = true; continue; }
            if (preg_match('/^UNEQUIP_ITEM serial=(\d+) .*matched=(.+?) result=ok/', $body, $m)) {
                if (intval($m[1]) === $serial) {
                    if ($pendingDrop) $dropped[strtolower(trim($m[2]))] = trim($m[2]);
                    else $removed[strtolower(trim($m[2]))] = [trim($m[2]), $pendingSlot];
                }
                $pendingSlot = '';
                $pendingDrop = false;
                continue;
            }
            if (preg_match('/^ACTION_BRIDGE EQUIP_ITEM actor=(\d+) .*matched=(.+?) result=ok/', $body, $m) && intval($m[1]) === $serial) {
                unset($removed[strtolower(trim($m[2]))]);
            }
        }
        if (count($removed) === 0 && count($dropped) === 0) return '';
        $carried = stobeNegInventoryCounts(strval($npcData['inventory'] ?? ''));
        $worn = stobeNegInventoryCounts(strval($npcData['equipment'] ?? ''));
        $labels = ['armour'=>'body armour / vest layer', 'shirt'=>'shirt', 'legs'=>'pants', 'boots'=>'footwear',
            'head'=>'hat / helmet', 'eyes'=>'face / eyewear', 'gloves'=>'gloves', 'back'=>'back', 'belt'=>'belt', 'backpack'=>'backpack'];
        $lines = [];
        foreach ($removed as $key => [$name, $slot]) {
            if (!stobeNegItemCount($carried, $name) || stobeNegItemCount($worn, $name)) continue;
            $lines[] = $name . ($slot !== '' ? ' (was your ' . ($labels[$slot] ?? $slot) . ')' : '');
        }
        $droppedLines = [];
        foreach ($dropped as $name) {
            if (!stobeNegItemCount($carried, $name) && !stobeNegItemCount($worn, $name)) $droppedLines[] = $name;
        }
        $droppedText = count($droppedLines) > 0
            ? "\nYour pack was full, so these were dropped at your feet (full pack) when you took them off; they are on the ground, not in your pack: " . implode('; ', $droppedLines) . '.'
            : '';
        if (count($lines) === 0) return $droppedText === '' ? '' : "<clothing_you_took_off>" . $droppedText . "\n</clothing_you_took_off>";
        return "<clothing_you_took_off>" . $droppedText . "\nYou took these off earlier and are carrying them, not wearing them: " . implode('; ', $lines)
            . ".\nPeople may call them by slot (vest, top, pants, shoes, hat). If asked to put clothes back on, EquipItem these exact items, one per response.\n</clothing_you_took_off>";
    } catch (Throwable $e) {
        return '';
    }
}

// ------------------------------------------------------------------ prompt support

function stobeNegDealPromptExtras(string $npc, array $npcData, array $openDeal = []): string {
    $player = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $lines = [];
    $money = stobeNegMoney(stobeNegNpcRow($npc));
    if (function_exists('stobeNegOfferCap')) {
        // Cap tiers / item 53: her real limit, and no "I'm broke" when she has money.
        [$capNow, $carriedNow, , $topupNow] = stobeNegOfferCap($npc, $npcData) + [3=>false];
        $lines[] = $topupNow
            ? 'You carry about ' . $carriedNow . ' Cats, but you can have more brought: the most you will pay in a deal is ' . $capNow . ' Cats.'
            : ($capNow > 0
                ? 'You carry about ' . $carriedNow . ' Cats; the most you will pay in a deal is ' . $capNow . ' Cats. Asked for more, refuse or offer ' . $capNow . ' at most; do not claim you have no money.'
                : 'You have no Cats to pay with: offer an item or something else instead.');
    } elseif ($money['known']) {
        $lines[] = 'Your purse: ' . $money['value'] . ' Cats (you cannot promise more than you have).';
    }
    if (count($openDeal) > 0) {
        $kind = strval($openDeal['kind'] ?? 'combat');
        $rounds = intval($openDeal['rounds'] ?? 0);
        if (stobeNegPhaseEnabled(3) && in_array(strval($openDeal['status'] ?? ''), ['PROPOSED','COUNTERED'], true)) {
            $lines[] = 'Bargaining round ' . ($rounds + 1) . ' of ' . STOBE_NEG_MAX_COUNTER_ROUNDS . '.'
                . ($rounds + 1 >= STOBE_NEG_MAX_COUNTER_ROUNDS - 1 ? ' This is the final round: ACCEPT or REJECT, no more counters.' : '')
                . ' Judge fairness by real values (item values in the equipment lists, your purse) and what the fight is costing you.';
        }
        $state = stobeNegDecode($openDeal['term_state'] ?? []);
        if (count($state) > 0) {
            $now = time();
            $parts = [];
            foreach ($state as $t) {
                $left = intval($t['deadline_unix'] ?? 0) > 0 ? max(0, intval($t['deadline_unix']) - $now) : -1;
                $parts[] = (($t['by'] ?? '') === 'npc' ? 'you' : $player) . ' ' . strtolower(str_replace('_', ' ', strval($t['kind'] ?? '')))
                    . (isset($t['amount']) ? ' ' . intval($t['amount']) . ' Cats' : '') . (!empty($t['item']) ? ' ' . $t['item'] : '')
                    . ': ' . strtolower(strval($t['status'] ?? '')) . ($left >= 0 && ($t['status'] ?? '') === 'AWAITING_PLAYER' ? ' (' . $left . 's left)' : '');
            }
            $lines[] = 'Deal progress (observed, authoritative): ' . implode('; ', $parts) . '.';
            if (function_exists('stobeDealMoneyTotalsLine')) {
                $moneyLine = stobeDealMoneyTotalsLine($state, $player);
                if ($moneyLine !== '') $lines[] = $moneyLine;
            }
        }
        if (function_exists('stobeDealIsUnderway') && stobeDealIsUnderway($openDeal)) {
            $owed = stobeDealOutstanding($openDeal);
            $still = [];
            if (count($owed['player']) > 0) $still[] = $player . ' still has to ' . str_replace([' me ', ' me'], [' you ', ' you'], implode(' and ', $owed['player']));
            if (count($owed['npc']) > 0) $still[] = 'you still have to ' . str_replace([' you ', ' you'], [' ' . $player . ' ', ' ' . $player], implode(' and ', $owed['npc']));
            $lines[] = 'This deal is underway and not finished (' . implode('; ', $still) . '). Do not agree to or offer any new or different deal until it is settled. '
                . 'If ' . $player . ' proposes one, tell them to finish this deal first and say what is still owed, with deal_decision NONE.';
        }
        $betrayal = stobeNegDecode($openDeal['betrayal'] ?? []);
        if (!empty($betrayal['planned']) && empty($betrayal['executed'])) {
            $lines[] = 'Privately, you do not intend to keep your side once ' . $player . ' has paid. Do not reveal this.';
        }
        if ($kind === 'social') $lines[] = 'This is not a fight: no STOP_ATTACK is needed. Unless a time is stated, promises are due within one in-game day.';
    }
    $rep = stobeNegPhaseEnabled(7) ? stobeNegReputationLine($player) : '';
    if ($rep !== '') $lines[] = $rep;
    if (function_exists('stobeDealWeaponPromptLine')) {
        $weaponLine = stobeDealWeaponPromptLine($npcData, $player, count($openDeal) > 0 ? strval($openDeal['kind'] ?? '') : stobeDealKindFor($npcData));
        if ($weaponLine !== '') $lines[] = $weaponLine;
    }
    if (stobeNegPhaseEnabled(8)) {
        $lines[] = 'Tolls, ransoms, bribes, prisoner exchanges and safe passage are all valid deals. Threats and leverage are fair game, but terms must be things either side can actually do.';
    }
    return count($lines) > 0 ? implode("\n", $lines) : '';
}
