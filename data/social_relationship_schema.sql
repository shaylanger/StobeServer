-- Phase 1 durable social state. No raw gameplay event changes affinity until interpreted.
CREATE TABLE IF NOT EXISTS social_event_inbox (
 campaign_id text NOT NULL, timeline_epoch text NOT NULL, event_id text NOT NULL,
 native_session_id text NOT NULL, sequence bigint NOT NULL, game_ts bigint NOT NULL,
 incident_id text NOT NULL, payload jsonb NOT NULL, payload_hash text NOT NULL,
 status text NOT NULL, mode text NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(campaign_id,timeline_epoch,event_id),
 UNIQUE(campaign_id,timeline_epoch,native_session_id,sequence)
);
CREATE INDEX IF NOT EXISTS social_event_time ON social_event_inbox(campaign_id,game_ts);
CREATE TABLE IF NOT EXISTS social_incident (
 campaign_id text NOT NULL, timeline_epoch text NOT NULL, incident_id text NOT NULL,
 state jsonb NOT NULL DEFAULT '{}', game_ts bigint NOT NULL, last_sequence bigint NOT NULL,
 PRIMARY KEY(campaign_id,timeline_epoch,incident_id)
);
CREATE TABLE IF NOT EXISTS social_belief (
 campaign_id text NOT NULL, timeline_epoch text NOT NULL, incident_id text NOT NULL,
 observer_key text NOT NULL, belief jsonb NOT NULL, game_ts bigint NOT NULL,
 PRIMARY KEY(campaign_id,timeline_epoch,incident_id,observer_key)
);
CREATE TABLE IF NOT EXISTS social_effect (
 campaign_id text NOT NULL, timeline_epoch text NOT NULL, incident_id text NOT NULL,
 observer_key text NOT NULL, culprit_key text NOT NULL, component text NOT NULL,
 game_ts bigint NOT NULL, delta integer NOT NULL CHECK(delta BETWEEN -100 AND 100),
 detail jsonb NOT NULL, applied boolean NOT NULL, rules_version text NOT NULL,
 PRIMARY KEY(campaign_id,timeline_epoch,incident_id,observer_key,culprit_key,component)
);
CREATE INDEX IF NOT EXISTS social_effect_time ON social_effect(campaign_id,game_ts);
CREATE TABLE IF NOT EXISTS social_evidence (
 campaign_id text NOT NULL, timeline_epoch text NOT NULL, observer_key text NOT NULL,
 culprit_key text NOT NULL, evidence jsonb NOT NULL DEFAULT '{}', game_ts bigint NOT NULL,
 PRIMARY KEY(campaign_id,timeline_epoch,observer_key,culprit_key)
);
CREATE TABLE IF NOT EXISTS social_checkpoint (
 campaign_id text NOT NULL, timeline_epoch text NOT NULL, incident_id text NOT NULL,
 event_id text NOT NULL, game_ts bigint NOT NULL, sequence bigint NOT NULL,
 snapshot jsonb NOT NULL,
 PRIMARY KEY(campaign_id,timeline_epoch,event_id)
);
CREATE INDEX IF NOT EXISTS social_checkpoint_time ON social_checkpoint(campaign_id,game_ts,sequence);
INSERT INTO database_versioning(tablename,version)
SELECT unnest(ARRAY['social_event_inbox','social_incident','social_belief','social_effect','social_evidence','social_checkpoint']),202610020001
ON CONFLICT(tablename) DO UPDATE SET version=GREATEST(database_versioning.version,EXCLUDED.version);
