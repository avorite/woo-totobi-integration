<?php
/**
 * Plugin Name: Woo Totobi Integration
 * Plugin URI:  https://github.com/avorite/woo-totobi-integration
 * Description: Imports Totobi Prom YML products into WooCommerce with category mapping, variations, scheduling, and logs.
 * Version:     0.1.23
 * Author:      Avorite
 * Author URI:  https://github.com/avorite
 * Text Domain: woo-totobi-integration
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WTI_VERSION', '0.1.23' );
define( 'WTI_PLUGIN_FILE', __FILE__ );
define( 'WTI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WTI_TEXT_DOMAIN', 'woo-totobi-integration' );
define( 'WTI_OPTION_KEY', 'wti_settings' );
define( 'WTI_CRON_HOOK', 'wti_scheduled_sync' );
define( 'WTI_CRON_CONTINUE_HOOK', 'wti_scheduled_sync_continue' );
define( 'WTI_AS_CONTINUE_HOOK', 'wti_background_sync_continue' );
define( 'WTI_AS_GROUP', 'wti-totobi' );

$wti_includes = array(
	'includes/class-wti-logger.php',
	'includes/class-wti-feed-client.php',
	'includes/class-wti-parser.php',
	'includes/class-wti-image-sync.php',
	'includes/class-wti-license.php',
	'includes/class-wti-autopoly-integration.php',
	'includes/class-wti-layout-pdf.php',
	'includes/class-wti-product-sync.php',
	'includes/class-wti-feed-index.php',
	'includes/class-wti-sync-report.php',
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
add_action( 'wp_enqueue_scripts', 'wti_enqueue_frontend_assets' );
add_action( 'woocommerce_single_product_summary', 'wti_render_single_price_notice', 11 );
add_action( 'pre_get_posts', 'wti_apply_vyd_catalog_filter', 20 );
add_action( 'woocommerce_product_query', 'wti_apply_vyd_woocommerce_product_filter', 20 );
add_filter( 'posts_where', 'wti_apply_vyd_posts_where_filter', 20, 2 );
add_filter( 'woocommerce_product_tabs', array( 'WTI_Layout_PDF', 'add_product_tab' ), 65 );
add_action( 'wp_ajax_wti_start_import', array( 'WTI_Importer', 'handle_ajax_start' ) );
add_action( 'wp_ajax_wti_process_batch', array( 'WTI_Importer', 'handle_ajax_batch' ) );
add_action( 'wp_ajax_wti_get_progress', array( 'WTI_Importer', 'handle_ajax_progress' ) );
add_action( 'wp_ajax_wti_pause_import', array( 'WTI_Importer', 'handle_ajax_pause' ) );
add_action( 'wp_ajax_wti_resume_import', array( 'WTI_Importer', 'handle_ajax_resume' ) );
add_action( 'wp_ajax_wti_reset_import', array( 'WTI_Importer', 'handle_ajax_reset' ) );
add_action( 'wp_ajax_wti_run_automatic_batch', array( 'WTI_Importer', 'handle_ajax_automatic_batch' ) );
add_action( WTI_CRON_HOOK, array( 'WTI_Importer', 'run_scheduled_sync' ) );
add_action( WTI_CRON_CONTINUE_HOOK, array( 'WTI_Importer', 'run_scheduled_batch' ) );
add_action( WTI_AS_CONTINUE_HOOK, array( 'WTI_Importer', 'run_background_batch' ) );

function wti_enqueue_frontend_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	wp_enqueue_style( 'wti-frontend', WTI_PLUGIN_URL . 'assets/css/frontend.css', array(), WTI_VERSION );
	wp_enqueue_script( 'wti-frontend', WTI_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), WTI_VERSION, true );

	$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;

	wp_localize_script(
		'wti-frontend',
		'wtiFrontend',
		array(
			'productPrice' => $product ? (float) $product->get_price() : 0,
			'currency'     => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
			'currencyPos'  => function_exists( 'get_option' ) ? get_option( 'woocommerce_currency_pos', 'right_space' ) : 'right_space',
			'decimalSep'   => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
			'thousandSep'  => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
			'decimals'     => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
			'qtyLabel'     => __( 'за %s шт.', WTI_TEXT_DOMAIN ),
		)
	);
}

function wti_render_single_price_notice() {
	echo '<div class="wti-price-notice">' . esc_html__( 'Ціна вказана за сам товар. Друк оплачується додатково.', WTI_TEXT_DOMAIN ) . '</div>';
}

function wti_apply_vyd_catalog_filter( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( 'product_cat' ) ) {
		return;
	}

	wti_add_vyd_tax_query( $query );
}

function wti_apply_vyd_woocommerce_product_filter( $query ) {
	if ( is_admin() ) {
		return;
	}

	wti_add_vyd_tax_query( $query );
}

function wti_add_vyd_tax_query( $query ) {
	if ( empty( $_GET['filter_vyd'] ) || ! taxonomy_exists( 'pa_vyd' ) ) {
		return;
	}

	$term_ids = wti_get_vyd_filter_term_ids();

	if ( empty( $term_ids ) ) {
		return;
	}

	$tax_query = (array) $query->get( 'tax_query' );

	foreach ( $tax_query as $tax_query_part ) {
		if ( is_array( $tax_query_part ) && isset( $tax_query_part['taxonomy'] ) && 'pa_vyd' === $tax_query_part['taxonomy'] ) {
			return;
		}
	}

	$tax_query[] = array(
		'taxonomy' => 'pa_vyd',
		'field'    => 'term_id',
		'terms'    => array_values( $term_ids ),
		'operator' => 'IN',
	);

	$query->set( 'tax_query', $tax_query );
}

function wti_apply_vyd_posts_where_filter( $where, $query ) {
	global $wpdb;

	if ( is_admin() || empty( $_GET['filter_vyd'] ) || ! taxonomy_exists( 'pa_vyd' ) ) {
		return $where;
	}

	$post_type = $query->get( 'post_type' );

	if ( is_array( $post_type ) ) {
		$is_product_query = in_array( 'product', $post_type, true );
	} else {
		$is_product_query = 'product' === $post_type || ( '' === (string) $post_type && $query->is_tax( 'product_cat' ) );
	}

	if ( ! $is_product_query ) {
		return $where;
	}

	$term_ids = wti_get_vyd_filter_term_ids();

	if ( empty( $term_ids ) ) {
		return $where;
	}

	$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
	$where       .= $wpdb->prepare(
		" AND {$wpdb->posts}.ID IN (
			SELECT tr.object_id
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy = 'pa_vyd' AND tt.term_id IN ($placeholders)
		)",
		$term_ids
	);

	return $where;
}

function wti_get_vyd_filter_slugs() {
	if ( empty( $_GET['filter_vyd'] ) ) {
		return array();
	}

	return array_values(
		array_unique(
			array_filter(
				array_map(
					'sanitize_title',
					explode( ',', wc_clean( wp_unslash( $_GET['filter_vyd'] ) ) )
				)
			)
		)
	);
}

function wti_get_vyd_filter_term_ids() {
	$slugs = wti_get_vyd_filter_slugs();

	if ( empty( $slugs ) || ! taxonomy_exists( 'pa_vyd' ) ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'pa_vyd',
			'hide_empty' => false,
			'slug'       => $slugs,
			'fields'     => 'ids',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return array();
	}

	return array_values( array_unique( array_map( 'absint', $terms ) ) );
}
