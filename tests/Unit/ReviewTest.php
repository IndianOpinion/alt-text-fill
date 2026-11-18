<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Admin\AttachmentBox;
use IndianOpinion\AltTextFill\Admin\ReviewScreen;
use IndianOpinion\AltTextFill\Admin\Screens;
use IndianOpinion\AltTextFill\Exception\ConflictException;
use IndianOpinion\AltTextFill\Exception\PermissionException;
use IndianOpinion\AltTextFill\Model\Entry;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Tests\TestCase;
use IndianOpinion\AltTextFill\Validator;

/**
 * PRD 4.6, the review list.
 */
final class ReviewTest extends TestCase
{
    /**
     * @param array<string, mixed> $o
     */
    private function suggestion(int $attachment, string $text = 'A red bus on a bridge', array $o = []): int
    {
        if (!isset(\WP::$posts[$attachment])) {
            $this->attachment($attachment, ['width' => 800, 'height' => 600]);
        }

        return $this->repo->insertSuggestion(array_merge([
            'attachment_id' => $attachment, 'run_id' => 'r1', 'kind' => 'text', 'suggested_text' => $text,
            'original_text' => '{}', 'final_text' => '', 'previous_alt' => '', 'flags' => '[]', 'confidence' => 0.9,
            'status' => Suggestion::PENDING, 'reject_reason' => '', 'reject_note' => '', 'context_post_id' => 0,
            'file_hash' => '', 'adapter' => 'fake', 'model' => 'fake-model', 'created' => $this->clock->now,
            'decided' => 0, 'decided_by' => 0,
        ], $o));
    }

    /**
     * @param array<string, mixed> $post
     * @return list<string>
     */
    private function submit(string $op, array $post = [], string $nonce = Screens::NONCE_REVIEW): array
    {
        return Screens::handleReview(array_merge(['op' => $op, '_wpnonce' => wp_create_nonce($nonce)], $post), false);
    }

    public function testT40ReviewListRendersEveryColumnEscaped(): void
    {
        $this->post(100, ['title' => 'Bridge <story>']);
        $this->suggestion(10, '<script>alert("x")</script> & a bus', ['flags' => json_encode([Validator::FLAG_NAMES_PERSON]), 'context_post_id' => 100, 'model' => 'm<1>']);

        $html = (new ReviewScreen($this->c))->render();

        foreach (['Image', 'Current alt', 'Suggested alt', 'Context post', 'Flags', 'Confidence', 'Adapter and model', 'Time', 'Actions'] as $col) {
            self::assertStringContainsString('>' . $col . '<', $html);
        }
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; a bus', $html);
        self::assertStringContainsString('Bridge &lt;story&gt;', $html);
        self::assertStringContainsString('m&lt;1&gt;', $html);
        self::assertStringContainsString('>none<', $html);
        self::assertStringContainsString('names person', $html);
        self::assertStringContainsString('0.90', $html);
        self::assertStringContainsString('class="alt-text-fill-count"', $html);
        self::assertStringContainsString('<img src=', $html);
    }

    public function testT41RowAndBulkActionsWork(): void
    {
        $a = $this->suggestion(10);
        $b = $this->suggestion(11, 'A quiet street');
        $c = $this->suggestion(12, 'Three cups of tea');
        $d = $this->suggestion(13, 'A tiled roof');

        $this->submit('approve', ['id' => $a]);
        self::assertSame('A red bus on a bridge', $this->alt(10));
        $this->submit('approve_edit', ['id' => $b, 'text' => [$b => 'A quiet street at night']]);
        self::assertSame('A quiet street at night', $this->alt(11));
        $this->submit('reject', ['id' => $c, 'reason' => [$c => 'vague'], 'note' => [$c => 'too general']]);
        self::assertSame(Suggestion::REJECTED, $this->repo->getSuggestion($c)?->status);
        $this->submit('decorative', ['attachment' => 13]);
        self::assertTrue($this->c->field->isDecorative(13));
        self::assertSame(Suggestion::SUPERSEDED, $this->repo->getSuggestion($d)?->status);

        $e = $this->suggestion(14, 'A wet pavement');
        $f = $this->suggestion(15, 'A lamp post at dusk');
        $g = $this->suggestion(16, 'A row of bicycles');
        $this->submit('bulk_approve', ['ids' => [$e, $f]]);
        self::assertSame('A wet pavement', $this->alt(14));
        self::assertSame('A lamp post at dusk', $this->alt(15));
        $this->submit('bulk_reject', ['ids' => [$g]]);
        self::assertSame(Suggestion::REJECTED, $this->repo->getSuggestion($g)?->status);

        $h = $this->suggestion(17, 'A hillside');
        $this->submit('try_again', ['attachment' => 17]);
        self::assertSame('try again', $this->repo->getSuggestion($h)?->rejectReason);
        self::assertFalse($this->c->field->state(17)['hold']);
        $msgs = $this->submit('skip', ['id' => $h]);
        self::assertStringContainsString('Skipped', $msgs[0]);
    }

