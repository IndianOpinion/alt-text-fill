<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Exception\AdapterException;
use IndianOpinion\AltTextFill\Exception\CapReachedException;
use IndianOpinion\AltTextFill\Exception\ConfigException;
use IndianOpinion\AltTextFill\Exception\DisabledException;
use IndianOpinion\AltTextFill\Exception\LockedException;
use IndianOpinion\AltTextFill\Model\RunReport;
use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\RenderFill;
use IndianOpinion\AltTextFill\Runner;
use IndianOpinion\AltTextFill\Settings;
use IndianOpinion\AltTextFill\State;
use IndianOpinion\AltTextFill\Tests\TestCase;

/**
 * PRD 4.8 and 4.9: runs, dry run, caps, switch, integrations, failures.
 */
final class RunTest extends TestCase
{
    private function images(int $n, int $from = 1): void
    {
        for ($i = $from; $i < $from + $n; $i++) {
            $this->attachment($i, ['width' => 800, 'height' => 600]);
        }
    }

    public function testT56DryRunCallsNoAdapterWritesNothingButTheRunRecordAndReportsEachItem(): void
    {
        $this->post(100, ['content' => '<p>A lighthouse story.</p><img class="wp-image-1" />']);
        $this->attachment(1, ['parent' => 100, 'width' => 800, 'height' => 600]);
        $this->attachment(2, ['width' => 800, 'height' => 600, 'write' => false]);
        $metaBefore = \WP::$meta;

        $report = alt_text_fill_run(['dry_run' => true, 'trigger' => 'cli']);

        self::assertTrue($report->isDryRun());
        self::assertSame([], $this->fake->calls);
        self::assertSame([], $this->repo->suggestions);
        self::assertSame([], $this->repo->entries);
        self::assertCount(1, $this->repo->runs);
        self::assertSame($metaBefore, \WP::$meta);
        self::assertSame(0, $this->c->state->today()['calls']);
        self::assertCount(2, $report->items);
        $first = $report->items[0];
        self::assertTrue($first['would_send']);
        self::assertStringContainsString('A lighthouse story.', $first['context']);
        self::assertSame('full', $first['size']);
        self::assertSame(800, $first['width']);
        self::assertGreaterThan(0, $first['bytes']);
        self::assertFalse($report->items[1]['would_send']);
        self::assertSame('file missing', $report->items[1]['reason']);
        self::assertSame(1, $report->estimatedCalls);
        self::assertSame(200, $report->remainingCalls);
    }

    public function testT57NewInstallRefusesRealRunsUntilDryRunOnlyIsOff(): void
    {
        unset(\WP::$options[Settings::OPTION]);
        $this->rebuild();
        self::assertTrue($this->c->settings->bool('dry_run_only'));
        $this->images(1);

        try {
            alt_text_fill_run(['trigger' => 'cli']);
            self::fail('a new install must refuse real runs');
        } catch (DisabledException $e) {
            self::assertSame(Runner::STOP_DRY_ONLY, $e->stopReason());
        }
        self::assertTrue(alt_text_fill_run(['dry_run' => true])->isDryRun());

        $this->settings(['adapter' => 'fake', 'dry_run_only' => false]);
        self::assertSame(1, alt_text_fill_run()->count('suggestions'));
    }

    public function testT58RenderFillOffByDefault(): void
    {
        $this->attachment(10, ['alt' => 'A stored description', 'write' => false]);
        $post = \WP::$posts[10];

        self::assertSame(['alt' => ''], RenderFill::attributes(['alt' => ''], $post));
        $html = '<figure><img src="a.jpg" class="wp-image-10" /></figure>';
        self::assertSame($html, RenderFill::block($html, ['blockName' => 'core/image', 'attrs' => ['id' => 10]]));
    }

    public function testT58RenderFillFillsEmptyAttributeAndKeepsDeliberateEmptyBlockAlt(): void
    {
        $this->settings(['render_fill' => true]);
        $this->attachment(10, ['alt' => 'A "stored" description', 'write' => false]);
        $post = \WP::$posts[10];

        self::assertSame(['alt' => 'A "stored" description'], RenderFill::attributes(['alt' => ''], $post));
        self::assertSame(['alt' => 'Kept'], RenderFill::attributes(['alt' => 'Kept'], $post));
        $noAlt = '<figure><img src="a.jpg" class="wp-image-10" /></figure>';
        self::assertSame('<figure><img alt="A &quot;stored&quot; description" src="a.jpg" class="wp-image-10" /></figure>', RenderFill::block($noAlt, ['blockName' => 'core/image', 'attrs' => ['id' => 10]]));
        $deliberate = '<figure><img src="a.jpg" alt="" class="wp-image-10" /></figure>';
        self::assertSame($deliberate, RenderFill::block($deliberate, ['blockName' => 'core/image', 'attrs' => ['id' => 10]]));
        self::assertSame($noAlt, RenderFill::block($noAlt, ['blockName' => 'core/gallery', 'attrs' => ['id' => 10]]));
    }

