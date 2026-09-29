<?php
require_once dirname(__DIR__) . "/lib/tts_filter_presets.php";
$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");
if (!isset($GLOBALS["db"]) || !($GLOBALS["db"] instanceof sql)) {
    $GLOBALS["db"] = new sql();
}

function h(mixed $value): string
{
    return htmlspecialchars(strval($value), ENT_QUOTES, "UTF-8");
}

function npc_bios_trim(mixed $value): string
{
    return trim(strval($value));
}

function npc_bios_is_true(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $text = strtolower(trim(strval($value)));
    return in_array($text, ["1", "true", "yes", "on", "t"], true);
}

function npc_bios_build_url(array $params, bool $isEmbed): string
{
    $base = basename($_SERVER["PHP_SELF"] ?? "npc_bios.php");
    if ($isEmbed) {
        $params["embed"] = "1";
    }
    $qs = http_build_query($params);
    return $base . ($qs !== "" ? ("?" . $qs) : "");
}

function npc_bios_render_pagination(
    int $page,
    int $totalRows,
    int $pageSize,
    string $pageKey,
    array $params,
    bool $isEmbed
): void {
    $totalPages = max(1, intval(ceil($totalRows / max(1, $pageSize))));
    if ($totalPages <= 1) {
        echo '<div class="pagination-bar"><span>' . intval($totalRows) . ' entries</span></div>';
        return;
    }

    echo '<div class="pagination-bar">';
    if ($page > 1) {
        $previous = $params;
        $previous[$pageKey] = $page - 1;
        echo '<a class="action-button" href="' . h(npc_bios_build_url($previous, $isEmbed)) . '">Previous</a>';
    } else {
        echo '<span class="pagination-placeholder"></span>';
    }
    echo '<span>Page ' . intval($page) . ' of ' . intval($totalPages)
        . ' (' . intval($totalRows) . ' entries)</span>';
    if ($page < $totalPages) {
        $next = $params;
        $next[$pageKey] = $page + 1;
        echo '<a class="action-button" href="' . h(npc_bios_build_url($next, $isEmbed)) . '">Next</a>';
    } else {
        echo '<span class="pagination-placeholder"></span>';
    }
    echo '</div>';
}

function npc_bios_wants_json(): bool
{
    $requestedWith = strtolower(strval($_SERVER["HTTP_X_REQUESTED_WITH"] ?? ""));
    $accept = strtolower(strval($_SERVER["HTTP_ACCEPT"] ?? ""));
    return $requestedWith === "xmlhttprequest" || strpos($accept, "application/json") !== false;
}

function npc_bios_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload);
    exit;
}

function npc_bios_toggle_enabled(sql $db, string $baseTable, string $customTable, string $label, string $tab, string $noticeKey, array $redirectParams, bool $isEmbed): void
{
    $rowId = intval($_POST["row_id"] ?? 0);
    $source = strtolower(npc_bios_trim($_POST["source"] ?? ""));
    $targetEnabled = npc_bios_is_true($_POST["target_enabled"] ?? "0");
    $table = $source === "custom" ? $customTable : ($source === "base" ? $baseTable : "");
    $okToggle = false;
    if ($rowId > 0 && $table !== "") {
        $result = $db->exec(
            "UPDATE {$table}
             SET is_enabled = $1,
                  updated_at = NOW()
             WHERE id = $2",
            [$targetEnabled ? "1" : "0", $rowId]
        );
        if ($result === false) {
            stobeLogError('NPC biography state update failed', [
                'table' => $table,
                'row_id' => $rowId,
                'source' => $source,
                'db_error' => $db->GetLastError(),
            ]);
        } else {
            $affectedRows = $db->affectedRows($result);
            $okToggle = $affectedRows === 1;
            if (!$okToggle) {
                stobeLogWarn('NPC biography state update matched no row', [
                    'table' => $table,
                    'row_id' => $rowId,
                    'source' => $source,
                    'affected_rows' => $affectedRows,
                ]);
            }
        }
    }
    if (npc_bios_wants_json()) {
        if (!$okToggle) {
            npc_bios_json_response(["ok" => false, "message" => "Could not update {$label} entry state."], 400);
        }
        npc_bios_json_response([
            "ok" => true,
            "enabled" => $targetEnabled,
            "row_id" => $rowId,
            "source" => $source,
            "message" => "Updated {$label} entry state.",
        ]);
    }
    $redirectParams["tab"] = $tab;
    $redirectParams["notice"] = $okToggle ? "toggled_" . $noticeKey : "toggle_failed_" . $noticeKey;
    header("Location: " . npc_bios_build_url($redirectParams, $isEmbed));
    exit;
}

function npc_bios_pick_csv(array $row, array $map, array $aliases, int $fallback = -1): string
{
    foreach ($aliases as $alias) {
        $key = strtolower(trim(strval($alias)));
        if ($key !== "" && array_key_exists($key, $map)) {
            return trim(strval($row[intval($map[$key])] ?? ""));
        }
    }
    if ($fallback >= 0) {
        return trim(strval($row[$fallback] ?? ""));
    }
    return "";
}

function npc_bios_read_uploaded_csv(string $tmpPath): array
{
    $csvData = @file_get_contents($tmpPath);
    if ($csvData === false) {
        return [false, "Error reading the uploaded CSV file.", [], []];
    }
    if (substr($csvData, 0, 3) === "\xEF\xBB\xBF") {
        $csvData = substr($csvData, 3);
    }
    if (strpos($csvData, "\x00") !== false) {
        $csvData = mb_convert_encoding($csvData, "UTF-8", "UTF-16");
    } elseif (!mb_check_encoding($csvData, "UTF-8")) {
        $csvData = mb_convert_encoding($csvData, "UTF-8", "Windows-1252");
    }

    $stream = fopen("php://memory", "r+");
    fwrite($stream, $csvData);
    rewind($stream);

    $header = fgetcsv($stream, 0, ",");
    $map = [];
    if (is_array($header)) {
        foreach ($header as $i => $colName) {
            $k = strtolower(trim(strval($colName)));
            if ($k !== "") {
                $map[$k] = intval($i);
            }
        }
    }

    $rows = [];
    while (($row = fgetcsv($stream, 0, ",")) !== false) {
        if (!is_array($row) || count($row) === 0) {
            continue;
        }
        $rows[] = $row;
    }
    fclose($stream);
    if (isset($map['tts_filter_preset'])) {
        foreach ($rows as $row) {
            try { stobeBiographyVoiceFilter($row[$map['tts_filter_preset']] ?? ''); }
            catch (InvalidArgumentException $e) { return [false, $e->getMessage(), [], []]; }
        }
    }
    return [true, "", $map, $rows];
}

$db = $GLOBALS["db"];
$validTypes = ["personality", "backstory", "speechstyle", "occupation", "appearance", "goals"];
$browsePageSize = 100;
$isEmbed = isset($_GET["embed"]) && strval($_GET["embed"]) === "1";

$activeTab = strtolower(npc_bios_trim($_GET["tab"] ?? "bio_random"));
if (!in_array($activeTab, ["bio_random", "bio_unique", "rename_token"], true)) {
    $activeTab = "bio_random";
}

$qRandom = npc_bios_trim($_GET["q_random"] ?? "");
$qUnique = npc_bios_trim($_GET["q_unique"] ?? "");
$qToken = npc_bios_trim($_GET["q_token"] ?? "");
$pageRandom = max(1, intval($_GET["page_random"] ?? 1));
$pageUnique = max(1, intval($_GET["page_unique"] ?? 1));
$pageToken = max(1, intval($_GET["page_token"] ?? 1));
$enabledRandom = strtolower(npc_bios_trim($_GET["enabled_random"] ?? "all"));
if (!in_array($enabledRandom, ["all", "enabled", "disabled"], true)) {
    $enabledRandom = "all";
}
$enabledUnique = strtolower(npc_bios_trim($_GET["enabled_unique"] ?? "all"));
if (!in_array($enabledUnique, ["all", "enabled", "disabled"], true)) {
    $enabledUnique = "all";
}
$enabledToken = strtolower(npc_bios_trim($_GET["enabled_token"] ?? "all"));
if (!in_array($enabledToken, ["all", "enabled", "disabled"], true)) {
    $enabledToken = "all";
}
$letterRandom = strtoupper(npc_bios_trim($_GET["letter_random"] ?? ""));
$letterUnique = strtoupper(npc_bios_trim($_GET["letter_unique"] ?? ""));
$letterToken = strtoupper(npc_bios_trim($_GET["letter_token"] ?? ""));
if (!preg_match("/^[A-Z]$/", $letterRandom)) {
    $letterRandom = "";
}
if (!preg_match("/^[A-Z]$/", $letterUnique)) {
    $letterUnique = "";
}
if (!preg_match("/^[A-Z]$/", $letterToken)) {
    $letterToken = "";
}

