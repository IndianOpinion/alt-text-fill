<?php

/**
 * Plugin Name:       Alt Text Fill
 * Plugin URI:        https://github.com/IndianOpinion/alt-text-fill
 * Description:       Suggests alt text for images that have none, using a vision model you choose. Nothing is written until a person approves it, and every write can be undone.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       alt-text-fill
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ALT_TEXT_FILL_VERSION', '0.1.0');

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\AltTextFill\Plugin::boot(__FILE__);
