<?php

/**
 * A small in-memory stand-in for the WordPress functions this plugin uses,
 * so PHPUnit runs without a WordPress install. State lives in WP::$...
 * and is reset by WP::reset() before each test.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/alt-text-fill-abspath/');
}
const ARRAY_A = 'ARRAY_A';
const OBJECT = 'OBJECT';
const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;

final class WP
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, bool> */
    public static array $autoload = [];
    /** @var array<string, array{0: mixed, 1: int}> */
    public static array $transients = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, list<mixed>>> */
    public static array $meta = [];
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public static array $fired = [];
    public static int $userId = 1;
    /** @var callable|null fn(string $cap, mixed ...$args): bool */
    public static $can = null;
    /** @var callable|null fn(string $method, string $url, array $args): array|WP_Error */
    public static $http = null;
    /** @var list<array{method: string, url: string, args: array<string, mixed>}> */
    public static array $requests = [];
    /** @var array<string, array{time: int, recurrence: string}> */
    public static array $cron = [];
    public static string $uploadsBase = 'https://example.org/wp-content/uploads';
    public static string $uploadsDir = '';
    public static string $locale = 'en_GB';
    public static bool $failMetaWrite = false;
    public static bool $multisite = false;
    /** @var list<int> */
    public static array $sites = [1];
    public static int $blog = 1;
    /** @var list<string> */
    public static array $dbDelta = [];
    public static int $now = 1763164800;

    public static function reset(): void
    {
        self::$options = [];
        self::$autoload = [];
        self::$transients = [];
        self::$posts = [];
        self::$meta = [];
        self::$hooks = [];
        self::$fired = [];
        self::$userId = 1;
        self::$can = null;
        self::$http = null;
        self::$requests = [];
        self::$cron = [];
        self::$locale = 'en_GB';
        self::$failMetaWrite = false;
        self::$multisite = false;
        self::$sites = [1];
        self::$blog = 1;
        self::$dbDelta = [];
    }

    /** @return list<array<int, mixed>> */
    public static function firedArgs(string $hook): array
    {
        $out = [];
        foreach (self::$fired as [$name, $args]) {
            if ($name === $hook) {
                $out[] = $args;
            }
        }

        return $out;
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_mime_type = '';
    public string $post_password = '';
    public int $post_parent = 0;
    public string $post_date = '2026-01-01 00:00:00';

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $k => $v) {
            $this->$k = $v;
        }
    }
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

class wpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    /** Empty: the stub has no options table, so the run lock uses the options API. */
    public string $options = '';
    /** @var array<string, list<string>> Table name to columns, for SHOW COLUMNS. */
    public array $columns = [];
    /** @var list<string> */
    public array $queries = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    /** @return list<string> */
    public function get_col(string $sql, int $x = 0): array
    {
        $this->queries[] = $sql;
        if (preg_match('/SHOW COLUMNS FROM `([^`]+)`/', $sql, $m) === 1) {
            return $this->columns[$m[1]] ?? [];
        }

        return [];
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;
        if (preg_match('/DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m) === 1) {
            unset($this->columns[$m[1]]);
        }

        return 0;
    }

    public function get_var(string $sql): ?string
    {
        $this->queries[] = $sql;

        return '0';
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        return vsprintf(str_replace(['%d', '%s', '%f', '%i'], ['%d', "'%s'", '%F', '`%s`'], $sql), $args);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }
}

// Hooks.

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WP::$hooks[$hook][$priority][] = [$cb, $args];

    return true;
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}

function has_filter(string $hook): bool
{
    return !empty(WP::$hooks[$hook]);
}

function has_action(string $hook): bool
{
    return has_filter($hook);
}

function remove_all_filters(string $hook): bool
{
    unset(WP::$hooks[$hook]);

    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (empty(WP::$hooks[$hook])) {
        return $value;
    }
    ksort(WP::$hooks[$hook]);
    foreach (WP::$hooks[$hook] as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $value = $cb(...array_slice(array_merge([$value], $args), 0, max(1, $n)));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WP::$fired[] = [$hook, $args];
    if (empty(WP::$hooks[$hook])) {
        return;
    }
    ksort(WP::$hooks[$hook]);
    foreach (WP::$hooks[$hook] as $callbacks) {
        foreach ($callbacks as [$cb, $n]) {
            $cb(...array_slice($args, 0, $n));
        }
    }
}

function register_activation_hook(string $file, callable $cb): void
{
}

function register_deactivation_hook(string $file, callable $cb): void
{
}

// Options and transients.

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WP::$options) ? WP::$options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    if (array_key_exists($name, WP::$options) && WP::$options[$name] === $value) {
        return false;
    }
    WP::$options[$name] = $value;
    if ($autoload !== null) {
        WP::$autoload[$name] = (bool) $autoload;
    }

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = true): bool
{
    if (array_key_exists($name, WP::$options)) {
        return false;
    }
    WP::$options[$name] = $value;
    WP::$autoload[$name] = (bool) $autoload;

    return true;
}

