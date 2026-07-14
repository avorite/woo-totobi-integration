<?php

defined( 'ABSPATH' ) || exit;

class WTI_License {
	const CHECK_URL = 'https://totobi.webvector.space/check.php';
	const CACHE_KEY = 'wti_license_status';

	public static function check( $license_key, $force = false ) {
		$license_key = trim( (string) $license_key );

		if ( '' === $license_key ) {
			return array(
				'valid'   => false,
				'status'  => 'missing_key',
				'message' => __( 'License key is missing.', WTI_TEXT_DOMAIN ),
			);
		}

		$cache_key = self::CACHE_KEY . '_' . md5( $license_key . '|' . self::domain() );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_post(
			self::CHECK_URL,
			array(
				'timeout' => 12,
				'body'    => array(
					'key'     => $license_key,
					'domain'  => self::domain(),
					'version' => defined( 'WTI_VERSION' ) ? WTI_VERSION : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$cached = get_transient( $cache_key . '_grace' );
			if ( is_array( $cached ) && ! empty( $cached['valid'] ) ) {
				$cached['message'] = __( 'License server is temporarily unavailable. Cached license is used.', WTI_TEXT_DOMAIN );
				return $cached;
			}

			return array(
				'valid'   => false,
				'status'  => 'server_unavailable',
				'message' => $response->get_error_message(),
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array(
				'valid'   => false,
				'status'  => 'invalid_response',
				'message' => __( 'Invalid license server response.', WTI_TEXT_DOMAIN ),
			);
		}

		$status = array(
			'valid'      => ! empty( $body['valid'] ),
			'status'     => isset( $body['status'] ) ? sanitize_key( $body['status'] ) : 'unknown',
			'expires_at' => isset( $body['expires_at'] ) ? sanitize_text_field( $body['expires_at'] ) : '',
			'domain'     => isset( $body['domain'] ) ? sanitize_text_field( $body['domain'] ) : self::domain(),
		);
		$status['message'] = self::message_for_status( $status );

		set_transient( $cache_key, $status, 6 * HOUR_IN_SECONDS );
		if ( ! empty( $status['valid'] ) ) {
			set_transient( $cache_key . '_grace', $status, 7 * DAY_IN_SECONDS );
		}

		return $status;
	}

	public static function clear_cache() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::CACHE_KEY ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	public static function is_valid( $settings = null, $force = false ) {
		$settings = is_array( $settings ) ? $settings : WTI_Admin::get_settings();
		$status   = self::check( isset( $settings['license_key'] ) ? $settings['license_key'] : '', $force );

		return ! empty( $status['valid'] );
	}

	public static function status_label( $status ) {
		if ( ! is_array( $status ) ) {
			return __( 'Unknown', WTI_TEXT_DOMAIN );
		}

		return ! empty( $status['valid'] ) ? __( 'Active', WTI_TEXT_DOMAIN ) : __( 'Inactive', WTI_TEXT_DOMAIN );
	}

	private static function domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( (string) $host );
	}

	private static function message_for_status( $status ) {
		switch ( isset( $status['status'] ) ? $status['status'] : '' ) {
			case 'active':
				return __( 'License is active.', WTI_TEXT_DOMAIN );
			case 'missing_key':
				return __( 'License key is missing.', WTI_TEXT_DOMAIN );
			case 'expired':
				return __( 'License has expired.', WTI_TEXT_DOMAIN );
			case 'inactive':
				return __( 'License is inactive.', WTI_TEXT_DOMAIN );
			case 'domain_mismatch':
				return __( 'License is not assigned to this domain.', WTI_TEXT_DOMAIN );
			case 'not_found':
				return __( 'License key was not found.', WTI_TEXT_DOMAIN );
			default:
				return __( 'License is not active.', WTI_TEXT_DOMAIN );
		}
	}
}
