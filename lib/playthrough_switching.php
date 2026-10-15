<?php
require_once __DIR__ . '/playthrough_home.php';

// Match frozen character metadata, never mutable display labels or approximate names.
function pas_select(array $rows, string $character, string $name, int $gamets, bool $allowLegacy = true, array $links = [], array $members = []): ?array {
    if ($character !== '' && isset($links[$character])) {
        foreach ($rows as $row) {
            if ($row['id'] === $links[$character] && !empty($row['available']) && ($row['character_id'] ?? '') === $character) return $row;
        }
        throw new RuntimeException('The linked Playthrough Save is unavailable. Choose a Playthrough Save for this campaign.');
    }
    if ($character === '' || !array_filter($rows, static fn($r) => ($r['character_id'] ?? '') === $character)) {
        $key = static function ($names) { $names = array_map(static fn($n) => mb_strtolower(trim($n), 'UTF-8'), $names); sort($names); return $names; };
        $expected = $key($members);
        foreach ($rows as &$row) $row['player_name'] = $expected && $key($row['player_faction_members'] ?? []) === $expected ? $name : '';
        unset($row);
    }
    $matches = array_values(array_filter($rows, static function ($row) use ($character, $name) {
        if (empty($row['available'])) return false;
        if ($character !== '') return ($row['character_id'] ?? '') === $character;
        return mb_strtolower(trim($row['player_name'] ?? ''), 'UTF-8') === mb_strtolower(trim($name), 'UTF-8');
    }));
    if (!$matches && $character !== '' && $allowLegacy) {
        // Older saves have no character ID; rank their exact-name snapshots below.
        $matches = array_values(array_filter($rows, static fn($row) => !empty($row['available'])
            && empty($row['character_id']) && mb_strtolower(trim($row['player_name'] ?? ''), 'UTF-8') === mb_strtolower(trim($name), 'UTF-8')));
    }
    if (!$matches) return null;
    if ($character === '') {
        // Old Kenshi saves may lack our campaign ID; honor a unique exact-roster association.
        $known = array_values(array_unique(array_filter(array_column($matches, 'character_id'), static fn($id) => isset($links[$id]))));
        if (count($known) === 1) return pas_select($rows, $known[0], $name, $gamets, $allowLegacy, $links, $members);
        if (count($known) > 1) throw new RuntimeException('More than one campaign has this party. Choose its Playthrough Save.');
    }
    foreach ($matches as $row) if ($row['active']) return $row;
    usort($matches, static function ($a, $b) use ($gamets) {
        $distance = abs($a['last_gamets'] - $gamets) <=> abs($b['last_gamets'] - $gamets);
        if ($distance !== 0) return $distance;
        return ($b['last_gamets'] <=> $a['last_gamets']) ?: ($b['id'] <=> $a['id']);
    });
    return $matches[0];
}

function pas_enabled($conn): bool { return ptr_read($conn, 'PLAYTHROUGH_AUTO_SWITCH', false) === true; }

// Character destinations are global metadata, never part of a restored gameplay snapshot.
function pas_link($conn, string $character, int $profile): void {
    $links = ptr_read($conn, 'PLAYTHROUGH_CHARACTER_LINKS', []);
    foreach ($links as $other => $id) if ($id === $profile && $other !== $character) unset($links[$other]);
    $links[$character] = $profile;
    ptr_write($conn, 'PLAYTHROUGH_CHARACTER_LINKS', $links);
}

// Keep the load high-water mark when manual actions revoke a receipt.
function pas_invalidate($conn): void {
    $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
    $session['status'] = 'waiting';
    $session['message'] = 'Reload your Kenshi save to connect its campaign.';
    unset($session['token']);
    ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
}

// The shared runtime lease makes this check and the subsequent writes one generation.
function pas_guard($conn, bool $required = false): void {
    if (PHP_SAPI === 'cli' || !empty($GLOBALS['pas_checked'])) return;
    $token = $_SERVER['HTTP_X_STOBE_PLAYTHROUGH'] ?? '';
    $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
    if (!$required && $token === '' && empty($session['client_id'])) return;
    $identity = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.conf_opts WHERE id='PLAYTHROUGH_CAMPAIGN_ID'"));
    if (pas_receipt_valid($session, $token) && hash_equals($session['character_id'], $identity['value'] ?? '')) { $GLOBALS['pas_checked'] = true; return; }
    http_response_code(409);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'message'=>'STOBE is waiting for the loaded campaign. Open Playthrough Saves.']);
    exit;
}

