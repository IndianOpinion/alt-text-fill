<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Integration;

/**
 * Enough of wpdb over mysqli to run the plugin's SQL against a real server.
 * Configured by ALT_TEXT_FILL_TEST_DB_* environment variables.
 */
final class MysqlWpdb extends \wpdb
{
    public int $insert_id = 0;
    private \mysqli $db;

    public function __construct(string $prefix = 'wptest_')
    {
        $this->prefix = $prefix;
        $this->posts = $prefix . 'posts';
        $this->postmeta = $prefix . 'postmeta';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new \mysqli(
            (string) (getenv('ALT_TEXT_FILL_TEST_DB_HOST') ?: 'localhost'),
            (string) (getenv('ALT_TEXT_FILL_TEST_DB_USER') ?: 'root'),
            (string) (getenv('ALT_TEXT_FILL_TEST_DB_PASSWORD') ?: ''),
            (string) (getenv('ALT_TEXT_FILL_TEST_DB_NAME') ?: 'atf_test'),
            (int) (getenv('ALT_TEXT_FILL_TEST_DB_PORT') ?: 3306),
            getenv('ALT_TEXT_FILL_TEST_DB_SOCKET') ?: null
        );
        $this->db->set_charset('utf8mb4');
    }

    public static function configured(): bool
    {
        return getenv('ALT_TEXT_FILL_TEST_DB_SOCKET') !== false || getenv('ALT_TEXT_FILL_TEST_DB_HOST') !== false;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        $i = 0;

        return (string) preg_replace_callback('/%[dsf]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? null;

            return match ($m[0]) {
                '%d' => (string) (int) $value,
                '%f' => sprintf('%F', (float) $value),
                default => "'" . $this->db->real_escape_string((string) $value) . "'",
            };
        }, $sql);
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;
        $result = $this->db->query($sql);
        if ($result instanceof \mysqli_result) {
            $result->free();
        }
        $this->insert_id = (int) $this->db->insert_id;

        return $this->db->affected_rows;
    }

    /** @return list<string> */
    public function get_col(string $sql, int $x = 0): array
    {
        $out = [];
        foreach ($this->rows($sql) as $row) {
            $out[] = (string) array_values($row)[$x];
        }

        return $out;
    }

    public function get_var(string $sql): ?string
    {
        $rows = $this->rows($sql);

        return $rows === [] ? null : (string) array_values($rows[0])[0];
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $sql, string $output = ARRAY_A): ?array
    {
        return $this->rows($sql)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $sql, string $output = ARRAY_A): array
    {
        return $this->rows($sql);
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int|false
    {
        return $this->write('INSERT', $table, $data);
    }

    /** @param array<string, mixed> $data */
    public function replace(string $table, array $data): int|false
    {
        return $this->write('REPLACE', $table, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int|false
    {
        $set = implode(', ', array_map(fn (string $k): string => "`{$k}` = " . $this->literal($data[$k]), array_keys($data)));
        $cond = implode(' AND ', array_map(fn (string $k): string => "`{$k}` = " . $this->literal($where[$k]), array_keys($where)));
        try {
            return (int) $this->query("UPDATE `{$table}` SET {$set} WHERE {$cond}");
        } catch (\mysqli_sql_exception $e) {
            return false;
        }
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int|false
    {
        $cond = implode(' AND ', array_map(fn (string $k): string => "`{$k}` = " . $this->literal($where[$k]), array_keys($where)));

        return (int) $this->query("DELETE FROM `{$table}` WHERE {$cond}");
    }

    /** @param array<string, mixed> $data */
    private function write(string $verb, string $table, array $data): int|false
    {
        $cols = implode(', ', array_map(static fn (string $k): string => "`{$k}`", array_keys($data)));
        $vals = implode(', ', array_map(fn ($v): string => $this->literal($v), array_values($data)));
        try {
            return (int) $this->query("{$verb} INTO `{$table}` ({$cols}) VALUES ({$vals})");
        } catch (\mysqli_sql_exception $e) {
            return false;
        }
    }

    private function literal(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return "'" . $this->db->real_escape_string((string) $v) . "'";
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $this->queries[] = $sql;
        $result = $this->db->query($sql);
        if (!$result instanceof \mysqli_result) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }
}
