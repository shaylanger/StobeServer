<?php
/**
 * NPC info panel (player view): a compact, read-only summary of one NPC as the
 * speaking squad character knows them. Built from stored game/server state only;
 * nothing here calls an LLM.
 *
 * Learned facts (stobe_npc_learned_fact): what an NPC disclosed about themselves
 * to one listener, extracted by the existing per-turn relationship evaluator
 * (stobeEvaluateRelationshipsForTurn) in the same model call. Only these rows,
 * deal outcomes and live/observed game state are shown; the stored backstory,
 * goals, personality and relationship notes stay hidden.
 */

const STOBE_NPC_FACT_CATEGORIES = ['background', 'interest', 'occupation', 'history'];
const STOBE_NPC_FACT_MAX_PER_TURN = 2;
const STOBE_NPC_FACT_MAX_SHOWN = 8;

function stobeNpcFactEnsureSchema(): void {
    static $done = false;
    if ($done) return;
    $db = $GLOBALS['db'];
    $db->exec(
        "CREATE TABLE IF NOT EXISTS stobe_npc_learned_fact (
            id BIGSERIAL PRIMARY KEY,
            npc_storage_id TEXT NOT NULL,
            npc_name TEXT NOT NULL DEFAULT '',
            learner_name TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'told',
            category TEXT NOT NULL DEFAULT 'background',
            fact TEXT NOT NULL,
            fact_key TEXT NOT NULL,
            source_ref TEXT NOT NULL DEFAULT '',
            source_line TEXT NOT NULL DEFAULT '',
            game_ts BIGINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )"
    );
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_stobe_npc_learned_fact ON stobe_npc_learned_fact (npc_storage_id, LOWER(learner_name), fact_key)");
    $done = true;
}

/** Game time of the current request (main.php global), else the newest event's. */
function stobeNpcFactCurrentGamets(): int {
    $g = intval($GLOBALS['gamets'] ?? 0);
    if ($g <= 0 && function_exists('stobeNegLatestGamets')) $g = stobeNegLatestGamets();
    return max(0, $g);
}

function stobeNpcFactKey(string $fact): string {
    $norm = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $fact) ?? '');
    return md5(trim(preg_replace('/\s+/', ' ', $norm) ?? ''));
}

function stobeNpcViewStorageId(array|false $npcData): string {
    if (!is_array($npcData)) return '';
    $meta = $npcData['metadata'] ?? [];
    if (is_string($meta)) $meta = json_decode($meta, true);
    if (!is_array($meta)) return '';
    return normalizeStorageIdToken(strval($meta['storage_id'] ?? ($meta['refid'] ?? '')));
}

/** Pulls the optional "disclosed" list out of a relationship-evaluator JSON reply. */
function stobeNpcFactParseDisclosed(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    $json = json_decode($raw, true);
    if (!is_array($json) && preg_match('/\{[\s\S]*\}/', $raw, $m)) $json = json_decode($m[0], true);
    if (!is_array($json) || !is_array($json['disclosed'] ?? null)) return [];
    $out = [];
    foreach ($json['disclosed'] as $row) {
        if (is_string($row)) $row = ['fact' => $row];
        if (!is_array($row)) continue;
        $fact = trim(preg_replace('/\s+/', ' ', strval($row['fact'] ?? '')) ?? '');
        if ($fact === '' || strlen($fact) < 6) continue;
        if (strlen($fact) > 160) $fact = rtrim(substr($fact, 0, 157)) . '...';
        $cat = strtolower(trim(strval($row['category'] ?? 'background')));
        if (!in_array($cat, STOBE_NPC_FACT_CATEGORIES, true)) $cat = 'background';
        $out[] = ['fact' => $fact, 'category' => $cat];
        if (count($out) >= STOBE_NPC_FACT_MAX_PER_TURN) break;
    }
    return $out;
}

/**
 * Stores facts the NPC (speaker) told the listener. Needs the NPC's stable storage id;
 * without one nothing is stored (better unknown than mixed up between same-name NPCs).
 */
