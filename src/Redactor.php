<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Removes secrets from any text before it is logged or shown.
 */
final class Redactor
{
    /** @var list<string> */
    private static array $secrets = [];

    public static function register(string $secret): void
    {
        if (strlen($secret) >= 4 && !in_array($secret, self::$secrets, true)) {
            self::$secrets[] = $secret;
        }
    }

    public static function reset(): void
    {
        self::$secrets = [];
    }

    public static function redact(string $text): string
    {
        foreach (self::$secrets as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }
        $text = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1[redacted]', $text);
        $text = (string) preg_replace('/\b(sk|pk|rk)-[A-Za-z0-9_-]{8,}/', '[redacted]', $text);
        $text = (string) preg_replace('/((?:api[_-]?key|token|secret|password)["\']?\s*[:=]\s*["\']?)[^"\'\s,&}]+/i', '$1[redacted]', $text);
        $text = (string) preg_replace('/data:[a-z\/+.-]+;base64,[A-Za-z0-9+\/=]{16,}/i', 'data:[image bytes removed]', $text);

        return $text;
    }

    /** Last four characters only, for screens. */
    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        return str_repeat('*', 8) . substr($secret, -4);
    }
}
