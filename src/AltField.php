<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * Reads and writes the attachment's own alt field and this plugin's
 * attachment meta. Nothing else about an attachment is ever written.
 */
class AltField
{
    public const META = '_wp_attachment_image_alt';
    public const DECORATIVE = '_alt_text_fill_decorative';
    public const STATE = '_alt_text_fill_state';

    /** @return list<string> */
    public static function ownMetaKeys(): array
    {
        return [self::DECORATIVE, self::STATE];
    }

    public function exists(int $attachmentId): bool
    {
        return metadata_exists('post', $attachmentId, self::META);
    }

    /** The exact stored value, or null when the meta row is absent. */
    public function read(int $attachmentId): ?string
    {
        if (!$this->exists($attachmentId)) {
            return null;
        }
        $value = get_post_meta($attachmentId, self::META, true);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Sets the exact value (null deletes the row), then reads it back.
     * Returns false when the stored value is not what was asked for.
     */
    public function write(int $attachmentId, ?string $value): bool
    {
        if ($value === null) {
            delete_post_meta($attachmentId, self::META);
        } elseif ($this->exists($attachmentId)) {
            update_post_meta($attachmentId, self::META, wp_slash($value));
        } else {
            add_post_meta($attachmentId, self::META, wp_slash($value), true);
        }
        wp_cache_delete($attachmentId, 'post_meta');

        return $this->read($attachmentId) === $value;
    }

    public function isDecorative(int $attachmentId): bool
    {
        return (string) get_post_meta($attachmentId, self::DECORATIVE, true) === '1';
    }

    public function setDecorative(int $attachmentId, bool $on): bool
    {
        if ($on) {
            update_post_meta($attachmentId, self::DECORATIVE, '1');
        } else {
            delete_post_meta($attachmentId, self::DECORATIVE);
        }

        return $this->isDecorative($attachmentId) === $on;
    }

    /**
     * Candidate state: rejected (answer) count, parked reason, hold flag,
     * last tried, skip reason and the scan generation it applies to.
     *
     * @return array{rejected: int, parked: string, hold: bool, last_tried: int, skip: string, skip_gen: int}
     */
    public function state(int $attachmentId): array
    {
        $raw = get_post_meta($attachmentId, self::STATE, true);
        $raw = is_array($raw) ? $raw : [];

        return [
            'rejected' => (int) ($raw['rejected'] ?? 0),
            'parked' => (string) ($raw['parked'] ?? ''),
            'hold' => (bool) ($raw['hold'] ?? false),
            'last_tried' => (int) ($raw['last_tried'] ?? 0),
            'skip' => (string) ($raw['skip'] ?? ''),
            'skip_gen' => (int) ($raw['skip_gen'] ?? -1),
        ];
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function updateState(int $attachmentId, array $changes): void
    {
        $state = array_merge($this->state($attachmentId), $changes);
        update_post_meta($attachmentId, self::STATE, $state);
    }

    /** "Try again": clears the hold, the parked reason and the rejected count. */
    public function release(int $attachmentId): void
    {
        $this->updateState($attachmentId, ['hold' => false, 'parked' => '', 'rejected' => 0, 'skip' => '', 'skip_gen' => -1]);
    }
}