function stobeNpcFactRecordDisclosed(array|false $npcData, string $npcName, string $learner, array $facts, string $sourceLine, int $gamets): int {
    $learner = trim($learner);
    $sid = stobeNpcViewStorageId($npcData);
    if ($sid === '' || $learner === '' || count($facts) === 0) return 0;
    if (strcasecmp($learner, 'The Narrator') === 0 || strcasecmp($learner, $npcName) === 0) return 0;
    stobeNpcFactEnsureSchema();
    $line = substr(trim($sourceLine), 0, 400);
    $stored = 0;
    foreach ($facts as $f) {
        $fact = strval($f['fact'] ?? '');
        if ($fact === '') continue;
        $row = $GLOBALS['db']->fetchOne(
            "INSERT INTO stobe_npc_learned_fact (npc_storage_id, npc_name, learner_name, kind, category, fact, fact_key, source_ref, source_line, game_ts)
             VALUES ($1,$2,$3,'told',$4,$5,$6,$7,$8,$9)
             ON CONFLICT (npc_storage_id, LOWER(learner_name), fact_key) DO NOTHING RETURNING id",
            [$sid, $npcName, $learner, strval($f['category'] ?? 'background'), $fact, stobeNpcFactKey($fact),
             'chat gamets=' . $gamets, $line, max(0, $gamets)]
        );
        if (is_array($row) && intval($row['id'] ?? 0) > 0) $stored++;
    }
    if ($stored > 0 && function_exists('stobeLogInfo')) {
        stobeLogInfo('NPC_FACTS: recorded disclosed facts', ['npc' => $npcName, 'sid' => $sid, 'learner' => $learner, 'count' => $stored, 'gamets' => $gamets]);
    }
    return $stored;
}

function stobeNpcFactsFor(string $sid, string $learner, int $maxGamets = 0): array {
    if ($sid === '' || $learner === '') return [];
    stobeNpcFactEnsureSchema();
    $sql = "SELECT kind, category, fact, game_ts FROM stobe_npc_learned_fact
            WHERE npc_storage_id=$1 AND LOWER(learner_name)=LOWER($2)";
    $params = [$sid, $learner];
    if ($maxGamets > 0) { $sql .= " AND game_ts <= $3"; $params[] = $maxGamets; }
    $sql .= " ORDER BY game_ts DESC, id DESC LIMIT 40";
    $rows = $GLOBALS['db']->fetchAll($sql, $params);
    return is_array($rows) ? $rows : [];
}

// ------------------------------------------------------------------ view

function stobeNpcViewCleanFaction(string $faction): string {
    $faction = trim(preg_replace('/\s*\[[^\]]*\]\s*$/', '', $faction) ?? '');
    return $faction;
}

function stobeNpcViewDealStatusLabel(string $status, string $npc, string $speaker): string {
    switch (strtoupper($status)) {
        case 'PROPOSED': return 'proposed, not agreed yet';
        case 'COUNTERED': return 'counter-offer on the table';
        case 'ACCEPTED': return 'agreed';
        case 'AWAITING_PERFORMANCE': return 'agreed, in progress';
        case 'COMPLETE': return 'completed';
        case 'BREACHED_NPC': return 'broken by ' . $npc;
        case 'BREACHED_PLAYER': return 'broken by ' . $speaker;
        case 'EXPIRED': return 'expired';
        case 'IMPOSSIBLE': return 'could not be carried out';
        case 'CANCELLED': return 'cancelled';
        case 'REJECTED': return 'rejected';
    }
    return strtolower($status);
}

function stobeNpcViewTermStatusLabel(string $status, string $speaker): string {
    switch (strtoupper($status)) {
        case '': return '';
        case 'PENDING': return 'not started';
        case 'DISPATCHED': case 'REISSUE_QUEUED': return 'in progress';
        case 'AWAITING_PLAYER': case 'WAITING_FOR_PLAYER': return 'waiting for ' . $speaker;
        case 'SETTLE_QUEUED': return 'settling';
        case 'VERIFIED': return 'done';
        case 'UNMET': return 'not done';
        case 'IMPOSSIBLE': return 'impossible';
        case 'BETRAYED': return 'betrayed';
        case 'RECORDED': return 'noted';
    }
    return strtolower($status);
}

function stobeNpcViewTermText(array $t, string $npc, string $speaker): string {
    $who = ($t['by'] ?? '') === 'npc' ? $npc : $speaker;
    $kind = strtoupper(strval($t['kind'] ?? ''));
    $other = ($t['by'] ?? '') === 'npc' ? $speaker : $npc;
    switch ($kind) {
        case 'GIVE_CATS': $what = 'pays ' . intval($t['amount'] ?? 0) . ' Cats'; break;
        case 'GIVE_ITEM': $what = 'gives ' . (intval($t['count'] ?? ($t['quantity'] ?? 1)) > 1 ? intval($t['count'] ?? $t['quantity']) . ' ' : '') . strval($t['item'] ?? 'an item'); break;
        case 'STOP_ATTACK': $what = 'stops attacking'; break;
        case 'SPARE': $what = 'spares ' . $other; break;
        case 'PROMISE': $what = 'promises: ' . strval($t['text'] ?? ''); break;
        default:
            $what = strtolower(str_replace('_', ' ', $kind));
            if (!empty($t['item'])) $what .= ' ' . strval($t['item']);
            elseif (!empty($t['text'])) $what .= ': ' . strval($t['text']);
    }
    $line = $who . ' ' . $what;
    if (strlen($line) > 140) $line = substr($line, 0, 137) . '...';
    return $line;
}

