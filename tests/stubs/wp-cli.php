<?php

/**
 * A stand-in for WP_CLI that records output and turns halt() into an exception.
 */

declare(strict_types=1);

namespace {
    final class WP_CLI_Halt extends \RuntimeException
    {
        public function __construct(public readonly int $exitCode)
        {
            parent::__construct('halt ' . $exitCode);
        }
    }

    class WP_CLI
    {
        /** @var list<string> */
        public static array $lines = [];
        /** @var list<string> */
        public static array $warnings = [];
        /** @var list<array{format: string, rows: array<int, mixed>, fields: array<int, string>}> */
        public static array $tables = [];
        /** @var array<string, string> */
        public static array $commands = [];
        public static bool $confirm = true;

        public static function reset(): void
        {
            self::$lines = [];
            self::$warnings = [];
            self::$tables = [];
            self::$confirm = true;
        }

        public static function log(string $m): void
        {
            self::$lines[] = $m;
        }

        public static function warning(string $m): void
        {
            self::$warnings[] = $m;
        }

        public static function halt(int $code): void
        {
            throw new WP_CLI_Halt($code);
        }

        /** @param array<string, mixed> $assoc */
        public static function confirm(string $q, array $assoc = []): void
        {
            if (empty($assoc['yes']) && !self::$confirm) {
                throw new WP_CLI_Halt(0);
            }
        }

        public static function add_command(string $name, string $class): void
        {
            self::$commands[$name] = $class;
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param array<int, mixed>  $rows
     * @param array<int, string> $fields
     */
    function format_items(string $format, array $rows, array $fields): void
    {
        \WP_CLI::$tables[] = ['format' => $format, 'rows' => $rows, 'fields' => $fields];
    }
}
