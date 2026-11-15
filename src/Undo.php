<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Exception\NotFoundException;
use IndianOpinion\AltTextFill\Exception\PermissionException;
use IndianOpinion\AltTextFill\Exception\StorageException;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\UndoReport;
use IndianOpinion\AltTextFill\Storage\Repository;

/**
 * Undo of one entry, a run, or everything since a time (PRD 4.7). Undo
 * restores the exact previous state: absent stays absent, and a stored
 * string comes back byte for byte.
 */
class Undo
{
    public function __construct(private Repository $repository, private AltField $field, private Writer $writer)
    {
    }

    /**
     * @throws NotFoundException|PermissionException
     */
    public function entry(int $entryId, bool $force = false): UndoReport
    {
        $entry = $this->repository->getEntry($entryId);
        if ($entry === null) {
            throw new NotFoundException('No history entry ' . $entryId . '.');
        }
        if (!current_user_can('edit_post', $entry->attachmentId)) {
            throw new PermissionException('You cannot edit this attachment.');
        }
        $detail = $this->one($entry, $force);

        return new UndoReport(
            $detail['result'] === 'restored' ? 1 : 0,
            $detail['result'] === 'refused' ? 1 : 0,
            $detail['result'] === 'already undone' ? 1 : 0,
            [$detail]
        );
    }

    public function run(string $runId): UndoReport
    {
        return $this->many($this->repository->findEntries(['run_id' => $runId], 0));
    }

    public function since(int $timestamp): UndoReport
    {
        return $this->many($this->repository->findEntries(['since' => $timestamp], 0));
    }

    /**
     * Newest first. Undo entries themselves are left alone, so "undo since"
     * never re-applies a write that a person already took back.
     *
     * @param list<Entry> $entries
     */
    private function many(array $entries): UndoReport
    {
        $restored = $refused = $already = 0;
        $details = [];
        foreach ($entries as $entry) {
            if ($entry->action === Entry::UNDO) {
                continue;
            }
            $fresh = $this->repository->getEntry($entry->id) ?? $entry;
            if (!current_user_can('edit_post', $fresh->attachmentId)) {
                $detail = ['entry' => $fresh->id, 'result' => 'refused', 'message' => 'no permission'];
            } else {
                $detail = $this->one($fresh, false);
            }
            $details[] = $detail;
            match ($detail['result']) {
                'restored' => $restored++,
                'already undone' => $already++,
                default => $refused++,
            };
        }

        return new UndoReport($restored, $refused, $already, $details);
    }

    /**
     * @return array{entry: int, result: string, message: string}
     */
    private function one(Entry $entry, bool $force): array
    {
        if ($entry->isUndone()) {
            return ['entry' => $entry->id, 'result' => 'already undone', 'message' => 'Undone by entry ' . $entry->undoneBy . '.'];
        }
        $current = $this->field->read($entry->attachmentId);
        $marker = $this->field->isDecorative($entry->attachmentId);
        $changed = ($current !== null) !== $entry->newExists
            || ($current !== null && $current !== $entry->newValue)
            || $marker !== $entry->markerAfter;
        if ($changed && !$force) {
            return [
                'entry' => $entry->id,
                'result' => 'refused',
                'message' => sprintf(
                    'changed since: the plugin wrote %s, the value now is %s%s',
                    self::show($entry->newExists ? $entry->newValue : null),
                    self::show($current),
                    $marker !== $entry->markerAfter ? ' (decorative marker changed too)' : ''
                ),
            ];
        }
        try {
            $undo = $this->writer->apply(
                $entry->attachmentId,
                $entry->previousExists,
                $entry->previousValue,
                $entry->markerBefore,
                Entry::UNDO,
                $entry->runId,
                $entry->suggestionId,
                $entry->id
            );
        } catch (StorageException $e) {
            return ['entry' => $entry->id, 'result' => 'refused', 'message' => $e->getMessage()];
        }
        $this->repository->markEntryUndone($entry->id, $undo->id);
        $this->field->updateState($entry->attachmentId, ['hold' => true]);
        $original = $this->repository->getEntry($entry->id) ?? $entry;
        do_action('alt_text_fill_undone', $undo, $original);

        return ['entry' => $entry->id, 'result' => 'restored', 'message' => 'Restored ' . self::show($entry->previousExists ? $entry->previousValue : null) . '.'];
    }

    private static function show(?string $value): string
    {
        return $value === null ? '(absent)' : '"' . $value . '"';
    }
}
