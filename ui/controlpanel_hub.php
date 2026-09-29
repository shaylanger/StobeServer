<?php
/**
 * StobeServer Control Panel Hub.
 * Config-hub style tab shell for control panel pages.
 */

$path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "lib/bootstrap.php");

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
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
$distroDashboardRoot = preg_replace('#/(HerikaServer|StobeServer)$#', '/Dwemer-Dashboard', $webRoot);
if (!is_string($distroDashboardRoot) || trim($distroDashboardRoot) === '' || $distroDashboardRoot === $webRoot) {
    $distroDashboardRoot = '/Dwemer-Dashboard';
}
$distroDebuggerStobeEmbedUrl = rtrim($distroDashboardRoot, '/') . '/distro_debugger.php?embed=1&tab=stobe';
$distroDatabaseManagerUrl = rtrim($distroDashboardRoot, '/') . '/database_manager.php';

// One entry point: playthroughs, storage and database tools now share a single page.
$distroDataManagerFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Dwemer-Dashboard'
    . DIRECTORY_SEPARATOR . 'data_manager.php';
$distroDataManagerAvailable = is_file($distroDataManagerFile) && is_file(dirname($distroDataManagerFile) . '/lib/storage_fragment.php');
$storageTabUrl = $distroDataManagerAvailable
    ? rtrim($distroDashboardRoot, '/') . '/data_manager.php?mod=stobe&view=manage'
    : '';

function buildTabTargetSrc(array $tab, string $webRoot): string
{
    $directUrl = trim(strval($tab['url'] ?? ''));
    if ($directUrl !== '') {
        return $directUrl;
    }
    $page = trim(strval($tab['page'] ?? ''));
    if ($page === '') {
        return '';
    }
    $src = ($webRoot !== '' ? $webRoot : '') . '/ui/' . ltrim($page, '/');
    if (!empty($tab['embed'])) {
        $src .= '?embed=1';
    }
    return $src;
}

$tabs = [
    ['id' => 'server_logs', 'group' => 'diagnostics', 'icon' => '&#x1F332;', 'label' => 'Server Logs', 'page' => '', 'url' => $distroDebuggerStobeEmbedUrl, 'status' => 'wired', 'embed' => false],
    ['id' => 'request_logs', 'group' => 'diagnostics', 'icon' => '&#x1F50D;', 'label' => 'Request Logs', 'page' => 'request_logs.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'world_knowledge_audit', 'group' => 'diagnostics', 'icon' => '&#x1F4D6;', 'label' => 'World Knowledge Audit', 'page' => 'world_knowledge_audit.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'relationship_logs', 'group' => 'diagnostics', 'icon' => '&#x1F517;', 'label' => 'Relationship Logs', 'page' => 'relationship_logs.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'cost_breakdown', 'group' => 'monitoring', 'icon' => '&#x1F4CA;', 'label' => 'Cost Breakdown', 'page' => 'audit.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'response_queue', 'group' => 'monitoring', 'icon' => '&#x1F4AC;', 'label' => 'Response Queue', 'page' => 'response_queue.php', 'status' => 'wired', 'embed' => true],
    ['id' => 'storage', 'group' => 'data-tools', 'icon' => '&#x1F5C4;&#xFE0F;', 'label' => 'Playthrough Management', 'page' => 'playthrough_manager.php', 'url' => $storageTabUrl, 'status' => 'wired', 'embed' => true],
    ['id' => 'audio_image_cache', 'group' => 'data-tools', 'icon' => '&#x1F3BC;', 'label' => 'Audio & Image Cache', 'page' => 'cache_browser.php', 'url' => '', 'status' => 'wired', 'embed' => true],
];
// Without the shared page installed, the legacy database tools keep their own tab.
if (!$distroDataManagerAvailable) {
    $tabs[] = ['id' => 'database_manager', 'group' => 'data-tools', 'icon' => '&#x1F5C3;&#xFE0F;', 'label' => 'Database Manager', 'page' => 'database_manager.php', 'status' => 'wired', 'embed' => true];
}

$tabGroups = [
    'diagnostics' => 'Diagnostics',
    'monitoring' => 'Monitoring',
    'data-tools' => 'Data & Tools',
];

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'server_logs')));
$tabMap = [];
foreach ($tabs as $tab) {
    $tabMap[$tab['id']] = $tab;
}
// Retired tab ids stay valid as deep links.
$tabAliases = [
    'playthough_manager' => 'storage',
    'playthrough_manager' => 'storage',
    'database_manager' => 'storage',
];
if (!isset($tabMap[$activeTab]) && isset($tabAliases[$activeTab])) {
    $activeTab = $tabAliases[$activeTab];
}
if (!isset($tabMap[$activeTab])) {
    $activeTab = 'server_logs';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Panel</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/hub-navigation.css?v=<?= filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'hub-navigation.css') ?>">
    <style>
        body {
            /* Match the fixed navbar height instead of a hard-coded 80px band. */
            padding-top: var(--hub-navbar-offset);
        }

        main {
            padding-top: 0;
            padding-bottom: 8px;
            padding-left: 10px;
            padding-right: 10px;
        }

        @font-face {
            font-family: "MagicCards";
            src: url("css/font/MailartRubberstamp-Regular.otf") format("opentype");
            font-weight: normal;
            font-style: normal;
        }

        h1, h3 {
            font-family: "MagicCards", sans-serif;
            letter-spacing: 1.5px;
        }

        .tab-container {
            margin: 0 0 6px;
        }

        .tab-buttons {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.2);
            gap: 5px;
            word-spacing: 5px;
        }

        .tab-button {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.8), rgba(34, 34, 34, 0.9));
            border: 2px solid #3a3a3a;
            border-bottom: none;
            padding: 12px 18px;
            color: #f8f9fa;
            cursor: pointer;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            transition: all 0.3s ease;
            font-size: 1em;
            white-space: nowrap;
            font-family: "MagicCards", sans-serif;
            word-spacing: 5px;
            letter-spacing: 1.5px;
            margin-bottom: -2px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            display: inline-block;
        }

        .tab-button:hover {
            background: linear-gradient(180deg, rgba(58, 58, 58, 0.9), rgba(48, 48, 48, 1));
            color: #e6b76c;
            border-color: rgba(230, 183, 108, 0.3);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-decoration: none;
        }

        .tab-button.active {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-color: rgba(230, 183, 108, 0.5);
            border-bottom: 2px solid rgba(42, 42, 42, 0.95);
            color: #e6b76c;
            box-shadow: 0 4px 8px rgba(230, 183, 108, 0.2);
        }

        .tab-content {
            display: none;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 10px 12px 12px;
            border-radius: 8px;
            border-top-left-radius: 0;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            min-height: 240px;
        }

        .tab-content.active {
            display: block;
        }

        .embed-wrap {
            width: 100%;
            min-height: calc(100vh - 200px);
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            overflow: hidden;
            background: #2a2a2a;
        }

        .embed {
            width: 100%;
            min-height: calc(100vh - 200px);
            border: 0;
            background: transparent;
        }

        .embed-wrap-light {
            background: #ffffff;
            border-color: #d7d7d7;
        }

        .embed-light {
            background: #ffffff;
        }

        .info-message {
            color: #9ca3af;
            padding: 18px 4px;
        }
    </style>
