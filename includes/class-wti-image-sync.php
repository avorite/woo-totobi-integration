<?php

defined( 'ABSPATH' ) || exit;

class WTI_Image_Sync {
	const META_SOURCE_URL  = '_wti_source_image_url';
	const META_SOURCE_HASH = '_wti_source_image_hash';
	const META_IMAGE_SET_HASH = '_wti_image_set_hash';

	public static function sync_product_images( $product, $image_urls ) {
		$image_urls = array_values( array_filter( array_unique( (array) $image_urls ) ) );
		$desired    = self::build_desired_image_set( $image_urls );

		if ( empty( $image_urls ) ) {
			return array(
				'featured_id'    => 0,
				'gallery_ids'    => array(),
				'errors'         => array(),
				'imported_count' => 0,
				'reused_count'   => 0,
				'skipped_count'  => 0,
				'changed'        => false,
			);
		}

		self::load_media_functions();

		$current_ids = self::get_current_product_image_ids( $product );

		if ( self::current_images_match_desired_set( $product, $current_ids, $desired ) ) {
			return array(
				'featured_id'    => isset( $current_ids[0] ) ? (int) $current_ids[0] : 0,
				'gallery_ids'    => array_slice( $current_ids, 1 ),
				'errors'         => array(),
				'imported_count' => 0,
				'reused_count'   => count( $current_ids ),
				'skipped_count'  => count( $current_ids ),
				'changed'        => false,
			);
		}

		$attachment_ids = array();
		$errors         = array();
		$imported_count = 0;
		$reused_count   = 0;

		foreach ( $desired as $image ) {
			$attachment_id = self::find_matching_current_attachment( $current_ids, $image );
			$status        = 'reused';

			if ( ! $attachment_id ) {
				$import = self::get_or_import_image_with_status( $image['url'], $product->get_name(), $image['name'] );

				if ( is_wp_error( $import ) ) {
					$errors[] = array(
						'url'   => $image['url'],
						'error' => $import->get_error_message(),
					);
					continue;
				}

				$attachment_id = isset( $import['id'] ) ? (int) $import['id'] : 0;
				$status        = isset( $import['status'] ) ? $import['status'] : 'reused';
			}

			if ( is_wp_error( $attachment_id ) ) {
				$errors[] = array(
					'url'   => $image['url'],
					'error' => $attachment_id->get_error_message(),
				);
				continue;
			}

			if ( $attachment_id ) {
				$attachment_ids[] = (int) $attachment_id;
				update_post_meta( $attachment_id, self::META_SOURCE_URL, $image['url'] );
				update_post_meta( $attachment_id, self::META_SOURCE_HASH, $image['hash'] );

				if ( 'imported' === $status ) {
					$imported_count++;
				} else {
					$reused_count++;
				}
			}
		}

		$attachment_ids = array_values( array_unique( $attachment_ids ) );
		$featured_id    = isset( $attachment_ids[0] ) ? (int) $attachment_ids[0] : 0;
		$gallery_ids    = array_slice( $attachment_ids, 1 );

		if ( $featured_id ) {
			$product->set_image_id( $featured_id );
		}

		$product->set_gallery_image_ids( $gallery_ids );
		$product->update_meta_data( self::META_IMAGE_SET_HASH, self::build_image_set_hash( $desired ) );

		return array(
			'featured_id'    => $featured_id,
			'gallery_ids'    => $gallery_ids,
			'errors'         => $errors,
			'imported_count' => $imported_count,
			'reused_count'   => $reused_count,
			'skipped_count'  => 0,
			'changed'        => true,
		);
	}

	public static function get_or_import_image( $url, $title = '' ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return new WP_Error( 'wti_empty_image_url', 'Image URL is empty.' );
		}

		$existing_id = self::find_attachment_by_source_url( $url );

		if ( $existing_id ) {
			return $existing_id;
		}

		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_name = wp_basename( parse_url( $url, PHP_URL_PATH ) );

		if ( '' === $file_name ) {
			$file_name = md5( $url ) . '.jpg';
		}

		$file = array(
			'name'     => sanitize_file_name( $file_name ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, 0, $title );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::META_SOURCE_URL, $url );
		update_post_meta( $attachment_id, self::META_SOURCE_HASH, md5( $url ) );

