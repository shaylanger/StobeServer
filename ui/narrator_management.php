<?php
require_once __DIR__ . '/tmpl/dynamic_profile_schedule.php';

$enginePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'tmpl' . DIRECTORY_SEPARATOR . 'voice_filter_field.php');

if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
if ($uiPos !== false) {
    $webRoot = substr($scriptPath, 0, $uiPos);
} else {
    $webRoot = '';
}
if ($webRoot === '/') {
    $webRoot = '';
}
$webRoot = rtrim($webRoot, '/');

$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';
$narrator = new Narrator();
$saveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_narrator'])) {
    try {
        $roleplayName = Narrator::normalizeRoleplayName($_POST['roleplay_name'] ?? Narrator::DEFAULT_ROLEPLAY_NAME);
    } catch (InvalidArgumentException $exception) {
        $roleplayName = $narrator->getRoleplayName();
        $saveError = $exception->getMessage();
    }
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' ? '1' : '0';
    $welcomeEnabled = isset($_POST['welcome_enabled']) && $_POST['welcome_enabled'] === '1' ? '1' : '0';
    $randomEnabled = isset($_POST['random_enabled']) && $_POST['random_enabled'] === '1' ? '1' : '0';
    $diaryEnabled = isset($_POST['diary_enabled']) && $_POST['diary_enabled'] === '1' ? '1' : '0';
    $autoDiaryEnabled = isset($_POST['auto_diary_enabled']) && $_POST['auto_diary_enabled'] === '1' ? '1' : '0';
    $onlyDiaryAccess = isset($_POST['only_diary_access']) && $_POST['only_diary_access'] === '1' ? '1' : '0';
    $preserveInlineNarrationContext = isset($_POST['preserve_inline_narration_context']) && $_POST['preserve_inline_narration_context'] === '1' ? '1' : '0';
    $dynamicProfile = isset($_POST['dynamic_profile']) && $_POST['dynamic_profile'] === '1' ? '1' : '0';
    $inlineNarrationMode = strtolower(trim(strval($_POST['inline_narration_mode'] ?? 'disabled')));
    if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
        $inlineNarrationMode = 'disabled';
    }

    $randomChance = max(1, min(100, intval($_POST['random_chance'] ?? 15)));
    $randomCooldown = max(0, min(30, intval($_POST['random_cooldown'] ?? 10)));
    $welcomeCooldown = max(1, min(1440, intval($_POST['welcome_cooldown'] ?? 10)));

    $dynamicProfileFields = [];
    if (isset($_POST['dynamic_profile_fields']) && is_array($_POST['dynamic_profile_fields'])) {
        $dynamicProfileFields = array_values(array_filter(
            $_POST['dynamic_profile_fields'],
            static function ($field): bool {
                return in_array(strtolower(trim(strval($field))), ['personality', 'speechstyle', 'goals'], true);
            }
        ));
    }

    $profileId = intval($_POST['profile_id'] ?? 1);
    if ($profileId <= 0) {
        $profileId = 1;
    }

    $payload = [
        'roleplay_name' => $roleplayName,
        'enabled' => $enabled,
        'welcome_enabled' => $welcomeEnabled,
        'random_enabled' => $randomEnabled,
        'random_chance' => strval($randomChance),
        'random_cooldown' => strval($randomCooldown),
        'welcome_cooldown' => strval($welcomeCooldown),
        'diary_enabled' => $diaryEnabled,
        'auto_diary_enabled' => $autoDiaryEnabled,
        'only_diary_access' => $onlyDiaryAccess,
        'inline_narration_mode' => $inlineNarrationMode,
        'preserve_inline_narration_context' => $preserveInlineNarrationContext,
        'dynamic_profile' => $dynamicProfile,
        'profile_id' => strval($profileId),
        'voiceid' => trim(strval($_POST['voiceid'] ?? '')),
        'tts_filter_preset' => stobeUiNormalizeVoiceFilterPreset($_POST['tts_filter_preset'] ?? 'none'),
        'core' => trim(strval($_POST['core'] ?? '')),
        'background' => trim(strval($_POST['background'] ?? '')),
        'personality' => trim(strval($_POST['personality'] ?? '')),
        'speechstyle' => trim(strval($_POST['speechstyle'] ?? '')),
        'goals' => trim(strval($_POST['goals'] ?? '')),
        'oghma_knowledge' => trim(strval($_POST['oghma_knowledge'] ?? 'knowall')),
        'prompt_head' => trim(strval($_POST['prompt_head'] ?? '')),
    ];

    if ($saveError === '') {
        foreach (dps_policy((array)($_POST['dynamic_schedule'] ?? [])) as $key=>$value) $payload[$key]=(string)$value;
        $narrator->setMultiple($payload);
        $narrator->setDynamicProfileFields($dynamicProfileFields);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$roleplayName = $narrator->getRoleplayName();
$enabled = $narrator->getBool('enabled', true);
$welcomeEnabled = $narrator->getBool('welcome_enabled', false);
$randomEnabled = $narrator->getBool('random_enabled', false);
$randomChance = $narrator->getInt('random_chance', 15);
$randomCooldown = max(0, min(30, $narrator->getInt('random_cooldown', 10)));
$welcomeCooldown = $narrator->getInt('welcome_cooldown', 10);
$diaryEnabled = $narrator->getBool('diary_enabled', false);
$autoDiaryEnabled = $narrator->getBool('auto_diary_enabled', false);
$onlyDiaryAccess = $narrator->getBool('only_diary_access', false);
$inlineNarrationMode = strtolower(trim(strval($narrator->get('inline_narration_mode') ?? 'disabled')));
if (!in_array($inlineNarrationMode, ['disabled', 'narrator', 'npc', 'text_only'], true)) {
    $inlineNarrationMode = 'disabled';
}
$preserveInlineNarrationContext = $narrator->getBool('preserve_inline_narration_context', false);
$dynamicProfile = $narrator->getBool('dynamic_profile', false);
$dynamicProfileFields = $narrator->getDynamicProfileFields();
$profileId = $narrator->getInt('profile_id', 1);
$defaultNarratorSeed = Narrator::defaultSeedValues($profileId > 0 ? $profileId : 1);
$voiceid = $narrator->get('voiceid') ?? strval($defaultNarratorSeed['voiceid'] ?? 'stobenarrator');
$narratorTtsFilterPreset = stobeUiNormalizeVoiceFilterPreset($narrator->get('tts_filter_preset') ?? 'none');
$core = $narrator->get('core') ?? strval($defaultNarratorSeed['core'] ?? '');
$background = $narrator->get('background') ?? strval($defaultNarratorSeed['background'] ?? '');
$personality = $narrator->get('personality') ?? strval($defaultNarratorSeed['personality'] ?? '');
$speechstyle = $narrator->get('speechstyle') ?? strval($defaultNarratorSeed['speechstyle'] ?? '');
$goals = $narrator->get('goals') ?? strval($defaultNarratorSeed['goals'] ?? '');
$oghmaKnowledge = $narrator->get('oghma_knowledge') ?? strval($defaultNarratorSeed['oghma_knowledge'] ?? 'knowall');
$promptHead = $narrator->get('prompt_head') ?? strval($defaultNarratorSeed['prompt_head'] ?? '');

$profiles = function_exists('getAllCoreProfiles') ? getAllCoreProfiles() : [];

if (!$isEmbed) {
    require_once(__DIR__ . "/../profile_loader.php");
    $TITLE = "Narrator Management";
    ob_start();
    include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/head.html");
    include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/navbar.php");
}
?>

<link rel="stylesheet" href="<?= htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8') ?>/ui/css/main.css">
<style>
    main {
        padding-top: <?= $isEmbed ? '20px' : '80px' ?>;
        padding-bottom: 40px;
        padding-left: 5%;
        padding-right: 5%;
        margin: 0;
        display: flex;
        justify-content: center;
    }

    .page-container {
        width: 100%;
        max-width: 1200px;
    }

    /* Keep all text in narrator management white for consistent readability. */
    .page-container,
    .page-container * {
        color: #ffffff !important;
    }

    /* The white override above would otherwise hide the sample error state. */
    .page-container .voice-filter-status.is-error {
        color: #ff9c6e !important;
    }

    .page-container input::placeholder,
    .page-container textarea::placeholder {
        color: rgba(255, 255, 255, 0.85) !important;
    }

    .page-header {
        text-align: center;
        margin-bottom: 28px;
        padding: 24px 20px;
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(28, 28, 28, 0.98));
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .page-header h1 {
        margin-bottom: 10px;
        font-family: var(--stobe-title-font);
        word-spacing: 8px;
        font-size: 2em;
        color: rgb(242, 124, 17);
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
    }

    .page-header p {
        color: #aaa;
        font-size: 1em;
        margin: 0;
    }

    .content-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .content-section {
        background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        padding: 22px;
        border-radius: 10px;
        border: 1px solid #3a3a3a;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        transition: border-color 0.2s ease;
    }

    .content-section:hover {
        border-color: #4a4a4a;
    }

    .content-section h2 {
        font-family: var(--stobe-title-font);
        color: rgb(242, 124, 17);
        text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        word-spacing: 6px;
        margin-bottom: 18px;
        font-size: 1.35em;
        padding-bottom: 12px;
        border-bottom: 1px solid rgba(242, 124, 17, 0.2);
    }

    .full-width-section {
        grid-column: 1 / -1;
    }

    .content-section > label:not(.toggle-row):not(.field-chip) {
        display: block;
        font-size: 13px;
        color: rgb(242, 124, 17);
        font-weight: 600;
        margin-bottom: 6px;
        margin-top: 14px;
    }

    .content-section > label:not(.toggle-row):not(.field-chip):first-of-type {
        margin-top: 0;
    }

    .content-section input[type="text"],
    .content-section input[type="number"],
    .content-section select,
    .content-section textarea {
        background-color: rgba(26, 26, 26, 0.8);
        color: #e9efff;
        border: 1px solid #3a3a3a;
        padding: 10px 12px;
        border-radius: 6px;
        width: 100%;
        margin-bottom: 4px;
        transition: all 0.2s ease;
    }

    .content-section input[type="text"]:focus,
    .content-section input[type="number"]:focus,
    .content-section select:focus,
    .content-section textarea:focus {
        border-color: rgba(242, 124, 17, 0.5);
        outline: none;
        box-shadow: 0 0 0 3px rgba(242, 124, 17, 0.1);
    }

    .content-section textarea {
        min-height: 80px;
        font-family: inherit;
        resize: vertical;
    }

    .toggle-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        background: rgba(26, 26, 26, 0.6);
        border: 1px solid #3a3a3a;
        border-radius: 8px;
        margin-bottom: 10px;
        transition: all 0.2s ease;
    }

    .toggle-row:hover {
        background: rgba(36, 36, 36, 0.8);
        border-color: #4a4a4a;
    }

    .toggle-switch {
        position: relative;
        width: 48px;
        height: 24px;
        flex-shrink: 0;
    }

    .toggle-switch input[type="checkbox"] {
        position: absolute;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
        margin: 0;
        z-index: 2;
    }

    .toggle-slider {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #3a3a3a;
        border-radius: 24px;
        transition: all 0.3s ease;
        border: 1px solid #555;
    }

    .toggle-slider::before {
        content: '';
        position: absolute;
        width: 18px;
        height: 18px;
        left: 3px;
        top: 50%;
        transform: translateY(-50%);
        background-color: #888;
        border-radius: 50%;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .toggle-switch input[type="checkbox"]:checked + .toggle-slider {
        background-color: rgba(32, 122, 74, 0.9);
        border-color: rgba(72, 187, 120, 0.5);
    }

    .toggle-switch input[type="checkbox"]:checked + .toggle-slider::before {
        transform: translateY(-50%) translateX(22px);
        background-color: #fff;
    }

    .toggle-label {
        flex: 1;
        color: #e0e0e0;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        user-select: none;
    }

    .hint {
        font-size: 12px;
        color: #999;
        margin-top: 4px;
        margin-bottom: 6px;
        display: block;
        padding-left: 2px;
        line-height: 1.4;
    }

    .toggle-row + .hint {
        margin-left: 62px;
        margin-top: -2px;
        margin-bottom: 12px;
    }

    .dynamic-profile-card {
        margin-bottom: 20px;
        padding: 18px;
        background: linear-gradient(135deg, rgba(26, 26, 26, 0.8), rgba(32, 32, 32, 0.6));
        border: 1px solid #3a3a3a;
        border-radius: 10px;
        box-shadow: inset 0 1px rgba(255, 255, 255, 0.03);
    }

    .dynamic-profile-card h3 {
        color: rgb(242, 124, 17);
        margin-bottom: 14px;
        font-size: 1.15em;
        font-weight: 600;
    }

    .field-selection-label {
        margin-top: 14px;
        display: block;
        color: rgb(242, 124, 17);
        font-weight: 600;
        font-size: 0.95em;
    }

    .field-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 10px;
    }

    .field-chip {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(42, 42, 42, 0.8);
        border: 1px solid #4a4a4a;
        padding: 10px 14px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .field-chip:hover {
        background: rgba(52, 52, 52, 0.9);
        border-color: #5a5a5a;
    }

    .field-chip:has(input:checked) {
        background: rgba(32, 122, 74, 0.25);
        border-color: rgba(72, 187, 120, 0.5);
    }

    .field-chip input[type="checkbox"] {
        accent-color: #176529;
        transform: scale(1.3);
        cursor: pointer;
    }

    .field-chip .chip-text {
        color: #cfd8e3;
        font-size: 0.95em;
        font-weight: 500;
    }

    .btn-save {
        background-color: #176529;
        color: #fff;
        border: 1px solid rgba(72, 187, 120, 0.3);
        border-radius: 8px;
        padding: 12px 28px;
        cursor: pointer;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: 0.3px;
        margin-bottom: 24px;
        transition: all 0.2s ease;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.1);
    }

    .btn-save:hover {
        background-color: #1e8738;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px rgba(255, 255, 255, 0.15);
    }

    @media (max-width: 768px) {
        .content-grid {
            grid-template-columns: 1fr;
        }

        .page-header {
            padding: 15px;
        }

        .content-section {
            padding: 15px;
        }
    }

    @media (max-width: 480px) {
        main {
            padding-left: 2%;
            padding-right: 2%;
        }

        .page-header h1 {
            font-size: 1.5em;
        }

        .toggle-row + .hint {
            margin-left: 0;
        }

        .field-chips {
            flex-direction: column;
        }
    }
