<?php

/**
 * Lifelike NPC context helpers.
 *
 * This layer deliberately uses only data STOBE already has. It does not make an
 * extra LLM request during chat, so it adds context without adding model
 * latency. Dynamic profile generation is responsible for deeper long-lived
 * identity; this file selects recent/important world facts for each turn.
 */

function stobeLifelikeDecodeAssoc(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function stobeLifelikeEventScore(array $row): int
{
    $type = strtolower(trim(strval($row['type'] ?? '')));
    $data = strtolower(trim(strval($row['data'] ?? '')));
    $score = 10;

    $weights = [
        'death' => 100, 'dead' => 100, 'enslaved' => 100, 'freed_slave' => 95,
        'relationship' => 90, 'recruit' => 90, 'join' => 85, 'leave' => 85,
        'combatendmighty' => 85, 'combatend' => 75, 'combat_end' => 75,
        'combat' => 70, 'major_damage' => 85, 'limb_loss' => 95,
        'healing' => 70, 'knockout' => 75, 'carry' => 50,
        'trade' => 35, 'item_pickup' => 30, 'lockpicked' => 30,
        'infoloc' => 30, 'chat' => 25, 'inputtext' => 25, 'rechat' => 22,
        'bored' => 12,
    ];
    foreach ($weights as $needle => $weight) {
        if ($type === $needle || str_contains($type, $needle)) {
            $score = max($score, $weight);
        }
    }

    $isDialogue = in_array($type, ['chat', 'inputtext', 'rechat', 'bored'], true);
    if ($isDialogue) {
        // Dialogue can establish commitments/relationship facts, but do not
        // treat metaphorical words like "starving" or "dying" as physical
        // world events.
        if (preg_match('/\b(i love you|i hate you|i promise|we promise|never forgive|remember when|swear to you|i trust you|do not trust you)\b/i', $data) === 1) {
            $score = max($score, 70);
        } elseif (preg_match('/\b(tomorrow|next time|we will|we\'ll|plan to|promise|promised|meet at|head to|travel to|return to)\b/i', $data) === 1) {
            $score = max($score, 55);
        }
        return $score;
    }

    if (preg_match('/\b(died|killed|lost (?:an? )?(?:arm|leg|limb)|enslaved|escaped slavery|betray|saved .* life|rescued|married|revenge)\b/i', $data) === 1) {
        $score = max($score, 90);
    } elseif (preg_match('/\b(bleeding|wounded|injured|healed|attacked|ambush|beak thing|bandit|hungry|starving|unconscious)\b/i', $data) === 1) {
        $score = max($score, 65);
    } elseif (preg_match('/\b(tomorrow|later|next time|we will|we\'ll|plan to|promise|swore|meet at|head to|travel to|return to)\b/i', $data) === 1) {
        $score = max($score, 55);
    }

    return $score;
}

function stobeLifelikeCleanLine(array $row, int $max = 280): string
{
    $line = function_exists('stobeFormatEventHistoryLine')
        ? stobeFormatEventHistoryLine($row, true)
        : trim(strval($row['data'] ?? ''));
    $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
    if ($line === '') {
        return '';
    }
    if (strlen($line) > $max) {
        $line = substr($line, 0, $max) . '...';
    }
    return $line;
}

/**
 * Was this NPC part of the event (speaker, the one spoken to, or named in it)?
 * Nearby NPCs receive every line said around them; lines between other people
 * must not become this NPC's own situation or commitments.
 */
function stobeLifelikeRowInvolves(array $row, string $npcName): bool
{
    $base = static fn(string $n): string => strtolower(trim(preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $n) ?? $n));
    $npc = $base($npcName);
    if ($npc === '') {
        return true;
    }
    $data = trim(strval($row['data'] ?? ''));
    if (preg_match('/^([^:\r\n]{1,100}):\s*(.*?)(?:\((?:talking|whispering|shouting)\s+to:?\s*([^\)]+)\))?\s*$/us', $data, $m) === 1
        && isset($m[3]) && trim($m[3]) !== '') {
        return $base($m[1]) === $npc || $base($m[3]) === $npc
            || preg_match('/\b' . preg_quote($npc, '/') . '\b/iu', strval($m[2])) === 1;
    }
    // Non-dialogue events (combat, travel, actions): involved when named.
    return preg_match('/\b' . preg_quote($npc, '/') . '\b/iu', $data) === 1;
}

function stobeLifelikeFetchEvents(string $npcName, int $limit = 90): array
{
    static $cache = [];
    $safe = normalizeParticipantNameToken($npcName);
    if ($safe === '') {
        return [];
    }
    $limit = max(20, min(140, $limit));
    $key = strtolower($safe) . '|' . $limit;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    $visibility = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';

    try {
        $rows = $db->fetchAll(
            "SELECT rowid AS id, type, data, gamets, localts, ts, people, location
             FROM eventlog
             WHERE {$visibility}
               AND type NOT IN ('setconf', 'status_msg', 'npc_snapshot', 'playerinfo')
               AND (
                    LOWER(COALESCE(people, '')) LIKE LOWER($1)
                    OR LOWER(COALESCE(data, '')) LIKE LOWER($1)
               )
             ORDER BY rowid DESC
             LIMIT " . intval($limit),
            ['%' . $safe . '%']
        );
        $cache[$key] = is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        $cache[$key] = [];
        if (function_exists('stobeLogException')) {
            stobeLogException($e, 'Lifelike context event lookup failed', ['npc_name' => $safe]);
        }
    }
    return $cache[$key];
}

