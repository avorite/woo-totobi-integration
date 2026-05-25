<?php

defined( 'ABSPATH' ) || exit;

class WTI_Feed_Index {
	const OPTION_KEY = 'wti_feed_index';

	public static function filter_changed_plan( $plan, $import_images = true, $mark_missing_outofstock = false, $category_ids = array() ) {
		$previous      = get_option( self::OPTION_KEY, array() );
		$previous_rows = isset( $previous['offers'] ) && is_array( $previous['offers'] ) ? $previous['offers'] : array();
		$current_rows  = self::build_plan_rows( $plan );
		$filtered      = $plan;
		$filtered['simple']   = array();
		$filtered['variable'] = array();
		$unchanged     = 0;

		foreach ( $plan['simple'] as $offer ) {
			if ( self::row_is_unchanged( $offer, $previous_rows, $import_images ) ) {
				$unchanged++;
				continue;
			}

			$filtered['simple'][] = $offer;
		}

		foreach ( $plan['variable'] as $variable ) {
			$parent             = $variable['parent'];
			$parent_unchanged   = self::row_is_unchanged( $parent, $previous_rows, $import_images );
			$changed_variations = array();

			foreach ( $variable['variations'] as $variation ) {
				if ( self::row_is_unchanged( $variation, $previous_rows, false ) ) {
					$unchanged++;
					continue;
				}

				$changed_variations[] = $variation;
			}

			if ( $parent_unchanged && empty( $changed_variations ) ) {
				$unchanged++;
				continue;
			}

			$variable['variations'] = $changed_variations;
			$filtered['variable'][] = $variable;
		}

		$filtered['deleted'] = $mark_missing_outofstock ? self::find_deleted_products( $previous_rows, $current_rows, $category_ids ) : array();

		return array(
			'plan'      => $filtered,
			'unchanged' => $unchanged,
			'deleted'   => count( $filtered['deleted'] ),
			'current'   => $current_rows,
		);
	}

	public static function save_from_store( $catalog_date = '' ) {
		$rows = array();
		$ids  = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => WTI_Product_Sync::META_SOURCE,
				'meta_value'     => 'totobi',
			)
		);

		foreach ( $ids as $product_id ) {
			$offer_id = (string) get_post_meta( $product_id, WTI_Product_Sync::META_OFFER_ID, true );

			if ( '' === $offer_id ) {
				continue;
			}

			$rows[ $offer_id ] = array(
				'offer_id'   => $offer_id,
				'product_id' => (int) $product_id,
				'raw_hash'   => (string) get_post_meta( $product_id, WTI_Product_Sync::META_RAW_HASH, true ),
				'image_hash' => class_exists( 'WTI_Image_Sync' ) ? (string) get_post_meta( $product_id, WTI_Image_Sync::META_IMAGE_SET_HASH, true ) : '',
			);
		}

		update_option(
			self::OPTION_KEY,
			array(
				'catalog_date' => (string) $catalog_date,
				'updated_at'   => current_time( 'mysql' ),
				'offers'       => $rows,
			),
			false
		);
	}

	private static function build_plan_rows( $plan ) {
		$rows = array();

		foreach ( $plan['simple'] as $offer ) {
			$rows[ (string) $offer['id'] ] = self::offer_row( $offer );
		}

		foreach ( $plan['variable'] as $variable ) {
			$rows[ (string) $variable['parent']['id'] ] = self::offer_row( $variable['parent'] );

			foreach ( $variable['variations'] as $variation ) {
				$rows[ (string) $variation['id'] ] = self::offer_row( $variation );
			}
		}

		return $rows;
	}

	private static function offer_row( $offer ) {
		return array(
			'offer_id'   => (string) $offer['id'],
			'raw_hash'   => isset( $offer['raw_hash'] ) ? (string) $offer['raw_hash'] : '',
			'image_hash' => class_exists( 'WTI_Image_Sync' ) ? WTI_Image_Sync::build_image_set_hash_from_urls( isset( $offer['pictures'] ) ? $offer['pictures'] : array() ) : '',
		);
	}

	private static function row_is_unchanged( $offer, $previous_rows, $import_images ) {
		$offer_id = isset( $offer['id'] ) ? (string) $offer['id'] : '';

		if ( '' === $offer_id || empty( $previous_rows[ $offer_id ] ) ) {
			return false;
		}

		$previous = $previous_rows[ $offer_id ];

		if ( empty( $previous['product_id'] ) || empty( $previous['raw_hash'] ) || (string) $previous['raw_hash'] !== (string) $offer['raw_hash'] ) {
			return false;
		}

		if ( ! $import_images ) {
			return true;
		}

		$expected_image_hash = class_exists( 'WTI_Image_Sync' ) ? WTI_Image_Sync::build_image_set_hash_from_urls( isset( $offer['pictures'] ) ? $offer['pictures'] : array() ) : '';

		return '' !== $expected_image_hash && ! empty( $previous['image_hash'] ) && (string) $previous['image_hash'] === $expected_image_hash;
	}

	private static function find_deleted_products( $previous_rows, $current_rows, $category_ids = array() ) {
		$deleted = array();
		$current_product_ids = array();

		foreach ( $previous_rows as $offer_id => $row ) {
			if ( isset( $current_rows[ $offer_id ] ) && ! empty( $row['product_id'] ) ) {
				$current_product_ids[] = (int) $row['product_id'];
			}

			if ( isset( $current_rows[ $offer_id ] ) || empty( $row['product_id'] ) ) {
				continue;
			}

			$deleted[ (int) $row['product_id'] ] = array(
				'product_id' => (int) $row['product_id'],
				'offer_id'   => (string) $offer_id,
			);
		}

		foreach ( self::find_products_missing_from_categories( array_unique( $current_product_ids ), $category_ids ) as $product_id ) {
			if ( isset( $deleted[ $product_id ] ) ) {
				continue;
			}

			$deleted[ $product_id ] = array(
				'product_id' => (int) $product_id,
				'offer_id'   => (string) get_post_meta( $product_id, WTI_Product_Sync::META_OFFER_ID, true ),
			);
		}

		return array_values( $deleted );
	}

	private static function find_products_missing_from_categories( $current_product_ids, $category_ids ) {
		$category_ids = array_values( array_filter( array_map( 'absint', (array) $category_ids ) ) );

		if ( empty( $category_ids ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'fields'         => 'ids',
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'post__not_in'   => array_map( 'absint', (array) $current_product_ids ),
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $category_ids,
						'include_children' => true,
					),
				),
			)
		);

		return array_map( 'absint', $query->posts );
	}
}
