<?php

/**
 * Public PHP functions (PRD 5.1).
 */

declare(strict_types=1);

use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\RunReport;
use IndianOpinion\AltTextFill\Model\ScanReport;
use IndianOpinion\AltTextFill\Model\UndoReport;
use IndianOpinion\AltTextFill\Plugin;

if (!function_exists('alt_text_fill_is_empty')) {
    /** Case 4, and case 5 when weak-alt mode is on. */
    function alt_text_fill_is_empty(int $attachment_id): bool
    {
        return Plugin::container()->emptyAlt->isEmpty($attachment_id);
    }

    /**
     * @param array<string, mixed> $args limit, after_id, include_rejected, mime_types.
     */
    function alt_text_fill_scan(array $args = []): ScanReport
    {
        return Plugin::container()->scanner->scan($args);
    }

    /**
     * @param array<string, mixed> $args limit, dry_run, time_budget, ids, trigger.
     */
    function alt_text_fill_run(array $args = []): RunReport
    {
        return Plugin::container()->runner->run($args);
    }

    function alt_text_fill_approve(int $suggestion_id, ?string $edited = null): Entry
    {
        return Plugin::container()->review->approve($suggestion_id, $edited);
    }

    function alt_text_fill_reject(int $suggestion_id, string $reason, string $note = ''): void
    {
        Plugin::container()->review->reject($suggestion_id, $reason, $note);
    }

    function alt_text_fill_mark_decorative(int $attachment_id): Entry
    {
        return Plugin::container()->review->markDecorative($attachment_id);
    }

    function alt_text_fill_undo(int $entry_id, bool $force = false): UndoReport
    {
        return Plugin::container()->undo->entry($entry_id, $force);
    }

    function alt_text_fill_undo_run(string $run_id): UndoReport
    {
        return Plugin::container()->undo->run($run_id);
    }

    function alt_text_fill_undo_since(int $timestamp): UndoReport
    {
        return Plugin::container()->undo->since($timestamp);
    }

    /**
     * @return array<string, mixed>
     */
    function alt_text_fill_status(): array
    {
        return Plugin::container()->status->get();
    }

    function alt_text_fill_pause(string $reason = 'manual'): void
    {
        Plugin::container()->switches->pause($reason);
    }

    function alt_text_fill_resume(): void
    {
        Plugin::container()->switches->resume();
    }
}
