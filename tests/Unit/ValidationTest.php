<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\AnswerParser;
use IndianOpinion\AltTextFill\Exception\MalformedAnswerException;
use IndianOpinion\AltTextFill\Model\Answer;
use IndianOpinion\AltTextFill\Model\Candidate;
use IndianOpinion\AltTextFill\Model\Context;
use IndianOpinion\AltTextFill\Model\Suggestion;
use IndianOpinion\AltTextFill\Scanner;
use IndianOpinion\AltTextFill\Tests\TestCase;
use IndianOpinion\AltTextFill\Validator;

/**
 * PRD 4.5, validating answers.
 */
final class ValidationTest extends TestCase
{
    private function ctx(string $title = 'Monsoon floods close the coastal highway', bool $instructions = false): Context
    {
        return new Context('', 'en_GB', 100, false, $instructions, $title);
    }

    private function cand(bool $shared = false, bool $weak = false): Candidate
    {
        return new Candidate(10, 'image/jpeg', 800, 600, 100, $shared, [], $weak, $weak ? 'IMG_1' : '');
    }

    /**
     * @param array<string, mixed> $o
     */
    private function ans(string $alt, array $o = []): Answer
    {
        return new Answer($alt, $o['decorative'] ?? false, $o['contains_text'] ?? false, $o['names_person'] ?? false, $o['matches_context'] ?? true, $o['confidence'] ?? 0.9);
    }

    public function testT29AnswerWithAllFieldsMapsToASuggestion(): void
    {
        $this->post(100, ['title' => 'Town hall']);
        $this->attachment(10, ['parent' => 100, 'width' => 800, 'height' => 600]);
        $this->fake->push($this->answer('A crowd outside a stone building', ['contains_text' => true, 'names_person' => true, 'matches_context' => false, 'confidence' => 0.42]));

        $this->runReal();

        $s = $this->repo->findSuggestions()[0];
        self::assertSame(Suggestion::PENDING, $s->status);
        self::assertSame('A crowd outside a stone building', $s->text);
        self::assertEqualsWithDelta(0.42, $s->confidence, 0.0001);
        self::assertSame([Validator::FLAG_NAMES_PERSON, Validator::FLAG_CONTAINS_TEXT, Validator::FLAG_MISMATCH, Validator::FLAG_LOW_CONFIDENCE], $s->flags);
        self::assertSame('fake', $s->adapter);
        self::assertSame('fake-model', $s->model);
        self::assertSame(100, $s->contextPostId);
        self::assertSame(100, $s->inputTokens);
        self::assertStringStartsWith('f:', $s->fileHash);
        self::assertCount(1, \WP::firedArgs('alt_text_fill_suggestion_created'));
    }

