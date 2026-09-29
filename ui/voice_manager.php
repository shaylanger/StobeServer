<?php
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "tts_voice_management.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "tts_pronunciation_preview.php");
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}
$db = $GLOBALS["db"];

function h(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, "UTF-8");
}

function stobe_voice_trim(mixed $value): string
{
    return trim(strval($value));
}

function stobe_voice_normalize_voiceid(mixed $value): string
{
    $voiceid = strtolower(stobe_voice_trim($value));
    if ($voiceid === "") {
        return "";
    }
    if (strlen($voiceid) > 255) {
        $voiceid = substr($voiceid, 0, 255);
    }
    return $voiceid;
}

function stobe_voice_normalize_sample_file(mixed $value): string
{
    $name = basename(stobe_voice_trim($value));
    if ($name === "") {
        return "";
    }
    if (strlen($name) > 255) {
        $name = substr($name, 0, 255);
    }
    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
        return "";
    }
    return $name;
}

function stobe_voice_build_url(array $params = [], bool $embed = false, string $anchor = ""): string
{
    $base = basename($_SERVER["PHP_SELF"] ?? "voice_manager.php");
    if ($embed) {
        $params["embed"] = "1";
    }
    $qs = http_build_query($params);
    $url = $base . ($qs !== "" ? ("?" . $qs) : "");
    if ($anchor !== "") {
        $url .= "#" . ltrim($anchor, "#");
    }
    return $url;
}

function stobe_voice_store_upload(array $file, string $voiceid, string $voicesDir, string &$error): string
{
    $error = "";
    $uploadError = intval($file["error"] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return "";
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        $error = "Upload failed (error code " . strval($uploadError) . ").";
        return "";
    }

    $tmpPath = strval($file["tmp_name"] ?? "");
    if ($tmpPath === "" || !is_uploaded_file($tmpPath)) {
        $error = "Invalid upload payload.";
        return "";
    }

    $originalName = strval($file["name"] ?? "");
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ["mp3", "wav", "ogg"];
    if (!in_array($ext, $allowed, true)) {
        $error = "Unsupported file type. Allowed: mp3, wav, ogg.";
        return "";
    }

    $size = intval($file["size"] ?? 0);
    $maxSize = 25 * 1024 * 1024;
    if ($size <= 0 || $size > $maxSize) {
        $error = "Invalid file size. Maximum 25MB.";
        return "";
    }

    if (!is_dir($voicesDir)) {
        if (!@mkdir($voicesDir, 0775, true) && !is_dir($voicesDir)) {
            $error = "Could not create voices directory.";
            return "";
        }
    }

    $base = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9_-]+/i', '_', $base) ?? "";
    $base = trim($base, "_");
    if ($base === "") {
        $base = $voiceid !== "" ? $voiceid : "voice_sample";
    }
    if (strlen($base) > 120) {
        $base = substr($base, 0, 120);
    }

    $uploadedHeader = @file_get_contents($tmpPath, false, null, 0, 12);
    $isRiffWav = is_string($uploadedHeader)
        && strlen($uploadedHeader) >= 12
        && substr($uploadedHeader, 0, 4) === "RIFF"
        && substr($uploadedHeader, 8, 4) === "WAVE";
    $mustConvertToWav = ($ext !== "wav") || !$isRiffWav;

    $targetExt = "wav";
    $candidate = $base . "." . $targetExt;
    $targetPath = $voicesDir . DIRECTORY_SEPARATOR . $candidate;
    $suffix = 2;
    while (file_exists($targetPath) && $suffix < 2000) {
        $candidate = $base . "_" . strval($suffix) . "." . $targetExt;
        $targetPath = $voicesDir . DIRECTORY_SEPARATOR . $candidate;
        $suffix++;
    }

    if ($mustConvertToWav) {
        $ffmpegPath = trim(strval(@shell_exec("command -v ffmpeg 2>/dev/null")));
        if ($ffmpegPath === "") {
            $ffmpegFallback = "/usr/bin/ffmpeg";
            if (is_file($ffmpegFallback)) {
                $ffmpegPath = $ffmpegFallback;
            }
        }
        if ($ffmpegPath === "") {
            $error = "ffmpeg is required to convert uploaded audio to WAV but was not found.";
            return "";
        }

        $command = escapeshellarg($ffmpegPath)
            . " -y -i " . escapeshellarg($tmpPath)
            . " -ac 1 -ar 22050 -f wav "
            . escapeshellarg($targetPath)
            . " >/dev/null 2>&1";
        $exitCode = 1;
        @exec($command, $unused, $exitCode);
        if ($exitCode !== 0 || !is_file($targetPath) || intval(@filesize($targetPath)) <= 44) {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
            $error = "Failed to convert uploaded audio to WAV.";
            return "";
        }
    } else {
        if (!@move_uploaded_file($tmpPath, $targetPath)) {
            $error = "Failed to move uploaded voice sample.";
            return "";
        }
    }

    @chmod($targetPath, 0664);
    return $candidate;
}

$isEmbed = isset($_GET["embed"]) && strval($_GET["embed"]) === "1";
$scriptPath = $_SERVER["SCRIPT_NAME"] ?? "";
$uiPos = strpos($scriptPath, "/ui/");
$webRoot = ($uiPos !== false) ? substr($scriptPath, 0, $uiPos) : "";
if ($webRoot === "/") {
    $webRoot = "";
}
$webRoot = rtrim($webRoot, "/");

$voicesDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "voices";
$message = "";
$messageType = "ok";
$view = strtolower(stobe_voice_trim($_GET["view"] ?? "voices"));
if (!in_array($view, ["voices", "pronunciations"], true)) {
    $view = "voices";
}
$pronunciationDictionary = new TTSPronunciationDictionary();
$higgsPreviewUrl = '';
$higgsConnectors = [];
$providerConnectors = [];
$providerConnectorMap = [];
foreach (getAllTtsConnectors() as $connectorRow) {
    $target = stobeVoiceProviderTarget($connectorRow);
    if ($target["provider"] === "") {
        continue;
    }
    $providerConnectorMap[intval($target["id"])] = $target;
    if ($target['provider'] === 'higgs') {
        $higgsConnectors[] = $target;
    } else {
        $providerConnectors[] = $target;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Preview a chosen Higgs connector without changing defaults or NPC profiles.
    if (isset($_POST['higgs_preview'])) {
        $connectorId = intval($_POST['connector_id'] ?? 0);
        $voice = stobeVoiceProviderNormalizeId(strval($_POST['voiceid'] ?? ''));
        $text = trim(strval($_POST['preview_text'] ?? ''));
        $target = $providerConnectorMap[$connectorId] ?? [];
        if (($target['provider'] ?? '') !== 'higgs' || $voice === '' || $text === '' || strlen($text) > 2000) {
            $message = 'Select a Higgs connector, a voice, and preview text (up to 2000 characters).';
            $messageType = 'err';
        } elseif (in_array(strtolower(strval(parse_url($target['endpoint'], PHP_URL_HOST))), ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && !is_readable(stobeFindVoiceSamplePath($voice))
            && !is_readable('/home/dwemer/higgs-tts/voices/' . $voice . '.wav')) {
            $message = 'The selected Higgs voice sample is unavailable. Upload it before previewing.';
            $messageType = 'err';
        } else {
            @set_time_limit(150);
            $connector = getTtsConnectorById($connectorId);
            $result = stobeSynthesizeTtsFromConnector($connector, $text, $voice);
            if (is_array($result) && !empty($result['audio_path'])) {
                $higgsPreviewUrl = $webRoot . '/' . ltrim($result['audio_path'], '/') . '?ts=' . time();
                $message = 'Higgs preview is ready. Your active connector has not changed.';
            } else {
                $message = 'Higgs could not generate speech. Check the service, connector, and selected voice sample.';
                $messageType = 'err';
            }
        }
    } elseif (isset($_POST["pronunciation_action"])) {
        $view = "pronunciations";
        $pronunciationAction = strtolower(stobe_voice_trim($_POST["pronunciation_action"] ?? ""));
        $pronunciationId = intval($_POST["pronunciation_id"] ?? 0);

        try {
            $saved = false;
            $status = "";
            if ($pronunciationAction === "save") {
                $saved = $pronunciationDictionary->saveCustom(
                    $pronunciationId > 0 ? $pronunciationId : null,
                    stobe_voice_trim($_POST["source_text"] ?? ""),
                    stobe_voice_trim($_POST["spoken_text"] ?? ""),
                    stobe_voice_trim($_POST["npc_names"] ?? ""),
                    stobe_voice_trim($_POST["races"] ?? ""),
                    stobe_voice_trim($_POST["oghma_tags"] ?? ""),
                    isset($_POST["enabled"])
                );
                $status = "saved";
            } elseif ($pronunciationAction === "save_builtin") {
                $saved = $pronunciationDictionary->saveBuiltin(
                    $pronunciationId,
                    stobe_voice_trim($_POST["spoken_text"] ?? ""),
                    isset($_POST["enabled"])
                );
                $status = "saved";
            } elseif ($pronunciationAction === "toggle") {
                $saved = $pronunciationDictionary->setEnabled(
                    $pronunciationId,
                    stobeTtsPronunciationBoolean($_POST["enabled"] ?? false)
                );
                $status = "updated";
            } elseif ($pronunciationAction === "delete") {
                $saved = $pronunciationDictionary->deleteEntry($pronunciationId);
                $status = "deleted";
            } else {
                $message = "Unknown pronunciation action.";
                $messageType = "err";
            }

            if ($saved) {
                header("Location: " . stobe_voice_build_url(
                    ["view" => "pronunciations", "pronunciation_ok" => $status],
                    $isEmbed
                ));
                exit;
            }
            if ($message === "") {
                if ($pronunciationAction === "save") {
                    $message = "Enter a written and spoken form, or check for a duplicate entry.";
                } elseif ($pronunciationAction === "save_builtin") {
                    $message = "Enter a spoken form for the built-in pronunciation.";
                } else {
                    $message = "The pronunciation could not be updated.";
                }
                $messageType = "err";
            }
        } catch (Throwable $exception) {
            if (function_exists('stobeLogException')) {
                stobeLogException($exception, 'TTS pronunciation update failed');
            }
            $message = "The pronunciation could not be saved. Check for a duplicate entry and try again.";
            $messageType = "err";
        }
    } elseif (isset($_POST["provider_voice_action"])) {
        $connectorId = intval($_POST["connector_id"] ?? 0);
        $voiceid = stobeVoiceProviderNormalizeId(strval($_POST["voiceid"] ?? ""));
        $providerAction = strtolower(stobe_voice_trim($_POST["provider_voice_action"] ?? ""));
        $target = $providerConnectorMap[$connectorId] ?? null;

        if (!is_array($target)) {
            $message = "Select a configured TTS connector.";
            $messageType = "err";
        } elseif ($voiceid === "") {
            $message = "Enter a valid voice ID.";
            $messageType = "err";
        } elseif ($providerAction === "delete") {
            $result = stobeVoiceProviderDelete($target, $voiceid);
            if ($result["success"]) {
                $syncKey = "tts_sync_v2_" . $target["provider"] . "_" . md5(strtolower($voiceid));
                $db->exec("DELETE FROM conf_opts WHERE id = $1", [$syncKey]);
                header("Location: " . stobe_voice_build_url(["ok" => "provider_deleted", "provider_voice" => $voiceid], $isEmbed, "provider-voices"));
                exit;
            }
            $message = "Could not remove provider voice: " . $result["message"];
            $messageType = "err";
        } elseif ($providerAction === "sync") {
            $sampleRow = $db->fetchOne(
                "SELECT sample_file FROM combined_core_voiceid WHERE LOWER(voiceid) = LOWER($1) LIMIT 1",
                [$voiceid]
            );
            $sampleFile = stobe_voice_normalize_sample_file($sampleRow["sample_file"] ?? "");
            $samplePath = $sampleFile !== "" ? $voicesDir . DIRECTORY_SEPARATOR . $sampleFile : "";
            $result = stobeVoiceProviderUpload($target, $voiceid, $samplePath);
            if ($result["success"]) {
                $fileHash = is_file($samplePath) ? strval(md5_file($samplePath)) : "";
                if ($fileHash !== "") {
                    $syncKey = "tts_sync_v2_" . $target["provider"] . "_" . md5(strtolower($voiceid));
                    $db->exec(
                        "INSERT INTO conf_opts (id, value, updated_at) VALUES ($1, $2, NOW()) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()",
                        [$syncKey, $fileHash]
                    );
                }
                header("Location: " . stobe_voice_build_url(["ok" => "provider_synced", "provider_voice" => $voiceid], $isEmbed, "provider-voices"));
                exit;
            }
            $message = "Could not upload provider voice: " . $result["message"];
            $messageType = "err";
        } else {
            $message = "Unknown provider voice action.";
            $messageType = "err";
        }
    } elseif (isset($_POST["save_voice"])) {
        $originalVoiceId = stobe_voice_normalize_voiceid($_POST["original_voiceid"] ?? "");
        $voiceid = stobe_voice_normalize_voiceid($_POST["voiceid"] ?? "");
        if ($originalVoiceId !== "") {
            $voiceid = $originalVoiceId;
        }

        $gender = strtolower(stobe_voice_trim($_POST["gender"] ?? "any"));
        if (!in_array($gender, ["any", "male", "female"], true)) {
            $gender = "any";
        }

        $race = stobe_voice_trim($_POST["race"] ?? "any");
        if ($race === "") {
            $race = "any";
        }
        $faction = stobe_voice_trim($_POST["faction"] ?? "any");
        if ($faction === "") {
            $faction = "any";
        }
        $unique = stobe_voice_trim($_POST["unique"] ?? "");
        $notes = stobe_voice_trim($_POST["notes"] ?? "");

        $existingSample = stobe_voice_normalize_sample_file($_POST["existing_sample_file"] ?? "");
        $uploadError = "";
        $uploadedSample = "";
        $voiceIdFormatOk = (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $voiceid) === 1);

        if ($voiceid === "") {
            $message = "Voice ID is required.";
            $messageType = "err";
        } elseif (!$voiceIdFormatOk) {
            $message = "Voice ID may only contain lowercase letters, numbers, underscore, and dash.";
            $messageType = "err";
        } else {
            if (isset($_FILES["voice_file"]) && is_array($_FILES["voice_file"])) {
                $uploadedSample = stobe_voice_store_upload($_FILES["voice_file"], $voiceid, $voicesDir, $uploadError);
            }
            if ($uploadError !== "") {
                $message = $uploadError;
                $messageType = "err";
            }

            $sampleFile = $uploadedSample !== "" ? $uploadedSample : $existingSample;
            if ($messageType !== "err" && $sampleFile === "") {
                $message = "Upload a voice sample.";
                $messageType = "err";
            } elseif ($messageType !== "err") {
                $ok = $db->exec(
                    "INSERT INTO core_voiceid_custom (voiceid, sample_file, gender, race, faction, \"unique\", notes, updated_at)
                     VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
                     ON CONFLICT (voiceid) DO UPDATE SET
                        sample_file = EXCLUDED.sample_file,
                        gender = EXCLUDED.gender,
                        race = EXCLUDED.race,
                        faction = EXCLUDED.faction,
                        \"unique\" = EXCLUDED.\"unique\",
                        notes = EXCLUDED.notes,
                        updated_at = NOW()",
                    [$voiceid, $sampleFile, $gender, $race, $faction, $unique, $notes]
                );
                if ($ok) {
                    header("Location: " . stobe_voice_build_url(["ok" => "saved", "edit" => $voiceid], $isEmbed, "edit-form"));
                    exit;
                }
                $message = "Failed to save voice entry.";
                $messageType = "err";
            }
        }
    } elseif (isset($_POST["delete_override"])) {
        $voiceid = stobe_voice_normalize_voiceid($_POST["voiceid"] ?? "");
        if ($voiceid !== "") {
            $db->exec("DELETE FROM core_voiceid_custom WHERE LOWER(voiceid) = LOWER($1)", [$voiceid]);
        }
        header("Location: " . stobe_voice_build_url(["ok" => "deleted"], $isEmbed, "entries"));
        exit;
    } elseif (isset($_POST["truncate_custom"])) {
        $db->exec("TRUNCATE TABLE core_voiceid_custom");
        header("Location: " . stobe_voice_build_url(["ok" => "reset"], $isEmbed, "entries"));
        exit;
    }
}

if (isset($_GET["ok"])) {
    $ok = stobe_voice_trim($_GET["ok"] ?? "");
    if ($ok === "saved") {
        $message = "Voice entry saved to custom overrides.";
    } elseif ($ok === "deleted") {
        $message = "Custom voice override deleted.";
    } elseif ($ok === "reset") {
        $message = "All custom voice overrides cleared.";
    } elseif ($ok === "provider_deleted") {
        $message = "Provider copy removed. The local voice sample remains available for re-upload.";
    } elseif ($ok === "provider_synced") {
        $message = "Voice sample uploaded to the provider. Existing provider artifacts were replaced.";
    }
}

if (isset($_GET["pronunciation_ok"])) {
    $pronunciationOk = stobe_voice_trim($_GET["pronunciation_ok"] ?? "");
    if ($pronunciationOk === "saved") {
        $message = "Pronunciation saved.";
    } elseif ($pronunciationOk === "updated") {
        $message = "Pronunciation status updated.";
    } elseif ($pronunciationOk === "deleted") {
        $message = "Pronunciation deleted.";
    }
}

$letter = strtoupper(stobe_voice_trim($_GET["letter"] ?? ""));
$search = stobe_voice_trim($_GET["search"] ?? "");
$params = [];
$whereParts = [];

if ($letter !== "" && preg_match('/^[A-Z]$/', $letter) === 1) {
    $params[] = strtolower($letter) . "%";
    $idx = count($params);
    $whereParts[] = "LOWER(COALESCE(v.voiceid, '')) LIKE $" . $idx;
}

if ($search !== "") {
    $params[] = "%" . strtolower($search) . "%";
    $idx = count($params);
    $whereParts[] = "(LOWER(COALESCE(v.voiceid, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.sample_file, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.gender, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.race, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.faction, '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.\"unique\", '')) LIKE $" . $idx . "
                OR LOWER(COALESCE(v.notes, '')) LIKE $" . $idx . ")";
}

