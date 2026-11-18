<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * The cleaned text sent beside an image (PRD 4.2). It is data, not instructions.
 */
final class Context
{
    public function __construct(
        public readonly string $text,
        public readonly string $language,
        public readonly int $postId,
        public readonly bool $shared,
        public readonly bool $instructionLike,
        public readonly string $postTitle = '',
    ) {
    }
}
