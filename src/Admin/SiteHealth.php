<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Admin;

use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Storage\Schema;

/**
 * Site Health tests (section 11, case 75).
 */
final class SiteHealth
{
    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public static function register(array $tests): array
    {
        $tests['direct']['alt_text_fill'] = ['label' => 'Alt text fill', 'test' => [self::class, 'test']];

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public static function test(): array
    {
        $c = Plugin::container();
        $problems = [];
        $adapter = $c->adapters->active();
        if ($adapter === null) {
            $problems[] = 'No adapter is chosen.';
        } else {
            foreach ($adapter->validateSettings($c->settings->adapterSettings($adapter->id())) as $error) {
                $problems[] = $error;
            }
        }
        if ($c->preparer->scaler() === null) {
            $problems[] = 'No image editor (GD or Imagick) is available: images over the byte limit are skipped, and large images are sent unscaled.';
        }
        foreach (Schema::missing() as $table => $columns) {
            $problems[] = 'Table ' . $table . ' is missing columns: ' . implode(', ', $columns) . '.';
        }
        $reason = $c->switches->reason();
        if ($reason !== null) {
            $problems[] = 'Paused: ' . $reason . '.';
        }

        return [
            'label' => $problems === [] ? 'Alt text fill is ready' : 'Alt text fill needs attention',
            'status' => $problems === [] ? 'good' : 'recommended',
            'badge' => ['label' => 'Media', 'color' => 'blue'],
            'description' => '<p>' . implode('</p><p>', array_map('esc_html', $problems === [] ? ['Adapter, key, image editor and tables are in place.'] : $problems)) . '</p>',
            'actions' => '',
            'test' => 'alt_text_fill',
        ];
    }
}
