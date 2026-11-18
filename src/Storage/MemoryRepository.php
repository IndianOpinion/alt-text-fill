<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Storage;

use IndianOpinion\AltTextFill\Exception\StorageException;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\RunReport;
use IndianOpinion\AltTextFill\Model\Suggestion;

/**
 * In-memory storage with the same semantics as the wpdb one. Used by tests,
 * by demos and by the examples. Failure injection lets tests prove the
 * fail-closed paths.
 */
final class MemoryRepository implements Repository
{
    /** @var array<int, array<string, mixed>> */
    public array $suggestions = [];
    /** @var array<int, array<string, mixed>> */
    public array $entries = [];
    /** @var array<string, array<string, mixed>> */
    public array $runs = [];
    public bool $failEntryInsert = false;
    public bool $failSuggestionUpdate = false;
    private int $nextSuggestion = 1;
    private int $nextEntry = 1;

    public function insertSuggestion(array $row): int
    {
        $id = $this->nextSuggestion++;
        $row['id'] = $id;
        $this->suggestions[$id] = $row;

        return $id;
    }

    public function updateSuggestion(int $id, array $changes): void
    {
        if ($this->failSuggestionUpdate) {
            throw new StorageException('Injected suggestion update failure.');
        }
        if (!isset($this->suggestions[$id])) {
            throw new StorageException('No such suggestion: ' . $id);
        }
        $this->suggestions[$id] = array_merge($this->suggestions[$id], $changes);
    }

    public function getSuggestion(int $id): ?Suggestion
    {
        return isset($this->suggestions[$id]) ? Suggestion::fromRow($this->suggestions[$id]) : null;
    }

    public function findSuggestions(array $filter = [], string $orderBy = 'created', string $order = 'DESC', int $limit = 20, int $offset = 0): array
    {
        $rows = array_values(array_filter($this->suggestions, fn (array $r): bool => $this->matchesSuggestion($r, $filter)));
        $key = $orderBy === 'confidence' ? 'confidence' : 'created';
        usort($rows, static function (array $a, array $b) use ($key, $order): int {
            $cmp = ($a[$key] ?? 0) <=> ($b[$key] ?? 0);
            if ($cmp === 0) {
                $cmp = $a['id'] <=> $b['id'];
            }

            return strtoupper($order) === 'ASC' ? $cmp : -$cmp;
        });
        $rows = array_slice($rows, $offset, $limit > 0 ? $limit : null);

        return array_map(static fn (array $r): Suggestion => Suggestion::fromRow($r), $rows);
    }

    public function countSuggestions(array $filter = []): int
    {
        return count(array_filter($this->suggestions, fn (array $r): bool => $this->matchesSuggestion($r, $filter)));
    }

