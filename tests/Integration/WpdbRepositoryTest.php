<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Integration;

use IndianOpinion\AltTextFill\Context\WpMediaLibrary;
use IndianOpinion\AltTextFill\Exception\StorageException;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Storage\Schema;
use IndianOpinion\AltTextFill\Storage\WpdbRepository;
use PHPUnit\Framework\TestCase;

/**
 * The wpdb storage and media queries against a real MySQL server. Skipped
 * unless ALT_TEXT_FILL_TEST_DB_SOCKET or ALT_TEXT_FILL_TEST_DB_HOST is set.
 */
final class WpdbRepositoryTest extends TestCase
{
    private MysqlWpdb $db;
    private WpdbRepository $repo;

    protected function setUp(): void
    {
        if (!MysqlWpdb::configured()) {
            self::markTestSkipped('No test database configured.');
        }
        \WP::reset();
        $this->db = new MysqlWpdb();
        foreach (['suggestions', 'entries', 'runs'] as $t) {
            $this->db->query('DROP TABLE IF EXISTS `wptest_alt_text_fill_' . $t . '`');
        }
        foreach (Schema::statements($this->db) as $sql) {
            $this->db->query($sql);
        }
        $this->repo = new WpdbRepository($this->db);
    }

    public function testSchemaCreatesEveryExpectedColumn(): void
    {
        self::assertSame([], Schema::verify($this->db));
        $this->db->query('ALTER TABLE wptest_alt_text_fill_entries DROP COLUMN undone_by');
        self::assertSame(['entries' => ['undone_by']], Schema::verify($this->db));
    }

    public function testSuggestionRoundTripFiltersAndSorting(): void
    {
        $base = ['run_id' => 'r1', 'kind' => 'text', 'flags' => '["names person"]', 'status' => 'pending', 'file_hash' => 'f:x', 'adapter' => 'fake', 'model' => 'm', 'decided' => 0, 'decided_by' => 0];
        $a = $this->repo->insertSuggestion($base + ['attachment_id' => 5, 'suggested_text' => "Quote ' and \\ backslash", 'confidence' => 0.4, 'created' => 10, 'input_tokens' => 12, 'output_tokens' => null]);
        $b = $this->repo->insertSuggestion(array_merge($base, ['attachment_id' => 6, 'suggested_text' => 'B', 'confidence' => 0.9, 'created' => 20, 'flags' => '[]', 'context_post_id' => 77]));

        $s = $this->repo->getSuggestion($a);
        self::assertSame("Quote ' and \\ backslash", $s?->text);
        self::assertSame(['names person'], $s?->flags);
        self::assertSame(12, $s?->inputTokens);
        self::assertNull($s?->outputTokens);
        $ids = static fn (array $l): array => array_map(static fn (Suggestion $x): int => $x->id, $l);
        self::assertSame([$b, $a], $ids($this->repo->findSuggestions(['status' => 'pending'])));
        self::assertSame([$a, $b], $ids($this->repo->findSuggestions([], 'confidence', 'ASC')));
        self::assertSame([$a], $ids($this->repo->findSuggestions(['flag' => 'names person'])));
        self::assertSame([$b], $ids($this->repo->findSuggestions(['min_confidence' => 0.5, 'context_post_id' => 77])));
        self::assertSame(2, $this->repo->countSuggestions(['status' => ['pending', 'approved']]));

        $this->repo->updateSuggestion($a, ['status' => 'approved', 'decided' => 30]);
        self::assertSame([['adapter' => 'fake', 'model' => 'm', 'status' => 'approved', 'n' => 1]], $this->repo->decisionStats(0));
        self::assertSame(1, $this->repo->deletePendingForAttachment(6));
        self::assertNull($this->repo->getSuggestion($b));
    }

    public function testEntriesKeepExactNullAndWhitespaceValues(): void
    {
        $row = ['attachment_id' => 9, 'previous_exists' => false, 'previous_value' => null, 'new_exists' => true, 'new_value' => "  \t text ", 'marker_before' => false, 'marker_after' => true, 'action' => 'approve', 'user_id' => 1, 'created' => 100, 'run_id' => 'r1', 'suggestion_id' => 3, 'undoes' => 0, 'undone_by' => 0];
        $id = $this->repo->insertEntry($row);
        $id2 = $this->repo->insertEntry(array_merge($row, ['created' => 200, 'previous_exists' => true, 'previous_value' => '']));

        $e = $this->repo->getEntry($id);
        self::assertFalse($e?->previousExists);
        self::assertNull($e?->previousValue);
        self::assertSame("  \t text ", $e?->newValue);
        self::assertTrue($e?->markerAfter);
        self::assertSame('', $this->repo->getEntry($id2)?->previousValue);
        self::assertSame([$id2, $id], array_map(static fn ($x): int => $x->id, $this->repo->findEntries(['run_id' => 'r1'])));
        self::assertSame([$id2], array_map(static fn ($x): int => $x->id, $this->repo->findEntries(['since' => 150])));
        $this->repo->markEntryUndone($id, $id2);
        self::assertSame($id2, $this->repo->getEntry($id)?->undoneBy);
    }

