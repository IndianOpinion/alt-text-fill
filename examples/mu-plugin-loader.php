<?php

/**
 * Plugin Name: Alt Text Fill (must-use loader)
 * Description: Loads Alt Text Fill from wp-content/mu-plugins/alt-text-fill/.
 *
 * Copy this file to wp-content/mu-plugins/alt-text-fill-loader.php and the
 * plugin directory to wp-content/mu-plugins/alt-text-fill/. Must-use plugins
 * are never activated, so create the tables once with:
 *   wp eval '\IndianOpinion\AltTextFill\Plugin::activate();'
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/alt-text-fill/alt-text-fill.php';
