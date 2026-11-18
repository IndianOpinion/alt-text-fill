<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * One history row: a write and the exact state before it (PRD case 51).
 */
final class Entry
{
    public const APPROVE = 'approve';
    public const APPROVE_EDITED = 'approve-edited';
    public const DECORATIVE = 'decorative';
    public const UNDO = 'undo';

    public function __construct(
        public readonly int $id,
        public readonly int $attachmentId,
        public readonly bool $previousExists,
        public readonly ?string $previousValue,
        public readonly bool $newExists,
        public readonly ?string $newValue,
        public readonly bool $markerBefore,
        public readonly bool $markerAfter,
        public readonly string $action,
        public readonly int $userId,
        public readonly int $created,
        public readonly string $runId,
        public readonly int $suggestionId,
        public readonly int $undoes = 0,
        public readonly int $undoneBy = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['attachment_id'] ?? 0),
            (bool) (int) ($row['previous_exists'] ?? 0),
            isset($row['previous_value']) ? (string) $row['previous_value'] : null,
            (bool) (int) ($row['new_exists'] ?? 0),
            isset($row['new_value']) ? (string) $row['new_value'] : null,
            (bool) (int) ($row['marker_before'] ?? 0),
            (bool) (int) ($row['marker_after'] ?? 0),
            (string) ($row['action'] ?? ''),
            (int) ($row['user_id'] ?? 0),
            (int) ($row['created'] ?? 0),
            (string) ($row['run_id'] ?? ''),
            (int) ($row['suggestion_id'] ?? 0),
            (int) ($row['undoes'] ?? 0),
            (int) ($row['undone_by'] ?? 0),
        );
    }

    public function isUndone(): bool
    {
        return $this->undoneBy > 0;
    }
}
