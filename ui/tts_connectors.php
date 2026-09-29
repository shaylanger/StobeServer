<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}

function h(mixed $value): string { return htmlspecialchars(strval($value), ENT_QUOTES, "UTF-8"); }
function ttsBool(mixed $v): bool { if (is_bool($v)) return $v; return in_array(strtolower(trim(strval($v))), ['1','true','yes','on'], true); }
function ttsCfg(mixed $raw): array { if (is_array($raw)) return $raw; $d = json_decode(strval($raw), true); return is_array($d) ? $d : []; }
function ttsService(string $raw): string { return stobeNormalizeTtsConnectorTypeForStorage($raw); }
function ttsSpecs(): array {
    return [
        'pocket_tts' => ['label' => 'Pocket TTS', 'local' => true],
        'xtts' => ['label' => 'XTTS', 'local' => true],
        'chatterbox' => ['label' => 'Chatterbox', 'local' => true],
        'higgs' => ['label' => 'Higgs TTS 3', 'local' => true],
        'cartesia' => ['label' => 'Cartesia', 'local' => false],
        'inworld' => ['label' => 'Inworld', 'local' => false],
        'omnivoice' => ['label' => 'OmniVoice', 'local' => true],
    ];
}
function ttsDefaultUrl(string $service): string { return match ($service) { 'omnivoice' => 'http://127.0.0.1:8021', 'higgs' => 'http://127.0.0.1:8025', 'chatterbox' => 'http://127.0.0.1:8023', 'pocket_tts' => 'http://127.0.0.1:8024', 'xtts' => 'http://127.0.0.1:8020', default => '' }; }
function ttsOmniVoiceLanguageLabel(string $languageId): string {
    static $fallbackLabels = ['cs'=>'Czech','en'=>'English','es'=>'Spanish','ro'=>'Romanian','ru'=>'Russian','sk'=>'Slovak'];
    $languageId = strtolower(trim($languageId));
    if ($languageId === '') return '';
    $profilePath = '/home/dwemer/omnivoice-tts/languages/' . $languageId . '.json';
    if (is_file($profilePath) && is_readable($profilePath)) {
        $profile = json_decode(strval(@file_get_contents($profilePath)), true);
        if (is_array($profile)) {
            $label = trim(strval($profile['display_name'] ?? $profile['omnivoice_language'] ?? ''));
            if ($label !== '') return $label;
        }
    }
    return $fallbackLabels[$languageId] ?? strtoupper($languageId);
}
function ttsOmniVoicePreparedLanguages(): array {
    $profilesPath = '/home/dwemer/omnivoice-tts/languages';
    $voicesPath = '/home/dwemer/omnivoice-tts/voices';
    if (!is_dir($profilesPath) || !is_readable($profilesPath)) return [];
    $entries = @scandir($profilesPath);
    if (!is_array($entries)) return [];
    $options = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || substr($entry, -5) !== '.json') continue;
        $profilePath = $profilesPath . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($profilePath) || !is_readable($profilePath)) continue;
        $rawProfile = strval(@file_get_contents($profilePath));
        if ($rawProfile === '' || stripos($rawProfile, 'REPLACE THIS') !== false) continue;
        $profile = json_decode($rawProfile, true);
        if (!is_array($profile)) continue;
        $languageId = strtolower(trim(strval($profile['id'] ?? basename($entry, '.json'))));
        if ($languageId === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $languageId)) continue;
        $voiceCount = 0;
        $totalVoiceFolders = 0;
        $languagePath = $voicesPath . DIRECTORY_SEPARATOR . $languageId;
        $voiceEntries = @scandir($languagePath);
        if (is_array($voiceEntries)) {
            foreach ($voiceEntries as $voiceEntry) {
                if ($voiceEntry === '.' || $voiceEntry === '..') continue;
                $voicePath = $languagePath . DIRECTORY_SEPARATOR . $voiceEntry;
                if (is_dir($voicePath)) {
                    $totalVoiceFolders++;
                    if (is_file($voicePath . DIRECTORY_SEPARATOR . 'reference.wav')
                        && is_file($voicePath . DIRECTORY_SEPARATOR . 'reference.txt')) {
                        $voiceCount++;
                    }
                }
            }
        }
        $options[$languageId] = [
            'id' => $languageId,
            'label' => trim(strval($profile['display_name'] ?? $profile['omnivoice_language'] ?? '')) ?: ttsOmniVoiceLanguageLabel($languageId),
            'voice_count' => $voiceCount,
            'total_voice_folders' => $totalVoiceFolders,
        ];
    }
    uasort($options, fn($a, $b) => strcasecmp(strval($a['label'] ?? ''), strval($b['label'] ?? '')));
    return array_values($options);
}
function ttsOmniVoiceEnsureLanguage(string $endpoint, string $language, string $scope, array $voices = []): array {
    $endpoint = rtrim(trim($endpoint), '/');
    $language = strtolower(trim($language));
    if ($endpoint === '' || $language === '') return ['ok'=>false,'status'=>'skipped','error'=>'OmniVoice endpoint or language is empty.'];
    $payload = [
        'language' => $language,
        'scope' => $scope,
        'voices' => array_values(array_filter(array_map('strval', $voices), fn($voice) => trim($voice) !== '')),
        'make_active' => true,
        'start' => true,
    ];
    $ch = curl_init($endpoint . '/ensure_language');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false || $curlError !== '') return ['ok'=>false,'status'=>'unreachable','error'=>$curlError ?: 'Unable to reach OmniVoice.'];
    $decoded = json_decode(strval($response), true);
    if (!is_array($decoded)) return ['ok'=>false,'status'=>'bad_response','error'=>'OmniVoice returned a non-JSON response.','http_code'=>$httpCode];
    $decoded['http_code'] = $httpCode;
    return $decoded;
}
function ttsOmniVoiceEnsureNotice(array $result, string $language): string {
    if (!($result['ok'] ?? false)) return 'Connector saved. OmniVoice setup could not be started for ' . $language . ': ' . strval($result['error'] ?? 'unknown error');
    $status = strtolower(strval($result['status'] ?? ''));
    if ($status === 'ready') return 'Connector saved. OmniVoice ' . $language . ' is ready.';
    if (in_array($status, ['building','queued','running'], true)) return 'Connector saved. OmniVoice ' . $language . ' preparation started in the background.';
    return 'Connector saved. OmniVoice ' . $language . ' status: ' . ($status !== '' ? $status : 'unknown') . '.';
}
function ttsDefaultConfig(string $service): array {
    return [
        'provider' => $service,
        'language' => ($service === 'inworld') ? 'EN_US' : ($service === 'omnivoice' ? '' : 'en'),
        'fallback_male' => $service === 'omnivoice' ? 'default_male' : 'male1',
        'fallback_female' => $service === 'omnivoice' ? 'default_female' : 'female1',
        'api_badge_id' => 0,
        'model_id' => ($service === 'cartesia') ? 'sonic-3' : (($service === 'inworld') ? 'inworld-tts-1' : ''),
        'workspace' => '',
        'stream_chunk_size' => 20,
        'temperature' => ($service === 'inworld') ? 1.1 : 0.9,
        'speed' => 1.0,
        'length_penalty' => 1.0,
        'repetition_penalty' => 5.0,
        'top_p' => 0.85,
        'top_k' => 50,
    ];
}
function ttsNormalizeRow(array $row): array {
    $config = ttsCfg($row['config'] ?? '{}');
    $service = ttsService(strval($row['connector_type'] ?? 'pocket_tts'));
    if (isset($config['provider']) && trim(strval($config['provider'])) !== '') $service = ttsService(strval($config['provider']));
    $cfg = ttsDefaultConfig($service);
    foreach ($config as $k => $v) $cfg[$k] = $v;
    $legacyVoiceId = trim(strval($config['voiceid'] ?? ''));
    if (trim(strval($cfg['fallback_male'] ?? '')) === '') $cfg['fallback_male'] = $legacyVoiceId !== '' ? $legacyVoiceId : 'male1';
    if (trim(strval($cfg['fallback_female'] ?? '')) === '') $cfg['fallback_female'] = $legacyVoiceId !== '' ? $legacyVoiceId : 'female1';
    $cfg['api_badge_id'] = intval($cfg['api_badge_id'] ?? 0);
    return [
        'id' => intval($row['id'] ?? 0),
        'label' => strval($row['name'] ?? ''),
        'service' => $service,
        'url' => trim(strval($row['base_url'] ?? ttsDefaultUrl($service))),
        'is_default' => ttsBool($row['is_default'] ?? false),
        'config' => $cfg,
    ];
}
function ttsUniqueLabel(string $base, int $excludeId = 0): string {
    $rows = getAllTtsConnectors();
    $set = [];
    foreach ($rows as $r) { $id = intval($r['id'] ?? 0); if ($excludeId > 0 && $id === $excludeId) continue; $set[strtolower(trim(strval($r['name'] ?? '')))] = true; }
    $candidate = trim($base) !== '' ? trim($base) : 'TTS Connector';
    if (!isset($set[strtolower($candidate)])) return $candidate;
    for ($i=2;$i<5000;$i++) { $try = $candidate . ' ' . $i; if (!isset($set[strtolower($try)])) return $try; }
    return $candidate . ' ' . time();
}
function ttsBuildFields(array $payload, ?array $existing): array {
    $service = ttsService(strval($payload['service'] ?? ($existing['connector_type'] ?? 'pocket_tts')));
    $cfg = ttsDefaultConfig($service);
    foreach (ttsCfg($existing['config'] ?? '{}') as $k => $v) $cfg[$k] = $v;
    $cfg['provider'] = $service;
    foreach (['language','fallback_male','fallback_female','model_id','workspace','accent'] as $k) if (array_key_exists($k, $payload)) $cfg[$k] = trim(strval($payload[$k]));
    foreach (['stream_chunk_size','top_k'] as $k) if (array_key_exists($k, $payload) && trim(strval($payload[$k])) !== '') $cfg[$k] = intval($payload[$k]);
    foreach (['temperature','speed','length_penalty','repetition_penalty','top_p'] as $k) if (array_key_exists($k, $payload) && trim(strval($payload[$k])) !== '') $cfg[$k] = floatval($payload[$k]);
    $cfg['api_badge_id'] = intval($payload['api_badge_id'] ?? ($cfg['api_badge_id'] ?? 0));
    unset($cfg['voiceid']);

    $fields = [
        'name' => trim(strval($payload['label'] ?? ($existing['name'] ?? ''))),
        'connector_type' => $service,
        'base_url' => trim(strval($payload['url'] ?? ($existing['base_url'] ?? ttsDefaultUrl($service)))),
        'is_default' => ttsBool($payload['is_default'] ?? ($existing['is_default'] ?? false)),
        'config' => $cfg,
    ];
    if ($fields['base_url'] === '' && in_array($service, ['pocket_tts','xtts','chatterbox','omnivoice','higgs'], true)) $fields['base_url'] = ttsDefaultUrl($service);
    if ($existing && isset($existing['id'])) $fields['id'] = intval($existing['id']);
    return $fields;
}

