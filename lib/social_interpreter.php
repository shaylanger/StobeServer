<?php
declare(strict_types=1);
require_once __DIR__ . '/social_identity.php';
require_once __DIR__ . '/social_care.php';
require_once __DIR__ . '/social_property.php';

/**
 * Server-owned interpretation of structured native facts (facts.source = "structured").
 * Runs inside SocialStore::ingest's transaction, right after the raw event is captured.
 * Roles in structured events: actor = who did it (attacker, owner, taker), target = who it was done to.
 *
 * Phase 2 (combat): an attack changes only victim -> attacker. The first strike of an encounter
 * (per pair, idle window in game seconds) makes the attacker the initiator; hitting back, or hitting
 * someone who is attacking your ally, is defence and costs nothing. Harm escalates one budget per
 * incident (aggression -> injury -> KO -> limb), charging only the difference. A defender who maims
 * still leaves a separate grievance. Harm without an open encounter for that pair is not scored
 * (no invented intent); unknown attacker is never guessed.
 */
final class SocialInterpreter
{
    use SocialCareInterpreter;
    use SocialPropertyInterpreter;
    public const HARM_GROUP = ['aggression', 'injury', 'serious_assault', 'critical_harm', 'maiming'];
    private array $combat;

    public function __construct(private SocialStore $store, private SocialRules $rules)
    {
        $this->combat = $rules->section('combat') + ['encounter_idle_seconds'=>600, 'repeat_assault_severity'=>1.15,
            'harm_levels'=>['injury'=>'injury','knockout'=>'serious_assault','maiming'=>'maiming']];
    }

    public function interpret(array $event, string $mode): array
    {
        return match ($event['event_kind']) {
            'attack' => $this->attack($event, $mode),
            'harm' => $this->harm($event, $mode),
            'recovered' => $this->recovered($event, $mode),
            'item_transfer' => $this->itemTransfer($event, $mode),
            'enslaved' => $this->enslaved($event, $mode),
            'freed' => $this->freed($event),
            'aid' => $this->aid($event, $mode),
            'carry_start' => $this->carryStart($event, $mode),
            'carry_end' => $this->carryEnd($event, $mode),
            'placed' => $this->placed($event, $mode),
            'eat' => $this->eat($event, $mode),
            'trade' => $this->trade($event, $mode),
            default => [['status'=>'recorded']],
        };
    }

    // ---------- helpers ----------
    public static function pairKey(string $a, string $b): string { return strcmp($a, $b) <= 0 ? "$a|$b" : "$b|$a"; }

    private function idle(): int { return max(1, (int)$this->combat['encounter_idle_seconds']); }

    private function openPair(array $event, string $a, string $b): ?array
    {
        $rows = $this->store->fetchRows("SELECT incident_id,state FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='combat' AND state->>'pair'=\$3 AND state->>'phase'='active' ORDER BY game_ts DESC, last_sequence DESC LIMIT 1",
            [$event['campaign_id'], $event['timeline_epoch'], self::pairKey($a, $b)]);
        if (!$rows) return null;
        $state = json_decode($rows[0]['state'], true);
        if ($event['game_ts'] - (int)($state['last_ts'] ?? 0) > $this->idle()) {
            $state['phase'] = 'closed'; $state['closed_reason'] = 'idle';
            $this->store->saveIncident($event, $rows[0]['incident_id'], $state);
            return null;
        }
        return ['id'=>$rows[0]['incident_id'], 'state'=>$state];
    }

    private static function allies(?array $x, ?array $y): bool
    {
        if (!$x || !$y) return false;
        if (($x['in_player_faction'] ?? null) === true && ($y['in_player_faction'] ?? null) === true) return true;
        $fx = strtolower(trim(strval($x['faction'] ?? ''))); $fy = strtolower(trim(strval($y['faction'] ?? '')));
        return $fx !== '' && !in_array($fx, ['none', 'unknown', 'neutral'], true) && $fx === $fy;
    }

