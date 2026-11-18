<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Cli;

use IndianOpinion\AltTextFill\Exception\AltTextFillException;
use IndianOpinion\AltTextFill\Exception\CapReachedException;
use IndianOpinion\AltTextFill\Exception\ConfigException;
use IndianOpinion\AltTextFill\Exception\DisabledException;
use IndianOpinion\AltTextFill\Exception\LockedException;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Model\UndoReport;
use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Runner;

/**
 * Finds images with no alt text, suggests descriptions, and manages review and undo.
 *
 * Exit codes: 0 success, 1 error, 2 refused by a cap or the kill switch,
 * 3 partial (some items refused). Commands that write need --user=<login>
 * of someone who can edit the attachments.
 */
class Command
{
    public const OK = 0;
    public const ERROR = 1;
    public const REFUSED = 2;
    public const PARTIAL = 3;

    /** Stop reasons that mean a cap or the switch refused further work. */
    private const REFUSING_STOPS = [
        Runner::STOP_DAILY, Runner::STOP_MONEY, Runner::STOP_SWITCH, Runner::STOP_SPEND,
        Runner::STOP_RATE, Runner::STOP_ACCOUNT, Runner::STOP_BREAKER, Runner::STOP_SETTINGS,
    ];

    public int $lastExit = 0;