class TTSConnector {
    public function all(): array { $out = []; foreach (getAllTtsConnectors() as $r) $out[] = ttsNormalizeRow($r); return $out; }
    public function byId(int $id): array|false { $r = getTtsConnectorById($id); return $r ? ttsNormalizeRow($r) : false; }
    public function create(array $payload): int { return saveTtsConnector(ttsBuildFields($payload, null)); }
    public function update(int $id, array $payload): int { $existing = getTtsConnectorById($id); if (!$existing) return 0; return saveTtsConnector(ttsBuildFields($payload, $existing)); }
    public function delete(int $id): void { deleteTtsConnector($id); }
    public function clone(int $id): int {
        $existing = getTtsConnectorById($id); if (!$existing) return 0;
        $fields = ttsBuildFields([], $existing); unset($fields['id']); $fields['name'] = ttsUniqueLabel(strval($existing['name'] ?? 'TTS Connector') . ' Copy'); $fields['is_default'] = false;
        return saveTtsConnector($fields);
    }
}

$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$uiPos = strpos($scriptPath, '/ui/');
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : '';
if ($webRoot === '/') $webRoot = '';
$webRoot = rtrim($webRoot, '/');
$isEmbed = isset($_GET['embed']) && strval($_GET['embed']) === '1';
$withEmbed = function(string $url) use ($isEmbed): string { return $isEmbed ? ($url . (strpos($url,'?')===false ? '?embed=1' : '&embed=1')) : $url; };

