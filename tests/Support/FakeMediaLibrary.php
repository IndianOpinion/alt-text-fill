<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Support;

use IndianOpinion\AltTextFill\Context\MediaLibrary;

/**
 * MediaLibrary over the stub post store, with the same ordering rules as
 * the wpdb one. Counts calls so tests can prove bounds.
 */
final class FakeMediaLibrary implements MediaLibrary
{
    public int $contentCalls = 0;
    /** @var list<array{id: int, urls: list<string>, limit: int}> */
    public array $contentArgs = [];

    public function attachmentPage(int $afterId, int $limit): array
    {
        $ids = [];
        foreach (\WP::$posts as $id => $post) {
            if ($post->post_type === 'attachment' && str_starts_with($post->post_mime_type, 'image/') && $id > $afterId) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    public function primeMeta(array $ids): void
    {
    }

    public function thumbnailUsers(int $attachmentId): array
    {
        $out = [];
        foreach (\WP::$posts as $id => $post) {
            if ($post->post_type !== 'attachment' && (string) get_post_meta($id, '_thumbnail_id', true) === (string) $attachmentId) {
                $out[] = $id;
            }
        }

        return $this->newestFirst($out);
    }

    public function contentUsers(int $attachmentId, array $urls, int $limit): array
    {
        $this->contentCalls++;
        $this->contentArgs[] = ['id' => $attachmentId, 'urls' => $urls, 'limit' => $limit];
        $needles = ['wp-image-' . $attachmentId . '"', 'wp-image-' . $attachmentId . ' ', '"id":' . $attachmentId . ',', '"id":' . $attachmentId . '}'];
        foreach ($urls as $url) {
            $needles[] = $url;
        }
        $out = [];
        foreach (\WP::$posts as $id => $post) {
            if ($post->post_type === 'attachment' || in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
                continue;
            }
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($post->post_content, $needle)) {
                    $out[] = $id;
                    break;
                }
            }
        }
        usort($out, static function (int $a, int $b): int {
            $pa = \WP::$posts[$a];
            $pb = \WP::$posts[$b];
            $publicA = $pa->post_status === 'publish' && $pa->post_password === '' ? 1 : 0;
            $publicB = $pb->post_status === 'publish' && $pb->post_password === '' ? 1 : 0;

            return [$publicB, $pb->post_date] <=> [$publicA, $pa->post_date];
        });

        return array_slice($out, 0, $limit);
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function newestFirst(array $ids): array
    {
        usort($ids, static fn (int $a, int $b): int => strcmp(\WP::$posts[$b]->post_date, \WP::$posts[$a]->post_date));

        return $ids;
    }
}