		return (int) $attachment_id;
	}

	private static function get_or_import_image_with_status( $url, $title = '', $expected_name = '' ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return new WP_Error( 'wti_empty_image_url', 'Image URL is empty.' );
		}

		$existing_id = self::find_attachment_by_source_url( $url );

		if ( $existing_id ) {
			return array(
				'id'     => $existing_id,
				'status' => 'reused',
			);
		}

		$expected_file_name = '' !== $expected_name ? sanitize_file_name( $expected_name ) : sanitize_file_name( wp_basename( parse_url( $url, PHP_URL_PATH ) ) );
		$existing_id        = self::find_attachment_by_file_name( $expected_file_name );

		if ( $existing_id ) {
			update_post_meta( $existing_id, self::META_SOURCE_URL, $url );
			update_post_meta( $existing_id, self::META_SOURCE_HASH, md5( $url ) );

			return array(
				'id'     => $existing_id,
				'status' => 'reused',
			);
		}

		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_name = '' !== $expected_name ? $expected_name : wp_basename( parse_url( $url, PHP_URL_PATH ) );

		if ( '' === $file_name ) {
			$file_name = md5( $url ) . '.jpg';
		}

		$file = array(
			'name'     => sanitize_file_name( $file_name ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, 0, $title );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::META_SOURCE_URL, $url );
		update_post_meta( $attachment_id, self::META_SOURCE_HASH, md5( $url ) );

		return array(
			'id'     => (int) $attachment_id,
			'status' => 'imported',
		);
	}

	private static function build_desired_image_set( $image_urls ) {
		$desired = array();

		foreach ( $image_urls as $url ) {
			$url = esc_url_raw( $url );

			if ( '' === $url ) {
				continue;
			}

			$name = wp_basename( parse_url( $url, PHP_URL_PATH ) );

			if ( '' === $name ) {
				$name = md5( $url ) . '.jpg';
			}

			$desired[] = array(
				'url'  => $url,
				'hash' => md5( $url ),
				'name' => sanitize_file_name( $name ),
			);
		}

		return $desired;
	}

	public static function build_image_set_hash_from_urls( $image_urls ) {
		return self::build_image_set_hash( self::build_desired_image_set( (array) $image_urls ) );
	}

	private static function get_current_product_image_ids( $product ) {
		$ids = array();

		if ( $product->get_image_id() ) {
			$ids[] = (int) $product->get_image_id();
		}

		foreach ( (array) $product->get_gallery_image_ids() as $id ) {
			if ( $id ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function current_images_match_desired_set( $product, $current_ids, $desired ) {
		if ( count( $current_ids ) !== count( $desired ) ) {
			return false;
		}

		$stored_set_hash = (string) $product->get_meta( self::META_IMAGE_SET_HASH );

		if ( '' !== $stored_set_hash && $stored_set_hash === self::build_image_set_hash( $desired ) ) {
			return true;
		}

		foreach ( $desired as $index => $image ) {
			$attachment_id = isset( $current_ids[ $index ] ) ? (int) $current_ids[ $index ] : 0;

			if ( ! $attachment_id || ! self::attachment_matches_image( $attachment_id, $image ) ) {
				return false;
			}
		}

		$product->update_meta_data( self::META_IMAGE_SET_HASH, self::build_image_set_hash( $desired ) );

		return true;
	}

	private static function find_matching_current_attachment( $current_ids, $image ) {
		foreach ( $current_ids as $attachment_id ) {
			if ( self::attachment_matches_image( (int) $attachment_id, $image ) ) {
				return (int) $attachment_id;
			}
		}

		return 0;
	}

	private static function attachment_matches_image( $attachment_id, $image ) {
		$source_hash = (string) get_post_meta( $attachment_id, self::META_SOURCE_HASH, true );

		if ( '' !== $source_hash && $source_hash === $image['hash'] ) {
			return true;
		}

		$file = get_attached_file( $attachment_id );
		$name = $file ? sanitize_file_name( wp_basename( $file ) ) : sanitize_file_name( wp_basename( get_the_title( $attachment_id ) ) );

		return '' !== $name && $name === $image['name'];
	}

	private static function build_image_set_hash( $desired ) {
		$parts = array();

		foreach ( $desired as $image ) {
			$parts[] = $image['hash'] . ':' . $image['name'];
		}

		return md5( implode( '|', $parts ) );
	}

	private static function find_attachment_by_source_url( $url ) {
		$query = new WP_Query(
			array(
				'fields'         => 'ids',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::META_SOURCE_HASH,
						'value' => md5( $url ),
					),
				),
			)
		);

		return empty( $query->posts ) ? 0 : (int) $query->posts[0];
	}

	private static function find_attachment_by_file_name( $file_name ) {
		$file_name = sanitize_file_name( (string) $file_name );

		if ( '' === $file_name ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'fields'         => 'ids',
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				's'              => pathinfo( $file_name, PATHINFO_FILENAME ),
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			$name = $file ? sanitize_file_name( wp_basename( $file ) ) : sanitize_file_name( wp_basename( get_the_title( $attachment_id ) ) );

			if ( $name === $file_name ) {
				return (int) $attachment_id;
			}
		}

		return 0;
	}

	private static function load_media_functions() {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
