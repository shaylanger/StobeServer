<?php
declare(strict_types=1);

/**
 * REL phase 4: aid, carry and food (used by SocialInterpreter).
 *
 * Aid: one native 'aid' fact per first-aid session with measured vitals before/after. Credit goes to the
 * provider by verified outcome (routine / meaningful / lifesaving), once per injury episode of the
 * recipient (one growing positive budget per episode; repeats are free), never for treating an injury
 * the provider or the provider's side inflicted. Unmeasured treatment earns nothing.
 * Carry: outsider pickup of a conscious person is known suspicion; a carried unconscious person learns
 * nothing until an outcome: bed = safe rescue (lifesaving class if near death at pickup), prison = coercive
 * imprisonment (learned on waking if unconscious), drop on the ground = nothing. Squad carrying is exempt
 * from penalties; routine squad bedding earns nothing, a critical rescue still counts.
 * Food: food handed over by a conscious donor and then eaten by the recipient within a game day earns
 * the donor food_aid (moderate hunger) or survival_food (extreme); full recipients, own food and routine
 * squad supply (unless extreme) earn nothing; one class per donor/recipient/game day.
 */
trait SocialCareInterpreter
{
    public const AID_GROUP = ['routine_healing', 'meaningful_aid', 'lifesaving'];
    public const FOOD_GROUP = ['food_aid', 'survival_food'];

    private function care(string $key, $default)
    {
        $section = $this->rules->section('care');
        return $section[$key] ?? $default;
    }

    private static function num($value): ?float { return is_int($value) || is_float($value) ? (float)$value : null; }

    private static function squadPair(?array $x, ?array $y): bool
    {
        return $x && $y && ($x['in_player_faction'] ?? null) === true && ($y['in_player_faction'] ?? null) === true;
    }

    /** The recipient's current injury episode: its latest combat or KO incident as victim, else the game day. */
    private function injuryEpisode(array $event, array $recipient): string
    {
        $rows = $this->store->fetchRows("SELECT incident_id FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2
              AND ((state->>'kind'='combat' AND state->>'victim'=\$3) OR (state->>'kind'='ko' AND state->'victim'->>'entity_key'=\$3))
              AND game_ts<=\$4 ORDER BY game_ts DESC LIMIT 1", [$event['campaign_id'], $event['timeline_epoch'], $recipient['entity_key'], $event['game_ts']]);
        return $rows ? $rows[0]['incident_id'] : 'day' . intdiv((int)$event['game_ts'], 86400);
    }

    /** Did the helper (or the helper's side) cause the recipient's injury in this load? Then helping earns no trust. */
    private function helperCausedHarm(array $event, array $helper, array $recipient): bool
    {
        $rows = $this->store->fetchRows("SELECT state FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='combat'
              AND state->>'victim'=\$3 AND game_ts<=\$4 AND game_ts>=\$5", [$event['campaign_id'], $event['timeline_epoch'], $recipient['entity_key'],
              $event['game_ts'], $event['game_ts'] - (int)$this->care('own_harm_window_seconds', 86400)]);
        foreach ($rows as $row) {
            $state = json_decode($row['state'], true);
            $initiator = $state['parties'][$state['initiator'] ?? ''] ?? null;
            if (!$initiator) continue;
            if ($initiator['entity_key'] === $helper['entity_key'] || self::allies($initiator, $helper)) return true;
        }
        return false;
    }

    private function vitals(array $facts, string $prefix): ?array
    {
        if (($facts[$prefix . 'known'] ?? false) !== true) return null;
        $h = self::num($facts[$prefix . 'health'] ?? null); $b = self::num($facts[$prefix . 'blood'] ?? null); $l = self::num($facts[$prefix . 'bleed'] ?? null);
        if ($h === null || $b === null || $l === null) return null;
        // wound = damage points over all parts, untreated = damage not covered by bandaging (native since run m4).
        return ['health'=>$h, 'blood'=>$b, 'bleed'=>$l, 'wound'=>self::num($facts[$prefix . 'wound'] ?? null), 'untreated'=>self::num($facts[$prefix . 'untreated'] ?? null)];
    }