$whereSql = count($whereParts) > 0 ? ("WHERE " . implode(" AND ", $whereParts)) : "";
$rows = $db->fetchAll(
    "SELECT
        v.voiceid,
        v.sample_file,
        v.gender,
        v.race,
        v.faction,
        v.\"unique\",
        v.notes,
        EXISTS (
            SELECT 1
            FROM core_voiceid_custom c
            WHERE LOWER(c.voiceid) = LOWER(v.voiceid)
        ) AS is_custom
     FROM combined_core_voiceid v
     $whereSql
     ORDER BY LOWER(v.voiceid)
     LIMIT 2000",
    $params
);

$editVoiceId = stobe_voice_normalize_voiceid($_GET["edit"] ?? "");
$providerVoiceId = stobeVoiceProviderNormalizeId(strval($_GET["provider_voice"] ?? $editVoiceId));
$editRow = false;
if ($editVoiceId !== "") {
    $editRow = $db->fetchOne(
        "SELECT voiceid, sample_file, gender, race, faction, \"unique\", notes
         FROM combined_core_voiceid
         WHERE LOWER(voiceid) = LOWER($1)
         LIMIT 1",
        [$editVoiceId]
    );
}
if (!$editRow) {
    $editRow = [
        "voiceid" => "",
        "sample_file" => "",
        "gender" => "any",
        "race" => "any",
        "faction" => "any",
        "unique" => "",
        "notes" => "",
    ];
}

