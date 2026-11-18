<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

/**
 * A short hash of an attachment's file, used to spot replaced media (case 69)
 * and as part of the gen-queue idempotency key (case 65). A local file is
 * hashed by its bytes; offloaded media by its URL and stored metadata.
 */
final class Fingerprint
{
    public static function of(int $attachmentId): string
    {
        $path = (string) get_attached_file($attachmentId);
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $hash = sha1_file($path);
            if (is_string($hash)) {
                return 'f:' . $hash;
            }
        }
        $meta = wp_get_attachment_metadata($attachmentId);
        $meta = is_array($meta) ? $meta : [];
        $basis = [
            (string) wp_get_attachment_url($attachmentId),
            (string) ($meta['file'] ?? ''),
            (int) ($meta['width'] ?? 0),
            (int) ($meta['height'] ?? 0),
            (int) ($meta['filesize'] ?? 0),
        ];

        return 'm:' . sha1((string) wp_json_encode($basis));
    }
}