$svc = new TTSConnector();

if (isset($_GET['export'])) {
    $id = intval($_GET['export']);
    $row = $svc->byId($id);
    if (!$row) { http_response_code(404); echo 'Not found'; exit; }
    $filename = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower(strval($row['label'] ?: ('tts_' . $id))));
    if (!is_string($filename) || $filename === '') $filename = 'tts_connector_' . $id;
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['create_blank'])) {
    $newId = $svc->create(['label' => ttsUniqueLabel('New TTS Connector'), 'service' => 'pocket_tts', 'url' => ttsDefaultUrl('pocket_tts')]);
    header('Location: ' . $withEmbed('tts_connectors.php?edit=' . intval($newId))); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save']) || isset($_POST['create']))) {
    $payload = [
        'label' => trim(strval($_POST['label'] ?? '')),
        'service' => strval($_POST['service'] ?? 'pocket_tts'),
        'url' => strval($_POST['url'] ?? ''),
        'api_badge_id' => strval($_POST['api_badge_id'] ?? ''),
        'language' => strval($_POST['language'] ?? ''),
        'fallback_male' => strval($_POST['fallback_male'] ?? ''),
        'fallback_female' => strval($_POST['fallback_female'] ?? ''),
        'model_id' => strval($_POST['model_id'] ?? ''),
        'accent' => strval($_POST['accent'] ?? ''),
        'workspace' => strval($_POST['workspace'] ?? ''),
        'stream_chunk_size' => strval($_POST['stream_chunk_size'] ?? ''),
        'temperature' => strval($_POST['temperature'] ?? ''),
        'speed' => strval($_POST['speed'] ?? ''),
        'length_penalty' => strval($_POST['length_penalty'] ?? ''),
        'repetition_penalty' => strval($_POST['repetition_penalty'] ?? ''),
        'top_p' => strval($_POST['top_p'] ?? ''),
        'top_k' => strval($_POST['top_k'] ?? ''),
    ];
    $payload['label'] = ttsUniqueLabel($payload['label'] === '' ? 'TTS Connector' : $payload['label'], intval($_POST['id'] ?? 0));
    $savedId = 0;
    if (isset($_POST['save']) && intval($_POST['id'] ?? 0) > 0) $savedId = $svc->update(intval($_POST['id']), $payload);
    else $savedId = $svc->create($payload);
    $notice = '';
    if (ttsService($payload['service']) === 'omnivoice') {
        $endpoint = trim(strval($payload['url'] ?? '')) !== '' ? trim(strval($payload['url'])) : ttsDefaultUrl('omnivoice');
        $language = strtolower(trim(strval($payload['language'] ?? '')));
        if ($language !== '') {
            $ensure = ttsOmniVoiceEnsureLanguage($endpoint, $language, 'voice_set', [
                strval($payload['fallback_male'] ?? ''),
                strval($payload['fallback_female'] ?? ''),
            ]);
            $notice = ttsOmniVoiceEnsureNotice($ensure, $language);
        }
    }
    $target = 'tts_connectors.php?edit=' . intval($savedId);
    if ($notice !== '') $target .= '&notice=' . urlencode($notice);
    header('Location: ' . $withEmbed($target)); exit;
}
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $inUse = $GLOBALS['db']->fetchOne("SELECT COUNT(*) AS c FROM core_profiles WHERE tts_connector_id = $1", [$id]);
    $count = intval($inUse['c'] ?? 0);
    if ($count > 0) { header('Location: ' . $withEmbed('tts_connectors.php?notice=' . urlencode('Cannot delete: connector is used by ' . $count . ' profile' . ($count>1?'s':'') . '.'))); exit; }
    $svc->delete($id); header('Location: ' . $withEmbed('tts_connectors.php')); exit;
}
if (isset($_GET['clone'])) { $svc->clone(intval($_GET['clone'])); header('Location: ' . $withEmbed('tts_connectors.php')); exit; }

