<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * Counts and details of an undo of one entry, a run, or everything since a time.
 */
final class UndoReport
{
    /**
     * @param list<array{entry: int, result: string, message: string}> $details
     */
    public function __construct(
        public readonly int $restored,
        public readonly int $refused,
        public readonly int $alreadyUndone,
        public readonly array $details,
    ) {
    }

    public function isPartial(): bool
    {
        return $this->refused > 0;
    }
}