function stobeNpcViewJsonArray(mixed $v): array {
    if (is_array($v)) return $v;
    $d = json_decode(strval($v ?? ''), true);
    return is_array($d) ? $d : [];
}

function stobeNpcViewHoursText(float $hours): string {
    if ($hours < 1) return max(1, intval(round($hours * 60))) . ' game min';
    return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . ' game h';
}

function stobeNpcViewAgeText(string $ts): string {
    $t = strtotime($ts);
    if ($t === false) return '';
    $s = max(0, time() - $t);
    if ($s < 90) return 'just now';
    if ($s < 5400) return intval(round($s / 60)) . ' min ago';
    if ($s < 172800) return intval(round($s / 3600)) . ' h ago';
    return intval(round($s / 86400)) . ' days ago';
}

function stobeNpcViewDeals(int $serial, string $npcName, string $speaker, int $gamets, array &$outstanding): array {
    $db = $GLOBALS['db'];
    if ($serial > 0) {
        $rows = $db->fetchAll(
            "SELECT * FROM stobe_social_contract WHERE npc_serial=$1 AND LOWER(player_name)=LOWER($2) ORDER BY updated_at DESC LIMIT 12",
            [$serial, $speaker]
        );
    } else {
        $rows = $db->fetchAll(
            "SELECT * FROM stobe_social_contract WHERE LOWER(npc_name)=LOWER($1) AND LOWER(player_name)=LOWER($2) ORDER BY updated_at DESC LIMIT 12",
            [$npcName, $speaker]
        );
    }
    $rows = is_array($rows) ? $rows : [];
    $open = ['PROPOSED', 'COUNTERED', 'ACCEPTED', 'AWAITING_PERFORMANCE'];
    $active = [];
    $past = [];
    foreach ($rows as $r) {
        $status = strtoupper(strval($r['status'] ?? ''));
        $npc = strval($r['npc_name'] ?? $npcName);
        $state = stobeNpcViewJsonArray($r['term_state'] ?? []);
        $terms = count($state) > 0 ? $state : stobeNpcViewJsonArray($r['terms'] ?? []);
        if (in_array($status, $open, true)) {
            $lines = ['- ' . ucfirst(stobeNpcViewDealStatusLabel($status, $npc, $speaker)) . ' (' . strtolower(strval($r['kind'] ?? 'deal')) . ')'];
            $done = 0; $total = 0;
            foreach ($terms as $t) {
                if (!is_array($t)) continue;
                $tStatus = strtoupper(strval($t['status'] ?? ''));
                if ($tStatus !== 'RECORDED') { $total++; if ($tStatus === 'VERIFIED') $done++; }
                $label = stobeNpcViewTermStatusLabel($tStatus, $speaker);
                $lines[] = '    ' . stobeNpcViewTermText($t, $npc, $speaker) . ($label !== '' ? ' [' . $label . ']' : '');
                if (strtoupper(strval($t['kind'] ?? '')) === 'GIVE_CATS' && $tStatus !== 'VERIFIED') {
                    $amount = intval($t['amount'] ?? 0);
                    $outstanding[] = (($t['by'] ?? '') === 'npc' ? $npc . ' owes ' . $speaker : $speaker . ' owes ' . $npc) . ' ' . $amount . ' Cats';
                }
            }
            if (count($state) > 0 && $total > 0) $lines[] = '    Progress: ' . $done . '/' . $total . ' terms done';
            $dg = intval($r['deadline_gamets'] ?? 0);
            $du = intval($r['deadline_unix'] ?? 0);
            if ($dg > 0 && $gamets > 0) {
                $left = ($dg - $gamets) / 3600.0;
                $lines[] = '    Deadline: ' . ($left > 0 ? 'in ' . stobeNpcViewHoursText($left) : 'passed');
            } elseif ($du > 0) {
                $left = $du - time();
                $lines[] = '    Deadline: ' . ($left > 0 ? 'in ' . max(1, intval(round($left / 60))) . ' min (real time)' : 'passed');
            }
            $active[] = implode("\n", $lines);
        } elseif (count($past) < 3) {
            $summary = [];
            foreach ($terms as $t) {
                if (is_array($t) && strtoupper(strval($t['status'] ?? '')) !== 'RECORDED') $summary[] = stobeNpcViewTermText($t, $npc, $speaker);
                if (count($summary) >= 2) break;
            }
            $past[] = '- ' . ucfirst(stobeNpcViewDealStatusLabel($status, $npc, $speaker))
                . (count($summary) > 0 ? ': ' . implode('; ', $summary) : '')
                . ' (' . stobeNpcViewAgeText(strval($r['updated_at'] ?? '')) . ')';
        }
    }
    return [$active, $past];
}

