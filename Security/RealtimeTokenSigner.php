<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Security;

final class RealtimeTokenSigner
{
    /** @return array{token:string,url:string,expires_at:string} */
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
            'expires_at' => gmdate(DATE_ATOM, $expires),
        ];
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
        $secret = trim((string) getenv('MAUTIC_WEBCHAT_REALTIME_SECRET'));
        if (strlen($secret) < 32) {
            throw new \RuntimeException('MAUTIC_WEBCHAT_REALTIME_SECRET must contain at least 32 characters.');
        }

        return $secret;
    }

    private function publicUrl(): string
    {
        $url = trim((string) getenv('MAUTIC_WEBCHAT_REALTIME_URL'));
        if (!preg_match('#^wss?://#', $url)) {
            throw new \RuntimeException('MAUTIC_WEBCHAT_REALTIME_URL must be a WebSocket URL.');
        }

        return $url;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
