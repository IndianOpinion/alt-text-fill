<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Image;

final class ImagickScaler implements Scaler
{
    public static function available(): bool
    {
        return class_exists(\Imagick::class);
    }

    public function name(): string
    {
        return 'imagick';
    }

    public function scale(string $bytes, int $longSide): ?array
    {
        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image = $image->getImage();
            $w = $image->getImageWidth();
            $h = $image->getImageHeight();
            $ratio = min(1.0, $longSide / max($w, $h, 1));
            $nw = max(1, (int) round($w * $ratio));
            $nh = max(1, (int) round($h * $ratio));
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image->resizeImage($nw, $nh, \Imagick::FILTER_LANCZOS, 1);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(82);
            $image->stripImage();
            $out = $image->getImageBlob();
            $image->clear();
        } catch (\ImagickException $e) {
            return null;
        }

        return $out === '' ? null : ['bytes' => $out, 'width' => $nw, 'height' => $nh];
    }
}
