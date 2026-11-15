<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Wall time and the site's calendar day. Tests replace it with a frozen clock.
 */
interface Clock
{
    /** Unix seconds. */
    public function now(): int;

    /** Seconds as a float, for latency and time budgets. */
    public function micro(): float;

    /** The site's current day as Y-m-d, in the site time zone. */
    public function siteDay(): string;
}