    /**
     * Scans one page of the media library from the stored cursor.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Page size. Default 500.
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function scan(array $args, array $assoc): void
    {
        $scanArgs = [];
        if (isset($assoc['limit'])) {
            $scanArgs['limit'] = (int) $assoc['limit'];
        }
        $report = Plugin::container()->scanner->scan($scanArgs);
        $rows = [['measure' => 'considered', 'value' => $report->considered], ['measure' => 'empty alt', 'value' => $report->emptyAlt], ['measure' => 'candidates', 'value' => count($report->candidates)]];
        foreach ($report->skipped as $reason => $n) {
            $rows[] = ['measure' => 'skipped: ' . $reason, 'value' => $n];
        }
        $rows[] = ['measure' => 'next cursor', 'value' => $report->cursor];
        $rows[] = ['measure' => 'wrapped', 'value' => $report->wrapped ? 'yes' : 'no'];
        $this->output($rows, ['measure', 'value'], $assoc);
        $this->finish(self::OK);
    }

    /**
     * Runs one pass: real, or dry with --dry-run.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : At most this many images (never above the per-run cap).
     *
     * [--dry-run]
     * : Report what would be sent; call no model and write nothing.
     *
     * [--ids=<list>]
     * : Comma-separated attachment IDs instead of the scan cursor.
     *
     * [--time=<seconds>]
     * : Time budget. None by default under WP-CLI.
     *
     * [--include-rejected]
     * : Include images that were rejected, undone or parked.
     *
     * [--format=<format>]
     * : table, json or csv for the dry-run items.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $runArgs = ['trigger' => 'cli', 'dry_run' => !empty($assoc['dry-run']), 'include_rejected' => !empty($assoc['include-rejected'])];
        if (isset($assoc['limit'])) {
            $runArgs['limit'] = (int) $assoc['limit'];
        }
        if (isset($assoc['time'])) {
            $runArgs['time_budget'] = (int) $assoc['time'];
        }
        if (isset($assoc['ids'])) {
            $runArgs['ids'] = array_values(array_filter(array_map('intval', explode(',', (string) $assoc['ids']))));
        }
        try {
            $report = Plugin::container()->runner->run($runArgs);
        } catch (DisabledException | CapReachedException | LockedException $e) {
            \WP_CLI::warning('Refused: ' . $e->getMessage());
            $this->finish(self::REFUSED);

            return;
        } catch (ConfigException $e) {
            \WP_CLI::warning($e->getMessage());
            $this->finish(self::ERROR);

            return;
        }
        if ($report->isDryRun()) {
            $items = array_map(static fn (array $i): array => [
                'attachment' => $i['attachment_id'],
                'send' => $i['would_send'] ? 'yes' : 'no',
                'reason' => $i['reason'],
                'size' => $i['size'],
                'pixels' => $i['width'] . 'x' . $i['height'],
                'bytes' => $i['bytes'],
                'context' => mb_substr((string) $i['context'], 0, 80),
            ], $report->items);
            $this->output($items, ['attachment', 'send', 'reason', 'size', 'pixels', 'bytes', 'context'], $assoc);
            \WP_CLI::log(sprintf('Would send %d images; %d calls left today.', $report->estimatedCalls, $report->remainingCalls));
        }
        \WP_CLI::log(sprintf(
            'Run %s (%s): considered %d, sent %d, suggestions %d, rejected answers %d, failures %d, enqueued %d, skipped %d. Stop: %s.',
            $report->id,
            $report->mode,
            $report->count('considered'),
            $report->count('sent'),
            $report->count('suggestions'),
            $report->count('rejected'),
            $report->count('failures'),
            $report->count('enqueued'),
            $report->count('skipped'),
            $report->stopReason
        ));
        $this->finish(in_array($report->stopReason, self::REFUSING_STOPS, true) ? self::REFUSED : self::OK);
    }

    /**
     * Lists, approves or rejects suggestions. There is no option to approve everything.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, approve or reject.
     *
     * [<id>...]
     * : Suggestion IDs for approve and reject.
     *
     * [--status=<status>]
     * : For list. Default pending.
     *
     * [--flag=<flag>]
     * : For list.
     *
     * [--text=<text>]
     * : For approve: the edited text (one ID only).
     *
     * [--reason=<reason>]
     * : For reject: wrong, vague, names the wrong person, other.
     *
     * [--note=<note>]
     * : For reject.
     *
     * [--format=<format>]
     * : table, json or csv.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function review(array $args, array $assoc): void
    {
        $action = (string) array_shift($args);
        $c = Plugin::container();
        if ($action === 'list') {
            $filter = ['status' => (string) ($assoc['status'] ?? Suggestion::PENDING)];
            if (!empty($assoc['flag'])) {
                $filter['flag'] = (string) $assoc['flag'];
            }
            $rows = array_map(static fn (Suggestion $s): array => [
                'id' => $s->id, 'attachment' => $s->attachmentId, 'status' => $s->status, 'kind' => $s->kind,
                'text' => $s->text, 'flags' => implode('; ', $s->flags), 'confidence' => $s->confidence,
                'post' => $s->contextPostId, 'run' => $s->runId, 'reason' => $s->rejectReason,
            ], $c->repository->findSuggestions($filter, 'created', 'DESC', 200));
            $this->output($rows, ['id', 'attachment', 'status', 'kind', 'text', 'flags', 'confidence', 'post', 'run', 'reason'], $assoc);
            $this->finish(self::OK);

            return;
        }
        if (!in_array($action, ['approve', 'reject'], true)) {
            \WP_CLI::warning('Use: review list | review approve <id>... | review reject <id>... --reason=<r>');
            $this->finish(self::ERROR);

            return;
        }
        if (!$this->requireUser()) {
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $args)));
        if ($ids === []) {
            \WP_CLI::warning('Give at least one suggestion ID.');
            $this->finish(self::ERROR);

            return;
        }
        if (isset($assoc['text']) && count($ids) !== 1) {
            \WP_CLI::warning('--text applies to one suggestion at a time.');
            $this->finish(self::ERROR);

            return;
        }
        if ($action === 'reject' && empty($assoc['reason'])) {
            \WP_CLI::warning('Reject needs --reason=<wrong|vague|names the wrong person|other>.');
            $this->finish(self::ERROR);

            return;
        }
        $ok = $failed = 0;
        foreach ($ids as $id) {
            try {
                if ($action === 'approve') {
                    $c->review->approve($id, isset($assoc['text']) ? (string) $assoc['text'] : null);
                } else {
                    $c->review->reject($id, (string) $assoc['reason'], (string) ($assoc['note'] ?? ''));
                }
                \WP_CLI::log(sprintf('%d: %s.', $id, $action === 'approve' ? 'approved' : 'rejected'));
                $ok++;
            } catch (AltTextFillException | \InvalidArgumentException $e) {
                \WP_CLI::warning(sprintf('%d: %s', $id, $e->getMessage()));
                $failed++;
            }
        }
        $this->finish($failed === 0 ? self::OK : ($ok > 0 ? self::PARTIAL : self::ERROR));
    }

    /**
     * Marks attachments decorative.
     *
     * ## OPTIONS
     *
     * <attachment-id>...
     * : Attachment IDs.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function decorative(array $args, array $assoc): void
    {
        if (!$this->requireUser()) {
            return;
        }
        $ok = $failed = 0;
        foreach (array_filter(array_map('intval', $args)) as $id) {
            try {
                $entry = Plugin::container()->review->markDecorative($id);
                \WP_CLI::log(sprintf('%d: decorative (entry %d).', $id, $entry->id));
                $ok++;
            } catch (AltTextFillException $e) {
                \WP_CLI::warning(sprintf('%d: %s', $id, $e->getMessage()));
                $failed++;
            }
        }
        $this->finish($failed === 0 && $ok > 0 ? self::OK : ($ok > 0 ? self::PARTIAL : self::ERROR));
    }

    /**
     * Undoes one entry, a run, or every plugin write since a time.
     *
     * ## OPTIONS
     *
     * [<entry-id>]
     * : The entry to undo.
     *
     * [--force]
     * : Undo one entry even if the value changed since.
     *
     * [--run=<run-id>]
     * : Undo every entry of a run.
     *
     * [--since=<datetime>]
     * : Undo every plugin write after this time.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function undo(array $args, array $assoc): void
    {
        if (!$this->requireUser()) {
            return;
        }
        $undo = Plugin::container()->undo;
        try {
            if (!empty($assoc['run'])) {
                \WP_CLI::confirm('Undo every write of run ' . $assoc['run'] . '?', $assoc);
                $report = $undo->run((string) $assoc['run']);
            } elseif (!empty($assoc['since'])) {
                $ts = strtotime((string) $assoc['since']);
                if ($ts === false) {
                    \WP_CLI::warning('That time could not be read.');
                    $this->finish(self::ERROR);

                    return;
                }
                \WP_CLI::confirm('Undo every plugin write since ' . gmdate('c', $ts) . '?', $assoc);
                $report = $undo->since($ts);
            } elseif (isset($args[0])) {
                \WP_CLI::confirm('Undo entry ' . (int) $args[0] . '?', $assoc);
                $report = $undo->entry((int) $args[0], !empty($assoc['force']));
            } else {
                \WP_CLI::warning('Give an entry ID, --run or --since.');
                $this->finish(self::ERROR);

                return;
            }
        } catch (AltTextFillException $e) {
            \WP_CLI::warning($e->getMessage());
            $this->finish(self::ERROR);

            return;
        }
        $this->finish($this->undoReport($report));
    }

    /**
     * Shows counts, today's use against the caps, the switch and the last run.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $rows = [];
        foreach (Plugin::container()->status->get() as $key => $value) {
            $rows[] = ['key' => $key, 'value' => is_array($value) ? (string) wp_json_encode($value) : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)];
        }
        $this->output($rows, ['key', 'value'], $assoc);
        $this->finish(self::OK);
    }

    /**
     * Turns the kill switch on.
     *
     * ## OPTIONS
     *
     * [--reason=<reason>]
     * : Shown in the status.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function pause(array $args, array $assoc): void
    {
        Plugin::container()->switches->pause((string) ($assoc['reason'] ?? 'manual'));
        \WP_CLI::log('Paused. No model calls will be made.');
        $this->finish(self::OK);
    }

    /**
     * Turns the kill switch off and clears the circuit breaker.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function resume(array $args, array $assoc): void
    {
        Plugin::container()->switches->resume();
        \WP_CLI::log('Resumed.');
        $this->finish(self::OK);
    }

    /**
     * Lists history entries.
     *
     * ## OPTIONS
     *
     * [--attachment=<id>]
     * : One attachment.
     *
     * [--run=<id>]
     * : One run.
     *
     * [--format=<format>]
     * : table, json or csv.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function history(array $args, array $assoc): void
    {
        $filter = [];
        if (isset($assoc['attachment'])) {
            $filter['attachment_id'] = (int) $assoc['attachment'];
        }
        if (isset($assoc['run'])) {
            $filter['run_id'] = (string) $assoc['run'];
        }
        $rows = [];
        foreach (Plugin::container()->repository->findEntries($filter, 200) as $e) {
            $rows[] = [
                'id' => $e->id, 'attachment' => $e->attachmentId, 'action' => $e->action,
                'before' => $e->previousExists ? (string) $e->previousValue : '(absent)',
                'after' => $e->newExists ? (string) $e->newValue : '(absent)',
                'user' => $e->userId, 'time' => gmdate('Y-m-d H:i:s', $e->created), 'run' => $e->runId,
                'undone_by' => $e->undoneBy,
            ];
        }
        $this->output($rows, ['id', 'attachment', 'action', 'before', 'after', 'user', 'time', 'run', 'undone_by'], $assoc);
        $this->finish(self::OK);
    }

    private function undoReport(UndoReport $report): int
    {
        foreach ($report->details as $d) {
            if ($d['result'] === 'refused') {
                \WP_CLI::warning(sprintf('Entry %d refused: %s', $d['entry'], $d['message']));
            }
        }
        \WP_CLI::log(sprintf('Restored %d, refused %d, already undone %d.', $report->restored, $report->refused, $report->alreadyUndone));
        if ($report->refused === 0) {
            return self::OK;
        }

        return $report->restored + $report->alreadyUndone > 0 ? self::PARTIAL : self::ERROR;
    }

    private function requireUser(): bool
    {
        if (get_current_user_id() > 0) {
            return true;
        }
        \WP_CLI::warning('This command writes alt text. Run it with --user=<login> of someone who can edit the attachments.');
        $this->finish(self::ERROR);

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $fields
     * @param array<string, mixed>       $assoc
     */
    private function output(array $rows, array $fields, array $assoc): void
    {
        $format = in_array($assoc['format'] ?? 'table', ['table', 'json', 'csv'], true) ? (string) ($assoc['format'] ?? 'table') : 'table';
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    private function finish(int $code): void
    {
        $this->lastExit = $code;
        if ($code !== self::OK) {
            \WP_CLI::halt($code);
        }
    }
}
