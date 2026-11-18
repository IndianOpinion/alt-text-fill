<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Opt-in render fill (case 60). Reads only the stored alt value.
 */
final class RenderFill
{
    private static function enabled(): bool
    {
        return Plugin::container()->settings->bool('render_fill');
    }

    /**
     * @param array<string, mixed> $attr
     * @param mixed                $attachment
     * @return array<string, mixed>
     */
    public static function attributes(array $attr, $attachment): array
    {
        if (!self::enabled() || !$attachment instanceof \WP_Post) {
            return $attr;
        }
        if (!isset($attr['alt']) || Text::isBlank((string) $attr['alt'])) {
            $stored = Plugin::container()->field->read((int) $attachment->ID);
            if ($stored !== null && !Text::isBlank($stored)) {
                $attr['alt'] = Text::collapse(wp_strip_all_tags($stored));
            }
        }

        return $attr;
    }

    /**
     * Image blocks whose img tag has no alt attribute at all take the stored
     * alt. An alt="" written in the block is left alone: it may be deliberate.
     *
     * @param array<string, mixed> $block
     */
    public static function block(string $html, array $block): string
    {
        if (($block['blockName'] ?? '') !== 'core/image' || !self::enabled()) {
            return $html;
        }
        $id = (int) ($block['attrs']['id'] ?? 0);
        if ($id <= 0 || preg_match('/<img\b[^>]*>/i', $html, $m) !== 1) {
            return $html;
        }
        $tag = $m[0];
        if (preg_match('/\salt\s*=/i', $tag) === 1) {
            return $html;
        }
        $stored = Plugin::container()->field->read($id);
        if ($stored === null || Text::isBlank($stored)) {
            return $html;
        }
        $newTag = (string) preg_replace('/^<img\b/i', '<img alt="' . esc_attr(Text::collapse(wp_strip_all_tags($stored))) . '"', $tag);
        $pos = strpos($html, $tag);

        return $pos === false ? $html : substr_replace($html, $newTag, $pos, strlen($tag));
    }
}
