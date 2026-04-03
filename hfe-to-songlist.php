<?php
/**
 * Plugin Name: HFE to Songlist
 * Description: Upload an HFE floppy image and generate a copy-ready song list from MIDI and E-SEQ/FIL files.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: HFE Tools
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('HFE_TO_SONGLIST_PLUGIN_VERSION', '0.2.0');
define('HFE_TO_SONGLIST_PLUGIN_FILE', __FILE__);

require_once plugin_dir_path(__FILE__) . 'includes/class-hfe-to-songlist-plugin.php';

HFE_To_Songlist_Plugin::instance();