$data = $svc->all();
$editItem = isset($_GET['edit']) ? $svc->byId(intval($_GET['edit'])) : false;
$cfg = is_array($editItem) ? ($editItem['config'] ?? []) : [];
$apiRows = $GLOBALS['db']->fetchAll("SELECT id, label, api_key FROM core_api_badge ORDER BY label ASC");
$specs = ttsSpecs();

$TITLE = "ðŸ“¢ STOBE - TTS Connectors";
ob_start();
include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/head.html");
?>
<link rel="stylesheet" href="<?php echo $webRoot; ?>/ui/css/main.css?v=<?= (int) @filemtime(__DIR__ . '/css/main.css') ?>">
<style>
main{padding:10px 5px 5px}.layout{display:grid;grid-template-columns:minmax(240px,340px) 1fr;gap:16px;align-items:start}.left-col{position:sticky;top:90px;height:calc(100vh - 120px);overflow:hidden;border:1px solid #3a3a3a;border-radius:10px;background:linear-gradient(180deg,rgba(42,42,42,.95),rgba(34,34,34,.98));padding:12px}.list-wrap{display:flex;flex-direction:column;gap:8px;overflow:auto;height:calc(100% - 52px)}.conn-li{border:1px solid #3a3a3a;border-radius:10px;padding:10px;background:rgba(26,26,26,.8);cursor:pointer}.conn-li.active{outline:2px solid #e6b76c}.conn-head{display:flex;justify-content:space-between;gap:8px;align-items:center}.conn-badge{font-size:11px;color:#9fb1c9;border:1px solid #4a4a4a;border-radius:999px;padding:2px 6px}.conn-sub{color:#9fb1c9;font-size:12px;margin-top:4px}.actions{display:flex;gap:6px;margin-top:8px;justify-content:flex-end}.form-container{border:1px solid #3a3a3a;border-radius:10px;background:linear-gradient(180deg,rgba(42,42,42,.95),rgba(34,34,34,.98));padding:14px}.btn-save,.btn-danger,.btn-primary{padding:10px 14px;color:#fff;border-radius:8px;border:1px solid rgba(138,155,182,.35);background:#2f3b52;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-save{background:#176529;border-color:#2b7d3d}.btn-danger{background:#8a1a1a;border-color:#992c2c}.btn-primary{background:#204e7a;border-color:rgba(138,155,182,.4)}label{color:#e6b76c;font-weight:600;margin-top:8px;margin-bottom:6px;display:block}input[type=text],input[type=number],input[type=password],select,textarea{width:100%;box-sizing:border-box;background:rgba(26,26,26,.8);color:#e9efff;border:1px solid #3a3a3a;border-radius:6px;padding:10px 12px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}.help{color:#9fb1c9;font-size:12px;margin-top:4px}.service-picker{display:flex;gap:8px;flex-wrap:wrap}.service-btn{padding:8px 10px;border:1px solid #4a4a4a;border-radius:8px;background:#1f2736;color:#e9efff;cursor:pointer}.service-btn.active{outline:2px solid #e6b76c}.placeholder{border:1px solid #3a3a3a;border-radius:10px;background:rgba(26,26,26,.7);color:#9fb1c9;padding:18px}#tts_test_modal{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;z-index:9999;align-items:center;justify-content:center}#tts_test_modal .inner{width:min(1100px,94vw);height:min(820px,92vh);background:#111827;border:1px solid #3a3a3a;border-radius:10px;position:relative;overflow:hidden}#tts_test_modal iframe{width:100%;height:100%;border:0}#tts_test_modal .close{position:absolute;top:8px;right:10px;z-index:2}@media (max-width:980px){.layout{grid-template-columns:1fr}.left-col{position:relative;top:auto;height:auto}.list-wrap{height:auto;max-height:420px}.grid2{grid-template-columns:1fr}}
</style>
<style>
/* Page header is the shared compact inline row (.stobe-page-head in main.css). */
</style>
<main class="d-flex flex-column">
<div class="page-header stobe-page-head"><h1 class="api-title stobe-page-head-title">TTS Connectors</h1><p class="page-subtitle stobe-page-head-note">Configure text-to-speech connectors for AI dialogue generation</p></div>
<?php if (isset($_GET['notice']) && $_GET['notice'] !== ''): ?><div class="help" style="color:#ffb862;margin-bottom:10px;"><?= h($_GET['notice']) ?></div><?php endif; ?>
<div class="layout">
<div class="left-col">
<div class="sidebar-action-grid single-action"><form method="get" action="<?= h($withEmbed('tts_connectors.php')) ?>"><input type="hidden" name="create_blank" value="1"><button type="submit" class="btn-save">New</button></form></div>
<div class="list-wrap">
<?php foreach ($data as $row): $active = ($editItem && intval($editItem['id'])===intval($row['id'])) ? ' active' : ''; ?>
<div class="conn-li<?= $active ?>" data-edit-id="<?= h($row['id']) ?>">
<div class="conn-head"><div style="font-weight:600;"><?= h($row['label'] ?: ('Connector #'.$row['id'])) ?></div><div class="conn-badge"><?= h($specs[$row['service']]['label'] ?? $row['service']) ?></div></div>
<div class="conn-sub"><?= h($row['url']) ?></div>
<div class="actions">
<form method="get" action="<?= h($withEmbed('tts_connectors.php')) ?>" onsubmit="return confirm('Delete this connector?');"><input type="hidden" name="delete" value="<?= h($row['id']) ?>"><button type="submit" class="btn-danger">Delete</button></form>
<form method="get" action="<?= h($withEmbed('tts_connectors.php')) ?>"><input type="hidden" name="clone" value="<?= h($row['id']) ?>"><button type="submit" class="btn-primary">Clone</button></form>
</div></div>
<?php endforeach; ?>
</div></div>
<div class="right-col"><div class="form-container">
<?php if (!$editItem): ?><div class="placeholder"><div style="font-weight:600;color:#e9efff;margin-bottom:6px;">No connector selected</div><div>Select a TTS connector from the list on the left to edit.</div></div><?php else: ?>
<form id="tts_editor_form" method="post" action="<?= h($withEmbed('tts_connectors.php')) ?>">
<input type="hidden" name="id" value="<?= h($editItem['id']) ?>">
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;"><button type="submit" name="save" class="btn-save">Save</button><button type="button" id="btn_test_connector" class="btn-primary">Test</button><button type="submit" formmethod="get" formaction="<?= h($withEmbed('tts_connectors.php')) ?>" name="export" value="<?= h($editItem['id']) ?>" class="btn-primary">Export</button><div class="help">Save before testing to use latest settings.</div></div>
<div class="grid2" id="row_name_badge">
<div>
<label for="label">Name</label>
<input id="label" type="text" name="label" value="<?= h($editItem['label']) ?>">
<div class="help">Display name used in profile selection and admin tools.</div>
</div>
<div id="row_api_badge">
<label for="api_badge_id">API Key Badge</label>
<select id="api_badge_id" name="api_badge_id"><option value="">-- None --</option><?php foreach ($apiRows as $api): $id=intval($api['id']??0); $sel=intval($cfg['api_badge_id']??0)===$id?'selected':''; $has=trim(strval($api['api_key']??''))!==''; ?><option value="<?= h($id) ?>" <?= $sel ?>><?= h(strval($api['label'] ?? ('Badge #'.$id)).($has?' [Configured]':' [Missing Key]')) ?></option><?php endforeach; ?></select>
<div class="help">Used for Cartesia, Inworld, and Chatterbox TTS connectors.</div>
</div>
</div>

<div id="row_workspace_auth">
<label for="workspace">Workspace ID (Inworld)</label>
<input id="workspace" type="text" name="workspace" value="<?= h(strval($cfg['workspace'] ?? '')) ?>">
<div class="help">Inworld workspace/project identifier used for voice synthesis routing.</div>
</div>

<div id="row_url">
<label for="url">URL</label>
<input id="url" type="text" name="url" value="<?= h($editItem['url']) ?>">
<div class="help">Base endpoint for this TTS provider. DwemerDistro uses XTTS `8020`, OmniVoice `8021`, Chatterbox `8023`, Python PocketTTS `8024`, and PocketTTS audio.cpp `8086`.</div>
</div>

<label>Provider</label>
<input type="hidden" id="service" name="service" value="<?= h($editItem['service']) ?>">
<div class="service-picker"><?php foreach ($specs as $k=>$spec): ?><button type="button" class="service-btn<?= $editItem['service']===$k ? ' active' : '' ?>" data-service="<?= h($k) ?>"><?= h($spec['label']) ?></button><?php endforeach; ?></div>
<div class="help">Select the connector driver used to build request payloads and handle responses.</div>

<div class="grid2">
<div>
<label for="language">Language</label>
<?php if (($editItem['service'] ?? '') === 'omnivoice' && !empty(ttsOmniVoicePreparedLanguages())): ?>
<?php
    $omniLanguages = ttsOmniVoicePreparedLanguages();
    $currentOmniLanguage = strtolower(trim(strval($cfg['language'] ?? '')));
    $preparedLanguageIds = array_map(fn($option) => strval($option['id'] ?? ''), $omniLanguages);
    $currentLanguagePrepared = $currentOmniLanguage !== '' && in_array($currentOmniLanguage, $preparedLanguageIds, true);
    $selectedOmniLanguage = $currentLanguagePrepared ? $currentOmniLanguage : strval($omniLanguages[0]['id'] ?? '');
?>
<select id="language" name="language">
<?php foreach ($omniLanguages as $languageOption): ?>
<?php
    $languageId = strval($languageOption['id'] ?? '');
    $languageLabel = strval($languageOption['label'] ?? strtoupper($languageId));
    $optionLabel = $languageLabel . ' (' . $languageId . ')';
?>
<option value="<?= h($languageId) ?>" <?= $selectedOmniLanguage === $languageId ? 'selected' : '' ?>><?= h($optionLabel) ?></option>
<?php endforeach; ?>
</select>
<?php if ($currentOmniLanguage !== '' && !$currentLanguagePrepared): ?>
<div class="help">Saved language <?= h($currentOmniLanguage) ?> is not available as an OmniVoice profile.</div>
<?php else: ?>
<div class="help">Saving this connector will prepare the selected language automatically if needed.</div>
<?php endif; ?>
<?php else: ?>
<input id="language" type="text" name="language" value="<?= h(strval($cfg['language'] ?? 'en')) ?>">
<div class="help"><?= ($editItem['service'] ?? '') === 'omnivoice' ? 'No OmniVoice language profiles were found in /home/dwemer/omnivoice-tts/languages.' : 'Locale/language code sent to provider (for example `en`, `EN_US`).' ?></div>
<?php endif; ?>
</div>
<div>
<label for="fallback_male">Fallback Male Voice ID</label>
<input id="fallback_male" type="text" name="fallback_male" value="<?= h(strval($cfg['fallback_male'] ?? 'male1')) ?>">
<div class="help">Used when an NPC has no voice mapping and the actor resolves as male or non-female.</div>
</div>
<div>
<label for="fallback_female">Fallback Female Voice ID</label>
<input id="fallback_female" type="text" name="fallback_female" value="<?= h(strval($cfg['fallback_female'] ?? 'female1')) ?>">
<div class="help">Used when an NPC has no voice mapping and the actor resolves as female.</div>
</div>
</div>

<div id="row_model_workspace" class="grid2">
<div>
<label for="model_id">Model ID</label>
<input id="model_id" list="cartesia_models" type="text" name="model_id" value="<?= h(strval($cfg['model_id'] ?? '')) ?>">
<datalist id="cartesia_models">
<option value="sonic-3"><option value="sonic-3.5"><option value="sonic-3.6">
<option value="sonic-3.5-2026-05-04"><option value="sonic-3.6-2026-08-27">
</datalist>
<datalist id="inworld_models">
<option value="inworld-tts-2-flash">
</datalist>
<div class="help">Provider model, such as sonic-3.5, sonic-3.6, or inworld-tts-2-flash. Inworld Flash prioritizes speed. A dated snapshot pins a release.</div>
</div>
</div>

<div id="row_cartesia_accent" class="grid2">
<div>
<label for="accent">Accent</label>
<input id="accent" type="text" name="accent" value="<?= h(strval($cfg['accent'] ?? '')) ?>">
<div class="help">Optional, Sonic 3.6 only. For multilingual voices, enter an accent ID supported by the selected voice, such as general-american or standard-hindi. Leave blank for the voice default. <a href="https://docs.cartesia.ai/build-with-cartesia/capability-guides/multilingual-voices" target="_blank" rel="noopener">Cartesia accent guide</a></div>
</div>
</div>

<div id="row_local_tuning" class="grid2">
<div>
<label for="stream_chunk_size">Stream Chunk Size</label>
<input id="stream_chunk_size" type="number" step="1" name="stream_chunk_size" value="<?= h(strval($cfg['stream_chunk_size'] ?? 20)) ?>">
<div class="help">Chunk size used during local synthesis/stream splitting. Higher values mean larger chunks.</div>
</div>
<div>
<label for="temperature">Temperature</label>
<input id="temperature" type="number" step="0.01" name="temperature" value="<?= h(strval($cfg['temperature'] ?? 0.9)) ?>">
<div class="help">Randomness of generation. Lower = more deterministic, higher = more variation.</div>
</div>
<div>
<label for="speed">Speed</label>
<input id="speed" type="number" step="0.01" name="speed" value="<?= h(strval($cfg['speed'] ?? 1.0)) ?>">
<div class="help">Playback/synthesis speed multiplier. `1.0` is normal speed.</div>
</div>
<div>
<label for="length_penalty">Length Penalty</label>
<input id="length_penalty" type="number" step="0.01" name="length_penalty" value="<?= h(strval($cfg['length_penalty'] ?? 1.0)) ?>">
<div class="help">Bias toward shorter or longer outputs depending on provider support.</div>
</div>
<div>
<label for="repetition_penalty">Repetition Penalty</label>
<input id="repetition_penalty" type="number" step="0.01" name="repetition_penalty" value="<?= h(strval($cfg['repetition_penalty'] ?? 5.0)) ?>">
<div class="help">Penalizes repeated tokens. Increase to reduce repeated sounds/phrases.</div>
</div>
<div>
<label for="top_p">Top P</label>
<input id="top_p" type="number" step="0.01" name="top_p" value="<?= h(strval($cfg['top_p'] ?? 0.85)) ?>">
<div class="help">Nucleus sampling threshold. Lower values produce safer/more focused output.</div>
</div>
<div>
<label for="top_k">Top K</label>
<input id="top_k" type="number" step="1" name="top_k" value="<?= h(strval($cfg['top_k'] ?? 50)) ?>">
<div class="help">Top-k sampling cap. Restricts token choice to the highest-ranked candidates.</div>
</div>
</div>

</form>
<?php endif; ?>
</div></div>
</div>
<div id="tts_test_modal"><div class="inner"><button type="button" class="btn-danger close" id="tts_test_close">Close</button><iframe id="tts_test_iframe" src="about:blank"></iframe></div></div>
</main>
<script>
(function(){
  const embedSuffix = <?= json_encode($isEmbed ? '&embed=1' : '', JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('.conn-li').forEach(function(el){ el.addEventListener('click', function(ev){ if (ev.target.closest('form') || ev.target.closest('button')) return; const id = el.getAttribute('data-edit-id'); if (id) window.location.href = 'tts_connectors.php?edit=' + encodeURIComponent(id) + embedSuffix; }); });
  const serviceInput = document.getElementById('service');
  const buttons = document.querySelectorAll('.service-btn[data-service]');
  const rowNameBadge = document.getElementById('row_name_badge');
  const rowApiBadge = document.getElementById('row_api_badge');
  const rowWorkspaceAuth = document.getElementById('row_workspace_auth');
  const rowUrl = document.getElementById('row_url');
  const rowModel = document.getElementById('row_model_workspace');
  const rowAccent = document.getElementById('row_cartesia_accent');
  const modelInput = document.getElementById('model_id');
  const rowLocal = document.getElementById('row_local_tuning');
  function applyService(s){
    if (!serviceInput) return;
    serviceInput.value = s;
    buttons.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-service') === s); });
    const local = (s==='pocket_tts'||s==='xtts'||s==='chatterbox'||s==='omnivoice');
    const cloud = (s==='cartesia'||s==='inworld');
    const inworld = (s==='inworld');
    const showApiBadge = (s==='cartesia'||s==='inworld'||s==='chatterbox');
    if (rowLocal) rowLocal.style.display = local ? '' : 'none';
    if (rowModel) rowModel.style.display = cloud ? '' : 'none';
    if (rowAccent) rowAccent.style.display = s === 'cartesia' ? '' : 'none';
    if (modelInput) {
      if (s === 'cartesia') modelInput.setAttribute('list', 'cartesia_models');
      else if (inworld) modelInput.setAttribute('list', 'inworld_models');
      else modelInput.removeAttribute('list');
    }
    if (rowApiBadge) rowApiBadge.style.display = showApiBadge ? '' : 'none';
    if (rowNameBadge) rowNameBadge.style.gridTemplateColumns = showApiBadge ? '1fr 1fr' : '1fr';
    if (rowWorkspaceAuth) rowWorkspaceAuth.style.display = inworld ? '' : 'none';
    if (rowUrl) rowUrl.style.display = local ? '' : 'none';
  }
  buttons.forEach(function(b){ b.addEventListener('click', function(){ applyService(b.getAttribute('data-service')); }); });
  if (serviceInput) applyService(serviceInput.value || 'pocket_tts');
  const testBtn = document.getElementById('btn_test_connector');
  const modal = document.getElementById('tts_test_modal');
  const iframe = document.getElementById('tts_test_iframe');
  const closeBtn = document.getElementById('tts_test_close');
  if (testBtn && modal && iframe) {
    testBtn.addEventListener('click', function(){
      const idInput = document.querySelector('input[name="id"]');
      const connectorId = idInput ? idInput.value : '';
      if (!connectorId) { alert('Save connector first, then run test.'); return; }
      const prompt = 'The great father Chitrin was betrayed by his children; broken by their sins and their lack of faith.';
      const fallbackMaleInput = document.getElementById('fallback_male');
      const voiceid = fallbackMaleInput && fallbackMaleInput.value.trim() !== '' ? fallbackMaleInput.value.trim() : 'male1';
      const base = <?= json_encode(($webRoot !== '' ? $webRoot : '') . '/ui/ttstest.php', JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
      iframe.src = base + '?connector_id=' + encodeURIComponent(connectorId) + '&prompt=' + encodeURIComponent(prompt) + '&voiceid=' + encodeURIComponent(voiceid);
      modal.style.display = 'flex';
    });
  }
  if (closeBtn && modal && iframe) closeBtn.addEventListener('click', function(){ modal.style.display='none'; iframe.src='about:blank'; });
})();
</script>
<?php
include(__DIR__ . DIRECTORY_SEPARATOR . "../tmpl/footer.html");
$buffer = ob_get_contents();
ob_end_clean();
echo $buffer;

