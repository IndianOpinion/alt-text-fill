<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests;

use IndianOpinion\AltTextFill\Adapter\FakeAdapter;
use IndianOpinion\AltTextFill\Container;
use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Redactor;
use IndianOpinion\AltTextFill\Settings;
use IndianOpinion\AltTextFill\Storage\MemoryRepository;
use IndianOpinion\AltTextFill\Tests\Support\FakeMediaLibrary;
use IndianOpinion\AltTextFill\Tests\Support\FrozenClock;
use IndianOpinion\AltTextFill\Tests\Support\RecordingLogger;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Container $c;
    protected FrozenClock $clock;
    protected MemoryRepository $repo;
    protected FakeMediaLibrary $library;
    protected RecordingLogger $logger;
    protected FakeAdapter $fake;
    protected string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        \WP::reset();
        \WP_CLI::reset();
        Redactor::reset();
        $this->uploads = sys_get_temp_dir() . '/alt-text-fill-tests-' . bin2hex(random_bytes(4));
        mkdir($this->uploads, 0777, true);
        \WP::$uploadsDir = $this->uploads;
        $this->clock = new FrozenClock();
        \WP::$now = $this->clock->now;
        $this->repo = new MemoryRepository();
        $this->library = new FakeMediaLibrary();
        $this->logger = new RecordingLogger();
        $this->fake = new FakeAdapter();
        $fake = $this->fake;
        add_filter('alt_text_fill_adapters', static function (array $adapters) use ($fake): array {
            $adapters['fake'] = $fake;

            return $adapters;
        });
        $this->settings(['adapter' => 'fake', 'dry_run_only' => false]);
    }

    protected function tearDown(): void
    {
        Plugin::setContainer(null);
        $this->rmdir($this->uploads);
        parent::tearDown();
    }

    /**
     * Stores settings (merged over the defaults) and rebuilds the container.
     *
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): void
    {
        $current = \WP::$options[Settings::OPTION] ?? Settings::defaults();
        \WP::$options[Settings::OPTION] = Settings::sanitize(array_merge(is_array($current) ? $current : Settings::defaults(), $values));
        $this->rebuild();
    }

    protected function rebuild(): void
    {
        $this->c = new Container($this->clock, $this->repo, $this->library, $this->logger);
        Plugin::setContainer($this->c);
    }

    /** @var array<string, string> */
    private static array $images = [];

    protected function image(int $w, int $h, string $type = 'jpeg', bool $noisy = false): string
    {
        $key = $w . 'x' . $h . $type . ($noisy ? 'n' : '');
        if (isset(self::$images[$key])) {
            return self::$images[$key];
        }
        $img = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($img, 120, 160, 200);
        imagefill($img, 0, 0, (int) $color);
        if ($noisy) {
            mt_srand(7);
            for ($i = 0; $i < $w * $h / 2; $i++) {
                imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), (int) imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        match ($type) {
            'png' => imagepng($img),
            'gif' => imagegif($img),
            'webp' => imagewebp($img),
            default => imagejpeg($img, null, 90),
        };

        return self::$images[$key] = (string) ob_get_clean();
    }

    /** A two-frame animated GIF, built by hand. */
    protected function animatedGif(): string
    {
        $header = "GIF89a\x02\x00\x02\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff";
        $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00" . "\x02\x02\x44\x01\x00";

        return $header . $loop . $frame . $frame . "\x3B";
    }

    /**
     * Creates an attachment with a real file unless 'write' is false.
     *
     * @param array<string, mixed> $o mime, file, width, height, parent, alt (null: absent), sizes, bytes, write, date, caption, description, type
     */
    protected function attachment(int $id, array $o = []): int
    {
        $mime = (string) ($o['mime'] ?? 'image/jpeg');
        $width = (int) ($o['width'] ?? 1600);
        $height = (int) ($o['height'] ?? 1200);
        $file = (string) ($o['file'] ?? 'photo-' . $id . '.' . (explode('/', $mime)[1] ?? 'jpg'));
        \WP::$posts[$id] = new \WP_Post([
            'ID' => $id,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => $mime,
            'post_parent' => (int) ($o['parent'] ?? 0),
            'post_date' => (string) ($o['date'] ?? '2026-01-01 00:00:00'),
            'post_excerpt' => (string) ($o['caption'] ?? ''),
            'post_content' => (string) ($o['description'] ?? ''),
            'post_title' => (string) ($o['title'] ?? 'photo'),
        ]);
        \WP::$meta[$id]['_wp_attached_file'] = [$file];
        $sizes = [];
        foreach ((array) ($o['sizes'] ?? []) as $name => [$sw, $sh, $smime]) {
            $sfile = pathinfo($file, PATHINFO_FILENAME) . '-' . $sw . 'x' . $sh . '.' . ($smime === 'image/jpeg' ? 'jpg' : (explode('/', $smime)[1] ?? 'jpg'));
            $sizes[$name] = ['file' => $sfile, 'width' => $sw, 'height' => $sh, 'mime-type' => $smime];
            if (($o['write'] ?? true) && in_array($smime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                file_put_contents($this->uploads . '/' . $sfile, $this->image($sw, $sh, explode('/', $smime)[1]));
            }
        }
        \WP::$meta[$id]['_wp_attachment_metadata'] = [['width' => $width, 'height' => $height, 'file' => $file, 'sizes' => $sizes]];
        if (array_key_exists('alt', $o) && $o['alt'] !== null) {
            \WP::$meta[$id]['_wp_attachment_image_alt'] = [(string) $o['alt']];
        }
        if ($o['write'] ?? true) {
            $bytes = $o['bytes'] ?? null;
            if ($bytes === null) {
                $type = explode('/', $mime)[1] ?? 'jpeg';
                $bytes = in_array($type, ['jpeg', 'png', 'gif', 'webp'], true) ? $this->image(min($width, 2000), min($height, 2000), $type) : 'not an image';
            }
            file_put_contents($this->uploads . '/' . $file, (string) $bytes);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $o status, content, title, date, thumbnail, password, excerpt, type
     */
    protected function post(int $id, array $o = []): int
    {
        \WP::$posts[$id] = new \WP_Post([
            'ID' => $id,
            'post_type' => (string) ($o['type'] ?? 'post'),
            'post_status' => (string) ($o['status'] ?? 'publish'),
            'post_title' => (string) ($o['title'] ?? 'Post ' . $id),
            'post_content' => (string) ($o['content'] ?? ''),
            'post_excerpt' => (string) ($o['excerpt'] ?? ''),
            'post_password' => (string) ($o['password'] ?? ''),
            'post_date' => (string) ($o['date'] ?? '2026-02-01 00:00:00'),
        ]);
        if (isset($o['thumbnail'])) {
            \WP::$meta[$id]['_thumbnail_id'] = [(string) $o['thumbnail']];
        }

        return $id;
    }

    protected function alt(int $id): ?string
    {
        return $this->c->field->read($id);
    }

    protected function answer(string $alt, array $extra = []): string
    {
        return (string) json_encode(array_merge([
            'alt' => $alt,
            'decorative' => false,
            'contains_text' => false,
            'names_person' => false,
            'matches_context' => true,
            'confidence' => 0.9,
        ], $extra));
    }

    /** Runs a real pass with the fake adapter and returns the report. */
    protected function runReal(array $args = []): \IndianOpinion\AltTextFill\Model\RunReport
    {
        return $this->c->runner->run(array_merge(['trigger' => 'cli'], $args));
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                $p = $dir . '/' . $f;
                is_dir($p) ? $this->rmdir($p) : unlink($p);
            }
        }
        rmdir($dir);
    }
}
