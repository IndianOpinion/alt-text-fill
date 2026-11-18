<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Storage;

use IndianOpinion\AltTextFill\Exception\StorageException;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\RunReport;
use IndianOpinion\AltTextFill\Model\Suggestion;

/**
 * Storage for suggestions, entries and runs. The plugin uses the wpdb
 * implementation; tests use the in-memory one, and the integration suite
 * runs the wpdb one against a real MySQL server.
 *
 * Suggestion filters: status (string or list), attachment_id, run_id,
 * flag, min_confidence, max_confidence, context_post_id.
 * Entry filters: attachment_id, run_id, since (created >= ts), user_id.
 */
interface Repository
{
    /**
     * @param array<string, mixed> $row
     * @throws StorageException
     */
    public function insertSuggestion(array $row): int;

    /**
     * @param array<string, mixed> $changes
     * @throws StorageException
     */
    public function updateSuggestion(int $id, array $changes): void;

    public function getSuggestion(int $id): ?Suggestion;

    /**
     * @param array<string, mixed> $filter
     * @return list<Suggestion>
     */
    public function findSuggestions(array $filter = [], string $orderBy = 'created', string $order = 'DESC', int $limit = 20, int $offset = 0): array;

    /**
     * @param array<string, mixed> $filter
     */
    public function countSuggestions(array $filter = []): int;

    /** Deletes pending suggestions of an attachment; returns how many. */
    public function deletePendingForAttachment(int $attachmentId): int;

    /**
     * @param array<string, mixed> $row
     * @throws StorageException
     */
    public function insertEntry(array $row): int;

    public function getEntry(int $id): ?Entry;

    /**
     * @param array<string, mixed> $filter
     * @return list<Entry> Newest first.
     */
    public function findEntries(array $filter = [], int $limit = 100, int $offset = 0): array;

    /** @throws StorageException */
    public function markEntryUndone(int $entryId, int $undoEntryId): void;

    /**
     * @param array<string, mixed> $row
     * @throws StorageException
     */
    public function saveRun(array $row): void;

    public function getRun(string $id): ?RunReport;

    /**
     * @return list<RunReport> Newest first.
     */
    public function recentRuns(int $limit = 20): array;

    /**
     * Expires pending suggestions created before $pendingBefore, deletes
     * decided suggestions decided before $decidedBefore, runs started before
     * $runsBefore and entries created before $entriesBefore. Zero skips a step.
     *
     * @return array<string, int>
     */
    public function purge(int $pendingBefore, int $decidedBefore, int $runsBefore, int $entriesBefore): array;

    /**
     * Counts of decided suggestions by adapter, model and status since a time.
     *
     * @return list<array{adapter: string, model: string, status: string, n: int}>
     */
    public function decisionStats(int $since): array;
}
