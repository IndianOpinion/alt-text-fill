<?php

declare(strict_types=1);

// Constants PHPStan needs; WordPress defines them at runtime.
define('ABSPATH', __DIR__ . '/');
define('ALT_TEXT_FILL_VERSION', '0.1.0');
define('ARRAY_A', 'ARRAY_A');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

// gen-queue's registration function, defined only while gen-queue is
// active; the plugin calls it behind function_exists().
if (!function_exists('gen_queue_register_type')) {
    /**
     * @param array<string, mixed> $args
     */
    function gen_queue_register_type(string $type, mixed $handler, array $args = []): void
    {
    }
}