function stobeNpcViewGoal(int $serial, string $npcName): array {
    $db = $GLOBALS['db'];
    $rows = [];
    foreach (['stobe_task_goal_runtime', 'stobe_work_goal'] as $table) {
        try {
            $r = $db->fetchAll(
                "SELECT *, '" . $table . "' AS src FROM " . $table . "
                 WHERE (actor_serial > 0 AND actor_serial = $1) OR (COALESCE(actor_serial,0) = 0 AND LOWER(actor_name) = LOWER($2))
                 ORDER BY updated_at DESC LIMIT 3",
                [$serial, $npcName]
            );
            if (is_array($r)) $rows = array_merge($rows, $r);
        } catch (Throwable $e) {
        }
    }
    usort($rows, static fn($a, $b) => strcmp(strval($b['updated_at'] ?? ''), strval($a['updated_at'] ?? '')));
    if (count($rows) === 0) return [];
    $terminal = ['COMPLETE', 'CANCELLED', 'FAILED', 'IMPOSSIBLE'];
    $pick = null;
    foreach ($rows as $r) {
        if (!in_array(strtoupper(strval($r['status'] ?? '')), $terminal, true)) { $pick = $r; break; }
    }
    $isLast = $pick === null;
    if ($isLast) $pick = $rows[0];
    $kind = str_replace('_', ' ', strtolower(strval($pick['kind'] ?? 'make')));
    $item = strval($pick['item_name'] ?? '');
    $qty = intval($pick['quantity'] ?? 0);
    $what = ucfirst($kind) . ' ' . ($qty > 1 ? $qty . ' ' : '') . $item;
    $dest = trim(strval($pick['destination_name'] ?? ''));
    if ($dest !== '') $what .= ' -> ' . $dest;
    $target = trim(strval($pick['target_name'] ?? ''));
    if ($target !== '' && strcasecmp($target, $dest) !== 0) $what .= ' (' . $target . ')';
    $status = strtoupper(strval($pick['status'] ?? ''));
    $lines = [];
    $lines[] = ($isLast ? 'Last agreed goal: ' : 'Agreed goal: ') . $what;
    $lines[] = '    Status: ' . strtolower($status) . ($qty > 0 ? ', ' . intval($pick['completed'] ?? 0) . '/' . $qty . ' done' : '')
        . ' (reported ' . stobeNpcViewAgeText(strval($pick['updated_at'] ?? '')) . ')';
    $step = trim(strval($pick['current_step'] ?? ''));
    if ($step !== '' && !$isLast) $lines[] = '    Step: ' . $step;
    $reason = trim(strval($pick['reason'] ?? ''));
    if ($reason !== '' && ($status === 'BLOCKED' || $status === 'CANCELLED')) $lines[] = '    Blocked: ' . (strlen($reason) > 160 ? substr($reason, 0, 157) . '...' : $reason);
    return $lines;
}

/**
 * Builds the panel text. $p: storage_id or serial (target), name, speaker, gamets,
 * live_activity, live_faction, trader (flags from the game thread).
 */
