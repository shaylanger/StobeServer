<?php
/**
 * NPC info panel (player view): a compact, read-only summary of one NPC as the
 * speaking squad character knows them, laid out as a biography card (header fields,
 * About them, What you've learned, Dealings with you, Right now). Built from stored
 * game/server state; the only LLM call is the cached "What you've learned" paragraph
 * (stobeNpcBioFor), written from the NPC's own lines to that listener and the facts
 * they disclosed, regenerated only when new dialogue/facts exist (max once per 60 s).
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

// ------------------------------------------------------------------ biography card

const STOBE_NPC_BIO_MIN_REGEN_SECONDS = 60;
const STOBE_NPC_BIO_DIALOGUE_TYPES = ['chat', 'rechat', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];
const STOBE_NPC_BIO_EMPTY = "You don't know much about them yet. Talk to them to learn more.";
const STOBE_NPC_BIO_MAX_LINES = 40;

function stobeNpcBioEnsureSchema(): void {
    static $done = false;
    if ($done) return;
    $db = $GLOBALS['db'];
    $db->exec(
        "CREATE TABLE IF NOT EXISTS stobe_npc_bio (
            id BIGSERIAL PRIMARY KEY,
            npc_storage_id TEXT NOT NULL,
            npc_name TEXT NOT NULL DEFAULT '',
            learner_name TEXT NOT NULL,
            bio TEXT NOT NULL DEFAULT '',
            source_rowid_max BIGINT NOT NULL DEFAULT 0,
            fact_count INT NOT NULL DEFAULT 0,
            source_gamets BIGINT NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )"
    );
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_stobe_npc_bio ON stobe_npc_bio (npc_storage_id, LOWER(learner_name))");
    $done = true;
}

function stobeNpcBioLikeEscape(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/** "Name: text (talking to: X)" -> "text". */
function stobeNpcBioCleanLine(string $data): string {
    $t = trim(preg_replace('/\s+/', ' ', $data) ?? '');
    $t = preg_replace('/\s*\((?:talking to:?|to:)\s*[^)]*\)\s*$/i', '', $t) ?? $t;
    if (preg_match('/^[^:]{1,80}:\s+(.*)$/s', $t, $m)) $t = $m[1];
    $t = trim($t);
    if (strlen($t) > 300) $t = rtrim(substr($t, 0, 297)) . '...';
    return $t;
}

/**
 * Lines spoken between the NPC and the learner (eventlog rows whose people list holds both),
 * chronological. Only rows spoken BY the NPC or BY the learner count; the first people entry is the speaker.
 */
function stobeNpcBioDialogue(string $sid, string $learner, int $limit = 400): array {
    $out = ['lines' => [], 'npc_lines' => 0, 'rowid_max' => 0, 'gamets_max' => 0, 'first_gamets' => 0, 'talks' => 0];
    if ($sid === '' || $learner === '') return $out;
    $types = "'" . implode("','", STOBE_NPC_BIO_DIALOGUE_TYPES) . "'";
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT rowid, type, gamets, people, data FROM eventlog
         WHERE type IN ($types) AND people LIKE $1 AND people ILIKE $2
         ORDER BY rowid DESC LIMIT " . max(1, $limit),
        ['%|' . stobeNpcBioLikeEscape($sid) . '"%', '%"' . stobeNpcBioLikeEscape($learner) . '|%']
    );
    $rows = is_array($rows) ? array_reverse($rows) : [];
    $lastTs = null;
    foreach ($rows as $r) {
        $people = json_decode(strval($r['people'] ?? ''), true);
        if (!is_array($people) || count($people) === 0) continue;
        $first = strval($people[0]);
        $pipe = strrpos($first, '|');
        $firstName = $pipe === false ? $first : substr($first, 0, $pipe);
        $firstSid = $pipe === false ? '' : substr($first, $pipe + 1);
        $data = strval($r['data'] ?? '');
        if ($firstSid === $sid) {
            // NPC line: only when addressed to the learner (or to nobody in particular)
            if (preg_match('/\((?:talking to:?|to:)\s*([^)]*)\)\s*$/i', $data, $m) && stripos($m[1], $learner) === false) continue;
            $who = 'npc';
        } elseif (strcasecmp(trim($firstName), $learner) === 0) {
            $who = 'you';
        } else {
            continue;
        }
        $text = stobeNpcBioCleanLine($data);
        if ($text === '') continue;
        $ts = intval($r['gamets'] ?? 0);
        $out['lines'][] = ['who' => $who, 'text' => $text, 'gamets' => $ts];
        if ($who === 'npc') $out['npc_lines']++;
        $out['rowid_max'] = max($out['rowid_max'], intval($r['rowid'] ?? 0));
        $out['gamets_max'] = max($out['gamets_max'], $ts);
        if ($out['first_gamets'] === 0 || ($ts > 0 && $ts < $out['first_gamets'])) $out['first_gamets'] = $ts;
        // a new conversation starts after an hour of game time without a word between them
        if ($lastTs === null || $ts - $lastTs > 3600) $out['talks']++;
        $lastTs = $ts;
    }
    return $out;
}

