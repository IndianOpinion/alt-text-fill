<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * An image attachment that passed every candidate rule (PRD 4.1).
 */
final class Candidate
{
    /**
     * @param list<int> $usedBy Post IDs found to use the image, newest first.
     */
    public function __construct(
        public readonly int $attachmentId,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
        public readonly int $contextPostId,
        public readonly bool $shared,
        public readonly array $usedBy = [],
        public readonly bool $weakAlt = false,
        public readonly string $currentAlt = '',
    ) {
    }
}
