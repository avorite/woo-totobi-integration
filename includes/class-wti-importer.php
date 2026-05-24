<?php

defined( 'ABSPATH' ) || exit;

class WTI_Importer {
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

		WTI_Logger::log( 'Sync scaffold started.', array( 'manual' => ! empty( $args['manual'] ), 'feed_url' => $feed_url ) );

		$xml = WTI_Feed_Client::fetch( $feed_url );

		if ( is_wp_error( $xml ) ) {
			WTI_Logger::log( 'Feed fetch failed.', array( 'error' => $xml->get_error_message() ) );
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $xml->get_error_message() ) );

			return $xml;
		}

		$meta = WTI_Parser::parse_catalog_meta( $xml );

		if ( is_wp_error( $meta ) ) {
			WTI_Logger::log( 'Feed parse failed.', array( 'error' => $meta->get_error_message() ) );
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $meta->get_error_message() ) );

			return $meta;
		}

		$result = array(
			'status'       => 'ok',
			'message'      => 'Scaffold sync checked feed metadata. Product import is not implemented yet.',
			'catalog_date' => isset( $meta['date'] ) ? $meta['date'] : '',
			'created'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'errors'       => 0,
		);

		self::save_last_result( $started, $result );
		WTI_Logger::log( 'Sync scaffold completed.', $result );

		return $result;
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
}