    private function nearDeath(?array $v): bool
    {
        return $v !== null && ($v['health'] <= (float)$this->care('near_death_health', -0.3)
            || $v['blood'] <= (float)$this->care('near_death_blood', 0.35));
    }

    /** routine_healing | meaningful_aid | lifesaving | null (no verified improvement). */
    public function aidClass(?array $before, ?array $after, ?bool $consciousBefore): ?string
    {
        if (!$before || !$after) return null;
        $gain = $after['health'] - $before['health'];
        $bleedStopped = $before['bleed'] > 0.0001 && $after['bleed'] <= $before['bleed'] * (float)$this->care('bleed_stop_ratio', 0.25);
        $critical = $before['health'] <= 0 || $before['blood'] <= (float)$this->care('critical_blood', 0.5) || $consciousBefore === false;
        $meaningful = (float)$this->care('meaningful_gain', 0.15);
        // First aid mostly bandages (run m4: flesh -41% stayed, bandaging 0 -> 96): treated = wound points newly covered.
        $treated = 0.0; $treatedShare = 0.0;
        if ($before['untreated'] !== null && $after['untreated'] !== null) {
            $treated = max(0.0, $before['untreated'] - $after['untreated']);
            $treatedShare = $treated / max(1.0, $before['untreated']);
        }
        $bigTreatment = $treated >= (float)$this->care('meaningful_treated_points', 30) && $treatedShare >= (float)$this->care('meaningful_treated_share', 0.3);
        if ($this->nearDeath($before) && ($bleedStopped || $gain >= $meaningful
            || ($bigTreatment && $treatedShare >= (float)$this->care('lifesaving_treated_share', 0.5)))) return 'lifesaving';
        if ($bleedStopped || $gain >= $meaningful || $bigTreatment || ($critical && $gain >= 0.05)) return 'meaningful_aid';
        if ($gain >= (float)$this->care('routine_gain', 0.02) || $treated >= (float)$this->care('routine_treated_points', 5)
            || ($before['bleed'] > 0 && $after['bleed'] < $before['bleed'] * 0.9)) return 'routine_healing';
        return null;
    }

    private function aid(array $event, string $mode): array
    {
        $p = $event['actor']; $r = $event['target'];
        if (!$p || !$r || $p['entity_key'] === $r['entity_key']) return [['status'=>'incomplete_roles']];
        $facts = $event['facts'];
        $class = $this->aidClass($this->vitals($facts, 'before_'), $this->vitals($facts, 'after_'), $facts['conscious_before'] ?? null);
        if (!$class) return [['status'=>'no_verified_improvement']];
        if ($this->helperCausedHarm($event, $p, $r)) return [['status'=>'no_trust_from_own_harm', 'component'=>$class]];
        $incident = 'aid:' . substr(hash('sha256', $r['entity_key'] . '|' . $this->injuryEpisode($event, $r)), 0, 24);
        $this->store->saveIncident($event, $incident, ['kind'=>'aid', 'phase'=>'resolved', 'recipient'=>$r, 'last_provider'=>$p, 'class'=>$class]);
        return [$this->effect($event, $mode, $r, $p, $class,
            ['awareness'=>'verified_aid', 'conscious'=>$r['conscious'] ?? null, 'note'=>($class === 'lifesaving' ? 'Saved my life: ' : 'Treated my wounds: ') . $p['name'], 'kind'=>'aid'],
            $incident, ['escalation_group'=>self::AID_GROUP])];
    }

