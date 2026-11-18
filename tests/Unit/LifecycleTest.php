<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Admin\Screens;
use IndianOpinion\AltTextFill\Admin\SettingsScreen;
use IndianOpinion\AltTextFill\Exception\DisabledException;
use IndianOpinion\AltTextFill\Exception\StaleSuggestionException;
use IndianOpinion\AltTextFill\Exception\StorageException;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Runner;
use IndianOpinion\AltTextFill\Settings;
use IndianOpinion\AltTextFill\State;
use IndianOpinion\AltTextFill\Storage\Schema;
use IndianOpinion\AltTextFill\Tests\TestCase;
use IndianOpinion\AltTextFill\Uninstaller;

/**
 * Replaced files, deletion, fail-closed writes, settings, secrets, uninstall, schema.
 */
final class LifecycleTest extends TestCase
{
    private function suggest(int $id): Suggestion
    {
        $this->attachment($id, ['width' => 800, 'height' => 600]);
        $this->fake->push($this->answer('A boat at the quay'));
        $this->runReal(['ids' => [$id]]);

        return $this->repo->findSuggestions(['attachment_id' => $id])[0];
    }

    public function testT67ReplacedFileMakesTheSuggestionStaleAndUnapprovable(): void
    {
        $s = $this->suggest(10);
        file_put_contents($this->uploads . '/photo-10.jpeg', $this->image(800, 600, 'png'));

        try {
            $this->c->review->approve($s->id);
            self::fail('stale');
        } catch (StaleSuggestionException $e) {
            self::assertStringContainsString('changed', $e->getMessage());
        }
        self::assertNull($this->alt(10));
        self::assertSame(Suggestion::STALE, $this->repo->getSuggestion($s->id)?->status);
        self::assertInstanceOf(\IndianOpinion\AltTextFill\Model\Candidate::class, $this->c->scanner->evaluate(10));
        $this->expectException(StaleSuggestionException::class);
        $this->c->review->approve($s->id);
    }

    public function testT67AttachmentUpdateHookMarksStale(): void
    {
        $s = $this->suggest(10);
        file_put_contents($this->uploads . '/photo-10.jpeg', $this->image(700, 600));

        Plugin::onAttachmentUpdated(10);

        self::assertSame(Suggestion::STALE, $this->repo->getSuggestion($s->id)?->status);
    }

    public function testT68DeletingAnAttachmentRemovesPendingSuggestionsAndKeepsEntries(): void
    {
        $approved = $this->suggest(10);
        $entry = $this->c->review->approve($approved->id);
        $this->repo->insertSuggestion(['attachment_id' => 10, 'status' => Suggestion::PENDING, 'suggested_text' => 'x', 'created' => 1, 'flags' => '[]']);
        $this->repo->insertSuggestion(['attachment_id' => 11, 'status' => Suggestion::PENDING, 'suggested_text' => 'y', 'created' => 1, 'flags' => '[]']);

        Plugin::onDeleteAttachment(10);

        self::assertSame(0, $this->repo->countSuggestions(['attachment_id' => 10, 'status' => Suggestion::PENDING]));
        self::assertSame(1, $this->repo->countSuggestions(['attachment_id' => 11]));
        self::assertNotNull($this->repo->getEntry($entry->id));
        self::assertSame(Suggestion::APPROVED, $this->repo->getSuggestion($approved->id)?->status);
    }

    public function testT70FailedAltWriteCreatesNoEntryAndKeepsTheSuggestionPending(): void
    {
        $s = $this->suggest(10);
        \WP::$failMetaWrite = true;

        try {
            $this->c->review->approve($s->id);
            self::fail('write failure must surface');
        } catch (StorageException $e) {
            self::assertStringContainsString('could not be saved', $e->getMessage());
        }
        self::assertSame([], $this->repo->entries);
        self::assertSame(Suggestion::PENDING, $this->repo->getSuggestion($s->id)?->status);
        \WP::$failMetaWrite = false;
        self::assertNull($this->alt(10));

        $msgs = Screens::handleReview(['op' => 'approve', 'id' => $s->id, '_wpnonce' => wp_create_nonce(Screens::NONCE_REVIEW)], false);
        self::assertStringContainsString('Approved', $msgs[0]);
    }