function stobeNpcBioCache(string $sid, string $learner): array|false {
    stobeNpcBioEnsureSchema();
    $row = $GLOBALS['db']->fetchOne(
        "SELECT *, (attempted_at IS NOT NULL AND attempted_at > NOW() - make_interval(secs => $3)) AS throttled
         FROM stobe_npc_bio WHERE npc_storage_id=$1 AND LOWER(learner_name)=LOWER($2) LIMIT 1",
        [$sid, $learner, STOBE_NPC_BIO_MIN_REGEN_SECONDS]
    );
    return is_array($row) ? $row : false;
}

function stobeNpcBioCleanReply(string $raw): string {
    $t = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $raw) ?? $raw;
    $t = preg_replace('/^```[a-z]*\s*|```$/im', '', $t) ?? $t;
    $t = trim(preg_replace('/\s+/', ' ', $t) ?? '');
    $t = preg_replace('/^(bio(graphy)?|summary)\s*:\s*/i', '', $t) ?? $t;
    $t = trim($t, " \"'");
    $sentences = preg_split('/(?<=[.!?])\s+/', $t) ?: [$t];
    $t = implode(' ', array_slice($sentences, 0, 6));
    if (strlen($t) > 900) $t = rtrim(substr($t, 0, 897)) . '...';
    return $t;
}

