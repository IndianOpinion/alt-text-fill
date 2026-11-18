<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Model\Answer;
use IndianOpinion\AltTextFill\Model\Candidate;
use IndianOpinion\AltTextFill\Model\Context;

/**
 * Cleans and checks a model answer before a person sees it (PRD 4.5).
 */
class Validator
{
    public const FLAG_NAMES_PERSON = 'names person';
    public const FLAG_CONTAINS_TEXT = 'contains text';
    public const FLAG_MISMATCH = 'image may not match post';
    public const FLAG_LOW_CONFIDENCE = 'low confidence';
    public const FLAG_SHARED = 'shared image';
    public const FLAG_TRIMMED = 'trimmed';
    public const FLAG_WEAK_ALT = 'weak alt replaced';
    public const FLAG_INSTRUCTIONS = 'context contained instructions';
    public const FLAG_DECORATIVE = 'suggest decorative';

    public const R_EMPTY = 'empty';
    public const R_SHORT = 'too short';
    public const R_REFUSAL = 'refusal';
    public const R_LINK = 'contains link or file name';
    public const R_TITLE = 'copies title';
    public const R_FILE = 'copies file name';
    public const R_LONG = 'too long';
    public const R_MALFORMED = 'malformed';

    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return array<string, list<string>> Openers per language code ("*" applies to all).
     */
    public static function openers(): array
    {
        $defaults = [
            'en' => [
                'an image of', 'image of', 'a photo of', 'photo of', 'a photograph of', 'photograph of',
                'a picture of', 'picture of', 'a picture showing', 'picture showing', 'an image showing',
                'image showing', 'a photo showing', 'photo showing', 'this image shows', 'this is an image of',
                'this is a photo of', 'the image shows', 'the photo shows', 'the picture shows', 'image:', 'photo:',
            ],
        ];
        $filtered = apply_filters('alt_text_fill_openers', $defaults);

        return self::lists(is_array($filtered) ? $filtered : $defaults);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function refusals(): array
    {
        $defaults = [
            'en' => [
                "i can't", 'i cannot', 'i can not', "i'm sorry", 'i am sorry', 'sorry,', 'as an ai',
                'unable to', "i'm unable", "i won't", 'i will not', 'cannot help', "can't help",
                'not able to', 'i apologise', 'i apologize',
            ],
        ];
        $filtered = apply_filters('alt_text_fill_refusal_patterns', $defaults);

        return self::lists(is_array($filtered) ? $filtered : $defaults);
    }

    /**
     * Cleaning only (case 35). Used for model text and for edited text.
     */
    public function clean(string $text, string $language = 'en'): string
    {
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = Text::collapse($text);
        for ($i = 0; $i < 3; $i++) {
            $before = $text;
            $text = trim($text);
            if (preg_match('/^(["\'\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}`])(.*)(["\'\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}`])$/u', $text, $m) === 1) {
                $text = trim($m[2]);
            }
            foreach (self::forLanguage(self::openers(), $language) as $opener) {
                if ($opener !== '' && mb_stripos($text, $opener) === 0) {
                    $text = ltrim(mb_substr($text, mb_strlen($opener)), " ,:;-");
                    break;
                }
            }
            if ($text === $before) {
                break;
            }
        }
        if ($text !== '') {
            $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
        }

        return $text;
    }

    /**
     * Cleans, checks, trims and flags one answer.
     *
     * @return array{ok: bool, text: string, reason: string, flags: list<string>, decorative: bool}
     */
    public function validate(Answer $answer, Context $context, Candidate $candidate, string $fileName): array
    {
        $flags = [];
        $lowConfidence = $answer->confidence < $this->settings->float('low_confidence');
        if ($answer->decorative && $answer->confidence >= $this->settings->float('decorative_confidence')) {
            $flags[] = self::FLAG_DECORATIVE;
            if ($candidate->shared) {
                $flags[] = self::FLAG_SHARED;
            }

            return $this->filter(['ok' => true, 'text' => '', 'reason' => '', 'flags' => $flags, 'decorative' => true], $answer, $context, $candidate);
        }
        $text = $this->clean($answer->alt, $context->language);
        $reason = $this->reason($text, $context, $fileName, $context->language);
        if ($reason === '') {
            $max = $this->settings->int('max_length');
            if (mb_strlen($text) > $max) {
                $trimmed = self::trimTo($text, $max, $this->settings->int('min_length'));
                if ($trimmed === '') {
                    $reason = self::R_LONG;
                } else {
                    $text = $trimmed;
                    $flags[] = self::FLAG_TRIMMED;
                }
            }
        }
        if ($answer->namesPerson) {
            $flags[] = self::FLAG_NAMES_PERSON;
        }
        if ($answer->containsText) {
            $flags[] = self::FLAG_CONTAINS_TEXT;
        }
        if (!$answer->matchesContext) {
            $flags[] = self::FLAG_MISMATCH;
        }
        if ($lowConfidence) {
            $flags[] = self::FLAG_LOW_CONFIDENCE;
        }
        if ($candidate->shared) {
            $flags[] = self::FLAG_SHARED;
        }
        if ($candidate->weakAlt) {
            $flags[] = self::FLAG_WEAK_ALT;
        }
        if ($context->instructionLike) {
            $flags[] = self::FLAG_INSTRUCTIONS;
        }

        return $this->filter(['ok' => $reason === '', 'text' => $text, 'reason' => $reason, 'flags' => $flags, 'decorative' => false], $answer, $context, $candidate);
    }

    /**
     * The rejection rules of case 36; empty string when none fires.
     */
    public function reason(string $text, Context $context, string $fileName, string $language = 'en'): string
    {
        if ($text === '') {
            return self::R_EMPTY;
        }
        if (mb_strlen($text) < $this->settings->int('min_length')) {
            return self::R_SHORT;
        }
        $lower = mb_strtolower($text);
        foreach (self::forLanguage(self::refusals(), $language) as $pattern) {
            if ($pattern !== '' && mb_strpos($lower, $pattern) !== false) {
                return self::R_REFUSAL;
            }
        }
        if (preg_match('#(https?://|www\.)\S+|\b[\w.+-]+@[\w-]+\.[\w.-]+\b|\b[\w-]+\.(jpe?g|png|gif|webp|avif|heic|heif|tiff?|bmp|svg|ico)\b#iu', $text) === 1) {
            return self::R_LINK;
        }
        if (self::copiesTitle($text, $context->postTitle)) {
            return self::R_TITLE;
        }
        if ($fileName !== '') {
            $normal = Text::normalise($text);
            if (mb_strtolower($text) === mb_strtolower(basename($fileName)) || ($normal !== '' && $normal === Text::fileNameWords($fileName))) {
                return self::R_FILE;
            }
        }

        return '';
    }

    /**
     * Same text after normalising, or (ruling 3: titles of three or more
     * words only) at least 80 per cent of the title's words in the text.
     */
    public static function copiesTitle(string $text, string $title): bool
    {
        $t = Text::normalise($title);
        if ($t === '') {
            return false;
        }
        if (Text::normalise($text) === $t) {
            return true;
        }
        $titleWords = Text::words($title);
        if (count($titleWords) < 3) {
            return false;
        }
        $shared = array_intersect($titleWords, Text::words($text));

        return count($shared) / count($titleWords) >= 0.8;
    }

    /** Trim at the last sentence end, else the last word boundary, before the limit. */
    public static function trimTo(string $text, int $max, int $min): string
    {
        $slice = mb_substr($text, 0, $max);
        if (preg_match_all('/[.!?](?=\s|$)/u', $slice, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($m[0]);
            $sentence = $last === false ? '' : trim(substr($slice, 0, $last[1] + 1));
            if (mb_strlen($sentence) >= $min) {
                return $sentence;
            }
        }
        $word = Text::trimToWord($text, $max);

        return mb_strlen($word) >= $min ? $word : '';
    }

    /**
     * Edited text from a person (case 45): cleaned and not empty; not subject
     * to the model rules. Returns the cleaned text or throws.
     */
    public function cleanEdited(string $text, string $language = 'en'): string
    {
        $clean = $this->clean($text, $language);
        if ($clean === '') {
            throw new \InvalidArgumentException('The edited alt text is empty.');
        }
        if (mb_strlen($clean) > Settings::MAX_ALT_CEILING) {
            throw new \InvalidArgumentException('The edited alt text is longer than ' . Settings::MAX_ALT_CEILING . ' characters.');
        }

        return $clean;
    }

    /**
     * The alt_text_fill_validate filter may reject or add flags; it cannot
     * change the text or clear a rejection.
     *
     * @param array{ok: bool, text: string, reason: string, flags: list<string>, decorative: bool} $result
     * @return array{ok: bool, text: string, reason: string, flags: list<string>, decorative: bool}
     */
    private function filter(array $result, Answer $answer, Context $context, Candidate $candidate): array
    {
        $filtered = apply_filters('alt_text_fill_validate', ['text' => $result['text'], 'flags' => $result['flags'], 'reason' => $result['reason']], $answer, $context, $candidate);
        if (is_array($filtered)) {
            if ($result['reason'] === '' && isset($filtered['reason']) && is_string($filtered['reason']) && $filtered['reason'] !== '') {
                $result['reason'] = $filtered['reason'];
                $result['ok'] = false;
            }
            foreach ((array) ($filtered['flags'] ?? []) as $flag) {
                if (is_string($flag) && $flag !== '' && !in_array($flag, $result['flags'], true)) {
                    $result['flags'][] = $flag;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, list<string>>
     */
    private static function lists(array $raw): array
    {
        $out = [];
        foreach ($raw as $lang => $items) {
            if (is_array($items)) {
                $list = array_values(array_map(static fn ($s): string => mb_strtolower((string) $s), $items));
                usort($list, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
                $out[(string) $lang] = $list;
            }
        }

        return $out;
    }

    /**
     * English plus the language's own list plus "*" entries.
     *
     * @param array<string, list<string>> $lists
     * @return list<string>
     */
    private static function forLanguage(array $lists, string $language): array
    {
        $code = strtolower(substr($language, 0, 2));
        $merged = array_merge($lists['*'] ?? [], $lists[$code] ?? [], $code === 'en' ? [] : ($lists['en'] ?? []));
        usort($merged, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_values(array_unique($merged));
    }
}
