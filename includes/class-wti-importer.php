<?php

defined( 'ABSPATH' ) || exit;

class WTI_Importer {
	const IMPORT_PLAN_TRANSIENT = 'wti_import_plan';
	const MEDIA_PLAN_TRANSIENT = 'wti_media_plan';
	const IMPORT_PLAN_FILE = 'wti-import-plan.json';
	const MEDIA_PLAN_FILE = 'wti-media-plan.json';
	const IMPORT_SESSION_OPTION = 'wti_import_session';
	const IMPORT_LOCK_TRANSIENT = 'wti_import_lock';
	const BATCH_LOCK_OPTION = 'wti_batch_lock';
	const STALE_SESSION_SECONDS = 2700;
	const SIMPLE_BATCH_SIZE = 20;
	const VARIABLE_BATCH_SIZE = 5;
	const MAX_VARIATIONS_PER_BATCH = 150;
	const MEDIA_BATCH_SIZE = 1;
	const REUSED_MEDIA_BATCH_SIZE = 20;

	public static function run_manual_sync() {
		return self::run_sync( array( 'manual' => true ) );
	}

	public static function run_scheduled_sync() {
		if ( class_exists( 'WTI_License' ) && ! WTI_License::is_valid() ) {
			WTI_Logger::log( 'Scheduled sync skipped: inactive license.' );
			return array( 'status' => 'license_inactive' );
		}

		$session = get_option( self::IMPORT_SESSION_OPTION, array() );
		$plan    = self::load_import_plan();

		if ( is_array( $session ) && 'automatic' === ( $session['sync_type'] ?? '' ) && in_array( $session['status'] ?? '', array( 'running', 'paused' ), true ) && is_array( $plan ) ) {
			self::schedule_background_continue();
			return self::session_response( $session );
		}

		self::recover_stale_import_session( 'automatic' );

		return self::start_scheduled_sync();
	}

	public static function run_scheduled_batch() {
		return self::run_background_batch();
	}

	public static function run_background_batch() {
		$result   = null;
		$deadline = time() + 20;

		do {
			$result = self::process_stored_batch( self::SIMPLE_BATCH_SIZE, self::VARIABLE_BATCH_SIZE, false );

			if ( is_wp_error( $result ) || ! empty( $result['completed'] ) ) {
				return $result;
			}
		} while ( time() < $deadline );

		self::schedule_background_continue( true );

		return $result;
	}

	public static function run_sync( $args = array() ) {
		$settings = WTI_Admin::get_settings();
		if ( class_exists( 'WTI_License' ) && ! WTI_License::is_valid( $settings ) ) {
			return array(
				'status'  => 'error',
				'message' => 'License is not active.',
			);
		}

		$feed_url = ! empty( $settings['feed_url'] ) ? $settings['feed_url'] : WTI_Feed_Client::DEFAULT_PROM_FEED_URL;
		$started = current_time( 'mysql' );
		$sync_type = empty( $args['manual'] ) ? 'automatic' : 'manual';
		$report_file = '';

		if ( ! self::acquire_import_lock() ) {
			return new WP_Error( 'sync_locked', 'Synchronization is already running.' );
		}

		self::cancel_pending_background_actions();
		self::delete_plan_files();

		self::save_sync_session(
			array(
				'status'      => 'running',
				'sync_type'   => $sync_type,
				'started_at'  => $started,
				'start_time'  => microtime( true ),
				'total'       => 0,
				'processed'   => 0,
				'settings'    => $settings,
			)
		);

		WTI_Logger::log( 'Sync scaffold started.', array( 'manual' => ! empty( $args['manual'] ), 'feed_url' => $feed_url ) );

		$xml = WTI_Feed_Client::fetch( $feed_url );

		if ( is_wp_error( $xml ) ) {
			WTI_Logger::log( 'Feed fetch failed.', array( 'error' => $xml->get_error_message() ) );
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $xml->get_error_message() ) );
			self::finish_sync_session( 'error', $sync_type, $started, $xml->get_error_message() );
			self::release_import_lock();

