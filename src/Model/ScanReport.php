<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * The result of one scan page or a bounded scan (PRD 4.1).
 */
final class ScanReport
{
    /**
     * @param list<Candidate>    $candidates
     * @param array<string, int> $skipped Count per reason.
     * @param array<int, string> $reasons Skip reason per attachment ID.
     */
    public function __construct(
        public readonly int $considered,
        public readonly array $candidates,
        public readonly array $skipped,
        public readonly array $reasons,
        public readonly int $cursor,
        public readonly bool $wrapped,
        public readonly int $emptyAlt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'considered' => $this->considered,
            'candidates' => array_map(static fn (Candidate $c): int => $c->attachmentId, $this->candidates),
            'skipped' => $this->skipped,
            'cursor' => $this->cursor,
            'wrapped' => $this->wrapped,
            'empty_alt' => $this->emptyAlt,
        ];
    }
}
