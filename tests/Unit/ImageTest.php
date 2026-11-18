<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Adapter\FakeAdapter;
use IndianOpinion\AltTextFill\Image\ImagePreparer;
use IndianOpinion\AltTextFill\Image\MimeSniffer;
use IndianOpinion\AltTextFill\Model\Candidate;
use IndianOpinion\AltTextFill\Scanner;
use IndianOpinion\AltTextFill\Tests\TestCase;

/**
 * PRD 4.3, preparing the image, with rulings 2 and 5.
 */
final class ImageTest extends TestCase
{
    private function candidate(int $id): Candidate
    {
        $c = $this->c->scanner->evaluate($id, true);
        self::assertInstanceOf(Candidate::class, $c);

        return $c;
    }

    /**
     * @return array{input: ?\IndianOpinion\AltTextFill\Model\ImageInput, reason: string, size: string, bytes: int, width: int, height: int, mime: string}
     */
    private function prepare(int $id, ?FakeAdapter $adapter = null): array
    {
        return $this->c->preparer->prepare($this->candidate($id), ($adapter ?? $this->fake)->capabilities());
    }

    private function noScaler(): void
    {
        add_filter('alt_text_fill_scaler', static fn () => null);
        $this->rebuild();
    }

    public function testT21SmallestSizeAtOrOverTargetIsChosen(): void
    {
        $this->attachment(10, ['width' => 3000, 'height' => 2000, 'sizes' => [
            'medium' => [300, 200, 'image/jpeg'],
            'medium_large' => [768, 512, 'image/jpeg'],
            'large' => [1024, 683, 'image/jpeg'],
            '1536x1536' => [1536, 1024, 'image/jpeg'],
        ]]);

        $prep = $this->prepare(10);

        self::assertSame('large', $prep['size']);
        self::assertSame(1024, $prep['input']?->width);
        self::assertFalse($prep['input']?->scaled);
    }

    public function testT21LargestIsChosenWhenNothingReachesTheTarget(): void
    {
        $this->attachment(11, ['width' => 900, 'height' => 600, 'sizes' => ['medium' => [300, 200, 'image/jpeg']]]);

        self::assertSame('full', $this->prepare(11)['size']);
    }

    public function testT22OversizedImageIsScaledInMemoryAndNeverSaved(): void
    {
        $this->attachment(20, ['width' => 2000, 'height' => 1500, 'bytes' => $this->image(2000, 1500, 'jpeg', true)]);
        $before = scandir($this->uploads);
        $original = (string) file_get_contents($this->uploads . '/photo-20.jpeg');
        $this->settings(['byte_limit' => 2 * 1024 * 1024]);
        self::assertGreaterThan(2 * 1024 * 1024, strlen($original));

        $prep = $this->prepare(20);

        self::assertNotNull($prep['input']);
        self::assertTrue($prep['input']->scaled);
        self::assertSame(1024, max($prep['input']->width, $prep['input']->height));
        self::assertLessThanOrEqual(2 * 1024 * 1024, $prep['input']->byteCount);
        self::assertSame('image/jpeg', $prep['input']->mimeType);
        self::assertSame($before, scandir($this->uploads));
        self::assertSame($original, file_get_contents($this->uploads . '/photo-20.jpeg'));
    }

    public function testT22WithoutAnEditorOversizedIsSkippedAndSmallIsSentAsIs(): void
    {
        $this->noScaler();
        $this->settings(['byte_limit' => 2 * 1024 * 1024]);
        $this->attachment(21, ['width' => 2000, 'height' => 1500, 'bytes' => $this->image(2000, 1500, 'jpeg', true)]);
        $this->attachment(22, ['width' => 2000, 'height' => 1500]);

        self::assertSame(ImagePreparer::SKIP_TOO_LARGE, $this->prepare(21)['reason']);
        $small = $this->prepare(22);
        self::assertNotNull($small['input']);
        self::assertFalse($small['input']->scaled);
        self::assertSame(2000, $small['input']->width);
    }

