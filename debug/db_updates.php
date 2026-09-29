<?php

/**
 * StobeServer DB updates (release consolidator).
 * Set STOBE_DB_UPDATES_LEGACY=1 to run the archived pre-release migrator.
 */

$useLegacy = strval(getenv('STOBE_DB_UPDATES_LEGACY') ?: '') === '1';
if ($useLegacy) {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'db_updates_legacy.php');
    return;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'rename_name_pool_functions.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'world_knowledge_aliases.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'world_state_runtime.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'player_mood_prompts.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'tts_pronunciation.php';

if (!function_exists('stobeRunDatabaseUpdates')) {
    function stobeRunDatabaseUpdates(): void
    {
        if (!empty($GLOBALS['__stobe_db_updates_ran'])) {
            return;
        }
        $GLOBALS['__stobe_db_updates_ran'] = true;

        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            stobeLogWarn('DB updates skipped: database handle is missing');
            return;
        }
        if (function_exists('stobeDatabaseEncodingIsSupported') && !stobeDatabaseEncodingIsSupported($db)) {
            $message = function_exists('stobeDatabaseEncodingError')
                ? stobeDatabaseEncodingError($db)
                : 'Stobe database updates require UTF8.';
            stobeLogError('DB updates skipped: unsupported database encoding', ['error' => $message]);
            return;
        }

        $versionSqlPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'database_versioning.sql';
        if (is_file($versionSqlPath)) {
            $sql = file_get_contents($versionSqlPath);
            if (is_string($sql) && trim($sql) !== '') {
                $db->exec($sql);
            }
        }

        $db->exec("DO $$
        BEGIN
            IF EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'database_versioning'
            ) AND NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'database_versioning' AND column_name = 'tablename'
            ) THEN
                ALTER TABLE public.database_versioning RENAME TO database_versioning_legacy;
                CREATE TABLE IF NOT EXISTS public.database_versioning (
                    tablename TEXT PRIMARY KEY,
                    version BIGINT NOT NULL
                );
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = 'database_versioning_legacy' AND column_name = 'version'
                ) THEN
                    INSERT INTO public.database_versioning (tablename, version)
                    SELECT 'schema', COALESCE(MAX(version), 0)::BIGINT
                    FROM public.database_versioning_legacy
                    ON CONFLICT (tablename) DO UPDATE
                    SET version = GREATEST(public.database_versioning.version, EXCLUDED.version);
                END IF;
                DROP TABLE IF EXISTS public.database_versioning_legacy;
            END IF;
        END $$;");

        $checkVersion = static function (string $tableName) use ($db): int {
            $row = $db->fetchOne("SELECT version FROM public.database_versioning WHERE tablename = $1", [$tableName]);
            return $row ? intval($row['version'] ?? -1) : -1;
        };

        $setVersion = static function (string $tableName, int $version) use ($db): void {
            $db->exec(
                "INSERT INTO public.database_versioning (tablename, version)
                 VALUES ($1, $2)
                 ON CONFLICT (tablename) DO UPDATE SET version = EXCLUDED.version",
                [$tableName, $version]
            );
            stobeLogInfo('DB patch version updated', ['tablename' => $tableName, 'version' => $version]);
        };

        $applyPatch = static function (string $tableName, int $version, callable $callback) use ($checkVersion, $setVersion): void {
            if ($checkVersion($tableName) >= $version) {
                return;
            }
            try {
                $callback();
                $setVersion($tableName, $version);
            } catch (Throwable $e) {
                stobeLogException($e, 'DB patch failed', ['tablename' => $tableName, 'version' => $version]);
            }
        };

        $runSqlSeedFile = static function (
            string $seedPath,
            string $missingMessage,
            string $emptyMessage,
            string $normalizedEmptyMessage,
            bool $stripTx = true,
            bool $throwOnFail = false
        ) use ($db): void {
            if (!is_file($seedPath)) {
                stobeLogWarn($missingMessage, ['path' => $seedPath]);
                return;
            }
            $sql = file_get_contents($seedPath);
            if (!is_string($sql) || trim($sql) === '') {
                stobeLogWarn($emptyMessage, ['path' => $seedPath]);
                return;
            }
            // Some generated/imported seed files can carry a UTF-8 BOM.
            // PostgreSQL treats that byte order mark as invalid SQL input.
            $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
            if ($stripTx) {
                $sql = preg_replace('/^\s*BEGIN\s*;\s*/mi', '', $sql) ?? $sql;
                $sql = preg_replace('/\s*COMMIT\s*;\s*$/mi', '', $sql) ?? $sql;
            }
            $sql = trim($sql);
            if ($sql === '') {
                stobeLogWarn($normalizedEmptyMessage, ['path' => $seedPath]);
                return;
            }
            $ok = $db->exec($sql);
            if ($throwOnFail && $ok === false) {
                throw new RuntimeException('Seed SQL execution failed: ' . $seedPath);
            }
        };
        $runBioUniqueSeedBundle = static function (
            string $missingPrefix = 'bio_unique seed file missing',
            string $emptyPrefix = 'bio_unique seed file empty',
            string $normalizedPrefix = 'bio_unique seed file normalized to empty SQL'
        ) use ($runSqlSeedFile): void {
            $seedSpecs = [
                [
                    'filename' => 'kenshi_boss_bio_unique_upsert.sql',
                    'missing' => 'Boss ' . $missingPrefix,
                    'empty' => 'Boss ' . $emptyPrefix,
                    'normalized' => 'Boss ' . $normalizedPrefix,
                ],
                [
                    'filename' => 'kenshi_unique_bio_unique_upsert.sql',
                    'missing' => 'Unique ' . $missingPrefix,
                    'empty' => 'Unique ' . $emptyPrefix,
                    'normalized' => 'Unique ' . $normalizedPrefix,
                ],
                [
                    'filename' => 'kenshi_animals_bio_unique_upsert.sql',
                    'missing' => 'Animals personified ' . $missingPrefix,
                    'empty' => 'Animals personified ' . $emptyPrefix,
                    'normalized' => 'Animals personified ' . $normalizedPrefix,
                ],
            ];
            foreach ($seedSpecs as $spec) {
                $seedPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . $spec['filename'];
                $runSqlSeedFile($seedPath, $spec['missing'], $spec['empty'], $spec['normalized']);
            }
        };
        $importWorldKnowledgeCsv = static function (
            string $seedPath,
            ?array $allowedTopics = null,
            bool $updateExisting = true
        ) use ($db): void {
            if (!is_file($seedPath)) {
                stobeLogWarn('world_knowledge import skipped: seed file missing', ['path' => $seedPath]);
                return;
            }
            $has = $db->fetchOne("SELECT to_regclass('public.world_knowledge') AS rel");
            if (!is_array($has) || trim(strval($has['rel'] ?? '')) === '') {
                stobeLogWarn('world_knowledge import skipped: table not found');
                return;
            }
            $h = @fopen($seedPath, 'r');
            if (!$h) {
                stobeLogWarn('world_knowledge import skipped: cannot open seed file', ['path' => $seedPath]);
                return;
            }
            $header = fgetcsv($h);
            if (!is_array($header) || count($header) === 0) {
                fclose($h);
                stobeLogWarn('world_knowledge import skipped: empty CSV header', ['path' => $seedPath]);
                return;
            }
            $map = [];
            $normalizeHeader = static function ($value): string {
                $key = preg_replace('/^\xEF\xBB\xBF/', '', strval($value));
                $key = strtolower(trim(strval($key)));
                $key = preg_replace('/[^a-z0-9]+/', '_', $key);
                return trim(strval($key), '_');
            };
            foreach ($header as $i => $nameRaw) {
                $name = $normalizeHeader($nameRaw ?? '');
                if ($name !== '') {
                    $map[$name] = intval($i);
                }
            }
            $allowedTopicKeys = null;
            if (is_array($allowedTopics)) {
                $allowedTopicKeys = [];
                foreach ($allowedTopics as $allowedTopic) {
                    $key = strtolower(trim(strval($allowedTopic)));
                    if ($key !== '') {
                        $allowedTopicKeys[$key] = true;
                    }
                }
            }
            $pick = static function (array $row, array $columnMap, array $aliases, int $fallback = -1) use ($normalizeHeader): string {
                foreach ($aliases as $alias) {
                    $k = $normalizeHeader($alias);
                    if ($k !== '' && array_key_exists($k, $columnMap)) {
                        return trim(strval($row[intval($columnMap[$k])] ?? ''));
                    }
                }
                return $fallback >= 0 ? trim(strval($row[$fallback] ?? '')) : '';
            };
            while (($row = fgetcsv($h)) !== false) {
                if (!is_array($row)) {
                    continue;
                }
                if (count(array_filter($row, static function ($value): bool {
                    return trim(strval($value)) !== '';
                })) === 0) {
                    continue;
                }
                $topic = $pick($row, $map, ['topic', 'stringid', 'baseid'], 0);
                $desc = $pick($row, $map, ['topic_desc', 'description'], 1);
                $descBasic = $pick($row, $map, ['topic_desc_basic', 'basic_description'], 2);
                $kClass = $pick($row, $map, ['knowledge_class']);
                $kClassBasic = $pick($row, $map, ['knowledge_class_basic']);
                $aliases = $pick($row, $map, ['aliases']);
                $tags = $pick($row, $map, ['tags', 'category']);
                if ($topic === '' || ($desc === '' && $descBasic === '')) {
                    continue;
                }
                if (is_array($allowedTopicKeys) && !isset($allowedTopicKeys[strtolower($topic)])) {
                    continue;
                }
                $existing = $db->fetchOne("SELECT id FROM world_knowledge WHERE LOWER(topic)=LOWER($1) LIMIT 1", [$topic]);
                $id = intval($existing['id'] ?? 0);
                if ($id > 0) {
                    if (!$updateExisting) {
                        continue;
                    }
                    $db->exec(
                        "UPDATE world_knowledge
                         SET topic=$1, topic_desc=$2, topic_desc_basic=$3,
                             knowledge_class=$4, knowledge_class_basic=$5,
                             aliases=$6, tags=$7
                         WHERE id=$8",
                        [$topic, $desc, $descBasic, $kClass, $kClassBasic, $aliases, $tags, $id]
                    );
                } else {
                    $rowId = $db->fetchOne(
                        "INSERT INTO world_knowledge (
                            topic, topic_desc, topic_desc_basic,
                            knowledge_class, knowledge_class_basic,
                            aliases, tags
                         ) VALUES ($1, $2, $3, $4, $5, $6, $7)
                         RETURNING id",
                        [$topic, $desc, $descBasic, $kClass, $kClassBasic, $aliases, $tags]
                    );
                    $id = intval($rowId['id'] ?? 0);
                }
                if ($id > 0) {
                    stobeWorldKnowledgeUpdateNativeVector($db, $id);
                }
            }
            fclose($h);
        };

        $defaultMetadata = json_encode([
            'DYNAMIC_PROFILE_ENABLED' => false,
            'MIDDLE_TERM_MEMORY_ENABLED' => false,
            'AUTO_DIARY_ENABLED' => false,
            'DIARY_DAYS' => 1,
            'AUTO_DIARY_MIN_EVENTS' => 50,
            'AUTO_DIARY_HOUR' => 21,
            'DYNAMIC_PROFILE_FIELDS' => ['personality', 'occupation', 'speechstyle', 'goals'],
            'RECHAT_RESPONSES' => 3,
            'RECHAT_PROBABILITY' => 66,
            'DIARY_PROMPT' => "Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#'s dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with the current date and time.",
            'DIARY_COOLDOWN' => 120,
            'CONTEXT_HISTORY' => 75,
            'CONTEXT_HISTORY_DIARY' => 100,
            'CONTEXT_HISTORY_DYNAMIC_PROFILE' => 50,
            'BORED_EVENT_CHANCE' => 50,
            'RELATIONSHIP_UPDATE_CHANCE' => 50,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($defaultMetadata) || trim($defaultMetadata) === '') {
            $defaultMetadata = '{}';
        }

        $applyPatch('core_npc_master', 202603130201, static function () use ($db): void {
            $db->exec("DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='core_npc' AND table_type='BASE TABLE')
                   AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='core_npc_master' AND table_type='BASE TABLE') THEN
                    ALTER TABLE core_npc RENAME TO core_npc_master;
                END IF;
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='core_npc_master' AND table_type='BASE TABLE') THEN
                    EXECUTE 'CREATE OR REPLACE VIEW core_npc AS SELECT * FROM core_npc_master';
                END IF;
            END $$;");

            $db->exec("ALTER TABLE core_npc_master ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT ''");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS bounty JSONB DEFAULT '{}'::jsonb");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT ''");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS snapshot_reason VARCHAR(64) DEFAULT 'snapshot'");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS snapshot_hash TEXT DEFAULT ''");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS source_created_at TIMESTAMP");
            $db->exec("ALTER TABLE core_npc_master_history ADD COLUMN IF NOT EXISTS source_updated_at TIMESTAMP");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_npc_id ON core_npc_master_history (npc_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_created ON core_npc_master_history (created DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_gamets ON core_npc_master_history (gamets_last_updated DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_bounty ON core_npc_master ((CASE WHEN COALESCE(bounty->>'total','') ~ '^[0-9]+$' THEN (bounty->>'total')::BIGINT ELSE 0 END) DESC)");

            $db->exec("CREATE OR REPLACE FUNCTION core_npc_master_history_audit_fn()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE src RECORD; reason TEXT;
            BEGIN
                IF TG_OP='DELETE' THEN src := OLD; reason := 'delete';
                ELSIF TG_OP='INSERT' THEN src := NEW; reason := 'create';
                ELSE src := NEW; reason := 'snapshot';
                END IF;
                INSERT INTO core_npc_master_history (
                    npc_id, name, original_name, npc_favorite, lock_profile,
                    prompt_head, personality, backstory, emote_moods, occupation,
                    appearance, equipment, inventory, skills, speechstyle,
                    goals, relationships, voiceid, metadata, race, faction, gender,
                    profile_id, dynamic_profile, extended_data, md5, gamets_last_updated,
                    bounty, limbs, blood, hunger, tags, is_animal, is_slave,
                    world_knowledge_tags,
                    snapshot_reason, snapshot_hash, source_created_at, source_updated_at, created
                ) VALUES (
                    COALESCE(src.id,0), COALESCE(src.name,''), COALESCE(src.original_name,''),
                    COALESCE(src.npc_favorite,FALSE), COALESCE(src.lock_profile,FALSE),
                    COALESCE(src.prompt_head,''), COALESCE(src.personality,''), COALESCE(src.backstory,''), COALESCE(src.emote_moods,''), COALESCE(src.occupation,''),
                    COALESCE(src.appearance,''), COALESCE(src.equipment,''), COALESCE(src.inventory,''), COALESCE(src.skills,''), COALESCE(src.speechstyle,''),
                    COALESCE(src.goals,''), COALESCE(src.relationships,''), COALESCE(src.voiceid,''), COALESCE(src.metadata,'{}'::jsonb), COALESCE(src.race,''), COALESCE(src.faction,''), COALESCE(src.gender,''),
                    src.profile_id, FALSE, COALESCE(src.extended_data,'{}'::jsonb), COALESCE(src.md5,''), COALESCE(src.gamets_last_updated,0),
                    COALESCE(src.bounty,'{}'::jsonb), COALESCE(src.limbs,'{}'::jsonb), COALESCE(src.blood,'0/0'), COALESCE(src.hunger,'300/300'), COALESCE(src.tags,''), COALESCE(src.is_animal,FALSE), COALESCE(src.is_slave,FALSE),
                    COALESCE(src.world_knowledge_tags,''),
                    reason, md5(COALESCE(to_jsonb(src)::text,'')), src.created_at, src.updated_at, NOW()
                );
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;");
            $db->exec("DROP TRIGGER IF EXISTS trg_core_npc_master_history_audit ON core_npc_master");
            $db->exec("CREATE TRIGGER trg_core_npc_master_history_audit AFTER INSERT OR UPDATE OR DELETE ON core_npc_master FOR EACH ROW EXECUTE FUNCTION core_npc_master_history_audit_fn()");
        });
        $applyPatch('world_knowledge', 202603130202, static function () use ($db): void {
            $db->exec("DO $$
            BEGIN
                IF to_regclass('public.world_knowledge') IS NULL AND to_regclass('public.oghma') IS NOT NULL THEN
                    ALTER TABLE oghma RENAME TO world_knowledge;
                END IF;
            END $$;");
            $db->exec("CREATE TABLE IF NOT EXISTS world_knowledge (id SERIAL PRIMARY KEY, topic VARCHAR(255) NOT NULL, topic_desc TEXT NOT NULL, topic_desc_basic TEXT DEFAULT '', knowledge_class TEXT DEFAULT '', knowledge_class_basic TEXT DEFAULT '', aliases TEXT DEFAULT '', tags TEXT DEFAULT '', native_vector TSVECTOR)");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS topic_desc_basic TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS knowledge_class TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS knowledge_class_basic TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS aliases TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS tags TEXT DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ADD COLUMN IF NOT EXISTS native_vector TSVECTOR");
            $db->exec("ALTER TABLE world_knowledge DROP COLUMN IF EXISTS category");
            $db->exec("DROP INDEX IF EXISTS idx_world_knowledge_category_lower");
            $db->exec("DROP INDEX IF EXISTS idx_oghma_category_lower");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_knowledge_topic_lower ON world_knowledge (LOWER(topic))");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_knowledge_native_vector_gin ON world_knowledge USING GIN (native_vector)");
            $db->exec("ALTER TABLE world_knowledge ALTER COLUMN knowledge_class SET DEFAULT ''");
            $db->exec("ALTER TABLE world_knowledge ALTER COLUMN knowledge_class_basic SET DEFAULT ''");
            $db->exec("UPDATE world_knowledge SET knowledge_class = '' WHERE LOWER(BTRIM(COALESCE(knowledge_class,'')))='knowall'");
            $db->exec("UPDATE world_knowledge SET knowledge_class_basic = '' WHERE LOWER(BTRIM(COALESCE(knowledge_class_basic,'')))='knowall'");
        });

        $applyPatch('general_settings', 202603130203, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('WORLD_KNOWLEDGE_ENABLED','true','Enable world knowledge retrieval',NOW()),
                ('WORLD_KNOWLEDGE_AMOUNT','2','Max extracted world knowledge topics per turn',NOW()),
                ('WORLD_KNOWLEDGE_CONTEXT_HISTORY','16','Recent event rows used for world knowledge keyword context',NOW()),
                ('WORLD_KNOWLEDGE_CONTEXT_KEYWORDS','8','Max world knowledge context keywords',NOW()),
                ('WORLD_KNOWLEDGE_MIN_RANK','3.30','Minimum combined rank for world knowledge hints (Herika-aligned threshold)',NOW()),
                ('SPEAKER_RECHAT','false','When true, the initiating player speaker may be selected in rechat; when false, they are excluded.',NOW()),
                ('STOBE_QUICKSTART_COMPLETED','false','When false, first dashboard visit redirects to the quickstart menu.',NOW())
                ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value, description=EXCLUDED.description, updated_at=NOW()");
            $db->exec("DELETE FROM general_settings WHERE id IN ('OGHMA_ENABLED','OGHMA_AMOUNT','OGHMA_CONTEXT_HISTORY','OGHMA_CONTEXT_KEYWORDS','OGHMA_MIN_RANK')");
        });
        $applyPatch('general_settings', 202603130211, static function () use ($db): void {
            $db->exec("DELETE FROM general_settings WHERE id IN ('BORED_EVENT_RANGE','BORED_EVENT_ENABLED','BORED_EVENT_INTERVAL','BORED_EVENT_NPC_LIMIT')");
        });
        $applyPatch('general_settings', 202603130212, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('WORLD_KNOWLEDGE_MIN_RANK','3.30','Minimum combined rank for world knowledge hints (Herika-aligned threshold)',NOW())
                ON CONFLICT (id) DO UPDATE
                SET value = CASE
                        WHEN BTRIM(COALESCE(general_settings.value,'')) IN ('', '0.10', '0.1')
                            THEN EXCLUDED.value
                        ELSE general_settings.value
                    END,
                    description = EXCLUDED.description,
                    updated_at = NOW()");
        });
        $applyPatch('general_settings', 202603130213, static function () use ($db): void {
            $db->exec("DELETE FROM general_settings WHERE id = 'WORLD_KNOWLEDGE_HEURISTIC_ENABLED'");
        });
        $applyPatch('general_settings', 202603190001, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('INDIVIDUAL_MEMORY_SUMMARY_THRESHOLD','2','How many global memory summaries involving an NPC are required before creating one NPC-scoped summary',NOW())
                ON CONFLICT (id) DO NOTHING");
        });
        $applyPatch('general_settings', 202603190002, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('AUTO_LOCK_PROFILE','true','When true, saving an NPC profile automatically locks it to prevent rollback/history overwrite updates.',NOW())
                ON CONFLICT (id) DO UPDATE
                SET description = EXCLUDED.description,
                    updated_at = NOW()");
        });
        $applyPatch('general_settings', 202603190003, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('RELATIONSHIP_SYSTEM_ENABLED','true','Enable relationship system analysis and updates for NPC interactions.',NOW())
                ON CONFLICT (id) DO UPDATE
                SET description = EXCLUDED.description,
                    updated_at = NOW()");
        });
        $applyPatch('short_term_memory_settings', 202608280501, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES ('SHORT_TERM_MEMORY_IN_COMPACT_CHAT', 'true',
                    'Include short-term memory for enabled NPCs when Compact Chat History is on. Plain chat is unaffected.', NOW())
                 ON CONFLICT (id) DO NOTHING"
            );
        });
        $applyPatch('core_narrator', 202603250301, static function () use ($db): void {
            $db->exec("CREATE TABLE IF NOT EXISTS core_narrator (
                id TEXT PRIMARY KEY,
                value TEXT
            )");

            $defaultProfileId = 1;
            $profileRow = $db->fetchOne(
                "SELECT id
                 FROM core_profiles
                 WHERE is_default_npc = TRUE
                 ORDER BY id ASC
                 LIMIT 1"
            );
            if (is_array($profileRow)) {
                $resolved = intval($profileRow['id'] ?? 0);
                if ($resolved > 0) {
                    $defaultProfileId = $resolved;
                }
            }

            $defaults = [
                'enabled' => '1',
                'welcome_enabled' => '0',
                'welcome_cooldown' => '10',
                'random_enabled' => '0',
                'random_chance' => '15',
                'random_cooldown' => '10',
                'dynamic_profile' => '0',
                'dynamic_profile_fields' => '[]',
                'profile_id' => strval($defaultProfileId),
                'voiceid' => 'stobenarrator',
                'core' => "The Narrator is a male voice within the player's mind. His job is to help the player as they navigate the world of Tamriel. Provide unique insight and descriptions of what is going on in the world.",
                'background' => "A guiding voice that describes the world, events, and transitions. He is not a character, but a voice within the player's mind.",
                'personality' => 'Laid-back, observant, and friendly; describes scenes with calm confidence.',
                'speechstyle' => 'Relaxed and conversational, with vivid scene descriptions in one or two concise sentences.',
                'goals' => '',
                'oghma_knowledge' => 'knowall',
                'gender' => 'male',
                'prompt_head' => '',
            ];

            foreach ($defaults as $key => $value) {
                $existing = $db->fetchOne(
                    "SELECT value FROM core_narrator WHERE id = $1 LIMIT 1",
                    [$key]
                );
                $currentValue = is_array($existing) && array_key_exists('value', $existing)
                    ? trim(strval($existing['value']))
                    : '';
                if ($currentValue !== '') {
                    continue;
                }
                $db->exec(
                    "INSERT INTO core_narrator (id, value)
                     VALUES ($1, $2)
                     ON CONFLICT (id) DO UPDATE
                     SET value = EXCLUDED.value",
                    [$key, strval($value)]
                );
            }
        });
        $applyPatch('core_narrator', 202603250405, static function () use ($db): void {
            $db->exec("DELETE FROM core_narrator WHERE id IN ('diary_enabled', 'diary_connector_id')");
        });
        $applyPatch('core_narrator', 202603250406, static function () use ($db): void {
            $newVoiceId = 'stobenarrator';
            $newPersonality = 'Laid-back, observant, and friendly; describes scenes with calm confidence.';
            $newSpeechStyle = 'Relaxed and conversational, with vivid scene descriptions in one or two concise sentences.';
            $normalize = static function (string $value): string {
                return strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
            };

            $voiceIdRow = $db->fetchOne("SELECT value FROM core_narrator WHERE id = 'voiceid' LIMIT 1");
            $currentVoiceId = is_array($voiceIdRow) && array_key_exists('value', $voiceIdRow)
                ? trim(strval($voiceIdRow['value']))
                : '';
            if (
                $currentVoiceId === ''
                || $normalize($currentVoiceId) === $normalize('TheNarrator')
            ) {
                $db->exec(
                    "INSERT INTO core_narrator (id, value)
                     VALUES ('voiceid', $1)
                     ON CONFLICT (id) DO UPDATE
                     SET value = EXCLUDED.value",
                    [$newVoiceId]
                );
            }

            $personalityRow = $db->fetchOne("SELECT value FROM core_narrator WHERE id = 'personality' LIMIT 1");
            $currentPersonality = is_array($personalityRow) && array_key_exists('value', $personalityRow)
                ? trim(strval($personalityRow['value']))
                : '';
            if (
                $currentPersonality === ''
                || $normalize($currentPersonality) === $normalize('Detached, descriptive, witty, helpful.')
            ) {
                $db->exec(
                    "INSERT INTO core_narrator (id, value)
                     VALUES ('personality', $1)
                     ON CONFLICT (id) DO UPDATE
                     SET value = EXCLUDED.value",
                    [$newPersonality]
                );
            }

            $speechStyleRow = $db->fetchOne("SELECT value FROM core_narrator WHERE id = 'speechstyle' LIMIT 1");
            $currentSpeechStyle = is_array($speechStyleRow) && array_key_exists('value', $speechStyleRow)
                ? trim(strval($speechStyleRow['value']))
                : '';
            if (
                $currentSpeechStyle === ''
                || $normalize($currentSpeechStyle) === $normalize('Direct and practical.')
                || $normalize($currentSpeechStyle) === $normalize('Detached, descriptive, witty, helpful.')
            ) {
                $db->exec(
                    "INSERT INTO core_narrator (id, value)
                     VALUES ('speechstyle', $1)
                     ON CONFLICT (id) DO UPDATE
                     SET value = EXCLUDED.value",
                    [$newSpeechStyle]
                );
            }
        });
        $applyPatch('core_narrator', 202603250407, static function () use ($db): void {
            $newVoiceId = 'stobenarrator';
            $voiceIdRow = $db->fetchOne("SELECT value FROM core_narrator WHERE id = 'voiceid' LIMIT 1");
            $currentVoiceId = is_array($voiceIdRow) && array_key_exists('value', $voiceIdRow)
                ? trim(strval($voiceIdRow['value']))
                : '';
            $normalize = static function (string $value): string {
                return strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
            };
            if (
                $currentVoiceId === ''
                || $normalize($currentVoiceId) === $normalize('TheNarrator')
            ) {
                $db->exec(
                    "INSERT INTO core_narrator (id, value)
                     VALUES ('voiceid', $1)
                     ON CONFLICT (id) DO UPDATE
                     SET value = EXCLUDED.value",
                    [$newVoiceId]
                );
            }
        });
        $applyPatch('core_narrator', 202607280001, static function () use ($db): void {
            $defaults = [
                'roleplay_name' => 'The Narrator',
                'diary_enabled' => '0',
                'auto_diary_enabled' => '0',
                'only_diary_access' => '0',
                'inline_narration_mode' => 'disabled',
                'preserve_inline_narration_context' => '0',
            ];
            foreach ($defaults as $key => $value) {
                $db->exec(
                    "INSERT INTO core_narrator (id, value)
                     VALUES ($1, $2)
                     ON CONFLICT (id) DO NOTHING",
                    [$key, $value]
                );
            }

            $oldCore = "The Narrator is a male voice within the player's mind. His job is to help the player as they navigate the world of Tamriel. Provide unique insight and descriptions of what is going on in the world.";
            $newCore = "The Narrator is a male voice within the player's mind. His job is to help the player as they navigate the world of Kenshi. Provide unique insight and descriptions of what is going on in the world.";
            $db->exec(
                "UPDATE core_narrator
                 SET value = $1
                 WHERE id = 'core' AND value = $2",
                [$newCore, $oldCore]
            );
        });
        $applyPatch('prompts', 202603130214, static function () use ($db): void {
            $analysisPrompt = <<<'PROMPT'
You are a relationship analyzer for Kenshi NPCs. Analyze relationship descriptions and output JSON.

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
{"relationships": {"Target": {"aff": 50, "type": "professional", "note": "works together"}}}
PROMPT;
            $evalPrompt = <<<'PROMPT'
You are a behavioral psychologist. Evaluate interactions and provide brief insight.

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

No changes? Return: {"changes": {}}
PROMPT;

            $db->exec(
                "UPDATE prompts
                 SET default_prompt = $1,
                     description = 'System prompt for relationship LLM analysis. Used in ext/relationship_system/relationship_llm.php.',
                     updated_at = NOW()
                 WHERE prompt_key = 'rel_llm_analysis'",
                [$analysisPrompt]
            );
            $db->exec(
                "UPDATE prompts
                 SET default_prompt = $1,
                     description = 'System prompt for relationship conversation evaluation. Used in ext/relationship_system/relationship_llm.php.',
                     updated_at = NOW()
                 WHERE prompt_key = 'rel_llm_evaluation'",
                [$evalPrompt]
            );
        });

        $applyPatch('core_action', 202603130204, static function () use ($db): void {
            $desc = 'Describe a roleplay action along with your dialogue.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('ROLEPLAY_ACTION','RoleplayAction',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, updated_at=NOW()", [$desc]);
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('TRAVEL_LOCATION','TravelLocation','Travel to a previously visited location by name.',TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, updated_at=NOW()");
            $db->exec("DELETE FROM core_action WHERE UPPER(COALESCE(command,'')) IN ('NOTIFY','RELEASE_PLAYER','RELEASE_PRISONER','RELEASEPLAYER')");
            $db->exec("UPDATE core_action_custom SET command='ROLEPLAY_ACTION', action_name='RoleplayAction', description=$1, updated_at=NOW() WHERE UPPER(COALESCE(command,''))='NOTIFY'", [$desc]);
            $db->exec("DELETE FROM core_action_custom WHERE UPPER(COALESCE(command,'')) IN ('RELEASE_PLAYER','RELEASE_PRISONER','RELEASEPLAYER')");
        });
        $applyPatch('core_action', 202603140205, static function () use ($db): void {
            $desc = 'Remove one limb from a helpless target. Requires a hacksaw in inventory. Use target and item as LEFT_ARM, RIGHT_ARM, LEFT_LEG, or RIGHT_LEG. Works only on knocked-out, unconscious, imprisoned, or carried targets.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('REMOVE_LIMB','RemoveLimb',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202604120001, static function () use ($db): void {
            $desc = "Cut off a helpless Shek target's horns with a hacksaw. Use target as the victim. Works only on dead, knocked-out, unconscious, imprisoned, or carried Shek whose horns are not already cut off.";
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('CUT_HORNS','CutHorns',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202603140206, static function () use ($db): void {
            $desc = 'Consume Hashish from your inventory/equipment. Applies a high state for 5 in-game hours and increases hunger drain to 1.5x during that time. Put the item name in target or message field.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('USE_DRUGS','UseDrugs',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202603140207, static function () use ($db): void {
            $desc = 'Consume Bloodrum, Cactus Rum, Grog, or Sake from your inventory/equipment. Applies drunk effects and can escalate to knockout. Put the item name in target or message field.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('DRINK','Drink',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202603310001, static function () use ($db): void {
            $desc = 'Force a helpless target to drink Bloodrum, Cactus Rum, Grog, or Sake from your inventory/equipment. Use target as the victim and item/message as the drink name. Defaults to Cactus Rum.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('FORCE_DRINK','ForceDrink',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202604010001, static function () use ($db): void {
            $desc = 'Take one or more items. Use target to take from a nearby helpless actor (dead, knocked out, unconscious, imprisoned, or carried), or omit target to take from the player. Item supports quantities and lists like GiveItem, plus equipment/all loot queries.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('TAKE_ITEM','TakeItem',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
            $db->exec("UPDATE core_action_custom
                SET action_name='TakeItem', description=$1, updated_at=NOW()
                WHERE UPPER(COALESCE(command,''))='TAKE_ITEM'
                  AND (
                    COALESCE(description,'') = ''
                    OR description ILIKE '%Take a specific item from the player.%'
                    OR description ILIKE '%Take one or more items.%'
                  )", [$desc]);
        });
        $applyPatch('core_action', 202604010002, static function () use ($db): void {
            $desc = 'Pick up a nearby helpless target and carry them. Use target as the actor name. Only valid when you are not already carrying someone.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('PICKUP_NPC','PickupNpc',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202603140208, static function () use ($db): void {
            $desc = 'Kill a helpless target immediately.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('KILL','Kill',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202604200001, static function () use ($db): void {
            $desc = 'Knock out a target immediately without killing them. Self-targeting is allowed; otherwise the target must already be helpless.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('KNOCKOUT','Knockout',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_action', 202604200006, static function () use ($db): void {
            $descriptions = [
                'GIVE_CATS' => 'Give cats to the target. Put the recipient in target and the numeric amount in amount.',
                'TAKE_CATS' => 'Take cats from the target. Put the victim in target and the numeric amount in amount.',
                'TAKE_ITEM' => 'Take one or more items. Use target to take from a nearby helpless actor (dead, knocked out, unconscious, imprisoned, or carried), or omit target to take from the player. Put the item name in item and an optional stack count in amount. Equipment/all loot queries are still supported in item.',
                'GIVE_ITEM' => 'Give a specific item to the target. Put the recipient in target, the exact item name in item, and an optional stack count in amount.',
            ];
            foreach ($descriptions as $command => $desc) {
                $actionName = match ($command) {
                    'GIVE_CATS' => 'GiveCats',
                    'TAKE_CATS' => 'TakeCats',
                    'TAKE_ITEM' => 'TakeItem',
                    'GIVE_ITEM' => 'GiveItem',
                    default => $command,
                };
                $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                    VALUES ($1,$2,$3,TRUE,NOW())
                    ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$command, $actionName, $desc]);
            }
        });
        $applyPatch('core_action', 202603300001, static function () use ($db): void {
            $desc = 'Attack with intention to kill a named actor in scene. Use target name. If you attack someone in your same faction, you will be made an enemy of that faction.';
            $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('ATTACK','Attack',$1,TRUE,NOW())
                ON CONFLICT (command) DO UPDATE SET action_name=EXCLUDED.action_name, description=EXCLUDED.description, is_activated=EXCLUDED.is_activated, updated_at=NOW()", [$desc]);
        });
        $applyPatch('core_npc_master', 202603140211, static function () use ($db): void {
            $db->exec("DO $$
            DECLARE
                schema_name TEXT;
                source_schema TEXT;
                col_list TEXT;
            BEGIN
                FOR schema_name IN
                    SELECT t.table_schema
                    FROM information_schema.tables t
                    WHERE t.table_name='core_npc_master' AND t.table_type='BASE TABLE'
                LOOP
                    EXECUTE format(
                        'ALTER TABLE %I.core_npc_master ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT %L',
                        schema_name,
                        ''
                    );
                END LOOP;

                FOR schema_name IN
                    SELECT t.table_schema
                    FROM information_schema.tables t
                    WHERE t.table_name='core_npc_master_history' AND t.table_type='BASE TABLE'
                LOOP
                    EXECUTE format(
                        'ALTER TABLE %I.core_npc_master_history ADD COLUMN IF NOT EXISTS world_knowledge_tags TEXT DEFAULT %L',
                        schema_name,
                        ''
                    );
                END LOOP;

                FOR schema_name IN
                    SELECT v.table_schema
                    FROM information_schema.views v
                    WHERE v.table_name='core_npc'
                LOOP
                    source_schema := schema_name;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM information_schema.tables t
                        WHERE t.table_schema = schema_name
                          AND t.table_name = 'core_npc_master'
                          AND t.table_type = 'BASE TABLE'
                    ) THEN
                        source_schema := 'public';
                    END IF;

                    SELECT string_agg(format('%I', c.column_name), ', ' ORDER BY c.ordinal_position)
                    INTO col_list
                    FROM information_schema.columns c
                    WHERE c.table_schema = source_schema
                      AND c.table_name = 'core_npc_master'
                      AND c.column_name <> 'knowledge_tags';

                    IF COALESCE(col_list, '') <> '' THEN
                        EXECUTE format('DROP VIEW IF EXISTS %I.core_npc', schema_name);
                        EXECUTE format(
                            'CREATE VIEW %I.core_npc AS SELECT %s FROM %I.core_npc_master',
                            schema_name,
                            col_list,
                            source_schema
                        );
                    END IF;
                END LOOP;

                FOR schema_name IN
                    SELECT c.table_schema
                    FROM information_schema.columns c
                    WHERE c.table_name='core_npc_master' AND c.column_name='knowledge_tags'
                LOOP
                    EXECUTE format(
                        'UPDATE %I.core_npc_master SET world_knowledge_tags = COALESCE(NULLIF(world_knowledge_tags, %L), NULLIF(knowledge_tags, %L), %L)',
                        schema_name,
                        '',
                        '',
                        ''
                    );
                    EXECUTE format('ALTER TABLE %I.core_npc_master DROP COLUMN knowledge_tags', schema_name);
                END LOOP;

                FOR schema_name IN
                    SELECT c.table_schema
                    FROM information_schema.columns c
                    WHERE c.table_name='core_npc_master_history' AND c.column_name='knowledge_tags'
                LOOP
                    EXECUTE format(
                        'UPDATE %I.core_npc_master_history SET world_knowledge_tags = COALESCE(NULLIF(world_knowledge_tags, %L), NULLIF(knowledge_tags, %L), %L)',
                        schema_name,
                        '',
                        '',
                        ''
                    );
                    EXECUTE format('ALTER TABLE %I.core_npc_master_history DROP COLUMN knowledge_tags', schema_name);
                END LOOP;
            END $$;");
            $db->exec("CREATE OR REPLACE FUNCTION core_npc_master_history_audit_fn()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE src RECORD; reason TEXT;
            BEGIN
                IF TG_OP='DELETE' THEN src := OLD; reason := 'delete';
                ELSIF TG_OP='INSERT' THEN src := NEW; reason := 'create';
                ELSE src := NEW; reason := 'snapshot';
                END IF;
                INSERT INTO core_npc_master_history (
                    npc_id, name, original_name, npc_favorite, lock_profile,
                    prompt_head, personality, backstory, emote_moods, occupation,
                    appearance, equipment, inventory, skills, speechstyle,
                    goals, relationships, voiceid, metadata, race, faction, gender,
                    profile_id, dynamic_profile, extended_data, md5, gamets_last_updated,
                    bounty, limbs, blood, hunger, tags, is_animal, is_slave,
                    world_knowledge_tags,
                    snapshot_reason, snapshot_hash, source_created_at, source_updated_at, created
                ) VALUES (
                    COALESCE(src.id,0), COALESCE(src.name,''), COALESCE(src.original_name,''),
                    COALESCE(src.npc_favorite,FALSE), COALESCE(src.lock_profile,FALSE),
                    COALESCE(src.prompt_head,''), COALESCE(src.personality,''), COALESCE(src.backstory,''), COALESCE(src.emote_moods,''), COALESCE(src.occupation,''),
                    COALESCE(src.appearance,''), COALESCE(src.equipment,''), COALESCE(src.inventory,''), COALESCE(src.skills,''), COALESCE(src.speechstyle,''),
                    COALESCE(src.goals,''), COALESCE(src.relationships,''), COALESCE(src.voiceid,''), COALESCE(src.metadata,'{}'::jsonb), COALESCE(src.race,''), COALESCE(src.faction,''), COALESCE(src.gender,''),
                    src.profile_id, FALSE, COALESCE(src.extended_data,'{}'::jsonb), COALESCE(src.md5,''), COALESCE(src.gamets_last_updated,0),
                    COALESCE(src.bounty,'{}'::jsonb), COALESCE(src.limbs,'{}'::jsonb), COALESCE(src.blood,'0/0'), COALESCE(src.hunger,'300/300'), COALESCE(src.tags,''), COALESCE(src.is_animal,FALSE), COALESCE(src.is_slave,FALSE),
                    COALESCE(src.world_knowledge_tags,''),
                    reason, md5(COALESCE(to_jsonb(src)::text,'')), src.created_at, src.updated_at, NOW()
                );
                IF TG_OP='DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;");
            $db->exec("DROP TRIGGER IF EXISTS trg_core_npc_master_history_audit ON core_npc_master");
            $db->exec("CREATE TRIGGER trg_core_npc_master_history_audit AFTER INSERT OR UPDATE OR DELETE ON core_npc_master FOR EACH ROW EXECUTE FUNCTION core_npc_master_history_audit_fn()");
        });

        $applyPatch('core_profiles', 202603130210, static function () use ($db, $defaultMetadata): void {
            $db->exec("INSERT INTO core_api_badge (label, api_key) VALUES ('Player2','019cf504-1461-74e7-b4da-045b14e9019d') ON CONFLICT (label) DO NOTHING");
            $badge = $db->fetchOne("SELECT id FROM core_api_badge WHERE LOWER(label) IN ('player2','stobe') ORDER BY CASE WHEN LOWER(label)='player2' THEN 0 ELSE 1 END, id ASC LIMIT 1");
            $badgeId = intval($badge['id'] ?? 0);
            $db->exec("INSERT INTO core_llm_connector (name, connector_type, api_badge_id, api_key, base_url, model, max_tokens, temperature, is_default, config)
                VALUES ('Player2 Local','player2json',$1,'','http://127.0.0.1:4315/v1/chat/completions','player2-app-selected',750,1.0,FALSE,'{\"player2_game_key\":\"019cf504-1461-74e7-b4da-045b14e9019d\"}'::jsonb)
                ON CONFLICT (name) DO UPDATE SET connector_type=EXCLUDED.connector_type, api_badge_id=COALESCE(core_llm_connector.api_badge_id,EXCLUDED.api_badge_id)", [$badgeId > 0 ? $badgeId : null]);
            $std = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='gemini 2.5 flash' LIMIT 1");
            $stdId = intval($std['id'] ?? 0);
            if ($stdId <= 0) {
                $fallback = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='openrouter default' LIMIT 1");
                $stdId = intval($fallback['id'] ?? 0);
            }
            $lite = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='gemini 2.5 flash lite' LIMIT 1");
            $liteId = intval($lite['id'] ?? 0);
            if ($liteId <= 0) {
                $liteId = $stdId;
            }
            $memory = $db->fetchOne("SELECT id FROM core_llm_connector WHERE LOWER(name)='mistral small 3.2 24b' LIMIT 1");
            $memoryId = intval($memory['id'] ?? 0);
            if ($memoryId <= 0) {
                $memoryId = $stdId;
            }
            $responseConnectorId = $stdId > 0 ? $stdId : null;
            $diaryConnectorId = $responseConnectorId;
            $autochatConnectorId = $liteId > 0 ? $liteId : $responseConnectorId;
            $middletermConnectorId = $memoryId > 0 ? $memoryId : $responseConnectorId;
            $backgroundlifeConnectorId = $middletermConnectorId;
            $dynamicConnectorId = $responseConnectorId;
            $relationshipConnectorId = $responseConnectorId;
            $tts = $db->fetchOne("SELECT id FROM core_tts_connector WHERE LOWER(name)='pocket tts default' LIMIT 1");
            $ttsId = intval($tts['id'] ?? 0);
            $row = $db->fetchOne(
                "INSERT INTO core_profiles (label, is_default_npc, response_connector, diary_connector, autochat_connector, middleterm_connector, backgroundlife_connector, dynamic_connector, relationship_connector, tts_connector_id, metadata)
                 VALUES ('Default Profile', TRUE, $1, $2, $3, $4, $5, $6, $7, $8, $9::jsonb)
                 ON CONFLICT (label) DO UPDATE SET is_default_npc=TRUE, metadata = CASE WHEN core_profiles.metadata IS NULL OR core_profiles.metadata='[]'::jsonb OR jsonb_typeof(core_profiles.metadata) <> 'object' THEN EXCLUDED.metadata ELSE EXCLUDED.metadata || core_profiles.metadata END, updated_at=NOW()
                 RETURNING id",
                [
                    $responseConnectorId,
                    $diaryConnectorId,
                    $autochatConnectorId,
                    $middletermConnectorId,
                    $backgroundlifeConnectorId,
                    $dynamicConnectorId,
                    $relationshipConnectorId,
                    $ttsId > 0 ? $ttsId : null,
                    $defaultMetadata
                ]
            );
            $pid = intval($row['id'] ?? 0);
            if ($pid > 0) {
                $db->exec("UPDATE core_profiles SET is_default_npc=FALSE WHERE id<>$1 AND COALESCE(is_default_npc,FALSE)=TRUE", [$pid]);
                $db->exec("UPDATE core_npc_master SET profile_id=$1 WHERE profile_id IS NULL", [$pid]);
            }
            if (
                $responseConnectorId !== null
                || $autochatConnectorId !== null
                || $middletermConnectorId !== null
            ) {
                $db->exec(
                    "UPDATE core_profiles
                     SET response_connector=$1,
                         diary_connector=$2,
                         autochat_connector=$3,
                         middleterm_connector=$4,
                         backgroundlife_connector=$5,
                         dynamic_connector=$6,
                         relationship_connector=$7
                     WHERE COALESCE(is_default_npc,FALSE)=TRUE
                        OR LOWER(COALESCE(label,''))='default profile'",
                    [
                        $responseConnectorId,
                        $diaryConnectorId,
                        $autochatConnectorId,
                        $middletermConnectorId,
                        $backgroundlifeConnectorId,
                        $dynamicConnectorId,
                        $relationshipConnectorId
                    ]
                );
            }
        });
        $applyPatch('core_tts_connector', 202605101600, static function () use ($db): void {
            $db->exec("UPDATE core_tts_connector
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
                       WHERE connector_type IN ('pocket_tts', 'xtts', 'chatterbox', 'omnivoice', 'cartesia', 'inworld')");
        });
        $applyPatch('core_tts_connector', 202605101610, static function () use ($db): void {
            $db->exec("UPDATE core_tts_connector
                       SET config = jsonb_set(
                           jsonb_set(
                               CASE
                                   WHEN config IS NULL OR config = '[]'::jsonb OR jsonb_typeof(config) <> 'object' THEN '{}'::jsonb
                                   ELSE config
                               END,
                               '{fallback_male}',
                               to_jsonb('male1'::text),
                               true
                           ),
                           '{fallback_female}',
                           to_jsonb('female1'::text),
                           true
                       )
                       WHERE LOWER(name) IN (
                           'pocket tts default',
                           'xtts default',
                           'chatterbox default',
                           'omnivoice default',
                           'cartesia default',
                           'inworld default'
                       )");
        });
        $applyPatch('core_tts_connector', 202607071200, static function () use ($db): void {
            $db->exec("INSERT INTO core_tts_connector (
                           name,
                           connector_type,
                           base_url,
                           is_default,
                           config
                       ) VALUES (
                           'OmniVoice Default',
                           'omnivoice',
                           'http://127.0.0.1:8021',
                           FALSE,
                           '{\"language\":\"\",\"fallback_male\":\"default_male\",\"fallback_female\":\"default_female\",\"stream_chunk_size\":20,\"temperature\":0.9,\"speed\":1.0,\"length_penalty\":1.0,\"repetition_penalty\":5.0,\"top_p\":0.85,\"top_k\":50,\"enable_text_splitting\":true}'::jsonb
                       )
                       ON CONFLICT (name) DO UPDATE SET
                           connector_type = EXCLUDED.connector_type,
                           base_url = EXCLUDED.base_url,
                           config = EXCLUDED.config");
        });
        $applyPatch('core_npc_master', 202603130215, static function () use ($db): void {
            $db->exec("
                UPDATE core_npc_master
                SET extended_data = jsonb_set(
                    COALESCE(extended_data, '{}'::jsonb),
                    '{relationships}',
                    COALESCE(
                        (
                            SELECT jsonb_object_agg(e.key, e.value)
                            FROM jsonb_each(COALESCE(core_npc_master.extended_data->'relationships', '{}'::jsonb)) AS e(key, value)
                            WHERE LOWER(BTRIM(e.key)) NOT IN ('player', 'the player', '#player_name#', 'dragonborn', 'the dragonborn')
                        ),
                        '{}'::jsonb
                    ),
                    true
                )
                WHERE COALESCE(extended_data, '{}'::jsonb) ? 'relationships'
            ");
        });
        $applyPatch('core_npc_master', 202603260301, static function () use ($db): void {
            $rows = $db->fetchAll("
                SELECT id, COALESCE(occupation, '') AS occupation, COALESCE(faction, '') AS faction
                FROM core_npc_master
                WHERE COALESCE(occupation, '') <> ''
                  AND occupation ~ '\\[[^\\]]+\\]'
                  AND occupation ~* 'faction'
            ");
            $updated = 0;
            foreach ($rows as $row) {
                $id = intval($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $occupation = strval($row['occupation'] ?? '');
                $fallbackFaction = strval($row['faction'] ?? '');
                $normalized = stobeNormalizeOccupationText($occupation, $fallbackFaction);
                if ($normalized === '' || $normalized === $occupation) {
                    continue;
                }
                $db->exec(
                    "UPDATE core_npc_master SET occupation=$1, updated_at=NOW() WHERE id=$2",
                    [$normalized, $id]
                );
                $updated++;
            }
            if ($updated > 0) {
                stobeLogInfo('core_npc_master occupation faction text normalized', ['updated' => $updated]);
            }
        });
        $applyPatch('memory_summary', 202603190001, static function () use ($db): void {
            $db->exec("ALTER TABLE memory_summary ADD COLUMN IF NOT EXISTS scope TEXT");
            $db->exec("UPDATE memory_summary SET scope='global' WHERE scope IS NULL OR BTRIM(scope)=''");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_summary_scope_gamets ON memory_summary (LOWER(COALESCE(scope, '')), gamets_end DESC, id DESC)");
        });

        $applyPatch('rename_token_global', 202603130206, static function () use ($runSqlSeedFile, $runBioUniqueSeedBundle): void {
            // The generated token seed excludes names already present in bio_unique.
            $runBioUniqueSeedBundle();
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_characters_rename_token_global_upsert.sql';
            $runSqlSeedFile($seed, 'rename_token_global category characters seed file missing', 'rename_token_global category characters seed file empty', 'rename_token_global category characters seed file normalized to empty SQL');
        });

        $applyPatch('bio_random', 202605130002, static function () use ($runSqlSeedFile): void {
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_bio_random_upsert.sql';
            $runSqlSeedFile($seed, 'bio_random seed file missing', 'bio_random seed file empty', 'bio_random seed file normalized to empty SQL', true, false);
            $occ = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'kenshi_rename_token_bio_random_occupation_upsert.sql';
            $runSqlSeedFile($occ, 'bio_random rename-token occupation seed file missing', 'bio_random rename-token occupation seed file empty', 'bio_random rename-token occupation seed normalized to empty SQL', true, true);
        });
        $applyPatch('bio_random_faction_backstory', 202605130003, static function () use ($runSqlSeedFile): void {
            $factionBackstory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'faction_bio_backstory_upsert.sql';
            $runSqlSeedFile(
                $factionBackstory,
                'bio_random faction backstory seed file missing',
                'bio_random faction backstory seed file empty',
                'bio_random faction backstory seed normalized to empty SQL',
                true,
                true
            );
        });
        $applyPatch('bio_random_personality', 202605130002, static function () use ($runSqlSeedFile): void {
            $personalitySeed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'personality_bio_random_upsert.sql';
            $runSqlSeedFile(
                $personalitySeed,
                'bio_random personality seed file missing',
                'bio_random personality seed file empty',
                'bio_random personality seed normalized to empty SQL',
                true,
                true
            );
        });
        $applyPatch('bio_random_speechstyle', 202605130001, static function () use ($runSqlSeedFile): void {
            $speechstyleSeed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'speechstyle_bio_random_upsert.sql';
            $runSqlSeedFile(
                $speechstyleSeed,
                'bio_random speechstyle seed file missing',
                'bio_random speechstyle seed file empty',
                'bio_random speechstyle seed normalized to empty SQL',
                true,
                true
            );
        });
        $applyPatch('bio_random_faction_goals', 202605130001, static function () use ($runSqlSeedFile): void {
            $factionGoalsSeed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'faction_bio_goals_upsert.sql';
            $runSqlSeedFile(
                $factionGoalsSeed,
                'bio_random faction goals seed file missing',
                'bio_random faction goals seed file empty',
                'bio_random faction goals seed normalized to empty SQL',
                true,
                true
            );
        });
        $applyPatch('bio_random', 202606150001, static function () use ($db): void {
            $db->exec("ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE");
            $db->exec("ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE");
            $db->exec("UPDATE bio_random SET is_enabled = TRUE WHERE is_enabled IS NULL");
            $db->exec("UPDATE bio_random_custom SET is_enabled = TRUE WHERE is_enabled IS NULL");
            $db->exec("DROP VIEW IF EXISTS combined_bio_random");
            $db->exec(
                "CREATE OR REPLACE VIEW combined_bio_random AS
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
                 WHERE c.id IS NULL"
            );
        });

        $applyPatch('rename_token_global', 202606150001, static function () use ($db): void {
            $db->exec("ALTER TABLE rename_token_global ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE");
            $db->exec("ALTER TABLE rename_token_global_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE");
            $db->exec("UPDATE rename_token_global SET is_enabled = TRUE WHERE is_enabled IS NULL");
            $db->exec("UPDATE rename_token_global_custom SET is_enabled = TRUE WHERE is_enabled IS NULL");
            $db->exec("DROP VIEW IF EXISTS combined_rename_token_global");
            $db->exec(
                "CREATE OR REPLACE VIEW combined_rename_token_global AS
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
                 WHERE c.token IS NULL"
            );
        });

        $applyPatch('bio_unique', 202603130208, static function () use ($db, $runBioUniqueSeedBundle): void {
            $runBioUniqueSeedBundle();
            $db->exec("DELETE FROM bio_unique WHERE LOWER(name) IN ('amateur recruit','ameteur recruit','cpu of cat-lon','cpu of general hat-12','cpu of general jang','cpu of rhinobot','cpu of the head of agriculture')");
            $db->exec("DELETE FROM bio_unique_custom WHERE LOWER(name) IN ('amateur recruit','ameteur recruit','cpu of cat-lon','cpu of general hat-12','cpu of general jang','cpu of rhinobot','cpu of the head of agriculture')");
        });

        $applyPatch('bio_unique', 202606150001, static function () use ($db): void {
            $db->exec("ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE");
            $db->exec("ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE");
            $db->exec("UPDATE bio_unique SET is_enabled = TRUE WHERE is_enabled IS NULL");
            $db->exec("UPDATE bio_unique_custom SET is_enabled = TRUE WHERE is_enabled IS NULL");
            $db->exec("DROP VIEW IF EXISTS combined_bio_unique");
            $db->exec(
                "CREATE OR REPLACE VIEW combined_bio_unique AS
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
                 WHERE c.id IS NULL"
            );
        });

        $applyPatch('rename_token_global', 202608020001, static function () use ($db): void {
            $db->exec(
                "DELETE FROM rename_token_global token
                 WHERE EXISTS (
                    SELECT 1
                    FROM combined_bio_unique unique_bio
                    WHERE LOWER(BTRIM(unique_bio.name)) = LOWER(BTRIM(token.token))
                 )"
            );
        });

        $applyPatch('world_knowledge_seed', 202603130209, static function () use ($importWorldKnowledgeCsv): void {
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'world_knowledge_v1.csv';
            $importWorldKnowledgeCsv($seed);
        });

        $applyPatch('world_knowledge_aliases', 202607220002, static function () use ($db): void {
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'world_knowledge_v1.csv';
            $stats = stobeWorldKnowledgeApplyAliasSeed($db, $seed);
            stobeLogInfo('World knowledge aliases merged and indexed', $stats);
        });

        $applyPatch('world_knowledge_world_state_topics', 202607290100, static function () use ($importWorldKnowledgeCsv): void {
            $seed = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'world_knowledge_v1.csv';
            $topics = [
                'Ghost',
                'Gutterhead',
                'Beep',
                'Agnu',
                'Shek Kingdom',
                'Flotsam Ninjas',
                'Grey',
                'Jaegar',
                'Elder',
                'Spider Foreman',
            ];
            $importWorldKnowledgeCsv($seed, $topics, false);
        });

        $applyPatch('world_state', 202603150001, static function () use ($db): void {
            $db->exec("CREATE TABLE IF NOT EXISTS world_state (
                id BIGSERIAL PRIMARY KEY,
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
            )");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_game_ts ON world_state (game_ts DESC, id DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_created_at ON world_state (created_at DESC, id DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_rule_category ON world_state (rule_category)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_query_name_lower ON world_state (LOWER(query_name))");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_entity_name_lower ON world_state (LOWER(entity_name))");
        });
        $applyPatch('world_state', 202603150002, static function () use ($db): void {
            $db->exec("DROP TABLE IF EXISTS world_state_snapshot");
            $db->exec("DELETE FROM public.database_versioning WHERE tablename='world_state_snapshot'");
        });
        $applyPatch('world_state', 202603150003, static function () use ($db): void {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_world_state_source ON world_state (source)");
        });
        $applyPatch('world_state', 202603150004, static function () use ($db): void {
            $db->exec("ALTER TABLE world_state ADD COLUMN IF NOT EXISTS merge_key TEXT");
            $db->exec("
                UPDATE world_state
                SET merge_key =
                    LOWER(COALESCE(source, '')) || '|' ||
                    LOWER(COALESCE(query_name, '')) || '|' ||
                    LOWER(COALESCE(query_string_id, '')) || '|' ||
                    COALESCE(query_numeric_id, 0)::text || '|' ||
                    CASE WHEN COALESCE(player_involvement, FALSE) THEN '1' ELSE '0' END || '|' ||
                    LOWER(COALESCE(rule_category, '')) || '|' ||
                    LOWER(COALESCE(entity_name, '')) || '|' ||
                    LOWER(COALESCE(entity_string_id, '')) || '|' ||
                    COALESCE(entity_numeric_id, 0)::text
                WHERE COALESCE(merge_key, '') = ''
            ");
            $db->exec("
                WITH ranked AS (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY COALESCE(merge_key, '')
                            ORDER BY COALESCE(game_ts, 0) DESC, id DESC
                        ) AS rn
                    FROM world_state
                )
                DELETE FROM world_state
                WHERE id IN (SELECT id FROM ranked WHERE rn > 1)
            ");
            $db->exec("
                UPDATE world_state
                SET merge_key =
                    LOWER(COALESCE(source, '')) || '|' ||
                    LOWER(COALESCE(query_name, '')) || '|' ||
                    LOWER(COALESCE(query_string_id, '')) || '|' ||
                    COALESCE(query_numeric_id, 0)::text || '|' ||
                    CASE WHEN COALESCE(player_involvement, FALSE) THEN '1' ELSE '0' END || '|' ||
                    LOWER(COALESCE(rule_category, '')) || '|' ||
                    LOWER(COALESCE(entity_name, '')) || '|' ||
                    LOWER(COALESCE(entity_string_id, '')) || '|' ||
                    COALESCE(entity_numeric_id, 0)::text
                WHERE merge_key IS NULL
            ");
            $db->exec("ALTER TABLE world_state ALTER COLUMN merge_key SET NOT NULL");
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_world_state_merge_key ON world_state (merge_key)");
        });
        $applyPatch('general_settings', 202603150005, static function () use ($db): void {
            $db->exec("
                INSERT INTO general_settings (id, value, description, updated_at)
                VALUES (
                    'RELATIONSHIP_SYSTEM',
                    COALESCE(
                        (SELECT value FROM general_settings WHERE id='RELATIONSHIP_SYSTEM_ENABLED' LIMIT 1),
                        'true'
                    ),
                    'Master toggle for relationship connector evaluation. When false, relationship LLM updates are skipped.',
                    NOW()
                )
                ON CONFLICT (id) DO UPDATE
                SET description = EXCLUDED.description,
                    updated_at = NOW()
            ");
        });
        $applyPatch('prompts', 202603150007, static function () use ($db): void {
            $prompt = 'Focus on key events, tagging characters, locations, and factions accurately. Ensure memories align and maintain chronological order while foreshadowing future arcs.';
            $db->exec(
                "INSERT INTO prompts (prompt_key, default_prompt, description)
                 VALUES ('regular_memory_summarizer', $1, 'System prompt for regular memory summary packing. Used in lib/memory_helper_functions.php.')
                 ON CONFLICT (prompt_key) DO UPDATE
                 SET default_prompt = EXCLUDED.default_prompt,
                     description = EXCLUDED.description,
                     updated_at = NOW()",
                [$prompt]
            );
        });
        $applyPatch('prompts', 202603250401, static function () use ($db): void {
            $prompt = "Describe the current scene visually using only details from context. Focus on characters present, body language, environment, and atmosphere in 2-3 concise sentences. Do not invent events or include action tags.";
            $db->exec(
                "INSERT INTO prompts (prompt_key, default_prompt, description)
                 VALUES ('random_narration_prompt', $1, 'Prompt for random narrator interjections during rechat turns. Used in processor/rechat.php.')
                 ON CONFLICT (prompt_key) DO UPDATE
                 SET default_prompt = EXCLUDED.default_prompt,
                     description = EXCLUDED.description,
                     updated_at = NOW()",
                [$prompt]
            );
        });
        $applyPatch('prompts', 202603250402, static function () use ($db): void {
            $prompt = "Describe the current scene visually using only details from context. Focus on characters present, body language, environment, and atmosphere in 1-2 concise sentences. Do not invent events or include action tags.";
            $db->exec(
                "INSERT INTO prompts (prompt_key, default_prompt, description)
                 VALUES ('random_narration_prompt', $1, 'Prompt for random narrator interjections during rechat turns. Used in processor/rechat.php.')
                 ON CONFLICT (prompt_key) DO UPDATE
                 SET default_prompt = EXCLUDED.default_prompt,
                     description = EXCLUDED.description,
                    updated_at = NOW()",
                [$prompt]
            );
        });
        $applyPatch('core_npc_master', 202603150006, static function () use ($db): void {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_core_npc_master_history_npc_history ON core_npc_master_history (npc_id, history_id DESC)");
            $db->exec("CREATE OR REPLACE FUNCTION core_npc_master_history_audit_fn()
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
                    normalized_old := COALESCE(to_jsonb(OLD), '{}'::jsonb) - '{updated_at,metadata,extended_data,limbs,blood,hunger}'::text[];
                    normalized_new := COALESCE(to_jsonb(NEW), '{}'::jsonb) - '{updated_at,metadata,extended_data,limbs,blood,hunger}'::text[];
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

                snapshot_payload := COALESCE(to_jsonb(src), '{}'::jsonb) - '{updated_at,metadata,extended_data,limbs,blood,hunger}'::text[];
                source_npc_id := COALESCE(src.id, 0);

                INSERT INTO core_npc_master_history (
                    npc_id, name, original_name, npc_favorite, lock_profile,
                    prompt_head, personality, backstory, emote_moods, occupation,
                    appearance, equipment, inventory, skills, speechstyle,
                    goals, relationships, voiceid, metadata, race, faction, gender,
                    profile_id, dynamic_profile, extended_data, md5, gamets_last_updated,
                    bounty, limbs, blood, hunger, tags, is_animal, is_slave,
                    world_knowledge_tags,
                    snapshot_reason, snapshot_hash, source_created_at, source_updated_at, created
                ) VALUES (
                    COALESCE(src.id,0), COALESCE(src.name,''), COALESCE(src.original_name,''),
                    COALESCE(src.npc_favorite,FALSE), COALESCE(src.lock_profile,FALSE),
                    COALESCE(src.prompt_head,''), COALESCE(src.personality,''), COALESCE(src.backstory,''), COALESCE(src.emote_moods,''), COALESCE(src.occupation,''),
                    COALESCE(src.appearance,''), COALESCE(src.equipment,''), COALESCE(src.inventory,''), COALESCE(src.skills,''), COALESCE(src.speechstyle,''),
                    COALESCE(src.goals,''), COALESCE(src.relationships,''), COALESCE(src.voiceid,''), COALESCE(src.metadata,'{}'::jsonb), COALESCE(src.race,''), COALESCE(src.faction,''), COALESCE(src.gender,''),
                    src.profile_id, FALSE, COALESCE(src.extended_data,'{}'::jsonb), COALESCE(src.md5,''), COALESCE(src.gamets_last_updated,0),
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
            $$;");
            $db->exec("DROP TRIGGER IF EXISTS trg_core_npc_master_history_audit ON core_npc_master");
            $db->exec("CREATE TRIGGER trg_core_npc_master_history_audit AFTER INSERT OR UPDATE OR DELETE ON core_npc_master FOR EACH ROW EXECUTE FUNCTION core_npc_master_history_audit_fn()");
            $db->exec("
                WITH ranked AS (
                    SELECT history_id,
                           ROW_NUMBER() OVER (PARTITION BY npc_id ORDER BY history_id DESC) AS rn
                    FROM core_npc_master_history
                )
                DELETE FROM core_npc_master_history
                WHERE history_id IN (
                    SELECT history_id
                    FROM ranked
                    WHERE rn > 50
                )
            ");
        });
        $applyPatch('core_npc_master', 202604050101, static function () use ($db): void {
            $db->exec("CREATE OR REPLACE FUNCTION core_npc_master_history_audit_fn()
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
                    normalized_old := COALESCE(to_jsonb(OLD), '{}'::jsonb) - '{updated_at,metadata,extended_data,limbs,blood,hunger,skills,relationships}'::text[];
                    normalized_new := COALESCE(to_jsonb(NEW), '{}'::jsonb) - '{updated_at,metadata,extended_data,limbs,blood,hunger,skills,relationships}'::text[];
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

                snapshot_payload := COALESCE(to_jsonb(src), '{}'::jsonb) - '{updated_at,metadata,extended_data,limbs,blood,hunger,skills,relationships}'::text[];
                source_npc_id := COALESCE(src.id, 0);

                INSERT INTO core_npc_master_history (
                    npc_id, name, original_name, npc_favorite, lock_profile,
                    prompt_head, personality, backstory, emote_moods, occupation,
                    appearance, equipment, inventory, skills, speechstyle,
                    goals, relationships, voiceid, metadata, race, faction, gender,
                    profile_id, dynamic_profile, extended_data, md5, gamets_last_updated,
                    bounty, limbs, blood, hunger, tags, is_animal, is_slave,
                    world_knowledge_tags,
                    snapshot_reason, snapshot_hash, source_created_at, source_updated_at, created
                ) VALUES (
                    COALESCE(src.id,0), COALESCE(src.name,''), COALESCE(src.original_name,''),
                    COALESCE(src.npc_favorite,FALSE), COALESCE(src.lock_profile,FALSE),
                    COALESCE(src.prompt_head,''), COALESCE(src.personality,''), COALESCE(src.backstory,''), COALESCE(src.emote_moods,''), COALESCE(src.occupation,''),
                    COALESCE(src.appearance,''), COALESCE(src.equipment,''), COALESCE(src.inventory,''), COALESCE(src.skills,''), COALESCE(src.speechstyle,''),
                    COALESCE(src.goals,''), COALESCE(src.relationships,''), COALESCE(src.voiceid,''), COALESCE(src.metadata,'{}'::jsonb), COALESCE(src.race,''), COALESCE(src.faction,''), COALESCE(src.gender,''),
                    src.profile_id, FALSE, COALESCE(src.extended_data,'{}'::jsonb), COALESCE(src.md5,''), COALESCE(src.gamets_last_updated,0),
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
            $$;");
            $db->exec("DROP TRIGGER IF EXISTS trg_core_npc_master_history_audit ON core_npc_master");
            $db->exec("CREATE TRIGGER trg_core_npc_master_history_audit AFTER INSERT OR UPDATE OR DELETE ON core_npc_master FOR EACH ROW EXECUTE FUNCTION core_npc_master_history_audit_fn()");
        });

        $applyPatch('core_profiles', 202603270101, static function () use ($db): void {
            $db->exec("ALTER TABLE core_profiles ADD COLUMN IF NOT EXISTS is_player_faction_profile BOOLEAN DEFAULT FALSE");
            $db->exec("UPDATE core_profiles SET is_player_faction_profile = FALSE WHERE is_player_faction_profile IS NULL");
            $keeper = $db->fetchOne(
                "SELECT id
                 FROM core_profiles
                 WHERE COALESCE(is_player_faction_profile, FALSE) = TRUE
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $keeperId = intval($keeper['id'] ?? 0);
            if ($keeperId > 0) {
                $db->exec(
                    "UPDATE core_profiles
                     SET is_player_faction_profile = FALSE,
                         updated_at = NOW()
                     WHERE id <> $1
                       AND COALESCE(is_player_faction_profile, FALSE) = TRUE",
                    [$keeperId]
                );
            }
            $db->exec(
                "CREATE UNIQUE INDEX IF NOT EXISTS idx_core_profiles_single_player_faction
                 ON core_profiles (is_player_faction_profile)
                 WHERE is_player_faction_profile = TRUE"
            );
        });

        $applyPatch('core_profiles', 202604030003, static function () use ($db, $defaultMetadata): void {
            $defaultProfile = $db->fetchOne(
                "SELECT id,
                        response_connector,
                        diary_connector,
                        autochat_connector,
                        middleterm_connector,
                        backgroundlife_connector,
                        dynamic_connector,
                        relationship_connector,
                        tts_connector_id,
                        metadata
                 FROM core_profiles
                 ORDER BY CASE
                            WHEN COALESCE(is_default_npc, FALSE) = TRUE THEN 0
                            WHEN LOWER(COALESCE(label, '')) = 'default profile' THEN 1
                            ELSE 2
                          END,
                          id ASC
                 LIMIT 1"
            );
            $defaultProfileId = intval($defaultProfile['id'] ?? 0);
            if ($defaultProfileId <= 0) {
                return;
            }

            $rawMetadata = $defaultProfile['metadata'] ?? '{}';
            if (is_array($rawMetadata)) {
                $playerMetadata = $rawMetadata;
            } else {
                $decoded = json_decode(strval($rawMetadata), true);
                $playerMetadata = is_array($decoded) ? $decoded : [];
            }
            if (count($playerMetadata) === 0) {
                $fallbackDecoded = json_decode($defaultMetadata, true);
                if (is_array($fallbackDecoded)) {
                    $playerMetadata = $fallbackDecoded;
                }
            }
            $playerMetadata['DYNAMIC_PROFILE_ENABLED'] = true;
            $playerMetadata['MIDDLE_TERM_MEMORY_ENABLED'] = true;
            $playerMetadata['AUTO_DIARY_ENABLED'] = true;
            $playerMetadataJson = json_encode($playerMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($playerMetadataJson) || trim($playerMetadataJson) === '') {
                $playerMetadataJson = '{"DYNAMIC_PROFILE_ENABLED":true,"MIDDLE_TERM_MEMORY_ENABLED":true,"AUTO_DIARY_ENABLED":true}';
            }

            $playerByLabel = $db->fetchOne(
                "SELECT id
                 FROM core_profiles
                 WHERE LOWER(COALESCE(label, '')) = 'player faction'
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $playerProfileId = intval($playerByLabel['id'] ?? 0);
            if ($playerProfileId <= 0) {
                $playerByFlag = $db->fetchOne(
                    "SELECT id
                     FROM core_profiles
                     WHERE COALESCE(is_player_faction_profile, FALSE) = TRUE
                     ORDER BY id ASC
                     LIMIT 1"
                );
                $playerProfileId = intval($playerByFlag['id'] ?? 0);
            }

            $responseConnector = intval($defaultProfile['response_connector'] ?? 0);
            $diaryConnector = intval($defaultProfile['diary_connector'] ?? 0);
            $autochatConnector = intval($defaultProfile['autochat_connector'] ?? 0);
            $middletermConnector = intval($defaultProfile['middleterm_connector'] ?? 0);
            $backgroundlifeConnector = intval($defaultProfile['backgroundlife_connector'] ?? 0);
            $dynamicConnector = intval($defaultProfile['dynamic_connector'] ?? 0);
            $relationshipConnector = intval($defaultProfile['relationship_connector'] ?? 0);
            $ttsConnectorId = intval($defaultProfile['tts_connector_id'] ?? 0);

            if ($playerProfileId <= 0) {
                $inserted = $db->fetchOne(
                    "INSERT INTO core_profiles (
                        label,
                        is_default_npc,
                        is_player_faction_profile,
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
                        'Player Faction',
                        FALSE,
                        TRUE,
                        $1, $2, $3, $4, $5, $6, $7, $8,
                        $9::jsonb
                    )
                    ON CONFLICT (label) DO UPDATE SET
                        is_default_npc = FALSE,
                        is_player_faction_profile = TRUE,
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
                        updated_at = NOW()
                    RETURNING id",
                    [
                        $responseConnector > 0 ? $responseConnector : null,
                        $diaryConnector > 0 ? $diaryConnector : null,
                        $autochatConnector > 0 ? $autochatConnector : null,
                        $middletermConnector > 0 ? $middletermConnector : null,
                        $backgroundlifeConnector > 0 ? $backgroundlifeConnector : null,
                        $dynamicConnector > 0 ? $dynamicConnector : null,
                        $relationshipConnector > 0 ? $relationshipConnector : null,
                        $ttsConnectorId > 0 ? $ttsConnectorId : null,
                        $playerMetadataJson
                    ]
                );
                $playerProfileId = intval($inserted['id'] ?? 0);
            }

            if ($playerProfileId > 0) {
                $db->exec(
                    "UPDATE core_profiles
                     SET label = 'Player Faction',
                         is_default_npc = FALSE,
                         is_player_faction_profile = TRUE,
                         response_connector = COALESCE(response_connector, $1::INT),
                         diary_connector = COALESCE(diary_connector, $2::INT),
                         autochat_connector = COALESCE(autochat_connector, $3::INT),
                         middleterm_connector = COALESCE(middleterm_connector, $4::INT),
                         backgroundlife_connector = COALESCE(backgroundlife_connector, $5::INT),
                         dynamic_connector = COALESCE(dynamic_connector, $6::INT),
                         relationship_connector = COALESCE(relationship_connector, $7::INT),
                         tts_connector_id = COALESCE(tts_connector_id, $8::INT),
                         metadata = CASE
                            WHEN metadata IS NULL
                              OR metadata = '[]'::jsonb
                              OR jsonb_typeof(metadata) <> 'object'
                            THEN $9::jsonb
                            ELSE jsonb_set(
                                jsonb_set(
                                    jsonb_set(metadata, '{DYNAMIC_PROFILE_ENABLED}', 'true'::jsonb, true),
                                    '{MIDDLE_TERM_MEMORY_ENABLED}',
                                    'true'::jsonb,
                                    true
                                ),
                                '{AUTO_DIARY_ENABLED}',
                                'true'::jsonb,
                                true
                            )
                         END,
                         updated_at = NOW()
                     WHERE id = $10",
                    [
                        $responseConnector > 0 ? $responseConnector : null,
                        $diaryConnector > 0 ? $diaryConnector : null,
                        $autochatConnector > 0 ? $autochatConnector : null,
                        $middletermConnector > 0 ? $middletermConnector : null,
                        $backgroundlifeConnector > 0 ? $backgroundlifeConnector : null,
                        $dynamicConnector > 0 ? $dynamicConnector : null,
                        $relationshipConnector > 0 ? $relationshipConnector : null,
                        $ttsConnectorId > 0 ? $ttsConnectorId : null,
                        $playerMetadataJson,
                        $playerProfileId
                    ]
                );

                $db->exec(
                    "UPDATE core_profiles
                     SET is_player_faction_profile = FALSE,
                         updated_at = NOW()
                     WHERE id <> $1
                       AND COALESCE(is_player_faction_profile, FALSE) = TRUE",
                    [$playerProfileId]
                );
            }
        });

        $applyPatch('core_npc_master', 202603270102, static function () use ($db): void {
            $db->exec("ALTER TABLE core_npc_master ADD COLUMN IF NOT EXISTS profile_id_before_player_faction INT");
            $db->exec("CREATE OR REPLACE VIEW core_npc AS SELECT * FROM core_npc_master");
        });

        $applyPatch('general_settings', 202604050101, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('MIDDLE_TERM_MEMORY_INTERVAL_HOURS','10','Middle-term memory summary interval (in-game hours)',NOW())
                ON CONFLICT (id) DO UPDATE
                SET description = EXCLUDED.description,
                    updated_at = NOW()");
        });

        $applyPatch('general_settings', 202604050102, static function () use ($db): void {
            $db->exec("DELETE FROM general_settings WHERE id = 'MIDDLE_TERM_MEMORY_INTERVAL_HOURS'");
        });

        $applyPatch('general_settings', 202604050104, static function () use ($db): void {
            $db->exec("DELETE FROM general_settings WHERE id IN (
                'MEMORY_TIME_DELAY',
                'MEMORY_CONTEXT_SIZE',
                'MEMORY_BIAS_A',
                'MEMORY_BIAS_B'
            )");
        });

        $applyPatch('prompts', 202604050103, static function () use ($db): void {
            $db->exec(
                "INSERT INTO prompts (prompt_key, default_prompt, description)
                 VALUES
                 (
                    'middleterm_narrative_summarizer',
                    'You are a long-term narrative continuity summarizer for an improvised Kenshi universe chronicle.
- Always read ALL provided materials.
- Treat any **Previous Context History Summary** as the canonical prior unless anything in the new Context History explicitly supersedes it.
- Maintain in-universe tone and correct chronology. Do not invent facts outside the supplied context.
- When combining prior and new histories, you may compress the earlier parts of the prior summary.
- Maintain roughly 20-25 bullet points total in **Notable Events**. Older portions should be condensed into broader, grouped statements unless they describe major quest milestones, major character life events (e.g., death, intimacy, severe injury, transformation), or other pivotal story turns.
- Preserve continuity and references to major quests even when compressing earlier material.',
                    'Herika-style middle-term narrative summarizer system prompt.'
                 ),
                 (
                    'middleterm_narrative_request',
                    'Main character in this logbook is {HERIKA_NAME}.
Task: Read **Context History** (newest session) and, if present, the **Previous Context History Summary** (prior canon). Integrate them to produce an updated broad narrative strokes summary that preserves continuity. Summary sections:

- **Notable Events in Chronological Order:**
  - Provide ~10 bullet points from earliest to latest, reflecting the story so far.
  - Prefer facts already established in the previous summary; only revise if the new context clearly changes them.

- **Current Quest Progression and background:**
  - Name questlines, stages/milestones if stated, objectives completed/active, and motivations.
When generating entries, ensure that {HERIKA_NAME} - the protagonist - is actively present in the scene. Any narrative content that occurs before {HERIKA_NAME}''s arrival or outside {HERIKA_NAME}''s perspective should be omitted, reflect only events {HERIKA_NAME} directly witness or participate in.
If the resulting summary would exceed roughly 25 bullet points, merge or generalise older entries into broader grouped events. Always retain explicit entries for major quest milestones, major character life events, or turning points.',
                    'Herika-style middle-term narrative request prompt.'
                 )
                 ON CONFLICT (prompt_key) DO UPDATE SET
                     default_prompt = EXCLUDED.default_prompt,
                     description = EXCLUDED.description"
            );
        });
        $applyPatch('prompts', 202604110101, static function () use ($db): void {
            $prompt = 'Please write a short summary of the last #DAYS_SINCE_LAST_DIARY# in-game day(s) of #PLAYER_NAME# and #NPC_NAME#\'s dialogues and events written above into #NPC_NAME#\'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with exactly this header: "#KENSHI_DIARY_HEADER#".';
            $description = 'Global default prompt for diary generation. Profile-level DIARY_PROMPT overrides this when set. Used in lib/diary_helper_functions.php.';
            $db->exec(
                "INSERT INTO prompts (prompt_key, default_prompt, description)
                 VALUES ('DIARY_PROMPT', $1, $2)
                 ON CONFLICT (prompt_key) DO UPDATE
                 SET default_prompt = EXCLUDED.default_prompt,
                     description = EXCLUDED.description,
                     updated_at = NOW()",
                [$prompt, $description]
            );
        });
        $applyPatch('prompts', 202607280001, static function () use ($db): void {
            $prompt = 'If useful, begin the reply with one brief third-person scene description in single asterisks, followed by spoken dialogue outside the asterisks. Example: *She glances toward the gate.* We should leave. Never wrap the entire reply in asterisks.';
            $db->exec(
                "INSERT INTO prompts (prompt_key, default_prompt, custom_prompt, description, updated_at)
                 VALUES ('inline_narration_prompt', $1, '', 'Formatting instruction used when narrator inline narration mode is enabled.', NOW())
                 ON CONFLICT (prompt_key) DO UPDATE
                 SET default_prompt = EXCLUDED.default_prompt,
                     description = EXCLUDED.description,
                     updated_at = NOW()",
                [$prompt]
            );
        });
        $applyPatch('prompts', 202607290001, static function () use ($db): void {
            $prompt = 'Begin each reply with one brief third-person scene description in single asterisks, followed by spoken dialogue outside the asterisks. Example: *She glances toward the gate.* We should leave. Never wrap the entire reply in asterisks.';
            $db->exec(
                "INSERT INTO prompts (prompt_key, default_prompt, custom_prompt, description, updated_at)
                 VALUES ('inline_narration_prompt', $1, '', 'Formatting instruction used when narrator inline narration mode is enabled.', NOW())
                 ON CONFLICT (prompt_key) DO UPDATE
                 SET default_prompt = EXCLUDED.default_prompt,
                     description = EXCLUDED.description,
                     updated_at = NOW()",
                [$prompt]
            );
        });
        $applyPatch('core_profiles', 202604110102, static function () use ($db): void {
            $db->exec(
                "UPDATE core_profiles
                 SET metadata = CASE
                     WHEN metadata IS NULL OR metadata = '[]'::jsonb OR jsonb_typeof(metadata) <> 'object'
                         THEN jsonb_build_object('AUTO_DIARY_HOUR', 21)
                     WHEN NOT (metadata ? 'AUTO_DIARY_HOUR')
                         THEN metadata || jsonb_build_object('AUTO_DIARY_HOUR', 21)
                     ELSE metadata
                 END,
                 updated_at = NOW()
                 WHERE metadata IS NULL
                    OR metadata = '[]'::jsonb
                    OR jsonb_typeof(metadata) <> 'object'
                    OR NOT (metadata ? 'AUTO_DIARY_HOUR')"
            );
        });

        $applyPatch('memory', 202604050105, static function () use ($db): void {
            $db->exec("ALTER TABLE memory ADD COLUMN IF NOT EXISTS location TEXT DEFAULT ''");
        });

        $applyPatch('general_settings', 202604050106, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES (
                    'AUTO_CREATE_SUMMARY_MIN_EVENTS',
                    '5',
                    'Minimum memory events required to create one packed summary block.',
                    NOW()
                 )
                 ON CONFLICT (id) DO UPDATE
                 SET description = EXCLUDED.description,
                     updated_at = NOW()"
            );

            $db->exec(
                "UPDATE general_settings
                 SET description = 'Memory summary packing interval (in-game hours).',
                     updated_at = NOW()
                 WHERE id = 'MEMORY_AUTO_CREATE_SUMMARY_INTERVAL'"
            );
        });

        $applyPatch('general_settings', 202604060301, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES (
                    'MEMORY_AUTO_CREATE_SUMMARY_INTERVAL',
                    '6',
                    'Memory summary packing interval (in-game hours).',
                    NOW()
                 )
                 ON CONFLICT (id) DO UPDATE
                 SET value = EXCLUDED.value,
                     description = EXCLUDED.description,
                     updated_at = NOW()"
            );
        });

        $applyPatch('general_settings', 202604060302, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES
                    ('PLAYER_FACTION_CUSTOM_NAME', '', 'Optional custom display name for the player faction in prompts.', NOW()),
                    ('PLAYER_FACTION_PROMPT', '', 'Optional player-faction instruction block injected into prompts.', NOW())
                 ON CONFLICT (id) DO UPDATE
                 SET description = EXCLUDED.description,
                     updated_at = NOW()"
            );
        });
        $applyPatch('general_settings', 202604200002, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES (
                    'ALWAYS_INSERT_RACE',
                    'true',
                    'When true, always inject world knowledge entries for detected speaker and nearby NPC races when matching topics exist.',
                    NOW()
                 )
                 ON CONFLICT (id) DO UPDATE
                 SET description = EXCLUDED.description,
                     updated_at = NOW()"
            );
        });
        $applyPatch('general_settings', 202605110001, static function () use ($db): void {
            $defaultPromptContextOptions = json_encode(stobeGetDefaultPromptContextOptions(), JSON_UNESCAPED_SLASHES);
            $description = 'Controls which prompt context blocks and subsections are included in Stobe system prompts. Managed from Global Settings.';
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES ($1, $2, $3, NOW())
                 ON CONFLICT (id) DO UPDATE
                 SET description = EXCLUDED.description,
                     updated_at = NOW()",
                ['PROMPT_CONTEXT_OPTIONS', $defaultPromptContextOptions, $description]
            );
        });
        $applyPatch('general_settings', 202605110002, static function () use ($db): void {
            $definitions = [
                [
                    'id' => 'RECHAT_MODE',
                    'value' => 'random',
                    'description' => 'Controls how Stobe chooses the next rechat responder: tight, conversational, group, or random.',
                ],
                [
                    'id' => 'ENFORCE_STRICT_RECHAT_RESPONSE',
                    'value' => 'false',
                    'description' => 'When true, rechat replies must target the actor who just spoke.',
                ],
                [
                    'id' => 'SPEAKER_RECHAT',
                    'value' => 'false',
                    'description' => 'When true, the initiating player speaker may be selected in rechat; when false, they are excluded.',
                ],
            ];

            foreach ($definitions as $definition) {
                $db->exec(
                    "INSERT INTO general_settings (id, value, description, updated_at)
                     VALUES ($1, $2, $3, NOW())
                     ON CONFLICT (id) DO UPDATE
                     SET description = EXCLUDED.description,
                         updated_at = NOW()",
                    [
                        strval($definition['id'] ?? ''),
                        strval($definition['value'] ?? ''),
                        strval($definition['description'] ?? ''),
                    ]
                );
            }
        });
        $applyPatch('general_settings', 202605110003, static function () use ($db): void {
            $db->exec("ALTER TABLE eventlog ADD COLUMN IF NOT EXISTS utterance_id TEXT");
            $db->exec("ALTER TABLE eventlog ADD COLUMN IF NOT EXISTS delivery_state TEXT");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_eventlog_utterance_id ON eventlog (utterance_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_eventlog_delivery_state ON eventlog (delivery_state)");
        });

        $applyPatch('autonomy_control_plane', 202607140101, static function (): void {
            stobeAutonomyEnsureSchema();
        });

        $applyPatch('autonomy_phase2_decision_ledger', 202607140102, static function (): void {
            stobeAutonomyEnsureSchema();
        });

        $applyPatch('autonomy_phase2_heartbeat_epoch', 202607140103, static function (): void {
            stobeAutonomyEnsureSchema();
        });

        $applyPatch('autonomy_phase3_supervised_planner', 202607150101, static function (): void {
            stobeAutonomyEnsureSchema();
        });

        $applyPatch('autonomy_phase3_planner_controls', 202607150102, static function () use ($db): void {
            stobeAutonomyEnsureSchema();
            $db->exec(
                "UPDATE autonomy_session
                 SET policy = jsonb_set(
                     jsonb_set(policy, '{minimum_interval_seconds}', '30'::jsonb, TRUE),
                     '{max_decisions_per_hour}',
                     COALESCE(policy->'max_decisions_per_hour', '30'::jsonb),
                     TRUE
                 )
                 WHERE NOT (policy ? 'minimum_interval_seconds')
                    OR CASE
                        WHEN COALESCE(policy->>'minimum_interval_seconds', '') ~ '^[0-9]+$'
                            THEN (policy->>'minimum_interval_seconds')::INT
                        ELSE 12
                    END = 12"
            );
        });

        $applyPatch('autonomy_phase4_survival_actions', 202607150201, static function () use ($db): void {
            stobeAutonomyEnsureSchema();
            $actions = [
                ['MOVE_NEARBY', 'MoveNearby', 'Move a short distance in a compass direction. Direction must be N, NE, E, SE, S, SW, W, or NW and distance is limited to 10-80 metres.'],
                ['FLEE', 'Flee', 'Run at maximum speed away from currently observed hostile characters.'],
                ['FIRST_AID', 'FirstAid', 'Apply first aid or robotic repair to yourself or an injured nearby player-faction character.'],
                ['REST', 'Rest', 'Rest until recovered when no immediate threat or untreated wound is present.'],
            ];
            foreach ($actions as [$command, $name, $description]) {
                $db->exec(
                    "INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                     VALUES ($1, $2, $3, TRUE, NOW())
                     ON CONFLICT (command) DO UPDATE SET
                         action_name = EXCLUDED.action_name,
                         description = EXCLUDED.description,
                         updated_at = NOW()",
                    [$command, $name, $description]
                );
            }
        });

        $applyPatch('autonomy_phase4_rest_bed_contract', 202607150202, static function () use ($db): void {
            $db->exec(
                "UPDATE core_action
                 SET description = 'Use an available nearby bed and rest until recovered when no immediate threat or untreated wound is present.',
                     updated_at = NOW()
                 WHERE UPPER(command) = 'REST'"
            );
        });

        $applyPatch('autonomy_phase4_pilot_commands', 202607150203, static function () use ($db): void {
            $db->exec('ALTER TABLE autonomy_pilot_step DROP CONSTRAINT IF EXISTS autonomy_pilot_step_command_check');
            $db->exec(
                "ALTER TABLE autonomy_pilot_step
                 ADD CONSTRAINT autonomy_pilot_step_command_check
                 CHECK (command IN ('IDLE', 'TRAVEL_LOCATION', 'MOVE_NEARBY',
                                    'FLEE', 'FIRST_AID', 'REST'))"
            );
        });

        $applyPatch('autonomy_phase5_equipment_loot_combat', 202607160301, static function () use ($db): void {
            stobeAutonomyEnsureSchema();
            $actions = [
                ['EQUIP_ITEM', 'EquipItem', 'Equip one specific item currently carried by the autonomous NPC. The item must be named explicitly and accepted by a Kenshi equipment slot.'],
                ['TAKE_ITEM', 'TakeItem', 'Take a named item from a nearby helpless actor. Target and item are required, amount is limited, and broad equipment or all-inventory looting is not allowed for autonomy.'],
            ];
            foreach ($actions as [$command, $name, $description]) {
                $db->exec(
                    "INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                     VALUES ($1, $2, $3, TRUE, NOW())
                     ON CONFLICT (command) DO UPDATE SET
                         action_name = EXCLUDED.action_name,
                         description = EXCLUDED.description,
                         updated_at = NOW()",
                    [$command, $name, $description]
                );
            }
            $db->exec('ALTER TABLE autonomy_pilot_step DROP CONSTRAINT IF EXISTS autonomy_pilot_step_command_check');
            $db->exec(
                "ALTER TABLE autonomy_pilot_step
                 ADD CONSTRAINT autonomy_pilot_step_command_check
                 CHECK (command IN ('IDLE', 'TRAVEL_LOCATION', 'MOVE_NEARBY',
                                    'FLEE', 'FIRST_AID', 'REST', 'ATTACK',
                                    'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT',
                                    'KILL', 'REMOVE_LIMB', 'CUT_HORNS'))"
            );
        });

        $applyPatch('autonomy_phase6_economy_work', 202607160401, static function () use ($db): void {
            stobeAutonomyEnsureSchema();
            $actions = [
                ['BUY_ITEM', 'BuyItem', 'Buy one exact observed item from a nearby trader using a real Kenshi transaction. The purchase must remain within the configured cats limit.'],
                ['SELL_ITEM', 'SellItem', 'Sell one exact carried item to a nearby trader using a real Kenshi transaction. The sale must meet the configured minimum price.'],
                ['WORK_RESOURCE', 'WorkResource', 'Operate one exact observed nearby mine or natural resource for a bounded work cycle.'],
                ['PROSPECT', 'Prospect', 'Perform a bounded prospecting scan at one exact observed nearby resource.'],
            ];
            foreach ($actions as [$command, $name, $description]) {
                $db->exec(
                    "INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                     VALUES ($1, $2, $3, TRUE, NOW())
                     ON CONFLICT (command) DO UPDATE SET
                         action_name = EXCLUDED.action_name,
                         description = EXCLUDED.description,
                         updated_at = NOW()",
                    [$command, $name, $description]
                );
            }
            $db->exec('ALTER TABLE autonomy_decision DROP CONSTRAINT IF EXISTS autonomy_decision_command_check');
            $db->exec('ALTER TABLE autonomy_pilot_step DROP CONSTRAINT IF EXISTS autonomy_pilot_step_command_check');
            $db->exec(
                "ALTER TABLE autonomy_pilot_step
                 ADD CONSTRAINT autonomy_pilot_step_command_check
                 CHECK (command IN ('IDLE', 'TRAVEL_LOCATION', 'MOVE_NEARBY',
                                    'FLEE', 'FIRST_AID', 'REST', 'ATTACK',
                                    'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT',
                                    'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
                                    'BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE',
                                    'PROSPECT'))"
            );
        });

        $applyPatch('rename_name_pool_manager', 202607190101, static function () use ($db): void {
            $db->exec('ALTER TABLE rename_global ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE');
            $db->exec('ALTER TABLE rename_global_custom ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE');
            $db->exec(
                "CREATE OR REPLACE VIEW combined_rename_global AS
                 SELECT c.id, c.name, c.gender, c.faction, c.race, c.created_at, c.updated_at, c.is_enabled
                 FROM rename_global_custom c
                 UNION ALL
                 SELECT g.id, g.name, g.gender, g.faction, g.race, g.created_at, g.updated_at, g.is_enabled
                 FROM rename_global g
                 LEFT JOIN rename_global_custom c ON LOWER(g.name) = LOWER(c.name)
                 WHERE c.name IS NULL"
            );
        });

        $applyPatch('rename_name_pool_expansion', 202607190102, static function () use ($db): void {
            $seedPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'rename_names_seed.csv';
            stobeRenameNameImportBaseSeed($db, $seedPath);
        });

        $applyPatch('minime_remote_service_url', 202607220101, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES ('TXTAI_URL', 'http://127.0.0.1:8082',
                         'MiniMe/TXT2VEC service base URL. Use the local DwemerDistro endpoint or a reachable remote service URL.',
                         NOW())
                 ON CONFLICT (id) DO NOTHING"
            );
        });

        $applyPatch('player2_game_client_id', 202607270101, static function () use ($db): void {
            $gameClientId = '019cf504-1461-74e7-b4da-045b14e9019d';
            $db->exec(
                "UPDATE core_api_badge
                 SET api_key = $1
                 WHERE LOWER(label) IN ('player2', 'stobe')
                   AND (
                       BTRIM(COALESCE(api_key, '')) = ''
                       OR UPPER(BTRIM(api_key)) = 'STOBE'
                   )",
                [$gameClientId]
            );
            $db->exec(
                "UPDATE core_llm_connector
                 SET config = jsonb_set(
                     CASE
                         WHEN config IS NULL OR jsonb_typeof(config) <> 'object' THEN '{}'::jsonb
                         ELSE config
                     END,
                     '{player2_game_key}',
                     to_jsonb($1::text),
                     TRUE
                 )
                 WHERE LOWER(COALESCE(connector_type, '')) = 'player2json'
                   AND (
                       config IS NULL
                       OR jsonb_typeof(config) <> 'object'
                       OR BTRIM(COALESCE(config->>'player2_game_key', '')) = ''
                       OR UPPER(BTRIM(config->>'player2_game_key')) = 'STOBE'
                   )",
                [$gameClientId]
            );
        });

        $applyPatch('latest_diary_context', 202607270201, static function () use ($db): void {
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_diarylog_people_gamets
                 ON diarylog (LOWER(TRIM(people)), gamets DESC, localts DESC, rowid DESC)'
            );
        });

        $applyPatch('general_settings', 202607280101, static function () use ($db): void {
            $intervalHours = 24;
            $legacyHoursRow = $db->fetchOne(
                "SELECT value FROM conf_opts WHERE id = 'DYNAMIC_PROFILE_INTERVAL_HOURS' LIMIT 1"
            );
            $legacyHours = trim(strval($legacyHoursRow['value'] ?? ''));
            if (preg_match('/^-?\d+$/', $legacyHours) === 1) {
                $intervalHours = intval($legacyHours);
            } else {
                $legacyMinutesRow = $db->fetchOne(
                    "SELECT value FROM conf_opts WHERE id = 'DYNAMIC_PROFILE_INTERVAL_MINUTES' LIMIT 1"
                );
                $legacyMinutes = trim(strval($legacyMinutesRow['value'] ?? ''));
                if (preg_match('/^-?\d+$/', $legacyMinutes) === 1) {
                    $intervalHours = intval(ceil(intval($legacyMinutes) / 60));
                }
            }
            $intervalHours = max(1, min(720, $intervalHours));

            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES ('DYNAMIC_PROFILE_INTERVAL_HOURS', $1,
                         'In-game hours between dynamic profile refreshes for enabled NPCs. Allowed range: 1-720.',
                         NOW())
                 ON CONFLICT (id) DO UPDATE
                 SET description = EXCLUDED.description,
                     updated_at = NOW()",
                [strval($intervalHours)]
            );
        });

        $applyPatch('world_state_query_result', 202607290101, static function () use ($db): void {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS world_state_query_result (
                    query_id TEXT PRIMARY KEY,
                    query_name TEXT NOT NULL DEFAULT '',
                    is_true BOOLEAN NOT NULL,
                    game_ts BIGINT NOT NULL DEFAULT 0,
                    catalog_sha256 TEXT NOT NULL DEFAULT '',
                    first_observed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    last_evaluated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    changed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
                )"
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_world_state_query_result_value
                 ON world_state_query_result (is_true, query_name)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_world_state_query_result_evaluated
                 ON world_state_query_result (last_evaluated_at DESC)'
            );
        });

        $applyPatch('world_state_addendum', 202607290102, static function () use ($db): void {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS world_state_addendum (
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
                )"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS world_state_addendum_custom (
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
                )"
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_world_state_addendum_enabled
                 ON world_state_addendum (enabled, query_id)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_world_state_addendum_origin
                 ON world_state_addendum (origin, source_mod)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_world_state_addendum_custom_enabled
                 ON world_state_addendum_custom (enabled, query_id)'
            );
            $db->exec(
                "CREATE OR REPLACE VIEW combined_world_state_addendum AS
                 SELECT
                    c.query_id, c.query_name, c.source_mod, c.origin,
                    c.matched_topics, c.when_true, c.when_false, c.enabled,
                    ''::TEXT AS catalog_sha256, c.created_at, c.updated_at,
                    TRUE AS is_custom
                 FROM world_state_addendum_custom c
                 UNION ALL
                 SELECT
                    b.query_id, b.query_name, b.source_mod, b.origin,
                    b.matched_topics, b.when_true, b.when_false, b.enabled,
                    b.catalog_sha256, b.created_at, b.updated_at,
                    FALSE AS is_custom
                 FROM world_state_addendum b
                 LEFT JOIN world_state_addendum_custom c ON c.query_id = b.query_id
                 WHERE c.query_id IS NULL"
            );
        });

        $applyPatch('world_state_definition', 202607290103, static function () use ($db): void {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS world_state_definition (
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
                )"
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_world_state_definition_active
                 ON world_state_definition (active, query_name)'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_world_state_definition_source
                 ON world_state_definition (source_mod, active)'
            );
        });

        $applyPatch('general_settings', 202607290201, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES
                    ('ALWAYS_INSERT_LOCATION', 'true',
                     'When true, always inject matching World Knowledge for locations shown in the current prompt context.',
                     NOW()),
                    ('ALWAYS_INSERT_PEOPLE', 'true',
                     'When true, always inject matching World Knowledge for characters shown in the current prompt context.',
                     NOW())
                 ON CONFLICT (id) DO UPDATE
                 SET description = EXCLUDED.description,
                     updated_at = NOW()"
            );
        });

        $applyPatch('general_settings', 202607290202, static function () use ($db): void {
            $db->exec(
                "UPDATE general_settings
                 SET description = 'When true, always inject matching World Knowledge for characters shown in the current prompt context.',
                     updated_at = NOW()
                 WHERE id = 'ALWAYS_INSERT_PEOPLE'"
            );
        });

        $applyPatch('general_settings', 202607300101, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES (
                    'COMPACT_CHAT_HISTORY_ENABLED',
                    'true',
                    'Combine recent NPC chat history into a compact Markdown block in prompts. Narrator prompts are unchanged.',
                    NOW()
                 )
                 ON CONFLICT (id) DO NOTHING"
            );
        });

        $applyPatch('core_profiles', 202607290301, static function () use ($db): void {
            $db->exec(
                "ALTER TABLE core_profiles
                    ADD COLUMN IF NOT EXISTS llm_primary_id INT,
                    ADD COLUMN IF NOT EXISTS llm_secondary_id INT,
                    ADD COLUMN IF NOT EXISTS llm_tertiary_id INT,
                    ADD COLUMN IF NOT EXISTS llm_quaternary_id INT"
            );
            $db->exec(
                "UPDATE core_profiles
                 SET llm_primary_id = COALESCE(llm_primary_id, response_connector),
                     response_connector = COALESCE(response_connector, llm_primary_id)"
            );
            $foreignKeys = [
                'core_profiles_llm_primary_fk' => 'llm_primary_id',
                'core_profiles_llm_secondary_fk' => 'llm_secondary_id',
                'core_profiles_llm_tertiary_fk' => 'llm_tertiary_id',
                'core_profiles_llm_quaternary_fk' => 'llm_quaternary_id',
            ];
            foreach ($foreignKeys as $constraintName => $columnName) {
                $db->exec(
                    "DO $$
                     BEGIN
                        IF NOT EXISTS (
                            SELECT 1 FROM pg_constraint WHERE conname = '{$constraintName}'
                        ) THEN
                            ALTER TABLE core_profiles
                            ADD CONSTRAINT {$constraintName}
                            FOREIGN KEY ({$columnName}) REFERENCES core_llm_connector(id) ON DELETE SET NULL;
                        END IF;
                     END $$"
                );
            }
        });

        $applyPatch('core_profiles', 202607290302, static function () use ($db): void {
            $db->exec(
                "INSERT INTO conf_opts (id, value, updated_at)
                 VALUES ('stobe_profile_model', '1', NOW())
                 ON CONFLICT (id) DO NOTHING"
            );
            $db->exec(
                "UPDATE conf_opts
                 SET value = '1',
                     updated_at = NOW()
                 WHERE id = 'stobe_profile_model'
                   AND (value IS NULL OR value NOT IN ('1', '2', '3', '4'))"
            );
            $db->exec(
                "UPDATE core_profiles
                 SET metadata = metadata - 'LLM_RESPONSE_MODE',
                     updated_at = NOW()
                 WHERE jsonb_typeof(metadata) = 'object'
                   AND metadata ? 'LLM_RESPONSE_MODE'"
            );
        });

        $applyPatch('core_profiles', 202608020101, static function () use ($db): void {
            $db->exec(
                "INSERT INTO core_llm_connector (
                    name, connector_type, api_badge_id, api_key, base_url,
                    model, max_tokens, temperature, is_default, config
                 ) VALUES
                 (
                    'DeepSeek V4 Flash', 'openrouterjson',
                    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
                    '', 'https://openrouter.ai/api/v1/chat/completions', 'deepseek/deepseek-v4-flash',
                    750, 0.6, FALSE,
                    '{\"service\":\"openrouter\",\"provider\":\"openrouter\",\"reasoning_model\":true,\"enforce_json\":true,\"json_schema\":true,\"prefill_json\":false}'::jsonb
                 ),
                 (
                    'GLM 5.2', 'openrouterjson',
                    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
                    '', 'https://openrouter.ai/api/v1/chat/completions', 'z-ai/glm-5.2',
                    750, 1.0, FALSE,
                    '{\"service\":\"openrouter\",\"provider\":\"openrouter\",\"enforce_json\":true,\"json_schema\":true,\"prefill_json\":false}'::jsonb
                 ),
                 (
                    'DeepSeek V4 Pro', 'openrouterjson',
                    (SELECT id FROM core_api_badge WHERE LOWER(label) = 'openrouter' LIMIT 1),
                    '', 'https://openrouter.ai/api/v1/chat/completions', 'deepseek/deepseek-v4-pro',
                    750, 0.6, FALSE,
                    '{\"service\":\"openrouter\",\"provider\":\"openrouter\",\"enforce_json\":true,\"json_schema\":true,\"prefill_json\":false}'::jsonb
                 )
                 ON CONFLICT (name) DO NOTHING"
            );
            $db->exec(
                "UPDATE core_profiles
                 SET llm_primary_id = COALESCE(
                        llm_primary_id,
                        response_connector,
                        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
                        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'glm 4.7' LIMIT 1),
                        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'openrouter default' LIMIT 1)
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
                        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'deepseek v4 flash' LIMIT 1),
                        (SELECT id FROM core_llm_connector WHERE LOWER(name) = 'glm 4.7' LIMIT 1)
                     ),
                     updated_at = NOW()
                 WHERE COALESCE(is_default_npc, FALSE) = TRUE
                    OR COALESCE(is_player_faction_profile, FALSE) = TRUE"
            );
        });

        $applyPatch('player_bases', 202607300101, static function () use ($db): void {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS player_bases (
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
                    game_ts BIGINT NOT NULL DEFAULT 0,
                    last_seen_at TIMESTAMP NOT NULL DEFAULT NOW()
                )"
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_player_bases_last_seen
                 ON player_bases (last_seen_at DESC)'
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS player_base_presence (
                    scope_key TEXT PRIMARY KEY,
                    session_id TEXT NOT NULL,
                    observer_serial BIGINT NOT NULL DEFAULT 0,
                    observer_name TEXT NOT NULL DEFAULT '',
                    inside BOOLEAN NOT NULL DEFAULT FALSE,
                    base_id TEXT REFERENCES player_bases(base_id) ON DELETE SET NULL,
                    game_ts BIGINT NOT NULL DEFAULT 0,
                    observed_at TIMESTAMP NOT NULL DEFAULT NOW()
                )"
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_player_base_presence_observed
                 ON player_base_presence (inside, observed_at DESC)'
            );
        });

        $applyPatch('player_bases', 202607300102, static function () use ($db): void {
            $db->exec(
                "ALTER TABLE player_bases
                 ADD COLUMN IF NOT EXISTS details JSONB NOT NULL DEFAULT '{}'::jsonb"
            );
        });

        $applyPatch('player_bases', 202607300103, static function () use ($db): void {
            $db->exec(
                'ALTER TABLE player_bases
                 ADD COLUMN IF NOT EXISTS first_game_ts BIGINT NOT NULL DEFAULT 0,
                 ADD COLUMN IF NOT EXISTS last_game_ts BIGINT NOT NULL DEFAULT 0'
            );
            $db->exec(
                'UPDATE player_bases
                 SET last_game_ts = CASE WHEN last_game_ts <= 0 THEN game_ts ELSE last_game_ts END'
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_player_bases_game_range
                 ON player_bases (first_game_ts, last_game_ts)'
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS player_base_history (
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
                )"
            );
            $db->exec(
                'CREATE INDEX IF NOT EXISTS idx_player_base_history_rollback
                 ON player_base_history (game_ts DESC, base_id)'
            );
            $db->exec(
                "INSERT INTO player_base_history (
                    base_id, name, power_generated, power_required,
                    battery_charge, battery_capacity, battery_drain, battery_charging,
                    battery_mode, has_spare_power, members_inside,
                    has_gates, gates_closed, details, game_ts, observed_at
                 )
                 SELECT
                    base_id, name, power_generated, power_required,
                    battery_charge, battery_capacity, battery_drain, battery_charging,
                    battery_mode, has_spare_power, members_inside,
                    has_gates, gates_closed, details,
                    CASE WHEN first_game_ts > 0 THEN game_ts ELSE 0 END,
                    last_seen_at
                 FROM player_bases
                 ON CONFLICT (base_id, game_ts) DO NOTHING"
            );
        });

        $applyPatch('general_settings', 202607300102, static function () use ($db): void {
            $row = $db->fetchOne(
                "SELECT value
                 FROM general_settings
                 WHERE id = 'PROMPT_CONTEXT_OPTIONS'
                 LIMIT 1"
            );
            $options = json_decode(strval($row['value'] ?? ''), true);
            if (!is_array($options)) {
                $options = stobeGetDefaultPromptContextOptions();
            }
            if (!isset($options['enabled_sections']) || !is_array($options['enabled_sections'])) {
                $defaults = stobeGetDefaultPromptContextOptions();
                $options['enabled_sections'] = $defaults['enabled_sections'] ?? [];
            }
            if (!in_array('player_base', $options['enabled_sections'], true)) {
                $options['enabled_sections'][] = 'player_base';
            }
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES (
                    'PROMPT_CONTEXT_OPTIONS',
                    $1,
                    'Controls which prompt context blocks and subsections are included in Stobe system prompts. Managed from Global Settings.',
                    NOW()
                 )
                 ON CONFLICT (id) DO UPDATE SET
                    value = EXCLUDED.value,
                    description = EXCLUDED.description,
                    updated_at = NOW()",
                [json_encode($options, JSON_UNESCAPED_SLASHES)]
            );
        });

        $applyPatch('core_voiceid', 202607310001, static function () use ($runSqlSeedFile): void {
            $seedPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data'
                . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'stobe_voice_library_upsert.sql';
            $runSqlSeedFile(
                $seedPath,
                'Voice library seed file missing',
                'Voice library seed file empty',
                'Voice library seed file normalized to empty SQL',
                true,
                true
            );
        });

        $applyPatch('core_stt_connector', 202608050001, static function () use ($db): void {
            $db->exec("CREATE TABLE IF NOT EXISTS core_stt_connector (
                id SERIAL PRIMARY KEY,
                driver TEXT NOT NULL DEFAULT 'parakeet',
                label TEXT NOT NULL DEFAULT 'Global STT Connector',
                metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                api_badge_id INT REFERENCES core_api_badge(id) ON DELETE SET NULL,
                url TEXT
            )");
            $db->exec("INSERT INTO core_api_badge (label, api_key) VALUES ('Deepgram', ''), ('Azure', '') ON CONFLICT (label) DO NOTHING");
            $db->exec("INSERT INTO core_stt_connector (driver, label, metadata, url)
                SELECT 'parakeet', 'Global STT Connector',
                       '{\"LANGUAGE\":\"en\",\"TRANSLATE\":false,\"TIMEOUT\":60}'::jsonb,
                       'http://127.0.0.1:8022/v1/audio/transcriptions'
                WHERE NOT EXISTS (SELECT 1 FROM core_stt_connector)");
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at)
                SELECT 'GLOBAL_STT_CONNECTOR_ID', id::text, 'Active speech-to-text connector.', NOW()
                FROM core_stt_connector
                ORDER BY CASE WHEN driver = 'parakeet' THEN 0 ELSE 1 END, id ASC
                LIMIT 1
                ON CONFLICT (id) DO NOTHING");
        });

        $applyPatch('general_settings', 202608050001, static function () use ($db): void {
            $db->exec(
                "INSERT INTO general_settings (id, value, description, updated_at)
                 VALUES (
                    'PLAYER_DIALOGUE_AUDIO_ENABLED',
                    'true',
                    'Play TTS for when the selected player character speaks.',
                    NOW()
                 )
                 ON CONFLICT (id) DO NOTHING"
            );
        });

        $applyPatch('core_action', 202608300001, static function () use ($db): void {
            $description = 'End hostilities between your entire faction and the target actor\'s faction after agreeing to a ceasefire or recognizing a misunderstanding. Target a nearby actor from the opposing faction. Stops current combat on both sides and makes the two factions no longer enemies. Does not clear crimes or bounties.';
            $db->exec(
                "INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                 VALUES ('STOP_ATTACK', 'StopAttack', $1, TRUE, NOW())
                 ON CONFLICT (command) DO UPDATE SET
                    action_name = EXCLUDED.action_name,
                    description = EXCLUDED.description,
                    updated_at = NOW()",
                [$description]
            );
        });

        $applyPatch('relationship_preservation_settings', 202608280703, static function () use ($db): void {
            $db->exec("INSERT INTO general_settings (id, value, description, updated_at) VALUES
                ('NEVER_CLEAR_RELATIONSHIP_DATA','false','Keep current relationships when loading an older game save. Off by default. Can retain relationships from later events; does not carry them between saved playthrough snapshots.',NOW())
                ON CONFLICT (id) DO NOTHING");
        });

        $applyPatch('prompts', 202608260001, static function () use ($db): void {
            foreach (stobePlayerMoodPromptCatalog() as $mood => $prompt) {
                $db->exec(
                    "INSERT INTO prompts (prompt_key, default_prompt, description)
                     VALUES ($1, $2, $3) ON CONFLICT (prompt_key) DO NOTHING",
                    ['player_mood_' . $mood . '_prompt', $prompt, 'Player tone for ' . $mood . ' dialogue. Not spoken aloud.']
                );
            }
        });

        $applyPatch('core_tts_pronunciation', 202608300001, static function (): void {
            if (!stobeEnsureTtsPronunciationDictionary()) {
                throw new RuntimeException('Could not create the TTS pronunciation dictionary.');
            }
        });

        $applyPatch('core_tts_pronunciation', 202609010001, static function (): void {
            if (!stobeEnsureTtsPronunciationDictionary() || !stobeUnhyphenateBuiltinTtsPronunciations()) {
                throw new RuntimeException('Could not enable editable pronunciation defaults.');
            }
        });

        $applyPatch('stobe_settings_presets', 202608280701, static function () use ($db): void {
            $sql = file_get_contents(dirname(__DIR__) . '/data/settings_presets.sql');
            if ($sql === false || $db->exec($sql) === false) {
                throw new RuntimeException('Could not create settings preset store.');
            }
        });

        $applyPatch('core_action', 202609040001, static function () use ($db): void {
            $result = $db->exec("INSERT INTO core_action (command, action_name, description, is_activated, updated_at)
                VALUES ('MOVE_TO', 'MoveTo', 'Move to the specified character, object, or known point and stop nearby.', TRUE, NOW())
                ON CONFLICT (command) DO NOTHING");
            if ($result === false) {
                throw new RuntimeException('Could not register MoveTo.');
            }
        });

        try {
            $seededAddenda = stobeWorldStateSeedBuiltinAddenda();
            stobeLogInfo('World-state addenda seeded', ['rows' => $seededAddenda]);
        } catch (Throwable $exception) {
            stobeLogException($exception, 'World-state addendum seed failed');
        }

        $applyPatch('biography_voice_filters', 202609180001, static function () use ($db): void {
            if ($db->exec("ALTER TABLE bio_random ADD COLUMN IF NOT EXISTS tts_filter_preset TEXT") === false) throw new RuntimeException("Biography filter migration failed");
            if ($db->exec("ALTER TABLE bio_random_custom ADD COLUMN IF NOT EXISTS tts_filter_preset TEXT") === false) throw new RuntimeException("Biography filter migration failed");
            if ($db->exec("CREATE OR REPLACE VIEW combined_bio_random AS
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
                    c.is_enabled, c.tts_filter_preset
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
                    b.is_enabled, b.tts_filter_preset
                 FROM bio_random b
                 LEFT JOIN bio_random_custom c
                   ON LOWER(b.type) = LOWER(c.type)
                  AND LOWER(b.description) = LOWER(c.description)
                  AND LOWER(COALESCE(b.name, '')) = LOWER(COALESCE(c.name, ''))
                 WHERE c.id IS NULL") === false) throw new RuntimeException("Biography filter view migration failed");
            if ($db->exec("ALTER TABLE bio_unique ADD COLUMN IF NOT EXISTS tts_filter_preset TEXT") === false) throw new RuntimeException("Biography filter migration failed");
            if ($db->exec("ALTER TABLE bio_unique_custom ADD COLUMN IF NOT EXISTS tts_filter_preset TEXT") === false) throw new RuntimeException("Biography filter migration failed");
            if ($db->exec("CREATE OR REPLACE VIEW combined_bio_unique AS
                 SELECT
                    c.id,
                    c.name,
                    c.type,
                    c.description,
                    c.created_at,
                    c.updated_at,
                    c.is_enabled, c.tts_filter_preset
                 FROM bio_unique_custom c
                 UNION ALL
                 SELECT
                    b.id,
                    b.name,
                    b.type,
                    b.description,
                    b.created_at,
                    b.updated_at,
                    b.is_enabled, b.tts_filter_preset
                 FROM bio_unique b
                 LEFT JOIN bio_unique_custom c
                   ON LOWER(b.name) = LOWER(c.name)
                  AND LOWER(b.type) = LOWER(c.type)
                 WHERE c.id IS NULL") === false) throw new RuntimeException("Biography filter view migration failed");
        });

        $applyPatch('npc_plugin_extended_data', 20260919001, static function () use ($db): void {
            if (!$db->query(file_get_contents(dirname(__DIR__) . '/lib/core/database_schema/plugin_extended_data.sql'))) {
                throw new RuntimeException('NPC plugin data migration failed.');
            }
        });

        stobeLogInfo('DB updates completed (release consolidator)');
    }
}

stobeRunDatabaseUpdates();


// Install durable event accounting before refreshing the snapshot schema.
if ($GLOBALS['db']->query(file_get_contents(dirname(__DIR__) . '/lib/dynamic_profile_scheduler.sql')) === false) {
    throw new RuntimeException('Dynamic profile migration failed.');
}

// Keep the installed snapshot functions and pgAdmin comments aligned with the current table policy.
require_once dirname(__DIR__) . '/lib/playthrough_schema.php';
require_once dirname(__DIR__) . '/lib/playthrough_preferences.php';
require_once dirname(__DIR__) . '/lib/playthrough_retention.php';
$playthroughPolicyConn = ptp_connect();
if ($playthroughPolicyConn) {
    try {
        // Upgrade existing metadata before the first save/import; fresh setup also uses this helper.
        ptr_ensure_schema($playthroughPolicyConn);
        if (!pts_update_playthrough_policy($playthroughPolicyConn)) {
            Logger::error('Playthrough Save table policy update failed; retry the database update.');
        }
    } finally { pg_close($playthroughPolicyConn); }
} else {
    Logger::error('Cannot connect to update the Playthrough Save table policy.');
}
