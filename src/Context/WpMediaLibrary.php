<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Context;

/**
 * MediaLibrary through $wpdb: ID-range pages, never one join over all meta.
 */
final class WpMediaLibrary implements MediaLibrary
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    public function attachmentPage(int $afterId, int $limit): array
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT ID FROM %i WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
            $this->wpdb->posts,
            'image/%',
            $afterId,
            $limit
        ));

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    public function primeMeta(array $ids): void
    {
        if ($ids !== []) {
            update_meta_cache('post', $ids);
        }
    }

    public function thumbnailUsers(int $attachmentId): array
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT pm.post_id FROM %i pm INNER JOIN %i p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %s AND p.post_type NOT IN ('revision', 'attachment')
             ORDER BY p.post_date DESC LIMIT 100",
            $this->wpdb->postmeta,
            $this->wpdb->posts,
            (string) $attachmentId
        ));

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    public function contentUsers(int $attachmentId, array $urls, int $limit): array
    {
        $likes = [
            '%' . $this->wpdb->esc_like('wp-image-' . $attachmentId . '"') . '%',
            '%' . $this->wpdb->esc_like('wp-image-' . $attachmentId . ' ') . '%',
            '%' . $this->wpdb->esc_like('"id":' . $attachmentId . ',') . '%',
            '%' . $this->wpdb->esc_like('"id":' . $attachmentId . '}') . '%',
        ];
        foreach ($urls as $url) {
            $likes[] = '%' . $this->wpdb->esc_like($url) . '%';
        }
        $or = implode(' OR ', array_fill(0, count($likes), 'post_content LIKE %s'));
        $sql = "SELECT ID FROM %i
                WHERE post_type NOT IN ('revision', 'attachment', 'nav_menu_item') AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
                AND ({$or})
                ORDER BY (post_status = 'publish' AND post_password = '') DESC, post_date DESC LIMIT %d";
        $ids = $this->wpdb->get_col($this->wpdb->prepare($sql, ...array_merge([$this->wpdb->posts], $likes, [$limit])));

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }
}
