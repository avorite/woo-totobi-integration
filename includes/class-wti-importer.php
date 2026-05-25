<?php

defined( 'ABSPATH' ) || exit;

class WTI_Importer {
	const IMPORT_PLAN_TRANSIENT = 'wti_import_plan';
	const IMPORT_SESSION_OPTION = 'wti_import_session';
	const IMPORT_LOCK_TRANSIENT = 'wti_import_lock';

	public static function run_manual_sync() {
		return self::run_sync( array( 'manual' => true ) );
	}

	public static function run_scheduled_sync() {
		return self::run_sync( array( 'manual' => false ) );
	}

	public static function run_sync( $args = array() ) {
		$settings = WTI_Admin::get_settings();
		$feed_url = ! empty( $settings['feed_url'] ) ? $settings['feed_url'] : WTI_Feed_Client::DEFAULT_PROM_FEED_URL;
		$started = current_time( 'mysql' );
		$sync_type = empty( $args['manual'] ) ? 'automatic' : 'manual';

		if ( ! self::acquire_import_lock() ) {
			return new WP_Error( 'sync_locked', 'Synchronization is already running.' );
		}

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
		$index_result    = WTI_Feed_Index::filter_changed_plan( $plan, isset( $settings['import_images'] ) && 'yes' === $settings['import_images'] );
		$plan            = $index_result['plan'];
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
				'total'        => (int) $summary['total_products'] + (int) $summary['deleted_products'],
				'processed'    => 0,
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
			$execution_plan['variable'] = $variable_limit > 0 ? array_slice( $plan['variable'], $variable_offset, $variable_limit ) : array();
		}

