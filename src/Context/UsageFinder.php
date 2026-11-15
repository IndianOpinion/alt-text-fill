<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Context;

use IndianOpinion\AltTextFill\Settings;

/**
 * Finds the posts that use an image (PRD case 14 and 15): the parent, posts
 * that have it as the featured image, then posts whose content refers to it
 * by block ID, by class or by URL, bounded and newest first.
 */
class UsageFinder
{
    public function __construct(private MediaLibrary $library, private Settings $settings)
    {
    }

    /**
     * @return list<int> Post IDs, parent first, without duplicates.
     */
    public function find(int $attachmentId): array
    {
        $attachment = get_post($attachmentId);
        $ids = [];
        if ($attachment instanceof \WP_Post && (int) $attachment->post_parent > 0) {
            $ids[] = (int) $attachment->post_parent;
        }
        foreach ($this->library->thumbnailUsers($attachmentId) as $id) {
            $ids[] = $id;
        }
        $urls = $this->settings->bool('url_lookup') ? self::urls($attachmentId) : [];
        $limit = $this->settings->int('url_lookup_limit');
        foreach ($this->library->contentUsers($attachmentId, $urls, $limit) as $id) {
            $ids[] = $id;
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0 && $id !== $attachmentId)));
        $filtered = apply_filters('alt_text_fill_context_posts', $ids, $attachmentId);

        return is_array($filtered) ? array_values(array_unique(array_map('intval', $filtered))) : $ids;
    }

    /**
     * The file URL and every intermediate size URL, scheme removed so http
     * and https references both match.
     *
     * @return list<string>
     */
    public static function urls(int $attachmentId): array
    {
        $url = wp_get_attachment_url($attachmentId);
        if (!is_string($url) || $url === '') {
            return [];
        }
        $urls = [$url];
        $meta = wp_get_attachment_metadata($attachmentId);
        $base = substr($url, 0, (int) strrpos($url, '/') + 1);
        if (is_array($meta)) {
            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (is_array($size) && isset($size['file']) && is_string($size['file'])) {
                    $urls[] = $base . $size['file'];
                }
            }
            if (isset($meta['original_image']) && is_string($meta['original_image'])) {
                $urls[] = $base . $meta['original_image'];
            }
        }

        return array_values(array_unique(array_map(static fn (string $u): string => (string) preg_replace('#^https?:#i', '', $u), $urls)));
    }

    public static function isPublic(int $postId): bool
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return false;
        }

        return $post->post_status === 'publish' && (string) $post->post_password === '';
    }
}
