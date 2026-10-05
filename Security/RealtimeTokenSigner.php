<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Security;

final class RealtimeTokenSigner
{
    public function __construct(private string $secretValue, private string $publicUrlValue)
    {
    }

    /** @return array{token:string,url:string,event_url:string,transport:string,expires_at:string} */
    public function issue(string $sessionId, string $role, int $ttl = 3600): array
    {
        if (!in_array($role, ['visitor', 'agent'], true)) {
            throw new \InvalidArgumentException('Invalid realtime role.');
        }
        $expires = time() + max(60, min(86400, $ttl));
        $payload = $this->encode(json_encode(['sid' => $sessionId, 'role' => $role, 'exp' => $expires], JSON_THROW_ON_ERROR));
        $signature = $this->encode(hash_hmac('sha256', $payload, $this->secret(), true));

        return [
            'token' => $payload.'.'.$signature,
            'url' => rtrim($this->publicUrl(), '/'),
            'event_url' => preg_replace('#/chat/realtime$#', '/chat/api/realtime/events', rtrim($this->publicUrl(), '/')),
            'transport' => 'sse',
            'expires_at' => gmdate(DATE_ATOM, $expires),
        ];
    }

    /** @return array{sid:string,role:string,exp:int} */
    public function verify(string $token): array
    {
        if (strlen($token) > 1024 || 2 !== count($parts = explode('.', $token))) {
            throw new \DomainException('Invalid realtime token.');
        }
        [$payload, $signature] = $parts;
        if (!hash_equals($this->encode(hash_hmac('sha256', $payload, $this->secret(), true)), $signature)) {
            throw new \DomainException('Invalid realtime signature.');
        }
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true);
        if (!is_array($claims) || !preg_match('/^[a-f0-9]{32}$/', (string) ($claims['sid'] ?? ''))
            || !in_array($claims['role'] ?? '', ['visitor', 'agent'], true)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()) {
            throw new \DomainException('Invalid or expired realtime token.');
        }
        return $claims;
    }

    public function gatewaySecret(): string
    {
        return $this->secret();
    }

    public function isConfigured(): bool
    {
        try {
            $this->secret();
            $this->publicUrl();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function secret(): string
    {
        $secret = trim($this->secretValue);
        if (strlen($secret) < 32) {
            throw new \RuntimeException('MAUTIC_WEBCHAT_REALTIME_SECRET must contain at least 32 characters.');
        }

        return $secret;
    }

    private function publicUrl(): string
    {
        $url = trim($this->publicUrlValue);
        $url = preg_replace('#^ws(s?)://#', 'http$1://', $url);
        if (!preg_match('#^https?://[^/]+/chat/realtime$#', $url)) {
            throw new \RuntimeException('MAUTIC_WEBCHAT_REALTIME_URL must be an HTTPS SSE URL ending in /chat/realtime.');
        }

        return $url;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
