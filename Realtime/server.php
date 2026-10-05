<?php

declare(strict_types=1);

// A single event loop streams notifications; it never boots Mautic, calls FPM or touches its database.
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/../Security/RealtimeTokenSigner.php';

use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

$secret = (string) getenv('MAUTIC_WEBCHAT_REALTIME_SECRET');
$signer = new RealtimeTokenSigner($secret, 'http://localhost/chat/realtime');
$signer->gatewaySecret(); // Fail before listening if the secret is missing.
$host = getenv('WEBCHAT_HOST') ?: '127.0.0.1';
if (!in_array($host, ['127.0.0.1', '::1'], true)) throw new RuntimeException('The broker must listen on loopback.');
$worker = new Worker('http://'.$host.':'.(getenv('WEBCHAT_PORT') ?: '8790'));
$worker->name = 'mautic-webchat-sse';
$worker->count = 1;
Worker::$pidFile = (getenv('WEBCHAT_RUNTIME_DIR') ?: sys_get_temp_dir()).'/mautic-webchat-sse.pid';
Worker::$logFile = (getenv('WEBCHAT_RUNTIME_DIR') ?: sys_get_temp_dir()).'/mautic-webchat-sse.log';
$rooms = [];
$clients = [];
$history = [];
$historyBytes = 0;
$epoch = bin2hex(random_bytes(8));
$sequence = 0;
$published = 0;

$send = static function (TcpConnection $connection, array $event, ?string $id = null): void {
    $data = (null !== $id ? 'id: '.$id."\n" : '').'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
    $connection->send(new Chunk($data));
};
$broadcast = static function (string $sid, array $event, bool $durable = false) use (&$rooms, &$history, &$historyBytes, &$sequence, &$published, $epoch, $send): void {
    $id = $durable ? $epoch.'-'.(++$sequence) : null;
    if ($durable) {
        $bytes = strlen(json_encode($event));
        $history[] = ['id' => $id, 'sid' => $sid, 'event' => $event, 'bytes' => $bytes];
        $historyBytes += $bytes;
        while (count($history) > 256 || $historyBytes > 1048576) {
            $historyBytes -= array_shift($history)['bytes'];
        }
        ++$published;
    }
    foreach ($rooms[$sid] ?? [] as $connection) $send($connection, $event, $id);
};
$presence = static function (string $sid) use (&$rooms, &$clients): bool {
    foreach ($rooms[$sid] ?? [] as $id => $connection) {
        if ('agent' === ($clients[$id]['role'] ?? '')) return true;
    }
    return false;
};
$worker->onConnect = static function (TcpConnection $connection): void {
    $connection->maxPackageSize = 16384;
    $connection->maxSendBufferSize = 65536;
    $connection->onBufferFull = static fn (TcpConnection $slow) => $slow->destroy();
};
$worker->onMessage = static function (TcpConnection $connection, Request $request) use ($signer, $secret, &$rooms, &$clients, &$history, &$published, &$historyBytes, $send, $broadcast, $presence): void {
    if (isset($clients[$connection->id])) { $connection->destroy(); return; }
    if ('GET' === $request->method() && '/health' === $request->path()) {
        $connection->close(new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true, 'transport' => 'sse', 'runtime' => 'php', 'connections' => count($clients), 'rooms' => count($rooms), 'published' => $published, 'replay_bytes' => $historyBytes])));
        return;
    }
    if ('POST' === $request->method() && '/publish' === $request->path()) {
        if (!hash_equals('Bearer '.$secret, $request->header('authorization', ''))) {
            $connection->close(new Response(401)); return;
        }
        $input = json_decode($request->rawBody(), true);
        if (!is_array($input) || !preg_match('/^[a-f0-9]{32}$/', (string) ($input['session'] ?? '')) || !is_array($input['event'] ?? null) || strlen($request->rawBody()) > 16384) {
            $connection->close(new Response(422)); return;
        }
        $event = $input['event'];
        if (!in_array($event['type'] ?? '', ['message.created', 'message.read', 'message.delivered', 'typing.started', 'typing.stopped'], true)) {
            $connection->close(new Response(422)); return;
        }
        $broadcast($input['session'], $event, str_starts_with($event['type'], 'message.'));
        $connection->close(new Response(202, ['Content-Type' => 'application/json'], '{"ok":true}'));
        return;
    }
    if ('GET' !== $request->method() || '/chat/realtime' !== $request->path()) { $connection->close(new Response(404)); return; }
    try { $claims = $signer->verify((string) $request->get('token', '')); }
    catch (DomainException) { $connection->close(new Response(401)); return; }
    $sid = $claims['sid'];
    if (count($clients) >= 256 || count($rooms[$sid] ?? []) >= 8) {
        $connection->close(new Response(429, ['Retry-After' => '15'])); return;
    }
    $wasOnline = $presence($sid);
    $clients[$connection->id] = $claims;
    $rooms[$sid][$connection->id] = $connection;
    $connection->send(new Response(200, [
        'Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store, no-transform',
        'X-Accel-Buffering' => 'no', 'Transfer-Encoding' => 'chunked',
    ]));
    $connection->send(new Chunk("retry: 3000\n\n"));
    $send($connection, ['type' => 'connection.ready', 'role' => $claims['role'], 'transport' => 'sse']);
    $send($connection, ['type' => 'presence.changed', 'role' => 'agent', 'online' => $presence($sid)]);
    $lastId = (string) $request->header('last-event-id', '');
    if ('' !== $lastId) {
        $index = array_search($lastId, array_column($history, 'id'), true);
        if (false === $index) $send($connection, ['type' => 'sync.required']);
        else foreach (array_slice($history, $index + 1) as $entry) {
            if ($entry['sid'] === $sid) $send($connection, $entry['event'], $entry['id']);
        }
    }
    if (!$wasOnline && 'agent' === $claims['role']) $broadcast($sid, ['type' => 'presence.changed', 'role' => 'agent', 'online' => true]);
};
$worker->onClose = static function (TcpConnection $connection) use (&$clients, &$rooms, $broadcast, $presence): void {
    $claims = $clients[$connection->id] ?? null;
    if (!$claims) return;
    unset($clients[$connection->id], $rooms[$claims['sid']][$connection->id]);
    if (empty($rooms[$claims['sid']])) unset($rooms[$claims['sid']]);
    if ('agent' === $claims['role'] && !$presence($claims['sid'])) {
        $broadcast($claims['sid'], ['type' => 'presence.changed', 'role' => 'agent', 'online' => false]);
    }
};
$worker->onWorkerStart = static function () use (&$rooms, &$clients, $send): void {
    Timer::add(15, static function () use (&$rooms, &$clients, $send): void {
        foreach ($rooms as $room) foreach ($room as $id => $connection) {
            if (($clients[$id]['exp'] ?? 0) <= time()) {
                $send($connection, ['type' => 'auth.expired']);
                $connection->close(new Chunk(''));
            } else $connection->send(new Chunk(": heartbeat\n\n"));
        }
    });
};
Worker::runAll();
