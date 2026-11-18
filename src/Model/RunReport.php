<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * The record of one run (PRD case 68).
 */
final class RunReport
{
    /**
     * @param array<string, int>                $counts  considered, sent, suggestions, rejected, failures, enqueued.
     * @param array<string, int>                $skipped Count per skip reason.
     * @param list<array<string, mixed>>        $items   Per-image detail (always for dry runs).
     */
    public function __construct(
        public readonly string $id,
        public readonly int $started,
        public readonly int $finished,
        public readonly string $mode,
        public readonly string $trigger,
        public readonly array $counts,
        public readonly array $skipped,
        public readonly string $stopReason,
        public readonly int $userId,
        public readonly array $items = [],
        public readonly int $estimatedCalls = 0,
        public readonly int $remainingCalls = 0,
    ) {
    }

    public function isDryRun(): bool
    {
        return $this->mode === 'dry';
    }

    public function count(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'started' => $this->started,
            'finished' => $this->finished,
            'mode' => $this->mode,
            'trigger' => $this->trigger,
            'counts' => $this->counts,
            'skipped' => $this->skipped,
            'stop_reason' => $this->stopReason,
            'user_id' => $this->userId,
            'estimated_calls' => $this->estimatedCalls,
            'remaining_calls' => $this->remainingCalls,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $counts = json_decode((string) ($row['counts'] ?? '{}'), true);
        $skipped = json_decode((string) ($row['skipped'] ?? '{}'), true);

        return new self(
            (string) ($row['id'] ?? ''),
            (int) ($row['started'] ?? 0),
            (int) ($row['finished'] ?? 0),
            (string) ($row['mode'] ?? ''),
            (string) ($row['run_trigger'] ?? ''),
            is_array($counts) ? array_map('intval', $counts) : [],
            is_array($skipped) ? array_map('intval', $skipped) : [],
            (string) ($row['stop_reason'] ?? ''),
            (int) ($row['user_id'] ?? 0),
        );
    }
}
