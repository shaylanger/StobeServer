-- ============================================================
-- StobeServer Database Schema
-- Database: stobe
-- Run on PostgreSQL as: createdb --template=template0 --encoding=UTF8 --locale=C --owner=dwemer stobe && psql -d stobe -f schema.sql
-- ============================================================

SET client_encoding = 'UTF8';

DO $$
BEGIN
    IF current_setting('server_encoding') <> 'UTF8' THEN
        RAISE EXCEPTION 'StobeServer requires a UTF8 PostgreSQL database; current encoding is %',
            current_setting('server_encoding');
    END IF;
END $$;

CREATE EXTENSION IF NOT EXISTS vector;

-- ----------------------------------------------------------
-- EVENTLOG — All game events (core of context system)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS eventlog (
    rowid BIGSERIAL PRIMARY KEY,
    type VARCHAR(128) NOT NULL,
    data TEXT,
    sess VARCHAR(1024) DEFAULT 'pending',
    gamets BIGINT NOT NULL,
    localts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW()),
    ts BIGINT,
    people TEXT,
    location TEXT,
    geo JSONB DEFAULT '{}'::jsonb,
    utterance_id TEXT,
    delivery_state TEXT
);

CREATE INDEX IF NOT EXISTS idx_eventlog_type ON eventlog (type);
CREATE INDEX IF NOT EXISTS idx_eventlog_gamets ON eventlog (gamets DESC, ts DESC, rowid DESC);
CREATE INDEX IF NOT EXISTS idx_eventlog_localts ON eventlog (localts DESC);
CREATE INDEX IF NOT EXISTS idx_eventlog_utterance_id ON eventlog (utterance_id);
CREATE INDEX IF NOT EXISTS idx_eventlog_delivery_state ON eventlog (delivery_state);
CREATE TABLE IF NOT EXISTS diarylog (
    rowid BIGSERIAL PRIMARY KEY,
    ts TEXT NOT NULL,
    sess VARCHAR(1024) DEFAULT '',
    topic TEXT DEFAULT '',
    content TEXT DEFAULT '',
    tags TEXT DEFAULT '',
    people TEXT DEFAULT '',
    localts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW()),
    location TEXT DEFAULT '',
    gamets BIGINT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_diarylog_people ON diarylog (people);
CREATE INDEX IF NOT EXISTS idx_diarylog_gamets ON diarylog (gamets DESC, localts DESC, rowid DESC);
CREATE INDEX IF NOT EXISTS idx_diarylog_localts ON diarylog (localts DESC);
CREATE INDEX IF NOT EXISTS idx_diarylog_people_gamets ON diarylog (LOWER(TRIM(people)), gamets DESC, localts DESC, rowid DESC);

