<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * The kill switch (case 63): the screen setting, the WP-CLI pause command and
 * the ALT_TEXT_FILL_DISABLED constant. The constant wins. Unreadable
 * settings count as the switch being on (case 77).
 */
class Switches
{
    public const REASON_PROVIDER = 'provider account';
    public const REASON_CONSTANT = 'constant';
    public const REASON_SETTINGS = 'settings unreadable';

    public function __construct(private State $state, private Settings $settings)
    {
    }

    /** Null when calls may be made; otherwise why not. */
    public function reason(): ?string
    {
        if (defined('ALT_TEXT_FILL_DISABLED') && constant('ALT_TEXT_FILL_DISABLED')) {
            return self::REASON_CONSTANT;
        }
        if ($this->settings->isCorrupted()) {
            return self::REASON_SETTINGS;
        }
        $switch = $this->state->switchState();
        if ($switch['on']) {
            return $switch['reason'] !== '' ? $switch['reason'] : 'manual';
        }

        return null;
    }

    public function isOn(): bool
    {
        return $this->reason() !== null;
    }

    public function pause(string $reason = 'manual'): void
    {
        $reason = trim(mb_substr(Text::collapse($reason), 0, 60));
        $this->state->pause($reason === '' ? 'manual' : $reason);
        do_action('alt_text_fill_paused', $reason === '' ? 'manual' : $reason);
    }

    public function resume(): void
    {
        $this->state->resume();
    }
}
