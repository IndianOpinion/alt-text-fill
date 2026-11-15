<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Decides whether an attachment's alt counts as empty (PRD cases 4 and 5).
 *
 * Ruling 1: an alt row holding an empty string counts as never set. Only the
 * plugin's own decorative marker means decorative.
 */
class EmptyAlt
{
    public function __construct(private AltField $field, private Settings $settings)
    {
    }

    public function isEmpty(int $attachmentId): bool
    {
        $value = $this->field->read($attachmentId);
        if ($value === null || Text::isBlank($value)) {
            return true;
        }

        return $this->settings->bool('weak_alt') && self::isWeak($value, (string) get_attached_file($attachmentId));
    }

    /** True when the value is not blank but weak (case 5). Independent of the setting. */
    public function isWeakValue(int $attachmentId): bool
    {
        $value = $this->field->read($attachmentId);

        return $value !== null && !Text::isBlank($value) && self::isWeak($value, (string) get_attached_file($attachmentId));
    }

    /**
     * @return array{patterns: list<string>, words: list<string>}
     */
    public static function weakPatterns(): array
    {
        $defaults = [
            'patterns' => [
                '/^(img|dsc|dscn|dscf|pxl|mvimg|photo|image)[_ -]?\d{3,}([_ -]\d+)*$/i',
                '/^screen ?shot[ _-]?\d{4}[\d _.:-]*( at [\d .:]+)?( ?(am|pm))?$/i',
                '/^whatsapp image [\d -]+ at [\d.]+( ?(am|pm))?$/i',
                '/^\d{8}[_ -]\d{6}$/',
            ],
            'words' => ['image', 'photo', 'picture', 'img', 'untitled', 'placeholder'],
        ];
        $filtered = apply_filters('alt_text_fill_weak_alt_patterns', $defaults);
        if (!is_array($filtered)) {
            return $defaults;
        }

        return [
            'patterns' => array_values(array_map('strval', (array) ($filtered['patterns'] ?? []))),
            'words' => array_values(array_map(static fn ($w): string => mb_strtolower((string) $w), (array) ($filtered['words'] ?? []))),
        ];
    }

    public static function isWeak(string $value, string $filePath): bool
    {
        $plain = Text::collapse($value);
        $normal = Text::normalise($plain);
        $rules = self::weakPatterns();
        if (in_array($normal, $rules['words'], true)) {
            return true;
        }
        $bare = (string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $plain);
        foreach ($rules['patterns'] as $pattern) {
            if (Text::matches($pattern, $bare)) {
                return true;
            }
        }
        if ($filePath !== '') {
            $file = basename($filePath);
            if (mb_strtolower($plain) === mb_strtolower($file) || $normal === Text::fileNameWords($file)) {
                return true;
            }
        }

        return false;
    }
}
