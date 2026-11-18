<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Storage;

use IndianOpinion\AltTextFill\Exception\StorageException;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\RunReport;
use IndianOpinion\AltTextFill\Model\Suggestion;

/**
 * The production storage: three tables through $wpdb, every value prepared.
 */
final class WpdbRepository implements Repository
{
    public function __construct(private \wpdb $wpdb)
    {
    }

    private function t(string $suffix): string
    {
        return Schema::table($this->wpdb, $suffix);
    }

    public function insertSuggestion(array $row): int
    {
        $row = array_merge([
            'reject_note' => '', 'context_text' => '', 'previous_alt' => '', 'final_text' => '',
            'original_text' => '', 'suggested_text' => '', 'flags' => '[]',
        ], $row);
        unset($row['id']);
        if ($this->wpdb->insert($this->t('suggestions'), $row) === false) {
            throw new StorageException('Could not store the suggestion.');
        }

        return (int) $this->wpdb->insert_id;
    }

    public function updateSuggestion(int $id, array $changes): void
    {
        unset($changes['id']);
        if ($this->wpdb->update($this->t('suggestions'), $changes, ['id' => $id]) === false) {
            throw new StorageException('Could not update suggestion ' . $id . '.');
        }
    }

    public function getSuggestion(int $id): ?Suggestion
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->t('suggestions'), $id), ARRAY_A);

        return is_array($row) ? Suggestion::fromRow($row) : null;
    }

    public function findSuggestions(array $filter = [], string $orderBy = 'created', string $order = 'DESC', int $limit = 20, int $offset = 0): array
    {
        [$where, $args] = $this->suggestionWhere($filter);
        $column = $orderBy === 'confidence' ? 'confidence' : 'created';
        $dir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $args = array_merge([$this->t('suggestions')], $args);
        $sql = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY ' . $column . ' ' . $dir . ', id ' . $dir;
        if ($limit > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $args[] = $limit;
            $args[] = max(0, $offset);
        }
        $rows = $this->wpdb->get_results((string) $this->wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(static fn (array $r): Suggestion => Suggestion::fromRow($r), is_array($rows) ? $rows : []);
    }

    public function countSuggestions(array $filter = []): int
    {
        [$where, $args] = $this->suggestionWhere($filter);
        $sql = 'SELECT COUNT(*) FROM %i WHERE ' . $where;

        return (int) $this->wpdb->get_var((string) $this->wpdb->prepare($sql, $this->t('suggestions'), ...$args));
    }

    public function deletePendingForAttachment(int $attachmentId): int
    {
        $n = $this->wpdb->delete($this->t('suggestions'), ['attachment_id' => $attachmentId, 'status' => Suggestion::PENDING]);

        return is_int($n) ? $n : 0;
    }

    public function insertEntry(array $row): int
    {
        unset($row['id']);
        foreach (['previous_exists', 'new_exists', 'marker_before', 'marker_after'] as $k) {
            $row[$k] = !empty($row[$k]) ? 1 : 0;
        }
        if ($this->wpdb->insert($this->t('entries'), $row) === false) {
            throw new StorageException('Could not store the history entry.');
        }

        return (int) $this->wpdb->insert_id;
    }

    public function getEntry(int $id): ?Entry
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->t('entries'), $id), ARRAY_A);

        return is_array($row) ? Entry::fromRow($row) : null;
    }

    public function findEntries(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $args = [];
        foreach (['attachment_id' => '%d', 'user_id' => '%d', 'run_id' => '%s'] as $key => $fmt) {
            if (isset($filter[$key])) {
                $where[] = "{$key} = {$fmt}";
                $args[] = $filter[$key];
            }
        }
        if (isset($filter['since'])) {
            $where[] = 'created >= %d';
            $args[] = (int) $filter['since'];
        }
        $args = array_merge([$this->t('entries')], $args);
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where) . ' ORDER BY created DESC, id DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $args[] = $limit;
            $args[] = max(0, $offset);
        }
        $rows = $this->wpdb->get_results((string) $this->wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map(static fn (array $r): Entry => Entry::fromRow($r), is_array($rows) ? $rows : []);
    }

    public function markEntryUndone(int $entryId, int $undoEntryId): void
    {
        if ($this->wpdb->update($this->t('entries'), ['undone_by' => $undoEntryId], ['id' => $entryId]) === false) {
            throw new StorageException('Could not mark entry ' . $entryId . ' as undone.');
        }
    }

    public function saveRun(array $row): void
    {
        $data = [
            'id' => (string) $row['id'],
            'started' => (int) ($row['started'] ?? 0),
            'finished' => (int) ($row['finished'] ?? 0),
            'mode' => (string) ($row['mode'] ?? ''),
            'run_trigger' => (string) ($row['run_trigger'] ?? ''),
            'counts' => is_string($row['counts'] ?? null) ? $row['counts'] : (string) wp_json_encode($row['counts'] ?? []),
            'skipped' => is_string($row['skipped'] ?? null) ? $row['skipped'] : (string) wp_json_encode($row['skipped'] ?? []),
            'stop_reason' => (string) ($row['stop_reason'] ?? ''),
            'user_id' => (int) ($row['user_id'] ?? 0),
        ];
        if ($this->wpdb->replace($this->t('runs'), $data) === false) {
            throw new StorageException('Could not store the run record.');
        }
    }

    public function getRun(string $id): ?RunReport
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM %i WHERE id = %s', $this->t('runs'), $id), ARRAY_A);

        return is_array($row) ? RunReport::fromRow($row) : null;
    }

    public function recentRuns(int $limit = 20): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare('SELECT * FROM %i ORDER BY started DESC LIMIT %d', $this->t('runs'), $limit), ARRAY_A);

        return array_map(static fn (array $r): RunReport => RunReport::fromRow($r), is_array($rows) ? $rows : []);
    }

    public function purge(int $pendingBefore, int $decidedBefore, int $runsBefore, int $entriesBefore): array
    {
        $s = $this->t('suggestions');
        $out = ['expired' => 0, 'suggestions' => 0, 'runs' => 0, 'entries' => 0];
        if ($pendingBefore > 0) {
            $out['expired'] = (int) $this->wpdb->query((string) $this->wpdb->prepare(
                'UPDATE %i SET status = %s, decided = %d WHERE status = %s AND created < %d',
                $s,
                Suggestion::EXPIRED,
                $pendingBefore,
                Suggestion::PENDING,
                $pendingBefore
            ));
        }
        if ($decidedBefore > 0) {
            $out['suggestions'] = (int) $this->wpdb->query((string) $this->wpdb->prepare(
                'DELETE FROM %i WHERE status <> %s AND GREATEST(decided, created) < %d',
                $s,
                Suggestion::PENDING,
                $decidedBefore
            ));
        }
        if ($runsBefore > 0) {
            $out['runs'] = (int) $this->wpdb->query((string) $this->wpdb->prepare('DELETE FROM %i WHERE started < %d', $this->t('runs'), $runsBefore));
        }
        if ($entriesBefore > 0) {
            $out['entries'] = (int) $this->wpdb->query((string) $this->wpdb->prepare('DELETE FROM %i WHERE created < %d', $this->t('entries'), $entriesBefore));
        }

        return $out;
    }

    public function decisionStats(int $since): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT adapter, model, status, COUNT(*) AS n FROM %i WHERE status IN (%s, %s, %s) AND decided >= %d GROUP BY adapter, model, status',
            $this->t('suggestions'),
            Suggestion::APPROVED,
            Suggestion::EDITED,
            Suggestion::REJECTED,
            $since
        ), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = ['adapter' => (string) $r['adapter'], 'model' => (string) $r['model'], 'status' => (string) $r['status'], 'n' => (int) $r['n']];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0: literal-string, 1: list<mixed>}
     */
    private function suggestionWhere(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (isset($f['status'])) {
            $statuses = array_values(array_map('strval', (array) $f['status']));
            if ($statuses !== []) {
                $where[] = 'status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')';
                array_push($args, ...$statuses);
            }
        }
        foreach (['attachment_id' => '%d', 'context_post_id' => '%d', 'run_id' => '%s'] as $key => $fmt) {
            if (isset($f[$key])) {
                $where[] = "{$key} = {$fmt}";
                $args[] = $f[$key];
            }
        }
        if (isset($f['flag']) && $f['flag'] !== '') {
            $where[] = 'flags LIKE %s';
            $args[] = '%' . $this->wpdb->esc_like('"' . (string) $f['flag'] . '"') . '%';
        }
        if (isset($f['min_confidence'])) {
            $where[] = 'confidence >= %f';
            $args[] = (float) $f['min_confidence'];
        }
        if (isset($f['max_confidence'])) {
            $where[] = 'confidence <= %f';
            $args[] = (float) $f['max_confidence'];
        }

        return [implode(' AND ', $where), $args];
    }
}
