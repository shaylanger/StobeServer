<?php
declare(strict_types=1);
require_once __DIR__ . '/social_store.php';

function stobeSocialMode(): string
{
    $mode = getSetting('SOCIAL_RELATIONSHIP_MODE','off');
    return in_array($mode,['shadow','enabled'],true) ? $mode : 'off';
}

function stobeSocialScope(object $db): array
{
    $row = $db->fetchOne("SELECT value FROM stobe_meta.settings WHERE key='PLAYTHROUGH_SESSION'");
    $session = json_decode(strval($row['value'] ?? '{}'),true);
    if (!is_array($session) || ($session['status'] ?? '') !== 'ready') throw new DomainException('Social capture requires a connected campaign');
    return ['campaign_id'=>SocialEventContract::token($session['character_id'] ?? null,'campaign'),
        'timeline_epoch'=>strval(SocialEventContract::number($session['load_id'] ?? null,'load')),
        'native_session_id'=>SocialEventContract::token($session['client_id'] ?? null,'client')];
}

function stobeSocialResolveEntity(string $key): ?array
{
    // Durable binding only. Serial-only phase 1 captures remain unbound for future telemetry work.
    $rows = $GLOBALS['db']->fetchAll("SELECT id,name FROM core_npc WHERE metadata->>'storage_id'=$1",[$key]);
    if (count($rows) !== 1 || stobeIsGenericNpcName($rows[0]['name'])) return null;
    return ['id'=>(int)$rows[0]['id'],'name'=>$rows[0]['name']];
}

function stobeSocialMergeMap(array $base, array $incoming, array $current): array
{
    foreach ($incoming as $target=>$next) {
        if (!is_array($next)) continue;
        $baseKey = stobeFindRelationshipEntryKey($base,strval($target));
        $currentKey = stobeFindRelationshipEntryKey($current,strval($target));
        $old = $base[$baseKey] ?? [];
        $live = $current[$currentKey] ?? [];
        $key = $currentKey ?: $target;
        $delta = (int)($next['aff'] ?? 0) - (int)($old['aff'] ?? 0);
        $merged = $live;
        $merged['aff'] = max(-100,min(100,(int)($live['aff'] ?? 0)+$delta));
        $merged['tier'] = stobeRelationshipTierLabel($merged['aff']);
        foreach (['type','note','updated_at'] as $field) {
            if (array_key_exists($field,$next) && (!array_key_exists($field,$old) || $next[$field] !== $old[$field])) $merged[$field] = $next[$field];
        }
        $current[$key] = $merged;
    }
    return $current;
}
