<?php
declare(strict_types=1);
require_once __DIR__ . '/social_runtime.php';

/**
 * REL phase 6: keep the dialogue evaluator (LLM) from re-scoring mechanical outcomes.
 * Only with SOCIAL_RELATIONSHIP_MODE=enabled and SOCIAL_CATEGORY_DIALOGUE on:
 * - affinity deltas from dialogue are clamped to the insult/compliment band (rules dialogue.clamp, -8..+3);
 * - a target with a mechanical REL effect for this speaker in the last game hour keeps only type/note:
 *   the fight/aid/theft was already counted by REL.
 * Off/shadow: updates pass unchanged (legacy behaviour).
 */
function stobeSocialFilterDialogueUpdates(string $speaker, array $updates): array
{
    if (!$updates || stobeSocialMode() !== 'enabled' || !getSettingBool('SOCIAL_CATEGORY_DIALOGUE', true)) return $updates;
    $rules = (new SocialRules())->section('dialogue');
    [$lo, $hi] = $rules['clamp'] ?? [-8, 3];
    $window = (int)($rules['mechanical_window_seconds'] ?? 3600);
    $db = $GLOBALS['db'];
    $latest = $db->fetchOne('SELECT MAX(game_ts) AS ts FROM social_effect WHERE applied');
    $since = (int)($latest['ts'] ?? 0) - $window;
    foreach ($updates as &$update) {
        if (!is_array($update) || !array_key_exists('aff_delta', $update)) continue;
        $target = strval($update['target'] ?? '');
        $recent = $target === '' ? false : $db->fetchOne("SELECT 1 AS x FROM social_effect WHERE applied AND delta<>0 AND game_ts >= $1
              AND lower(detail->>'observer_name')=lower($2) AND lower(detail->>'culprit_name')=lower($3) AND component NOT LIKE 'witness_%' LIMIT 1",
            [$since, $speaker, $target]);
        $before = (int)$update['aff_delta'];
        $update['aff_delta'] = $recent ? 0 : max((int)$lo, min((int)$hi, $before));
        if ($before !== (int)$update['aff_delta'] && function_exists('stobeLogRelationshipInfo')) {
            stobeLogRelationshipInfo('SOCIAL_DIALOGUE filtered', ['speaker'=>$speaker, 'target'=>$target, 'from'=>$before,
                'to'=>$update['aff_delta'], 'reason'=>$recent ? 'mechanical_outcome_already_counted' : 'dialogue_band']);
        }
    }
    unset($update);
    return $updates;
}
