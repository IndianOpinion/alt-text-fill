<?php

/**
 * Offline walk-through of the whole flow, no WordPress and no network:
 * dry run, real run with the fake adapter, review, approve, undo.
 *
 * Run from the repository root: php examples/offline-demo.php
 * It uses the test stub layer in tests/stubs in place of WordPress.
 */

declare(strict_types=1);

use IndianOpinion\AltTextFill\Adapter\FakeAdapter;
use IndianOpinion\AltTextFill\Container;
use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Settings;
use IndianOpinion\AltTextFill\Storage\MemoryRepository;
use IndianOpinion\AltTextFill\Tests\Support\FakeMediaLibrary;

$root = dirname(__DIR__);
require $root . '/tests/stubs/wordpress.php';
require $root . '/bootstrap.php';
require $root . '/tests/Support/FakeMediaLibrary.php';

// A tiny site: one post that uses one image, one unattached image.
$uploads = sys_get_temp_dir() . '/alt-text-fill-demo-' . getmypid();
mkdir($uploads);
WP::$uploadsDir = $uploads;
$png = (string) base64_decode(IndianOpinion\AltTextFill\TestImage::PNG_BASE64, true);
foreach ([10 => 'harbour.png', 11 => 'banner.png'] as $id => $file) {
    file_put_contents($uploads . '/' . $file, $png);
    WP::$posts[$id] = new WP_Post(['ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'post_parent' => $id === 10 ? 100 : 0]);
    WP::$meta[$id]['_wp_attached_file'] = [$file];
    WP::$meta[$id]['_wp_attachment_metadata'] = [['width' => 64, 'height' => 64, 'file' => $file, 'sizes' => []]];
}
WP::$posts[100] = new WP_Post(['ID' => 100, 'post_title' => 'Harbour reopens', 'post_content' => '<p>Boats returned to the harbour this morning.</p><img class="wp-image-10" />']);

// The fake adapter answers without leaving the machine.
$fake = new FakeAdapter();
$fake->push(
    (string) json_encode(['alt' => 'An image of a yellow buoy floating in blue water', 'decorative' => false, 'contains_text' => false, 'names_person' => false, 'matches_context' => true, 'confidence' => 0.86]),
    (string) json_encode(['alt' => '', 'decorative' => true, 'contains_text' => false, 'names_person' => false, 'matches_context' => true, 'confidence' => 0.92])
);
add_filter('alt_text_fill_adapters', static fn (array $a): array => array_merge($a, ['fake' => $fake]));
WP::$options[Settings::OPTION] = Settings::sanitize(['adapter' => 'fake', 'dry_run_only' => false]);
$repo = new MemoryRepository();
Plugin::setContainer(new Container(null, $repo, new FakeMediaLibrary()));

$dry = alt_text_fill_run(['dry_run' => true, 'trigger' => 'cli']);
echo "Dry run: would send {$dry->estimatedCalls} images.\n";
foreach ($dry->items as $item) {
    printf("  #%d %s, %d bytes, context: %s\n", $item['attachment_id'], $item['would_send'] ? 'send' : 'skip (' . $item['reason'] . ')', $item['bytes'], $item['context'] === '' ? '(none)' : str_replace("\n", ' | ', $item['context']));
}

$real = alt_text_fill_run(['trigger' => 'cli']);
echo "Real run {$real->id}: {$real->count('suggestions')} suggestions, stop: {$real->stopReason}.\n";
foreach ($repo->findSuggestions(['status' => 'pending']) as $s) {
    printf("  suggestion %d for #%d: %s [%s]\n", $s->id, $s->attachmentId, $s->kind === 'decorative' ? '(decorative)' : $s->text, implode(', ', $s->flags));
}

$first = $repo->findSuggestions(['attachment_id' => 10])[0];
$entry = alt_text_fill_approve($first->id);
echo "Approved: alt of #10 is now \"" . get_post_meta(10, '_wp_attachment_image_alt', true) . "\" (entry {$entry->id}).\n";

$undo = alt_text_fill_undo($entry->id);
echo 'Undo restored ' . $undo->restored . '; the alt row is ' . (metadata_exists('post', 10, '_wp_attachment_image_alt') ? 'present' : 'absent again, as it was before') . ".\n";

array_map('unlink', glob($uploads . '/*') ?: []);
rmdir($uploads);