		$actions = WTI_Product_Sync::build_action_plan(
			$execution_plan,
			array(
				'dry_run'      => 'yes' === $settings['dry_run'],
				'catalog_date' => $catalog_date,
				'category_map' => isset( $settings['category_map'] ) ? $settings['category_map'] : array(),
			)
		);
		$execution = WTI_Product_Sync::execute_action_plan(
			$actions,
			array(
				'dry_run'        => 'yes' === $settings['dry_run'],
				'import_limit'   => $simple_limit,
				'variable_limit' => $variable_limit,
				'deleted_limit'  => isset( $plan['deleted'] ) ? count( $plan['deleted'] ) : 0,
				'simple_offset'  => 0,
				'variable_offset' => 0,
				'import_images'  => isset( $settings['import_images'] ) && 'yes' === $settings['import_images'],
				'product_status' => isset( $settings['product_status'] ) ? $settings['product_status'] : 'draft',
			)
		);

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
			'message'      => 'Dry-run parsed Totobi Prom YML. Product import is not implemented yet.',
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
		);

		self::save_last_result( $started, $result );
		self::save_last_catalog_date( $catalog_date );
		WTI_Feed_Index::save_from_store( $catalog_date );
		WTI_Logger::log( 'Sync scaffold completed.', $result );
		self::finish_sync_session( 'completed', $sync_type, $started, $result['message'], $catalog_date, $execution );
		self::release_import_lock();

		return $result;
	}

	public static function handle_ajax_start() {
		self::check_ajax_request();

		if ( ! self::acquire_import_lock() ) {
			wp_send_json_error( 'Synchronization is already running or paused. Resume and finish it before starting a new one.' );
		}

		$settings = WTI_Admin::get_settings();
		$feed_url = ! empty( $settings['feed_url'] ) ? $settings['feed_url'] : WTI_Feed_Client::DEFAULT_PROM_FEED_URL;
		$started = current_time( 'mysql' );

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
		$index_result = WTI_Feed_Index::filter_changed_plan( $plan, isset( $settings['import_images'] ) && 'yes' === $settings['import_images'] );
		$plan         = $index_result['plan'];
		$summary      = WTI_Parser::summarize_plan( $plan );
		$summary['unchanged_products']   = (int) $index_result['unchanged'];
		$summary['deleted_products']     = (int) $index_result['deleted'];
		$summary['feed_total_products']  = (int) $full_summary['total_products'];
		$total        = (int) $summary['simple_products'] + (int) $summary['variable_products'] + (int) $summary['deleted_products'];
		$feed_total   = (int) $full_summary['simple_products'] + (int) $full_summary['variable_products'];

		if ( $feed_total < 1 ) {
			self::fail_ajax_start( 'No products found for selected Totobi categories.' );
		}

		set_transient( self::IMPORT_PLAN_TRANSIENT, $plan, 2 * HOUR_IN_SECONDS );

		$session = array(
			'status'              => 'running',
			'sync_type'           => 'manual',
			'started_at'          => $started,
			'start_time'          => microtime( true ),
			'catalog_date'        => isset( $meta['date'] ) ? $meta['date'] : '',
			'dry_run'             => 'yes' === $settings['dry_run'],
			'settings'            => $settings,
			'total'               => $total,
			'total_simple'        => (int) $summary['simple_products'],
			'total_variable'      => (int) $summary['variable_products'],
			'total_deleted'       => (int) $summary['deleted_products'],
			'total_variations'    => (int) $summary['variations'],
			'processed'           => 0,
			'simple_offset'       => 0,
			'variable_offset'     => 0,
			'deleted_offset'      => 0,
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

		wp_send_json_success( self::session_response( $session ) );
	}

	public static function handle_ajax_batch() {
		self::check_ajax_request();
		self::refresh_import_lock();

		$plan = get_transient( self::IMPORT_PLAN_TRANSIENT );
		if ( ! is_array( $plan ) ) {
			self::release_import_lock();
			wp_send_json_error( 'Import session expired. Start import again.' );
		}

		$session = get_option( self::IMPORT_SESSION_OPTION, array() );
		if ( empty( $session ) || 'paused' === $session['status'] ) {
			if ( empty( $session ) ) {
				self::release_import_lock();
			}
			wp_send_json_error( 'Import is paused or not running.' );
		}

		$settings       = isset( $session['settings'] ) && is_array( $session['settings'] ) ? $session['settings'] : WTI_Admin::get_settings();
		$simple_size    = isset( $_POST['simple_batch_size'] ) ? min( 100, max( 1, absint( wp_unslash( $_POST['simple_batch_size'] ) ) ) ) : min( 50, max( 1, absint( $settings['import_limit'] ) ) );
		$variable_size  = isset( $_POST['variable_batch_size'] ) ? min( 20, max( 1, absint( wp_unslash( $_POST['variable_batch_size'] ) ) ) ) : min( 10, max( 1, absint( $settings['variable_limit'] ) ) );
		$import_images  = isset( $settings['import_images'] ) && 'yes' === $settings['import_images'];

		if ( $import_images ) {
			$simple_size   = min( $simple_size, 3 );
			$variable_size = min( $variable_size, 3 );
		}

		$simple_total   = count( $plan['simple'] );
		$variable_total = count( $plan['variable'] );
		$deleted_total  = isset( $plan['deleted'] ) && is_array( $plan['deleted'] ) ? count( $plan['deleted'] ) : 0;
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
			$batch_plan['variable'] = array_slice( $plan['variable'], (int) $session['variable_offset'], $variable_size );
			$session['variable_offset'] += count( $batch_plan['variable'] );
		} elseif ( $session['deleted_offset'] < $deleted_total ) {
			$stage = 'deleted';
			$batch_plan['deleted'] = array_slice( $plan['deleted'], (int) $session['deleted_offset'], 50 );
			$session['deleted_offset'] += count( $batch_plan['deleted'] );
		}

		if ( empty( $batch_plan['simple'] ) && empty( $batch_plan['variable'] ) && empty( $batch_plan['deleted'] ) ) {
			self::complete_ajax_session( $session );
		}

		$actions = WTI_Product_Sync::build_action_plan(
			$batch_plan,
			array(
				'dry_run'      => ! empty( $session['dry_run'] ),
				'catalog_date' => $session['catalog_date'],
				'category_map' => isset( $settings['category_map'] ) ? $settings['category_map'] : array(),
			)
		);

		$execution = WTI_Product_Sync::execute_action_plan(
			$actions,
			array(
				'dry_run'        => ! empty( $session['dry_run'] ),
				'import_limit'   => count( $batch_plan['simple'] ),
				'variable_limit' => count( $batch_plan['variable'] ),
				'deleted_limit'  => count( $batch_plan['deleted'] ),
				'import_images'  => $import_images,
				'product_status' => isset( $settings['product_status'] ) ? $settings['product_status'] : 'draft',
			)
		);

		if ( is_wp_error( $execution ) ) {
			self::release_import_lock();
			wp_send_json_error( $execution->get_error_message() );
		}

		self::merge_execution_into_session( $session, $execution );
		if ( ! empty( $session['report_file'] ) && ! empty( $execution['report_rows'] ) && class_exists( 'WTI_Sync_Report' ) ) {
			WTI_Sync_Report::append_rows( $session['report_file'], $execution['report_rows'] );
		}
		$session['processed'] = min( $session['total'], (int) $session['simple_offset'] + (int) $session['variable_offset'] + (int) $session['deleted_offset'] );
		$session['status']    = 'running';

		if ( $session['simple_offset'] >= $simple_total && $session['variable_offset'] >= $variable_total && $session['deleted_offset'] >= $deleted_total ) {
			self::complete_ajax_session( $session, $execution, $stage );
		}

		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		$response                 = self::session_response( $session );
		$response['completed']    = false;
		$response['stage']        = $stage;
		$response['log_entries']  = self::build_batch_log_entries( $execution, $stage, $actions );

		wp_send_json_success( $response );
	}

	public static function handle_ajax_progress() {
		self::check_ajax_request();
		$response = self::session_response( get_option( self::IMPORT_SESSION_OPTION, array() ) );
		$plan     = get_transient( self::IMPORT_PLAN_TRANSIENT );

		$response['can_resume'] = in_array( $response['status'], array( 'running', 'paused' ), true ) && is_array( $plan );
		$response['plan_exists'] = is_array( $plan );

		wp_send_json_success( $response );
	}

	public static function handle_ajax_pause() {
		self::check_ajax_request();
		$session           = get_option( self::IMPORT_SESSION_OPTION, array() );
		$session['status'] = 'paused';
		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		wp_send_json_success( self::session_response( $session ) );
	}

	public static function handle_ajax_resume() {
		self::check_ajax_request();
		$session           = get_option( self::IMPORT_SESSION_OPTION, array() );
		$session['status'] = 'running';
		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		self::refresh_import_lock();
		wp_send_json_success( self::session_response( $session ) );
	}

	public static function handle_ajax_reset() {
		self::check_ajax_request();
		delete_transient( self::IMPORT_PLAN_TRANSIENT );
		delete_option( self::IMPORT_SESSION_OPTION );
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

	private static function complete_ajax_session( $session, $last_execution = array(), $stage = '' ) {
		$session['status']      = 'completed';
		$session['finished_at'] = current_time( 'mysql' );
		$session['duration']    = isset( $session['start_time'] ) ? round( microtime( true ) - (float) $session['start_time'], 2 ) : 0;

		update_option( self::IMPORT_SESSION_OPTION, $session, false );
		delete_transient( self::IMPORT_PLAN_TRANSIENT );
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
		WTI_Feed_Index::save_from_store( isset( $session['catalog_date'] ) ? $session['catalog_date'] : '' );
		WTI_Logger::log( 'AJAX import completed.', $result );

		$response                = self::session_response( $session );
		$response['completed']   = true;
		$response['stage']       = $stage;
		$response['log_entries'] = self::build_batch_log_entries( $last_execution, $stage, array() );
		wp_send_json_success( $response );
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
			'total_variations'   => isset( $session['total_variations'] ) ? (int) $session['total_variations'] : 0,
			'processed'          => isset( $session['processed'] ) ? (int) $session['processed'] : 0,
			'simple_offset'      => isset( $session['simple_offset'] ) ? (int) $session['simple_offset'] : 0,
			'variable_offset'    => isset( $session['variable_offset'] ) ? (int) $session['variable_offset'] : 0,
			'deleted_offset'     => isset( $session['deleted_offset'] ) ? (int) $session['deleted_offset'] : 0,
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
		$lock = get_transient( self::IMPORT_LOCK_TRANSIENT );
		if ( $lock ) {
			$session = get_option( self::IMPORT_SESSION_OPTION, array() );
			$status  = isset( $session['status'] ) ? (string) $session['status'] : '';
			$age     = time() - absint( $lock );

			if ( $age > 15 * MINUTE_IN_SECONDS ) {
				self::release_import_lock();
				delete_transient( self::IMPORT_PLAN_TRANSIENT );
				delete_option( self::IMPORT_SESSION_OPTION );
				$status = '';
			}

			if ( in_array( $status, array( 'running', 'paused' ), true ) ) {
				return false;
			}

			self::release_import_lock();
		}

		$session = get_option( self::IMPORT_SESSION_OPTION, array() );
		if ( is_array( $session ) && in_array( isset( $session['status'] ) ? (string) $session['status'] : '', array( 'running', 'paused' ), true ) && ! get_transient( self::IMPORT_PLAN_TRANSIENT ) ) {
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
			'start_time'         => microtime( true ),
			'catalog_date'       => '',
			'total'              => 0,
			'total_simple'       => 0,
			'total_variable'     => 0,
			'total_deleted'      => 0,
			'total_variations'   => 0,
			'processed'          => 0,
			'simple_offset'      => 0,
			'variable_offset'    => 0,
			'deleted_offset'     => 0,
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

	private static function release_import_lock() {
		delete_transient( self::IMPORT_LOCK_TRANSIENT );
	}

	private static function fail_ajax_start( $message ) {
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
