<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Runtime state: the scan cursor, today's counters, the circuit breaker, the
 * rate-limit wait, the kill switch and the run lock.
 *
 * The state lives in one option with autoload off. The kill switch has its
 * own small autoloaded option because admin notices read it on every admin
 * page. The lock is a separate option row taken and broken atomically in
 * SQL (see acquireLock()), so two runs can never both hold it.
 */
class State
{
    public const OPTION = 'alt_text_fill_state';
    public const SWITCH_OPTION = 'alt_text_fill_switch';
    public const LOCK_OPTION = 'alt_text_fill_lock';
    public const BREAKER_LIMIT = 5;

    /**
     * @param \wpdb|null $db A real wpdb (one with an options table name) makes
     *                       the run lock atomic in SQL. Without one (the test
     *                       stub layer) the options API is used.
     */
    public function __construct(private Clock $clock, private ?\wpdb $db = null)
    {
    }

    public static function lockDatabase(mixed $wpdb): ?\wpdb
    {
        return $wpdb instanceof \wpdb && $wpdb->options !== '' ? $wpdb : null;
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
     *
     * With a database the lock is one row in the options table, taken with
     * INSERT IGNORE and broken with a compare-and-swap UPDATE on the exact
     * stale value, so two processes can never both hold it, even when both
     * see the same stale lock. add_option() is not used: it upserts, so two
     * racing callers could both be told they won.
     */
    public function acquireLock(int $ttl, bool &$brokeStale = false): ?string
    {
        $brokeStale = false;
        $token = bin2hex(random_bytes(8));
        $value = ['token' => $token, 'expires' => $this->clock->now() + $ttl];
        if ($this->db !== null) {
            return $this->acquireWithDb($value, $brokeStale);
        }
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
     * Pushes the expiry of a lock this process holds, so a long run is not
     * taken for a stale one. Returns false when the lock is no longer ours.
     */
    public function refreshLock(string $token, int $ttl): bool
    {
        $value = ['token' => $token, 'expires' => $this->clock->now() + $ttl];
        if ($this->db !== null) {
            $held = $this->readLockRow();
            if ($held === null || $this->lockToken($held) !== $token) {
                return false;
            }
            $new = (string) wp_json_encode($value);

            // MySQL reports zero changed rows for an identical value, so a
            // refresh within the same second is already done.
            return $held === $new || $this->swapLockRow($held, $new);
        }
        $held = get_option(self::LOCK_OPTION);
        if (!is_array($held) || ($held['token'] ?? '') !== $token) {
            return false;
        }
        update_option(self::LOCK_OPTION, $value, false);

        return true;
    }

    /**
     * @param array{token: string, expires: int} $value
     */
    private function acquireWithDb(array $value, bool &$brokeStale): ?string
    {
        $db = $this->db;
        if ($db === null) {
            return null;
        }
        $serialized = (string) wp_json_encode($value);
        $inserted = $db->query((string) $db->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $db->options,
            self::LOCK_OPTION,
            $serialized
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');
        if ($inserted === 1) {
            return $value['token'];
        }
        $held = $this->readLockRow();
        if ($held === null) {
            return null;
        }
        $expires = (int) ($this->decodeLock($held)['expires'] ?? 0);
        if ($expires > $this->clock->now()) {
            return null;
        }
        if (!$this->swapLockRow($held, $serialized)) {
            return null;
        }
        $brokeStale = true;

        return $value['token'];
    }

    private function readLockRow(): ?string
    {
        $db = $this->db;
        if ($db === null) {
            return null;
        }
        $held = $db->get_var($db->prepare('SELECT option_value FROM %i WHERE option_name = %s', $db->options, self::LOCK_OPTION));

        return is_string($held) ? $held : null;
    }

    /** Compare-and-swap on the exact stored value: true only for the one caller that changed it. */
    private function swapLockRow(string $old, string $new): bool
    {
        $db = $this->db;
        if ($db === null) {
            return false;
        }
        $changed = $db->query((string) $db->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
            $db->options,
            $new,
            self::LOCK_OPTION,
            $old
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return $changed === 1;
    }

    private function lockToken(string $serialized): string
    {
        return (string) ($this->decodeLock($serialized)['token'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLock(string $stored): array
    {
        $data = json_decode($stored, true);

        return is_array($data) ? $data : [];
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
        $db = $this->db;
        if ($db !== null) {
            $held = $this->readLockRow();
            if ($held !== null && $this->lockToken($held) === $token) {
                $db->query((string) $db->prepare('DELETE FROM %i WHERE option_name = %s AND option_value = %s', $db->options, self::LOCK_OPTION, $held));
                wp_cache_delete(self::LOCK_OPTION, 'options');
            }

            return;
        }
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
