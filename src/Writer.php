<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Exception\StorageException;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Storage\Repository;

/**
 * The only code that changes an alt value. Every change gets an entry with
 * the exact state before it; when the entry cannot be stored the change is
 * reverted (cases 51, 72, 73). Only the alt field and the plugin's own
 * decorative marker are touched (case 58).
 */
class Writer
{
    public function __construct(private AltField $field, private Repository $repository, private Clock $clock)
    {
    }

    /**
     * @throws StorageException
     */
    public function apply(int $attachmentId, bool $newExists, ?string $newValue, bool $markerAfter, string $action, string $runId, int $suggestionId, int $undoes = 0): Entry
    {
        $previous = $this->field->read($attachmentId);
        $markerBefore = $this->field->isDecorative($attachmentId);
        $target = $newExists ? (string) $newValue : null;

        if ($previous !== $target && !$this->field->write($attachmentId, $target)) {
            $this->field->write($attachmentId, $previous);
            throw new StorageException('The alt text could not be saved. Nothing was changed.');
        }
        if ($markerBefore !== $markerAfter && !$this->field->setDecorative($attachmentId, $markerAfter)) {
            $this->field->write($attachmentId, $previous);
            $this->field->setDecorative($attachmentId, $markerBefore);
            throw new StorageException('The decorative marker could not be saved. Nothing was changed.');
        }
        $row = [
            'attachment_id' => $attachmentId,
            'previous_exists' => $previous !== null,
            'previous_value' => $previous,
            'new_exists' => $target !== null,
            'new_value' => $target,
            'marker_before' => $markerBefore,
            'marker_after' => $markerAfter,
            'action' => $action,
            'user_id' => (int) get_current_user_id(),
            'created' => $this->clock->now(),
            'run_id' => $runId,
            'suggestion_id' => $suggestionId,
            'undoes' => $undoes,
            'undone_by' => 0,
        ];
        try {
            $id = $this->repository->insertEntry($row);
        } catch (StorageException $e) {
            $this->field->write($attachmentId, $previous);
            $this->field->setDecorative($attachmentId, $markerBefore);
            throw new StorageException('The history entry could not be stored, so the change was reverted.', 0, $e);
        }
        $entry = $this->repository->getEntry($id);

        return $entry ?? Entry::fromRow(array_merge($row, ['id' => $id]));
    }
}
