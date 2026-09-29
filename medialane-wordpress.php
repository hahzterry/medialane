<?php
/**
 * Plugin Name: Tokenize, Protect & License Your Content
 * Description: Turn your posts into protected, licensed IP assets with Medialane. Prove authorship, set clear licensing terms, and keep control of how your content is used.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author: Medialane
 * License: GPL-2.0-or-later
 * Text Domain: medialane
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEDIALANE_PLUGIN_VERSION', '0.2.0' );
define( 'MEDIALANE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIALANE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEDIALANE_BACKEND_URL', 'https://api.medialane.io' );

require_once MEDIALANE_PLUGIN_DIR . 'includes/class-post-meta.php';
require_once MEDIALANE_PLUGIN_DIR . 'includes/class-settings.php';
require_once MEDIALANE_PLUGIN_DIR . 'includes/class-rest-proxy.php';
require_once MEDIALANE_PLUGIN_DIR . 'includes/class-metabox.php';
require_once MEDIALANE_PLUGIN_DIR . 'includes/class-bulk-action.php';
require_once MEDIALANE_PLUGIN_DIR . 'includes/class-asset-badge.php';

add_action( 'init', array( 'Medialane\\PostMeta', 'register' ) );
add_action( 'init', array( 'Medialane\\Settings', 'register' ) );
add_action( 'init', array( 'Medialane\\Metabox', 'register' ) );
add_action( 'init', array( 'Medialane\\BulkAction', 'register' ) );
add_action( 'init', array( 'Medialane\\AssetBadge', 'register' ) );
add_action( 'rest_api_init', array( 'Medialane\\RestProxy', 'register_routes' ) );

register_activation_hook( __FILE__, array( 'Medialane\\Settings', 'grant_default_capability' ) );