    public function testT33JsonInAFenceOrAfterProseIsAccepted(): void
    {
        self::assertSame('A cat', AnswerParser::parse("```json\n{\"alt\":\"A cat\",\"confidence\":0.8}\n```")->alt);
        self::assertSame('A {curly} cat', AnswerParser::parse('Sure! Here it is: {"alt":"A {curly} cat"} hope that helps {"alt":"x"}')->alt);
        self::assertSame(0.8, AnswerParser::parse('{"alt":"A cat","confidence":"0.8","decorative":"false"}')->confidence);
        self::assertFalse(AnswerParser::parse('{"alt":"A cat","decorative":"false"}')->decorative);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformed(): array
    {
        return [
            'prose' => ['A cat sitting on a mat.'],
            'array' => ['["A cat"]'],
            'broken' => ['{"alt": "A cat"'],
            'alt not string' => ['{"alt": {"text": "A cat"}}'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider malformed
     */
    public function testT33OtherTextIsMalformed(string $raw): void
    {
        $this->expectException(MalformedAnswerException::class);
        AnswerParser::parse($raw);
    }

    public function testT33MalformedAnswerIsRecordedAsRejected(): void
    {
        $this->attachment(10, ['width' => 800, 'height' => 600]);
        $this->fake->push('I think this shows a cat.');

        $report = $this->runReal();

        self::assertSame(1, $report->count('rejected'));
        $s = $this->repo->findSuggestions(['status' => Suggestion::INVALID])[0];
        self::assertSame(Validator::R_MALFORMED, $s->rejectReason);
    }

    public function testT34Cleaning(): void
    {
        $v = $this->c->validator;
        self::assertSame('Dog on a beach', $v->clean('"An image of dog on a beach"'));
        self::assertSame('Red bus', $v->clean("  <b>A photo of</b>\u{200B} red\nbus\t "));
        self::assertSame('Fishing boats at dawn', $v->clean("\u{201C}Picture showing fishing boats at dawn\u{201D}"));
        self::assertSame('Two cranes', $v->clean("Two\x07 cranes"));
        self::assertSame('Ärzte am Bett', $v->clean('image of ärzte am Bett'));
        add_filter('alt_text_fill_openers', static fn (array $o): array => array_merge($o, ['fr' => ['une photo de']]));
        self::assertSame('Chat noir', $v->clean('Une photo de chat noir', 'fr_FR'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejections(): array
    {
        return [
            'empty' => ['', Validator::R_EMPTY],
            'too short' => ['Cat', Validator::R_SHORT],
            'refusal' => ["I'm sorry, I can't describe this image.", Validator::R_REFUSAL],
            'as an ai' => ['As an AI I cannot see people', Validator::R_REFUSAL],
            'url' => ['A map, see https://maps.example.com/x', Validator::R_LINK],
            'email' => ['Poster with contact desk@example.org', Validator::R_LINK],
            'file name' => ['Shot saved as harbour_final.jpg', Validator::R_LINK],
            'same as title' => ['Monsoon floods close the coastal highway!', Validator::R_TITLE],
            'near title' => ['Monsoon floods close coastal highway near town', Validator::R_TITLE],
            'copies file' => ['beach sunset 2026', Validator::R_FILE],
        ];
    }

    /**
     * @dataProvider rejections
     */
    public function testT35EachRejectionReasonFires(string $alt, string $reason): void
    {
        $result = $this->c->validator->validate($this->ans($alt), $this->ctx(), $this->cand(), '/up/beach-sunset_2026.jpg');

        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['reason']);
    }

    public function testT35TitleRuleOnlyForTitlesOfThreeOrMoreWords(): void
    {
        $v = $this->c->validator;
        self::assertTrue($v->validate($this->ans('Elephants crossing a river'), $this->ctx('Elephants'), $this->cand(), '')['ok']);
        self::assertTrue($v->validate($this->ans('A river with elephants crossing'), $this->ctx('River elephants'), $this->cand(), '')['ok']);
        self::assertSame(Validator::R_TITLE, $v->validate($this->ans('River elephants'), $this->ctx('River elephants'), $this->cand(), '')['reason']);
        self::assertTrue($v->validate($this->ans('Floods on a highway by the sea'), $this->ctx(), $this->cand(), '')['ok']);
    }

    public function testT35ValidateFilterCanRejectOrFlagButNotRewrite(): void
    {
        add_filter('alt_text_fill_validate', static fn (array $r): array => ['text' => 'REWRITTEN', 'flags' => ['house rule'], 'reason' => str_contains($r['text'], 'banned') ? 'house rule' : ''], 10, 1);
        $v = $this->c->validator;
        $ok = $v->validate($this->ans('A quiet street at night'), $this->ctx(), $this->cand(), '');
        self::assertTrue($ok['ok']);
        self::assertSame('A quiet street at night', $ok['text']);
        self::assertContains('house rule', $ok['flags']);
        $bad = $v->validate($this->ans('A banned word here'), $this->ctx(), $this->cand(), '');
        self::assertSame('house rule', $bad['reason']);
    }

    public function testT36OverLongTextIsTrimmedAtASentenceOrWordAndFlagged(): void
    {
        $v = $this->c->validator;
        $text = 'A wide view of a busy market street with stalls selling fruit. Shoppers walk between the stalls under bright awnings in the afternoon sun.';
        $r = $v->validate($this->ans($text), $this->ctx(''), $this->cand(), '');
        self::assertTrue($r['ok']);
        self::assertSame('A wide view of a busy market street with stalls selling fruit.', $r['text']);
        self::assertContains(Validator::FLAG_TRIMMED, $r['flags']);

        $long = str_repeat('word ', 40);
        $r = $v->validate($this->ans($long), $this->ctx(''), $this->cand(), '');
        self::assertLessThanOrEqual(125, mb_strlen($r['text']));
        self::assertStringEndsWith('word', $r['text']);

        $this->settings(['max_length' => 250]);
        $r = $this->c->validator->validate($this->ans($text), $this->ctx(''), $this->cand(), '');
        self::assertSame($text, $r['text']);
        self::assertNotContains(Validator::FLAG_TRIMMED, $r['flags']);

        $r = $this->c->validator->validate($this->ans(str_repeat('x', 300)), $this->ctx(''), $this->cand(), '');
        self::assertSame(Validator::R_LONG, $r['reason']);
    }

    public function testT37EachFlagShowsAndNoneRejects(): void
    {
        $r = $this->c->validator->validate(
            $this->ans(str_repeat('A long harbour view ', 10), ['names_person' => true, 'contains_text' => true, 'matches_context' => false, 'confidence' => 0.1]),
            $this->ctx('', true),
            $this->cand(true, true),
            ''
        );

        self::assertTrue($r['ok']);
        foreach ([Validator::FLAG_NAMES_PERSON, Validator::FLAG_CONTAINS_TEXT, Validator::FLAG_MISMATCH, Validator::FLAG_LOW_CONFIDENCE, Validator::FLAG_SHARED, Validator::FLAG_TRIMMED, Validator::FLAG_WEAK_ALT, Validator::FLAG_INSTRUCTIONS] as $flag) {
            self::assertContains($flag, $r['flags']);
        }
    }

    public function testT38TwoRejectedAnswersParkTheImage(): void
    {
        $this->attachment(10, ['width' => 800, 'height' => 600]);
        $this->fake->push($this->answer("I'm sorry, I cannot help"));
        $this->runReal();
        self::assertSame(1, $this->c->field->state(10)['rejected']);
        self::assertInstanceOf(Candidate::class, $this->c->scanner->evaluate(10));

        $this->fake->push($this->answer('img'));
        $report = $this->runReal();
        self::assertSame(1, $report->count('rejected'));
        self::assertSame(Scanner::SKIP_PARKED, $this->c->scanner->evaluate(10));
        self::assertSame(2, $this->repo->countSuggestions(['status' => Suggestion::INVALID, 'attachment_id' => 10]));
        self::assertSame(2, $this->c->state->today()['calls']);

        $this->runReal();
        self::assertCount(2, $this->fake->calls);
    }

    public function testT39DecorativeAnswerBecomesSuggestDecorative(): void
    {
        $this->attachment(10, ['width' => 800, 'height' => 600]);
        $this->fake->push($this->answer('', ['decorative' => true, 'confidence' => 0.95]));
        $this->runReal();

        $s = $this->repo->findSuggestions()[0];
        self::assertSame(Suggestion::KIND_DECORATIVE, $s->kind);
        self::assertContains(Validator::FLAG_DECORATIVE, $s->flags);

        $entry = $this->c->review->approve($s->id);
        self::assertTrue($this->c->field->isDecorative(10));
        self::assertSame('', $this->alt(10));
        self::assertSame('decorative', $entry->action);

        $this->attachment(11, ['width' => 800, 'height' => 600, 'alt' => '  ']);
        $this->fake->push($this->answer('', ['decorative' => true, 'confidence' => 0.95]));
        $this->runReal(['ids' => [11]]);
        $s2 = $this->repo->findSuggestions(['attachment_id' => 11])[0];
        $this->c->review->approve($s2->id);
        self::assertSame('  ', $this->alt(11), 'an existing value is left as it was');

        $low = $this->c->validator->validate($this->ans('', ['decorative' => true, 'confidence' => 0.3]), $this->ctx(), $this->cand(), '');
        self::assertFalse($low['ok']);
        self::assertSame(Validator::R_EMPTY, $low['reason']);
    }
}
