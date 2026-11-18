<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Tests\TestCase;

/**
 * PRD 4.7, writing and undo.
 */
final class UndoTest extends TestCase
{
    private function approved(int $attachment, string $text, ?string $alt = null, string $run = 'r1'): Entry
    {
        $o = ['width' => 800, 'height' => 600, 'write' => false];
        if ($alt !== null) {
            $o['alt'] = $alt;
        }
        $this->attachment($attachment, $o);
        $id = $this->repo->insertSuggestion([
            'attachment_id' => $attachment, 'run_id' => $run, 'kind' => 'text', 'suggested_text' => $text,
            'flags' => '[]', 'confidence' => 0.9, 'status' => Suggestion::PENDING, 'file_hash' => '',
            'created' => $this->clock->now, 'previous_alt' => '',
        ]);

        return $this->c->review->approve($id);
    }

    public function testT49WriteCreatesAnEntryWithTheExactPreviousState(): void
    {
        $this->clock->advance(5);
        $absent = $this->approved(10, 'A pier in fog');
        $blank = $this->approved(11, 'A pier at noon', " \u{00A0}");

        self::assertFalse($absent->previousExists);
        self::assertNull($absent->previousValue);
        self::assertTrue($absent->newExists);
        self::assertSame('A pier in fog', $absent->newValue);
        self::assertSame(Entry::APPROVE, $absent->action);
        self::assertSame(1, $absent->userId);
        self::assertSame($this->clock->now, $absent->created);
        self::assertSame('r1', $absent->runId);
        self::assertGreaterThan(0, $absent->suggestionId);
        self::assertTrue($blank->previousExists);
        self::assertSame(" \u{00A0}", $blank->previousValue);
        self::assertCount(1, \WP::firedArgs('alt_text_fill_written')[0]);
    }

    public function testT50UndoRestoresAbsentAsAbsentAndWhitespaceExactly(): void
    {
        $a = $this->approved(10, 'A pier in fog');
        $b = $this->approved(11, 'A pier at noon', "  \t ");
        $this->attachment(12, ['alt' => 'back\\slash \'quote\'', 'write' => false]);
        $c = $this->c->writer->apply(12, true, 'A pier at dusk', false, Entry::APPROVE, 'r1', 0);
        $d = $this->approved(13, 'A pier at dawn', '');

        foreach ([$a, $b, $c, $d] as $e) {
            self::assertSame(1, alt_text_fill_undo($e->id)->restored);
        }

        self::assertNull($this->alt(10));
        self::assertFalse(metadata_exists('post', 10, '_wp_attachment_image_alt'));
        self::assertSame("  \t ", $this->alt(11));
        self::assertSame('back\\slash \'quote\'', $this->alt(12));
        self::assertSame('', $this->alt(13));
    }

    public function testT51UndoRefusesOnChangedSinceAndForceOverridesOneEntry(): void
    {
        $e = $this->approved(10, 'A pier in fog');
        update_post_meta(10, '_wp_attachment_image_alt', 'An editor rewrote this');

        $report = $this->c->undo->entry($e->id);
        self::assertSame(1, $report->refused);
        self::assertStringContainsString('changed since', $report->details[0]['message']);
        self::assertStringContainsString('A pier in fog', $report->details[0]['message']);
        self::assertStringContainsString('An editor rewrote this', $report->details[0]['message']);
        self::assertSame('An editor rewrote this', $this->alt(10));

        $forced = $this->c->undo->entry($e->id, true);
        self::assertSame(1, $forced->restored);
        self::assertNull($this->alt(10));
    }

    public function testT51ScreenForceNeedsTheConfirmStep(): void
    {
        $e = $this->approved(10, 'A pier in fog');
        update_post_meta(10, '_wp_attachment_image_alt', 'Changed');
        $nonce = wp_create_nonce(\IndianOpinion\AltTextFill\Admin\Screens::NONCE_UNDO);

        \IndianOpinion\AltTextFill\Admin\Screens::handleUndo(['entry' => $e->id, 'force' => '1', '_wpnonce' => $nonce], false);
        self::assertSame('Changed', $this->alt(10));
        \IndianOpinion\AltTextFill\Admin\Screens::handleUndo(['entry' => $e->id, 'force' => '1', 'confirm' => '1', '_wpnonce' => $nonce], false);
        self::assertNull($this->alt(10));
    }

