<?php
// Overlap sentence synthesis with the remainder of a streamed model response.
// Responses are released in original order because Kenshi associates each bubble
// with its audio hash.
final class StobeStreamTtsQueue {
    private $multi;
    private array $pending = [];
    private array $waiting = [];
    private array $ready = [];
    private int $next = 0;
    private int $sent = 0;
    private $emit;
    private string $url;
    private string $actor;
    private string $storageId;
    public function __construct(string $actor, array|false $actorData, callable $emit, ?string $urlOverride = null) {
        $this->actor = $actor;
        $metadata = is_array($actorData) ? ($actorData['metadata'] ?? []) : [];
        if (is_string($metadata)) $metadata = json_decode($metadata, true) ?: [];
        $this->storageId = is_array($metadata) ? trim(strval($metadata['storage_id'] ?? '')) : '';
        $port = intval($_SERVER['SERVER_PORT'] ?? 8083);
        $scriptDir = rtrim(str_replace('\\', '/', dirname(strval($_SERVER['SCRIPT_NAME'] ?? '/StobeServer/main.php'))), '/');
        $this->url = $urlOverride ?? ('http://127.0.0.1:' . $port . $scriptDir . '/dialogue_tts.php');
        $this->multi = function_exists('curl_multi_init') ? curl_multi_init() : false;
        $this->emit = $emit;
    }
    public function enqueue(string $text): void {
        $this->waiting[$this->next++] = $text;
        $this->startWaiting();
        $this->pump();
    }
    private function startWaiting(): void {
        // Chatterbox shares one GPU; bound concurrent requests to avoid a pileup.
        while ($this->waiting && count($this->pending) < 2) {
            $id = array_key_first($this->waiting);
            $text = $this->waiting[$id];
            unset($this->waiting[$id]);
            if (!$this->multi || !($handle = curl_init($this->url))) {
                $this->ready[$id] = [$text, null];
                continue;
            }
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['actor' => $this->actor, 'storage_id' => $this->storageId, 'text' => $text]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 30,
            ]);
            $this->pending[$id] = [$handle, $text];
            curl_multi_add_handle($this->multi, $handle);
        }
        $this->release();
    }
    public function pump(): void {
        if (!$this->multi) return;
        do { $status = curl_multi_exec($this->multi, $running); }
        while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($this->multi)) {
            $handle = $info['handle'];
            foreach ($this->pending as $id => [$candidate, $text]) {
                if ($candidate !== $handle) continue;
                $decoded = json_decode(strval(curl_multi_getcontent($handle)), true);
                $prepared = is_array($decoded) && !empty($decoded['ok']) ? $decoded : null;
                $this->ready[$id] = [$text, $prepared];
                unset($this->pending[$id]);
                curl_multi_remove_handle($this->multi, $handle);
                curl_close($handle);
                break;
            }
        }
        $this->startWaiting();
        $this->release();
    }
    private function release(): void {
        while (array_key_exists($this->sent, $this->ready)) {
            [$text, $prepared] = $this->ready[$this->sent];
            unset($this->ready[$this->sent++]);
            ($this->emit)($text, $prepared);
        }
    }
    public function finish(): void {
        while ($this->pending || $this->waiting) {
            $this->pump();
            if (!$this->pending && !$this->waiting) break;
            if ($this->multi) curl_multi_select($this->multi, 0.1);
        }
        if ($this->multi) curl_multi_close($this->multi);
    }
}