    /** B is the active aggressor against someone A defends (A's ally). Returns that ally's key. */
    private function aggressorAgainstAllyOf(array $event, array $b, array $a): ?string
    {
        $rows = $this->store->fetchRows("SELECT state FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='combat' AND state->>'phase'='active' AND state->>'initiator'=\$3 AND (state->>'last_ts')::bigint >= \$4",
            [$event['campaign_id'], $event['timeline_epoch'], $b['entity_key'], $event['game_ts'] - $this->idle()]);
        foreach ($rows as $row) {
            $state = json_decode($row['state'], true);
            $victim = $state['parties'][$state['victim'] ?? ''] ?? null;
            if ($victim && $victim['entity_key'] !== $a['entity_key'] && self::allies($victim, $a)) return $victim['entity_key'];
        }
        return null;
    }

    private function effect(array $event, string $mode, array $observer, array $culprit, string $component, array $belief,
        string $incident, array $context = []): array
    {
        $resolve = static fn(string $key) => SocialIdentity::resolve($key === $observer['entity_key'] ? $observer : $culprit,
            $key === $observer['entity_key'] ? 'observer' : 'culprit');
        $belief += ['responsible_entity'=>$culprit['entity_key'], 'confidence'=>'certain'];
        $result = $this->store->apply($event, $observer['entity_key'], $culprit['entity_key'], $component, $belief, $resolve, $mode, $context, $incident);
        return ['component'=>$component, 'observer'=>$observer['name'], 'culprit'=>$culprit['name']] + $result;
    }

    /** What the victim knows right now: direct experience needs explicit consciousness. */
    private static function awareness(?bool $conscious): array
    {
        return ['awareness'=>$conscious === true ? 'directly_experienced' : 'unknown', 'conscious'=>$conscious];
    }

    private function latent(array $event, array $observer, array $culprit, string $component, string $incident, array $extra = []): void
    {
        // Unconscious victim: remember the fact; it is resolved on waking (phase 3), never charged now.
        $rows = $this->store->fetchRows('SELECT state FROM social_incident WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3', [$event['campaign_id'], $event['timeline_epoch'], $incident]);
        $state = $rows ? json_decode($rows[0]['state'], true) : [];
        $facts = $state['pending'][$observer['entity_key']] ?? [];
        $facts[] = ['kind'=>'harm', 'component'=>$component, 'culprit'=>$culprit, 'game_ts'=>$event['game_ts'], 'sequence'=>$event['sequence']] + $extra;
        $this->store->pending($event, $observer['entity_key'], array_slice($facts, -16), $incident);
    }

    // ---------- phase 2: combat ----------
    private function attack(array $event, string $mode): array
    {
        $a = $event['actor']; $b = $event['target'];
        if (!$a || !$b || $a['entity_key'] === $b['entity_key']) return [['status'=>'incomplete_roles']];
        $open = $this->openPair($event, $a['entity_key'], $b['entity_key']);
        if ($open) {
            $state = $open['state'];
            // Keep the encounter alive without a write (and checkpoint) per hit: refresh at most once a game minute.
            if ($event['game_ts'] - (int)($state['last_ts'] ?? 0) >= (int)($this->combat['refresh_seconds'] ?? 60)) {
                $state['last_ts'] = $event['game_ts'];
                $state['parties'][$a['entity_key']] = $a; $state['parties'][$b['entity_key']] = $b;
                $this->store->saveIncident($event, $open['id'], $state);
            }
            if ($state['initiator'] !== $a['entity_key']) return [['status'=>'defence', 'basis'=>'retaliation', 'incident'=>$open['id']]];
            // Same encounter: the first strike was already charged (ledger dedup); a victim who was unaware then and aware now learns it now.
            return [$this->effect($event, $mode, $b, $a, 'aggression', self::awareness($b['conscious'] ?? null) + ['note'=>'Attacked by ' . $a['name'], 'kind'=>'aggression'],
                $open['id'], ['escalation_group'=>self::HARM_GROUP, 'severity'=>$state['severity'] ?? 1])];
        }
        $facts = $event['facts'];
        $basis = 'first_strike'; $initiator = $a['entity_key']; $ally = null;
        if (($facts['victim_targeting_actor'] ?? null) === true || ($facts['player_defending'] ?? null) === true) {
            $basis = 'victim_already_engaged'; $initiator = $b['entity_key'];
        } elseif ($ally = $this->aggressorAgainstAllyOf($event, $b, $a)) {
            $basis = 'defending_ally'; $initiator = $b['entity_key'];
        }
        $victim = $initiator === $a['entity_key'] ? $b['entity_key'] : $a['entity_key'];
        $prior = $this->store->fetchRows("SELECT count(*) AS n FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='combat' AND state->>'initiator'=\$3 AND state->>'victim'=\$4",
            [$event['campaign_id'], $event['timeline_epoch'], $initiator, $victim]);
        $severity = ((int)($prior[0]['n'] ?? 0)) > 0 ? (float)$this->combat['repeat_assault_severity'] : 1.0;
        $incident = 'combat:' . $event['sequence'] . ':' . substr(hash('sha256', self::pairKey($a['entity_key'], $b['entity_key'])), 0, 16);
        $state = ['kind'=>'combat', 'phase'=>'active', 'pair'=>self::pairKey($a['entity_key'], $b['entity_key']),
            'initiator'=>$initiator, 'victim'=>$victim, 'basis'=>$basis, 'ally'=>$ally, 'severity'=>$severity,
            'parties'=>[$a['entity_key']=>$a, $b['entity_key']=>$b], 'opened_ts'=>$event['game_ts'], 'last_ts'=>$event['game_ts'], 'pending'=>(object)[]];
        $this->store->saveIncident($event, $incident, $state);
        if ($initiator !== $a['entity_key']) return [['status'=>'defence', 'basis'=>$basis, 'incident'=>$incident]];
        $belief = self::awareness($b['conscious'] ?? null) + ['note'=>'Attacked by ' . $a['name'], 'kind'=>'aggression'];
        $result = $this->effect($event, $mode, $b, $a, 'aggression', $belief, $incident, ['escalation_group'=>self::HARM_GROUP, 'severity'=>$severity]);
        if (($b['conscious'] ?? null) === false) $this->latent($event, $b, $a, 'aggression', $incident);
        return [$result];
    }

