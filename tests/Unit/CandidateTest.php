<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Model\Candidate;
use IndianOpinion\AltTextFill\Scanner;
use IndianOpinion\AltTextFill\Tests\TestCase;

/**
 * PRD 4.1, finding candidates.
 */
final class CandidateTest extends TestCase
{
    public function testT1AllowedTypesAreCandidatesAndSvgIsSkipped(): void
    {
        $this->attachment(10, ['mime' => 'image/jpeg']);
        $this->attachment(11, ['mime' => 'image/png']);
        $this->attachment(12, ['mime' => 'image/gif', 'width' => 100, 'height' => 100]);
        $this->attachment(13, ['mime' => 'image/webp', 'width' => 100, 'height' => 100]);
        $this->attachment(14, ['mime' => 'image/avif', 'write' => false]);
        $this->attachment(15, ['mime' => 'image/svg+xml', 'write' => false]);

        $report = $this->c->scanner->scan(['after_id' => 0]);

        $ids = array_map(static fn (Candidate $c): int => $c->attachmentId, $report->candidates);
        self::assertSame([10, 11, 12, 13, 14], $ids);
        self::assertSame(1, $report->skipped[Scanner::SKIP_UNSUPPORTED]);
        self::assertSame(Scanner::SKIP_UNSUPPORTED, $report->reasons[15]);
    }

    public function testT1MimeFilterCannotReAddSvg(): void
    {
        add_filter('alt_text_fill_mime_types', static fn (array $m): array => array_merge($m, ['image/svg+xml']));
        $this->attachment(15, ['mime' => 'image/svg+xml', 'write' => false]);

        self::assertSame(Scanner::SKIP_UNSUPPORTED, $this->c->scanner->evaluate(15));
    }

    public function testT2HeicWithJpegSizeIsCandidateAndSendsThatSize(): void
    {
        $this->attachment(20, ['mime' => 'image/heic', 'file' => 'phone.heic', 'width' => 4000, 'height' => 3000, 'sizes' => ['large' => [1024, 768, 'image/jpeg']]]);
        $this->attachment(21, ['mime' => 'image/heic', 'file' => 'other.heic', 'width' => 4000, 'height' => 3000]);

        $candidate = $this->c->scanner->evaluate(20);
        self::assertInstanceOf(Candidate::class, $candidate);
        self::assertSame(Scanner::SKIP_UNSUPPORTED, $this->c->scanner->evaluate(21));

        $prep = $this->c->preparer->prepare($candidate, $this->fake->capabilities());
        self::assertNotNull($prep['input']);
        self::assertSame('large', $prep['size']);
        self::assertSame('image/jpeg', $prep['input']->mimeType);
    }

    public function testT2HeicIsCandidateWhenTheAdapterAcceptsIt(): void
    {
        $fake = new \IndianOpinion\AltTextFill\Adapter\FakeAdapter(['mime_types' => ['image/jpeg', 'image/heic']]);
        add_filter('alt_text_fill_adapters', static fn (array $a): array => array_merge($a, ['fake' => $fake]), 20);
        $this->rebuild();
        $this->attachment(21, ['mime' => 'image/heic', 'file' => 'other.heic', 'write' => false]);

        self::assertInstanceOf(Candidate::class, $this->c->scanner->evaluate(21));
    }

    /**
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function altValues(): array
    {
        return [
            'missing' => [null, true],
            'empty string' => ['', true],
            'spaces' => ['   ', true],
            'nbsp' => ["\u{00A0}\u{00A0}", true],
            'zero width' => ["\u{200B}\u{FEFF}", true],
            'mixed invisible' => [" \u{200D}\t\n\u{00A0}", true],
            'real' => ['A dog', false],
        ];
    }

    /**
     * @dataProvider altValues
     */
    public function testT3EmptyAltDetection(?string $alt, bool $empty): void
    {
        $this->attachment(30, ['alt' => $alt, 'write' => false]);

        self::assertSame($empty, alt_text_fill_is_empty(30));
    }

