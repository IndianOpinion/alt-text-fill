<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Context;

use IndianOpinion\AltTextFill\Model\Context;
use IndianOpinion\AltTextFill\Settings;
use IndianOpinion\AltTextFill\Text;

/**
 * Builds the short, clean context for one image (PRD 4.2). The file name is
 * never part of it.
 */
class ContextBuilder
{
    /** Phrases that read as instructions to a model (case 20). */
    private const INSTRUCTION_PATTERNS = [
        '/\b(ignore|disregard|forget)\b.{0,30}\b(previous|prior|above|earlier|all|any)\b.{0,20}\b(instructions?|prompts?|rules?|messages?)\b/iu',
        '/\b(system|developer)\s+(prompt|message|instructions?)\b/iu',
        '/\byou\s+are\s+now\b/iu',
        '/\bnew\s+instructions?\s*:/iu',
        '/\b(respond|reply|answer)\s+(only\s+)?with\b/iu',
        '/<\/?\s*(system|assistant|user|instructions?)\s*>/iu',
        '/\bact\s+as\s+(an?|the)\b/iu',
    ];

    public function __construct(private Settings $settings)
    {
    }

    public function build(int $attachmentId, int $postId, bool $shared): Context
    {
        $attachment = get_post($attachmentId);
        $parts = [];
        if ($attachment instanceof \WP_Post) {
            $caption = self::clean((string) $attachment->post_excerpt);
            $description = self::clean((string) $attachment->post_content);
            if ($caption !== '') {
                $parts[] = 'Caption: ' . $caption;
            }
            if ($description !== '' && $description !== $caption) {
                $parts[] = 'Description: ' . $description;
            }
        }
        $title = '';
        if (!$shared && $postId > 0) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                $title = self::clean((string) $post->post_title);
                if ($title !== '') {
                    $parts[] = 'Post title: ' . $title;
                }
                [$before, $after, $heading] = self::around((string) $post->post_content, $attachmentId);
                if ($before === '' && $after === '') {
                    $excerpt = self::clean((string) $post->post_excerpt);
                    if ($excerpt === '') {
                        $excerpt = Text::trimToWord(self::clean((string) $post->post_content), 400);
                    }
                    if ($excerpt !== '') {
                        $parts[] = 'Excerpt: ' . $excerpt;
                    }
                } else {
                    if ($before !== '') {
                        $parts[] = 'Text before the image: ' . $before;
                    }
                    if ($after !== '') {
                        $parts[] = 'Text after the image: ' . $after;
                    }
                }
                if ($heading !== '') {
                    $parts[] = 'Nearest heading: ' . $heading;
                }
            }
        }
        $text = implode("\n", $parts);
        $text = self::cap($text, $this->settings->int('context_length'));
        $filtered = apply_filters('alt_text_fill_context', $text, $attachmentId, $postId);
        $text = is_string($filtered) ? self::cap($filtered, max(1, $this->settings->int('context_length'))) : $text;

        return new Context(
            $text,
            $this->language($shared ? 0 : $postId),
            $shared ? 0 : $postId,
            $shared,
            self::looksLikeInstructions($text),
            $title
        );
    }

    /**
     * The context post's language wins (ruling 7), then the site override,
     * then the site locale.
     */
    public function language(int $postId): string
    {
        if ($postId > 0) {
            $postLanguage = apply_filters('alt_text_fill_post_language', null, $postId);
            if (is_string($postLanguage) && $postLanguage !== '') {
                return $postLanguage;
            }
        }
        $override = $this->settings->string('language');
        if ($override !== '') {
            return $override;
        }

        return (string) get_locale();
    }

    public static function looksLikeInstructions(string $text): bool
    {
        foreach (self::INSTRUCTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** HTML, shortcodes, block comments, scripts, styles and URLs removed; whitespace collapsed. */
    public static function clean(string $html): string
    {
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = (string) preg_replace('#<!--.*?-->#s', ' ', $text);
        $text = (string) preg_replace('#\[(\[?)[a-zA-Z][^\]]*\](?:[^\[]*\[/[a-zA-Z_-]+\])?(\]?)#', ' ', $text);
        $text = (string) preg_replace('#<[^>]*>#', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('#\b(?:https?://|www\.)\S+#iu', ' ', $text);
        $text = (string) preg_replace('#\b[\w.+-]+@[\w-]+\.[\w.-]+\b#u', ' ', $text);

        return Text::collapse($text);
    }

    public static function cap(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max + 1);
        $pos = max((int) mb_strrpos($cut, ' '), (int) mb_strrpos($cut, "\n"));

        return $pos > 0 ? rtrim(mb_substr($cut, 0, $pos)) : '';
    }

    /**
     * Two paragraphs before and after the image, and the nearest heading above.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function around(string $content, int $attachmentId): array
    {
        $markers = ['wp-image-' . $attachmentId . '"', 'wp-image-' . $attachmentId . ' ', '"id":' . $attachmentId . ',', '"id":' . $attachmentId . '}'];
        $url = wp_get_attachment_url($attachmentId);
        if (is_string($url) && $url !== '') {
            $stem = (string) preg_replace('/\.[a-z0-9]+$/i', '', basename($url));
            if (strlen($stem) >= 6) {
                $markers[] = '/' . $stem;
            }
        }
        $offset = null;
        foreach ($markers as $marker) {
            $pos = strpos($content, $marker);
            if ($pos !== false && ($offset === null || $pos < $offset)) {
                $offset = $pos;
            }
        }
        if ($offset === null) {
            return ['', '', ''];
        }
        $before = [];
        $after = [];
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $content, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[1] as $i => $match) {
                $text = self::clean($match[0]);
                if ($text === '') {
                    continue;
                }
                if ($m[0][$i][1] < $offset) {
                    $before[] = $text;
                } else {
                    $after[] = $text;
                }
            }
        }
        $heading = '';
        if (preg_match_all('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', substr($content, 0, $offset), $h) > 0) {
            $heading = self::clean((string) end($h[1]));
        }

        return [implode(' ', array_slice($before, -2)), implode(' ', array_slice($after, 0, 2)), $heading];
    }
}
