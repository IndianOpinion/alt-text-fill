<?php

/**
 * Uninstall: drops the plugin's tables, options, own attachment meta and
 * scheduled events. Alt text is never changed or deleted.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\AltTextFill\Uninstaller::uninstall();
