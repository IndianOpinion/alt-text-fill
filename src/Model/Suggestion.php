<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * A row of the review list.
 */
final class Suggestion
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const EDITED = 'edited';
    public const REJECTED = 'rejected';
    public const SUPERSEDED = 'superseded';
    public const STALE = 'stale';
    public const EXPIRED = 'expired';
    /** A model answer that failed validation: recorded, never approvable. */
    public const INVALID = 'invalid';

    public const KIND_TEXT = 'text';
    public const KIND_DECORATIVE = 'decorative';

    public const STATUSES = [
        self::PENDING, self::APPROVED, self::EDITED, self::REJECTED,
        self::SUPERSEDED, self::STALE, self::EXPIRED, self::INVALID,
    ];

    /**
     * @param list<string> $flags
     */
    public function __construct(
        public readonly int $id,
        public readonly int $attachmentId,
        public readonly string $runId,
        public readonly string $kind,
        public readonly string $text,
        public readonly string $originalText,
        public readonly string $finalText,
        public readonly array $flags,
        public readonly float $confidence,
        public readonly string $status,
        public readonly string $rejectReason,
        public readonly string $rejectNote,
        public readonly int $contextPostId,
        public readonly string $fileHash,
        public readonly string $adapter,
        public readonly string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly int $created,
        public readonly int $decided,
        public readonly int $decidedBy,
        public readonly string $previousAlt = '',
        public readonly string $contextText = '',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $flags = json_decode((string) ($row['flags'] ?? '[]'), true);

        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['attachment_id'] ?? 0),
            (string) ($row['run_id'] ?? ''),
            (string) ($row['kind'] ?? self::KIND_TEXT),
            (string) ($row['suggested_text'] ?? ''),
            (string) ($row['original_text'] ?? ''),
            (string) ($row['final_text'] ?? ''),
            is_array($flags) ? array_values(array_map('strval', $flags)) : [],
            (float) ($row['confidence'] ?? 0),
            (string) ($row['status'] ?? self::PENDING),
            (string) ($row['reject_reason'] ?? ''),
            (string) ($row['reject_note'] ?? ''),
            (int) ($row['context_post_id'] ?? 0),
            (string) ($row['file_hash'] ?? ''),
            (string) ($row['adapter'] ?? ''),
            (string) ($row['model'] ?? ''),
            isset($row['input_tokens']) ? (int) $row['input_tokens'] : null,
            isset($row['output_tokens']) ? (int) $row['output_tokens'] : null,
            (int) ($row['created'] ?? 0),
            (int) ($row['decided'] ?? 0),
            (int) ($row['decided_by'] ?? 0),
            (string) ($row['previous_alt'] ?? ''),
            (string) ($row['context_text'] ?? ''),
        );
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
