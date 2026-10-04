<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Security;

use MauticPlugin\MauticWebChatBundle\Entity\ChatWidget;

final class WidgetOrigin
{
    public function assertAllowed(ChatWidget $widget, string $origin): string
    {
        $normalized = $this->normalizeOrigin($origin);
        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));
        foreach ($widget->getAllowedDomains() as $allowed) {
            $allowedHost = strtolower(trim((string) preg_replace('#^https?://#', '', $allowed), '/'));
            if ('' !== $allowedHost && ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost))) {
                return $normalized;
            }
        }
        throw new \DomainException('Este domínio não está autorizado a usar o chat.');
    }

    public function normalizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = trim(explode('/', $domain, 2)[0]);
        if (!preg_match('/^(localhost(?::\d+)?|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})$/i', $domain)) {
            return null;
        }
        return $domain;
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url(trim($origin));
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new \DomainException('Origem do chat inválida.');
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        return strtolower($parts['scheme'].'://'.$parts['host'].$port);
    }
}