    private function harm(array $event, string $mode): array
    {
        $b = $event['target']; $a = $event['actor']; $level = strval($event['facts']['level'] ?? '');
        if (!$b) return [['status'=>'incomplete_roles']];
        if ($level === 'knockout') $this->openKo($event, $a, $b);
        if ($level === 'death') {
            foreach ($this->activeKo($event, $b, true) as $ko) {
                $ko['state']['phase'] = 'dead'; // a dead victim never wakes to latent penalties
                $this->store->saveIncident($event, $ko['id'], $ko['state']);
            }
            // The dead hold no grudges; witnesses are a later phase. Close every open encounter of the victim.
            foreach ($this->store->fetchRows("SELECT incident_id,state FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='combat' AND state->>'phase'='active' AND state->'parties' ? \$3",
                [$event['campaign_id'], $event['timeline_epoch'], $b['entity_key']]) as $row) {
                $state = json_decode($row['state'], true); $state['phase'] = 'closed'; $state['closed_reason'] = 'death';
                $this->store->saveIncident($event, $row['incident_id'], $state);
            }
            return [['status'=>'death_recorded']];
        }
        if (!$a || $a['entity_key'] === $b['entity_key']) return [['status'=>'unattributed']];
        $open = $this->openPair($event, $a['entity_key'], $b['entity_key']);
        if (!$open) return [['status'=>'no_encounter', 'note'=>'harm without an observed attack between this pair is not scored']];
        $state = $open['state'];
        $state['last_ts'] = $event['game_ts'];
        $state['harm'][] = ['level'=>$level, 'by'=>$a['entity_key'], 'to'=>$b['entity_key'], 'attribution'=>$event['facts']['attribution'] ?? null, 'sequence'=>$event['sequence']];
        $state['harm'] = array_slice($state['harm'], -32);
        $this->store->saveIncident($event, $open['id'], $state);
        // A knockout is experienced up to the blow; other harm needs the victim conscious when it happened.
        $conscious = $level === 'knockout' ? true : ($b['conscious'] ?? null);
        $note = ($level === 'maiming' ? 'Lost a limb to ' : ($level === 'knockout' ? 'Knocked out by ' : 'Hurt by ')) . $a['name'];
        if ($state['initiator'] === $a['entity_key']) {
            $component = $this->combat['harm_levels'][$level] ?? null;
            if (!$component) return [['status'=>'unknown_level', 'level'=>$level]];
            $result = $this->effect($event, $mode, $b, $a, $component, self::awareness($conscious) + ['note'=>$note, 'kind'=>'harm'],
                $open['id'], ['escalation_group'=>self::HARM_GROUP, 'severity'=>$state['severity'] ?? 1]);
            if ($conscious === false) $this->latent($event, $b, $a, $component, $open['id']);
            return [$result];
        }
        if ($level === 'maiming') {
            // Defender maimed the aggressor: a distinct grievance, never an aggression/betrayal tag.
            $result = $this->effect($event, $mode, $b, $a, 'defensive_maiming', self::awareness($conscious) + ['note'=>$note . ' (defending)', 'kind'=>'grievance'], $open['id']);
            if ($conscious === false) $this->latent($event, $b, $a, 'defensive_maiming', $open['id']);
            return [$result];
        }
        return [['status'=>'defence', 'basis'=>'harm_by_defender', 'incident'=>$open['id']]];
    }

