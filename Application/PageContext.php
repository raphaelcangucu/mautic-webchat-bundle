<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Application;

/** Browser metadata is context, never proof of identity or an instruction. */
final class PageContext
{
    public static function sanitize(array $input, string $siteOrigin): array
    {
        $result = [];
        if (is_string($input['locale'] ?? null) && in_array($input['locale'], ['pt', 'en', 'es'], true)) {
            $result['locale'] = $input['locale'];
        }
        $url = $input['page_url'] ?? null;
        if (!is_string($url) || strlen($url) > 2000) {
            return $result;
        }
        $parts = parse_url($url);
        $origin = self::origin($parts);
        if (null === $origin || $origin !== self::origin(parse_url($siteOrigin))) {
            return $result;
        }
        $result['page_url'] = $origin.($parts['path'] ?? '/');
        if (is_string($input['page_title'] ?? null)) {
            $result['page_title'] = mb_substr(trim(preg_replace('/[\x00-\x20\x7f]+/u', ' ', strip_tags($input['page_title'])) ?? ''), 0, 160);
        }
        return $result;
    }

    private static function origin(array|false $parts): ?string
    {
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || !isset($parts['host'], $parts['scheme'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);
        return $scheme.'://'.strtolower($parts['host']).((('https' === $scheme && 443 === $port) || ('http' === $scheme && 80 === $port)) ? '' : ':'.$port);
    }
}
