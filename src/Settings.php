<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Reads, validates and stores the settings option (PRD section 7).
 *
 * A missing option is a new install: defaults apply and dry-run-only is on.
 * An option that exists but is not an array is corrupted: defaults apply and
 * the kill switch is treated as on until an administrator saves the screen.
 */
final class Settings
{
    public const OPTION = 'alt_text_fill_settings';
    public const MAX_ALT_CEILING = 250;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;
    private bool $corrupted = false;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'dry_run_only' => true,
            'adapter' => '',
            'adapter_settings' => [],
            'per_run_cap' => 20,
            'daily_cap' => 200,
            'price_input' => 0.0,
            'price_output' => 0.0,
            'money_cap' => 0.0,
            'currency' => '',
            'schedule' => false,
            'time_budget' => 25,
            'target_side' => 1024,
            'byte_limit' => 4 * 1024 * 1024,
            'send_as' => 'base64',
            'context_length' => 1500,
            'shared_threshold' => 3,
            'placeholder_ids' => [],
            'include_nonpublic' => false,
            'include_unattached' => true,
            'min_width' => 32,
            'min_height' => 32,
            'exclude_ids' => [],
            'exclude_mimes' => [],
            'date_from' => '',
            'date_to' => '',
            'weak_alt' => false,
            'min_length' => 5,
            'max_length' => 125,
            'low_confidence' => 0.5,
            'decorative_confidence' => 0.8,
            'language' => '',
            'render_fill' => false,
            'use_gen_queue' => false,
            'timeout' => 30,
            'max_tokens' => 200,
            'temperature' => 0.2,
            'url_lookup' => true,
            'url_lookup_limit' => 20,
            'remote_hosts' => [],
            'store_context' => false,
            'prompt_slug' => '',
            'scan_page' => 500,
        ];
    }

    public function reload(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $raw = get_option(self::OPTION, null);
        $this->corrupted = false;
        if ($raw === null || $raw === false) {
            $values = self::defaults();
        } elseif (!is_array($raw)) {
            $this->corrupted = true;
            $values = self::defaults();
        } else {
            $values = self::sanitize($raw);
        }
        $values = $this->applyConstants($values);
        $this->cache = $values;

        return $values;
    }

    public function isCorrupted(): bool
    {
        $this->all();

        return $this->corrupted;
    }

    public function get(string $key): mixed
    {
        $all = $this->all();

        return $all[$key] ?? (self::defaults()[$key] ?? null);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_map('intval', $value)) : [];
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * Settings for one adapter, with the API key resolved from the constant or
     * environment variable when one is set (they win over the stored value).
     *
     * @return array<string, mixed>
     */
    public function adapterSettings(string $adapterId): array
    {
        $all = $this->get('adapter_settings');
        $values = is_array($all) && isset($all[$adapterId]) && is_array($all[$adapterId]) ? $all[$adapterId] : [];
        $key = self::apiKeyOverride();
        if ($key !== null) {
            $values['api_key'] = $key;
        }
        $values['timeout'] = $this->int('timeout');
        $values['max_tokens'] = $this->int('max_tokens');
        $values['temperature'] = $this->float('temperature');

        return $values;
    }

    public static function apiKeyOverride(): ?string
    {
        if (defined('ALT_TEXT_FILL_API_KEY')) {
            $value = constant('ALT_TEXT_FILL_API_KEY');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        $env = getenv('ALT_TEXT_FILL_API_KEY');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    /**
     * Merge, validate and store. Secrets supplied by a constant or the
     * environment are never written to the option.
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input): bool
    {
        $current = get_option(self::OPTION, null);
        $base = is_array($current) ? $current : [];
        $values = self::sanitize(array_merge($base, $input));
        if (self::apiKeyOverride() !== null && is_array($values['adapter_settings'])) {
            foreach ($values['adapter_settings'] as $id => $fields) {
                if (is_array($fields)) {
                    unset($fields['api_key']);
                    $values['adapter_settings'][$id] = $fields;
                }
            }
        }
        $ok = update_option(self::OPTION, $values, false);
        $this->cache = null;

        return $ok || get_option(self::OPTION) === $values;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function sanitize(array $raw): array
    {
        $d = self::defaults();
        $out = [];
        foreach ($d as $key => $default) {
            $value = array_key_exists($key, $raw) ? $raw[$key] : $default;
            $out[$key] = match (true) {
                is_bool($default) => (bool) $value,
                is_int($default) => is_numeric($value) ? (int) $value : $default,
                is_float($default) => is_numeric($value) ? (float) $value : $default,
                is_array($default) => is_array($value) ? $value : $default,
                default => is_scalar($value) ? trim((string) $value) : $default,
            };
        }
        $out['per_run_cap'] = max(1, min(1000, $out['per_run_cap']));
        $out['daily_cap'] = max(0, $out['daily_cap']);
        $out['price_input'] = max(0.0, $out['price_input']);
        $out['price_output'] = max(0.0, $out['price_output']);
        $out['money_cap'] = max(0.0, $out['money_cap']);
        $out['time_budget'] = max(0, $out['time_budget']);
        $out['target_side'] = max(128, min(4096, $out['target_side']));
        $out['byte_limit'] = max(65536, $out['byte_limit']);
        $out['send_as'] = in_array($out['send_as'], ['base64', 'url'], true) ? $out['send_as'] : 'base64';
        $out['context_length'] = max(0, min(10000, $out['context_length']));
        $out['shared_threshold'] = max(1, $out['shared_threshold']);
        $out['min_width'] = max(0, $out['min_width']);
        $out['min_height'] = max(0, $out['min_height']);
        $out['min_length'] = max(1, $out['min_length']);
        $out['max_length'] = max($out['min_length'], min(self::MAX_ALT_CEILING, $out['max_length']));
        $out['low_confidence'] = max(0.0, min(1.0, $out['low_confidence']));
        $out['decorative_confidence'] = max(0.0, min(1.0, $out['decorative_confidence']));
        $out['timeout'] = max(1, min(300, $out['timeout']));
        $out['max_tokens'] = max(16, min(4096, $out['max_tokens']));
        $out['temperature'] = max(0.0, min(2.0, $out['temperature']));
        $out['url_lookup_limit'] = max(1, min(100, $out['url_lookup_limit']));
        $out['scan_page'] = max(10, min(5000, $out['scan_page']));
        $out['adapter'] = preg_match('/^[a-z0-9-]{0,40}$/', (string) $out['adapter']) === 1 ? $out['adapter'] : '';
        foreach (['placeholder_ids', 'exclude_ids'] as $key) {
            $out[$key] = array_values(array_unique(array_filter(array_map('intval', (array) $out[$key]), static fn (int $v): bool => $v > 0)));
        }
        foreach (['exclude_mimes', 'remote_hosts'] as $key) {
            $out[$key] = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => strtolower(trim((string) $v)),
                (array) $out[$key]
            ), static fn (string $v): bool => $v !== '' && preg_match('/^[a-z0-9.\/+-]+$/', $v) === 1)));
        }
        foreach (['date_from', 'date_to'] as $key) {
            $out[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $out[$key]) === 1 ? $out[$key] : '';
        }
        $out['language'] = preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', (string) $out['language']) === 1 ? $out['language'] : '';
        $out['prompt_slug'] = preg_match('/^[a-z0-9_.-]{0,100}$/', (string) $out['prompt_slug']) === 1 ? $out['prompt_slug'] : '';
        $out['currency'] = mb_substr(preg_replace('/[^\p{L}\p{Sc} ]/u', '', (string) $out['currency']) ?? '', 0, 8);
        $adapterSettings = [];
        foreach ((array) $out['adapter_settings'] as $id => $fields) {
            if (is_string($id) && preg_match('/^[a-z0-9-]{1,40}$/', $id) === 1 && is_array($fields)) {
                $clean = [];
                foreach ($fields as $k => $v) {
                    if (is_string($k) && is_scalar($v)) {
                        $clean[$k] = is_bool($v) ? $v : (string) $v;
                    }
                }
                $adapterSettings[$id] = $clean;
            }
        }
        $out['adapter_settings'] = $adapterSettings;

        return $out;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function applyConstants(array $values): array
    {
        if (defined('ALT_TEXT_FILL_DAILY_CAP') && is_numeric(constant('ALT_TEXT_FILL_DAILY_CAP'))) {
            $values['daily_cap'] = max(0, (int) constant('ALT_TEXT_FILL_DAILY_CAP'));
        }
        if (defined('ALT_TEXT_FILL_PER_RUN_CAP') && is_numeric(constant('ALT_TEXT_FILL_PER_RUN_CAP'))) {
            $values['per_run_cap'] = max(1, (int) constant('ALT_TEXT_FILL_PER_RUN_CAP'));
        }

        return $values;
    }
}
