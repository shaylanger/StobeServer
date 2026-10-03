<?php
declare(strict_types=1);

/**
 * REL phase 5: property and economy (used by SocialInterpreter).
 *
 * Theft: only a confirmed transfer of stolen-flagged items from a conscious owner who CAUGHT it scores
 * (native 'caught' is null until an engine detection signal is proven: then nothing scores, by design).
 * Permission checks never reach here. Severity by share of the owner's belongings, food taken from a
 * starving owner = major. Giving the same items back within a day is a small compensation, once.
 * Squad members handling each other's things are exempt.
 * Gifts: a conscious non-food, non-stolen hand-over outside a deal = gift (+1..+4) to the recipient's view.
 * Trade: only the game's own purchase path with a character seller; price vs the game's value of the goods
 * decides favorable/generous/exceptional (fair = nothing); a shop/faction purse or missing seller credits
 * nobody. All economic gains share decreasing weights and a day budget (+6) and never lift a relationship
 * past +30 by economics alone.
 */
trait SocialPropertyInterpreter
{
    public const PROPERTY_COMPONENTS = ['petty_theft', 'theft', 'major_theft', 'primary_weapon_theft', 'all_property_theft'];
    public const ECONOMIC_COMPONENTS = ['favorable_trade', 'generous_trade', 'exceptional_trade', 'gift'];

    private function economy(string $key, $default)
    {
        $section = $this->rules->section('economy');
        return $section[$key] ?? $default;
    }

