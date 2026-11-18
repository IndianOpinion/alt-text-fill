<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Storage\Schema;

/**
 * Removes what the plugin created: three tables, its options, its own
 * attachment meta and its scheduled events. It never changes or deletes
 * any alt text, whether the plugin wrote it or not. Undo by time first if
 * the writes should go.
 */
final class Uninstaller
{
    public const SITE_BATCH = 100;

    /** @return list<string> */
    public static function options(): array
    {
        return array_merge([Settings::OPTION, Schema::OPTION], State::options());
    }

    public static function uninstall(): void
    {
        if (is_multisite()) {
            $offset = 0;
            do {
                $sites = get_sites(['fields' => 'ids', 'number' => self::SITE_BATCH, 'offset' => $offset]);
                foreach ($sites as $siteId) {
                    switch_to_blog((int) $siteId);
                    self::site();
                    restore_current_blog();
                }
                $offset += self::SITE_BATCH;
            } while (count($sites) === self::SITE_BATCH);

            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        Schema::drop($wpdb);
        foreach (self::options() as $option) {
            delete_option($option);
        }
        foreach (AltField::ownMetaKeys() as $key) {
            delete_post_meta_by_key($key);
        }
        wp_clear_scheduled_hook(Plugin::CRON_HOOK);
        wp_clear_scheduled_hook(Plugin::MAINTENANCE_HOOK);
    }
}
