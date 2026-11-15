<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Unit;

use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Settings;
use IndianOpinion\AltTextFill\Storage\Schema;
use IndianOpinion\AltTextFill\Tests\TestCase;

final class BootTest extends TestCase
{
    public function testMainFileRegistersHooks(): void
    {
        require_once dirname(__DIR__, 2) . '/alt-text-fill.php';
        Plugin::boot(dirname(__DIR__, 2) . '/alt-text-fill.php');

        foreach (['init', 'admin_menu', 'admin_post_alt_text_fill_review', 'admin_post_alt_text_fill_settings', 'admin_post_alt_text_fill_undo', 'delete_attachment', 'wp_get_attachment_image_attributes', 'render_block', 'site_status_tests', Plugin::CRON_HOOK, Plugin::MAINTENANCE_HOOK, 'plugin_row_meta'] as $hook) {
            self::assertTrue(has_filter($hook), $hook);
        }
        $meta = Plugin::rowMeta([], 'alt-text-fill/alt-text-fill.php');
        self::assertStringContainsString('Uninstall keeps every alt text value', $meta[0]);
    }

    public function testActivationCreatesTablesDefaultsAndMaintenanceEvent(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        unset(\WP::$options[Settings::OPTION]);

        Plugin::activate();

        self::assertTrue(\WP::$options[Settings::OPTION]['dry_run_only']);
        self::assertFalse(\WP::$autoload[Settings::OPTION]);
        self::assertSame(Schema::VERSION, \WP::$options[Schema::OPTION]['version']);
        self::assertSame('daily', \WP::$cron[Plugin::MAINTENANCE_HOOK]['recurrence']);
        Plugin::deactivate();
        self::assertSame([], \WP::$cron);
    }

    public function testSwitchOptionIsAutoloadedAndStateIsNot(): void
    {
        $this->c->switches->pause('x');
        $this->c->state->update(['cursor' => 1]);

        self::assertTrue(\WP::$autoload['alt_text_fill_switch']);
        self::assertFalse(\WP::$autoload['alt_text_fill_state']);
    }
}
