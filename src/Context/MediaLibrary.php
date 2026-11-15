<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Context;

/**
 * The few database lookups the scan and context steps need. Everything else
 * goes through ordinary WordPress functions.
 */
interface MediaLibrary
{
    /**
     * Image attachment IDs above $afterId, ascending, at most $limit.
     *
     * @return list<int>
     */
    public function attachmentPage(int $afterId, int $limit): array;

    /**
     * Loads meta for many posts in one query.
     *
     * @param list<int> $ids
     */
    public function primeMeta(array $ids): void;

    /**
     * Posts whose featured image is the attachment.
     *
     * @return list<int>
     */
    public function thumbnailUsers(int $attachmentId): array;

    /**
     * Posts whose content refers to the attachment by block ID, by the
     * wp-image-<id> class, or by one of the URLs. Public posts first, then
     * newest first, at most $limit.
     *
     * @param list<string> $urls
     * @return list<int>
     */
    public function contentUsers(int $attachmentId, array $urls, int $limit): array;
}
