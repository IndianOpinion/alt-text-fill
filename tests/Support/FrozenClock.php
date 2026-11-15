<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Support;

use IndianOpinion\AltTextFill\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public int $now = 1763164800, public string $timezone = 'UTC', public float $fraction = 0.0)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function micro(): float
    {
        return $this->now + $this->fraction;
    }

    public function siteDay(): string
    {
        return (new \DateTimeImmutable('@' . $this->now))->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d');
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
        \WP::$now = $this->now;
    }
}