    public function testT22DecompressionBombIsNeverDecoded(): void
    {
        // A valid PNG header that declares a 30,000 x 30,000 canvas in a few bytes.
        $ihdr = pack('NNCCCCC', 30000, 30000, 8, 6, 0, 0, 0);
        $bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . str_repeat("\0", 64);
        $this->attachment(23, ['width' => 30000, 'height' => 30000, 'bytes' => $bomb, 'mime' => 'image/png']);
        $spy = new class () implements \IndianOpinion\AltTextFill\Image\Scaler {
            public int $calls = 0;

            public function name(): string
            {
                return 'spy';
            }

            public function scale(string $bytes, int $longSide): ?array
            {
                $this->calls++;

                return null;
            }
        };
        add_filter('alt_text_fill_scaler', static fn () => $spy);

        self::assertSame(ImagePreparer::SKIP_TOO_LARGE, $this->prepare(23)['reason']);
        self::assertSame(0, $spy->calls);
    }

    public function testT24ProtocolRelativeRedirectIsCheckedAgainstTheHostList(): void
    {
        $hops = [];
        \WP::$http = static function (string $method, string $url) use (&$hops): array {
            $hops[] = $url;

            return ['response' => ['code' => 302], 'headers' => ['location' => '//internal.example/secret.jpg'], 'body' => ''];
        };
        $fetcher = new \IndianOpinion\AltTextFill\Image\RemoteFetcher();
        $result = $fetcher->fetch(\WP::$uploadsBase . '/x.jpg', 1000);

        self::assertFalse($result['ok']);
        self::assertSame('host not allowed', $result['reason']);
        self::assertCount(1, $hops);
    }

    public function testT23Base64ByDefault(): void
    {
        $this->attachment(30, ['width' => 800, 'height' => 600]);

        $input = $this->prepare(30)['input'];

        self::assertNotNull($input);
        self::assertFalse($input->isUrl());
        self::assertStringStartsWith('data:image/jpeg;base64,', $input->dataUri());
        self::assertSame([], \WP::$requests);
    }

    public function testT23UrlModeNeedsAdapterSupportAndA200Head(): void
    {
        $this->settings(['send_as' => 'url']);
        $this->attachment(31, ['width' => 800, 'height' => 600]);
        $urlAdapter = new FakeAdapter(['accepts_url' => true]);
        $status = 200;
        \WP::$http = static function (string $method) use (&$status): array {
            return ['response' => ['code' => $method === 'HEAD' ? $status : 500], 'body' => '', 'headers' => []];
        };

        self::assertFalse($this->prepare(31)['input']?->isUrl());
        $input = $this->prepare(31, $urlAdapter)['input'];
        self::assertTrue($input?->isUrl());
        self::assertSame('https://example.org/wp-content/uploads/photo-31.jpeg', $input?->url);

        $status = 404;
        self::assertFalse($this->prepare(31, $urlAdapter)['input']?->isUrl());
        self::assertFalse((new \IndianOpinion\AltTextFill\Adapter\OpenAiCompatibleAdapter())->capabilities()['accepts_url']);
    }

    public function testT24OffloadedFileFetchedFromAllowedHostsOnly(): void
    {
        $bytes = $this->image(800, 600);
        $this->attachment(40, ['width' => 800, 'height' => 600, 'write' => false]);
        $this->settings(['remote_hosts' => ['cdn.example.net']]);
        \WP::$http = static function (string $method, string $url) use ($bytes): array {
            return match ($url) {
                'https://example.org/wp-content/uploads/photo-40.jpeg' => ['response' => ['code' => 301], 'body' => '', 'headers' => ['location' => 'https://cdn.example.net/photo-40.jpeg']],
                'https://cdn.example.net/photo-40.jpeg' => ['response' => ['code' => 200], 'body' => $bytes, 'headers' => []],
                default => ['response' => ['code' => 404], 'body' => '', 'headers' => []],
            };
        };

        $prep = $this->prepare(40);
        self::assertNotNull($prep['input']);
        self::assertSame(strlen($bytes), $prep['input']->byteCount);
        self::assertSame(10, \WP::$requests[0]['args']['timeout']);
        self::assertSame(0, \WP::$requests[0]['args']['redirection']);

        $this->settings(['remote_hosts' => []]);
        \WP::$requests = [];
        self::assertSame(ImagePreparer::SKIP_MISSING, $this->prepare(40)['reason']);
        self::assertCount(1, \WP::$requests, 'the disallowed redirect target is never requested');
    }

