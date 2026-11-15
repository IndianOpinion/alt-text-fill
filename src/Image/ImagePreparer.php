<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Image;

use IndianOpinion\AltTextFill\Logger;
use IndianOpinion\AltTextFill\Model\Candidate;
use IndianOpinion\AltTextFill\Model\ImageInput;
use IndianOpinion\AltTextFill\Scanner;
use IndianOpinion\AltTextFill\Settings;

/**
 * Chooses, loads, checks and (when needed) scales the image for one call
 * (PRD 4.3 with rulings 2 and 5).
 */
class ImagePreparer
{
    public const SKIP_MISSING = 'file missing';
    public const SKIP_TOO_LARGE = 'too large';
    public const SKIP_ANIMATED = 'animated image';

    private ?Scaler $scaler = null;
    private bool $scalerResolved = false;

    public function __construct(private Settings $settings, private RemoteFetcher $fetcher, private Logger $logger)
    {
    }

    public function scaler(): ?Scaler
    {
        if (!$this->scalerResolved) {
            $default = ImagickScaler::available() ? new ImagickScaler() : (GdScaler::available() ? new GdScaler() : null);
            $filtered = apply_filters('alt_text_fill_scaler', $default);
            $this->scaler = $filtered instanceof Scaler ? $filtered : null;
            $this->scalerResolved = true;
        }

        return $this->scaler;
    }

    /**
     * @param array{mime_types: list<string>, accepts_url: bool, accepts_animation: bool, max_bytes: int} $caps
     * @return array{input: ?ImageInput, reason: string, size: string, bytes: int, width: int, height: int, mime: string}
     */
    public function prepare(Candidate $candidate, array $caps): array
    {
        $id = $candidate->attachmentId;
        $out = ['input' => null, 'reason' => '', 'size' => '', 'bytes' => 0, 'width' => 0, 'height' => 0, 'mime' => $candidate->mimeType];
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : [];
        $target = $this->settings->int('target_side');
        $limit = min($this->settings->int('byte_limit'), $caps['max_bytes'] > 0 ? $caps['max_bytes'] : PHP_INT_MAX);

        $jpegOnly = in_array($candidate->mimeType, Scanner::CONVERTIBLE, true) && !in_array($candidate->mimeType, $caps['mime_types'], true);
        $size = self::chooseSize($meta, $candidate, $target, $jpegOnly);
        if ($size === null) {
            return array_merge($out, ['reason' => Scanner::SKIP_UNSUPPORTED]);
        }
        $out['size'] = $size['name'];
        $out['width'] = $size['width'];
        $out['height'] = $size['height'];

        $path = (string) get_attached_file($id);
        $url = (string) wp_get_attachment_url($id);
        $localPath = $size['name'] === 'full' ? $path : dirname($path) . '/' . $size['file'];
        $remoteUrl = $size['name'] === 'full' ? $url : substr($url, 0, (int) strrpos($url, '/') + 1) . $size['file'];

        if ($this->settings->string('send_as') === 'url' && $caps['accepts_url'] && $remoteUrl !== '' && $this->fetcher->headOk($remoteUrl)) {
            $bytes = (int) ($size['filesize'] ?? 0);
            $input = new ImageInput(null, $remoteUrl, $size['mime'], $size['width'], $size['height'], $bytes, $size['name']);

            return array_merge($out, ['input' => $input, 'bytes' => $bytes, 'mime' => $size['mime']]);
        }

        $scaler = $this->scaler();
        $readCap = $scaler !== null ? $limit * 4 : $limit;
        $bytes = null;
        if ($localPath !== '' && is_file($localPath) && is_readable($localPath)) {
            $fileSize = (int) filesize($localPath);
            if ($fileSize > $readCap) {
                return array_merge($out, ['reason' => self::SKIP_TOO_LARGE, 'bytes' => $fileSize]);
            }
            $read = file_get_contents($localPath);
            $bytes = is_string($read) ? $read : null;
        }
        if ($bytes === null) {
            if ($remoteUrl === '' || !$this->fetcher->isAllowed($remoteUrl)) {
                return array_merge($out, ['reason' => self::SKIP_MISSING]);
            }
            $fetched = $this->fetcher->fetch($remoteUrl, $readCap);
            if (!$fetched['ok']) {
                $reason = $fetched['reason'] === 'too large' ? self::SKIP_TOO_LARGE : self::SKIP_MISSING;

                return array_merge($out, ['reason' => $reason]);
            }
            $bytes = $fetched['bytes'];
        }

        $real = MimeSniffer::detect($bytes);
        if ($real === null || $real === 'image/svg+xml') {
            return array_merge($out, ['reason' => Scanner::SKIP_UNSUPPORTED, 'bytes' => strlen($bytes)]);
        }
        $out['mime'] = $real;
        if (($real === 'image/gif' || $real === 'image/webp') && MimeSniffer::isAnimated($bytes, $real)) {
            $this->logger->info('Skipped an animated image; 0.1.0 does not send animated GIF or WebP.', ['attachment_id' => $id]);

            return array_merge($out, ['reason' => self::SKIP_ANIMATED, 'bytes' => strlen($bytes)]);
        }
        $dims = getimagesizefromstring($bytes);
        if (is_array($dims) && $dims[0] > 0) {
            $out['width'] = (int) $dims[0];
            $out['height'] = (int) $dims[1];
        }
        $scaled = false;
        $long = max($out['width'], $out['height']);
        $needsScale = $long > $target || strlen($bytes) > $limit;
        if ($needsScale && $scaler !== null) {
            $result = $scaler->scale($bytes, min($target, $long > 0 ? $long : $target));
            if ($result !== null) {
                $bytes = $result['bytes'];
                $out['width'] = $result['width'];
                $out['height'] = $result['height'];
                $real = 'image/jpeg';
                $scaled = true;
            }
        }
        if (!in_array($real, $caps['mime_types'], true)) {
            return array_merge($out, ['reason' => Scanner::SKIP_UNSUPPORTED, 'bytes' => strlen($bytes), 'mime' => $real]);
        }
        if (strlen($bytes) > $limit) {
            return array_merge($out, ['reason' => self::SKIP_TOO_LARGE, 'bytes' => strlen($bytes), 'mime' => $real]);
        }
        $input = new ImageInput($bytes, null, $real, $out['width'], $out['height'], strlen($bytes), $size['name'], $scaled);

        return array_merge($out, ['input' => $input, 'bytes' => strlen($bytes), 'mime' => $real]);
    }

