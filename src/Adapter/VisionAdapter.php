<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Adapter;

use IndianOpinion\AltTextFill\Exception\AdapterException;
use IndianOpinion\AltTextFill\Model\AdapterResponse;
use IndianOpinion\AltTextFill\Model\Context;
use IndianOpinion\AltTextFill\Model\ImageInput;
use IndianOpinion\AltTextFill\Model\Prompt;

/**
 * A vision-capable model behind its official, documented API (PRD 5.3).
 * Adapters do HTTP through the WordPress HTTP API only.
 */
interface VisionAdapter
{
    /** A slug matching ^[a-z0-9-]{1,40}$. */
    public function id(): string;

    public function label(): string;

    /**
     * @return array{mime_types: list<string>, accepts_url: bool, accepts_animation: bool, max_bytes: int}
     */
    public function capabilities(): array;

    /**
     * @return list<array{key: string, label: string, type: string, secret: bool, default: string|bool}>
     */
    public function settingsFields(): array;

    /**
     * @param array<string, mixed> $settings
     * @return list<string> Error messages; empty when the settings are usable.
     */
    public function validateSettings(array $settings): array;

    /**
     * @param array<string, mixed> $settings
     * @throws AdapterException
     */
    public function describe(ImageInput $image, Context $context, Prompt $prompt, array $settings): AdapterResponse;
}
