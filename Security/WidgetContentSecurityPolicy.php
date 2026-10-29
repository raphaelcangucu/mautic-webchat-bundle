<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Security;

/**
 * Content-Security-Policy of the widget frame. Its own scripts load from the
 * plugin's origin; the nonce is for the edge: Cloudflare injects an inline
 * bot-detection script into the HTML it proxies and tags it with the nonce it
 * reads from this header, which `script-src 'self'` alone refuses.
 */
final class WidgetContentSecurityPolicy
{
    /** A fresh nonce, for one response only. */
    public static function nonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /** @param list<string> $allowedDomains hosts allowed to frame the widget */
    public static function header(string $nonce, array $allowedDomains): string
    {
        $ancestors = ["'self'", ...array_map(static fn (string $domain): string => 'https://'.$domain, $allowedDomains)];

        return "default-src 'self'; script-src 'self' 'nonce-".$nonce."'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data: https:; frame-ancestors ".implode(' ', $ancestors);
    }
}