    /**
     * The smallest size whose longer side reaches the target, else the
     * largest (case 22). With $jpegOnly, only JPEG intermediate sizes (case 3).
     *
     * @param array<string, mixed> $meta
     * @return array{name: string, file: string, width: int, height: int, mime: string, filesize: int}|null
     */
    public static function chooseSize(array $meta, Candidate $candidate, int $target, bool $jpegOnly): ?array
    {
        $options = [];
        if (!$jpegOnly) {
            $options[] = [
                'name' => 'full',
                'file' => basename((string) ($meta['file'] ?? '')),
                'width' => (int) ($meta['width'] ?? $candidate->width),
                'height' => (int) ($meta['height'] ?? $candidate->height),
                'mime' => $candidate->mimeType,
                'filesize' => (int) ($meta['filesize'] ?? 0),
            ];
        }
        foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
            if (!is_array($size) || !isset($size['file'])) {
                continue;
            }
            $mime = (string) ($size['mime-type'] ?? $candidate->mimeType);
            if ($jpegOnly && $mime !== 'image/jpeg') {
                continue;
            }
            $options[] = [
                'name' => (string) $name,
                'file' => (string) $size['file'],
                'width' => (int) ($size['width'] ?? 0),
                'height' => (int) ($size['height'] ?? 0),
                'mime' => $mime,
                'filesize' => (int) ($size['filesize'] ?? 0),
            ];
        }
        if ($options === []) {
            return null;
        }
        usort($options, static fn (array $a, array $b): int => max($a['width'], $a['height']) <=> max($b['width'], $b['height']));
        foreach ($options as $option) {
            if (max($option['width'], $option['height']) >= $target) {
                return $option;
            }
        }

        return end($options);
    }
}
