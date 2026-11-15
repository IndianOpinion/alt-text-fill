<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Model;

/**
 * The image an adapter receives: bytes (sent as base64) or a public URL.
 */
final class ImageInput
{
    public function __construct(
        public readonly ?string $bytes,
        public readonly ?string $url,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
        public readonly int $byteCount,
        public readonly string $sizeName = 'full',
        public readonly bool $scaled = false,
    ) {
    }

    public function isUrl(): bool
    {
        return $this->url !== null;
    }

    public function dataUri(): string
    {
        return 'data:' . $this->mimeType . ';base64,' . base64_encode((string) $this->bytes);
    }
}