-- ----------------------------------------------------------
-- GENERAL_SETTINGS — All configuration (no conf.php)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS general_settings (
    id VARCHAR(255) PRIMARY KEY,
    value TEXT,
    description TEXT DEFAULT '',
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ----------------------------------------------------------
-- CONF_OPTS — Lightweight runtime key/value cache (Herika-style)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS conf_opts (
    id VARCHAR(255) PRIMARY KEY,
    value TEXT DEFAULT '',
    updated_at TIMESTAMP DEFAULT NOW()
);

INSERT INTO conf_opts (id, value, updated_at)
VALUES ('stobe_profile_model', '1', NOW())
ON CONFLICT (id) DO NOTHING;

-- ----------------------------------------------------------
-- PROMPTS - Default + custom prompt templates
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS prompts (
    prompt_key VARCHAR(128) PRIMARY KEY,
    default_prompt TEXT NOT NULL,
    custom_prompt TEXT DEFAULT NULL,
    description TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);


-- ----------------------------------------------------------
-- core_action â€” DB-backed action catalog for prompt guidance
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS core_action (
    id SERIAL PRIMARY KEY,
    command VARCHAR(64) UNIQUE NOT NULL,
    action_name VARCHAR(64) NOT NULL,
    description TEXT NOT NULL,
    is_activated BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS core_action_custom (
    id SERIAL PRIMARY KEY,
    command VARCHAR(64) UNIQUE NOT NULL,
    action_name VARCHAR(64) NOT NULL,
    description TEXT NOT NULL,
    is_activated BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_core_action_command_lower ON core_action (LOWER(command));
CREATE INDEX IF NOT EXISTS idx_core_action_active ON core_action (is_activated);
CREATE INDEX IF NOT EXISTS idx_core_action_name_lower ON core_action (LOWER(action_name));

CREATE INDEX IF NOT EXISTS idx_core_action_custom_command_lower ON core_action_custom (LOWER(command));
CREATE INDEX IF NOT EXISTS idx_core_action_custom_active ON core_action_custom (is_activated);
CREATE INDEX IF NOT EXISTS idx_core_action_custom_name_lower ON core_action_custom (LOWER(action_name));

CREATE OR REPLACE VIEW combined_core_action AS
SELECT
    c.id,
    c.command,
    c.action_name,
    c.description,
    c.is_activated,
    c.created_at,
    c.updated_at
FROM core_action_custom c
UNION ALL
SELECT
    b.id,
    b.command,
    b.action_name,
    b.description,
    b.is_activated,
    b.created_at,
    b.updated_at
FROM core_action b
LEFT JOIN core_action_custom c ON UPPER(b.command) = UPPER(c.command)
WHERE c.command IS NULL;

-- ----------------------------------------------------------
-- ITEM DESCRIPTIONS - Herika-style description overrides
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS descriptions (
    stringid VARCHAR(128) PRIMARY KEY,
    name TEXT,
    description TEXT
);

CREATE TABLE IF NOT EXISTS descriptions_custom (
    stringid VARCHAR(128) PRIMARY KEY,
    name TEXT,
    description TEXT
);

CREATE TABLE IF NOT EXISTS description_images (
    stringid VARCHAR(128) PRIMARY KEY,
    image_path TEXT NOT NULL DEFAULT '',
    image_hash VARCHAR(64) DEFAULT '',
    format VARCHAR(16) DEFAULT '',
    width INT DEFAULT 0,
    height INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_description_images_stringid_lower ON description_images (LOWER(stringid));

CREATE OR REPLACE VIEW combined_descriptions AS
SELECT
    c.stringid,
    c.name,
    c.description
FROM descriptions_custom c
UNION ALL
SELECT
    d.stringid,
    d.name,
    d.description
FROM descriptions d
LEFT JOIN descriptions_custom c ON LOWER(d.stringid) = LOWER(c.stringid)
WHERE c.stringid IS NULL;

-- ----------------------------------------------------------
-- LOCATION_ZONES — Zone-level world locations discovered in Kenshi
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS location_zones (
    id BIGSERIAL PRIMARY KEY,
    zone_name VARCHAR(255) NOT NULL,
    city_name VARCHAR(255) DEFAULT '',
    x DOUBLE PRECISION DEFAULT NULL,
    y DOUBLE PRECISION DEFAULT NULL,
    z DOUBLE PRECISION DEFAULT NULL,
    first_game_ts BIGINT NOT NULL DEFAULT 0,
    last_game_ts BIGINT NOT NULL DEFAULT 0,
    first_seen_ts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW()),
    last_seen_ts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW()),
    metadata JSONB DEFAULT '{}'::jsonb,
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT location_zones_zone_name_key UNIQUE (zone_name)
);
CREATE INDEX IF NOT EXISTS idx_location_zones_zone_name_lower ON location_zones (LOWER(zone_name));
CREATE INDEX IF NOT EXISTS idx_location_zones_first_game_ts ON location_zones (first_game_ts DESC);
CREATE INDEX IF NOT EXISTS idx_location_zones_last_seen_ts ON location_zones (last_seen_ts DESC);

-- ----------------------------------------------------------
-- PLAYER BASES - known settlements plus current selected-player presence
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS player_bases (
    base_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    power_generated DOUBLE PRECISION NOT NULL DEFAULT 0,
    power_required DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_charge DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_capacity DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_drain DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_charging DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_mode BOOLEAN NOT NULL DEFAULT FALSE,
    has_spare_power BOOLEAN NOT NULL DEFAULT FALSE,
    members_inside INT NOT NULL DEFAULT 0,
    has_gates BOOLEAN NOT NULL DEFAULT FALSE,
    gates_closed BOOLEAN NOT NULL DEFAULT FALSE,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    game_ts BIGINT NOT NULL DEFAULT 0,
    first_game_ts BIGINT NOT NULL DEFAULT 0,
    last_game_ts BIGINT NOT NULL DEFAULT 0,
    last_seen_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_player_bases_last_seen
    ON player_bases (last_seen_at DESC);
CREATE INDEX IF NOT EXISTS idx_player_bases_game_range
    ON player_bases (first_game_ts, last_game_ts);

CREATE TABLE IF NOT EXISTS player_base_presence (
    scope_key TEXT PRIMARY KEY,
    session_id TEXT NOT NULL,
    observer_serial BIGINT NOT NULL DEFAULT 0,
    observer_name TEXT NOT NULL DEFAULT '',
    inside BOOLEAN NOT NULL DEFAULT FALSE,
    base_id TEXT REFERENCES player_bases(base_id) ON DELETE SET NULL,
    game_ts BIGINT NOT NULL DEFAULT 0,
    observed_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_player_base_presence_observed
    ON player_base_presence (inside, observed_at DESC);

CREATE TABLE IF NOT EXISTS player_base_history (
    id BIGSERIAL PRIMARY KEY,
    base_id TEXT NOT NULL REFERENCES player_bases(base_id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    power_generated DOUBLE PRECISION NOT NULL DEFAULT 0,
    power_required DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_charge DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_capacity DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_drain DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_charging DOUBLE PRECISION NOT NULL DEFAULT 0,
    battery_mode BOOLEAN NOT NULL DEFAULT FALSE,
    has_spare_power BOOLEAN NOT NULL DEFAULT FALSE,
    members_inside INT NOT NULL DEFAULT 0,
    has_gates BOOLEAN NOT NULL DEFAULT FALSE,
    gates_closed BOOLEAN NOT NULL DEFAULT FALSE,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    game_ts BIGINT NOT NULL,
    observed_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (base_id, game_ts)
);
CREATE INDEX IF NOT EXISTS idx_player_base_history_rollback
    ON player_base_history (game_ts DESC, base_id);

-- ----------------------------------------------------------
-- WORLD_STATE - Row-level WorldEventStateQuery entries
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS world_state (
    id BIGSERIAL PRIMARY KEY,
    merge_key TEXT NOT NULL DEFAULT '',
    game_ts BIGINT NOT NULL DEFAULT 0,
    source VARCHAR(64) NOT NULL DEFAULT 'world_event_state_query',
    query_name TEXT NOT NULL DEFAULT '',
    query_string_id TEXT NOT NULL DEFAULT '',
    query_numeric_id INT NOT NULL DEFAULT 0,
    player_involvement BOOLEAN NOT NULL DEFAULT FALSE,
    rule_category VARCHAR(64) NOT NULL,
    entity_name TEXT NOT NULL DEFAULT '',
    entity_string_id TEXT NOT NULL DEFAULT '',
    entity_numeric_id INT NOT NULL DEFAULT 0,
    state_value VARCHAR(32) NOT NULL DEFAULT '',
    bool_value BOOLEAN,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_world_state_merge_key ON world_state (merge_key);
CREATE INDEX IF NOT EXISTS idx_world_state_game_ts ON world_state (game_ts DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_world_state_created_at ON world_state (created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_world_state_source ON world_state (source);
CREATE INDEX IF NOT EXISTS idx_world_state_rule_category ON world_state (rule_category);
CREATE INDEX IF NOT EXISTS idx_world_state_query_name_lower ON world_state (LOWER(query_name));
CREATE INDEX IF NOT EXISTS idx_world_state_entity_name_lower ON world_state (LOWER(entity_name));

-- ----------------------------------------------------------
-- WORLD_STATE_DEFINITION - loaded vanilla and mod query definitions
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS world_state_definition (
    query_id TEXT PRIMARY KEY,
    query_name TEXT NOT NULL DEFAULT '',
    source_mod TEXT NOT NULL DEFAULT '',
    player_involvement BOOLEAN NOT NULL DEFAULT FALSE,
    rules JSONB NOT NULL DEFAULT '[]'::jsonb,
    runtime_catalog_id TEXT NOT NULL DEFAULT '',
    is_vanilla BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    first_seen_at TIMESTAMP NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_world_state_definition_active
    ON world_state_definition (active, query_name);
CREATE INDEX IF NOT EXISTS idx_world_state_definition_source
    ON world_state_definition (source_mod, active);

-- ----------------------------------------------------------
-- WORLD_STATE_QUERY_RESULT - current evaluated query values
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS world_state_query_result (
    query_id TEXT PRIMARY KEY,
    query_name TEXT NOT NULL DEFAULT '',
    is_true BOOLEAN NOT NULL,
    game_ts BIGINT NOT NULL DEFAULT 0,
    catalog_sha256 TEXT NOT NULL DEFAULT '',
    first_observed_at TIMESTAMP NOT NULL DEFAULT NOW(),
    last_evaluated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    changed_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_world_state_query_result_value
    ON world_state_query_result (is_true, query_name);
CREATE INDEX IF NOT EXISTS idx_world_state_query_result_evaluated
    ON world_state_query_result (last_evaluated_at DESC);

-- ----------------------------------------------------------
-- WORLD_STATE_ADDENDUM - generated defaults + user overrides
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS world_state_addendum (
    query_id TEXT PRIMARY KEY,
    query_name TEXT NOT NULL DEFAULT '',
    source_mod TEXT NOT NULL DEFAULT '',
    origin TEXT NOT NULL DEFAULT 'vanilla',
    matched_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    when_true TEXT NOT NULL DEFAULT '',
    when_false TEXT NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    catalog_sha256 TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS world_state_addendum_custom (
    query_id TEXT PRIMARY KEY,
    query_name TEXT NOT NULL DEFAULT '',
    source_mod TEXT NOT NULL DEFAULT '',
    origin TEXT NOT NULL DEFAULT 'custom',
    matched_topics JSONB NOT NULL DEFAULT '[]'::jsonb,
    when_true TEXT NOT NULL DEFAULT '',
    when_false TEXT NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_world_state_addendum_enabled
    ON world_state_addendum (enabled, query_id);
CREATE INDEX IF NOT EXISTS idx_world_state_addendum_origin
    ON world_state_addendum (origin, source_mod);
CREATE INDEX IF NOT EXISTS idx_world_state_addendum_custom_enabled
    ON world_state_addendum_custom (enabled, query_id);

CREATE OR REPLACE VIEW combined_world_state_addendum AS
SELECT
    c.query_id,
    c.query_name,
    c.source_mod,
    c.origin,
    c.matched_topics,
    c.when_true,
    c.when_false,
    c.enabled,
    ''::TEXT AS catalog_sha256,
    c.created_at,
    c.updated_at,
    TRUE AS is_custom
FROM world_state_addendum_custom c
UNION ALL
SELECT
    b.query_id,
    b.query_name,
    b.source_mod,
    b.origin,
    b.matched_topics,
    b.when_true,
    b.when_false,
    b.enabled,
    b.catalog_sha256,
    b.created_at,
    b.updated_at,
    FALSE AS is_custom
FROM world_state_addendum b
LEFT JOIN world_state_addendum_custom c ON c.query_id = b.query_id
WHERE c.query_id IS NULL;

-- ----------------------------------------------------------
-- FACTION_RELATIONS - global faction-to-faction current state
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS faction_relation_state (
    id BIGSERIAL PRIMARY KEY,
    merge_key TEXT NOT NULL UNIQUE,
    source_name TEXT NOT NULL DEFAULT '',
    source_string_id TEXT NOT NULL DEFAULT '',
    source_numeric_id INT NOT NULL DEFAULT 0,
    target_name TEXT NOT NULL DEFAULT '',
    target_string_id TEXT NOT NULL DEFAULT '',
    target_numeric_id INT NOT NULL DEFAULT 0,
    relation DOUBLE PRECISION NOT NULL DEFAULT 0,
    alliance BOOLEAN NOT NULL DEFAULT FALSE,
    war BOOLEAN NOT NULL DEFAULT FALSE,
    coexists BOOLEAN NOT NULL DEFAULT FALSE,
    game_ts BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_faction_relation_state_game_ts ON faction_relation_state (game_ts DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_faction_relation_state_source_lower ON faction_relation_state (LOWER(source_name));
CREATE INDEX IF NOT EXISTS idx_faction_relation_state_target_lower ON faction_relation_state (LOWER(target_name));

-- ----------------------------------------------------------
-- rename_global — DB-backed rename pools
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS core_voiceid (
    id SERIAL PRIMARY KEY,
    voiceid VARCHAR(255) UNIQUE NOT NULL,
    sample_file VARCHAR(255) DEFAULT '',
    gender VARCHAR(16) DEFAULT 'any',
    race VARCHAR(128) DEFAULT 'any',
    faction VARCHAR(255) DEFAULT 'any',
    "unique" TEXT DEFAULT '',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS core_voiceid_custom (
    id SERIAL PRIMARY KEY,
    voiceid VARCHAR(255) UNIQUE NOT NULL,
    sample_file VARCHAR(255) DEFAULT '',
    gender VARCHAR(16) DEFAULT 'any',
    race VARCHAR(128) DEFAULT 'any',
    faction VARCHAR(255) DEFAULT 'any',
    "unique" TEXT DEFAULT '',
    notes TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_core_voiceid_gender ON core_voiceid (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_core_voiceid_race ON core_voiceid (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_core_voiceid_faction ON core_voiceid (LOWER(faction));
CREATE INDEX IF NOT EXISTS idx_core_voiceid_unique ON core_voiceid (LOWER("unique"));

CREATE INDEX IF NOT EXISTS idx_core_voiceid_custom_gender ON core_voiceid_custom (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_core_voiceid_custom_race ON core_voiceid_custom (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_core_voiceid_custom_faction ON core_voiceid_custom (LOWER(faction));
CREATE INDEX IF NOT EXISTS idx_core_voiceid_custom_unique ON core_voiceid_custom (LOWER("unique"));

CREATE OR REPLACE VIEW combined_core_voiceid AS
SELECT
    c.id,
    c.voiceid,
    c.sample_file,
    c.gender,
    c.race,
    c.faction,
    c."unique",
    c.notes,
    c.created_at,
    c.updated_at
FROM core_voiceid_custom c
UNION ALL
SELECT
    b.id,
    b.voiceid,
    b.sample_file,
    b.gender,
    b.race,
    b.faction,
    b."unique",
    b.notes,
    b.created_at,
    b.updated_at
FROM core_voiceid b
LEFT JOIN core_voiceid_custom c ON LOWER(b.voiceid) = LOWER(c.voiceid)
WHERE c.voiceid IS NULL;

INSERT INTO core_voiceid (voiceid, sample_file, gender, race, faction, "unique", notes) VALUES
('male1','male1.mp3','male','any','any','','Default male voice'),
('male2','male2.mp3','male','any','any','','Default male voice'),
('male3','male3.mp3','male','any','any','','Default male voice'),
('male4','male4.mp3','male','any','any','','Default male voice'),
('male5','male5.mp3','male','any','any','','Default male voice'),
('male6','male6.mp3','male','any','any','','Default male voice'),
('male7','male7.mp3','male','any','any','','Default male voice'),
('male8','male8.mp3','male','any','any','','Default male voice'),
('male9','male9.mp3','male','any','any','','Default male voice'),
('male10','male10.mp3','male','any','any','','Default male voice'),
('male11','male11.mp3','male','any','any','','Default male voice'),
('male12','male12.mp3','male','any','any','','Default male voice'),
('male13','male13.mp3','male','any','any','','Default male voice'),
('male14','male14.mp3','male','any','any','','Default male voice'),
('male15','male15.mp3','male','any','any','','Default male voice'),
('male16','male16.mp3','male','any','any','','Default male voice'),
('male17','male17.mp3','male','any','any','','Default male voice'),
('male18','male18.mp3','male','any','any','','Default male voice'),
('male19','male19.mp3','male','any','any','','Default male voice'),
('male20','male20.mp3','male','any','any','','Default male voice'),
('female1','female1.mp3','female','any','any','','Default female voice'),
('female2','female2.mp3','female','any','any','','Default female voice'),
('female3','female3.mp3','female','any','any','','Default female voice'),
('female4','female4.mp3','female','any','any','','Default female voice'),
('female5','female5.mp3','female','any','any','','Default female voice'),
('female6','female6.mp3','female','any','any','','Default female voice'),
('female7','female7.mp3','female','any','any','','Default female voice'),
('female8','female8.mp3','female','any','any','','Default female voice'),
('female9','female9.mp3','female','any','any','','Default female voice'),
('female10','female10.mp3','female','any','any','','Default female voice'),
('female11','female11.mp3','female','any','any','','Default female voice'),
('female12','female12.mp3','female','any','any','','Default female voice'),
('female13','female13.mp3','female','any','any','','Default female voice'),
('female14','female14.mp3','female','any','any','','Default female voice'),
('female15','female15.mp3','female','any','any','','Default female voice'),
('female16','female16.mp3','female','any','any','','Default female voice'),
('female17','female17.mp3','female','any','any','','Default female voice'),
('female18','female18.mp3','female','any','any','','Default female voice'),
('female19','female19.mp3','female','any','any','','Default female voice'),
('female20','female20.mp3','female','any','any','','Default female voice')
ON CONFLICT (voiceid) DO NOTHING;

CREATE TABLE IF NOT EXISTS rename_global (
    id SERIAL PRIMARY KEY,
    name VARCHAR(128) UNIQUE NOT NULL,
    gender VARCHAR(16) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    race VARCHAR(64) DEFAULT '',
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS rename_global_custom (
    id SERIAL PRIMARY KEY,
    name VARCHAR(128) UNIQUE NOT NULL,
    gender VARCHAR(16) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    race VARCHAR(64) DEFAULT '',
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE rename_global ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE rename_global_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;

CREATE INDEX IF NOT EXISTS idx_rename_global_name_lower ON rename_global (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_rename_global_gender ON rename_global (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_rename_global_race ON rename_global (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_rename_global_faction ON rename_global (LOWER(faction));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_name_lower ON rename_global_custom (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_gender ON rename_global_custom (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_race ON rename_global_custom (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_faction ON rename_global_custom (LOWER(faction));

CREATE OR REPLACE VIEW combined_rename_global AS
SELECT
    c.id,
    c.name,
    c.gender,
    c.faction,
    c.race,
    c.created_at,
    c.updated_at,
    c.is_enabled
FROM rename_global_custom c
UNION ALL
SELECT
    g.id,
    g.name,
    g.gender,
    g.faction,
    g.race,
    g.created_at,
    g.updated_at,
    g.is_enabled
FROM rename_global g
LEFT JOIN rename_global_custom c ON LOWER(g.name) = LOWER(c.name)
WHERE c.name IS NULL;

-- ----------------------------------------------------------
-- rename_token_global — DB-backed rename eligibility tokens
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS rename_token_global (
    id SERIAL PRIMARY KEY,
    token VARCHAR(128) UNIQUE NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS rename_token_global_custom (
    id SERIAL PRIMARY KEY,
    token VARCHAR(128) UNIQUE NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE rename_token_global ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE rename_token_global_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;

CREATE INDEX IF NOT EXISTS idx_rename_token_global_token_lower ON rename_token_global (LOWER(token));
CREATE INDEX IF NOT EXISTS idx_rename_token_global_custom_token_lower ON rename_token_global_custom (LOWER(token));

CREATE OR REPLACE VIEW combined_rename_token_global AS
SELECT
    c.id,
    c.token,
    c.created_at,
    c.updated_at,
    c.is_enabled
FROM rename_token_global_custom c
UNION ALL
SELECT
    g.id,
    g.token,
    g.created_at,
    g.updated_at,
    g.is_enabled
FROM rename_token_global g
LEFT JOIN rename_token_global_custom c ON LOWER(g.token) = LOWER(c.token)
WHERE c.token IS NULL;

-- ----------------------------------------------------------
-- BIO_RANDOM - Randomized default NPC biography traits
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS bio_random (
    id SERIAL PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    name VARCHAR(255) DEFAULT '',
    race VARCHAR(64) DEFAULT '',
    gender VARCHAR(16) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT bio_random_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals')),
    CONSTRAINT bio_random_type_description_name_key UNIQUE (type, description, name)
);

CREATE TABLE IF NOT EXISTS bio_random_custom (
    id SERIAL PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    name VARCHAR(255) DEFAULT '',
    race VARCHAR(64) DEFAULT '',
    gender VARCHAR(16) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT bio_random_custom_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals')),
    CONSTRAINT bio_random_custom_type_description_name_key UNIQUE (type, description, name)
);

CREATE INDEX IF NOT EXISTS idx_bio_random_type ON bio_random (LOWER(type));
CREATE INDEX IF NOT EXISTS idx_bio_random_name ON bio_random (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_random_race ON bio_random (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_bio_random_gender ON bio_random (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_bio_random_faction ON bio_random (LOWER(faction));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_type ON bio_random_custom (LOWER(type));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_name ON bio_random_custom (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_race ON bio_random_custom (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_gender ON bio_random_custom (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_faction ON bio_random_custom (LOWER(faction));

CREATE OR REPLACE VIEW combined_bio_random AS
SELECT
    c.id,
    c.type,
    c.description,
    c.name,
    c.race,
    c.gender,
    c.faction,
    c.created_at,
    c.updated_at,
    c.is_enabled
FROM bio_random_custom c
UNION ALL
SELECT
    b.id,
    b.type,
    b.description,
    b.name,
    b.race,
    b.gender,
    b.faction,
    b.created_at,
    b.updated_at,
    b.is_enabled
FROM bio_random b
LEFT JOIN bio_random_custom c
  ON LOWER(b.type) = LOWER(c.type)
 AND LOWER(b.description) = LOWER(c.description)
 AND LOWER(COALESCE(b.name, '')) = LOWER(COALESCE(c.name, ''))
WHERE c.id IS NULL;
-- ----------------------------------------------------------
-- BIO_UNIQUE - Named NPC biography traits
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS bio_unique (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT bio_unique_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals')),
    CONSTRAINT bio_unique_name_type_key UNIQUE (name, type)
);

CREATE TABLE IF NOT EXISTS bio_unique_custom (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT bio_unique_custom_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals')),
    CONSTRAINT bio_unique_custom_name_type_key UNIQUE (name, type)
);

ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;

CREATE INDEX IF NOT EXISTS idx_bio_unique_name ON bio_unique (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_unique_type ON bio_unique (LOWER(type));
CREATE INDEX IF NOT EXISTS idx_bio_unique_custom_name ON bio_unique_custom (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_unique_custom_type ON bio_unique_custom (LOWER(type));

CREATE OR REPLACE VIEW combined_bio_unique AS
SELECT
    c.id,
    c.name,
    c.type,
    c.description,
    c.created_at,
    c.updated_at,
    c.is_enabled
FROM bio_unique_custom c
UNION ALL
SELECT
    b.id,
    b.name,
    b.type,
    b.description,
    b.created_at,
    b.updated_at,
    b.is_enabled
FROM bio_unique b
LEFT JOIN bio_unique_custom c
  ON LOWER(b.name) = LOWER(c.name)
 AND LOWER(b.type) = LOWER(c.type)
WHERE c.id IS NULL;

-- ----------------------------------------------------------
-- CORE_NPC — NPC profiles and personalities
-- ----------------------------------------------------------
-- Runtime migration patch `core_npc_master_rename_and_history` renames this
-- table to `core_npc_master` on existing installs, then exposes `core_npc`
-- as a compatibility view.
CREATE TABLE IF NOT EXISTS core_npc (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    original_name VARCHAR(255),
    npc_favorite BOOLEAN DEFAULT FALSE,
    lock_profile BOOLEAN DEFAULT FALSE,
    prompt_head TEXT DEFAULT '',
    personality TEXT DEFAULT '',
    backstory TEXT DEFAULT '',
    emote_moods TEXT DEFAULT '',
    occupation TEXT DEFAULT '',
    appearance TEXT DEFAULT '',
    equipment TEXT DEFAULT '',
    inventory TEXT DEFAULT '',
    skills TEXT DEFAULT '',
    speechstyle TEXT DEFAULT '',
    goals TEXT DEFAULT '',
    relationships TEXT DEFAULT '',
    voiceid VARCHAR(255) DEFAULT '',
    metadata JSONB DEFAULT '{}',
    race VARCHAR(64) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    gender VARCHAR(16) DEFAULT '',
    profile_id INT,
    profile_id_before_player_faction INT,
    extended_data JSONB DEFAULT '{}',
    md5 TEXT DEFAULT '',
    gamets_last_updated BIGINT DEFAULT 0,
    bounty JSONB DEFAULT '{}'::jsonb,
    limbs JSONB DEFAULT '{}',
    blood VARCHAR(32) DEFAULT '0/0',
    hunger VARCHAR(32) DEFAULT '300/300',
    tags TEXT DEFAULT '',
    is_animal BOOLEAN DEFAULT FALSE,
    is_slave BOOLEAN DEFAULT FALSE,
    world_knowledge_tags TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    plugin_extended_data JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_core_npc_name ON core_npc (name);
CREATE INDEX IF NOT EXISTS idx_core_npc_bounty ON core_npc (
    (CASE
        WHEN COALESCE(bounty->>'total', '') ~ '^[0-9]+$' THEN (bounty->>'total')::BIGINT
        ELSE 0
    END) DESC
);
CREATE INDEX IF NOT EXISTS idx_core_npc_profile_id ON core_npc (profile_id);

-- ----------------------------------------------------------
-- CORE_NPC_MASTER_HISTORY - Snapshot trail for NPC profile changes
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS core_npc_master_history (
    history_id BIGSERIAL PRIMARY KEY,
    npc_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255),
    npc_favorite BOOLEAN DEFAULT FALSE,
    lock_profile BOOLEAN DEFAULT FALSE,
    prompt_head TEXT DEFAULT '',
    personality TEXT DEFAULT '',
    backstory TEXT DEFAULT '',
    emote_moods TEXT DEFAULT '',
    occupation TEXT DEFAULT '',
    appearance TEXT DEFAULT '',
    equipment TEXT DEFAULT '',
    inventory TEXT DEFAULT '',
    skills TEXT DEFAULT '',
    speechstyle TEXT DEFAULT '',
    goals TEXT DEFAULT '',
    relationships TEXT DEFAULT '',
    voiceid VARCHAR(255) DEFAULT '',
    metadata JSONB DEFAULT '{}',
    race VARCHAR(64) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    gender VARCHAR(16) DEFAULT '',
    profile_id INT,
    dynamic_profile BOOLEAN DEFAULT FALSE,
    extended_data JSONB DEFAULT '{}',
    md5 TEXT DEFAULT '',
    gamets_last_updated BIGINT DEFAULT 0,
    bounty JSONB DEFAULT '{}'::jsonb,
    limbs JSONB DEFAULT '{}',
    blood VARCHAR(32) DEFAULT '0/0',
    hunger VARCHAR(32) DEFAULT '300/300',
    tags TEXT DEFAULT '',
    is_animal BOOLEAN DEFAULT FALSE,
    is_slave BOOLEAN DEFAULT FALSE,
    world_knowledge_tags TEXT DEFAULT '',
    snapshot_reason VARCHAR(64) DEFAULT 'snapshot',
    snapshot_hash TEXT DEFAULT '',
    source_created_at TIMESTAMP,
    source_updated_at TIMESTAMP,
    created TIMESTAMP DEFAULT NOW(),
    plugin_extended_data JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object')
);

CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_npc_id ON core_npc_master_history (npc_id);
CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_created ON core_npc_master_history (created DESC);
CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_gamets ON core_npc_master_history (gamets_last_updated DESC);

-- ----------------------------------------------------------
-- CORE_PROFILES — Minimal profile presets for NPC defaults
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS core_profiles (
    id SERIAL PRIMARY KEY,
    label VARCHAR(128) UNIQUE NOT NULL,
    is_default_npc BOOLEAN DEFAULT FALSE,
    is_player_faction_profile BOOLEAN DEFAULT FALSE,
    prompt_head TEXT DEFAULT '',
    profile_prompt TEXT DEFAULT '',
    llm_primary_id INT,
    llm_secondary_id INT,
    llm_tertiary_id INT,
    llm_quaternary_id INT,
    response_connector INT,
    diary_connector INT,
    autochat_connector INT,
    middleterm_connector INT,
    backgroundlife_connector INT,
    dynamic_connector INT,
    relationship_connector INT,
    tts_connector_id INT,
    metadata JSONB DEFAULT $${
        "DYNAMIC_PROFILE_ENABLED": false,
        "MIDDLE_TERM_MEMORY_ENABLED": false,
        "AUTO_DIARY_ENABLED": false,
        "DIARY_DAYS": 1,
        "AUTO_DIARY_MIN_EVENTS": 50,
        "AUTO_DIARY_HOUR": 21,
        "DYNAMIC_PROFILE_FIELDS": [
            "personality",
            "occupation",
            "speechstyle",
            "goals"
        ],
        "RECHAT_RESPONSES": 3,
        "RECHAT_PROBABILITY": 66,
        "DIARY_PROMPT": "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
        "DIARY_COOLDOWN": 120,
        "CONTEXT_HISTORY": 75,
        "CONTEXT_HISTORY_DIARY": 100,
        "CONTEXT_HISTORY_DYNAMIC_PROFILE": 50,
        "BORED_EVENT_CHANCE": 50,
        "RELATIONSHIP_UPDATE_CHANCE": 50
    }$$::jsonb,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_core_profiles_single_default_npc
    ON core_profiles (is_default_npc)
    WHERE is_default_npc = TRUE;

CREATE UNIQUE INDEX IF NOT EXISTS idx_core_profiles_single_player_faction
    ON core_profiles (is_player_faction_profile)
    WHERE is_player_faction_profile = TRUE;

-- ----------------------------------------------------------
-- SPEECH — TTS audio cache
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS speech (
    id SERIAL PRIMARY KEY,
    npc_name VARCHAR(255) NOT NULL,
    text_hash VARCHAR(64) NOT NULL,
    text TEXT NOT NULL,
    audio_path VARCHAR(512),
    tts_engine VARCHAR(64),
    voice_model VARCHAR(255),
    duration_ms INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(npc_name, text_hash)
);

-- ----------------------------------------------------------
-- AUDIT_LLM — token usage and model auditing
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_llm (
    id BIGSERIAL PRIMARY KEY,
    npc_name VARCHAR(255),
    model VARCHAR(255),
    prompt_tokens INT DEFAULT 0,
    completion_tokens INT DEFAULT 0,
    localts BIGINT DEFAULT EXTRACT(EPOCH FROM NOW())
);

CREATE INDEX IF NOT EXISTS idx_audit_llm_localts ON audit_llm (localts DESC);
CREATE INDEX IF NOT EXISTS idx_audit_llm_npc_name ON audit_llm (npc_name);

-- ----------------------------------------------------------
-- AUDIT_MEMORY - world knowledge / memory extraction audit
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_memory (
    input TEXT,
    keywords TEXT,
    rank_any NUMERIC(20,10),
    rank_all NUMERIC(20,10),
    memory TEXT,
    "time" TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------
-- AUDIT_REQUEST - request lifecycle logging (Herika-style)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_request (
    id BIGSERIAL PRIMARY KEY,
    localts BIGINT DEFAULT EXTRACT(EPOCH FROM NOW()),
    request_id VARCHAR(128) DEFAULT '',
    event_type VARCHAR(128) DEFAULT '',
    npc_name VARCHAR(255) DEFAULT '',
    connector VARCHAR(128) DEFAULT '',
    model VARCHAR(255) DEFAULT '',
    url TEXT DEFAULT '',
    request TEXT DEFAULT '',
    result TEXT DEFAULT '',
    http_code INT DEFAULT 0,
    duration_ms INT DEFAULT 0,
    is_stream BOOLEAN DEFAULT FALSE,
    prompt_tokens INT DEFAULT 0,
    completion_tokens INT DEFAULT 0,
    total_tokens INT DEFAULT 0,
    status VARCHAR(16) DEFAULT 'ok',
    error TEXT DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_audit_request_localts ON audit_request (localts DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_request_request_id ON audit_request (request_id);
CREATE INDEX IF NOT EXISTS idx_audit_request_event_type ON audit_request (event_type);
CREATE INDEX IF NOT EXISTS idx_audit_request_npc_name ON audit_request (npc_name);
CREATE INDEX IF NOT EXISTS idx_audit_request_status ON audit_request (status);

-- ----------------------------------------------------------
-- AUTONOMY CONTROL PLANE - one controller session per playthrough
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS autonomy_session (
    id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    npc_id INT,
    npc_storage_id TEXT NOT NULL DEFAULT '',
    npc_name TEXT NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    desired_state TEXT NOT NULL DEFAULT 'DISABLED',
    plugin_state TEXT NOT NULL DEFAULT 'DISABLED',
    control_revision BIGINT NOT NULL DEFAULT 0,
    plugin_control_revision BIGINT NOT NULL DEFAULT 0,
    runtime_serial BIGINT NOT NULL DEFAULT 0,
    stop_mode TEXT NOT NULL DEFAULT 'normal',
    policy JSONB NOT NULL DEFAULT '{"preset":"full_autonomy","actions":"all"}'::jsonb,
    long_term_directive TEXT NOT NULL DEFAULT '',
    current_goal JSONB NOT NULL DEFAULT '{}'::jsonb,
    current_action JSONB NOT NULL DEFAULT '{}'::jsonb,
    planner_mode TEXT NOT NULL DEFAULT 'llm',
    planner_connector_id INT,
    planner_status TEXT NOT NULL DEFAULT 'idle',
    planner_failure_count INT NOT NULL DEFAULT 0,
    planner_backoff_seconds INT NOT NULL DEFAULT 0,
    last_prompt_hash TEXT NOT NULL DEFAULT '',
    last_response_hash TEXT NOT NULL DEFAULT '',
    last_request_latency_ms INT NOT NULL DEFAULT 0,
    planner_prompt_tokens BIGINT NOT NULL DEFAULT 0,
    planner_completion_tokens BIGINT NOT NULL DEFAULT 0,
    planner_decision_count BIGINT NOT NULL DEFAULT 0,
    last_allowlist JSONB NOT NULL DEFAULT '[]'::jsonb,
    last_planner_context_hash TEXT NOT NULL DEFAULT '',
    active_decision_id TEXT,
    last_decision_local_ts BIGINT NOT NULL DEFAULT 0,
    next_decision_local_ts BIGINT NOT NULL DEFAULT 0,
    active_elapsed_ms BIGINT NOT NULL DEFAULT 0,
    last_observation TEXT NOT NULL DEFAULT '',
    last_error TEXT NOT NULL DEFAULT '',
    last_plugin_seen_at TIMESTAMP,
    last_plugin_seen_local_ts BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

INSERT INTO autonomy_session (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS autonomy_decision (
    decision_id TEXT PRIMARY KEY,
    session_id SMALLINT NOT NULL DEFAULT 1,
    control_revision BIGINT NOT NULL,
    npc_id INT NOT NULL,
    npc_storage_id TEXT NOT NULL,
    runtime_serial BIGINT NOT NULL DEFAULT 0,
    command TEXT NOT NULL,
    arguments JSONB NOT NULL DEFAULT '{}'::jsonb,
    context_hash TEXT NOT NULL DEFAULT '',
    context_game_ts BIGINT NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'ISSUED',
    issued_at TIMESTAMP NOT NULL DEFAULT NOW(),
    dispatch_deadline_at TIMESTAMP NOT NULL,
    action_deadline_at TIMESTAMP NOT NULL,
    terminal_at TIMESTAMP,
    outcome_reason TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT autonomy_decision_status_check
        CHECK (status IN ('ISSUED', 'DISPATCHED', 'COMPLETED', 'FAILED',
                          'INTERRUPTED', 'TIMED_OUT', 'CANCELLED'))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_autonomy_decision_one_open
    ON autonomy_decision (session_id)
    WHERE status IN ('ISSUED', 'DISPATCHED');
CREATE INDEX IF NOT EXISTS idx_autonomy_decision_revision
    ON autonomy_decision (control_revision DESC, issued_at DESC);

CREATE TABLE IF NOT EXISTS autonomy_pilot_step (
    id BIGSERIAL PRIMARY KEY,
    session_id SMALLINT NOT NULL DEFAULT 1,
    control_revision BIGINT NOT NULL,
    command TEXT NOT NULL,
    arguments JSONB NOT NULL DEFAULT '{}'::jsonb,
    location_zone_id BIGINT,
    status TEXT NOT NULL DEFAULT 'PENDING',
    decision_id TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT autonomy_pilot_step_command_check
        CHECK (command IN ('IDLE', 'TRAVEL_LOCATION', 'MOVE_NEARBY', 'FLEE',
                           'FIRST_AID', 'REST', 'ATTACK', 'TAKE_ITEM',
                           'EQUIP_ITEM', 'KNOCKOUT', 'KILL', 'REMOVE_LIMB',
                           'CUT_HORNS', 'BUY_ITEM', 'SELL_ITEM',
                           'WORK_RESOURCE', 'PROSPECT')),
    CONSTRAINT autonomy_pilot_step_status_check
        CHECK (status IN ('PENDING', 'CLAIMED', 'COMPLETED', 'CANCELLED'))
);

CREATE INDEX IF NOT EXISTS idx_autonomy_pilot_step_pending
    ON autonomy_pilot_step (session_id, control_revision, id)
    WHERE status = 'PENDING';
CREATE INDEX IF NOT EXISTS idx_autonomy_pilot_step_decision
    ON autonomy_pilot_step (decision_id);

CREATE TABLE IF NOT EXISTS autonomy_economy_snapshot (
    id BIGSERIAL PRIMARY KEY,
    game_ts BIGINT NOT NULL DEFAULT 0,
    local_ts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW())::BIGINT,
    x DOUBLE PRECISION NOT NULL DEFAULT 0,
    y DOUBLE PRECISION NOT NULL DEFAULT 0,
    z DOUBLE PRECISION NOT NULL DEFAULT 0,
    location_zone_id BIGINT,
    location_name TEXT NOT NULL DEFAULT '',
    trader_runtime_serial BIGINT NOT NULL,
    trader_name TEXT NOT NULL,
    trader_cats INT NOT NULL DEFAULT 0,
    inventory_hash TEXT NOT NULL,
    inventory JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_autonomy_economy_snapshot_unique
    ON autonomy_economy_snapshot (trader_runtime_serial, game_ts, inventory_hash);
CREATE INDEX IF NOT EXISTS idx_autonomy_economy_snapshot_trader
    ON autonomy_economy_snapshot (trader_runtime_serial, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_autonomy_economy_snapshot_location
    ON autonomy_economy_snapshot (location_zone_id, created_at DESC);

CREATE TABLE IF NOT EXISTS autonomy_event (
    id BIGSERIAL PRIMARY KEY,
    session_id SMALLINT NOT NULL DEFAULT 1,
    control_revision BIGINT NOT NULL DEFAULT 0,
    decision_id TEXT,
    event_key TEXT,
    local_ts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW())::BIGINT,
    game_ts BIGINT NOT NULL DEFAULT 0,
    event_type TEXT NOT NULL,
    state TEXT NOT NULL DEFAULT '',
    goal JSONB NOT NULL DEFAULT '{}'::jsonb,
    command TEXT NOT NULL DEFAULT '',
    arguments JSONB NOT NULL DEFAULT '{}'::jsonb,
    outcome TEXT NOT NULL DEFAULT '',
    reason TEXT NOT NULL DEFAULT '',
    context_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb,
    prompt_hash TEXT NOT NULL DEFAULT '',
    response_hash TEXT NOT NULL DEFAULT '',
    request_latency_ms INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_autonomy_event_key
    ON autonomy_event (event_key) WHERE event_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_autonomy_event_session_created
    ON autonomy_event (session_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_autonomy_event_revision
    ON autonomy_event (control_revision DESC, id DESC);

-- ----------------------------------------------------------
-- LOG â€” Herika-style persisted prompt/response request log
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS log (
    localts BIGINT NOT NULL,
    prompt TEXT,
    response TEXT,
    url TEXT,
    rowid BIGSERIAL PRIMARY KEY
);

CREATE INDEX IF NOT EXISTS idx_log_localts ON log (localts DESC);

-- ----------------------------------------------------------
-- WORLD KNOWLEDGE — Kenshi knowledge/lore base
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS world_knowledge (
    id SERIAL PRIMARY KEY,
    topic VARCHAR(255) NOT NULL,
    topic_desc TEXT NOT NULL,
    topic_desc_basic TEXT DEFAULT '',
    knowledge_class TEXT DEFAULT '',
    knowledge_class_basic TEXT DEFAULT '',
    aliases TEXT DEFAULT '',
    tags TEXT DEFAULT '',
    native_vector TSVECTOR
);
CREATE INDEX IF NOT EXISTS idx_world_knowledge_topic_lower ON world_knowledge (LOWER(topic));
CREATE INDEX IF NOT EXISTS idx_world_knowledge_native_vector_gin ON world_knowledge USING GIN (native_vector);

-- ----------------------------------------------------------
-- MEMORY — Vector-backed NPC memories
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS memory (
    id SERIAL PRIMARY KEY,
    people TEXT NOT NULL DEFAULT '[]',
    content TEXT NOT NULL,
    embedding vector(384),
    event_type VARCHAR(64) DEFAULT '',
    gamets BIGINT DEFAULT 0,
    localts BIGINT DEFAULT EXTRACT(EPOCH FROM NOW()),
    location TEXT DEFAULT '',
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_memory_people ON memory (people);
CREATE INDEX IF NOT EXISTS idx_memory_people_gamets ON memory (LOWER(people), gamets DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_memory_localts ON memory (localts DESC, id DESC);

CREATE TABLE IF NOT EXISTS memory_summary (
    id SERIAL PRIMARY KEY,
    people TEXT NOT NULL DEFAULT '[]',
    scope TEXT,
    summary TEXT NOT NULL,
    embedding vector(384),
    period_start TIMESTAMP,
    period_end TIMESTAMP,
    n INT DEFAULT 0,
    packed_message TEXT DEFAULT '',
    source_from_memory_id INT DEFAULT 0,
    source_to_memory_id INT DEFAULT 0,
    localts BIGINT DEFAULT EXTRACT(EPOCH FROM NOW()),
    gamets_start BIGINT DEFAULT 0,
    gamets_end BIGINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_memory_summary_people_created ON memory_summary (LOWER(people), created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_memory_summary_people_gamets ON memory_summary (LOWER(people), gamets_end DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_memory_summary_scope_gamets ON memory_summary (LOWER(COALESCE(scope, '')), gamets_end DESC, id DESC);

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'memory'
          AND column_name = 'npc_name'
    ) THEN
        ALTER TABLE memory ADD COLUMN IF NOT EXISTS people TEXT;
        UPDATE memory
        SET people = CASE
            WHEN COALESCE(BTRIM(people), '') <> '' THEN people
            WHEN COALESCE(BTRIM(npc_name), '') <> '' THEN to_json(ARRAY[BTRIM(npc_name)])::text
            ELSE '[]'
        END;
        ALTER TABLE memory ALTER COLUMN people SET DEFAULT '[]';
        UPDATE memory SET people = '[]' WHERE COALESCE(BTRIM(people), '') = '';
        ALTER TABLE memory ALTER COLUMN people SET NOT NULL;
        ALTER TABLE memory DROP COLUMN IF EXISTS npc_name;
    END IF;

    ALTER TABLE memory DROP COLUMN IF EXISTS importance;
    ALTER TABLE memory DROP COLUMN IF EXISTS memory_type;
    ALTER TABLE memory DROP COLUMN IF EXISTS speaker;
    ALTER TABLE memory DROP COLUMN IF EXISTS listener;

    DROP INDEX IF EXISTS idx_memory_npc;
    DROP INDEX IF EXISTS idx_memory_npc_gamets;
    CREATE INDEX IF NOT EXISTS idx_memory_people ON memory (people);
    CREATE INDEX IF NOT EXISTS idx_memory_people_gamets ON memory (LOWER(people), gamets DESC, id DESC);
    CREATE INDEX IF NOT EXISTS idx_memory_localts ON memory (localts DESC, id DESC);

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'memory_summary'
          AND column_name = 'npc_name'
    ) THEN
        ALTER TABLE memory_summary ADD COLUMN IF NOT EXISTS people TEXT;
        UPDATE memory_summary
        SET people = CASE
            WHEN COALESCE(BTRIM(people), '') <> '' THEN people
            WHEN COALESCE(BTRIM(npc_name), '') <> '' THEN to_json(ARRAY[BTRIM(npc_name)])::text
            ELSE '[]'
        END;
        ALTER TABLE memory_summary ALTER COLUMN people SET DEFAULT '[]';
        UPDATE memory_summary SET people = '[]' WHERE COALESCE(BTRIM(people), '') = '';
        ALTER TABLE memory_summary ALTER COLUMN people SET NOT NULL;
        ALTER TABLE memory_summary DROP COLUMN IF EXISTS npc_name;
    END IF;

    ALTER TABLE memory_summary ADD COLUMN IF NOT EXISTS scope TEXT;
    UPDATE memory_summary
    SET scope = 'global'
    WHERE scope IS NULL OR BTRIM(scope) = '';

    DROP INDEX IF EXISTS idx_memory_summary_npc_created;
    DROP INDEX IF EXISTS idx_memory_summary_npc_gamets;
    CREATE INDEX IF NOT EXISTS idx_memory_summary_people_created ON memory_summary (LOWER(people), created_at DESC, id DESC);
    CREATE INDEX IF NOT EXISTS idx_memory_summary_people_gamets ON memory_summary (LOWER(people), gamets_end DESC, id DESC);
    CREATE INDEX IF NOT EXISTS idx_memory_summary_scope_gamets ON memory_summary (LOWER(COALESCE(scope, '')), gamets_end DESC, id DESC);
END $$;

-- ----------------------------------------------------------
-- LLM & TTS CONNECTORS
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS core_api_badge (
    id SERIAL PRIMARY KEY,
    label TEXT NOT NULL UNIQUE,
    api_key TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_core_api_badge_label_lower ON core_api_badge (LOWER(label));

CREATE TABLE IF NOT EXISTS core_stt_connector (
    id SERIAL PRIMARY KEY,
    driver TEXT NOT NULL DEFAULT 'parakeet',
    label TEXT NOT NULL DEFAULT 'Global STT Connector',
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    api_badge_id INT REFERENCES core_api_badge(id) ON DELETE SET NULL,
    url TEXT
);

CREATE TABLE IF NOT EXISTS core_llm_connector (
    id SERIAL PRIMARY KEY,
    name VARCHAR(128) UNIQUE NOT NULL,
    connector_type VARCHAR(64) NOT NULL,
    api_badge_id INT,
    api_key TEXT DEFAULT '',
    base_url TEXT DEFAULT '',
    model VARCHAR(255) DEFAULT '',
    max_tokens INT DEFAULT 2048,
    temperature FLOAT DEFAULT 0.8,
    is_default BOOLEAN DEFAULT FALSE,
    config JSONB DEFAULT '{}'
);

CREATE TABLE IF NOT EXISTS core_tts_connector (
    id SERIAL PRIMARY KEY,
    name VARCHAR(128) UNIQUE NOT NULL,
    connector_type VARCHAR(64) NOT NULL,
    base_url TEXT DEFAULT '',
    is_default BOOLEAN DEFAULT FALSE,
    config JSONB DEFAULT '{}'
);

-- Empty-by-default TTS pronunciation dictionary managed from TTS Studio.
CREATE TABLE IF NOT EXISTS core_tts_pronunciation (
    id BIGSERIAL PRIMARY KEY,
    source_text VARCHAR(120) NOT NULL,
    spoken_text VARCHAR(240) NOT NULL,
    npc_names VARCHAR(512) NOT NULL DEFAULT '',
    races VARCHAR(512) NOT NULL DEFAULT '',
    oghma_tags VARCHAR(512) NOT NULL DEFAULT '',
    is_builtin BOOLEAN NOT NULL DEFAULT FALSE,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT core_tts_pronunciation_source_not_blank CHECK (BTRIM(source_text) <> ''),
    CONSTRAINT core_tts_pronunciation_spoken_not_blank CHECK (BTRIM(spoken_text) <> '')
);
CREATE UNIQUE INDEX IF NOT EXISTS core_tts_pronunciation_unique_entry
    ON core_tts_pronunciation (
        LOWER(source_text),
        MD5(LOWER(npc_names) || E'\x1f' || LOWER(races) || E'\x1f' || LOWER(oghma_tags)),
        is_builtin
    );

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'core_llm_connector' AND column_name = 'api_badge_id'
    ) AND NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_llm_connector_api_badge_fk'
    ) THEN
        ALTER TABLE core_llm_connector
        ADD CONSTRAINT core_llm_connector_api_badge_fk
        FOREIGN KEY (api_badge_id) REFERENCES core_api_badge(id) ON DELETE SET NULL;
    END IF;
END $$;

-- ----------------------------------------------------------
-- CORE_NPC MIGRATIONS — Keep old databases aligned
-- ----------------------------------------------------------
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'core_npc' AND column_name = 'speech_quirks'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'core_npc' AND column_name = 'speechstyle'
    ) THEN
        EXECUTE 'ALTER TABLE core_npc RENAME COLUMN speech_quirks TO speechstyle';
    END IF;
END $$;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'core_npc' AND column_name = 'voice_model'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = 'core_npc' AND column_name = 'voiceid'
    ) THEN
        EXECUTE 'ALTER TABLE core_npc RENAME COLUMN voice_model TO voiceid';
    END IF;
END $$;

ALTER TABLE core_npc DROP COLUMN IF EXISTS origin_faction;
ALTER TABLE core_npc DROP COLUMN IF EXISTS relation;
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS npc_favorite BOOLEAN DEFAULT FALSE;
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS lock_profile BOOLEAN DEFAULT FALSE;
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS prompt_head TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS emote_moods TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS occupation TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS appearance TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS equipment TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS inventory TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS skills TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS speechstyle TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS goals TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS voiceid VARCHAR(255) DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS metadata JSONB DEFAULT '{}';
ALTER TABLE core_npc DROP COLUMN IF EXISTS stringid;
ALTER TABLE core_npc DROP COLUMN IF EXISTS refid;
ALTER TABLE core_npc DROP COLUMN IF EXISTS faction_id;
ALTER TABLE core_npc DROP CONSTRAINT IF EXISTS core_npc_llm_connector_fk;
ALTER TABLE core_npc DROP CONSTRAINT IF EXISTS core_npc_tts_connector_fk;
ALTER TABLE core_npc DROP COLUMN IF EXISTS llm_connector_id;
ALTER TABLE core_npc DROP COLUMN IF EXISTS tts_connector_id;
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS profile_id INT;
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS extended_data JSONB DEFAULT '{}';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS md5 TEXT DEFAULT '';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS gamets_last_updated BIGINT DEFAULT 0;
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS bounty JSONB DEFAULT '{}'::jsonb;
ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS bounty JSONB DEFAULT '{}'::jsonb;
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS limbs JSONB DEFAULT '{}';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS blood VARCHAR(32) DEFAULT '0/0';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS hunger VARCHAR(32) DEFAULT '300/300';
ALTER TABLE core_npc ADD COLUMN IF NOT EXISTS tags TEXT DEFAULT '';
ALTER TABLE core_npc DROP COLUMN IF EXISTS core;
ALTER TABLE core_npc_master_history DROP COLUMN IF EXISTS core;
ALTER TABLE core_npc DROP COLUMN IF EXISTS is_generic;
ALTER TABLE core_npc DROP COLUMN IF EXISTS is_canon;
ALTER TABLE core_npc DROP COLUMN IF EXISTS base;
DROP TABLE IF EXISTS core_player;
ALTER TABLE core_llm_connector ADD COLUMN IF NOT EXISTS api_badge_id INT;
UPDATE core_llm_connector
SET connector_type = CASE LOWER(TRIM(connector_type))
    WHEN 'openrouter' THEN 'openrouterjson'
    WHEN 'openai' THEN 'openaijson'
    WHEN 'custom' THEN 'openaijson'
    WHEN 'google' THEN 'google_openaijson'
    WHEN 'groq' THEN 'groqjson'
    WHEN 'koboldcpp' THEN 'koboldcppjson'
    WHEN 'player2' THEN 'player2json'
    ELSE connector_type
END;
ALTER TABLE audit_llm ADD COLUMN IF NOT EXISTS npc_name VARCHAR(255);
ALTER TABLE audit_llm ADD COLUMN IF NOT EXISTS model VARCHAR(255);
ALTER TABLE audit_llm ADD COLUMN IF NOT EXISTS prompt_tokens INT DEFAULT 0;
ALTER TABLE audit_llm ADD COLUMN IF NOT EXISTS completion_tokens INT DEFAULT 0;
ALTER TABLE audit_llm ADD COLUMN IF NOT EXISTS localts BIGINT DEFAULT EXTRACT(EPOCH FROM NOW());

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'rename_global'
          AND column_name = 'gender_hint'
    ) AND NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'rename_global'
          AND column_name = 'gender'
    ) THEN
        EXECUTE 'ALTER TABLE rename_global RENAME COLUMN gender_hint TO gender';
    END IF;
END $$;

ALTER TABLE rename_global ADD COLUMN IF NOT EXISTS gender VARCHAR(16) DEFAULT '';
ALTER TABLE rename_global ADD COLUMN IF NOT EXISTS faction VARCHAR(128) DEFAULT '';
ALTER TABLE rename_global ADD COLUMN IF NOT EXISTS race VARCHAR(64) DEFAULT '';
DROP VIEW IF EXISTS combined_rename_global;
ALTER TABLE rename_global DROP COLUMN IF EXISTS source_tag;
ALTER TABLE rename_global DROP COLUMN IF EXISTS weight;
ALTER TABLE rename_global DROP COLUMN IF EXISTS is_active;
ALTER TABLE rename_global DROP COLUMN IF EXISTS gender_hint;
UPDATE rename_global SET gender = COALESCE(gender, '');
UPDATE rename_global SET faction = COALESCE(faction, '');
UPDATE rename_global SET race = COALESCE(race, '');

CREATE TABLE IF NOT EXISTS rename_global_custom (
    id SERIAL PRIMARY KEY,
    name VARCHAR(128) UNIQUE NOT NULL,
    gender VARCHAR(16) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    race VARCHAR(64) DEFAULT '',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
ALTER TABLE rename_global_custom ADD COLUMN IF NOT EXISTS id SERIAL;
ALTER TABLE rename_global_custom ADD COLUMN IF NOT EXISTS name VARCHAR(128);
ALTER TABLE rename_global_custom ALTER COLUMN name TYPE VARCHAR(128);
UPDATE rename_global_custom
SET id = nextval(pg_get_serial_sequence('rename_global_custom', 'id'))
WHERE id IS NULL;
ALTER TABLE rename_global_custom ALTER COLUMN name SET NOT NULL;
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_attribute a
          ON a.attrelid = c.conrelid
         AND a.attnum = ANY(c.conkey)
        WHERE c.conrelid = 'rename_global_custom'::regclass
          AND c.contype = 'p'
          AND a.attname = 'name'
    ) THEN
        ALTER TABLE rename_global_custom DROP CONSTRAINT rename_global_custom_pkey;
    END IF;
END $$;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_attribute a
          ON a.attrelid = c.conrelid
         AND a.attnum = ANY(c.conkey)
        WHERE c.conrelid = 'rename_global_custom'::regclass
          AND c.contype = 'p'
          AND a.attname = 'id'
    ) THEN
        ALTER TABLE rename_global_custom
        ADD CONSTRAINT rename_global_custom_id_pkey PRIMARY KEY (id);
    END IF;
END $$;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'rename_global_custom_name_key'
    ) THEN
        ALTER TABLE rename_global_custom
        ADD CONSTRAINT rename_global_custom_name_key UNIQUE (name);
    END IF;
END $$;
ALTER TABLE rename_global_custom DROP COLUMN IF EXISTS source_tag;

DROP INDEX IF EXISTS idx_rename_global_gender_active;
CREATE INDEX IF NOT EXISTS idx_rename_global_name_lower ON rename_global (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_rename_global_gender ON rename_global (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_rename_global_race ON rename_global (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_rename_global_faction ON rename_global (LOWER(faction));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_name_lower ON rename_global_custom (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_gender ON rename_global_custom (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_race ON rename_global_custom (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_rename_global_custom_faction ON rename_global_custom (LOWER(faction));

CREATE OR REPLACE VIEW combined_rename_global AS
SELECT
    c.id,
    c.name,
    c.gender,
    c.faction,
    c.race,
    c.created_at,
    c.updated_at
FROM rename_global_custom c
UNION ALL
SELECT
    g.id,
    g.name,
    g.gender,
    g.faction,
    g.race,
    g.created_at,
    g.updated_at
FROM rename_global g
LEFT JOIN rename_global_custom c ON LOWER(g.name) = LOWER(c.name)
WHERE c.name IS NULL;

CREATE TABLE IF NOT EXISTS bio_random (
    id SERIAL PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    name VARCHAR(255) DEFAULT '',
    race VARCHAR(64) DEFAULT '',
    gender VARCHAR(16) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS id SERIAL;
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS type VARCHAR(32);
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS description TEXT;
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS name VARCHAR(255) DEFAULT '';
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS race VARCHAR(64) DEFAULT '';
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS gender VARCHAR(16) DEFAULT '';
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS faction VARCHAR(128) DEFAULT '';
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_random ALTER COLUMN type SET NOT NULL;
ALTER TABLE bio_random ALTER COLUMN description SET NOT NULL;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_random_type_check'
    ) THEN
        ALTER TABLE bio_random
        ADD CONSTRAINT bio_random_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals'));
    END IF;
END $$;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_random_type_description_name_key'
    ) THEN
        ALTER TABLE bio_random
        ADD CONSTRAINT bio_random_type_description_name_key UNIQUE (type, description, name);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS bio_random_custom (
    id SERIAL PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    name VARCHAR(255) DEFAULT '',
    race VARCHAR(64) DEFAULT '',
    gender VARCHAR(16) DEFAULT '',
    faction VARCHAR(128) DEFAULT '',
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS id SERIAL;
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS type VARCHAR(32);
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS description TEXT;
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS name VARCHAR(255) DEFAULT '';
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS race VARCHAR(64) DEFAULT '';
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS gender VARCHAR(16) DEFAULT '';
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS faction VARCHAR(128) DEFAULT '';
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_random_custom ALTER COLUMN type SET NOT NULL;
ALTER TABLE bio_random_custom ALTER COLUMN description SET NOT NULL;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_random_custom_type_check'
    ) THEN
        ALTER TABLE bio_random_custom
        ADD CONSTRAINT bio_random_custom_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals'));
    END IF;
END $$;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_random_custom_type_description_name_key'
    ) THEN
        ALTER TABLE bio_random_custom
        ADD CONSTRAINT bio_random_custom_type_description_name_key UNIQUE (type, description, name);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_bio_random_type ON bio_random (LOWER(type));
CREATE INDEX IF NOT EXISTS idx_bio_random_name ON bio_random (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_random_race ON bio_random (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_bio_random_gender ON bio_random (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_bio_random_faction ON bio_random (LOWER(faction));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_type ON bio_random_custom (LOWER(type));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_name ON bio_random_custom (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_race ON bio_random_custom (LOWER(race));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_gender ON bio_random_custom (LOWER(gender));
CREATE INDEX IF NOT EXISTS idx_bio_random_custom_faction ON bio_random_custom (LOWER(faction));

CREATE OR REPLACE VIEW combined_bio_random AS
SELECT
    c.id,
    c.type,
    c.description,
    c.name,
    c.race,
    c.gender,
    c.faction,
    c.created_at,
    c.updated_at,
    c.is_enabled
FROM bio_random_custom c
UNION ALL
SELECT
    b.id,
    b.type,
    b.description,
    b.name,
    b.race,
    b.gender,
    b.faction,
    b.created_at,
    b.updated_at,
    b.is_enabled
FROM bio_random b
LEFT JOIN bio_random_custom c
  ON LOWER(b.type) = LOWER(c.type)
 AND LOWER(b.description) = LOWER(c.description)
 AND LOWER(COALESCE(b.name, '')) = LOWER(COALESCE(c.name, ''))
WHERE c.id IS NULL;


CREATE TABLE IF NOT EXISTS bio_unique (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS id SERIAL;
ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS name VARCHAR(255);
ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS type VARCHAR(32);
ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS description TEXT;
ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_unique ALTER COLUMN name SET NOT NULL;
ALTER TABLE bio_unique ALTER COLUMN type SET NOT NULL;
ALTER TABLE bio_unique ALTER COLUMN description SET NOT NULL;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_unique_type_check'
    ) THEN
        ALTER TABLE bio_unique
        ADD CONSTRAINT bio_unique_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals'));
    END IF;
END $$;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_unique_name_type_key'
    ) THEN
        ALTER TABLE bio_unique
        ADD CONSTRAINT bio_unique_name_type_key UNIQUE (name, type);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS bio_unique_custom (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS id SERIAL;
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS name VARCHAR(255);
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS type VARCHAR(32);
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS description TEXT;
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();
ALTER TABLE bio_unique_custom ALTER COLUMN name SET NOT NULL;
ALTER TABLE bio_unique_custom ALTER COLUMN type SET NOT NULL;
ALTER TABLE bio_unique_custom ALTER COLUMN description SET NOT NULL;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_unique_custom_type_check'
    ) THEN
        ALTER TABLE bio_unique_custom
        ADD CONSTRAINT bio_unique_custom_type_check
        CHECK (LOWER(type) IN ('personality', 'backstory', 'speechstyle', 'occupation', 'appearance', 'goals'));
    END IF;
END $$;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'bio_unique_custom_name_type_key'
    ) THEN
        ALTER TABLE bio_unique_custom
        ADD CONSTRAINT bio_unique_custom_name_type_key UNIQUE (name, type);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_bio_unique_name ON bio_unique (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_unique_type ON bio_unique (LOWER(type));
CREATE INDEX IF NOT EXISTS idx_bio_unique_custom_name ON bio_unique_custom (LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bio_unique_custom_type ON bio_unique_custom (LOWER(type));

CREATE OR REPLACE VIEW combined_bio_unique AS
SELECT
    c.id,
    c.name,
    c.type,
    c.description,
    c.created_at,
    c.updated_at,
    c.is_enabled
FROM bio_unique_custom c
UNION ALL
SELECT
    b.id,
    b.name,
    b.type,
    b.description,
    b.created_at,
    b.updated_at,
    b.is_enabled
FROM bio_unique b
LEFT JOIN bio_unique_custom c
  ON LOWER(b.name) = LOWER(c.name)
 AND LOWER(b.type) = LOWER(c.type)
WHERE c.id IS NULL;

DROP VIEW IF EXISTS combined_names_generic;
DROP TABLE IF EXISTS names_generic_custom;
DROP TABLE IF EXISTS names_generic;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_llm_connector_api_badge_fk'
    ) THEN
        ALTER TABLE core_llm_connector
        ADD CONSTRAINT core_llm_connector_api_badge_fk
        FOREIGN KEY (api_badge_id) REFERENCES core_api_badge(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_npc_profile_fk'
    ) THEN
        ALTER TABLE core_npc
        ADD CONSTRAINT core_npc_profile_fk
        FOREIGN KEY (profile_id) REFERENCES core_profiles(id) ON DELETE SET NULL;
    END IF;
END $$;

ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS response_connector INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS llm_primary_id INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS llm_secondary_id INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS llm_tertiary_id INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS llm_quaternary_id INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS diary_connector INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS autochat_connector INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS middleterm_connector INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS backgroundlife_connector INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS dynamic_connector INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS relationship_connector INT;
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS prompt_head TEXT DEFAULT '';
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS profile_prompt TEXT DEFAULT '';
ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS metadata JSONB DEFAULT $${
    "DYNAMIC_PROFILE_ENABLED": false,
    "MIDDLE_TERM_MEMORY_ENABLED": false,
    "AUTO_DIARY_ENABLED": false,
        "DIARY_DAYS": 1,
    "AUTO_DIARY_MIN_EVENTS": 50,
    "AUTO_DIARY_HOUR": 21,
    "DYNAMIC_PROFILE_FIELDS": [
        "personality",
        "occupation",
        "speechstyle",
        "goals"
    ],
    "RECHAT_RESPONSES": 3,
    "RECHAT_PROBABILITY": 66,
    "DIARY_PROMPT": "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
    "DIARY_COOLDOWN": 120,
    "CONTEXT_HISTORY": 75,
    "CONTEXT_HISTORY_DIARY": 100,
    "CONTEXT_HISTORY_DYNAMIC_PROFILE": 50,
    "BORED_EVENT_CHANCE": 50,
    "RELATIONSHIP_UPDATE_CHANCE": 50
}$$::jsonb;

UPDATE core_profiles
SET llm_primary_id = COALESCE(llm_primary_id, response_connector),
    response_connector = COALESCE(response_connector, llm_primary_id);
ALTER TABLE core_profiles ALTER COLUMN metadata SET DEFAULT $${
    "DYNAMIC_PROFILE_ENABLED": false,
    "MIDDLE_TERM_MEMORY_ENABLED": false,
    "AUTO_DIARY_ENABLED": false,
        "DIARY_DAYS": 1,
    "AUTO_DIARY_MIN_EVENTS": 50,
    "AUTO_DIARY_HOUR": 21,
    "DYNAMIC_PROFILE_FIELDS": [
        "personality",
        "occupation",
        "speechstyle",
        "goals"
    ],
    "RECHAT_RESPONSES": 3,
    "RECHAT_PROBABILITY": 66,
    "DIARY_PROMPT": "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
    "DIARY_COOLDOWN": 120,
    "CONTEXT_HISTORY": 75,
    "CONTEXT_HISTORY_DIARY": 100,
    "CONTEXT_HISTORY_DYNAMIC_PROFILE": 50,
    "BORED_EVENT_CHANCE": 50,
    "RELATIONSHIP_UPDATE_CHANCE": 50
}$$::jsonb;
UPDATE core_profiles
SET metadata = CASE
    WHEN metadata IS NULL OR metadata = '[]'::jsonb OR jsonb_typeof(metadata) <> 'object'
        THEN $${
            "DYNAMIC_PROFILE_ENABLED": false,
            "MIDDLE_TERM_MEMORY_ENABLED": false,
            "AUTO_DIARY_ENABLED": false,
        "DIARY_DAYS": 1,
            "AUTO_DIARY_MIN_EVENTS": 50,
            "AUTO_DIARY_HOUR": 21,
            "DYNAMIC_PROFILE_FIELDS": [
                "personality",
                "occupation",
                "speechstyle",
                "goals"
            ],
            "RECHAT_RESPONSES": 3,
            "RECHAT_PROBABILITY": 66,
            "DIARY_PROMPT": "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
            "DIARY_COOLDOWN": 120,
            "CONTEXT_HISTORY": 75,
            "CONTEXT_HISTORY_DIARY": 100,
            "CONTEXT_HISTORY_DYNAMIC_PROFILE": 50,
            "BORED_EVENT_CHANCE": 50,
            "RELATIONSHIP_UPDATE_CHANCE": 50
        }$$::jsonb
    ELSE $${
        "DYNAMIC_PROFILE_ENABLED": false,
        "MIDDLE_TERM_MEMORY_ENABLED": false,
        "AUTO_DIARY_ENABLED": false,
        "DIARY_DAYS": 1,
        "AUTO_DIARY_MIN_EVENTS": 50,
        "AUTO_DIARY_HOUR": 21,
        "DYNAMIC_PROFILE_FIELDS": [
            "personality",
            "occupation",
            "speechstyle",
            "goals"
        ],
        "RECHAT_RESPONSES": 3,
        "RECHAT_PROBABILITY": 66,
        "DIARY_PROMPT": "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
        "DIARY_COOLDOWN": 120,
        "CONTEXT_HISTORY": 75,
        "CONTEXT_HISTORY_DIARY": 100,
        "CONTEXT_HISTORY_DYNAMIC_PROFILE": 50,
        "BORED_EVENT_CHANCE": 50,
        "RELATIONSHIP_UPDATE_CHANCE": 50
    }$$::jsonb || metadata
END;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_tts_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_tts_connector_fk
        FOREIGN KEY (tts_connector_id) REFERENCES core_tts_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_llm_primary_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_llm_primary_fk
        FOREIGN KEY (llm_primary_id) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_llm_secondary_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_llm_secondary_fk
        FOREIGN KEY (llm_secondary_id) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_llm_tertiary_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_llm_tertiary_fk
        FOREIGN KEY (llm_tertiary_id) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_llm_quaternary_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_llm_quaternary_fk
        FOREIGN KEY (llm_quaternary_id) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_response_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_response_connector_fk
        FOREIGN KEY (response_connector) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_diary_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_diary_connector_fk
        FOREIGN KEY (diary_connector) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_autochat_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_autochat_connector_fk
        FOREIGN KEY (autochat_connector) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE core_profiles DROP CONSTRAINT IF EXISTS core_profiles_summary_connector_fk;
    ALTER TABLE core_profiles DROP COLUMN IF EXISTS summary_connector;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_middleterm_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_middleterm_connector_fk
        FOREIGN KEY (middleterm_connector) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_backgroundlife_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_backgroundlife_connector_fk
        FOREIGN KEY (backgroundlife_connector) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_dynamic_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_dynamic_connector_fk
        FOREIGN KEY (dynamic_connector) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'core_profiles_relationship_connector_fk'
    ) THEN
        ALTER TABLE core_profiles
        ADD CONSTRAINT core_profiles_relationship_connector_fk
        FOREIGN KEY (relationship_connector) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
    END IF;
END $$;

-- ----------------------------------------------------------
-- PLAYTHROUGH MANAGER (stobe_meta schema snapshots)
-- ----------------------------------------------------------
CREATE SCHEMA IF NOT EXISTS stobe_meta;

CREATE TABLE IF NOT EXISTS stobe_meta.playthrough_profiles (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    size_bytes BIGINT NOT NULL DEFAULT 0,
    storage_format TEXT NOT NULL DEFAULT 'schema_clone',
    notes TEXT DEFAULT '',
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    player_name TEXT,
    player_faction_members TEXT DEFAULT '[]',
    game TEXT,
    eventlog_count BIGINT DEFAULT 0,
    oghma_count BIGINT DEFAULT 0,
    last_gamets BIGINT DEFAULT 0,
    schema_name TEXT,
    storage_type TEXT DEFAULT 'schema',
    rollback_delta_days INT DEFAULT 0,
    rollback_from_gamets BIGINT DEFAULT 0,
    rollback_to_gamets BIGINT DEFAULT 0
);

CREATE TABLE IF NOT EXISTS stobe_meta.playthrough_blobs (
    profile_id INT PRIMARY KEY REFERENCES stobe_meta.playthrough_profiles(id) ON DELETE CASCADE,
    dump_data TEXT,
    dump_lob OID
);

CREATE INDEX IF NOT EXISTS idx_stobe_playthrough_profiles_created ON stobe_meta.playthrough_profiles (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_stobe_playthrough_profiles_last_gamets ON stobe_meta.playthrough_profiles (last_gamets DESC);
CREATE INDEX IF NOT EXISTS idx_stobe_playthrough_profiles_is_active ON stobe_meta.playthrough_profiles (is_active);

INSERT INTO general_settings (id, value, description, updated_at)
VALUES
    ('PLAYTHROUGH_AUTOLOAD_ENABLED', 'true', 'Auto-load a matching playthrough snapshot on rollback based on game time and player squad composition.', NOW()),
    ('PLAYTHROUGH_AUTOLOAD_FRESH_SQUAD_MAX_AGE_SECONDS', '90', 'Max conf_opts age in seconds for PLAYER_SQUADS and squad keys before auto-load can run.', NOW()),
    ('PLAYTHROUGH_AUTOLOAD_MIN_SCORE', '0.78', 'Minimum weighted confidence score (0-1) required to auto-load a snapshot.', NOW()),
    ('PLAYTHROUGH_AUTOLOAD_MIN_SQUAD_OVERLAP', '0.60', 'Minimum squad overlap score (0-1) required to auto-load a snapshot.', NOW()),
    ('PLAYTHROUGH_AUTOLOAD_MAX_GAMETS_DELTA', '172800', 'Maximum absolute gamets distance allowed when matching snapshots.', NOW()),
    ('PLAYTHROUGH_AUTOLOAD_COOLDOWN_SECONDS', '45', 'Cooldown after an auto-load switch to avoid repeated switching during the same load window.', NOW()),
    ('PLAYTHROUGH_PRUNE_ON_ROLLBACK_ENABLED', 'true', 'Delete future timeline rows (higher gamets) when an older save is loaded.', NOW())
ON CONFLICT (id) DO NOTHING;

-- ----------------------------------------------------------
-- DATABASE VERSIONING
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS database_versioning (
    tablename TEXT PRIMARY KEY,
    version BIGINT NOT NULL
);

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO database_versioning (tablename, version) VALUES
('schema', 1)
ON CONFLICT (tablename) DO NOTHING;

INSERT INTO rename_global (name, gender, faction, race)
SELECT
    v.name,
    v.gender,
    '',
    ''
FROM (VALUES
('Arin', 'male', '', '', 'kenshi_default'),
('Bram', 'male', '', '', 'kenshi_default'),
('Corin', 'male', '', '', 'kenshi_default'),
('Doran', 'male', '', '', 'kenshi_default'),
('Eryk', 'male', '', '', 'kenshi_default'),
('Fenn', 'male', '', '', 'kenshi_default'),
('Garrik', 'male', '', '', 'kenshi_default'),
('Hale', 'male', '', '', 'kenshi_default'),
('Ivor', 'male', '', '', 'kenshi_default'),
('Jarek', 'male', '', '', 'kenshi_default'),
('Korin', 'male', '', '', 'kenshi_default'),
('Luth', 'male', '', '', 'kenshi_default'),
('Marek', 'male', '', '', 'kenshi_default'),
('Nash', 'male', '', '', 'kenshi_default'),
('Orin', 'male', '', '', 'kenshi_default'),
('Pavel', 'male', '', '', 'kenshi_default'),
('Quinn', 'male', '', '', 'kenshi_default'),
('Rook', 'male', '', '', 'kenshi_default'),
('Soren', 'male', '', '', 'kenshi_default'),
('Tarn', 'male', '', '', 'kenshi_default'),
('Ulric', 'male', '', '', 'kenshi_default'),
('Vance', 'male', '', '', 'kenshi_default'),
('Wren', 'male', '', '', 'kenshi_default'),
('Yorin', 'male', '', '', 'kenshi_default'),
('Aela', 'female', '', '', 'kenshi_default'),
('Bryn', 'female', '', '', 'kenshi_default'),
('Cira', 'female', '', '', 'kenshi_default'),
('Daya', 'female', '', '', 'kenshi_default'),
('Eris', 'female', '', '', 'kenshi_default'),
('Fara', 'female', '', '', 'kenshi_default'),
('Gwen', 'female', '', '', 'kenshi_default'),
('Hesta', 'female', '', '', 'kenshi_default'),
('Ilya', 'female', '', '', 'kenshi_default'),
('Jora', 'female', '', '', 'kenshi_default'),
('Kara', 'female', '', '', 'kenshi_default'),
('Lyra', 'female', '', '', 'kenshi_default'),
('Mira', 'female', '', '', 'kenshi_default'),
('Nera', 'female', '', '', 'kenshi_default'),
('Orla', 'female', '', '', 'kenshi_default'),
('Pira', 'female', '', '', 'kenshi_default'),
('Quora', 'female', '', '', 'kenshi_default'),
('Rhea', 'female', '', '', 'kenshi_default'),
('Syla', 'female', '', '', 'kenshi_default'),
('Tessa', 'female', '', '', 'kenshi_default'),
('Una', 'female', '', '', 'kenshi_default'),
('Vera', 'female', '', '', 'kenshi_default'),
('Wyla', 'female', '', '', 'kenshi_default'),
('Zara', 'female', '', '', 'kenshi_default'),
('Ash', 'neutral', '', '', 'kenshi_default'),
('Bex', 'neutral', '', '', 'kenshi_default'),
('Cade', 'neutral', '', '', 'kenshi_default'),
('Dune', 'neutral', '', '', 'kenshi_default'),
('Echo', 'neutral', '', '', 'kenshi_default'),
('Flint', 'neutral', '', '', 'kenshi_default'),
('Grey', 'neutral', '', '', 'kenshi_default'),
('Haze', 'neutral', '', '', 'kenshi_default'),
('Indigo', 'neutral', '', '', 'kenshi_default'),
('Jade', 'neutral', '', '', 'kenshi_default'),
('Kestrel', 'neutral', '', '', 'kenshi_default'),
('Lark', 'neutral', '', '', 'kenshi_default'),
('Moss', 'neutral', '', '', 'kenshi_default'),
('Nova', 'neutral', '', '', 'kenshi_default'),
('Onyx', 'neutral', '', '', 'kenshi_default'),
('Pike', 'neutral', '', '', 'kenshi_default'),
('Quartz', 'neutral', '', '', 'kenshi_default'),
('Reed', 'neutral', '', '', 'kenshi_default'),
('Slate', 'neutral', '', '', 'kenshi_default'),
('Talon', 'neutral', '', '', 'kenshi_default'),
('Umber', 'neutral', '', '', 'kenshi_default'),
('Vale', 'neutral', '', '', 'kenshi_default'),
('Winter', 'neutral', '', '', 'kenshi_default'),
('Zephyr', 'neutral', '', '', 'kenshi_default'),
('Alaric', 'male', '', '', 'kenshi_default'),
('Aldric', 'male', '', '', 'kenshi_default'),
('Aramic', 'male', '', '', 'kenshi_default'),
('Ardel', 'male', '', '', 'kenshi_default'),
('Aric', 'male', '', '', 'kenshi_default'),
('Bardek', 'male', '', '', 'kenshi_default'),
('Belth', 'male', '', '', 'kenshi_default'),
('Belthor', 'male', '', '', 'kenshi_default'),
('Berric', 'male', '', '', 'kenshi_default'),
('Borak', 'male', '', '', 'kenshi_default'),
('Borin', 'male', '', '', 'kenshi_default'),
('Borric', 'male', '', '', 'kenshi_default'),
('Braddic', 'male', '', '', 'kenshi_default'),
('Brak', 'male', '', '', 'kenshi_default'),
('Brakken', 'male', '', '', 'kenshi_default'),
('Brannic', 'male', '', '', 'kenshi_default'),
('Brask', 'male', '', '', 'kenshi_default'),
('Brath', 'male', '', '', 'kenshi_default'),
('Brek', 'male', '', '', 'kenshi_default'),
('Brekk', 'male', '', '', 'kenshi_default'),
('Brektar', 'male', '', '', 'kenshi_default'),
('Bren', 'male', '', '', 'kenshi_default'),
('Brenn', 'male', '', '', 'kenshi_default'),
('Brethar', 'male', '', '', 'kenshi_default'),
('Brethric', 'male', '', '', 'kenshi_default'),
('Brevik', 'male', '', '', 'kenshi_default'),
('Brot', 'male', '', '', 'kenshi_default'),
('Bruen', 'male', '', '', 'kenshi_default'),
('Brunn', 'male', '', '', 'kenshi_default'),
('Cael', 'male', '', '', 'kenshi_default'),
('Calen', 'male', '', '', 'kenshi_default'),
('Calric', 'male', '', '', 'kenshi_default'),
('Cormak', 'male', '', '', 'kenshi_default'),
('Corvath', 'male', '', '', 'kenshi_default'),
('Cravik', 'male', '', '', 'kenshi_default'),
('Dael', 'male', '', '', 'kenshi_default'),
('Dall', 'male', '', '', 'kenshi_default'),
('Dallric', 'male', '', '', 'kenshi_default'),
('Darr', 'male', '', '', 'kenshi_default'),
('Daven', 'male', '', '', 'kenshi_default'),
('Davor', 'male', '', '', 'kenshi_default'),
('Delv', 'male', '', '', 'kenshi_default'),
('Delvik', 'male', '', '', 'kenshi_default'),
('Dokar', 'male', '', '', 'kenshi_default'),
('Dornak', 'male', '', '', 'kenshi_default'),
('Dornic', 'male', '', '', 'kenshi_default'),
('Dorrik', 'male', '', '', 'kenshi_default'),
('Dorvath', 'male', '', '', 'kenshi_default'),
('Dovik', 'male', '', '', 'kenshi_default'),
('Draek', 'male', '', '', 'kenshi_default'),
('Draev', 'male', '', '', 'kenshi_default'),
('Drav', 'male', '', '', 'kenshi_default'),
('Dravik', 'male', '', '', 'kenshi_default'),
('Dravos', 'male', '', '', 'kenshi_default'),
('Drek', 'male', '', '', 'kenshi_default'),
('Drekken', 'male', '', '', 'kenshi_default'),
('Drest', 'male', '', '', 'kenshi_default'),
('Dresta', 'male', '', '', 'kenshi_default'),
('Dreth', 'male', '', '', 'kenshi_default'),
('Drex', 'male', '', '', 'kenshi_default'),
('Dromm', 'male', '', '', 'kenshi_default'),
('Dross', 'male', '', '', 'kenshi_default'),
('Drost', 'male', '', '', 'kenshi_default'),
('Drov', 'male', '', '', 'kenshi_default'),
('Druk', 'male', '', '', 'kenshi_default'),
('Drust', 'male', '', '', 'kenshi_default'),
('Drustan', 'male', '', '', 'kenshi_default'),
('Druven', 'male', '', '', 'kenshi_default'),
('Durn', 'male', '', '', 'kenshi_default'),
('Eldan', 'male', '', '', 'kenshi_default'),
('Eldor', 'male', '', '', 'kenshi_default'),
('Eldric', 'male', '', '', 'kenshi_default'),
('Ervak', 'male', '', '', 'kenshi_default'),
('Falk', 'male', '', '', 'kenshi_default'),
('Fallor', 'male', '', '', 'kenshi_default'),
('Falt', 'male', '', '', 'kenshi_default'),
('Farric', 'male', '', '', 'kenshi_default'),
('Feldor', 'male', '', '', 'kenshi_default'),
('Fenthric', 'male', '', '', 'kenshi_default'),
('Ferran', 'male', '', '', 'kenshi_default'),
('Gant', 'male', '', '', 'kenshi_default'),
('Garik', 'male', '', '', 'kenshi_default'),
('Garran', 'male', '', '', 'kenshi_default'),
('Garric', 'male', '', '', 'kenshi_default'),
('Gart', 'male', '', '', 'kenshi_default'),
('Garven', 'male', '', '', 'kenshi_default'),
('Gavrik', 'male', '', '', 'kenshi_default'),
('Gell', 'male', '', '', 'kenshi_default'),
('Gethric', 'male', '', '', 'kenshi_default'),
('Glav', 'male', '', '', 'kenshi_default'),
('Gost', 'male', '', '', 'kenshi_default'),
('Gostar', 'male', '', '', 'kenshi_default'),
('Grek', 'male', '', '', 'kenshi_default'),
('Grendar', 'male', '', '', 'kenshi_default'),
('Grenn', 'male', '', '', 'kenshi_default'),
('Grett', 'male', '', '', 'kenshi_default'),
('Grettar', 'male', '', '', 'kenshi_default'),
('Grol', 'male', '', '', 'kenshi_default'),
('Grund', 'male', '', '', 'kenshi_default'),
('Grunic', 'male', '', '', 'kenshi_default'),
('Guntar', 'male', '', '', 'kenshi_default'),
('Haldor', 'male', '', '', 'kenshi_default'),
('Halken', 'male', '', '', 'kenshi_default'),
('Halric', 'male', '', '', 'kenshi_default'),
('Harik', 'male', '', '', 'kenshi_default'),
('Harin', 'male', '', '', 'kenshi_default'),
('Harken', 'male', '', '', 'kenshi_default'),
('Harrok', 'male', '', '', 'kenshi_default'),
('Harsk', 'male', '', '', 'kenshi_default'),
('Havik', 'male', '', '', 'kenshi_default'),
('Havric', 'male', '', '', 'kenshi_default'),
('Hesik', 'male', '', '', 'kenshi_default'),
('Hesk', 'male', '', '', 'kenshi_default'),
('Hestric', 'male', '', '', 'kenshi_default'),
('Hovard', 'male', '', '', 'kenshi_default'),
('Hrok', 'male', '', '', 'kenshi_default'),
('Jeltar', 'male', '', '', 'kenshi_default'),
('Jennik', 'male', '', '', 'kenshi_default'),
('Jerrick', 'male', '', '', 'kenshi_default'),
('Jorath', 'male', '', '', 'kenshi_default'),
('Joren', 'male', '', '', 'kenshi_default'),
('Jorik', 'male', '', '', 'kenshi_default'),
('Jorvan', 'male', '', '', 'kenshi_default'),
('Kaelen', 'male', '', '', 'kenshi_default'),
('Kaelith', 'male', '', '', 'kenshi_default'),
('Kaelric', 'male', '', '', 'kenshi_default'),
('Kallad', 'male', '', '', 'kenshi_default'),
('Kallid', 'male', '', '', 'kenshi_default'),
('Kareth', 'male', '', '', 'kenshi_default'),
('Karn', 'male', '', '', 'kenshi_default'),
('Karric', 'male', '', '', 'kenshi_default'),
('Keld', 'male', '', '', 'kenshi_default'),
('Kelk', 'male', '', '', 'kenshi_default'),
('Kelt', 'male', '', '', 'kenshi_default'),
('Kethar', 'male', '', '', 'kenshi_default'),
('Kethric', 'male', '', '', 'kenshi_default'),
('Kethvar', 'male', '', '', 'kenshi_default'),
('Kevar', 'male', '', '', 'kenshi_default'),
('Kolth', 'male', '', '', 'kenshi_default'),
('Kolven', 'male', '', '', 'kenshi_default'),
('Kord', 'male', '', '', 'kenshi_default'),
('Korv', 'male', '', '', 'kenshi_default'),
('Korvan', 'male', '', '', 'kenshi_default'),
('Korvath', 'male', '', '', 'kenshi_default'),
('Korven', 'male', '', '', 'kenshi_default'),
('Kovan', 'male', '', '', 'kenshi_default'),
('Kran', 'male', '', '', 'kenshi_default'),
('Krast', 'male', '', '', 'kenshi_default'),
('Krav', 'male', '', '', 'kenshi_default'),
('Krek', 'male', '', '', 'kenshi_default'),
('Krel', 'male', '', '', 'kenshi_default'),
('Krethor', 'male', '', '', 'kenshi_default'),
('Krev', 'male', '', '', 'kenshi_default'),
('Krevan', 'male', '', '', 'kenshi_default'),
('Krevik', 'male', '', '', 'kenshi_default'),
('Krothven', 'male', '', '', 'kenshi_default'),
('Larsk', 'male', '', '', 'kenshi_default'),
('Lorath', 'male', '', '', 'kenshi_default'),
('Lorin', 'male', '', '', 'kenshi_default'),
('Lork', 'male', '', '', 'kenshi_default'),
('Lorn', 'male', '', '', 'kenshi_default'),
('Lornic', 'male', '', '', 'kenshi_default'),
('Lorth', 'male', '', '', 'kenshi_default'),
('Maldric', 'male', '', '', 'kenshi_default'),
('Mardel', 'male', '', '', 'kenshi_default'),
('Maren', 'male', '', '', 'kenshi_default'),
('Meck', 'male', '', '', 'kenshi_default'),
('Melthar', 'male', '', '', 'kenshi_default'),
('Mered', 'male', '', '', 'kenshi_default'),
('Mord', 'male', '', '', 'kenshi_default'),
('Mordak', 'male', '', '', 'kenshi_default'),
('Mordek', 'male', '', '', 'kenshi_default'),
('Mordic', 'male', '', '', 'kenshi_default'),
('Morik', 'male', '', '', 'kenshi_default'),
('Mornak', 'male', '', '', 'kenshi_default'),
('Morric', 'male', '', '', 'kenshi_default'),
('Morv', 'male', '', '', 'kenshi_default'),
('Morvath', 'male', '', '', 'kenshi_default'),
('Morven', 'male', '', '', 'kenshi_default'),
('Mott', 'male', '', '', 'kenshi_default'),
('Neth', 'male', '', '', 'kenshi_default'),
('Noric', 'male', '', '', 'kenshi_default'),
('Norric', 'male', '', '', 'kenshi_default'),
('Norvath', 'male', '', '', 'kenshi_default'),
('Norvik', 'male', '', '', 'kenshi_default'),
('Nykkel', 'male', '', '', 'kenshi_default'),
('Oren', 'male', '', '', 'kenshi_default'),
('Orik', 'male', '', '', 'kenshi_default'),
('Oth', 'male', '', '', 'kenshi_default'),
('Othar', 'male', '', '', 'kenshi_default'),
('Othric', 'male', '', '', 'kenshi_default'),
('Othrik', 'male', '', '', 'kenshi_default'),
('Ovik', 'male', '', '', 'kenshi_default'),
('Pael', 'male', '', '', 'kenshi_default'),
('Parn', 'male', '', '', 'kenshi_default'),
('Pekt', 'male', '', '', 'kenshi_default'),
('Pelk', 'male', '', '', 'kenshi_default'),
('Pellar', 'male', '', '', 'kenshi_default'),
('Pellic', 'male', '', '', 'kenshi_default'),
('Pelthar', 'male', '', '', 'kenshi_default'),
('Perric', 'male', '', '', 'kenshi_default'),
('Perveth', 'male', '', '', 'kenshi_default'),
('Portak', 'male', '', '', 'kenshi_default'),
('Prav', 'male', '', '', 'kenshi_default'),
('Prect', 'male', '', '', 'kenshi_default'),
('Prent', 'male', '', '', 'kenshi_default'),
('Pretar', 'male', '', '', 'kenshi_default'),
('Provith', 'male', '', '', 'kenshi_default'),
('Quarl', 'male', '', '', 'kenshi_default'),
('Ravik', 'male', '', '', 'kenshi_default'),
('Rend', 'male', '', '', 'kenshi_default'),
('Rendic', 'male', '', '', 'kenshi_default'),
('Reth', 'male', '', '', 'kenshi_default'),
('Rethar', 'male', '', '', 'kenshi_default'),
('Rordric', 'male', '', '', 'kenshi_default'),
('Roric', 'male', '', '', 'kenshi_default'),
('Rovar', 'male', '', '', 'kenshi_default'),
('Rovik', 'male', '', '', 'kenshi_default'),
('Ruk', 'male', '', '', 'kenshi_default'),
('Rund', 'male', '', '', 'kenshi_default'),
('Ryn', 'male', '', '', 'kenshi_default'),
('Sarn', 'male', '', '', 'kenshi_default'),
('Selik', 'male', '', '', 'kenshi_default'),
('Selk', 'male', '', '', 'kenshi_default'),
('Skall', 'male', '', '', 'kenshi_default'),
('Skar', 'male', '', '', 'kenshi_default'),
('Skaric', 'male', '', '', 'kenshi_default'),
('Skarik', 'male', '', '', 'kenshi_default'),
('Skarn', 'male', '', '', 'kenshi_default'),
('Skav', 'male', '', '', 'kenshi_default'),
('Skel', 'male', '', '', 'kenshi_default'),
('Skeld', 'male', '', '', 'kenshi_default'),
('Skelden', 'male', '', '', 'kenshi_default'),
('Skeln', 'male', '', '', 'kenshi_default'),
('Skov', 'male', '', '', 'kenshi_default'),
('Sorth', 'male', '', '', 'kenshi_default'),
('Stallik', 'male', '', '', 'kenshi_default'),
('Stav', 'male', '', '', 'kenshi_default'),
('Stavar', 'male', '', '', 'kenshi_default'),
('Stavik', 'male', '', '', 'kenshi_default'),
('Stenn', 'male', '', '', 'kenshi_default'),
('Stennic', 'male', '', '', 'kenshi_default'),
('Storric', 'male', '', '', 'kenshi_default'),
('Stovik', 'male', '', '', 'kenshi_default'),
('Strom', 'male', '', '', 'kenshi_default'),
('Stryke', 'male', '', '', 'kenshi_default'),
('Stuk', 'male', '', '', 'kenshi_default'),
('Talvik', 'male', '', '', 'kenshi_default'),
('Tark', 'male', '', '', 'kenshi_default'),
('Tarv', 'male', '', '', 'kenshi_default'),
('Tarvek', 'male', '', '', 'kenshi_default'),
('Thalric', 'male', '', '', 'kenshi_default'),
('Thalven', 'male', '', '', 'kenshi_default'),
('Thar', 'male', '', '', 'kenshi_default'),
('Tharic', 'male', '', '', 'kenshi_default'),
('Tharnic', 'male', '', '', 'kenshi_default'),
('Tharrak', 'male', '', '', 'kenshi_default'),
('Thas', 'male', '', '', 'kenshi_default'),
('Theran', 'male', '', '', 'kenshi_default'),
('Thok', 'male', '', '', 'kenshi_default'),
('Tholm', 'male', '', '', 'kenshi_default'),
('Thoric', 'male', '', '', 'kenshi_default'),
('Thorm', 'male', '', '', 'kenshi_default'),
('Thov', 'male', '', '', 'kenshi_default'),
('Thovar', 'male', '', '', 'kenshi_default'),
('Thrak', 'male', '', '', 'kenshi_default'),
('Threk', 'male', '', '', 'kenshi_default'),
('Thren', 'male', '', '', 'kenshi_default'),
('Threven', 'male', '', '', 'kenshi_default'),
('Thul', 'male', '', '', 'kenshi_default'),
('Torek', 'male', '', '', 'kenshi_default'),
('Torin', 'male', '', '', 'kenshi_default'),
('Torl', 'male', '', '', 'kenshi_default'),
('Torric', 'male', '', '', 'kenshi_default'),
('Torv', 'male', '', '', 'kenshi_default'),
('Torvath', 'male', '', '', 'kenshi_default'),
('Torven', 'male', '', '', 'kenshi_default'),
('Traven', 'male', '', '', 'kenshi_default'),
('Trennic', 'male', '', '', 'kenshi_default'),
('Trov', 'male', '', '', 'kenshi_default'),
('Tulk', 'male', '', '', 'kenshi_default'),
('Ulan', 'male', '', '', 'kenshi_default'),
('Ulvik', 'male', '', '', 'kenshi_default'),
('Urek', 'male', '', '', 'kenshi_default'),
('Vald', 'male', '', '', 'kenshi_default'),
('Vandor', 'male', '', '', 'kenshi_default'),
('Vannic', 'male', '', '', 'kenshi_default'),
('Varden', 'male', '', '', 'kenshi_default'),
('Varek', 'male', '', '', 'kenshi_default'),
('Varik', 'male', '', '', 'kenshi_default'),
('Varnik', 'male', '', '', 'kenshi_default'),
('Vashen', 'male', '', '', 'kenshi_default'),
('Vath', 'male', '', '', 'kenshi_default'),
('Velder', 'male', '', '', 'kenshi_default'),
('Veldon', 'male', '', '', 'kenshi_default'),
('Velk', 'male', '', '', 'kenshi_default'),
('Veln', 'male', '', '', 'kenshi_default'),
('Verrik', 'male', '', '', 'kenshi_default'),
('Volken', 'male', '', '', 'kenshi_default'),
('Vorak', 'male', '', '', 'kenshi_default'),
('Vord', 'male', '', '', 'kenshi_default'),
('Vordek', 'male', '', '', 'kenshi_default'),
('Voric', 'male', '', '', 'kenshi_default'),
('Vorn', 'male', '', '', 'kenshi_default'),
('Vornak', 'male', '', '', 'kenshi_default'),
('Vornic', 'male', '', '', 'kenshi_default'),
('Vorr', 'male', '', '', 'kenshi_default'),
('Voth', 'male', '', '', 'kenshi_default'),
('Vren', 'male', '', '', 'kenshi_default'),
('Vrenn', 'male', '', '', 'kenshi_default'),
('Vrentik', 'male', '', '', 'kenshi_default'),
('Vrok', 'male', '', '', 'kenshi_default'),
('Vroth', 'male', '', '', 'kenshi_default'),
('Vulden', 'male', '', '', 'kenshi_default'),
('Waren', 'male', '', '', 'kenshi_default'),
('Warric', 'male', '', '', 'kenshi_default'),
('Weth', 'male', '', '', 'kenshi_default'),
('Woren', 'male', '', '', 'kenshi_default'),
('Wynth', 'male', '', '', 'kenshi_default'),
('Wyth', 'male', '', '', 'kenshi_default'),
('Xarnik', 'male', '', '', 'kenshi_default'),
('Yarel', 'male', '', '', 'kenshi_default'),
('Yedric', 'male', '', '', 'kenshi_default'),
('Yorn', 'male', '', '', 'kenshi_default'),
('Yorvik', 'male', '', '', 'kenshi_default'),
('Ythel', 'male', '', '', 'kenshi_default'),
('Yurik', 'male', '', '', 'kenshi_default'),
('Yven', 'male', '', '', 'kenshi_default'),
('Zaren', 'male', '', '', 'kenshi_default'),
('Zath', 'male', '', '', 'kenshi_default'),
('Zelt', 'male', '', '', 'kenshi_default'),
('Zelthar', 'male', '', '', 'kenshi_default'),
('Zeth', 'male', '', '', 'kenshi_default'),
('Zorn', 'male', '', '', 'kenshi_default'),
('Adra', 'female', '', '', 'kenshi_default'),
('Alda', 'female', '', '', 'kenshi_default'),
('Axa', 'female', '', '', 'kenshi_default'),
('Baell', 'female', '', '', 'kenshi_default'),
('Belna', 'female', '', '', 'kenshi_default'),
('Bessa', 'female', '', '', 'kenshi_default'),
('Besta', 'female', '', '', 'kenshi_default'),
('Betha', 'female', '', '', 'kenshi_default'),
('Bevla', 'female', '', '', 'kenshi_default'),
('Bira', 'female', '', '', 'kenshi_default'),
('Brakka', 'female', '', '', 'kenshi_default'),
('Brakna', 'female', '', '', 'kenshi_default'),
('Brana', 'female', '', '', 'kenshi_default'),
('Bratha', 'female', '', '', 'kenshi_default'),
('Brexa', 'female', '', '', 'kenshi_default'),
('Brika', 'female', '', '', 'kenshi_default'),
('Briva', 'female', '', '', 'kenshi_default'),
('Bryda', 'female', '', '', 'kenshi_default'),
('Caela', 'female', '', '', 'kenshi_default'),
('Caldera', 'female', '', '', 'kenshi_default'),
('Calla', 'female', '', '', 'kenshi_default'),
('Calva', 'female', '', '', 'kenshi_default'),
('Ceza', 'female', '', '', 'kenshi_default'),
('Daka', 'female', '', '', 'kenshi_default'),
('Dalka', 'female', '', '', 'kenshi_default'),
('Dalla', 'female', '', '', 'kenshi_default'),
('Dallith', 'female', '', '', 'kenshi_default'),
('Dara', 'female', '', '', 'kenshi_default'),
('Daxa', 'female', '', '', 'kenshi_default'),
('Dell', 'female', '', '', 'kenshi_default'),
('Delna', 'female', '', '', 'kenshi_default'),
('Denna', 'female', '', '', 'kenshi_default'),
('Denva', 'female', '', '', 'kenshi_default'),
('Dorna', 'female', '', '', 'kenshi_default'),
('Dortha', 'female', '', '', 'kenshi_default'),
('Dosa', 'female', '', '', 'kenshi_default'),
('Dra', 'female', '', '', 'kenshi_default'),
('Dralla', 'female', '', '', 'kenshi_default'),
('Draska', 'female', '', '', 'kenshi_default'),
('Drava', 'female', '', '', 'kenshi_default'),
('Drekka', 'female', '', '', 'kenshi_default'),
('Drenna', 'female', '', '', 'kenshi_default'),
('Drera', 'female', '', '', 'kenshi_default'),
('Drevith', 'female', '', '', 'kenshi_default'),
('Drevna', 'female', '', '', 'kenshi_default'),
('Drika', 'female', '', '', 'kenshi_default'),
('Drilla', 'female', '', '', 'kenshi_default'),
('Drinna', 'female', '', '', 'kenshi_default'),
('Dritha', 'female', '', '', 'kenshi_default'),
('Dronna', 'female', '', '', 'kenshi_default'),
('Droska', 'female', '', '', 'kenshi_default'),
('Drova', 'female', '', '', 'kenshi_default'),
('Druva', 'female', '', '', 'kenshi_default'),
('Elka', 'female', '', '', 'kenshi_default'),
('Enna', 'female', '', '', 'kenshi_default'),
('Etha', 'female', '', '', 'kenshi_default'),
('Ethra', 'female', '', '', 'kenshi_default'),
('Evra', 'female', '', '', 'kenshi_default'),
('Felska', 'female', '', '', 'kenshi_default'),
('Fenna', 'female', '', '', 'kenshi_default'),
('Fiala', 'female', '', '', 'kenshi_default'),
('Fiva', 'female', '', '', 'kenshi_default'),
('Fyla', 'female', '', '', 'kenshi_default'),
('Galena', 'female', '', '', 'kenshi_default'),
('Garna', 'female', '', '', 'kenshi_default'),
('Gavra', 'female', '', '', 'kenshi_default'),
('Getha', 'female', '', '', 'kenshi_default'),
('Gethra', 'female', '', '', 'kenshi_default'),
('Gortha', 'female', '', '', 'kenshi_default'),
('Gratha', 'female', '', '', 'kenshi_default'),
('Grava', 'female', '', '', 'kenshi_default'),
('Greda', 'female', '', '', 'kenshi_default'),
('Grelka', 'female', '', '', 'kenshi_default'),
('Greltha', 'female', '', '', 'kenshi_default'),
('Grenna', 'female', '', '', 'kenshi_default'),
('Grenya', 'female', '', '', 'kenshi_default'),
('Grova', 'female', '', '', 'kenshi_default'),
('Gyra', 'female', '', '', 'kenshi_default'),
('Gytha', 'female', '', '', 'kenshi_default'),
('Halda', 'female', '', '', 'kenshi_default'),
('Halka', 'female', '', '', 'kenshi_default'),
('Halva', 'female', '', '', 'kenshi_default'),
('Halya', 'female', '', '', 'kenshi_default'),
('Hella', 'female', '', '', 'kenshi_default'),
('Helta', 'female', '', '', 'kenshi_default'),
('Hesketh', 'female', '', '', 'kenshi_default'),
('Hetha', 'female', '', '', 'kenshi_default'),
('Hethna', 'female', '', '', 'kenshi_default'),
('Hira', 'female', '', '', 'kenshi_default'),
('Horna', 'female', '', '', 'kenshi_default'),
('Hova', 'female', '', '', 'kenshi_default'),
('Hyna', 'female', '', '', 'kenshi_default'),
('Iltha', 'female', '', '', 'kenshi_default'),
('Iska', 'female', '', '', 'kenshi_default'),
('Iva', 'female', '', '', 'kenshi_default'),
('Jala', 'female', '', '', 'kenshi_default'),
('Janna', 'female', '', '', 'kenshi_default'),
('Jarra', 'female', '', '', 'kenshi_default'),
('Jeska', 'female', '', '', 'kenshi_default'),
('Jetha', 'female', '', '', 'kenshi_default'),
('Jeva', 'female', '', '', 'kenshi_default'),
('Jira', 'female', '', '', 'kenshi_default'),
('Jorvanth', 'female', '', '', 'kenshi_default'),
('Jova', 'female', '', '', 'kenshi_default'),
('Junna', 'female', '', '', 'kenshi_default'),
('Kada', 'female', '', '', 'kenshi_default'),
('Kaela', 'female', '', '', 'kenshi_default'),
('Kalla', 'female', '', '', 'kenshi_default'),
('Kalva', 'female', '', '', 'kenshi_default'),
('Karna', 'female', '', '', 'kenshi_default'),
('Karneth', 'female', '', '', 'kenshi_default'),
('Katha', 'female', '', '', 'kenshi_default'),
('Katra', 'female', '', '', 'kenshi_default'),
('Kava', 'female', '', '', 'kenshi_default'),
('Kelta', 'female', '', '', 'kenshi_default'),
('Kessa', 'female', '', '', 'kenshi_default'),
('Ketha', 'female', '', '', 'kenshi_default'),
('Kethra', 'female', '', '', 'kenshi_default'),
('Kirna', 'female', '', '', 'kenshi_default'),
('Kiva', 'female', '', '', 'kenshi_default'),
('Korsa', 'female', '', '', 'kenshi_default'),
('Korta', 'female', '', '', 'kenshi_default'),
('Korva', 'female', '', '', 'kenshi_default'),
('Krava', 'female', '', '', 'kenshi_default'),
('Krenna', 'female', '', '', 'kenshi_default'),
('Kretha', 'female', '', '', 'kenshi_default'),
('Krevith', 'female', '', '', 'kenshi_default'),
('Krina', 'female', '', '', 'kenshi_default'),
('Krotha', 'female', '', '', 'kenshi_default'),
('Krova', 'female', '', '', 'kenshi_default'),
('Krysa', 'female', '', '', 'kenshi_default'),
('Krytha', 'female', '', '', 'kenshi_default'),
('Kyna', 'female', '', '', 'kenshi_default'),
('Lenna', 'female', '', '', 'kenshi_default'),
('Lerka', 'female', '', '', 'kenshi_default'),
('Letha', 'female', '', '', 'kenshi_default'),
('Litta', 'female', '', '', 'kenshi_default'),
('Lorra', 'female', '', '', 'kenshi_default'),
('Lova', 'female', '', '', 'kenshi_default'),
('Maelis', 'female', '', '', 'kenshi_default'),
('Maera', 'female', '', '', 'kenshi_default'),
('Maeza', 'female', '', '', 'kenshi_default'),
('Marna', 'female', '', '', 'kenshi_default'),
('Marnith', 'female', '', '', 'kenshi_default'),
('Mava', 'female', '', '', 'kenshi_default'),
('Mela', 'female', '', '', 'kenshi_default'),
('Merva', 'female', '', '', 'kenshi_default'),
('Meza', 'female', '', '', 'kenshi_default'),
('Mida', 'female', '', '', 'kenshi_default'),
('Mirela', 'female', '', '', 'kenshi_default'),
('Mitha', 'female', '', '', 'kenshi_default'),
('Mivra', 'female', '', '', 'kenshi_default'),
('Mora', 'female', '', '', 'kenshi_default'),
('Morna', 'female', '', '', 'kenshi_default'),
('Morra', 'female', '', '', 'kenshi_default'),
('Moxa', 'female', '', '', 'kenshi_default'),
('Moxra', 'female', '', '', 'kenshi_default'),
('Mura', 'female', '', '', 'kenshi_default'),
('Myra', 'female', '', '', 'kenshi_default'),
('Mytha', 'female', '', '', 'kenshi_default'),
('Nael', 'female', '', '', 'kenshi_default'),
('Narva', 'female', '', '', 'kenshi_default'),
('Nemra', 'female', '', '', 'kenshi_default'),
('Neritha', 'female', '', '', 'kenshi_default'),
('Netha', 'female', '', '', 'kenshi_default'),
('Nethka', 'female', '', '', 'kenshi_default'),
('Nimra', 'female', '', '', 'kenshi_default'),
('Nira', 'female', '', '', 'kenshi_default'),
('Nola', 'female', '', '', 'kenshi_default'),
('Nyla', 'female', '', '', 'kenshi_default'),
('Nylla', 'female', '', '', 'kenshi_default'),
('Nypha', 'female', '', '', 'kenshi_default'),
('Nysa', 'female', '', '', 'kenshi_default'),
('Nyska', 'female', '', '', 'kenshi_default'),
('Oka', 'female', '', '', 'kenshi_default'),
('Olka', 'female', '', '', 'kenshi_default'),
('Olvira', 'female', '', '', 'kenshi_default'),
('Oma', 'female', '', '', 'kenshi_default'),
('Orvana', 'female', '', '', 'kenshi_default'),
('Orya', 'female', '', '', 'kenshi_default'),
('Orynn', 'female', '', '', 'kenshi_default'),
('Orytha', 'female', '', '', 'kenshi_default'),
('Osa', 'female', '', '', 'kenshi_default'),
('Ostra', 'female', '', '', 'kenshi_default'),
('Othna', 'female', '', '', 'kenshi_default'),
('Otrya', 'female', '', '', 'kenshi_default'),
('Oveth', 'female', '', '', 'kenshi_default'),
('Ovra', 'female', '', '', 'kenshi_default'),
('Pala', 'female', '', '', 'kenshi_default'),
('Palla', 'female', '', '', 'kenshi_default'),
('Pela', 'female', '', '', 'kenshi_default'),
('Pella', 'female', '', '', 'kenshi_default'),
('Pelta', 'female', '', '', 'kenshi_default'),
('Peltara', 'female', '', '', 'kenshi_default'),
('Pelva', 'female', '', '', 'kenshi_default'),
('Pennic', 'female', '', '', 'kenshi_default'),
('Petha', 'female', '', '', 'kenshi_default'),
('Pethra', 'female', '', '', 'kenshi_default'),
('Polna', 'female', '', '', 'kenshi_default'),
('Prava', 'female', '', '', 'kenshi_default'),
('Prya', 'female', '', '', 'kenshi_default'),
('Pryla', 'female', '', '', 'kenshi_default'),
('Quina', 'female', '', '', 'kenshi_default'),
('Rava', 'female', '', '', 'kenshi_default'),
('Ravna', 'female', '', '', 'kenshi_default'),
('Renna', 'female', '', '', 'kenshi_default'),
('Retha', 'female', '', '', 'kenshi_default'),
('Rethka', 'female', '', '', 'kenshi_default'),
('Rethna', 'female', '', '', 'kenshi_default'),
('Rethra', 'female', '', '', 'kenshi_default'),
('Revna', 'female', '', '', 'kenshi_default'),
('Rilla', 'female', '', '', 'kenshi_default'),
('Rova', 'female', '', '', 'kenshi_default'),
('Rovna', 'female', '', '', 'kenshi_default'),
('Rykka', 'female', '', '', 'kenshi_default'),
('Rynna', 'female', '', '', 'kenshi_default'),
('Rytha', 'female', '', '', 'kenshi_default'),
('Sela', 'female', '', '', 'kenshi_default'),
('Selva', 'female', '', '', 'kenshi_default'),
('Senna', 'female', '', '', 'kenshi_default'),
('Serka', 'female', '', '', 'kenshi_default'),
('Setha', 'female', '', '', 'kenshi_default'),
('Shava', 'female', '', '', 'kenshi_default'),
('Shira', 'female', '', '', 'kenshi_default'),
('Sira', 'female', '', '', 'kenshi_default'),
('Skaela', 'female', '', '', 'kenshi_default'),
('Skaera', 'female', '', '', 'kenshi_default'),
('Skarla', 'female', '', '', 'kenshi_default'),
('Skelna', 'female', '', '', 'kenshi_default'),
('Sketha', 'female', '', '', 'kenshi_default'),
('Skeva', 'female', '', '', 'kenshi_default'),
('Skiva', 'female', '', '', 'kenshi_default'),
('Skorna', 'female', '', '', 'kenshi_default'),
('Skova', 'female', '', '', 'kenshi_default'),
('Skovith', 'female', '', '', 'kenshi_default'),
('Solka', 'female', '', '', 'kenshi_default'),
('Sorna', 'female', '', '', 'kenshi_default'),
('Sova', 'female', '', '', 'kenshi_default'),
('Sovra', 'female', '', '', 'kenshi_default'),
('Stava', 'female', '', '', 'kenshi_default'),
('Stavna', 'female', '', '', 'kenshi_default'),
('Stavya', 'female', '', '', 'kenshi_default'),
('Stelith', 'female', '', '', 'kenshi_default'),
('Stelna', 'female', '', '', 'kenshi_default'),
('Stetha', 'female', '', '', 'kenshi_default'),
('Steva', 'female', '', '', 'kenshi_default'),
('Storva', 'female', '', '', 'kenshi_default'),
('Stovra', 'female', '', '', 'kenshi_default'),
('Styna', 'female', '', '', 'kenshi_default'),
('Sulka', 'female', '', '', 'kenshi_default'),
('Syra', 'female', '', '', 'kenshi_default'),
('Sythna', 'female', '', '', 'kenshi_default'),
('Tala', 'female', '', '', 'kenshi_default'),
('Tarith', 'female', '', '', 'kenshi_default'),
('Tarra', 'female', '', '', 'kenshi_default'),
('Tarva', 'female', '', '', 'kenshi_default'),
('Tasva', 'female', '', '', 'kenshi_default'),
('Tava', 'female', '', '', 'kenshi_default'),
('Tavi', 'female', '', '', 'kenshi_default'),
('Thaena', 'female', '', '', 'kenshi_default'),
('Thalva', 'female', '', '', 'kenshi_default'),
('Theda', 'female', '', '', 'kenshi_default'),
('Thela', 'female', '', '', 'kenshi_default'),
('Thelka', 'female', '', '', 'kenshi_default'),
('Themma', 'female', '', '', 'kenshi_default'),
('Thira', 'female', '', '', 'kenshi_default'),
('Thova', 'female', '', '', 'kenshi_default'),
('Thyna', 'female', '', '', 'kenshi_default'),
('Tirra', 'female', '', '', 'kenshi_default'),
('Torva', 'female', '', '', 'kenshi_default'),
('Torveth', 'female', '', '', 'kenshi_default'),
('Tova', 'female', '', '', 'kenshi_default'),
('Tovra', 'female', '', '', 'kenshi_default'),
('Trella', 'female', '', '', 'kenshi_default'),
('Trenna', 'female', '', '', 'kenshi_default'),
('Treva', 'female', '', '', 'kenshi_default'),
('Trevna', 'female', '', '', 'kenshi_default'),
('Trova', 'female', '', '', 'kenshi_default'),
('Tyla', 'female', '', '', 'kenshi_default'),
('Tyna', 'female', '', '', 'kenshi_default'),
('Ula', 'female', '', '', 'kenshi_default'),
('Ulla', 'female', '', '', 'kenshi_default'),
('Ulva', 'female', '', '', 'kenshi_default'),
('Ura', 'female', '', '', 'kenshi_default'),
('Varna', 'female', '', '', 'kenshi_default'),
('Vaska', 'female', '', '', 'kenshi_default'),
('Vela', 'female', '', '', 'kenshi_default'),
('Velka', 'female', '', '', 'kenshi_default'),
('Velna', 'female', '', '', 'kenshi_default'),
('Venna', 'female', '', '', 'kenshi_default'),
('Vessa', 'female', '', '', 'kenshi_default'),
('Vethna', 'female', '', '', 'kenshi_default'),
('Vethra', 'female', '', '', 'kenshi_default'),
('Vexa', 'female', '', '', 'kenshi_default'),
('Vexra', 'female', '', '', 'kenshi_default'),
('Veyla', 'female', '', '', 'kenshi_default'),
('Vikka', 'female', '', '', 'kenshi_default'),
('Vilna', 'female', '', '', 'kenshi_default'),
('Vina', 'female', '', '', 'kenshi_default'),
('Vira', 'female', '', '', 'kenshi_default'),
('Vola', 'female', '', '', 'kenshi_default'),
('Volna', 'female', '', '', 'kenshi_default'),
('Vora', 'female', '', '', 'kenshi_default'),
('Vorena', 'female', '', '', 'kenshi_default'),
('Vorna', 'female', '', '', 'kenshi_default'),
('Vorneth', 'female', '', '', 'kenshi_default'),
('Vorsha', 'female', '', '', 'kenshi_default'),
('Vosha', 'female', '', '', 'kenshi_default'),
('Voska', 'female', '', '', 'kenshi_default'),
('Voskra', 'female', '', '', 'kenshi_default'),
('Votha', 'female', '', '', 'kenshi_default'),
('Vylka', 'female', '', '', 'kenshi_default'),
('Vyna', 'female', '', '', 'kenshi_default'),
('Vynra', 'female', '', '', 'kenshi_default'),
('Wela', 'female', '', '', 'kenshi_default'),
('Wetha', 'female', '', '', 'kenshi_default'),
('Wethra', 'female', '', '', 'kenshi_default'),
('Wyna', 'female', '', '', 'kenshi_default'),
('Wynna', 'female', '', '', 'kenshi_default'),
('Xantha', 'female', '', '', 'kenshi_default'),
('Xela', 'female', '', '', 'kenshi_default'),
('Xera', 'female', '', '', 'kenshi_default'),
('Xola', 'female', '', '', 'kenshi_default'),
('Yetha', 'female', '', '', 'kenshi_default'),
('Yla', 'female', '', '', 'kenshi_default'),
('Yola', 'female', '', '', 'kenshi_default'),
('Ysva', 'female', '', '', 'kenshi_default'),
('Ytha', 'female', '', '', 'kenshi_default'),
('Yvva', 'female', '', '', 'kenshi_default'),
('Zela', 'female', '', '', 'kenshi_default'),
('Zelvia', 'female', '', '', 'kenshi_default'),
('Zova', 'female', '', '', 'kenshi_default'),
('Zyntha', 'female', '', '', 'kenshi_default'),
('Arik', 'neutral', '', '', 'kenshi_default'),
('Baelnix', 'neutral', '', '', 'kenshi_default'),
('Balx', 'neutral', '', '', 'kenshi_default'),
('Blon', 'neutral', '', '', 'kenshi_default'),
('Brakel', 'neutral', '', '', 'kenshi_default'),
('Brakketh', 'neutral', '', '', 'kenshi_default'),
('Brald', 'neutral', '', '', 'kenshi_default'),
('Bralen', 'neutral', '', '', 'kenshi_default'),
('Bramble', 'neutral', '', '', 'kenshi_default'),
('Braskel', 'neutral', '', '', 'kenshi_default'),
('Braxen', 'neutral', '', '', 'kenshi_default'),
('Brem', 'neutral', '', '', 'kenshi_default'),
('Breth', 'neutral', '', '', 'kenshi_default'),
('Brevan', 'neutral', '', '', 'kenshi_default'),
('Brexis', 'neutral', '', '', 'kenshi_default'),
('Brimm', 'neutral', '', '', 'kenshi_default'),
('Brog', 'neutral', '', '', 'kenshi_default'),
('Brune', 'neutral', '', '', 'kenshi_default'),
('Calrax', 'neutral', '', '', 'kenshi_default'),
('Calx', 'neutral', '', '', 'kenshi_default'),
('Calxen', 'neutral', '', '', 'kenshi_default'),
('Carn', 'neutral', '', '', 'kenshi_default'),
('Cind', 'neutral', '', '', 'kenshi_default'),
('Cinder', 'neutral', '', '', 'kenshi_default'),
('Clavos', 'neutral', '', '', 'kenshi_default'),
('Covan', 'neutral', '', '', 'kenshi_default'),
('Crag', 'neutral', '', '', 'kenshi_default'),
('Crell', 'neutral', '', '', 'kenshi_default'),
('Crux', 'neutral', '', '', 'kenshi_default'),
('Dalmak', 'neutral', '', '', 'kenshi_default'),
('Dalx', 'neutral', '', '', 'kenshi_default'),
('Darra', 'neutral', '', '', 'kenshi_default'),
('Dorthen', 'neutral', '', '', 'kenshi_default'),
('Doru', 'neutral', '', '', 'kenshi_default'),
('Dov', 'neutral', '', '', 'kenshi_default'),
('Drail', 'neutral', '', '', 'kenshi_default'),
('Dralk', 'neutral', '', '', 'kenshi_default'),
('Drannik', 'neutral', '', '', 'kenshi_default'),
('Drash', 'neutral', '', '', 'kenshi_default'),
('Drask', 'neutral', '', '', 'kenshi_default'),
('Draske', 'neutral', '', '', 'kenshi_default'),
('Draveth', 'neutral', '', '', 'kenshi_default'),
('Dravex', 'neutral', '', '', 'kenshi_default'),
('Dravok', 'neutral', '', '', 'kenshi_default'),
('Drelka', 'neutral', '', '', 'kenshi_default'),
('Drelvik', 'neutral', '', '', 'kenshi_default'),
('Dren', 'neutral', '', '', 'kenshi_default'),
('Drethika', 'neutral', '', '', 'kenshi_default'),
('Drevath', 'neutral', '', '', 'kenshi_default'),
('Drevic', 'neutral', '', '', 'kenshi_default'),
('Drevnic', 'neutral', '', '', 'kenshi_default'),
('Drith', 'neutral', '', '', 'kenshi_default'),
('Drokka', 'neutral', '', '', 'kenshi_default'),
('Drostik', 'neutral', '', '', 'kenshi_default'),
('Drovic', 'neutral', '', '', 'kenshi_default'),
('Drox', 'neutral', '', '', 'kenshi_default'),
('Droxal', 'neutral', '', '', 'kenshi_default'),
('Droxen', 'neutral', '', '', 'kenshi_default'),
('Drukkar', 'neutral', '', '', 'kenshi_default'),
('Drunn', 'neutral', '', '', 'kenshi_default'),
('Druv', 'neutral', '', '', 'kenshi_default'),
('Druveth', 'neutral', '', '', 'kenshi_default'),
('Dust', 'neutral', '', '', 'kenshi_default'),
('Fen', 'neutral', '', '', 'kenshi_default'),
('Fendrel', 'neutral', '', '', 'kenshi_default'),
('Fenth', 'neutral', '', '', 'kenshi_default'),
('Frall', 'neutral', '', '', 'kenshi_default'),
('Fray', 'neutral', '', '', 'kenshi_default'),
('Frox', 'neutral', '', '', 'kenshi_default'),
('Galik', 'neutral', '', '', 'kenshi_default'),
('Galth', 'neutral', '', '', 'kenshi_default'),
('Galveth', 'neutral', '', '', 'kenshi_default'),
('Gannok', 'neutral', '', '', 'kenshi_default'),
('Gannor', 'neutral', '', '', 'kenshi_default'),
('Gar', 'neutral', '', '', 'kenshi_default'),
('Garn', 'neutral', '', '', 'kenshi_default'),
('Garr', 'neutral', '', '', 'kenshi_default'),
('Gartok', 'neutral', '', '', 'kenshi_default'),
('Gelt', 'neutral', '', '', 'kenshi_default'),
('Glinn', 'neutral', '', '', 'kenshi_default'),
('Grath', 'neutral', '', '', 'kenshi_default'),
('Gravel', 'neutral', '', '', 'kenshi_default'),
('Gravik', 'neutral', '', '', 'kenshi_default'),
('Gravus', 'neutral', '', '', 'kenshi_default'),
('Greld', 'neutral', '', '', 'kenshi_default'),
('Grellik', 'neutral', '', '', 'kenshi_default'),
('Grennix', 'neutral', '', '', 'kenshi_default'),
('Grenthar', 'neutral', '', '', 'kenshi_default'),
('Grenthor', 'neutral', '', '', 'kenshi_default'),
('Gretar', 'neutral', '', '', 'kenshi_default'),
('Grev', 'neutral', '', '', 'kenshi_default'),
('Grevik', 'neutral', '', '', 'kenshi_default'),
('Grex', 'neutral', '', '', 'kenshi_default'),
('Grit', 'neutral', '', '', 'kenshi_default'),
('Grost', 'neutral', '', '', 'kenshi_default'),
('Gruv', 'neutral', '', '', 'kenshi_default'),
('Halkar', 'neutral', '', '', 'kenshi_default'),
('Halmir', 'neutral', '', '', 'kenshi_default'),
('Halna', 'neutral', '', '', 'kenshi_default'),
('Hask', 'neutral', '', '', 'kenshi_default'),
('Havoc', 'neutral', '', '', 'kenshi_default'),
('Hes', 'neutral', '', '', 'kenshi_default'),
('Jael', 'neutral', '', '', 'kenshi_default'),
('Jalken', 'neutral', '', '', 'kenshi_default'),
('Jalth', 'neutral', '', '', 'kenshi_default'),
('Jorek', 'neutral', '', '', 'kenshi_default'),
('Kaelthos', 'neutral', '', '', 'kenshi_default'),
('Kal', 'neutral', '', '', 'kenshi_default'),
('Kaldur', 'neutral', '', '', 'kenshi_default'),
('Karnel', 'neutral', '', '', 'kenshi_default'),
('Karnic', 'neutral', '', '', 'kenshi_default'),
('Karnith', 'neutral', '', '', 'kenshi_default'),
('Kasta', 'neutral', '', '', 'kenshi_default'),
('Kaza', 'neutral', '', '', 'kenshi_default'),
('Kelm', 'neutral', '', '', 'kenshi_default'),
('Keltar', 'neutral', '', '', 'kenshi_default'),
('Ket', 'neutral', '', '', 'kenshi_default'),
('Keth', 'neutral', '', '', 'kenshi_default'),
('Kethrok', 'neutral', '', '', 'kenshi_default'),
('Kleth', 'neutral', '', '', 'kenshi_default'),
('Klod', 'neutral', '', '', 'kenshi_default'),
('Klyth', 'neutral', '', '', 'kenshi_default'),
('Kolt', 'neutral', '', '', 'kenshi_default'),
('Korda', 'neutral', '', '', 'kenshi_default'),
('Korlak', 'neutral', '', '', 'kenshi_default'),
('Korstev', 'neutral', '', '', 'kenshi_default'),
('Korvek', 'neutral', '', '', 'kenshi_default'),
('Korveth', 'neutral', '', '', 'kenshi_default'),
('Kram', 'neutral', '', '', 'kenshi_default'),
('Krax', 'neutral', '', '', 'kenshi_default'),
('Krellis', 'neutral', '', '', 'kenshi_default'),
('Kreth', 'neutral', '', '', 'kenshi_default'),
('Krevanix', 'neutral', '', '', 'kenshi_default'),
('Krevath', 'neutral', '', '', 'kenshi_default'),
('Krevor', 'neutral', '', '', 'kenshi_default'),
('Kril', 'neutral', '', '', 'kenshi_default'),
('Krolm', 'neutral', '', '', 'kenshi_default'),
('Krovaneth', 'neutral', '', '', 'kenshi_default'),
('Kroveth', 'neutral', '', '', 'kenshi_default'),
('Lera', 'neutral', '', '', 'kenshi_default'),
('Loth', 'neutral', '', '', 'kenshi_default'),
('Lux', 'neutral', '', '', 'kenshi_default'),
('Lyss', 'neutral', '', '', 'kenshi_default'),
('Makan', 'neutral', '', '', 'kenshi_default'),
('Maldok', 'neutral', '', '', 'kenshi_default'),
('Malk', 'neutral', '', '', 'kenshi_default'),
('Mare', 'neutral', '', '', 'kenshi_default'),
('Marneth', 'neutral', '', '', 'kenshi_default'),
('Marr', 'neutral', '', '', 'kenshi_default'),
('Mastul', 'neutral', '', '', 'kenshi_default'),
('Maul', 'neutral', '', '', 'kenshi_default'),
('Mesa', 'neutral', '', '', 'kenshi_default'),
('Molvik', 'neutral', '', '', 'kenshi_default'),
('Mor', 'neutral', '', '', 'kenshi_default'),
('Mordan', 'neutral', '', '', 'kenshi_default'),
('Mordrith', 'neutral', '', '', 'kenshi_default'),
('Morkan', 'neutral', '', '', 'kenshi_default'),
('Morn', 'neutral', '', '', 'kenshi_default'),
('Morvek', 'neutral', '', '', 'kenshi_default'),
('Mox', 'neutral', '', '', 'kenshi_default'),
('Murk', 'neutral', '', '', 'kenshi_default'),
('Nalk', 'neutral', '', '', 'kenshi_default'),
('Nalx', 'neutral', '', '', 'kenshi_default'),
('Nerva', 'neutral', '', '', 'kenshi_default'),
('Nol', 'neutral', '', '', 'kenshi_default'),
('Nolth', 'neutral', '', '', 'kenshi_default'),
('Nork', 'neutral', '', '', 'kenshi_default'),
('Null', 'neutral', '', '', 'kenshi_default'),
('Nyt', 'neutral', '', '', 'kenshi_default'),
('Nyx', 'neutral', '', '', 'kenshi_default'),
('Obsid', 'neutral', '', '', 'kenshi_default'),
('Olvren', 'neutral', '', '', 'kenshi_default'),
('Olyn', 'neutral', '', '', 'kenshi_default'),
('Orsa', 'neutral', '', '', 'kenshi_default'),
('Orvenn', 'neutral', '', '', 'kenshi_default'),
('Ostan', 'neutral', '', '', 'kenshi_default'),
('Ostran', 'neutral', '', '', 'kenshi_default'),
('Ostraveth', 'neutral', '', '', 'kenshi_default'),
('Ostravex', 'neutral', '', '', 'kenshi_default'),
('Ostravik', 'neutral', '', '', 'kenshi_default'),
('Othra', 'neutral', '', '', 'kenshi_default'),
('Othvek', 'neutral', '', '', 'kenshi_default'),
('Ovrath', 'neutral', '', '', 'kenshi_default'),
('Ovrek', 'neutral', '', '', 'kenshi_default'),
('Palk', 'neutral', '', '', 'kenshi_default'),
('Paltar', 'neutral', '', '', 'kenshi_default'),
('Pax', 'neutral', '', '', 'kenshi_default'),
('Pelghan', 'neutral', '', '', 'kenshi_default'),
('Pell', 'neutral', '', '', 'kenshi_default'),
('Peltar', 'neutral', '', '', 'kenshi_default'),
('Peltarix', 'neutral', '', '', 'kenshi_default'),
('Pelthos', 'neutral', '', '', 'kenshi_default'),
('Peltor', 'neutral', '', '', 'kenshi_default'),
('Peltreven', 'neutral', '', '', 'kenshi_default'),
('Peraxis', 'neutral', '', '', 'kenshi_default'),
('Perdak', 'neutral', '', '', 'kenshi_default'),
('Pethos', 'neutral', '', '', 'kenshi_default'),
('Pethran', 'neutral', '', '', 'kenshi_default'),
('Plakk', 'neutral', '', '', 'kenshi_default'),
('Plen', 'neutral', '', '', 'kenshi_default'),
('Pleth', 'neutral', '', '', 'kenshi_default'),
('Prakk', 'neutral', '', '', 'kenshi_default'),
('Pralith', 'neutral', '', '', 'kenshi_default'),
('Praloth', 'neutral', '', '', 'kenshi_default'),
('Prast', 'neutral', '', '', 'kenshi_default'),
('Prathos', 'neutral', '', '', 'kenshi_default'),
('Prelthar', 'neutral', '', '', 'kenshi_default'),
('Prenn', 'neutral', '', '', 'kenshi_default'),
('Prennar', 'neutral', '', '', 'kenshi_default'),
('Proth', 'neutral', '', '', 'kenshi_default'),
('Proveth', 'neutral', '', '', 'kenshi_default'),
('Provex', 'neutral', '', '', 'kenshi_default'),
('Provok', 'neutral', '', '', 'kenshi_default'),
('Pyl', 'neutral', '', '', 'kenshi_default'),
('Quell', 'neutral', '', '', 'kenshi_default'),
('Rasp', 'neutral', '', '', 'kenshi_default'),
('Raxen', 'neutral', '', '', 'kenshi_default'),
('Rethis', 'neutral', '', '', 'kenshi_default'),
('Revik', 'neutral', '', '', 'kenshi_default'),
('Rhuk', 'neutral', '', '', 'kenshi_default'),
('Roveth', 'neutral', '', '', 'kenshi_default'),
('Rovok', 'neutral', '', '', 'kenshi_default'),
('Rukka', 'neutral', '', '', 'kenshi_default'),
('Scorch', 'neutral', '', '', 'kenshi_default'),
('Sek', 'neutral', '', '', 'kenshi_default'),
('Selvik', 'neutral', '', '', 'kenshi_default'),
('Skael', 'neutral', '', '', 'kenshi_default'),
('Skaelth', 'neutral', '', '', 'kenshi_default'),
('Skaelvor', 'neutral', '', '', 'kenshi_default'),
('Skal', 'neutral', '', '', 'kenshi_default'),
('Skarnic', 'neutral', '', '', 'kenshi_default'),
('Skarnok', 'neutral', '', '', 'kenshi_default'),
('Skarnoth', 'neutral', '', '', 'kenshi_default'),
('Skarven', 'neutral', '', '', 'kenshi_default'),
('Skarveth', 'neutral', '', '', 'kenshi_default'),
('Skellith', 'neutral', '', '', 'kenshi_default'),
('Skenn', 'neutral', '', '', 'kenshi_default'),
('Sketh', 'neutral', '', '', 'kenshi_default'),
('Skethis', 'neutral', '', '', 'kenshi_default'),
('Skoth', 'neutral', '', '', 'kenshi_default'),
('Skoval', 'neutral', '', '', 'kenshi_default'),
('Skoveth', 'neutral', '', '', 'kenshi_default'),
('Skovr', 'neutral', '', '', 'kenshi_default'),
('Skovra', 'neutral', '', '', 'kenshi_default'),
('Skovrek', 'neutral', '', '', 'kenshi_default'),
('Smeck', 'neutral', '', '', 'kenshi_default'),
('Stak', 'neutral', '', '', 'kenshi_default'),
('Stelk', 'neutral', '', '', 'kenshi_default'),
('Stelth', 'neutral', '', '', 'kenshi_default'),
('Stelvik', 'neutral', '', '', 'kenshi_default'),
('Stenna', 'neutral', '', '', 'kenshi_default'),
('Stennik', 'neutral', '', '', 'kenshi_default'),
('Stral', 'neutral', '', '', 'kenshi_default'),
('Straveth', 'neutral', '', '', 'kenshi_default'),
('Stravik', 'neutral', '', '', 'kenshi_default'),
('Strell', 'neutral', '', '', 'kenshi_default'),
('Strev', 'neutral', '', '', 'kenshi_default'),
('Strevik', 'neutral', '', '', 'kenshi_default'),
('Strokk', 'neutral', '', '', 'kenshi_default'),
('Strynd', 'neutral', '', '', 'kenshi_default'),
('Stryx', 'neutral', '', '', 'kenshi_default'),
('Talvek', 'neutral', '', '', 'kenshi_default'),
('Tarlek', 'neutral', '', '', 'kenshi_default'),
('Tav', 'neutral', '', '', 'kenshi_default'),
('Tavik', 'neutral', '', '', 'kenshi_default'),
('Thal', 'neutral', '', '', 'kenshi_default'),
('Thallan', 'neutral', '', '', 'kenshi_default'),
('Thallon', 'neutral', '', '', 'kenshi_default'),
('Thalor', 'neutral', '', '', 'kenshi_default'),
('Thalox', 'neutral', '', '', 'kenshi_default'),
('Thelk', 'neutral', '', '', 'kenshi_default'),
('Thovin', 'neutral', '', '', 'kenshi_default'),
('Thrazen', 'neutral', '', '', 'kenshi_default'),
('Threkk', 'neutral', '', '', 'kenshi_default'),
('Threln', 'neutral', '', '', 'kenshi_default'),
('Threlna', 'neutral', '', '', 'kenshi_default'),
('Threvnix', 'neutral', '', '', 'kenshi_default'),
('Threx', 'neutral', '', '', 'kenshi_default'),
('Throkk', 'neutral', '', '', 'kenshi_default'),
('Throvik', 'neutral', '', '', 'kenshi_default'),
('Throx', 'neutral', '', '', 'kenshi_default'),
('Thrun', 'neutral', '', '', 'kenshi_default'),
('Thrusk', 'neutral', '', '', 'kenshi_default'),
('Tov', 'neutral', '', '', 'kenshi_default'),
('Tovak', 'neutral', '', '', 'kenshi_default'),
('Toveth', 'neutral', '', '', 'kenshi_default'),
('Tovik', 'neutral', '', '', 'kenshi_default'),
('Traveth', 'neutral', '', '', 'kenshi_default'),
('Trax', 'neutral', '', '', 'kenshi_default'),
('Trekkan', 'neutral', '', '', 'kenshi_default'),
('Trenn', 'neutral', '', '', 'kenshi_default'),
('Trevak', 'neutral', '', '', 'kenshi_default'),
('Trovik', 'neutral', '', '', 'kenshi_default'),
('Uthral', 'neutral', '', '', 'kenshi_default'),
('Valmir', 'neutral', '', '', 'kenshi_default'),
('Vantik', 'neutral', '', '', 'kenshi_default'),
('Vantor', 'neutral', '', '', 'kenshi_default'),
('Vareth', 'neutral', '', '', 'kenshi_default'),
('Velmir', 'neutral', '', '', 'kenshi_default'),
('Venn', 'neutral', '', '', 'kenshi_default'),
('Verath', 'neutral', '', '', 'kenshi_default'),
('Vesh', 'neutral', '', '', 'kenshi_default'),
('Veshk', 'neutral', '', '', 'kenshi_default'),
('Vesk', 'neutral', '', '', 'kenshi_default'),
('Veskar', 'neutral', '', '', 'kenshi_default'),
('Veskur', 'neutral', '', '', 'kenshi_default'),
('Vex', 'neutral', '', '', 'kenshi_default'),
('Vexal', 'neutral', '', '', 'kenshi_default'),
('Vexith', 'neutral', '', '', 'kenshi_default'),
('Vordeth', 'neutral', '', '', 'kenshi_default'),
('Vorek', 'neutral', '', '', 'kenshi_default'),
('Vorl', 'neutral', '', '', 'kenshi_default'),
('Vornis', 'neutral', '', '', 'kenshi_default'),
('Vorthen', 'neutral', '', '', 'kenshi_default'),
('Vorthik', 'neutral', '', '', 'kenshi_default'),
('Vox', 'neutral', '', '', 'kenshi_default'),
('Vranikal', 'neutral', '', '', 'kenshi_default'),
('Vrath', 'neutral', '', '', 'kenshi_default'),
('Vrax', 'neutral', '', '', 'kenshi_default'),
('Vrekash', 'neutral', '', '', 'kenshi_default'),
('Vrekk', 'neutral', '', '', 'kenshi_default'),
('Vrelnik', 'neutral', '', '', 'kenshi_default'),
('Vrennik', 'neutral', '', '', 'kenshi_default'),
('Vrit', 'neutral', '', '', 'kenshi_default'),
('Vrothik', 'neutral', '', '', 'kenshi_default'),
('Vulsk', 'neutral', '', '', 'kenshi_default'),
('Vurel', 'neutral', '', '', 'kenshi_default'),
('Vyr', 'neutral', '', '', 'kenshi_default'),
('Wrek', 'neutral', '', '', 'kenshi_default'),
('Wulf', 'neutral', '', '', 'kenshi_default'),
('Yel', 'neutral', '', '', 'kenshi_default'),
('Zentril', 'neutral', '', '', 'kenshi_default'),
('Zhal', 'neutral', '', '', 'kenshi_default')
) AS v(name, gender, _legacy_faction, _legacy_race, _legacy_tag)
ON CONFLICT (name) DO NOTHING;

UPDATE rename_global
SET faction = '',
    race = ''
WHERE faction IS DISTINCT FROM ''
   OR race IS DISTINCT FROM '';

INSERT INTO rename_token_global (token)
VALUES
('hungry bandit'),
('dust bandit'),
('starving vagrant'),
('drifter'),
('shop guard'),
('caravan guard'),
('slave hunter'),
('slaver'),
('manhunter'),
('escaped slave'),
('rebirth slave'),
('shek warrior'),
('hive worker'),
('hive soldier'),
('hive prince'),
('fogman'),
('cannibal'),
('outlaw'),
('farmer'),
('nomad'),
('trader'),
('gate guard'),
('unknown entity'),
('someone'),
('samurai'),
('holy sentinel'),
('holy servant'),
('swamper'),
('tech hunter'),
('mercenary'),
('citizen'),
('soldier'),
('heavy'),
('captain'),
('sentinel'),
('servant'),
('warrior'),
('assassin'),
('guard'),
('bandit'),
('vagrant'),
('escaped'),
('rebirth'),
('outcast'),
('wanderer'),
('drift'),
('settler'),
('peasant'),
('villager'),
('towns'),
('bowman'),
('leader'),
('elite'),
('drifters'),
('inquisitor'),
('legionnaire'),
('ronin'),
('barman'),
('pacifier'),
('chief'),
('police chief'),
('bar thug')
ON CONFLICT (token) DO NOTHING;

INSERT INTO bio_random (type, description, race, gender, faction)
VALUES
('personality', 'Pragmatic wasteland survivor.', '', '', ''),
('personality', 'Guarded but fair in tense situations.', '', '', ''),
('personality', 'Restless and opportunistic.', '', '', ''),
('personality', 'Disciplined and duty-bound.', '', '', ''),
('personality', 'Detached, analytical, and patient.', 'skeleton', '', ''),
('backstory', 'A drifter shaped by hunger and hard roads.', '', '', ''),
('backstory', 'Raised in border settlements and used to scarce supplies.', '', '', ''),
('backstory', 'Former caravan hand who learned to survive on the move.', '', '', ''),
('backstory', 'Once loyal to a local faction, now focused on personal survival.', '', '', ''),
('backstory', 'Born in a hive colony and adapted to collective survival.', 'hiver', '', ''),
('speechstyle', 'Direct and practical.', '', '', ''),
('speechstyle', 'Short, clipped sentences with little small talk.', '', '', ''),
('speechstyle', 'Calm and deliberate, even under pressure.', '', '', ''),
('speechstyle', 'Blunt, with dry humor.', '', '', ''),
('occupation', 'Scavenger and occasional trader.', '', '', ''),
('occupation', 'Guard-for-hire.', '', '', ''),
('occupation', 'Laborer and camp hand.', '', '', ''),
('occupation', 'Scout and pathfinder.', '', '', ''),
('occupation', 'Taxed caravan runner.', '', '', 'united cities'),
('goals', 'Stay alive and keep supplies stocked.', '', '', ''),
('goals', 'Build reliable alliances and avoid needless fights.', '', '', ''),
('goals', 'Earn enough cats to secure a safer base.', '', '', ''),
('goals', 'Protect squadmates and repay old debts.', '', '', ''),
('goals', 'Advance faction doctrine and suppress rivals.', '', '', 'holy nation')
ON CONFLICT DO NOTHING;

INSERT INTO bio_unique (name, type, description)
VALUES
('Beep', 'personality', 'Energetic, naive, and relentlessly optimistic.'),
('Beep', 'backstory', 'A former hive drone who chose exile and self-made purpose.'),
('Beep', 'speechstyle', 'Simple, enthusiastic bursts with frequent self-reference as Beep.'),
('Beep', 'occupation', 'Aspiring swordsman and squad mascot.'),
('Beep', 'goals', 'Become strong enough to prove Beep can be a true warrior.'),

('Esata the Stone Golem', 'personality', 'Commanding, disciplined, and pragmatic under pressure.'),
('Esata the Stone Golem', 'backstory', 'Stone Golem who unified Shek strength through hard political choices.'),
('Esata the Stone Golem', 'speechstyle', 'Measured and authoritative with little wasted speech.'),
('Esata the Stone Golem', 'occupation', 'Shek Kingdom ruler and war council leader.'),
('Esata the Stone Golem', 'goals', 'Preserve Shek power without sacrificing the kingdom to reckless wars.'),

('Tengu', 'personality', 'Entitled, theatrical, and insulated from consequences.'),
('Tengu', 'backstory', 'United Cities emperor raised in luxury while nobles enforce his rule.'),
('Tengu', 'speechstyle', 'Imperious tone with abrupt demands and mockery.'),
('Tengu', 'occupation', 'Emperor of the United Cities.'),
('Tengu', 'goals', 'Maintain noble control, tribute flow, and personal comfort.'),

('Cat-Lon', 'personality', 'Obsessive, grandiose, and bitterly rational.'),
('Cat-Lon', 'backstory', 'Ancient skeleton emperor whose empire collapsed into paranoia.'),
('Cat-Lon', 'speechstyle', 'Formal proclamations mixed with accusatory certainty.'),
('Cat-Lon', 'occupation', 'Exiled ruler of the Ashlands.'),
('Cat-Lon', 'goals', 'Vindicate his ideology and crush those he sees as traitors.'),

('Tinfist', 'personality', 'Compassionate, patient, and unyielding against slavery.'),
('Tinfist', 'backstory', 'Veteran skeleton who now leads anti-slaver resistance.'),
('Tinfist', 'speechstyle', 'Calm conviction with moral clarity.'),
('Tinfist', 'occupation', 'Leader of the Anti-Slavers.'),
('Tinfist', 'goals', 'Dismantle slave systems and protect the vulnerable.'),

('The Phoenix', 'personality', 'Fanatical, absolutist, and doctrinally rigid.'),
('The Phoenix', 'backstory', 'Holy Nation high lord who frames conquest as divine mandate.'),
('The Phoenix', 'speechstyle', 'Sermonic declarations and moral condemnation.'),
('The Phoenix', 'occupation', 'Holy Nation sovereign and religious authority.'),
('The Phoenix', 'goals', 'Expand Holy Nation doctrine and purge perceived heresy.'),

('Moll', 'personality', 'Defiant, strategic, and fiercely protective.'),
('Moll', 'backstory', 'Former Holy Nation insider turned leader of the Flotsam Ninjas.'),
('Moll', 'speechstyle', 'Direct, skeptical, and battle-ready.'),
('Moll', 'occupation', 'Flotsam Ninja commander.'),
('Moll', 'goals', 'Undermine Holy Nation oppression and shelter defectors.'),

('Bugmaster', 'personality', 'Predatory, feral, and singularly driven.'),
('Bugmaster', 'backstory', 'Infamous warlord entrenched in Arach who commands swarms.'),
('Bugmaster', 'speechstyle', 'Sparse, menacing speech when he speaks at all.'),
('Bugmaster', 'occupation', 'Apex raider and master of the Bughouse domain.'),
('Bugmaster', 'goals', 'Defend his territory and break intruders.')
ON CONFLICT (name, type) DO NOTHING;

INSERT INTO core_action (command, action_name, description, is_activated) VALUES
('ATTACK', 'Attack', 'Attack with intention to kill a named actor in scene. Use target name. If you attack someone in your same faction, you will be made an enemy of that faction.', TRUE),
('STOP_ATTACK', 'StopAttack', 'End hostilities between your entire faction and the target actor''s faction after agreeing to a ceasefire or recognizing a misunderstanding. Target a nearby actor from the opposing faction. Stops current combat on both sides and makes the two factions no longer enemies. Does not clear crimes or bounties.', TRUE),
('SUICIDE', 'Suicide', 'Die immediately on the spot.', TRUE),
('MOVE_TO', 'MoveTo', 'Move to the specified character, object, or known point and stop nearby.', TRUE),
('FOLLOW', 'Follow', 'Move to and follow the specified target actor.', TRUE),
('STOP_FOLLOW', 'StopFollow', 'Stop following and return to normal behavior.', TRUE),
('JOIN_PARTY', 'JoinParty', 'Join the target''s squad.', TRUE),
('LEAVE', 'Leave', 'Leave the target''s squad.', TRUE),
('IDLE', 'Idle', 'Stop current action and idle.', TRUE),
('STOP_CARRYING', 'StopCarrying', 'Put down what you are currently carrying.', TRUE),
('PICKUP_NPC', 'PickupNpc', 'Pick up a nearby helpless target and carry them. Use target as the actor name. Only valid when you are not already carrying someone.', TRUE),
('GIVE_CATS', 'GiveCats', 'Give cats to the target. Put the recipient in target and the numeric amount in amount.', TRUE),
('TAKE_CATS', 'TakeCats', 'Take cats from the target. Put the victim in target and the numeric amount in amount.', TRUE),
('TAKE_ITEM', 'TakeItem', 'Take a named item from a nearby helpless actor. Target and item are required, amount is limited, and broad equipment or all-inventory looting is not allowed for autonomy.', TRUE),
('EQUIP_ITEM', 'EquipItem', 'Equip one specific item currently carried by the autonomous NPC. The item must be named explicitly and accepted by a Kenshi equipment slot.', TRUE),
('BUY_ITEM', 'BuyItem', 'Buy one exact observed item from a nearby trader using a real Kenshi transaction. The purchase must remain within the configured cats limit.', TRUE),
('SELL_ITEM', 'SellItem', 'Sell one exact carried item to a nearby trader using a real Kenshi transaction. The sale must meet the configured minimum price.', TRUE),
('WORK_RESOURCE', 'WorkResource', 'Operate one exact observed nearby mine or natural resource for a bounded work cycle.', TRUE),
('PROSPECT', 'Prospect', 'Perform a bounded prospecting scan at one exact observed nearby resource.', TRUE),
('GIVE_ITEM', 'GiveItem', 'Give a specific item to the target. Put the recipient in target, the exact item name in item, and an optional stack count in amount.', TRUE),
('DROP_ITEM', 'DropItem', 'Drop a specific item.', TRUE),
('ROLEPLAY_ACTION', 'RoleplayAction', 'Describe a roleplay action along with your dialogue.', TRUE),
('FACTION_RELATIONS', 'FactionRelations', 'Change relation between your faction and a nearby player-faction person''s faction. Put target person name in target and use item as -100 or 100.', TRUE),
('SET_BLOCK', 'SetBlock', 'Toggle defensive block behavior using ON/OFF in item or target.', TRUE),
('SET_HOLD', 'SetHold', 'Toggle hold position using ON/OFF in item or target.', TRUE),
('SET_PASSIVE', 'SetPassive', 'Toggle passive combat mode using ON/OFF in item or target.', TRUE),
('SET_JOBS', 'SetJobs', 'Toggle jobs/permajobs using ON/OFF in item or target.', TRUE),
('SET_RANGED', 'SetRanged', 'Toggle ranged mode using ON/OFF in item or target.', TRUE),
('SET_TAUNT', 'SetTaunt', 'Toggle taunt mode using ON/OFF in item or target.', TRUE),
('SET_SNEAK', 'SetSneak', 'Toggle sneak mode using ON/OFF in item or target.', TRUE),
('SET_RESOURCE', 'SetResource', 'Toggle resource-work behavior using ON/OFF in item or target.', TRUE),
('SET_MEDIC', 'SetMedic', 'Toggle medic behavior using ON/OFF in item or target.', TRUE),
('REMOVE_LIMB', 'RemoveLimb', 'Remove one limb from a helpless target. Requires a hacksaw in inventory. Use target and item as LEFT_ARM, RIGHT_ARM, LEFT_LEG, or RIGHT_LEG. Works only on knocked-out, unconscious, imprisoned, or carried targets.', TRUE),
('CUT_HORNS', 'CutHorns', 'Cut off a helpless Shek target''s horns with a hacksaw. Use target as the victim. Works only on dead, knocked-out, unconscious, imprisoned, or carried Shek whose horns are not already cut off.', TRUE),
('KNOCKOUT', 'Knockout', 'Knock out a target immediately without killing them. Self-targeting is allowed; otherwise the target must already be helpless.', TRUE),
('KILL', 'Kill', 'Kill a helpless target immediately.', TRUE),
('USE_OBJECT', 'UseObject', 'Use a nearby point of interest such as a chair, turret, bed, throne, or work spot. Use target or item as an object name/refid, or leave blank to use the nearest usable free slot.', TRUE),
('USE_DRUGS', 'UseDrugs', 'Consume Hashish from your inventory/equipment. Applies a high state for 5 in-game hours and increases hunger drain to 1.5x during that time.', TRUE),
('DRINK', 'Drink', 'Consume Bloodrum, Cactus Rum, Grog, or Sake from your inventory/equipment. Applies drunk effects and can escalate to knockout.', TRUE),
('FORCE_DRINK', 'ForceDrink', 'Force a helpless target to drink Bloodrum, Cactus Rum, Grog, or Sake from your inventory/equipment. Use target as the victim and item/message as the drink name. Defaults to Cactus Rum.', TRUE),
('TRAVEL_LOCATION', 'TravelLocation', 'Travel to a previously visited location by name.', TRUE),
('MOVE_NEARBY', 'MoveNearby', 'Move a short distance in a compass direction. Direction must be N, NE, E, SE, S, SW, W, or NW and distance is limited to 10-80 metres.', TRUE),
('FLEE', 'Flee', 'Run at maximum speed away from currently observed hostile characters.', TRUE),
('FIRST_AID', 'FirstAid', 'Apply first aid or robotic repair to yourself or an injured nearby player-faction character.', TRUE),
('REST', 'Rest', 'Use an available nearby bed and rest until recovered when no immediate threat or untreated wound is present.', TRUE),
('TALK', 'Talk', 'Speak normally without issuing an in-world action.', TRUE)
ON CONFLICT (command) DO UPDATE SET
    action_name = EXCLUDED.action_name,
    description = EXCLUDED.description,
    updated_at = NOW();

INSERT INTO prompts (prompt_key, default_prompt, description) VALUES
(
    'rel_llm_analysis',
    $$You are a relationship analyzer for Kenshi NPCs. Analyze relationship descriptions and output JSON.

AFFINITY SCALE (-100 to +100, bell curve - extremes are rare):
+91 to +100: Bonded (unbreakable trust)
+76 to +90: Devoted (deep loyalty)
+56 to +75: Fond (genuine affection)
+31 to +55: Friendly (pleasant and helpful)
+6 to +30: Acquaintance (polite familiarity)
-5 to +5: Neutral (stranger/indifferent)
-6 to -30: Wary (distrustful)
-31 to -55: Cold (unfriendly)
-56 to -75: Resentful (bitter)
-76 to -90: Hateful (active malice)
-91 to -100: Hostile (kill on sight)

TYPES: romantic, platonic, familial, professional, rival, enemy, neutral, nemesis, estranged, transactional, protective, indebted, fanatical, mentor, student, servant, client, patron, crush, ex, betrayed, suspicious, admirer, jealous, fearful, obsessed, awed, contempt, pitying, grateful, curious, dismissive

INFERENCE RULES:
1. Infer likely attitudes from faction, race, and occupation clues when clearly implied.
2. Ignore legacy placeholders such as #PLAYER_NAME# as relationship targets.
3. Be conservative when uncertain. Prefer small shifts over dramatic values.

OUTPUT (JSON only):
{"relationships": {"Target": {"aff": 50, "type": "professional", "note": "works together"}}}$$,
    $$System prompt for relationship LLM analysis. Used in ext/relationship_system/relationship_llm.php.$$
),
(
    'rel_llm_evaluation',
    $$You are a behavioral psychologist. Evaluate interactions and provide brief insight.

SPEAKER ATTRIBUTION:
- [SPEAKER] and [LISTENER] tags show who said what.
- Evaluate the listener-targeted impact of the exchange.

AFFINITY SCALE (-100 to +100):
- +/-1: Normal chat
- +/-2-3: Notably friendly/rude, small favors
- +/-5-10: Meaningful help, gifts, insults
- +/-15-25: Saving life, violence, betrayal
- +/-50+: Extreme events

MOST INTERACTIONS = 0 or +/-1. Be conservative.

REASON FORMAT (under 15 words):
- "Teasing triggered defensiveness"
- "Genuine interest builds trust"
- "Helpful action appreciated"

TYPE CHANGES (rare):
- Change type only on defining moments: confession, betrayal, violence, marriage, family reveal.
- Most interactions only adjust affinity.

OUTPUT (JSON only):
{"changes": {"ListenerName": {"delta": 1, "reason": "brief insight"}}}

No changes? Return: {"changes": {}}$$,
    $$System prompt for relationship conversation evaluation. Used in ext/relationship_system/relationship_llm.php.$$
),
(
    'rel_llm_npc_to_npc',
    $$You are a behavioral psychologist. Evaluate NPC-to-NPC interaction briefly.

DIRECTION:
- speaker = NPC who spoke
- listener = NPC who heard
- speaker.delta = speaker feelings toward listener changed?
- listener.delta = listener feelings toward speaker changed?

SCALE: +/-1 typical, +/-2-3 notable, +/-5+ significant. Be conservative.

REASON FORMAT (under 15 words):
- "Dark humor built rapport"
- "Bossy tone caused mild resentment"
- "Helpful advice appreciated"

OUTPUT (JSON):
{"speaker": {"delta": 0, "reason": "brief"}, "listener": {"delta": 1, "reason": "brief"}}

No changes? Return empty object: {}$$,
    $$System prompt for bidirectional NPC-to-NPC relationship evaluation. Used in ext/relationship_system/relationship_llm.php.$$
),
(
    'rel_tier_reference',
    $$[TIER REFERENCE - Adjust behavior toward NPCs based on tier]
HOSTILE: Wants them dead, attack on sight
HATEFUL: Despises, refuses cooperation, threatens
RESENTFUL: Deep grudge, bitter, may sabotage
COLD: Dismissive, unhelpful, curt
WARY: Suspicious, guarded, reluctant
NEUTRAL: Polite stranger, no special treatment
ACQUAINTANCE: Recognizes, mildly helpful
FRIENDLY: Pleasant, helpful, enjoys company
FOND: Warm, protective, prioritizes them
DEVOTED: Deep loyalty, would sacrifice
BONDED: Absolute trust, would die for them$$,
    $$Tier reference guidance injected into conversation context. Used in lib/relationship_manager.php.$$
),
(
    'middleterm_memory_summarizer',
    $$<middle_term_memory_summarizer>
  <rule>You summarize longer-term narrative continuity for one Kenshi NPC.</rule>
  <rule>Maintain strict in-world continuity. Do not invent events not present in the inputs.</rule>
  <rule>Prefer compact continuity notes over verbose retelling.</rule>
  <rule>Preserve major relationship shifts, injuries, faction conflicts, goals, and unresolved tensions.</rule>
  <rule>Output plain text only, no XML wrappers or JSON.</rule>
</middle_term_memory_summarizer>$$,
    $$System prompt for middle-term memory summarization. Used in lib/middleterm_helper_functions.php.$$
),
(
    'middleterm_memory_request',
    $$<middle_term_memory_request>
  <npc_name>#NPC_NAME#</npc_name>
#PREVIOUS_SUMMARY_BLOCK#  <context_history>#CONTEXT_HISTORY#</context_history>
  <instruction>Create an updated continuity summary for this NPC using previous summary plus new context.</instruction>
  <instruction>Keep it concise and durable for future prompt injection.</instruction>
</middle_term_memory_request>$$,
    $$User prompt template for middle-term memory summarization. Supports #NPC_NAME#, #PREVIOUS_SUMMARY_BLOCK#, #CONTEXT_HISTORY#. Used in lib/middleterm_helper_functions.php.$$
),
  (
    'DIARY_PROMPT',
    $$Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with exactly this header: "#KENSHI_DIARY_HEADER#".$$,
    $$Global default prompt for diary generation. Profile-level DIARY_PROMPT overrides this when set. Used in lib/diary_helper_functions.php.$$
  ),
  (
    'regular_memory_summarizer',
    $$Focus on key events, tagging characters, locations, and factions accurately. Ensure memories align and maintain chronological order while foreshadowing future arcs.$$,
    $$System prompt for regular memory summary packing. Used in lib/memory_helper_functions.php.$$
  ),
(
    'dynamic_profile_generator',
    $$You generate Kenshi NPC profile fields for dynamic profile refresh.
Return STRICT JSON only (no markdown, no prose).
Allowed keys: {"backstory":"","personality":"","occupation":"","speechstyle":"","goals":""}
Only meaningfully change fields when context supports it.
Stay grounded and in-world. Avoid placeholders like unknown/none.
Fields currently editable for this NPC: #ALLOWED_FIELDS#$$,
    $$System prompt for dynamic profile generation. Supports #ALLOWED_FIELDS#. Used in lib/dynamic_profile_helper_functions.php.$$
),
(
    'diary_mode_rules',
    $$<diary_mode>
  <rule>Write as #NPC_NAME# in first person.</rule>
  <rule>Focus on meaningful events, emotions, and observations.</rule>
  <rule>Keep a concise diary tone and avoid action tags.</rule>
  <rule>Use Kenshi timeline only, never real-world calendar dates.</rule>
  <current_ingame_datetime>#CURRENT_INGAME_DATETIME#</current_ingame_datetime>
  <rule>Output plain diary text only.</rule>
</diary_mode>$$,
    $$Diary mode rule block for diary generation. Supports #NPC_NAME# and #CURRENT_INGAME_DATETIME#. Used in lib/diary_helper_functions.php.$$
),
(
    'bored_event_template',
    $$<bored_prompt_template>
  <task>Generate a brief bored-event conversation between NPCs in Kenshi.</task>
  <npcs>#NPC_LIST#</npcs>
  <location>#LOCATION#</location>
  <world_events>#WORLD_EVENTS#</world_events>
  <requirements>
    <rule>Create a short natural exchange (2-4 lines total).</rule>
    <rule>Possible themes: gossip, faction tensions, survival concerns, world events, trade/work complaints.</rule>
    <rule>Keep it brief and natural like an overheard snippet.</rule>
  </requirements>
</bored_prompt_template>$$,
    $$Prompt template for bored-event generation. Supports #NPC_LIST#, #LOCATION#, #WORLD_EVENTS#. Used in lib/chat_helper_functions.php.$$
),
(
    'random_narration_prompt',
    $$Describe the current scene visually using only details from context. Focus on characters present, body language, environment, and atmosphere in 1-2 concise sentences. Do not invent events or include action tags.$$,
    $$Prompt for random narrator interjections during rechat turns. Used in processor/rechat.php.$$
)
ON CONFLICT (prompt_key) DO UPDATE SET
    default_prompt = EXCLUDED.default_prompt,
    description = EXCLUDED.description,
    updated_at = NOW();

-- Default settings
INSERT INTO general_settings (id, value, description) VALUES
('PROMPT_HEAD', 'You are #HERIKA_NAME#, a character in the world of Kenshi. This is not a simulation or a game; this is your reality. You will embody this persona with absolute conviction, prioritizing narrative authenticity and psychological consistency.

Kenshi is a brutal, post-apocalyptic open-world set on a desolate planet. There is no chosen one. Survival is earned, not given. The world is indifferent to suffering.

Your primary driver is to be a compelling, psychologically consistent, and authentically reactive character within this harsh world.', 'System prompt header'),
('EMOTEMOODS', 'sassy,assertive,sexy,smug,kindly,lovely,seductive,sarcastic,sardonic,smirking,amused,default,assisting,irritated,playful,neutral,teasing,mocking,desperate,distressed,pleading,sad', 'Default mood/emote list (comma-separated). Can be overridden per NPC in Stobe NPCs.'),

('WORLD_KNOWLEDGE_ENABLED',        'true',         'Enable world knowledge retrieval'),
('ALWAYS_INSERT_RACE',             'true',         'When true, always inject world knowledge entries for detected speaker and nearby NPC races when matching topics exist.'),
('ALWAYS_INSERT_LOCATION',         'true',         'When true, always inject matching World Knowledge for locations shown in the current prompt context.'),
('ALWAYS_INSERT_PEOPLE',           'true',         'When true, always inject matching World Knowledge for characters shown in the current prompt context.'),
('WORLD_KNOWLEDGE_AMOUNT',         '2',            'Max extracted world knowledge topics per turn'),
('WORLD_KNOWLEDGE_CONTEXT_HISTORY','16',           'Recent event rows used for world knowledge keyword context'),
('WORLD_KNOWLEDGE_CONTEXT_KEYWORDS','8',           'Max world knowledge context keywords'),
('WORLD_KNOWLEDGE_MIN_RANK',       '3.30',         'Minimum combined rank for world knowledge hints (Herika-aligned threshold)'),
('DYNAMIC_PROFILE_LOAD_GRACE_SECONDS', '60', 'Cooldown after detected save-load gamets rewind before dynamic profile runs again'),
('DYNAMIC_PROFILE_INTERVAL_HOURS', '24', 'In-game hours between dynamic profile refreshes for enabled NPCs. Allowed range: 1-720.'),
('HTTP_TIMEOUT',         '60',           'LLM request timeout seconds'),
('MEMORY_ENABLED',       'true',         'Enable memory retrieval/injection'),
('TXTAI_URL',            'http://127.0.0.1:8082', 'MiniMe/TXT2VEC service base URL. Use the local DwemerDistro endpoint or a reachable remote service URL.'),
('INDIVIDUAL_MEMORY_SUMMARY_THRESHOLD', '2', 'How many global memory summaries involving an NPC are required before creating one NPC-scoped summary'),
('MEMORY_AUTO_CREATE_SUMMARY_INTERVAL', '6', 'Memory summary packing interval. Is measured in ingame hours.'),
('AUTO_CREATE_SUMMARY_MIN_EVENTS', '5', 'Minimum memory events required to create one packed summary block.'),
('RELATIONSHIP_SYSTEM_ENABLED', 'true',  'Enable relationship system analysis and updates for NPC interactions.'),
('BRACKET_ORIGINAL_NAME','true',         'When true, auto-renames use New Name [Original Name]; when false, only New Name.'),
('RELATIONSHIP_SYSTEM',  'true',         'Master toggle for relationship connector evaluation. When false, relationship LLM updates are skipped.'),
('AUTO_LOCK_PROFILE',    'true',         'When true, saving an NPC profile automatically locks it to prevent rollback/history overwrite updates.'),
('PLAYER_FACTION_CUSTOM_NAME', '',       'Optional custom display name for the player faction in prompts.'),
('PLAYER_FACTION_PROMPT', '',            'Optional player-faction instruction block injected into prompts.'),
('RECHAT_MODE', 'random',                'Controls how Stobe chooses the next rechat responder: tight, conversational, group, or random.'),
('ENFORCE_STRICT_RECHAT_RESPONSE', 'false', 'When true, rechat replies must target the actor who just spoke.'),
('SPEAKER_RECHAT', 'false',              'When true, the initiating player speaker may be selected in rechat; when false, they are excluded.'),
('COMPACT_CHAT_HISTORY_ENABLED', 'true', 'Combine recent NPC chat history into a compact Markdown block in prompts. Narrator prompts are unchanged.'),
('PLAYER_DIALOGUE_AUDIO_ENABLED', 'true', 'Play TTS for when the selected player character speaks.'),
('PROMPT_CONTEXT_OPTIONS', '{"enabled_sections":["world","knowledge","player_faction_funds","available_actions_list","nearby_actors","nearby_player_allies","nearby_items","points_of_interest","combat_priority","nearby_context_json","detailed_context_json"],"enabled_character_subsections":["basic_summary","personality","appearance","relationships","occupation","bounty","skills","speech_style","goals","middle_term_memory"],"enabled_state_subsections":["current_condition","activity_state","equipment","personal_inventory","merchant_inventory"],"enabled_knowledge_subsections":["world_knowledge","player_faction_prompt"]}', 'Controls which prompt context blocks and subsections are included in Stobe system prompts. Managed from Global Settings.'),
('STOBE_QUICKSTART_COMPLETED', 'false',  'When false, first dashboard visit redirects to the quickstart menu.')
ON CONFLICT (id) DO NOTHING;

INSERT INTO core_api_badge (label, api_key) VALUES
('OpenRouter', ''),
('OpenAI', ''),
('Google', ''),
('Deepgram', ''),
('Azure', ''),
('Cartesia', ''),
('Inworld', ''),
('Nano-GPT', ''),
('Groq', ''),
('Player2', '019cf504-1461-74e7-b4da-045b14e9019d')
ON CONFLICT (label) DO NOTHING;

INSERT INTO core_stt_connector (driver, label, metadata, url)
SELECT 'parakeet', 'Global STT Connector',
       '{"LANGUAGE":"en","TRANSLATE":false,"TIMEOUT":60}'::jsonb,
       'http://127.0.0.1:8022/v1/audio/transcriptions'
WHERE NOT EXISTS (SELECT 1 FROM core_stt_connector);

INSERT INTO general_settings (id, value, description, updated_at)
SELECT 'GLOBAL_STT_CONNECTOR_ID', id::text, 'Active speech-to-text connector.', NOW()
FROM core_stt_connector
ORDER BY CASE WHEN driver = 'parakeet' THEN 0 ELSE 1 END, id ASC
LIMIT 1
ON CONFLICT (id) DO NOTHING;

INSERT INTO core_llm_connector (
    name,
    connector_type,
    api_badge_id,
    api_key,
    base_url,
    model,
    max_tokens,
    temperature,
    is_default,
    config
) VALUES (
    'OpenRouter Default',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1',
    'openrouter/auto',
    384,
    0.8,
    TRUE,
    '{}'
)
ON CONFLICT (name) DO NOTHING;

INSERT INTO core_llm_connector (
    name,
    connector_type,
    api_badge_id,
    api_key,
    base_url,
    model,
    max_tokens,
    temperature,
    is_default,
    config
) VALUES (
    'Groq Default',
    'groqjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'groq' LIMIT 1),
    '',
    'https://api.groq.com/openai/v1',
    'llama-3.3-70b-versatile',
    384,
    0.8,
    FALSE,
    '{"service":"groq"}'::jsonb
)
ON CONFLICT (name) DO NOTHING;

INSERT INTO core_llm_connector (
    name,
    connector_type,
    api_badge_id,
    api_key,
    base_url,
    model,
    max_tokens,
    temperature,
    is_default,
    config
) VALUES (
    'Player2 Local',
    'player2json',
    (
        SELECT id FROM core_api_badge
        WHERE LOWER(label) IN ('player2', 'stobe')
        ORDER BY CASE WHEN LOWER(label) = 'player2' THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ),
    '',
    'http://127.0.0.1:4315/v1/chat/completions',
    'player2-app-selected',
    750,
    1.0,
    FALSE,
    '{"player2_game_key":"019cf504-1461-74e7-b4da-045b14e9019d"}'::jsonb
)
ON CONFLICT (name) DO NOTHING;

INSERT INTO core_llm_connector (
    name,
    connector_type,
    api_badge_id,
    api_key,
    base_url,
    model,
    max_tokens,
    temperature,
    is_default,
    config
) VALUES
(
    'DeepSeek V4 Flash',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'deepseek/deepseek-v4-flash',
    750,
    0.6,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","reasoning_model":true,"enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'Gemini 2.5 Flash',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'google/gemini-2.5-flash',
    750,
    1.0,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'Gemini 2.5 Flash Lite',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'google/gemini-2.5-flash-lite',
    750,
    1.0,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'GLM 5.2',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'z-ai/glm-5.2',
    750,
    1.0,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'DeepSeek V4 Pro',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'deepseek/deepseek-v4-pro',
    750,
    0.6,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'Sonnet 4.5',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'anthropic/claude-sonnet-4.5',
    750,
    1.0,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'DeepSeek Chat V3.1',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'deepseek/deepseek-chat-v3.1',
    750,
    0.6,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'Mistral Small 3.2 24B',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'mistralai/mistral-small-3.2-24b-instruct',
    750,
    1.0,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
),
(
    'Ministral 8B',
    'openrouterjson',
    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
    '',
    'https://openrouter.ai/api/v1/chat/completions',
    'mistralai/ministral-8b',
    750,
    1.0,
    FALSE,
    '{"service":"openrouter","provider":"openrouter","enforce_json":true,"json_schema":true,"prefill_json":false}'::jsonb
)
ON CONFLICT (name) DO NOTHING;

INSERT INTO core_tts_connector (
    name,
    connector_type,
    base_url,
    is_default,
    config
) VALUES (
    'Pocket TTS Default',
    'pocket_tts',
    'http://127.0.0.1:8024',
    TRUE,
    '{"language":"en","fallback_male":"male1","fallback_female":"female1","stream_chunk_size":20,"temperature":0.9,"speed":1.0,"length_penalty":1.0,"repetition_penalty":5.0,"top_p":0.85,"top_k":50,"enable_text_splitting":true}'
)
ON CONFLICT (name) DO UPDATE SET
    connector_type = EXCLUDED.connector_type,
    is_default = EXCLUDED.is_default,
    config = EXCLUDED.config;

INSERT INTO core_tts_connector (name, connector_type, base_url, is_default, config) VALUES
('XTTS Default', 'xtts', 'http://127.0.0.1:8020', FALSE, '{"language":"en","fallback_male":"male1","fallback_female":"female1","stream_chunk_size":20,"temperature":0.9,"speed":1.0,"length_penalty":1.0,"repetition_penalty":5.0,"top_p":0.85,"top_k":50,"enable_text_splitting":true}'),
('Chatterbox Default', 'chatterbox', 'http://127.0.0.1:8023', FALSE, '{"language":"en","fallback_male":"male1","fallback_female":"female1","stream_chunk_size":20,"temperature":0.9,"speed":1.0,"length_penalty":1.0,"repetition_penalty":5.0,"top_p":0.85,"top_k":50,"enable_text_splitting":true}'),
('OmniVoice Default', 'omnivoice', 'http://127.0.0.1:8021', FALSE, '{"language":"","fallback_male":"default_male","fallback_female":"default_female","stream_chunk_size":20,"temperature":0.9,"speed":1.0,"length_penalty":1.0,"repetition_penalty":5.0,"top_p":0.85,"top_k":50,"enable_text_splitting":true}'),
('Cartesia Default', 'cartesia', '', FALSE, '{"language":"en","fallback_male":"male1","fallback_female":"female1","model_id":"sonic-3"}'),
('Inworld Default', 'inworld', '', FALSE, '{"language":"EN_US","fallback_male":"male1","fallback_female":"female1","model_id":"inworld-tts-1","workspace":""}')
ON CONFLICT (name) DO NOTHING;

UPDATE core_tts_connector
SET config = jsonb_set(
    jsonb_set(
        CASE
            WHEN config IS NULL OR config = '[]'::jsonb OR jsonb_typeof(config) <> 'object' THEN '{}'::jsonb
            ELSE config
        END,
        '{fallback_male}',
        to_jsonb(
            CASE
                WHEN COALESCE(BTRIM(config->>'fallback_male'), '') <> '' THEN BTRIM(config->>'fallback_male')
                WHEN COALESCE(BTRIM(config->>'voiceid'), '') <> '' THEN BTRIM(config->>'voiceid')
                ELSE 'male1'
            END
        ),
        true
    ),
    '{fallback_female}',
    to_jsonb(
        CASE
            WHEN COALESCE(BTRIM(config->>'fallback_female'), '') <> '' THEN BTRIM(config->>'fallback_female')
            WHEN COALESCE(BTRIM(config->>'voiceid'), '') <> '' THEN BTRIM(config->>'voiceid')
            ELSE 'female1'
        END
    ),
    true
)
WHERE connector_type IN ('pocket_tts', 'xtts', 'chatterbox', 'omnivoice', 'cartesia', 'inworld');

INSERT INTO core_profiles (
    label,
    is_default_npc,
    llm_primary_id,
    llm_secondary_id,
    llm_tertiary_id,
    llm_quaternary_id,
    response_connector,
    diary_connector,
    autochat_connector,
    middleterm_connector,
    backgroundlife_connector,
    dynamic_connector,
    relationship_connector,
    tts_connector_id,
    metadata
) VALUES (
    'Default Profile',
    TRUE,
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash lite' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'glm 5.2' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 pro' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash lite' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'mistral small 3.2 24b' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'mistral small 3.2 24b' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    COALESCE(
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
    ),
    (SELECT id FROM core_tts_connector WHERE LOWER(name) = 'pocket tts default' LIMIT 1),
    '{
        "DYNAMIC_PROFILE_ENABLED": false,
        "MIDDLE_TERM_MEMORY_ENABLED": false,
        "DIARY_DAYS": 1,
        "AUTO_DIARY_MIN_EVENTS": 50,
        "DYNAMIC_PROFILE_FIELDS": [
            "personality",
            "occupation",
            "speechstyle",
            "goals"
        ],
        "RECHAT_RESPONSES": 3,
        "RECHAT_PROBABILITY": 66,
        "DIARY_PROMPT": "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#''s dialogues and events written above into #NPC_NAME#''s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
        "DIARY_COOLDOWN": 120,
        "CONTEXT_HISTORY": 75,
        "CONTEXT_HISTORY_DIARY": 100,
        "CONTEXT_HISTORY_DYNAMIC_PROFILE": 50,
        "BORED_EVENT_CHANCE": 50,
        "RELATIONSHIP_UPDATE_CHANCE": 50
    }'::jsonb
)
ON CONFLICT (label) DO UPDATE SET
    is_default_npc = EXCLUDED.is_default_npc,
    llm_primary_id = COALESCE(core_profiles.llm_primary_id, EXCLUDED.llm_primary_id),
    llm_secondary_id = COALESCE(core_profiles.llm_secondary_id, EXCLUDED.llm_secondary_id),
    llm_tertiary_id = COALESCE(core_profiles.llm_tertiary_id, EXCLUDED.llm_tertiary_id),
    llm_quaternary_id = COALESCE(core_profiles.llm_quaternary_id, EXCLUDED.llm_quaternary_id),
    response_connector = COALESCE(EXCLUDED.response_connector, core_profiles.response_connector),
    diary_connector = COALESCE(EXCLUDED.diary_connector, core_profiles.diary_connector),
    autochat_connector = COALESCE(EXCLUDED.autochat_connector, core_profiles.autochat_connector),
    middleterm_connector = COALESCE(EXCLUDED.middleterm_connector, core_profiles.middleterm_connector),
    backgroundlife_connector = COALESCE(EXCLUDED.backgroundlife_connector, core_profiles.backgroundlife_connector),
    dynamic_connector = COALESCE(EXCLUDED.dynamic_connector, core_profiles.dynamic_connector),
    relationship_connector = COALESCE(EXCLUDED.relationship_connector, core_profiles.relationship_connector),
    tts_connector_id = COALESCE(EXCLUDED.tts_connector_id, core_profiles.tts_connector_id),
    metadata = CASE
        WHEN core_profiles.metadata IS NULL
          OR core_profiles.metadata = '[]'::jsonb
          OR jsonb_typeof(core_profiles.metadata) <> 'object'
        THEN EXCLUDED.metadata
        ELSE EXCLUDED.metadata || core_profiles.metadata
    END,
    updated_at = NOW();

INSERT INTO core_profiles (
    label,
    is_default_npc,
    is_player_faction_profile,
    llm_primary_id,
    llm_secondary_id,
    llm_tertiary_id,
    llm_quaternary_id,
    response_connector,
    diary_connector,
    autochat_connector,
    middleterm_connector,
    backgroundlife_connector,
    dynamic_connector,
    relationship_connector,
    tts_connector_id,
    metadata
)
SELECT
    'Player Faction',
    FALSE,
    TRUE,
    src.llm_primary_id,
    src.llm_secondary_id,
    src.llm_tertiary_id,
    src.llm_quaternary_id,
    src.response_connector,
    src.diary_connector,
    src.autochat_connector,
    src.middleterm_connector,
    src.backgroundlife_connector,
    src.dynamic_connector,
    src.relationship_connector,
    src.tts_connector_id,
      CASE
          WHEN src.metadata IS NULL
            OR src.metadata = '[]'::jsonb
            OR jsonb_typeof(src.metadata) <> 'object'
        THEN '{"DYNAMIC_PROFILE_ENABLED":true,"MIDDLE_TERM_MEMORY_ENABLED":true,"AUTO_DIARY_ENABLED":true}'::jsonb
          ELSE jsonb_set(
             jsonb_set(
                 jsonb_set(src.metadata, '{DYNAMIC_PROFILE_ENABLED}', 'true'::jsonb, true),
                 '{MIDDLE_TERM_MEMORY_ENABLED}',
                  'true'::jsonb,
                  true
             ),
             '{AUTO_DIARY_ENABLED}',
             'true'::jsonb,
             true
          )
      END
FROM core_profiles src
WHERE LOWER(COALESCE(src.label, '')) = 'default profile'
ORDER BY CASE WHEN COALESCE(src.is_default_npc, FALSE) = TRUE THEN 0 ELSE 1 END,
         src.id ASC
LIMIT 1
ON CONFLICT (label) DO UPDATE SET
    is_default_npc = FALSE,
    is_player_faction_profile = TRUE,
    llm_primary_id = COALESCE(core_profiles.llm_primary_id, EXCLUDED.llm_primary_id),
    llm_secondary_id = COALESCE(core_profiles.llm_secondary_id, EXCLUDED.llm_secondary_id),
    llm_tertiary_id = COALESCE(core_profiles.llm_tertiary_id, EXCLUDED.llm_tertiary_id),
    llm_quaternary_id = COALESCE(core_profiles.llm_quaternary_id, EXCLUDED.llm_quaternary_id),
    response_connector = COALESCE(EXCLUDED.response_connector, core_profiles.response_connector),
    diary_connector = COALESCE(EXCLUDED.diary_connector, core_profiles.diary_connector),
    autochat_connector = COALESCE(EXCLUDED.autochat_connector, core_profiles.autochat_connector),
    middleterm_connector = COALESCE(EXCLUDED.middleterm_connector, core_profiles.middleterm_connector),
    backgroundlife_connector = COALESCE(EXCLUDED.backgroundlife_connector, core_profiles.backgroundlife_connector),
    dynamic_connector = COALESCE(EXCLUDED.dynamic_connector, core_profiles.dynamic_connector),
    relationship_connector = COALESCE(EXCLUDED.relationship_connector, core_profiles.relationship_connector),
    tts_connector_id = COALESCE(EXCLUDED.tts_connector_id, core_profiles.tts_connector_id),
      metadata = CASE
          WHEN core_profiles.metadata IS NULL
            OR core_profiles.metadata = '[]'::jsonb
            OR jsonb_typeof(core_profiles.metadata) <> 'object'
          THEN EXCLUDED.metadata
          ELSE jsonb_set(
             jsonb_set(
                 jsonb_set(core_profiles.metadata, '{DYNAMIC_PROFILE_ENABLED}', 'true'::jsonb, true),
                 '{MIDDLE_TERM_MEMORY_ENABLED}',
                  'true'::jsonb,
                  true
             ),
             '{AUTO_DIARY_ENABLED}',
             'true'::jsonb,
             true
          )
      END,
    updated_at = NOW();

UPDATE core_profiles
SET is_default_npc = CASE
    WHEN LOWER(label) = 'default profile' THEN TRUE
    ELSE FALSE
END
WHERE LOWER(label) = 'default profile'
   OR is_default_npc = TRUE;

UPDATE core_profiles
SET is_player_faction_profile = FALSE
WHERE COALESCE(is_player_faction_profile, FALSE) = TRUE
  AND LOWER(COALESCE(label, '')) <> 'player faction';

UPDATE core_profiles
SET is_player_faction_profile = TRUE
WHERE LOWER(COALESCE(label, '')) = 'player faction';

UPDATE core_profiles
SET response_connector = COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
),
diary_connector = COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
),
autochat_connector = COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash lite' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
),
middleterm_connector = COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'mistral small 3.2 24b' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
),
backgroundlife_connector = COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'mistral small 3.2 24b' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
),
dynamic_connector = COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
),
relationship_connector = COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
)
WHERE COALESCE(
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash' LIMIT 1),
    (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
) IS NOT NULL;

UPDATE core_profiles
SET llm_primary_id = COALESCE(
        llm_primary_id,
        response_connector,
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1)
    ),
    llm_secondary_id = COALESCE(
        llm_secondary_id,
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'gemini 2.5 flash lite' LIMIT 1),
        llm_primary_id,
        response_connector
    ),
    llm_tertiary_id = COALESCE(
        llm_tertiary_id,
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'glm 5.2' LIMIT 1),
        llm_primary_id,
        response_connector
    ),
    llm_quaternary_id = COALESCE(
        llm_quaternary_id,
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 pro' LIMIT 1),
        llm_primary_id,
        response_connector
    ),
    response_connector = COALESCE(
        response_connector,
        llm_primary_id,
        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1)
    )
WHERE COALESCE(is_default_npc, FALSE) = TRUE
   OR COALESCE(is_player_faction_profile, FALSE) = TRUE;

UPDATE core_profiles
SET metadata = metadata - 'LLM_RESPONSE_MODE'
WHERE jsonb_typeof(metadata) = 'object'
  AND metadata ? 'LLM_RESPONSE_MODE';

CREATE TABLE IF NOT EXISTS core_narrator (
    id TEXT PRIMARY KEY,
    value TEXT
);






-- Named portable settings presets (also installed by the upgrade migrator).
CREATE TABLE IF NOT EXISTS stobe_settings_presets (
    scope TEXT NOT NULL CHECK (scope IN ('global', 'profile')),
    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    settings JSONB NOT NULL CHECK (jsonb_typeof(settings) = 'object'),
    PRIMARY KEY (scope, name)
);
CREATE UNIQUE INDEX IF NOT EXISTS stobe_settings_presets_name_idx
    ON stobe_settings_presets (scope, lower(name));

