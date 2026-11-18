<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Small text helpers shared by the candidate, context and validation steps.
 */
final class Text
{
    /** Zero-width and invisible format characters. */
    public const INVISIBLE = '/[\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]/u';

    /** True when the value is empty after removing every kind of whitespace and invisible character. */
    public static function isBlank(string $value): bool
    {
        return self::collapse($value) === '';
    }

    /** Removes invisible characters, turns any whitespace (NBSP included) into single spaces, trims. */
    public static function collapse(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = (string) preg_replace(self::INVISIBLE, '', $value);
        $value = (string) preg_replace('/[\p{Cc}\p{Z}\s]+/u', ' ', $value);

        return trim($value);
    }

    /** Cuts at a word boundary at or below $max characters. Never mid-word. */
    public static function trimToWord(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        $cut = mb_substr($value, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');
        if ($space === false || $space === 0) {
            return '';
        }

        return rtrim(mb_substr($cut, 0, $space), " ,;:-");
    }

    /**
     * Lower case, punctuation removed, whitespace collapsed: the form used for
     * "copies title" and "copies file name" comparisons.
     */
    public static function normalise(string $value): string
    {
        $value = mb_strtolower(self::collapse($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return list<string>
     */
    public static function words(string $value): array
    {
        $normal = self::normalise($value);

        return $normal === '' ? [] : array_values(array_unique(explode(' ', $normal)));
    }

    /** The file name in readable form: extension removed, separators to spaces. */
    public static function fileNameWords(string $fileName): string
    {
        $base = basename($fileName);
        $base = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $base);
        $base = (string) preg_replace('/-(\d+x\d+|scaled|rotated|e\d{10,})$/', '', $base);

        return self::normalise((string) preg_replace('/[_\-.+]+/', ' ', $base));
    }

    /**
     * preg_match for patterns that come from a filter: an invalid pattern is
     * treated as no match instead of raising a warning.
     */
    public static function matches(string $pattern, string $subject): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        return $result === 1;
    }
}