</head>
<body class="hub-page">
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>

<main class="container-fluid">
    <div class="tab-container">
        <div class="config-navigation" aria-label="Control Panel sections">
            <div class="tab-groups">
                <?php foreach ($tabGroups as $groupId => $groupLabel): ?>
                    <section class="tab-group <?= ($tabMap[$activeTab]['group'] ?? '') === $groupId ? 'active' : '' ?>" data-category="<?= h($groupId) ?>">
                        <div class="tab-group-label"><?= h($groupLabel) ?></div>
                        <div class="tab-buttons" role="tablist" aria-label="<?= h($groupLabel) ?> pages">
                            <?php foreach ($tabs as $tab): ?>
                                <?php if ($tab['group'] !== $groupId) continue; ?>
                                <button
                                    type="button"
                                    class="tab-button <?= $activeTab === $tab['id'] ? 'active' : '' ?>"
                                    data-tab="<?= h($tab['id']) ?>"
                                    data-category="<?= h($groupId) ?>"
                                ><span class="tab-icon" aria-hidden="true"><?= $tab['icon'] ?></span><span><?= h($tab['label']) ?></span></button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>

        <?php foreach ($tabs as $tab): ?>
            <?php
                $isActive = ($activeTab === $tab['id']);
                $targetSrc = ($tab['status'] === 'wired') ? buildTabTargetSrc($tab, $webRoot) : '';
                $hasPage = ($targetSrc !== '');
            ?>
            <div id="tab-<?= h($tab['id']) ?>" class="tab-content <?= $isActive ? 'active' : '' ?>">
                <?php if ($tab['id'] === 'storage' && $distroDataManagerAvailable) include __DIR__ . '/tmpl/playthrough_auto_switch.php'; ?>
                <?php if ($hasPage): ?>
                    <div class="embed-wrap">
                        <iframe
                            class="embed"
                            loading="<?= $isActive ? 'eager' : 'lazy' ?>"
                            src="<?= $isActive ? h($targetSrc) : 'about:blank' ?>"
                            data-src="<?= h($targetSrc) ?>"
                        ></iframe>
                    </div>
                <?php else: ?>
                    <div class="info-message">This tab is not wired yet in StobeServer UI.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<script>
(function(){
    const buttons = document.querySelectorAll('.tab-button');
    const groups = document.querySelectorAll('.tab-group');
    const tabs = document.querySelectorAll('.tab-content');

    function loadIframeFor(tabPane) {
        if (!tabPane) return;
        const iframe = tabPane.querySelector('iframe[data-src]');
        if (!iframe) return;
        if (iframe.src === 'about:blank') {
            iframe.src = iframe.getAttribute('data-src');
        }
    }

    function activate(tabId) {
        const selected = Array.from(buttons).find(function(btn){ return btn.dataset.tab === tabId; });
        const category = selected ? selected.dataset.category : '';
        groups.forEach(function(group){
            group.classList.toggle('active', group.dataset.category === category);
        });
        buttons.forEach(function(btn){
            btn.classList.toggle('active', btn.dataset.tab === tabId);
        });
        tabs.forEach(function(pane){
            const isActive = (pane.id === ('tab-' + tabId));
            pane.classList.toggle('active', isActive);
            if (isActive) {
                loadIframeFor(pane);
            }
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url.toString());
    }

    buttons.forEach(function(btn){
        btn.addEventListener('click', function(){
            activate(btn.dataset.tab);
        });
    });

    const active = document.querySelector('.tab-content.active');
    loadIframeFor(active);
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