    public function testT59PerRunCapStopsTheRun(): void
    {
        $this->images(5);
        $this->settings(['per_run_cap' => 3]);

        $report = $this->runReal();

        self::assertSame(3, $report->count('sent'));
        self::assertSame(Runner::STOP_RUN_CAP, $report->stopReason);
        $report = $this->runReal();
        self::assertSame(2, $report->count('sent'));
        self::assertSame(Runner::STOP_DONE, $report->stopReason);
    }

    public function testT59TimeBudgetStopsTheRun(): void
    {
        $this->images(5);
        $clock = $this->clock;
        $this->fake->onCall = static function () use ($clock): void {
            $clock->advance(12);
        };

        $report = $this->c->runner->run(['trigger' => 'cron']);

        self::assertSame(Runner::STOP_TIME, $report->stopReason);
        self::assertSame(1, $report->count('sent'), 'with 25 s budget and a 30 s timeout, only the first item starts');

        $this->settings(['timeout' => 5]);
        $report = $this->c->runner->run(['trigger' => 'cli', 'time_budget' => 25]);
        self::assertSame(Runner::STOP_TIME, $report->stopReason);
        self::assertSame(2, $report->count('sent'));
    }

    public function testT60DailyCallCapStopsBeforeTheCallAndResetsAtSiteMidnight(): void
    {
        update_option('timezone_string', 'Asia/Kolkata');
        $this->clock->timezone = 'Asia/Kolkata';
        $this->images(5);
        $this->settings(['daily_cap' => 2]);

        $report = $this->runReal();
        self::assertSame(Runner::STOP_DAILY, $report->stopReason);
        self::assertCount(2, $this->fake->calls);
        try {
            $this->runReal();
            self::fail('refused at the cap');
        } catch (CapReachedException $e) {
            self::assertSame(Runner::STOP_DAILY, $e->stopReason());
        }
        $day = $this->clock->siteDay();
        while ($this->clock->siteDay() === $day) {
            $this->clock->advance(1800);
        }
        self::assertSame(0, $this->c->state->today()['calls']);
        self::assertSame(2, $this->runReal()->count('sent'));
    }

    public function testT60MoneyCapUsesReportedTokensAndOperatorPrices(): void
    {
        $this->images(5);
        $this->settings(['money_cap' => 0.05]);
        self::assertSame(5, $this->runReal()->count('sent'), 'off until prices are entered');
        self::assertSame(0.0, $this->c->state->today()['cost']);

        $this->images(7, 20);
        // 100 input and 20 output tokens per fake call: 0.002 a call; the first estimate is 200 output tokens, 0.01.
        $this->settings(['price_input' => 10.0, 'price_output' => 50.0, 'money_cap' => 0.011]);
        $report = $this->runReal();
        self::assertSame(Runner::STOP_MONEY, $report->stopReason);
        self::assertSame(5, $report->count('sent'));
        self::assertEqualsWithDelta(0.01, $this->c->state->today()['cost'], 0.000001);

        $this->settings(['money_cap' => 0.005]);
        $this->expectException(CapReachedException::class);
        $this->runReal();
    }

    public function testT61KillSwitchBySettingStopsARunMidWayAndReviewWritesStillWork(): void
    {
        $this->images(4);
        $switches = $this->c->switches;
        $calls = 0;
        $this->fake->onCall = static function () use ($switches, &$calls): void {
            if (++$calls === 2) {
                $switches->pause('manual');
            }
        };

        $report = $this->runReal();

        self::assertSame(Runner::STOP_SWITCH, $report->stopReason);
        self::assertCount(2, $this->fake->calls);
        $this->expectExceptionObject(new DisabledException('manual', 'Paused: manual.'));
        try {
            $this->runReal();
        } finally {
            $s = $this->repo->findSuggestions()[0];
            $this->c->review->approve($s->id);
            self::assertSame($s->text, $this->alt($s->attachmentId));
        }
    }

