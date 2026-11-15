<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Admin;

use IndianOpinion\AltTextFill\Exception\AltTextFillException;
use IndianOpinion\AltTextFill\Exception\PermissionException;
use IndianOpinion\AltTextFill\Plugin;
use IndianOpinion\AltTextFill\Review;
use IndianOpinion\AltTextFill\Settings;
use IndianOpinion\AltTextFill\Storage\Schema;
use IndianOpinion\AltTextFill\Switches;

/**
 * Menus, form handlers and notices. Every handler checks a capability and a
 * nonce before it does anything (case 50, section 8).
 */
final class Screens
{
    public const NONCE_REVIEW = 'alt_text_fill_review';
    public const NONCE_SETTINGS = 'alt_text_fill_settings';
    public const NONCE_UNDO = 'alt_text_fill_undo';
    public const PER_PAGE = 20;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menus']);
        add_action('admin_post_alt_text_fill_review', [self::class, 'handleReview']);
        add_action('admin_post_alt_text_fill_settings', [self::class, 'handleSettings']);
        add_action('admin_post_alt_text_fill_undo', [self::class, 'handleUndo']);
        add_action('admin_notices', [self::class, 'notices']);
        add_action('add_meta_boxes_attachment', [AttachmentBox::class, 'add']);
    }

    public static function reviewCapability(): string
    {
        $cap = apply_filters('alt_text_fill_review_capability', 'upload_files');

        return is_string($cap) && $cap !== '' ? $cap : 'upload_files';
    }

    public static function adminCapability(): string
    {
        $cap = apply_filters('alt_text_fill_admin_capability', 'manage_options');

        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    public static function menus(): void
    {
        add_media_page('Alt text review', 'Alt text review', self::reviewCapability(), 'alt-text-fill-review', [self::class, 'renderReview']);
        add_options_page('Alt text fill', 'Alt text fill', self::adminCapability(), 'alt-text-fill', [self::class, 'renderSettings']);
    }

    public static function renderReview(): void
    {
        if (!current_user_can(self::reviewCapability())) {
            wp_die(esc_html__('You cannot review alt text.', 'alt-text-fill'), '', ['response' => 403]);
        }
        echo self::flash(); // Escaped inside.
        echo (new ReviewScreen(Plugin::container()))->render(self::query()); // Escaped inside.
    }

    public static function renderSettings(): void
    {
        if (!current_user_can(self::adminCapability())) {
            wp_die(esc_html__('You cannot change these settings.', 'alt-text-fill'), '', ['response' => 403]);
        }
        echo self::flash(); // Escaped inside.
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
        echo (new SettingsScreen(Plugin::container()))->render($tab); // Escaped inside.
    }

    /**
     * @return array<string, mixed>
     */
    private static function query(): array
    {
        // Read-only list filters; no state changes, so no nonce.
        $q = [];
        foreach (['status', 'flag', 'run', 'orderby', 'order'] as $key) {
            if (isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification
                $q[$key] = sanitize_text_field((string) wp_unslash($_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification
            }
        }
        foreach (['post', 'paged'] as $key) {
            if (isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification
                $q[$key] = absint($_GET[$key]); // phpcs:ignore WordPress.Security.NonceVerification
            }
        }
        foreach (['min_confidence', 'max_confidence'] as $key) {
            if (isset($_GET[$key]) && is_numeric($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification
                $q[$key] = (float) $_GET[$key]; // phpcs:ignore WordPress.Security.NonceVerification
            }
        }

        return $q;
    }

    /**
     * Handles one review form post. Returns the messages it set (for tests);
     * in WordPress it redirects back.
     *
     * @param array<string, mixed>|null $post
     * @return list<string>
     */
    public static function handleReview(?array $post = null, bool $redirect = true): array
    {
        $post ??= wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification
        $post = is_array($post) ? $post : [];
        if (!current_user_can(self::reviewCapability())) {
            return self::refuse('You cannot review alt text.', $redirect);
        }
        if (!wp_verify_nonce((string) ($post['_wpnonce'] ?? ''), self::NONCE_REVIEW)) {
            return self::refuse('The form expired. Reload the page and try again.', $redirect);
        }
        $review = Plugin::container()->review;
        $op = sanitize_key((string) ($post['op'] ?? ''));
        $ids = array_slice(array_values(array_filter(array_map('absint', (array) ($post['ids'] ?? [])))), 0, self::PER_PAGE);
        $single = absint($post['id'] ?? 0);
        $messages = [];
        $run = static function (callable $fn) use (&$messages): void {
            try {
                $messages[] = (string) $fn();
            } catch (AltTextFillException | \InvalidArgumentException $e) {
                $messages[] = 'Not done: ' . $e->getMessage();
            }
        };
        switch ($op) {
            case 'approve':
                $run(static function () use ($review, $single): string {
                    $review->approve($single);

                    return 'Approved and written.';
                });
                break;
            case 'approve_edit':
                $text = (string) (is_array($post['text'] ?? null) ? ($post['text'][$single] ?? '') : ($post['text'] ?? ''));
                $run(static function () use ($review, $single, $text): string {
                    $review->approve($single, $text);

                    return 'Approved with your edit and written.';
                });
                break;
            case 'reject':
                $reason = sanitize_text_field((string) (is_array($post['reason'] ?? null) ? ($post['reason'][$single] ?? 'other') : ($post['reason'] ?? 'other')));
                $note = (string) (is_array($post['note'] ?? null) ? ($post['note'][$single] ?? '') : ($post['note'] ?? ''));
                $run(static function () use ($review, $single, $reason, $note): string {
                    $review->reject($single, $reason, $note);

                    return 'Rejected.';
                });
                break;
            case 'try_again':
                $attachment = absint($post['attachment'] ?? 0);
                $run(static function () use ($review, $attachment): string {
                    $review->tryAgain($attachment);

                    return 'The image will be tried again in the next run.';
                });
                break;
            case 'decorative':
                $attachment = absint($post['attachment'] ?? 0);
                $run(static function () use ($review, $attachment): string {
                    $review->markDecorative($attachment);

                    return 'Marked decorative.';
                });
                break;
            case 'skip':
                $messages[] = 'Skipped for now; it stays on the list.';
                break;
            case 'bulk_approve':
                foreach ($ids as $id) {
                    $run(static function () use ($review, $id): string {
                        $review->approve($id);

                        return 'Approved ' . $id . '.';
                    });
                }
                break;
            case 'bulk_reject':
                foreach ($ids as $id) {
                    $run(static function () use ($review, $id): string {
                        $review->reject($id, 'other');

                        return 'Rejected ' . $id . '.';
                    });
                }
                break;
            default:
                $messages[] = 'Unknown action.';
        }
        self::finish($messages, $redirect, admin_url('upload.php?page=alt-text-fill-review'));

        return $messages;
    }

    /**
     * @param array<string, mixed>|null $post
     * @return list<string>
     */
    public static function handleSettings(?array $post = null, bool $redirect = true): array
    {
        $post ??= wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification
        $post = is_array($post) ? $post : [];
        if (!current_user_can(self::adminCapability())) {
            return self::refuse('You cannot change these settings.', $redirect);
        }
        if (!wp_verify_nonce((string) ($post['_wpnonce'] ?? ''), self::NONCE_SETTINGS)) {
            return self::refuse('The form expired. Reload the page and try again.', $redirect);
        }
        $c = Plugin::container();
        $op = sanitize_key((string) ($post['op'] ?? 'save'));
        $messages = [];
        try {
            switch ($op) {
                case 'pause':
                    $c->switches->pause('manual');
                    $messages[] = 'Paused. No calls will be made.';
                    break;
                case 'resume':
                    $c->switches->resume();
                    $messages[] = 'Resumed.';
                    break;
                case 'test':
                    $result = $c->runner->testConnection();
                    $messages[] = ($result['ok'] ? 'Connection works. Answer: ' : 'Test failed: ') . $result['message'];
                    break;
                case 'dry_run':
                case 'run':
                    $report = $c->runner->run(['dry_run' => $op === 'dry_run', 'trigger' => 'screen']);
                    $messages[] = sprintf('Run %s finished (%s): %d sent, %d suggestions, %d skipped.', $report->id, $report->stopReason, $report->count('sent'), $report->count('suggestions'), $report->count('skipped'));
                    if ($report->isDryRun()) {
                        $messages[] = sprintf('Would send %d images; %d calls left today.', $report->estimatedCalls, $report->remainingCalls);
                    }
                    break;
                default:
                    $input = self::settingsInput($post);
                    $c->settings->save($input);
                    $c->settings->reload();
                    Plugin::syncSchedule();
                    $messages[] = 'Settings saved.';
            }
        } catch (AltTextFillException $e) {
            $messages[] = 'Not done: ' . $e->getMessage();
        }
        self::finish($messages, $redirect, admin_url('options-general.php?page=alt-text-fill'));

        return $messages;
    }

    /**
     * @param array<string, mixed>|null $post
     * @return list<string>
     */
    public static function handleUndo(?array $post = null, bool $redirect = true): array
    {
        $post ??= wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification
        $post = is_array($post) ? $post : [];
        if (!current_user_can(self::reviewCapability())) {
            return self::refuse('You cannot undo alt text changes.', $redirect);
        }
        if (!wp_verify_nonce((string) ($post['_wpnonce'] ?? ''), self::NONCE_UNDO)) {
            return self::refuse('The form expired. Reload the page and try again.', $redirect);
        }
        $undo = Plugin::container()->undo;
        $messages = [];
        try {
            if (!empty($post['run']) || !empty($post['since'])) {
                if (!current_user_can(self::adminCapability())) {
                    throw new PermissionException('Undo by run or by time needs the settings capability.');
                }
                if (!empty($post['run'])) {
                    $report = $undo->run(sanitize_text_field((string) $post['run']));
                } else {
                    $ts = strtotime((string) $post['since']);
                    if ($ts === false) {
                        throw new \InvalidArgumentException('That time could not be read.');
                    }
                    $report = $undo->since($ts);
                }
            } else {
                $force = !empty($post['force']) && !empty($post['confirm']);
                $report = $undo->entry(absint($post['entry'] ?? 0), $force);
            }
            $messages[] = sprintf('Restored %d, refused %d, already undone %d.', $report->restored, $report->refused, $report->alreadyUndone);
            foreach ($report->details as $detail) {
                if ($detail['result'] === 'refused') {
                    $messages[] = 'Entry ' . $detail['entry'] . ': ' . $detail['message'];
                }
            }
        } catch (AltTextFillException | \InvalidArgumentException $e) {
            $messages[] = 'Not done: ' . $e->getMessage();
        }
        self::finish($messages, $redirect, admin_url('options-general.php?page=alt-text-fill&tab=history'));

        return $messages;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function settingsInput(array $post): array
    {
        $raw = is_array($post['settings'] ?? null) ? $post['settings'] : [];
        $input = [];
        $defaults = Settings::defaults();
        foreach ($defaults as $key => $default) {
            if ($key === 'adapter_settings') {
                continue;
            }
            if (is_bool($default)) {
                $input[$key] = !empty($raw[$key]);
            } elseif (is_array($default)) {
                if (array_key_exists($key, $raw)) {
                    $list = is_array($raw[$key]) ? $raw[$key] : preg_split('/[\s,]+/', (string) $raw[$key]);
                    $input[$key] = array_values(array_filter(array_map(static fn ($v): string => sanitize_text_field((string) $v), (array) $list)));
                }
            } elseif (array_key_exists($key, $raw)) {
                $input[$key] = sanitize_text_field((string) $raw[$key]);
            }
        }
        $adapter = (string) ($input['adapter'] ?? '');
        if ($adapter !== '' && is_array($raw['adapter_settings'][$adapter] ?? null)) {
            $fields = [];
            $existing = Plugin::container()->settings->get('adapter_settings');
            $existing = is_array($existing) && is_array($existing[$adapter] ?? null) ? $existing[$adapter] : [];
            $registered = Plugin::container()->adapters->get($adapter);
            foreach ($registered !== null ? $registered->settingsFields() : [] as $field) {
                $value = $raw['adapter_settings'][$adapter][$field['key']] ?? null;
                if ($field['type'] === 'checkbox') {
                    $fields[$field['key']] = !empty($value);
                } elseif ($field['secret'] && ($value === null || $value === '')) {
                    $fields[$field['key']] = (string) ($existing[$field['key']] ?? '');
                } elseif ($field['type'] === 'textarea') {
                    $fields[$field['key']] = sanitize_textarea_field((string) $value);
                } else {
                    $fields[$field['key']] = sanitize_text_field((string) $value);
                }
            }
            $all = Plugin::container()->settings->get('adapter_settings');
            $all = is_array($all) ? $all : [];
            $all[$adapter] = $fields;
            $input['adapter_settings'] = $all;
        }

        return $input;
    }

    public static function notices(): void
    {
        if (!current_user_can(self::reviewCapability())) {
            return;
        }
        $c = Plugin::container();
        $reason = $c->switches->reason();
        if ($reason === Switches::REASON_PROVIDER) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Alt text fill is paused: the provider refused the account or key. Fix it, then resume on the settings screen.', 'alt-text-fill') . '</p></div>';
        }
        if ($c->settings->isCorrupted()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Alt text fill could not read its settings, so it will not call any model. Open its settings screen and save.', 'alt-text-fill') . '</p></div>';
        }
        $missing = Schema::missing();
        if ($missing !== [] && current_user_can(self::adminCapability())) {
            $parts = [];
            foreach ($missing as $table => $columns) {
                $parts[] = $table . ': ' . implode(', ', $columns);
            }
            echo '<div class="notice notice-error"><p>' . esc_html(sprintf('Alt text fill tables are missing columns (%s). Deactivate and activate the plugin, or check the database user can alter tables.', implode('; ', $parts))) . '</p></div>';
        }
    }

    /**
     * @return list<string>
     */
    private static function refuse(string $message, bool $redirect): array
    {
        if ($redirect) {
            wp_die(esc_html($message), '', ['response' => 403]);
        }

        return [$message];
    }

    /**
     * @param list<string> $messages
     */
    private static function finish(array $messages, bool $redirect, string $url): void
    {
        set_transient('alt_text_fill_flash_' . get_current_user_id(), $messages, 120);
        if ($redirect) {
            wp_safe_redirect(wp_get_referer() ?: $url);
            exit;
        }
    }

    public static function flash(): string
    {
        $key = 'alt_text_fill_flash_' . get_current_user_id();
        $messages = get_transient($key);
        if (!is_array($messages) || $messages === []) {
            return '';
        }
        delete_transient($key);
        $html = '<div class="notice notice-info is-dismissible">';
        foreach ($messages as $m) {
            $html .= '<p>' . esc_html((string) $m) . '</p>';
        }

        return $html . '</div>';
    }

    public static function nonceField(string $action): string
    {
        return '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($action)) . '" />';
    }

    /** True when the current user may act on rows for this attachment. */
    public static function canActOn(int $attachmentId): bool
    {
        return Review::canEdit($attachmentId);
    }
}