$message = "";
$messageType = "ok";
$editRandom = false;
$editUnique = false;
$editToken = false;

if (isset($_GET["action"]) && $_GET["action"] === "export_custom_bio_random") {
    $rows = $db->fetchAll(
        "SELECT type AS stringid, name, description, tts_filter_preset
         FROM bio_random_custom
         ORDER BY LOWER(COALESCE(name, '')), LOWER(type)"
    );
    $filename = "bio_random_custom_export_" . date("Y-m-d_H-i-s") . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["stringid", "name", "description", "tts_filter_preset"]);
    foreach ($rows as $row) {
        fputcsv($out, [
            strval($row["stringid"] ?? ""),
            strval($row["name"] ?? ""),
            strval($row["description"] ?? ""),
            strval($row["tts_filter_preset"] ?? ""),
        ]);
    }
    fclose($out);
    exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "download_example_bio_random") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"example_bio_random.csv\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["stringid", "name", "description", "tts_filter_preset"]);
    fputcsv($out, ["backstory", "Nomadic Bandit", "A drifter hardened by hunger, dust storms, and broken alliances.", ""]);
    fputcsv($out, ["appearance", "Nomadic Bandit", "Sun-darkened skin, wind-burned cheeks, and a patched desert scarf.", ""]);
    fputcsv($out, ["goals", "Nomadic Bandit", "Secure food, avoid patrols, and survive the next raid.", ""]);
    fclose($out);
    exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "export_custom_bio_unique") {
    $rows = $db->fetchAll(
        "SELECT type AS stringid, name, description, tts_filter_preset
         FROM bio_unique_custom
         ORDER BY LOWER(name), LOWER(type)"
    );
    $filename = "bio_unique_custom_export_" . date("Y-m-d_H-i-s") . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["stringid", "name", "description", "tts_filter_preset"]);
    foreach ($rows as $row) {
        fputcsv($out, [
            strval($row["stringid"] ?? ""),
            strval($row["name"] ?? ""),
            strval($row["description"] ?? ""),
            strval($row["tts_filter_preset"] ?? ""),
        ]);
    }
    fclose($out);
    exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "download_example_bio_unique") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"example_bio_unique.csv\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["stringid", "name", "description", "tts_filter_preset"]);
    fputcsv($out, ["personality", "Tinfist", "Measured, forceful, and deeply committed to anti-slavery ideals.", ""]);
    fputcsv($out, ["appearance", "Tinfist", "Heavy iron limbs and a battle-worn frame scarred by years of revolt.", ""]);
    fputcsv($out, ["goals", "Tinfist", "Break slave systems and protect those fleeing bondage.", ""]);
    fclose($out);
    exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "export_custom_rename_token") {
    $rows = $db->fetchAll(
        "SELECT token
         FROM rename_token_global_custom
         ORDER BY LOWER(token)"
    );
    $filename = "rename_token_custom_export_" . date("Y-m-d_H-i-s") . ".csv";
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["token"]);
    foreach ($rows as $row) {
        fputcsv($out, [strval($row["token"] ?? "")]);
    }
    fclose($out);
    exit;
}

