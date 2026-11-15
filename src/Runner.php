<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Adapter\AdapterRegistry;
use IndianOpinion\AltTextFill\Adapter\VisionAdapter;
use IndianOpinion\AltTextFill\Context\ContextBuilder;
use IndianOpinion\AltTextFill\Exception\AdapterException;
use IndianOpinion\AltTextFill\Exception\CapReachedException;
use IndianOpinion\AltTextFill\Exception\ConfigException;
use IndianOpinion\AltTextFill\Exception\DisabledException;
use IndianOpinion\AltTextFill\Exception\LockedException;
use IndianOpinion\AltTextFill\Exception\MalformedAnswerException;
use IndianOpinion\AltTextFill\Image\ImagePreparer;
use IndianOpinion\AltTextFill\Model\AdapterResponse;
use IndianOpinion\AltTextFill\Model\Candidate;
use IndianOpinion\AltTextFill\Model\Context;
use IndianOpinion\AltTextFill\Model\ImageInput;
use IndianOpinion\AltTextFill\Model\RunReport;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Storage\Repository;

/**
 * One run: scan, context, image, call, validate, suggest (PRD 4.4 and 4.8).
 * Every call is preceded by the switch, breaker, cap and spend checks, and
 * failed or rejected calls count against the caps because they were billed.
 */
class Runner
{
    public const LOCK_TTL = 600;

    public const STOP_DONE = 'done';
    public const STOP_RUN_CAP = 'per-run cap';
    public const STOP_TIME = 'time budget';
    public const STOP_DAILY = 'daily cap';
    public const STOP_MONEY = 'money cap';
    public const STOP_SWITCH = 'kill switch';
    public const STOP_ACCOUNT = 'provider account';
    public const STOP_RATE = 'rate limited';
    public const STOP_BREAKER = 'repeated failures';
    public const STOP_SPEND = 'spend meter';
    public const STOP_DRY_ONLY = 'dry run only';
    public const STOP_SETTINGS = 'settings unreadable';

    /** Skip reasons found while preparing that hold until the next full scan. */
    private const HOLD_UNTIL_NEXT_SCAN = [ImagePreparer::SKIP_MISSING, ImagePreparer::SKIP_ANIMATED, ImagePreparer::SKIP_TOO_LARGE, Scanner::SKIP_UNSUPPORTED];

    public function __construct(
        private Settings $settings,
        private State $state,
        private Clock $clock,
        private Scanner $scanner,
        private ContextBuilder $contexts,
        private ImagePreparer $preparer,
        private AdapterRegistry $adapters,
        private PromptBuilder $prompts,
        private Validator $validator,
        private Repository $repository,
        private AltField $field,
        private Logger $logger,
        private Switches $switches,
    ) {
    }

