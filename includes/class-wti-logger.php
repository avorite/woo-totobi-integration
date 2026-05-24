<?php

defined( 'ABSPATH' ) || exit;

class WTI_Logger {
	const LOG_FILE = 'woo-totobi-integration.log';

	public static function log( $message, $context = array() ) {
		$upload_dir = wp_upload_dir();

		if ( empty( $upload_dir['basedir'] ) ) {
			return false;
		}

		$line = sprintf(
			"[%s] %s%s\n",
			current_time( 'mysql' ),
			(string) $message,
			empty( $context ) ? '' : ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		return (bool) file_put_contents( trailingslashit( $upload_dir['basedir'] ) . self::LOG_FILE, $line, FILE_APPEND | LOCK_EX );
	}

	public static function get_log_path() {
		$upload_dir = wp_upload_dir();

		return empty( $upload_dir['basedir'] ) ? '' : trailingslashit( $upload_dir['basedir'] ) . self::LOG_FILE;
	}

	public static function read_tail( $bytes = 20000 ) {
		$path = self::get_log_path();

		if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$size   = filesize( $path );
		$offset = max( 0, $size - absint( $bytes ) );
		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			return '';
		}

		fseek( $handle, $offset );
		$content = stream_get_contents( $handle );
		fclose( $handle );

		return (string) $content;
	}
}

