<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Hook names used to talk to spend-meter, gen-queue and prompt-library when
 * they are installed. The plugin never requires them. A site whose versions
 * use other names maps them with the alt_text_fill_integration_hooks filter.
 */
final class Integrations
{
    public const PURPOSE = 'alt_text_fill.describe';
    public const JOB_TYPE = 'alt_text_fill.describe';

    /**
     * @return array<string, string>
     */
    public static function hooks(): array
    {
        $defaults = [
            // Filter: (bool|\WP_Error $allow, string $purpose, array $estimate) => bool|\WP_Error.
            'spend_check' => 'spend_meter_pre_spend',
            // Action: (string $purpose, array $usage).
            'spend_record' => 'spend_meter_record',
            // Filter: (mixed $job_id_or_null, string $type, array $payload, array $args) => job ID or null.
            'queue_enqueue' => 'gen_queue_enqueue',
            // Action fired by the queue to run one job: (array $payload, string $type).
            'queue_handle' => 'gen_queue_run_' . self::JOB_TYPE,
            // Filter: (?string $text, string $slug, array $vars) => rendered template or null.
            'prompt_render' => 'prompt_library_render',
        ];
        $filtered = apply_filters('alt_text_fill_integration_hooks', $defaults);
        if (!is_array($filtered)) {
            return $defaults;
        }
        foreach ($defaults as $key => $value) {
            if (!isset($filtered[$key]) || !is_string($filtered[$key]) || $filtered[$key] === '') {
                $filtered[$key] = $value;
            }
        }

        /** @var array<string, string> $filtered */
        return $filtered;
    }

    public static function hook(string $key): string
    {
        return self::hooks()[$key] ?? '';
    }

    public static function spendMeterPresent(): bool
    {
        return has_filter(self::hook('spend_check')) !== false;
    }

    public static function genQueuePresent(): bool
    {
        return has_filter(self::hook('queue_enqueue')) !== false;
    }
}
