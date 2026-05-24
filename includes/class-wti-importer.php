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

		$offers = WTI_Parser::parse_offers(
			$xml,
			array(
				'allowed_paths' => isset( $settings['selected_paths'] ) ? $settings['selected_paths'] : WTI_Parser::DEFAULT_ALLOWED_PATHS,
			)
		);

		if ( is_wp_error( $offers ) ) {
			WTI_Logger::log( 'Offer parse failed.', array( 'error' => $offers->get_error_message() ) );
			self::save_last_result( $started, array( 'status' => 'error', 'message' => $offers->get_error_message() ) );

			return $offers;
		}

		$plan    = WTI_Parser::build_import_plan( $offers );
		$summary = WTI_Parser::summarize_plan( $plan );
		$actions = WTI_Product_Sync::build_action_plan(
			$plan,
			array(
				'dry_run'      => true,
				'catalog_date' => isset( $meta['date'] ) ? $meta['date'] : '',
			)
		);
		$execution = WTI_Product_Sync::execute_action_plan( $actions, array( 'dry_run' => true ) );

		$result = array(
			'status'       => 'ok',
			'message'      => 'Dry-run parsed Totobi Prom YML. Product import is not implemented yet.',
			'catalog_date' => isset( $meta['date'] ) ? $meta['date'] : '',
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
		WTI_Logger::log( 'Sync scaffold completed.', $result );

		return $result;
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
