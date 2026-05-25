<?php

defined( 'ABSPATH' ) || exit;

class WTI_Sync_Report {
	const DIR_NAME = 'wti-sync-reports';
	const KEEP_FILES = 10;

	public static function create() {
		$dir = self::directory();

		if ( is_wp_error( $dir ) ) {
			return '';
		}

		$file = trailingslashit( $dir['path'] ) . 'totobi-sync-' . gmdate( 'Ymd-His' ) . '.csv';
		$fh   = fopen( $file, 'wb' );

		if ( ! $fh ) {
			return '';
		}

		fwrite( $fh, "\xEF\xBB\xBF" );
		fwrite( $fh, "sep=;\r\n" );
		fputcsv( $fh, array( 'Type', 'Action', 'Product', 'SKU', 'Site URL', 'Details' ), ';' );
		fclose( $fh );
		self::rotate();

		return $file;
	}

	public static function append_rows( $file, $rows ) {
		if ( empty( $file ) || empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}

		$fh = fopen( $file, 'ab' );

		if ( ! $fh ) {
			return;
		}

		foreach ( $rows as $row ) {
			fputcsv(
				$fh,
				array(
					isset( $row['type'] ) ? $row['type'] : '',
					isset( $row['action'] ) ? $row['action'] : '',
					isset( $row['name'] ) ? $row['name'] : '',
					isset( $row['sku'] ) ? $row['sku'] : '',
					isset( $row['url'] ) ? $row['url'] : '',
					isset( $row['details'] ) ? $row['details'] : '',
				),
				';'
			);
		}

		fclose( $fh );
	}

	public static function url_for_file( $file ) {
		$dir = self::directory();

		if ( is_wp_error( $dir ) || empty( $file ) ) {
			return '';
		}

		return trailingslashit( $dir['url'] ) . rawurlencode( wp_basename( $file ) );
	}

	private static function rotate() {
		$dir = self::directory();

		if ( is_wp_error( $dir ) ) {
			return;
		}

		$files = glob( trailingslashit( $dir['path'] ) . 'totobi-sync-*.csv' );

		if ( ! is_array( $files ) || count( $files ) <= self::KEEP_FILES ) {
			return;
		}

		usort(
			$files,
			function ( $left, $right ) {
				return filemtime( $left ) <=> filemtime( $right );
			}
		);

		foreach ( array_slice( $files, 0, count( $files ) - self::KEEP_FILES ) as $file ) {
			@unlink( $file );
		}
	}

	private static function directory() {
		$upload = wp_upload_dir();

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wti_report_upload_dir', $upload['error'] );
		}

		$path = trailingslashit( $upload['basedir'] ) . self::DIR_NAME;
		$url  = trailingslashit( $upload['baseurl'] ) . self::DIR_NAME;

		if ( ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'wti_report_dir_failed', 'Cannot create report directory.' );
		}

		return array(
			'path' => $path,
			'url'  => $url,
		);
	}
}