    /**
     * args: limit, dry_run, time_budget, ids, trigger, include_rejected.
     *
     * @param array<string, mixed> $args
     * @throws DisabledException|CapReachedException|ConfigException|LockedException
     */
    public function run(array $args = []): RunReport
    {
        $dry = !empty($args['dry_run']);
        $trigger = isset($args['trigger']) && is_string($args['trigger']) ? $args['trigger'] : 'api';
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : $this->settings->int('per_run_cap');
        $limit = min($limit, $this->settings->int('per_run_cap'));
        $budget = isset($args['time_budget']) ? max(0, (int) $args['time_budget']) : ($trigger === 'cron' || $trigger === 'screen' ? $this->settings->int('time_budget') : 0);
        $ids = isset($args['ids']) && is_array($args['ids']) ? array_values(array_filter(array_map('intval', $args['ids']))) : null;
        $includeRejected = !empty($args['include_rejected']);

        $adapter = $this->adapters->active();
        if (!$dry) {
            $this->assertCanSpend($adapter);
        }

        $brokeStale = false;
        $token = $this->state->acquireLock(self::LOCK_TTL, $brokeStale);
        if ($token === null) {
            throw new LockedException('Another run is in progress on this site.');
        }
        if ($brokeStale) {
            $this->logger->warning('Broke a stale run lock left by an earlier run.');
        }

        $runId = 'r' . gmdate('YmdHis', $this->clock->now()) . '-' . bin2hex(random_bytes(3));
        $started = $this->clock->now();
        $startMicro = $this->clock->micro();
        $userId = (int) get_current_user_id();
        $counts = ['considered' => 0, 'sent' => 0, 'suggestions' => 0, 'rejected' => 0, 'failures' => 0, 'enqueued' => 0, 'skipped' => 0];
        $skipped = [];
        $items = [];
        $stop = self::STOP_DONE;
        $mode = $dry ? 'dry' : 'real';
        $this->repository->saveRun(['id' => $runId, 'started' => $started, 'finished' => 0, 'mode' => $mode, 'run_trigger' => $trigger, 'counts' => $counts, 'skipped' => [], 'stop_reason' => 'running', 'user_id' => $userId]);
        do_action('alt_text_fill_run_started', $runId, $args);

        try {
            $found = $this->scanner->collect($limit, $includeRejected, $ids);
            $counts['considered'] = $found['considered'];
            foreach ($found['skipped'] as $reason => $n) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + $n;
            }
            $useQueue = !$dry && $this->settings->bool('use_gen_queue') && Integrations::genQueuePresent();
            $caps = $adapter !== null ? $adapter->capabilities() : ['mime_types' => Scanner::DEFAULT_MIMES, 'accepts_url' => false, 'accepts_animation' => false, 'max_bytes' => 0];
            $processed = 0;
            foreach ($found['candidates'] as $candidate) {
                if ($dry) {
                    $items[] = $this->dryItem($candidate, $caps);
                    continue;
                }
                $halt = $this->haltReason($startMicro, $budget, $processed);
                if ($halt !== null) {
                    $stop = $halt;
                    break;
                }
                $processed++;
                if ($useQueue) {
                    $key = 'alt-text-fill:' . $candidate->attachmentId . ':' . Fingerprint::of($candidate->attachmentId);
                    $job = apply_filters(Integrations::hook('queue_enqueue'), null, Integrations::JOB_TYPE, ['attachment_id' => $candidate->attachmentId, 'run_id' => $runId], ['idempotency_key' => $key]);
                    if ($job !== null && $job !== false && !is_wp_error($job)) {
                        $counts['enqueued']++;
                        continue;
                    }
                }
                $outcome = $this->processOne($candidate, (string) $adapter?->id(), $runId);
                if (str_starts_with($outcome, 'skip:')) {
                    $reason = substr($outcome, 5);
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                    continue;
                }
                if (str_starts_with($outcome, 'stop:')) {
                    $stop = substr($outcome, 5);
                    $counts['sent']++;
                    $counts['failures']++;
                    break;
                }
                if (str_starts_with($outcome, 'halt:')) {
                    $stop = substr($outcome, 5);
                    break;
                }
                $counts['sent']++;
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                if ($outcome === 'failures' && $this->state->breakerOpen()) {
                    $stop = self::STOP_BREAKER;
                    break;
                }
            }
            if ($stop === self::STOP_DONE && count($found['candidates']) >= $limit && $ids === null) {
                $stop = self::STOP_RUN_CAP;
            }
        } finally {
            $counts['skipped'] = array_sum($skipped);
            $today = $this->state->today();
            $remaining = max(0, $this->settings->int('daily_cap') - $today['calls']);
            $wouldSend = count(array_filter($items, static fn (array $i): bool => (bool) $i['would_send']));
            $report = new RunReport($runId, $started, $this->clock->now(), $mode, $trigger, $counts, $skipped, $stop, $userId, $items, $wouldSend, $remaining);
            $this->repository->saveRun(array_merge($report->toArray(), ['run_trigger' => $trigger]));
            $this->state->update(['last_run' => $runId]);
            $this->state->releaseLock($token);
        }
        do_action('alt_text_fill_run_finished', $report);

