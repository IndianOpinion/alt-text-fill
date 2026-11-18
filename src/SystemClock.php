<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function micro(): float
    {
        return microtime(true);
    }

    public function siteDay(): string
    {
        $date = new \DateTimeImmutable('@' . $this->now());

        return $date->setTimezone(wp_timezone())->format('Y-m-d');
    }
}
