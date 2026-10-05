<?php
require __DIR__.'/../../Security/RealtimeTokenSigner.php';
require __DIR__.'/../../Application/ReceiptPolicy.php';
use MauticPlugin\MauticWebChatBundle\Security\RealtimeTokenSigner;
use MauticPlugin\MauticWebChatBundle\Application\ReceiptPolicy;
function check(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
$s = new RealtimeTokenSigner(str_repeat('secret', 8), 'wss://example.test/chat/realtime');
foreach (['visitor','agent'] as $role) {
    $token = $s->issue(str_repeat('a', 32), $role);
    check($s->verify($token['token'])['role'] === $role, 'scoped identity');
    check($token['transport'] === 'sse' && $token['url'] === 'https://example.test/chat/realtime', 'SSE metadata');
    check($token['event_url'] === 'https://example.test/chat/api/realtime/events', 'command URL');
    try { $s->verify($token['token'].'x'); throw new RuntimeException('Tampered token accepted'); } catch (DomainException) {}
}
check(ReceiptPolicy::shouldApply('visitor','read','agent','sent',10,0), 'new read');
check(!ReceiptPolicy::shouldApply('visitor','read','agent','read',10,10), 'same receipt no-op');
check(!ReceiptPolicy::shouldApply('visitor','read','agent','read',9,10), 'no regression');
check(!ReceiptPolicy::shouldApply('visitor','delivered','agent','read',10,10), 'read never becomes delivered');
check(!ReceiptPolicy::shouldApply('visitor','read','visitor','sent',10,0), 'wrong side no-op');
check(ReceiptPolicy::shouldApply('agent','read','visitor','sent',10,0), 'operator read');
check(!ReceiptPolicy::shouldApply('agent','read','visitor','sent',10,10), 'operator duplicate no-op');
echo "Token and monotonic receipt unit checks passed (no database).\n";