function stobeLifelikeCurrentLocation(string $npcName): string
{
    if (!function_exists('getEventGeoFromNpcName')) {
        return '';
    }
    $geo = getEventGeoFromNpcName($npcName);
    foreach (['building', 'location', 'city', 'zone', 'region'] as $key) {
        $value = trim(strval($geo[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/* Fetch a few durable memories tied to the current place from the full event
 * history, not just the recent-context window. This makes returning somewhere
 * months later capable of producing a real place callback without bloating the
 * ordinary prompt. */
function stobeLifelikeHistoricalPlaceEvents(string $npcName, string $location, int $limit = 4): array
{
    $db = $GLOBALS['db'] ?? null;
    $safeNpc = normalizeParticipantNameToken($npcName);
    $safeLocation = trim($location);
    if (!$db || $safeNpc === '' || $safeLocation === '') {
        return [];
    }
    $limit = max(1, min(6, $limit));
    $visibility = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';
    try {
        $rows = $db->fetchAll(
            "SELECT rowid AS id, type, data, gamets, localts, ts, people, location
             FROM eventlog
             WHERE {$visibility}
               AND LOWER(COALESCE(location, '')) = LOWER($2)
               AND (LOWER(COALESCE(people, '')) LIKE LOWER($1)
                    OR LOWER(COALESCE(data, '')) LIKE LOWER($1))
               AND type IN ('death','combat_end','combatend','combatendmighty','major_damage',
                            'limb_loss','healing','knockout','recovered','slavery','trade',
                            'relationship','recruit','carry','chat','inputtext','rechat')
             ORDER BY rowid DESC
             LIMIT " . intval($limit * 5),
            ['%' . $safeNpc . '%', $safeLocation]
        );
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || stobeLifelikeEventScore($row) < 30) {
                continue;
            }
            $line = stobeLifelikeCleanLine($row, 260);
            if ($line !== '') {
                $out[] = $line;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/* Select a tiny set of high-value older events from beyond the immediate
 * conversational window for occasional natural callbacks. */
/* Pull unresolved-looking plans/promises from already-compressed long-term
 * memory so commitments survive beyond the raw event window. This does not ask
 * the LLM again; it reuses memory summaries Stobe already generated. */
function stobeLifelikeMemoryCommitments(string $npcName, int $limit = 4): array
{
    $db = $GLOBALS['db'] ?? null;
    $safeNpc = normalizeParticipantNameToken($npcName);
    if (!$db || $safeNpc === '') {
        return [];
    }
    $limit = max(1, min(6, $limit));
    try {
        $rows = $db->fetchAll(
            "SELECT summary FROM memory_summary
             WHERE LOWER(COALESCE(people, '')) LIKE LOWER($1)
                OR LOWER(COALESCE(scope, '')) = LOWER($2)
             ORDER BY id DESC LIMIT 16",
            ['%' . $safeNpc . '%', $safeNpc]
        );
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $summary = trim(strval($row['summary'] ?? ''));
            if ($summary === '' || preg_match('/\b' . preg_quote($safeNpc, '/') . '\b/iu', $summary) !== 1) {
                continue; // a bystander's copy of someone else's conversation
            }
            foreach (preg_split('/\\r\\n|\\r|\\n/u', $summary) ?: [] as $rawLine) {
                $line = trim(strval($rawLine), " \t-*#");
                if ($line === '' || preg_match('/\\b(promise|promised|plan|planned|intends?|intended|goal|wants? to|will |going to|head to|travel to|return to|meet at|owes?|debt|agreed to)\\b/i', $line) !== 1) {
                    continue;
                }
                $line = truncatePromptValue($line, 280);
                $key = strtolower($line);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $line;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function stobeLifelikeDurableCallbacks(string $npcName, int $limit = 3): array
{
    $db = $GLOBALS['db'] ?? null;
    $safeNpc = normalizeParticipantNameToken($npcName);
    if (!$db || $safeNpc === '') {
        return [];
    }
    $limit = max(1, min(4, $limit));
    $visibility = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';
    try {
        $rows = $db->fetchAll(
            "SELECT rowid AS id, type, data, gamets, localts, ts, people, location
             FROM eventlog
             WHERE {$visibility}
               AND (LOWER(COALESCE(people, '')) LIKE LOWER($1)
                    OR LOWER(COALESCE(data, '')) LIKE LOWER($1))
               AND type IN ('death','combat_end','combatend','combatendmighty','major_damage',
                            'limb_loss','healing','knockout','recovered','slavery','relationship',
                            'recruit','trade','carry')
             ORDER BY rowid DESC
             LIMIT 240",
            ['%' . $safeNpc . '%']
        );
        $out = [];
        $seen = [];
        foreach ($rows as $idx => $row) {
            if (!is_array($row) || $idx < 12 || stobeLifelikeEventScore($row) < 65) {
                continue;
            }
            $line = stobeLifelikeCleanLine($row, 260);
            $key = strtolower($line);
            if ($line === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $line;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function stobeLifelikeLatestNearbyRosterNames(string $npcName): array
{
    $db = $GLOBALS['db'] ?? null;
    $safe = normalizeParticipantNameToken($npcName);
    if (!$db || $safe === '') {
        return [];
    }

    try {
        // Prefer telemetry emitted by this NPC/player. If none exists, use the
        // most recent nearby roster containing the NPC so companions can still
        // reason about the group around them.
        $row = $db->fetchOne(
            "SELECT data, people
             FROM eventlog
             WHERE type = 'infonpc'
               AND (
                    data ILIKE $1
                    OR COALESCE(people, '') ILIKE $2
               )
             ORDER BY rowid DESC
             LIMIT 1",
            [$safe . ': nearby NPC roster%', '%' . $safe . '%']
        );
        if (!is_array($row)) {
            return [];
        }
        $data = trim(strval($row['data'] ?? ''));
        if (preg_match('/nearby\s+NPC\s+roster\s*\([0-9]+\)\s*:\s*(.+)$/iu', $data, $m) !== 1) {
            return [];
        }
        $names = [];
        $seen = [];
        $appendName = static function (string $raw) use (&$names, &$seen, $safe): void {
            $name = normalizeParticipantNameToken(trim(str_replace('...', '', $raw)));
            if ($name === '' || strcasecmp($name, $safe) === 0) {
                return;
            }
            $key = strtolower($name);
            if (isset($seen[$key]) || count($names) >= 10) {
                return;
            }
            $seen[$key] = true;
            $names[] = $name;
        };

        // The event participant list includes the player/speaker, while the
        // roster text usually lists only the other nearby NPCs.
        if (function_exists('extractParticipantIdentities')) {
            $identities = extractParticipantIdentities(['people' => strval($row['people'] ?? '')]);
            foreach ($identities as $identity) {
                if (is_array($identity)) {
                    $appendName(strval($identity['name'] ?? ''));
                }
            }
        }

        foreach (explode(',', strval($m[1] ?? '')) as $piece) {
            $appendName(stobeRosterSplitState(strval($piece))[0]);
        }
        return $names;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * "Sorth [Dust Bandit] (dead)" -> ["Sorth [Dust Bandit]", "dead"] (bug 73).
 * Records the state in $GLOBALS['STOBE_ROSTER_STATES'][lowercase name].
 */
function stobeRosterSplitState(string $raw): array
{
    $raw = trim($raw);
    $state = '';
    if (preg_match('/^(.*?)\s*\((dead|unconscious)\)\s*$/iu', $raw, $m) === 1) {
        $raw = trim($m[1]);
        $state = strtolower($m[2]);
    }
    if ($raw !== '') {
        $GLOBALS['STOBE_ROSTER_STATES'][strtolower($raw)] = $state;
    }
    return [$raw, $state];
}

function stobeRosterState(string $name): string
{
    return strval($GLOBALS['STOBE_ROSTER_STATES'][strtolower(trim($name))] ?? '');
}

function stobeLifelikeNearbySquadLines(array $npcData, string $npcName): array
{
    $names = stobeLifelikeLatestNearbyRosterNames($npcName);
    if (count($names) === 0) {
        return [];
    }

    $out = [];
    foreach ($names as $name) {
        $other = getNpcData($name);
        if (!is_array($other)) {
            $out[] = $name;
            continue;
        }

        // Bug 97: say the gender so nobody calls Malzin "him".
        $gender = strtolower(trim(strval($other['gender'] ?? '')));
        $bits = [in_array($gender, ['female', 'male'], true) ? $name . ' (' . $gender . ')' : $name];
        if (function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($other)) {
            // Bug 38: "squadmate" only when the viewer is in the player's squad too.
            $bits[] = npcIsInPlayerFaction($npcData) ? 'squadmate' : "player's squad";
        }

        $meta = function_exists('normalizeNpcMetadataPayload')
            ? normalizeNpcMetadataPayload($other['metadata'] ?? [])
            : stobeLifelikeDecodeAssoc($other['metadata'] ?? []);
        $rosterState = stobeRosterState($name);
        if ($rosterState === 'dead') {
            $out[] = $name . ' | DEAD: a corpse, not alive; cannot hear, speak or move';
            continue;
        }
        $action = trim(strval($meta['current_action'] ?? ''));
        if ($rosterState === 'unconscious') {
            $action = 'unconscious (knocked out, cannot talk)';
        }
        if ($action !== '' && strtolower($action) !== 'idle' && strtolower($action) !== 'none') {
            $bits[] = 'doing:' . $action;
        }
        if (!empty($meta['is_in_combat']) || !empty($meta['is_attacking'])) {
            $bits[] = 'in combat';
        }
        if (!empty($meta['is_being_carried'])) {
            $bits[] = 'being carried';
        }
        if (!empty($meta['is_carrying'])) {
            $bits[] = 'carrying someone';
        }

        if (function_exists('stobeBuildNpcConditionText')) {
            $condition = trim(stobeBuildNpcConditionText($other, $meta));
            if ($condition !== '') {
                $condition = trim(preg_replace('/\s+/u', ' ', $condition) ?? $condition);
                if (stripos($condition, 'damaged') !== false
                    || stripos($condition, 'blood') !== false
                    || stripos($condition, 'hungry') !== false
                    || stripos($condition, 'critical') !== false) {
                    $bits[] = 'condition:' . truncatePromptValue($condition, 180);
                }
            }
        }

        $out[] = implode(' | ', $bits);
        if (count($out) >= 8) {
            break;
        }
    }
    return $out;
}

function stobeLifelikeEmotionalCue(array $rows, string $condition, int $currentGamets = 0): string
{
    $conditionLower = strtolower($condition);
    if (preg_match('/\b(critical|bleeding|dying|unconscious|severely|injured|wounded)\b/i', $conditionLower)) {
        return 'pain, fear, exhaustion, or urgency should visibly influence the response';
    }

    $cue = '';
    foreach (array_slice($rows, 0, 18) as $row) {
        $type = strtolower(trim(strval($row['type'] ?? '')));
        $data = strtolower(trim(strval($row['data'] ?? '')));
        $rowGamets = intval($row['gamets'] ?? 0);
        $age = ($currentGamets > 0 && $rowGamets > 0)
            ? max(0, $currentGamets - $rowGamets)
            : 0;

        $isDeath = str_contains($type, 'death') || (!$type || $type === 'relationship')
            && preg_match('/\b(died|killed)\b/i', $data) === 1;
        if ($isDeath && ($age === 0 || $age <= 86400)) {
            return 'shaken or grieving from a significant recent loss; the intensity may soften with time but should not vanish between conversations';
        }

        $isCombat = str_contains($type, 'combat')
            || in_array($type, ['major_damage', 'knockout', 'limb_loss'], true);
        if ($isCombat && ($age === 0 || $age <= 3600)) {
            $cue = 'alert and keyed-up from recent danger; speech may be shorter and more tactical';
            break;
        }

        $isCare = str_contains($type, 'healing')
            || in_array($type, ['recovered', 'major_damage', 'knockout'], true);
        if ($isCare && ($age === 0 || $age <= 7200)) {
            $cue = 'still affected by recent injury, recovery, or being cared for';
        }

        if ($type === 'relationship' && ($age === 0 || $age <= 21600)) {
            if (preg_match('/\\b(betray|lied|hurt|angry|resent|insult|argument|rejected|abandon)\\b/i', $data)) {
                return 'carrying some hurt, anger, or guardedness from a recent relationship event; do not reset emotionally just because the conversation changed';
            }
            if (preg_match('/\\b(trust|bond|love|lover|confess|intimate|forgave|saved|devoted|fond)\\b/i', $data)) {
                return 'carrying warmth, vulnerability, relief, or closeness from a recent relationship event; let it color behavior without constantly announcing it';
            }
        }
        if (in_array($type, ['recruit', 'join', 'leave'], true) && ($age === 0 || $age <= 14400)) {
            $cue = 'still adjusting emotionally to a recent change in belonging, loyalty, or group membership';
        }
        if (in_array($type, ['trade', 'gift'], true) && preg_match('/\\b(gave|gift|transferred|shared)\\b/i', $data)
            && ($age === 0 || $age <= 3600)) {
            $cue = 'still remembers a recent practical kindness or exchange; any appreciation should be subtle and character-appropriate';
        }
    }
    return $cue;
}

function stobeLifelikePacingCue(
    string $npcName,
    array $rows,
    string $emotion,
    string $eventType,
    int $currentGamets
): string {
    $urgentText = strtolower($emotion . ' ' . $eventType);
    if (preg_match('/\\b(combat|danger|bleed|dying|critical|panic|urgent|knockout|attack)\\b/i', $urgentText)) {
        return 'Keep this turn terse and immediate: usually one short sentence or fragment unless vital information requires more.';
    }

    $latestId = 0;
    if (isset($rows[0]) && is_array($rows[0])) {
        $latestId = intval($rows[0]['id'] ?? ($rows[0]['rowid'] ?? 0));
    }
    // Stable for the current conversational moment, but changes naturally as
    // time/events advance. This avoids uniform LLM-length responses.
    $bucket = $currentGamets > 0 ? intdiv($currentGamets, 120) : 0;
    $roll = abs(crc32(strtolower($npcName) . '|' . $latestId . '|' . $bucket)) % 100;
    if ($roll < 48) {
        return 'Prefer a brief natural reply: a few words to one sentence. Do not elaborate unless asked.';
    }
    if ($roll < 87) {
        return 'Use a normal conversational reply: roughly one to three sentences, focused on what matters now.';
    }
    return 'This moment can breathe a little: a somewhat fuller reply or anecdote is appropriate if it genuinely adds character, but avoid a monologue.';
}

function stobeLifelikeIdentityForTurn(array $deepIdentity, string $playerMessage): array
{
    if (count($deepIdentity) === 0) {
        return [];
    }

    $message = strtolower(trim($playerMessage));
    $selected = [];
    $baseKeys = [
        'everyday_tastes',
        'social_style',
        'values_and_beliefs',
        'desires_and_wants',
        'habits_and_quirks',
        'faction_and_place_opinions',
        'contradictions',
    ];
    foreach ($baseKeys as $key) {
        if (array_key_exists($key, $deepIdentity)) {
            $selected[$key] = $deepIdentity[$key];
        }
    }

    $historyRelevant = $message === ''
        || preg_match('/\b(family|parent|mother|father|brother|sister|childhood|grew up|born|home|past|before|used to|history|remember|where are you from|story)\b/i', $message) === 1;
    if ($historyRelevant) {
        foreach (['roots_and_family', 'formative_history'] as $key) {
            if (array_key_exists($key, $deepIdentity)) {
                $selected[$key] = $deepIdentity[$key];
            }
        }
    }

    $fearRelevant = $message === ''
        || preg_match('/\b(fear|afraid|scared|worry|worried|hate|trauma|danger|trust|insecure|nightmare)\b/i', $message) === 1;
    if ($fearRelevant && array_key_exists('fears_and_insecurities', $deepIdentity)) {
        $selected['fears_and_insecurities'] = $deepIdentity['fears_and_insecurities'];
    }

    $relationshipRelevant = preg_match('/\b(love|like me|relationship|dating|date|partner|marry|marriage|attracted|attraction|romance|romantic|kiss|sex|sexual|intimacy|intimate|bed|desire|turn on|boundary|boundaries)\b/i', $message) === 1;
    if ($relationshipRelevant) {
        foreach (['romantic_preferences', 'intimacy_preferences', 'boundaries'] as $key) {
            if (array_key_exists($key, $deepIdentity)) {
                $selected[$key] = $deepIdentity[$key];
            }
        }
    } elseif (array_key_exists('boundaries', $deepIdentity)) {
        // General boundaries can still shape ordinary social behavior without
        // exposing private romantic/intimate details every turn.
        $selected['boundaries'] = $deepIdentity['boundaries'];
    }

    $secretRelevant = preg_match('/\b(secret|hide|hiding|never told|confess|confession|trust me|tell me something|private|ashamed|regret)\b/i', $message) === 1;
    if ($secretRelevant && array_key_exists('secrets', $deepIdentity)) {
        $selected['secrets'] = $deepIdentity['secrets'];
    }

    // Keep this turn-local identity reference compact and valid JSON. The full
    // matrix remains persisted; only a few details from each relevant section
    // are surfaced on a given turn so the model has room to remember the world
    // and does not repeat the same biography dump constantly.
    $messageTokens = [];
    if ($message !== '') {
        foreach (preg_split('/[^a-z0-9]+/i', $message) ?: [] as $token) {
            $token = strtolower(trim($token));
            if (strlen($token) >= 4) {
                $messageTokens[$token] = true;
            }
        }
    }

    $out = [];
    $budget = 5200;
    foreach ($selected as $key => $value) {
        $turnValue = $value;
        if (is_array($value) && array_is_list($value)) {
            $ranked = [];
            foreach ($value as $idx => $item) {
                if (!is_scalar($item)) {
                    continue;
                }
                $text = trim(strval($item));
                if ($text === '') {
                    continue;
                }
                $lower = strtolower($text);
                $score = 0;
                foreach ($messageTokens as $token => $_) {
                    if (str_contains($lower, $token)) {
                        $score += 20;
                    }
                }
                // Stable tie-break rotation based on the current utterance and
                // section, so ordinary conversations expose different facets.
                $tie = abs(crc32($key . '|' . $message . '|' . $idx)) % 10000;
                $ranked[] = ['score' => $score, 'tie' => $tie, 'text' => truncatePromptValue($text, 420)];
            }
            usort($ranked, static function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) {
                    return $b['score'] <=> $a['score'];
                }
                return $a['tie'] <=> $b['tie'];
            });
            $turnValue = array_map(static fn(array $row): string => strval($row['text']), array_slice($ranked, 0, 3));
        } elseif (is_string($value)) {
            $turnValue = truncatePromptValue($value, 700);
        }

        $json = json_encode($turnValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            continue;
        }
        $chunk = '"' . $key . '":' . $json;
        if ($budget - strlen($chunk) < 0) {
            continue;
        }
        $out[$key] = $turnValue;
        $budget -= strlen($chunk);
    }
    return $out;
}

function stobeLifelikeSelectTurnMemories(array $items, string $playerMessage, int $limit = 2): array
{
    if ($limit < 1 || count($items) === 0) {
        return [];
    }
    $limit = min($limit, count($items));
    $tokens = [];
    foreach (preg_split('/[^a-z0-9]+/i', strtolower($playerMessage)) ?: [] as $token) {
        $token = trim($token);
        if (strlen($token) >= 4) {
            $tokens[$token] = true;
        }
    }
    $ranked = [];
    foreach (array_values($items) as $idx => $item) {
        $text = trim(strval($item));
        if ($text === '') {
            continue;
        }
        $lower = strtolower($text);
        $score = 0;
        foreach ($tokens as $token => $_) {
            if (str_contains($lower, $token)) {
                $score += 20;
            }
        }
        $tie = abs(crc32($playerMessage . '|' . $text . '|' . $idx)) % 10000;
        $ranked[] = ['score' => $score, 'tie' => $tie, 'text' => $text];
    }
    usort($ranked, static function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        return $a['tie'] <=> $b['tie'];
    });
    return array_map(static fn(array $row): string => strval($row['text']), array_slice($ranked, 0, $limit));
}

function stobeLifelikeSquadForTurn(array $lines, string $playerMessage): array
{
    if (count($lines) <= 3) {
        return $lines;
    }
    $message = strtolower(trim($playerMessage));
    $explicitSquadQuestion = preg_match('/\b(squad|team|everyone|nearby|who is|who\'s|around us|with us|following|follower)\b/i', $message) === 1;
    if ($explicitSquadQuestion) {
        return array_slice($lines, 0, 8);
    }
    $selected = [];
    $ordinary = [];
    foreach ($lines as $line) {
        $text = strval($line);
        $lower = strtolower($text);
        $name = trim(strval(explode('|', $text, 2)[0] ?? ''));
        $name = trim(preg_replace('/\s*\((?:female|male)\)$/', '', $name) ?? $name); // bug 97 tag
        $mentioned = $name !== '' && str_contains($message, strtolower($name));
        $noteworthy = str_contains($lower, 'doing:')
            || str_contains($lower, 'in combat')
            || str_contains($lower, 'being carried')
            || str_contains($lower, 'carrying someone')
            || str_contains($lower, 'condition:');
        if ($mentioned || $noteworthy) {
            $selected[] = $text;
        } else {
            $ordinary[] = $text;
        }
    }
    foreach (array_slice($ordinary, 0, 3) as $line) {
        $selected[] = $line;
    }
    return array_slice(array_values(array_unique($selected)), 0, 8);
}

function stobeBuildLifelikeNpcContextBlock(
    string $npcName,
    array $npcData,
    string $playerName,
    string $playerMessage,
    int $currentGamets,
    string $eventType
): string {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return '';
    }

    $rows = stobeLifelikeFetchEvents($safeNpc, 90);
    $recentMeaningful = [];
    $callbacks = [];
    $commitments = [];
    $samePlace = [];
    $location = stobeLifelikeCurrentLocation($safeNpc);

    foreach ($rows as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = stobeLifelikeCleanLine($row);
        if ($line === '') {
            continue;
        }
        $score = stobeLifelikeEventScore($row);
        $involved = stobeLifelikeRowInvolves($row, $safeNpc);
        if (!$involved) {
            $line = '(overheard, not said to you) ' . $line;
        }

        if ($score >= 30 && count($recentMeaningful) < 6) {
            $recentMeaningful[] = $line;
        }
        if ($idx >= 8 && $score >= 65 && count($callbacks) < 2) {
            $callbacks[] = $line;
        }
        if ($involved && preg_match('/\b(tomorrow|later|next time|we will|we\'ll|plan to|promise|promised|meet at|head to|travel to|return to)\b/i', $line) === 1
            && count($commitments) < 4) {
            $commitments[] = $line;
        }
        $rowLoc = trim(strval($row['location'] ?? ''));
        if ($location !== '' && $rowLoc !== '' && strcasecmp($rowLoc, $location) === 0
            && $idx >= 6 && $score >= 30 && count($samePlace) < 3) {
            $samePlace[] = $line;
        }
    }

    // Supplement the rolling recent window with a tiny full-history lookup so
    // long-separated returns to a place and old major events remain recallable.
    if ($location !== '') {
        foreach (stobeLifelikeHistoricalPlaceEvents($safeNpc, $location, 3) as $placeMemory) {
            if (!in_array($placeMemory, $samePlace, true)) {
                $samePlace[] = $placeMemory;
            }
            if (count($samePlace) >= 3) {
                break;
            }
        }
    }
    foreach (stobeLifelikeDurableCallbacks($safeNpc, 3) as $durableMemory) {
        if (!in_array($durableMemory, $callbacks, true)) {
            $callbacks[] = $durableMemory;
        }
        if (count($callbacks) >= 3) {
            break;
        }
    }
    foreach (stobeLifelikeMemoryCommitments($safeNpc, 4) as $memoryCommitment) {
        if (!in_array($memoryCommitment, $commitments, true)) {
            $commitments[] = $memoryCommitment;
        }
        if (count($commitments) >= 4) {
            break;
        }
    }

    $metadata = function_exists('normalizeNpcMetadataPayload')
        ? normalizeNpcMetadataPayload($npcData['metadata'] ?? [])
        : stobeLifelikeDecodeAssoc($npcData['metadata'] ?? []);
    $condition = function_exists('stobeBuildNpcConditionText')
        ? trim(stobeBuildNpcConditionText($npcData, $metadata))
        : '';
    $emotion = stobeLifelikeEmotionalCue($rows, $condition, $currentGamets);
    $pacing = stobeLifelikePacingCue($safeNpc, $rows, $emotion, $eventType, $currentGamets);
    $squad = stobeLifelikeSquadForTurn(stobeLifelikeNearbySquadLines($npcData, $safeNpc), $playerMessage);
    $samePlace = stobeLifelikeSelectTurnMemories($samePlace, $playerMessage, 2);
    $callbacks = stobeLifelikeSelectTurnMemories($callbacks, $playerMessage, 2);
    $commitments = stobeLifelikeSelectTurnMemories($commitments, $playerMessage, 3);

    $lines = ['<lifelike_continuity>'];
    $lines[] = '  <rule>Behave like a person who existed before meeting the player and continues to exist between conversations.</rule>';
    $lines[] = '  <rule>Do not reduce yourself to your occupation. Draw naturally from biography, tastes, relationships, fears, private wants, past experiences, and current circumstances.</rule>';
    $lines[] = '  <rule>Do not volunteer private or intimate information without a plausible conversational reason and sufficient familiarity.</rule>';
    $lines[] = '  <rule>Let established relationships affect warmth, teasing, trust, suspicion, protectiveness, irritation, jealousy, forgiveness, and willingness to disclose.</rule>';
    $lines[] = '  <rule>When the player gives a clear practical order that maps to an available game action, prefer performing the real action rather than merely claiming it happened.</rule>';
    $lines[] = '  <rule>In danger, combat, severe injury, or urgent flight, prioritize survival and short urgent speech over casual conversation.</rule>';
    $lines[] = '  <rule>Vary response length naturally: acknowledgements can be a few words; important or emotional topics can be longer. Avoid turning every turn into a monologue.</rule>';
    $lines[] = '  <rule>Occasionally reference a relevant shared event or personal anecdote, but do not force callbacks into every response.</rule>';

    $identityExtended = function_exists('normalizeCoreNpcExtendedData')
        ? normalizeCoreNpcExtendedData($npcData['extended_data'] ?? [])
        : stobeLifelikeDecodeAssoc($npcData['extended_data'] ?? []);
    $deepIdentity = $identityExtended['deep_identity'] ?? null;
    if (is_array($deepIdentity) && count($deepIdentity) > 0) {
        $turnIdentity = stobeLifelikeIdentityForTurn($deepIdentity, $playerMessage);
        $deepIdentityJson = json_encode($turnIdentity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($deepIdentityJson) && $deepIdentityJson !== '' && $deepIdentityJson !== '[]') {
            $lines[] = '  <deep_identity_private_reference>';
            $lines[] = '    <disclosure_rule>This is selected persistent private background canon relevant to this turn. Use it to shape reactions and provide anecdotes naturally. Never dump the profile. Reveal sensitive, romantic, intimate, or secret details only when relationship, trust, and conversational context make that disclosure believable.</disclosure_rule>';
            $lines[] = '    <identity_json>' . stobePromptXmlEscape(truncatePromptValue($deepIdentityJson, 5400)) . '</identity_json>';
            $lines[] = '  </deep_identity_private_reference>';
        }
    }

    $conditionRelevant = $condition !== '' && (
        preg_match('/\b(critical|bleed|blood|injur|wound|hurt|hungry|starv|unconscious|missing|limb|carried|damaged|pain)\b/i', $condition) === 1
        || preg_match('/\b(health|hurt|okay|injur|wound|blood|hungry|starving|limb|pain)\b/i', $playerMessage) === 1
    );
    if ($conditionRelevant) {
        $lines[] = '  <current_physical_state>' . stobePromptXmlEscape($condition) . '</current_physical_state>';
    }
    if ($emotion !== '') {
        $lines[] = '  <emotional_carryover>' . stobePromptXmlEscape($emotion) . '</emotional_carryover>';
    }
    if ($pacing !== '') {
        $lines[] = '  <conversation_pacing>' . stobePromptXmlEscape($pacing) . '</conversation_pacing>';
    }
    if ($location !== '') {
        $lines[] = '  <current_place>' . stobePromptXmlEscape($location) . '</current_place>';
    }
    if (count($recentMeaningful) > 0) {
        $lines[] = '  <recent_situation>';
        foreach ($recentMeaningful as $line) {
            $lines[] = '    <event>' . stobePromptXmlEscape($line) . '</event>';
        }
        $lines[] = '  </recent_situation>';
    }
    if (count($commitments) > 0) {
        $lines[] = '  <plans_and_promises>';
        $lines[] = '    <continuity_rule>These are candidate commitments from prior dialogue. Treat one as still active only when later events have not clearly completed, cancelled, or contradicted it.</continuity_rule>';
        foreach ($commitments as $line) {
            $lines[] = '    <commitment>' . stobePromptXmlEscape($line) . '</commitment>';
        }
        $lines[] = '  </plans_and_promises>';
    }
    if (count($samePlace) > 0) {
        $lines[] = '  <memories_tied_to_current_place>';
        foreach ($samePlace as $line) {
            $lines[] = '    <memory>' . stobePromptXmlEscape($line) . '</memory>';
        }
        $lines[] = '  </memories_tied_to_current_place>';
    }
    if (count($callbacks) > 0) {
        $lines[] = '  <older_shared_memories>';
        foreach ($callbacks as $line) {
            $lines[] = '    <memory>' . stobePromptXmlEscape($line) . '</memory>';
        }
        $lines[] = '  </older_shared_memories>';
    }
    if (count($squad) > 0) {
        $lines[] = '  <nearby_people_awareness>';
        foreach ($squad as $line) {
            $lines[] = '    <person>' . stobePromptXmlEscape($line) . '</person>';
        }
        $lines[] = '  </nearby_people_awareness>';
    }

    if (trim($playerMessage) !== '') {
        $lines[] = '  <latest_player_intent_note>React to what was actually said or asked; do not ignore it merely to surface background lore.</latest_player_intent_note>';
    }
    $lines[] = '</lifelike_continuity>';

    return implode("\n", $lines);
}

function stobeBuildLifelikeBoredInstruction(
    string $speaker,
    string $listener,
    array $speakerData,
    int $gamets
): string {
    $rows = stobeLifelikeFetchEvents($speaker, 50);
    $hooks = [];
    foreach ($rows as $row) {
        if (!is_array($row) || stobeLifelikeEventScore($row) < 40) {
            continue;
        }
        $line = stobeLifelikeCleanLine($row, 220);
        if ($line !== '') {
            $hooks[] = $line;
        }
        if (count($hooks) >= 3) {
            break;
        }
    }
    $condition = '';
    if (function_exists('normalizeNpcMetadataPayload') && function_exists('stobeBuildNpcConditionText')) {
        $meta = normalizeNpcMetadataPayload($speakerData['metadata'] ?? []);
        $condition = trim(stobeBuildNpcConditionText($speakerData, $meta));
    }

    $instruction = 'Start a brief spontaneous conversation because something in the current situation, recent shared history, relationship, physical condition, location, or nearby squad gives you a reason to speak. Prefer a concrete trigger over generic small talk.';
    if (count($hooks) > 0) {
        $instruction .= ' Recent possible hooks: ' . implode(' | ', $hooks) . '.';
    }
    if ($condition !== '') {
        $instruction .= ' Current condition: ' . $condition . '.';
    }
    $instruction .= ' It is also valid to say very little if that better fits the character and moment.';
    return $instruction;
}

/* One-shot runtime signals consumed by KenshiFP. These let meaningful events
 * affect the already-running Stobe plugin without another LLM request. */
function stobeLifelikeRuntimeSignalPath(string $kind): string
{
    $kind = strtolower(trim($kind));
    if (!in_array($kind, ['interrupt', 'initiative'], true)) {
        return '';
    }
    return '/mnt/d/Steam/steamapps/common/Kenshi/RE_Kenshi/mods/Stobe/lifelike_' . $kind . '.flag';
}

function stobeLifelikeSignalRuntime(string $kind, string $eventType, int $cooldownSeconds = 0): bool
{
    $path = stobeLifelikeRuntimeSignalPath($kind);
    if ($path === '') {
        return false;
    }
    $now = time();
    // Bug 99: the DLL deletes the flag when it reads it, so its mtime can't
    // carry the cooldown. Keep the last-signal time in our own marker file.
    $marker = rtrim(sys_get_temp_dir(), '/') . '/stobe_lifelike_' . $kind . '.last';
    $mtime = @filemtime($marker);
    if ($cooldownSeconds > 0 && is_int($mtime) && ($now - $mtime) < $cooldownSeconds) {
        return false;
    }
    @touch($marker, $now);
    $ok = @file_put_contents($path, strtolower(trim($eventType)) . "\n" . $now . "\n", LOCK_EX) !== false;
    if ($ok && function_exists('stobeLogInfo')) {
        stobeLogInfo('Lifelike runtime signal queued', ['kind' => $kind, 'event_type' => strtolower(trim($eventType))]);
    }
    return $ok;
}

function stobeLifelikeEventTouchesPlayerContext(string $eventData, string $peopleRaw): bool
{
    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', ''));
    if ($playerName !== '' && stripos($eventData, $playerName) !== false) {
        return true;
    }

    if (function_exists('extractParticipantIdentities')) {
        foreach (extractParticipantIdentities(['people' => $peopleRaw]) as $identity) {
            if (!is_array($identity)) {
                continue;
            }
            $name = normalizeParticipantNameToken(strval($identity['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($playerName !== '' && strcasecmp($name, $playerName) === 0) {
                return true;
            }
            $npc = getNpcData($name);
            if (is_array($npc) && function_exists('npcIsInPlayerFaction') && npcIsInPlayerFaction($npc)) {
                return true;
            }
        }
    }
    return false;
}

function stobeLifelikeSignalForIncomingEvent(string $eventType, string $eventData = '', string $peopleRaw = ''): void
{
    $type = strtolower(trim($eventType));
    if (!stobeLifelikeEventTouchesPlayerContext($eventData, $peopleRaw)) {
        return;
    }
    if (in_array($type, [
        'combat', 'combat_start', 'attack', 'major_damage', 'knockout', 'death',
        'predation', 'slavery', 'enslaved'
    ], true)) {
        stobeLifelikeSignalRuntime('interrupt', $type, 2);
    }
}

function stobeLifelikeSignalForCompletedEvent(string $eventType, string $eventData = '', string $peopleRaw = ''): void
{
    $type = strtolower(trim($eventType));
    if (!stobeLifelikeEventTouchesPlayerContext($eventData, $peopleRaw)) {
        return;
    }
    if (in_array($type, [
        'combat_end', 'combatend', 'combatendmighty', 'major_damage', 'knockout',
        'recovered', 'death', 'healing', 'slavery', 'enslaved', 'freed_slave',
        'carry', 'trade', 'limb_loss', 'recruit', 'join', 'leave', 'relationship'
    ], true)) {
        // Bug 99: strangers healing each other near the player aren't a reason to talk.
        $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', ''));
        if (in_array($type, ['healing', 'recovered'], true)
            && ($playerName === '' || stripos($eventData, $playerName) === false)) {
            return;
        }
        stobeLifelikeSignalRuntime('initiative', $type, 45);
    }
}