    public function testT71FailedEntryWriteRevertsTheAltWrite(): void
    {
        $this->attachment(10, ['width' => 800, 'height' => 600, 'alt' => " \t"]);
        $this->fake->push($this->answer('A boat at the quay'));
        $this->runReal(['ids' => [10]]);
        $s = $this->repo->findSuggestions(['attachment_id' => 10])[0];
        $this->repo->failEntryInsert = true;

        try {
            $this->c->review->approve($s->id);
            self::fail('entry failure must surface');
        } catch (StorageException $e) {
            self::assertStringContainsString('reverted', $e->getMessage());
        }
        self::assertSame(" \t", $this->alt(10));
        self::assertSame(Suggestion::PENDING, $this->repo->getSuggestion($s->id)?->status);

        $this->repo->failEntryInsert = true;
        $this->attachment(11, ['write' => false]);
        try {
            $this->c->review->markDecorative(11);
            self::fail('entry failure must surface');
        } catch (StorageException $e) {
            self::assertFalse($this->c->field->isDecorative(11));
            self::assertNull($this->alt(11));
        }
    }

    public function testT74UnreadableSettingsTreatTheSwitchAsOnAndShowANotice(): void
    {
        $this->attachment(10, ['width' => 800, 'height' => 600]);
        \WP::$options[Settings::OPTION] = 'a:1:{broken';
        $this->rebuild();

        self::assertTrue($this->c->settings->isCorrupted());
        self::assertSame('settings unreadable', $this->c->switches->reason());
        try {
            $this->runReal();
            self::fail('refused');
        } catch (DisabledException $e) {
            self::assertSame(Runner::STOP_SETTINGS, $e->stopReason());
        }
        self::assertSame([], $this->fake->calls);
        ob_start();
        Screens::notices();
        self::assertStringContainsString('could not read its settings', (string) ob_get_clean());

        Screens::handleSettings(['op' => 'save', '_wpnonce' => wp_create_nonce(Screens::NONCE_SETTINGS), 'settings' => ['adapter' => 'fake']], false);
        self::assertFalse($this->c->settings->isCorrupted());
        self::assertNull($this->c->switches->reason());
    }

    public function testT75ApiKeyNeverAppearsBeyondFourCharacters(): void
    {
        $key = 'sk-live-abcdefghijklmnop1234WXYZ';
        $this->settings(['adapter' => 'openai-compatible', 'adapter_settings' => ['openai-compatible' => ['base_url' => 'https://api.example.com/v1', 'model' => 'm', 'api_key' => $key]]]);
        $this->attachment(10, ['width' => 800, 'height' => 600]);
        \WP::$http = static fn (): array => ['response' => ['code' => 500], 'headers' => [], 'body' => '{"error":{"message":"upstream echoed Authorization: Bearer ' . $key . '"}}'];
        $logged = [];
        add_action('alt_text_fill_log', static function (string $level, string $message) use (&$logged): void {
            $logged[] = $message;
        }, 10, 2);

        $this->runReal();
        $page = (new SettingsScreen($this->c))->render('settings');
        $result = $this->c->runner->testConnection();

        $everything = implode("\n", array_merge($logged, $this->logger->written, [$page, $result['message']]));
        self::assertStringNotContainsString($key, $everything);
        self::assertStringNotContainsString('abcdefghijklmnop', $everything);
        self::assertStringContainsString('WXYZ', $page);
        self::assertStringNotContainsString('value="' . $key, $page);
    }

    public function testT75KeyFromConstantOrEnvironmentIsNotStored(): void
    {
        putenv('ALT_TEXT_FILL_API_KEY=env-key-0000000000009999');
        try {
            $this->settings(['adapter' => 'openai-compatible']);
            self::assertSame('env-key-0000000000009999', $this->c->settings->adapterSettings('openai-compatible')['api_key']);
            $this->c->settings->save(['adapter_settings' => ['openai-compatible' => ['api_key' => 'typed-in', 'model' => 'm']]]);
            $stored = \WP::$options[Settings::OPTION]['adapter_settings']['openai-compatible'];
            self::assertArrayNotHasKey('api_key', $stored);
            $page = (new SettingsScreen($this->c))->render('settings');
            self::assertStringContainsString('Set by constant or environment', $page);
            self::assertStringNotContainsString('env-key-0000000000009999', $page);
        } finally {
            putenv('ALT_TEXT_FILL_API_KEY');
        }
    }