function delete_option(string $name): bool
{
    $had = array_key_exists($name, WP::$options);
    unset(WP::$options[$name], WP::$autoload[$name]);

    return $had;
}

function get_transient(string $key): mixed
{
    if (!isset(WP::$transients[$key])) {
        return false;
    }
    [$value, $expires] = WP::$transients[$key];
    if ($expires > 0 && $expires < WP::$now) {
        unset(WP::$transients[$key]);

        return false;
    }

    return $value;
}

function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    WP::$transients[$key] = [$value, $ttl > 0 ? WP::$now + $ttl : 0];

    return true;
}

function delete_transient(string $key): bool
{
    unset(WP::$transients[$key]);

    return true;
}

// Posts and meta.

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }

    return WP::$posts[(int) $post] ?? null;
}

function get_post_status(mixed $post = null): string|false
{
    $p = get_post($post);

    return $p === null ? false : $p->post_status;
}

function metadata_exists(string $type, int $id, string $key): bool
{
    return isset(WP::$meta[$id][$key]) && WP::$meta[$id][$key] !== [];
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    if ($key === '') {
        return WP::$meta[$id] ?? [];
    }
    $values = WP::$meta[$id][$key] ?? [];
    if ($single) {
        return $values === [] ? '' : $values[0];
    }

    return $values;
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    if (WP::$failMetaWrite && $key === '_wp_attachment_image_alt') {
        return false;
    }
    $value = wp_unslash($value);
    if (isset(WP::$meta[$id][$key][0]) && WP::$meta[$id][$key][0] === $value) {
        return false;
    }
    WP::$meta[$id][$key] = [$value];

    return true;
}

function add_post_meta(int $id, string $key, mixed $value, bool $unique = false): int|false
{
    if (WP::$failMetaWrite && $key === '_wp_attachment_image_alt') {
        return false;
    }
    if ($unique && metadata_exists('post', $id, $key)) {
        return false;
    }
    WP::$meta[$id][$key][] = wp_unslash($value);

    return 1;
}

function delete_post_meta(int $id, string $key, mixed $value = ''): bool
{
    if (WP::$failMetaWrite && $key === '_wp_attachment_image_alt') {
        return false;
    }
    $had = isset(WP::$meta[$id][$key]);
    unset(WP::$meta[$id][$key]);

    return $had;
}

function delete_post_meta_by_key(string $key): bool
{
    foreach (WP::$meta as $id => $keys) {
        unset(WP::$meta[$id][$key]);
    }

    return true;
}

/** @param list<int> $ids */
function update_meta_cache(string $type, array $ids): array
{
    return [];
}

function wp_cache_delete(int|string $key, string $group = ''): bool
{
    return true;
}

/** @param list<int> $ids */
function _prime_post_caches(array $ids, bool $terms = true, bool $meta = true): void
{
}

function wp_slash(mixed $value): mixed
{
    return is_string($value) ? addslashes($value) : $value;
}

function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function get_attached_file(int $id): string|false
{
    $file = get_post_meta($id, '_wp_attached_file', true);
    if (!is_string($file) || $file === '') {
        return false;
    }

    return rtrim(WP::$uploadsDir, '/') . '/' . $file;
}

function wp_get_attachment_metadata(int $id): array|false
{
    $meta = get_post_meta($id, '_wp_attachment_metadata', true);

    return is_array($meta) ? $meta : false;
}

function wp_get_attachment_url(int $id): string|false
{
    $file = get_post_meta($id, '_wp_attached_file', true);

    return is_string($file) && $file !== '' ? WP::$uploadsBase . '/' . $file : false;
}

function wp_attachment_is_image(mixed $post = null): bool
{
    $p = get_post($post);

    return $p !== null && str_starts_with($p->post_mime_type, 'image/');
}

function wp_get_attachment_image(int $id, string $size = 'thumbnail', bool $icon = false, array $attr = []): string
{
    return '<img src="' . esc_url((string) wp_get_attachment_url($id)) . '" alt="" />';
}

