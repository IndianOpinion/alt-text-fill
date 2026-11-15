<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Model\Suggestion;

/**
 * The status block (PRD section 11).
 */
class Status
{
    public function __construct(private Container $c)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $state = $this->c->state->all();
        $today = $this->c->state->today();
        $repo = $this->c->repository;
        $lastRun = $state['last_run'] !== '' ? $repo->getRun((string) $state['last_run']) : null;
        $switch = $this->c->switches->reason();
        $rates = [];
        foreach ($repo->decisionStats($this->c->clock->now() - 30 * DAY_IN_SECONDS) as $row) {
            $key = $row['adapter'] . ' / ' . $row['model'];
            $rates[$key] ??= ['approved' => 0, 'edited' => 0, 'rejected' => 0];
            $rates[$key][$row['status']] = $row['n'];
        }
        foreach ($rates as $key => $r) {
            $total = max(1, array_sum($r));
            $rates[$key]['approved_share'] = round($r['approved'] / $total, 3);
            $rates[$key]['edited_share'] = round($r['edited'] / $total, 3);
            $rates[$key]['rejected_share'] = round($r['rejected'] / $total, 3);
        }

        return [
            'empty_alt' => (int) $state['last_full_scan']['empty'],
            'empty_alt_scanned' => (int) $state['last_full_scan']['date'],
            'pending' => $repo->countSuggestions(['status' => Suggestion::PENDING]),
            'approved' => $repo->countSuggestions(['status' => Suggestion::APPROVED]),
            'edited' => $repo->countSuggestions(['status' => Suggestion::EDITED]),
            'rejected' => $repo->countSuggestions(['status' => Suggestion::REJECTED]),
            'invalid' => $repo->countSuggestions(['status' => Suggestion::INVALID]),
            'parked' => $this->countMeta('parked'),
            'decorative' => $this->countDecorative(),
            'calls_today' => $today['calls'],
            'daily_cap' => $this->c->settings->int('daily_cap'),
            'cost_today' => round($today['cost'], 4),
            'money_cap' => $this->c->settings->float('money_cap'),
            'money_cap_active' => $this->c->settings->float('money_cap') > 0 && $this->c->runner->pricesSet(),
            'currency' => $this->c->settings->string('currency'),
            'switch' => $switch !== null ? 'on' : 'off',
            'pause_reason' => (string) $switch,
            'breaker_open' => $this->c->state->breakerOpen(),
            'dry_run_only' => $this->c->settings->bool('dry_run_only'),
            'adapter' => $this->c->settings->string('adapter'),
            'last_run' => $lastRun?->toArray(),
            'approval_rates' => $rates,
        ];
    }

    private function countMeta(string $kind): int
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            AltField::STATE,
            '%' . $wpdb->esc_like('"' . $kind . '";s:') . '%' . $wpdb->esc_like(Scanner::SKIP_PARKED) . '%'
        ));
    }

    private function countDecorative(): int
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", AltField::DECORATIVE));
    }
}