$pronunciationSearch = '';
$pronunciationTag = '';
$pronunciationTags = [];
$pronunciationRows = [];
$pronunciationEditRow = [
    'id' => 0,
    'source_text' => '',
    'spoken_text' => '',
    'npc_names' => '',
    'races' => '',
    'oghma_tags' => '',
    'enabled' => true,
];
$pronunciationPreviewOptions = [
    'connectors' => [],
    'voices' => [],
    'default_connector_id' => 0,
    'default_voice' => '',
];
if ($view === 'pronunciations') {
    $pronunciationSearch = stobe_voice_trim($_GET["pronunciation_search"] ?? "");
    $pronunciationTag = stobe_voice_trim($_GET["pronunciation_tag"] ?? "");
    $pronunciationTags = $pronunciationDictionary->getAvailableTags();
    $pronunciationRows = $pronunciationDictionary->getRows($pronunciationTag);
    if ($pronunciationSearch !== "") {
        $needle = function_exists('mb_strtolower')
            ? mb_strtolower($pronunciationSearch, 'UTF-8')
            : strtolower($pronunciationSearch);
        $pronunciationRows = array_values(array_filter(
            $pronunciationRows,
            static function (array $row) use ($needle): bool {
                $haystack = implode(' ', [
                    strval($row['source_text'] ?? ''),
                    strval($row['spoken_text'] ?? ''),
                    strval($row['npc_names'] ?? ''),
                    strval($row['races'] ?? ''),
                    strval($row['oghma_tags'] ?? ''),
                ]);
                $haystack = function_exists('mb_strtolower')
                    ? mb_strtolower($haystack, 'UTF-8')
                    : strtolower($haystack);
                return strpos($haystack, $needle) !== false;
            }
        ));
    }

    $editPronunciationId = intval($_GET['edit_pronunciation'] ?? 0);
    if ($editPronunciationId > 0) {
        foreach ($pronunciationDictionary->getRows() as $candidate) {
            if (intval($candidate['id'] ?? 0) === $editPronunciationId
                && !stobeTtsPronunciationBoolean($candidate['is_builtin'] ?? false)) {
                $pronunciationEditRow = $candidate;
                break;
            }
        }
    }
    if ($_SERVER["REQUEST_METHOD"] === "POST"
        && strtolower(stobe_voice_trim($_POST["pronunciation_action"] ?? "")) === "save"
        && $messageType === "err") {
        $pronunciationEditRow = [
            'id' => intval($_POST['pronunciation_id'] ?? 0),
            'source_text' => stobe_voice_trim($_POST['source_text'] ?? ''),
            'spoken_text' => stobe_voice_trim($_POST['spoken_text'] ?? ''),
            'npc_names' => stobe_voice_trim($_POST['npc_names'] ?? ''),
            'races' => stobe_voice_trim($_POST['races'] ?? ''),
            'oghma_tags' => stobe_voice_trim($_POST['oghma_tags'] ?? ''),
            'enabled' => isset($_POST['enabled']),
        ];
    }
    $pronunciationPreviewOptions = stobeTtsPronunciationPreviewOptions();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TTS Studio &amp; Voice Manager</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/tts-pronunciations.css?v=20260830">
    <style>
        main {
            padding-top: 30px;
            padding-bottom: 40px;
            padding-left: 5px;
            padding-right: 5px;
            width: 100%;
            margin: 0;
        }
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        .page-header h1.api-title { margin-bottom: 8px; }
        h1.api-title {
            margin: 0 0 20px 0;
            font-family: "MagicCards", serif;
            word-spacing: 8px;
            font-size: 2.2em;
            color: #ffffff;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h1.api-title, h1.api-title * {
            font-family: "MagicCards", serif !important;
        }
        .page-subtitle {
            margin: 0;
            color: #ffffff;
            font-size: 1.1em;
            line-height: 1.6;
            font-family: var(--stobe-title-font) !important;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        .content-section {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .content-section:hover {
            border-color: #4a4a4a;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
        }
        .content-section h2 {
            font-family: "MagicCards", serif;
            color: #e6b76c;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            word-spacing: 6px;
            margin-bottom: 15px;
            margin-top: 0;
            font-size: 1.4em;
        }
        .info-panel p {
            margin: 8px 0;
            color: #c9d3e5;
            line-height: 1.55;
        }
        .logic-list {
            margin: 0;
            padding-left: 18px;
            color: #dbe4f3;
        }
        .logic-list li {
            margin-bottom: 8px;
        }
        .full-width-section {
            grid-column: 1 / -1;
        }
        .full-width-section h2 {
            font-family: "MagicCards", serif;
            color: #e6b76c;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            word-spacing: 6px;
            margin-bottom: 15px;
            font-size: 1.6em;
            text-align: center;
        }
        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
            color: #e6b76c;
            font-weight: bold;
        }
        input[type="text"], input[type="file"], select, textarea {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 10px;
            border-radius: 6px;
            border: 1px solid #3a3a3a;
            background: rgba(26, 26, 26, 0.8);
            color: #e9efff;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        input[type="text"]:focus, input[type="file"]:focus, select:focus, textarea:focus {
            border-color: rgba(230, 183, 108, 0.5);
            outline: none;
            box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
            background: rgba(34, 34, 34, 0.9);
        }
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        .hint {
            color: #9fb1c9;
            font-size: 0.88em;
            margin-top: -6px;
            margin-bottom: 10px;
        }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .action-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .search-container {
            display: flex;
            gap: 10px;
            min-width: 300px;
        }
        .search-container input[type="text"] {
            flex: 1;
        }
        .filter-section {
            margin-bottom: 20px;
            text-align: center;
        }
        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin: 10px 0;
            justify-content: center;
        }
        .table-container {
            width: 100%;
            overflow-x: auto;
            margin-top: 20px;
            max-height: calc(100vh - 320px);
            overflow-y: auto;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
        }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
        }
        .table-container th {
            position: sticky;
            top: 0;
            background: linear-gradient(135deg, rgba(58, 58, 58, 0.95), rgba(48, 48, 48, 0.95));
            color: #e6b76c;
            padding: 12px 10px;
            text-align: left;
            font-family: "MagicCards", serif;
            letter-spacing: 1px;
            border-bottom: 2px solid rgba(230, 183, 108, 0.3);
            z-index: 10;
        }
        .table-container td {
            padding: 10px;
            border-bottom: 1px solid #3a3a3a;
            vertical-align: top;
        }
        .table-container tr:hover {
            background: rgba(58, 58, 58, 0.5);
        }
        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid #4a4a4a;
        }
        .status-pill.custom {
            color: #6dd19c;
            border-color: rgba(109, 209, 156, 0.45);
            background: rgba(25, 77, 50, 0.3);
        }
        .status-pill.base {
            color: #c9d3e5;
            border-color: rgba(138, 155, 182, 0.35);
            background: rgba(55, 66, 84, 0.28);
        }
        .sample-audio {
            width: 220px;
            max-width: 100%;
            height: 34px;
        }
        .provider-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr) auto;
            gap: 14px;
            align-items: end;
        }
        .provider-form .action-row { margin-bottom: 10px; }
        .provider-form label { margin-top: 0; }
        .higgs-preview-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .higgs-preview-form .higgs-preview-text,
        .higgs-preview-form .action-row { grid-column: 1 / -1; }
        .small-muted {
            color: #9fb1c9;
            font-size: 12px;
        }
        .toast-notification {
            position: fixed;
            top: 24px;
            right: 24px;
            min-width: 280px;
            max-width: 560px;
            background: rgba(19, 24, 31, 0.96);
            color: #e9efff;
            border: 1px solid rgba(138, 155, 182, 0.38);
            border-radius: 10px;
            padding: 12px 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            transform: translateY(-6px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 9999;
        }
        .toast-notification.show {
            opacity: 1;
            transform: translateY(0);
        }
        @media (max-width: 1024px) {
            main {
                padding-left: 4%;
                padding-right: 4%;
            }
            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .provider-form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main>
    <div id="toast" class="toast-notification"><span class="message"></span></div>

    <div class="page-header">
        <h1 class="api-title">TTS Studio &amp; Voice Manager</h1>
        <p class="page-subtitle">
            <?= $view === "pronunciations"
                ? "Control how written words are spoken without changing dialogue text"
                : "Manage local voice samples, NPC matching metadata, and uploaded connector copies" ?>
        </p>
    </div>

    <nav class="tts-studio-tabs" aria-label="TTS Studio sections">
        <a
            class="tts-studio-tab <?= $view === "voices" ? "active" : "" ?>"
            href="<?= h(stobe_voice_build_url([], $isEmbed)) ?>"
            <?= $view === "voices" ? 'aria-current="page"' : '' ?>
        >Voice Manager</a>
        <a
            class="tts-studio-tab <?= $view === "pronunciations" ? "active" : "" ?>"
            href="<?= h(stobe_voice_build_url(["view" => "pronunciations"], $isEmbed)) ?>"
            <?= $view === "pronunciations" ? 'aria-current="page"' : '' ?>
        >Pronunciations</a>
    </nav>

    <?php if ($view === "pronunciations"): ?>
        <?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "tts_pronunciations.php"); ?>
    <?php else: ?>
    <div class="content-grid">
        <div id="edit-form" class="content-section">
            <h2><?= stobe_voice_trim($editRow["voiceid"] ?? "") !== "" ? "Edit Voice Override" : "Add Voice Override" ?></h2>
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="original_voiceid" value="<?= h($editRow["voiceid"] ?? "") ?>">
                <input type="hidden" name="existing_sample_file" value="<?= h($editRow["sample_file"] ?? "") ?>">

                <label for="voiceid">Voice ID (required)</label>
                <input
                    type="text"
                    id="voiceid"
                    name="voiceid"
                    value="<?= h($editRow["voiceid"] ?? "") ?>"
                    <?= stobe_voice_trim($editRow["voiceid"] ?? "") !== "" ? "readonly" : "" ?>
                    placeholder="example: male21, hive_worker_1"
                    required
                >
                <div class="hint">Lowercase letters, numbers, underscore, and dash only.</div>

                <label for="voice_file">Upload Voice Sample (single file)</label>
                <input type="file" id="voice_file" name="voice_file" accept=".mp3,.wav,.ogg">
                <div class="hint">Uploaded to <code>StobeServer/data/voices</code>. Non-WAV files are auto-converted to <code>.wav</code>.</div>

                <label for="gender">Gender</label>
                <?php $gender = strtolower(stobe_voice_trim($editRow["gender"] ?? "any")); ?>
                <select id="gender" name="gender">
                    <option value="any" <?= $gender === "any" ? "selected" : "" ?>>any</option>
                    <option value="male" <?= $gender === "male" ? "selected" : "" ?>>male</option>
                    <option value="female" <?= $gender === "female" ? "selected" : "" ?>>female</option>
                </select>

                <label for="race">Race</label>
                <input type="text" id="race" name="race" value="<?= h($editRow["race"] ?? "any") ?>" placeholder="any">

                <label for="faction">Faction</label>
                <input type="text" id="faction" name="faction" value="<?= h($editRow["faction"] ?? "any") ?>" placeholder="any">

                <label for="unique">Unique Match</label>
                <input type="text" id="unique" name="unique" value="<?= h($editRow["unique"] ?? "") ?>" placeholder="NPC name (optional)">

                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="4" placeholder="Optional notes for this voice mapping."><?= h($editRow["notes"] ?? "") ?></textarea>

                <div class="action-row">
                    <button type="submit" name="save_voice" class="action-button upload-csv">Save Voice Override</button>
                    <?php if (stobe_voice_trim($editRow["voiceid"] ?? "") !== ""): ?>
                        <a href="<?= h(stobe_voice_build_url([], $isEmbed, "entries")) ?>" class="action-button">Clear Form</a>
                    <?php endif; ?>
                </div>
            </form>
            <form action="" method="post" style="margin-top:10px;">
                <button
                    type="submit"
                    name="truncate_custom"
                    class="btn-danger"
                    onclick="return confirm('Delete all custom voice overrides? This cannot be undone.');"
                >
                    Factory Reset Custom Voice Overrides
                </button>
            </form>
        </div>

        <div class="content-section info-panel">
            <h2>Voice Selection Logic</h2>
            <p>Stobe selects a voice ID from <code>combined_core_voiceid</code> and writes the chosen value to each NPC profile.</p>
            <ol class="logic-list">
                <li>Prefer <strong>unique name matches</strong> first (the <code>unique</code> column).</li>
                <li>Rows with a non-empty <code>unique</code> are used only for that unique NPC and are never in the random pool.</li>
                <li>Then match by <strong>gender + race + faction</strong> (strict), then relax to <strong>gender + race</strong>, then <strong>gender + faction</strong>, then <strong>gender</strong>.</li>
                <li>If no match exists, use deterministic fallback from the remaining non-unique rows.</li>
                <li>If a custom row exists in <code>core_voiceid_custom</code>, it overrides base <code>core_voiceid</code> for the same <code>voiceid</code>.</li>
            </ol>
            <p class="small-muted">Tip: use <code>sample_file</code> values that exist under <code>data/voices</code> for local sample preview and consistency.</p>
        </div>

        <div id="higgs-voices" class="content-section full-width-section">
            <h2>Higgs TTS 3</h2>
            <p class="small-muted">Higgs uses the samples in your voice library directly. Upload a sample above, then select its voice ID to preview it. Previewing does not change your active connector.</p>
            <p class="small-muted">For a remote Higgs service, place the sample on that host with the same voice name. Upload / Rebuild and Remove Provider Copy do not apply to Higgs.</p>
            <?php if (empty($higgsConnectors)): ?>
                <p><a href="<?= h($webRoot . '/ui/tts_connectors.php') ?>">Add a Higgs TTS 3 connector</a> to preview voices here.</p>
            <?php else: ?>
                <form method="post" action="#higgs-voices" class="provider-form higgs-preview-form">
                    <div>
                        <label for="higgs-connector">Connector</label>
                        <select id="higgs-connector" name="connector_id" required>
                            <?php foreach ($higgsConnectors as $connector): ?>
                                <option value="<?= h($connector['id']) ?>" <?= intval($_POST['connector_id'] ?? 0) === $connector['id'] ? 'selected' : '' ?>><?= h($connector['name'] ?: 'Higgs TTS 3') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="higgs-voice">Voice ID</label>
                        <input type="text" id="higgs-voice" name="voiceid" list="higgs-voice-ids" value="<?= h($_POST['voiceid'] ?? $providerVoiceId) ?>" required>
                    </div>
                    <div class="higgs-preview-text">
                        <label for="higgs-text">Preview text</label>
                        <input type="text" id="higgs-text" name="preview_text" maxlength="2000" value="<?= h($_POST['preview_text'] ?? 'Welcome, traveler. Let us hear how this voice sounds.') ?>" required>
                    </div>
                    <div class="action-row"><button type="submit" name="higgs_preview" value="1" class="action-button">Preview Higgs Voice</button></div>
                </form>
                <datalist id="higgs-voice-ids">
                    <?php foreach ($rows as $voiceRow): ?>
                        <option value="<?= h($voiceRow['voiceid'] ?? '') ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <?php if ($higgsPreviewUrl !== ''): ?>
                    <audio controls autoplay preload="none" src="<?= h($higgsPreviewUrl) ?>"></audio>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div id="provider-voices" class="content-section full-width-section">
            <h2>Connector Voice Copies</h2>
            <p class="small-muted">Upload or rebuild the selected local sample on a connector, or remove only its provider-side copy. Cartesia and Inworld use clone-first replacement: the new clone is validated before its ID becomes active. Removing a provider copy never deletes the WAV under <code>data/voices</code>.</p>
            <?php if (count($providerConnectors) === 0): ?>
                <p>No manageable TTS connector is configured.</p>
            <?php else: ?>
                <form action="" method="post" class="provider-form">
                    <div>
                        <label for="provider_connector_id">Connector</label>
                        <select id="provider_connector_id" name="connector_id" required>
                            <?php foreach ($providerConnectors as $connector): ?>
                                <option value="<?= h($connector["id"]) ?>">
                                    <?= h(($connector["name"] !== "" ? $connector["name"] : strtoupper($connector["provider"])) . " — " . $connector["provider"] . ($connector["can_manage"] ? "" : " (management unavailable)")) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="provider_voiceid">Voice ID</label>
                        <input type="text" id="provider_voiceid" name="voiceid" list="provider_voice_ids" value="<?= h($providerVoiceId) ?>" placeholder="example: hive_worker_1" required>
                        <datalist id="provider_voice_ids">
                            <?php foreach ($rows as $voiceRow): ?>
                                <option value="<?= h($voiceRow["voiceid"] ?? "") ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="action-row">
                        <button type="submit" name="provider_voice_action" value="sync" class="action-button edit">Upload / Rebuild</button>
                        <button type="submit" name="provider_voice_action" value="delete" class="action-button btn-danger" onclick="return confirm('Remove this voice from the selected connector? The local WAV will be kept.');">Remove Provider Copy</button>
                    </div>
                </form>
                <p class="hint">OmniVoice uses the connector language. Cartesia and Inworld require a configured API badge; Inworld also requires a workspace. Cloud deletion is allowed only for clones marked as created by this Stobe installation.</p>
            <?php endif; ?>
        </div>

        <div class="content-section full-width-section">
            <div id="entries"></div>

            <div class="action-container">
                <form class="search-container" method="get" action="">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <?php if ($letter !== ""): ?><input type="hidden" name="letter" value="<?= h($letter) ?>"><?php endif; ?>
                    <input type="text" name="search" placeholder="Search voice id, sample, race, faction, unique..." value="<?= h($search) ?>">
                    <button type="submit" class="action-button edit">Search</button>
                    <a class="action-button" href="<?= h(stobe_voice_build_url([], $isEmbed, "entries")) ?>">Clear</a>
                </form>
            </div>

            <div class="filter-section">
                <strong>Filter by Voice ID:</strong>
                <div class="filter-buttons">
                    <a href="<?= h(stobe_voice_build_url([], $isEmbed, "entries")) ?>" class="alphabet-button">All</a>
                    <?php foreach (range("A", "Z") as $char): ?>
                        <a href="<?= h(stobe_voice_build_url(["letter" => $char], $isEmbed, "entries")) ?>" class="alphabet-button"><?= h($char) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="voice-table-container" class="table-container">
                <table>
                    <tr>
                        <th>Voice ID</th>
                        <th>Sample</th>
                        <th>Gender</th>
                        <th>Race</th>
                        <th>Faction</th>
                        <th>Unique</th>
                        <th>Notes</th>
                        <th>Source</th>
                        <th>Actions</th>
                    </tr>
                    <?php if (count($rows) === 0): ?>
                        <tr><td colspan="9">No voice rows found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $voiceid = strval($row["voiceid"] ?? "");
                        $sampleFile = stobe_voice_normalize_sample_file($row["sample_file"] ?? "");
                        $samplePath = $sampleFile !== "" ? ($voicesDir . DIRECTORY_SEPARATOR . $sampleFile) : "";
                        $sampleExists = ($samplePath !== "" && file_exists($samplePath));
                        $sampleUrl = ($sampleFile !== "") ? ($webRoot . "/data/voices/" . rawurlencode($sampleFile)) : "";
                        $isCustom = strval($row["is_custom"] ?? "f") === "t";
                        ?>
                        <tr>
                            <td><code><?= h($voiceid) ?></code></td>
                            <td>
                                <div><?= h($sampleFile !== "" ? $sampleFile : "(none)") ?></div>
                                <?php if ($sampleExists): ?>
                                    <audio class="sample-audio" controls preload="none" src="<?= h($sampleUrl) ?>"></audio>
                                <?php elseif ($sampleFile !== ""): ?>
                                    <div class="small-muted">File not found in data/voices</div>
                                <?php endif; ?>
                            </td>
                            <td><?= h(strval($row["gender"] ?? "")) ?></td>
                            <td><?= h(strval($row["race"] ?? "")) ?></td>
                            <td><?= h(strval($row["faction"] ?? "")) ?></td>
                            <td><?= h(strval($row["unique"] ?? "")) ?></td>
                            <td style="max-width: 280px;"><?= nl2br(h(strval($row["notes"] ?? ""))) ?></td>
                            <td>
                                <span class="status-pill <?= $isCustom ? "custom" : "base" ?>">
                                    <?= $isCustom ? "custom" : "base" ?>
                                </span>
                            </td>
                            <td>
                                <a class="action-button edit" href="<?= h(stobe_voice_build_url(["edit" => $voiceid], $isEmbed, "edit-form")) ?>">Edit</a>
                                <a class="action-button" href="<?= h(stobe_voice_build_url(["provider_voice" => $voiceid], $isEmbed, "provider-voices")) ?>">Manage Provider</a>
                                <?php if ($isCustom): ?>
                                    <form action="" method="post" style="display:inline;">
                                        <input type="hidden" name="voiceid" value="<?= h($voiceid) ?>">
                                        <button
                                            type="submit"
                                            name="delete_override"
                                            class="action-button btn-danger"
                                            onclick="return confirm('Delete custom override for <?= h($voiceid) ?>?');"
                                        >
                                            Remove Override
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
function showToast(message, duration = 5000) {
    const toast = document.getElementById("toast");
    const messageSpan = toast.querySelector(".message");
    messageSpan.textContent = message;
    toast.classList.add("show");
    setTimeout(() => {
        toast.classList.remove("show");
    }, duration);
}
<?php if ($message !== ""): ?>
document.addEventListener("DOMContentLoaded", function() {
    showToast(<?= json_encode($message) ?>);
});
<?php endif; ?>
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
