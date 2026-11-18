<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * What an adapter returns on success: the raw text and what the provider reported.
 */
final class AdapterResponse
{
    public function __construct(
        public readonly string $rawText,
        public readonly string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly int $httpStatus,
        public readonly float $latency,
    ) {
    }
}
