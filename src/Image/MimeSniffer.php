<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Image;

/**
 * Reads the real type from the file bytes (case 26) and spots animation.
 */
final class MimeSniffer
{
    public static function detect(string $bytes): ?string
    {
        $head = substr($bytes, 0, 32);
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if (in_array($brand, ['avif', 'avis'], true)) {
                return 'image/avif';
            }
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true)) {
                return 'image/heic';
            }
        }
        if (str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")) {
            return 'image/tiff';
        }
        if (str_starts_with($head, 'BM')) {
            return 'image/bmp';
        }
        if (str_starts_with($head, "\x00\x00\x01\x00")) {
            return 'image/x-icon';
        }
        $text = ltrim(substr($bytes, 0, 512));
        if (preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE svg[^>]*>\s*)?<svg\b/is', $text) === 1) {
            return 'image/svg+xml';
        }

        return null;
    }

    public static function isAnimated(string $bytes, string $mime): bool
    {
        if ($mime === 'image/gif') {
            return self::gifFrames($bytes) > 1;
        }
        if ($mime === 'image/webp') {
            if (substr($bytes, 12, 4) === 'VP8X' && (ord($bytes[20] ?? "\0") & 0x02) === 0x02) {
                return true;
            }

            return str_contains(substr($bytes, 0, 4096), 'ANIM');
        }
        if ($mime === 'image/png') {
            $idat = strpos($bytes, 'IDAT');
            $actl = strpos($bytes, 'acTL');

            return $actl !== false && ($idat === false || $actl < $idat);
        }

        return false;
    }

    /** Counts image descriptors in a GIF stream, stopping at two. */
    private static function gifFrames(string $bytes): int
    {
        $len = strlen($bytes);
        if ($len < 13) {
            return 0;
        }
        $pos = 13;
        $flags = ord($bytes[10]);
        if (($flags & 0x80) !== 0) {
            $pos += 3 * (2 ** (($flags & 0x07) + 1));
        }
        $frames = 0;
        while ($pos < $len) {
            $block = ord($bytes[$pos]);
            if ($block === 0x3B) {
                break;
            }
            if ($block === 0x21) {
                $pos += 2;
                $pos = self::skipSubBlocks($bytes, $pos, $len);
                continue;
            }
            if ($block !== 0x2C) {
                break;
            }
            $frames++;
            if ($frames > 1) {
                return $frames;
            }
            if ($pos + 10 > $len) {
                break;
            }
            $local = ord($bytes[$pos + 9]);
            $pos += 10;
            if (($local & 0x80) !== 0) {
                $pos += 3 * (2 ** (($local & 0x07) + 1));
            }
            $pos += 1;
            $pos = self::skipSubBlocks($bytes, $pos, $len);
        }

        return $frames;
    }

    private static function skipSubBlocks(string $bytes, int $pos, int $len): int
    {
        while ($pos < $len) {
            $size = ord($bytes[$pos]);
            $pos++;
            if ($size === 0) {
                break;
            }
            $pos += $size;
        }

        return $pos;
    }
}