    public function testT52UndoByRunNewestFirstWithCounts(): void
    {
        $order = [];
        add_action('alt_text_fill_undone', static function (Entry $undo, Entry $original) use (&$order): void {
            $order[] = $original->id;
        }, 10, 2);
        $e1 = $this->approved(10, 'One harbour view', null, 'rX');
        $this->clock->advance(10);
        $e2 = $this->approved(11, 'Two harbour views', null, 'rX');
        $this->clock->advance(10);
        $e3 = $this->approved(12, 'Three harbour views', null, 'rX');
        $other = $this->approved(13, 'Other run', null, 'rY');
        update_post_meta(11, '_wp_attachment_image_alt', 'Changed by hand');
        $this->c->undo->entry($e3->id);
        $order = [];

        $report = alt_text_fill_undo_run('rX');

        self::assertSame(1, $report->restored);
        self::assertSame(1, $report->refused);
        self::assertSame(1, $report->alreadyUndone);
        self::assertSame([$e3->id, $e2->id, $e1->id], array_column($report->details, 'entry'));
        self::assertSame([$e1->id], $order);
        self::assertSame('Other run', $this->alt(13));
        self::assertNotNull($other);
    }

    public function testT53UndoSinceReversesOnlyLaterPluginWrites(): void
    {
        $early = $this->approved(10, 'Before the cut');
        $this->clock->advance(100);
        $cut = $this->clock->now;
        $late = $this->approved(11, 'After the cut');
        $this->attachment(12, ['alt' => 'Human edit, not the plugin', 'write' => false]);

        $report = alt_text_fill_undo_since($cut);

        self::assertSame(1, $report->restored);
        self::assertSame('Before the cut', $this->alt(10));
        self::assertNull($this->alt(11));
        self::assertSame('Human edit, not the plugin', $this->alt(12));
        self::assertNotNull($early);
        self::assertNotNull($late);

        $again = alt_text_fill_undo_since($cut);
        self::assertSame(0, $again->restored);
        self::assertSame(1, $again->alreadyUndone);
        self::assertNull($this->alt(11), 'undo entries are not re-applied by a later undo since');
    }

    public function testT54UndoOfAnUndoRestoresTheWrittenValue(): void
    {
        $e = $this->approved(10, 'A pier in fog');
        $this->c->undo->entry($e->id);
        $undoEntry = $this->repo->findEntries(['attachment_id' => 10])[0];
        self::assertSame(Entry::UNDO, $undoEntry->action);
        self::assertSame($e->id, $undoEntry->undoes);

        $this->c->undo->entry($undoEntry->id);

        self::assertSame('A pier in fog', $this->alt(10));
        self::assertCount(3, $this->repo->findEntries(['attachment_id' => 10]));

        // The original write is live again, so undo by run takes it back.
        self::assertFalse($this->repo->getEntry($e->id)?->isUndone());
        $report = $this->c->undo->run($e->runId);
        self::assertSame(1, $report->restored);
        self::assertNull($this->alt(10));
    }

    public function testT55WritesAndUndosChangeOnlyTheAltField(): void
    {
        $this->post(100, ['content' => '<img class="wp-image-10" alt="" />']);
        $this->attachment(10, ['width' => 800, 'height' => 600, 'caption' => 'Cap', 'description' => 'Desc', 'title' => 'Title', 'write' => false]);
        update_post_meta(10, 'custom', 'keep');
        $postsBefore = serialize(\WP::$posts);
        $metaBefore = \WP::$meta;
        $id = $this->repo->insertSuggestion(['attachment_id' => 10, 'run_id' => 'r', 'kind' => 'text', 'suggested_text' => 'A pier', 'flags' => '[]', 'confidence' => 0.9, 'status' => 'pending', 'file_hash' => '', 'created' => 1]);

        $e = $this->c->review->approve($id);
        $this->c->undo->entry($e->id);

        self::assertSame($postsBefore, serialize(\WP::$posts));
        $after = \WP::$meta;
        unset($after[10]['_alt_text_fill_state'], $after[10]['_wp_attachment_image_alt']);
        self::assertSame($metaBefore, $after);
    }
}