    public function testT76UninstallDropsTablesOptionsOwnMetaAndLeavesEveryAltUnchanged(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        Schema::install($wpdb);
        $s = $this->suggest(10);
        $this->c->review->approve($s->id);
        $this->attachment(11, ['alt' => 'Human text', 'write' => false]);
        $this->c->review->markDecorative($this->attachment(12, ['write' => false]));
        $this->c->switches->pause('x');
        $this->c->state->update(['cursor' => 5]);
        \WP::$cron[Plugin::CRON_HOOK] = ['time' => 1, 'recurrence' => Plugin::SCHEDULE];
        \WP::$cron[Plugin::MAINTENANCE_HOOK] = ['time' => 1, 'recurrence' => 'daily'];
        $altBefore = [$this->alt(10), $this->alt(11), $this->alt(12)];

        Uninstaller::uninstall();

        foreach (['suggestions', 'entries', 'runs'] as $t) {
            self::assertContains('DROP TABLE IF EXISTS `wp_alt_text_fill_' . $t . '`', $wpdb->queries);
        }
        foreach (array_merge([Settings::OPTION, Schema::OPTION], State::options()) as $option) {
            self::assertArrayNotHasKey($option, \WP::$options, $option);
        }
        foreach (\WP::$meta as $keys) {
            self::assertArrayNotHasKey('_alt_text_fill_state', $keys);
            self::assertArrayNotHasKey('_alt_text_fill_decorative', $keys);
        }
        self::assertSame([], \WP::$cron);
        self::assertSame($altBefore, [$this->alt(10), $this->alt(11), $this->alt(12)]);
        self::assertSame('A boat at the quay', $this->alt(10));
    }

    public function testT76NetworkUninstallWalksEverySiteInBatches(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        \WP::$multisite = true;
        \WP::$sites = range(1, 205);
        $prev = \WP::$blog;
        Uninstaller::uninstall();
        $drops = count(array_filter($wpdb->queries, static fn (string $q): bool => str_contains($q, 'alt_text_fill_runs')));
        self::assertSame(205, $drops);
        self::assertSame($prev, \WP::$blog);
    }

    public function testT77TableUpgradeVerifiesColumnsAndWarnsWhenOneIsMissing(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        self::assertSame([], Schema::install($wpdb));
        self::assertSame(1, get_option(Schema::OPTION)['version']);

        $wpdb = new \wpdb();
        $all = Schema::expectedColumns()['entries'];
        $wpdb->columns['wp_alt_text_fill_entries'] = array_values(array_diff($all, ['undone_by']));
        $missing = Schema::install($wpdb);

        self::assertSame(['entries' => ['undone_by']], $missing);
        self::assertSame(['entries' => ['undone_by']], Schema::missing());
        ob_start();
        Screens::notices();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('missing columns', $html);
        self::assertStringContainsString('undone_by', $html);
        $health = \IndianOpinion\AltTextFill\Admin\SiteHealth::test();
        self::assertStringContainsString('undone_by', $health['description']);
    }

    public function testT77MaybeUpgradeRunsOnlyForAnOlderVersion(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        update_option(Schema::OPTION, ['version' => Schema::VERSION, 'missing' => []]);
        Schema::maybeUpgrade($wpdb);
        self::assertSame([], \WP::$dbDelta);
        update_option(Schema::OPTION, ['version' => 0, 'missing' => []]);
        Schema::maybeUpgrade($wpdb);
        self::assertCount(3, \WP::$dbDelta);
    }

    public function testSiteHealthReportsMissingEditorAndPause(): void
    {
        add_filter('alt_text_fill_scaler', static fn () => null);
        $this->rebuild();
        $this->c->switches->pause('manual');

        $result = \IndianOpinion\AltTextFill\Admin\SiteHealth::test();

        self::assertSame('recommended', $result['status']);
        self::assertStringContainsString('No image editor', $result['description']);
        self::assertStringContainsString('Paused: manual', $result['description']);
    }
}