    public function testT24RedirectsAreCappedAndTheByteLimitIsEnforced(): void
    {
        $this->attachment(41, ['width' => 800, 'height' => 600, 'write' => false]);
        \WP::$http = static fn (string $m, string $url): array => ['response' => ['code' => 302], 'body' => '', 'headers' => ['location' => $url . 'x']];
        self::assertSame(ImagePreparer::SKIP_MISSING, $this->prepare(41)['reason']);
        self::assertCount(4, \WP::$requests);

        $this->noScaler();
        $this->settings(['byte_limit' => 65536]);
        \WP::$requests = [];
        \WP::$http = static fn (): array => ['response' => ['code' => 200], 'body' => str_repeat('x', 70000), 'headers' => []];
        self::assertSame(ImagePreparer::SKIP_TOO_LARGE, $this->prepare(41)['reason']);
        self::assertSame(65537, \WP::$requests[0]['args']['limit_response_size']);
    }

    public function testT24InternalHostsCannotBeReached(): void
    {
        $fetcher = new \IndianOpinion\AltTextFill\Image\RemoteFetcher([]);
        self::assertFalse($fetcher->isAllowed('http://169.254.169.254/latest/meta-data'));
        self::assertFalse($fetcher->isAllowed('file:///etc/passwd'));
        self::assertTrue($fetcher->isAllowed('https://example.org/wp-content/uploads/a.jpg'));
    }

    public function testT25RealTypeComesFromTheBytes(): void
    {
        $this->attachment(50, ['mime' => 'image/jpeg', 'file' => 'actually-png.jpg', 'width' => 800, 'height' => 600, 'bytes' => $this->image(800, 600, 'png')]);
        $this->attachment(51, ['mime' => 'image/jpeg', 'file' => 'bitmap.jpg', 'width' => 800, 'height' => 600, 'bytes' => "BM" . str_repeat("\0", 200)]);

        self::assertSame('image/png', $this->prepare(50)['input']?->mimeType);
        self::assertSame(Scanner::SKIP_UNSUPPORTED, $this->prepare(51)['reason']);
        self::assertSame('image/svg+xml', MimeSniffer::detect('<?xml version="1.0"?><svg xmlns="x"></svg>'));
        self::assertSame('image/avif', MimeSniffer::detect("\0\0\0\x1cftypavif" . str_repeat("\0", 20)));
    }

    public function testT26AnimatedGifIsSkippedWithALoggedReason(): void
    {
        $this->attachment(60, ['mime' => 'image/gif', 'width' => 400, 'height' => 400, 'bytes' => $this->animatedGif()]);
        $this->attachment(61, ['mime' => 'image/gif', 'width' => 400, 'height' => 400]);
        $logged = [];
        add_action('alt_text_fill_log', static function (string $level, string $message) use (&$logged): void {
            $logged[] = $message;
        }, 10, 2);

        self::assertSame(ImagePreparer::SKIP_ANIMATED, $this->prepare(60)['reason']);
        self::assertStringContainsString('animated', implode(' ', $logged));
        self::assertNotNull($this->prepare(61)['input']);
        self::assertTrue(MimeSniffer::isAnimated($this->animatedGif(), 'image/gif'));
    }

    public function testT26AnimatedWebpIsSkipped(): void
    {
        $vp8x = 'RIFF' . pack('V', 30) . 'WEBP' . 'VP8X' . pack('V', 10) . "\x02" . str_repeat("\0", 9) . 'ANIM' . pack('V', 6) . str_repeat("\0", 6);
        $this->attachment(62, ['mime' => 'image/webp', 'width' => 400, 'height' => 400, 'bytes' => $vp8x]);

        self::assertSame(ImagePreparer::SKIP_ANIMATED, $this->prepare(62)['reason']);
        self::assertFalse(MimeSniffer::isAnimated($this->image(50, 50, 'webp'), 'image/webp'));
    }
}