        return $report;
    }

    /**
     * Fails closed before any spending run (cases 59, 63, 76, 77, 32, 33, 62).
     *
     * @throws DisabledException|CapReachedException|ConfigException
     */
    public function assertCanSpend(?VisionAdapter $adapter): void
    {
        $this->settings->reload();
        if ($this->settings->isCorrupted()) {
            throw new DisabledException(self::STOP_SETTINGS, 'Settings could not be read. Save the settings screen to restore them; no calls are made until then.');
        }
        $switch = $this->switches->reason();
        if ($switch !== null) {
            throw new DisabledException($switch, 'Paused: ' . $switch . '.');
        }
        if ($this->settings->bool('dry_run_only')) {
            throw new DisabledException(self::STOP_DRY_ONLY, 'Dry-run-only mode is on. Turn it off in the settings before a real run.');
        }
        if ($this->state->breakerOpen()) {
            throw new DisabledException(self::STOP_BREAKER, 'Paused for the rest of the day after repeated failures.');
        }
        if ($this->state->waitingUntil() > 0) {
            throw new CapReachedException(self::STOP_RATE, 'Rate limited by the provider until ' . gmdate('c', $this->state->waitingUntil()) . '.');
        }
        if ($adapter === null) {
            throw new ConfigException('No adapter is chosen. Pick one in the settings.');
        }
        $errors = $adapter->validateSettings($this->settings->adapterSettings($adapter->id()));
        if ($errors !== []) {
            throw new ConfigException(implode(' ', $errors));
        }
        $cap = $this->capReason();
        if ($cap !== null) {
            throw new CapReachedException($cap, 'Today\'s ' . $cap . ' is reached.');
        }
    }

    /** Daily call cap, then money cap (case 62). */
    public function capReason(): ?string
    {
        $today = $this->state->today();
        if ($today['calls'] >= $this->settings->int('daily_cap')) {
            return self::STOP_DAILY;
        }
        $moneyCap = $this->settings->float('money_cap');
        if ($moneyCap > 0 && $this->pricesSet()) {
            $estimate = $today['priced_calls'] > 0
                ? $today['cost'] / $today['priced_calls']
                : $this->settings->int('max_tokens') * $this->settings->float('price_output') / 1000000;
            if ($today['cost'] + $estimate > $moneyCap) {
                return self::STOP_MONEY;
            }
        }

        return null;
    }

    public function pricesSet(): bool
    {
        return $this->settings->float('price_input') > 0 || $this->settings->float('price_output') > 0;
    }

    private function haltReason(float $startMicro, int $budget, int $processed): ?string
    {
        $this->settings->reload();
        if ($this->settings->isCorrupted()) {
            return self::STOP_SETTINGS;
        }
        $switch = $this->switches->reason();
        if ($switch !== null) {
            return $switch === Switches::REASON_PROVIDER ? self::STOP_ACCOUNT : self::STOP_SWITCH;
        }
        if ($this->state->breakerOpen()) {
            return self::STOP_BREAKER;
        }
        if ($budget > 0) {
            $elapsed = $this->clock->micro() - $startMicro;
            if ($elapsed >= $budget || ($processed > 0 && $budget - $elapsed < $this->settings->int('timeout'))) {
                return self::STOP_TIME;
            }
        }

        return null;
    }

    /**
     * Checks done immediately before a call: caps and spend-meter.
     */
    private function preCallReason(): ?string
    {
        $cap = $this->capReason();
        if ($cap !== null) {
            return $cap;
        }
        if (Integrations::spendMeterPresent()) {
            $allow = apply_filters(Integrations::hook('spend_check'), true, Integrations::PURPOSE, ['calls' => 1]);
            if ($allow === false || is_wp_error($allow)) {
                return self::STOP_SPEND;
            }
        }

        return null;
    }

    /**
     * Processes one candidate. Returns "suggestions", "rejected",
     * "failures", "skip:<reason>", "halt:<stop reason>" (nothing was sent)
     * or "stop:<stop reason>" (a failed call that ends the run).
     */
    public function processOne(Candidate $candidate, string $adapterId, string $runId): string
    {
        $adapter = $this->adapters->get($adapterId);
        if ($adapter === null) {
            return 'halt:no adapter';
        }
        $id = $candidate->attachmentId;
        $context = $this->contexts->build($id, $candidate->contextPostId, $candidate->shared);
        $prep = $this->preparer->prepare($candidate, $adapter->capabilities());
        if ($prep['input'] === null) {
            if (in_array($prep['reason'], self::HOLD_UNTIL_NEXT_SCAN, true)) {
                $this->field->updateState($id, ['skip' => $prep['reason'], 'skip_gen' => (int) $this->state->all()['generation']]);
            }

            return 'skip:' . $prep['reason'];
        }
        $halt = $this->preCallReason();
        if ($halt !== null) {
            return 'halt:' . $halt;
        }
        $prompt = $this->prompts->build($context);
        $settings = $this->settings->adapterSettings($adapter->id());
        $this->field->updateState($id, ['last_tried' => $this->clock->now()]);

        try {
            $response = $this->call($adapter, $prep['input'], $context, $prompt, $settings);
        } catch (AdapterException $e) {
            return $this->onAdapterFailure($e, $id);
        } catch (\Throwable $e) {
            $this->logger->error('Adapter threw ' . get_class($e) . ': ' . Redactor::redact($e->getMessage()), ['attachment_id' => $id]);
            if ($this->state->recordFailure()) {
                $this->tripBreaker();
            }

            return 'failures';
        }
        $this->state->recordSuccess();
        $this->recordSpend($adapter, $response);

        return $this->accept($candidate, $context, $response, $adapter->id(), $runId);
    }

    /**
     * One call with one retry on a timeout or 5xx (case 31). Both count.
     *
     * @param array<string, mixed> $settings
     */
    private function call(VisionAdapter $adapter, ImageInput $image, Context $context, \IndianOpinion\AltTextFill\Model\Prompt $prompt, array $settings): AdapterResponse
    {
        $this->state->countCall();
        try {
            return $adapter->describe($image, $context, $prompt, $settings);
        } catch (AdapterException $e) {
            if (!$e->isRetryable() || $this->capReason() !== null) {
                throw $e;
            }
            $this->logger->info('Retrying once after: ' . $e->getMessage());
            $this->state->countCall();

            return $adapter->describe($image, $context, $prompt, $settings);
        }
    }

    private function onAdapterFailure(AdapterException $e, int $attachmentId): string
    {
        if ($e->isAccountError()) {
            $this->switches->pause(Switches::REASON_PROVIDER);
            $this->logger->error('Paused: the provider refused the account (' . $e->getMessage() . ').');

            return 'stop:' . self::STOP_ACCOUNT;
        }
        if ($e->isRateLimit()) {
            $this->state->setNotBefore($this->clock->now() + (int) $e->retryAfter());
            $this->logger->warning('Rate limited; waiting ' . (int) $e->retryAfter() . ' seconds before the next run.');

            return 'stop:' . self::STOP_RATE;
        }
        $this->logger->warning('Call failed for attachment ' . $attachmentId . ': ' . $e->getMessage());
        if ($this->state->recordFailure()) {
            $this->tripBreaker();
        }

        return 'failures';
    }

    private function tripBreaker(): void
    {
        $this->logger->error('Paused for the rest of the site day after ' . State::BREAKER_LIMIT . ' consecutive failed calls.');
        do_action('alt_text_fill_paused', self::STOP_BREAKER);
    }

    private function recordSpend(VisionAdapter $adapter, AdapterResponse $response): void
    {
        $cost = null;
        if ($this->pricesSet()) {
            if ($response->inputTokens === null && $response->outputTokens === null) {
                $today = $this->state->today();
                $cost = $today['priced_calls'] > 0 ? $today['cost'] / $today['priced_calls'] : 0.0;
                $this->logger->warning('The provider reported no token counts; the money estimate uses today\'s average call.');
            } else {
                $cost = ((int) $response->inputTokens * $this->settings->float('price_input') + (int) $response->outputTokens * $this->settings->float('price_output')) / 1000000;
            }
            $this->state->addCost($cost);
        }
        if (Integrations::spendMeterPresent()) {
            do_action(Integrations::hook('spend_record'), Integrations::PURPOSE, [
                'adapter' => $adapter->id(),
                'model' => $response->model,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'estimated_cost' => $cost,
            ]);
        }
    }

    private function accept(Candidate $candidate, Context $context, AdapterResponse $response, string $adapterId, string $runId): string
    {
        $id = $candidate->attachmentId;
        $file = (string) get_attached_file($id);
        $base = [
            'attachment_id' => $id,
            'run_id' => $runId,
            'original_text' => mb_substr($response->rawText, 0, 2000),
            'previous_alt' => $candidate->currentAlt,
            'context_post_id' => $candidate->contextPostId,
            'context_text' => $this->settings->bool('store_context') ? $context->text : '',
            'file_hash' => Fingerprint::of($id),
            'adapter' => $adapterId,
            'model' => mb_substr($response->model, 0, 191),
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'created' => $this->clock->now(),
            'decided' => 0,
            'decided_by' => 0,
            'reject_note' => '',
            'final_text' => '',
        ];
        try {
            $answer = AnswerParser::parse($response->rawText);
            $result = $this->validator->validate($answer, $context, $candidate, $file);
            $confidence = $answer->confidence;
        } catch (MalformedAnswerException $e) {
            $result = ['ok' => false, 'text' => '', 'reason' => Validator::R_MALFORMED, 'flags' => [], 'decorative' => false];
            $confidence = 0.0;
        }
        if ($result['ok']) {
            $sid = $this->repository->insertSuggestion(array_merge($base, [
                'kind' => $result['decorative'] ? Suggestion::KIND_DECORATIVE : Suggestion::KIND_TEXT,
                'suggested_text' => $result['text'],
                'flags' => (string) wp_json_encode($result['flags']),
                'confidence' => $confidence,
                'status' => Suggestion::PENDING,
                'reject_reason' => '',
            ]));
            $suggestion = $this->repository->getSuggestion($sid);
            if ($suggestion !== null) {
                do_action('alt_text_fill_suggestion_created', $suggestion);
            }

            return 'suggestions';
        }
        $this->repository->insertSuggestion(array_merge($base, [
            'kind' => Suggestion::KIND_TEXT,
            'suggested_text' => $result['text'],
            'flags' => (string) wp_json_encode($result['flags']),
            'confidence' => $confidence,
            'status' => Suggestion::INVALID,
            'reject_reason' => $result['reason'],
            'decided' => $this->clock->now(),
        ]));
        $state = $this->field->state($id);
        $rejected = $state['rejected'] + 1;
        $this->field->updateState($id, ['rejected' => $rejected, 'parked' => $rejected >= 2 ? Scanner::SKIP_PARKED : '']);
        do_action('alt_text_fill_answer_rejected', $id, $result['reason']);

        return 'rejected';
    }

    /**
     * @param array{mime_types: list<string>, accepts_url: bool, accepts_animation: bool, max_bytes: int} $caps
     * @return array<string, mixed>
     */
    private function dryItem(Candidate $candidate, array $caps): array
    {
        $context = $this->contexts->build($candidate->attachmentId, $candidate->contextPostId, $candidate->shared);
        $prep = $this->preparer->prepare($candidate, $caps);

        return [
            'attachment_id' => $candidate->attachmentId,
            'would_send' => $prep['input'] !== null,
            'reason' => $prep['reason'],
            'context' => $context->text,
            'context_post_id' => $context->postId,
            'shared' => $candidate->shared,
            'size' => $prep['size'],
            'width' => $prep['width'],
            'height' => $prep['height'],
            'bytes' => $prep['bytes'],
            'mime' => $prep['mime'],
        ];
    }

    /**
     * Runs one queued job (case 65). The caps and the switch still apply.
     *
     * @param array<string, mixed> $payload
     */
    public function handleJob(array $payload): string
    {
        $id = (int) ($payload['attachment_id'] ?? 0);
        $adapter = $this->adapters->active();
        try {
            $this->assertCanSpend($adapter);
        } catch (DisabledException | CapReachedException $e) {
            return 'halt:' . $e->stopReason();
        } catch (ConfigException $e) {
            return 'halt:config';
        }
        $candidate = $this->scanner->evaluate($id, false);
        if (!$candidate instanceof Candidate) {
            return 'skip:' . (is_string($candidate) ? $candidate : 'has alt');
        }

        return $this->processOne($candidate, (string) $adapter?->id(), (string) ($payload['run_id'] ?? 'queue'));
    }

    /**
     * "Test connection": one small built-in image, counted against the daily cap.
     *
     * @return array{ok: bool, message: string, raw: string}
     */
    public function testConnection(): array
    {
        $adapter = $this->adapters->active();
        if ($adapter === null) {
            return ['ok' => false, 'message' => 'No adapter is chosen.', 'raw' => ''];
        }
        if ($this->switches->reason() !== null) {
            return ['ok' => false, 'message' => 'The kill switch is on.', 'raw' => ''];
        }
        if ($this->capReason() !== null) {
            return ['ok' => false, 'message' => 'Today\'s cap is reached.', 'raw' => ''];
        }
        $settings = $this->settings->adapterSettings($adapter->id());
        $errors = $adapter->validateSettings($settings);
        if ($errors !== []) {
            return ['ok' => false, 'message' => implode(' ', $errors), 'raw' => ''];
        }
        $bytes = (string) base64_decode(TestImage::PNG_BASE64, true);
        $image = new ImageInput($bytes, null, 'image/png', TestImage::WIDTH, TestImage::HEIGHT, strlen($bytes), 'test');
        $context = new Context('', $this->contexts->language(0), 0, false, false);
        $this->state->countCall();
        try {
            $response = $adapter->describe($image, $context, $this->prompts->build($context), $settings);
        } catch (AdapterException $e) {
            return ['ok' => false, 'message' => Redactor::redact($e->getMessage()), 'raw' => ''];
        }
        $this->recordSpend($adapter, $response);
        try {
            $answer = AnswerParser::parse($response->rawText);
        } catch (MalformedAnswerException $e) {
            return ['ok' => false, 'message' => 'The provider answered, but not with a JSON object.', 'raw' => mb_substr($response->rawText, 0, 300)];
        }

        return ['ok' => true, 'message' => $answer->alt, 'raw' => mb_substr($response->rawText, 0, 300)];
    }
}