function stobeNpcPlayerViewText(array $p): array {
    $db = $GLOBALS['db'];
    $serial = intval($p['serial'] ?? 0);
    $sid = normalizeStorageIdToken(strval($p['storage_id'] ?? ($serial > 0 ? 'hand_' . $serial : '')));
    $name = trim(strval($p['name'] ?? ''));
    $speaker = trim(strval($p['speaker'] ?? ''));
    $gamets = intval($p['gamets'] ?? 0);
    if ($gamets <= 0 && function_exists('stobeNegLatestGamets')) $gamets = stobeNegLatestGamets();

    $row = false;
    if ($sid !== '') {
        $row = $db->fetchOne(
            "SELECT * FROM core_npc WHERE COALESCE(metadata->>'storage_id','') = $1 ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC LIMIT 1",
            [$sid]
        );
    }
    $displayName = $name !== '' ? $name : strval($row['name'] ?? 'Unknown');
    $lines = [];
    $lines[] = $displayName;
    $faction = stobeNpcViewCleanFaction(strval($p['live_faction'] ?? ''));
    if ($faction === '' && is_array($row)) {
        $faction = stobeNpcViewCleanFaction(strval($row['faction'] ?? ''));
        if ($faction !== '') $faction .= ' (last known)';
    }
    $lines[] = 'Faction: ' . ($faction !== '' ? $faction : 'unknown');

    $facts = ($sid !== '' && $speaker !== '') ? stobeNpcFactsFor($sid, $speaker, 0) : [];
    $occupation = '';
    foreach ($facts as $f) {
        if (($f['category'] ?? '') === 'occupation') { $occupation = strval($f['fact']) . ' (they told you)'; break; }
    }
    if ($occupation === '' && !empty($p['trader'])) $occupation = 'Trader (seen trading)';
    $lines[] = 'Occupation: ' . ($occupation !== '' ? $occupation : 'unknown');
    if (!is_array($row)) $lines[] = '(No Stobe record for this character yet.)';

    // Deals
    $lines[] = '';
    $lines[] = 'DEALS WITH ' . strtoupper($speaker !== '' ? $speaker : 'YOU');
    $outstanding = [];
    [$active, $past] = $speaker !== '' ? stobeNpcViewDeals($serial, $displayName, $speaker, $gamets, $outstanding) : [[], []];
    if (count($active) === 0 && count($past) === 0) $lines[] = 'No deals.';
    foreach ($active as $a) $lines[] = $a;
    if (count($outstanding) > 0) $lines[] = 'Outstanding: ' . implode('; ', array_unique($outstanding));
    if (count($past) > 0) { $lines[] = 'Earlier:'; foreach ($past as $x) $lines[] = $x; }

    // Activity
    $lines[] = '';
    $lines[] = 'ACTIVITY';
    $goal = stobeNpcViewGoal($serial, $displayName);
    foreach ($goal as $g) $lines[] = $g;
    $live = trim(strval($p['live_activity'] ?? ''));
    $lines[] = 'Doing now: ' . ($live !== '' ? $live : 'not visible');
    if (count($goal) === 0) $lines[] = 'No agreed goal.';

    // Relationship
    $lines[] = '';
    $lines[] = 'RELATIONSHIP WITH ' . strtoupper($speaker !== '' ? $speaker : 'YOU');
    $rel = 'No opinion of ' . ($speaker !== '' ? $speaker : 'you') . ' yet (neutral).';
    if (is_array($row) && $speaker !== '') {
        $map = stobeGetNpcRelationshipMap($row);
        $key = stobeFindRelationshipEntryKey($map, $speaker);
        if ($key !== '' && is_array($map[$key] ?? null)) {
            $aff = intval($map[$key]['aff'] ?? 0);
            $type = stobeNormalizeRelationshipTypeToken(strval($map[$key]['type'] ?? 'neutral'));
            $rel = stobeRelationshipTierLabel($aff) . ($type !== '' && $type !== 'neutral' ? ' (' . $type . ')' : '');
        }
    }
    $lines[] = $rel;

    // Knowledge
    $lines[] = '';
    $lines[] = 'WHAT ' . strtoupper($speaker !== '' ? $speaker : 'YOU') . ' KNOWS';
    $told = [];
    foreach ($facts as $f) {
        if (count($told) >= STOBE_NPC_FACT_MAX_SHOWN) break;
        $cat = strval($f['category'] ?? 'background');
        $told[] = '- ' . strval($f['fact']) . ($cat === 'interest' ? ' (interest)' : ($cat === 'history' ? ' (shared past)' : ''));
    }
    if (count($told) > 0) { $lines[] = 'They told you (not verified):'; foreach ($told as $t) $lines[] = $t; }
    if (count($past) > 0 || count($active) > 0) $lines[] = 'You saw: ' . (count($active) + count($past)) . ' deal(s) between you, listed above.';
    if (count($told) === 0) $lines[] = 'Nothing learned about their past yet.';

    return ['text' => implode("\n", $lines), 'storage_id' => $sid, 'facts' => count($facts), 'gamets' => $gamets];
}