    private function carryStart(array $event, string $mode): array
    {
        $c = $event['actor']; $t = $event['target'];
        if (!$c || !$t || $c['entity_key'] === $t['entity_key']) return [['status'=>'incomplete_roles']];
        $incident = 'carry:' . $event['sequence'] . ':' . substr(hash('sha256', $c['entity_key'] . '|' . $t['entity_key']), 0, 16);
        $vitals = $this->vitals($event['facts'], '');
        $state = ['kind'=>'carry', 'phase'=>'carrying', 'carrier'=>$c, 'target'=>$t, 'pickup_vitals'=>$vitals,
            'near_death'=>$this->nearDeath($vitals), 'conscious_at_pickup'=>$t['conscious'] ?? null, 'opened_ts'=>$event['game_ts'], 'outcome'=>null];
        $this->store->saveIncident($event, $incident, $state);
        if (self::squadPair($c, $t) || self::allies($c, $t)) return [['status'=>'exempt', 'note'=>'squad/ally carrying']];
        if (($t['conscious'] ?? null) !== true) return [['status'=>'latent', 'note'=>'unconscious: judged by the outcome']];
        return [$this->effect($event, $mode, $t, $c, 'outsider_pickup',
            ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>'Picked up by ' . $c['name'], 'kind'=>'carry'], $incident)];
    }

    private function openCarry(array $event, array $carrier, array $target): ?array
    {
        $rows = $this->store->fetchRows("SELECT incident_id,state FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='carry'
              AND state->'carrier'->>'entity_key'=\$3 AND state->'target'->>'entity_key'=\$4 AND state->>'phase' IN ('carrying','dropped')
              AND game_ts>=\$5 ORDER BY game_ts DESC LIMIT 1", [$event['campaign_id'], $event['timeline_epoch'], $carrier['entity_key'], $target['entity_key'],
              $event['game_ts'] - (int)$this->care('carry_window_seconds', 3600)]);
        return $rows ? ['id'=>$rows[0]['incident_id'], 'state'=>json_decode($rows[0]['state'], true)] : null;
    }

    private function carryEnd(array $event, string $mode): array
    {
        $c = $event['actor']; $t = $event['target'];
        if (!$c || !$t) return [['status'=>'incomplete_roles']];
        $open = $this->openCarry($event, $c, $t);
        if (!$open) return [['status'=>'no_carry_incident']];
        $open['state']['phase'] = 'dropped'; $open['state']['drop_ts'] = $event['game_ts'];
        $this->store->saveIncident($event, $open['id'], $open['state']);
        $place = (int)($event['facts']['in_something'] ?? -1);
        if ($place === 1 || $place === 2) return $this->placedOutcome($event, $mode, $c, $t, $place === 1 ? 'bed' : 'prison', $open);
        return [['status'=>'dropped', 'note'=>'a drop alone is no rescue; waiting for a bed/prison placement']];
    }

    private function placed(array $event, string $mode): array
    {
        $c = $event['actor']; $t = $event['target'];
        if (!$t) return [['status'=>'incomplete_roles']];
        if (!$c) return [['status'=>'recorded', 'note'=>'no recent carrier: nobody is credited or blamed']];
        $open = $this->openCarry($event, $c, $t);
        if (!$open) return [['status'=>'no_carry_incident']];
        return $this->placedOutcome($event, $mode, $c, $t, strval($event['facts']['place'] ?? ''), $open);
    }

    private function placedOutcome(array $event, string $mode, array $c, array $t, string $place, array $open): array
    {
        if (($open['state']['outcome'] ?? null) !== null) return [['status'=>'duplicate_outcome']];
        $open['state']['outcome'] = $place; $open['state']['phase'] = 'resolved';
        $this->store->saveIncident($event, $open['id'], $open['state']);
        $squad = self::squadPair($c, $t);
        if ($place === 'bed') {
            if ($this->helperCausedHarm($event, $c, $t)) return [['status'=>'no_trust_from_own_harm', 'component'=>'safe_rescue']];
            $critical = ($open['state']['near_death'] ?? false) === true;
            if ($squad && !$critical) return [['status'=>'squad_routine', 'note'=>'routine squad bedding']];
            return [$this->effect($event, $mode, $t, $c, $critical ? 'lifesaving' : 'safe_rescue',
                ['awareness'=>'verified_aid', 'conscious'=>$t['conscious'] ?? null, 'note'=>'Carried to safety by ' . $c['name'], 'kind'=>'rescue'],
                $open['id'], ['escalation_group'=>['safe_rescue', 'lifesaving']])];
        }
        if ($place === 'prison') {
            if ($squad) return [['status'=>'exempt', 'note'=>'squad control']];
            if (($t['conscious'] ?? null) === true) {
                return [$this->effect($event, $mode, $t, $c, 'imprisonment',
                    ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>'Caged by ' . $c['name'], 'kind'=>'imprisonment'], $open['id'])];
            }
            $ko = $this->activeKo($event, $t)[0] ?? null;
            if ($ko) {
                $ko['state']['caged_by'] = ['captor'=>$c, 'incident'=>$open['id']];
                $this->store->saveIncident($event, $ko['id'], $ko['state']);
            }
            return [['status'=>'latent_imprisonment', 'incident'=>$ko['id'] ?? $open['id']]];
        }
        return [['status'=>'recorded', 'place'=>$place]];
    }

    /** A conscious donor handed food to someone: remembered for the meal that follows. */
    private function foodGift(array $event): ?array
    {
        $recipient = $event['actor']; $donor = $event['target']; $food = $event['facts']['food_items'] ?? [];
        if (!$recipient || !$donor || !is_array($food) || !$food || ($donor['conscious'] ?? null) !== true) return null;
        $incident = 'food:' . substr(hash('sha256', $recipient['entity_key']), 0, 24);
        $rows = $this->store->fetchRows('SELECT state FROM social_incident WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3', [$event['campaign_id'], $event['timeline_epoch'], $incident]);
        $state = $rows ? json_decode($rows[0]['state'], true) : ['kind'=>'food', 'phase'=>'active', 'recipient'=>$recipient, 'gifts'=>[]];
        $state['gifts'][] = ['donor'=>$donor, 'items'=>$food, 'hunger'=>$event['facts']['recipient_hunger'] ?? null, 'game_ts'=>$event['game_ts']];
        $state['gifts'] = array_slice($state['gifts'], -16);
        $this->store->saveIncident($event, $incident, $state);
        return ['status'=>'food_gift_recorded', 'donor'=>$donor['name']];
    }

    private function eat(array $event, string $mode): array
    {
        $e = $event['actor'];
        if (!$e) return [['status'=>'incomplete_roles']];
        $incident = 'food:' . substr(hash('sha256', $e['entity_key']), 0, 24);
        $rows = $this->store->fetchRows('SELECT state FROM social_incident WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3', [$event['campaign_id'], $event['timeline_epoch'], $incident]);
        if (!$rows) return [['status'=>'own_food']];
        $state = json_decode($rows[0]['state'], true);
        $eaten = is_array($event['facts']['items'] ?? null) ? $event['facts']['items'] : [];
        $hunger = self::num($event['facts']['hunger_before'] ?? null);
        $window = (int)$this->care('food_gift_window_seconds', 86400);
        $results = [];
        foreach ($state['gifts'] as $i => &$gift) {
            if ($event['game_ts'] - (int)$gift['game_ts'] > $window) continue;
            $used = 0;
            foreach ($eaten as $key => $qty) {
                $left = (int)($gift['items'][$key] ?? 0);
                if ($left <= 0) continue;
                $take = min($left, (int)$qty); $gift['items'][$key] = $left - $take; $used += $take;
            }
            if ($used <= 0) continue;
            $donor = $gift['donor'];
            if ($hunger === null || $hunger < 0) { $results[] = ['status'=>'unknown_hunger']; continue; }
            $class = $hunger < (float)$this->care('survival_hunger_below', 1.0) ? 'survival_food'
                : ($hunger < (float)$this->care('moderate_hunger_below', 2.0) ? 'food_aid' : null);
            if (!$class) { $results[] = ['status'=>'not_hungry', 'donor'=>$donor['name']]; continue; }
            if (self::squadPair($donor, $e) && $class !== 'survival_food') { $results[] = ['status'=>'squad_routine_supply']; continue; }
            $day = 'meal:' . substr(hash('sha256', $e['entity_key'] . '|' . $donor['entity_key'] . '|' . intdiv((int)$event['game_ts'], 86400)), 0, 24);
            $results[] = $this->effect($event, $mode, $e, $donor, $class,
                ['awareness'=>'verified_aid', 'conscious'=>true, 'note'=>'Fed by ' . $donor['name'] . ' when hungry', 'kind'=>'food'],
                $day, ['escalation_group'=>self::FOOD_GROUP]);
        }
        unset($gift);
        $state['gifts'] = array_values(array_filter($state['gifts'], fn($g) => array_sum(array_map('intval', $g['items'])) > 0 && $event['game_ts'] - (int)$g['game_ts'] <= $window));
        $this->store->saveIncident($event, $incident, $state);
        return $results ?: [['status'=>'own_food']];
    }
}