			return $xml;
		}

		$meta = WTI_Parser::parse_catalog_meta( $xml );

		if ( is_wp_error( $meta ) ) {
			WTI_Logger::log( 'Feed parse failed.', array( 'error' => $meta->get_error_message() ) );
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $meta->get_error_message() ) );
			self::finish_sync_session( 'error', $sync_type, $started, $meta->get_error_message() );
			self::release_import_lock();

			return $meta;
		}

		$catalog_date      = isset( $meta['date'] ) ? $meta['date'] : '';
		$last_catalog_date = (string) get_option( 'wti_last_catalog_date', '' );

		if ( empty( $args['manual'] ) && '' !== $catalog_date && $catalog_date === $last_catalog_date ) {
			$result = array(
				'status'       => 'skipped',
				'message'      => 'Catalog date is unchanged; scheduled sync skipped.',
				'catalog_date' => $catalog_date,
			);

			self::save_last_result( $started, $result );
			WTI_Logger::log( 'Scheduled sync skipped because catalog date is unchanged.', $result );
			self::finish_sync_session( 'completed', $sync_type, $started, $result['message'], $catalog_date );
			self::release_import_lock();

			return $result;
		}

		$offers = WTI_Parser::parse_offers(
			$xml,
			array(
				'allowed_paths' => isset( $settings['selected_paths'] ) ? $settings['selected_paths'] : WTI_Parser::DEFAULT_ALLOWED_PATHS,
			)
		);

		if ( is_wp_error( $offers ) ) {
			WTI_Logger::log( 'Offer parse failed.', array( 'error' => $offers->get_error_message() ) );
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $offers->get_error_message() ) );
			self::finish_sync_session( 'error', $sync_type, $started, $offers->get_error_message(), $catalog_date );
			self::release_import_lock();

			return $offers;
		}

		$plan            = WTI_Parser::build_import_plan( $offers );
		$import_images   = isset( $settings['import_images'] ) && 'yes' === $settings['import_images'];
		$full_plan       = $plan;
		$index_result    = WTI_Feed_Index::filter_changed_plan( $plan, false, self::should_mark_missing_outofstock( $settings ), self::missing_product_category_ids( $settings ), self::sync_price_args( $settings ) );
		$plan            = $index_result['plan'];
		$media_plan      = $import_images ? self::build_media_plan( $full_plan, self::sync_price_args( $settings ) ) : array();
		$summary         = WTI_Parser::summarize_plan( $plan );
		$summary['unchanged_products']  = (int) $index_result['unchanged'];
		$summary['deleted_products']    = (int) $index_result['deleted'];
		self::save_sync_session(
			array(
				'status'       => 'running',
				'sync_type'    => $sync_type,
				'started_at'   => $started,
				'start_time'   => microtime( true ),
				'catalog_date' => $catalog_date,
				'total'        => (int) $summary['total_products'] + (int) $summary['deleted_products'] + count( $media_plan ),
				'processed'    => 0,
				'total_media'  => count( $media_plan ),
				'media_offset' => 0,
				'plan'         => $summary,
				'settings'     => $settings,
			)
		);
		$simple_offset   = isset( $args['simple_offset'] ) ? absint( $args['simple_offset'] ) : 0;
		$variable_offset = isset( $args['variable_offset'] ) ? absint( $args['variable_offset'] ) : 0;
		$simple_limit    = isset( $settings['import_limit'] ) ? absint( $settings['import_limit'] ) : 10;
		$variable_limit  = isset( $settings['variable_limit'] ) ? absint( $settings['variable_limit'] ) : 1;
		$batch_mode      = isset( $args['simple_offset'] ) || isset( $args['variable_offset'] );
		$execution_plan  = $plan;

		if ( $batch_mode ) {
			$execution_plan['simple']   = $simple_limit > 0 ? array_slice( $plan['simple'], $simple_offset, $simple_limit ) : array();
			$execution_plan['variable'] = self::select_variable_batch( $plan['variable'], $variable_offset, $variable_limit );
		}

		$actions = WTI_Product_Sync::build_action_plan(
			$execution_plan,
			array(
				'dry_run'      => 'yes' === $settings['dry_run'],
				'catalog_date' => $catalog_date,
				'category_map' => isset( $settings['category_map'] ) ? $settings['category_map'] : array(),
				'markup_percent' => isset( $settings['markup_percent'] ) ? $settings['markup_percent'] : 0,
			)
		);
		$report_file = class_exists( 'WTI_Sync_Report' ) ? WTI_Sync_Report::create() : '';
		if ( '' !== $report_file ) {
			$session = get_option( self::IMPORT_SESSION_OPTION, array() );
			if ( is_array( $session ) ) {
				$session['report_file'] = $report_file;
				update_option( self::IMPORT_SESSION_OPTION, $session, false );
			}
		}
		$execution = WTI_Product_Sync::execute_action_plan(
			$actions,
			array(
				'dry_run'        => 'yes' === $settings['dry_run'],
				'import_limit'   => $batch_mode ? $simple_limit : max( 1, count( $execution_plan['simple'] ) ),
				'variable_limit' => $batch_mode ? count( $execution_plan['variable'] ) : max( 1, count( $execution_plan['variable'] ) ),
				'deleted_limit'  => isset( $plan['deleted'] ) ? count( $plan['deleted'] ) : 0,
				'simple_offset'  => 0,
				'variable_offset' => 0,
				'import_images'  => false,
				'product_status' => isset( $settings['product_status'] ) ? $settings['product_status'] : 'draft',
			)
		);
		if ( ! empty( $report_file ) && ! empty( $execution['report_rows'] ) && class_exists( 'WTI_Sync_Report' ) ) {
			WTI_Sync_Report::append_rows( $report_file, $execution['report_rows'] );
		}

		if ( $batch_mode && ! is_wp_error( $execution ) ) {
			$execution['simple_total']          = count( $plan['simple'] );
			$execution['variable_total']        = count( $plan['variable'] );
			$execution['simple_offset']         = $simple_offset;
			$execution['variable_offset']       = $variable_offset;
			$execution['next_simple_offset']    = min( count( $plan['simple'] ), $simple_offset + count( $execution_plan['simple'] ) );
			$execution['next_variable_offset']  = min( count( $plan['variable'] ), $variable_offset + count( $execution_plan['variable'] ) );
			$execution['simple_complete']       = $execution['next_simple_offset'] >= count( $plan['simple'] );
			$execution['variable_complete']     = $execution['next_variable_offset'] >= count( $plan['variable'] );
			$execution['skipped_simple']        = max( 0, count( $plan['simple'] ) - $execution['next_simple_offset'] );
			$execution['skipped_variable']      = max( 0, count( $plan['variable'] ) - $execution['next_variable_offset'] );
		}

		$result = array(
			'status'       => 'ok',
			'message'      => 'Sync completed.',
			'catalog_date' => $catalog_date,
			'offers'       => count( $offers ),
			'plan'         => $summary,
			'actions'      => self::build_action_summary( $actions ),
			'execution'    => $execution,
			'validation'   => self::build_validation_summary( $plan ),
			'examples'     => self::build_examples( $plan ),
			'created'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'errors'       => 0,
			'report_url'   => ! empty( $report_file ) && class_exists( 'WTI_Sync_Report' ) ? WTI_Sync_Report::url_for_file( $report_file ) : '',
		);

		self::save_last_result( $started, $result );
		self::save_last_catalog_date( $catalog_date );
		WTI_Feed_Index::save_from_store( $catalog_date, self::sync_price_args( $settings ) );
		self::flush_site_caches();
		WTI_Logger::log( 'Sync scaffold completed.', $result );
		self::finish_sync_session( 'completed', $sync_type, $started, $result['message'], $catalog_date, $execution );
		self::release_import_lock();

		return $result;
	}

	private static function start_scheduled_sync() {
		$settings  = WTI_Admin::get_settings();
		if ( class_exists( 'WTI_License' ) && ! WTI_License::is_valid( $settings ) ) {
			WTI_Logger::log( 'Scheduled sync skipped: inactive license.' );
			return array( 'status' => 'license_inactive' );
		}

		$feed_url  = ! empty( $settings['feed_url'] ) ? $settings['feed_url'] : WTI_Feed_Client::DEFAULT_PROM_FEED_URL;
		$started   = current_time( 'mysql' );

		if ( ! self::acquire_import_lock() ) {
			return new WP_Error( 'sync_locked', 'Synchronization is already running.' );
		}

		self::cancel_pending_background_actions();
		self::delete_plan_files();

		self::save_sync_session(
			array(
				'status'     => 'preparing',
				'sync_type'  => 'automatic',
				'started_at' => $started,
				'start_time' => microtime( true ),
				'total'      => 0,
				'processed'  => 0,
				'settings'   => $settings,
			)
		);

		$xml = WTI_Feed_Client::fetch( $feed_url );
		if ( is_wp_error( $xml ) ) {
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $xml->get_error_message() ) );
			self::finish_sync_session( 'error', 'automatic', $started, $xml->get_error_message() );
			self::release_import_lock();
			return $xml;
		}

		$meta = WTI_Parser::parse_catalog_meta( $xml );
		if ( is_wp_error( $meta ) ) {
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $meta->get_error_message() ) );
			self::finish_sync_session( 'error', 'automatic', $started, $meta->get_error_message() );
			self::release_import_lock();
			return $meta;
		}

		$catalog_date      = isset( $meta['date'] ) ? $meta['date'] : '';
		$last_catalog_date = (string) get_option( 'wti_last_catalog_date', '' );

		if ( '' !== $catalog_date && $catalog_date === $last_catalog_date ) {
			$result = array(
				'status'       => 'skipped',
				'message'      => 'Catalog date is unchanged; scheduled sync skipped.',
				'catalog_date' => $catalog_date,
			);

			self::save_last_result( $started, $result );
			self::finish_sync_session( 'completed', 'automatic', $started, $result['message'], $catalog_date );
			self::release_import_lock();
			return $result;
		}

		$offers = WTI_Parser::parse_offers(
			$xml,
			array(
				'allowed_paths' => isset( $settings['selected_paths'] ) ? $settings['selected_paths'] : WTI_Parser::DEFAULT_ALLOWED_PATHS,
			)
		);

		if ( is_wp_error( $offers ) ) {
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $offers->get_error_message() ) );
			self::finish_sync_session( 'error', 'automatic', $started, $offers->get_error_message(), $catalog_date );
			self::release_import_lock();
			return $offers;
		}

		$plan         = WTI_Parser::build_import_plan( $offers );
		$full_summary = WTI_Parser::summarize_plan( $plan );
		$full_plan    = $plan;
		$index_result = WTI_Feed_Index::filter_changed_plan( $plan, false, self::should_mark_missing_outofstock( $settings ), self::missing_product_category_ids( $settings ), self::sync_price_args( $settings ) );
		$plan         = $index_result['plan'];
		$media_plan   = isset( $settings['import_images'] ) && 'yes' === $settings['import_images'] ? self::build_media_plan( $full_plan, self::sync_price_args( $settings ) ) : array();
		$summary      = WTI_Parser::summarize_plan( $plan );
		$summary['unchanged_products']  = (int) $index_result['unchanged'];
		$summary['deleted_products']    = (int) $index_result['deleted'];
		$summary['feed_total_products'] = (int) $full_summary['total_products'];
		$total = (int) $summary['simple_products'] + (int) $summary['variable_products'] + (int) $summary['deleted_products'] + count( $media_plan );

		self::save_import_plan( $plan );
		self::save_media_plan( $media_plan );

		self::save_sync_session(
			array(
				'status'             => 'running',
				'sync_type'          => 'automatic',
				'started_at'         => $started,
				'start_time'         => microtime( true ),
				'catalog_date'       => $catalog_date,
				'dry_run'            => 'yes' === $settings['dry_run'],
				'settings'           => $settings,
				'total'              => $total,
				'total_simple'       => (int) $summary['simple_products'],
				'total_variable'     => (int) $summary['variable_products'],
				'total_deleted'      => (int) $summary['deleted_products'],
				'total_variations'   => (int) $summary['variations'],
				'total_media'        => count( $media_plan ),
				'media_offset'       => 0,
				'skipped_unchanged'  => (int) $summary['unchanged_products'],
				'report_file'        => class_exists( 'WTI_Sync_Report' ) ? WTI_Sync_Report::create() : '',
				'plan'               => $summary,
				'validation'         => self::build_validation_summary( $plan ),
			)
		);

		WTI_Logger::log( 'Scheduled AJAX-style import started.', array( 'total' => $total, 'catalog_date' => $catalog_date ) );

		self::schedule_background_continue( true );

		return self::session_response( get_option( self::IMPORT_SESSION_OPTION, array() ) );
	}

	public static function handle_ajax_start() {
		self::check_ajax_request();

		if ( ! self::acquire_import_lock() ) {
			wp_send_json_error( 'Synchronization is already running or paused. Resume and finish it before starting a new one.' );
		}

		self::cancel_pending_background_actions();
		self::delete_plan_files();

		$settings = WTI_Admin::get_settings();
		if ( class_exists( 'WTI_License' ) && ! WTI_License::is_valid( $settings ) ) {
			self::release_import_lock();
			wp_send_json_error( 'License is not active.' );
		}

		$feed_url = ! empty( $settings['feed_url'] ) ? $settings['feed_url'] : WTI_Feed_Client::DEFAULT_PROM_FEED_URL;
		$started = current_time( 'mysql' );

		self::save_sync_session(
			array(
				'status'     => 'preparing',
				'sync_type'  => 'manual',
				'started_at' => $started,
				'start_time' => microtime( true ),
				'total'      => 0,
				'processed'  => 0,
				'settings'   => $settings,
			)
		);

		$xml = WTI_Feed_Client::fetch( $feed_url );
		if ( is_wp_error( $xml ) ) {
			self::fail_ajax_start( $xml->get_error_message() );
		}

		$meta = WTI_Parser::parse_catalog_meta( $xml );
		if ( is_wp_error( $meta ) ) {
			self::fail_ajax_start( $meta->get_error_message() );
		}

		$offers = WTI_Parser::parse_offers(
			$xml,
			array(
				'allowed_paths' => isset( $settings['selected_paths'] ) ? $settings['selected_paths'] : WTI_Parser::DEFAULT_ALLOWED_PATHS,
			)
		);
		if ( is_wp_error( $offers ) ) {
			self::fail_ajax_start( $offers->get_error_message() );
		}

		$plan    = WTI_Parser::build_import_plan( $offers );
		$full_summary = WTI_Parser::summarize_plan( $plan );
		$full_plan    = $plan;
		$index_result = WTI_Feed_Index::filter_changed_plan( $plan, false, self::should_mark_missing_outofstock( $settings ), self::missing_product_category_ids( $settings ), self::sync_price_args( $settings ) );
		$plan         = $index_result['plan'];
		$media_plan   = isset( $settings['import_images'] ) && 'yes' === $settings['import_images'] ? self::build_media_plan( $full_plan, self::sync_price_args( $settings ) ) : array();
		$summary      = WTI_Parser::summarize_plan( $plan );
		$summary['unchanged_products']   = (int) $index_result['unchanged'];
		$summary['deleted_products']     = (int) $index_result['deleted'];
		$summary['feed_total_products']  = (int) $full_summary['total_products'];
		$total        = (int) $summary['simple_products'] + (int) $summary['variable_products'] + (int) $summary['deleted_products'] + count( $media_plan );
		$feed_total   = (int) $full_summary['simple_products'] + (int) $full_summary['variable_products'];

		if ( $feed_total < 1 ) {
			self::fail_ajax_start( 'No products found for selected Totobi categories.' );
		}

		self::save_import_plan( $plan );
		self::save_media_plan( $media_plan );

		$session = array(
			'status'              => 'running',
			'sync_type'           => 'manual',
			'started_at'          => $started,
			'updated_at'          => current_time( 'mysql' ),
			'updated_ts'          => time(),
			'start_time'          => microtime( true ),
			'catalog_date'        => isset( $meta['date'] ) ? $meta['date'] : '',
			'dry_run'             => 'yes' === $settings['dry_run'],
			'settings'            => $settings,
			'total'               => $total,
			'total_simple'        => (int) $summary['simple_products'],
			'total_variable'      => (int) $summary['variable_products'],
			'total_deleted'       => (int) $summary['deleted_products'],
			'total_variations'    => (int) $summary['variations'],
			'total_media'         => count( $media_plan ),
			'processed'           => 0,
			'simple_offset'       => 0,
			'variable_offset'     => 0,
			'deleted_offset'      => 0,
			'media_offset'        => 0,
			'created_simple'      => 0,
			'updated_simple'      => 0,
			'created_variable'    => 0,
			'updated_variable'    => 0,
			'created_variation'   => 0,
			'updated_variation'   => 0,
			'imported_images'     => 0,
			'reused_images'       => 0,
			'skipped_images'      => 0,
			'skipped_unchanged'   => (int) $summary['unchanged_products'],
			'deleted_outofstock'  => 0,
			'errors'              => 0,
			'error_samples'       => array(),
			'report_file'         => class_exists( 'WTI_Sync_Report' ) ? WTI_Sync_Report::create() : '',
			'plan'                => $summary,
			'validation'          => self::build_validation_summary( $plan ),
		);

		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		WTI_Logger::log( 'AJAX import started.', array( 'total' => $total, 'unchanged' => (int) $summary['unchanged_products'], 'deleted' => (int) $summary['deleted_products'], 'catalog_date' => $session['catalog_date'], 'dry_run' => $session['dry_run'] ) );
		self::schedule_background_continue( true );

		wp_send_json_success( self::session_response( $session ) );
	}

	public static function handle_ajax_batch() {
		self::check_ajax_request();

		self::process_stored_batch( null, null, true );
	}

	public static function handle_ajax_automatic_batch() {
		self::check_ajax_request();

		$session = get_option( self::IMPORT_SESSION_OPTION, array() );

		if ( ! is_array( $session ) || 'automatic' !== ( $session['sync_type'] ?? '' ) || 'running' !== ( $session['status'] ?? '' ) ) {
			wp_send_json_success( self::session_response( $session ) );
		}

		self::schedule_background_continue();
		$result = self::session_response( $session );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	private static function process_stored_batch( $simple_size = null, $variable_size = null, $send_json = true ) {
		self::refresh_import_lock();

		if ( ! self::acquire_batch_lock() ) {
			$response         = self::session_response( get_option( self::IMPORT_SESSION_OPTION, array() ) );
			$response['busy'] = true;

			if ( $send_json ) {
				wp_send_json_success( $response );
			}

			return $response;
		}

		$result = self::process_stored_batch_unlocked( $simple_size, $variable_size, false );
		self::release_batch_lock();

		if ( is_wp_error( $result ) ) {
			if ( $send_json ) {
				wp_send_json_error( $result->get_error_message() );
			}

			return $result;
		}

		if ( $send_json ) {
			wp_send_json_success( $result );
		}

		return $result;
	}

	private static function select_variable_batch( $items, $offset, $limit ) {
		$items  = is_array( $items ) ? $items : array();
		$limit  = max( 0, absint( $limit ) );
		$batch  = array();
		$weight = 0;

		if ( 0 === $limit ) {
			return $batch;
		}

		foreach ( array_slice( $items, absint( $offset ), $limit ) as $item ) {
			$variation_count = isset( $item['variations'] ) && is_array( $item['variations'] ) ? count( $item['variations'] ) : 1;
			$variation_count = max( 1, $variation_count );

			if ( $batch && $weight + $variation_count > self::MAX_VARIATIONS_PER_BATCH ) {
				break;
			}

			$batch[] = $item;
			$weight += $variation_count;

			if ( $weight >= self::MAX_VARIATIONS_PER_BATCH ) {
				break;
			}
		}

		return $batch;
	}

	private static function select_media_batch( $items, $offset ) {
		$items = is_array( $items ) ? $items : array();
		$batch = array();
		$existing_urls = self::existing_media_source_urls();

		foreach ( array_slice( $items, absint( $offset ), self::REUSED_MEDIA_BATCH_SIZE ) as $item ) {
			$requires_download = false;
			foreach ( (array) ( $item['pictures'] ?? array() ) as $url ) {
				if ( ! isset( $existing_urls[ (string) $url ] ) ) {
					$requires_download = true;
					break;
				}
			}

			if ( $requires_download ) {
				if ( ! $batch ) {
					$batch[] = $item;
				}
				break;
			}

			$batch[] = $item;
		}

		return $batch;
	}

	private static function existing_media_source_urls() {
		static $urls = null;
		if ( null === $urls ) {
			$urls = array_fill_keys(
				array_map(
					'strval',
					(array) $GLOBALS['wpdb']->get_col(
						"SELECT DISTINCT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_wti_source_image_url'"
					)
				),
				true
			);
		}

		return $urls;
	}

	private static function process_stored_batch_unlocked( $simple_size = null, $variable_size = null, $send_json = true ) {
		self::refresh_import_lock();

		$plan       = self::load_import_plan();
		$media_plan = self::load_media_plan();
		if ( ! is_array( $plan ) ) {
			$session = get_option( self::IMPORT_SESSION_OPTION, array() );
			if ( is_array( $session ) && 'completed' === ( $session['status'] ?? '' ) ) {
				$response              = self::session_response( $session );
				$response['completed'] = true;

				return $response;
			}

			if ( is_array( $session ) ) {
				$session['status']      = 'error';
				$session['message']     = 'Import session expired. Start import again.';
				$session['finished_at'] = current_time( 'mysql' );
				update_option( self::IMPORT_SESSION_OPTION, $session, false );
			}
			self::release_import_lock();
			return self::batch_error( 'Import session expired. Start import again.', $send_json );
		}

		if ( ! is_array( $media_plan ) ) {
			$media_plan = array();
		}

		$session = get_option( self::IMPORT_SESSION_OPTION, array() );
		if ( empty( $session ) || 'paused' === $session['status'] ) {
			if ( empty( $session ) ) {
				self::release_import_lock();
			}
			return self::batch_error( 'Import is paused or not running.', $send_json );
		}

		$settings      = isset( $session['settings'] ) && is_array( $session['settings'] ) ? $session['settings'] : WTI_Admin::get_settings();
		$simple_size   = self::SIMPLE_BATCH_SIZE;
		$variable_size = self::VARIABLE_BATCH_SIZE;

		$simple_total   = count( $plan['simple'] );
		$variable_total = count( $plan['variable'] );
		$deleted_total  = isset( $plan['deleted'] ) && is_array( $plan['deleted'] ) ? count( $plan['deleted'] ) : 0;
		$media_total    = count( $media_plan );
		$batch_plan     = $plan;
		$stage          = 'simple';

		$batch_plan['simple']   = array();
		$batch_plan['variable'] = array();
		$batch_plan['deleted']  = array();

		if ( $session['simple_offset'] < $simple_total ) {
			$batch_plan['simple'] = array_slice( $plan['simple'], (int) $session['simple_offset'], $simple_size );
			$session['simple_offset'] += count( $batch_plan['simple'] );
		} elseif ( $session['variable_offset'] < $variable_total ) {
			$stage = 'variable';
			$batch_plan['variable'] = self::select_variable_batch( $plan['variable'], (int) $session['variable_offset'], $variable_size );
			$session['variable_offset'] += count( $batch_plan['variable'] );
		} elseif ( $session['deleted_offset'] < $deleted_total ) {
			$stage = 'deleted';
			$batch_plan['deleted'] = array_slice( $plan['deleted'], (int) $session['deleted_offset'], 50 );
			$session['deleted_offset'] += count( $batch_plan['deleted'] );
		} elseif ( ( $session['media_offset'] ?? 0 ) < $media_total ) {
			$stage       = 'media';
			$media_batch = self::select_media_batch( $media_plan, (int) $session['media_offset'] );
			$session['media_offset'] += count( $media_batch );
		}

		if ( empty( $media_batch ) ) {
			$media_batch = array();
		}

		if ( empty( $batch_plan['simple'] ) && empty( $batch_plan['variable'] ) && empty( $batch_plan['deleted'] ) && empty( $media_batch ) ) {
			return self::complete_ajax_session( $session, array(), '', $send_json );
		}

		$actions = empty( $media_batch ) ? WTI_Product_Sync::build_action_plan(
			$batch_plan,
			array(
				'dry_run'      => ! empty( $session['dry_run'] ),
				'catalog_date' => $session['catalog_date'],
				'category_map' => isset( $settings['category_map'] ) ? $settings['category_map'] : array(),
				'markup_percent' => isset( $settings['markup_percent'] ) ? $settings['markup_percent'] : 0,
			)
		) : array();

		$execution = empty( $media_batch ) ? WTI_Product_Sync::execute_action_plan(
			$actions,
			array(
				'dry_run'        => ! empty( $session['dry_run'] ),
				'import_limit'   => count( $batch_plan['simple'] ),
				'variable_limit' => count( $batch_plan['variable'] ),
				'deleted_limit'  => count( $batch_plan['deleted'] ),
				'import_images'  => false,
				'product_status' => isset( $settings['product_status'] ) ? $settings['product_status'] : 'draft',
			)
		) : WTI_Product_Sync::sync_offer_images_batch( $media_batch, ! empty( $session['dry_run'] ) );

		if ( is_wp_error( $execution ) ) {
			self::release_import_lock();
			return self::batch_error( $execution->get_error_message(), $send_json );
		}

		self::merge_execution_into_session( $session, $execution );
		$log_entries = self::build_batch_log_entries( $execution, $stage, $actions );
		self::append_session_log_entries( $session, $log_entries );
		if ( ! empty( $session['report_file'] ) && ! empty( $execution['report_rows'] ) && class_exists( 'WTI_Sync_Report' ) ) {
			WTI_Sync_Report::append_rows( $session['report_file'], $execution['report_rows'] );
		}
		$session['processed'] = min( $session['total'], (int) $session['simple_offset'] + (int) $session['variable_offset'] + (int) $session['deleted_offset'] + (int) ( $session['media_offset'] ?? 0 ) );
		$session['status']    = 'running';
		$session['stage']     = $stage;
		$session['updated_at'] = current_time( 'mysql' );
		$session['updated_ts'] = time();

		if ( $session['simple_offset'] >= $simple_total && $session['variable_offset'] >= $variable_total && $session['deleted_offset'] >= $deleted_total && ( $session['media_offset'] ?? 0 ) >= $media_total ) {
			return self::complete_ajax_session( $session, $execution, $stage, $send_json );
		}

		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		$response                 = self::session_response( $session );
		$response['completed']    = false;
		$response['stage']        = $stage;
		$response['log_entries']  = $log_entries;

		self::schedule_background_continue();

		if ( $send_json ) {
			wp_send_json_success( $response );
		}

		return $response;
	}

	private static function sync_price_args( $settings ) {
		return array(
			'markup_percent' => isset( $settings['markup_percent'] ) ? $settings['markup_percent'] : 0,
		);
	}

	private static function plan_storage_dir() {
		$upload_dir = wp_upload_dir();
		$base_dir   = empty( $upload_dir['basedir'] ) ? WP_CONTENT_DIR . '/uploads' : $upload_dir['basedir'];
		$dir        = trailingslashit( $base_dir ) . 'wti-sync';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}

		return $dir;
	}

	private static function plan_file_path( $file_name ) {
		$dir = self::plan_storage_dir();

		return '' === $dir ? '' : trailingslashit( $dir ) . $file_name;
	}

	private static function save_import_plan( $plan ) {
		self::save_plan_file( self::IMPORT_PLAN_FILE, $plan );
		delete_transient( self::IMPORT_PLAN_TRANSIENT );
	}

	private static function save_media_plan( $plan ) {
		self::save_plan_file( self::MEDIA_PLAN_FILE, $plan );
		delete_transient( self::MEDIA_PLAN_TRANSIENT );
	}

	private static function load_import_plan() {
		return self::load_plan_file( self::IMPORT_PLAN_FILE, self::IMPORT_PLAN_TRANSIENT );
	}

	private static function load_media_plan() {
		return self::load_plan_file( self::MEDIA_PLAN_FILE, self::MEDIA_PLAN_TRANSIENT );
	}

	private static function save_plan_file( $file_name, $plan ) {
		$path = self::plan_file_path( $file_name );

		if ( '' === $path ) {
			set_transient( self::IMPORT_PLAN_FILE === $file_name ? self::IMPORT_PLAN_TRANSIENT : self::MEDIA_PLAN_TRANSIENT, $plan, 12 * HOUR_IN_SECONDS );
			return;
		}

		$json = wp_json_encode( $plan );
		if ( false === $json ) {
			return;
		}

		@file_put_contents( $path, $json, LOCK_EX );
	}

	private static function load_plan_file( $file_name, $transient_name ) {
		$path = self::plan_file_path( $file_name );

		if ( '' !== $path && file_exists( $path ) ) {
			$json = file_get_contents( $path );
			$data = json_decode( (string) $json, true );

			if ( is_array( $data ) ) {
				return $data;
			}
		}

		$legacy = get_transient( $transient_name );
		return is_array( $legacy ) ? $legacy : false;
	}

	private static function delete_plan_files() {
		foreach ( array( self::IMPORT_PLAN_FILE, self::MEDIA_PLAN_FILE ) as $file_name ) {
			$path = self::plan_file_path( $file_name );
			if ( '' !== $path && file_exists( $path ) ) {
				@unlink( $path );
			}
		}

		delete_transient( self::IMPORT_PLAN_TRANSIENT );
		delete_transient( self::MEDIA_PLAN_TRANSIENT );
	}

	public static function handle_ajax_progress() {
		self::check_ajax_request();
		$response = self::session_response( get_option( self::IMPORT_SESSION_OPTION, array() ) );
		$plan     = self::load_import_plan();

		$response['can_resume'] = in_array( $response['status'], array( 'running', 'paused' ), true ) && is_array( $plan );
		$response['plan_exists'] = is_array( $plan );

		wp_send_json_success( $response );
	}

	public static function handle_ajax_pause() {
		self::check_ajax_request();
		$session           = get_option( self::IMPORT_SESSION_OPTION, array() );
		$session['status'] = 'paused';
		$session['updated_at'] = current_time( 'mysql' );
		$session['updated_ts'] = time();
		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		self::release_import_lock();
		wp_send_json_success( self::session_response( $session ) );
	}

	public static function handle_ajax_resume() {
		self::check_ajax_request();
		$session           = get_option( self::IMPORT_SESSION_OPTION, array() );
		$session['status'] = 'running';
		$session['updated_at'] = current_time( 'mysql' );
		$session['updated_ts'] = time();
		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		self::refresh_import_lock();
		self::schedule_background_continue();
		wp_send_json_success( self::session_response( $session ) );
	}

	public static function handle_ajax_reset() {
		self::check_ajax_request();
		self::delete_plan_files();
		delete_option( self::IMPORT_SESSION_OPTION );
		delete_option( self::BATCH_LOCK_OPTION );
		self::unschedule_automatic_continue();
		self::release_import_lock();
		wp_send_json_success( array( 'reset' => true ) );
	}

	private static function check_ajax_request() {
		check_ajax_referer( 'wti_ajax_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
	}

	private static function merge_execution_into_session( &$session, $execution ) {
		foreach ( array( 'created_simple', 'updated_simple', 'created_variable', 'updated_variable', 'created_variation', 'updated_variation', 'imported_images', 'reused_images', 'skipped_images', 'skipped_unchanged', 'deleted_outofstock' ) as $key ) {
			$session[ $key ] = isset( $session[ $key ] ) ? (int) $session[ $key ] : 0;
			$session[ $key ] += isset( $execution[ $key ] ) ? (int) $execution[ $key ] : 0;
		}

		$errors = isset( $execution['errors'] ) && is_array( $execution['errors'] ) ? $execution['errors'] : array();
		$session['errors'] = isset( $session['errors'] ) ? (int) $session['errors'] : 0;
		$session['errors'] += count( $errors );

		if ( ! isset( $session['error_samples'] ) || ! is_array( $session['error_samples'] ) ) {
			$session['error_samples'] = array();
		}

		foreach ( array_slice( $errors, 0, 5 ) as $error ) {
			if ( count( $session['error_samples'] ) >= 10 ) {
				break;
			}
			$session['error_samples'][] = $error;
		}
	}

	private static function append_session_log_entries( &$session, $entries ) {
		if ( empty( $entries ) || ! is_array( $entries ) ) {
			return;
		}

		if ( ! isset( $session['log_entries'] ) || ! is_array( $session['log_entries'] ) ) {
			$session['log_entries'] = array();
		}

		foreach ( $entries as $entry ) {
			$session['log_entries'][] = (string) $entry;
		}

		$session['log_entries'] = array_slice( $session['log_entries'], -40 );
	}

	private static function complete_ajax_session( $session, $last_execution = array(), $stage = '', $send_json = true ) {
		$session['status']      = 'completed';
		$session['finished_at'] = current_time( 'mysql' );
		$session['updated_at']  = current_time( 'mysql' );
		$session['updated_ts']  = time();
		$session['stage']       = $stage;
		$session['duration']    = isset( $session['start_time'] ) ? round( microtime( true ) - (float) $session['start_time'], 2 ) : 0;
		self::append_session_log_entries( $session, self::build_batch_log_entries( $last_execution, $stage, array() ) );

		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		self::delete_plan_files();
		self::unschedule_automatic_continue();
		self::release_import_lock();

		$result = array(
			'status'       => 'ok',
			'message'      => 'AJAX batch import completed.',
			'catalog_date' => isset( $session['catalog_date'] ) ? $session['catalog_date'] : '',
			'plan'         => isset( $session['plan'] ) ? $session['plan'] : array(),
			'execution'    => $session,
			'validation'   => isset( $session['validation'] ) ? $session['validation'] : array(),
			'created'      => (int) $session['created_simple'] + (int) $session['created_variable'] + (int) $session['created_variation'],
			'updated'      => (int) $session['updated_simple'] + (int) $session['updated_variable'] + (int) $session['updated_variation'],
			'skipped'      => 0,
			'errors'       => (int) $session['errors'],
			'report_url'   => ! empty( $session['report_file'] ) && class_exists( 'WTI_Sync_Report' ) ? WTI_Sync_Report::url_for_file( $session['report_file'] ) : '',
		);

		self::save_last_result( isset( $session['started_at'] ) ? $session['started_at'] : current_time( 'mysql' ), $result );
		self::save_last_catalog_date( isset( $session['catalog_date'] ) ? $session['catalog_date'] : '' );
		WTI_Feed_Index::save_from_store( isset( $session['catalog_date'] ) ? $session['catalog_date'] : '', self::sync_price_args( isset( $session['settings'] ) && is_array( $session['settings'] ) ? $session['settings'] : WTI_Admin::get_settings() ) );
		self::flush_site_caches();
		WTI_Logger::log( 'AJAX import completed.', $result );

		$response                = self::session_response( $session );
		$response['completed']   = true;
		$response['stage']       = $stage;
		$response['log_entries'] = isset( $session['log_entries'] ) ? $session['log_entries'] : array();
		if ( $send_json ) {
			wp_send_json_success( $response );
		}

		return $response;
	}

	private static function batch_error( $message, $send_json = true ) {
		if ( $send_json ) {
			wp_send_json_error( $message );
		}

		return new WP_Error( 'wti_batch_error', $message );
	}

	private static function acquire_batch_lock() {
		$now  = time();
		$lock = (int) get_option( self::BATCH_LOCK_OPTION, 0 );

		if ( $lock && $lock > ( $now - 180 ) ) {
			return false;
		}

		if ( $lock ) {
			delete_option( self::BATCH_LOCK_OPTION );
		}

		return add_option( self::BATCH_LOCK_OPTION, $now, '', false );
	}

	private static function release_batch_lock() {
		delete_option( self::BATCH_LOCK_OPTION );
	}

	private static function schedule_background_continue( $force = false ) {
		if ( defined( 'WTI_AS_CONTINUE_HOOK' ) && function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_enqueue_async_action' ) ) {
			$group = defined( 'WTI_AS_GROUP' ) ? WTI_AS_GROUP : '';

			if ( ! $force && as_next_scheduled_action( WTI_AS_CONTINUE_HOOK, array(), $group ) ) {
				return;
			}

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5, WTI_AS_CONTINUE_HOOK, array(), $group, false );
			} else {
				as_enqueue_async_action( WTI_AS_CONTINUE_HOOK, array(), $group, false );
			}
			return;
		}

		if ( ! defined( 'WTI_CRON_CONTINUE_HOOK' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( WTI_CRON_CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 15, WTI_CRON_CONTINUE_HOOK );
		}
	}

	private static function cancel_pending_background_actions() {
		if ( defined( 'WTI_AS_CONTINUE_HOOK' ) && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( WTI_AS_CONTINUE_HOOK, array(), defined( 'WTI_AS_GROUP' ) ? WTI_AS_GROUP : '' );
		}

		if ( defined( 'WTI_CRON_CONTINUE_HOOK' ) ) {
			$timestamp = wp_next_scheduled( WTI_CRON_CONTINUE_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, WTI_CRON_CONTINUE_HOOK );
			}
		}

		delete_option( self::BATCH_LOCK_OPTION );
	}

	private static function schedule_automatic_continue() {
		self::schedule_background_continue();
	}

	private static function unschedule_automatic_continue() {
		if ( defined( 'WTI_AS_CONTINUE_HOOK' ) && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( WTI_AS_CONTINUE_HOOK, array(), defined( 'WTI_AS_GROUP' ) ? WTI_AS_GROUP : '' );
		}

		if ( ! defined( 'WTI_CRON_CONTINUE_HOOK' ) ) {
			return;
		}

		$timestamp = wp_next_scheduled( WTI_CRON_CONTINUE_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, WTI_CRON_CONTINUE_HOOK );
		}
	}

	private static function flush_site_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( class_exists( 'W3TC\\Dispatcher' ) ) {
			try {
				$plugin = \W3TC\Dispatcher::component( 'CacheFlush' );
				if ( $plugin && method_exists( $plugin, 'flush_all' ) ) {
					$plugin->flush_all();
				}
			} catch ( Exception $exception ) {
				// Cache plugins should never break a completed import.
			}
		}

		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
			LiteSpeed_Cache_API::purge_all();
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		do_action( 'wti_after_cache_flush' );
		WTI_Logger::log( 'Site caches flushed after Totobi sync.' );
	}

	private static function session_response( $session ) {
		if ( ! is_array( $session ) ) {
			$session = array();
		}

		return array(
			'status'             => isset( $session['status'] ) ? $session['status'] : '',
			'sync_type'          => isset( $session['sync_type'] ) ? $session['sync_type'] : '',
			'total'              => isset( $session['total'] ) ? (int) $session['total'] : 0,
			'total_simple'       => isset( $session['total_simple'] ) ? (int) $session['total_simple'] : 0,
			'total_variable'     => isset( $session['total_variable'] ) ? (int) $session['total_variable'] : 0,
			'total_deleted'      => isset( $session['total_deleted'] ) ? (int) $session['total_deleted'] : 0,
			'total_media'        => isset( $session['total_media'] ) ? (int) $session['total_media'] : 0,
			'total_variations'   => isset( $session['total_variations'] ) ? (int) $session['total_variations'] : 0,
			'processed'          => isset( $session['processed'] ) ? (int) $session['processed'] : 0,
			'simple_offset'      => isset( $session['simple_offset'] ) ? (int) $session['simple_offset'] : 0,
			'variable_offset'    => isset( $session['variable_offset'] ) ? (int) $session['variable_offset'] : 0,
			'deleted_offset'     => isset( $session['deleted_offset'] ) ? (int) $session['deleted_offset'] : 0,
			'media_offset'       => isset( $session['media_offset'] ) ? (int) $session['media_offset'] : 0,
			'created_simple'     => isset( $session['created_simple'] ) ? (int) $session['created_simple'] : 0,
			'updated_simple'     => isset( $session['updated_simple'] ) ? (int) $session['updated_simple'] : 0,
			'created_variable'   => isset( $session['created_variable'] ) ? (int) $session['created_variable'] : 0,
			'updated_variable'   => isset( $session['updated_variable'] ) ? (int) $session['updated_variable'] : 0,
			'created_variation'  => isset( $session['created_variation'] ) ? (int) $session['created_variation'] : 0,
			'updated_variation'  => isset( $session['updated_variation'] ) ? (int) $session['updated_variation'] : 0,
			'imported_images'    => isset( $session['imported_images'] ) ? (int) $session['imported_images'] : 0,
			'reused_images'      => isset( $session['reused_images'] ) ? (int) $session['reused_images'] : 0,
			'skipped_images'     => isset( $session['skipped_images'] ) ? (int) $session['skipped_images'] : 0,
			'skipped_unchanged'  => isset( $session['skipped_unchanged'] ) ? (int) $session['skipped_unchanged'] : 0,
			'deleted_outofstock' => isset( $session['deleted_outofstock'] ) ? (int) $session['deleted_outofstock'] : 0,
			'errors'             => isset( $session['errors'] ) ? (int) $session['errors'] : 0,
			'error_samples'      => isset( $session['error_samples'] ) ? $session['error_samples'] : array(),
			'catalog_date'       => isset( $session['catalog_date'] ) ? $session['catalog_date'] : '',
			'report_url'         => ! empty( $session['report_file'] ) && class_exists( 'WTI_Sync_Report' ) ? WTI_Sync_Report::url_for_file( $session['report_file'] ) : '',
			'dry_run'            => ! empty( $session['dry_run'] ),
			'stage'              => isset( $session['stage'] ) ? (string) $session['stage'] : '',
			'log_entries'        => isset( $session['log_entries'] ) && is_array( $session['log_entries'] ) ? $session['log_entries'] : array(),
		);
	}

	private static function build_batch_log_entries( $execution, $stage, $actions = array() ) {
		if ( empty( $execution ) || ! is_array( $execution ) ) {
			return array();
		}

		$entries = array(
			sprintf(
				'%s: simple %d created, %d updated; variable %d created, %d updated; variations %d created, %d updated; errors %d',
				'simple' === $stage ? 'Simple products batch' : 'Variable products batch',
				isset( $execution['created_simple'] ) ? (int) $execution['created_simple'] : 0,
				isset( $execution['updated_simple'] ) ? (int) $execution['updated_simple'] : 0,
				isset( $execution['created_variable'] ) ? (int) $execution['created_variable'] : 0,
				isset( $execution['updated_variable'] ) ? (int) $execution['updated_variable'] : 0,
				isset( $execution['created_variation'] ) ? (int) $execution['created_variation'] : 0,
				isset( $execution['updated_variation'] ) ? (int) $execution['updated_variation'] : 0,
				isset( $execution['errors'] ) && is_array( $execution['errors'] ) ? count( $execution['errors'] ) : 0
			),
		);

		if ( ! empty( $execution['imported_images'] ) || ! empty( $execution['reused_images'] ) || ! empty( $execution['skipped_images'] ) ) {
			$entries[] = sprintf(
				'Images: %d downloaded, %d reused, %d unchanged.',
				isset( $execution['imported_images'] ) ? (int) $execution['imported_images'] : 0,
				isset( $execution['reused_images'] ) ? (int) $execution['reused_images'] : 0,
				isset( $execution['skipped_images'] ) ? (int) $execution['skipped_images'] : 0
			);
		}

		if ( ! empty( $execution['skipped_unchanged'] ) ) {
			$entries[] = sprintf( 'Unchanged records skipped: %d.', (int) $execution['skipped_unchanged'] );
		}

		if ( ! empty( $execution['deleted_outofstock'] ) ) {
			$entries[] = sprintf( 'Missing Totobi products marked out of stock: %d.', (int) $execution['deleted_outofstock'] );
		}

		if ( 'media' === $stage ) {
			$entries[] = sprintf(
				'Images batch: %d downloaded, %d reused, %d unchanged; errors %d.',
				isset( $execution['imported_images'] ) ? (int) $execution['imported_images'] : 0,
				isset( $execution['reused_images'] ) ? (int) $execution['reused_images'] : 0,
				isset( $execution['skipped_images'] ) ? (int) $execution['skipped_images'] : 0,
				isset( $execution['errors'] ) && is_array( $execution['errors'] ) ? count( $execution['errors'] ) : 0
			);
		}

		$samples = array();

		if ( ! empty( $actions['simple'] ) ) {
			foreach ( array_slice( $actions['simple'], 0, 3 ) as $action ) {
				$samples[] = sprintf( '%s (%s)', $action['name'], $action['sku'] );
			}
		} elseif ( ! empty( $actions['variable'] ) ) {
			foreach ( array_slice( $actions['variable'], 0, 3 ) as $action ) {
				$samples[] = sprintf( '%s (%s)', $action['name'], $action['sku'] );
			}
		}

		if ( $samples ) {
			$entries[] = 'Products: ' . implode( '; ', $samples );
		}

		return $entries;
	}

	private static function build_action_summary( $actions ) {
		return array(
			'summary'          => isset( $actions['summary'] ) ? $actions['summary'] : array(),
			'simple_samples'   => isset( $actions['simple'] ) ? array_slice( $actions['simple'], 0, 5 ) : array(),
			'variable_samples' => isset( $actions['variable'] ) ? array_slice( $actions['variable'], 0, 5 ) : array(),
			'variation_samples' => isset( $actions['variations'] ) ? array_slice( $actions['variations'], 0, 5 ) : array(),
		);
	}

	private static function build_media_plan( $plan, $args = array() ) {
		$media_plan = array();
		$previous   = get_option( WTI_Feed_Index::OPTION_KEY, array() );
		$rows       = isset( $previous['offers'] ) && is_array( $previous['offers'] ) ? $previous['offers'] : array();
		$price_only = WTI_Feed_Index::markup_changed_from_store( $args );

		foreach ( isset( $plan['simple'] ) ? $plan['simple'] : array() as $offer ) {
			if ( self::offer_needs_media_job( $offer, $rows, $price_only ) ) {
				$media_plan[] = self::media_offer_row( $offer, 'simple' );
			}
		}

		foreach ( isset( $plan['variable'] ) ? $plan['variable'] : array() as $variable ) {
			$parent = isset( $variable['parent'] ) ? $variable['parent'] : array();

			if ( self::offer_needs_media_job( $parent, $rows, $price_only ) ) {
				$media_plan[] = self::media_offer_row( $parent, 'variable' );
			}

			foreach ( isset( $variable['variations'] ) ? (array) $variable['variations'] : array() as $variation ) {
				if ( self::offer_needs_media_job( $variation, $rows, $price_only ) ) {
					$media_plan[] = self::media_offer_row( $variation, 'variation' );
				}
			}
		}

		return $media_plan;
	}

	private static function offer_needs_media_job( $offer, $previous_rows, $price_only = false ) {
		if ( empty( $offer['pictures'] ) || ! class_exists( 'WTI_Image_Sync' ) ) {
			return false;
		}

		return WTI_Product_Sync::offer_needs_image_sync( $offer );
	}

	private static function media_offer_row( $offer, $type ) {
		return array(
			'type'     => $type,
			'offer_id' => isset( $offer['id'] ) ? (string) $offer['id'] : '',
			'sku'      => isset( $offer['sku'] ) ? (string) $offer['sku'] : '',
			'name'     => isset( $offer['name'] ) ? (string) $offer['name'] : '',
			'pictures' => isset( $offer['pictures'] ) ? (array) $offer['pictures'] : array(),
		);
	}

	private static function should_mark_missing_outofstock( $settings ) {
		return isset( $settings['mark_missing_outofstock'] ) && 'yes' === $settings['mark_missing_outofstock'];
	}

	private static function missing_product_category_ids( $settings ) {
		$map = isset( $settings['category_map'] ) && is_array( $settings['category_map'] ) ? $settings['category_map'] : array();

		return array_values( array_unique( array_filter( array_map( 'absint', $map ) ) ) );
	}

	private static function build_validation_summary( $plan ) {
		$validation = isset( $plan['validation'] ) ? $plan['validation'] : array();

		return array(
			'invalid_count' => isset( $validation['invalid_count'] ) ? $validation['invalid_count'] : 0,
			'reasons'       => isset( $validation['reasons'] ) ? $validation['reasons'] : array(),
			'samples'       => isset( $validation['invalid'] ) ? array_slice( $validation['invalid'], 0, 10 ) : array(),
			'skipped_groups' => isset( $plan['skipped_groups'] ) ? array_slice( $plan['skipped_groups'], 0, 10 ) : array(),
		);
	}

	private static function build_examples( $plan ) {
		$examples = array(
			'simple'   => array(),
			'variable' => array(),
		);

		foreach ( array_slice( $plan['simple'], 0, 3 ) as $offer ) {
			$examples['simple'][] = array(
				'id'           => $offer['id'],
				'name'         => $offer['name'],
				'sku'          => $offer['sku'],
				'vendor_code'  => $offer['vendor_code'],
				'category_id'  => $offer['category_id'],
				'price'        => $offer['price'],
				'stock_status' => $offer['stock_status'],
			);
		}

		foreach ( array_slice( $plan['variable'], 0, 3 ) as $variable ) {
			$examples['variable'][] = array(
				'group_id'         => $variable['group_id'],
				'name'             => $variable['parent']['name'],
				'category_id'      => $variable['parent']['category_id'],
				'variation_count'  => count( $variable['variations'] ),
				'variation_sample' => array_map(
					function ( $offer ) {
						return array(
							'id'           => $offer['id'],
							'sku'          => $offer['sku'],
							'vendor_code'  => $offer['vendor_code'],
							'size'         => $offer['size'],
							'price'        => $offer['price'],
							'stock_status' => $offer['stock_status'],
						);
					},
					array_slice( $variable['variations'], 0, 5 )
				),
			);
		}

		return $examples;
	}

	private static function acquire_import_lock() {
		self::recover_stale_import_session( 'manual' );

		$lock = get_transient( self::IMPORT_LOCK_TRANSIENT );
		if ( $lock ) {
			$session = get_option( self::IMPORT_SESSION_OPTION, array() );
			$status  = isset( $session['status'] ) ? (string) $session['status'] : '';
			$age     = time() - absint( $lock );

			if ( $age > self::STALE_SESSION_SECONDS ) {
				self::mark_stale_session_closed( $session, 'Import lock stopped updating.' );
				$status = '';
			}

			if ( in_array( $status, array( 'running', 'paused' ), true ) ) {
				return false;
			}

			self::release_import_lock();
		}

		$session = get_option( self::IMPORT_SESSION_OPTION, array() );
		if ( is_array( $session ) && in_array( isset( $session['status'] ) ? (string) $session['status'] : '', array( 'running', 'paused' ), true ) && ! self::load_import_plan() ) {
			self::delete_plan_files();
			delete_option( self::IMPORT_SESSION_OPTION );
		}

		set_transient( self::IMPORT_LOCK_TRANSIENT, time(), 3 * HOUR_IN_SECONDS );

		return true;
	}

	private static function save_sync_session( $session ) {
		$defaults = array(
			'status'             => 'running',
			'sync_type'          => 'manual',
			'started_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
			'updated_ts'         => time(),
			'start_time'         => microtime( true ),
			'catalog_date'       => '',
			'total'              => 0,
			'total_simple'       => 0,
			'total_variable'     => 0,
			'total_deleted'      => 0,
			'total_media'        => 0,
			'total_variations'   => 0,
			'processed'          => 0,
			'simple_offset'      => 0,
			'variable_offset'    => 0,
			'deleted_offset'     => 0,
			'media_offset'       => 0,
			'created_simple'     => 0,
			'updated_simple'     => 0,
			'created_variable'   => 0,
			'updated_variable'   => 0,
			'created_variation'  => 0,
			'updated_variation'  => 0,
			'imported_images'    => 0,
			'reused_images'      => 0,
			'skipped_images'     => 0,
			'skipped_unchanged'  => 0,
			'deleted_outofstock' => 0,
			'errors'             => 0,
			'error_samples'      => array(),
		);

		update_option( self::IMPORT_SESSION_OPTION, wp_parse_args( $session, $defaults ), false );
	}

	private static function finish_sync_session( $status, $sync_type, $started, $message = '', $catalog_date = '', $execution = array() ) {
		$session = get_option( self::IMPORT_SESSION_OPTION, array() );
		$session = is_array( $session ) ? $session : array();
		$session['status']      = $status;
		$session['sync_type']   = $sync_type;
		$session['started_at']  = isset( $session['started_at'] ) ? $session['started_at'] : $started;
		$session['finished_at'] = current_time( 'mysql' );
		$session['updated_at']  = current_time( 'mysql' );
		$session['updated_ts']  = time();
		$session['message']     = $message;

		if ( '' !== $catalog_date ) {
			$session['catalog_date'] = $catalog_date;
		}

		if ( is_array( $execution ) ) {
			self::merge_execution_into_session( $session, $execution );
			$session['processed'] = isset( $session['total'] ) ? (int) $session['total'] : 0;
		}

		update_option( self::IMPORT_SESSION_OPTION, $session, false );
	}

	private static function refresh_import_lock() {
		set_transient( self::IMPORT_LOCK_TRANSIENT, time(), 30 * MINUTE_IN_SECONDS );
	}

	private static function recover_stale_import_session( $replacement_sync_type = '' ) {
		$session = get_option( self::IMPORT_SESSION_OPTION, array() );

		if ( ! is_array( $session ) || ! in_array( isset( $session['status'] ) ? (string) $session['status'] : '', array( 'preparing', 'running', 'paused' ), true ) ) {
			return false;
		}

		if ( 'automatic' === $replacement_sync_type && 'automatic' === ( $session['sync_type'] ?? '' ) && is_array( self::load_import_plan() ) ) {
			return false;
		}

		if ( ! self::is_session_stale( $session ) ) {
			return false;
		}

		return self::mark_stale_session_closed( $session, 'Previous sync stopped responding and was closed automatically.' );
	}

	private static function is_session_stale( $session ) {
		$lock = get_transient( self::IMPORT_LOCK_TRANSIENT );
		$last = $lock ? absint( $lock ) : 0;

		if ( empty( $last ) && ! empty( $session['updated_ts'] ) ) {
			$last = absint( $session['updated_ts'] );
		}

		if ( empty( $last ) && ! empty( $session['start_time'] ) ) {
			$last = (int) floor( (float) $session['start_time'] );
		}

		return empty( $last ) || ( time() - $last ) > self::STALE_SESSION_SECONDS;
	}

	private static function mark_stale_session_closed( $session, $message ) {
		$session = is_array( $session ) ? $session : array();
		$session['status']            = 'interrupted';
		$session['message']           = $message;
		$session['finished_at']       = current_time( 'mysql' );
		$session['updated_at']        = current_time( 'mysql' );
		$session['updated_ts']        = time();
		$session['auto_closed_stale'] = true;

		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		self::delete_plan_files();
		self::unschedule_automatic_continue();
		self::release_import_lock();
		WTI_Logger::log( 'Stale sync session closed automatically.', array( 'sync_type' => $session['sync_type'] ?? '', 'message' => $message ) );

		return true;
	}

	private static function release_import_lock() {
		delete_transient( self::IMPORT_LOCK_TRANSIENT );
	}

	private static function fail_ajax_start( $message ) {
		$session = get_option( self::IMPORT_SESSION_OPTION, array() );
		if ( is_array( $session ) ) {
			$session['status']      = 'error';
			$session['message']     = $message;
			$session['finished_at'] = current_time( 'mysql' );
			$session['updated_at']  = current_time( 'mysql' );
			$session['updated_ts']  = time();
			update_option( self::IMPORT_SESSION_OPTION, $session, false );
		}
		self::release_import_lock();
		wp_send_json_error( $message );
	}

	private static function save_last_result( $started, $result ) {
		update_option(
			'wti_last_result',
			array_merge(
				array(
					'started_at'  => $started,
					'finished_at' => current_time( 'mysql' ),
				),
				$result
			),
			false
		);
	}

	private static function save_last_catalog_date( $catalog_date ) {
		if ( '' !== (string) $catalog_date ) {
			update_option( 'wti_last_catalog_date', (string) $catalog_date, false );
		}
	}
}
