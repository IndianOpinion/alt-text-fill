<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * The raw fields of a model answer (PRD case 30), after type checks only.
 */
final class Answer
{
    public function __construct(
        public readonly string $alt,
        public readonly bool $decorative,
        public readonly bool $containsText,
        public readonly bool $namesPerson,
        public readonly bool $matchesContext,
        public readonly float $confidence,
    ) {
    }
}
