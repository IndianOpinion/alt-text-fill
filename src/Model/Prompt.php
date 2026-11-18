<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * The instructions (system) and the delimited request (user) for one call.
 */
final class Prompt
{
    public function __construct(
        public readonly string $system,
        public readonly string $user,
        public readonly string $source = 'built-in',
    ) {
    }
}