// A legacy header is never authority, including when automatic profile switching is off.
function pas_receipt_valid(array $session, $token): bool {
    return is_string($token) && preg_match('/^[a-f0-9]{32}$/D', $token)
        && ($session['status'] ?? '') === 'ready'
        && is_string($session['character_id'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/D', $session['character_id'])
        && is_string($session['token'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/D', $session['token'])
        && hash_equals($session['token'], $token);
}

// Authenticate the live campaign only. No profile manager, selection or restore APIs.
function pas_off_handshake($conn, array $input, string $name): array {
    $requestDigest = hash('sha256', json_encode([$input['client_id'], $input['load_id'], $input['character_id'],
        $input['new_game'] ?? false, $input['player_name'], $input['gamets'], $input['player_members'] ?? []], JSON_THROW_ON_ERROR));
    pth_query($conn, 'BEGIN');
    try {
        // Receipt metadata is the only schema this path needs; do not run profile upgrades.
        pth_query($conn, 'CREATE SCHEMA IF NOT EXISTS stobe_meta');
        pth_query($conn, 'CREATE TABLE IF NOT EXISTS stobe_meta.settings (key TEXT PRIMARY KEY, value TEXT)');
        $last = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
        $retired = ptr_read($conn, 'PLAYTHROUGH_RETIRED_CLIENTS', []);
        $identity = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.conf_opts WHERE id='PLAYTHROUGH_CAMPAIGN_ID' FOR UPDATE"));
        if (in_array($input['client_id'], $retired, true)) throw new RuntimeException('This Kenshi session was replaced.');
        if (($last['client_id'] ?? '') === $input['client_id']) {
            if (($last['load_id'] ?? 0) > $input['load_id']) throw new RuntimeException('This load request is stale.');
            if (($last['load_id'] ?? 0) === $input['load_id']) {
                if (($last['load_character_id'] ?? null) !== $input['character_id']
                    || ($last['request_digest'] ?? '') !== $requestDigest) throw new RuntimeException('Conflicting load identity.');
                if (($last['status'] ?? '') === 'ready' && ($last['character_id'] ?? '') !== ($identity['value'] ?? '')) throw new RuntimeException('The current campaign changed.');
                pth_query($conn, 'COMMIT');
                return ['ok'=>($last['status'] ?? '') === 'ready'] + $last;
            }
        }
        if (!empty($last['client_id']) && $last['client_id'] !== $input['client_id']) {
            $retired[] = $last['client_id'];
            ptr_write($conn, 'PLAYTHROUGH_RETIRED_CLIENTS', array_slice($retired, -32));
        }
        $session = array_intersect_key($input, array_flip(['client_id','load_id','character_id','gamets']));
        $session += ['load_character_id'=>$input['character_id'], 'new_game'=>$input['new_game'] ?? false,
            'request_digest'=>$requestDigest, 'auth_mode'=>'current_campaign', 'player_name'=>$name, 'status'=>'pending', 'message'=>'The loaded campaign identity does not match the current campaign.'];
        $character = $identity['value'] ?? '';
        $unbound = !$identity || $character === '';
        // Switching is off, so there is one shared campaign. An explicit new game (or a save written by a
        // new game before its first handshake succeeded) carries a fresh id: adopt it instead of locking
        // Stobe out. Loads (new_game false) of a different campaign stay rejected.
        if (!$unbound && !empty($input['new_game']) && preg_match('/^[a-f0-9]{32}$/D', $input['character_id'])) {
            $unbound = true;
            $character = $input['character_id'];
        }
        // First genuine load may bind the previously unbound public campaign. Never repair
        // malformed existing identity or guess identity from names/party/profile metadata.
        if ($unbound) {
            $character = $input['character_id'] !== '' ? $input['character_id'] : bin2hex(random_bytes(16));
            pth_query($conn, "INSERT INTO public.conf_opts(id,value) VALUES('PLAYTHROUGH_CAMPAIGN_ID',$1) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value", [$character]);
        }
        if (($unbound || empty($input['new_game'])) && preg_match('/^[a-f0-9]{32}$/D', $character)
            && ($input['character_id'] === '' || hash_equals($character, $input['character_id']))) {
            $session['character_id'] = $character;
            $session['status'] = 'ready';
            $session['token'] = bin2hex(random_bytes(16));
            $session['message'] = '';
        }
        ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
        pth_query($conn, 'COMMIT');
        return ['ok'=>$session['status'] === 'ready'] + $session;
    } catch (Throwable $error) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) @pg_query($conn, 'ROLLBACK');
        throw $error;
    }
}

// Tagged uploads must be checked before file writes or connector calls, not just SQL writes.
function pas_http_guard(bool $required = false): void {
    if (PHP_SAPI === 'cli') return;
    ptr_runtime_enter();
    $conn = ptp_connect();
    if (!$conn) { http_response_code(503); exit; }
    try { pas_guard($conn, $required); } finally { pg_close($conn); }
}

// Persist the binding and handshake receipt inside the same transaction as restoration.
function pas_bind($conn, array $session, int $profile): array {
    $character = $session['character_id'];
    if ($character === '') {
        $existing = pg_fetch_assoc(pth_query($conn, "SELECT value FROM public.conf_opts WHERE id='PLAYTHROUGH_CAMPAIGN_ID'"));
        $character = $existing['value'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/D', $character)) $character = bin2hex(random_bytes(16));
    }
    foreach (['PLAYTHROUGH_CAMPAIGN_ID'=>$character, 'PLAYTHROUGH_CAMPAIGN_NAME'=>$session['player_name']] as $key=>$value) {
        pth_query($conn, 'INSERT INTO public.conf_opts(id,value) VALUES($1,$2) ON CONFLICT(id) DO UPDATE SET value=EXCLUDED.value', [$key,$value]);
    }
    // Replace only the initial placeholder name; keep custom names and existing saves intact.
    $meta = ptp_product()['meta'];
    $row = pg_fetch_assoc(pth_query($conn, "SELECT name FROM {$meta}.playthrough_profiles WHERE id=$1 FOR UPDATE", [$profile]));
    if ($row && strtolower($row['name']) === 'default') {
        $name = $session['player_name'];
        $suffix = 2;
        while (pg_num_rows(pth_query($conn, "SELECT id FROM {$meta}.playthrough_profiles WHERE id<>$1 AND lower(name)=lower($2)", [$profile,$name]))) {
            $name = $session['player_name'] . ' (' . $suffix++ . ')';
        }
        // The default was protected from deletion by its name; retain that protection after renaming.
        pth_query($conn, "UPDATE {$meta}.playthrough_profiles SET name=$1,retention_pinned=true WHERE id=$2", [$name,$profile]);
        ptr_write($conn, 'PLAYTHROUGH_HOME_REVISION', bin2hex(random_bytes(16)));
    }
    $session['character_id'] = $character;
    $session['profile_id'] = $profile;
    $session['status'] = 'ready';
    $session['token'] = bin2hex(random_bytes(16));
    $session['message'] = '';
    pas_link($conn, $character, $profile);
    ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
    return $session;
}

// A browser selection is explicit authority to associate an otherwise ambiguous legacy save.
function pas_manual($conn, array $input): array {
    $runtime = ptr_runtime_begin_switch(30.0, $conn);
    try {
        require_once __DIR__ . '/postgresql.class.php';
        require_once __DIR__ . '/settings.php';
        require_once __DIR__ . '/data_functions.php';
        $GLOBALS['db'] ??= new sql();

        $state = pth_state($conn);
        if (!hash_equals($state['token'], (string)$input['expected_token'])) throw new RuntimeException('The playthrough changed. Reload this page.');
        $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
        if (($session['status'] ?? '') !== 'pending') throw new RuntimeException('No campaign is waiting. Reload this page.');
        $id = filter_var($input['profile_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new InvalidArgumentException('Choose a Playthrough Save.');
        if ($id !== $state['active_id']) {
            $result = pth_change($conn, 'switch', $input + ['_session'=>$session], true);
        } else {
            pth_query($conn, 'BEGIN');
            pas_bind($conn, $session, $id);
            pth_query($conn, 'COMMIT');
            $result = ['success'=>true];
        }
        $ready = ptr_runtime_finish_switch($runtime);
        return array_replace($result, ['message'=>$ready ? 'Campaign associated. Reload the Kenshi save to resume STOBE.' : 'Campaign associated. Restart the mod server before reloading Kenshi.']);
    } catch (Throwable $error) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) @pg_query($conn, 'ROLLBACK');
        throw $error;
    } finally { if ($runtime !== null) ptr_runtime_finish_switch($runtime); }
}

// One load operation owns the runtime barrier; a duplicate returns its committed receipt.
function pas_handshake($conn, array $input): array {
    foreach (['client_id','character_id','player_name'] as $key) if (!is_string($input[$key] ?? null)) throw new InvalidArgumentException('Invalid character request.');
    if (!preg_match('/^[a-f0-9]{32}$/D', $input['client_id'])
        || ($input['character_id'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $input['character_id']))
        || !is_int($input['load_id'] ?? null) || $input['load_id'] < 1
        || !is_int($input['gamets'] ?? null) || $input['gamets'] < 0) throw new InvalidArgumentException('Invalid load identity.');
    if (isset($input['new_game']) && (!is_bool($input['new_game']) || ($input['new_game'] && $input['character_id'] === ''))) throw new InvalidArgumentException('Invalid new-character identity.');
    $members = $input['player_members'] ?? [];
    if (!is_array($members) || count($members) > 128) throw new InvalidArgumentException('Invalid party.');
    foreach ($members as $member) if (!is_string($member) || mb_strlen($member) > 80 || !preg_match('//u', $member)) throw new InvalidArgumentException('Invalid party member.');
    $name = trim($input['player_name']);
    if ($name === '' || !preg_match('//u', $name) || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $name)
        || (empty($input['new_game']) && in_array(mb_strtolower($name), ['unknown','null','none'], true))) {
        return ['ok'=>false,'status'=>'pending','message'=>'Waiting for your Kenshi campaign name. Reload the save after naming your campaign.'];
    }
    $runtime = ptr_runtime_begin_switch(30.0, $conn);
    try {
        // Runtime readiness uses background_processor.php, whose settings helpers
        // and DB adapter are normally loaded by the profile-switch path below.
        // OFF mode skips that path, but still must bootstrap the same helpers
        // before ptr_runtime_finish_switch() checks manager readiness.
        require_once __DIR__ . '/postgresql.class.php';
        require_once __DIR__ . '/settings.php';
        require_once __DIR__ . '/data_functions.php';
        $GLOBALS['db'] ??= new sql();

        if (!pas_enabled($conn)) {
            $session = pas_off_handshake($conn, $input, $name);
            $committed = array_diff_key($session, ['ok'=>true]);
            // The manager may take >30s to acknowledge this generation after
            // an ordinary Kenshi load. Keep one load generation alive long
            // enough for its bounded startup; WinHTTP allows 60s here.
            if (!ptr_runtime_finish_switch($runtime, 45.0)) {
                $session['ok'] = false;
                $session['status'] = 'pending';
                unset($session['token']);
                $session['message'] = 'Restart the mod server before reloading Kenshi.';
                // finish releases the barrier. Never overwrite a newer load that acquired it.
                pth_query($conn, "UPDATE stobe_meta.settings SET value=$1 WHERE key='PLAYTHROUGH_SESSION' AND value=$2",
                    [json_encode(array_diff_key($session, ['ok'=>true]), JSON_THROW_ON_ERROR), json_encode($committed, JSON_THROW_ON_ERROR)]);
            }
            return $session;
        }
        $last = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
        if (($last['client_id'] ?? '') === $input['client_id']) {
            if (($last['load_id'] ?? 0) > $input['load_id']) throw new RuntimeException('This load request is stale.');
            if (($last['load_id'] ?? 0) === $input['load_id']) return ['ok'=>($last['status'] ?? '') === 'ready'] + $last;
        }
        $retired = ptr_read($conn, 'PLAYTHROUGH_RETIRED_CLIENTS', []);
        if (in_array($input['client_id'], $retired, true)) throw new RuntimeException('This Kenshi session was replaced.');
        if (!empty($last['client_id']) && $last['client_id'] !== $input['client_id']) {
            $retired[] = $last['client_id'];
            ptr_write($conn, 'PLAYTHROUGH_RETIRED_CLIENTS', array_slice($retired, -32));
        }
        $session = array_intersect_key($input, array_flip(['client_id','load_id','character_id','gamets']));
        $session += ['player_name'=>$name, 'status'=>'pending', 'message'=>'Choose the loaded campaign’s Playthrough Save, then reload the Kenshi save.'];
        // Invalidate the old receipt before any possibility of failure; wrong-character writes stay blocked.
        ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
        $state = pth_state($conn);
        if (!$state['available']) return ['ok'=>false] + $session;
        // Older installs can have live progress without an active manager entry.
        // Offer it as an active candidate so exact identity matching keeps that progress.
        if (!$state['active_id']) {
            $live = json_decode(pg_fetch_result(pth_query($conn, "SELECT stobe_meta.playthrough_identity('public')"),0,0),true);
            $state['playthroughs'][] = ['id'=>0,'name'=>$name,'active'=>true,'available'=>true,'last_gamets'=>$input['gamets']] + $live;
        }
        try {
            $target = pas_select($state['playthroughs'], $input['character_id'], $name, $input['gamets'], empty($input['new_game']), ptr_read($conn, 'PLAYTHROUGH_CHARACTER_LINKS', []), $input['player_members'] ?? []);
        } catch (RuntimeException $error) {
            $session['message'] = $error->getMessage();
            ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
            return ['ok'=>false] + $session;
        }
        if (!$target) {
            // Reuse the transactional fresh-start path: preserve outgoing progress before clearing gameplay.
            $newName = $name;
            $suffix = 2;
            while (pg_num_rows(pth_query($conn, 'SELECT id FROM ' . ptp_product()['meta'] . '.playthrough_profiles WHERE lower(name)=lower($1)', [$newName]))) {
                $newName = $name . ' (' . $suffix++ . ')';
            }
            pth_change($conn, 'new', ['name'=>$newName, 'expected_token'=>$state['token'], '_session'=>$session], true);
            $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
            $session['message'] = 'Created playthrough for ' . $name . '. Previous playthrough saved.';
        } elseif ($target['active']) {
            pth_query($conn, 'BEGIN');
            if ($target['id'] === 0) {
                $label = $name; $suffix = 2;
                while (pg_num_rows(pth_query($conn, "SELECT id FROM stobe_meta.playthrough_profiles WHERE lower(name)=lower($1)", [$label]))) {
                    $label = $name . ' (' . $suffix++ . ')';
                }
                $target['id'] = (int)pth_capture($conn, $label)['id'];
                pth_query($conn, "UPDATE stobe_meta.playthrough_profiles SET is_active=true,retention_pinned=true WHERE id=$1", [$target['id']]);
                ptr_write($conn, 'PLAYTHROUGH_HOME_REVISION', bin2hex(random_bytes(16)));
            }
            $session = pas_bind($conn, $session, $target['id']);
            pth_query($conn, 'COMMIT');
        } else {
            pth_change($conn, 'switch', ['profile_id'=>$target['id'], 'expected_token'=>$state['token'], '_session'=>$session], true);
            $session = ptr_read($conn, 'PLAYTHROUGH_SESSION', []);
            $session['message'] = 'Switched to ' . $target['name'] . ' playthrough. Previous playthrough saved.';
        }
        $ready = ptr_runtime_finish_switch($runtime);
        if (!$ready) {
            $session['status'] = 'pending';
            $session['message'] = 'Playthrough restored. Restart the mod server before reloading Kenshi.';
            ptr_write($conn, 'PLAYTHROUGH_SESSION', $session);
        }
        return ['ok'=>$ready] + $session;
    } catch (Throwable $error) {
        if (pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) @pg_query($conn, 'ROLLBACK');
        throw $error;
    } finally { if ($runtime !== null) ptr_runtime_finish_switch($runtime); }
}
