<?php

// Explicit gameplay table policy. Shared presets/libraries and unknown plugin tables stay live.
function pts_table_policy(): array {
    return [
        'global' => explode(',', 'bio_random,bio_random_custom,bio_unique,bio_unique_custom,core_action,core_action_custom,core_api_badge,core_llm_connector,core_narrator,core_profile_import_rules,core_profiles,core_stt_connector,core_tts_connector,core_tts_pronunciation,core_voiceid,core_voiceid_custom,description_images,descriptions,descriptions_custom,location_zones,prompts,rename_global,rename_global_custom,rename_token_global,rename_token_global_custom,stobe_settings_presets'),
        'playthrough' => explode(',', 'audit_llm,audit_memory,audit_request,autonomy_decision,autonomy_economy_snapshot,autonomy_event,autonomy_pilot_step,autonomy_session,core_npc_master,core_npc_master_history,diarylog,eventlog,faction_relation_state,log,memory,memory_summary,player_base_history,player_base_presence,player_bases,speech,world_knowledge,world_knowledge_context_rule,world_state,world_state_addendum,world_state_addendum_custom,world_state_definition,world_state_query_result'),
        'mixed' => ['conf_opts', 'general_settings'],
        'infrastructure' => ['database_versioning'],
        // Tables absent from these lists are unmanaged and must never be cleared.

    ];
}

// Only gameplay tables and gameplay rows of mixed tables belong in a save.
function pts_playthrough_tables(): array {
    $policy = pts_table_policy();
    return array_merge($policy['playthrough'], $policy['mixed']);
}

// Shared libraries excluded here include biography templates, descriptions and preset stores.
// STOBE also keeps reusable voice/name pools shared.
// stobe_settings_presets combines global and profile presets, so its entire library stays shared.

// Run after database updates so new tables and retired labels follow the capture policy.
function pts_update_playthrough_policy($conn): bool {
    if (!pts_ensure_functions($conn)) return false;
    $result = @pg_query_params($conn,
        'SELECT stobe_meta.sync_playthrough_comments(ARRAY(SELECT jsonb_array_elements_text($1::jsonb)))',
        [json_encode(pts_playthrough_tables())]);
    if (!$result) Logger::error('Could not refresh Playthrough Save table comments: ' . pg_last_error($conn));
    return $result !== false;
}
