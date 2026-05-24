<?php

defined( 'ABSPATH' ) || exit;

class WTI_Feed_Client {
	const DEFAULT_PROM_FEED_URL = 'https://totobi.com.ua/index.php?dispatch=yml.get&access_key=nnpnlo7d96a3';
	const DEFAULT_MAIN_FEED_URL = 'https://totobi.com.ua/index.php?dispatch=yml.get&access_key=lg3bjy2gvww';

	public static function fetch( $url ) {
		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout'     => 60,
				'redirection' => 3,
				'user-agent'  => 'Woo Totobi Integration/' . WTI_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'wti_feed_http_error', sprintf( 'Totobi feed returned HTTP %d.', $code ) );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === trim( (string) $body ) ) {
			return new WP_Error( 'wti_feed_empty', 'Totobi feed response is empty.' );
		}

		return $body;
	}
}

