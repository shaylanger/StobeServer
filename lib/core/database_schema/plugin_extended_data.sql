-- Keep live NPCs and history compatible in the same atomic schema update.
ALTER TABLE public.core_npc_master
    ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb
    CHECK (jsonb_typeof(plugin_extended_data) = 'object');
ALTER TABLE public.core_npc_master_history
    ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb
    CHECK (jsonb_typeof(plugin_extended_data) = 'object');

CREATE OR REPLACE VIEW public.core_npc AS SELECT * FROM public.core_npc_master;

CREATE OR REPLACE FUNCTION core_npc_master_history_audit_fn()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    src RECORD;
    reason TEXT;
    normalized_old JSONB;
    normalized_new JSONB;
    snapshot_payload JSONB;
    source_npc_id INTEGER;
BEGIN
    IF TG_OP='UPDATE' THEN
        normalized_old := COALESCE(to_jsonb(OLD), '{}'::jsonb) - '{updated_at,metadata,extended_data,plugin_extended_data,limbs,blood,hunger,skills,relationships}'::text[];
        normalized_new := COALESCE(to_jsonb(NEW), '{}'::jsonb) - '{updated_at,metadata,extended_data,plugin_extended_data,limbs,blood,hunger,skills,relationships}'::text[];
        IF normalized_old = normalized_new THEN
            RETURN NEW;
        END IF;
    END IF;

    IF TG_OP='DELETE' THEN
        src := OLD;
        reason := 'delete';
    ELSIF TG_OP='INSERT' THEN
        src := NEW;
        reason := 'create';
    ELSE
        src := NEW;
        reason := 'snapshot';
    END IF;

    snapshot_payload := COALESCE(to_jsonb(src), '{}'::jsonb) - '{updated_at,metadata,extended_data,plugin_extended_data,limbs,blood,hunger,skills,relationships}'::text[];
    source_npc_id := COALESCE(src.id, 0);

    INSERT INTO core_npc_master_history (
        npc_id, name, original_name, npc_favorite, lock_profile,
        prompt_head, personality, backstory, emote_moods, occupation,
        appearance, equipment, inventory, skills, speechstyle,
        goals, relationships, voiceid, metadata, race, faction, gender,
        profile_id, dynamic_profile, plugin_extended_data, extended_data, md5, gamets_last_updated,
        bounty, limbs, blood, hunger, tags, is_animal, is_slave,
        world_knowledge_tags,
        snapshot_reason, snapshot_hash, source_created_at, source_updated_at, created
    ) VALUES (
        COALESCE(src.id,0), COALESCE(src.name,''), COALESCE(src.original_name,''),
        COALESCE(src.npc_favorite,FALSE), COALESCE(src.lock_profile,FALSE),
        COALESCE(src.prompt_head,''), COALESCE(src.personality,''), COALESCE(src.backstory,''), COALESCE(src.emote_moods,''), COALESCE(src.occupation,''),
        COALESCE(src.appearance,''), COALESCE(src.equipment,''), COALESCE(src.inventory,''), COALESCE(src.skills,''), COALESCE(src.speechstyle,''),
        COALESCE(src.goals,''), COALESCE(src.relationships,''), COALESCE(src.voiceid,''), COALESCE(src.metadata,'{}'::jsonb), COALESCE(src.race,''), COALESCE(src.faction,''), COALESCE(src.gender,''),
        src.profile_id, FALSE, src.plugin_extended_data, COALESCE(src.extended_data,'{}'::jsonb), COALESCE(src.md5,''), COALESCE(src.gamets_last_updated,0),
        COALESCE(src.bounty,'{}'::jsonb), COALESCE(src.limbs,'{}'::jsonb), COALESCE(src.blood,'0/0'), COALESCE(src.hunger,'300/300'), COALESCE(src.tags,''), COALESCE(src.is_animal,FALSE), COALESCE(src.is_slave,FALSE),
        COALESCE(src.world_knowledge_tags,''),
        reason, md5(COALESCE(snapshot_payload::text,'')), src.created_at, src.updated_at, NOW()
    );

    IF source_npc_id > 0 THEN
        DELETE FROM core_npc_master_history
        WHERE history_id IN (
            SELECT history_id
            FROM core_npc_master_history
            WHERE npc_id = source_npc_id
            ORDER BY history_id DESC
            OFFSET 50
        );
    END IF;

    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
