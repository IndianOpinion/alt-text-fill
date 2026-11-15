<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Cli\Command;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Tests\TestCase;

/**
 * PRD 5.6, WP-CLI, and its exit codes.
 */
final class CliTest extends TestCase
{
    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    private function cli(string $method, array $args = [], array $assoc = []): int
    {
        $command = new Command();
        try {
            $command->$method($args, $assoc);
        } catch (\WP_CLI_Halt $halt) {
            return $halt->exitCode;
        }

        return $command->lastExit;
    }

    private function pending(int $attachment, string $text): int
    {
        $this->attachment($attachment, ['width' => 800, 'height' => 600, 'write' => false]);

        return $this->repo->insertSuggestion(['attachment_id' => $attachment, 'run_id' => 'r1', 'kind' => 'text', 'suggested_text' => $text, 'flags' => '[]', 'confidence' => 0.9, 'status' => Suggestion::PENDING, 'file_hash' => '', 'created' => 1]);
    }

    public function testT78ExitCodeZeroOnSuccess(): void
    {
        $this->attachment(1, ['width' => 800, 'height' => 600]);

        self::assertSame(0, $this->cli('scan', [], ['format' => 'json']));
        self::assertSame('json', \WP_CLI::$tables[0]['format']);
        self::assertSame(0, $this->cli('run', [], ['dry-run' => true]));
        self::assertStringContainsString('Would send 1 images', implode("\n", \WP_CLI::$lines));
        self::assertSame(0, $this->cli('run'));
        self::assertSame(0, $this->cli('status'));
        self::assertSame(0, $this->cli('review', ['list']));
        self::assertSame(1, count(\WP_CLI::$tables[3]['rows']));
        self::assertSame(0, $this->cli('history', [], ['attachment' => '1']));
    }

    public function testT78ExitCodeOneOnError(): void
    {
        self::assertSame(1, $this->cli('review', ['approve', '999']));
        self::assertSame(1, $this->cli('review', ['bogus']));
        self::assertSame(1, $this->cli('undo', ['42'], ['yes' => true]));
        $this->settings(['adapter' => '']);
        self::assertSame(1, $this->cli('run'));
        \WP::$userId = 0;
        self::assertSame(1, $this->cli('decorative', ['1']));
        self::assertStringContainsString('--user', implode(' ', \WP_CLI::$warnings));
    }

    public function testT78ExitCodeTwoWhenRefusedByACapOrTheSwitch(): void
    {
        $this->attachment(1, ['width' => 800, 'height' => 600]);
        self::assertSame(0, $this->cli('pause', [], ['reason' => 'maintenance']));
        self::assertSame('maintenance', $this->c->switches->reason());
        self::assertSame(2, $this->cli('run'));
        self::assertSame(0, $this->cli('resume'));
        self::assertNull($this->c->switches->reason());

        $this->settings(['daily_cap' => 0]);
        self::assertSame(2, $this->cli('run'));

        $this->settings(['daily_cap' => 1]);
        $this->attachment(2, ['width' => 800, 'height' => 600]);
        self::assertSame(2, $this->cli('run'), 'a run stopped mid-way by the cap');
    }

    public function testT78ExitCodeThreeWhenSomeItemsAreRefused(): void
    {
        $ok = $this->pending(1, 'A narrow lane');
        $bad = $this->pending(2, 'A wide lane');
        update_post_meta(2, '_wp_attachment_image_alt', 'Filled by an editor');

        self::assertSame(3, $this->cli('review', ['approve', (string) $ok, (string) $bad]));
        self::assertSame('A narrow lane', $this->alt(1));

        $e1 = $this->repo->findEntries(['attachment_id' => 1])[0];
        $other = $this->pending(3, 'A dusty road');
        $this->c->review->approve($other);
        update_post_meta(3, '_wp_attachment_image_alt', 'Changed');
        self::assertSame(3, $this->cli('undo', [], ['run' => 'r1', 'yes' => true]));
        self::assertNull($this->alt(1));
        self::assertSame('Changed', $this->alt(3));
        self::assertGreaterThan(0, (int) $this->repo->getEntry($e1->id)?->undoneBy);
    }

    public function testT78UndoForceAndSince(): void
    {
        $id = $this->pending(1, 'A narrow lane');
        $e = $this->c->review->approve($id);
        update_post_meta(1, '_wp_attachment_image_alt', 'Changed');

        self::assertSame(1, $this->cli('undo', [(string) $e->id], ['yes' => true]));
        self::assertSame(0, $this->cli('undo', [(string) $e->id], ['force' => true, 'yes' => true]));
        self::assertNull($this->alt(1));
        self::assertSame(1, $this->cli('undo', [], ['since' => 'not a date', 'yes' => true]));
        self::assertSame(0, $this->cli('undo', [], ['since' => '2000-01-01 00:00', 'yes' => true]));
    }

    public function testT41CliHasNoApproveEverythingOption(): void
    {
        $this->pending(1, 'A narrow lane');
        self::assertSame(1, $this->cli('review', ['approve'], ['all' => true]));
        self::assertNull($this->alt(1));
        self::assertSame(1, $this->cli('review', ['approve', '1', '2'], ['text' => 'Edited']));
        self::assertSame(1, $this->cli('review', ['reject', '1']));
    }

    public function testRejectAndDecorativeFromCli(): void
    {
        $id = $this->pending(1, 'A narrow lane');
        $this->attachment(2, ['write' => false]);

        self::assertSame(0, $this->cli('review', ['reject', (string) $id], ['reason' => 'vague', 'note' => 'too general']));
        self::assertSame('vague', $this->repo->getSuggestion($id)?->rejectReason);
        self::assertSame(0, $this->cli('decorative', ['2']));
        self::assertTrue($this->c->field->isDecorative(2));
    }
}
