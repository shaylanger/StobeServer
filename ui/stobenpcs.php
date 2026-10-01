<?php

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . "../";

require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "model_dynmodel.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "data_functions.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "utils_game_timestamp.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "npc_profile_lock_controls.php");

$GLOBALS["ENGINE_PATH"]=$enginePath;

require_once("{$enginePath}/lib/core/npc_master.class.php");

$CONF_SAMPLE_VARS=extract_assignments("$enginePath/lib/bootstrap.php");


//function renderSelect($obj, $fieldName, $labelText, $selectedValue = "") 
//function include from below file
include(__DIR__."/tmpl/ui_utils.php");
include_once(__DIR__."/tmpl/voice_filter_field.php");

// Determine web root and include site chrome like world_knowledge_upload
$scriptPath = $_SERVER['SCRIPT_NAME'];
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot == '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');

require_once(__DIR__.DIRECTORY_SEPARATOR."../profile_loader.php");

// Route AI profile generation through this page to avoid web-root/cmd path issues.
if (isset($_GET['action_ai_regen_profile']) && strval($_GET['action_ai_regen_profile']) === '1') {
    require_once(__DIR__ . DIRECTORY_SEPARATOR . 'cmd' . DIRECTORY_SEPARATOR . 'action_ai_regen_profile.php');
    exit;
}

function stobeUiResolveMetadataToggleOverride(array $metadata, string $settingKey): ?bool
{
    if (!array_key_exists($settingKey, $metadata)) {
        return null;
    }
    $raw = $metadata[$settingKey];
    if ($raw === '' || $raw === null) {
        return null;
    }
    return coerceBoolean($raw);
}

function stobeUiResolveMtmOverride(array $metadata, array $extended): ?bool
{
    $metadataOverride = stobeUiResolveMetadataToggleOverride($metadata, 'MIDDLE_TERM_MEMORY_ENABLED');
    if ($metadataOverride !== null) {
        return $metadataOverride;
    }
    if (array_key_exists('middle_term_enabled', $extended)) {
        $raw = $extended['middle_term_enabled'];
        if ($raw !== '' && $raw !== null) {
            return coerceBoolean($raw);
        }
    }
    return null;
}

function stobeUiResolveShortTermMaxOverride(array $metadata): ?int
{
    if (!array_key_exists('SHORT_TERM_MEMORY_MAX', $metadata)) {
        return null;
    }
    $raw = $metadata['SHORT_TERM_MEMORY_MAX'];
    if ($raw === '' || $raw === null || !is_numeric($raw)) {
        return null;
    }
    return max(1, min(50, intval($raw)));
}

function stobeUiShortTermProfileDefaults(array $profileMetadata): array
{
    $enabled = $profileMetadata['SHORT_TERM_MEMORY_ENABLED'] ?? null;
    $max = $profileMetadata['SHORT_TERM_MEMORY_MAX'] ?? null;
    return [
        'enabled' => coerceBoolean($enabled),
        'max' => (is_numeric($max) ? max(1, min(50, intval($max))) : 10),
    ];
}

/**
 * Merge the posted Short-Term Memory selects into an NPC metadata array.
 * Blank posted values remove the override so the profile value applies again.
 */
function stobeUiApplyShortTermMemoryOverrides(array $meta, array $post): array
{
    if (array_key_exists('short_term_memory_enabled', $post)) {
        $raw = $post['short_term_memory_enabled'];
        $raw = is_scalar($raw) ? trim((string)$raw) : '';
        if ($raw === '') {
            unset($meta['SHORT_TERM_MEMORY_ENABLED']);
        } else {
            $meta['SHORT_TERM_MEMORY_ENABLED'] = coerceBoolean($raw);
        }
    }
    if (array_key_exists('short_term_memory_max', $post)) {
        $raw = $post['short_term_memory_max'];
        $raw = is_scalar($raw) ? trim((string)$raw) : '';
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            unset($meta['SHORT_TERM_MEMORY_MAX']);
        } else {
            $meta['SHORT_TERM_MEMORY_MAX'] = max(1, min(50, intval($raw)));
        }
    }
    return $meta;
}

/**
 * Apply the Short-Term Memory selects to $_POST['metadata'] for the plain
 * (non-AJAX) create/update submits, which post the metadata textarea as-is.
 */
