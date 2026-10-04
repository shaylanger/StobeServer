<?php
declare(strict_types=1);
require_once __DIR__ . '/social_store.php';
require_once __DIR__ . '/social_fights.php'; // B 55

function stobeSocialMode(): string
{
    $mode = getSetting('SOCIAL_RELATIONSHIP_MODE','off');
    return in_array($mode,['shadow','enabled'],true) ? $mode : 'off';
}

/**
 * B 55: the mode social capture runs in. SOCIAL_RELATIONSHIP_MODE when shadow/enabled; while it is off, "fights"
 * (only fights and deal outcomes change relationships, see lib/social_fights.php) unless SOCIAL_FIGHTS_LIVE=false.
 */
function stobeSocialIngestMode(): string
{
    $mode = stobeSocialMode();
    if ($mode !== 'off') return $mode;
    try { return getSettingBool('SOCIAL_FIGHTS_LIVE', true) ? 'fights' : 'off'; } catch (Throwable $e) { return 'off'; }
}

/** Campaign id used when Playthrough Saves (automatic switching) is off: one shared server timeline. */
const STOBE_SOCIAL_LEGACY_CAMPAIGN = 'legacy';

function stobeSocialPlaythroughSwitching(object $db): bool
{
    $row = $db->fetchOne("SELECT value FROM stobe_meta.settings WHERE key='PLAYTHROUGH_AUTO_SWITCH'");
    return is_array($row) && json_decode(strval($row['value'] ?? 'null'), true) === true;
}

/**
 * The scope an event must match. With Playthrough Saves on: the authenticated handshake (campaign,
 * load id, client). With it off (no handshake, no campaign id; the game then sends campaign "legacy"):
 * the event's own load id and client, accepted only for campaign "legacy" and never older than the
 * newest load this client already sent (stale queued events after a reload are refused).
 * Without an event (server-owned outcomes): the newest load seen in the legacy campaign.
 */
function stobeSocialScope(object $db, ?array $event = null): array
{
    $row = $db->fetchOne("SELECT value FROM stobe_meta.settings WHERE key='PLAYTHROUGH_SESSION'");
    $session = json_decode(strval($row['value'] ?? '{}'),true);
    if (is_array($session) && ($session['status'] ?? '') === 'ready' && stobeSocialPlaythroughSwitching($db)) {
        return ['campaign_id'=>SocialEventContract::token($session['character_id'] ?? null,'campaign'),
            'timeline_epoch'=>strval(SocialEventContract::number($session['load_id'] ?? null,'load')),
            'native_session_id'=>SocialEventContract::token($session['client_id'] ?? null,'client')];
    }
    if (stobeSocialPlaythroughSwitching($db)) throw new DomainException('Social capture requires a connected campaign');
    if ($event === null) {
        $latest = $db->fetchOne("SELECT timeline_epoch,native_session_id FROM social_event_inbox WHERE campaign_id=$1 AND native_session_id<>'server' ORDER BY created_at DESC LIMIT 1", [STOBE_SOCIAL_LEGACY_CAMPAIGN]);
        if (!is_array($latest)) throw new DomainException('No legacy social timeline yet');
        return ['campaign_id'=>STOBE_SOCIAL_LEGACY_CAMPAIGN, 'timeline_epoch'=>strval($latest['timeline_epoch']), 'native_session_id'=>strval($latest['native_session_id'])];
    }
    if (($event['campaign_id'] ?? '') !== STOBE_SOCIAL_LEGACY_CAMPAIGN) throw new DomainException('Campaign id without Playthrough Saves');
    $epoch = strval($event['timeline_epoch'] ?? '');
    if (!preg_match('/^[0-9]{1,18}$/D', $epoch)) throw new DomainException('Invalid load id');
    $newest = $db->fetchOne("SELECT MAX(timeline_epoch::bigint) AS n FROM social_event_inbox WHERE campaign_id=$1 AND native_session_id=$2", [STOBE_SOCIAL_LEGACY_CAMPAIGN, strval($event['native_session_id'] ?? '')]);
    if (is_array($newest) && $newest['n'] !== null && (int)$epoch < (int)$newest['n']) throw new DomainException('Stale load');
    return ['campaign_id'=>STOBE_SOCIAL_LEGACY_CAMPAIGN, 'timeline_epoch'=>$epoch, 'native_session_id'=>strval($event['native_session_id'])];
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
