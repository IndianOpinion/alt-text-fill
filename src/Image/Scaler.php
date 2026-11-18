<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Image;

/**
 * Makes an in-memory JPEG copy with the longer side at a target. Nothing is
 * written to the media library or the uploads directory.
 */
interface Scaler
{
    public function name(): string;

    /**
     * @return array{bytes: string, width: int, height: int}|null Null when the image cannot be read.
     */
    public function scale(string $bytes, int $longSide): ?array;
}
