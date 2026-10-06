<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Security;

/** Dedicated CMS RS256 trust anchor. A self-declared email never establishes identity. */
final class IdentityVerifier
{
    public function __construct(private string $publicKey = '', private string $issuer = '') {}

    public function verify(string $token, string $widget, string $origin, ?int $now = null): array
    {
        if (strlen($token) > 12000 || '' === $this->publicKey || '' === $this->issuer) {
            throw new \DomainException('identity_invalid');
        }
        try {
            $parts = explode('.', $token);
            if (3 !== count($parts)) throw new \UnexpectedValueException();
            [$header, $payload, $signature] = $parts;
            $h = json_decode(self::decode($header), true, 8, JSON_THROW_ON_ERROR);
            $claims = json_decode(self::decode($payload), true, 8, JSON_THROW_ON_ERROR);
            $key = str_replace('\\n', "\n", $this->publicKey);
            if (!is_array($h) || ($h['alg'] ?? '') !== 'RS256' || ($h['typ'] ?? '') !== 'JWT' || !is_array($claims) || 1 !== openssl_verify($header.'.'.$payload, self::decode($signature), $key, OPENSSL_ALGO_SHA256)) {
                throw new \UnexpectedValueException();
            }
            $now ??= time();
            if (($claims['iss'] ?? '') !== $this->issuer || ($claims['aud'] ?? '') !== $widget || ($claims['origin'] ?? '') !== $origin || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null) || $claims['iat'] > $now + 30 || $claims['exp'] <= $now || $claims['exp'] - $claims['iat'] > 300 || $claims['iat'] < $now - 300 || !is_string($claims['sub'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{1,30}:[A-Za-z0-9_-]{1,64}$/', $claims['sub'])) {
                throw new \UnexpectedValueException();
            }
            return array_intersect_key($claims, array_flip(['sub', 'name', 'email', 'phone', 'locale']));
        } catch (\Throwable) {
            throw new \DomainException('identity_invalid');
        }
    }

    private static function decode(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) throw new \UnexpectedValueException();
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $decoded) throw new \UnexpectedValueException();
        return $decoded;
    }
}
