<?php

// Initialize features that did not exist in the saved server version, using this
// game's defaults. The original archive and live settings are never seed sources.
function pts_migrate_prepared_playthrough($conn, string $stage): void {
    if (!preg_match('/^stobe_profile_upgrade_[0-9]+_[0-9]+$/D', $stage)
        || pg_transaction_status($conn) !== PGSQL_TRANSACTION_INTRANS) {
        throw new RuntimeException('Invalid playthrough migration context');
    }
    $result = @pg_query_params($conn, "SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname=$1", [$stage]);
    if (!$result) throw new RuntimeException(pg_last_error($conn));
    $metadata = json_decode(pg_fetch_result($result, 0, 0), true, 32, JSON_THROW_ON_ERROR);
    $schema = pg_escape_identifier($conn, $stage);
    if (in_array('core_tts_pronunciation', $metadata['missing_tables'], true)) {
        require_once __DIR__ . '/tts_pronunciation.php';
        foreach (stobeDefaultTtsPronunciationEntries() as $entry) {
            $result = @pg_query_params($conn,
                "INSERT INTO {$schema}.core_tts_pronunciation(source_text,spoken_text,oghma_tags,is_builtin) VALUES($1,$2,$3,true)",
                [$entry['source_text'], $entry['spoken_text'], $entry['oghma_tags'] ?? '']);
            if (!$result) throw new RuntimeException(pg_last_error($conn));
        }
    }
    $metadata['content_upgraded'] = true;
    if (!@pg_query($conn, 'COMMENT ON SCHEMA ' . $schema . ' IS ' . pg_escape_literal($conn, json_encode($metadata, JSON_THROW_ON_ERROR)))) {
        throw new RuntimeException(pg_last_error($conn));
    }
}