    // ---------- phase 3: unconscious perception ----------
    /**
     * Knockout opens a per-victim incident: the remembered attacker (only if an encounter with them was
     * observed or the game says they defeated the victim), the inventory baseline, and later objective
     * facts (who took what, who enslaved). Objective facts stay diagnostic; on waking the victim infers.
     */
    private function openKo(array $event, ?array $a, array $b): void
    {
        if ($this->activeKo($event, $b)) return; // duplicate KO poll/hook: keep the first baseline
        $remembered = null; $encounter = null;
        if ($a && $a['entity_key'] !== $b['entity_key']) {
            $open = $this->openPair($event, $a['entity_key'], $b['entity_key']);
            if ($open || ($event['facts']['attribution'] ?? '') === 'defeated_by') {
                $remembered = $a; $encounter = $open['id'] ?? null;
            }
        }
        $incident = 'ko:' . $event['sequence'] . ':' . substr(hash('sha256', $b['entity_key']), 0, 16);
        $this->store->saveIncident($event, $incident, ['kind'=>'ko', 'phase'=>'pending_awareness', 'victim'=>$b,
            'remembered'=>$remembered, 'encounter'=>$encounter, 'baseline'=>$event['facts']['inventory'] ?? null,
            'baseline_total'=>$event['facts']['inventory_total'] ?? null, 'money'=>$event['facts']['money'] ?? null,
            'opened_ts'=>$event['game_ts'], 'objective'=>['transfers'=>[]], 'enslaved_by'=>null, 'known_thief'=>null]);
    }

    /** Latest unresolved KO incident of this victim in the campaign (a reload may carry it into a new load). */
    private function activeKo(array $event, array $victim, bool $all = false): array
    {
        $rows = $this->store->fetchRows("SELECT timeline_epoch,incident_id,state FROM social_incident WHERE campaign_id=\$1 AND state->>'kind'='ko' AND state->>'phase'='pending_awareness'
              AND (state->'victim'->>'entity_key'=\$2 OR (timeline_epoch<>\$3 AND state->'victim'->>'serial'=\$4 AND lower(state->'victim'->>'name')=lower(\$5)))
              AND game_ts<=\$6 ORDER BY game_ts DESC, last_sequence DESC" . ($all ? '' : ' LIMIT 1'),
            [$event['campaign_id'], $victim['entity_key'], $event['timeline_epoch'], strval($victim['serial']), strval($victim['name']), $event['game_ts']]);
        $out = [];
        foreach ($rows as $row) {
            $state = json_decode($row['state'], true);
            if ($row['timeline_epoch'] !== $event['timeline_epoch']) $state['carried_from_load'] = $row['timeline_epoch'];
            $out[] = ['id'=>$row['incident_id'], 'state'=>$state];
        }
        return $out;
    }

