<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Redactor;
use IndianOpinion\AltTextFill\Tests\TestCase;

/**
 * PRD section 11: logging, throttling, status.
 */
final class ObservabilityTest extends TestCase
{
    public function testWarningsAreWrittenOncePerTenMinutesAndEveryEventFiresTheAction(): void
    {
        $this->logger->warning('Same line');
        $this->logger->warning('Same line');
        $this->logger->info('Only an action');
        self::assertSame(['[alt-text-fill] warning: Same line'], $this->logger->written);
        self::assertCount(3, \WP::firedArgs('alt_text_fill_log'));

        \WP::$now += 601;
        $this->logger->warning('Same line');
        self::assertCount(2, $this->logger->written);
    }

    public function testRedactorRemovesSecretsAndImageBytes(): void
    {
        Redactor::register('my-secret-value');
        $text = Redactor::redact('key my-secret-value, Bearer abc123, api_key=xyz, data:image/png;base64,' . str_repeat('A', 40));

        self::assertStringNotContainsString('my-secret-value', $text);
        self::assertStringNotContainsString('abc123', $text);
        self::assertStringNotContainsString('xyz', $text);
        self::assertStringNotContainsString(str_repeat('A', 40), $text);
        self::assertSame('********WXYZ', Redactor::mask('abcdefWXYZ'));
    }

    public function testStatusReportsCountsCapsSwitchAndApprovalRates(): void
    {
        $this->attachment(1, ['width' => 800, 'height' => 600]);
        $this->attachment(2, ['width' => 800, 'height' => 600]);
        $this->fake->push($this->answer('A canal at night'), $this->answer('A bridge at night'));
        $this->c->runner->run(['trigger' => 'cli']);
        $this->c->scanner->scan(['after_id' => 0]);
        $s = $this->repo->findSuggestions(['status' => Suggestion::PENDING]);
        $this->c->review->approve($s[0]->id);
        $this->c->review->reject($s[1]->id, 'vague');

        $status = alt_text_fill_status();

        self::assertSame(0, $status['pending']);
        self::assertSame(1, $status['approved']);
        self::assertSame(1, $status['rejected']);
        self::assertSame(2, $status['calls_today']);
        self::assertSame(200, $status['daily_cap']);
        self::assertSame('off', $status['switch']);
        self::assertSame('done', $status['last_run']['stop_reason']);
        self::assertSame(0.5, $status['approval_rates']['fake / fake-model']['approved_share']);
        self::assertFalse($status['money_cap_active']);

        alt_text_fill_pause('testing');
        self::assertSame('testing', alt_text_fill_status()['pause_reason']);
        alt_text_fill_resume();
        self::assertSame('off', alt_text_fill_status()['switch']);
    }
}