if (isset($_GET["action"]) && $_GET["action"] === "download_example_rename_token") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"example_rename_token.csv\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["token"]);
    fputcsv($out, ["police chief"]);
    fputcsv($out, ["gate guard"]);
    fputcsv($out, ["captain"]);
    fclose($out);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && in_array($_POST['action'] ?? '', ['save_random', 'save_unique'], true)) {
    try { stobeBiographyVoiceFilter($_POST['tts_filter_preset'] ?? null); }
    catch (InvalidArgumentException $e) { http_response_code(400); echo h($e->getMessage()); exit; }
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["submit_csv_random"])) {
        $activeTab = "bio_random";
        if (!isset($_FILES["csv_file_random"]) || intval($_FILES["csv_file_random"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = "No file uploaded or there was an upload error.";
            $messageType = "err";
        } else {
            $uploadName = strval($_FILES["csv_file_random"]["name"] ?? "");
            $ext = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
            if ($ext !== "csv") {
                $message = "Upload failed. Allowed file type: csv";
                $messageType = "err";
            } else {
                [$ok, $readErr, $map, $rows] = npc_bios_read_uploaded_csv(strval($_FILES["csv_file_random"]["tmp_name"] ?? ""));
                if (!$ok) {
                    $message = $readErr;
                    $messageType = "err";
                } else {
                    $count = 0;
                    foreach ($rows as $row) {
                        $type = strtolower(npc_bios_pick_csv($row, $map, ["type", "stringid", "baseid"], 0));
                        $name = npc_bios_pick_csv($row, $map, ["name"], 1);
                        $description = npc_bios_pick_csv($row, $map, ["description"], 2);
                        $voiceFilter = stobeBiographyVoiceFilter(isset($map["tts_filter_preset"]) ? ($row[$map["tts_filter_preset"]] ?? "") : null);
                        $race = npc_bios_pick_csv($row, $map, ["race"]);
                        $gender = npc_bios_pick_csv($row, $map, ["gender"]);
                        $faction = npc_bios_pick_csv($row, $map, ["faction"]);
                        if (!in_array($type, $validTypes, true) || $description === "") {
                            continue;
                        }
                        $okUpsert = $db->exec(
                            "INSERT INTO bio_random_custom (type, description, name, race, gender, faction, tts_filter_preset)
                             VALUES ($1, $2, $3, $4, $5, $6, $7)
                             ON CONFLICT (type, description, name)
                             DO UPDATE SET
                                race = EXCLUDED.race,
                                gender = EXCLUDED.gender,
                                faction = EXCLUDED.faction,
                        tts_filter_preset = COALESCE(EXCLUDED.tts_filter_preset, bio_random_custom.tts_filter_preset),
                                updated_at = NOW()",
                            [$type, $description, $name, $race, $gender, $faction, $voiceFilter]
                        );
                        if ($okUpsert !== false) {
                            $count++;
                        }
                    }
                    $message = $count . " records inserted/updated successfully from the CSV file.";
                    $messageType = "ok";
                }
            }
        }
    } elseif (isset($_POST["submit_csv_unique"])) {
        $activeTab = "bio_unique";
        if (!isset($_FILES["csv_file_unique"]) || intval($_FILES["csv_file_unique"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = "No file uploaded or there was an upload error.";
            $messageType = "err";
        } else {
            $uploadName = strval($_FILES["csv_file_unique"]["name"] ?? "");
            $ext = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
            if ($ext !== "csv") {
                $message = "Upload failed. Allowed file type: csv";
                $messageType = "err";
            } else {
                [$ok, $readErr, $map, $rows] = npc_bios_read_uploaded_csv(strval($_FILES["csv_file_unique"]["tmp_name"] ?? ""));
                if (!$ok) {
                    $message = $readErr;
                    $messageType = "err";
                } else {
                    $count = 0;
                    foreach ($rows as $row) {
                        $type = strtolower(npc_bios_pick_csv($row, $map, ["type", "stringid", "baseid"], 0));
                        $name = npc_bios_pick_csv($row, $map, ["name"], 1);
                        $description = npc_bios_pick_csv($row, $map, ["description"], 2);
                        $voiceFilter = stobeBiographyVoiceFilter(isset($map["tts_filter_preset"]) ? ($row[$map["tts_filter_preset"]] ?? "") : null);
                        if ($name === "" || !in_array($type, $validTypes, true) || $description === "") {
                            continue;
                        }
                        $okUpsert = $db->exec(
                            "INSERT INTO bio_unique_custom (name, type, description, tts_filter_preset)
                             VALUES ($1, $2, $3, $4)
                             ON CONFLICT (name, type)
                             DO UPDATE SET
                                description = EXCLUDED.description,
                        tts_filter_preset = COALESCE(EXCLUDED.tts_filter_preset, bio_unique_custom.tts_filter_preset),
                                updated_at = NOW()",
                            [$name, $type, $description, $voiceFilter]
                        );
                        if ($okUpsert !== false) {
                            $count++;
                        }
                    }
                    $message = $count . " records inserted/updated successfully from the CSV file.";
                    $messageType = "ok";
                }
            }
        }
    } elseif (isset($_POST["submit_csv_token"])) {
        $activeTab = "rename_token";
        if (!isset($_FILES["csv_file_token"]) || intval($_FILES["csv_file_token"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = "No file uploaded or there was an upload error.";
            $messageType = "err";
        } else {
            $uploadName = strval($_FILES["csv_file_token"]["name"] ?? "");
            $ext = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
            if ($ext !== "csv") {
                $message = "Upload failed. Allowed file type: csv";
                $messageType = "err";
            } else {
                [$ok, $readErr, $map, $rows] = npc_bios_read_uploaded_csv(strval($_FILES["csv_file_token"]["tmp_name"] ?? ""));
                if (!$ok) {
                    $message = $readErr;
                    $messageType = "err";
                } else {
                    $count = 0;
                    foreach ($rows as $row) {
                        $token = npc_bios_pick_csv($row, $map, ["token", "stringid", "name", "rename_token"], 0);
                        if ($token === "") {
                            continue;
                        }
                        $okUpsert = $db->exec(
                            "INSERT INTO rename_token_global_custom (token)
                             VALUES ($1)
                             ON CONFLICT (token)
                             DO UPDATE SET
                                updated_at = NOW()",
                            [$token]
                        );
                        if ($okUpsert !== false) {
                            $count++;
                        }
                    }
                    $message = $count . " records inserted/updated successfully from the CSV file.";
                    $messageType = "ok";
                }
            }
        }
    } else {
        $action = strtolower(npc_bios_trim($_POST["action"] ?? ""));

        if ($action === "toggle_random_enabled") {
            $activeTab = "bio_random";
            npc_bios_toggle_enabled($db, "bio_random", "bio_random_custom", "bio_random", "bio_random", "random", [
                "q_random" => npc_bios_trim($_POST["q_random"] ?? ""),
                "letter_random" => npc_bios_trim($_POST["letter_random"] ?? ""),
                "enabled_random" => npc_bios_trim($_POST["enabled_random"] ?? "all"),
                "page_random" => max(1, intval($_POST["page_random"] ?? 1)),
            ], $isEmbed);
        } elseif ($action === "toggle_unique_enabled") {
            $activeTab = "bio_unique";
            npc_bios_toggle_enabled($db, "bio_unique", "bio_unique_custom", "bio_unique", "bio_unique", "unique", [
                "q_unique" => npc_bios_trim($_POST["q_unique"] ?? ""),
                "letter_unique" => npc_bios_trim($_POST["letter_unique"] ?? ""),
                "enabled_unique" => npc_bios_trim($_POST["enabled_unique"] ?? "all"),
                "page_unique" => max(1, intval($_POST["page_unique"] ?? 1)),
            ], $isEmbed);
        } elseif ($action === "toggle_token_enabled") {
            $activeTab = "rename_token";
            npc_bios_toggle_enabled($db, "rename_token_global", "rename_token_global_custom", "rename token", "rename_token", "token", [
                "q_token" => npc_bios_trim($_POST["q_token"] ?? ""),
                "letter_token" => npc_bios_trim($_POST["letter_token"] ?? ""),
                "enabled_token" => npc_bios_trim($_POST["enabled_token"] ?? "all"),
                "page_token" => max(1, intval($_POST["page_token"] ?? 1)),
            ], $isEmbed);
        } elseif ($action === "save_random") {
        $activeTab = "bio_random";
        $rowId = intval($_POST["row_id"] ?? 0);
        $type = strtolower(npc_bios_trim($_POST["type"] ?? ""));
        $description = npc_bios_trim($_POST["description"] ?? "");
        $voiceFilter = stobeBiographyVoiceFilter($_POST["tts_filter_preset"] ?? null);
        $name = npc_bios_trim($_POST["name"] ?? "");
        $race = npc_bios_trim($_POST["race"] ?? "");
        $gender = npc_bios_trim($_POST["gender"] ?? "");
        $faction = npc_bios_trim($_POST["faction"] ?? "");

        if (!in_array($type, $validTypes, true) || $description === "") {
            $message = "Type and description are required for bio_random.";
            $messageType = "err";
            $editRandom = [
                "id" => $rowId,
                "type" => $type,
                "description" => $description,
                "name" => $name,
                "race" => $race,
                "gender" => $gender,
                "faction" => $faction,
            ];
        } else {
            if ($rowId > 0) {
                $db->exec(
                    "UPDATE bio_random_custom
                     SET type = $1,
                         description = $2,
                         name = $3,
                         race = $4,
                         gender = $5,
                         faction = $6,
                         tts_filter_preset = COALESCE($8, tts_filter_preset),
                         updated_at = NOW()
                     WHERE id = $7",
                    [$type, $description, $name, $race, $gender, $faction, $rowId, $voiceFilter]
                );
            } else {
                $db->exec(
                    "INSERT INTO bio_random_custom (type, description, name, race, gender, faction, tts_filter_preset)
                     VALUES ($1, $2, $3, $4, $5, $6, $7)
                     ON CONFLICT (type, description, name)
                     DO UPDATE SET
                        race = EXCLUDED.race,
                        gender = EXCLUDED.gender,
                        faction = EXCLUDED.faction,
                        tts_filter_preset = COALESCE(EXCLUDED.tts_filter_preset, bio_random_custom.tts_filter_preset),
                        updated_at = NOW()",
                    [$type, $description, $name, $race, $gender, $faction, $voiceFilter]
                );
            }
            header("Location: " . npc_bios_build_url(["tab" => "bio_random", "notice" => "saved_random"], $isEmbed));
            exit;
        }
        } elseif ($action === "delete_random") {
        $activeTab = "bio_random";
        $rowId = intval($_POST["row_id"] ?? 0);
        if ($rowId > 0) {
            $db->exec("DELETE FROM bio_random_custom WHERE id = $1", [$rowId]);
        }
        header("Location: " . npc_bios_build_url(["tab" => "bio_random", "notice" => "deleted_random"], $isEmbed));
        exit;
        } elseif ($action === "save_unique") {
        $activeTab = "bio_unique";
        $rowId = intval($_POST["row_id"] ?? 0);
        $name = npc_bios_trim($_POST["name"] ?? "");
        $type = strtolower(npc_bios_trim($_POST["type"] ?? ""));
        $description = npc_bios_trim($_POST["description"] ?? "");
        $voiceFilter = stobeBiographyVoiceFilter($_POST["tts_filter_preset"] ?? null);

        if ($name === "" || !in_array($type, $validTypes, true) || $description === "") {
            $message = "Name, type, and description are required for bio_unique.";
            $messageType = "err";
            $editUnique = [
                "id" => $rowId,
                "name" => $name,
                "type" => $type,
                "description" => $description,
            ];
        } else {
            if ($rowId > 0) {
                $db->exec(
                    "UPDATE bio_unique_custom
                     SET name = $1,
                         type = $2,
                         description = $3,
                         tts_filter_preset = COALESCE($5, tts_filter_preset),
                         updated_at = NOW()
                     WHERE id = $4",
                    [$name, $type, $description, $rowId, $voiceFilter]
                );
            } else {
                $db->exec(
                    "INSERT INTO bio_unique_custom (name, type, description, tts_filter_preset)
                     VALUES ($1, $2, $3, $4)
                     ON CONFLICT (name, type)
                     DO UPDATE SET
                        description = EXCLUDED.description,
                        tts_filter_preset = COALESCE(EXCLUDED.tts_filter_preset, bio_unique_custom.tts_filter_preset),
                        updated_at = NOW()",
                    [$name, $type, $description, $voiceFilter]
                );
            }
            header("Location: " . npc_bios_build_url(["tab" => "bio_unique", "notice" => "saved_unique"], $isEmbed));
            exit;
        }
        } elseif ($action === "delete_unique") {
        $activeTab = "bio_unique";
        $rowId = intval($_POST["row_id"] ?? 0);
        if ($rowId > 0) {
            $db->exec("DELETE FROM bio_unique_custom WHERE id = $1", [$rowId]);
        }
        header("Location: " . npc_bios_build_url(["tab" => "bio_unique", "notice" => "deleted_unique"], $isEmbed));
        exit;
        } elseif ($action === "save_token") {
        $activeTab = "rename_token";
        $rowId = intval($_POST["row_id"] ?? 0);
        $token = npc_bios_trim($_POST["token"] ?? "");

        if ($token === "") {
            $message = "Token is required for rename tokens.";
            $messageType = "err";
            $editToken = [
                "id" => $rowId,
                "token" => $token,
            ];
        } else {
            $okWrite = false;
            if ($rowId > 0) {
                $okWrite = $db->exec(
                    "UPDATE rename_token_global_custom
                     SET token = $1,
                         updated_at = NOW()
                     WHERE id = $2",
                    [$token, $rowId]
                ) !== false;
            } else {
                $okWrite = $db->exec(
                    "INSERT INTO rename_token_global_custom (token)
                     VALUES ($1)
                     ON CONFLICT (token)
                     DO UPDATE SET
                        updated_at = NOW()",
                    [$token]
                ) !== false;
            }
            if ($okWrite) {
                header("Location: " . npc_bios_build_url(["tab" => "rename_token", "notice" => "saved_token"], $isEmbed));
                exit;
            }
            $message = "Could not save rename token. It may conflict with an existing value.";
            $messageType = "err";
            $editToken = [
                "id" => $rowId,
                "token" => $token,
            ];
        }
        } elseif ($action === "delete_token") {
        $activeTab = "rename_token";
        $rowId = intval($_POST["row_id"] ?? 0);
        if ($rowId > 0) {
            $db->exec("DELETE FROM rename_token_global_custom WHERE id = $1", [$rowId]);
        }
        header("Location: " . npc_bios_build_url(["tab" => "rename_token", "notice" => "deleted_token"], $isEmbed));
        exit;
        }
    }
}

if (isset($_GET["notice"])) {
    $notice = strtolower(npc_bios_trim($_GET["notice"] ?? ""));
    if ($notice === "saved_random") {
        $message = "Saved bio_random entry.";
    } elseif ($notice === "deleted_random") {
        $message = "Deleted custom bio_random entry.";
    } elseif ($notice === "toggled_random") {
        $message = "Updated bio_random entry state.";
    } elseif ($notice === "toggle_failed_random") {
        $message = "Could not update bio_random entry state.";
        $messageType = "err";
    } elseif ($notice === "saved_unique") {
        $message = "Saved bio_unique entry.";
    } elseif ($notice === "deleted_unique") {
        $message = "Deleted custom bio_unique entry.";
    } elseif ($notice === "toggled_unique") {
        $message = "Updated bio_unique entry state.";
    } elseif ($notice === "toggle_failed_unique") {
        $message = "Could not update bio_unique entry state.";
        $messageType = "err";
    } elseif ($notice === "saved_token") {
        $message = "Saved rename token.";
    } elseif ($notice === "deleted_token") {
        $message = "Deleted custom rename token.";
    } elseif ($notice === "toggled_token") {
        $message = "Updated rename token state.";
    } elseif ($notice === "toggle_failed_token") {
        $message = "Could not update rename token state.";
        $messageType = "err";
    }
}

$editRandomId = intval($_GET["edit_random"] ?? 0);
if (!$editRandom && $editRandomId > 0) {
    $editRandom = $db->fetchOne("SELECT * FROM bio_random_custom WHERE id = $1", [$editRandomId]);
    if (!$editRandom) {
        $message = "Could not find that custom bio_random entry.";
        $messageType = "err";
    } else {
        $activeTab = "bio_random";
    }
}

$editUniqueId = intval($_GET["edit_unique"] ?? 0);
if (!$editUnique && $editUniqueId > 0) {
    $editUnique = $db->fetchOne("SELECT * FROM bio_unique_custom WHERE id = $1", [$editUniqueId]);
    if (!$editUnique) {
        $message = "Could not find that custom bio_unique entry.";
        $messageType = "err";
    } else {
        $activeTab = "bio_unique";
    }
}

$editTokenId = intval($_GET["edit_token"] ?? 0);
if (!$editToken && $editTokenId > 0) {
    $editToken = $db->fetchOne("SELECT * FROM rename_token_global_custom WHERE id = $1", [$editTokenId]);
    if (!$editToken) {
        $message = "Could not find that custom rename token entry.";
        $messageType = "err";
    } else {
        $activeTab = "rename_token";
    }
}

$autoOpenRandomModal = $activeTab === "bio_random" && $editRandom !== false;
$autoOpenUniqueModal = $activeTab === "bio_unique" && $editUnique !== false;
$autoOpenTokenModal = $activeTab === "rename_token" && $editToken !== false;

$randomParams = [];
$randomWhereParts = [];
if ($qRandom !== "") {
    $randomParams[] = "%" . $qRandom . "%";
    $p = "$" . count($randomParams);
    $randomWhereParts[] = "(LOWER(COALESCE(v.type, '')) LIKE LOWER($p)
                    OR LOWER(COALESCE(v.description, '')) LIKE LOWER($p)
                    OR LOWER(COALESCE(v.name, '')) LIKE LOWER($p)
                    OR LOWER(COALESCE(v.race, '')) LIKE LOWER($p)
                    OR LOWER(COALESCE(v.gender, '')) LIKE LOWER($p)
                    OR LOWER(COALESCE(v.faction, '')) LIKE LOWER($p))";
}
if ($letterRandom !== "") {
    $randomParams[] = $letterRandom . "%";
    $p = "$" . count($randomParams);
    $randomWhereParts[] = "LOWER(COALESCE(v.name, '')) LIKE LOWER($p)";
}
if ($enabledRandom === "enabled") {
    $randomWhereParts[] = "COALESCE(v.is_enabled, TRUE) = TRUE";
} elseif ($enabledRandom === "disabled") {
    $randomWhereParts[] = "COALESCE(v.is_enabled, TRUE) = FALSE";
}
$randomWhere = count($randomWhereParts) > 0 ? ("WHERE " . implode(" AND ", $randomWhereParts)) : "";
$randomCountRow = $db->fetchOne(
    "SELECT COUNT(*) AS total FROM combined_bio_random v $randomWhere",
    $randomParams
);
$randomTotalRows = intval(is_array($randomCountRow) ? ($randomCountRow["total"] ?? 0) : 0);
$randomTotalPages = max(1, intval(ceil($randomTotalRows / $browsePageSize)));
$pageRandom = min($pageRandom, $randomTotalPages);
$randomOffset = ($pageRandom - 1) * $browsePageSize;
$randomRows = $db->fetchAll(
    "SELECT
        v.*,
        EXISTS (
            SELECT 1
            FROM bio_random_custom c
            WHERE LOWER(c.type) = LOWER(v.type)
              AND LOWER(c.description) = LOWER(v.description)
              AND LOWER(COALESCE(c.name, '')) = LOWER(COALESCE(v.name, ''))
        ) AS is_custom
     FROM combined_bio_random v
     $randomWhere
     ORDER BY LOWER(COALESCE(v.name, '')), LOWER(COALESCE(v.type, '')), v.id DESC
     LIMIT " . intval($browsePageSize) . " OFFSET " . intval($randomOffset),
    $randomParams
);

$uniqueParams = [];
$uniqueWhereParts = [];
if ($qUnique !== "") {
    $uniqueParams[] = "%" . $qUnique . "%";
    $p = "$" . count($uniqueParams);
    $uniqueWhereParts[] = "(LOWER(COALESCE(v.name, '')) LIKE LOWER($p)
                    OR LOWER(COALESCE(v.type, '')) LIKE LOWER($p)
                    OR LOWER(COALESCE(v.description, '')) LIKE LOWER($p))";
}
if ($letterUnique !== "") {
    $uniqueParams[] = $letterUnique . "%";
    $p = "$" . count($uniqueParams);
    $uniqueWhereParts[] = "LOWER(COALESCE(v.name, '')) LIKE LOWER($p)";
}
if ($enabledUnique === "enabled") {
    $uniqueWhereParts[] = "COALESCE(v.is_enabled, TRUE) = TRUE";
} elseif ($enabledUnique === "disabled") {
    $uniqueWhereParts[] = "COALESCE(v.is_enabled, TRUE) = FALSE";
}
$uniqueWhere = count($uniqueWhereParts) > 0 ? ("WHERE " . implode(" AND ", $uniqueWhereParts)) : "";
$uniqueCountRow = $db->fetchOne(
    "SELECT COUNT(*) AS total FROM combined_bio_unique v $uniqueWhere",
    $uniqueParams
);
$uniqueTotalRows = intval(is_array($uniqueCountRow) ? ($uniqueCountRow["total"] ?? 0) : 0);
$uniqueTotalPages = max(1, intval(ceil($uniqueTotalRows / $browsePageSize)));
$pageUnique = min($pageUnique, $uniqueTotalPages);
$uniqueOffset = ($pageUnique - 1) * $browsePageSize;
$uniqueRows = $db->fetchAll(
    "SELECT
        v.*,
        EXISTS (
            SELECT 1
            FROM bio_unique_custom c
            WHERE LOWER(c.name) = LOWER(v.name)
              AND LOWER(c.type) = LOWER(v.type)
        ) AS is_custom
     FROM combined_bio_unique v
     $uniqueWhere
     ORDER BY LOWER(COALESCE(v.name, '')), LOWER(COALESCE(v.type, '')), v.id DESC
     LIMIT " . intval($browsePageSize) . " OFFSET " . intval($uniqueOffset),
    $uniqueParams
);

$tokenParams = [];
$tokenWhereParts = [];
if ($qToken !== "") {
    $tokenParams[] = "%" . $qToken . "%";
    $p = "$" . count($tokenParams);
    $tokenWhereParts[] = "LOWER(COALESCE(v.token, '')) LIKE LOWER($p)";
}
if ($letterToken !== "") {
    $tokenParams[] = $letterToken . "%";
    $p = "$" . count($tokenParams);
    $tokenWhereParts[] = "LOWER(COALESCE(v.token, '')) LIKE LOWER($p)";
}
if ($enabledToken === "enabled") {
    $tokenWhereParts[] = "COALESCE(v.is_enabled, TRUE) = TRUE";
} elseif ($enabledToken === "disabled") {
    $tokenWhereParts[] = "COALESCE(v.is_enabled, TRUE) = FALSE";
}
$tokenWhere = count($tokenWhereParts) > 0 ? ("WHERE " . implode(" AND ", $tokenWhereParts)) : "";
$tokenCountRow = $db->fetchOne(
    "SELECT COUNT(*) AS total FROM combined_rename_token_global v $tokenWhere",
    $tokenParams
);
$tokenTotalRows = intval(is_array($tokenCountRow) ? ($tokenCountRow["total"] ?? 0) : 0);
$tokenTotalPages = max(1, intval(ceil($tokenTotalRows / $browsePageSize)));
$pageToken = min($pageToken, $tokenTotalPages);
$tokenOffset = ($pageToken - 1) * $browsePageSize;
$tokenRows = $db->fetchAll(
    "SELECT
        v.*,
        EXISTS (
            SELECT 1
            FROM rename_token_global_custom c
            WHERE LOWER(c.token) = LOWER(v.token)
        ) AS is_custom
     FROM combined_rename_token_global v
     $tokenWhere
     ORDER BY LOWER(COALESCE(v.token, '')), v.id DESC
     LIMIT " . intval($browsePageSize) . " OFFSET " . intval($tokenOffset),
    $tokenParams
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NPC Biographies</title>
    <link rel="icon" type="image/x-icon" href="/StobeServer/ui/images/favicon.ico">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        main {
            padding-top: 10px;
            padding-bottom: 24px;
            padding-left: 5px;
            padding-right: 5px;
            width: 100%;
            margin: 0;
        }
        /* Page header is the shared compact inline row (.stobe-page-head in main.css). */
        h1.api-title, h1.api-title * {
            font-family: "MagicCards", serif !important;
        }
        .content-section {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            margin-bottom: 20px;
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
        .tab-buttons {
            display: flex;
            flex-wrap: nowrap;
            margin-bottom: 20px;
            gap: 0;
            border-bottom: 1px solid #3a3a3a;
            align-items: flex-end;
        }
        .tab-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: 1px solid #3a3a3a;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            background: linear-gradient(180deg, rgba(46, 46, 46, 0.92), rgba(36, 36, 36, 0.95));
            color: #c9d3e5;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
            margin-right: 8px;
        }
        .tab-link:hover {
            color: #ffffff;
            border-color: rgba(230, 183, 108, 0.45);
            background: linear-gradient(180deg, rgba(60, 60, 60, 0.95), rgba(44, 44, 44, 0.98));
        }
        .tab-link.active {
            color: #e6b76c;
            border-color: rgba(230, 183, 108, 0.45);
            background: linear-gradient(180deg, rgba(58, 58, 58, 0.98), rgba(46, 46, 46, 1));
        }
        .grid-two {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .form-container {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #3a3a3a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            margin-bottom: 18px;
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
        input[type="text"]:focus, textarea:focus {
            border-color: rgba(230, 183, 108, 0.5);
            outline: none;
            box-shadow: 0 0 0 3px rgba(230, 183, 108, 0.1);
            background: rgba(34, 34, 34, 0.9);
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        .button-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        .info-panel h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #e6b76c;
            font-family: "MagicCards", serif;
            letter-spacing: 1px;
        }
        .info-panel p {
            margin: 0;
            color: #c9d3e5;
            line-height: 1.55;
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
            padding: 12px 10px;
            background: linear-gradient(135deg, rgba(58, 58, 58, 0.95), rgba(48, 48, 48, 0.95));
            color: #e6b76c;
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
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .table-container tr:hover {
            background: rgba(58, 58, 58, 0.5);
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            border: 1px solid #4a4a4a;
            color: #ddd;
        }
        .pill.custom {
            color: #7ce3a0;
            border-color: rgba(124, 227, 160, 0.45);
            background: rgba(30, 90, 40, 0.25);
        }
        .pill.base {
            color: #bbb;
            border-color: rgba(170, 170, 170, 0.35);
            background: rgba(80, 80, 80, 0.25);
        }
        .filter-buttons .active {
            border-color: rgba(234, 238, 5, 0.65);
            box-shadow: 0 0 0 2px rgba(234, 238, 5, 0.12);
        }
        .inline-status {
            display: none;
            margin: 0 0 12px;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid rgba(47, 125, 83, 0.8);
            background: rgba(47, 125, 83, 0.16);
            color: #dff6e9;
        }
        .inline-status.err {
            border-color: rgba(187, 68, 68, 0.8);
            background: rgba(187, 68, 68, 0.16);
        }
        .pagination-bar {
            display: grid;
            grid-template-columns: 120px 1fr 120px;
            align-items: center;
            gap: 12px;
            margin-top: 14px;
            text-align: center;
            color: #c9d3e5;
        }
        .pagination-bar .action-button:last-child {
            justify-self: end;
        }
        .pagination-placeholder {
            min-width: 1px;
        }
        @media (max-width: 1000px) {
            .grid-two { grid-template-columns: 1fr; }
            .content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .pagination-bar {
                grid-template-columns: 1fr 1fr;
            }
            .pagination-bar > span:not(.pagination-placeholder) {
                grid-column: 1 / -1;
                grid-row: 1;
            }
            .pagination-bar .action-button:first-child {
                justify-self: start;
            }
            .pagination-placeholder {
                display: none;
            }
        }
        .modal-content {
            background: #2a2a2a;
            color: #f8f9fa;
            border: 1px solid #4a4a4a;
        }
        .modal-header, .modal-footer {
            border-color: #3a3a3a;
        }
        .modal .btn-close {
            filter: invert(1) grayscale(1);
        }
    </style>
</head>
<body>
<?php if (!$isEmbed): ?>
<?php include(__DIR__ . DIRECTORY_SEPARATOR . "tmpl" . DIRECTORY_SEPARATOR . "navbar.php"); ?>
<?php endif; ?>

<main>
    <div class="page-header stobe-page-head">
        <h1 class="api-title stobe-page-head-title">NPC Biographies</h1>
        <p class="page-subtitle stobe-page-head-note">Configure random and unique biography pools plus rename eligibility tokens for NPC imports</p>
    </div>

    <div class="content-section">
        <div class="tab-buttons">
            <a class="tab-link <?= $activeTab === "bio_random" ? "active" : "" ?>"
               href="<?= h(npc_bios_build_url(["tab" => "bio_random", "q_random" => $qRandom, "q_unique" => $qUnique, "q_token" => $qToken, "letter_random" => $letterRandom, "letter_unique" => $letterUnique, "letter_token" => $letterToken], $isEmbed)) ?>">Random</a>
            <a class="tab-link <?= $activeTab === "bio_unique" ? "active" : "" ?>"
               href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "q_random" => $qRandom, "q_unique" => $qUnique, "q_token" => $qToken, "letter_random" => $letterRandom, "letter_unique" => $letterUnique, "letter_token" => $letterToken], $isEmbed)) ?>">Unique</a>
            <a class="tab-link <?= $activeTab === "rename_token" ? "active" : "" ?>"
               href="<?= h(npc_bios_build_url(["tab" => "rename_token", "q_random" => $qRandom, "q_unique" => $qUnique, "q_token" => $qToken, "letter_random" => $letterRandom, "letter_unique" => $letterUnique, "letter_token" => $letterToken], $isEmbed)) ?>">Rename Tokens</a>
        </div>

        <?php if ($message !== ""): ?>
            <div class="form-container" style="border-color: <?= $messageType === "err" ? "#bb4444" : "#2f7d53" ?>;">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($activeTab === "bio_random"): ?>
            <div class="content-grid">
                <div class="content-section">
                    <h2>CSV Upload</h2>
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <label>Select .csv file to upload:</label>
                        <input type="file" name="csv_file_random" accept=".csv" required>
                        <div class="button-row" style="margin-top:10px;">
                            <button class="action-button upload-csv" type="submit" name="submit_csv_random">Upload CSV</button>
                            <a class="action-button download-csv" href="<?= h(npc_bios_build_url(["tab" => "bio_random", "action" => "download_example_bio_random"], $isEmbed)) ?>">Download Example CSV</a>
                            <a class="action-button export-csv" href="<?= h(npc_bios_build_url(["tab" => "bio_random", "action" => "export_custom_bio_random"], $isEmbed)) ?>">Export Custom Descriptions</a>
                        </div>
                    </form>
                </div>
                <div class="content-section info-panel">
                    <h2>Bio Random</h2>
                    <p>This page manages reusable biography text pools applied to non-unique NPCs when they are imported. Match rules can be broad (type only) or constrained by name, race, gender, and faction. Use <code>appearance</code> as the type when you want to append extra appearance text onto the generated NPC appearance.</p>
                </div>
            </div>

            <div class="content-section full-width-section">
            <div id="randomToggleStatus" class="inline-status" role="status" aria-live="polite"></div>
            <div class="action-container">
                <button type="button" class="action-button add-new" data-bs-toggle="modal" data-bs-target="#bioRandomModal">Add Custom bio_random Entry</button>
                <form class="search-container" method="get" action="">
                    <input type="hidden" name="tab" value="bio_random">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <?php if ($letterRandom !== ""): ?><input type="hidden" name="letter_random" value="<?= h($letterRandom) ?>"><?php endif; ?>
                    <input type="hidden" name="enabled_random" value="<?= h($enabledRandom) ?>">
                    <input type="text" name="q_random" value="<?= h($qRandom) ?>" placeholder="Search type, description, name, race, gender, or faction">
                    <button type="submit" class="action-button edit">Search</button>
                    <a class="action-button" href="<?= h(npc_bios_build_url(["tab" => "bio_random"], $isEmbed)) ?>">Clear</a>
                </form>
            </div>

            <div class="filter-section">
                <strong>Filter by State:</strong>
                <div class="filter-buttons">
                    <a class="alphabet-button <?= $enabledRandom === "all" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "bio_random", "q_random" => $qRandom, "letter_random" => $letterRandom, "enabled_random" => "all"], $isEmbed)) ?>">All</a>
                    <a class="alphabet-button <?= $enabledRandom === "enabled" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "bio_random", "q_random" => $qRandom, "letter_random" => $letterRandom, "enabled_random" => "enabled"], $isEmbed)) ?>">Enabled</a>
                    <a class="alphabet-button <?= $enabledRandom === "disabled" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "bio_random", "q_random" => $qRandom, "letter_random" => $letterRandom, "enabled_random" => "disabled"], $isEmbed)) ?>">Disabled</a>
                </div>
            </div>

            <div class="filter-section">
                <strong>Filter by Name:</strong>
                <div class="filter-buttons">
                    <a class="alphabet-button" href="<?= h(npc_bios_build_url(["tab" => "bio_random", "q_random" => $qRandom, "enabled_random" => $enabledRandom], $isEmbed)) ?>">All</a>
                    <?php foreach (range("A", "Z") as $char): ?>
                        <a class="alphabet-button" href="<?= h(npc_bios_build_url(["tab" => "bio_random", "q_random" => $qRandom, "letter_random" => $char, "enabled_random" => $enabledRandom], $isEmbed)) ?>"><?= h($char) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Race</th>
                            <th>Gender</th>
                            <th>Faction</th>
                            <th>Description</th>
                            <th>Source</th>
                            <th>State</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($randomRows) === 0): ?>
                            <tr><td colspan="9">No rows found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($randomRows as $row): ?>
                            <?php $isCustom = npc_bios_is_true($row["is_custom"] ?? false); ?>
                            <?php $isEnabled = npc_bios_is_true($row["is_enabled"] ?? true); ?>
                            <?php $source = $isCustom ? "custom" : "base"; ?>
                            <tr data-random-bio-row="1" data-enabled="<?= $isEnabled ? "1" : "0" ?>">
                                <td><?= h($row["type"] ?? "") ?></td>
                                <td><?= h($row["name"] ?? "") ?></td>
                                <td><?= h($row["race"] ?? "") ?></td>
                                <td><?= h($row["gender"] ?? "") ?></td>
                                <td><?= h($row["faction"] ?? "") ?></td>
                                <td><?= nl2br(h($row["description"] ?? "")) ?></td>
                                <td><span class="pill <?= $isCustom ? "custom" : "base" ?>"><?= $isCustom ? "custom" : "base" ?></span></td>
                                <td>
                                    <span class="random-state-label" style="color:<?= $isEnabled ? "#4caf50" : "#f44336" ?>;">
                                        <?= $isEnabled ? "Enabled" : "Disabled" ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="" class="enabled-toggle-form" data-enabled-filter="<?= h($enabledRandom) ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_random_enabled">
                                        <input type="hidden" name="row_id" value="<?= h($row["id"] ?? "") ?>">
                                        <input type="hidden" name="source" value="<?= h($source) ?>">
                                        <input type="hidden" name="target_enabled" value="<?= $isEnabled ? "0" : "1" ?>">
                                        <input type="hidden" name="q_random" value="<?= h($qRandom) ?>">
                                        <input type="hidden" name="letter_random" value="<?= h($letterRandom) ?>">
                                        <input type="hidden" name="enabled_random" value="<?= h($enabledRandom) ?>">
                                        <input type="hidden" name="page_random" value="<?= intval($pageRandom) ?>">
                                        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                        <button class="<?= $isEnabled ? "btn-danger" : "btn-save" ?>" type="submit">
                                            <?= $isEnabled ? "Disable" : "Enable" ?>
                                        </button>
                                    </form>
                                    <?php if ($isCustom): ?>
                                        <a class="action-button edit"
                                           href="<?= h(npc_bios_build_url(["tab" => "bio_random", "edit_random" => intval($row["id"] ?? 0), "q_random" => $qRandom, "letter_random" => $letterRandom, "enabled_random" => $enabledRandom], $isEmbed)) ?>">Edit</a>
                                        <form method="post" action="" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_random">
                                            <input type="hidden" name="row_id" value="<?= h($row["id"] ?? "") ?>">
                                            <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                            <button class="btn-danger" type="submit" onclick="return confirm('Delete this custom bio_random entry?');">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php npc_bios_render_pagination(
                $pageRandom,
                $randomTotalRows,
                $browsePageSize,
                "page_random",
                [
                    "tab" => "bio_random",
                    "q_random" => $qRandom,
                    "letter_random" => $letterRandom,
                    "enabled_random" => $enabledRandom,
                ],
                $isEmbed
            ); ?>
            </div>

            <div class="modal fade" id="bioRandomModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?= $editRandom ? "Edit Custom bio_random Entry" : "Add Custom bio_random Entry" ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="post" action="">
                            <div class="modal-body">
                                <input type="hidden" name="action" value="save_random">
                                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                <?php if ($editRandom): ?><input type="hidden" name="row_id" value="<?= h($editRandom["id"] ?? 0) ?>"><?php endif; ?>

                                <div class="grid-two">
                                    <div>
                                        <label>Type</label>
                                        <select name="type" required>
                                            <?php foreach ($validTypes as $t): ?>
                                                <option value="<?= h($t) ?>" <?= strtolower(strval($editRandom["type"] ?? "")) === $t ? "selected" : "" ?>><?= h($t) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label>Name Match (optional)</label>
                                        <input type="text" name="name" value="<?= h($editRandom["name"] ?? "") ?>" maxlength="255" placeholder="Original NPC name">
                                    </div>
                                    <div>
                                        <label>Race (optional)</label>
                                        <input type="text" name="race" value="<?= h($editRandom["race"] ?? "") ?>" maxlength="64">
                                    </div>
                                    <div>
                                        <label>Gender (optional)</label>
                                        <input type="text" name="gender" value="<?= h($editRandom["gender"] ?? "") ?>" maxlength="16">
                                    </div>
                                    <div style="grid-column: 1 / -1;">
                                        <label>Faction (optional)</label>
                                        <input type="text" name="faction" value="<?= h($editRandom["faction"] ?? "") ?>" maxlength="128">
                                    </div>
                                </div>
                                <label>Description</label>
                                <textarea name="description" required><?= h($editRandom["description"] ?? "") ?></textarea>
                                <label for="random-voice-filter">Voice Filter</label>
                                <select name="tts_filter_preset" id="random-voice-filter">
                                    <option value="">Keep current / no preset</option>
                                    <?php foreach (stobeTtsFilterPresetOptions() as $presetId => $preset): ?>
                                    <option value="<?= h($presetId) ?>" <?= ($editRandom['tts_filter_preset'] ?? '') === $presetId ? 'selected' : '' ?>><?= h($preset['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small>New NPCs only. Unique presets win, then the first configured trait in the listed order. None disables filtering.</small>
                                <small class="hint">Use <code>appearance</code> as the type to append this text to the generated NPC appearance.</small>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Close</button>
                                <button class="btn-save" type="submit"><?= $editRandom ? "Update Entry" : "Save" ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif ($activeTab === "bio_unique"): ?>
            <div class="content-grid">
                <div class="content-section">
                    <h2>CSV Upload</h2>
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <label>Select .csv file to upload:</label>
                        <input type="file" name="csv_file_unique" accept=".csv" required>
                        <div class="button-row" style="margin-top:10px;">
                            <button class="action-button upload-csv" type="submit" name="submit_csv_unique">Upload CSV</button>
                            <a class="action-button download-csv" href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "action" => "download_example_bio_unique"], $isEmbed)) ?>">Download Example CSV</a>
                            <a class="action-button export-csv" href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "action" => "export_custom_bio_unique"], $isEmbed)) ?>">Export Custom Descriptions</a>
                        </div>
                    </form>
                </div>
                <div class="content-section info-panel">
                    <h2>Bio Unique</h2>
                    <p>This page stores biography overrides for specific named characters. When a matching unique NPC is imported, these entries take priority over random biography pools. Use <code>appearance</code> as the type when you want to append extra appearance text onto the generated NPC appearance.</p>
                </div>
            </div>

            <div class="content-section full-width-section">
            <div id="randomToggleStatus" class="inline-status" role="status" aria-live="polite"></div>
            <div class="action-container">
                <button type="button" class="action-button add-new" data-bs-toggle="modal" data-bs-target="#bioUniqueModal">Add Custom bio_unique Entry</button>
                <form class="search-container" method="get" action="">
                    <input type="hidden" name="tab" value="bio_unique">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <?php if ($letterUnique !== ""): ?><input type="hidden" name="letter_unique" value="<?= h($letterUnique) ?>"><?php endif; ?>
                    <input type="hidden" name="enabled_unique" value="<?= h($enabledUnique) ?>">
                    <input type="text" name="q_unique" value="<?= h($qUnique) ?>" placeholder="Search name, type, or description">
                    <button type="submit" class="action-button edit">Search</button>
                    <a class="action-button" href="<?= h(npc_bios_build_url(["tab" => "bio_unique"], $isEmbed)) ?>">Clear</a>
                </form>
            </div>

            <div class="filter-section">
                <strong>Filter by State:</strong>
                <div class="filter-buttons">
                    <a class="alphabet-button <?= $enabledUnique === "all" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "q_unique" => $qUnique, "letter_unique" => $letterUnique, "enabled_unique" => "all"], $isEmbed)) ?>">All</a>
                    <a class="alphabet-button <?= $enabledUnique === "enabled" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "q_unique" => $qUnique, "letter_unique" => $letterUnique, "enabled_unique" => "enabled"], $isEmbed)) ?>">Enabled</a>
                    <a class="alphabet-button <?= $enabledUnique === "disabled" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "q_unique" => $qUnique, "letter_unique" => $letterUnique, "enabled_unique" => "disabled"], $isEmbed)) ?>">Disabled</a>
                </div>
            </div>

            <div class="filter-section">
                <strong>Filter by Name:</strong>
                <div class="filter-buttons">
                    <a class="alphabet-button" href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "q_unique" => $qUnique, "enabled_unique" => $enabledUnique], $isEmbed)) ?>">All</a>
                    <?php foreach (range("A", "Z") as $char): ?>
                        <a class="alphabet-button" href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "q_unique" => $qUnique, "letter_unique" => $char, "enabled_unique" => $enabledUnique], $isEmbed)) ?>"><?= h($char) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Source</th>
                            <th>State</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($uniqueRows) === 0): ?>
                            <tr><td colspan="6">No rows found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($uniqueRows as $row): ?>
                            <?php $isCustom = npc_bios_is_true($row["is_custom"] ?? false); ?>
                            <?php $isEnabled = npc_bios_is_true($row["is_enabled"] ?? true); ?>
                            <?php $source = $isCustom ? "custom" : "base"; ?>
                            <tr data-enabled-row="1" data-enabled="<?= $isEnabled ? "1" : "0" ?>">
                                <td><?= h($row["name"] ?? "") ?></td>
                                <td><?= h($row["type"] ?? "") ?></td>
                                <td><?= nl2br(h($row["description"] ?? "")) ?></td>
                                <td><span class="pill <?= $isCustom ? "custom" : "base" ?>"><?= $isCustom ? "custom" : "base" ?></span></td>
                                <td>
                                    <span class="enabled-state-label" style="color:<?= $isEnabled ? "#4caf50" : "#f44336" ?>;">
                                        <?= $isEnabled ? "Enabled" : "Disabled" ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="" class="enabled-toggle-form" data-enabled-filter="<?= h($enabledUnique) ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_unique_enabled">
                                        <input type="hidden" name="row_id" value="<?= h($row["id"] ?? "") ?>">
                                        <input type="hidden" name="source" value="<?= h($source) ?>">
                                        <input type="hidden" name="target_enabled" value="<?= $isEnabled ? "0" : "1" ?>">
                                        <input type="hidden" name="q_unique" value="<?= h($qUnique) ?>">
                                        <input type="hidden" name="letter_unique" value="<?= h($letterUnique) ?>">
                                        <input type="hidden" name="enabled_unique" value="<?= h($enabledUnique) ?>">
                                        <input type="hidden" name="page_unique" value="<?= intval($pageUnique) ?>">
                                        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                        <button class="<?= $isEnabled ? "btn-danger" : "btn-save" ?>" type="submit">
                                            <?= $isEnabled ? "Disable" : "Enable" ?>
                                        </button>
                                    </form>
                                    <?php if ($isCustom): ?>
                                        <a class="action-button edit"
                                           href="<?= h(npc_bios_build_url(["tab" => "bio_unique", "edit_unique" => intval($row["id"] ?? 0), "q_unique" => $qUnique, "letter_unique" => $letterUnique, "enabled_unique" => $enabledUnique], $isEmbed)) ?>">Edit</a>
                                        <form method="post" action="" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_unique">
                                            <input type="hidden" name="row_id" value="<?= h($row["id"] ?? "") ?>">
                                            <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                            <button class="btn-danger" type="submit" onclick="return confirm('Delete this custom bio_unique entry?');">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php npc_bios_render_pagination(
                $pageUnique,
                $uniqueTotalRows,
                $browsePageSize,
                "page_unique",
                [
                    "tab" => "bio_unique",
                    "q_unique" => $qUnique,
                    "letter_unique" => $letterUnique,
                    "enabled_unique" => $enabledUnique,
                ],
                $isEmbed
            ); ?>
            </div>

            <div class="modal fade" id="bioUniqueModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?= $editUnique ? "Edit Custom bio_unique Entry" : "Add Custom bio_unique Entry" ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="post" action="">
                            <div class="modal-body">
                                <input type="hidden" name="action" value="save_unique">
                                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                <?php if ($editUnique): ?><input type="hidden" name="row_id" value="<?= h($editUnique["id"] ?? 0) ?>"><?php endif; ?>

                                <div class="grid-two">
                                    <div>
                                        <label>Name</label>
                                        <input type="text" name="name" required value="<?= h($editUnique["name"] ?? "") ?>" maxlength="255">
                                    </div>
                                    <div>
                                        <label>Type</label>
                                        <select name="type" required>
                                            <?php foreach ($validTypes as $t): ?>
                                                <option value="<?= h($t) ?>" <?= strtolower(strval($editUnique["type"] ?? "")) === $t ? "selected" : "" ?>><?= h($t) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <label>Description</label>
                                <textarea name="description" required><?= h($editUnique["description"] ?? "") ?></textarea>
                                <label for="unique-voice-filter">Voice Filter</label>
                                <select name="tts_filter_preset" id="unique-voice-filter">
                                    <option value="">Keep current / no preset</option>
                                    <?php foreach (stobeTtsFilterPresetOptions() as $presetId => $preset): ?>
                                    <option value="<?= h($presetId) ?>" <?= ($editUnique['tts_filter_preset'] ?? '') === $presetId ? 'selected' : '' ?>><?= h($preset['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small>New NPCs only. Unique presets win, then the first configured trait in the listed order. None disables filtering.</small>
                                <small class="hint">Use <code>appearance</code> as the type to append this text to the generated NPC appearance.</small>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Close</button>
                                <button class="btn-save" type="submit"><?= $editUnique ? "Update Entry" : "Save" ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="content-grid">
                <div class="content-section">
                    <h2>CSV Upload</h2>
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <label>Select .csv file to upload:</label>
                        <input type="file" name="csv_file_token" accept=".csv" required>
                        <div class="button-row" style="margin-top:10px;">
                            <button class="action-button upload-csv" type="submit" name="submit_csv_token">Upload CSV</button>
                            <a class="action-button download-csv" href="<?= h(npc_bios_build_url(["tab" => "rename_token", "action" => "download_example_rename_token"], $isEmbed)) ?>">Download Example CSV</a>
                            <a class="action-button export-csv" href="<?= h(npc_bios_build_url(["tab" => "rename_token", "action" => "export_custom_rename_token"], $isEmbed)) ?>">Export Custom Tokens</a>
                        </div>
                    </form>
                </div>
                <div class="content-section info-panel">
                    <h2>Rename Tokens</h2>
                    <p>Upload rename eligibility tokens by CSV. Use one token per row under <code>token</code> (or <code>stringid</code>/<code>name</code>).</p>
                </div>
            </div>

            <div class="content-section full-width-section">
            <div id="randomToggleStatus" class="inline-status" role="status" aria-live="polite"></div>
            <div class="action-container">
                <button type="button" class="action-button add-new" data-bs-toggle="modal" data-bs-target="#renameTokenModal">Add Custom Rename Token</button>
                <form class="search-container" method="get" action="">
                    <input type="hidden" name="tab" value="rename_token">
                    <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <?php if ($letterToken !== ""): ?><input type="hidden" name="letter_token" value="<?= h($letterToken) ?>"><?php endif; ?>
                    <input type="hidden" name="enabled_token" value="<?= h($enabledToken) ?>">
                    <input type="text" name="q_token" value="<?= h($qToken) ?>" placeholder="Search token">
                    <button type="submit" class="action-button edit">Search</button>
                    <a class="action-button" href="<?= h(npc_bios_build_url(["tab" => "rename_token"], $isEmbed)) ?>">Clear</a>
                </form>
            </div>

            <div class="filter-section">
                <strong>Filter by State:</strong>
                <div class="filter-buttons">
                    <a class="alphabet-button <?= $enabledToken === "all" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "rename_token", "q_token" => $qToken, "letter_token" => $letterToken, "enabled_token" => "all"], $isEmbed)) ?>">All</a>
                    <a class="alphabet-button <?= $enabledToken === "enabled" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "rename_token", "q_token" => $qToken, "letter_token" => $letterToken, "enabled_token" => "enabled"], $isEmbed)) ?>">Enabled</a>
                    <a class="alphabet-button <?= $enabledToken === "disabled" ? "active" : "" ?>" href="<?= h(npc_bios_build_url(["tab" => "rename_token", "q_token" => $qToken, "letter_token" => $letterToken, "enabled_token" => "disabled"], $isEmbed)) ?>">Disabled</a>
                </div>
            </div>

            <div class="filter-section">
                <strong>Filter by Token:</strong>
                <div class="filter-buttons">
                    <a class="alphabet-button" href="<?= h(npc_bios_build_url(["tab" => "rename_token", "q_token" => $qToken, "enabled_token" => $enabledToken], $isEmbed)) ?>">All</a>
                    <?php foreach (range("A", "Z") as $char): ?>
                        <a class="alphabet-button" href="<?= h(npc_bios_build_url(["tab" => "rename_token", "q_token" => $qToken, "letter_token" => $char, "enabled_token" => $enabledToken], $isEmbed)) ?>"><?= h($char) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Source</th>
                            <th>State</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($tokenRows) === 0): ?>
                            <tr><td colspan="4">No tokens found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($tokenRows as $row): ?>
                            <?php $isCustom = npc_bios_is_true($row["is_custom"] ?? false); ?>
                            <?php $isEnabled = npc_bios_is_true($row["is_enabled"] ?? true); ?>
                            <?php $source = $isCustom ? "custom" : "base"; ?>
                            <tr data-enabled-row="1" data-enabled="<?= $isEnabled ? "1" : "0" ?>">
                                <td><?= h($row["token"] ?? "") ?></td>
                                <td><span class="pill <?= $isCustom ? "custom" : "base" ?>"><?= $isCustom ? "custom" : "base" ?></span></td>
                                <td>
                                    <span class="enabled-state-label" style="color:<?= $isEnabled ? "#4caf50" : "#f44336" ?>;">
                                        <?= $isEnabled ? "Enabled" : "Disabled" ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="" class="enabled-toggle-form" data-enabled-filter="<?= h($enabledToken) ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_token_enabled">
                                        <input type="hidden" name="row_id" value="<?= h($row["id"] ?? "") ?>">
                                        <input type="hidden" name="source" value="<?= h($source) ?>">
                                        <input type="hidden" name="target_enabled" value="<?= $isEnabled ? "0" : "1" ?>">
                                        <input type="hidden" name="q_token" value="<?= h($qToken) ?>">
                                        <input type="hidden" name="letter_token" value="<?= h($letterToken) ?>">
                                        <input type="hidden" name="enabled_token" value="<?= h($enabledToken) ?>">
                                        <input type="hidden" name="page_token" value="<?= intval($pageToken) ?>">
                                        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                        <button class="<?= $isEnabled ? "btn-danger" : "btn-save" ?>" type="submit">
                                            <?= $isEnabled ? "Disable" : "Enable" ?>
                                        </button>
                                    </form>
                                    <?php if ($isCustom): ?>
                                        <a class="action-button edit"
                                           href="<?= h(npc_bios_build_url(["tab" => "rename_token", "edit_token" => intval($row["id"] ?? 0), "q_token" => $qToken, "letter_token" => $letterToken, "enabled_token" => $enabledToken], $isEmbed)) ?>">Edit</a>
                                        <form method="post" action="" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_token">
                                            <input type="hidden" name="row_id" value="<?= h($row["id"] ?? "") ?>">
                                            <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                            <button class="btn-danger" type="submit" onclick="return confirm('Delete this custom rename token?');">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php npc_bios_render_pagination(
                $pageToken,
                $tokenTotalRows,
                $browsePageSize,
                "page_token",
                [
                    "tab" => "rename_token",
                    "q_token" => $qToken,
                    "letter_token" => $letterToken,
                    "enabled_token" => $enabledToken,
                ],
                $isEmbed
            ); ?>
            </div>

            <div class="modal fade" id="renameTokenModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?= $editToken ? "Edit Custom Rename Token" : "Add Custom Rename Token" ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="post" action="">
                            <div class="modal-body">
                                <input type="hidden" name="action" value="save_token">
                                <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                                <?php if ($editToken): ?><input type="hidden" name="row_id" value="<?= h($editToken["id"] ?? 0) ?>"><?php endif; ?>

                                <label>Token</label>
                                <input type="text" name="token" required value="<?= h($editToken["token"] ?? "") ?>" maxlength="128" placeholder="e.g. police chief">
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn-cancel" data-bs-dismiss="modal">Close</button>
                                <button class="btn-save" type="submit"><?= $editToken ? "Update Token" : "Save" ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const openRandom = <?= $autoOpenRandomModal ? 'true' : 'false' ?>;
    const openUnique = <?= $autoOpenUniqueModal ? 'true' : 'false' ?>;
    const openToken = <?= $autoOpenTokenModal ? 'true' : 'false' ?>;
    const randomStatus = document.getElementById('randomToggleStatus');

    function showRandomStatus(message, isError) {
        if (!randomStatus) {
            return;
        }
        randomStatus.textContent = message;
        randomStatus.classList.toggle('err', Boolean(isError));
        randomStatus.style.display = 'block';
        window.setTimeout(function () {
            randomStatus.style.display = 'none';
        }, 3000);
    }

    document.querySelectorAll('.enabled-toggle-form').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            const targetInput = form.querySelector('input[name="target_enabled"]');
            const row = form.closest('tr[data-random-bio-row="1"], tr[data-enabled-row="1"]');
            if (!button || !targetInput || !row) {
                form.submit();
                return;
            }

            button.disabled = true;
            try {
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'Could not update bio_random entry state.');
                }

                const enabled = Boolean(data.enabled);
                row.dataset.enabled = enabled ? '1' : '0';
                targetInput.value = enabled ? '0' : '1';

                const label = row.querySelector('.random-state-label, .enabled-state-label');
                if (label) {
                    label.textContent = enabled ? 'Enabled' : 'Disabled';
                    label.style.color = enabled ? '#4caf50' : '#f44336';
                }

                button.textContent = enabled ? 'Disable' : 'Enable';
                button.classList.toggle('btn-danger', enabled);
                button.classList.toggle('btn-save', !enabled);

                const activeEnabledFilter = form.dataset.enabledFilter || 'all';
                if (activeEnabledFilter !== 'all' && activeEnabledFilter !== (enabled ? 'enabled' : 'disabled')) {
                    row.style.display = 'none';
                }

                showRandomStatus(data.message || 'Updated bio_random entry state.', false);
            } catch (error) {
                showRandomStatus(error.message || 'Could not update bio_random entry state.', true);
            } finally {
                button.disabled = false;
            }
        });
    });

    if (openRandom) {
        const el = document.getElementById('bioRandomModal');
        if (el) {
            const modal = new bootstrap.Modal(el);
            modal.show();
        }
    }
    if (openUnique) {
        const el = document.getElementById('bioUniqueModal');
        if (el) {
            const modal = new bootstrap.Modal(el);
            modal.show();
        }
    }
    if (openToken) {
        const el = document.getElementById('renameTokenModal');
        if (el) {
            const modal = new bootstrap.Modal(el);
            modal.show();
        }
    }
});
</script>
</body>
</html>

