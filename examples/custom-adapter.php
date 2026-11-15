<?php

/**
 * Plugin Name: Alt Text Fill, custom adapter example
 * Description: Registers a vision adapter for an endpoint with its own request shape, and wires two site hooks.
 *
 * Copy this file into wp-content/mu-plugins/ and the alt-text-fill-example
 * folder beside it (a subfolder, so WordPress does not load the class file
 * on its own before Alt Text Fill is active), then adjust. It shows the interface; the
 * endpoint below is a placeholder and must be replaced by a real, documented API.
 */

declare(strict_types=1);

use IndianOpinion\AltTextFill\Adapter\VisionAdapter;

add_action('plugins_loaded', static function (): void {
    if (!interface_exists(VisionAdapter::class)) {
        return; // Alt Text Fill is not active.
    }

    require_once __DIR__ . '/alt-text-fill-example/ExampleVisionAdapter.php';

    add_filter('alt_text_fill_adapters', static function (array $adapters): array {
        $adapter = new \Example\AltTextFill\ExampleVisionAdapter();
        $adapters[$adapter->id()] = $adapter;

        return $adapters;
    });

    // A translation plugin tells the plugin each post's language.
    add_filter('alt_text_fill_post_language', static function (?string $language, int $postId): ?string {
        $lang = get_post_meta($postId, 'my_language_code', true);

        return is_string($lang) && $lang !== '' ? $lang : $language;
    }, 10, 2);

    // Never send images from one category.
    add_filter('alt_text_fill_is_candidate', static function (bool $yes, int $attachmentId): bool {
        $parent = (int) wp_get_post_parent_id($attachmentId);

        return $yes && !($parent > 0 && has_category('internal', $parent));
    }, 10, 2);
});