function wp_upload_dir(mixed $time = null, bool $create = true): array
{
    return ['path' => WP::$uploadsDir, 'url' => WP::$uploadsBase, 'subdir' => '', 'basedir' => WP::$uploadsDir, 'baseurl' => WP::$uploadsBase, 'error' => false];
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function get_permalink(int $id): string
{
    return 'https://example.org/?p=' . $id;
}

function get_the_title(int $id): string
{
    return WP::$posts[$id]->post_title ?? '';
}

// Users and nonces.

function get_current_user_id(): int
{
    return WP::$userId;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    if (WP::$userId === 0) {
        return false;
    }
    if (WP::$can !== null) {
        return (bool) (WP::$can)($cap, ...$args);
    }

    return true;
}

function wp_create_nonce(string $action): string
{
    return 'nonce-' . $action . '-' . WP::$userId;
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return $nonce === wp_create_nonce($action) ? 1 : false;
}

// HTTP.

function wp_remote_request(string $method, string $url, array $args): array|WP_Error
{
    WP::$requests[] = ['method' => $method, 'url' => $url, 'args' => $args];
    if (WP::$http === null) {
        return new WP_Error('http_request_failed', 'No HTTP in tests.');
    }

    return (WP::$http)($method, $url, $args);
}

function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    return wp_remote_request('POST', $url, $args);
}

function wp_safe_remote_get(string $url, array $args = []): array|WP_Error
{
    return wp_remote_request('GET', $url, $args);
}

function wp_safe_remote_head(string $url, array $args = []): array|WP_Error
{
    return wp_remote_request('HEAD', $url, $args);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(mixed $r): int|string
{
    return is_array($r) ? (int) ($r['response']['code'] ?? 0) : '';
}

function wp_remote_retrieve_body(mixed $r): string
{
    return is_array($r) ? (string) ($r['body'] ?? '') : '';
}

function wp_remote_retrieve_header(mixed $r, string $name): string|array
{
    if (!is_array($r)) {
        return '';
    }
    foreach ((array) ($r['headers'] ?? []) as $k => $v) {
        if (strcasecmp((string) $k, $name) === 0) {
            return $v;
        }
    }

    return '';
}

// Escaping, sanitising, formatting.

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function esc_html(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url(mixed $url): string
{
    $url = (string) $url;
    if (preg_match('#^(https?:)?//#i', $url) !== 1 && !str_starts_with($url, '/') && !str_starts_with($url, '?')) {
        return '';
    }

    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_sql(string $s): string
{
    return addslashes($s);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function esc_attr__(string $text, string $domain = 'default'): string
{
    return esc_attr($text);
}

function sanitize_text_field(mixed $s): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s)));
}

function sanitize_textarea_field(mixed $s): string
{
    return trim(strip_tags((string) $s));
}

function sanitize_key(string $s): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
}

function absint(mixed $v): int
{
    return abs((int) $v);
}

function wp_strip_all_tags(string $s): string
{
    return trim(strip_tags((string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $s)));
}

function selected(mixed $a, mixed $b, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' checked="checked"' : '';
}

function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . ltrim($path, '/');
}

function wp_date(string $format, ?int $ts = null): string|false
{
    return gmdate($format, $ts ?? WP::$now);
}

function get_locale(): string
{
    return WP::$locale;
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone((string) (get_option('timezone_string') ?: 'UTC'));
}

function wp_die(string $message = '', string $title = '', array $args = []): void
{
    throw new RuntimeException('wp_die: ' . $message);
}

function wp_get_referer(): string|false
{
    return false;
}

function wp_safe_redirect(string $url): bool
{
    return true;
}

// Cron.

function wp_next_scheduled(string $hook): int|false
{
    return isset(WP::$cron[$hook]) ? WP::$cron[$hook]['time'] : false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook, array $args = []): bool
{
    WP::$cron[$hook] = ['time' => $time, 'recurrence' => $recurrence];

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $had = isset(WP::$cron[$hook]) ? 1 : 0;
    unset(WP::$cron[$hook]);

    return $had;
}

// Multisite.

function is_multisite(): bool
{
    return WP::$multisite;
}

function get_sites(array $args = []): array
{
    $offset = (int) ($args['offset'] ?? 0);
    $number = (int) ($args['number'] ?? 100);

    return array_slice(WP::$sites, $offset, $number > 0 ? $number : null);
}

function switch_to_blog(int $id): bool
{
    WP::$blog = $id;

    return true;
}

function restore_current_blog(): bool
{
    WP::$blog = 1;

    return true;
}

// Schema helper.

function dbDelta(string|array $sql): array
{
    global $wpdb;
    foreach ((array) $sql as $statement) {
        WP::$dbDelta[] = $statement;
        if ($wpdb instanceof wpdb && preg_match('/CREATE TABLE (\S+) \((.*)\)/s', $statement, $m) === 1) {
            $cols = [];
            foreach (explode("\n", $m[2]) as $line) {
                if (preg_match('/^\s+([a-z_]+) [a-z]/', $line, $c) === 1 && !in_array($c[1], ['PRIMARY', 'KEY'], true)) {
                    $cols[] = $c[1];
                }
            }
            if (!isset($wpdb->columns[$m[1]])) {
                $wpdb->columns[$m[1]] = $cols;
            }
        }
    }

    return [];
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}
