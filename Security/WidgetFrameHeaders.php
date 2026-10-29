<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Security;

/**
 * Security and cache headers of the widget frame. Its scripts all load from
 * the plugin's origin, so inline scripts stay refused. `no-transform` keeps
 * Cloudflare from injecting its inline bot-detection script into the frame:
 * the policy refused it, and every host page logged the violation. A CSP nonce
 * is not an option, because Nginx serves this page from a shared cache and the
 * nonce would be public and reused.
 */
final class WidgetFrameHeaders
{
    /**
     * @param list<string> $allowedDomains hosts allowed to frame the widget
     *
     * @return array<string, string>
     */
    public static function for(array $allowedDomains): array
    {
        $ancestors = ["'self'", ...array_map(static fn (string $domain): string => 'https://'.$domain, $allowedDomains)];

        return [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data: https:; frame-ancestors ".implode(' ', $ancestors),
            'Cache-Control' => 'no-store, no-transform',
        ];
    }
}
