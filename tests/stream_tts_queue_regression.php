<?php
require_once __DIR__ . '/../lib/stream_tts_queue.php';
$port = random_int(21000, 28000);
$server = <<<'PY'
import sys,time,json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        data=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if self.path=='/llm':
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b'first')
            self.wfile.flush()
            time.sleep(.3)
            self.wfile.write(b'second')
            self.wfile.flush()
            return
        text=data['text']
        time.sleep(.35 if text=='first' else .05)
        body=json.dumps({'ok':True,'hash':text,'duration_ms':300}).encode()
        self.send_response(200)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(body)))
        self.end_headers()
        self.wfile.write(body)
ThreadingHTTPServer(('127.0.0.1',int(sys.argv[1])),Handler).serve_forever()
PY;
$process = proc_open(['python3', '-u', '-c', $server, strval($port)],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('mock TTS server failed');
try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('mock TTS server did not start');
    $received = [];
    $queue = new StobeStreamTtsQueue('NPC', false,
        static function ($text, $prepared) use (&$received) { $received[] = [$text, $prepared['hash'] ?? '']; },
        'http://127.0.0.1:' . $port . '/dialogue_tts.php');
    $start = microtime(true);
    $queue->enqueue('first');
    $queue->enqueue('second');
    $queue->enqueue('third');
    $queue->finish();
    $elapsed = microtime(true) - $start;
    if ($received !== [['first','first'],['second','second'],['third','third']])
        throw new RuntimeException('TTS order or hash mismatch: ' . json_encode($received));
    if ($elapsed > .65) throw new RuntimeException('TTS requests did not overlap: ' . $elapsed);
    $nested = [];
    $nestedQueue = new StobeStreamTtsQueue('NPC', false,
        static function ($text, $prepared) use (&$nested) { $nested[] = [$text, $prepared['hash'] ?? '']; },
        'http://127.0.0.1:' . $port . '/dialogue_tts.php');
    $llm = curl_init('http://127.0.0.1:' . $port . '/llm');
    curl_setopt_array($llm, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}',
        CURLOPT_WRITEFUNCTION => static function ($handle, $data) use ($nestedQueue): int {
            $nestedQueue->enqueue($data);
            $nestedQueue->pump();
            return strlen($data);
        }]);
    if (curl_exec($llm) === false) throw new RuntimeException('mock LLM stream failed: ' . curl_error($llm));
    curl_close($llm);
    $nestedQueue->finish();
    if ($nested !== [['first','first'],['second','second']])
        throw new RuntimeException('Nested model stream lost TTS: ' . json_encode($nested));
    echo 'PASS: concurrent TTS, ordered output, nested model callback (' . round($elapsed, 3) . "s)\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
}