/** The bio prompt: built only from the dialogue lines and told facts (never the stored profile). */
function stobeNpcBioMessages(string $npcName, string $learner, array $lines, array $facts, string $pronoun = 'they'): array {
    $system = "<npc_card_biography>\n"
        . "  <rule>Write the 'What you've learned' paragraph of a character card in a game UI.</rule>\n"
        . "  <rule>Use ONLY what the character said in the conversation lines and the told facts below. Do not invent anything, do not guess, do not add lore.</rule>\n"
        . "  <rule>Third person about the character, plain everyday English, 2 to 5 short sentences, no lists, no headings, no quotes, no names of the reader.</rule>\n"
        . "  <rule>Prefer what they said about themselves (origin, work, family, interests, plans). If they said nothing personal, say in one or two sentences how they have come across when talking.</rule>\n"
        . "  <rule>Reply with the paragraph only.</rule>\n"
        . "</npc_card_biography>";
    $conv = [];
    foreach (array_slice($lines, -STOBE_NPC_BIO_MAX_LINES) as $l) {
        $conv[] = ($l['who'] === 'npc' ? $npcName : $learner) . ': ' . $l['text'];
    }
    $told = [];
    foreach ($facts as $f) $told[] = '- ' . strval($f['fact'] ?? '');
    $user = "<character>" . stobePromptXmlEscape($npcName) . "</character>\n"
        . "<pronoun>" . stobePromptXmlEscape($pronoun) . "</pronoun>\n"
        . "<reader>" . stobePromptXmlEscape($learner) . "</reader>\n"
        . "<told_facts>\n" . stobePromptXmlEscape(count($told) > 0 ? implode("\n", $told) : '(none)') . "\n</told_facts>\n"
        . "<conversation>\n" . stobePromptXmlEscape(implode("\n", $conv)) . "\n</conversation>";
    return [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
}

function stobeNpcBioCallLlm(array $messages, array|false $npcRow, string $npcName): string|false {
    $stub = $GLOBALS['STOBE_NPC_BIO_LLM'] ?? null; // tests inject a callable
    if (is_callable($stub)) return $stub($messages);
    if (!function_exists('stobeCallLLM')) require_once dirname(__DIR__) . '/connector/llm_dispatcher.php';
    $config = getLlmConfigForNpcPurpose($npcRow, 'relationship');
    return stobeCallLLM($messages, $config, ['npc_name' => $npcName, 'event_type' => 'npc_bio']);
}

/**
 * The growing biography for (NPC, learner). $generate=false never calls the LLM: it returns the cache and
 * 'stale'=true when new dialogue/facts exist and a regeneration is allowed (DLL then asks again with bio=1).
 * $generate=true regenerates at most once per STOBE_NPC_BIO_MIN_REGEN_SECONDS per pair.
 * state: empty | cached | pending | updated
 */
function stobeNpcBioFor(string $sid, string $npcName, string $learner, array|false $npcRow, array $dialogue, array $facts, bool $generate, bool $quiet = false): array {
    $res = ['bio' => '', 'state' => 'empty', 'stale' => false];
    if ($sid === '' || $learner === '') return $res;
    if ($dialogue['npc_lines'] === 0 && count($facts) === 0) return $res;
    $cache = stobeNpcBioCache($sid, $learner);
    $cachedBio = is_array($cache) ? strval($cache['bio'] ?? '') : '';
    $fresh = is_array($cache) && $cachedBio !== ''
        && intval($cache['source_rowid_max']) === intval($dialogue['rowid_max'])
        && intval($cache['fact_count']) === count($facts);
    $throttled = is_array($cache) && ($cache['throttled'] === true || $cache['throttled'] === 't');
    $res['bio'] = $cachedBio;
    $res['state'] = $cachedBio !== '' ? 'cached' : 'empty';
    if ($fresh) {
        if (!$quiet) stobeLogInfo('NPC_BIO: cache hit', ['npc' => $npcName, 'sid' => $sid, 'learner' => $learner, 'rowid' => $dialogue['rowid_max'], 'facts' => count($facts)]);
        return $res;
    }
    if ($throttled) {
        if (!$quiet) stobeLogInfo('NPC_BIO: throttled', ['npc' => $npcName, 'sid' => $sid, 'learner' => $learner]);
        return $res;
    }
    if (!$generate) {
        $res['state'] = 'pending';
        $res['stale'] = true;
        return $res;
    }
    $db = $GLOBALS['db'];
    $srcGamets = intval($dialogue['gamets_max']);
    foreach ($facts as $f) $srcGamets = max($srcGamets, intval($f['game_ts'] ?? 0));
    $db->exec(
        "INSERT INTO stobe_npc_bio (npc_storage_id, npc_name, learner_name, attempted_at) VALUES ($1,$2,$3,NOW())
         ON CONFLICT (npc_storage_id, LOWER(learner_name)) DO UPDATE SET attempted_at=NOW(), npc_name=EXCLUDED.npc_name",
        [$sid, $npcName, $learner]
    );
    $t0 = microtime(true);
    $raw = false;
    try {
        $g = is_array($npcRow) ? strtolower(trim(strval($npcRow['gender'] ?? ''))) : '';
        $pronoun = $g === 'female' ? 'she' : ($g === 'male' ? 'he' : 'they');
        $raw = stobeNpcBioCallLlm(stobeNpcBioMessages($npcName, $learner, $dialogue['lines'], $facts, $pronoun), $npcRow, $npcName);
    } catch (Throwable $e) {
        stobeLogWarn('NPC_BIO: llm failed', ['npc' => $npcName, 'error' => $e->getMessage()]);
    }
    $bio = $raw !== false ? stobeNpcBioCleanReply(strval($raw)) : '';
    if ($bio === '') {
        stobeLogWarn('NPC_BIO: llm returned nothing', ['npc' => $npcName, 'sid' => $sid, 'learner' => $learner]);
        return $res;
    }
    $db->exec(
        "UPDATE stobe_npc_bio SET bio=$3, source_rowid_max=$4, fact_count=$5, source_gamets=$6, updated_at=NOW()
         WHERE npc_storage_id=$1 AND LOWER(learner_name)=LOWER($2)",
        [$sid, $learner, $bio, intval($dialogue['rowid_max']), count($facts), $srcGamets]
    );
    stobeLogInfo('NPC_BIO: generated', ['npc' => $npcName, 'sid' => $sid, 'learner' => $learner, 'lines' => count($dialogue['lines']),
        'facts' => count($facts), 'rowid' => $dialogue['rowid_max'], 'ms' => intval((microtime(true) - $t0) * 1000)]);
    return ['bio' => $bio, 'state' => 'updated', 'stale' => false];
}

// ------------------------------------------------------------------ header fields

/** "Jora [Slavemonger Guard]" -> ["Jora", "Slavemonger Guard"] */
function stobeNpcViewSplitTitle(string $name): array {
    if (preg_match('/^(.*?)\s*\[([^\]]+)\]\s*$/', $name, $m) && trim($m[1]) !== '') return [trim($m[1]), trim($m[2])];
    return [trim($name), ''];
}

function stobeNpcViewArticle(string $word): string {
    return preg_match('/^[aeiou]/i', $word) ? 'an' : 'a';
}

/** Visible looks parsed from the stored appearance + race/gender columns. */
function stobeNpcViewLooks(array|false $row): array {
    $looks = ['race' => '', 'gender' => '', 'age' => '', 'build' => '', 'extras' => [], 'raw' => ''];
    if (!is_array($row)) return $looks;
    $looks['race'] = trim(strval($row['race'] ?? ''));
    if (strcasecmp($looks['race'], 'Unknown') === 0) $looks['race'] = '';
    $g = strtolower(trim(strval($row['gender'] ?? '')));
    $looks['gender'] = in_array($g, ['male', 'female'], true) ? $g : '';
    $app = trim(preg_replace('/\s+/', ' ', strval($row['appearance'] ?? '')) ?? '');
    $looks['raw'] = $app;
    if (preg_match('/with an? ([a-z-]+) look\./i', $app, $m)) $looks['age'] = strtolower($m[1]);
    if (preg_match('/Build appears ([a-z]+)\./i', $app, $m)) $looks['build'] = strtolower($m[1]);
    if (stripos($app, 'facial hair') !== false) $looks['extras'][] = 'facial hair';
    if (stripos($app, 'head is shaved') !== false) $looks['extras'][] = 'a shaved head';
    if (stripos($app, 'flayed') !== false) $looks['extras'][] = 'heavy scarring';
    return $looks;
}

function stobeNpcViewGenderNoun(array $looks): string {
    if ($looks['gender'] === '' || preg_match('/hive|skeleton|robot|animal/i', $looks['race'])) return '';
    return $looks['gender'] === 'female' ? 'woman' : 'man';
}

/** "Greenlander woman, older" */
function stobeNpcViewRaceLine(array $looks): string {
    $parts = array_values(array_filter([$looks['race'], stobeNpcViewGenderNoun($looks)], 'strlen'));
    $line = implode(' ', $parts);
    if ($line === '' && $looks['gender'] !== '') $line = ucfirst($looks['gender']);
    if ($line !== '' && $looks['age'] !== '') $line .= ', ' . $looks['age'];
    return $line;
}

/** 1-2 natural sentences from visible info only (looks, job, faction). */
function stobeNpcViewAbout(array $looks, string $job, string $faction): string {
    $sent = [];
    $noun = trim($looks['race'] . ' ' . stobeNpcViewGenderNoun($looks));
    if ($noun !== '' && ($looks['age'] !== '' || $looks['build'] !== '')) {
        $adj = [];
        if ($looks['build'] === 'short' || $looks['build'] === 'tall') $adj[] = $looks['build'];
        if ($looks['age'] !== '') $adj[] = $looks['age'];
        $phrase = trim(implode(', ', $adj) . ' ' . $noun);
        $s = ucfirst(stobeNpcViewArticle($phrase) . ' ' . $phrase);
        if (count($looks['extras']) > 0) $s .= ' with ' . implode(' and ', $looks['extras']);
        $sent[] = $s . '.';
    } elseif ($looks['raw'] !== '') {
        $s = preg_replace('/\ba ([aeiou])/i', 'an $1', $looks['raw']) ?? $looks['raw'];
        $s = ucfirst(rtrim($s));
        if (!preg_match('/[.!?]$/', $s)) $s .= '.';
        if (strlen($s) > 240) $s = rtrim(substr($s, 0, 237)) . '...';
        $sent[] = $s;
    }
    $pron = $looks['gender'] === 'female' ? 'She' : ($looks['gender'] === 'male' ? 'He' : 'They');
    $jobL = $job !== '' && strcasecmp($job, 'unknown') !== 0 ? $job : '';
    $facL = preg_replace('/\s*\(last known\)$/', '', $faction) ?? $faction;
    $facL = strcasecmp($facL, 'unknown') === 0 ? '' : $facL;
    $works = $pron === 'They' ? 'work' : 'works';
    $is = $pron === 'They' ? 'are' : 'is';
    if ($jobL !== '' && $facL !== '') $sent[] = $pron . ' ' . $works . ' as ' . stobeNpcViewArticle($jobL) . ' ' . strtolower($jobL) . ' and ' . $is . ' with the ' . $facL . '.';
    elseif ($jobL !== '') $sent[] = $pron . ' ' . $works . ' as ' . stobeNpcViewArticle($jobL) . ' ' . strtolower($jobL) . '.';
    elseif ($facL !== '') $sent[] = $pron . ' ' . $is . ' with the ' . $facL . '.';
    return count($sent) > 0 ? implode(' ', $sent) : 'You know nothing about how they look yet.';
}

function stobeNpcViewRelation(array|false $row, string $speaker): array {
    $label = 'Neutral';
    $line = 'They have no opinion of you yet.';
    if (!is_array($row) || $speaker === '') return [$label, $line];
    $map = stobeGetNpcRelationshipMap($row);
    $key = stobeFindRelationshipEntryKey($map, $speaker);
    if ($key === '' || !is_array($map[$key] ?? null)) return [$label, $line];
    $aff = intval($map[$key]['aff'] ?? 0);
    $type = stobeNormalizeRelationshipTypeToken(strval($map[$key]['type'] ?? 'neutral'));
    $label = stobeRelationshipTierLabel($aff) . ($type !== '' && $type !== 'neutral' ? ' (' . $type . ')' : '');
    if ($aff >= 76) $line = 'They are deeply attached to you.';
    elseif ($aff >= 56) $line = 'They like you a lot.';
    elseif ($aff >= 31) $line = 'They like you.';
    elseif ($aff >= 6) $line = 'They know you a little.';
    elseif ($aff >= -5) $line = 'They have no strong feelings about you.';
    elseif ($aff >= -30) $line = 'They are wary of you.';
    elseif ($aff >= -55) $line = "They don't like you.";
    elseif ($aff >= -90) $line = 'They resent you.';
    else $line = 'They hate you.';
    return [$label, $line];
}

function stobeNpcViewGameAgo(int $seconds): string {
    if ($seconds < 3600) return 'less than an hour ago';
    if ($seconds < 172800) return intval(round($seconds / 3600)) . ' hours ago';
    return intval(round($seconds / 86400)) . ' days ago';
}

/**
 * Builds the NPC biography card. $p: storage_id or serial (target), name, speaker, gamets,
 * live_activity, live_faction, trader (flags from the game thread), bio (1 = may call the LLM), why.
 * Returns structured fields for the DLL plus 'text' (the whole card as plain text).
 */
function stobeNpcPlayerViewText(array $p): array {
    $db = $GLOBALS['db'];
    $serial = intval($p['serial'] ?? 0);
    $sid = normalizeStorageIdToken(strval($p['storage_id'] ?? ($serial > 0 ? 'hand_' . $serial : '')));
    $name = trim(strval($p['name'] ?? ''));
    $speaker = trim(strval($p['speaker'] ?? ''));
    $gamets = intval($p['gamets'] ?? 0);
    if ($gamets <= 0 && function_exists('stobeNegLatestGamets')) $gamets = stobeNegLatestGamets();
    $generate = !empty($p['bio']);
    $quiet = strval($p['why'] ?? '') === 'periodic';

    $row = false;
    if ($sid !== '') {
        $row = $db->fetchOne(
            "SELECT * FROM core_npc WHERE COALESCE(metadata->>'storage_id','') = $1 ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC LIMIT 1",
            [$sid]
        );
    }
    $fullName = $name !== '' ? $name : strval($row['name'] ?? 'Unknown');
    [$displayName, $title] = stobeNpcViewSplitTitle($fullName);
    $you = $speaker !== '' ? $speaker : 'you';

    $faction = stobeNpcViewCleanFaction(strval($p['live_faction'] ?? ''));
    if ($faction === '' && is_array($row)) {
        $faction = stobeNpcViewCleanFaction(strval($row['faction'] ?? ''));
        if ($faction !== '') $faction .= ' (last known)';
    }

    $facts = ($sid !== '' && $speaker !== '') ? stobeNpcFactsFor($sid, $speaker, 0) : [];
    $job = $title;
    if ($job === '' && !empty($p['trader'])) $job = 'Trader';
    if ($job === '') {
        foreach ($facts as $f) {
            if (($f['category'] ?? '') === 'occupation') { $job = ucfirst(strval($f['fact'])) . ' (they told you)'; break; }
        }
    }

    $looks = stobeNpcViewLooks($row);
    $raceLine = stobeNpcViewRaceLine($looks);
    [$relLabel, $relLine] = stobeNpcViewRelation($row, $speaker);

    $dialogue = stobeNpcBioDialogue($sid, $speaker);
    if ($dialogue['talks'] > 0) {
        $talked = 'Talked ' . $dialogue['talks'] . ($dialogue['talks'] === 1 ? ' time' : ' times');
        if ($dialogue['first_gamets'] > 0 && $gamets > 0) $talked .= ', first met ' . stobeNpcViewGameAgo(max(0, $gamets - $dialogue['first_gamets']));
    } else {
        $talked = "You haven't talked yet";
    }

    $about = stobeNpcViewAbout($looks, preg_replace('/\s*\(they told you\)$/', '', $job) ?? $job, $faction);
    $bio = stobeNpcBioFor($sid, $displayName, $speaker, $row, $dialogue, $facts, $generate, $quiet);

    // Dealings
    $outstanding = [];
    [$active, $past] = $speaker !== '' ? stobeNpcViewDeals($serial, $fullName, $speaker, $gamets, $outstanding) : [[], []];
    $deals = [];
    if (count($active) === 0 && count($past) === 0) $deals[] = 'No deals with ' . $you . ' yet.';
    foreach ($active as $a) $deals[] = $a;
    if (count($outstanding) > 0) $deals[] = 'Outstanding: ' . implode('; ', array_unique($outstanding));
    if (count($past) > 0) { $deals[] = 'Earlier:'; foreach ($past as $x) $deals[] = $x; }

    // Right now
    $now = stobeNpcViewGoal($serial, $fullName);
    $live = trim(strval($p['live_activity'] ?? ''));
    $now[] = 'Doing now: ' . ($live !== '' ? $live : 'not visible');

    // What you've learned
    $learned = [];
    if ($bio['bio'] !== '') $learned[] = $bio['bio'];
    elseif ($bio['state'] !== 'pending') {
        if (count($facts) > 0) {
            $learned[] = 'They told you:';
            foreach (array_slice($facts, 0, STOBE_NPC_FACT_MAX_SHOWN) as $f) $learned[] = '- ' . strval($f['fact']);
        } else {
            $learned[] = STOBE_NPC_BIO_EMPTY;
        }
    }
    if ($bio['state'] === 'pending') $learned[] = 'Updating...';

    $header = [$displayName];
    $header[] = 'Job: ' . ($job !== '' ? $job : 'unknown');
    $header[] = 'Faction: ' . ($faction !== '' ? $faction : 'unknown');
    if ($raceLine !== '') $header[] = $raceLine;
    $header[] = 'Relationship: ' . $relLabel . ' - ' . lcfirst($relLine);
    $header[] = $talked;
    if (!is_array($row)) $header[] = '(No Stobe record for this character yet.)';

    $body = [];
    $body[] = 'ABOUT THEM';
    $body[] = $about;
    $body[] = '';
    $body[] = "WHAT YOU'VE LEARNED";
    foreach ($learned as $l) $body[] = $l;
    $body[] = '';
    $body[] = 'DEALINGS WITH ' . strtoupper($you);
    foreach ($deals as $d) $body[] = $d;
    $body[] = '';
    $body[] = 'RIGHT NOW';
    foreach ($now as $n) $body[] = $n;

    return [
        'text' => implode("\n", array_merge($header, [''], $body)),
        'body' => implode("\n", $body),
        'name' => $displayName,
        'job' => $job !== '' ? $job : 'unknown',
        'faction' => $faction !== '' ? $faction : 'unknown',
        'race_line' => $raceLine,
        'relation_label' => $relLabel,
        'relation_line' => $relLine,
        'talked_line' => $talked,
        'about' => $about,
        'bio' => $bio['bio'],
        'bio_state' => $bio['state'],
        'bio_stale' => $bio['stale'] ? 1 : 0,
        'deals' => implode("\n", $deals),
        'now' => implode("\n", $now),
        'storage_id' => $sid,
        'facts' => count($facts),
        'gamets' => $gamets,
    ];
}
