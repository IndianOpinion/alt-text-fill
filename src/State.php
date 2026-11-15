<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Runtime state: the scan cursor, today's counters, the circuit breaker, the
 * rate-limit wait, the kill switch and the run lock.
 *
 * The state lives in one option with autoload off. The kill switch has its
 * own small autoloaded option because admin notices read it on every admin
 * page. The lock is a separate option taken with add_option(), which is an
 * atomic insert, so two runs can never both hold it.
 */
class State
{
    public const OPTION = 'alt_text_fill_state';
    public const SWITCH_OPTION = 'alt_text_fill_switch';
    public const LOCK_OPTION = 'alt_text_fill_lock';
    public const BREAKER_LIMIT = 5;

    public function __construct(private Clock $clock)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];

        return array_merge([
            'cursor' => 0,
            'generation' => 0,
            'scan_empty' => 0,
            'last_full_scan' => ['date' => 0, 'empty' => 0],
            'day' => '',
            'calls' => 0,
            'cost' => 0.0,
            'priced_calls' => 0,
            'failures' => 0,
            'breaker_day' => '',
            'not_before' => 0,
            'last_run' => '',
        ], $raw);
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(array $changes): void
    {
        update_option(self::OPTION, array_merge($this->all(), $changes), false);
    }

    /**
     * Today's counters, reset when the site day has changed.
     *
     * @return array{calls: int, cost: float, priced_calls: int, failures: int}
     */
    public function today(): array
    {
        $state = $this->all();
        $day = $this->clock->siteDay();
        if ($state['day'] !== $day) {
            $state = array_merge($state, ['day' => $day, 'calls' => 0, 'cost' => 0.0, 'priced_calls' => 0, 'failures' => 0]);
            $this->update($state);
        }

        return [
            'calls' => (int) $state['calls'],
            'cost' => (float) $state['cost'],
            'priced_calls' => (int) $state['priced_calls'],
            'failures' => (int) $state['failures'],
        ];
    }

    /** Counts one model call before it is made, so failed and rejected calls count. */
    public function countCall(): void
    {
        $today = $this->today();
        $this->update(['calls' => $today['calls'] + 1]);
    }

    public function addCost(float $cost): void
    {
        $today = $this->today();
        $this->update(['cost' => $today['cost'] + $cost, 'priced_calls' => $today['priced_calls'] + 1]);
    }

    /** Returns true when this failure trips the circuit breaker. */
    public function recordFailure(): bool
    {
        $today = $this->today();
        $failures = $today['failures'] + 1;
        $changes = ['failures' => $failures];
        $tripped = $failures >= self::BREAKER_LIMIT;
        if ($tripped) {
            $changes['breaker_day'] = $this->clock->siteDay();
        }
        $this->update($changes);

        return $tripped;
    }

    public function recordSuccess(): void
    {
        $this->today();
        $this->update(['failures' => 0]);
    }

    public function breakerOpen(): bool
    {
        return $this->all()['breaker_day'] === $this->clock->siteDay();
    }

    public function setNotBefore(int $timestamp): void
    {
        $this->update(['not_before' => $timestamp]);
    }

    public function waitingUntil(): int
    {
        $until = (int) $this->all()['not_before'];

        return $until > $this->clock->now() ? $until : 0;
    }

    // Kill switch.

    /**
     * @return array{on: bool, reason: string, since: int}
     */
    public function switchState(): array
    {
        $raw = get_option(self::SWITCH_OPTION, []);
        $raw = is_array($raw) ? $raw : [];

        return [
            'on' => (bool) ($raw['on'] ?? false),
            'reason' => (string) ($raw['reason'] ?? ''),
            'since' => (int) ($raw['since'] ?? 0),
        ];
    }

    public function pause(string $reason): void
    {
        update_option(self::SWITCH_OPTION, ['on' => true, 'reason' => $reason, 'since' => $this->clock->now()], true);
    }

    public function resume(): void
    {
        update_option(self::SWITCH_OPTION, ['on' => false, 'reason' => '', 'since' => $this->clock->now()], true);
        $this->update(['breaker_day' => '', 'failures' => 0]);
    }

    // Lock.

    /**
     * Takes the run lock. Returns the token, or null when another run holds
     * a lock that has not expired. A stale lock is broken and reported
     * through $brokeStale.
     */
    public function acquireLock(int $ttl, bool &$brokeStale = false): ?string
    {
        $brokeStale = false;
        $token = bin2hex(random_bytes(8));
        $value = ['token' => $token, 'expires' => $this->clock->now() + $ttl];
        if ($this->tryAdd($value)) {
            return $token;
        }
        $held = get_option(self::LOCK_OPTION);
        $expires = is_array($held) ? (int) ($held['expires'] ?? 0) : 0;
        if ($expires > $this->clock->now()) {
            return null;
        }
        delete_option(self::LOCK_OPTION);
        $brokeStale = true;

        return $this->tryAdd($value) ? $token : null;
    }

    /**
     * @phpstan-impure
     * @param array{token: string, expires: int} $value
     */
    private function tryAdd(array $value): bool
    {
        return add_option(self::LOCK_OPTION, $value, '', false);
    }

    public function releaseLock(string $token): void
    {
        $held = get_option(self::LOCK_OPTION);
        if (is_array($held) && ($held['token'] ?? '') === $token) {
            delete_option(self::LOCK_OPTION);
        }
    }

    /** @return list<string> */
    public static function options(): array
    {
        return [self::OPTION, self::SWITCH_OPTION, self::LOCK_OPTION];
    }
}
