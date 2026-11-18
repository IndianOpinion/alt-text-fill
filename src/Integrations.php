<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * The public hooks of spend-meter, gen-queue and prompt-library, as their
 * released 0.1.0 contracts define them. The plugin never requires any of
 * them: each hook returns its own default (null) when the companion is
 * absent, and the plugin then uses its own caps, runner and prompt.
 */
final class Integrations
{
    /** spend-meter purpose for every describe call (case 64). */
    public const PURPOSE = 'alt_text_fill.describe';
    /** This plugin's slug, sent as the spend-meter caller. */
    public const CALLER = 'alt-text-fill';
    /** gen-queue job type (case 65). */
    public const JOB_TYPE = 'alt_text_fill.describe';
    /** prompt-library consumer id. */
    public const CONSUMER = 'alt-text-fill';

    /** spend-meter contract version this plugin speaks (`SPEND_METER_API`). */
    public const SPEND_METER_CONTRACT = 1;

    public const SPEND_CHECK = 'spend_meter_pre_spend';
    public const SPEND_RECORD = 'spend_meter_record';
    public const SPEND_RELEASE = 'spend_meter_release';
    public const QUEUE_ENQUEUE = 'gen_queue_enqueue_job';
    public const QUEUE_REGISTER = 'gen_queue_register_types';
    public const PROMPT_RENDER = 'promptlib_render';

    /** Rough input tokens for one image at 1,024 px, for the spend-meter estimate. */
    public const IMAGE_TOKEN_ALLOWANCE = 1000;

    /**
     * Asks spend-meter before a call. Returns null when no meter answered
     * (the plugin's own caps apply alone), else the decision.
     *
     * Fails closed: a meter that speaks another contract version, throws,
     * or returns something that is not a decision array is a deny, because
     * a broken meter must not mean unmetered spend.
     *
     * @param array<string, mixed> $intent
     * @return array{allowed: bool, reason: string, reservation_id: ?string}|null
     */
    public static function preSpend(array $intent): ?array
    {
        if (defined('SPEND_METER_API') && (int) constant('SPEND_METER_API') !== self::SPEND_METER_CONTRACT) {
            return ['allowed' => false, 'reason' => 'unknown spend-meter contract ' . (int) constant('SPEND_METER_API'), 'reservation_id' => null];
        }
        try {
            $decision = apply_filters(self::SPEND_CHECK, null, $intent);
        } catch (\Throwable $e) {
            return ['allowed' => false, 'reason' => 'spend-meter threw ' . get_class($e), 'reservation_id' => null];
        }
        if ($decision === null) {
            return null;
        }
        if (!is_array($decision) || !array_key_exists('allowed', $decision)) {
            return ['allowed' => false, 'reason' => 'malformed spend-meter decision', 'reservation_id' => null];
        }
        $allowed = $decision['allowed'] === true;

        return [
            'allowed' => $allowed,
            'reason' => is_string($decision['reason'] ?? null) ? $decision['reason'] : ($allowed ? 'ok' : 'denied'),
            'reservation_id' => $allowed && is_string($decision['reservation_id'] ?? null) && $decision['reservation_id'] !== '' ? $decision['reservation_id'] : null,
        ];
    }

    /**
     * Records one attempt with spend-meter (its `spend_meter_record` action).
     *
     * @param array<string, mixed> $call
     */
    public static function recordSpend(array $call): void
    {
        do_action(self::SPEND_RECORD, $call);
    }

    /** Gives a reservation back when the call was not made. */
    public static function releaseSpend(string $reservationId): void
    {
        do_action(self::SPEND_RELEASE, $reservationId);
    }

    /**
     * Soft enqueue through gen-queue's `gen_queue_enqueue_job` filter.
     * Returns the job id, or null when gen-queue is absent or refused (the
     * caller then runs the item itself).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $args
     */
    public static function enqueue(array $payload, array $args): ?int
    {
        $job = apply_filters(self::QUEUE_ENQUEUE, null, self::JOB_TYPE, $payload, $args);

        return is_int($job) && $job > 0 ? $job : null;
    }

    /** Registers the job type when gen-queue fires `gen_queue_register_types`. */
    public static function registerJobType(): void
    {
        if (!function_exists('gen_queue_register_type')) {
            return;
        }
        call_user_func('gen_queue_register_type', self::JOB_TYPE, [Plugin::class, 'queueJob'], [
            'group' => self::CALLER,
            'max_attempts' => 5,
        ]);
    }

    /**
     * Renders a prompt-library template through its `promptlib_render`
     * filter. Returns the rendered system and user parts, or null when
     * prompt-library is absent or the render failed (the reason is in
     * $error).
     *
     * @param array<string, mixed> $values
     * @return array{system: string, user: string}|null
     */
    public static function renderPrompt(string $slug, array $values, ?string &$error = null): ?array
    {
        $error = null;
        try {
            $rendered = apply_filters(self::PROMPT_RENDER, null, $slug, $values, 'live', ['consumer' => self::CONSUMER]);
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();

            return null;
        }
        if ($rendered === null) {
            return null;
        }
        if (is_wp_error($rendered)) {
            $error = $rendered->get_error_message();

            return null;
        }
        if (!is_array($rendered)) {
            $error = 'prompt-library returned an unexpected value';

            return null;
        }

        return [
            'system' => is_string($rendered['system'] ?? null) ? $rendered['system'] : '',
            'user' => is_string($rendered['user'] ?? null) ? $rendered['user'] : '',
        ];
    }
}