    public function testRunsAndPurge(): void
    {
        $this->repo->saveRun(['id' => 'r1', 'started' => 100, 'finished' => 0, 'mode' => 'real', 'run_trigger' => 'cli', 'counts' => ['sent' => 1], 'skipped' => [], 'stop_reason' => 'running', 'user_id' => 1]);
        $this->repo->saveRun(['id' => 'r1', 'started' => 100, 'finished' => 150, 'mode' => 'real', 'run_trigger' => 'cli', 'counts' => ['sent' => 2], 'skipped' => ['too small' => 1], 'stop_reason' => 'done', 'user_id' => 1]);
        $run = $this->repo->getRun('r1');
        self::assertSame(2, $run?->count('sent'));
        self::assertSame(['too small' => 1], $run?->skipped);
        self::assertSame('done', $run?->stopReason);
        self::assertCount(1, $this->repo->recentRuns());

        $this->repo->insertSuggestion(['attachment_id' => 1, 'suggested_text' => 'x', 'status' => 'pending', 'created' => 50, 'flags' => '[]']);
        $this->repo->insertSuggestion(['attachment_id' => 2, 'suggested_text' => 'y', 'status' => 'approved', 'created' => 10, 'decided' => 20, 'flags' => '[]']);
        $out = $this->repo->purge(60, 30, 200, 0);
        self::assertSame(1, $out['expired']);
        self::assertSame(1, $out['suggestions']);
        self::assertSame(1, $out['runs']);
    }

    public function testMediaLibraryQueries(): void
    {
        $this->db->query('DROP TABLE IF EXISTS wptest_posts');
        $this->db->query('DROP TABLE IF EXISTS wptest_postmeta');
        $this->db->query("CREATE TABLE wptest_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_type varchar(20) NOT NULL DEFAULT 'post', post_status varchar(20) NOT NULL DEFAULT 'publish', post_mime_type varchar(100) NOT NULL DEFAULT '', post_content longtext NOT NULL, post_password varchar(255) NOT NULL DEFAULT '', post_date datetime NOT NULL DEFAULT '2026-01-01 00:00:00')");
        $this->db->query('CREATE TABLE wptest_postmeta (meta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id bigint unsigned NOT NULL, meta_key varchar(255), meta_value longtext)');
        $this->db->query("INSERT INTO wptest_posts (ID, post_type, post_status, post_mime_type, post_content) VALUES
            (1, 'attachment', 'inherit', 'image/jpeg', ''), (2, 'attachment', 'inherit', 'application/pdf', ''),
            (3, 'attachment', 'inherit', 'image/png', ''), (4, 'attachment', 'inherit', 'image/webp', '')");
        $this->db->query("INSERT INTO wptest_posts (ID, post_status, post_content, post_date) VALUES
            (10, 'publish', '<img class=\"wp-image-1\" />', '2026-01-01'), (11, 'draft', '<!-- wp:image {\"id\":1} -->', '2026-03-01'),
            (12, 'publish', '<img src=\"//example.org/up/a-300x200.jpg\" />', '2026-02-01'), (13, 'trash', 'wp-image-1\"', '2026-04-01'),
            (14, 'publish', 'wp-image-11\"', '2026-05-01')");
        $this->db->query("INSERT INTO wptest_postmeta (post_id, meta_key, meta_value) VALUES (12, '_thumbnail_id', '1'), (14, '_thumbnail_id', '11')");
        $lib = new WpMediaLibrary($this->db);

        self::assertSame([1, 3], $lib->attachmentPage(0, 2));
        self::assertSame([4], $lib->attachmentPage(3, 2));
        self::assertSame([12], $lib->thumbnailUsers(1));
        self::assertSame([12, 10, 11], $lib->contentUsers(1, ['//example.org/up/a-300x200.jpg'], 20));
        self::assertSame([12], $lib->contentUsers(1, ['//example.org/up/a-300x200.jpg'], 1));
        self::assertSame([10, 11], $lib->contentUsers(1, [], 20));
    }

    public function testRunLockIsExclusiveAcrossConnectionsAndAStaleLockIsBrokenOnce(): void
    {
        $this->db->query('DROP TABLE IF EXISTS wptest_options');
        $this->db->query("CREATE TABLE wptest_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', UNIQUE KEY option_name (option_name))");
        $clock = new \IndianOpinion\AltTextFill\Tests\Support\FrozenClock();
        $a = new \IndianOpinion\AltTextFill\State($clock, \IndianOpinion\AltTextFill\State::lockDatabase($this->db));
        $b = new \IndianOpinion\AltTextFill\State($clock, \IndianOpinion\AltTextFill\State::lockDatabase(new MysqlWpdb()));

        $tokenA = $a->acquireLock(600);
        self::assertNotNull($tokenA);
        self::assertNull($b->acquireLock(600), 'a second connection must not take a held lock');
        self::assertTrue($a->refreshLock((string) $tokenA, 600));

        // Both see the same expired lock; only one may break it.
        $clock->advance(601);
        $broke1 = $broke2 = false;
        $tokenB = $b->acquireLock(600, $broke1);
        self::assertNotNull($tokenB);
        self::assertTrue($broke1);
        self::assertNull($a->acquireLock(600, $broke2));
        self::assertFalse($a->refreshLock((string) $tokenA, 600), 'the old holder has lost the lock');

        $a->releaseLock((string) $tokenA);
        self::assertNull($a->acquireLock(600), 'releasing someone else\'s token changes nothing');
        $b->releaseLock((string) $tokenB);
        self::assertNotNull($a->acquireLock(600));
    }

    public function testInsertFailureThrows(): void
    {
        $this->db->query('DROP TABLE wptest_alt_text_fill_entries');
        $this->expectException(StorageException::class);
        $this->repo->insertEntry(['attachment_id' => 1]);
    }
}
