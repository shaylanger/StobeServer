<?php
declare(strict_types=1);
require_once __DIR__ . '/social_runtime.php';

/**
 * REL phase 7: emergent STOBE recruitment needs affinity >= 76 plus meaningful trust evidence (lifesaving,
 * a completed slave escape, repeated major aid / rescue) and no unresolved severe grievance. Economic
 * evidence never qualifies. Applied only with SOCIAL_RELATIONSHIP_MODE=enabled and SOCIAL_CATEGORY_RECRUITMENT
 * on; SOCIAL_RECRUITMENT_OVERRIDE=true is the documented forced/cheat override. Vanilla recruitment (paid
 * recruits, slaves) never passes through here. The NPC may still refuse above the threshold (dialogue).
 */
/**
 * SR30 test switch (general_settings SOCIAL_TEST_FORCE_JOIN_ATTEMPT, off by default): when the player's line asks a
 * non-squad NPC to join (join/recruit/come with me), the NPC's reply action becomes JoinParty so the recruitment
 * gate decides (the LLM almost never tries on its own). Logged every time it fires.
 */
function stobeSocialTestForceJoinAttempt(string $rawActionTag, string $character, string $eventType): string
{
    try { $on = getSettingBool('SOCIAL_TEST_FORCE_JOIN_ATTEMPT', false); } catch (Throwable $e) { $on = false; }
    if (!$on || trim($character) === '' || strtolower(trim($eventType)) !== 'chat') return $rawActionTag;
    $line = strval($GLOBALS['STOBE_CURRENT_PLAYER_MESSAGE'] ?? '');
    if (!preg_match('/\b(join|recruit)\b|\bcome with (me|us)\b/i', $line)) return $rawActionTag;
    $npc = getNpcData($character);
    if (!is_array($npc) || !$npc || npcIsInPlayerFaction($npc)) return $rawActionTag;
    stobeLogWarn('REL test switch SOCIAL_TEST_FORCE_JOIN_ATTEMPT: JoinParty forced (turn it off after the test)',
        ['npc'=>$character, 'replaced'=>$rawActionTag]);
    return 'JoinParty@';
}

function stobeSocialRecruitmentDecision(string $npc, string $player, int $affinity): array
{
    if (stobeSocialMode() !== 'enabled' || !getSettingBool('SOCIAL_CATEGORY_RECRUITMENT', true)) return ['allowed'=>true, 'reason'=>'rel_not_enabled'];
    if (getSettingBool('SOCIAL_RECRUITMENT_OVERRIDE', false)) return ['allowed'=>true, 'reason'=>'override'];
    $rows = $GLOBALS['db']->fetchAll("SELECT component, count(*) AS n FROM social_effect WHERE applied AND delta<>0
          AND lower(detail->>'observer_name')=lower($1) AND lower(detail->>'culprit_name')=lower($2) GROUP BY component", [$npc, $player]) ?: [];
    $count = [];
    foreach ($rows as $row) $count[$row['component']] = (int)$row['n'];
    $trust = [];
    if (($count['lifesaving'] ?? 0) > 0) $trust[] = 'lifesaving';
    if (($count['slave_escape'] ?? 0) > 0) $trust[] = 'slave_escape';
    if (($count['meaningful_aid'] ?? 0) + ($count['safe_rescue'] ?? 0) >= 2) $trust[] = 'major_aid';
    $grievances = [];
    foreach (['enslavement', 'betrayal', 'all_property_theft', 'major_theft', 'maiming', 'imprisonment'] as $severe) {
        if (($count[$severe] ?? 0) > 0) { $grievances[] = 'severe_unresolved'; break; }
    }
    $allowed = SocialRules::recruitment($affinity, $trust, $grievances);
    $reason = $allowed ? 'trusted' : ($affinity < 76 ? 'affinity_below_76' : ($grievances ? 'severe_grievance' : 'no_trust_evidence'));
    return ['allowed'=>$allowed, 'reason'=>$reason, 'trust'=>$trust, 'grievances'=>$grievances, 'affinity'=>$affinity];
}
