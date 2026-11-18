<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Exception\ConflictException;
use IndianOpinion\AltTextFill\Exception\NotFoundException;
use IndianOpinion\AltTextFill\Exception\PermissionException;
use IndianOpinion\AltTextFill\Exception\StaleSuggestionException;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Storage\Repository;

/**
 * Review actions (PRD 4.6). Writes go through the Writer. These work with
 * the kill switch on, because they spend nothing (case 63).
 */
class Review
{
    public const REJECT_REASONS = ['wrong', 'vague', 'names the wrong person', 'other', 'try again'];

    public function __construct(
        private Repository $repository,
        private AltField $field,
        private Writer $writer,
        private Validator $validator,
        private Clock $clock,
        private Logger $logger,
    ) {
    }

    public static function canEdit(int $attachmentId): bool
    {
        return current_user_can('edit_post', $attachmentId);
    }

    /**
     * @throws NotFoundException|PermissionException|ConflictException|StaleSuggestionException|\IndianOpinion\AltTextFill\Exception\StorageException|\InvalidArgumentException
     */
    public function approve(int $suggestionId, ?string $edited = null): Entry
    {
        $s = $this->pending($suggestionId);
        // Case 43 applies to every approval, the decorative kind included:
        // an alt an editor typed meanwhile is never overridden.
        $current = $this->field->read($s->attachmentId);
        $weakStill = $s->previousAlt !== '' && $current === $s->previousAlt;
        if ($current !== null && !Text::isBlank($current) && !$weakStill) {
            $this->repository->updateSuggestion($s->id, ['status' => Suggestion::SUPERSEDED, 'decided' => $this->clock->now(), 'decided_by' => (int) get_current_user_id()]);
            throw new ConflictException('Someone filled the alt text since this suggestion was made. Nothing was written.', null, $current);
        }
        if ($s->kind === Suggestion::KIND_DECORATIVE && $edited === null) {
            return $this->markDecorative($s->attachmentId, $s);
        }
        $final = $s->text;
        $action = Entry::APPROVE;
        if ($edited !== null) {
            $final = $this->validator->cleanEdited($edited);
            if ($final !== $s->text) {
                $action = Entry::APPROVE_EDITED;
            }
        }
        if ($final === '') {
            throw new \InvalidArgumentException('The suggestion has no text to write.');
        }
        $entry = $this->writer->apply($s->attachmentId, true, $final, $this->field->isDecorative($s->attachmentId), $action, $s->runId, $s->id);
        $this->decide($s, $action === Entry::APPROVE_EDITED ? Suggestion::EDITED : Suggestion::APPROVED, ['final_text' => $final]);
        $this->field->updateState($s->attachmentId, ['hold' => true]);
        do_action('alt_text_fill_written', $entry);

        return $entry;
    }

    /**
     * @throws NotFoundException|PermissionException|ConflictException
     */
    public function reject(int $suggestionId, string $reason, string $note = ''): void
    {
        $s = $this->pending($suggestionId, false);
        $reason = in_array($reason, self::REJECT_REASONS, true) ? $reason : 'other';
        $note = mb_substr(sanitize_textarea_field($note), 0, 500);
        $this->decide($s, Suggestion::REJECTED, ['reject_reason' => $reason, 'reject_note' => $note]);
        if ($reason === 'try again') {
            $this->field->release($s->attachmentId);
        } else {
            $this->field->updateState($s->attachmentId, ['hold' => true]);
        }
    }

    /**
     * Marks an image decorative (case 46): sets the marker, and an empty alt
     * only when the alt row was missing. Undoable like any write.
     *
     * @throws PermissionException|\IndianOpinion\AltTextFill\Exception\StorageException
     */
    public function markDecorative(int $attachmentId, ?Suggestion $from = null): Entry
    {
        if (!self::canEdit($attachmentId)) {
            throw new PermissionException('You cannot edit this attachment.');
        }
        $current = $this->field->read($attachmentId);
        $entry = $this->writer->apply(
            $attachmentId,
            true,
            $current ?? '',
            true,
            Entry::DECORATIVE,
            $from !== null ? $from->runId : '',
            $from !== null ? $from->id : 0
        );
        if ($from !== null) {
            $this->decide($from, Suggestion::APPROVED, ['final_text' => '']);
        }
        foreach ($this->repository->findSuggestions(['attachment_id' => $attachmentId, 'status' => Suggestion::PENDING], 'created', 'DESC', 0) as $other) {
            $this->decide($other, Suggestion::SUPERSEDED);
        }
        $this->field->updateState($attachmentId, ['hold' => true]);
        do_action('alt_text_fill_written', $entry);

        return $entry;
    }

    /**
     * "Try again": the image becomes a candidate for the next run, even if
     * it was rejected, undone or parked (case 7).
     *
     * @throws PermissionException
     */
    public function tryAgain(int $attachmentId): void
    {
        if (!self::canEdit($attachmentId)) {
            throw new PermissionException('You cannot edit this attachment.');
        }
        foreach ($this->repository->findSuggestions(['attachment_id' => $attachmentId, 'status' => Suggestion::PENDING], 'created', 'DESC', 0) as $s) {
            $this->decide($s, Suggestion::REJECTED, ['reject_reason' => 'try again']);
        }
        $this->field->release($attachmentId);
    }

    /**
     * Marks pending suggestions stale when the file changed (case 69).
     */
    public function checkStale(int $attachmentId): int
    {
        $n = 0;
        $hash = null;
        foreach ($this->repository->findSuggestions(['attachment_id' => $attachmentId, 'status' => Suggestion::PENDING], 'created', 'DESC', 0) as $s) {
            $hash ??= Fingerprint::of($attachmentId);
            if ($s->fileHash !== '' && $s->fileHash !== $hash) {
                $this->repository->updateSuggestion($s->id, ['status' => Suggestion::STALE, 'decided' => $this->clock->now()]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * @throws NotFoundException|PermissionException|ConflictException|StaleSuggestionException
     */
    private function pending(int $suggestionId, bool $checkStale = true): Suggestion
    {
        $s = $this->repository->getSuggestion($suggestionId);
        if ($s === null) {
            throw new NotFoundException('No suggestion ' . $suggestionId . '.');
        }
        if (!self::canEdit($s->attachmentId)) {
            throw new PermissionException('You cannot edit this attachment.');
        }
        if ($s->status === Suggestion::STALE) {
            throw new StaleSuggestionException('The image file changed after this suggestion was made.');
        }
        if (!$s->isPending()) {
            throw new ConflictException('This suggestion is ' . $s->status . ', not pending.');
        }
        if ($checkStale && $s->fileHash !== '' && $s->fileHash !== Fingerprint::of($s->attachmentId)) {
            $this->repository->updateSuggestion($s->id, ['status' => Suggestion::STALE, 'decided' => $this->clock->now()]);
            throw new StaleSuggestionException('The image file changed after this suggestion was made. It cannot be approved.');
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function decide(Suggestion $s, string $status, array $extra = []): void
    {
        try {
            $this->repository->updateSuggestion($s->id, array_merge([
                'status' => $status,
                'decided' => $this->clock->now(),
                'decided_by' => (int) get_current_user_id(),
            ], $extra));
        } catch (\IndianOpinion\AltTextFill\Exception\StorageException $e) {
            $this->logger->error('Could not record the decision on suggestion ' . $s->id . ': ' . $e->getMessage());
            throw $e;
        }
    }
}