</style>

<?php if ($isEmbed): ?>
<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/stobe-theme.css?v=<?php echo filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'stobe-theme.css'); ?>">
<style>
    main { padding-top: 20px; }
</style>
<?php endif; ?>

<main>
    <div class="page-container">
        <div class="page-header">
            <h1>Narrator Management</h1>
            <p>Configure narrator behavior and profile settings.</p>
        </div>

        <form method="post" action="">
            <button type="submit" class="btn-save" name="save_narrator" value="1">Save Narrator Settings</button>
            <?php if ($saveError !== ''): ?>
                <div class="content-section" style="border-color:#b94a48; margin-bottom:16px;">
                    <?= htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="content-grid">
                <div class="content-section">
                    <h2>Core Settings</h2>

                    <label for="roleplay_name">Narrator Name</label>
                    <input type="text" id="roleplay_name" name="roleplay_name" maxlength="64" value="<?= htmlspecialchars($roleplayName, ENT_QUOTES, 'UTF-8') ?>" placeholder="The Narrator">
                    <span class="hint">Changes the narrator name in prompts, popups, history, and diaries. Internal routing remains The Narrator.</span>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="enabled" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Enable Narrator</span>
                    </label>
                    <span class="hint">Enable or disable narrator mode.</span>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="welcome_enabled" name="welcome_enabled" value="1" <?= $welcomeEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Enable Welcome Narration</span>
                    </label>
                    <span class="hint">Narrator can greet and recap after loading.</span>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="random_enabled" name="random_enabled" value="1" <?= $randomEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Enable Random Narration</span>
                    </label>
                    <span class="hint">Narrator can add occasional scene interjections.</span>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="diary_enabled" name="diary_enabled" value="1" <?= $diaryEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Narrator Diary</span>
                    </label>
                    <span class="hint">Allow manual narrator diary entries from the in-game diary menu.</span>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="auto_diary_enabled" name="auto_diary_enabled" value="1" <?= $autoDiaryEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Narrator Auto Diary</span>
                    </label>
                    <span class="hint">Include the narrator in the daily automatic diary cycle.</span>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="only_diary_access" name="only_diary_access" value="1" <?= $onlyDiaryAccess ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Narrator-only Diary Recall</span>
                    </label>
                    <span class="hint">When enabled, the narrator recalls only its own diary. Otherwise it may recall relevant diaries from any NPC.</span>
                </div>

                <div class="content-section">
                    <h2>Narration Rules</h2>

                    <label for="inline_narration_mode">Inline Narration Mode</label>
                    <select id="inline_narration_mode" name="inline_narration_mode">
                        <option value="disabled" <?= $inlineNarrationMode === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                        <option value="narrator" <?= $inlineNarrationMode === 'narrator' ? 'selected' : '' ?>>Narrator Voice</option>
                        <option value="npc" <?= $inlineNarrationMode === 'npc' ? 'selected' : '' ?>>NPC Voice</option>
                        <option value="text_only" <?= $inlineNarrationMode === 'text_only' ? 'selected' : '' ?>>Text Only</option>
                    </select>
                    <span class="hint">Routes a leading *narration* block to the narrator, the NPC, text-only display, or leaves current behavior unchanged.</span>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="preserve_inline_narration_context" name="preserve_inline_narration_context" value="1" <?= $preserveInlineNarrationContext ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Keep Inline Narration in NPC Context</span>
                    </label>
                    <span class="hint">Allow later NPC prompts to see narrator-routed scene descriptions.</span>

                    <label for="welcome_cooldown">Welcome Cooldown (minutes)</label>
                    <input type="number" min="1" max="1440" id="welcome_cooldown" name="welcome_cooldown" value="<?= htmlspecialchars(strval($welcomeCooldown), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Minimum time between welcome narrations. Range: 1-1440, default: 10.</span>

                    <label for="random_chance">Random Narration Chance (%)</label>
                    <input type="number" min="1" max="100" id="random_chance" name="random_chance" value="<?= htmlspecialchars(strval($randomChance), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Probability (1-100) of a random narrator interjection. Default: 15%.</span>

                    <label for="random_cooldown">Random Narration Cooldown (events)</label>
                    <input type="number" min="0" max="30" id="random_cooldown" name="random_cooldown" value="<?= htmlspecialchars(strval($randomCooldown), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Minimum events between random narrations. Range: 0-30, default: 10.</span>
                </div>
            </div>

            <div class="content-grid">
                <div class="content-section">
                    <h2>Profile & Voice</h2>

                    <label for="profile_id">Profile</label>
                    <select id="profile_id" name="profile_id">
                        <?php foreach ($profiles as $profile): ?>
                            <?php $pid = intval($profile['id'] ?? 0); ?>
                            <?php if ($pid <= 0) continue; ?>
                            <option value="<?= htmlspecialchars(strval($pid), ENT_QUOTES, 'UTF-8') ?>" <?= $profileId === $pid ? 'selected' : '' ?>>
                                <?= htmlspecialchars(strval($profile['label'] ?? ('Profile ' . $pid)), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">LLM profile used by The Narrator.</span>

                    <label for="voiceid">Voice ID</label>
                    <input type="text" id="voiceid" name="voiceid" value="<?= htmlspecialchars($voiceid, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Narrator TTS voice identifier.</span>

                    <label for="tts_filter_preset">Voice Filter</label>
                    <?php stobeUiRenderVoiceFilterField([
                        'select_id' => 'tts_filter_preset',
                        'select_name' => 'tts_filter_preset',
                        'selected' => $narratorTtsFilterPreset,
                        'web_root' => $webRoot,
                        'hint' => 'Effect applied to everything The Narrator speaks. Presets are fixed and cannot be edited.',
                        'hint_tag' => 'span',
                        'hint_class' => 'hint',
                        'play_title' => 'Play a sample of the narrator voice with this filter',
                        'profile_select_id' => 'profile_id',
                        'voice_input_id' => 'voiceid',
                    ]); ?>

                    <label for="oghma_knowledge">World Knowledge Tags</label>
                    <input type="text" id="oghma_knowledge" name="oghma_knowledge" value="<?= htmlspecialchars($oghmaKnowledge, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">Knowledge tags used by narrator context systems.</span>
                </div>

                <div class="content-section">
                    <h2>Prompt Head Override</h2>
                    <label for="prompt_head">Custom Prompt Head</label>
                    <textarea id="prompt_head" name="prompt_head" rows="5"><?= htmlspecialchars($promptHead, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <span class="hint">Overrides profile/global prompt head while narrator is active.</span>
                </div>
            </div>

            <div class="content-section full-width-section">
                <h2>Character Description</h2>

                <div class="dynamic-profile-card">
                    <h3>Dynamic Profile Updates</h3>

                    <label class="toggle-row">
                        <div class="toggle-switch">
                            <input type="checkbox" id="dynamic_profile" name="dynamic_profile" value="1" <?= $dynamicProfile ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </div>
                        <span class="toggle-label">Enable Dynamic Profile</span>
                    </label>
                    <span class="hint">Allow narrator profile fields to evolve over time.</span>

                    <?php dps_render_controls($narrator->getAll(), true); ?>
                    <label class="field-selection-label">Fields to update</label>
                    <span class="hint">Select which fields dynamic profile can update:</span>
                    <div class="field-chips">
                        <label class="field-chip">
                            <input type="checkbox" name="dynamic_profile_fields[]" value="personality" <?= in_array('personality', $dynamicProfileFields, true) ? 'checked' : '' ?>>
                            <span class="chip-text">Personality</span>
                        </label>
                        <label class="field-chip">
                            <input type="checkbox" name="dynamic_profile_fields[]" value="speechstyle" <?= in_array('speechstyle', $dynamicProfileFields, true) ? 'checked' : '' ?>>
                            <span class="chip-text">Speech Style</span>
                        </label>
                        <label class="field-chip">
                            <input type="checkbox" name="dynamic_profile_fields[]" value="goals" <?= in_array('goals', $dynamicProfileFields, true) ? 'checked' : '' ?>>
                            <span class="chip-text">Goals</span>
                        </label>
                    </div>
                    <span class="hint">Recommended: choose 1-3 fields.</span>
                </div>

                <label for="core">Core Summary</label>
                <textarea id="core" name="core" rows="3"><?= htmlspecialchars($core, ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="hint">Short summary of narrator role/persona.</span>

                <label for="background">Background</label>
                <textarea id="background" name="background" rows="4"><?= htmlspecialchars($background, ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="hint">Narrator background and framing details.</span>

                <label for="personality">Personality</label>
                <textarea id="personality" name="personality" rows="3"><?= htmlspecialchars($personality, ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="hint">Narrator personality traits and tone.</span>

                <label for="speechstyle">Speech Style</label>
                <textarea id="speechstyle" name="speechstyle" rows="2"><?= htmlspecialchars($speechstyle, ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="hint">How the narrator should speak.</span>

                <label for="goals">Goals</label>
                <textarea id="goals" name="goals" rows="3"><?= htmlspecialchars($goals, ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="hint">Narrator objectives and role focus.</span>
            </div>
        </form>
    </div>
</main>

<?php
if (!$isEmbed) {
    include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/footer.html");
    $buffer = ob_get_contents();
    ob_end_clean();
    $title = $TITLE;
    $buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
    echo $buffer;
}
?>
