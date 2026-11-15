<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Exception\MalformedAnswerException;
use IndianOpinion\AltTextFill\Model\Answer;

/**
 * Parses a model answer into an Answer (PRD cases 30 and 34). A JSON object
 * inside a code fence or after leading prose is accepted once, by taking the
 * first complete object. Anything else is malformed.
 */
final class AnswerParser
{
    public static function parse(string $raw): Answer
    {
        $data = self::decode($raw);
        if ($data === null) {
            throw new MalformedAnswerException('The answer is not a JSON object.');
        }
        $alt = $data['alt'] ?? '';
        if (!is_string($alt) && !is_numeric($alt)) {
            throw new MalformedAnswerException('The "alt" field is not a string.');
        }
        $confidence = $data['confidence'] ?? 0;

        return new Answer(
            (string) $alt,
            self::bool($data['decorative'] ?? false),
            self::bool($data['contains_text'] ?? false),
            self::bool($data['names_person'] ?? false),
            self::bool($data['matches_context'] ?? true),
            is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : 0.0
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $raw = trim($raw);
        $direct = json_decode($raw, true);
        if (is_array($direct) && !array_is_list($direct)) {
            return $direct;
        }
        $object = self::firstObject($raw);
        if ($object === null) {
            return null;
        }
        $decoded = json_decode($object, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /** The first balanced {...} in the text, aware of strings and escapes. */
    public static function firstObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', '1', 'yes'], true);
        }

        return (bool) $value;
    }
}
