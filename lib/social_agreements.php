<?php
declare(strict_types=1);
require_once __DIR__ . '/social_runtime.php';
require_once __DIR__ . '/social_identity.php';

/**
 * REL phase 5: deal outcomes from the negotiation engine as server-owned semantic events.
 * COMPLETE: kept_coercive_deal (combat/surrender deals, 0..+2) or kept_promise (+1..+3).
 * BREACHED_PLAYER: broken_promise (-5..-15), plus betrayal (-20..-40) when the player broke the truce
 * (attacked after the NPC's surrender/ceasefire was accepted). BREACHED_NPC / IMPOSSIBLE: nothing here
 * (the NPC's own betrayal choice stays with the negotiation engine, which already needs negative affinity).
 * Returns true when REL applied the outcome (mode enabled): the caller then skips its legacy delta.
 * Off/shadow/category off/no connected campaign: false (legacy behaviour; shadow also records would-be effects).
 */
function stobeSocialAgreementOutcome(array $deal, string $player, string $status, array $termState): bool
{
    $mode = stobeSocialMode();
    if ($mode === 'off' || !getSettingBool('SOCIAL_CATEGORY_AGREEMENTS', true)) return false;
    $components = [];
    $hostile = in_array(strval($deal['kind'] ?? ''), ['combat', 'surrender'], true);
    if ($status === 'COMPLETE') $components[] = $hostile ? 'kept_coercive_deal' : 'kept_promise';
    if ($status === 'BREACHED_PLAYER') {
        $components[] = 'broken_promise';
        foreach ($termState as $term) if (!empty($term['player_broke_truce'])) { $components[] = 'betrayal'; break; }
    }
    if (!$components) return false;
    try {
        $db = $GLOBALS['db'];
        $scope = stobeSocialScope($db);
        $npcName = strval($deal['npc_name'] ?? '');
        $npcRow = getNpcData($npcName);
        if (!is_array($npcRow)) return false;
        $token = static fn(string $s) => substr(preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $s) ?? 'x', 0, 100);
        $meta = function_exists('normalizeCoreNpcMetadata') ? normalizeCoreNpcMetadata($npcRow['metadata'] ?? '{}')
            : (is_array($npcRow['metadata'] ?? null) ? $npcRow['metadata'] : (json_decode(strval($npcRow['metadata'] ?? '{}'), true) ?: []));
        $npc = ['entity_key'=>'npc:' . intval($npcRow['id']), 'serial'=>0, 'name'=>strval($npcRow['name']),
            'storage_id'=>is_string($meta['storage_id'] ?? null) && $meta['storage_id'] !== '' ? $meta['storage_id'] : null, 'faction'=>null,
            'in_player_faction'=>null, 'conscious'=>true];
        $playerEntity = ['entity_key'=>'player:' . $token(strtolower($player)), 'serial'=>0, 'name'=>$player, 'storage_id'=>null,
            'faction'=>null, 'in_player_faction'=>true, 'conscious'=>true];
        $contract = $token(strval($deal['contract_id'] ?? ''));
        if ($contract === '') return false;
        $store = new SocialStore($db);
        $event = $store->recordInternal($scope, $mode, 'agreement:' . $contract . ':' . $status, function_exists('stobeNegLatestGamets') ? max(0, stobeNegLatestGamets()) : 0,
            $playerEntity, $npc, ['source'=>'server_agreement', 'contract_id'=>$contract, 'status'=>$status, 'deal_kind'=>strval($deal['kind'] ?? '')]);
        if ($event === null) return $mode === 'enabled'; // already recorded (retry): legacy must not double-apply either
        $resolve = static fn(string $key) => SocialIdentity::resolve($key === $npc['entity_key'] ? $npc : $playerEntity, $key === $npc['entity_key'] ? 'observer' : 'culprit');
        foreach ($components as $component) {
            $note = match ($component) {
                'kept_coercive_deal', 'kept_promise' => $player . ' kept our deal',
                'broken_promise' => $player . ' broke our deal',
                default => $player . ' attacked me after we agreed to stop',
            };
            $result = $store->apply($event, $npc['entity_key'], $playerEntity['entity_key'], $component,
                ['responsible_entity'=>$playerEntity['entity_key'], 'awareness'=>'directly_experienced', 'confidence'=>'certain', 'conscious'=>true, 'note'=>$note, 'kind'=>'agreement'],
                $resolve, $mode, [], 'deal:' . $contract);
            if (function_exists('stobeLogRelationshipInfo')) stobeLogRelationshipInfo('SOCIAL_INTERPRET', ['mode'=>$mode, 'kind'=>'agreement', 'contract'=>$contract,
                'status'=>$status, 'component'=>$component, 'npc'=>$npc['name'], 'result'=>$result['status'] ?? null, 'delta'=>$result['effect']['delta'] ?? null]);
        }
        return $mode === 'enabled';
    } catch (Throwable $e) {
        if (function_exists('stobeLogWarn')) stobeLogWarn('REL agreement outcome failed; legacy deal consequence used', ['error'=>$e->getMessage()]);
        return false;
    }
}