    private function itemTransfer(array $event, string $mode = 'shadow'): array
    {
        $loser = $event['target']; $taker = $event['actor'];
        if (!$loser) return [['status'=>'incomplete_roles']];
        $ko = $this->activeKo($event, $loser)[0] ?? null;
        if (($loser['conscious'] ?? null) === true) return $this->consciousTransfer($event, $mode);
        if (!$ko) return [['status'=>'recorded', 'note'=>'owner state unknown: no blame']];
        $ko['state']['objective']['transfers'][] = ['taker'=>$taker, 'to_ground'=>($event['facts']['to_ground'] ?? false) === true,
            'items'=>$event['facts']['items'] ?? [], 'game_ts'=>$event['game_ts'], 'sequence'=>$event['sequence']];
        $ko['state']['objective']['transfers'] = array_slice($ko['state']['objective']['transfers'], -64);
        $this->store->saveIncident($event, $ko['id'], $ko['state']);
        return [['status'=>'latent_property', 'incident'=>$ko['id']]];
    }

    private function enslaved(array $event, string $mode): array
    {
        $victim = $event['target']; $owner = $event['actor'];
        if (!$victim) return [['status'=>'incomplete_roles']];
        if (!$owner || $owner['entity_key'] === $victim['entity_key']) return [['status'=>'unknown_owner', 'note'=>'no invented enslaver']];
        $incident = 'slave:' . substr(hash('sha256', $victim['entity_key'] . '|' . $owner['entity_key']), 0, 24);
        $this->store->saveIncident($event, $incident, ['kind'=>'slavery', 'phase'=>'active', 'victim'=>$victim, 'owner'=>$owner, 'opened_ts'=>$event['game_ts']]);
        if (($victim['conscious'] ?? null) === true) {
            return [$this->effect($event, $mode, $victim, $owner, 'enslavement',
                ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>'Enslaved by ' . $owner['name'], 'kind'=>'enslavement'],
                $incident, ['cap_max'=>-56])];
        }
        // Unconscious (or unknown): discovered on waking.
        $ko = $this->activeKo($event, $victim)[0] ?? null;
        if ($ko) {
            $ko['state']['enslaved_by'] = ['owner'=>$owner, 'incident'=>$incident, 'sequence'=>$event['sequence']];
            $this->store->saveIncident($event, $ko['id'], $ko['state']);
        }
        return [['status'=>'latent_enslavement', 'incident'=>$ko['id'] ?? $incident]];
    }

    private function freed(array $event): array
    {
        $victim = $event['target'];
        if (!$victim) return [['status'=>'incomplete_roles']];
        foreach ($this->activeKo($event, $victim, true) as $ko) {
            if (!$ko['state']['enslaved_by']) continue;
            $ko['state']['enslaved_by'] = null; // freed before waking: never learned
            $this->store->saveIncident($event, $ko['id'], $ko['state']);
        }
        return [['status'=>'recorded', 'note'=>'liberation credit is the recruitment/escape phase']];
    }

    private function recovered(array $event, string $mode): array
    {
        $b = $event['target'];
        if (!$b) return [['status'=>'incomplete_roles']];
        $observer = ['conscious'=>true] + $b;
        $observer['conscious'] = true;
        $results = [];
        $ko = $this->activeKo($event, $b)[0] ?? null;
        $remembered = $ko['state']['remembered'] ?? null;
        $knownThief = $ko['state']['known_thief'] ?? null;
        $believedThief = $knownThief ?? $remembered;
        if ($ko) {
            // 1) Missing belongings: only items that objectively left while unconscious (used-up items are
            //    not theft), bounded by what is really missing now. The thief the victim blames is the known
            //    one (better evidence) or the remembered attacker; the objective taker is never revealed.
            $baseline = is_array($ko['state']['baseline'] ?? null) ? $ko['state']['baseline'] : [];
            $current = is_array($event['facts']['inventory'] ?? null) ? $event['facts']['inventory'] : null;
            $moved = []; $takers = [];
            foreach ($ko['state']['objective']['transfers'] ?? [] as $t) {
                foreach ($t['items'] ?? [] as $key => $qty) $moved[$key] = ($moved[$key] ?? 0) + (int)$qty;
                $takers[] = $t['taker'];
            }
            $missing = 0;
            if ($current !== null) {
                foreach ($moved as $key => $qty) $missing += max(0, min($qty, (int)($baseline[$key] ?? $qty) - (int)($current[$key] ?? 0)));
            }
            $squadOnly = ($b['in_player_faction'] ?? null) === true && $takers
                && !array_filter($takers, fn($t) => !$t || ($t['in_player_faction'] ?? null) !== true);
            $total = max(1, (int)($ko['state']['baseline_total'] ?? array_sum(array_map('intval', $baseline))));
            if ($missing > 0 && $squadOnly) {
                $results[] = ['status'=>'squad_inventory', 'note'=>'routine squad inventory management'];
            } elseif ($missing > 0 && !$believedThief) {
                $results[] = ['status'=>'no_known_culprit', 'note'=>'missing belongings, unknown attacker'];
            } elseif ($missing > 0) {
                $share = $missing / $total;
                $component = $share >= 0.8 ? 'all_property_theft' : ($share >= 0.4 ? 'major_theft' : 'theft');
                $results[] = $this->effect($event, $mode, $observer, $believedThief, $component,
                    ['awareness'=>'inferred', 'confidence'=>$knownThief ? 'certain' : 'strongly_inferred', 'conscious'=>true,
                     'note'=>'Woke with belongings missing' . ($knownThief ? '' : ' (blames ' . $believedThief['name'] . ')'), 'kind'=>'theft'],
                    $ko['id']);
            }
            // 2b) Caged while unconscious (phase 4): the captor who carried them in is charged on waking.
            if (!empty($ko['state']['caged_by'])) {
                $captor = $ko['state']['caged_by']['captor'];
                $results[] = $this->effect($event, $mode, $observer, $captor, 'imprisonment',
                    ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>'Woke caged by ' . $captor['name'], 'kind'=>'imprisonment'],
                    $ko['state']['caged_by']['incident']);
            }
            // 2) Enslaved while unconscious: discovered now, charged to the owner who holds the chains.
            if (!empty($ko['state']['enslaved_by'])) {
                $owner = $ko['state']['enslaved_by']['owner'];
                $results[] = $this->effect($event, $mode, $observer, $owner, 'enslavement',
                    ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>'Woke enslaved by ' . $owner['name'], 'kind'=>'enslavement'],
                    $ko['state']['enslaved_by']['incident'], ['cap_max'=>-56]);
            }
        }
        // 3) Harm suffered while unconscious: inferred to the remembered attacker only, inside that encounter's budget.
        $pendingRows = $this->store->fetchRows("SELECT incident_id,state FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='combat' AND state->'pending' ? \$3",
            [$event['campaign_id'], $event['timeline_epoch'], $b['entity_key']]);
        foreach ($pendingRows as $row) {
            $state = json_decode($row['state'], true);
            foreach ($state['pending'][$b['entity_key']] ?? [] as $fact) {
                if (!$remembered) { $results[] = ['status'=>'no_known_culprit', 'component'=>$fact['component'] ?? null]; continue; }
                $same = ($fact['culprit']['entity_key'] ?? '') === $remembered['entity_key'];
                $results[] = $this->effect($event, $mode, $observer, $remembered, $fact['component'],
                    ['awareness'=>'inferred', 'confidence'=>$same ? 'certain' : 'strongly_inferred', 'conscious'=>true,
                     'note'=>'Woke hurt (blames ' . $remembered['name'] . ')', 'kind'=>'harm'],
                    $same ? $row['incident_id'] : ($ko['state']['encounter'] ?? $row['incident_id']), ['escalation_group'=>self::HARM_GROUP]);
            }
            unset($state['pending'][$b['entity_key']]);
            if (!$state['pending']) $state['pending'] = (object)[];
            $this->store->saveIncident($event, $row['incident_id'], $state);
        }
        if ($ko) {
            $ko['state']['phase'] = 'resolved'; $ko['state']['resolved_ts'] = $event['game_ts'];
            $this->store->saveIncident($event, $ko['id'], $ko['state']);
            if (isset($ko['state']['carried_from_load'])) $this->store->retireIncident($event['campaign_id'], $ko['state']['carried_from_load'], $ko['id']);
        }
        return $results ?: [['status'=>'recorded']];
    }

    /** Internal adapter: verified better evidence (e.g. a witnessed theft, phase 6) names the thief of a KO incident. */
    public function recordKnownThief(array $event, array $victim, array $thief): bool
    {
        $ko = $this->activeKo($event, $victim)[0] ?? null;
        if (!$ko) return false;
        $ko['state']['known_thief'] = $thief;
        $this->store->saveIncident($event, $ko['id'], $ko['state']);
        return true;
    }
}
