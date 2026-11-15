<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill;

use IndianOpinion\AltTextFill\Admin\Screens;
use IndianOpinion\AltTextFill\Admin\SiteHealth;
use IndianOpinion\AltTextFill\Cli\Command;
use IndianOpinion\AltTextFill\Exception\AltTextFillException;
use IndianOpinion\AltTextFill\Storage\Schema;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin
{
    public const CRON_HOOK = 'alt_text_fill_cron';
    public const MAINTENANCE_HOOK = 'alt_text_fill_maintenance';
    public const SCHEDULE = 'alt_text_fill_interval';

    private static ?Container $container = null;
    private static string $basename = '';

    public static function container(): Container
    {
        return self::$container ??= new Container();
    }

    public static function setContainer(?Container $container): void
    {
        self::$container = $container;
    }

    public static function boot(string $file): void
    {
        self::$basename = plugin_basename($file);
        register_activation_hook($file, [self::class, 'activate']);
        add_filter('plugin_row_meta', [self::class, 'rowMeta'], 10, 2);
        register_deactivation_hook($file, [self::class, 'deactivate']);
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action(self::CRON_HOOK, [self::class, 'cronRun']);
        add_action(self::MAINTENANCE_HOOK, [self::class, 'maintenance']);
        add_action('init', [self::class, 'init']);
        add_action('delete_attachment', [self::class, 'onDeleteAttachment']);
        add_filter('wp_update_attachment_metadata', [self::class, 'onMetadataUpdate'], 99, 2);
        add_action('attachment_updated', [self::class, 'onAttachmentUpdated']);
        add_filter('wp_get_attachment_image_attributes', [RenderFill::class, 'attributes'], 20, 2);
        add_filter('render_block', [RenderFill::class, 'block'], 20, 2);
        add_action('admin_init', [self::class, 'adminInit']);
        add_filter('site_status_tests', [SiteHealth::class, 'register']);
        Screens::register();
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('alt-text-fill', Command::class);
        }
    }

    /**
     * The plugins screen says what uninstall keeps (section 6).
     *
     * @param array<int|string, string> $meta
     * @return array<int|string, string>
     */
    public static function rowMeta(array $meta, string $file): array
    {
        if ($file === self::$basename) {
            $meta[] = esc_html__('Uninstall keeps every alt text value, including those this plugin wrote. Use undo by time first if they should go.', 'alt-text-fill');
        }

        return $meta;
    }

    public static function init(): void
    {
        add_action(Integrations::hook('queue_handle'), [self::class, 'queueJob'], 10, 1);
        self::syncSchedule();
    }

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::activateSite();
                restore_current_blog();
            }

            return;
        }
        self::activateSite();
    }

    private static function activateSite(): void
    {
        global $wpdb;
        Schema::install($wpdb);
        if (get_option(Settings::OPTION, null) === null) {
            add_option(Settings::OPTION, Settings::defaults(), '', false);
        }
        if (!wp_next_scheduled(self::MAINTENANCE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::MAINTENANCE_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::MAINTENANCE_HOOK);
    }

    public static function adminInit(): void
    {
        global $wpdb;
        Schema::maybeUpgrade($wpdb);
    }

    public static function interval(): int
    {
        return max(60, (int) apply_filters('alt_text_fill_cron_interval', 900));
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function cronSchedules(array $schedules): array
    {
        $schedules[self::SCHEDULE] = ['interval' => self::interval(), 'display' => 'Alt text fill interval'];

        return $schedules;
    }

    /** Case 67: the event exists exactly when the schedule setting is on. */
    public static function syncSchedule(): void
    {
        $on = self::container()->settings->bool('schedule');
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($on && !$next) {
            wp_schedule_event(time() + self::interval(), self::SCHEDULE, self::CRON_HOOK);
        } elseif (!$on && $next) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public static function cronRun(): void
    {
        $c = self::container();
        try {
            $c->runner->run(['trigger' => 'cron']);
        } catch (AltTextFillException $e) {
            $c->logger->info('Scheduled run did not start: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $c->logger->error('Scheduled run failed with ' . get_class($e) . ': ' . Redactor::redact($e->getMessage()));
        }
    }

    /**
     * @param mixed $payload
     */
    public static function queueJob($payload): void
    {
        if (is_array($payload)) {
            self::container()->runner->handleJob($payload);
        }
    }

    /**
     * Retention (section 6): pending suggestions expire, old rows go.
     *
     * @return array<string, int>
     */
    public static function maintenance(): array
    {
        $c = self::container();
        $now = $c->clock->now();
        $days = static function (string $table, int $default): int {
            return max(0, (int) apply_filters('alt_text_fill_retention_days', $default, $table));
        };
        $at = static fn (int $d): int => $d > 0 ? $now - $d * DAY_IN_SECONDS : 0;

        return $c->repository->purge(
            $at($days('pending', 30)),
            $at($days('suggestions', 90)),
            $at($days('runs', 90)),
            $at($days('entries', 365))
        );
    }

    /** Case 70: pending suggestions go with the attachment; entries stay. */
    public static function onDeleteAttachment(int $postId): void
    {
        self::container()->repository->deletePendingForAttachment($postId);
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public static function onMetadataUpdate($data, int $attachmentId = 0)
    {
        if ($attachmentId > 0) {
            add_action('shutdown', static function () use ($attachmentId): void {
                self::container()->review->checkStale($attachmentId);
            });
        }

        return $data;
    }

    public static function onAttachmentUpdated(int $attachmentId): void
    {
        self::container()->review->checkStale($attachmentId);
    }
}
