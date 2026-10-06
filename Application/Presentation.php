<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWebChatBundle\Application;

/** Versioned, allowlisted configuration; never accepts HTML, CSS or script URLs. */
final class Presentation
{
    public static function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $result = ['version' => 1, 'theme' => self::choice($input['theme'] ?? '', ['classic', 'macro', 'essential'], 'classic'), 'overrides' => []];
        foreach (['brandName' => 120, 'logoUrl' => 1000] as $key => $limit) {
            $value = mb_substr(trim((string) ($input[$key] ?? '')), 0, $limit);
            if ('logoUrl' === $key && '' !== $value && (!filter_var($value, FILTER_VALIDATE_URL) || 'https' !== parse_url($value, PHP_URL_SCHEME) || parse_url($value, PHP_URL_USER) || parse_url($value, PHP_URL_PASS))) {
                throw new \DomainException('Use uma URL HTTPS válida para o logo.');
            }
            $result[$key] = $value;
        }
        $choices = ['appearance' => ['auto', 'light', 'dark'], 'header' => ['surface', 'filled', 'minimal'], 'logo' => ['site', 'initials', 'url'], 'font' => ['site', 'system', 'arial', 'georgia'], 'density' => ['comfortable', 'compact'], 'position' => ['right', 'left'], 'shadow' => ['none', 'soft', 'regular'], 'formLayout' => ['vertical', 'grid']];
        foreach (['classic', 'macro', 'essential'] as $theme) {
            $saved = $input['overrides'][$theme] ?? [];
            $overrides = ['options' => [], 'light' => [], 'dark' => []];
            foreach ($choices as $key => $allowed) {
                if (isset($saved['options'][$key]) && in_array($saved['options'][$key], $allowed, true)) {
                    $overrides['options'][$key] = $saved['options'][$key];
                }
            }
            foreach (['radius' => [0, 24], 'controlRadius' => [0, 16], 'controlHeight' => [40, 56], 'width' => [320, 480]] as $key => [$min, $max]) {
                if (isset($saved['options'][$key]) && is_numeric($saved['options'][$key])) {
                    $overrides['options'][$key] = max($min, min($max, (int) $saved['options'][$key]));
                }
            }
            if (isset($saved['options']['showFooter']) && is_bool($saved['options']['showFooter'])) {
                $overrides['options']['showFooter'] = $saved['options']['showFooter'];
            }
            foreach (['light', 'dark'] as $variant) {
                foreach (['primary', 'buttonText', 'surface', 'background', 'title', 'text', 'muted', 'divider', 'accent'] as $key) {
                    $value = $saved[$variant][$key] ?? null;
                    if (is_string($value) && preg_match('/^#[a-f0-9]{6}$/i', $value)) {
                        $overrides[$variant][$key] = strtoupper($value);
                    }
                }
            }
            $result['overrides'][$theme] = array_filter($overrides);
        }
        foreach (['name', 'email', 'phone'] as $key) {
            if (isset($input['fields'][$key])) {
                $result['fields'][$key] = self::choice($input['fields'][$key], ['required', 'optional', 'hidden'], 'optional');
            }
        }
        foreach (['pt', 'en', 'es'] as $locale) {
            foreach (['greeting', 'offline'] as $key) {
                $value = mb_substr(trim((string) ($input['translations'][$locale][$key] ?? '')), 0, 500);
                if ('' !== $value) {
                    $result['translations'][$locale][$key] = $value;
                }
            }
        }
        return $result;
    }

    private static function choice(mixed $value, array $choices, string $fallback): string
    {
        return in_array($value, $choices, true) ? $value : $fallback;
    }
}
