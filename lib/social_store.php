<?php
declare(strict_types=1);
require_once __DIR__ . '/social_event_contract.php';
require_once __DIR__ . '/social_rules.php';

// Ingress owns the transaction. All queries fail closed; no partial history/ledger commit.
final class SocialStore
{
    public function __construct(private object $db, private SocialRules $rules = new SocialRules()) {}
    private function query(string $sql, array $params = []): mixed
    {
        $result = $this->db->exec($sql, $params);
        if ($result === false) throw new RuntimeException('Social database operation failed' . (method_exists($this->db, 'GetLastError') ? ': ' . substr($this->db->GetLastError(), 0, 300) : ''));
        return $result;
    }
    private function row(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params);
        return pg_fetch_assoc($result) ?: null;
    }
    private static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    private function lock(string $campaign): void
    {
        $this->query('SELECT pg_advisory_xact_lock(hashtextextended($1,0))', ['social:' . $campaign]);
    }
    private function transaction(callable $body): mixed
    {
        $owned = !method_exists($this->db, 'transactionStatus') || $this->db->transactionStatus() === PGSQL_TRANSACTION_IDLE;
        $savepoint = 'social_' . bin2hex(random_bytes(8));
        $this->query($owned ? 'BEGIN' : 'SAVEPOINT ' . $savepoint);
        try {
            $out = $body();
            $this->query($owned ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $savepoint);
            return $out;
        } catch (Throwable $error) {
            $this->db->exec($owned ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $savepoint);
            if (!$owned) $this->db->exec('RELEASE SAVEPOINT ' . $savepoint);
            throw $error;
        }
    }
    // Scope is resolved by the authenticated playthrough receipt at HTTP ingress, never a client assertion.
    public function ingest(string|array $input, array $scope, string $mode = 'off'): array
    {
        if ($mode === 'off') return ['status'=>'disabled', 'effects'=>[]];
        if (!in_array($mode, ['shadow', 'enabled'], true)) throw new InvalidArgumentException('Unknown social mode');
        $event = SocialEventContract::validate($input);
        foreach (['campaign_id','timeline_epoch','native_session_id'] as $field) {
            if (($event[$field] ?? null) !== ($scope[$field] ?? null)) throw new DomainException('Stale or mismatched social scope');
        }
        return $this->transaction(function () use ($event, $mode): array {
            $this->lock($event['campaign_id']);
            $key = [$event['campaign_id'],$event['timeline_epoch'],$event['event_id']];
            $existing = $this->row('SELECT payload_hash FROM social_event_inbox WHERE campaign_id=$1 AND timeline_epoch=$2 AND event_id=$3', $key);
            $hash = SocialEventContract::hash($event);
            if ($existing) {
                if ($existing['payload_hash'] !== $hash) throw new DomainException('Event ID payload conflict');
                return ['status'=>'duplicate','effects'=>[]];
            }
            $high = $this->row('SELECT MAX(sequence) AS sequence,MAX(game_ts) AS game_ts FROM social_event_inbox WHERE campaign_id=$1 AND timeline_epoch=$2 AND native_session_id=$3', [$event['campaign_id'],$event['timeline_epoch'],$event['native_session_id']]);
            $late = isset($high['sequence']) && ($event['sequence'] <= (int)$high['sequence'] || $event['game_ts'] < (int)$high['game_ts']);
            $active = $this->row('SELECT COUNT(*) AS n FROM social_incident WHERE campaign_id=$1 AND timeline_epoch=$2 AND state->>\'phase\'=\'pending_awareness\'',[$event['campaign_id'],$event['timeline_epoch']]);
            if ((int)$active['n'] >= 256 && !$this->row('SELECT 1 FROM social_incident WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3',[$event['campaign_id'],$event['timeline_epoch'],$event['incident_id']])) throw new LengthException('Social active incident limit reached');
            $status = $event['origin'] === 'setup' ? 'setup' : ($late ? 'late' : 'captured');
            $this->query('INSERT INTO social_event_inbox(campaign_id,timeline_epoch,event_id,native_session_id,sequence,game_ts,incident_id,payload,payload_hash,status,mode) VALUES($1,$2,$3,$4,$5,$6,$7,$8::jsonb,$9,$10,$11)',
                array_merge($key,[$event['native_session_id'],$event['sequence'],$event['game_ts'],$event['incident_id'],self::json($event),$hash,$status,$mode]));
            if ($status !== 'captured') return ['status'=>$status,'effects'=>[]];
            // Legacy prose events (LogGameEvent piggyback) stay raw diagnostics: inbox row only, no
            // incident/checkpoint growth. Structured native facts go to the server-owned interpreter.
            if (($event['facts']['source'] ?? null) !== 'structured') return ['status'=>'captured','effects'=>[]];
            require_once __DIR__ . '/social_interpreter.php';
            $effects = (new SocialInterpreter($this, $this->rules))->interpret($event, $mode);
            if (function_exists('stobeLogRelationshipInfo')) {
                $brief = array_map(static fn($e) => array_intersect_key($e + (isset($e['effect']) ? ['delta'=>$e['effect']['delta'] ?? null, 'reason'=>$e['effect']['reason'] ?? null] : []),
                    array_flip(['status','component','observer','culprit','delta','reason','basis','incident'])), $effects);
                stobeLogRelationshipInfo('SOCIAL_INTERPRET', ['mode'=>$mode, 'kind'=>$event['event_kind'], 'seq'=>$event['sequence'], 'game_ts'=>$event['game_ts'],
                    'actor'=>$event['actor']['name'] ?? null, 'target'=>$event['target']['name'] ?? null, 'level'=>$event['facts']['level'] ?? null, 'results'=>$brief]);
            }
            return ['status'=>'captured','effects'=>$effects];
        });
    }
    private function checkpoint(array $event, ?string $incident = null): void
    {
        $params = [$event['campaign_id'],$event['timeline_epoch'],$incident ?? $event['incident_id']];
        $snapshot = [];
        foreach (['social_incident','social_belief'] as $table) {
            // to_jsonb keeps jsonb columns as objects (a plain SELECT * would snapshot them as strings and the
            // rollback would restore them as JSON strings).
            $snapshot[$table] = array_map(static fn($r) => json_decode($r['row'], true), pg_fetch_all($this->query("SELECT to_jsonb(t) AS row FROM $table t WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND incident_id=\$3", $params)) ?: []);
        }
        $snapshot['social_evidence'] = array_map(static fn($r) => json_decode($r['row'], true), pg_fetch_all($this->query("SELECT to_jsonb(e) AS row FROM social_evidence e JOIN social_belief b ON e.campaign_id=b.campaign_id AND e.timeline_epoch=b.timeline_epoch AND e.observer_key=b.observer_key AND e.culprit_key=b.belief->>'responsible_entity' WHERE b.campaign_id=\$1 AND b.timeline_epoch=\$2 AND b.incident_id=\$3",$params)) ?: []);
        $this->query('INSERT INTO social_checkpoint(campaign_id,timeline_epoch,incident_id,event_id,game_ts,sequence,snapshot) VALUES($1,$2,$3,$4,$5,$6,$7::jsonb) ON CONFLICT(campaign_id,timeline_epoch,event_id) DO UPDATE SET snapshot=EXCLUDED.snapshot',
            array_merge(array_slice($params,0,2),[$params[2],$event['event_id'] . '#' . $params[2],$event['game_ts'],$event['sequence'],self::json($snapshot)]));
    }
    // Internal semantic adapter boundary; deliberately not exposed as a client-supplied affinity API.
    public function apply(array $event, string $observer, string $culprit, string $component, array $belief,
        callable $resolve, string $mode = 'shadow', array $context = [], ?string $incident = null): array
    {
        $incident = $incident ?? strval($event['incident_id'] ?? '');
        $event = SocialEventContract::validate($event);
        if (!in_array($mode,['shadow','enabled'],true)) return ['status'=>'disabled'];
        if (!getSettingBool('SOCIAL_CATEGORY_' . strtoupper($this->rules->category($component)),true)) return ['status'=>'category_disabled'];
        if ($event['origin'] !== 'gameplay') return ['status'=>'setup'];
        SocialEventContract::token($observer,'observer'); SocialEventContract::token($culprit,'culprit');
        if (($belief['responsible_entity'] ?? null) !== $culprit) throw new DomainException('Belief culprit mismatch');
        return $this->transaction(function () use ($event,$observer,$culprit,$component,$belief,$resolve,$mode,$context,$incident): array {
            $this->lock($event['campaign_id']);
            $captured = $this->row('SELECT status,mode,payload_hash FROM social_event_inbox WHERE campaign_id=$1 AND timeline_epoch=$2 AND event_id=$3',[$event['campaign_id'],$event['timeline_epoch'],$event['event_id']]);
            if (!$captured || $captured['payload_hash'] !== SocialEventContract::hash($event) || $captured['status'] !== 'captured' || $captured['mode'] !== $mode) throw new DomainException('Event not captured in this mode');
            $key = [$event['campaign_id'],$event['timeline_epoch'],$incident,$observer,$culprit,$component];
            if ($this->row('SELECT delta FROM social_effect WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3 AND observer_key=$4 AND culprit_key=$5 AND component=$6',$key)) return ['status'=>'duplicate'];
            $high = $this->row('SELECT MAX(sequence) AS n FROM social_event_inbox WHERE campaign_id=$1 AND timeline_epoch=$2 AND native_session_id=$3',[$event['campaign_id'],$event['timeline_epoch'],$event['native_session_id']]);
            if ((int)$high['n'] !== $event['sequence']) throw new DomainException('Late interpretation requires a new causal event');
            // Resolve only unique persistent IDs. Name-only guessing is forbidden.
            $a = $resolve($observer); $b = $resolve($culprit);
            if (!$a || !$b || $a['id'] === $b['id']) return ['status'=>'unresolved_identity'];
            $ids = [(int)$a['id'],(int)$b['id']]; sort($ids);
            foreach ($ids as $id) $this->query('SELECT id FROM core_npc WHERE id=$1 FOR UPDATE',[$id]);
            $fresh = getNpcById((int)$a['id']);
            $map = stobeGetNpcRelationshipMap($fresh);
            $entryKey = stobeFindRelationshipEntryKey($map,$b['name']);
            $context['affinity'] = (int)($map[$entryKey]['aff'] ?? 0);
            $group = $context['escalation_group'] ?? null; unset($context['escalation_group']);
            if (is_array($group) && $group) {
                // One growing budget per incident/observer/culprit: charge only the difference to the worst level so far.
                $positive = $this->rules->positive($component);
                $prior = $this->row('SELECT ' . ($positive ? 'MAX' : 'MIN') . '((detail->>\'total\')::int) AS worst FROM social_effect WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3 AND observer_key=$4 AND culprit_key=$5 AND component = ANY($6::text[]) AND detail ? \'total\'',
                    [$key[0],$key[1],$key[2],$observer,$culprit,'{' . implode(',', array_map('strval', $group)) . '}']);
                $context['prior_total'] = $positive ? max(0, (int)($prior['worst'] ?? 0)) : min(0, (int)($prior['worst'] ?? 0));
            }
            $effect = $this->rules->calculate($incident,$observer,$component,$belief,$context);
            $effect['observer_name'] = $a['name']; $effect['culprit_name'] = $b['name'];
            $effect['identity'] = [$a['basis'] ?? 'storage_id', $b['basis'] ?? 'storage_id'];
            if ($effect['reason'] === 'not_known') return ['status'=>'pending_awareness','effect'=>$effect];
            $applied = $mode === 'enabled';
            if ($applied && $effect['delta'] !== 0) {
                $updates = stobeApplyRelationshipUpdatesMap($map,[['target'=>$b['name'],'aff_delta'=>$effect['delta'],'note'=>strval($belief['note'] ?? $component)]]);
                if (($updates['updated'] ?? 0) !== 1) throw new RuntimeException('Canonical relationship update refused');
                $previous = $GLOBALS['gameRequest'] ?? null;
                $GLOBALS['gameRequest'] = ['social',0,$event['game_ts']];
                try {
                    if (!stobePersistNpcRelationshipMap($a['name'],$updates['map'],$fresh)) throw new RuntimeException('Relationship write failed');
                } finally {
                    if ($previous === null) unset($GLOBALS['gameRequest']); else $GLOBALS['gameRequest'] = $previous;
                }
            }
            $this->query('INSERT INTO social_effect(campaign_id,timeline_epoch,incident_id,observer_key,culprit_key,component,game_ts,delta,detail,applied,rules_version) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9::jsonb,$10,$11)',array_merge($key,[$event['game_ts'],$effect['delta'],self::json($effect),$applied,$this->rules->version()]));
            $this->query('INSERT INTO social_belief(campaign_id,timeline_epoch,incident_id,observer_key,belief,game_ts) VALUES($1,$2,$3,$4,$5::jsonb,$6) ON CONFLICT(campaign_id,timeline_epoch,incident_id,observer_key) DO UPDATE SET belief=EXCLUDED.belief,game_ts=EXCLUDED.game_ts',array_merge(array_slice($key,0,4),[self::json($belief),$event['game_ts']]));
            if ($applied) {
                $this->query('INSERT INTO social_evidence(campaign_id,timeline_epoch,observer_key,culprit_key,evidence,game_ts) VALUES($1,$2,$3,$4,jsonb_build_object($5::text,1),$6) ON CONFLICT(campaign_id,timeline_epoch,observer_key,culprit_key) DO UPDATE SET evidence=jsonb_set(social_evidence.evidence,ARRAY[$5::text],to_jsonb(COALESCE((social_evidence.evidence->>$5)::int,0)+1)),game_ts=EXCLUDED.game_ts',[$key[0],$key[1],$observer,$culprit,$component,$event['game_ts']]);
            }
            $this->checkpoint($event, $incident);
            return ['status'=>$mode,'effect'=>$effect];
        });
    }
    public function pending(array $event, string $observer, array $facts, ?string $incident = null): void
    {
        $event = SocialEventContract::validate($event);
        SocialEventContract::token($observer, 'observer');
        $incident = $incident ?? strval($event['incident_id']);
        $this->transaction(function () use ($event,$observer,$facts,$incident): void {
            $this->lock($event['campaign_id']);
            $row = $this->row('SELECT payload_hash,status FROM social_event_inbox WHERE campaign_id=$1 AND timeline_epoch=$2 AND event_id=$3',[$event['campaign_id'],$event['timeline_epoch'],$event['event_id']]);
            if (!$row || $row['status'] !== 'captured' || $row['payload_hash'] !== SocialEventContract::hash($event)) throw new DomainException('Pending evidence requires captured event');
            $high = $this->row('SELECT MAX(sequence) AS n FROM social_event_inbox WHERE campaign_id=$1 AND timeline_epoch=$2 AND native_session_id=$3',[$event['campaign_id'],$event['timeline_epoch'],$event['native_session_id']]);
            if ((int)$high['n'] !== $event['sequence']) throw new DomainException('Late pending evidence requires a new causal event');
            $active = $this->row("SELECT COUNT(*) AS n FROM social_incident WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND state->>'phase'='pending_awareness'",[$event['campaign_id'],$event['timeline_epoch']]);
            $current = $this->row('SELECT state FROM social_incident WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3',[$event['campaign_id'],$event['timeline_epoch'],$incident]);
            if ((int)$active['n'] >= 256 && (json_decode($current['state'] ?? '{}',true)['phase'] ?? '') !== 'pending_awareness') throw new LengthException('Social active incident limit reached');
            $this->query('INSERT INTO social_incident(campaign_id,timeline_epoch,incident_id,state,game_ts,last_sequence) VALUES($1,$2,$3,\'{"pending":{}}\'::jsonb,$4,$5) ON CONFLICT DO NOTHING',[$event['campaign_id'],$event['timeline_epoch'],$incident,$event['game_ts'],$event['sequence']]);
            $this->query('UPDATE social_incident SET state=jsonb_set(jsonb_set(state || \'{"phase":"pending_awareness"}\'::jsonb,\'{pending}\',COALESCE(state->\'pending\',\'{}\'::jsonb),true),ARRAY[\'pending\', $4::text],$5::jsonb,true),game_ts=$6,last_sequence=$7 WHERE campaign_id=$1 AND timeline_epoch=$2 AND incident_id=$3',[$event['campaign_id'],$event['timeline_epoch'],$incident,$observer,self::json($facts),$event['game_ts'],$event['sequence']]);
            $this->checkpoint($event, $incident);
        });
    }
    // Interpreter helpers (server-owned; never reachable from client input directly).
    public function fetchRows(string $sql, array $params = []): array { return pg_fetch_all($this->query($sql, $params)) ?: []; }
    public function saveIncident(array $event, string $incident, array $state): void
    {
        // json_decode(assoc) turns {} into []; keep the pending map an object so jsonb paths work.
        if (array_key_exists('pending', $state) && $state['pending'] === []) $state['pending'] = (object)[];
        $this->query('INSERT INTO social_incident(campaign_id,timeline_epoch,incident_id,state,game_ts,last_sequence) VALUES($1,$2,$3,$4::jsonb,$5,$6) ON CONFLICT(campaign_id,timeline_epoch,incident_id) DO UPDATE SET state=EXCLUDED.state,game_ts=EXCLUDED.game_ts,last_sequence=EXCLUDED.last_sequence',
            [$event['campaign_id'],$event['timeline_epoch'],$incident,self::json($state),$event['game_ts'],$event['sequence']]);
        $this->checkpoint($event, $incident);
    }
    /** A KO incident carried into a newer load is closed in its old load (no double resolution). */
    public function retireIncident(string $campaign, string $epoch, string $incident): void
    {
        $this->query("UPDATE social_incident SET state=jsonb_set(state,'{phase}','\"carried_over\"'::jsonb) WHERE campaign_id=\$1 AND timeline_epoch=\$2 AND incident_id=\$3", [$campaign, $epoch, $incident]);
    }
    public function rollback(int $cutoff): array
    {
        if ($cutoff < 0) throw new InvalidArgumentException('Invalid rollback time');
        // Inert when the feature never captured anything (default off): no table locks on a normal rollback.
        $any = $this->row('SELECT (EXISTS(SELECT 1 FROM social_event_inbox) OR EXISTS(SELECT 1 FROM social_incident) OR EXISTS(SELECT 1 FROM social_checkpoint) OR EXISTS(SELECT 1 FROM social_effect) OR EXISTS(SELECT 1 FROM social_evidence) OR EXISTS(SELECT 1 FROM social_belief)) AS any');
        if (($any['any'] ?? 'f') !== 't') return ['restored_scopes'=>0,'skipped'=>'empty'];
        return $this->transaction(function () use ($cutoff): array {
            // Exclusive lock prevents ingress race while rebuilding open states/evidence.
            $this->query('LOCK TABLE social_event_inbox,social_incident,social_belief,social_effect,social_evidence,social_checkpoint IN ACCESS EXCLUSIVE MODE');
            $snapshots = pg_fetch_all($this->query('SELECT * FROM (SELECT DISTINCT ON(campaign_id,timeline_epoch,incident_id) snapshot,game_ts,sequence FROM social_checkpoint WHERE game_ts<=$1 ORDER BY campaign_id,timeline_epoch,incident_id,game_ts DESC,sequence DESC) latest ORDER BY game_ts,sequence',[$cutoff])) ?: [];
            foreach (['social_incident','social_belief','social_evidence'] as $table) $this->query("DELETE FROM $table");
            foreach ($snapshots as $row) {
                $snapshot = json_decode($row['snapshot'],true,32,JSON_THROW_ON_ERROR);
                foreach (['social_incident','social_belief','social_evidence'] as $table) foreach ($snapshot[$table] ?? [] as $record) {
                    if ($table === 'social_evidence') $this->query('DELETE FROM social_evidence WHERE campaign_id=$1 AND timeline_epoch=$2 AND observer_key=$3 AND culprit_key=$4',[$record['campaign_id'],$record['timeline_epoch'],$record['observer_key'],$record['culprit_key']]);
                    $this->query("INSERT INTO $table SELECT * FROM jsonb_populate_record(NULL::$table,\$1::jsonb) ON CONFLICT DO NOTHING",[self::json($record)]);
                }
            }
            foreach (['social_event_inbox','social_effect','social_checkpoint'] as $table) $this->query("DELETE FROM $table WHERE game_ts>\$1",[$cutoff]);
            return ['restored_scopes'=>count($snapshots)];
        });
    }
}
