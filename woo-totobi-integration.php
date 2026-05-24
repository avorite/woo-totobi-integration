<?php
/**
 * Plugin Name: Woo Totobi Integration
 * Plugin URI:  https://github.com/avorite/woo-totobi-integration
 * Description: Imports Totobi Prom YML products into WooCommerce with category mapping, variations, scheduling, and logs.
 * Version:     0.1.0
 * Author:      Avorite
 * Author URI:  https://github.com/avorite
 * Text Domain: woo-totobi-integration
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WTI_VERSION', '0.1.0' );
define( 'WTI_PLUGIN_FILE', __FILE__ );
define( 'WTI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WTI_TEXT_DOMAIN', 'woo-totobi-integration' );
define( 'WTI_OPTION_KEY', 'wti_settings' );
define( 'WTI_CRON_HOOK', 'wti_scheduled_sync' );

$wti_includes = array(
	'includes/class-wti-logger.php',
	'includes/class-wti-feed-client.php',
	'includes/class-wti-parser.php',
	'includes/class-wti-image-sync.php',
	'includes/class-wti-product-sync.php',
	'includes/class-wti-feed-index.php',
	'includes/class-wti-importer.php',
	'includes/class-wti-scheduler.php',
	'includes/class-wti-admin.php',
);

foreach ( $wti_includes as $wti_include ) {
	require_once WTI_PLUGIN_DIR . $wti_include;
}

function wti_load_textdomain() {
	load_plugin_textdomain( WTI_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'wti_load_textdomain' );
add_filter( 'cron_schedules', array( 'WTI_Scheduler', 'add_cron_schedules' ) );

register_activation_hook( __FILE__, array( 'WTI_Scheduler', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WTI_Scheduler', 'deactivate' ) );

add_action( 'admin_menu', array( 'WTI_Admin', 'add_admin_menu' ) );
add_action( 'admin_init', array( 'WTI_Admin', 'handle_post_actions' ) );
add_action( 'admin_enqueue_scripts', array( 'WTI_Admin', 'enqueue_assets' ) );
add_action( 'wp_ajax_wti_start_import', array( 'WTI_Importer', 'handle_ajax_start' ) );
add_action( 'wp_ajax_wti_process_batch', array( 'WTI_Importer', 'handle_ajax_batch' ) );
add_action( 'wp_ajax_wti_get_progress', array( 'WTI_Importer', 'handle_ajax_progress' ) );
add_action( 'wp_ajax_wti_pause_import', array( 'WTI_Importer', 'handle_ajax_pause' ) );
add_action( 'wp_ajax_wti_resume_import', array( 'WTI_Importer', 'handle_ajax_resume' ) );
add_action( WTI_CRON_HOOK, array( 'WTI_Importer', 'run_scheduled_sync' ) );