    /** Repeat count and today's economic gain for this observer/culprit pair (by name, across loads of the campaign). */
    private function economicContext(array $event, array $observer, array $culprit): array
    {
        $rows = $this->store->fetchRows("SELECT count(*) AS n, COALESCE(sum(delta),0) AS gain FROM social_effect WHERE campaign_id=\$1
              AND lower(detail->>'observer_name')=lower(\$2) AND lower(detail->>'culprit_name')=lower(\$3) AND component = ANY(\$4::text[])
              AND game_ts/86400 = \$5::bigint/86400", [$event['campaign_id'], $observer['name'], $culprit['name'],
              '{' . implode(',', self::ECONOMIC_COMPONENTS) . '}', $event['game_ts']]);
        return ['repeat_count'=>(int)($rows[0]['n'] ?? 0), 'economic_day_gain'=>max(0, (int)($rows[0]['gain'] ?? 0))];
    }

    private function inDeal(array $a, array $b): bool
    {
        $exists = $this->store->fetchRows("SELECT to_regclass('stobe_social_contract') IS NOT NULL AS ok");
        if (($exists[0]['ok'] ?? 'f') !== 't') return false;
        $rows = $this->store->fetchRows("SELECT 1 FROM stobe_social_contract WHERE lower(npc_name) IN (lower(\$1), lower(\$2))
              AND performance_started_unix > 0 AND (resolved_at IS NULL OR resolved_at > now() - interval '10 minutes') LIMIT 1", [$a['name'], $b['name']]);
        return (bool)$rows;
    }

    /** Conscious owner lost items to someone: theft, a returned theft, a food gift (phase 4) or a gift. */
    private function consciousTransfer(array $event, string $mode): array
    {
        $taker = $event['actor']; $owner = $event['target']; $facts = $event['facts'];
        if (!$taker || !$owner || $taker['entity_key'] === $owner['entity_key']) return [['status'=>'recorded', 'note'=>'dropped or unknown taker']];
        $stolen = is_array($facts['stolen_items'] ?? null) ? $facts['stolen_items'] : [];
        $squad = self::squadPair($taker, $owner);
        if ($stolen && !$squad) return [$this->theft($event, $mode, $taker, $owner, $stolen)];
        if (!$squad && ($returned = $this->returnedProperty($event, $mode, $taker, $owner))) return [$returned];
        if (!$stolen && ($food = $this->foodGift($event))) return [$food]; // squad food is judged at the meal (survival only)
        if ($squad) return [['status'=>'exempt', 'note'=>'squad inventory management']];
        if ($this->inDeal($taker, $owner)) return [['status'=>'deal_payment', 'note'=>'scored by the agreement outcome']];
        return [$this->effect($event, $mode, $taker, $owner, 'gift',
            ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>'Got a gift from ' . $owner['name'], 'kind'=>'gift'],
            'gift:' . $event['sequence'], $this->economicContext($event, $taker, $owner))];
    }

    private function theft(array $event, string $mode, array $thief, array $owner, array $stolen): array
    {
        $facts = $event['facts'];
        $incident = 'theft:' . $event['sequence'] . ':' . substr(hash('sha256', $owner['entity_key'] . '|' . $thief['entity_key']), 0, 16);
        $qty = array_sum(array_map('intval', $stolen));
        $after = is_int($facts['loser_inventory_total'] ?? null) ? $facts['loser_inventory_total'] : null;
        $share = $after === null ? null : $qty / max(1, $after + $qty);
        $food = array_intersect_key($stolen, is_array($facts['food_items'] ?? null) ? $facts['food_items'] : []);
        $hunger = self::num($facts['loser_hunger'] ?? null);
        $component = 'theft';
        if ($share !== null && $share >= 0.8) $component = 'all_property_theft';
        elseif (($share !== null && $share >= 0.4) || ($food && $hunger !== null && $hunger < (float)$this->care('survival_hunger_below', 1.0))) $component = 'major_theft';
        elseif ($qty === 1 && $share !== null && $share < 0.1) $component = 'petty_theft';
        $this->store->saveIncident($event, $incident, ['kind'=>'theft', 'phase'=>'resolved', 'owner'=>$owner, 'thief'=>$thief,
            'items'=>$stolen, 'component'=>$component, 'caught'=>$facts['caught'] ?? null, 'returned'=>false]);
        if (($facts['caught'] ?? null) !== true) return ['status'=>'unseen_or_undetected', 'component'=>$component, 'note'=>'no detection: no personal blame'];
        return $this->effect($event, $mode, $owner, $thief, $component,
            ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>'Caught ' . $thief['name'] . ' stealing from me', 'kind'=>'theft'], $incident);
    }

    /** The thief hands the same items back within a game day: a small compensation, once per theft. */
    private function returnedProperty(array $event, string $mode, array $receiver, array $giver): ?array
    {
        $rows = $this->store->fetchRows("SELECT incident_id,state FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'kind'='theft'
              AND state->'owner'->>'entity_key'=\$3 AND state->'thief'->>'entity_key'=\$4 AND (state->>'returned')::boolean IS NOT TRUE
              AND (state->>'caught')::boolean IS TRUE AND game_ts>=\$5 ORDER BY game_ts DESC LIMIT 1",
            [$event['campaign_id'], $event['timeline_epoch'], $receiver['entity_key'], $giver['entity_key'], $event['game_ts'] - 86400]);
        if (!$rows) return null;
        $state = json_decode($rows[0]['state'], true);
        if (!array_intersect_key(is_array($event['facts']['items'] ?? null) ? $event['facts']['items'] : [], $state['items'])) return null;
        $state['returned'] = true;
        $this->store->saveIncident($event, $rows[0]['incident_id'], $state);
        return $this->effect($event, $mode, $receiver, $giver, 'property_returned',
            ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>$giver['name'] . ' gave back what was stolen', 'kind'=>'restitution'], $rows[0]['incident_id']);
    }

    private function trade(array $event, string $mode): array
    {
        $buyer = $event['actor']; $seller = $event['target']; $facts = $event['facts'];
        if (!$buyer) return [['status'=>'incomplete_roles']];
        if (!$seller) return [['status'=>'no_personal_seller', 'note'=>'shop storage or unknown seller: nobody credited']];
        $spent = (int)($facts['buyer_spent'] ?? 0); $gained = (int)($facts['seller_gained'] ?? 0); $ref = (int)($facts['reference_value'] ?? -1);
        if ($spent > 0 && $gained <= 0) return [['status'=>'shared_purse', 'note'=>'cats did not reach the seller personally']];
        $paid = max($spent, $gained);
        if ($ref <= 0 || $paid <= 0) return [['status'=>'no_reference']];
        $ratio = $paid / $ref;
        $results = [];
        // The seller's view of a customer who pays well; the buyer's view of a seller who sells cheap.
        $up = $ratio > (float)$this->economy('exceptional_above', 2.5) ? 'exceptional_trade'
            : ($ratio > (float)$this->economy('generous_above', 1.75) ? 'generous_trade' : ($ratio > (float)$this->economy('favorable_above', 1.25) ? 'favorable_trade' : null));
        $down = $ratio < (float)$this->economy('cheap_exceptional_below', 0.4) ? 'exceptional_trade'
            : ($ratio < (float)$this->economy('cheap_generous_below', 0.6) ? 'generous_trade' : ($ratio < (float)$this->economy('cheap_favorable_below', 0.8) ? 'favorable_trade' : null));
        $incident = 'trade:' . $event['sequence'];
        if ($up) $results[] = $this->effect($event, $mode, $seller, $buyer, $up,
            ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>$buyer['name'] . ' paid well', 'kind'=>'trade'], $incident, $this->economicContext($event, $seller, $buyer));
        if ($down) $results[] = $this->effect($event, $mode, $buyer, $seller, $down,
            ['awareness'=>'directly_experienced', 'conscious'=>true, 'note'=>$seller['name'] . ' sold cheap', 'kind'=>'trade'], $incident, $this->economicContext($event, $buyer, $seller));
        return $results ?: [['status'=>'fair_trade', 'ratio'=>round($ratio, 2)]];
    }
}