function stobeUiSyncShortTermMemoryPostMetadata(): void
{
    if (!array_key_exists('short_term_memory_enabled', $_POST)
        && !array_key_exists('short_term_memory_max', $_POST)) {
        return;
    }
    $meta = [];
    $postedMeta = isset($_POST['metadata']) ? trim((string)$_POST['metadata']) : '';
    if ($postedMeta !== '') {
        $decoded = json_decode($postedMeta, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        } else {
            // Unparseable metadata: leave it untouched rather than dropping data.
            return;
        }
    }
    $meta = stobeUiApplyShortTermMemoryOverrides($meta, $_POST);
    $_POST['metadata'] = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Read the stored voice-filter preset out of an NPC metadata array.
 * Tolerates legacy casing variants of the key.
 */
function stobeUiReadTtsFilterPresetFromMetadata(mixed $metadataValue): string
{
    $meta = is_array($metadataValue) ? $metadataValue : json_decode(strval($metadataValue), true);
    if (!is_array($meta)) {
        return 'none';
    }
    foreach ($meta as $metaKey => $metaValue) {
        if (strcasecmp(strval($metaKey), 'tts_filter_preset') === 0) {
            return stobeUiNormalizeVoiceFilterPreset($metaValue);
        }
    }
    return 'none';
}

/**
 * Merge the posted Voice Filter dropdown into an NPC metadata array.
 * The dropdown is the only editor for this key, so "none" removes the override
 * and every other key in the metadata payload is left untouched.
 */
function stobeUiApplyTtsFilterPresetOverride(array $meta, array $post): array
{
    if (!array_key_exists('tts_filter_preset', $post)) {
        return $meta;
    }
    foreach (array_keys($meta) as $metaKey) {
        if (strcasecmp(strval($metaKey), 'tts_filter_preset') === 0) {
            unset($meta[$metaKey]);
        }
    }
    $presetId = stobeUiNormalizeVoiceFilterPreset($post['tts_filter_preset']);
    if ($presetId !== 'none') {
        $meta['tts_filter_preset'] = $presetId;
    }
    return $meta;
}

/**
 * Apply the Voice Filter dropdown to $_POST['metadata'] for the plain
 * (non-AJAX) create/update submits, which post the metadata textarea as-is.
 */
function stobeUiSyncTtsFilterPresetPostMetadata(): void
{
    if (!array_key_exists('tts_filter_preset', $_POST)) {
        return;
    }
    $meta = [];
    $postedMeta = isset($_POST['metadata']) ? trim((string)$_POST['metadata']) : '';
    if ($postedMeta !== '') {
        $decoded = json_decode($postedMeta, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        } else {
            // Unparseable metadata: leave it untouched rather than dropping data.
            return;
        }
    }
    $meta = stobeUiApplyTtsFilterPresetOverride($meta, $_POST);
    $_POST['metadata'] = json_encode((object)$meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function stobeUiResolveIndividualMemoryEnabled(array $extended): bool
{
    if (!array_key_exists('individual_memory_enabled', $extended)) {
        return false;
    }
    $raw = $extended['individual_memory_enabled'];
    if ($raw === '' || $raw === null) {
        return false;
    }
    return coerceBoolean($raw);
}

function stobeUiPrepareRelationshipSavePayload(): bool
{
    if (!isset($_POST['relationships_jsonb']) || $_POST['relationships_jsonb'] === '') {
        return false;
    }

    $handler = __DIR__ . '/../ext/relationship_system/npc_save_handler.php';
    if (file_exists($handler)) {
        include $handler;
    }
    return true;
}

/**
 * Formats one directed faction standing for display. Returns a signed number, keeps a real
 * zero as "0", and reports "Unknown" when the snapshot carried no numeric value.
 */
function stobeUiFormatFactionStanding($relation): string
{
    if ($relation === null || !is_numeric($relation)) {
        return 'Unknown';
    }
    $rounded = round(floatval($relation), 2);
    if (abs($rounded - round($rounded)) < 0.005) {
        $text = strval(intval(round($rounded)));
    } else {
        $text = rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
    if ($rounded > 0 && strncmp($text, '-', 1) !== 0) {
        $text = '+' . $text;
    }
    return $text;
}

/**
 * Collects the status labels a faction standing row explicitly declares. Only the stored
 * alliance/war/coexists flags are used; no status is inferred from the numeric standing.
 */
function stobeUiFactionStandingFlags(array $relation): array
{
    $flags = [];
    if (!empty($relation['alliance'])) {
        $flags[] = 'Alliance';
    }
    if (!empty($relation['war'])) {
        $flags[] = 'War';
    }
    if (!empty($relation['coexists'])) {
        $flags[] = 'Coexists';
    }
    return $flags;
}

function stobeUiAutoLockProfileEnabled(): bool
{
    if (function_exists('getSettingBool')) {
        try {
            return getSettingBool('AUTO_LOCK_PROFILE', true);
        } catch (Throwable $exception) {
        }
    }

    $db = $GLOBALS['db'] ?? null;
    if ($db && method_exists($db, 'fetchOne')) {
        try {
            $row = $db->fetchOne("SELECT value FROM general_settings WHERE id = 'AUTO_LOCK_PROFILE' LIMIT 1");
            if (is_array($row) && array_key_exists('value', $row)) {
                return coerceBoolean($row['value']);
            }
        } catch (Throwable $exception) {
        }
    }

    return true;
}

function stobeUiHasCombinedBioTemplatesView(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $db = $GLOBALS['db'] ?? null;
    if (!$db || !method_exists($db, 'fetchOne')) {
        $cached = false;
        return $cached;
    }

    try {
        $row = $db->fetchOne("SELECT to_regclass('combined_bio_templates') AS reg_name");
        $cached = trim(strval($row['reg_name'] ?? '')) !== '';
    } catch (Throwable $exception) {
        $cached = false;
    }

    return $cached;
}

function stobeUiGetBioTemplateSourceParts(): array
{
    if (stobeUiHasCombinedBioTemplatesView()) {
        return [
            'from_sql' => 'combined_bio_templates cbt',
            'name_col' => 'npc_name',
            'core_col' => 'core',
            'refid_expr' => "COALESCE(refid, '')",
            'bio_expr' => "COALESCE(npc_static_bio, '')",
            'world_knowledge_expr' => "COALESCE(NULLIF(to_jsonb(cbt)->>'world_knowledge_tags', ''), '')",
        ];
    }

    return [
        'from_sql' => 'core_npc cbt',
        'name_col' => 'name',
        'core_col' => 'prompt_head',
        'refid_expr' => "COALESCE(cbt.metadata->>'storage_id', '')",
        'bio_expr' => "COALESCE(cbt.backstory, '')",
        'world_knowledge_expr' => "COALESCE(NULLIF(cbt.world_knowledge_tags, ''), '')",
    ];
}

if (!function_exists('renderStobeNpcLetterFilter')) {
    function renderStobeNpcLetterFilter(string $selectedLetter = ''): void
    {
        $selectedLetter = strtoupper(trim($selectedLetter));
        if (!preg_match('/^[A-Z]$/', $selectedLetter)) {
            $selectedLetter = '';
        }
        ?>
        <div class="npc-letter-filter">
          <input type="hidden" id="npc_letter_filter" value="<?= htmlspecialchars($selectedLetter, ENT_QUOTES) ?>" />
          <button type="button" class="npc-letter-btn<?= $selectedLetter === '' ? ' active' : '' ?>" data-letter="">All</button>
          <?php foreach (range('A', 'Z') as $char): ?>
            <button type="button" class="npc-letter-btn<?= $selectedLetter === $char ? ' active' : '' ?>" data-letter="<?= htmlspecialchars($char, ENT_QUOTES) ?>"><?= htmlspecialchars($char) ?></button>
          <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderStobeNpcToolbar')) {
    function renderStobeNpcToolbar(array $args = []): void
    {
        $top = !empty($args['top']);
        $suffix = $top ? '_top' : '';
        $q = strval($args['q'] ?? '');
        $nameLetterFilter = strtoupper(trim(strval($args['nameLetterFilter'] ?? '')));
        if (!preg_match('/^[A-Z]$/', $nameLetterFilter)) {
            $nameLetterFilter = '';
        }
        $profileRows = is_array($args['profileRows'] ?? null) ? $args['profileRows'] : [];
        $profileIdFilter = strval($args['profileIdFilter'] ?? '');
        $page = max(1, intval($args['page'] ?? 1));
        $totalPages = max(1, intval($args['totalPages'] ?? 1));
        $totalRows = max(0, intval($args['totalRows'] ?? 0));
        $favOnly = !empty($args['favOnly']);
        $dynOnly = !empty($args['dynOnly']);
        $mtmOnly = !empty($args['mtmOnly']);
        $lockOnly = !empty($args['lockOnly']);
        $playerFactionOnly = !empty($args['playerFactionOnly']);
        $pageWindow = min(10, $totalPages);
        $pageStart = max(1, min($page - 4, $totalPages - $pageWindow + 1));
        $pageEnd = min($totalPages, $pageStart + $pageWindow - 1);
        ?>
        <div class="pagination npc-toolbar" data-current-page="<?= (int)$page ?>" data-total-pages="<?= (int)$totalPages ?>">
          <div class="npc-toolbar-main">
            <div class="npc-toolbar-actions">
              <button id="npc_create_btn" type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action">+ Create NPC</button>
              <button id="npc_bulk_unlock_btn" type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action" title="Unlock every NPC profile except The Narrator">&#x1F513; Unlock All Profiles</button>
              <button id="npc_import_btn" type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action" title="Import NPC from JSON file">📥 Import NPC</button>
              <button id="rel_bulk_build_btn" type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action" title="Build JSONB relationships from recent NPC event history">🔗 Build Relationships</button>
              <button id="npc_bulk_switch_profile_btn" type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action npc-toolbar-btn-switch" title="Switch all NPCs from one profile to another">🔀 Mass Switch Profile</button>
              <button id="npc_bulk_delete_btn" type="button" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-danger" title="Delete all unlocked NPCs (excludes The Narrator and locked)">❌ Delete All Profiles</button>
            </div>
            <div class="npc-toolbar-tools">
              <input id="npc_search" type="text" placeholder="Search..." value="<?= htmlspecialchars($q) ?>" />
              <select id="npc_profile_filter" title="Filter by profile">
                <option value="">All Profiles</option>
                <?php foreach ($profileRows as $pr): ?>
                  <?php $pid = strval($pr['id'] ?? ''); $lbl = $pr['label'] ?? ('Profile #' . $pid); ?>
                  <option value="<?= htmlspecialchars($pid) ?>" <?= ($profileIdFilter !== '' && $profileIdFilter === $pid) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="npc-toolbar-subrow">
            <div class="npc-toolbar-pager">
              <button type="button" class="npc-letter-btn npc-page-link<?= $page <= 1 ? ' disabled' : '' ?>" data-page="1" <?= $page <= 1 ? 'disabled aria-disabled="true"' : '' ?>>First</button>
              <button type="button" class="npc-letter-btn npc-page-link<?= $page <= 1 ? ' disabled' : '' ?>" data-page="<?= max(1, $page - 1) ?>" <?= $page <= 1 ? 'disabled aria-disabled="true"' : '' ?>>Prev</button>
              <?php for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
                <button type="button" class="npc-letter-btn npc-page-link<?= $p === $page ? ' active' : '' ?>" data-page="<?= $p ?>" <?= $p === $page ? 'disabled aria-current="page"' : '' ?>><?= $p ?></button>
              <?php endfor; ?>
              <button type="button" class="npc-letter-btn npc-page-link<?= $page >= $totalPages ? ' disabled' : '' ?>" data-page="<?= min($totalPages, $page + 1) ?>" <?= $page >= $totalPages ? 'disabled aria-disabled="true"' : '' ?>>Next</button>
              <button type="button" class="npc-letter-btn npc-page-link<?= $page >= $totalPages ? ' disabled' : '' ?>" data-page="<?= $totalPages ?>" <?= $page >= $totalPages ? 'disabled aria-disabled="true"' : '' ?>>Last</button>
              <div class="npc-page-indicator" title="Current page"><?= $page ?>/<?= $totalPages ?></div>
            </div>
          </div>
          <div class="npc-toolbar-letter-row">
            <?php renderStobeNpcLetterFilter($nameLetterFilter); ?>
            <label class="npc-auto-lock-profile" title="When enabled, saving an NPC profile automatically locks it to prevent history updates from overwriting manual edits.">
              <input id="npc_auto_lock_profile" type="checkbox" <?= stobeUiAutoLockProfileEnabled() ? 'checked' : '' ?>>
              Auto Lock Profiles on Edit
            </label>
            <div class="npc-toolbar-summary">
              <div class="npc-filter-dropdown">
                <button type="button" id="npc_filter_btn<?= $suffix ?>" class="npc-toolbar-btn npc-toolbar-btn-uniform npc-toolbar-btn-action npc-toolbar-filter-btn" title="Filters" aria-label="Filters">▾ Filters</button>
                <div id="npc_filter_menu<?= $suffix ?>" class="npc-filter-menu" style="display:none;">
                  <label><input type="checkbox" id="npc_filter_fav<?= $suffix ?>" <?= $favOnly ? 'checked' : '' ?>> ⭐ Favorites</label>
                  <label><input type="checkbox" id="npc_filter_dyn<?= $suffix ?>" <?= $dynOnly ? 'checked' : '' ?>> ♻️ Dynamic profile</label>
                  <label><input type="checkbox" id="npc_filter_mtm<?= $suffix ?>" <?= $mtmOnly ? 'checked' : '' ?>> 📃 Middle-term memory</label>
                  <label><input type="checkbox" id="npc_filter_lock<?= $suffix ?>" <?= $lockOnly ? 'checked' : '' ?>> 🔒 Locked</label>
                  <label><input type="checkbox" id="npc_filter_pf<?= $suffix ?>" <?= $playerFactionOnly ? 'checked' : '' ?>> 🛡️ Player faction only</label>
                </div>
              </div>
              <div class="npc-total-pill" title="Total NPC profiles">
                <div class="npc-total-pill-icon">👥</div>
                <div class="npc-total-pill-value"><?= $totalRows ?></div>
              </div>
            </div>
          </div>
        </div>
        <?php
    }
}

$TITLE = "Stobe - NPC Master";
ob_start();
include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/head.html");
?>

<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
<link rel="stylesheet" href="css/npc_event_history.css">
<style>
/* Core styling alignment */
@font-face {
    font-family: 'MagicCards';
    src: url('<?php echo $webRoot; ?>/ui/css/font/MailartRubberstamp-Regular.otf') format('opentype');
    font-weight: normal;
    font-style: normal;
}
main {
    padding-top: 10px;
    padding-bottom: 24px;
}

/* Relationship Build Button - Gray/Orange theme to match UI */
.btn-rel-build {
    background: rgba(58, 58, 74, 0.8);
    color: #e6b76c;
    border: 1px solid rgba(230, 183, 108, 0.5);
    padding: 8px 14px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 600;
}
.btn-rel-build:hover {
    background: rgba(74, 74, 90, 0.9);
    border-color: #e6b76c;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(230, 183, 108, 0.2);
}

/* Relationship Build Modal - Gray/Orange theme */
.rel-build-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
    justify-content: center;
    align-items: center;
}
.rel-build-modal-overlay.show { display: flex; }
.rel-build-modal {
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.98), rgba(34, 34, 34, 0.98));
    border: 1px solid #3a3a3a;
    border-radius: 12px;
    padding: 32px;
    max-width: 600px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.6), 
                0 0 30px rgba(230, 183, 108, 0.1);
}
.rel-build-modal h2 {
    margin: 0 0 22px 0;
    color: #e6b76c;
    font-family: 'MagicCards', serif;
    font-size: 1.7em;
    text-align: center;
    text-shadow: 0 0 15px rgba(230, 183, 108, 0.4);
}
.rel-build-modal .modal-body {
    color: #e0e0e0;
    line-height: 1.6;
    margin-bottom: 20px;
    text-align: center;
}
.rel-build-modal .modal-body p { margin: 12px 0; }
.rel-build-modal .modal-body strong { color: #e6b76c; }
.rel-build-modal .stats-box {
    background: linear-gradient(135deg, rgba(26, 26, 26, 0.9), rgba(20, 20, 20, 0.95));
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    padding: 16px;
    margin: 16px 0;
    display: flex;
    justify-content: space-around;
    text-align: center;
    box-shadow: inset 0 1px rgba(255, 255, 255, 0.03);
}
.rel-build-modal .stat-item { }
.rel-build-modal .stat-value { font-size: 2em; color: #e6b76c; font-weight: bold; text-shadow: 0 0 10px rgba(230, 183, 108, 0.3); }
.rel-build-modal .stat-label { font-size: 0.85em; color: #999; margin-top: 4px; }
.rel-build-modal .progress-section { display: none; margin: 20px 0; }
.rel-build-modal .progress-section.show { display: block; }
.rel-build-modal .progress-bar-wrap {
    background: rgba(26, 26, 26, 0.9);
    border-radius: 8px;
    height: 26px;
    overflow: hidden;
    margin: 12px 0;
    border: 1px solid #3a3a3a;
}
.rel-build-modal .progress-bar {
    background: linear-gradient(90deg, rgb(200, 100, 10), #e6b76c);
    height: 100%;
    width: 0%;
    transition: width 0.3s ease;
    border-radius: 7px;
    box-shadow: 0 0 10px rgba(230, 183, 108, 0.5);
}
.rel-build-modal .progress-text {
    text-align: center;
    color: #e6b76c;
    font-size: 0.9em;
    margin-top: 8px;
}
.rel-build-modal .progress-log {
    background: rgba(10, 10, 10, 0.9);
    border: 1px solid #333;
    border-radius: 8px;
    padding: 12px;
    max-height: 150px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 0.8em;
    color: #999;
    margin-top: 12px;
}
.rel-build-modal .progress-log .success { color: #4ade80; }
.rel-build-modal .progress-log .error { color: #f87171; }
.rel-build-modal .progress-log .skip { color: #e6b76c; }
.rel-build-modal .modal-actions {
    display: flex;
    gap: 14px;
    justify-content: center;
    margin-top: 22px;
}
.rel-build-modal .btn-start {
    background: rgba(58, 58, 58, 0.9);
    color: #e6b76c;
    border: 1px solid rgba(230, 183, 108, 0.5);
    padding: 12px 32px;
    border-radius: 8px;
    font-size: 1em;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
}
.rel-build-modal .btn-start:hover { 
    background: rgba(74, 74, 74, 0.9); 
    border-color: #e6b76c;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(230, 183, 108, 0.3);
}
.rel-build-modal .btn-start:disabled { 
    background: #222; 
    color: #555; 
    border-color: #444; 
    cursor: not-allowed; 
    transform: none;
    box-shadow: none;
}
.rel-build-modal .btn-cancel {
    background: rgba(58, 58, 58, 0.9);
    color: #e6b76c;
    border: 1px solid rgba(230, 183, 108, 0.5);
    padding: 12px 32px;
    border-radius: 8px;
    font-size: 1em;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 600;
}
.rel-build-modal .btn-cancel:hover { 
    background: rgba(74, 74, 74, 0.9); 
    border-color: #e6b76c;
    transform: translateY(-1px);
}
.rel-build-modal .connector-info {
    background: rgba(230, 183, 108, 0.1);
    border: 1px solid rgba(230, 183, 108, 0.5);
    border-radius: 8px;
    padding: 12px;
    margin: 12px 0;
    font-size: 0.9em;
    text-align: center;
    color: #34d399;
}
.rel-build-modal .connector-info .connector-model {
    color: #bbb;
    font-size: 0.9em;
}
.rel-build-modal .no-connector {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.5);
    color: #f87171;
}
</style>

<main>

<?php
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}
$npc = new NpcMaster();

// Check if The Narrator exists in core_npc_master (for informational note)
$narratorExistsInNpcMaster = false;
try {
    $narratorExistsInNpcMaster = ($npc->getByName('The Narrator') !== false);
} catch (Throwable $e) {
    // Ignore errors
}

$lastInfoRow = $GLOBALS["db"]->fetchOne("select max(gamets) as gamets from eventlog where type='infosave'");
$LAST_INFOSAVE_EVENT = intval($lastInfoRow["gamets"] ?? 0);

// Helper: map gender text to an icon character
if (!function_exists('gender_icon_char')) {
    function gender_icon_char($gender){
        $g = strtolower(trim((string)$gender));
        if ($g === '') return '';
        if ($g === 'female' || $g === 'f' || $g === 'woman' || $g === 'girl') return 'F';
        if ($g === 'male' || $g === 'm' || $g === 'man' || $g === 'boy') return 'M';
        if ($g === 'nonbinary' || $g === 'non-binary' || $g === 'nb' || $g === 'enby' || $g === 'other' || $g === 'agender' || $g === 'genderfluid') return 'N';
        return '';
    }
}

// Helper: map gender text to a CSS class suffix for coloring
if (!function_exists('gender_icon_class')) {
    function gender_icon_class($gender){
        $g = strtolower(trim((string)$gender));
        if ($g === 'female' || $g === 'f' || $g === 'woman' || $g === 'girl') return 'gender-female';
        if ($g === 'male' || $g === 'm' || $g === 'man' || $g === 'boy') return 'gender-male';
        if ($g === 'nonbinary' || $g === 'non-binary' || $g === 'nb' || $g === 'enby' || $g === 'other' || $g === 'agender' || $g === 'genderfluid') return 'gender-nb';
        return '';
    }
}

if (!function_exists('stobe_ui_resolve_portrait_url')) {
    function stobe_ui_resolve_portrait_url(array $metadata, string $webRoot = ''): string {
        $candidate = '';
        if (isset($metadata['portrait']) && is_array($metadata['portrait'])) {
            $portrait = $metadata['portrait'];
            foreach (['web_path', 'url', 'path'] as $key) {
                $value = trim(strval($portrait[$key] ?? ''));
                if ($value !== '') {
                    $candidate = $value;
                    break;
                }
            }
        }
        if ($candidate === '') {
            foreach (['portrait_url', 'portrait_path'] as $key) {
                $value = trim(strval($metadata[$key] ?? ''));
                if ($value !== '') {
                    $candidate = $value;
                    break;
                }
            }
        }
        if ($candidate === '') {
            return '';
        }

        $candidate = str_replace('\\', '/', $candidate);
        if (preg_match('/^https?:\\/\\//i', $candidate) === 1) {
            return $candidate;
        }
        if (strpos($candidate, '/StobeServer/') === 0) {
            return $candidate;
        }
        if (strpos($candidate, '/data/portraits/') === 0) {
            return '/StobeServer' . $candidate;
        }
        if (strpos($candidate, 'data/portraits/') === 0) {
            return '/StobeServer/' . ltrim($candidate, '/');
        }

        $prefix = rtrim($webRoot, '/');
        if ($prefix !== '') {
            if ($candidate[0] !== '/') {
                $candidate = '/' . $candidate;
            }
            return $prefix . $candidate;
        }
        return ($candidate[0] === '/') ? $candidate : ('/' . $candidate);
    }
}

if (!function_exists('stobe_ui_portrait_fallback_char')) {
    function stobe_ui_portrait_fallback_char(string $name): string {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '?';
        }
        $ch = strtoupper(substr($trimmed, 0, 1));
        if ($ch === '') {
            return '?';
        }
        return $ch;
    }
}

if (!function_exists('stobe_ui_format_bounty_summary')) {
    function stobe_ui_format_bounty_summary(mixed $bountyValue, mixed $bountyPayloadValue, array $metadata): array {
        $bountyPayload = stobeNormalizeBountyPayload($bountyPayloadValue);
        if (count($bountyPayload) === 0) {
            $bountyPayload = stobeNormalizeBountyPayload($bountyValue);
        }

        $metadataBountyInfo = $metadata['bounty_info'] ?? null;
        if ($metadataBountyInfo !== null) {
            $bountyPayload = stobeNormalizeBountyPayload($bountyPayload, $metadataBountyInfo);
        }

        $bountyAmount = stobeBountyAmountFromPayload($bountyPayload);
        if ($bountyAmount <= 0) {
            $bountyAmount = stobeBountyAmountFromPayload($bountyValue);
        }
        $amountText = $bountyAmount > 0 ? (number_format($bountyAmount) . ' cats') : '0';
        $detailsText = '';
        $breakdownItems = [];
        $breakdownExtra = 0;
        $legacyDetails = '';

        if (!is_array($bountyPayload) || count($bountyPayload) === 0) {
            return [
                'amount_text' => $amountText,
                'details_text' => '',
                'breakdown_items' => [],
                'breakdown_extra' => 0,
                'legacy_details' => '',
            ];
        }

        $factions = $bountyPayload['factions'] ?? [];
        if (!is_array($factions) || count($factions) === 0) {
            $legacyText = trim(strval($metadata['bounty_text'] ?? ''));
            return [
                'amount_text' => $amountText,
                'details_text' => $legacyText,
                'breakdown_items' => [],
                'breakdown_extra' => 0,
                'legacy_details' => $legacyText,
            ];
        }

        $chunks = [];
        $maxFactionsToShow = 2;
        $maxBreakdownItems = 4;
        foreach ($factions as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (count($chunks) >= $maxFactionsToShow) {
                // Continue parsing to support full breakdown and accurate extra count.
                continue;
            }

            $factionName = trim(strval($entry['faction'] ?? ($entry['faction_id'] ?? 'Unknown faction')));
            $entryAmount = intval($entry['amount'] ?? 0);
            $reasonsRaw = $entry['what_for'] ?? [];
            $reasons = [];
            if (is_array($reasonsRaw)) {
                foreach ($reasonsRaw as $reason) {
                    $reasonText = trim(strval($reason));
                    if ($reasonText !== '') {
                        $reasons[] = $reasonText;
                    }
                }
            } elseif (is_string($reasonsRaw) && trim($reasonsRaw) !== '') {
                $reasons = array_filter(array_map('trim', explode(',', $reasonsRaw)), fn($v) => $v !== '');
            }
            if (count($reasons) > 6) {
                $reasons = array_slice($reasons, 0, 6);
            }

            if (count($breakdownItems) < $maxBreakdownItems) {
                $breakdownItems[] = [
                    'faction' => $factionName,
                    'amount_text' => $entryAmount > 0 ? (number_format($entryAmount) . ' cats') : '',
                    'reasons_text' => count($reasons) > 0 ? implode(', ', $reasons) : '',
                ];
            } else {
                $breakdownExtra++;
            }

            $segment = $factionName;
            if ($entryAmount > 0 && (count($factions) > 1 || $entryAmount !== $bountyAmount)) {
                $segment .= ' (' . number_format($entryAmount) . ')';
            }
            if (count($reasons) > 0) {
                $segment .= ' - Wanted for: ' . implode(', ', $reasons);
            }
            $chunks[] = $segment;
        }

        if (count($factions) > $maxFactionsToShow) {
            $chunks[] = '+' . (count($factions) - $maxFactionsToShow) . ' more faction(s)';
        }

        $detailsText = implode(' | ', $chunks);
        return [
            'amount_text' => $amountText,
            'details_text' => $detailsText,
            'breakdown_items' => $breakdownItems,
            'breakdown_extra' => $breakdownExtra,
            'legacy_details' => $legacyDetails,
        ];
    }
}

if (!function_exists('stobeUiNpcIsInPlayerFaction')) {
    function stobeUiParseFactionIdentity(string $rawFaction): array {
        if (function_exists('parseFactionIdentityToken')) {
            return parseFactionIdentityToken($rawFaction);
        }
        $raw = trim($rawFaction);
        if ($raw === '') {
            return ['name' => '', 'id' => ''];
        }

        $name = $raw;
        $id = '';
        if (preg_match('/^(.*?)\s*\[([^\]]+)\]\s*$/u', $raw, $matches) === 1) {
            $parsedName = trim(strval($matches[1] ?? ''));
            $parsedId = trim(strval($matches[2] ?? ''));
            if ($parsedId !== '') {
                $id = $parsedId;
                $name = $parsedName !== '' ? $parsedName : $raw;
            }
        }
        $name = trim(strval(preg_replace('/\s*\[[^\]]+\]\s*$/u', '', $name)));
        return ['name' => $name, 'id' => $id];
    }

    function stobeUiExtractFactionIdentityFromRow(array $npcRow): array {
        $identity = stobeUiParseFactionIdentity(strval($npcRow['faction'] ?? ''));

        $metadata = $npcRow['metadata'] ?? [];
        if (!is_array($metadata) && function_exists('normalizeNpcMetadataPayload')) {
            $metadata = normalizeNpcMetadataPayload($metadata);
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadataFactionId = trim(strval($metadata['faction_id'] ?? ($metadata['factionID'] ?? '')));
        if ($identity['id'] === '' && $metadataFactionId !== '') {
            $identity['id'] = $metadataFactionId;
        }

        if ($identity['name'] === '') {
            $metadataFaction = trim(strval($metadata['faction'] ?? ''));
            if ($metadataFaction !== '') {
                $metaIdentity = stobeUiParseFactionIdentity($metadataFaction);
                if ($metaIdentity['name'] !== '') {
                    $identity['name'] = $metaIdentity['name'];
                }
                if ($identity['id'] === '' && $metaIdentity['id'] !== '') {
                    $identity['id'] = $metaIdentity['id'];
                }
            }
        }

        return $identity;
    }

    function stobeUiGetPlayerFactionMemberSet(): array {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $members = [];
        $addMember = static function (string $rawName) use (&$members): void {
            $name = function_exists('normalizeParticipantNameToken')
                ? normalizeParticipantNameToken($rawName)
                : trim($rawName);
            if ($name === '') {
                return;
            }
            $key = strtolower($name);
            if (!isset($members[$key])) {
                $members[$key] = $name;
            }
        };

        if (function_exists('getPlayerSquadMembershipSnapshot')) {
            try {
                $snapshot = getPlayerSquadMembershipSnapshot();
                $squads = is_array($snapshot['squad_members'] ?? null) ? $snapshot['squad_members'] : [];
                foreach ($squads as $squadMembers) {
                    if (!is_array($squadMembers)) {
                        continue;
                    }
                    foreach ($squadMembers as $memberName) {
                        $addMember(strval($memberName));
                    }
                }
            } catch (Throwable $exception) {
            }
        }

        $playerName = trim(strval(getSetting('PLAYER_NAME', '')));
        if ($playerName !== '') {
            $addMember($playerName);
        }

        $cached = $members;
        return $cached;
    }

    function stobeUiResolvePlayerFactionIdentity(): array {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $identity = ['name' => '', 'id' => ''];
        if (function_exists('getCurrentPlayerFactionIdentity')) {
            try {
                $candidate = getCurrentPlayerFactionIdentity();
                if (is_array($candidate)) {
                    $identity = [
                        'name' => trim(strval($candidate['name'] ?? '')),
                        'id' => trim(strval($candidate['id'] ?? '')),
                    ];
                }
            } catch (Throwable $exception) {
            }
        }
        if ($identity['name'] !== '' || $identity['id'] !== '') {
            $cached = $identity;
            return $cached;
        }

        $db = $GLOBALS['db'] ?? null;
        if (!$db || !method_exists($db, 'fetchOne')) {
            $cached = $identity;
            return $cached;
        }

        $members = stobeUiGetPlayerFactionMemberSet();
        if (count($members) === 0) {
            $cached = $identity;
            return $cached;
        }

        $bestGamets = -1;
        foreach ($members as $memberName) {
            $safeMember = trim(strval($memberName));
            if ($safeMember === '') {
                continue;
            }
            $row = $db->fetchOne(
                "SELECT faction, metadata, gamets_last_updated
                 FROM core_npc
                 WHERE LOWER(name) = LOWER($1)
                 ORDER BY gamets_last_updated DESC, updated_at DESC
                 LIMIT 1",
                [$safeMember]
            );
            if (!$row) {
                continue;
            }

            $candidateIdentity = stobeUiExtractFactionIdentityFromRow($row);
            if ($candidateIdentity['name'] === '' && $candidateIdentity['id'] === '') {
                continue;
            }

            $rowGamets = intval($row['gamets_last_updated'] ?? 0);
            if ($rowGamets >= $bestGamets) {
                $identity = $candidateIdentity;
                $bestGamets = $rowGamets;
            }
        }

        $cached = $identity;
        return $cached;
    }

    function stobeUiNpcIsInPlayerFaction(array $npcRow): bool {
        if (function_exists('npcIsInPlayerFaction')) {
            try {
                if (npcIsInPlayerFaction($npcRow)) {
                    return true;
                }
            } catch (Throwable $exception) {
            }
        }

        $playerFaction = stobeUiResolvePlayerFactionIdentity();
        $npcFaction = stobeUiExtractFactionIdentityFromRow($npcRow);
        $playerFactionId = trim(strval($playerFaction['id'] ?? ''));
        $npcFactionId = trim(strval($npcFaction['id'] ?? ''));
        if ($playerFactionId !== '' && $npcFactionId !== '') {
            if (strcasecmp($playerFactionId, $npcFactionId) === 0) {
                return true;
            }
        }

        $playerFactionName = trim(strval($playerFaction['name'] ?? ''));
        $npcFactionName = trim(strval($npcFaction['name'] ?? ''));
        if ($playerFactionName !== '' && $npcFactionName !== '') {
            if (strcasecmp($playerFactionName, $npcFactionName) === 0) {
                return true;
            }
        }

        $npcName = strval($npcRow['npc_name'] ?? ($npcRow['name'] ?? ''));
        $npcName = function_exists('normalizeParticipantNameToken')
            ? normalizeParticipantNameToken($npcName)
            : trim($npcName);
        if ($npcName === '') {
            return false;
        }
        $members = stobeUiGetPlayerFactionMemberSet();
        return isset($members[strtolower($npcName)]);
    }
}

if (!function_exists('stobeUiFactionCardLabel')) {
    // Format the stored faction for cards, using the cached player-faction alias when set.
    function stobeUiFactionCardLabel(array $npcRow): string {
        $identity = function_exists('stobeUiExtractFactionIdentityFromRow')
            ? stobeUiExtractFactionIdentityFromRow($npcRow)
            : ['name' => '', 'id' => ''];
        $name = trim(strval($identity['name'] ?? ''));
        if ($name === '') {
            return 'Unknown';
        }

        if (function_exists('stobeResolvePlayerFactionPromptDisplayName')) {
            try {
                $aliased = trim(stobeResolvePlayerFactionPromptDisplayName($name, $identity));
                if ($aliased !== '') {
                    return $aliased;
                }
            } catch (Throwable $exception) {
            }
        }

        return $name;
    }
}

if (!function_exists('stobeUiSortNpcRows')) {
    function stobeUiSortNpcRows(array $rows, string $alpha = 'asc'): array {
        $direction = strtolower(trim($alpha)) === 'desc' ? 'desc' : 'asc';
        usort($rows, static function (array $a, array $b) use ($direction): int {
            $aLocked = coerceBoolean($a['lock_profile'] ?? false) ? 1 : 0;
            $bLocked = coerceBoolean($b['lock_profile'] ?? false) ? 1 : 0;
            if ($aLocked !== $bLocked) {
                return $bLocked <=> $aLocked;
            }

            $aFavorite = coerceBoolean($a['npc_favorite'] ?? false) ? 1 : 0;
            $bFavorite = coerceBoolean($b['npc_favorite'] ?? false) ? 1 : 0;
            if ($aFavorite !== $bFavorite) {
                return $bFavorite <=> $aFavorite;
            }

            $aPlayerFaction = stobeUiNpcIsInPlayerFaction($a) ? 1 : 0;
            $bPlayerFaction = stobeUiNpcIsInPlayerFaction($b) ? 1 : 0;
            if ($aPlayerFaction !== $bPlayerFaction) {
                return $bPlayerFaction <=> $aPlayerFaction;
            }

            $aGamets = intval($a['gamets_last_updated'] ?? 0);
            $bGamets = intval($b['gamets_last_updated'] ?? 0);
            if ($aGamets !== $bGamets) {
                return $bGamets <=> $aGamets;
            }

            $aName = strtolower(trim(strval($a['npc_name'] ?? ($a['name'] ?? ''))));
            $bName = strtolower(trim(strval($b['npc_name'] ?? ($b['name'] ?? ''))));
            $nameCmp = strcmp($aName, $bName);
            if ($nameCmp !== 0) {
                return $direction === 'desc' ? (0 - $nameCmp) : $nameCmp;
            }

            return intval($a['id'] ?? 0) <=> intval($b['id'] ?? 0);
        });
        return $rows;
    }
}

// Handle Create
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create"])) {
    $relationshipSave = stobeUiPrepareRelationshipSavePayload();
    stobeUiSyncShortTermMemoryPostMetadata();
    stobeUiSyncTtsFilterPresetPostMetadata();
    if (stobeUiAutoLockProfileEnabled()) {
        $_POST['lock_profile'] = 1;
    }
    $createdId = $relationshipSave
        ? stobeRunWithRelationshipExtendedDataWrite(static fn(): int => $npc->create($_POST), 0, true)
        : $npc->create($_POST);
    if ($relationshipSave && $createdId > 0) {
        stobeRelationshipTimelineStamp($createdId);
    }
    header("Location: npc_master.php");
    exit;
}

// Handle Update
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update"])) {
    $relationshipSave = stobeUiPrepareRelationshipSavePayload();
    stobeUiSyncShortTermMemoryPostMetadata();
    stobeUiSyncTtsFilterPresetPostMetadata();
    if (stobeUiAutoLockProfileEnabled()) {
        $_POST['lock_profile'] = 1;
    }
    $_POST["md5"]=md5($_POST["npc_name"]);
    $saveResult = $relationshipSave
        ? stobeRunWithRelationshipExtendedDataWrite(
            static function () use ($npc): bool {
                $result = $npc->update($_POST["id"], $_POST);
                if ($result !== false) {
                    stobeRelationshipTimelineStamp(intval($_POST['id'] ?? 0));
                }
                return $result;
            },
            intval($_POST['id'] ?? 0),
            true
        )
        : $npc->update($_POST["id"], $_POST);
    header("Location: npc_master.php");
    exit;
}

// Inline update (AJAX) for modal save
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["inline_update_npc"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);

        $relationshipSave = stobeUiPrepareRelationshipSavePayload();

        // Ensure extended_data is valid JSON and sync NPC-only toggles.
        try {
            $postedExt = isset($_POST['extended_data']) ? (string)$_POST['extended_data'] : '';
            $tmp = [];
            if ($postedExt !== '') {
                $decoded = json_decode($postedExt, true);
                if (is_array($decoded)) {
                    $tmp = $decoded;
                }
            }
            if (array_key_exists('individual_memory_enabled', $_POST)) {
                $imbVal = $_POST['individual_memory_enabled'];
                if ($imbVal === '' || $imbVal === null || !coerceBoolean($imbVal)) {
                    unset($tmp['individual_memory_enabled']);
                } else {
                    $tmp['individual_memory_enabled'] = 1;
                }
            }
            $_POST['extended_data'] = json_encode($tmp);
        } catch (Throwable $e) {
            $_POST['extended_data'] = '{}';
        }

        // Persist Dynamic Profile / Middle Term toggles as metadata overrides.
        try {
            $postedMeta = isset($_POST['metadata']) ? (string)$_POST['metadata'] : '';
            $meta = [];
            if ($postedMeta !== '') {
                $tmpMeta = json_decode($postedMeta, true);
                if (is_array($tmpMeta)) {
                    $meta = $tmpMeta;
                }
            }

            if (array_key_exists('dynamic_profile', $_POST)) {
                $dynVal = $_POST['dynamic_profile'];
                if ($dynVal === '' || $dynVal === null) {
                    unset($meta['DYNAMIC_PROFILE_ENABLED']);
                } else {
                    $meta['DYNAMIC_PROFILE_ENABLED'] = coerceBoolean($dynVal);
                }
            }
            if (array_key_exists('auto_diary_enabled', $_POST)) {
                $autoDiaryVal = $_POST['auto_diary_enabled'];
                if ($autoDiaryVal === '' || $autoDiaryVal === null) {
                    unset($meta['AUTO_DIARY_ENABLED']);
                } else {
                    $meta['AUTO_DIARY_ENABLED'] = coerceBoolean($autoDiaryVal);
                }
            }
            $meta = stobeUiApplyShortTermMemoryOverrides($meta, $_POST);
            $meta = stobeUiApplyTtsFilterPresetOverride($meta, $_POST);
            $_POST['metadata'] = json_encode($meta);
        } catch (Throwable $e) {
            if (!isset($_POST['metadata']) || trim((string)$_POST['metadata']) === '') {
                $_POST['metadata'] = '{}';
            }
        }
        if (stobeUiAutoLockProfileEnabled()) {
            $_POST['lock_profile'] = 1;
        }
        if ($id <= 0) {
            $newId = $relationshipSave
                ? stobeRunWithRelationshipExtendedDataWrite(static fn(): int => $npc->create($_POST), 0, true)
                : $npc->create($_POST);
            if ($newId <= 0) {
                echo json_encode(["ok"=>false, "error"=>($npc->getLastError() ?: "Insert failed")]);
                exit;
            }
            if ($relationshipSave) {
                stobeRelationshipTimelineStamp($newId);
            }
            echo json_encode(["ok"=>true, "id"=>$newId]);
        } else {
            $_POST["md5"]=md5($_POST["npc_name"]);
            $ok = $relationshipSave
                ? stobeRunWithRelationshipExtendedDataWrite(
                    static function () use ($npc, $id): bool {
                        $result = $npc->update($id, $_POST);
                        if ($result !== false) {
                            stobeRelationshipTimelineStamp($id);
                        }
                        return $result;
                    },
                    $id,
                    true
                )
                : $npc->update($id, $_POST);
            $npc->backupNpcById($id);// We also make a backup of manually edited NPCs, so when loading a save, will load this record
            if ($ok === false) {
                echo json_encode(["ok"=>false, "error"=>($npc->getLastError() ?? 'Update failed')]);
            } else {
                echo json_encode(["ok"=>true, "id"=>$id]);
            }
        }
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Save global auto-lock preference (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["set_auto_lock_profile"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $enabled = stobeSetAutoLockProfileSetting($_POST['auto_lock_profile'] ?? '0');
        echo json_encode(["ok" => true, "enabled" => $enabled]);
    } catch (Throwable $e) {
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// Toggle favorite (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_favorite"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid id"]); exit; }
        $rowBefore = $npc->getById($id);
        $current = coerceBoolean($rowBefore['npc_favorite'] ?? false) ? 1 : 0;
        $hasValue = array_key_exists('value', $_POST);
        $newValue = $hasValue
            ? (($_POST['value']==='1'||$_POST['value']===1||$_POST['value']===true) ? 1 : 0)
            : (1 - $current);
        $npc->update($id, ['npc_favorite' => $newValue]);
        $rowAfter = $npc->getById($id);
        $val = is_array($rowAfter)
            ? (coerceBoolean($rowAfter['npc_favorite'] ?? false) ? 1 : 0)
            : $newValue;
        echo json_encode(["ok"=>true, "favorite"=>$val]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Toggle lock (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_lock"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid id"]); exit; }
        $rowBefore = $npc->getById($id);
        $current = coerceBoolean($rowBefore['lock_profile'] ?? false) ? 1 : 0;
        $hasValue = array_key_exists('value', $_POST);
        $newValue = $hasValue
            ? (($_POST['value']==='1'||$_POST['value']===1||$_POST['value']===true) ? 1 : 0)
            : (1 - $current);
        $npc->update($id, ['lock_profile' => $newValue]);
        $rowAfter = $npc->getById($id);
        $val = is_array($rowAfter)
            ? (coerceBoolean($rowAfter['lock_profile'] ?? false) ? 1 : 0)
            : $newValue;
        echo json_encode(["ok"=>true, "locked"=>$val]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Bulk unlock all NPC profiles except The Narrator (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["bulk_unlock_npcs"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $unlocked = stobeBulkUnlockNpcProfiles(strval($_POST['confirm'] ?? ''));
        echo json_encode(["ok" => true, "unlocked" => $unlocked]);
    } catch (Throwable $e) {
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// Bulk delete unlocked NPCs except The Narrator (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["bulk_delete_npcs"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($confirm !== 'Delete') { echo json_encode(["ok"=>false, "error"=>"Confirmation text mismatch"]); exit; }
        // Delete all unlocked NPCs except The Narrator.
        $sql = "WITH del AS (
                    DELETE FROM core_npc
                    WHERE (lock_profile IS NULL OR lock_profile = FALSE)
                      AND trim(lower(name)) <> 'the narrator'
                    RETURNING 1
                ) SELECT count(*) AS c FROM del";
        $row = $GLOBALS['db']->fetchOne($sql);
        $deleted = intval($row['c'] ?? 0);
        echo json_encode(["ok"=>true, "deleted"=>$deleted]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Bulk switch NPC profile assignment by source profile (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["bulk_switch_profile"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if ($confirm !== 'Switch') { echo json_encode(["ok"=>false, "error"=>"Confirmation text mismatch"]); exit; }

        $sourceProfileId = intval($_POST['source_profile_id'] ?? 0);
        $targetProfileId = intval($_POST['target_profile_id'] ?? 0);
        if ($sourceProfileId <= 0 || $targetProfileId <= 0) {
            echo json_encode(["ok"=>false, "error"=>"Invalid source or target profile"]);
            exit;
        }
        if ($sourceProfileId === $targetProfileId) {
            echo json_encode(["ok"=>false, "error"=>"Source and target profiles must be different"]);
            exit;
        }

        $includeLockedRaw = $_POST['include_locked'] ?? '';
        $includeLocked = (
            $includeLockedRaw === '1' ||
            $includeLockedRaw === 1 ||
            $includeLockedRaw === true ||
            $includeLockedRaw === 'true'
        );

        $sourceRow = $GLOBALS['db']->fetchOne("SELECT id, label FROM core_profiles WHERE id = {$sourceProfileId} LIMIT 1");
        $targetRow = $GLOBALS['db']->fetchOne("SELECT id, label FROM core_profiles WHERE id = {$targetProfileId} LIMIT 1");
        if (!is_array($sourceRow) || empty($sourceRow['id'])) {
            echo json_encode(["ok"=>false, "error"=>"Source profile not found"]);
            exit;
        }
        if (!is_array($targetRow) || empty($targetRow['id'])) {
            echo json_encode(["ok"=>false, "error"=>"Target profile not found"]);
            exit;
        }

        $baseWhere = "profile_id = {$sourceProfileId} and trim(lower(name)) <> 'the narrator'";
        $countRow = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS c FROM core_npc WHERE {$baseWhere}");
        $totalMatched = intval($countRow['c'] ?? 0);

        $skippedLocked = 0;
        if (!$includeLocked) {
            $skippedRow = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS c FROM core_npc WHERE {$baseWhere} AND COALESCE(lock_profile,FALSE)=TRUE");
            $skippedLocked = intval($skippedRow['c'] ?? 0);
        }

        $lockClause = $includeLocked ? "TRUE" : "COALESCE(lock_profile,FALSE)=FALSE";
        $sql = "WITH upd AS (
                    UPDATE core_npc
                    SET profile_id = {$targetProfileId}
                    WHERE {$baseWhere}
                      AND {$lockClause}
                    RETURNING 1
                )
                SELECT COUNT(*) AS c FROM upd";
        $row = $GLOBALS['db']->fetchOne($sql);
        $updated = intval($row['c'] ?? 0);

        echo json_encode([
            "ok" => true,
            "updated" => $updated,
            "total_matched" => $totalMatched,
            "skipped_locked" => $skippedLocked,
            "include_locked" => $includeLocked,
            "source_profile_id" => $sourceProfileId,
            "target_profile_id" => $targetProfileId,
            "source_profile_label" => (string)($sourceRow['label'] ?? ('Profile #'.$sourceProfileId)),
            "target_profile_label" => (string)($targetRow['label'] ?? ('Profile #'.$targetProfileId)),
        ]);
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Handle Delete
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_npc"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $toDel = intval($_POST["id"] ?? 0);
        if ($toDel <= 0) {
            echo json_encode(["ok" => false, "error" => "Invalid id"]);
            exit;
        }
        $rowCheck = $npc->getById($toDel);
        if (!$rowCheck) {
            echo json_encode(["ok" => false, "error" => "NPC not found"]);
            exit;
        }
        if (coerceBoolean($rowCheck['lock_profile'] ?? false)) {
            echo json_encode(["ok" => false, "error" => "This NPC is locked and cannot be deleted"]);
            exit;
        }

        $npc->delete($toDel);
        echo json_encode(["ok" => true, "deleted" => $toDel]);
    } catch (Throwable $e) {
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET["delete"])) {
    $toDel = intval($_GET["delete"]);
    $rowCheck = $npc->getById($toDel);
    
    if ($rowCheck && coerceBoolean($rowCheck['lock_profile'] ?? false)) {
        header("Location: npc_master.php"); 
        exit; 
    }
    
    $npc->delete($toDel);
    header("Location: npc_master.php");
    exit;
}

// Handle Export NPC (download JSON)
if (isset($_GET["export"]) && is_numeric($_GET["export"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    
    $exportId = intval($_GET["export"]);
    $exportRow = $npc->getById($exportId);
    
    if (!$exportRow) {
        header("HTTP/1.1 404 Not Found");
        echo "NPC not found";
        exit;
    }
    
    // Build export data
    $exportData = [
        'export_version' => '1.0',
        'export_date' => date('c'),
        'npc_name' => $exportRow['npc_name'] ?? '',
        'npc_favorite' => coerceBoolean($exportRow['npc_favorite'] ?? false) ? 1 : 0,
        'lock_profile' => coerceBoolean($exportRow['lock_profile'] ?? false) ? 1 : 0,
        'prompt_head' => $exportRow['prompt_head'] ?? '',
        'npc_static_bio' => $exportRow['npc_static_bio'] ?? '',
        'world_knowledge_tags' => $exportRow['world_knowledge_tags'] ?? '',
        'emote_moods' => $exportRow['emote_moods'] ?? '',
        'personality' => $exportRow['personality'] ?? '',
        'relationships' => $exportRow['relationships'] ?? '',
        'occupation' => $exportRow['occupation'] ?? '',
        'appearance' => $exportRow['appearance'] ?? '',
        'skills' => $exportRow['skills'] ?? '',
        'speechstyle' => $exportRow['speechstyle'] ?? '',
        'goals' => $exportRow['goals'] ?? '',
        'voiceid' => $exportRow['voiceid'] ?? '',
        'gender' => $exportRow['gender'] ?? '',
        'race' => $exportRow['race'] ?? '',
        'dynamic_profile' => $exportRow['dynamic_profile'] ?? null,
        'base' => $exportRow['base'] ?? '',
        'core' => $exportRow['core'] ?? '',
        'tags' => $exportRow['tags'] ?? '',
        'metadata' => null,
        'extended_data' => null,
    ];
    
    // Parse JSON fields
    if (!empty($exportRow['metadata'])) {
        $tmp = json_decode((string)$exportRow['metadata'], true);
        if (is_array($tmp)) { $exportData['metadata'] = $tmp; }
    }
    if (!empty($exportRow['extended_data'])) {
        $tmp = json_decode((string)$exportRow['extended_data'], true);
        if (is_array($tmp)) { $exportData['extended_data'] = $tmp; }
    }
    
    $filename = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($exportRow['npc_name'] ?? 'npc')) . '_export.json';
    
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle Import NPC (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["import_npc"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    
    try {
        $importJson = $_POST['import_data'] ?? '';
        $targetId = isset($_POST['target_id']) ? intval($_POST['target_id']) : 0;
        $newName = trim($_POST['new_name'] ?? '');
        
        $importData = json_decode($importJson, true);
        if (!is_array($importData)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON data']);
            exit;
        }
        
        // Build NPC data from import
        $npcData = [];
        $allowedFields = ['npc_favorite', 'lock_profile', 'prompt_head', 'npc_static_bio', 
            'world_knowledge_tags', 'emote_moods', 'personality', 'relationships', 
            'occupation', 'appearance', 'skills', 'speechstyle', 'goals', 'voiceid',
            'gender', 'race', 'dynamic_profile', 'base', 'core', 'tags'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $importData)) {
                $npcData[$field] = $importData[$field];
            }
        }
        
        // Handle JSON fields
        if (isset($importData['metadata']) && is_array($importData['metadata'])) {
            $npcData['metadata'] = json_encode($importData['metadata']);
        }
        if (isset($importData['extended_data']) && is_array($importData['extended_data'])) {
            $npcData['extended_data'] = json_encode($importData['extended_data']);
        }
        
        if ($targetId > 0) {
            // Import to existing NPC
            $existingNpc = $npc->getById($targetId);
            if (!$existingNpc) {
                echo json_encode(['ok' => false, 'error' => 'Target NPC not found']);
                exit;
            }
            
            // Don't overwrite the name when importing to existing NPC
            unset($npcData['npc_name']);
            
            $npc->update($targetId, $npcData);
            echo json_encode(['ok' => true, 'message' => 'Biography imported to existing NPC', 'id' => $targetId]);
        } else {
            // Create new NPC
            if ($newName !== '') {
                $npcData['npc_name'] = $newName;
            } elseif (!empty($importData['npc_name'])) {
                $npcData['npc_name'] = $importData['npc_name'];
            } else {
                echo json_encode(['ok' => false, 'error' => 'NPC name is required']);
                exit;
            }
            
            // Check if name already exists
            $existingByName = $npc->getByName($npcData['npc_name']);
            if ($existingByName) {
                echo json_encode(['ok' => false, 'error' => 'An NPC with this name already exists. Use "Import to Existing" option instead.']);
                exit;
            }
            
            $npcData['md5'] = md5($npcData['npc_name']);
            $newId = $npc->create($npcData);
            echo json_encode(['ok' => true, 'message' => 'New NPC created from import', 'id' => $newId]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch Data
$perPage = 12;
$page = isset($_GET["page"]) ? intval($_GET["page"]) : 1;
if ($page < 1) $page = 1;

// Filters and sorting
$q = trim($_GET['q'] ?? '');
$alpha = strtolower($_GET['alpha'] ?? 'asc');
if (!in_array($alpha, ['asc','desc'], true)) { $alpha = 'asc'; }
$nameLetterFilter = strtoupper(trim((string)($_GET['letter'] ?? '')));
if (!preg_match('/^[A-Z]$/', $nameLetterFilter)) { $nameLetterFilter = ''; }
$profileIdFilter = isset($_GET['profile_id']) ? trim((string)$_GET['profile_id']) : '';
// New: checkbox filters
$favOnly = (isset($_GET['fav']) && $_GET['fav'] === '1');
$dynOnly = (isset($_GET['dyn']) && $_GET['dyn'] === '1');
$mtmOnly = (isset($_GET['mtm']) && $_GET['mtm'] === '1');
$lockOnly = (isset($_GET['lock']) && $_GET['lock'] === '1');
$playerFactionOnly = (isset($_GET['pf']) && $_GET['pf'] === '1');

$playerFactionIdentity = stobeUiResolvePlayerFactionIdentity();
$playerFactionName = trim(strval($playerFactionIdentity['name'] ?? ''));
$playerFactionId = trim(strval($playerFactionIdentity['id'] ?? ''));
$hasPlayerFactionIdentity = ($playerFactionName !== '' || $playerFactionId !== '');
$playerFactionMembers = stobeUiGetPlayerFactionMemberSet();
$hasPlayerFactionMembers = count($playerFactionMembers) > 0;
$hasPlayerFactionSignal = ($hasPlayerFactionIdentity || $hasPlayerFactionMembers);

// Preload profiles for filter dropdown
$profileRows = $GLOBALS["db"]->fetchAll("SELECT id, label, prompt_head, metadata FROM core_profiles ORDER BY label ASC");
// Default to first profile id for new NPCs
$firstProfileId = '';
if (is_array($profileRows) && count($profileRows) > 0) {
    $firstProfileId = (string)($profileRows[0]['id'] ?? '');
}
// Preload profile connector mappings and LLM connector labels for modal summary
$profileConnRows = $GLOBALS["db"]->fetchAll(
    "SELECT
        id,
        prompt_head,
        response_connector,
        diary_connector,
        autochat_connector,
        middleterm_connector,
        backgroundlife_connector,
        dynamic_connector,
        relationship_connector,
        metadata
     FROM core_profiles
     ORDER BY id ASC"
);
$llmRows = $GLOBALS["db"]->fetchAll("SELECT id, COALESCE(NULLIF(name,''), model) AS label FROM core_llm_connector ORDER BY LOWER(COALESCE(NULLIF(name,''), model)) ASC");
$profilesById = [];
foreach (($profileRows ?? []) as $pr) {
    $pid = (string)($pr['id'] ?? '');
    if ($pid !== '') $profilesById[$pid] = $pr['label'] ?? ('Profile #'.$pid);
}
$profileOptions = [];
foreach (($profileRows ?? []) as $pr) {
    $pid = (string)($pr['id'] ?? '');
    if ($pid === '') continue;
    $profileOptions[] = [
        'id' => $pid,
        'label' => (string)($pr['label'] ?? ('Profile #'.$pid)),
    ];
}
// Build profile metadata lookup for inherited settings
$profileMetaById = [];
$profilePromptHeadsById = [];
$globalPromptHead = (string)($GLOBALS['PROMPT_HEAD'] ?? '');
foreach (($profileConnRows ?? []) as $prow) {
    $pid = (string)($prow['id'] ?? '');
    if ($pid === '') continue;
    $pmeta = [];
    try {
        if (!empty($prow['metadata'])) {
            $tmp = json_decode((string)$prow['metadata'], true);
            if (is_array($tmp)) $pmeta = $tmp;
        }
    } catch (Throwable $e) {}
    // Check for both string "1" and boolean true
    $dynVal = isset($pmeta['DYNAMIC_PROFILE_ENABLED']) ? $pmeta['DYNAMIC_PROFILE_ENABLED'] : null;
    $mtmVal = isset($pmeta['MIDDLE_TERM_MEMORY_ENABLED']) ? $pmeta['MIDDLE_TERM_MEMORY_ENABLED'] : null;
    $blcVal = isset($pmeta['BACKGROUND_LIFE_COMMANDS']) ? $pmeta['BACKGROUND_LIFE_COMMANDS'] : null;
    $gpsVal = isset($pmeta['GPS_TRACK']) ? $pmeta['GPS_TRACK'] : null;
    $profilePromptHead = isset($prow['prompt_head']) && is_scalar($prow['prompt_head'])
        ? (string)$prow['prompt_head']
        : '';
    $profilePromptHeadsById[$pid] = $profilePromptHead !== '' ? $profilePromptHead : $globalPromptHead;
    
    $profileMetaById[$pid] = [
        'dyn' => ($dynVal === '1' || $dynVal === 1 || $dynVal === true),
        'mtm' => ($mtmVal === '1' || $mtmVal === 1 || $mtmVal === true),
        'blc' => ($blcVal === '1' || $blcVal === 1 || $blcVal === true),
        'gps' => ($gpsVal === '1' || $gpsVal === 1 || $gpsVal === true)
    ];
}
$profilesConnById = [];
foreach (($profileConnRows ?? []) as $prc) {
    $pid = (string)($prc['id'] ?? '');
    if ($pid !== '') $profilesConnById[$pid] = $prc;
}
$llmById = [];
foreach (($llmRows ?? []) as $lr) {
    $lid = (string)($lr['id'] ?? '');
    if ($lid !== '') $llmById[$lid] = $lr['label'] ?? ('Connector #'.$lid);
}

$where = "1=1";
// Narrator is managed in the dedicated Narrator menu and should not appear as a regular NPC card.
$where .= " and lower(npc_name) <> lower('The Narrator')";
if ($q !== ''){
    $qEsc = "%".$GLOBALS['db']->escape($q)."%";
    // Match by name primarily; include a few related fields
    $where .= " and (npc_name ilike '".$qEsc."' or coalesce(race,'') ilike '".$qEsc."' or coalesce(voiceid,'') ilike '".$qEsc."' or coalesce(refid,'') ilike '".$qEsc."' or coalesce(tags,'') ilike '".$qEsc."')";
}
if ($nameLetterFilter !== '') {
    $letterEsc = $GLOBALS['db']->escape(strtolower($nameLetterFilter));
    $where .= " and lower(npc_name) like '".$letterEsc."%'";
}
if ($profileIdFilter !== ''){
    $where .= " and profile_id = ".intval($profileIdFilter);
}
// Apply favorites/dynamic/middle-term filters when checked
if ($favOnly) {
    $where .= " and coalesce(npc_favorite,0)=1";
}
if ($dynOnly) {
    $where .= " and coalesce(dynamic_profile,0)=1";
}
if ($mtmOnly) {
    // Prefer metadata override key, keep legacy extended_data compatibility.
    $where .= " and ((coalesce(metadata::text,'') ~ '\"MIDDLE_TERM_MEMORY_ENABLED\"\\s*:\\s*(true|1)') or (coalesce(extended_data::text,'') ~ '\"middle_term_enabled\"\\s*:\\s*(true|1)'))";
}
if ($lockOnly) {
    $where .= " and coalesce(lock_profile,0)=1";
}

$fetchOrder = "order by lower(npc_name) " . $alpha . ", id asc";
$allFilteredRows = $npc->getAll("{$where} {$fetchOrder}");

if ($playerFactionOnly) {
    if (!$hasPlayerFactionSignal) {
        $allFilteredRows = [];
    } else {
        $allFilteredRows = array_values(array_filter(
            $allFilteredRows,
            static fn(array $row): bool => stobeUiNpcIsInPlayerFaction($row)
        ));
    }
}
$allFilteredRows = stobeUiSortNpcRows($allFilteredRows, $alpha);
$totalRows = count($allFilteredRows);
$totalPages = max(1, (int)ceil($totalRows / max(1, $perPage)));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$data = array_slice($allFilteredRows, $offset, $perPage);
$editItem = null;

if (isset($_GET["edit"])) {
    $editItem = $npc->getById($_GET["edit"]);
}

// Partial list renderer for AJAX refresh of grid and pagination
if (isset($_GET['list']) && $_GET['list'] === '1') {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <?php renderStobeNpcToolbar([
        'top' => false,
        'q' => $q,
        'nameLetterFilter' => $nameLetterFilter,
        'profileRows' => $profileRows ?? [],
        'profileIdFilter' => $profileIdFilter,
        'page' => $page,
        'totalPages' => $totalPages,
        'totalRows' => $totalRows,
        'favOnly' => $favOnly,
        'dynOnly' => $dynOnly,
        'mtmOnly' => $mtmOnly,
        'lockOnly' => $lockOnly,
        'playerFactionOnly' => $playerFactionOnly,
    ]); ?>
    <div class="npc-grid">
    <?php foreach ($data as $row): ?>
        <?php 
        $pid = (string)($row['profile_id'] ?? ''); 
        $profLabel = $profilesById[$pid] ?? ''; 
        $metaTmp = []; 
        if (!empty($row['metadata'])) { 
            $tmp = json_decode((string)$row['metadata'], true); 
            if (is_array($tmp)) { $metaTmp = $tmp; } 
        } 
        $bountySummary = stobe_ui_format_bounty_summary(
            $row['bounty'] ?? 0,
            $row['bounty_payload'] ?? null,
            $metaTmp
        );
        $bountyAmountText = $bountySummary['amount_text'];
        $bountyDetailsText = $bountySummary['details_text'];
        $bountyBreakdownItems = is_array($bountySummary['breakdown_items'] ?? null) ? $bountySummary['breakdown_items'] : [];
        $bountyBreakdownExtra = intval($bountySummary['breakdown_extra'] ?? 0);
        $bountyLegacyDetails = trim(strval($bountySummary['legacy_details'] ?? ''));
        $extTmp = []; 
        if (!empty($row['extended_data'])) { 
            $tmp2 = json_decode((string)$row['extended_data'], true); 
            if (is_array($tmp2)) { $extTmp = $tmp2; } 
        }
        
        // Check for inherited profile settings
        $profileMeta = isset($profileMetaById[$pid]) ? $profileMetaById[$pid] : ['dyn'=>false,'mtm'=>false,'blc'=>false,'gps'=>false];
        
        // Dynamic Profile: check NPC override, otherwise inherit from profile
        $dynEnabled = $profileMeta['dyn']; // default to profile
        if (isset($row['dynamic_profile']) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
            $dynEnabled = coerceBoolean($row['dynamic_profile']);
        }
        
        // MTM: check metadata override, otherwise legacy extended_data, otherwise inherit profile
        $mtmEnabled = $profileMeta['mtm']; // default to profile
        $mtmOverride = stobeUiResolveMtmOverride($metaTmp, $extTmp);
        if ($mtmOverride !== null) {
            $mtmEnabled = $mtmOverride;
        }

        // Individual memory bank is NPC-only (no profile inheritance).
        $imbEnabled = stobeUiResolveIndividualMemoryEnabled($extTmp);
        
        // Background Life Commands: check extended_data override, otherwise inherit from profile
        $blcEnabled = $profileMeta['blc']; // default to profile
        if (array_key_exists('background_life_commands', $extTmp) && $extTmp['background_life_commands'] !== null && $extTmp['background_life_commands'] !== '') {
            $blcEnabled = !empty($extTmp['background_life_commands']);
        }
        
        // GPS Track: check metadata override, otherwise inherit from profile
        $gpsEnabled = $profileMeta['gps']; // default to profile
        if (array_key_exists('gps_track', $metaTmp) && $metaTmp['gps_track'] !== null && $metaTmp['gps_track'] !== '') {
            $gpsEnabled = !empty($metaTmp['gps_track']);
        }
        
        $tagsVal = trim((string)($row['tags'] ?? '')); 
        $tagsDisp = ($tagsVal === '') ? '' : $tagsVal; 
        $npcNameCard = strval($row["npc_name"] ?? '');
        $portraitUrl = stobe_ui_resolve_portrait_url($metaTmp, $webRoot);
        $portraitInitial = stobe_ui_portrait_fallback_char($npcNameCard);
        $isPlayerFactionNpc = stobeUiNpcIsInPlayerFaction($row);
        $currentActionCard = strtolower(trim(strval($metaTmp['current_action'] ?? ($row['current_action'] ?? ''))));
        $isDeadCard = ($currentActionCard === 'dead');
        ?>
        <div class="npc-card<?= $isPlayerFactionNpc ? ' npc-card-player-faction' : '' ?><?= $isDeadCard ? ' npc-card-dead' : '' ?>" id="npc_card_<?= htmlspecialchars($row["id"]) ?>" data-id="<?= htmlspecialchars($row["id"]) ?>" data-player-faction="<?= $isPlayerFactionNpc ? '1' : '0' ?>" data-current-action="<?= htmlspecialchars($currentActionCard) ?>">
            <div class="npc-title">
                <div class="npc-title-left"><?php 
                    // Use already-parsed $metaTmp to avoid re-decoding
                    $levelDisp = '';
                    if (isset($metaTmp['stats']) && is_array($metaTmp['stats']) && isset($metaTmp['stats']['level'])) {
                        $levelDisp = ' ('.intval($metaTmp['stats']['level']).')';
                    }
                ?><span class="npc-name"><?= htmlspecialchars($npcNameCard.$levelDisp) ?></span> <?php $gch = gender_icon_char($row['gender'] ?? ''); $gcl = gender_icon_class($row['gender'] ?? ''); if ($gch!==''): ?><span class="npc-gender-icon <?= htmlspecialchars($gcl) ?>" title="<?= htmlspecialchars($row['gender'] ?? '') ?>"><?= $gch ?></span><?php endif; ?><?php if (!empty($dynEnabled)): ?><span class="npc-dyn-icon" title="Dynamic profile enabled">&#x267B;&#xFE0F;</span><?php endif; ?><?php if (!empty($mtmEnabled)): ?><span class="npc-mtm-icon" title="Middle-term memory enabled">&#x1F4C3;</span><?php endif; ?><?php if (!empty($imbEnabled)): ?><span class="npc-imb-icon" title="Individual memory bank enabled">&#x1F9E0;</span><?php endif; ?><?php if (!empty($blcEnabled)): ?><span class="npc-blc-icon" title="Background life commands enabled">&#x1F3AE;</span><?php endif; ?><?php if (!empty($gpsEnabled)): ?><span class="npc-gps-icon" title="GPS track enabled">&#x1F4CD;</span><?php endif; ?></div>
            <div class="npc-title-actions">
                    <?php if ($isDeadCard): ?>
                    <span class="npc-dead-badge" title="Current action: dead">Dead</span>
                    <?php endif; ?>
                    <?php if ($isPlayerFactionNpc): ?>
                    <span class="npc-player-faction-badge" title="Aligned with the player faction">Player Faction</span>
                    <?php endif; ?>
                    <?php if ($tagsDisp !== ''): ?>
                    <span class="npc-tags-top" title="<?= htmlspecialchars($tagsDisp) ?>"><?= htmlspecialchars($tagsDisp) ?></span>
                    <?php endif; ?>
                                <a class="btn btn-toggle <?= coerceBoolean($row["npc_favorite"] ?? false) ? "active" : "" ?>" href="#" data-favorite-id="<?= $row["id"] ?>" title="Toggle favorite"><?php echo coerceBoolean($row["npc_favorite"] ?? false) ? "&#9733;" : "&#9734;"; ?></a>
                                <a class="btn btn-toggle <?= coerceBoolean($row["lock_profile"] ?? false) ? "active" : "" ?>" href="#" data-lock-id="<?= $row["id"] ?>" title="Toggle lock - Locked profiles are protected from save rollback when loading saves"><?php echo coerceBoolean($row["lock_profile"] ?? false) ? "&#x1F512;" : "&#x1F513;"; ?></a>
                                <a class="btn btn-trash<?= coerceBoolean($row['lock_profile'] ?? false) ? ' disabled' : '' ?>" data-delete-id="<?= intval($row['id']) ?>" href="<?= coerceBoolean($row['lock_profile'] ?? false) ? '#' : ('npc_master.php?delete='.$row['id']) ?>" title="<?= coerceBoolean($row['lock_profile'] ?? false) ? 'Locked - cannot delete' : 'Delete' ?>">&#x274C;</a>
                </div>
            </div>
            <div class="npc-divider"></div>
            <div class="npc-row">
                <div class="npc-portrait-col">
                    <?php if ($portraitUrl !== ''): ?>
                    <img class="npc-portrait-img" src="<?= htmlspecialchars($portraitUrl) ?>" alt="<?= htmlspecialchars($npcNameCard) ?> portrait" loading="lazy">
                    <?php else: ?>
                    <div class="npc-portrait-fallback"><?= htmlspecialchars($portraitInitial) ?></div>
                    <?php endif; ?>
                </div>
                <div class="npc-fields">
                    <div class="npc-line"><span class="npc-muted">Gender:</span> <span class="npc-gender"><?= htmlspecialchars($row["gender"] ?? "") ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Race:</span> <span class="npc-race"><?= htmlspecialchars($row["race"] ?? "") ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Faction:</span> <span class="npc-faction-name"><?= htmlspecialchars(stobeUiFactionCardLabel($row)) ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Voice:</span> <span class="npc-voiceid"><?= htmlspecialchars($row["voiceid"] ?? "") ?></span></div>
                    <div class="npc-line"><span class="npc-muted">Profile:</span> <span class="npc-profile"><?= htmlspecialchars($profLabel) ?></span></div>
                    <div class="npc-line npc-bounty-line"<?= $bountyAmountText === '0' ? ' style="display:none"' : '' ?>><span class="npc-muted">Bounty:</span> <span class="npc-bounty"><?= htmlspecialchars($bountyAmountText === '0' ? '' : $bountyAmountText) ?></span></div>
                    <?php if (count($bountyBreakdownItems) > 0 || $bountyLegacyDetails !== ''): ?>
                    <div class="npc-bounty-section">
                        <div class="npc-bounty-heading">Bounty Breakdown</div>
                        <div class="npc-bounty-breakdown">
                            <?php foreach ($bountyBreakdownItems as $bd): ?>
                            <?php
                                $bdFaction = trim(strval($bd['faction'] ?? 'Unknown faction'));
                                $bdAmountText = trim(strval($bd['amount_text'] ?? ''));
                                $bdReasonsText = trim(strval($bd['reasons_text'] ?? ''));
                            ?>
                            <div class="npc-bounty-item">
                                <div class="npc-bounty-item-top">
                                    <span class="npc-bounty-faction"><?= htmlspecialchars($bdFaction) ?></span>
                                    <?php if ($bdAmountText !== ''): ?><span class="npc-bounty-amount"><?= htmlspecialchars($bdAmountText) ?></span><?php endif; ?>
                                </div>
                                <?php if ($bdReasonsText !== ''): ?><div class="npc-bounty-crimes">Wanted for: <?= htmlspecialchars($bdReasonsText) ?></div><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($bountyBreakdownExtra > 0): ?>
                            <div class="npc-bounty-more">+<?= htmlspecialchars(strval($bountyBreakdownExtra)) ?> more faction(s)</div>
                            <?php endif; ?>
                            <?php if (count($bountyBreakdownItems) === 0 && $bountyLegacyDetails !== ''): ?>
                            <div class="npc-bounty-legacy"><?= htmlspecialchars($bountyLegacyDetails) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php $tagsVal = trim((string)($row["tags"] ?? "")); $tagsDisp = ($tagsVal === "") ? "none" : $tagsVal; ?>                </div>
                <div class="npc-right"></div>
                <div class="npc-right-warn">
                    <?php 
                    if ($row["gamets_last_updated"] != $LAST_INFOSAVE_EVENT) {
                        echo "<span title='This NPC is out of sync, this means current NPC sheet has been modified after last save. If you edit this NPC, changes will be lost if you reload a previous savegame. '>&#x26A0;&#xFE0F;</span>";
                    }

                    ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php
    exit;
}

// NPC history: return timeline of snapshots for a given NPC (Tamrielic time)
if (isset($_GET['history'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false, 'error'=>'Invalid id']); exit; }
        // Skip the most recent snapshot (current state); show only historical entries
        $sel = "SELECT
                    h.history_id,
                    h.npc_id,
                    h.name AS npc_name,
                    CASE WHEN COALESCE(h.npc_favorite, FALSE) THEN 1 ELSE 0 END AS npc_favorite,
                    CASE WHEN COALESCE(h.lock_profile, FALSE) THEN 1 ELSE 0 END AS lock_profile,
                    h.prompt_head,
                    h.backstory AS npc_static_bio,
                    h.world_knowledge_tags,
                    h.emote_moods,
                    h.personality,
                    h.relationships,
                    h.occupation,
                    h.appearance,
                    h.skills,
                    h.speechstyle,
                    h.goals,
                    h.voiceid,
                    h.gender,
                    h.race,
                    COALESCE(NULLIF(h.metadata->>'storage_id', ''), NULLIF(h.metadata->>'refid', ''), '') AS refid,
                    h.profile_id,
                    CASE WHEN COALESCE(h.dynamic_profile, FALSE) THEN 1 ELSE 0 END AS dynamic_profile,
                    h.md5,
                    h.gamets_last_updated,
                    ''::text AS core,
                    ''::text AS base,
                    h.tags
                FROM core_npc_master_history h
                WHERE h.npc_id = {$id}
                ORDER BY COALESCE(h.gamets_last_updated,0) DESC, h.history_id DESC
                OFFSET 1";
        $rows = $GLOBALS['db']->fetchAll($sel) ?: [];
        $entries = [];
        foreach ($rows as $r){
            $g = isset($r['gamets_last_updated']) ? floatval($r['gamets_last_updated']) : 0.0;
            $tam = $g > 0 ? convert_gamets2skyrim_long_date2($g) : '';
            $greg = $g > 0 ? gamets2str_format_gregorian_date($g, 'Y-m-d H:i') : '';
            $entries[] = [
                'history_id' => (int)($r['history_id'] ?? 0),
                'gamets' => $g,
                'when_tamrielic' => $tam,
                'when_gregorian' => $greg,
                'fields' => [
                    'npc_name' => $r['npc_name'] ?? '',
                    'profile_id' => isset($r['profile_id']) ? (string)$r['profile_id'] : '',
                    'gender' => $r['gender'] ?? '',
                    'race' => $r['race'] ?? '',
                    'voiceid' => $r['voiceid'] ?? '',
                    'refid' => $r['refid'] ?? '',
                    'core' => $r['core'] ?? '',
                    'npc_static_bio' => $r['npc_static_bio'] ?? '',
                    'personality' => $r['personality'] ?? '',
                    'relationships' => $r['relationships'] ?? '',
                    'occupation' => $r['occupation'] ?? '',
                    'skills' => $r['skills'] ?? '',
                    'speechstyle' => $r['speechstyle'] ?? '',
                    'goals' => $r['goals'] ?? '',
                    'world_knowledge_tags' => $r['world_knowledge_tags'] ?? '',
                    'emote_moods' => $r['emote_moods'] ?? '',
                    'prompt_head' => $r['prompt_head'] ?? '',
                    'dynamic_profile' => coerceBoolean($r['dynamic_profile'] ?? false),
            'npc_favorite' => coerceBoolean($r['npc_favorite'] ?? false),
            'lock_profile' => coerceBoolean($r['lock_profile'] ?? false),
                    'tags' => $r['tags'] ?? '',
                    'base' => $r['base'] ?? ''
                ]
            ];
        }
        echo json_encode(['ok'=>true,'count'=>count($entries),'entries'=>$entries]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// Restore NPC from history (AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["restore_from_history"])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $historyId = intval($_POST['history_id'] ?? 0);
        if ($historyId <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid history_id"]); exit; }
        
        // Fetch the historical record
        $histRow = $GLOBALS['db']->fetchOne(
            "SELECT
                h.*,
                h.name AS npc_name,
                h.backstory AS npc_static_bio,
                COALESCE(NULLIF(h.metadata->>'storage_id', ''), NULLIF(h.metadata->>'refid', ''), '') AS refid,
                ''::text AS core,
                ''::text AS base
             FROM core_npc_master_history h
             WHERE h.history_id = {$historyId}"
        );
        if (!$histRow) { echo json_encode(["ok"=>false, "error"=>"Historical record not found"]); exit; }
        
        $npcId = intval($histRow['npc_id'] ?? 0);
        if ($npcId <= 0) { echo json_encode(["ok"=>false, "error"=>"Invalid NPC id in history"]); exit; }
        
        // Check if NPC is locked
        $current = $npc->getById($npcId);
        if ($current && coerceBoolean($current['lock_profile'] ?? false)) {
            echo json_encode(["ok"=>false, "error"=>"Cannot restore: NPC is locked"]);
            exit;
        }
        
        // Prepare data for update (copy relevant fields from history)
        $updateData = [
            'npc_name' => $histRow['npc_name'] ?? '',
            'profile_id' => $histRow['profile_id'] ?? null,
            'gender' => $histRow['gender'] ?? '',
            'race' => $histRow['race'] ?? '',
            'faction' => $histRow['faction'] ?? '',
            'voiceid' => $histRow['voiceid'] ?? '',
            'refid' => $histRow['refid'] ?? '',
            'core' => $histRow['core'] ?? '',
            'base' => $histRow['base'] ?? '',
            'npc_static_bio' => $histRow['npc_static_bio'] ?? '',
            'personality' => $histRow['personality'] ?? '',
            'relationships' => $histRow['relationships'] ?? '',
            'occupation' => $histRow['occupation'] ?? '',
            'appearance' => $histRow['appearance'] ?? '',
            'equipment' => $histRow['equipment'] ?? '',
            'inventory' => $histRow['inventory'] ?? '',
            'skills' => $histRow['skills'] ?? '',
            'speechstyle' => $histRow['speechstyle'] ?? '',
            'goals' => $histRow['goals'] ?? '',
            'world_knowledge_tags' => $histRow['world_knowledge_tags'] ?? '',
            'emote_moods' => $histRow['emote_moods'] ?? '',
            'prompt_head' => $histRow['prompt_head'] ?? '',
            'dynamic_profile' => coerceBoolean($histRow['dynamic_profile'] ?? false) ? 1 : 0,
            'tags' => $histRow['tags'] ?? '',
            'bounty' => $histRow['bounty'] ?? '{}',
            'limbs' => $histRow['limbs'] ?? '',
            'blood' => $histRow['blood'] ?? '',
            'hunger' => $histRow['hunger'] ?? '',
            'is_animal' => coerceBoolean($histRow['is_animal'] ?? false) ? 1 : 0,
            'is_slave' => coerceBoolean($histRow['is_slave'] ?? false) ? 1 : 0,
            'metadata' => $histRow['metadata'] ?? '',
            'extended_data' => $histRow['extended_data'] ?? '',
            'md5' => $histRow['md5'] ?? md5($histRow['npc_name'] ?? '')
        ];
        
        // Update the NPC
        $ok = $npc->update($npcId, $updateData);
        if ($ok === false) {
            echo json_encode(["ok"=>false, "error"=>($npc->getLastError() ?? 'Restore failed')]);
        } else {
            // Create a backup of the restored state
            $npc->backupNpcById($npcId);
            echo json_encode(["ok"=>true, "npc_id"=>$npcId]);
        }
    } catch (Throwable $e) {
        echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
    }
    exit;
}

// Bio database: search existing templates (combined_bio_templates)
if (isset($_GET['bio_search'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    $bioSource = stobeUiGetBioTemplateSourceParts();
    $bioFromSql = strval($bioSource['from_sql'] ?? 'core_npc cbt');
    $bioNameCol = strval($bioSource['name_col'] ?? 'name');
    $bioCoreCol = strval($bioSource['core_col'] ?? 'prompt_head');
    $bioRefIdExpr = strval($bioSource['refid_expr'] ?? "COALESCE(cbt.metadata->>'storage_id', '')");
    $bioExpr = strval($bioSource['bio_expr'] ?? "COALESCE(cbt.backstory, '')");
    $bioWorldKnowledgeExpr = strval($bioSource['world_knowledge_expr'] ?? "COALESCE(NULLIF(cbt.world_knowledge_tags, ''), '')");
    $search = trim((string)($_GET['search'] ?? ''));
    $letter = trim((string)($_GET['letter'] ?? ''));
    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = min(50, max(1, intval($_GET['pageSize'] ?? 20)));
    $where = [];
    if ($search !== '') {
        $q = '%'.$GLOBALS['db']->escape($search).'%';
        $where[] = "(lower({$bioNameCol}) like lower('{$q}') or lower({$bioCoreCol}) like lower('{$q}'))";
    }
    if ($letter !== '' && preg_match('/^[A-Za-z]$/', $letter)) {
        $l = $GLOBALS['db']->escape(strtolower($letter));
        $where[] = "lower({$bioNameCol}) like '{$l}%'";
    }
    $whereSql = count($where) ? ('where '.implode(' and ', $where)) : '';
    $cntRow = $GLOBALS['db']->fetchOne("select count(*) as c from {$bioFromSql} {$whereSql}");
    $total = intval($cntRow['c'] ?? 0);
    $offset = ($page - 1) * $pageSize;
    $rows = $GLOBALS['db']->fetchAll(
        "select {$bioNameCol} as npc_name,
                {$bioCoreCol} as core,
                voiceid,
                gender,
                race,
                {$bioRefIdExpr} as refid,
                {$bioExpr} as npc_static_bio,
                personality,
                appearance,
                relationships,
                occupation,
                skills,
                speechstyle,
                goals,
                {$bioWorldKnowledgeExpr} as world_knowledge_tags
         from {$bioFromSql}
         {$whereSql}
         order by lower({$bioNameCol}) asc
         limit {$pageSize} offset {$offset}"
    );
    $items = [];
    foreach (($rows ?? []) as $r) {
        $extFields = ['npc_static_bio','personality','appearance','relationships','occupation','skills','speechstyle','goals'];
        $filled = 0; foreach ($extFields as $f) { $v = trim((string)($r[$f] ?? '')); if ($v !== '') $filled++; }
        $coreFull = (string)($r['core'] ?? '');
        if (function_exists('mb_strimwidth')) {
            $corePreview = mb_strimwidth($coreFull, 0, 160, 'N/A', 'UTF-8');
        } else {
            $corePreview = (strlen($coreFull) > 160) ? (substr($coreFull, 0, 157).'N/A') : $coreFull;
        }
        $items[] = [
            'npc_name' => $r['npc_name'] ?? '',
            'core_preview' => $corePreview,
            'voiceid' => $r['voiceid'] ?? '',
            'gender' => $r['gender'] ?? '',
            'race' => $r['race'] ?? '',
            'refid' => $r['refid'] ?? '',
            'extended_filled' => $filled
        ];
    }
    echo json_encode(['ok'=>true,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'items'=>$items]);
    exit;
}

// Bio database: detail of a specific template by npc_name
if (isset($_GET['bio_detail'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    $bioSource = stobeUiGetBioTemplateSourceParts();
    $bioFromSql = strval($bioSource['from_sql'] ?? 'core_npc cbt');
    $bioNameCol = strval($bioSource['name_col'] ?? 'name');
    $bioCoreCol = strval($bioSource['core_col'] ?? 'prompt_head');
    $bioRefIdExpr = strval($bioSource['refid_expr'] ?? "COALESCE(cbt.metadata->>'storage_id', '')");
    $bioExpr = strval($bioSource['bio_expr'] ?? "COALESCE(cbt.backstory, '')");
    $bioWorldKnowledgeExpr = strval($bioSource['world_knowledge_expr'] ?? "COALESCE(NULLIF(cbt.world_knowledge_tags, ''), '')");
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Missing name']); exit; }
    $esc = $GLOBALS['db']->escape($name);
    // Case-insensitive exact match on npc_name to tolerate capitalization differences
    $r = $GLOBALS['db']->fetchOne(
        "select {$bioNameCol} as npc_name,
                {$bioCoreCol} as core,
                voiceid,
                gender,
                race,
                {$bioRefIdExpr} as refid,
                {$bioExpr} as npc_static_bio,
                personality,
                appearance,
                relationships,
                occupation,
                skills,
                speechstyle,
                goals,
                {$bioWorldKnowledgeExpr} as world_knowledge_tags
         from {$bioFromSql}
         where lower({$bioNameCol}) = lower('{$esc}')
         limit 1"
    );
    if (!$r) { echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true,'data'=>$r]);
    exit;
}

// Import from bio: server builds row and creates/updates NPC
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['import_from_bio'])) {
    try { while (ob_get_level() > 0) { ob_end_clean(); } } catch (Throwable $e) {}
    header('Content-Type: application/json');
    try {
        $bioSource = stobeUiGetBioTemplateSourceParts();
        $bioFromSql = strval($bioSource['from_sql'] ?? 'core_npc cbt');
        $bioNameCol = strval($bioSource['name_col'] ?? 'name');
        $bioCoreCol = strval($bioSource['core_col'] ?? 'prompt_head');
        $bioRefIdExpr = strval($bioSource['refid_expr'] ?? "COALESCE(cbt.metadata->>'storage_id', '')");
        $bioExpr = strval($bioSource['bio_expr'] ?? "COALESCE(cbt.backstory, '')");
        $bioWorldKnowledgeExpr = strval($bioSource['world_knowledge_expr'] ?? "COALESCE(NULLIF(cbt.world_knowledge_tags, ''), '')");
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Missing name']); exit; }
        $includeCore = ($_POST['include_core'] ?? '') ? true : false;
        $includeExt  = ($_POST['include_extended'] ?? '1') ? true : false;
        $includeWorldKnowledge  = ($_POST['include_world_knowledge'] ?? '1') ? true : false;
        $includeVM   = ($_POST['include_voice_meta'] ?? '1') ? true : false;
        $profileId   = isset($_POST['profile_id']) && $_POST['profile_id']!=='' ? intval($_POST['profile_id']) : null;

        $esc = $GLOBALS['db']->escape($name);
        $r = $GLOBALS['db']->fetchOne(
            "select {$bioNameCol} as npc_name,
                    {$bioCoreCol} as core,
                    voiceid,
                    gender,
                    race,
                    {$bioRefIdExpr} as refid,
                    {$bioExpr} as npc_static_bio,
                    personality,
                    appearance,
                    relationships,
                    occupation,
                    skills,
                    speechstyle,
                    goals,
                    {$bioWorldKnowledgeExpr} as world_knowledge_tags
             from {$bioFromSql}
             where lower({$bioNameCol}) = lower('{$esc}')
             limit 1"
        );
        if (!$r) { echo json_encode(['ok'=>false,'error'=>'Template not found']); exit; }

        $data = [ 'npc_name' => $r['npc_name'] ?? $name ];
        if ($profileId !== null) $data['profile_id'] = $profileId;
        if ($includeCore) { $data['core'] = $r['core'] ?? null; }
        if ($includeExt) {
            foreach (['npc_static_bio','personality','appearance','relationships','occupation','skills','speechstyle','goals'] as $f) {
                $data[$f] = $r[$f] ?? null;
            }
        }
        if ($includeWorldKnowledge) { $data['world_knowledge_tags'] = $r['world_knowledge_tags'] ?? null; }
        if ($includeVM) {
            foreach (['voiceid','gender','race','refid'] as $f) { $data[$f] = $r[$f] ?? null; }
        }

        // Upsert by name
        $existing = $npc->getByName($data['npc_name']);
        if ($existing) {
            $existingAppearance = trim(strval($existing['appearance'] ?? ''));
            $existingRefId = trim(strval($existing['refid'] ?? ''));
            if (
                $includeExt &&
                array_key_exists('appearance', $data) &&
                $existingAppearance !== '' &&
                $existingRefId !== ''
            ) {
                // Snapshot-backed NPCs should keep their generated appearance text.
                unset($data['appearance']);
            }
            $data['md5'] = md5((string)$data['npc_name']);
            $ok = $npc->update((int)$existing['id'], $data);
            if ($ok === false) { echo json_encode(['ok'=>false,'error'=>'Update failed']); exit; }
            $newId = (int)$existing['id'];
        } else {
            $npc->create($data);
            // Fetch newly created row
            $row = $npc->getByName($data['npc_name']);
            $newId = (int)($row['id'] ?? 0);
        }
        if (!$newId) { echo json_encode(['ok'=>false,'error'=>'Insert failed']); exit; }
        $payload = $npc->getById($newId) ?: $data;
        echo json_encode(['ok'=>true,'id'=>$newId,'data'=>$payload]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}
?>

<?php if ($editItem): ?>
    <h2>Edit NPC (ID: <?= htmlspecialchars($editItem["id"]) ?>)</h2>
<?php endif; ?>

<?php if (isset($_GET['partial']) && $_GET['partial']=='1') { ob_end_clean(); ?>
<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css">
<link rel="stylesheet" href="css/npc_event_history.css">
<style>html,body{background:#2a2a2a;margin-bottom:50px;margin-right:5px;} main{background:#2a2a2a; padding:12px;} .form-container{background:#2a2a2a; border:1px solid #4a4a4a; border-radius:8px;}
.modal-inline-actions{display:flex; gap:6px; align-items:center; justify-content:flex-end; margin-bottom:8px;}
.modal-inline-actions .btn-toggle{background:transparent; border:none; padding:6px; color:#e9efff; font-size:22px; line-height:1; text-decoration:none; cursor:pointer;}
.modal-inline-actions .btn-toggle:hover{color: #e6b76c; text-decoration:none;}
.modal-inline-actions .btn-toggle.active{color:#ffd700; font-weight:700;}
.modal-inline-actions .btn-toggle[data-lock]{color:#e9efff;}
.modal-inline-actions .btn-toggle.active[data-lock]{color: #e6b76c;}
.modal-inline-actions .btn-toggle[data-favorite]:hover,
.modal-inline-actions .btn-toggle[data-favorite]:focus-visible{color:#ffd700 !important; text-shadow:0 0 8px rgba(255,215,0,.7),0 0 14px rgba(255,215,0,.45) !important;}
.modal-inline-actions .btn-toggle.active[data-favorite]{color:#ffd700 !important;}
</style>
<form method="post" onsubmit='return false' style='display:block'>
<?php } else { ?>
<script>
// Ensure consolidation() exists so the full-page editor submit never aborts on a missing hook.
if (typeof window.consolidation !== 'function') { window.consolidation = function(){ return true; }; }
</script>
<form method="post" onsubmit='return consolidation()' style='<?= $editItem!=null?"":"display:none"?>'>
<?php } ?>
    <style>
    .form-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:12px 16px; }
    @media (max-width: 900px){ .form-grid { grid-template-columns: 1fr; } }
    .form-item { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; }
    .form-item label { font-weight:700; color:#e6b76c; }
    .form-item .hint { color:#e9efff; font-size:12px; line-height:1.35; }
    .form-item textarea { min-height:96px; }
    #prompt_head, #npc_static_bio, #appearance,
    #personality, #relationships, #occupation, #skills {
        min-height: 134px; /* 96px * 1.4 N/A 134 */
    }
    .form-item input[type="text"], .form-item textarea, .form-item select { background:#2a2a2a; color:#e9efff; border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; }
    .prompt-head-label-row { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .prompt-head-copy-btn { padding:4px 9px; border:1px solid #4a4a4a; border-radius:5px; background:#242424; color:#cfd9ea; cursor:pointer; font-size:11px; font-weight:600; }
    .prompt-head-copy-btn:hover { border-color:rgb(242,124,17); color:rgb(242,124,17); }
    /* Header-style checkbox next to label title */
    .label-with-toggle { display:flex; align-items:center; gap:10px; }
    .label-with-toggle input[type="checkbox"] { accent-color:#176529; transform: scale(1.8); transform-origin:center; cursor:pointer; }
    .stm-override-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .stm-override-row select { flex:1 1 180px; min-width:0; }
    .stm-override-row .stm-max-label { font-weight:700; color:#e6b76c; }
    .stm-override-row input[type="number"] { width:74px; background:#2a2a2a; color:#e9efff; border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; text-align:right; }
    .span-2 { grid-column: 1 / -1; margin-bottom:12px; }
    .checkbox-inline { display:flex; align-items:center; gap:8px; }
    .npc-faction-source { color:#cfd9ea; font-size:13px; }
    .npc-faction-empty { margin:4px 0 0; color:#9fb1c9; font-size:13px; line-height:1.4; }
    .npc-faction-note { margin:6px 0 0; color:#9fb1c9; font-size:11px; }
    .npc-faction-scroll {
        max-width:100%;
        max-height:320px;
        overflow:auto;
        border:1px solid #4a4a4a;
        border-radius:8px;
        background:#1a1a1a;
    }
    .npc-faction-scroll:focus-visible { outline:2px solid #e6b76c; outline-offset:2px; }
    .npc-faction-table { width:100%; table-layout:fixed; border-collapse:collapse; font-size:12px; }
    .npc-faction-table th, .npc-faction-table td {
        padding:5px 8px;
        text-align:left;
        vertical-align:top;
        border-bottom:1px solid #333;
        overflow-wrap:anywhere;
    }
    .npc-faction-table thead th {
        position:sticky;
        top:0;
        z-index:1;
        background:#262626;
        color:#e6b76c;
        font-weight:700;
        white-space:nowrap;
        box-shadow:inset 0 -1px 0 #4a4a4a;
    }
    .npc-faction-table tbody th { font-weight:600; color:#e9efff; width:46%; }
    .npc-faction-table tbody tr:last-child th, .npc-faction-table tbody tr:last-child td { border-bottom:0; }
    .npc-faction-standing { width:22%; color:#cfd9ea; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .npc-faction-status { width:32%; }
    .npc-faction-flag {
        display:inline-block;
        margin:0 4px 2px 0;
        padding:1px 6px;
        border:1px solid #4a4a4a;
        border-radius:10px;
        background:#242424;
        color:#cfd9ea;
        font-size:11px;
        font-weight:600;
        white-space:nowrap;
    }
    .npc-faction-flag-alliance { border-color:#3f7a4a; color:#8fd3a0; }
    .npc-faction-flag-war { border-color:#8a3b3b; color:#f0a3a3; }
    .npc-faction-flag-coexists { border-color:#4a4a4a; color:#cfd9ea; }
    .npc-faction-flag-none { border-style:dashed; color:#9fb1c9; }
    .npc-faction-caption {
        position:absolute;
        width:1px;
        height:1px;
        padding:0;
        margin:-1px;
        overflow:hidden;
        clip:rect(0 0 0 0);
        white-space:nowrap;
        border:0;
    }
    @media (max-width: 420px) {
        .npc-faction-table { font-size:11px; }
        .npc-faction-table th, .npc-faction-table td { padding:4px 6px; }
    }
    </style>
    <?php if ($editItem): ?>
        <input type="hidden" name="id" value="<?= htmlspecialchars($editItem["id"]) ?>">
    <?php endif; ?>

<?php $isPartial = (isset($_GET['partial']) && $_GET['partial']=='1'); $isFav = coerceBoolean($editItem['npc_favorite'] ?? false); $isLock = coerceBoolean($editItem['lock_profile'] ?? false); ?>
    <?php if ($isPartial): ?>
    <div class="modal-inline-actions">
        <p style="margin:0; color:#e6b76c ;">Tags:</p>
        <input type="text" id="modal_tags_input" name="tags" value="<?= htmlspecialchars($editItem['tags'] ?? '') ?>" placeholder="tags" style="max-width:240px; font-size:12px; padding:4px 6px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;" title="Tags help with searching and grouping" />
<a id="modal_fav_btn" class="btn btn-toggle<?= $isFav? ' active':'' ?>" href="#" title="Toggle favorite" data-favorite><?= $isFav? '&#9733;' : '&#9734;' ?></a>
<a id="modal_lock_btn" class="btn btn-toggle<?= $isLock? ' active':'' ?>" href="#" title="Toggle lock - Locked profiles are protected from save rollback when loading saves" data-lock><?= $isLock? '&#x1F512;' : '&#x1F513;' ?></a>
    </div>
    <?php
    // Render LLM summary container (will live-update via JS)
    $curPid = (string)($editItem['profile_id'] ?? '');
    $pc = ($curPid !== '' && isset($profilesConnById[$curPid])) ? $profilesConnById[$curPid] : null;
    $m = function($id) use ($llmById){ $k = (string)($id ?? ''); return $k !== '' && isset($llmById[$k]) ? $llmById[$k] : 'N/A'; };
    ?>
    <div id="profile_llm_summary" style="display:grid; grid-template-columns: 170px 1fr; gap:6px; color:#cfd9ea; border:1px solid #4a4a4a; border-radius:8px; padding:8px; margin-bottom:8px;">
        <div style="color:#e6b76c; font-weight:700; white-space:nowrap;">LLMs</div>
        <div>
            &#x1F4AC; <?= htmlspecialchars($pc ? $m($pc['response_connector'] ?? '') : 'N/A') ?>
            | &#x1F4D9; <?= htmlspecialchars($pc ? $m($pc['diary_connector'] ?? '') : 'N/A') ?>
            | &#x1F4AD; <?= htmlspecialchars($pc ? $m($pc['autochat_connector'] ?? '') : 'N/A') ?>
            | &#x1F9E0; <?= htmlspecialchars($pc ? $m($pc['middleterm_connector'] ?? '') : 'N/A') ?>
            | &#x1F30D; <?= htmlspecialchars($pc ? $m($pc['backgroundlife_connector'] ?? '') : 'N/A') ?>
            | &#x267B;&#xFE0F; <?= htmlspecialchars($pc ? $m($pc['dynamic_connector'] ?? '') : 'N/A') ?>
            | &#x1F91D; <?= htmlspecialchars($pc ? $m($pc['relationship_connector'] ?? '') : 'N/A') ?>
        </div>
    </div>
    <script>
    (function(){
        const PROFILE_CONN = <?= json_encode($profilesConnById ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        const LLM_LABELS = <?= json_encode($llmById ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        function labelOf(id){ const k=String(id||''); return (k && LLM_LABELS[k]) ? String(LLM_LABELS[k]) : 'N/A'; }
        function renderProfileSummary(pid){
            const box = document.getElementById('profile_llm_summary'); if (!box) return;
            const pc = PROFILE_CONN[String(pid||'')] || null;
            const all = [
                '&#x1F4AC; ' + (pc ? labelOf(pc.response_connector) : 'N/A'),
                '&#x1F4D9; ' + (pc ? labelOf(pc.diary_connector) : 'N/A'),
                '&#x1F4AD; ' + (pc ? labelOf(pc.autochat_connector) : 'N/A'),
                '&#x1F9E0; ' + (pc ? labelOf(pc.middleterm_connector) : 'N/A'),
                '&#x1F30D; ' + (pc ? labelOf(pc.backgroundlife_connector) : 'N/A'),
                '&#x267B;&#xFE0F; ' + (pc ? labelOf(pc.dynamic_connector) : 'N/A'),
                '&#x1F91D; ' + (pc ? labelOf(pc.relationship_connector) : 'N/A')
            ].join(' | ');
            box.innerHTML = '<div style="color:#e6b76c; font-weight:700; white-space:nowrap;">LLMs</div><div>' + all + '</div>';
        }
        document.addEventListener('DOMContentLoaded', function(){
            const sel = document.getElementById('profile_id');
            if (sel){ 
                sel.addEventListener('change', function(){ 
                    renderProfileSummary(this.value||''); 
                    updateInheritedSettings(this.value||'');
                }); 
                renderProfileSummary(sel.value||''); 
            }
        });
        
        // Profile metadata for inherited settings
        const PROFILE_META = <?= json_encode(array_map(function($pr){
            $meta = [];
            try {
                if (!empty($pr['metadata'])) {
                    $tmp = json_decode((string)$pr['metadata'], true);
                    if (is_array($tmp)) $meta = $tmp;
                }
            } catch (Throwable $e) {}
            $dynVal = isset($meta['DYNAMIC_PROFILE_ENABLED']) ? $meta['DYNAMIC_PROFILE_ENABLED'] : null;
            $mtmVal = isset($meta['MIDDLE_TERM_MEMORY_ENABLED']) ? $meta['MIDDLE_TERM_MEMORY_ENABLED'] : null;
            $blcVal = isset($meta['BACKGROUND_LIFE_COMMANDS']) ? $meta['BACKGROUND_LIFE_COMMANDS'] : null;
            $gpsVal = isset($meta['GPS_TRACK']) ? $meta['GPS_TRACK'] : null;
            $stmDefaults = stobeUiShortTermProfileDefaults($meta);
            return [
                'id' => (string)($pr['id'] ?? ''),
                'dyn' => ($dynVal === '1' || $dynVal === 1 || $dynVal === true),
                'mtm' => ($mtmVal === '1' || $mtmVal === 1 || $mtmVal === true),
                'blc' => ($blcVal === '1' || $blcVal === 1 || $blcVal === true),
                'gps' => ($gpsVal === '1' || $gpsVal === 1 || $gpsVal === true),
                'stm' => $stmDefaults['enabled'],
                'stmMax' => $stmDefaults['max']
            ];
        }, $profileConnRows ?? []), JSON_UNESCAPED_SLASHES) ?>;
        
        function updateInheritedSettings(profileId) {
            const profile = PROFILE_META.find(p => p.id === profileId);
            if (!profile) return;
            
            // Update dynamic_profile
            const dynCb = document.getElementById('dynamic_profile');
            if (dynCb) {
                dynCb.checked = profile.dyn;
                dynCb.setAttribute('data-profile-default', profile.dyn ? '1' : '0');
                const hint = dynCb.closest('.form-item').querySelector('.hint');
                if (hint) {
                    const base = 'Allow systems to evolve the profile based on gameplay events.';
                    hint.innerHTML = base + (profile.dyn ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '');
                }
            }
            
            // Update middle_term_enabled
            const mtmCb = document.getElementById('middle_term_enabled');
            if (mtmCb) {
                mtmCb.checked = profile.mtm;
                mtmCb.setAttribute('data-profile-default', profile.mtm ? '1' : '0');
                const hint = mtmCb.closest('.form-item').querySelector('.hint');
                if (hint) {
                    const base = 'Saves a list of recent events after every 10 memory summaries. Will be used for NPC context.';
                    hint.innerHTML = base + (profile.mtm ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '');
                }
            }

            // Update Short Term Memory inherit label / Max placeholder
            const stmSel = document.getElementById('short_term_memory_enabled');
            if (stmSel) {
                stmSel.setAttribute('data-profile-default', profile.stm ? '1' : '0');
                const inheritOption = stmSel.querySelector('option[value=""]');
                if (inheritOption) {
                    inheritOption.textContent = 'Inherit from profile (' + (profile.stm ? 'On' : 'Off') + ')';
                }
            }
            const stmMax = document.getElementById('short_term_memory_max');
            if (stmMax) { stmMax.setAttribute('placeholder', String(profile.stmMax)); }
            document.querySelectorAll('[data-stm-profile-max]').forEach(function(node){
                node.textContent = String(profile.stmMax);
            });
        }
    })();
    </script>
    <?php endif; ?>

    <div class="npc-editor-tabs" role="tablist" aria-label="NPC editor categories" data-npc-editor-tabs data-storage-key="stobe-npc-editor-tab">
    <button type="button" class="npc-editor-tab is-active" role="tab" aria-selected="true" data-npc-editor-tab="general">🧭 General</button>
    <button type="button" class="npc-editor-tab" role="tab" aria-selected="false" data-npc-editor-tab="bios">📖 Roleplay</button>
    <button type="button" class="npc-editor-tab" role="tab" aria-selected="false" data-npc-editor-tab="relationships">🤝 Relationships</button>
    <?php if ($editItem): ?><button type="button" class="npc-editor-tab" role="tab" aria-selected="false" data-npc-editor-tab="factions">⚖️ Factions</button><?php endif; ?>
    <button type="button" class="npc-editor-tab" role="tab" aria-selected="false" data-npc-editor-tab="info">🛠️ Info</button>
    <?php if ($editItem): ?><button type="button" class="npc-editor-tab" role="tab" aria-selected="false" data-npc-editor-tab="history">📜 History</button><?php endif; ?>
</div>
<style>
.npc-editor-tabs {
    display:grid;
    grid-template-columns:repeat(<?= $editItem ? 6 : 4 ?>, minmax(0, 1fr));
    gap:8px;
    margin-bottom:14px;
    padding:8px;
    border:1px solid #3a3a3a;
    border-radius:10px;
    background:rgba(30, 30, 30, 0.92);
}
.npc-editor-tab {
    position:relative;
    min-height:40px;
    padding:8px 12px;
    border:1px solid #444;
    border-radius:7px;
    background:#303030;
    color:#ddd;
    font-weight:700;
    cursor:pointer;
    transition:border-color 0.15s ease, background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.npc-editor-tab:hover { border-color:rgba(230,183,108,0.55); background:#383838; }
.npc-editor-tabs .npc-editor-tab.is-active {
    border-color:#e6b76c !important;
    color:#fff !important;
    background:rgba(82,67,42,0.95) !important;
    box-shadow:inset 0 0 0 1px rgba(230,183,108,0.28), 0 0 12px rgba(230,183,108,0.24) !important;
    transform:translateY(-1px) !important;
}
.npc-editor-tab:focus-visible { outline:2px solid #e6b76c; outline-offset:2px; }
.npc-editor-panels { display:block; }
.npc-editor-panel[hidden] { display:none !important; }
.npc-editor-panel[data-npc-editor-panel="bios"] { grid-template-columns:minmax(0, 1fr); }
@media (max-width:700px) { .npc-editor-tabs { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
</style>
<script>
(function(){
    const fieldSections = {
        general: new Set(['npc_name','profile_id','lock_profile','npc_favorite','gender','race','base','refid','oghma_knowledge_tags','worldknowledge_tags','world_knowledge_tags','voiceid','tts_filter_preset','faction','dynamic_profile','middle_term_enabled','short_term_memory_enabled','short_term_memory_max','individual_memory_enabled','auto_diary_enabled','auto_diary_wait_enabled','salutation_after_a_while','prompt_head']),
        bios: new Set(['core','npc_static_bio','appearance','personality','occupation','skills','speechstyle','goals']),
        relationships: new Set(['relationships','relationships_jsonb','middle_term_latest']),
        info: new Set(['emote_moods','metadata','extended_data'])
    };

    function initNpcEditorTabs(){
        document.querySelectorAll('[data-npc-editor-tabs]').forEach(function(tablist, index){
            if (tablist.dataset.initialized === '1') return;
            const form = tablist.closest('form');
            const grid = form ? form.querySelector('.form-grid') : null;
            if (!form || !grid) return;
            tablist.dataset.initialized = '1';

            const panels = {};
            const sections = ['general','bios','relationships','info'];
            if (tablist.querySelector('[data-npc-editor-tab="factions"]')) sections.splice(3, 0, 'factions');
            if (tablist.querySelector('[data-npc-editor-tab="history"]')) sections.push('history');
            sections.forEach(function(section){
                const panel = document.createElement('div');
                panel.className = 'npc-editor-panel npc-editor-panel-' + section + ' form-grid';
                panel.dataset.npcEditorPanel = section;
                panel.id = 'npc-editor-panel-' + section + '-' + index;
                panel.setAttribute('role', 'tabpanel');
                panels[section] = panel;
                const button = tablist.querySelector('[data-npc-editor-tab="' + section + '"]');
                if (button) button.setAttribute('aria-controls', panel.id);
            });

            function tokensFor(unit){
                const nodes = [];
                if (unit.matches('[id],[name]')) nodes.push(unit);
                unit.querySelectorAll('[id],[name]').forEach(function(node){ nodes.push(node); });
                const tokens = [];
                nodes.forEach(function(node){
                    if (node.id) tokens.push(node.id);
                    if (node.getAttribute('name')) tokens.push(node.getAttribute('name'));
                });
                return tokens;
            }

            function sectionFor(unit){
                if (unit.id === 'relationship-editor-section' || unit.querySelector('#relationship-editor-section')) return 'relationships';
                if (unit.id === 'npc-faction-standings-section' || unit.querySelector('#npc-faction-standings-section')) return 'factions';
                const label = unit.querySelector('label:not([for])');
                if (label && label.textContent.replace(/\s+/g, ' ').trim() === 'Relationships') return 'relationships';
                const tokens = tokensFor(unit);
                for (const section of ['relationships','general','bios','info']) {
                    if (tokens.some(function(token){ return fieldSections[section].has(token); })) return section;
                }
                return 'info';
            }

            function isFieldUnit(unit){
                if (!(unit instanceof Element)) return false;
                if (unit.matches('.form-item,#relationship-editor-section,#npc-faction-standings-section,input,textarea,select,details')) return true;
                return Boolean(unit.querySelector('input,textarea,select,details,#relationship-editor-section,#npc-faction-standings-section'));
            }

            function moveUnit(unit){
                if (!isFieldUnit(unit)) return;
                const panel = panels[sectionFor(unit)] || panels.info;
                panel.appendChild(unit);
            }

            Array.from(grid.children).forEach(function(unit){
                if (unit.classList.contains('dynamic-profile-section')) {
                    Array.from(unit.children).forEach(moveUnit);
                    unit.hidden = true;
                    return;
                }
                moveUnit(unit);
            });

            grid.classList.remove('form-grid');
            grid.classList.add('npc-editor-panels');
            Object.values(panels).forEach(function(panel){ grid.appendChild(panel); });

            const storageKey = tablist.dataset.storageKey || 'npc-editor-tab';
            function activate(section){
                if (!panels[section]) section = 'general';
                tablist.querySelectorAll('[data-npc-editor-tab]').forEach(function(button){
                    const active = button.dataset.npcEditorTab === section;
                    button.classList.toggle('is-active', active);
                    button.setAttribute('aria-selected', active ? 'true' : 'false');
                    button.tabIndex = active ? 0 : -1;
                });
                Object.entries(panels).forEach(function(entry){ entry[1].hidden = entry[0] !== section; });
                try { window.localStorage.setItem(storageKey, section); } catch (_e) {}
                if (section === 'history' && window.stobeNpcEventHistoryController) {
                    window.stobeNpcEventHistoryController.load();
                }
            }

            tablist.addEventListener('click', function(event){
                const button = event.target.closest('[data-npc-editor-tab]');
                if (button) activate(button.dataset.npcEditorTab);
            });
            tablist.addEventListener('keydown', function(event){
                if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
                const buttons = Array.from(tablist.querySelectorAll('[data-npc-editor-tab]'));
                let next = buttons.indexOf(document.activeElement);
                if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = buttons.length - 1;
                else next = (next + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
                event.preventDefault();
                buttons[next].focus();
                activate(buttons[next].dataset.npcEditorTab);
            });

            let initial = 'general';
            try { initial = window.localStorage.getItem(storageKey) || initial; } catch (_e) {}
            activate(initial);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initNpcEditorTabs);
    window.setTimeout(initNpcEditorTabs, 0);
})();
</script>
<div class="form-grid">
        <div class="form-item span-2">
            <label for="npc_name">NPC Name</label>
            <input type="text" id="npc_name" name="npc_name" placeholder="e.g. Aela the Huntress" value="<?= htmlspecialchars($editItem["npc_name"] ?? "") ?>">
            <small class="hint">The character's name. Must match their Kenshi in-game name!</small>
        </div>

        <div class="form-item">
            <label for="profile_id">Profile</label>
            <select id="profile_id" name="profile_id">
                <option value="">-- Select Profile --</option>
                <?php foreach (($profileRows ?? []) as $pr): $pid=(string)($pr['id']??''); $lbl=$pr['label']??('Profile #'.$pid); $sel = ((string)($editItem['profile_id'] ?? '') === $pid) ? ' selected' : ((empty($editItem) && $firstProfileId === $pid) ? ' selected' : ''); ?>
                    <option value="<?= htmlspecialchars($pid) ?>"<?= $sel ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="hint">Select which profile the NPC uses.</small>
        </div>

        <div class="form-item" style='<?= (isset($_GET['partial']) && $_GET['partial']=='1')?"display:none":"" ?>'>
            <label for="lock_profile" class="label-with-toggle">Lock Profile
                        <input type="checkbox" id="lock_profile" name="lock_profile" value="1" <?= coerceBoolean($editItem["lock_profile"] ?? false) ? "checked" : "" ?>>
            </label>
            <small class="hint">Prevents dynamic systems from modifying this NPC's profile.</small>
        </div>

        <div class="form-item" style='<?= (isset($_GET['partial']) && $_GET['partial']=='1')?"display:none":"" ?>'>
            <label for="npc_favorite" class="label-with-toggle">Favorite
                        <input type="checkbox" id="npc_favorite" name="npc_favorite" value="1" <?= coerceBoolean($editItem["npc_favorite"] ?? false) ? "checked" : "" ?>>
            </label>
            <small class="hint">Pin this NPC for quick access.</small>
        </div>

        <div class="form-item">
            <label for="gender">Gender</label>
            <input type="text" id="gender" name="gender" placeholder="female, male" value="<?= htmlspecialchars($editItem["gender"] ?? "") ?>">
            <small class="hint">Used for prompts.</small>
        </div>

        <div class="form-item">
            <label for="race">Race</label>
            <input type="text" id="race" name="race" placeholder="nord, dunmer, farm tool" value="<?= htmlspecialchars($editItem["race"] ?? "") ?>">
            <small class="hint">Lore-accurate race label used in prompts.</small>
        </div>

        <div class="form-item">
            <label for="world_knowledge_tags">World Knowledge Tags</label>
            <input type="text" id="world_knowledge_tags" name="world_knowledge_tags" placeholder="Comma-separated knowledge tags" value="<?= htmlspecialchars($editItem["world_knowledge_tags"] ?? "") ?>">
            <small class="hint">Used by World Knowledge systems for knowledge lookup restrictions.</small>
        </div>

        <div class="form-item">
            <label for="voiceid">Voice ID</label>
            <input type="text" id="voiceid" name="voiceid" placeholder="malenord" value="<?= htmlspecialchars($editItem["voiceid"] ?? "") ?>">
            <small class="hint">Voice ID for TTS.</small>
        </div>

        <div class="form-item">
            <label for="tts_filter_preset">Voice Filter</label>
            <?php stobeUiRenderVoiceFilterField([
                'select_id' => 'tts_filter_preset',
                'select_name' => 'tts_filter_preset',
                'selected' => stobeUiReadTtsFilterPresetFromMetadata(is_array($editItem) ? ($editItem['metadata'] ?? '') : ''),
                'web_root' => $webRoot,
                'hint' => 'Audio effect applied to everything this NPC says. Presets are fixed and cannot be edited.',
                'hint_tag' => 'small',
                'hint_class' => 'hint',
                'play_title' => 'Play a sample of this NPC voice with this filter',
                'profile_select_id' => 'profile_id',
                'voice_input_id' => 'voiceid',
            ]); ?>
        </div>

        <div class="form-item">
            <label for="faction">Faction</label>
            <input type="text" id="faction" name="faction" placeholder="e.g. Holy Nation, UC, Shek Kingdom" value="<?= htmlspecialchars($editItem["faction"] ?? "") ?>">
            <small class="hint">Primary faction alignment used by prompts and rule matching.</small>
        </div>

        <?php
        // Check profile-level settings for these features
        $profileDynEnabled = false;
        $profileMtmEnabled = false;
        $profileAutoDiaryEnabled = false;
        $profileStmDefaults = stobeUiShortTermProfileDefaults([]);
        $currentProfileId = (string)(is_array($editItem) ? ($editItem['profile_id'] ?? '') : '');
        if ($currentProfileId !== '') {
            foreach (($profileConnRows ?? []) as $prow) {
                if ((string)($prow['id'] ?? '') === $currentProfileId) {
                    $pmeta = [];
                    try {
                        if (!empty($prow['metadata'])) {
                            $tmp = json_decode((string)$prow['metadata'], true);
                            if (is_array($tmp)) $pmeta = $tmp;
                        }
                    } catch (Throwable $e) {}
                    $dynVal = isset($pmeta['DYNAMIC_PROFILE_ENABLED']) ? $pmeta['DYNAMIC_PROFILE_ENABLED'] : null;
                    $mtmVal = isset($pmeta['MIDDLE_TERM_MEMORY_ENABLED']) ? $pmeta['MIDDLE_TERM_MEMORY_ENABLED'] : null;
                    $autoDiaryVal = isset($pmeta['AUTO_DIARY_ENABLED']) ? $pmeta['AUTO_DIARY_ENABLED'] : null;
                    $profileDynEnabled = ($dynVal === '1' || $dynVal === 1 || $dynVal === true);
                    $profileMtmEnabled = ($mtmVal === '1' || $mtmVal === 1 || $mtmVal === true);
                    $profileAutoDiaryEnabled = ($autoDiaryVal === '1' || $autoDiaryVal === 1 || $autoDiaryVal === true);
                    $profileStmDefaults = stobeUiShortTermProfileDefaults($pmeta);
                    break;
                }
            }
        }
        
        // Dynamic Profile: check NPC override or fall back to profile default
        $dynChecked = $profileDynEnabled;
        $dynFromProfile = false;
        if (is_array($editItem) && isset($editItem['dynamic_profile']) && $editItem['dynamic_profile'] !== null && $editItem['dynamic_profile'] !== '') {
            // NPC has explicit value (override)
            $dynChecked = coerceBoolean($editItem['dynamic_profile']);
        } else {
            // No NPC override, inherit from profile
            $dynFromProfile = true;
        }
        
        // Middle Term Memory: check extended_data override or fall back to profile default
        $mtmChecked = $profileMtmEnabled;
        $mtmFromProfile = false;
        $stmOverride = null;
        $stmMaxOverride = null;
        $imbChecked = false;
        $autoDiaryChecked = $profileAutoDiaryEnabled;
        $autoDiaryFromProfile = false;
        try {
            $hasNpcOverride = false;
            $hasAutoDiaryOverride = false;
            if (is_array($editItem) && !empty($editItem['metadata'])) {
                $tmpMeta = json_decode((string)$editItem['metadata'], true);
                if (is_array($tmpMeta) && array_key_exists('MIDDLE_TERM_MEMORY_ENABLED', $tmpMeta) && $tmpMeta['MIDDLE_TERM_MEMORY_ENABLED'] !== null && $tmpMeta['MIDDLE_TERM_MEMORY_ENABLED'] !== '') {
                    $mtmChecked = coerceBoolean($tmpMeta['MIDDLE_TERM_MEMORY_ENABLED']);
                    $hasNpcOverride = true;
                }
                if (is_array($tmpMeta) && array_key_exists('AUTO_DIARY_ENABLED', $tmpMeta) && $tmpMeta['AUTO_DIARY_ENABLED'] !== null && $tmpMeta['AUTO_DIARY_ENABLED'] !== '') {
                    $autoDiaryChecked = coerceBoolean($tmpMeta['AUTO_DIARY_ENABLED']);
                    $hasAutoDiaryOverride = true;
                }
                if (is_array($tmpMeta)) {
                    $stmOverride = stobeUiResolveMetadataToggleOverride($tmpMeta, 'SHORT_TERM_MEMORY_ENABLED');
                    $stmMaxOverride = stobeUiResolveShortTermMaxOverride($tmpMeta);
                }
            }
            if (is_array($editItem) && !empty($editItem['extended_data'])) {
                $tmpEd = json_decode((string)$editItem['extended_data'], true);
                if (is_array($tmpEd) && array_key_exists('middle_term_enabled', $tmpEd) && $tmpEd['middle_term_enabled'] !== null && $tmpEd['middle_term_enabled'] !== '') {
                    $mtmChecked = coerceBoolean($tmpEd['middle_term_enabled']);
                    $hasNpcOverride = true;
                }
                if (is_array($tmpEd)) {
                    $imbChecked = stobeUiResolveIndividualMemoryEnabled($tmpEd);
                }
            }
            if (!$hasNpcOverride) {
                $mtmFromProfile = true;
            }
            if (!$hasAutoDiaryOverride) {
                $autoDiaryFromProfile = true;
            }
        } catch (Throwable $e) { }
        
        ?>
        <div class="form-item">
            <label for="dynamic_profile" class="label-with-toggle">Dynamic Profile
                <input type="hidden" name="dynamic_profile" value="0">
                <input type="checkbox" id="dynamic_profile" name="dynamic_profile" value="1" <?= $dynChecked ? "checked" : "" ?> data-profile-default="<?= $profileDynEnabled ? '1' : '0' ?>">
            </label>
            <small class="hint">Allow systems to evolve the profile based on gameplay events.<?= $dynFromProfile ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '' ?></small>
        </div>

        <div class="form-item">
            <label for="middle_term_enabled" class="label-with-toggle">Middle Term Memory
                <input type="checkbox" id="middle_term_enabled" name="middle_term_enabled" value="1" <?= $mtmChecked ? "checked" : "" ?> data-profile-default="<?= $profileMtmEnabled ? '1' : '0' ?>">
            </label>
            <small class="hint">Saves a list of recent events after every 10 memory summaries. Will be used for NPC context.<?= $mtmFromProfile ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '' ?></small>
        </div>

        <div class="form-item">
            <label for="short_term_memory_enabled">Short Term Memory</label>
            <div class="stm-override-row">
                <select id="short_term_memory_enabled" name="short_term_memory_enabled" data-profile-default="<?= $profileStmDefaults['enabled'] ? '1' : '0' ?>" aria-describedby="short_term_memory_hint" title="Inherit uses the assigned profile setting. On or Off overrides it for this NPC only.">
                    <option value="" <?= $stmOverride === null ? 'selected' : '' ?>>Inherit from profile (<?= $profileStmDefaults['enabled'] ? 'On' : 'Off' ?>)</option>
                    <option value="1" <?= $stmOverride === true ? 'selected' : '' ?>>On</option>
                    <option value="0" <?= $stmOverride === false ? 'selected' : '' ?>>Off</option>
                </select>
                <label for="short_term_memory_max" class="stm-max-label">Max</label>
                <input type="number" id="short_term_memory_max" name="short_term_memory_max" min="1" max="50" step="1" inputmode="numeric" value="<?= $stmMaxOverride === null ? '' : htmlspecialchars((string)$stmMaxOverride) ?>" placeholder="<?= htmlspecialchars((string)$profileStmDefaults['max']) ?>" aria-describedby="short_term_memory_hint" title="Most completed summaries injected for this NPC (1-50). Leave blank to use the profile value.">
            </div>
            <small class="hint" id="short_term_memory_hint">Injects already completed memory summaries into this NPC's context. Leave Max blank to use the profile value (<span data-stm-profile-max><?= htmlspecialchars((string)$profileStmDefaults['max']) ?></span>).</small>
        </div>

        <div class="form-item">
            <label for="individual_memory_enabled" class="label-with-toggle">Individual Memory Bank
                <input type="hidden" name="individual_memory_enabled" value="0">
                <input type="checkbox" id="individual_memory_enabled" name="individual_memory_enabled" value="1" <?= $imbChecked ? "checked" : "" ?>>
            </label>
            <small class="hint">Enable NPC-scoped memory summaries for this character only. Scoped summaries are generated from conversations where this NPC is present.</small>
        </div>

        <div class="form-item">
            <label for="auto_diary_enabled" class="label-with-toggle">Auto Diary
                <input type="hidden" name="auto_diary_enabled" value="">
                <input type="checkbox" id="auto_diary_enabled" name="auto_diary_enabled" value="1" <?= $autoDiaryChecked ? "checked" : "" ?> data-profile-default="<?= $profileAutoDiaryEnabled ? '1' : '0' ?>">
            </label>
            <small class="hint">Allow this NPC to write automatic diary entries from background day processing.<?= $autoDiaryFromProfile ? ' <strong style="color:#e6b76c;">(Inherited from profile)</strong>' : '' ?></small>
        </div>

        <div class="form-item span-2">
            <div class="prompt-head-label-row">
                <label for="prompt_head">Prompt Head Override</label>
                <button type="button" id="copy_current_prompt_head" class="prompt-head-copy-btn" title="Copy the Prompt Head inherited from the selected profile or Global Settings into this override">Copy Current</button>
            </div>
            <textarea id="prompt_head" name="prompt_head" placeholder="High-level system instructions injected before the core."><?= htmlspecialchars($editItem["prompt_head"] ?? "") ?></textarea>
            <small class="hint">System preamble inserted before other sections. Leave empty to inherit it from the selected profile or Global Settings.</small>
            <script>
            (function(){
                const promptHeadsByProfile = <?= json_encode($profilePromptHeadsById, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
                const globalPromptHead = <?= json_encode($globalPromptHead, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
                const button = document.getElementById('copy_current_prompt_head');
                const textarea = document.getElementById('prompt_head');
                const profileSelect = document.getElementById('profile_id');
                if (!button || !textarea) return;

                button.addEventListener('click', function(){
                    const profileId = profileSelect ? String(profileSelect.value || '') : '';
                    const inheritedPromptHead = Object.prototype.hasOwnProperty.call(promptHeadsByProfile, profileId)
                        ? String(promptHeadsByProfile[profileId] || '')
                        : String(globalPromptHead || '');
                    textarea.value = inheritedPromptHead;
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));

                    button.textContent = 'Copied';
                    window.setTimeout(function(){ button.textContent = 'Copy Current'; }, 1200);
                });
            })();
            </script>
        </div>

        <div class="form-item span-2">
            <label for="npc_static_bio">Backstory</label>
            <textarea id="npc_static_bio" name="npc_static_bio" placeholder="Fixed background, history, and facts."><?= htmlspecialchars($editItem["npc_static_bio"] ?? "") ?></textarea>
            <small class="hint">Historical facts and background information.</small>
        </div>

        <div class="form-item span-2">
            <label for="appearance">Appearance</label>
            <textarea id="appearance" name="appearance" placeholder="Physical appearance."><?= htmlspecialchars($editItem["appearance"] ?? "") ?></textarea>
            <small class="hint">Physical appearance. Keep it limited to character cosmetics, not equipment.</small>
        </div>

        <div class="dynamic-profile-section span-2">
        <div class="form-item">
            <label for="personality">Personality</label>
            <textarea id="personality" name="personality" placeholder="Personality traits and speaking characteristics."><?= htmlspecialchars($editItem["personality"] ?? "") ?></textarea>
            <small class="hint">Traits and quirks that guide tone and behavior.</small>
        </div>

        

        <textarea id="relationships" name="relationships" style="display:none;"><?= htmlspecialchars($editItem["relationships"] ?? "") ?></textarea>

        <?php if (file_exists(__DIR__."/../ext/relationship_system/relationship_editor.php")) {
            include(__DIR__."/../ext/relationship_system/relationship_editor.php");
        } ?>

        <div class="form-item">
            <label for="occupation">Occupation</label>
            <textarea id="occupation" name="occupation" placeholder="Role, job, affiliations."><?= htmlspecialchars($editItem["occupation"] ?? "") ?></textarea>
            <small class="hint">Primary role or job. Include relevant guilds or factions.</small>
        </div>

        <div class="form-item">
            <label for="skills">Skills</label>
            <textarea id="skills" name="skills" placeholder="Strengths, abilities, and specialties."><?= htmlspecialchars($editItem["skills"] ?? "") ?></textarea>
            <small class="hint">Highlight notable competencies of the NPC.</small>
        </div>

        

        <div class="form-item">
            <label for="speechstyle">Speech Style</label>
            <textarea id="speechstyle" name="speechstyle" placeholder="Dialect, cadence, verbal tics."><?= htmlspecialchars($editItem["speechstyle"] ?? "") ?></textarea>
            <small class="hint">How the NPC speaks their dialogue.</small>
        </div>

        <div class="form-item">
            <label for="goals">Goals</label>
            <textarea id="goals" name="goals" placeholder="Short and long-term objectives."><?= htmlspecialchars($editItem["goals"] ?? "") ?></textarea>
            <small class="hint">Motivations and goals for the NPC.</small>
        </div>


        <div class="form-item span-2">
            <label for="emote_moods">Emote Moods Override</label>
            <textarea id="emote_moods" name="emote_moods" placeholder="Allowed mood/emote set (comma-separated).">
            <?= htmlspecialchars($editItem["emote_moods"] ?? "") ?></textarea>
            <small class="hint">Whitelist of mood/emote cues the NPC may use (e.g., calm, angry, playful). <strong>Overrides</strong> the global EMOTEMOODS setting. Leave empty to use global default.</small>
        </div>

        <?php
        $mtmLatest = '';
        try {
            if (!empty($editItem['extended_data'])){
                $ed = json_decode((string)$editItem['extended_data'], true);
                if (is_array($ed) && !empty($ed['middle_term_memory']) && is_array($ed['middle_term_memory'])){
                    $mtmRaw = $ed['middle_term_memory'];
                    if (array_keys($mtmRaw) === range(0, count($mtmRaw) - 1)) {
                        $arr = array_values($mtmRaw);
                        if (!empty($arr)) {
                            $mtmLatest = (string)end($arr);
                        }
                    } else {
                        $numericMap = [];
                        foreach ($mtmRaw as $k => $v) {
                            if (!is_scalar($v) || $v === null) {
                                continue;
                            }
                            if (preg_match('/^-?\d+$/', strval($k)) === 1) {
                                $numericMap[intval($k)] = strval($v);
                            }
                        }
                        if (count($numericMap) > 0) {
                            ksort($numericMap, SORT_NUMERIC);
                            $mtmLatest = strval(end($numericMap));
                        } else {
                            $arr = array_values($mtmRaw);
                            if (!empty($arr)) {
                                $mtmLatest = strval(end($arr));
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) { $mtmLatest = ''; }
        ?>
        <div class="form-item span-2">
            <label for="middle_term_latest">Recent Middle Term Memory</label>
            <textarea id="middle_term_latest" name="middle_term_latest" placeholder="No middle term memory yet."><?= htmlspecialchars($mtmLatest) ?></textarea>
            <small class="hint">Edit the most recent middle term memory entry. Changes are saved to Extended Data ? middle_term_memory (latest).</small>
        </div>

        <?php
        $editMetaTmp = [];
        try {
            if (!empty($editItem['metadata'])) {
                $tmpMeta = json_decode((string)$editItem['metadata'], true);
                if (is_array($tmpMeta)) {
                    $editMetaTmp = $tmpMeta;
                }
            }
        } catch (Throwable $e) {
            $editMetaTmp = [];
        }
        $editBountySummary = stobe_ui_format_bounty_summary(
            $editItem['bounty'] ?? 0,
            $editItem['bounty_payload'] ?? null,
            $editMetaTmp
        );
        $editBountyAmountText = trim(strval($editBountySummary['amount_text'] ?? '0'));
        $editBountyBreakdownItems = is_array($editBountySummary['breakdown_items'] ?? null)
            ? $editBountySummary['breakdown_items']
            : [];
        $editBountyBreakdownExtra = intval($editBountySummary['breakdown_extra'] ?? 0);
        $editBountyLegacyDetails = trim(strval($editBountySummary['legacy_details'] ?? ''));
        ?>
        <div class="form-item span-2">
            <details class="metadata-bounty-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;" open>
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">Current Bounty (Read Only)</summary>
                <small class="hint">Imported from the latest in-game snapshot. This section is informational and not editable here.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <div class="npc-line"><span class="npc-muted">Total:</span> <span class="npc-bounty"><?= htmlspecialchars($editBountyAmountText !== '' ? $editBountyAmountText : '0') ?></span></div>
                    <?php if (count($editBountyBreakdownItems) > 0 || $editBountyLegacyDetails !== ''): ?>
                    <div class="npc-bounty-section" style="margin-top:8px;">
                        <div class="npc-bounty-heading">Bounty Breakdown</div>
                        <div class="npc-bounty-breakdown">
                            <?php foreach ($editBountyBreakdownItems as $bd): ?>
                            <?php
                                $bdFaction = trim(strval($bd['faction'] ?? 'Unknown faction'));
                                $bdAmountText = trim(strval($bd['amount_text'] ?? ''));
                                $bdReasonsText = trim(strval($bd['reasons_text'] ?? ''));
                            ?>
                            <div class="npc-bounty-item">
                                <div class="npc-bounty-item-top">
                                    <span class="npc-bounty-faction"><?= htmlspecialchars($bdFaction) ?></span>
                                    <?php if ($bdAmountText !== ''): ?><span class="npc-bounty-amount"><?= htmlspecialchars($bdAmountText) ?></span><?php endif; ?>
                                </div>
                                <?php if ($bdReasonsText !== ''): ?><div class="npc-bounty-crimes">Wanted for: <?= htmlspecialchars($bdReasonsText) ?></div><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($editBountyBreakdownExtra > 0): ?>
                            <div class="npc-bounty-more">+<?= htmlspecialchars(strval($editBountyBreakdownExtra)) ?> more faction(s)</div>
                            <?php endif; ?>
                            <?php if (count($editBountyBreakdownItems) === 0 && $editBountyLegacyDetails !== ''): ?>
                            <div class="npc-bounty-legacy"><?= htmlspecialchars($editBountyLegacyDetails) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php
        // REINSERT Skills, Equipment, Stats, Inventory sections here (below Middle Term Memory)
        // Read metadata once
        $metaRaw = '';
        $metaObj = [];
        try {
            if (is_array($editItem ?? null) && !empty($editItem['metadata'])) {
                $metaRaw = (string)$editItem['metadata'];
                if ($metaRaw !== '') { $metaObj = json_decode($metaRaw, true) ?: []; }
            }
        } catch (Throwable $e) { $metaObj = []; }
        // Skills (from core_npc.skills column)
        $skillsText = trim((string)($editItem['skills'] ?? ''));
        ?>
        <div class="form-item span-2">
            <details class="metadata-skills-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;">
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">Skills</summary>
                <small class="hint">These will also be used for Skill context.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <?php if ($skillsText !== ''): ?>
                        <div style="border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; background:#1a1a1a; white-space:pre-wrap;"><?= htmlspecialchars($skillsText) ?></div>
                    <?php else: ?>
                        <div style="color:#9fb1c9;">No in-game skills found.</div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php
        // Equipment (from core_npc.equipment column)
        $equipmentText = trim((string)($editItem['equipment'] ?? ''));
        ?>
        <div class="form-item span-2">
            <details class="metadata-equipment-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;">
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">Current Equipment</summary>
                <small class="hint">Equipment NPC had when first added to AI system.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <?php if ($equipmentText !== ''): ?>
                        <div style="border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; background:#1a1a1a; white-space:pre-wrap;"><?= htmlspecialchars($equipmentText) ?></div>
                    <?php else: ?>
                        <div style="color:#9fb1c9;">No equipment data found.</div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php
        // Inventory (from core_npc.inventory column)
        $inventoryText = trim((string)($editItem['inventory'] ?? ''));
        $inventoryUpdated = isset($metaObj['inventory_updated']) ? $metaObj['inventory_updated'] : null;
        ?>
        <div class="form-item span-2">
            <details class="metadata-inventory-view" style="border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#262626;">
                <summary style="cursor:pointer; font-weight:700; color:#e6b76c;">
                    Inventory
                    <?php if ($inventoryUpdated): ?>
                        <span style="color:#999; font-weight:400; font-size:12px;">
                            Last updated: <?= date('Y-m-d H:i:s', $inventoryUpdated) ?>
                        </span>
                    <?php endif; ?>
                </summary>
                <small class="hint">NPC inventory updated in real-time as items are added/removed.</small>
                <div style="margin-top:8px; color:#cfd9ea;">
                    <?php if ($inventoryText !== ''): ?>
                        <div style="border:1px solid #4a4a4a; border-radius:6px; padding:8px 10px; background:#1a1a1a; white-space:pre-wrap;"><?= htmlspecialchars($inventoryText) ?></div>
                    <?php else: ?>
                        <div style="color:#9fb1c9;">No inventory data found.</div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <?php
        // Directed faction standings for this NPC's own faction, taken from the last game
        // snapshot. Resolved once per editor render and never for list rows.
        $factionStandings = null;
        if (is_array($editItem) && function_exists('stobeGetNpcFactionStandings')) {
            try {
                $factionStandings = stobeGetNpcFactionStandings($editItem);
            } catch (Throwable $exception) {
                $factionStandings = null;
            }
        }
        if (is_array($editItem)):
            $factionStatus = is_array($factionStandings) ? strval($factionStandings['status'] ?? 'unavailable') : 'unavailable';
            $factionSourceName = is_array($factionStandings) ? trim(strval($factionStandings['faction_name'] ?? '')) : '';
            $factionRows = (is_array($factionStandings) && is_array($factionStandings['relations'] ?? null))
                ? $factionStandings['relations']
                : [];
            $factionTruncated = is_array($factionStandings) && !empty($factionStandings['truncated']);
            // An "ok" response with nothing stored is still an empty state, not a table.
            if ($factionStatus === 'ok' && count($factionRows) === 0) {
                $factionStatus = 'empty';
            }
            $factionEmptyMessage = '';
            if ($factionStatus === 'unknown_faction') {
                $factionEmptyMessage = 'This NPC has no known faction.';
            } elseif ($factionStatus === 'empty') {
                $factionEmptyMessage = 'No faction standings received yet. Open Kenshi with Stobe to sync them.';
            } elseif ($factionStatus !== 'ok') {
                $factionEmptyMessage = 'Faction standings are unavailable.';
            }
        ?>
        <div class="form-item span-2" id="npc-faction-standings-section">
            <label>Faction Standings</label>
            <?php if ($factionSourceName !== ''): ?>
                <div class="npc-faction-source">Source faction: <strong><?= htmlspecialchars($factionSourceName) ?></strong></div>
            <?php endif; ?>
            <small class="hint">Last synced game standings. Shared by members of this faction.</small>
            <?php if ($factionEmptyMessage !== ''): ?>
                <p class="npc-faction-empty"><?= htmlspecialchars($factionEmptyMessage) ?></p>
            <?php else: ?>
                <div class="npc-faction-scroll" tabindex="0" role="region" aria-label="Faction standings table, scrollable">
                    <table class="npc-faction-table">
                        <caption class="npc-faction-caption">Last synced standings of <?= htmlspecialchars($factionSourceName !== '' ? $factionSourceName : 'this faction') ?> toward other factions.</caption>
                        <thead>
                            <tr>
                                <th scope="col">Faction</th>
                                <th scope="col" class="npc-faction-standing">Standing</th>
                                <th scope="col" class="npc-faction-status">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($factionRows as $factionRow): ?>
                                <?php
                                if (!is_array($factionRow)) {
                                    continue;
                                }
                                $factionRowName = trim(strval($factionRow['name'] ?? ''));
                                $factionRowFlags = stobeUiFactionStandingFlags($factionRow);
                                ?>
                                <tr>
                                    <th scope="row"><?= htmlspecialchars($factionRowName !== '' ? $factionRowName : 'Unknown') ?></th>
                                    <td class="npc-faction-standing"><?= htmlspecialchars(stobeUiFormatFactionStanding($factionRow['relation'] ?? null)) ?></td>
                                    <td class="npc-faction-status">
                                        <?php if (count($factionRowFlags) === 0): ?>
                                            <span aria-label="No recorded alliance, war, or coexistence">&mdash;</span>
                                        <?php else: ?>
                                            <?php foreach ($factionRowFlags as $factionRowFlag): ?>
                                                <span class="npc-faction-flag npc-faction-flag-<?= htmlspecialchars(strtolower($factionRowFlag)) ?>"><?= htmlspecialchars($factionRowFlag) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($factionTruncated): ?>
                    <p class="npc-faction-note">Showing the first 200 factions from the snapshot.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($editItem): ?>
        <div class="form-item span-2" id="npc-work-goals-section">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                <label style="margin:0;">Goals / Tasks</label>
                <button type="button" id="npc_work_goals_refresh" class="prompt-head-copy-btn">Refresh</button>
            </div>
            <small class="hint">Persistent STOBE production and task goals for this NPC. Read-only debug view; updates automatically while this profile is open.</small>
            <div id="npc_work_goals_meta" style="font-size:11px;color:#9fb1c9;">Loading...</div>
            <div id="npc_work_goals_list" style="display:flex;flex-direction:column;gap:8px;margin-top:4px;">
                <div style="color:#9fb1c9;">Loading work goals...</div>
            </div>
        </div>
        <script>
        (function(){
            const root = document.getElementById('npc-work-goals-section');
            const list = document.getElementById('npc_work_goals_list');
            const meta = document.getElementById('npc_work_goals_meta');
            const btn = document.getElementById('npc_work_goals_refresh');
            const npcName = <?= json_encode(strval($editItem['npc_name'] ?? '')) ?>;
            if (!root || !list || !npcName) return;

            function esc(v){
                return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({
                    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
                }[c]));
            }
            function badge(status){
                const s = String(status || 'ACTIVE').toUpperCase();
                let bg = '#3a3a3a', fg = '#e9efff', border = '#5a5a5a';
                if (s === 'ACTIVE') { bg='#183a25'; fg='#79e39a'; border='#2f8050'; }
                else if (s === 'COMPLETE') { bg='#1d334a'; fg='#8bc7ff'; border='#3c6f9f'; }
                else if (s === 'BLOCKED') { bg='#4a2525'; fg='#ff9e9e'; border='#944848'; }
                else if (s === 'CANCELLED') { bg='#3e3420'; fg='#e8c67a'; border='#7b6637'; }
                else if (s === 'PAUSED') { bg='#30343a'; fg='#c5cfdd'; border='#596474'; }
                else if (s === 'WAITING_APPROVAL') { bg='#4a3a17'; fg='#ffd879'; border='#9a782d'; }
                return '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;background:'+bg+';color:'+fg+';border:1px solid '+border+';">'+esc(s)+'</span>';
            }
            function render(goals){
                if (!Array.isArray(goals) || goals.length === 0) {
                    list.innerHTML = '<div style="border:1px solid #4a4a4a;border-radius:8px;padding:10px;background:#1a1a1a;color:#9fb1c9;">No work goals for this NPC yet.</div>';
                    return;
                }
                list.innerHTML = goals.map(g => {
                    const qty = Math.max(0, Number(g.quantity || 0));
                    const done = Math.max(0, Number(g.completed || 0));
                    const pct = qty > 0 ? Math.max(0, Math.min(100, Math.round((done / qty) * 100))) : 0;
                    const dest = String(g.destination_name || '').trim();
                    const target = String(g.target_name || '').trim();
                    const kind = String(g.kind || g.goal_type || '').trim();
                    const step = String(g.current_step || '').trim();
                    const reason = String(g.reason || '').trim();
                    const cap = Math.max(0, Number(g.max_spend || 0));
                    const spent = Math.max(0, Number(g.spent || 0));
                    const progressText = qty > 0 ? (done+' / '+qty) : (done > 0 ? String(done)+' done' : 'open-ended');
                    return '<div style="border:1px solid #4a4a4a;border-radius:8px;padding:10px;background:#1a1a1a;">'
                        + '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">'
                        + '<div><div style="font-size:10px;color:#e6b76c;font-weight:800;letter-spacing:.5px;">'+esc(kind.replaceAll('_',' '))+'</div>'
                        + '<div style="font-weight:800;color:#e9efff;">'+esc(g.item_name || kind || 'Goal')+' <span style="color:#9fb1c9;font-weight:600;">'+esc(progressText)+'</span></div></div>'
                        + badge(g.status)
                        + '</div>'
                        + (qty > 0 ? '<div style="height:7px;background:#292929;border:1px solid #444;border-radius:999px;overflow:hidden;margin-top:8px;"><div style="height:100%;width:'+pct+'%;background:#e6b76c;"></div></div>' : '')
                        + (target ? '<div style="margin-top:7px;font-size:12px;color:#cfd9ea;"><strong style="color:#e6b76c;">Target/filter:</strong> '+esc(target)+'</div>' : '')
                        + (dest ? '<div style="margin-top:5px;font-size:12px;color:#cfd9ea;"><strong style="color:#e6b76c;">Destination:</strong> '+esc(dest)+'</div>' : '')
                        + (cap > 0 ? '<div style="margin-top:5px;font-size:12px;color:#ffd879;"><strong>Trade:</strong> '+spent+' / '+cap+' Cats cap</div>' : (spent > 0 ? '<div style="margin-top:5px;font-size:12px;color:#ffd879;"><strong>Trade:</strong> '+spent+' Cats</div>' : ''))
                        + (step ? '<div style="margin-top:5px;font-size:12px;color:#cfd9ea;"><strong style="color:#e6b76c;">Current step:</strong> '+esc(step)+'</div>' : '')
                        + (reason ? '<div style="margin-top:5px;font-size:12px;color:#ffaaaa;"><strong>Reason/blocker:</strong> '+esc(reason)+'</div>' : '')
                        + '<div style="margin-top:6px;font-size:10px;color:#6f7f93;">Goal ID: '+esc(g.goal_id || '')+'</div>'
                        + '</div>';
                }).join('');
            }
            let busy = false;
            async function load(){
                if (busy) return;
                busy = true;
                if (btn) btn.disabled = true;
                try {
                    const res = await fetch('api/stobe_work_goals.php?name=' + encodeURIComponent(npcName) + '&_=' + Date.now(), {cache:'no-store'});
                    const j = await res.json();
                    if (!j || !j.ok) throw new Error((j && j.error) || 'Failed to load work goals');
                    render(j.goals || []);
                    if (meta) meta.textContent = 'Last refreshed: ' + new Date().toLocaleTimeString();
                } catch (e) {
                    list.innerHTML = '<div style="border:1px solid #744;border-radius:8px;padding:10px;background:#2a1717;color:#ffaaaa;">Could not load work goals: '+esc(e && e.message ? e.message : e)+'</div>';
                    if (meta) meta.textContent = 'Refresh failed';
                } finally {
                    busy = false;
                    if (btn) btn.disabled = false;
                }
            }
            if (btn) btn.addEventListener('click', load);
            load();
            const timer = setInterval(() => {
                if (!document.body.contains(root)) { clearInterval(timer); return; }
                if (document.visibilityState === 'visible') load();
            }, 3000);
        })();
        </script>
        <?php endif; ?>

        <div class="form-item span-2">
            <label for="metadata">Metadata (JSON)</label>
            <textarea id="metadata" name="metadata" placeholder="{}"><?= htmlspecialchars($editItem["metadata"] ?? "") ?></textarea>
            <small class="hint">General NPC metadata used by systems.</small>
        </div>

        <div class="form-item span-2">
            <label for="extended_data">Character Data</label>
            <small class="hint">Override global and profile settings for this specific NPC. Changes here take precedence over all other configurations.</small>
            <textarea id="extended_data" name="extended_data" placeholder="{}"><?= htmlspecialchars($editItem["extended_data"] ?? "") ?></textarea>
        </div>
    </div>

    <?php if (isset($_GET['partial']) && $_GET['partial']=='1') { ?>
        <button type="button" id="npc_modal_save" class="btn-save" style="display:none"><?= $editItem ? "Update" : "Create" ?></button>
        <script>
        (function(){
            const save = document.getElementById('npc_modal_save');
            if (!save) return;
            save.addEventListener('click', async function(){
                let form = save.closest('form');
                
                // Sync extended data overrides from visual UI
                try {
                  if (typeof window.syncExtendedDataOverrides === 'function') {
                    window.syncExtendedDataOverrides();
                  }
                } catch(_e) { console.error('Failed to sync extended data overrides:', _e); }
                
                // Sync feature checkboxes into extended_data (only save if differs from profile default)
                try {
                  const mtm = form.querySelector('#middle_term_enabled');
                  const dyn = form.querySelector('#dynamic_profile');
                  const autoDiary = form.querySelector('#auto_diary_enabled');
                  const imb = form.querySelector('#individual_memory_enabled');
                  if (form.metadata){
                    let metadataObj = {};
                    try { metadataObj = JSON.parse(String(form.metadata.value||'')||'{}')||{}; } catch(_e){ metadataObj = {}; }

                    if (mtm) {
                      const profileDefault = mtm.getAttribute('data-profile-default') === '1';
                      if (mtm.checked !== profileDefault) {
                        metadataObj.MIDDLE_TERM_MEMORY_ENABLED = mtm.checked ? 1 : 0;
                      } else {
                        delete metadataObj.MIDDLE_TERM_MEMORY_ENABLED;
                      }
                    }

                    if (dyn) {
                      const profileDefault = dyn.getAttribute('data-profile-default') === '1';
                      if (dyn.checked !== profileDefault) {
                        metadataObj.DYNAMIC_PROFILE_ENABLED = dyn.checked ? 1 : 0;
                      } else {
                        delete metadataObj.DYNAMIC_PROFILE_ENABLED;
                      }
                    }

                    if (autoDiary) {
                      const profileDefault = autoDiary.getAttribute('data-profile-default') === '1';
                      if (autoDiary.checked !== profileDefault) {
                        metadataObj.AUTO_DIARY_ENABLED = autoDiary.checked ? 1 : 0;
                      } else {
                        delete metadataObj.AUTO_DIARY_ENABLED;
                      }
                    }
                    form.metadata.value = JSON.stringify(metadataObj);
                  }

                  if (form.extended_data){
                    let obj = {};
                    try { obj = JSON.parse(String(form.extended_data.value||'')||'{}')||{}; } catch(_e){ obj = {}; }
                    
                    // Keep legacy extended_data key removed; metadata is source of truth.
                    delete obj.middle_term_enabled;
                    if (imb) {
                      if (imb.checked) {
                        obj.individual_memory_enabled = 1;
                      } else {
                        delete obj.individual_memory_enabled;
                      }
                    }
                    form.extended_data.value = JSON.stringify(obj);
                  }
                  
                  // Dynamic Profile: handled separately in form POST
                  if (dyn) {
                    const profileDefault = dyn.getAttribute('data-profile-default') === '1';
                    const dynHidden = form.querySelector('input[type="hidden"][name="dynamic_profile"]');
                    if (dyn.checked !== profileDefault) {
                      // Override: set explicit value
                      if (dynHidden) dynHidden.value = dyn.checked ? '1' : '0';
                      dyn.value = dyn.checked ? '1' : '0';
                    } else {
                      // Inherit: send empty/null to clear override
                      if (dynHidden) dynHidden.value = '';
                      dyn.value = '';
                    }
                  }

                  if (autoDiary) {
                    const profileDefault = autoDiary.getAttribute('data-profile-default') === '1';
                    const autoDiaryHidden = form.querySelector('input[type="hidden"][name="auto_diary_enabled"]');
                    if (autoDiary.checked !== profileDefault) {
                      if (autoDiaryHidden) autoDiaryHidden.value = autoDiary.checked ? '1' : '0';
                      autoDiary.value = autoDiary.checked ? '1' : '0';
                    } else {
                      if (autoDiaryHidden) autoDiaryHidden.value = '';
                      autoDiary.value = '';
                    }
                  }
                } catch(_e){ console.error('Failed to sync feature toggles:', _e); }

                // Sync edited middle_term_latest back into extended_data JSON
                /*
                try {
                  const mtmLatest = form.querySelector('#middle_term_latest');
                  if (mtmLatest && form.extended_data){
                    let obj = {};
                    try { obj = JSON.parse(String(form.extended_data.value||'')||'{}')||{}; } catch(_e){ obj = {}; }
                    const editedVal = String(mtmLatest.value||'').trim();
                    if (editedVal !== '') {
                      if (!Array.isArray(obj.middle_term_memory)) {
                        obj.middle_term_memory = [];
                      }
                      if (obj.middle_term_memory.length > 0) {
                        obj.middle_term_memory[obj.middle_term_memory.length - 1] = editedVal;
                      } else {
                        obj.middle_term_memory.push(editedVal);
                      }
                    }
                    form.extended_data.value = JSON.stringify(obj);
                    
                  }
                } catch(_e){ console.error('Failed to sync middle term memory:', _e); }
                */
                if (form.metadata!=undefined && typeof window.jsonEditor !== 'undefined' && jsonEditor && typeof jsonEditor.get === 'function') {
                  const content = jsonEditor.get();

                  try {
                    form.metadata.value = JSON.stringify(content.json, null, 0);
                    console.log("JSON editor values copied to form:", content.json);
                  } catch (idontcare) {}
        
                  // allow empty metadata without confirmation
                }

                const fd = new FormData(form);
                fd.append('inline_update_npc','1');
                if (!fd.has('id') && <?= json_encode(!empty($editItem['id'])) ?>){ fd.append('id', <?= json_encode($editItem['id'] ?? '') ?>); }
                const res = await fetch('npc_master.php', { method:'POST', body: fd });
                let json={}; try{ json=await res.json(); } catch(_e){}
                if (json && json.ok){
                    const payload = {};
                    form.querySelectorAll('input,textarea,select').forEach(el=>{ const n=el.name; if (!n) return; if (el.type==='checkbox'){ payload[n]=el.checked?1:0; } else { payload[n]=el.value; } });
                    // Ensure header tags input is captured
                    try { const ti = document.getElementById('modal_tags_input'); if (ti) payload['tags'] = ti.value; } catch(_){ }
                    const newId = json.id || payload.id || <?= json_encode($editItem['id'] ?? '') ?>;
                    payload.id = newId;
                    window.parent.postMessage({ type:'npc_saved', id: newId, data: payload }, '*');
                } else {
                    alert('Save failed: '+((json && json.error) ? json.error : res.status));
                }
            });
        })();
        </script>
        <script>
        (function(){
            const favBtn = document.getElementById('modal_fav_btn');
            const lockBtn = document.getElementById('modal_lock_btn');
            const lockField = document.getElementById('lock_profile');
            const idVal = <?= json_encode($editItem['id'] ?? '') ?>;
            if (favBtn && idVal){
                favBtn.addEventListener('click', async function(e){
                    e.preventDefault();
                    try{
                        const fd = new FormData(); fd.append('toggle_favorite','1'); fd.append('id', idVal);
                        const res = await fetch('npc_master.php', { method:'POST', body: fd });
                        let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){ const active = Number(json.favorite||0)===1; favBtn.classList.toggle('active', active); favBtn.textContent = active ? '\u2605' : '\u2606'; }
                    }catch(_e){}
                });
            }
            if (lockBtn && idVal){
                lockBtn.addEventListener('click', async function(e){
                    e.preventDefault();
                    try{
                        const fd = new FormData(); fd.append('toggle_lock','1'); fd.append('id', idVal);
                        const res = await fetch('npc_master.php', { method:'POST', body: fd });
                        let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){
                const active = Number(json.locked||0)===1;
                lockBtn.classList.toggle('active', active);
                lockBtn.textContent = active ? '\u{1F512}' : '\u{1F513}';
                if (lockField) {
                    lockField.checked = active;
                }
            }
                    }catch(_e){}
                });
            }
            if (lockField && lockBtn){
                lockField.addEventListener('change', function(){
                    const active = !!lockField.checked;
                    lockBtn.classList.toggle('active', active);
                    lockBtn.textContent = active ? '\u{1F512}' : '\u{1F513}';
                });
            }
        })();
        </script>
    <?php } else { ?>
        <button type="submit" name="<?= $editItem ? "update" : "create" ?>" class="btn-save"><?= $editItem ? "Update" : "Create" ?></button>
    <?php } ?>
</form>
<script src="js/npc_event_history.js"></script>
<script>
(function(){
    function mountNpcEventHistory(){
        const panel = document.querySelector('[data-npc-editor-panel="history"]');
        const idInput = document.querySelector('form input[name="id"]');
        const nameInput = document.getElementById('npc_name');
        if (!panel || !idInput || !nameInput || !window.stobeNpcEventHistory || panel.dataset.historyMounted === '1') return;
        panel.dataset.historyMounted = '1';
        window.stobeNpcEventHistoryController = window.stobeNpcEventHistory.mount(panel, {
            npcId: Number(idInput.value || 0),
            npcName: nameInput.value || '',
            apiUrl: 'api/stobe_npc_history.php'
        });
        const active = document.querySelector('[data-npc-editor-tab="history"].is-active');
        if (active) window.stobeNpcEventHistoryController.load();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountNpcEventHistory);
    else mountNpcEventHistory();
})();
</script>
<?php if (isset($_GET['partial']) && $_GET['partial']=='1') { ?>
    <?php include(__DIR__."/tmpl/metadata_json_editor.php"); ?>
    </div>
    <?php exit; } ?>
</div>

<style>
.npc-grid { display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:14px; }
@media (max-width: 1400px){ .npc-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 1100px){ .npc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 720px){ .npc-grid { grid-template-columns: 1fr; } }
.npc-card { 
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98)); 
    border: 1px solid #3a3a3a; 
    border-radius: 10px; 
    padding: 16px; 
    display: flex; 
    flex-direction: column; 
    gap: 10px; 
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15),
                inset 0 1px rgba(255, 255, 255, 0.03); 
    transition: all 0.2s ease; 
    cursor: pointer; 
}
.npc-card.npc-card-player-faction {
    border-color: rgba(230, 183, 108, 0.95);
    box-shadow: 0 0 0 1px rgba(230, 183, 108, 0.28),
                0 4px 14px rgba(230, 183, 108, 0.18),
                inset 0 1px rgba(255, 255, 255, 0.05);
}
.npc-card:hover { 
    transform: translateY(-2px); 
    background: linear-gradient(180deg, rgba(48, 48, 48, 0.95), rgba(40, 40, 40, 0.98)); 
    border-color: #4a4a4a;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25),
                inset 0 1px rgba(255, 255, 255, 0.05);
}
.npc-card.npc-card-player-faction:hover {
    border-color: #ffd277;
    box-shadow: 0 0 0 1px rgba(255, 210, 119, 0.45),
                0 6px 18px rgba(230, 183, 108, 0.3),
                inset 0 1px rgba(255, 255, 255, 0.08);
}
.npc-card.npc-card-dead {
    border-color: rgba(232, 82, 82, 0.98);
    box-shadow: 0 0 0 1px rgba(232, 82, 82, 0.4),
                0 5px 16px rgba(120, 18, 18, 0.34),
                inset 0 1px rgba(255, 255, 255, 0.05);
}
.npc-card.npc-card-dead:hover {
    border-color: #ff8a8a;
    box-shadow: 0 0 0 1px rgba(255, 138, 138, 0.5),
                0 7px 20px rgba(130, 20, 20, 0.42),
                inset 0 1px rgba(255, 255, 255, 0.08);
}
.npc-title { font-weight:800; color:#e9efff; font-size:18px; text-align:center; letter-spacing:0.3px; display:flex; align-items:flex-start; justify-content:space-between; gap:8px; min-width:0; }
.npc-title-left { flex:1 1 auto; min-width:0; text-align:left; display:flex; align-items:center; flex-wrap:wrap; column-gap:4px; }
.npc-title-actions { display:flex; align-items:center; justify-content:flex-end; gap:6px; flex:0 0 auto; max-width:none; min-width:0; flex-wrap:nowrap; white-space:nowrap; }
.npc-title-actions > * { flex:0 0 auto; }
.npc-name { display:-webkit-box; max-width:min(100%, 22ch); overflow:hidden; overflow-wrap:anywhere; white-space:normal; -webkit-box-orient:vertical; -webkit-line-clamp:2; line-clamp:2; line-height:1.25; }
.npc-gender-icon { margin-left:6px; opacity:0.9; }
.npc-gender-icon.gender-female { color:#ff72d2; }
.npc-gender-icon.gender-male { color:#72a0ff; }
.npc-gender-icon.gender-nb { color:#ffd166; }
.npc-dyn-icon { margin-left:6px; color:#65d46e; opacity:0.95; }
.npc-mtm-icon { margin-left:6px; color:#9fb1ff; opacity:0.95; }
.npc-imb-icon { margin-left:6px; color:#70d4d4; opacity:0.95; }
.npc-blc-icon { margin-left:6px; color:#8db4e2; opacity:0.95; }
.npc-gps-icon { margin-left:6px; color:#ff6b6b; opacity:0.95; }
.npc-divider { height:1px; background: linear-gradient(90deg, transparent, rgba(230, 183, 108, 0.3) 50%, transparent); margin:6px 0 10px; }
.npc-fields { display:flex; flex-direction:column; gap:8px; }
.npc-line { color:#e0e0e0; font-size:13px; line-height:1.35; }
.npc-muted { color:#e6b76c; }
.npc-faction-name { overflow-wrap:anywhere; }
.npc-bounty-section {
    margin-top: 4px;
    padding: 8px 10px;
    border: 1px solid rgba(230, 183, 108, 0.22);
    border-radius: 8px;
    background: rgba(230, 183, 108, 0.08);
}
.npc-bounty-heading {
    color: #f0c880;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 6px;
}
.npc-bounty-breakdown { display:flex; flex-direction:column; gap:6px; }
.npc-bounty-item { border-bottom: 1px dashed rgba(230, 183, 108, 0.2); padding-bottom: 6px; }
.npc-bounty-item:last-child { border-bottom: none; padding-bottom: 0; }
.npc-bounty-item-top { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.npc-bounty-faction { color:#e9efff; font-size:12px; font-weight:600; }
.npc-bounty-amount { color:#ffd9a3; font-size:12px; white-space:nowrap; }
.npc-bounty-crimes { color:#d8c6aa; font-size:11px; line-height:1.35; margin-top:3px; }
.npc-bounty-more { color:#b2c0d8; font-size:11px; }
.npc-bounty-legacy { color:#d8c6aa; font-size:11px; line-height:1.35; }
.npc-actions { display:flex; gap:8px; margin-top:6px; justify-content:center; }
.npc-actions .btn { padding:6px 10px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff; text-decoration:none; cursor:pointer; }
.npc-actions .btn:hover { background:#3a3a3a; }
.npc-actions .btn-danger { background:#5a2a2a; border-color:#7a3a3a; }
.npc-actions .btn-danger:hover { background:#6a2a2a; }
.npc-title-actions a { text-decoration:none; border:none; }
.npc-title-actions a:hover { text-decoration:none; }
.btn-toggle { background:transparent; border:none; padding:6px; color:#e9efff; font-size:22px; line-height:1; text-decoration:none; transition: color .15s ease, text-shadow .15s ease; }
/* Navbar-like glow only for lock icon on cards */
.btn-toggle[data-lock-id]:hover,
.btn-toggle[data-lock-id]:focus-visible { color: #e6b76c; background:transparent; text-decoration:none; text-shadow: 0 0 6px rgba(230, 183, 108, 0.6), 0 0 12px rgba(230, 183, 108, 0.35); }
.npc-title-actions .btn-toggle[data-favorite-id]:hover,
.npc-title-actions .btn-toggle[data-favorite-id]:focus-visible { color:#ffd700 !important; text-shadow: 0 0 8px rgba(255, 215, 0, 0.7), 0 0 14px rgba(255, 215, 0, 0.45) !important; }
.btn-toggle.active { color: #e6b76c; font-weight:700; text-decoration:none; }
.npc-title-actions .btn-toggle.active[data-favorite-id] { color:#ffd700 !important; }
.btn-trash { background:transparent; border:none; padding:6px; color:#e9efff; font-size:20px; line-height:1; text-decoration:none; transition: color .15s ease, text-shadow .15s ease; }
.btn-trash:hover, .btn-trash:focus-visible { color:#ff6b6b; text-shadow: 0 0 6px rgba(255, 107, 107, 0.7), 0 0 12px rgba(255, 107, 107, 0.45); }
.npc-player-faction-badge {
    display:inline-flex;
    align-items:center;
    font-size:11px;
    font-weight:700;
    color:#2b2000;
    background:linear-gradient(180deg, #ffd77f, #e6b76c);
    border:1px solid rgba(255, 225, 150, 0.95);
    border-radius:999px;
    padding:2px 8px;
    box-shadow:0 0 8px rgba(230, 183, 108, 0.35);
    white-space:nowrap;
}
.npc-dead-badge {
    display:inline-flex;
    align-items:center;
    font-size:11px;
    font-weight:800;
    color:#fff3f3;
    background:linear-gradient(180deg, #c83d3d, #932323);
    border:1px solid rgba(255, 132, 132, 0.95);
    border-radius:999px;
    padding:2px 8px;
    box-shadow:0 0 8px rgba(200, 61, 61, 0.4);
    white-space:nowrap;
}
.npc-tags-label { font-size:11px; color:#9fb1c9; margin-right:4px; }
.npc-tags-top { display:inline-block; font-size:11px; color:#9fb1c9; border:1px solid #4a4a4a; border-radius:999px; padding:2px 6px; max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.npc-row { display:flex; gap:10px; align-items:flex-start; }
.npc-portrait-col { flex:0 0 74px; width:74px; display:flex; align-items:flex-start; justify-content:center; }
.npc-portrait-img,
.npc-portrait-fallback {
    width:74px;
    height:92px;
    border-radius:8px;
    border:1px solid #4a4a4a;
    box-shadow: 0 2px 6px rgba(0,0,0,0.35);
}
.npc-portrait-img {
    display:block;
    object-fit:cover;
    background:#1d1d1d;
}
.npc-portrait-fallback {
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:28px;
    font-weight:800;
    color:#d6dff0;
    background:linear-gradient(160deg, #3a424f, #242a34);
}
.npc-right { margin-left:auto; flex:0 0 auto; }
@media (max-width: 720px){
    .npc-portrait-col { flex:0 0 64px; width:64px; }
    .npc-portrait-img,
    .npc-portrait-fallback { width:64px; height:80px; }
    .npc-right { display:none; }
}
/* Dynamic profile grouping */
.dynamic-profile-section { 
    border:1px solid #3a3a3a; 
    border-radius:8px; 
    padding:12px; 
    margin:10px 0; 
    background: linear-gradient(135deg, rgba(26, 26, 26, 0.8), rgba(32, 32, 32, 0.6)); 
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
}
.dynamic-profile-section .section-title { 
    font-weight:700; 
    color:#e6b76c; 
    margin-bottom:8px; 
    font-size: 1.05em;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
}
.dynamic-profile-section > .form-item { margin-bottom:10px; }
</style>
<style>
/* Modal styling aligned with World Knowledge edit modal */
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:10000; align-items:center; justify-content:center; overflow-y:auto; padding:20px 0; }
.modal-container { 
    position:relative; 
    top:auto; 
    left:auto; 
    transform:none; 
    max-width:1200px; 
    width:95%; 
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.98), rgba(34, 34, 34, 0.98)); 
    border: 1px solid #3a3a3a; 
    border-radius: 12px; 
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
}
.modal-header { 
    display:flex; 
    justify-content:space-between; 
    align-items:center; 
    padding:16px 18px; 
    border-bottom: 1px solid rgba(230, 183, 108, 0.2); 
    background: rgba(42, 42, 42, 0.95); 
    position:sticky; 
    top:0; 
    z-index:2; 
    border-radius: 12px 12px 0 0;
}
.modal-title { 
    margin:0; 
    font-weight:700; 
    color: #e6b76c; 
    font-family: 'MagicCards', serif; 
    word-spacing: 6px; 
    font-size: 1.4em;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
}
.modal-body { 
    max-height:calc(85vh - 100px); 
    background: rgba(34, 34, 34, 0.95); 
}
/* Edit NPC modal: add subtle horizontal breathing room */
#npc_modal .modal-container {
    box-sizing: border-box;
    padding-left: 12px;
    padding-right: 12px;
}
.modal-close { 
    background:#3a3a3a; 
    color:#fff; 
    border:1px solid #4a4a4a; 
    border-radius:6px; 
    padding:6px 12px; 
    cursor:pointer; 
    transition: all 0.2s ease;
}
.modal-close:hover {
    background:#4a4a4a;
    border-color:#5a5a5a;
}
.modal-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.modal-actions .btn-save { 
    background: linear-gradient(135deg, #176529, #125121); 
    color:#fff; 
    border:1px solid rgba(72,187,120,0.3); 
    border-radius:6px; 
    padding:10px 16px; 
    cursor:pointer; 
    font-weight:700; 
    font-size:13px; 
    transition:all 0.2s ease; 
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.modal-actions .btn-save:hover { 
    background: linear-gradient(135deg, #125121, #0d3d19); 
    border-color:rgba(72,187,120,0.5); 
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
}
.modal-actions .btn-cancel { 
    background:#3a3a3a; 
    color:#e9efff; 
    border:1px solid #4a4a4a; 
    border-radius:6px; 
    padding:10px 16px; 
    cursor:pointer; 
    font-weight:600; 
    font-size:13px; 
    transition:all 0.2s ease; 
}
.modal-actions .btn-cancel:hover { 
    background:#4a4a4a; 
    border-color:#5a5a5a; 
    color:#e6b76c; 
    transform: translateY(-1px);
}
.modal-actions #npc_modal_regen { 
    background:rgba(230, 183, 108,0.15); 
    border-color:#e6b76c; 
    color:#e6b76c; 
}
.modal-actions #npc_modal_regen:hover { 
    background:rgba(230, 183, 108,0.3); 
    border-color:#e6b76c;
    transform: translateY(-1px);
}
.modal-actions #npc_modal_close { 
    background: linear-gradient(135deg, #5a2a2a, #4a1a1a); 
    border-color:#7a3a3a; 
    color:#fff; 
}
.modal-actions #npc_modal_close:hover { 
    background: linear-gradient(135deg, #6a3a3a, #5a2a2a); 
    transform: translateY(-1px);
}
.modal-save { 
    background: #e6b76c; 
    color:#111; 
    border:1px solid #e6b76c; 
    border-radius:6px; 
    padding:8px 14px; 
    cursor:pointer; 
    font-weight:700; 
    transition: all 0.2s ease;
}
.modal-save:hover {
    background: rgb(230, 183, 108);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(230, 183, 108, 0.3);
}
/* Styled tabs to match button aesthetics */
#npc_modal_tabs .pf-tab { 
    padding:8px 14px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(42, 42, 42, 0.8); 
    color:#e9efff; 
    cursor:pointer; 
    font-weight:700; 
    transition: all 0.2s ease;
}
#npc_modal_tabs .pf-tab:hover { 
    background: rgba(58, 58, 58, 0.9); 
    border-color: #4a4a4a;
}
#npc_modal_tabs .pf-tab.active { 
    background: linear-gradient(135deg, rgba(230, 183, 108, 0.2), rgba(230, 183, 108, 0.1)); 
    color: #e6b76c; 
    border-color: rgba(230, 183, 108, 0.5); 
    box-shadow: inset 0 -2px 0 #e6b76c;
}
</style>
<?php if ($totalPages >= 1): ?>
<style>
.pagination { display:flex; gap:8px; align-items:center; justify-content:center; margin:16px 0 0 0; flex-wrap:wrap; }
.pagination:not(.npc-toolbar) a, .pagination:not(.npc-toolbar) span { 
    padding:8px 12px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(42, 42, 42, 0.8); 
    color:#e9efff; 
    text-decoration:none; 
    transition: all 0.2s ease;
}
.pagination:not(.npc-toolbar) a:hover { 
    background: rgba(58, 58, 58, 0.9); 
    border-color: #4a4a4a;
    transform: translateY(-1px);
}
.pagination:not(.npc-toolbar) .active { 
    background: linear-gradient(135deg, rgba(230, 183, 108, 0.2), rgba(230, 183, 108, 0.1)); 
    color: #e6b76c; 
    border-color: rgba(230, 183, 108, 0.5); 
    font-weight:700; 
    box-shadow: inset 0 -2px 0 #e6b76c;
}
.pagination:not(.npc-toolbar) .disabled { opacity:0.4; pointer-events:none; }
.pagination:not(.npc-toolbar) button { 
    padding:8px 14px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(42, 42, 42, 0.8); 
    color:#e9efff; 
    cursor:pointer; 
    transition: all 0.2s ease;
    font-weight: 600;
}
.pagination:not(.npc-toolbar) button:hover { 
    background: rgba(58, 58, 58, 0.9); 
    border-color: #4a4a4a;
    transform: translateY(-1px);
}
.npc-letter-filter {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    align-items:center;
    justify-content:flex-start;
    margin-top:10px;
}
.npc-letter-btn {
    background:#2a2a2a;
    border:1px solid #4a4a4a;
    color:#cfd9ea;
    border-radius:6px;
    padding:6px 10px;
    cursor:pointer;
    min-width:36px;
    font-weight:700;
    transition:all 0.18s ease;
}
.npc-letter-btn:hover {
    background:#333333;
    border-color:#5a5a5a;
    transform: translateY(-1px);
}
.npc-letter-btn.active {
    background:rgba(230,183,108,0.16);
    border-color:rgba(230,183,108,0.7);
    color:#e6b76c;
    box-shadow: inset 0 0 0 1px rgba(230,183,108,0.18);
}
.pagination.npc-toolbar {
    display:flex;
    flex-direction:column;
    align-items:stretch;
    justify-content:flex-start;
    gap:12px;
    padding:14px;
    margin:0;
    background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
    border-radius: 10px;
    border: 1px solid #3a3a3a;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.pagination.npc-toolbar .npc-toolbar-main,
.pagination.npc-toolbar .npc-toolbar-subrow,
.pagination.npc-toolbar .npc-toolbar-letter-row {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}
.pagination.npc-toolbar .npc-toolbar-actions,
.pagination.npc-toolbar .npc-toolbar-tools,
.pagination.npc-toolbar .npc-toolbar-pager {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}
.pagination.npc-toolbar .npc-toolbar-main {
    align-items:flex-start;
    flex-wrap:nowrap;
}
.pagination.npc-toolbar .npc-toolbar-actions {
    flex:1 1 0;
    min-width:0;
    flex-wrap:wrap;
}
.pagination.npc-toolbar .npc-auto-lock-profile {
    display:flex;
    align-items:center;
    gap:8px;
    color:#e9efff;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
}
.pagination.npc-toolbar .npc-auto-lock-profile input {
    accent-color:#e6b76c;
    cursor:pointer;
}
.pagination.npc-toolbar .npc-toolbar-tools {
    flex:0 0 auto;
    width:auto;
    min-width:0;
    margin-left:auto;
    flex-direction:row;
    align-items:center;
    justify-content:flex-end;
    flex-wrap:nowrap;
}
.pagination.npc-toolbar .npc-toolbar-pager {
    flex:1 1 auto;
}
.pagination.npc-toolbar .npc-toolbar-letter-row {
    align-items:flex-end;
}
.pagination.npc-toolbar .npc-toolbar-letter-row .npc-letter-filter {
    flex:1 1 auto;
    width:auto;
    margin-top:0;
}
.pagination.npc-toolbar .npc-toolbar-summary {
    margin-left:auto;
    display:flex;
    align-items:flex-end;
    gap:8px;
    flex-wrap:wrap;
}
.pagination.npc-toolbar .npc-toolbar-btn {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    box-sizing:border-box;
    margin:0;
    padding:8px 14px;
    border-radius:8px;
    border:1px solid rgba(230, 183, 108, 0.32);
    font-size:14px;
    font-weight:600;
    line-height:1.2;
    font-family:inherit;
    cursor:pointer;
    transition:background 0.18s ease, border-color 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
    white-space:nowrap;
}
.pagination.npc-toolbar .npc-toolbar-btn:hover {
    transform:translateY(-1px);
}
.pagination.npc-toolbar .npc-toolbar-btn-uniform {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    flex:0 0 228px;
    width:228px;
    min-width:228px;
    max-width:228px;
    box-sizing:border-box;
    margin:0;
    padding:8px 14px;
    text-align:center;
}
.pagination.npc-toolbar .npc-toolbar-btn-action {
    background:rgba(42, 42, 42, 0.8);
    border-color:rgba(230, 183, 108, 0.48);
    color:#ffffff;
    box-shadow:0 4px 10px rgba(0, 0, 0, 0.18);
}
.pagination.npc-toolbar .npc-toolbar-btn-action:hover {
    background:rgba(54, 54, 54, 0.92);
    border-color:rgba(230, 183, 108, 0.72);
    color:#ffffff;
}
.pagination.npc-toolbar .npc-toolbar-btn-danger {
    background: linear-gradient(135deg, rgba(150, 36, 36, 0.96), rgba(110, 22, 22, 0.96));
    border-color: rgba(255, 115, 115, 0.45);
    color: #fff1f1;
    box-shadow: 0 4px 10px rgba(126, 24, 24, 0.22);
}
.pagination.npc-toolbar .npc-toolbar-btn-danger:hover {
    background: linear-gradient(135deg, rgba(168, 42, 42, 1), rgba(126, 26, 26, 1));
    border-color: rgba(255, 145, 145, 0.6);
}
.pagination.npc-toolbar .npc-filter-dropdown {
    position:relative;
    flex:0 0 228px;
    width:228px;
}
.pagination.npc-toolbar .npc-toolbar-filter-btn {
    width:100%;
}
.pagination.npc-toolbar .npc-filter-menu {
    position:absolute;
    left:0;
    top:calc(100% + 6px);
    min-width:220px;
    display:none;
    background:#2a2a2a;
    border:1px solid #4a4a4a;
    border-radius:8px;
    padding:8px;
    box-shadow:0 6px 18px rgba(0,0,0,0.35);
    z-index:15;
}
.pagination.npc-toolbar .npc-filter-menu label {
    display:flex;
    align-items:center;
    gap:8px;
    margin:4px 0;
    color:#e9efff;
    font-size:13px;
}
.pagination.npc-toolbar .npc-toolbar-tools input[type="text"] { 
    padding:6px 10px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(26, 26, 26, 0.8); 
    color:#e9efff; 
    height:32px;
    width:220px;
    min-width:0;
    max-width:220px;
    transition: all 0.2s ease;
}
.pagination.npc-toolbar .npc-toolbar-tools input[type="text"]:focus {
    border-color: rgba(230, 183, 108, 0.5);
    outline: none;
    box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
}
.pagination.npc-toolbar .npc-toolbar-tools select { 
    padding:6px 10px; 
    border-radius:6px; 
    border:1px solid #3a3a3a; 
    background: rgba(26, 26, 26, 0.8); 
    color:#e9efff; 
    height:32px;
    width:180px;
    min-width:0;
    max-width:180px;
    transition: all 0.2s ease;
}
.pagination.npc-toolbar .npc-toolbar-tools select:focus {
    border-color: rgba(230, 183, 108, 0.5);
    outline: none;
    box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
}
.pagination.npc-toolbar .npc-page-link {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    box-sizing:border-box;
    margin:0;
    font-family:inherit;
    appearance:none;
    cursor:pointer;
}
.pagination.npc-toolbar .npc-page-link.disabled,
.pagination.npc-toolbar .npc-page-link:disabled {
    opacity:0.4;
    pointer-events:none;
    transform:none;
}
.pagination.npc-toolbar .npc-page-indicator {
    color:#e6b76c;
    font-weight:700;
    padding:0 4px;
}
.pagination.npc-toolbar .npc-total-pill {
    display:flex;
    align-items:center;
    gap:10px;
    padding:8px 12px;
    border-radius:8px;
    border:1px solid #3a3a3a;
    background:rgba(26, 26, 26, 0.78);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.02);
}
.pagination.npc-toolbar .npc-total-pill-icon {
    font-size:15px;
    opacity:0.9;
}
.pagination.npc-toolbar .npc-total-pill-value {
    color:#e9efff;
    font-weight:700;
    font-size:22px;
    line-height:1;
}
@media (max-width: 1200px) {
    .pagination.npc-toolbar .npc-toolbar-actions {
        min-width:0;
        flex-wrap:wrap;
    }
    .pagination.npc-toolbar .npc-toolbar-tools {
        justify-content:flex-start;
    }
    .pagination.npc-toolbar .npc-total-pill {
        margin-left:0;
    }
}
@media (max-width: 780px) {
    .pagination.npc-toolbar .npc-toolbar-main {
        flex-wrap:wrap;
    }
    .pagination.npc-toolbar .npc-toolbar-tools,
    .pagination.npc-toolbar .npc-toolbar-actions,
    .pagination.npc-toolbar .npc-toolbar-pager,
    .pagination.npc-toolbar .npc-toolbar-summary,
    .pagination.npc-toolbar .npc-toolbar-letter-row {
        width:100%;
    }
    .pagination.npc-toolbar .npc-toolbar-subrow {
        align-items:flex-start;
    }
    .pagination.npc-toolbar .npc-toolbar-tools {
        flex:1 1 100%;
        width:100%;
        min-width:0;
        margin-left:0;
        flex-wrap:wrap;
        justify-content:flex-start;
    }
    .pagination.npc-toolbar .npc-toolbar-tools input[type="text"],
    .pagination.npc-toolbar .npc-toolbar-tools select {
        flex:1 1 100%;
        width:100%;
        max-width:none;
    }
    .pagination.npc-toolbar .npc-toolbar-letter-row {
        align-items:flex-start;
    }
    .pagination.npc-toolbar .npc-toolbar-letter-row .npc-letter-filter {
        width:100%;
    }
    .pagination.npc-toolbar .npc-toolbar-summary {
        margin-left:0;
        align-items:flex-start;
    }
}
</style>
<?php renderStobeNpcToolbar([
    'top' => true,
    'q' => $q,
    'nameLetterFilter' => $nameLetterFilter,
    'profileRows' => $profileRows ?? [],
    'profileIdFilter' => $profileIdFilter,
    'page' => $page,
    'totalPages' => $totalPages,
    'totalRows' => $totalRows,
    'favOnly' => $favOnly,
    'dynOnly' => $dynOnly,
    'mtmOnly' => $mtmOnly,
    'lockOnly' => $lockOnly,
    'playerFactionOnly' => $playerFactionOnly,
]); ?>
<div style="margin:8px 0 10px; padding:10px 14px; background:rgba(230, 183, 108,0.08); border:1px solid rgba(230, 183, 108,0.25); border-radius:8px; font-size:12.5px; color:#cfd9ea; line-height:1.5;">
  <strong style="color:#e6b76c;">Stobe Save Rollback:</strong>
  Every time a save is loaded, Stobe snapshots NPC profiles and restores <strong>unlocked</strong> NPCs to the state captured at that save's Kenshi game timestamp.
  Loading an older save will roll unlocked profiles back to that point in time. NPCs created <em>after</em> that save timestamp may disappear.
  <div style="margin-top:4px;">
    <span style="color:#e6b76c;">Lock a profile (Lock) to protect it from rollback.</span>
    You can view and restore previous versions of any NPC via the <strong>Profile Versions</strong> button in the edit modal.
  </div>
</div>
<div class="npc-grid">
    <?php foreach ($data as $row): ?>
    <?php 
    $pid = (string)($row['profile_id'] ?? ''); 
    $profLabel = $profilesById[$pid] ?? ''; 
    $tagsVal = trim((string)($row['tags'] ?? '')); 
    $tagsDisp = ($tagsVal === '') ? 'none' : $tagsVal; 
    $metaTmp = []; 
    if (!empty($row['metadata'])) { 
        $tmp = json_decode((string)$row['metadata'], true); 
        if (is_array($tmp)) { $metaTmp = $tmp; } 
    } 
    $bountySummary = stobe_ui_format_bounty_summary(
        $row['bounty'] ?? 0,
        $row['bounty_payload'] ?? null,
        $metaTmp
    );
    $bountyAmountText = $bountySummary['amount_text'];
    $bountyDetailsText = $bountySummary['details_text'];
    $bountyBreakdownItems = is_array($bountySummary['breakdown_items'] ?? null) ? $bountySummary['breakdown_items'] : [];
    $bountyBreakdownExtra = intval($bountySummary['breakdown_extra'] ?? 0);
    $bountyLegacyDetails = trim(strval($bountySummary['legacy_details'] ?? ''));
    $extTmp = []; 
    if (!empty($row['extended_data'])) { 
        $tmp2 = json_decode((string)$row['extended_data'], true); 
        if (is_array($tmp2)) { $extTmp = $tmp2; } 
    }
    
    // Check for inherited profile settings
    $profileMeta = isset($profileMetaById[$pid]) ? $profileMetaById[$pid] : ['dyn'=>false,'mtm'=>false,'blc'=>false,'gps'=>false];
    
    // Dynamic Profile: check NPC override, otherwise inherit from profile
    $dynEnabled = $profileMeta['dyn']; // default to profile
    if (isset($row['dynamic_profile']) && $row['dynamic_profile'] !== null && $row['dynamic_profile'] !== '') {
        $dynEnabled = coerceBoolean($row['dynamic_profile']);
    }

    // MTM: check metadata override, otherwise legacy extended_data, otherwise inherit from profile
    $mtmEnabled = $profileMeta['mtm']; // default to profile
    $mtmOverride = stobeUiResolveMtmOverride($metaTmp, $extTmp);
    if ($mtmOverride !== null) {
        $mtmEnabled = $mtmOverride;
    }

    // Individual memory bank is NPC-only (no profile inheritance).
    $imbEnabled = stobeUiResolveIndividualMemoryEnabled($extTmp);
    
    // Background Life Commands: check extended_data override, otherwise inherit from profile
    $blcEnabled = $profileMeta['blc']; // default to profile
    if (array_key_exists('background_life_commands', $extTmp) && $extTmp['background_life_commands'] !== null && $extTmp['background_life_commands'] !== '') {
        $blcEnabled = !empty($extTmp['background_life_commands']);
    }
    
    // GPS Track: check metadata override, otherwise inherit from profile
    $gpsEnabled = $profileMeta['gps']; // default to profile
    if (array_key_exists('gps_track', $metaTmp) && $metaTmp['gps_track'] !== null && $metaTmp['gps_track'] !== '') {
        $gpsEnabled = !empty($metaTmp['gps_track']);
    }
    $npcNameCard = strval($row["npc_name"] ?? '');
    $portraitUrl = stobe_ui_resolve_portrait_url($metaTmp, $webRoot);
    $portraitInitial = stobe_ui_portrait_fallback_char($npcNameCard);
    $isPlayerFactionNpc = stobeUiNpcIsInPlayerFaction($row);
    $currentActionCard = strtolower(trim(strval($metaTmp['current_action'] ?? ($row['current_action'] ?? ''))));
    $isDeadCard = ($currentActionCard === 'dead');
    
    ?>
    <div class="npc-card<?= $isPlayerFactionNpc ? ' npc-card-player-faction' : '' ?><?= $isDeadCard ? ' npc-card-dead' : '' ?>" id="npc_card_<?= htmlspecialchars($row["id"]) ?>" data-id="<?= htmlspecialchars($row["id"]) ?>" data-player-faction="<?= $isPlayerFactionNpc ? '1' : '0' ?>" data-current-action="<?= htmlspecialchars($currentActionCard) ?>">
            <div class="npc-title">
            <div class="npc-title-left"><?php 
                $levelDisp2 = '';
                if (isset($metaTmp['stats']) && is_array($metaTmp['stats']) && isset($metaTmp['stats']['level'])) {
                    $levelDisp2 = ' ('.intval($metaTmp['stats']['level']).')';
                }
                ?><span class="npc-name"><?= htmlspecialchars($npcNameCard.$levelDisp2) ?></span> <?php $gch = gender_icon_char($row['gender'] ?? ''); $gcl = gender_icon_class($row['gender'] ?? ''); if ($gch!==''): ?><span class="npc-gender-icon <?= htmlspecialchars($gcl) ?>" title="<?= htmlspecialchars($row['gender'] ?? '') ?>"><?= $gch ?></span><?php endif; ?><?php if (!empty($dynEnabled)): ?><span class="npc-dyn-icon" title="Dynamic profile enabled">&#x267B;&#xFE0F;</span><?php endif; ?><?php if (!empty($mtmEnabled)): ?><span class="npc-mtm-icon" title="Middle-term memory enabled">&#x1F4C3;</span><?php endif; ?><?php if (!empty($imbEnabled)): ?><span class="npc-imb-icon" title="Individual memory bank enabled">&#x1F9E0;</span><?php endif; ?><?php if (!empty($blcEnabled)): ?><span class="npc-blc-icon" title="Background life commands enabled">&#x1F3AE;</span><?php endif; ?><?php if (!empty($gpsEnabled)): ?><span class="npc-gps-icon" title="GPS track enabled">&#x1F4CD;</span><?php endif; ?></div>
            <div class="npc-title-actions">
                <?php if ($isDeadCard): ?>
                <span class="npc-dead-badge" title="Current action: dead">Dead</span>
                <?php endif; ?>
                <?php if ($isPlayerFactionNpc): ?>
                <span class="npc-player-faction-badge" title="Aligned with the player faction">Player Faction</span>
                <?php endif; ?>
                <?php if ($tagsDisp !== ''): ?>
                <span class="npc-tags-label">Tags:</span>
                <span class="npc-tags-top" title="Use Search to filter by these tags: <?= htmlspecialchars($tagsDisp) ?>"><?= htmlspecialchars($tagsDisp) ?></span>
                <?php endif; ?>
                                <a class="btn btn-toggle <?= coerceBoolean($row["npc_favorite"] ?? false) ? "active" : "" ?>" href="#" data-favorite-id="<?= $row["id"] ?>" title="Toggle favorite"><?php echo coerceBoolean($row["npc_favorite"] ?? false) ? "&#9733;" : "&#9734;"; ?></a>
                                <a class="btn btn-toggle <?= coerceBoolean($row["lock_profile"] ?? false) ? "active" : "" ?>" href="#" data-lock-id="<?= $row["id"] ?>" title="Toggle lock - Locked profiles are protected from save rollback when loading saves"><?php echo coerceBoolean($row["lock_profile"] ?? false) ? "&#x1F512;" : "&#x1F513;"; ?></a>
                                <a class="btn btn-trash<?= coerceBoolean($row['lock_profile'] ?? false) ? ' disabled' : '' ?>" data-delete-id="<?= intval($row['id']) ?>" href="<?= coerceBoolean($row['lock_profile'] ?? false) ? '#' : ('npc_master.php?delete='.$row['id']) ?>" title="<?= coerceBoolean($row['lock_profile'] ?? false) ? 'Locked - cannot delete' : 'Delete' ?>">&#x274C;</a>
            </div>
        </div>
        <div class="npc-divider"></div>
        <div class="npc-row">
            <div class="npc-portrait-col">
                <?php if ($portraitUrl !== ''): ?>
                <img class="npc-portrait-img" src="<?= htmlspecialchars($portraitUrl) ?>" alt="<?= htmlspecialchars($npcNameCard) ?> portrait" loading="lazy">
                <?php else: ?>
                <div class="npc-portrait-fallback"><?= htmlspecialchars($portraitInitial) ?></div>
                <?php endif; ?>
            </div>
            <div class="npc-fields">
                <div class="npc-line"><span class="npc-muted">Gender:</span> <span class="npc-gender"><?= htmlspecialchars($row["gender"] ?? "") ?></span></div>
                <div class="npc-line"><span class="npc-muted">Race:</span> <span class="npc-race"><?= htmlspecialchars($row["race"] ?? "") ?></span></div>
                <div class="npc-line"><span class="npc-muted">Faction:</span> <span class="npc-faction-name"><?= htmlspecialchars(stobeUiFactionCardLabel($row)) ?></span></div>
                <div class="npc-line"><span class="npc-muted">Voice:</span> <span class="npc-voiceid"><?= htmlspecialchars($row["voiceid"] ?? "") ?></span></div>
                <div class="npc-line"><span class="npc-muted">Profile:</span> <span class="npc-profile"><?= htmlspecialchars($profLabel) ?></span></div>
                <div class="npc-line npc-bounty-line"<?= $bountyAmountText === '0' ? ' style="display:none"' : '' ?>><span class="npc-muted">Bounty:</span> <span class="npc-bounty"><?= htmlspecialchars($bountyAmountText === '0' ? '' : $bountyAmountText) ?></span></div>
                <?php if (count($bountyBreakdownItems) > 0 || $bountyLegacyDetails !== ''): ?>
                <div class="npc-bounty-section">
                    <div class="npc-bounty-heading">Bounty Breakdown</div>
                    <div class="npc-bounty-breakdown">
                        <?php foreach ($bountyBreakdownItems as $bd): ?>
                        <?php
                            $bdFaction = trim(strval($bd['faction'] ?? 'Unknown faction'));
                            $bdAmountText = trim(strval($bd['amount_text'] ?? ''));
                            $bdReasonsText = trim(strval($bd['reasons_text'] ?? ''));
                        ?>
                        <div class="npc-bounty-item">
                            <div class="npc-bounty-item-top">
                                <span class="npc-bounty-faction"><?= htmlspecialchars($bdFaction) ?></span>
                                <?php if ($bdAmountText !== ''): ?><span class="npc-bounty-amount"><?= htmlspecialchars($bdAmountText) ?></span><?php endif; ?>
                            </div>
                            <?php if ($bdReasonsText !== ''): ?><div class="npc-bounty-crimes">Wanted for: <?= htmlspecialchars($bdReasonsText) ?></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($bountyBreakdownExtra > 0): ?>
                        <div class="npc-bounty-more">+<?= htmlspecialchars(strval($bountyBreakdownExtra)) ?> more faction(s)</div>
                        <?php endif; ?>
                        <?php if (count($bountyBreakdownItems) === 0 && $bountyLegacyDetails !== ''): ?>
                        <div class="npc-bounty-legacy"><?= htmlspecialchars($bountyLegacyDetails) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
                <div class="npc-right"></div>
            <div class="npc-right-warn">
                    <?php 
                    if ($row["gamets_last_updated"] != $LAST_INFOSAVE_EVENT) {
                        echo "<span title='This NPC is out of sync, this means current NPC sheet has been modified after last save. If you edit this NPC, changes will be lost if you reload a previous savegame. '>&#x26A0;&#xFE0F;</span>";
                    }
                    ?>
            </div>
        </div>
        
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div id="npc_modal" class="modal-backdrop">
  <div class="modal-container">
    <div class="modal-header">
      <h2 class="modal-title">Edit NPC</h2>
      <div class="modal-actions">
        <button id="npc_modal_save_header" class="btn-save">Save</button>
        <button id="npc_modal_export" class="btn-cancel" title="Export NPC biography to JSON file">Export Bio</button>
        <button id="npc_modal_import_to" class="btn-cancel" title="Import biography from another NPC's export file">Import Bio</button>
        <button id="npc_modal_reset" class="btn-cancel" title="Reimport bio template fields">Reset NPC</button>
        <button id="npc_modal_history" class="btn-cancel">Profile Versions</button>
        <button id="npc_modal_regen" class="btn-cancel" title="Will use AI to regenerate this profile. Intended for custom NPCs without biography descriptions.">AI Generate Profile</button>
        <button id="npc_modal_close" class="btn-cancel">Close</button>
      </div>
    </div>
    <div class="modal-body">
      <div id="npc_modal_tabs" style="display:flex; gap:8px; padding:8px; border-bottom:1px solid #4a4a4a; background:#2a2a2a; position:sticky; top:0; z-index:2;">
        <button type="button" class="pf-tab active" data-pane="pane_manual">Manual</button>
        <button type="button" class="pf-tab" data-pane="pane_bio">NPC Biographies</button>
      </div>
      <div id="pane_manual" class="pf-pane active" style="padding:0;">
        <iframe id="npc_modal_iframe" src="about:blank" style="width:100%; height:70vh; border:0; background:transparent;"></iframe>
      </div>
      <div id="pane_bio" class="pf-pane" style="display:none; padding:10px;">
        <div style="display:flex; gap:12px; align-items:flex-start;">
          <div style="flex: 0 0 340px; max-width:340px; border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#2a2a2a;">
            <div style="display:flex; flex-direction:column; gap:6px; align-items:stretch; margin-bottom:8px;">
              <select id="bio_letter" style="padding:6px 8px; border:1px solid #4a4a4a; border-radius:6px; background:#2a2a2a; color:#e9efff;">
                <option value="">All</option>
                <option>A</option><option>B</option><option>C</option><option>D</option><option>E</option><option>F</option><option>G</option><option>H</option><option>I</option><option>J</option><option>K</option><option>L</option><option>M</option><option>N</option><option>O</option><option>P</option><option>Q</option><option>R</option><option>S</option><option>T</option><option>U</option><option>V</option><option>W</option><option>X</option><option>Y</option><option>Z</option>
              </select>
              <input id="bio_search_input" type="text" placeholder="Search bio database..." style="padding:6px 8px; border:1px solid #4a4a4a; border-radius:6px; background:#2a2a2a; color:#e9efff;">
            </div>
            <div id="bio_list" style="height:58vh; overflow:auto; display:flex; flex-direction:column; gap:6px;"></div>
            <div id="bio_pager" style="display:flex; gap:6px; align-items:center; justify-content:center; margin-top:6px;"></div>
          </div>
          <div style="flex: 1 1 auto; min-width:0; border:1px solid #4a4a4a; border-radius:8px; padding:8px; background:#2a2a2a;">
            <div style="margin-bottom:8px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
              <label class="label-with-toggle"><input id="bio_inc_ext" type="checkbox" checked> Extended Profile</label>
              <label class="label-with-toggle"><input id="bio_inc_world_knowledge" type="checkbox" checked> World Knowledge Tags</label>
              <label class="label-with-toggle"><input id="bio_inc_vm" type="checkbox" checked> Voice & Meta</label>
              <select id="bio_profile_id" title="Assign Profile" style="margin-left:auto; padding:6px 8px; border:1px solid #4a4a4a; border-radius:6px; background:#2a2a2a; color:#e9efff;">
                <option value="">Select Profile</option>
                <?php foreach (($profileRows ?? []) as $pr): $pid=(string)($pr['id']??''); $lbl=$pr['label']??('Profile #'.$pid); $sel = ($firstProfileId === $pid) ? ' selected' : ''; ?>
                <option value="<?= htmlspecialchars($pid) ?>"<?= $sel ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <button id="bio_use_template" type="button" class="btn-base btn-primary">Use Template</button>
            </div>
            <div id="bio_detail" style="height:58vh; overflow:auto;">
              <div style="color:#9fb1c9">Select a template on the left</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- NPC profile versions viewer overlay -->
<div id="history_viewer" class="modal-backdrop" style="z-index:10002;">
  <div class="modal-container" style="max-width:1100px; width:95%;">
    <div class="modal-header">
      <h2 class="modal-title">Profile Versions</h2>
      <div class="modal-actions">
        <button id="history_close" class="btn-cancel">Close</button>
      </div>
    </div>
    <div class="modal-body" style="height:75vh; display:flex; gap:10px;">
      <div id="history_list" style="flex: 0 0 320px; max-width:320px; border-right:1px solid #4a4a4a; overflow:auto; padding:8px;">
      </div>
      <div id="history_detail" style="flex: 1 1 auto; min-width:0; overflow:auto; padding:8px;">
        <div style="color:#9fb1c9">Select a snapshot to view details</div>
      </div>
    </div>
  </div>
</div>


<!-- Build Relationships Modal -->
<div id="rel_build_modal" class="modal-backdrop" style="z-index:10003; display:none;">
  <div class="modal-container rel-build-modal-container" style="max-width:500px;">
    <div class="modal-header" style="border-bottom:1px solid #e6b76c; text-align:center; justify-content:center;">
      <h2 class="modal-title" style="color:#e6b76c; margin:0; width:100%; text-align:center;">Build Relationships</h2>
    </div>
    <div class="modal-body" style="padding:24px; text-align:center;">
      <div id="rel_build_content">
        <!-- Info Box -->
        <div style="background:#2a2a3a; border:1px solid #5a5a6a; border-radius:8px; padding:12px; margin-bottom:16px; text-align:left;">
          <div style="color:#9fb1c9; font-size:0.85em; line-height:1.4;">
            Building runs in the background while you play, using each NPC's recent event history as baseline evidence. You can adjust any NPC individually by clicking their profile and editing <strong>Relationship Affinities</strong>.
          </div>
        </div>

        <!-- Model Info -->
        <div style="background:#1a1a2a; border:1px solid #4a4a4a; border-radius:8px; padding:16px; margin-bottom:20px;">
          <div style="color:#9fb1c9; font-size:0.9em; margin-bottom:4px;">Relationship Model</div>
          <div id="rel_build_model" style="color:#e6b76c; font-size:1.1em; font-weight:bold;">Loading...</div>
        </div>

        <!-- NPC Counts -->
        <div style="display:flex; gap:16px; justify-content:center; margin-bottom:20px;">
          <div style="background:#1e3f1e; border:1px solid #2d5a2d; border-radius:8px; padding:16px; min-width:120px;">
            <div style="color:#4ade80; font-size:2em; font-weight:bold;" id="rel_count_built">--</div>
            <div style="color:#9fb1c9; font-size:0.85em;">Already Built</div>
          </div>
          <div style="background:#3f2f1e; border:1px solid #5a4a2d; border-radius:8px; padding:16px; min-width:120px;">
            <div style="color:#e6b76c; font-size:2em; font-weight:bold;" id="rel_count_pending">--</div>
            <div style="color:#9fb1c9; font-size:0.85em;">Need Building</div>
          </div>
        </div>

        <!-- Options -->
        <div style="text-align:left; background:#2a2a3a; border-radius:8px; padding:16px; margin-bottom:20px;">
          <label style="display:flex; align-items:flex-start; gap:10px; color:#cfd9ea; margin-bottom:12px; cursor:pointer;">
            <input type="checkbox" id="rel_build_force" style="width:16px; height:16px; min-width:16px; min-height:16px; accent-color:#e6b76c; margin-top:2px;">
            <span>Include NPCs that were already built</span>
          </label>
          <label style="display:flex; align-items:flex-start; gap:10px; color:#cfd9ea; cursor:pointer;">
            <input type="checkbox" id="rel_build_infer" checked style="width:16px; height:16px; min-width:16px; min-height:16px; accent-color:#e6b76c; margin-top:2px;">
            <div>
              <span>Build advanced relationship connections</span>
              <div style="font-size:0.75em; color:#7a8a9a; margin-top:4px; line-height:1.4;">
                Creates indirect opinions based on social networks.<br>
                <em>Example: If Eris loves Vivienne (+80) and Vivienne hates a bandit (-70), Eris becomes wary of that bandit too.</em>
              </div>
            </div>
          </label>
        </div>

        <!-- Buttons -->
        <div style="display:flex; gap:12px; justify-content:center;">
          <button id="rel_build_start" style="background:#1e3f1e; color:#fff; border:none; padding:12px 32px; border-radius:8px; font-size:1.1em; font-weight:bold; cursor:pointer; transition:background 0.2s;">
            Start Building
          </button>
          <button id="rel_build_close" style="background:#7a1e1e; color:#fff; border:none; padding:12px 32px; border-radius:8px; font-size:1.1em; font-weight:bold; cursor:pointer; transition:background 0.2s;">
            Cancel
          </button>
        </div>
      </div>

      <!-- Progress View -->
      <div id="rel_build_progress" style="display:none;">
        <div style="margin-bottom:20px;">
          <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <span id="rel_build_status" style="color:#e6b76c; font-weight:bold;">Processing...</span>
            <span id="rel_build_count" style="color:#9fb1c9;">0 / 0</span>
          </div>
          <div style="background:#1a1a2a; border-radius:8px; height:28px; overflow:hidden; border:1px solid #4a4a4a;">
            <div id="rel_build_bar" style="background:linear-gradient(90deg, #e6b76c, #f59e0b); height:100%; width:0%; transition:width 0.3s;"></div>
          </div>
        </div>
        <div id="rel_build_log" style="background:#1a1a2a; border:1px solid #4a4a4a; border-radius:8px; padding:12px; height:200px; overflow-y:auto; font-family:monospace; font-size:12px; color:#9fb1c9; text-align:left;">
        </div>
        <div style="margin-top:16px;">
          <button id="rel_build_done" style="display:none; background:#3a3a4a; color:#e6b76c; border:1px solid #e6b76c; padding:12px 32px; border-radius:8px; font-size:1.1em; font-weight:bold; cursor:pointer;">
            Done
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const PROFILES_BY_ID = <?= json_encode($profilesById ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const PROFILE_OPTIONS = <?= json_encode($profileOptions ?? [], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const PLAYER_FACTION_NAME = <?= json_encode(strtolower($playerFactionName), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const PLAYER_FACTION_ID = <?= json_encode(strtolower($playerFactionId), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const PLAYER_FACTION_MEMBERS = <?= json_encode(array_values(stobeUiGetPlayerFactionMemberSet()), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const PLAYER_FACTION_ALIAS = <?= json_encode(function_exists('stobeGetPlayerFactionCustomNameSetting') ? stobeGetPlayerFactionCustomNameSetting() : '', JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>;
  const PLAYER_FACTION_MEMBER_SET = (() => {
    const set = Object.create(null);
    (PLAYER_FACTION_MEMBERS || []).forEach((name) => {
      const key = String(name || '').trim().toLowerCase();
      if (key) {
        set[key] = true;
      }
    });
    return set;
  })();
  const modal = document.getElementById('npc_modal');
  const iframe = document.getElementById('npc_modal_iframe');
  function stobeParseFactionIdentity(rawFaction){
    const raw = String(rawFaction || '').trim();
    if (!raw) {
      return { name: '', id: '' };
    }

    let name = raw;
    let id = '';
    let match = raw.match(/^(.*?)\s*\[\[([^\]]+)\]\]\s*$/u);
    if (!match) {
      match = raw.match(/^(.*?)\s*\[([^\]]+)\]\s*$/u);
    }
    if (match) {
      const parsedName = String(match[1] || '').trim();
      const parsedId = String(match[2] || '').trim();
      if (parsedId) {
        id = parsedId;
        name = parsedName || raw;
      }
    }

    name = String(name)
      .replace(/\s*\[\[[^\]]+\]\]\s*$/u, '')
      .replace(/\s*\[[^\]]+\]\s*$/u, '')
      .trim();

    const nameLower = name.toLowerCase();
    if (nameLower === 'unknown' || nameLower === 'neutral' || nameLower === 'none' || nameLower === 'n/a') {
      name = '';
    }

    return { name, id };
  }
  function stobePayloadMetadataObject(payload){
    const data = (payload && typeof payload === 'object') ? payload : {};
    const rawMeta = data.metadata;
    if (rawMeta && typeof rawMeta === 'object') {
      return rawMeta;
    }
    if (typeof rawMeta === 'string' && rawMeta.trim() !== '') {
      try {
        const parsed = JSON.parse(rawMeta);
        if (parsed && typeof parsed === 'object') {
          return parsed;
        }
      } catch (_e) {}
    }
    return {};
  }
  function stobeExtractFactionIdentityFromPayload(payload){
    const data = (payload && typeof payload === 'object') ? payload : {};
    const directIdentity = stobeParseFactionIdentity(data.faction || '');
    const meta = stobePayloadMetadataObject(data);

    let factionName = String(directIdentity.name || '').trim();
    let factionId = String(directIdentity.id || '').trim();

    if (!factionName) {
      const metaFaction = String(meta.faction || '').trim();
      if (metaFaction) {
        factionName = stobeParseFactionIdentity(metaFaction).name;
      }
    }
    if (!factionId) {
      factionId = String(meta.faction_id || meta.factionID || '').trim();
    }

    return { name: factionName, id: factionId };
  }
  // Mirrors stobeUiFactionCardLabel()/stobeResolvePlayerFactionPromptDisplayName() so an
  // inline card update shows the same clean faction name as a server-rendered card.
  function stobeFactionIdentityIsPlayerFaction(identity){
    const factionId = String((identity && identity.id) || '').trim().toLowerCase();
    const factionName = String((identity && identity.name) || '').trim().toLowerCase();
    const playerFactionId = String(PLAYER_FACTION_ID || '').trim();
    const playerFactionName = String(PLAYER_FACTION_NAME || '').trim();
    if (playerFactionId && factionId) {
      return playerFactionId === factionId;
    }
    if (playerFactionName && factionName) {
      return playerFactionName === factionName;
    }
    return false;
  }
  function stobeFactionCardLabel(payload){
    const identity = stobeExtractFactionIdentityFromPayload(payload);
    const factionName = String(identity.name || '').trim();
    if (!factionName) {
      return 'Unknown';
    }
    const alias = String(PLAYER_FACTION_ALIAS || '').trim();
    if (!alias) {
      return factionName;
    }
    if (stobeFactionIdentityIsPlayerFaction(identity)) {
      return alias;
    }
    if (factionName.toLowerCase() !== 'nameless') {
      return factionName;
    }
    const playerFactionName = String(PLAYER_FACTION_NAME || '').trim();
    if (!playerFactionName || playerFactionName === 'nameless') {
      return alias;
    }
    return factionName;
  }
  function stobePayloadIsPlayerFaction(payload){
    const playerFactionName = String(PLAYER_FACTION_NAME || '').trim();
    const playerFactionId = String(PLAYER_FACTION_ID || '').trim();
    const npcFaction = stobeExtractFactionIdentityFromPayload(payload);
    const npcFactionName = String(npcFaction.name || '').trim().toLowerCase();
    const npcFactionId = String(npcFaction.id || '').trim().toLowerCase();

    if (playerFactionId && npcFactionId) {
      return playerFactionId === npcFactionId;
    }
    if (playerFactionName && npcFactionName) {
      return playerFactionName === npcFactionName;
    }
    const npcName = String((payload && (payload.npc_name || payload.name)) || '').trim().toLowerCase();
    if (npcName && PLAYER_FACTION_MEMBER_SET[npcName]) {
      return true;
    }
    return false;
  }
  function stobeApplyPlayerFactionCardState(card, payload){
    if (!card) {
      return;
    }
    const isPlayerFaction = stobePayloadIsPlayerFaction(payload);
    card.classList.toggle('npc-card-player-faction', !!isPlayerFaction);
    card.setAttribute('data-player-faction', isPlayerFaction ? '1' : '0');
    const actions = card.querySelector('.npc-title-actions');
    if (!actions) {
      return;
    }
    let badge = actions.querySelector('.npc-player-faction-badge');
    if (isPlayerFaction) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'npc-player-faction-badge';
        badge.title = 'Aligned with the player faction';
        badge.textContent = 'Player Faction';
        actions.prepend(badge);
      }
    } else {
      if (badge) {
        badge.remove();
      }
    }
  }
  function stobeExtractCurrentActionState(payload){
    const data = (payload && typeof payload === 'object') ? payload : {};
    if (Object.prototype.hasOwnProperty.call(data, 'current_action')) {
      return String(data.current_action || '').trim().toLowerCase();
    }
    const meta = stobePayloadMetadataObject(data);
    if (Object.prototype.hasOwnProperty.call(meta, 'current_action')) {
      return String(meta.current_action || '').trim().toLowerCase();
    }
    return null;
  }
  function stobeApplyCardActionState(card, payload){
    if (!card) {
      return;
    }
    const actionState = stobeExtractCurrentActionState(payload);
    if (actionState === null) {
      return;
    }
    const isDead = actionState === 'dead';
    card.classList.toggle('npc-card-dead', isDead);
    card.setAttribute('data-current-action', actionState);
    const actions = card.querySelector('.npc-title-actions');
    if (!actions) {
      return;
    }
    let badge = actions.querySelector('.npc-dead-badge');
    if (isDead) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'npc-dead-badge';
        badge.title = 'Current action: dead';
        badge.textContent = 'Dead';
        actions.prepend(badge);
      }
    } else if (badge) {
      badge.remove();
    }
  }
  function openModal(url){
    iframe.src = url;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    try {
      // Track current editing id for modal updates
      (function(){
        try { const m = url.match(/[?&]edit=([^&]+)/); window.CURRENT_NPC_ID = m ? String(decodeURIComponent(m[1])) : ''; } catch(_e){ window.CURRENT_NPC_ID=''; }
      })();
      const tabs = document.getElementById('npc_modal_tabs');
      const bioPane = document.getElementById('pane_bio');
      const manualPane = document.getElementById('pane_manual');
      const exportBtn = document.getElementById('npc_modal_export');
      const importBioBtn = document.getElementById('npc_modal_import_to');
      const isEdit = /[?&]edit=/.test(url);
      if (isEdit){
        if (tabs) tabs.style.display = 'none';
        if (bioPane) { bioPane.style.display = 'none'; bioPane.classList.remove('active'); }
        if (manualPane) { manualPane.style.display = 'block'; manualPane.classList.add('active'); }
        // Show export/import buttons only for existing NPCs
        if (exportBtn) exportBtn.style.display = '';
        if (importBioBtn) importBioBtn.style.display = '';
      } else {
        if (tabs) tabs.style.display = 'flex';
        // Hide export/import buttons for new NPCs
        if (exportBtn) exportBtn.style.display = 'none';
        if (importBioBtn) importBioBtn.style.display = 'none';
      }
    } catch(_e){}

     
  }
  function closeModal(){ modal.style.display = 'none'; document.body.style.overflow = 'auto'; try { iframe.src='about:blank'; } catch(_){} }
  const headerSave = document.getElementById('npc_modal_save_header');
  if (headerSave){
    window.NPC_UPDATE_SAVE_STATE = function(){
      try {
        const doc = iframe && iframe.contentDocument;
        const nameEl = doc ? doc.getElementById('npc_name') : null;
        const val = nameEl ? String(nameEl.value||'').trim() : '';
        const disable = (val === '');
        headerSave.disabled = disable;
        if (disable) headerSave.title = 'Enter NPC Name to save'; else headerSave.removeAttribute('title');
      } catch(_e){}
    };
    // Watch for iframe content load and bind input listener
    try {
      iframe.addEventListener('load', function(){
        try {
          const doc = iframe && iframe.contentDocument;
          const nameEl = doc ? doc.getElementById('npc_name') : null;
          if (nameEl){
            ['input','change','keyup'].forEach(evt=> nameEl.addEventListener(evt, window.NPC_UPDATE_SAVE_STATE));
          }
        } catch(_e){}
        window.NPC_UPDATE_SAVE_STATE();
      });
    } catch(_e){}
    headerSave.addEventListener('click', function(){
      try {
        // Guard: require NPC name
        window.NPC_UPDATE_SAVE_STATE(); if (headerSave.disabled) { return; }
        const btn = iframe && iframe.contentDocument ? iframe.contentDocument.getElementById('npc_modal_save') : null;
        if (btn){ btn.click(); }
        // else: nothing (no bio import submit anymore)
      } catch(_e){}
    });
  }
  // Reset NPC button wiring (reimport non-empty template fields by current name)
  (function(){
    const resetBtn = document.getElementById('npc_modal_reset');
    if (!resetBtn) return;
    resetBtn.addEventListener('click', async function(e){
      e.preventDefault();
      try {
        const doc = iframe && iframe.contentDocument;
        const nameEl = doc ? doc.getElementById('npc_name') : null;
        const npcName = nameEl ? String(nameEl.value||'').trim() : '';
        if (!npcName){ alert('Enter NPC Name to reset from template.'); return; }
        // Confirm overwrite of fields present in template
        const ok = window.confirm('Reset NPC "'+npcName+'" from bio template?\n\nThis will overwrite only fields present in the template. Other fields will remain unchanged.');
        if (!ok) return;
        const res = await fetch('npc_master.php?bio_detail=1&name='+encodeURIComponent(npcName));
        let j={}; try { j = await res.json(); } catch(_e) { j={ok:false}; }
        if (!j || !j.ok){ alert('No bio template found for "'+npcName+'"'); return; }
        const d = j.data || {};
        function setVal(id, val){ const el = doc ? doc.getElementById(id) : null; if (el) el.value = String(val); }
        function applyIfFilled(id, val){ if (val==null) return; const s=String(val).trim(); if (!s) return; setVal(id, s); }
        applyIfFilled('npc_static_bio', d.npc_static_bio);
        applyIfFilled('personality', d.personality);
        applyIfFilled('appearance', d.appearance);
        applyIfFilled('relationships', d.relationships);
        applyIfFilled('occupation', d.occupation);
        applyIfFilled('skills', d.skills);
        applyIfFilled('speechstyle', d.speechstyle);
        applyIfFilled('goals', d.goals);
        applyIfFilled('world_knowledge_tags', d.world_knowledge_tags);
        applyIfFilled('voiceid', d.voiceid);
        applyIfFilled('gender', d.gender);
        applyIfFilled('race', d.race);
        // Try to reflect middle_term_enabled if provided in template (rare)
        try { const mtm = (d && typeof d.middle_term_enabled!=='undefined') ? Number(d.middle_term_enabled) : null; if (mtm!==null){ const cb = doc.getElementById('middle_term_enabled'); if (cb) cb.checked = (Number(mtm)===1); } } catch(_e){}
        try { if (typeof window.NPC_UPDATE_SAVE_STATE === 'function') window.NPC_UPDATE_SAVE_STATE(); } catch(_e){}
        try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='Template values applied'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1500); } } catch(_e){}
      } catch(_e){}
    });
  })();
   // Regenerate profile using AI
  
  (function(){
    const regenBtn = document.getElementById('npc_modal_regen');
    if (!regenBtn) return;
    regenBtn.addEventListener('click', async function(e){
      e.preventDefault();
      try {
        const doc = iframe && iframe.contentDocument;
        const nameEl = doc ? doc.getElementById('npc_name') : null;
        const npcName = nameEl ? String(nameEl.value||'').trim() : '';
        
        if (!npcName) { alert('Enter NPC Name to generate profile.'); return; }
        
        // Show prompt dialog for user to add custom instructions
        const promptBox = document.createElement('div');
        promptBox.style.position='fixed';
        promptBox.style.inset='0';
        promptBox.style.zIndex='10050';
        promptBox.style.display='flex';
        promptBox.style.alignItems='center';
        promptBox.style.justifyContent='center';
        promptBox.style.background='rgba(0,0,0,0.65)';
        promptBox.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:16px; max-width:600px; width:92%; color:#e9efff;">\
          <div style="font-weight:700; color:#e6b76c; margin-bottom:8px; font-size:18px;">AI Generate Profile for "' + npcName.replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) + '"</div>\
          <div style="font-size:13px; color:#cfd9ea; margin-bottom:12px;">Add any specific information or instructions for the AI to consider when generating this profile. Leave blank to use default generation. This uses the NPC profile\'s response connector.</div>\
          <label style="display:block; font-size:13px; margin:6px 0 4px; color:#cfd9ea; font-weight:600;">Custom Instructions (optional):</label>\
          <textarea id="ai_user_prompt" placeholder="Example: This NPC should be a merchant specializing in enchanted weapons, with a mysterious past..." style="width:100%; min-height:120px; padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff; resize:vertical; font-family:inherit;"></textarea>\
          <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">\
            <button id="ai_prompt_cancel" style="padding:10px 20px; color:#fff; background:rgba(85,95,109,0.9); border:1px solid rgba(156,163,175,0.3); border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.2s ease;">Cancel</button>\
            <button id="ai_prompt_ok" style="padding:10px 20px; color:#111; background:#e6b76c; border:1px solid #e6b76c; border-radius:8px; cursor:pointer; font-size:14px; font-weight:700; transition:all 0.2s ease;">Generate Profile</button>\
          </div></div>';
        document.body.appendChild(promptBox);
        
        const promptInput = promptBox.querySelector('#ai_user_prompt');
        const okBtn = promptBox.querySelector('#ai_prompt_ok');
        const cancelBtn = promptBox.querySelector('#ai_prompt_cancel');
        
        promptInput.focus();
        
        cancelBtn.addEventListener('click', function(){
          document.body.removeChild(promptBox);
        });
        
        okBtn.addEventListener('click', async function(){
          const userPrompt = String(promptInput.value||'').trim();
          document.body.removeChild(promptBox);
          
          document.getElementById("npc_modal").style.cursor="wait";
          
          const processingMessage = document.createElement('div');
          processingMessage.innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><div class="spinner" style="width:20px;height:20px;border:3px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite;"></div><span>Generating profile with AI...</span></div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';
          processingMessage.style.position = 'fixed';
          processingMessage.style.top = '50%';
          processingMessage.style.left = '50%';
          processingMessage.style.transform = 'translate(-50%, -50%)';
          processingMessage.style.backgroundColor = 'rgba(0,0,0,0.9)';
          processingMessage.style.color = '#fff';
          processingMessage.style.padding = '16px 24px';
          processingMessage.style.borderRadius = '10px';
          processingMessage.style.zIndex = '10001';
          processingMessage.style.border = '1px solid #4a4a4a';
          processingMessage.id="processing_wheel";
          document.body.appendChild(processingMessage);

          const params = new URLSearchParams({ name: npcName });
          if (userPrompt) params.append('user_prompt', userPrompt);
          
          let j = {};
          let fetchError = null;
          try {
            const endpoint = window.location.pathname + '?action_ai_regen_profile=1&' + params.toString();
            const res = await fetch(endpoint);
            if (!res.ok) {
              fetchError = 'Server returned status ' + res.status;
            } else {
              try { j = await res.json(); } catch(_e) { j = {done:false, error:'Invalid JSON response from server'}; }
            }
          } catch(e) {
            fetchError = 'Network error: ' + String(e.message || e);
          }
          
          // Remove processing message
          const procEl = document.getElementById('processing_wheel');
          if (procEl) procEl.remove();
          document.getElementById("npc_modal").style.cursor = "";
          
          if (fetchError) {
            showAIGenerateResult(false, fetchError, npcName);
            return;
          }
          
          if (j && j.done) {
            showAIGenerateResult(true, 'Profile successfully generated with ' + (j.fields_updated || 'multiple') + ' fields updated.', npcName);
          } else {
            const errMsg = (j && j.error) ? j.error : 'Unknown error occurred. Check the server logs for details.';
            showAIGenerateResult(false, errMsg, npcName);
          }
        });
        
        function showAIGenerateResult(success, message, npcName) {
          const resultBox = document.createElement('div');
          resultBox.style.position = 'fixed';
          resultBox.style.inset = '0';
          resultBox.style.zIndex = '10050';
          resultBox.style.display = 'flex';
          resultBox.style.alignItems = 'center';
          resultBox.style.justifyContent = 'center';
          resultBox.style.background = 'rgba(0,0,0,0.65)';
          
          const iconColor = success ? '#4ade80' : '#f87171';
          const iconSymbol = success ? 'OK' : 'ERR';
          const title = success ? 'Profile Generated Successfully' : 'Profile Generation Failed';
          
          resultBox.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:20px; max-width:500px; width:92%; color:#e9efff;">\
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">\
              <div style="width:32px; height:32px; border-radius:50%; background:' + iconColor + '; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:bold; color:#111;">' + iconSymbol + '</div>\
              <div style="font-weight:700; color:' + iconColor + '; font-size:18px;">' + title + '</div>\
            </div>\
            <div style="font-size:14px; color:#cfd9ea; margin-bottom:16px; line-height:1.5;">' + message.replace(/[<>]/g, c=>({'<':'&lt;','>':'&gt;'}[c])) + '</div>\
            <div style="display:flex; gap:8px; justify-content:flex-end;">\
              ' + (success ? '' : '<button id="ai_result_retry" style="padding:10px 20px; color:#fff; background:rgba(85,95,109,0.9); border:1px solid rgba(156,163,175,0.3); border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.2s ease;">Try Again</button>') + '\
              <button id="ai_result_ok" style="padding:10px 20px; color:#111; background:' + (success ? '#e6b76c' : 'rgba(85,95,109,0.9)') + '; border:1px solid ' + (success ? '#e6b76c' : 'rgba(156,163,175,0.3)') + '; border-radius:8px; cursor:pointer; font-size:14px; font-weight:700; ' + (success ? 'color:#111;' : 'color:#fff;') + ' transition:all 0.2s ease;">' + (success ? 'Reload to View' : 'Close') + '</button>\
            </div></div>';
          document.body.appendChild(resultBox);
          
          const okBtn = resultBox.querySelector('#ai_result_ok');
          const retryBtn = resultBox.querySelector('#ai_result_retry');
          
          okBtn.addEventListener('click', function(){
            document.body.removeChild(resultBox);
            if (success) {
              document.location.reload();
            }
          });
          
          if (retryBtn) {
            retryBtn.addEventListener('click', function(){
              document.body.removeChild(resultBox);
              // Re-trigger the regenerate button click
              const regenBtn = document.getElementById('npc_modal_regen');
              if (regenBtn) regenBtn.click();
            });
          }
          
          // Close on background click
          resultBox.addEventListener('click', function(e){
            if (e.target === resultBox) {
              document.body.removeChild(resultBox);
            }
          });
        }

      } catch(_e){console.log(_e)}
    });
  })();

  
  // Profile Versions button wiring
  (function(){
    const btn = document.getElementById('npc_modal_history');
    const overlay = document.getElementById('history_viewer');
    const listBox = document.getElementById('history_list');
    const detailBox = document.getElementById('history_detail');
    const closeBtn = document.getElementById('history_close');

    const LABELS = {

      npc_name: 'NPC Name',
      profile_id: 'Profile',
      gender: 'Gender',
      race: 'Race',
      voiceid: 'Voice ID',
      faction: 'Faction',
      npc_static_bio: 'Backstory',
      appearance: 'Appearance',
      personality: 'Personality',
      relationships: 'Relationships',
      occupation: 'Occupation',
      skills: 'Skills',
      speechstyle: 'Speech Style',
      goals: 'Goals',
      world_knowledge_tags: 'World Knowledge Tags',
      emote_moods: 'Emote Moods',
      prompt_head: 'Prompt Head',
      dynamic_profile: 'Dynamic Profile',
      npc_favorite: 'Favorite',
      lock_profile: 'Lock Profile',
      tags: 'Tags'
    };
    function close(){ if (overlay) overlay.style.display='none'; }
    if (closeBtn) closeBtn.addEventListener('click', function(e){ e.preventDefault(); close(); });
    if (overlay) overlay.addEventListener('click', function(e){ if (e.target===overlay) close(); });
    function renderDetail(entry, prev){
      if (!entry){ detailBox.innerHTML = '<div style="color:#9fb1c9">No data</div>'; return; }
      const f = entry.fields||{}; const prevF = (prev && prev.fields) ? prev.fields : {};
      const order = ['npc_name','profile_id','gender','race','voiceid','faction','npc_static_bio','appearance','personality','relationships','occupation','skills','speechstyle','goals','world_knowledge_tags','emote_moods','prompt_head','dynamic_profile','npc_favorite','lock_profile','tags'];
      let html = '';
      html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">';
      html += '<div style="color:#cfd9ea;">'+(entry.when_tamrielic || (entry.gamets ? ('GameTS '+String(entry.gamets)) : 'Unknown in-game time'))+'</div>';
      html += '<button class="btn-restore-history" data-history-id="'+String(entry.history_id||'')+'" style="background:#e6b76c; color:#111; border:1px solid #e6b76c; border-radius:6px; padding:6px 12px; cursor:pointer; font-weight:700;">Restore this version</button>';
      html += '</div>';
      html += '<div style="display:grid; grid-template-columns: 220px 1fr; gap:6px;">';
      order.forEach(k=>{
        let v = f[k]; const has = (v!==null && v!==undefined && String(v).trim()!=='');
        if (!has) return;
        const changed = (prevF && String(prevF[k]??'') !== String(v));
        const label = LABELS[k] || k.replace(/_/g,' ');
        if (k==='profile_id') { v = (PROFILES_BY_ID && PROFILES_BY_ID[String(v||'')]) ? PROFILES_BY_ID[String(v)] : v; }
        html += '<div style="color:#e6b76c; font-weight:700;">'+label+'</div>';
        html += '<div style="border:1px solid #4a4a4a; border-radius:6px; padding:6px;'+(changed?' background:#333333;':'')+'">'+String(v).replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))+'</div>';
      });
      html += '</div>';
      detailBox.innerHTML = html;
      // Wire up restore button
      const restoreBtn = detailBox.querySelector('.btn-restore-history');
      if (restoreBtn) {
        restoreBtn.addEventListener('click', async function(){
          const histId = this.getAttribute('data-history-id');
          if (!histId) return;
          const ok = confirm('Restore this historical version?\n\nThis will replace the current NPC profile with the selected snapshot. This action creates a new backup before restoring.');
          if (!ok) return;
          try {
            this.disabled = true;
            this.textContent = 'Restoring...';
            const fd = new FormData();
            fd.append('restore_from_history', '1');
            fd.append('history_id', histId);
            const res = await fetch('npc_master.php', { method:'POST', body: fd });
            let j = {}; try { j = await res.json(); } catch(_e) { j = {ok:false}; }
            if (j && j.ok) {
              close();
              try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='NPC restored from history'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 2000); } } catch(_e){}
              // Refresh the page to show updated NPC
              window.location.reload();
            } else {
              alert('Restore failed: ' + (j && j.error ? j.error : 'Unknown error'));
              this.disabled = false;
              this.textContent = 'Restore this version';
            }
          } catch(_e) {
            alert('Restore failed: ' + String(_e));
            this.disabled = false;
            this.textContent = 'Restore this version';
          }
        });
      }

     
    

    }
    function openHistory(){
      try {
        const id = String(window.CURRENT_NPC_ID||'').trim();
        if (!id){ return; }
        if (overlay) { overlay.style.display='flex'; }
        if (listBox) { listBox.innerHTML = '<div style="color:#9fb1c9">LoadingN/A</div>'; }
        if (detailBox) { detailBox.innerHTML = '<div style="color:#9fb1c9">Fetching historyN/A</div>'; }
        fetch('npc_master.php?history=1&id='+encodeURIComponent(id))
          .then(r=>r.json()).then(j=>{
            if (!j || !j.ok){ listBox.innerHTML = '<div style="color:#ff6b6b">Failed to load history</div>'; detailBox.innerHTML=''; return; }
            const entries = j.entries||[];
            if (entries.length===0){ listBox.innerHTML = '<div style="color:#9fb1c9">No history yet</div>'; detailBox.innerHTML=''; return; }
            listBox.innerHTML = '';
            entries.forEach((e, idx)=>{
              const div = document.createElement('div');
              div.style.border='1px solid #4a4a4a'; div.style.borderRadius='8px'; div.style.padding='8px'; div.style.cursor='pointer'; div.style.marginBottom='6px';
              const label = e.when_tamrielic || (e.gamets ? ('GameTS '+String(e.gamets)) : ('Snapshot #'+String(e.history_id||idx+1)));
              div.innerHTML = '<div style="font-weight:700; color:#e9efff;">'+label+'</div>';
              div.addEventListener('click', function(){
                listBox.querySelectorAll('.active').forEach(n=>{ n.classList.remove('active'); n.style.background=''; });
                this.classList.add('active'); this.style.background='#333333';
                renderDetail(e, idx>0?entries[idx-1]:null);
              });
              listBox.appendChild(div);
            });
          })
          .catch(()=>{ listBox.innerHTML = '<div style="color:#ff6b6b">Failed to load history</div>'; detailBox.innerHTML=''; });
      } catch(_){}
    }
    if (btn){ btn.addEventListener('click', function(e){ e.preventDefault(); openHistory(); }); }
  })();
  document.addEventListener('click', function(e){ if (e.target && e.target.id==='npc_modal_close') closeModal(); });
  document.addEventListener('keydown', function(e){ if (e.key==='Escape') closeModal(); });
  // Tabs in modal
  (function(){
    const tabs = document.querySelectorAll('#npc_modal_tabs .pf-tab');
    function activate(id){
      tabs.forEach(t=>t.classList.toggle('active', t.getAttribute('data-pane')===id));
      document.querySelectorAll('.pf-pane').forEach(p=>{ p.style.display = (p.id===id) ? 'block' : 'none'; p.classList.toggle('active', p.id===id); });
    }
    tabs.forEach(tb=> tb.addEventListener('click', ()=> activate(tb.getAttribute('data-pane'))));
    activate('pane_manual');
  })();
  // Bio DB wiring
  (function(){
    const list = document.getElementById('bio_list');
    const pager = document.getElementById('bio_pager');
    const inp = document.getElementById('bio_search_input');
    const letter = document.getElementById('bio_letter');
    const detail = document.getElementById('bio_detail');
    const useBtn = document.getElementById('bio_use_template');
    const createBtn = document.getElementById('bio_use_create');
    const incExt = document.getElementById('bio_inc_ext');
    const incOgh = document.getElementById('bio_inc_world_knowledge');
    const incVM  = document.getElementById('bio_inc_vm');
    const selProfile = document.getElementById('bio_profile_id');
    let currentName = '';
    let page = 1; let total = 0; let pageSize = 20;
    async function fetchList(){
      const params = new URLSearchParams({ bio_search:'1', search:(inp.value||''), letter:(letter.value||''), page:String(page), pageSize:String(pageSize) });
      const res = await fetch('npc_master.php?'+params.toString()); let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (!j.ok) { list.innerHTML = '<div style="color:#ff6b6b">Failed to load</div>'; return; }
      total = Number(j.total||0); page = Number(j.page||1); pageSize = Number(j.pageSize||20);
      list.innerHTML = '';
      (j.items||[]).forEach(it=>{
        const div = document.createElement('div');
        div.style.border = '1px solid #4a4a4a'; div.style.borderRadius='8px'; div.style.padding='8px'; div.style.cursor='pointer';
        div.innerHTML = `<div style="font-weight:700; color:#e9efff">${escapeHtml(it.npc_name)}</div>
          <div style="color:#9fb1c9; font-size:12px; margin:4px 0">${it.core_preview||''}</div>
          <div style="display:flex; gap:8px; flex-wrap:wrap; font-size:12px; color:#cfd9ea;">
            ${it.voiceid?('<span>Voice: '+it.voiceid+'</span>'):''}
            ${it.gender?('<span>Gender: '+it.gender+'</span>'):''}
            ${it.race?('<span>Race: '+it.race+'</span>'):''}
            <span>Extended: ${String(it.extended_filled||0)}</span>
          </div>`;
        div.addEventListener('click', ()=> loadDetail(it.npc_name));
        list.appendChild(div);
      });
      // pager
      const pages = Math.max(1, Math.ceil(total / Math.max(1,pageSize)));
      pager.innerHTML='';
      const mk = (lab, p, dis)=>{ const b=document.createElement('button'); b.textContent=lab; b.disabled=!!dis; b.addEventListener('click', ()=>{ page=p; fetchList(); }); return b; };
      pager.appendChild(mk('First', 1, page<=1));
      pager.appendChild(mk('Prev', Math.max(1,page-1), page<=1));
      const start = Math.max(1, page-2), end = Math.min(pages, page+2);
      for(let i=start;i<=end;i++){ const b=mk(String(i), i, i===page); pager.appendChild(b); }
      pager.appendChild(mk('Next', Math.min(pages,page+1), page>=pages));
      pager.appendChild(mk('Last', pages, page>=pages));
    }
    async function loadDetail(name){
      currentName = name;
      const params = new URLSearchParams({ bio_detail:'1', name:name });
      const res = await fetch('npc_master.php?'+params.toString()); let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (!j.ok){ detail.innerHTML = '<div style="color:#ff6b6b">Failed to load detail</div>'; return; }
      const d = j.data||{};
      detail.innerHTML = `
        <div style="font-size:18px; font-weight:700; color:#e9efff;">${escapeHtml(d.npc_name||'')}</div>
        <div style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:10px; margin-top:8px;">
          ${kv('Backstory', d.npc_static_bio)}
          ${kv('Personality', d.personality)}
          ${kv('Appearance', d.appearance)}
          ${kv('Relationships', d.relationships)}
          ${kv('Occupation', d.occupation)}
          ${kv('Skills', d.skills)}
          ${kv('Speech Style', d.speechstyle)}
          ${kv('Goals', d.goals)}
        </div>
        <div style="margin-top:8px; color:#cfd9ea; display:flex; gap:10px; flex-wrap:wrap;">
          ${badge('VoiceID', d.voiceid)}
          ${badge('Gender', d.gender)}
          ${badge('Race', d.race)}
        </div>
        <div style="margin-top:8px; color:#cfd9ea;"><b style="color:#e6b76c">World Knowledge Tags:</b> ${escapeHtml(d.world_knowledge_tags||'N/A')}</div>
      `;
    }
    function kv(title, val){ const v=(val||'').trim(); return `<div><div style="color:#e6b76c; font-weight:700;">${title}</div><div style="white-space:pre-wrap;">${escapeHtml(v||'N/A')}</div></div>`; }
    function badge(k, v){ v=(v||'').trim(); if (!v) return ''; return `<span style="background:#3a3a3a; border:1px solid #4a4a4a; border-radius:999px; padding:3px 8px;">${k}: ${escapeHtml(v)}</span>`; }
    function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    let deb = null; function refetch(){ if (deb) clearTimeout(deb); deb=setTimeout(()=>{ page=1; fetchList(); }, 250); }
    if (inp) inp.addEventListener('input', refetch);
    if (letter) letter.addEventListener('change', refetch);
    if (useBtn) useBtn.addEventListener('click', async ()=>{
      if (!currentName) return;
      // Load detail and fill manual form in iframe
      const params = new URLSearchParams({ bio_detail:'1', name:currentName });
      const res = await fetch('npc_master.php?'+params.toString()); let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (!j.ok) return;
      const d = j.data||{};
      const doc = iframe && iframe.contentDocument; if (!doc) return;
      function setVal(id, val){ const el = doc.getElementById(id); if (el) el.value = val==null?'':String(val); }
      function setChk(id, on){ const el = doc.getElementById(id); if (el && el.type==='checkbox') el.checked = !!on; }
      setVal('npc_name', d.npc_name||'');
      if (incExt && incExt.checked) {
        setVal('npc_static_bio', d.npc_static_bio||''); setVal('personality', d.personality||''); setVal('appearance', d.appearance||''); setVal('relationships', d.relationships||''); setVal('occupation', d.occupation||''); setVal('skills', d.skills||''); setVal('speechstyle', d.speechstyle||''); setVal('goals', d.goals||'');
      }
      if (incOgh && incOgh.checked) setVal('world_knowledge_tags', d.world_knowledge_tags||'');
      if (incVM && incVM.checked) { setVal('voiceid', d.voiceid||''); setVal('gender', d.gender||''); setVal('race', d.race||''); }
      if (selProfile && selProfile.value) { const el = doc.getElementById('profile_id'); if (el) el.value = selProfile.value; }
      // Switch to manual tab
      document.querySelectorAll('#npc_modal_tabs .pf-tab').forEach(t=> t.classList.toggle('active', t.getAttribute('data-pane')==='pane_manual'));
      document.getElementById('pane_manual').style.display='block'; document.getElementById('pane_manual').classList.add('active');
      document.getElementById('pane_bio').style.display='none'; document.getElementById('pane_bio').classList.remove('active');
      // Update save button state after auto-filling name
      try { if (typeof window.NPC_UPDATE_SAVE_STATE === 'function') window.NPC_UPDATE_SAVE_STATE(); } catch(_e){}
    });
    if (createBtn) createBtn.addEventListener('click', async ()=>{
      if (!currentName) return;
      const fd = new FormData();
      fd.append('import_from_bio','1');
      fd.append('name', currentName);
      fd.append('include_extended', (incExt && incExt.checked) ? '1':'');
      fd.append('include_world_knowledge', (incOgh && incOgh.checked) ? '1':'');
      fd.append('include_voice_meta', (incVM && incVM.checked) ? '1':'');
      if (selProfile && selProfile.value) fd.append('profile_id', selProfile.value);
      const res = await fetch('npc_master.php', { method:'POST', body: fd });
      let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
      if (j && j.ok){
        window.postMessage({ type:'npc_saved', id: j.id, data: j.data }, '*');
      } else {
        alert('Import failed: '+(j && j.error ? j.error : 'Unknown'));
      }
    });
    // initial fetch
    try { fetchList(); } catch(_){}
  })();
  // Prevent browser history back/forward inside modal (mouse buttons/backspace)
  (function(){
    function blockNav(ev){ ev.preventDefault(); ev.stopPropagation(); return false; }
    window.addEventListener('popstate', blockNav, true);
    window.addEventListener('hashchange', blockNav, true);
    window.addEventListener('mousedown', function(e){ if (e.button===3 || e.button===4) { blockNav(e); } }, true);
    window.addEventListener('mouseup', function(e){ if (e.button===3 || e.button===4) { blockNav(e); } }, true);
    window.addEventListener('contextmenu', function(e){ /* noop */ }, true);
    // push a dummy state so back goes to same place
    try { history.pushState({modal:true}, document.title, location.href); } catch(_e){}
  })();
  document.querySelectorAll('.npc-card').forEach(card=>{
    card.addEventListener('click', function(ev){
      if (ev.target.closest('.npc-title-actions')) return;
      const id=this.getAttribute('data-id'); if (!id) return;
      ev.preventDefault();
      openModal('npc_master.php?edit='+encodeURIComponent(id)+'&partial=1');
    });
  });
  const createBtn = document.getElementById('npc_create_btn');
  if (createBtn){
    createBtn.addEventListener('click', function(){
      openModal('npc_master.php?partial=1');
    });
  }
  // Live search and alpha sort
  const searchInput = document.getElementById('npc_search');
  function updateCardLockVisualState(lockBtn, active){
    if (!lockBtn) return;
    lockBtn.classList.toggle('active', !!active);
    lockBtn.textContent = active ? '\u{1F512}' : '\u{1F513}';
    const card = lockBtn.closest('.npc-card');
    if (!card) return;
    const trash = card.querySelector('.btn-trash');
    if (!trash) return;
    const npcId = String(trash.getAttribute('data-delete-id') || '').trim();
    if (active) {
      trash.classList.add('disabled');
      trash.setAttribute('href', '#');
      trash.setAttribute('title', 'Locked - cannot delete');
      return;
    }
    trash.classList.remove('disabled');
    trash.setAttribute('href', npcId ? ('npc_master.php?delete=' + encodeURIComponent(npcId)) : '#');
    trash.setAttribute('title', 'Delete');
  }
  // Bulk unlock wiring
  (function(){
    function bindBulkUnlock(btn){
      if (!btn || btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function(){
        const box = document.createElement('div');
        box.style.position='fixed'; box.style.inset='0'; box.style.zIndex='10050'; box.style.display='flex'; box.style.alignItems='center'; box.style.justifyContent='center'; box.style.background='rgba(0,0,0,0.65)';
        box.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:16px; max-width:520px; width:92%; color:#e9efff;">\
          <div style="font-weight:700; color:#e6b76c; margin-bottom:8px;">Unlock All NPC Profiles</div>\
          <div style="font-size:13px; color:#cfd9ea; margin-bottom:8px;">This unlocks every NPC profile except The Narrator.</div>\
          <div style="font-size:12px; color:#ffd166; margin-bottom:12px;">If Auto Lock Profiles on Edit is enabled, a profile will lock again after it is edited and saved.</div>\
          <label style="display:block; font-size:13px; margin:6px 0; color:#cfd9ea;">Type <b style="color:#ffd166">Unlock</b> to confirm:</label>\
          <input id="bulk_unlock_confirm" type="text" style="width:100%; padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;"/>\
          <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">\
            <button id="bulk_unlock_cancel" class="btn-cancel">Cancel</button>\
            <button id="bulk_unlock_ok" class="btn-rel-build" disabled>Unlock All Profiles</button>\
          </div></div>';
        document.body.appendChild(box);
        const confirmEl = box.querySelector('#bulk_unlock_confirm');
        const okEl = box.querySelector('#bulk_unlock_ok');
        const cancelEl = box.querySelector('#bulk_unlock_cancel');
        function updateState(){ okEl.disabled = String(confirmEl.value || '').trim() !== 'Unlock'; }
        confirmEl.addEventListener('input', updateState);
        updateState();
        confirmEl.focus();
        cancelEl.addEventListener('click', function(){ document.body.removeChild(box); });
        okEl.addEventListener('click', async function(){
          okEl.disabled = true;
          try {
            const fd = new FormData();
            fd.append('bulk_unlock_npcs', '1');
            fd.append('confirm', String(confirmEl.value || ''));
            const res = await fetch('npc_master.php', { method:'POST', body:fd });
            let json = {};
            try { json = await res.json(); } catch(_e) { json = { ok:false, error:'Invalid response' }; }
            document.body.removeChild(box);
            if (json && json.ok) {
              try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='Unlocked '+String(json.unlocked||0)+' NPC profiles'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 2400); } } catch(_e){}
              refreshList(1);
            } else {
              alert('Bulk unlock failed: '+(json && json.error ? json.error : 'Unknown'));
            }
          } catch(_e) {
            okEl.disabled = false;
          }
        });
      });
    }
    window.bindNpcBulkUnlock = bindBulkUnlock;
    bindBulkUnlock(document.getElementById('npc_bulk_unlock_btn'));
  })();
  // Bulk delete wiring
  (function(){
    function bindBulk(btn){
      if (!btn) return;
      btn.addEventListener('click', function(){
        const box = document.createElement('div');
        box.style.position='fixed'; box.style.inset='0'; box.style.zIndex='10050'; box.style.display='flex'; box.style.alignItems='center'; box.style.justifyContent='center'; box.style.background='rgba(0,0,0,0.65)';
        box.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:16px; max-width:520px; width:92%; color:#e9efff;">\
          <div style="font-weight:700; color:#ff6b6b; margin-bottom:8px;">Danger: Delete ALL unlocked NPCs</div>\
          <div style="font-size:13px; color:#cfd9ea; margin-bottom:8px;">This will permanently delete every NPC that is not locked. The Narrator and any locked profiles will be preserved.</div>\
          <label style="display:block; font-size:13px; margin:6px 0; color:#cfd9ea;">Type <b style="color:#ffd166">Delete</b> to confirm:</label>\
          <input id="bulk_del_confirm" type="text" style="width:100%; padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;"/>\
          <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">\
            <button id="bulk_del_cancel" class="btn-cancel">Cancel</button>\
            <button id="bulk_del_ok" class="btn-danger" disabled>Delete</button>\
          </div></div>';
        document.body.appendChild(box);
        const inp = box.querySelector('#bulk_del_confirm');
        const ok  = box.querySelector('#bulk_del_ok');
        const cancel = box.querySelector('#bulk_del_cancel');
        function upd(){ ok.disabled = (String(inp.value||'').trim() !== 'Delete'); }
        inp.addEventListener('input', upd); upd(); inp.focus();
        cancel.addEventListener('click', function(){ document.body.removeChild(box); });
        ok.addEventListener('click', async function(){
          ok.disabled = true; try {
            const fd = new FormData(); fd.append('bulk_delete_npcs','1'); fd.append('confirm', String(inp.value||''));
            const res = await fetch('npc_master.php', { method:'POST', body: fd });
            let j={}; try{ j=await res.json(); }catch(_){ j={ok:false}; }
            document.body.removeChild(box);
            if (j && j.ok){
              try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='Deleted '+String(j.deleted||0)+' NPCs'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 2000); } } catch(_){}
              refreshList(1);
            } else {
              alert('Bulk delete failed: '+(j && j.error ? j.error : 'Unknown'));
            }
          } catch(_e){ ok.disabled=false; }
        });
      });
    }
    // expose for rebind after AJAX refresh
    window.bindNpcBulkDelete = bindBulk;
    bindBulk(document.getElementById('npc_bulk_delete_btn'));
  })();
  // Bulk profile switch wiring
  (function(){
    const profileOptions = Array.isArray(PROFILE_OPTIONS) ? PROFILE_OPTIONS : [];
    function escHtml(v){
      return String(v == null ? '' : v).replace(/[&<>"]/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c]));
    }
    function buildOptions(selectedValue){
      const selected = String(selectedValue || '');
      let html = '';
      profileOptions.forEach(function(pr){
        const id = String(pr && pr.id ? pr.id : '');
        if (!id) return;
        const label = String(pr && pr.label ? pr.label : ('Profile #' + id));
        const sel = (id === selected) ? ' selected' : '';
        html += '<option value="' + escHtml(id) + '"' + sel + '>' + escHtml(label) + '</option>';
      });
      return html;
    }
    function bindBulkSwitch(btn){
      if (!btn) return;
      btn.addEventListener('click', function(){
        if (!profileOptions.length) { alert('No profiles found.'); return; }
        const filterSel = document.getElementById('npc_profile_filter');
        const sourcePref = (filterSel && filterSel.value) ? String(filterSel.value) : String((profileOptions[0] && profileOptions[0].id) || '');
        let targetPref = '';
        for (let i = 0; i < profileOptions.length; i++) {
          const pid = String(profileOptions[i] && profileOptions[i].id ? profileOptions[i].id : '');
          if (pid !== '' && pid !== sourcePref) { targetPref = pid; break; }
        }
        if (!targetPref) targetPref = sourcePref;

        const box = document.createElement('div');
        box.style.position='fixed'; box.style.inset='0'; box.style.zIndex='10050'; box.style.display='flex'; box.style.alignItems='center'; box.style.justifyContent='center'; box.style.background='rgba(0,0,0,0.65)';
        box.innerHTML = '<div style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:10px; padding:16px; max-width:560px; width:92%; color:#e9efff;">\
          <div style="font-weight:700; color:#e6b76c; margin-bottom:8px;">Mass Switch NPC Profiles</div>\
          <div style="font-size:13px; color:#cfd9ea; margin-bottom:12px;">Move every NPC currently on one profile to another profile in one pass. The Narrator is always excluded.</div>\
          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:8px;">\
            <label style="display:flex; flex-direction:column; gap:6px; font-size:12px; color:#cfd9ea;">From profile\
              <select id="bulk_switch_source" style="padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;">' + buildOptions(sourcePref) + '</select>\
            </label>\
            <label style="display:flex; flex-direction:column; gap:6px; font-size:12px; color:#cfd9ea;">To profile\
              <select id="bulk_switch_target" style="padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;">' + buildOptions(targetPref) + '</select>\
            </label>\
          </div>\
          <label style="display:flex; align-items:center; gap:8px; margin:8px 0 12px 0; color:#cfd9ea; font-size:13px;"><input id="bulk_switch_include_locked" type="checkbox" /> Include locked NPCs</label>\
          <label style="display:block; font-size:13px; margin:6px 0; color:#cfd9ea;">Type <b style="color:#ffd166">Switch</b> to confirm:</label>\
          <input id="bulk_switch_confirm" type="text" style="width:100%; padding:8px; border-radius:6px; border:1px solid #4a4a4a; background:#2a2a2a; color:#e9efff;"/>\
          <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">\
            <button id="bulk_switch_cancel" class="btn-cancel">Cancel</button>\
            <button id="bulk_switch_ok" class="btn-rel-build" disabled>Switch Profiles</button>\
          </div></div>';
        document.body.appendChild(box);

        const sourceEl = box.querySelector('#bulk_switch_source');
        const targetEl = box.querySelector('#bulk_switch_target');
        const includeLockedEl = box.querySelector('#bulk_switch_include_locked');
        const confirmEl = box.querySelector('#bulk_switch_confirm');
        const okEl = box.querySelector('#bulk_switch_ok');
        const cancelEl = box.querySelector('#bulk_switch_cancel');

        function updateState(){
          const confirmOk = String(confirmEl.value || '').trim() === 'Switch';
          const hasSource = !!(sourceEl && sourceEl.value);
          const hasTarget = !!(targetEl && targetEl.value);
          const different = hasSource && hasTarget && String(sourceEl.value) !== String(targetEl.value);
          okEl.disabled = !(confirmOk && hasSource && hasTarget && different);
        }
        confirmEl.addEventListener('input', updateState);
        sourceEl.addEventListener('change', updateState);
        targetEl.addEventListener('change', updateState);
        updateState();
        confirmEl.focus();

        cancelEl.addEventListener('click', function(){ document.body.removeChild(box); });
        okEl.addEventListener('click', async function(){
          okEl.disabled = true;
          try {
            const fd = new FormData();
            fd.append('bulk_switch_profile', '1');
            fd.append('source_profile_id', String(sourceEl.value || ''));
            fd.append('target_profile_id', String(targetEl.value || ''));
            fd.append('include_locked', includeLockedEl && includeLockedEl.checked ? '1' : '0');
            fd.append('confirm', String(confirmEl.value || ''));
            const res = await fetch('npc_master.php', { method:'POST', body: fd });
            let j = {};
            try { j = await res.json(); } catch(_){ j = { ok:false, error:'Invalid JSON response' }; }
            document.body.removeChild(box);
            if (j && j.ok){
              let msg = 'Switched ' + String(j.updated || 0) + ' NPCs';
              if (j.source_profile_label && j.target_profile_label) {
                msg += ' (' + String(j.source_profile_label) + ' -> ' + String(j.target_profile_label) + ')';
              }
              if (!j.include_locked && Number(j.skipped_locked || 0) > 0) {
                msg += '; skipped ' + String(j.skipped_locked) + ' locked';
              }
              try {
                const toast = document.getElementById('toast');
                if (toast) {
                  toast.querySelector('.message').textContent = msg;
                  toast.classList.add('show');
                  setTimeout(() => toast.classList.remove('show'), 2400);
                }
              } catch(_){}
              refreshList(1);
            } else {
              alert('Mass switch failed: ' + (j && j.error ? j.error : 'Unknown'));
            }
          } catch(_e){
            okEl.disabled = false;
          }
        });
      });
    }
    window.bindNpcBulkSwitchProfile = bindBulkSwitch;
    bindBulkSwitch(document.getElementById('npc_bulk_switch_profile_btn'));
  })();
  function bindAutoLockProfile(control){
    if (!control || control.dataset.bound === '1') return;
    control.dataset.bound = '1';
    control.addEventListener('change', async function(){
      const requested = !!control.checked;
      control.disabled = true;
      try {
        const fd = new FormData();
        fd.append('set_auto_lock_profile', '1');
        fd.append('auto_lock_profile', requested ? '1' : '0');
        const res = await fetch('npc_master.php', { method:'POST', body:fd });
        let json = {};
        try { json = await res.json(); } catch(_e) { json = { ok:false, error:'Invalid response' }; }
        if (!json || !json.ok) {
          control.checked = !requested;
          alert('Failed to save Auto Lock Profiles on Edit: '+(json && json.error ? json.error : 'Unknown error'));
        }
      } catch(_e) {
        control.checked = !requested;
        alert('Failed to save Auto Lock Profiles on Edit.');
      } finally {
        control.disabled = false;
      }
    });
  }
  bindAutoLockProfile(document.getElementById('npc_auto_lock_profile'));
  function bindNpcToolbarPagination(root){
    const scope = root || document;
    scope.querySelectorAll('.pagination.npc-toolbar .npc-page-link[data-page]').forEach(btn=>{
      btn.addEventListener('click', function(e){
        e.preventDefault();
        const nextPage = parseInt(this.getAttribute('data-page') || '1', 10);
        refreshList(Number.isFinite(nextPage) && nextPage > 0 ? nextPage : 1);
      });
    });
    scope.querySelectorAll('.pagination.npc-toolbar a[href]').forEach(a=>{
      a.addEventListener('click', function(e){
        e.preventDefault();
        const m = this.href.match(/page=(\d+)/);
        const p = m ? parseInt(m[1], 10) : 1;
        refreshList(p);
      });
    });
  }
  function bindNpcLetterButtons(root){
    const scope = root || document;
    scope.querySelectorAll('.npc-letter-btn[data-letter]').forEach(btn=>{
      btn.addEventListener('click', function(e){
        e.preventDefault();
        const nextLetter = String(this.getAttribute('data-letter') || '').toUpperCase();
        const hidden = document.getElementById('npc_letter_filter');
        if (hidden) hidden.value = nextLetter;
        document.querySelectorAll('.npc-letter-btn[data-letter]').forEach(other=>{
          other.classList.toggle('active', other === this);
        });
        refreshList(1);
      });
    });
  }
  let listAbort = null;
  let listRequestId = 0;
  const LIST_STATE_KEYS = ['q','letter','profile_id','fav','dyn','mtm','lock','pf','alpha','embed'];
  const LIST_CHECKBOX_FILTERS = [['fav','npc_filter_fav'],['dyn','npc_filter_dyn'],['mtm','npc_filter_mtm'],['lock','npc_filter_lock'],['pf','npc_filter_pf']];
  function readServedPage(root){
    const pag = (root && root.matches && root.matches('.pagination.npc-toolbar'))
      ? root
      : (root || document).querySelector('.pagination.npc-toolbar[data-current-page]');
    const page = pag ? parseInt(pag.getAttribute('data-current-page') || '', 10) : NaN;
    return Number.isFinite(page) && page > 0 ? page : null;
  }
  let currentListPage = readServedPage(document) || 1;
  function readListControl(id, key, current){
    const control = document.getElementById(id);
    return control ? String(control.value || '') : String(current.get(key) || '');
  }
  function readListCheckbox(baseId, key, current){
    const control = document.getElementById(baseId + '_top') || document.getElementById(baseId);
    return control ? (control.checked ? '1' : '') : (current.get(key) === '1' ? '1' : '');
  }
  function buildListState(page){
    const current = new URLSearchParams(window.location.search);
    const params = new URLSearchParams();
    params.set('q', readListControl('npc_search', 'q', current));
    params.set('letter', readListControl('npc_letter_filter', 'letter', current).toUpperCase());
    params.set('profile_id', readListControl('npc_profile_filter', 'profile_id', current));
    LIST_CHECKBOX_FILTERS.forEach(function(pair){
      params.set(pair[0], readListCheckbox(pair[1], pair[0], current));
    });
    params.set('alpha', 'asc');
    if (current.get('embed') === '1') params.set('embed', '1');
    const requestedPage = parseInt(page, 10);
    params.set('page', String(Number.isFinite(requestedPage) && requestedPage > 0 ? requestedPage : 1));
    return params;
  }
  // Persist only list state so reloads reproduce the server-confirmed filter and page.
  function persistListState(params, servedPage){
    const visible = new URLSearchParams();
    LIST_STATE_KEYS.forEach(function(key){
      const value = params.get(key);
      if (value !== null && value !== '') visible.set(key, value);
    });
    const page = parseInt(servedPage, 10);
    visible.set('page', String(Number.isFinite(page) && page > 0 ? page : 1));
    const url = window.location.pathname + '?' + visible.toString() + window.location.hash;
    try { history.replaceState(history.state, document.title, url); } catch(_e){}
  }
  (function(){
    const current = new URLSearchParams(window.location.search);
    if (!current.has('page')) return;
    if (parseInt(current.get('page') || '', 10) === currentListPage) return;
    persistListState(buildListState(currentListPage), currentListPage);
  })();
  // Omitting page keeps the current served page; filter changes pass 1 explicitly.
  async function refreshList(page){
    const si = document.getElementById('npc_search');
    const wasFocused = document.activeElement && document.activeElement.id === 'npc_search';
    const caretStart = wasFocused && si && typeof si.selectionStart === 'number' ? si.selectionStart : null;
    const caretEnd = wasFocused && si && typeof si.selectionEnd === 'number' ? si.selectionEnd : null;
    const askedPage = parseInt(page, 10);
    const requestedPage = Number.isFinite(askedPage) && askedPage > 0 ? askedPage : currentListPage;
    const params = buildListState(requestedPage);
    const requestParams = new URLSearchParams(params.toString());
    requestParams.set('list','1');
    if (listAbort) { try { listAbort.abort(); } catch(_){} }
    const requestId = ++listRequestId;
    listAbort = new AbortController();
    try {
      const res = await fetch('npc_master.php?'+requestParams.toString(), { signal: listAbort.signal });
      if (!res.ok) throw new Error('HTTP ' + String(res.status));
      const html = await res.text();
      if (requestId !== listRequestId) return;
      const temp = document.createElement('div'); temp.innerHTML = html;
      const newPag = temp.querySelector('.pagination.npc-toolbar');
      const newGrid = temp.querySelector('.npc-grid');
      if (!newPag || !newGrid) throw new Error('Incomplete NPC list response');
      const oldPag = document.querySelector('.pagination.npc-toolbar');
      const oldGrid = document.querySelector('.npc-grid');
      if (oldPag && oldPag.parentElement) oldPag.parentElement.replaceChild(newPag, oldPag);
      if (oldGrid && oldGrid.parentElement) oldGrid.parentElement.replaceChild(newGrid, oldGrid);
      currentListPage = readServedPage(newPag) || requestedPage;
      persistListState(params, currentListPage);
      // rebind events on new elements
      document.querySelectorAll('.npc-card').forEach(card=>{
        card.addEventListener('click', function(ev){
          if (ev.target.closest('.npc-title-actions')) return;
          const id=this.getAttribute('data-id'); if (!id) return;
          ev.preventDefault();
          openModal('npc_master.php?edit='+encodeURIComponent(id)+'&partial=1');
        });
      });
      // Rebind filter dropdowns in refreshed DOM
      (function(){
        function bindDropdown(btnId, menuId){
          const btn = document.getElementById(btnId);
          const menu = document.getElementById(menuId);
          if (!btn || !menu) return;
          btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); menu.style.display = (menu.style.display==='none'||menu.style.display==='') ? 'block' : 'none'; });
          document.addEventListener('click', function(){ if (menu.style.display==='block') menu.style.display='none'; });
          menu.addEventListener('click', function(e){ e.stopPropagation(); });
          menu.querySelectorAll('input[type="checkbox"]').forEach(cb=> cb.addEventListener('change', function(){ refreshList(1); }));
        }
        bindDropdown('npc_filter_btn_top','npc_filter_menu_top');
        bindDropdown('npc_filter_btn','npc_filter_menu');
      })();
      document.querySelectorAll('[data-favorite-id]').forEach(btn=>{
        btn.addEventListener('click', async function(e){
          e.preventDefault(); const id = this.getAttribute('data-favorite-id');
          const fd = new FormData(); fd.append('toggle_favorite','1'); fd.append('id', id);
          const res = await fetch('npc_master.php', { method:'POST', body: fd }); let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){ const active = Number(json.favorite||0)===1; this.classList.toggle('active', active); this.textContent = active ? '\u2605' : '\u2606'; }
        });
      });
      document.querySelectorAll('[data-lock-id]').forEach(btn=>{
        btn.addEventListener('click', async function(e){
          e.preventDefault(); const id = this.getAttribute('data-lock-id');
          const fd = new FormData(); fd.append('toggle_lock','1'); fd.append('id', id);
          const res = await fetch('npc_master.php', { method:'POST', body: fd }); let json={}; try{ json=await res.json(); }catch(_e){}
            if (json && json.ok){ const active = Number(json.locked||0)===1; updateCardLockVisualState(this, active); }
        });
      });
      const newCreate = document.getElementById('npc_create_btn');
      if (newCreate){ newCreate.addEventListener('click', function(){ openModal('npc_master.php?partial=1'); }); }
      try { if (window.bindNpcToolbarImport) window.bindNpcToolbarImport(document.getElementById('npc_import_btn')); } catch(_){}
      // rebind bulk delete in refreshed DOM
      try { if (window.bindNpcBulkDelete) window.bindNpcBulkDelete(document.getElementById('npc_bulk_delete_btn')); } catch(_){}
      // rebind bulk unlock in refreshed DOM
      try { if (window.bindNpcBulkUnlock) window.bindNpcBulkUnlock(document.getElementById('npc_bulk_unlock_btn')); } catch(_){}
      // rebind mass switch in refreshed DOM
      try { if (window.bindNpcBulkSwitchProfile) window.bindNpcBulkSwitchProfile(document.getElementById('npc_bulk_switch_profile_btn')); } catch(_){}
      // rebind Build Relationships button in refreshed DOM
      try { if (window.bindRelBuildButton) window.bindRelBuildButton(document.getElementById('rel_bulk_build_btn')); } catch(_){}
      bindNpcLetterButtons(document);
      bindNpcToolbarPagination(document);
      const newSearch = document.getElementById('npc_search');
      if (newSearch){
        // Rebind with debounce and restore focus/caret
        newSearch.addEventListener('input', function(){ refreshListDebounced(1); });
        if (wasFocused){
          try {
            newSearch.focus();
            if (caretStart!=null && caretEnd!=null) newSearch.setSelectionRange(caretStart, caretEnd);
          } catch(_e){}
        }
      }
      const newProfileSel = document.getElementById('npc_profile_filter');
      if (newProfileSel){ newProfileSel.addEventListener('change', function(){ refreshList(1); }); }
      bindAutoLockProfile(document.getElementById('npc_auto_lock_profile'));
    } catch(error) {
      if (error && error.name === 'AbortError') return;
      console.error('NPC profile list refresh failed:', error);
    }
  }
  // Simple debounce for input
  let debTimer = null;
  function refreshListDebounced(page){
    if (debTimer) clearTimeout(debTimer);
    debTimer = setTimeout(()=>refreshList(page), 500)
  }
  if (searchInput){ searchInput.addEventListener('input', function(){ refreshListDebounced(1); }); }
  const profileSel = document.getElementById('npc_profile_filter');
  if (profileSel){ profileSel.addEventListener('change', function(){ refreshList(1); }); }
  // Removed alpha toggle; default remains ascending (favorites first)
  bindNpcLetterButtons(document);
  bindNpcToolbarPagination(document);
  // Toggle buttons
  // Filter dropdown toggles
  (function(){
    function bindDropdown(btnId, menuId){
      const btn = document.getElementById(btnId);
      const menu = document.getElementById(menuId);
      if (!btn || !menu) return;
      btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); menu.style.display = (menu.style.display==='none'||menu.style.display==='') ? 'block' : 'none'; });
      document.addEventListener('click', function(){ if (menu.style.display==='block') menu.style.display='none'; });
      menu.addEventListener('click', function(e){ e.stopPropagation(); });
      // When any checkbox changes, refetch
      menu.querySelectorAll('input[type="checkbox"]').forEach(cb=> cb.addEventListener('change', function(){ refreshList(1); }));
    }
    bindDropdown('npc_filter_btn_top','npc_filter_menu_top');
    bindDropdown('npc_filter_btn','npc_filter_menu');
  })();
  document.querySelectorAll('[data-favorite-id]').forEach(btn=>{
    btn.addEventListener('click', async function(e){
      e.preventDefault();
      const id = this.getAttribute('data-favorite-id');
      const fd = new FormData(); fd.append('toggle_favorite','1'); fd.append('id', id);
      const res = await fetch('npc_master.php', { method:'POST', body: fd });
      let json={}; try{ json=await res.json(); }catch(_e){}
      if (json && json.ok){
        const active = Number(json.favorite||0)===1;
        this.classList.toggle('active', active);
                this.textContent = active ? '\u2605' : '\u2606';
                try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent= active?'Marked favorite':'Unfavorited'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1500); } } catch(_e){}
      }
    });
  });
  document.querySelectorAll('[data-lock-id]').forEach(btn=>{
    btn.addEventListener('click', async function(e){
      e.preventDefault();
      const id = this.getAttribute('data-lock-id');
      const fd = new FormData(); fd.append('toggle_lock','1'); fd.append('id', id);
      const res = await fetch('npc_master.php', { method:'POST', body: fd });
      let json={}; try{ json=await res.json(); }catch(_e){}
      if (json && json.ok){
        const active = Number(json.locked||0)===1;
        updateCardLockVisualState(this, active);
        try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent= active?'Locked profile':'Unlocked profile'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1500); } } catch(_e){}
      }
    });
  });
  // Trash/delete button handler (delegated so it also works after dynamic refresh)
  document.addEventListener('click', async function(e){
    const el = (e.target && typeof e.target.closest === 'function')
      ? e.target.closest('.btn-trash')
      : null;
    if (!el) return;

    if (el.classList.contains('disabled')) {
        e.preventDefault();
        alert('This NPC is locked and cannot be deleted.');
        return;
    }
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
        return;
    }

    e.preventDefault();
    const href = String(el.getAttribute('href') || '');
    let npcId = String(el.getAttribute('data-delete-id') || '').trim();
    if (!npcId) {
        const m = href.match(/[?&]delete=(\d+)/i);
        npcId = m ? String(m[1]) : '';
    }
    if (!npcId) {
        if (href) window.location.href = href;
        return;
    }
    if (!confirm('Delete this NPC?')) {
        return;
    }

    try {
        const fd = new FormData();
        fd.append('delete_npc', '1');
        fd.append('id', npcId);
        const res = await fetch('npc_master.php', { method:'POST', body: fd });
        let json = {};
        try { json = await res.json(); } catch (_e) { json = { ok:false, error:'Invalid server response' }; }
        if (!(json && json.ok)) {
            alert('Delete failed: ' + (json && json.error ? json.error : 'Unknown'));
            return;
        }
        try {
            const card = document.getElementById('npc_card_' + npcId);
            if (card && card.parentElement) card.parentElement.removeChild(card);
        } catch (_e) {}
        try {
            const toast = document.getElementById('toast');
            if (toast && toast.querySelector('.message')) {
                toast.querySelector('.message').textContent = 'NPC deleted';
                toast.classList.add('show');
                setTimeout(()=>toast.classList.remove('show'), 1600);
            }
        } catch (_e) {}
        try { if (typeof refreshList === 'function') refreshList(); } catch (_e) {}
    } catch (_e) {
        if (href) window.location.href = href;
    }
  });
  function stobeParseObject(raw){
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    const txt = String(raw).trim();
    if (!txt) return null;
    try {
      const parsed = JSON.parse(txt);
      return (parsed && typeof parsed === 'object') ? parsed : null;
    } catch(_e){
      return null;
    }
  }
  function stobePortraitInitial(name){
    const v = String(name == null ? '' : name).trim();
    if (!v) return '?';
    const first = v.charAt(0).toUpperCase();
    return first || '?';
  }
  function stobeResolvePortraitUrl(payload){
    if (!payload || typeof payload !== 'object') return '';
    const metadata = stobeParseObject(payload.metadata);
    const candidates = [];
    const pushCandidate = (val) => {
      const s = String(val == null ? '' : val).trim();
      if (s) candidates.push(s);
    };

    if (metadata && typeof metadata === 'object') {
      if (metadata.portrait && typeof metadata.portrait === 'object') {
        pushCandidate(metadata.portrait.web_path);
        pushCandidate(metadata.portrait.url);
        pushCandidate(metadata.portrait.path);
      }
      pushCandidate(metadata.portrait_url);
      pushCandidate(metadata.portrait_path);
    }
    if (payload.portrait && typeof payload.portrait === 'object') {
      pushCandidate(payload.portrait.web_path);
      pushCandidate(payload.portrait.url);
      pushCandidate(payload.portrait.path);
    }
    pushCandidate(payload.portrait_url);
    pushCandidate(payload.portrait_path);

    if (!candidates.length) return '';
    let candidate = String(candidates[0] || '').replace(/\\/g, '/');
    if (!candidate) return '';
    if (/^https?:\/\//i.test(candidate)) return candidate;
    if (candidate.indexOf('/StobeServer/') === 0) return candidate;
    if (candidate.indexOf('/data/portraits/') === 0) return '/StobeServer' + candidate;
    if (candidate.indexOf('data/portraits/') === 0) return '/StobeServer/' + candidate.replace(/^\/+/, '');
    if (candidate.charAt(0) === '/') return candidate;
    return '/' + candidate;
  }
  function stobeApplyPortraitToCard(card, payload){
    if (!card) return;
    const row = card.querySelector('.npc-row');
    if (!row) return;
    let portraitCol = row.querySelector('.npc-portrait-col');
    if (!portraitCol){
      portraitCol = document.createElement('div');
      portraitCol.className = 'npc-portrait-col';
      const fields = row.querySelector('.npc-fields');
      if (fields) row.insertBefore(portraitCol, fields);
      else row.prepend(portraitCol);
    }
    portraitCol.textContent = '';
    const npcName = String((payload && payload.npc_name) || '');
    const portraitUrl = stobeResolvePortraitUrl(payload || {});
    if (portraitUrl){
      const img = document.createElement('img');
      img.className = 'npc-portrait-img';
      img.loading = 'lazy';
      img.src = portraitUrl;
      img.alt = npcName ? (npcName + ' portrait') : 'NPC portrait';
      portraitCol.appendChild(img);
      return;
    }
    const fallback = document.createElement('div');
    fallback.className = 'npc-portrait-fallback';
    fallback.textContent = stobePortraitInitial(npcName);
    portraitCol.appendChild(fallback);
  }
  // Receive save events from iframe and update the card inline
  window.addEventListener('message', async function(e){
    const d = e.data || {};
    if (d.type === 'npc_saved'){
      const id = String(d.id||'');
      const data = d.data || {};
      let card = document.getElementById('npc_card_'+id);
      if (!card){
        // Create a new card at the start of the grid
        const grid = document.querySelector('.npc-grid');
        if (grid){
          const div = document.createElement('div');
          div.className = 'npc-card';
          div.id = 'npc_card_'+id;
          div.setAttribute('data-id', id);
          div.setAttribute('data-player-faction', '0');
          div.setAttribute('data-current-action', '');
          div.innerHTML = `
            <div class="npc-title">
              <div class="npc-title-left"><span class="npc-name"></span></div>
              <div class="npc-title-actions">
                <span class="npc-tags-top" style="display:none"></span>
                <a class="btn btn-toggle" href="#" data-favorite-id="${id}" title="Toggle favorite">&#9734;</a>
                <a class="btn btn-toggle" href="#" data-lock-id="${id}" title="Toggle lock - Locked profiles are protected from save rollback when loading saves">&#x1F513;</a>
                <a class="btn btn-trash" data-delete-id="${id}" href="npc_master.php?delete=${id}" title="Delete">&#x274C;</a>
              </div>
            </div>
            <div class="npc-divider"></div>
            <div class="npc-row">
              <div class="npc-portrait-col">
                <div class="npc-portrait-fallback">?</div>
              </div>
              <div class="npc-fields">
                <div class="npc-line"><span class="npc-muted">Gender:</span> <span class="npc-gender"></span></div>
                <div class="npc-line"><span class="npc-muted">Race:</span> <span class="npc-race"></span></div>
                <div class="npc-line"><span class="npc-muted">Faction:</span> <span class="npc-faction-name"></span></div>
                <div class="npc-line"><span class="npc-muted">Voice:</span> <span class="npc-voiceid"></span></div>
                <div class="npc-line"><span class="npc-muted">Profile:</span> <span class="npc-profile"></span></div>
                <div class="npc-line npc-bounty-line" style="display:none"><span class="npc-muted">Bounty:</span> <span class="npc-bounty"></span></div>
                <div class="npc-bounty-section" style="display:none">
                  <div class="npc-bounty-heading">Bounty Breakdown</div>
                  <div class="npc-bounty-breakdown"></div>
                </div>
              </div>
              <div class="npc-right"></div>
            </div>
            `;
          grid.prepend(div);
          // Wire edit button
          div.addEventListener('click', function(ev){ if (ev.target.closest('.npc-title-actions')) return; ev.preventDefault(); openModal('npc_master.php?edit='+encodeURIComponent(id)+'&partial=1'); });
          card = div;
        }
      }
      if (card){
        const setText = (sel, val)=>{ const el = card.querySelector(sel); if (el) el.textContent = val==null?'':String(val); };
        setText('.npc-name', data.npc_name);
        setText('.npc-gender', data.gender);
        setText('.npc-race', data.race);
        // Only relabel the faction when the save payload actually carries faction data, so a
        // partial payload cannot replace a server-rendered name with "Unknown".
        const factionEl = card.querySelector('.npc-faction-name');
        if (factionEl) {
          const factionMetadata = stobePayloadMetadataObject(data);
          const hasFactionData = Object.prototype.hasOwnProperty.call(data, 'faction')
            || Object.prototype.hasOwnProperty.call(factionMetadata, 'faction');
          if (hasFactionData || String(factionEl.textContent || '').trim() === '') {
            factionEl.textContent = stobeFactionCardLabel(data);
          }
        }
        setText('.npc-voiceid', data.voiceid);
        stobeApplyPlayerFactionCardState(card, data);
        stobeApplyCardActionState(card, data);
        stobeApplyPortraitToCard(card, data);
        try {
          const normalizeObj = (raw) => {
            if (!raw) return null;
            if (typeof raw === 'object') return raw;
            const txt = String(raw).trim();
            if (!txt) return null;
            try {
              const parsed = JSON.parse(txt);
              return (parsed && typeof parsed === 'object') ? parsed : null;
            } catch(_e) {
              return null;
            }
          };

          const parsePositiveInt = (value) => {
            if (typeof value === 'number' && Number.isFinite(value)) {
              return Math.max(0, Math.trunc(value));
            }
            const txt = String(value == null ? '' : value).trim();
            if (!txt) return 0;
            const parsed = parseInt(txt.replace(/[^0-9-]/g, ''), 10);
            if (!Number.isFinite(parsed) || parsed <= 0) return 0;
            return parsed;
          };
          const normalizeReasons = (raw) => {
            if (Array.isArray(raw)) {
              return raw
                .map((v) => String(v || '').trim())
                .filter((v) => v !== '')
                .slice(0, 8);
            }
            if (typeof raw === 'string' && raw.trim() !== '') {
              return raw
                .split(/[|,;]+/)
                .map((v) => String(v || '').trim())
                .filter((v) => v !== '')
                .slice(0, 8);
            }
            return [];
          };

          const bountyObj = normalizeObj(data.bounty_payload) || normalizeObj(data.bounty);
          const breakdownRows = [];
          let bountyTotal = 0;

          if (bountyObj && typeof bountyObj === 'object') {
            bountyTotal = parsePositiveInt(bountyObj.total || bountyObj.amount || bountyObj.value || bountyObj.cats || bountyObj.bounty);
            if (Array.isArray(bountyObj.factions)) {
              let computedTotal = 0;
              bountyObj.factions.forEach((f) => {
                if (!f || typeof f !== 'object') return;
                const faction = String(f.faction || f.faction_id || 'Unknown faction').trim();
                const amount = parsePositiveInt(f.amount || f.total || f.value || f.cats || f.bounty);
                const reasons = normalizeReasons(f.what_for || f.crimes || f.reason || f.reasons || []);
                if (!faction && amount <= 0 && reasons.length === 0) return;
                computedTotal += amount;
                breakdownRows.push({ faction: faction || 'Unknown faction', amount, reasons });
              });
              if (bountyTotal <= 0 && computedTotal > 0) {
                bountyTotal = computedTotal;
              }
            }
          }

          if (bountyTotal <= 0 && Object.prototype.hasOwnProperty.call(data, 'bounty')) {
            bountyTotal = parsePositiveInt(data.bounty);
          }
          const bountyLine = card.querySelector('.npc-bounty-line');
          if (bountyLine) {
            bountyLine.style.display = bountyTotal > 0 ? '' : 'none';
          }
          setText('.npc-bounty', bountyTotal > 0 ? (bountyTotal.toLocaleString() + ' cats') : '');

          let legacyWanted = '';
          if (Object.prototype.hasOwnProperty.call(data, 'metadata')) {
            const metaObj = normalizeObj(data.metadata);
            if (metaObj && typeof metaObj.bounty_text === 'string' && metaObj.bounty_text.trim() !== '') {
              legacyWanted = metaObj.bounty_text.trim();
            }
          }

          const breakdownWrap = card.querySelector('.npc-bounty-section');
          const breakdownOut = card.querySelector('.npc-bounty-breakdown');
          if (breakdownWrap && breakdownOut) {
            breakdownOut.textContent = '';
            const maxRows = 4;
            if (breakdownRows.length > 0) {
              breakdownRows.slice(0, maxRows).forEach((row) => {
                const item = document.createElement('div');
                item.className = 'npc-bounty-item';

                const top = document.createElement('div');
                top.className = 'npc-bounty-item-top';

                const factionEl = document.createElement('span');
                factionEl.className = 'npc-bounty-faction';
                factionEl.textContent = row.faction;
                top.appendChild(factionEl);

                if (row.amount > 0) {
                  const amountEl = document.createElement('span');
                  amountEl.className = 'npc-bounty-amount';
                  amountEl.textContent = row.amount.toLocaleString() + ' cats';
                  top.appendChild(amountEl);
                }

                item.appendChild(top);

                if (row.reasons.length > 0) {
                  const crimesEl = document.createElement('div');
                  crimesEl.className = 'npc-bounty-crimes';
                  crimesEl.textContent = 'Wanted for: ' + row.reasons.join(', ');
                  item.appendChild(crimesEl);
                }

                breakdownOut.appendChild(item);
              });

              if (breakdownRows.length > maxRows) {
                const more = document.createElement('div');
                more.className = 'npc-bounty-more';
                more.textContent = '+' + String(breakdownRows.length - maxRows) + ' more faction(s)';
                breakdownOut.appendChild(more);
              }
              breakdownWrap.style.display = '';
            } else if (legacyWanted) {
              const legacy = document.createElement('div');
              legacy.className = 'npc-bounty-legacy';
              legacy.textContent = legacyWanted;
              breakdownOut.appendChild(legacy);
              breakdownWrap.style.display = '';
            } else {
              breakdownWrap.style.display = 'none';
            }
          }
        } catch(_e){}
        // Update title tags pill
        try {
          const top = card.querySelector('.npc-title-actions .npc-tags-top');
          const tval = (data.tags||'').trim();
          if (top){
            if (tval){ top.style.display='inline-block'; top.textContent = tval; top.title = 'Use Search to filter by these tags: ' + tval; }
            else { top.style.display='none'; top.textContent=''; top.removeAttribute('title'); }
          }
        } catch(_){}
        const profId = String(data.profile_id||'');
        setText('.npc-profile', PROFILES_BY_ID[profId] || '');
        // Toggle Middle-term memory icon based on metadata override (legacy extended_data fallback).
        try {
          const mtm = (function(){
            const metaRaw = String(data.metadata||'').trim();
            if (metaRaw) {
              try {
                const m = JSON.parse(metaRaw);
                if (m && typeof m === 'object' && Object.prototype.hasOwnProperty.call(m, 'MIDDLE_TERM_MEMORY_ENABLED')) {
                  return Number(m.MIDDLE_TERM_MEMORY_ENABLED) === 1 ? 1 : 0;
                }
              } catch(_e){}
            }
            const raw = String(data.extended_data||'').trim();
            if (!raw) return 0;
            try { const o = JSON.parse(raw); return (o && Number(o.middle_term_enabled||0)===1) ? 1 : 0; } catch(_e){ return 0; }
          })();
          const left = card.querySelector('.npc-title-left');
          if (left){
            let icon = left.querySelector('.npc-mtm-icon');
            if (mtm){ if (!icon){ icon = document.createElement('span'); icon.className='npc-mtm-icon'; icon.title='Middle-term memory enabled'; icon.textContent='\u{1F4C3}'; left.appendChild(icon); } }
            else { if (icon){ icon.remove(); } }
          }
        } catch(_e){}
        // Toggle Individual memory bank icon based on extended_data flag.
        try {
          const left = card.querySelector('.npc-title-left');
          if (left){
            let imbEnabled = 0;
            const rawExt = String(data.extended_data || '').trim();
            if (rawExt) {
              try {
                const ext = JSON.parse(rawExt);
                if (ext && typeof ext === 'object' && Object.prototype.hasOwnProperty.call(ext, 'individual_memory_enabled')) {
                  const raw = ext.individual_memory_enabled;
                  imbEnabled = (raw === true || Number(raw) === 1 || String(raw).toLowerCase() === 'true') ? 1 : 0;
                }
              } catch(_e){}
            }
            let icon = left.querySelector('.npc-imb-icon');
            if (imbEnabled){
              if (!icon){
                icon = document.createElement('span');
                icon.className = 'npc-imb-icon';
                icon.title = 'Individual memory bank enabled';
                icon.textContent = '\u{1F9E0}';
                left.appendChild(icon);
              }
            } else if (icon) {
              icon.remove();
            }
          }
        } catch(_e){}
      }
      closeModal();
      try { const toast=document.getElementById('toast'); if (toast){ toast.querySelector('.message').textContent='NPC saved'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 2000); } } catch(_e){}
    }
  });
})();

// Build Relationships Modal functionality
(function(){
  async function loadModalStats(){
    // Load model info and NPC counts
    try {
      const res = await fetch('../ext/relationship_system/batch_build.php?action=stats');
      const data = await res.json();
      if (data.ok){
        document.getElementById('rel_build_model').textContent = data.model || 'Not configured';
        document.getElementById('rel_count_built').textContent = data.built || 0;
        document.getElementById('rel_count_pending').textContent = data.pending || 0;
      }
    } catch(e){
      document.getElementById('rel_build_model').textContent = 'Error loading';
    }
  }

  function bindRelBuildButton(btn){
    if (!btn) return;
    btn.addEventListener('click', function(e){
      e.preventDefault();
      const modal = document.getElementById('rel_build_modal');
      if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Reset state
        document.getElementById('rel_build_content').style.display = 'block';
        document.getElementById('rel_build_progress').style.display = 'none';
        document.getElementById('rel_build_log').innerHTML = '';
        document.getElementById('rel_build_bar').style.width = '0%';
        document.getElementById('rel_build_count').textContent = '0 / 0';
        document.getElementById('rel_build_status').textContent = 'Ready';
        const doneBtn = document.getElementById('rel_build_done');
        if (doneBtn) doneBtn.style.display = 'none';
        // Load stats
        loadModalStats();
      }
    });
  }

  // Bind both buttons (AJAX partial and main page)
  bindRelBuildButton(document.getElementById('rel_bulk_build_btn'));

  // Make it available for rebinding after AJAX refresh
  window.bindRelBuildButton = bindRelBuildButton;

  // Close button
  const closeBtn = document.getElementById('rel_build_close');
  if (closeBtn){
    closeBtn.addEventListener('click', function(){
      const modal = document.getElementById('rel_build_modal');
      if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
      }
    });
  }

  // Start button
  const startBtn = document.getElementById('rel_build_start');
  if (startBtn){
    startBtn.addEventListener('click', async function(){
      const force = document.getElementById('rel_build_force').checked ? 1 : 0;
      const infer = document.getElementById('rel_build_infer').checked ? 1 : 0;

      // Show progress
      document.getElementById('rel_build_content').style.display = 'none';
      document.getElementById('rel_build_progress').style.display = 'block';

      const logEl = document.getElementById('rel_build_log');
      const barEl = document.getElementById('rel_build_bar');
      const countEl = document.getElementById('rel_build_count');
      const statusEl = document.getElementById('rel_build_status');

      function log(msg, type){
        const line = document.createElement('div');
        line.textContent = msg;
        if (type === 'error') line.style.color = '#ff6b6b';
        else if (type === 'success') line.style.color = '#69db7c';
        else if (type === 'info') line.style.color = '#e6b76c';
        logEl.appendChild(line);
        logEl.scrollTop = logEl.scrollHeight;
      }

      try {
        log('Starting relationship build...', 'info');
        statusEl.textContent = 'Fetching NPC list...';

        // Fetch list of NPCs to process
      const listRes = await fetch('../ext/relationship_system/batch_build.php?action=list&force=' + force);
        const listData = await listRes.json();

        if (!listData.ok){
          log('Error: ' + (listData.error || 'Failed to get NPC list'), 'error');
          statusEl.textContent = 'Failed';
          return;
        }

        const npcs = listData.npcs || [];
        const total = npcs.length;

        if (total === 0){
          log('No NPCs need processing.', 'info');
          statusEl.textContent = 'Complete';
          barEl.style.width = '100%';
          return;
        }

        log('Found ' + total + ' NPCs to process.', 'info');
        countEl.textContent = '0 / ' + total;

        let processed = 0;
        let success = 0;
        let failed = 0;

        // Process each NPC
        for (const npc of npcs){
          statusEl.textContent = 'Processing: ' + npc.name;

          try {
        const res = await fetch('../ext/relationship_system/batch_build.php?action=process&id=' + npc.id + '&force=' + force);
            const data = await res.json();

            if (data.ok){
              success++;
              log('? ' + npc.name + ': ' + (data.count || 0) + ' relationships', 'success');
            } else {
              failed++;
              log('? ' + npc.name + ': ' + (data.error || 'Failed'), 'error');
            }
          } catch(e){
            failed++;
            log('? ' + npc.name + ': Network error', 'error');
          }

          processed++;
          countEl.textContent = processed + ' / ' + total;
          barEl.style.width = Math.round((processed / total) * 100) + '%';
        }

        // Run inference if requested
        if (infer && success > 0){
          statusEl.textContent = 'Running transitive inference...';
          log('Running transitive inference...', 'info');

          try {
      const infRes = await fetch('../ext/relationship_system/batch_build.php?action=infer');
            const infData = await infRes.json();
            if (infData.ok){
              log('? Inference complete: ' + (infData.count || 0) + ' relationships updated', 'success');
            }
          } catch(e){
            log('Inference skipped due to error', 'error');
          }
        }

        statusEl.textContent = 'Complete';
        log('Done! ' + success + ' succeeded, ' + failed + ' failed.', 'info');

        // Show Done button
        const doneBtn = document.getElementById('rel_build_done');
        if (doneBtn) doneBtn.style.display = 'inline-block';

      } catch(e){
        log('Error: ' + e.message, 'error');
        statusEl.textContent = 'Failed';
        // Show Done button even on error
        const doneBtn = document.getElementById('rel_build_done');
        if (doneBtn) doneBtn.style.display = 'inline-block';
      }
    });
  }

  // Done button handler
  const doneBtn = document.getElementById('rel_build_done');
  if (doneBtn){
    doneBtn.addEventListener('click', function(){
      const modal = document.getElementById('rel_build_modal');
      if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
      }
    });
  }
})();

// NPC Export/Import functionality
(function(){
  // Export button in modal header
  const exportBtn = document.getElementById('npc_modal_export');
  if (exportBtn) {
    exportBtn.addEventListener('click', function(){
      const id = window.CURRENT_NPC_ID;
      if (!id) { alert('No NPC selected. Save the NPC first before exporting.'); return; }
      // Trigger download by navigating to export URL
      window.location.href = 'npc_master.php?export=' + id;
    });
  }
  
  // Import Bio to current NPC button in modal header (only for existing NPCs)
  const importToBtn = document.getElementById('npc_modal_import_to');
  if (importToBtn) {
    importToBtn.addEventListener('click', function(){
      const id = window.CURRENT_NPC_ID;
      if (!id) { alert('No NPC selected. Save the NPC first before importing.'); return; }
      
      // Create file input
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = '.json';
      input.style.display = 'none';
      document.body.appendChild(input);
      
      input.addEventListener('change', async function(){
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const text = await file.text();
        
        try {
          const data = JSON.parse(text);
          if (!confirm('Import biography from "' + (data.npc_name || 'Unknown') + '" to this NPC?\n\nThis will overwrite the current NPC\'s biography fields (personality, appearance, skills, etc.) but keep the name.')) {
            return;
          }
          
          const formData = new FormData();
          formData.append('import_npc', '1');
          formData.append('import_data', text);
          formData.append('target_id', id);
          
          const res = await fetch('npc_master.php', { method: 'POST', body: formData });
          const result = await res.json();
          
          if (result.ok) {
            alert(result.message || 'Biography imported successfully');
            location.reload();
          } else {
            alert('Error: ' + (result.error || 'Import failed'));
          }
        } catch(e) {
          alert('Error parsing JSON file: ' + e.message);
        } finally {
          document.body.removeChild(input);
        }
      });
      
      input.click();
    });
  }
  
  // Import NPC button in toolbar (create new NPC from JSON)
  function bindToolbarImportButton(importBtn){
    if (!importBtn || importBtn.dataset.boundImport === '1') {
      return;
    }
    importBtn.dataset.boundImport = '1';
    importBtn.addEventListener('click', function(){
      // Create file input
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = '.json';
      input.style.display = 'none';
      document.body.appendChild(input);
      
      input.addEventListener('change', async function(){
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const text = await file.text();
        
        try {
          const data = JSON.parse(text);
          const originalName = data.npc_name || '';
          
          // Show dialog to confirm or change name
          const newName = prompt(
            'Import NPC from file.\n\n' +
            'Original name: ' + (originalName || '(none)') + '\n\n' +
            'Enter NPC name (leave as-is or change for renamed NPCs):',
            originalName
          );
          
          if (newName === null) {
            // User cancelled
            return;
          }
          
          if (!newName.trim()) {
            alert('NPC name is required');
            return;
          }
          
          const formData = new FormData();
          formData.append('import_npc', '1');
          formData.append('import_data', text);
          formData.append('new_name', newName.trim());
          
          const res = await fetch('npc_master.php', { method: 'POST', body: formData });
          const result = await res.json();
          
          if (result.ok) {
            alert(result.message || 'NPC imported successfully');
            location.reload();
          } else {
            alert('Error: ' + (result.error || 'Import failed'));
          }
        } catch(e) {
          alert('Error parsing JSON file: ' + e.message);
        } finally {
          document.body.removeChild(input);
        }
      });
      
      input.click();
    });
  }
  window.bindNpcToolbarImport = bindToolbarImportButton;
  bindToolbarImportButton(document.getElementById('npc_import_btn'));
})();

</script>

<?php
 // Provides a JSON editor for metadata field and form consolidation function (only needed if metadata field is present)
 // Hide metadata editor in modal partial view
 if (!(isset($_GET['partial']) && $_GET['partial']=='1')) {
     include(__DIR__."/tmpl/metadata_json_editor.php");
 }
// Provides Datatables
 include(__DIR__."/tmpl/data_tables.php");
?>

    <div id="toast" class="toast-notification">
        <span class="message"></span>
    </div>

</main>

<?php
include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/footer.html");
$buffer = ob_get_contents();
ob_end_clean();
$title = $TITLE;
$buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
echo $buffer;
?>
