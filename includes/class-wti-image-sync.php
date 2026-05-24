<?php

defined( 'ABSPATH' ) || exit;

class WTI_Image_Sync {
	const META_SOURCE_URL  = '_wti_source_image_url';
	const META_SOURCE_HASH = '_wti_source_image_hash';

	public static function sync_product_images( $product, $image_urls ) {
		$image_urls = array_values( array_filter( array_unique( (array) $image_urls ) ) );

		if ( empty( $image_urls ) ) {
			return array(
				'featured_id' => 0,
				'gallery_ids' => array(),
				'errors'      => array(),
			);
		}

		self::load_media_functions();

		$attachment_ids = array();
		$errors         = array();

		foreach ( $image_urls as $image_url ) {
			$attachment_id = self::get_or_import_image( $image_url, $product->get_name() );

			if ( is_wp_error( $attachment_id ) ) {
				$errors[] = array(
					'url'   => $image_url,
					'error' => $attachment_id->get_error_message(),
				);
				continue;
			}

			if ( $attachment_id ) {
				$attachment_ids[] = (int) $attachment_id;
			}
		}

		$attachment_ids = array_values( array_unique( $attachment_ids ) );
		$featured_id    = isset( $attachment_ids[0] ) ? (int) $attachment_ids[0] : 0;
		$gallery_ids    = array_slice( $attachment_ids, 1 );

		if ( $featured_id ) {
			$product->set_image_id( $featured_id );
		}

		$product->set_gallery_image_ids( $gallery_ids );

		return array(
			'featured_id' => $featured_id,
			'gallery_ids' => $gallery_ids,
			'errors'      => $errors,
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

