<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Storage;

/**
 * Creates and upgrades the three tables, then checks that every expected
 * column exists. The WordPress table helper can skip a column change without
 * an error, so the check is the only proof (PRD section 6, lesson 14).
 */
class Schema
{
    public const VERSION = 1;
    public const OPTION = 'alt_text_fill_db';

    /**
     * @return array<string, list<string>> Table suffix to expected columns.
     */
    public static function expectedColumns(): array
    {
        return [
            'suggestions' => [
                'id', 'attachment_id', 'run_id', 'kind', 'suggested_text', 'original_text', 'final_text',
                'previous_alt', 'flags', 'confidence', 'status', 'reject_reason', 'reject_note',
                'context_post_id', 'context_text', 'file_hash', 'adapter', 'model', 'input_tokens',
                'output_tokens', 'created', 'decided', 'decided_by',
            ],
            'entries' => [
                'id', 'attachment_id', 'previous_exists', 'previous_value', 'new_exists', 'new_value',
                'marker_before', 'marker_after', 'action', 'user_id', 'created', 'run_id',
                'suggestion_id', 'undoes', 'undone_by',
            ],
            'runs' => [
                'id', 'started', 'finished', 'mode', 'run_trigger', 'counts', 'skipped', 'stop_reason', 'user_id',
            ],
        ];
    }

    public static function table(\wpdb $wpdb, string $suffix): string
    {
        return $wpdb->prefix . 'alt_text_fill_' . $suffix;
    }

    /**
     * @return list<string> The CREATE TABLE statements in the form the table helper needs.
     */
    public static function statements(\wpdb $wpdb): array
    {
        $charset = $wpdb->get_charset_collate();
        $s = self::table($wpdb, 'suggestions');
        $e = self::table($wpdb, 'entries');
        $r = self::table($wpdb, 'runs');

        return [
            "CREATE TABLE {$s} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  run_id varchar(40) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'text',
  suggested_text text NOT NULL,
  original_text text NOT NULL,
  final_text text NOT NULL,
  previous_alt text NOT NULL,
  flags text NOT NULL,
  confidence decimal(4,3) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  reject_reason varchar(60) NOT NULL DEFAULT '',
  reject_note text NOT NULL,
  context_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  context_text text NOT NULL,
  file_hash varchar(80) NOT NULL DEFAULT '',
  adapter varchar(40) NOT NULL DEFAULT '',
  model varchar(191) NOT NULL DEFAULT '',
  input_tokens int(11) DEFAULT NULL,
  output_tokens int(11) DEFAULT NULL,
  created bigint(20) NOT NULL DEFAULT 0,
  decided bigint(20) NOT NULL DEFAULT 0,
  decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status_created (status,created),
  KEY attachment_id (attachment_id),
  KEY run_id (run_id)
) {$charset};",
            "CREATE TABLE {$e} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  previous_exists tinyint(1) NOT NULL DEFAULT 0,
  previous_value longtext NULL,
  new_exists tinyint(1) NOT NULL DEFAULT 0,
  new_value longtext NULL,
  marker_before tinyint(1) NOT NULL DEFAULT 0,
  marker_after tinyint(1) NOT NULL DEFAULT 0,
  action varchar(20) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created bigint(20) NOT NULL DEFAULT 0,
  run_id varchar(40) NOT NULL DEFAULT '',
  suggestion_id bigint(20) unsigned NOT NULL DEFAULT 0,
  undoes bigint(20) unsigned NOT NULL DEFAULT 0,
  undone_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY attachment_id (attachment_id),
  KEY run_id (run_id),
  KEY created (created)
) {$charset};",
            "CREATE TABLE {$r} (
  id varchar(40) NOT NULL,
  started bigint(20) NOT NULL DEFAULT 0,
  finished bigint(20) NOT NULL DEFAULT 0,
  mode varchar(10) NOT NULL DEFAULT '',
  run_trigger varchar(20) NOT NULL DEFAULT '',
  counts text NOT NULL,
  skipped text NOT NULL,
  stop_reason varchar(40) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY started (started)
) {$charset};",
        ];
    }

    /**
     * Creates or upgrades the tables and verifies the columns.
     *
     * @return array<string, list<string>> Missing columns per table; empty when all is well.
     */
    public static function install(\wpdb $wpdb): array
    {
        if (!function_exists('dbDelta') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach (self::statements($wpdb) as $sql) {
            dbDelta($sql);
        }
        $missing = self::verify($wpdb);
        update_option(self::OPTION, ['version' => self::VERSION, 'missing' => $missing], false);

        return $missing;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function verify(\wpdb $wpdb): array
    {
        $missing = [];
        foreach (self::expectedColumns() as $suffix => $columns) {
            $table = self::table($wpdb, $suffix);
            $found = $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($table) . '`', 0);
            $found = is_array($found) ? array_map('strtolower', array_map('strval', $found)) : [];
            $absent = array_values(array_diff($columns, $found));
            if ($absent !== []) {
                $missing[$suffix] = $absent;
            }
        }

        return $missing;
    }

    /** Runs install() when the stored version is older than this code. */
    public static function maybeUpgrade(\wpdb $wpdb): void
    {
        $stored = get_option(self::OPTION, []);
        $version = is_array($stored) ? (int) ($stored['version'] ?? 0) : 0;
        if ($version < self::VERSION) {
            self::install($wpdb);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public static function missing(): array
    {
        $stored = get_option(self::OPTION, []);
        $missing = is_array($stored) ? ($stored['missing'] ?? []) : [];

        return is_array($missing) ? $missing : [];
    }

    public static function drop(\wpdb $wpdb): void
    {
        foreach (array_keys(self::expectedColumns()) as $suffix) {
            $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql(self::table($wpdb, $suffix)) . '`');
        }
    }
}
