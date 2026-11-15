<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Fires alt_text_fill_log for every notable event, and writes warnings and
 * errors to error_log with the [alt-text-fill] prefix, one identical line per
 * ten minutes at most. Every message passes the redactor first.
 */
class Logger
{
    public const THROTTLE_SECONDS = 600;

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $message = Redactor::redact($message);
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = Redactor::redact($value);
            }
        }
        do_action('alt_text_fill_log', $level, $message, $context);
        if ($level !== 'warning' && $level !== 'error') {
            return;
        }
        $line = '[alt-text-fill] ' . $level . ': ' . $message;
        $key = 'alt_text_fill_log_' . md5($line);
        if (get_transient($key) !== false) {
            return;
        }
        set_transient($key, 1, self::THROTTLE_SECONDS);
        $this->write($line);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    protected function write(string $line): void
    {
        error_log($line);
    }
}