    public function testT41NoCrossPageApproveAll(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->suggestion(100 + $i, 'A view number ' . $i);
        }
        $html = strtolower((new ReviewScreen($this->c))->render());
        self::assertStringNotContainsString('approve all', $html);
        self::assertSame(20, substr_count($html, 'name="ids[]"'));

        $this->submit('bulk_approve', ['ids' => $ids]);
        self::assertSame(Screens::PER_PAGE, $this->repo->countSuggestions(['status' => Suggestion::APPROVED]));

        $doc = (string) (new \ReflectionMethod(\IndianOpinion\AltTextFill\Cli\Command::class, 'review'))->getDocComment();
        self::assertStringNotContainsString('--all', $doc);
    }

    public function testT42ApprovalAfterAnEditorFilledTheFieldIsSuperseded(): void
    {
        $id = $this->suggestion(10);
        update_post_meta(10, '_wp_attachment_image_alt', 'Written by an editor');

        try {
            $this->c->review->approve($id);
            self::fail('expected a conflict');
        } catch (ConflictException $e) {
            self::assertSame('Written by an editor', $e->current());
        }
        self::assertSame('Written by an editor', $this->alt(10));
        self::assertSame(Suggestion::SUPERSEDED, $this->repo->getSuggestion($id)?->status);
        self::assertSame([], $this->repo->entries);
        self::assertStringContainsString('superseded', (new ReviewScreen($this->c))->render(['status' => 'superseded']));
    }

    public function testT42DecorativeApprovalAfterAnEditorFilledTheFieldIsSuperseded(): void
    {
        $id = $this->suggestion(10, '', ['kind' => Suggestion::KIND_DECORATIVE]);
        update_post_meta(10, '_wp_attachment_image_alt', 'Written by an editor');

        $this->expectException(ConflictException::class);
        try {
            $this->c->review->approve($id);
        } finally {
            self::assertFalse($this->c->field->isDecorative(10));
            self::assertSame(Suggestion::SUPERSEDED, $this->repo->getSuggestion($id)?->status);
        }
    }

    public function testT42WeakAltReplacementIsNotSuperseded(): void
    {
        $this->settings(['weak_alt' => true]);
        $this->attachment(10, ['width' => 800, 'height' => 600, 'alt' => 'IMG_2001']);
        $id = $this->suggestion(10, 'A cyclist on a hill', ['previous_alt' => 'IMG_2001', 'flags' => json_encode([Validator::FLAG_WEAK_ALT])]);
        $html = (new ReviewScreen($this->c))->render();
        self::assertStringContainsString('<strong>IMG_2001</strong>', $html);

        $entry = $this->c->review->approve($id);

        self::assertSame('A cyclist on a hill', $this->alt(10));
        self::assertSame('IMG_2001', $entry->previousValue);
        $this->c->undo->entry($entry->id);
        self::assertSame('IMG_2001', $this->alt(10));
    }

    public function testT43EditedApprovalStoresBothTextsAndIsCleaned(): void
    {
        $this->post(100, ['title' => 'Harbour festival opens with boat parade']);
        $id = $this->suggestion(10, 'Boats', ['context_post_id' => 100]);

        $entry = $this->c->review->approve($id, '  "An image of <b>Harbour festival opens with boat parade</b>"  ');

        self::assertSame(Entry::APPROVE_EDITED, $entry->action);
        $s = $this->repo->getSuggestion($id);
        self::assertSame(Suggestion::EDITED, $s?->status);
        self::assertSame('Boats', $s?->text);
        self::assertSame('Harbour festival opens with boat parade', $s?->finalText);
        self::assertSame('Harbour festival opens with boat parade', $this->alt(10), 'a person may use the title');

        $id2 = $this->suggestion(11);
        try {
            $this->c->review->approve($id2, " \u{200B} ");
            self::fail('empty edit must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('empty', $e->getMessage());
        }
        self::assertNull($this->alt(11));
        self::assertSame(Suggestion::PENDING, $this->repo->getSuggestion($id2)?->status);
    }

    public function testT44MarkDecorativeSetsTheMarkerWritesEmptyOnlyIfAbsentAndIsUndoable(): void
    {
        $this->attachment(10, ['width' => 800, 'height' => 600]);
        $this->attachment(11, ['width' => 800, 'height' => 600, 'alt' => ' ']);

        $e1 = alt_text_fill_mark_decorative(10);
        $e2 = alt_text_fill_mark_decorative(11);

        self::assertTrue($this->c->field->isDecorative(10));
        self::assertSame('', $this->alt(10));
        self::assertSame(' ', $this->alt(11));
        self::assertFalse($e1->previousExists);
        self::assertTrue($e1->markerAfter);
        self::assertFalse($e1->markerBefore);

        $this->c->undo->entry($e1->id);
        $this->c->undo->entry($e2->id);
        self::assertFalse($this->c->field->isDecorative(10));
        self::assertNull($this->alt(10));
        self::assertSame(' ', $this->alt(11));
        self::assertFalse($this->c->field->isDecorative(11));
    }

    public function testT45RejectStoresReasonAndNote(): void
    {
        $id = $this->suggestion(10);

        alt_text_fill_reject($id, 'names the wrong person', '<i>It is the mayor</i>');

        $s = $this->repo->getSuggestion($id);
        self::assertSame(Suggestion::REJECTED, $s?->status);
        self::assertSame('names the wrong person', $s?->rejectReason);
        self::assertSame('It is the mayor', $s?->rejectNote);
        self::assertSame(1, $s?->decidedBy);
        alt_text_fill_reject($this->suggestion(11), 'made up reason');
        self::assertSame('other', $this->repo->findSuggestions(['attachment_id' => 11])[0]->rejectReason);
    }

    public function testT46PendingSuggestionPastRetentionExpires(): void
    {
        $old = $this->suggestion(10, 'Old text', ['created' => $this->clock->now - 31 * DAY_IN_SECONDS]);
        $new = $this->suggestion(11, 'New text', ['created' => $this->clock->now - 2 * DAY_IN_SECONDS]);

        $out = Plugin::maintenance();

        self::assertSame(1, $out['expired']);
        self::assertSame(Suggestion::EXPIRED, $this->repo->getSuggestion($old)?->status);
        self::assertSame(Suggestion::PENDING, $this->repo->getSuggestion($new)?->status);
        self::assertNull($this->alt(10));
        self::assertInstanceOf(\IndianOpinion\AltTextFill\Model\Candidate::class, $this->c->scanner->evaluate(10));
        $this->expectException(ConflictException::class);
        $this->c->review->approve($old);
    }

    public function testT46RetentionIsFilterableAndZeroKeepsForever(): void
    {
        $this->suggestion(10, 'Old text', ['created' => $this->clock->now - 400 * DAY_IN_SECONDS]);
        add_filter('alt_text_fill_retention_days', static fn (int $d, string $table): int => 0, 10, 2);

        self::assertSame(0, Plugin::maintenance()['expired']);
    }

    public function testT47ListFiltersAndSorts(): void
    {
        $this->post(100);
        $a = $this->suggestion(10, 'Alpha view', ['confidence' => 0.3, 'flags' => json_encode([Validator::FLAG_CONTAINS_TEXT]), 'run_id' => 'rA', 'created' => 10]);
        $b = $this->suggestion(11, 'Bravo view', ['confidence' => 0.9, 'context_post_id' => 100, 'run_id' => 'rB', 'created' => 20]);
        $c = $this->suggestion(12, 'Charlie view', ['confidence' => 0.6, 'status' => Suggestion::REJECTED, 'created' => 30]);

        $repo = $this->repo;
        $ids = static fn (array $list): array => array_map(static fn (Suggestion $s): int => $s->id, $list);
        self::assertSame([$b, $a], $ids($repo->findSuggestions(['status' => 'pending'])));
        self::assertSame([$a, $b], $ids($repo->findSuggestions(['status' => 'pending'], 'confidence', 'ASC')));
        self::assertSame([$a], $ids($repo->findSuggestions(['flag' => Validator::FLAG_CONTAINS_TEXT])));
        self::assertSame([$b], $ids($repo->findSuggestions(['min_confidence' => 0.8])));
        self::assertSame([$b], $ids($repo->findSuggestions(['context_post_id' => 100])));
        self::assertSame([$a], $ids($repo->findSuggestions(['run_id' => 'rA'])));
        self::assertSame([$c], $ids($repo->findSuggestions(['status' => 'rejected'])));

        $html = (new ReviewScreen($this->c))->render(['status' => 'pending', 'orderby' => 'confidence', 'order' => 'ASC']);
        self::assertLessThan(strpos($html, 'Bravo view'), strpos($html, 'Alpha view'));
        $html = (new ReviewScreen($this->c))->render(['flag' => Validator::FLAG_CONTAINS_TEXT]);
        self::assertStringContainsString('Alpha view', $html);
        self::assertStringNotContainsString('Bravo view', $html);
    }

    public function testT48NoCapabilityOrBadNonceIsRefused(): void
    {
        $id = $this->suggestion(10);

        $msgs = Screens::handleReview(['op' => 'approve', 'id' => $id, '_wpnonce' => 'forged'], false);
        self::assertStringContainsString('expired', $msgs[0]);
        self::assertNull($this->alt(10));

        \WP::$can = static fn (string $cap): bool => $cap !== 'upload_files';
        $msgs = $this->submit('approve', ['id' => $id]);
        self::assertStringContainsString('cannot review', $msgs[0]);
        self::assertNull($this->alt(10));

        \WP::$can = static fn (string $cap, ...$args): bool => !($cap === 'edit_post' && ($args[0] ?? 0) === 10);
        $msgs = $this->submit('approve', ['id' => $id]);
        self::assertStringContainsString('cannot edit', $msgs[0]);
        self::assertNull($this->alt(10));
        self::assertStringNotContainsString('name="ids[]"', (new ReviewScreen($this->c))->render());
        self::assertStringNotContainsString('Approve</button>', AttachmentBox::render($this->c, 10));

        $this->expectException(PermissionException::class);
        $this->c->review->approve($id);
    }

    public function testT48SettingsAndUndoHandlersCheckCapabilityAndNonce(): void
    {
        \WP::$can = static fn (string $cap): bool => $cap !== 'manage_options';
        $msgs = Screens::handleSettings(['op' => 'pause', '_wpnonce' => wp_create_nonce(Screens::NONCE_SETTINGS)], false);
        self::assertStringContainsString('cannot change', $msgs[0]);
        self::assertNull($this->c->switches->reason());

        \WP::$can = null;
        $msgs = Screens::handleSettings(['op' => 'pause', '_wpnonce' => 'bad'], false);
        self::assertStringContainsString('expired', $msgs[0]);
        $msgs = Screens::handleUndo(['entry' => 1, '_wpnonce' => 'bad'], false);
        self::assertStringContainsString('expired', $msgs[0]);
        \WP::$can = static fn (string $cap): bool => $cap !== 'manage_options';
        $msgs = Screens::handleUndo(['run' => 'r1', '_wpnonce' => wp_create_nonce(Screens::NONCE_UNDO)], false);
        self::assertStringContainsString('settings capability', implode(' ', $msgs));
    }
}
