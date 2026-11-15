<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Image;

final class GdScaler implements Scaler
{
    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    public function name(): string
    {
        return 'gd';
    }

    public function scale(string $bytes, int $longSide): ?array
    {
        $size = getimagesizefromstring($bytes);
        if ($size === false) {
            return null;
        }
        $source = imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }
        [$w, $h] = [(int) $size[0], (int) $size[1]];
        $ratio = min(1.0, $longSide / max($w, $h, 1));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $target = imagecreatetruecolor($nw, $nh);
        if ($target === false) {
            return null;
        }
        $white = (int) imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            return null;
        }
        imagejpeg($target, $stream, 82);
        rewind($stream);
        $out = (string) stream_get_contents($stream);
        fclose($stream);

        return $out === '' ? null : ['bytes' => $out, 'width' => $nw, 'height' => $nh];
    }
}