    /**
     * Constants cannot be undefined, so this test runs in its own process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testT61KillSwitchByConstantWins(): void
    {
        $this->images(1);
        $this->c->switches->resume();
        define('ALT_TEXT_FILL_DISABLED', true);

        try {
            $this->runReal();
            self::fail('the constant must refuse the run');
        } catch (DisabledException $e) {
            self::assertSame('constant', $e->stopReason());
        }
        self::assertSame([], $this->fake->calls);
    }

    public function testT62SpendMeterDenyStopsTheRunAndRecordFiresAfterACall(): void
    {
        $this->images(3);
        $checks = 0;
        add_filter('spend_meter_pre_spend', static function ($allow, string $purpose) use (&$checks) {
            self::assertSame('alt_text_fill.describe', $purpose);

            return ++$checks <= 2;
        }, 10, 2);
        $recorded = [];
        add_action('spend_meter_record', static function (string $purpose, array $usage) use (&$recorded): void {
            $recorded[] = $usage;
        }, 10, 2);

        $report = $this->runReal();

        self::assertSame(Runner::STOP_SPEND, $report->stopReason);
        self::assertCount(2, $this->fake->calls);
        self::assertCount(2, $recorded);
        self::assertSame(100, $recorded[0]['input_tokens']);
        self::assertSame('fake-model', $recorded[0]['model']);
    }

    public function testT62OwnCapsStillApplyWhenSpendMeterAllows(): void
    {
        $this->images(3);
        add_filter('spend_meter_pre_spend', static fn (): bool => true);
        $this->settings(['daily_cap' => 1]);

        self::assertSame(Runner::STOP_DAILY, $this->runReal()->stopReason);
        self::assertCount(1, $this->fake->calls);
    }

    public function testT63GenQueueGetsOneJobPerImageWithTheIdempotencyKeyAndCapsStillApply(): void
    {
        $this->images(3);
        $this->settings(['use_gen_queue' => true, 'daily_cap' => 2]);
        $jobs = [];
        add_filter('gen_queue_enqueue', static function ($id, string $type, array $payload, array $args) use (&$jobs) {
            $jobs[] = ['type' => $type, 'payload' => $payload, 'key' => $args['idempotency_key']];

            return count($jobs);
        }, 10, 4);

        $report = $this->runReal();

        self::assertSame(3, $report->count('enqueued'));
        self::assertSame([], $this->fake->calls);
        self::assertSame('alt_text_fill.describe', $jobs[0]['type']);
        self::assertMatchesRegularExpression('/^alt-text-fill:1:f:[0-9a-f]{40}$/', $jobs[0]['key']);

        $outcomes = array_map(fn (array $j): string => $this->c->runner->handleJob($j['payload']), $jobs);
        self::assertSame(['suggestions', 'suggestions', 'halt:daily cap'], $outcomes);
        self::assertCount(2, $this->fake->calls);
    }

    public function testT63WithoutOptInTheOwnRunnerIsUsed(): void
    {
        $this->images(1);
        add_filter('gen_queue_enqueue', static fn () => 1);

        self::assertSame(1, $this->runReal()->count('suggestions'));
    }

    public function testT64SecondRunWhileLockedIsRefusedAndAStaleLockIsBroken(): void
    {
        $this->images(1);
        add_option(State::LOCK_OPTION, ['token' => 'other', 'expires' => $this->clock->now + 300]);

        try {
            $this->runReal();
            self::fail('locked');
        } catch (LockedException $e) {
            self::assertStringContainsString('in progress', $e->getMessage());
        }

        $this->clock->advance(301);
        $report = $this->runReal();
        self::assertSame(1, $report->count('suggestions'));
        self::assertStringContainsString('stale run lock', implode("\n", $this->logger->written));
        self::assertFalse(get_option(State::LOCK_OPTION));
    }

    public function testT64LockIsReleasedWhenTheRunThrows(): void
    {
        $this->images(1);
        add_action('alt_text_fill_suggestion_created', static function (): void {
            throw new \RuntimeException('boom');
        });

        try {
            $this->runReal();
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertFalse(get_option(State::LOCK_OPTION));
    }

    public function testT65ScheduleOffByDefaultOnAddsTheEventAtTheFilteredInterval(): void
    {
        Plugin::syncSchedule();
        self::assertFalse(wp_next_scheduled(Plugin::CRON_HOOK));

        add_filter('alt_text_fill_cron_interval', static fn (): int => 300);
        $this->settings(['schedule' => true]);
        Plugin::syncSchedule();
        self::assertSame(Plugin::SCHEDULE, \WP::$cron[Plugin::CRON_HOOK]['recurrence']);
        self::assertSame(300, Plugin::cronSchedules([])[Plugin::SCHEDULE]['interval']);

        $this->settings(['schedule' => false]);
        Plugin::syncSchedule();
        self::assertFalse(wp_next_scheduled(Plugin::CRON_HOOK));
    }

    public function testT66RunRecordHoldsEveryField(): void
    {
        $this->attachment(1, ['width' => 800, 'height' => 600]);
        $this->attachment(2, ['width' => 800, 'height' => 600]);
        $this->attachment(3, ['width' => 10, 'height' => 10, 'write' => false]);
        $this->attachment(4, ['width' => 800, 'height' => 600]);
        $this->fake->push($this->answer('A crane over a dock'), $this->answer('Sorry, I cannot'), new AdapterException('x'));
        $started = [];
        add_action('alt_text_fill_run_started', static function (string $id) use (&$started): void {
            $started[] = $id;
        });

        $report = $this->runReal();

        $stored = $this->repo->getRun($report->id);
        self::assertInstanceOf(RunReport::class, $stored);
        self::assertSame([$report->id], $started);
        self::assertSame($this->clock->now, $stored->started);
        self::assertGreaterThan(0, $stored->finished);
        self::assertSame('real', $stored->mode);
        self::assertSame('cli', $stored->trigger);
        self::assertSame(4, $stored->count('considered'));
        self::assertSame(3, $stored->count('sent'));
        self::assertSame(1, $stored->count('suggestions'));
        self::assertSame(1, $stored->count('rejected'));
        self::assertSame(1, $stored->count('failures'));
        self::assertSame(['too small' => 1], $stored->skipped);
        self::assertSame(Runner::STOP_DONE, $stored->stopReason);
        self::assertSame(1, $stored->userId);
        self::assertCount(1, \WP::firedArgs('alt_text_fill_run_finished'));
    }

    public function testT69UnreachableModelCountsAFailureAndTheRunContinues(): void
    {
        $this->images(3);
        $this->fake->push(new AdapterException('connect failed', true), new AdapterException('connect failed', true), $this->answer('A pier'), $this->answer('A boat'));

        $report = $this->runReal();

        self::assertSame(1, $report->count('failures'));
        self::assertSame(2, $report->count('suggestions'));
        self::assertSame(4, $this->c->state->today()['calls']);
        self::assertSame($this->clock->now, $this->c->field->state(1)['last_tried']);
        self::assertFalse($this->c->field->state(1)['hold'], 'retried in a later run');
    }

    public function testT72AdapterRuntimeErrorIsCaughtAndLoggedRedacted(): void
    {
        $this->images(2);
        \IndianOpinion\AltTextFill\Redactor::register('sk-secretsecretsecret99');
        $this->fake->push(new \TypeError('bad thing with key sk-secretsecretsecret99 and Bearer abc.def'), $this->answer('A pier'));

        $report = $this->runReal();

        self::assertSame(1, $report->count('failures'));
        self::assertSame(1, $report->count('suggestions'));
        $log = implode("\n", $this->logger->written);
        self::assertStringContainsString('TypeError', $log);
        self::assertStringNotContainsString('sk-secretsecretsecret99', $log);
        self::assertStringNotContainsString('abc.def', $log);
        self::assertStringContainsString('[redacted]', $log);
    }

    public function testT72CronRunNeverThrows(): void
    {
        $this->images(1);
        add_action('alt_text_fill_run_started', static function (): void {
            throw new \LogicException('unexpected');
        });

        Plugin::cronRun();

        self::assertStringContainsString('LogicException', implode("\n", $this->logger->written));
    }

    public function testT73NoKeyRefusesRealRunsAndDryRunsStillWork(): void
    {
        $this->images(1);
        $this->settings(['adapter' => 'openai-compatible', 'adapter_settings' => ['openai-compatible' => ['base_url' => 'https://api.example.com/v1', 'model' => 'm']]]);

        try {
            $this->runReal();
            self::fail('no key');
        } catch (ConfigException $e) {
            self::assertStringContainsString('API key', $e->getMessage());
        }
        self::assertSame([], \WP::$requests);
        self::assertTrue($this->c->runner->run(['dry_run' => true])->items[0]['would_send']);

        $this->settings(['adapter' => '']);
        $this->expectException(ConfigException::class);
        $this->runReal();
    }

    public function testTestConnectionCountsAgainstTheDailyCap(): void
    {
        $this->fake->push($this->answer('A yellow circle on blue'));

        $result = $this->c->runner->testConnection();

        self::assertTrue($result['ok']);
        self::assertSame('A yellow circle on blue', $result['message']);
        self::assertSame(1, $this->c->state->today()['calls']);
        self::assertSame(64, $this->fake->calls[0]['image']->width);
    }
}