    public function testT4WeakAltModeIsOffByDefault(): void
    {
        $this->attachment(40, ['file' => 'IMG_4021.jpg', 'alt' => 'IMG_4021', 'write' => false]);

        self::assertFalse(alt_text_fill_is_empty(40));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function weakValues(): array
    {
        return [
            'file name with extension' => ['family-trip_2026.jpg', 'family-trip_2026.jpg'],
            'file name words' => ['family trip 2026', 'family-trip_2026.jpg'],
            'camera IMG' => ['IMG_1234', 'x.jpg'],
            'camera DSC' => ['DSC01234', 'x.jpg'],
            'camera PXL' => ['PXL_20260101_123456789', 'x.jpg'],
            'screenshot' => ['Screenshot 2026-01-05 at 10.22.01', 'x.jpg'],
            'filler' => ['Photo', 'x.jpg'],
            'filler placeholder' => ['placeholder', 'x.jpg'],
        ];
    }

    /**
     * @dataProvider weakValues
     */
    public function testT4WeakAltModeTreatsWeakValuesAsEmpty(string $alt, string $file): void
    {
        $this->settings(['weak_alt' => true]);
        $this->attachment(41, ['file' => $file, 'alt' => $alt, 'write' => false]);

        self::assertTrue(alt_text_fill_is_empty(41));
        $candidate = $this->c->scanner->evaluate(41);
        self::assertInstanceOf(Candidate::class, $candidate);
        self::assertTrue($candidate->weakAlt);
        self::assertSame($alt, $candidate->currentAlt);
    }

    public function testT4WeakAltPatternsAreFilterable(): void
    {
        $this->settings(['weak_alt' => true]);
        add_filter('alt_text_fill_weak_alt_patterns', static fn (array $p): array => ['patterns' => [], 'words' => ['banner']]);
        $this->attachment(42, ['alt' => 'Banner', 'write' => false]);
        $this->attachment(43, ['alt' => 'IMG_1234', 'write' => false]);

        self::assertTrue(alt_text_fill_is_empty(42));
        self::assertFalse(alt_text_fill_is_empty(43));
    }

    public function testT5DecorativeIsNeverACandidate(): void
    {
        $this->attachment(50, ['write' => false]);
        $this->c->field->setDecorative(50, true);

        self::assertSame(Scanner::SKIP_DECORATIVE, $this->c->scanner->evaluate(50, true));
        self::assertSame([], $this->c->scanner->scan(['after_id' => 0, 'include_rejected' => true])->candidates);
    }

    public function testT6RejectedAndUndoneAreSkippedUnlessAsked(): void
    {
        $this->attachment(60, ['write' => false]);
        $this->attachment(61, ['write' => false]);
        $this->c->field->updateState(60, ['hold' => true]);
        $this->c->field->updateState(61, ['parked' => Scanner::SKIP_PARKED]);

        self::assertSame(Scanner::SKIP_HELD, $this->c->scanner->evaluate(60));
        self::assertSame(Scanner::SKIP_PARKED, $this->c->scanner->evaluate(61));
        self::assertInstanceOf(Candidate::class, $this->c->scanner->evaluate(60, true));
        self::assertInstanceOf(Candidate::class, $this->c->scanner->evaluate(61, true));
    }

    public function testT6HumanRejectionHoldsAndUndoHolds(): void
    {
        $this->attachment(62, ['write' => false]);
        $sid = $this->repo->insertSuggestion(['attachment_id' => 62, 'status' => 'pending', 'suggested_text' => 'A red bus', 'kind' => 'text', 'created' => 1, 'flags' => '[]', 'file_hash' => '', 'run_id' => 'r', 'confidence' => 0.9]);
        $this->c->review->reject($sid, 'wrong');
        self::assertSame(Scanner::SKIP_HELD, $this->c->scanner->evaluate(62));

        $this->attachment(63, ['write' => false]);
        $sid = $this->repo->insertSuggestion(['attachment_id' => 63, 'status' => 'pending', 'suggested_text' => 'A red bus', 'kind' => 'text', 'created' => 1, 'flags' => '[]', 'file_hash' => '', 'run_id' => 'r', 'confidence' => 0.9]);
        $entry = $this->c->review->approve($sid);
        $this->c->undo->entry($entry->id);
        self::assertNull($this->alt(63));
        self::assertSame(Scanner::SKIP_HELD, $this->c->scanner->evaluate(63));

        $this->c->review->tryAgain(63);
        self::assertInstanceOf(Candidate::class, $this->c->scanner->evaluate(63));
    }

    public function testT7ImageUsedOnlyByDraftIsNotPublic(): void
    {
        $this->post(100, ['status' => 'draft', 'content' => '<!-- wp:image {"id":70} --><img class="wp-image-70" /><!-- /wp:image -->']);
        $this->attachment(70, ['parent' => 100, 'write' => false]);
        foreach (['private', 'pending'] as $i => $status) {
            $this->post(101 + $i, ['status' => $status]);
            $this->attachment(71 + $i, ['parent' => 101 + $i, 'write' => false]);
        }
        $this->post(105, ['password' => 'secret']);
        $this->attachment(75, ['parent' => 105, 'write' => false]);

        foreach ([70, 71, 72, 75] as $id) {
            self::assertSame(Scanner::SKIP_NOT_PUBLIC, $this->c->scanner->evaluate($id), (string) $id);
        }
        $this->settings(['include_nonpublic' => true]);
        $candidate = $this->c->scanner->evaluate(70);
        self::assertInstanceOf(Candidate::class, $candidate);
        self::assertSame(100, $candidate->contextPostId);
    }

    public function testT8UnattachedIsCandidateWithNoContextUnlessExcluded(): void
    {
        $this->attachment(80, ['write' => false]);
        $candidate = $this->c->scanner->evaluate(80);
        self::assertInstanceOf(Candidate::class, $candidate);
        self::assertSame(0, $candidate->contextPostId);
        $context = $this->c->contexts->build(80, 0, false);
        self::assertSame('', $context->text);

        $this->settings(['include_unattached' => false]);
        self::assertSame(Scanner::SKIP_UNATTACHED, $this->c->scanner->evaluate(80));
    }

    public function testT9Exclusions(): void
    {
        $this->attachment(90, ['write' => false]);
        $this->attachment(91, ['mime' => 'image/png', 'write' => false]);
        $this->attachment(92, ['date' => '2020-05-01 00:00:00', 'write' => false]);
        $this->attachment(93, ['width' => 20, 'height' => 300, 'write' => false]);
        $this->attachment(94, ['write' => false]);
        $this->settings(['exclude_ids' => [90], 'exclude_mimes' => ['image/png'], 'date_from' => '2025-01-01']);
        add_filter('alt_text_fill_is_candidate', static fn (bool $yes, int $id): bool => $id !== 94, 10, 2);

        self::assertSame(Scanner::SKIP_EXCLUDED, $this->c->scanner->evaluate(90));
        self::assertSame(Scanner::SKIP_EXCLUDED, $this->c->scanner->evaluate(91));
        self::assertSame(Scanner::SKIP_EXCLUDED, $this->c->scanner->evaluate(92));
        self::assertSame(Scanner::SKIP_TOO_SMALL, $this->c->scanner->evaluate(93));
        self::assertSame(Scanner::SKIP_FILTERED, $this->c->scanner->evaluate(94));
    }

    public function testT9FilterCannotInclude(): void
    {
        $this->attachment(95, ['width' => 10, 'height' => 10, 'write' => false]);
        $this->attachment(96, ['alt' => 'A real description', 'write' => false]);
        $calls = 0;
        add_filter('alt_text_fill_is_candidate', static function () use (&$calls): bool {
            $calls++;

            return true;
        });

        self::assertSame(Scanner::SKIP_TOO_SMALL, $this->c->scanner->evaluate(95));
        self::assertNull($this->c->scanner->evaluate(96));
        self::assertSame(0, $calls);
    }

    public function testT10ScanPagesByIdWithResumableCursorThatWraps(): void
    {
        foreach ([5, 3, 9, 7, 1] as $id) {
            $this->attachment($id, ['write' => false]);
        }
        $first = $this->c->scanner->scan(['limit' => 2]);
        self::assertSame([1, 3], array_map(static fn (Candidate $c): int => $c->attachmentId, $first->candidates));
        self::assertSame(3, $first->cursor);
        self::assertSame(3, $this->c->state->all()['cursor']);

        $this->rebuild();
        $second = $this->c->scanner->scan(['limit' => 2]);
        self::assertSame([5, 7], array_map(static fn (Candidate $c): int => $c->attachmentId, $second->candidates));

        $third = $this->c->scanner->scan(['limit' => 2]);
        self::assertSame([9], array_map(static fn (Candidate $c): int => $c->attachmentId, $third->candidates));
        self::assertTrue($third->wrapped);
        self::assertSame(0, $third->cursor);
        self::assertSame(5, $this->c->state->all()['last_full_scan']['empty']);
        self::assertSame(1, $this->c->state->all()['generation']);

        $again = $this->c->scanner->scan(['limit' => 2]);
        self::assertSame([1, 3], array_map(static fn (Candidate $c): int => $c->attachmentId, $again->candidates));
    }

    public function testT11ScanOpensNoImageFile(): void
    {
        $this->attachment(110, ['write' => false, 'width' => 1600, 'height' => 1200]);
        self::assertFileDoesNotExist($this->uploads . '/photo-110.jpeg');
        $scalerCalls = 0;
        add_filter('alt_text_fill_scaler', static function ($s) use (&$scalerCalls) {
            $scalerCalls++;

            return $s;
        });

        $report = $this->c->scanner->scan(['after_id' => 0]);

        self::assertCount(1, $report->candidates);
        self::assertArrayNotHasKey(110, $report->reasons);
        self::assertSame(0, $scalerCalls);
        self::assertSame([], \WP::$requests);
    }

    public function testT12MissingFileThatCannotBeFetchedIsSkippedUntilNextFullScan(): void
    {
        $this->attachment(120, ['write' => false]);
        \WP::$http = static fn (): array => ['response' => ['code' => 404], 'body' => '', 'headers' => []];

        $report = $this->runReal();

        self::assertSame(1, $report->skipped['file missing']);
        self::assertSame('file missing', $this->c->scanner->evaluate(120));
        $this->c->state->update(['generation' => $this->c->state->all()['generation'] + 1]);
        self::assertInstanceOf(Candidate::class, $this->c->scanner->evaluate(120));
    }
}