    public function deletePendingForAttachment(int $attachmentId): int
    {
        $n = 0;
        foreach ($this->suggestions as $id => $row) {
            if ((int) $row['attachment_id'] === $attachmentId && $row['status'] === Suggestion::PENDING) {
                unset($this->suggestions[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function insertEntry(array $row): int
    {
        if ($this->failEntryInsert) {
            throw new StorageException('Injected entry insert failure.');
        }
        $id = $this->nextEntry++;
        $row['id'] = $id;
        $this->entries[$id] = $row;

        return $id;
    }

    public function getEntry(int $id): ?Entry
    {
        return isset($this->entries[$id]) ? Entry::fromRow($this->entries[$id]) : null;
    }

    public function findEntries(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $rows = array_values(array_filter($this->entries, static function (array $r) use ($filter): bool {
            if (isset($filter['attachment_id']) && (int) $r['attachment_id'] !== (int) $filter['attachment_id']) {
                return false;
            }
            if (isset($filter['run_id']) && (string) $r['run_id'] !== (string) $filter['run_id']) {
                return false;
            }
            if (isset($filter['since']) && (int) $r['created'] < (int) $filter['since']) {
                return false;
            }
            if (isset($filter['user_id']) && (int) $r['user_id'] !== (int) $filter['user_id']) {
                return false;
            }

            return true;
        }));
        usort($rows, static fn (array $a, array $b): int => [$b['created'], $b['id']] <=> [$a['created'], $a['id']]);

        return array_map(static fn (array $r): Entry => Entry::fromRow($r), array_slice($rows, $offset, $limit > 0 ? $limit : null));
    }

    public function markEntryUndone(int $entryId, int $undoEntryId): void
    {
        if (!isset($this->entries[$entryId])) {
            throw new StorageException('No such entry: ' . $entryId);
        }
        $this->entries[$entryId]['undone_by'] = $undoEntryId;
    }

    public function saveRun(array $row): void
    {
        foreach (['counts', 'skipped'] as $key) {
            if (isset($row[$key]) && !is_string($row[$key])) {
                $row[$key] = (string) json_encode($row[$key]);
            }
        }
        $this->runs[(string) $row['id']] = array_merge($this->runs[(string) $row['id']] ?? [], $row);
    }

    public function getRun(string $id): ?RunReport
    {
        return isset($this->runs[$id]) ? RunReport::fromRow($this->runs[$id]) : null;
    }

    public function recentRuns(int $limit = 20): array
    {
        $rows = array_values($this->runs);
        usort($rows, static fn (array $a, array $b): int => (int) $b['started'] <=> (int) $a['started']);

        return array_map(static fn (array $r): RunReport => RunReport::fromRow($r), array_slice($rows, 0, $limit));
    }

    public function purge(int $pendingBefore, int $decidedBefore, int $runsBefore, int $entriesBefore): array
    {
        $out = ['expired' => 0, 'suggestions' => 0, 'runs' => 0, 'entries' => 0];
        foreach ($this->suggestions as $id => $row) {
            if ($pendingBefore > 0 && $row['status'] === Suggestion::PENDING && (int) $row['created'] < $pendingBefore) {
                $this->suggestions[$id]['status'] = Suggestion::EXPIRED;
                $this->suggestions[$id]['decided'] = $pendingBefore;
                $out['expired']++;
            } elseif ($decidedBefore > 0 && $row['status'] !== Suggestion::PENDING && (int) ($row['decided'] ?: $row['created']) < $decidedBefore) {
                unset($this->suggestions[$id]);
                $out['suggestions']++;
            }
        }
        foreach ($this->runs as $id => $row) {
            if ($runsBefore > 0 && (int) $row['started'] < $runsBefore) {
                unset($this->runs[$id]);
                $out['runs']++;
            }
        }
        foreach ($this->entries as $id => $row) {
            if ($entriesBefore > 0 && (int) $row['created'] < $entriesBefore) {
                unset($this->entries[$id]);
                $out['entries']++;
            }
        }

        return $out;
    }

    public function decisionStats(int $since): array
    {
        $out = [];
        foreach ($this->suggestions as $row) {
            if (!in_array($row['status'], [Suggestion::APPROVED, Suggestion::EDITED, Suggestion::REJECTED], true) || (int) $row['decided'] < $since) {
                continue;
            }
            $key = $row['adapter'] . "\0" . $row['model'] . "\0" . $row['status'];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        $list = [];
        foreach ($out as $key => $n) {
            [$adapter, $model, $status] = explode("\0", $key);
            $list[] = ['adapter' => $adapter, 'model' => $model, 'status' => $status, 'n' => $n];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $f
     */
    private function matchesSuggestion(array $r, array $f): bool
    {
        if (isset($f['status'])) {
            $statuses = (array) $f['status'];
            if (!in_array($r['status'], $statuses, true)) {
                return false;
            }
        }
        foreach (['attachment_id', 'context_post_id'] as $key) {
            if (isset($f[$key]) && (int) $r[$key] !== (int) $f[$key]) {
                return false;
            }
        }
        if (isset($f['run_id']) && (string) $r['run_id'] !== (string) $f['run_id']) {
            return false;
        }
        if (isset($f['flag']) && $f['flag'] !== '') {
            $flags = json_decode((string) ($r['flags'] ?? '[]'), true);
            if (!is_array($flags) || !in_array($f['flag'], $flags, true)) {
                return false;
            }
        }
        if (isset($f['min_confidence']) && (float) $r['confidence'] < (float) $f['min_confidence']) {
            return false;
        }
        if (isset($f['max_confidence']) && (float) $r['confidence'] > (float) $f['max_confidence']) {
            return false;
        }

        return true;
    }
}
