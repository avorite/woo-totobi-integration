<?php

defined( 'ABSPATH' ) || exit;

class WTI_Product_Sync {
	const META_SOURCE       = '_wti_source';
	const META_OFFER_ID     = '_wti_offer_id';
	const META_GROUP_ID     = '_wti_group_id';
	const META_VENDOR_CODE  = '_wti_vendor_code';
	const META_CATEGORY_ID  = '_wti_category_id';
	const META_SOURCE_URL   = '_wti_source_url';
	const META_RAW_HASH     = '_wti_raw_hash';
	const META_CATALOG_DATE = '_wti_catalog_date';

	public static function build_action_plan( $plan, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'      => true,
				'catalog_date' => '',
			)
		);

		$actions = array(
			'simple'     => array(),
			'variable'   => array(),
			'variations' => array(),
			'summary'    => array(
				'create_simple'     => 0,
				'update_simple'     => 0,
				'create_variable'   => 0,
				'update_variable'   => 0,
				'create_variation'  => 0,
				'update_variation'  => 0,
				'skipped'           => 0,
			),
		);

		foreach ( $plan['simple'] as $offer ) {
			$existing_id = self::find_existing_simple_product_id( $offer );
			$action      = $existing_id ? 'update_simple' : 'create_simple';

			$actions['summary'][ $action ]++;
			$actions['simple'][] = self::build_simple_action( $action, $existing_id, $offer, $args );
		}

		foreach ( $plan['variable'] as $variable ) {
			$parent      = $variable['parent'];
			$existing_id = self::find_existing_variable_product_id( $variable );
			$action      = $existing_id ? 'update_variable' : 'create_variable';

			$actions['summary'][ $action ]++;
			$variable_action = self::build_variable_action( $action, $existing_id, $variable, $args );

			foreach ( $variable['variations'] as $variation ) {
				$variation_id     = self::find_existing_variation_id( $variation, $existing_id );
				$variation_action = $variation_id ? 'update_variation' : 'create_variation';

				$actions['summary'][ $variation_action ]++;
				$actions['variations'][] = self::build_variation_action( $variation_action, $variation_id, $existing_id, $parent, $variation, $args );
			}

			$actions['variable'][] = $variable_action;
		}

		return $actions;
	}

	public static function execute_action_plan( $actions, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run' => true,
			)
		);

		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'status'  => 'dry_run',
				'message' => 'Product creation/update is disabled in dry-run mode.',
				'summary' => isset( $actions['summary'] ) ? $actions['summary'] : array(),
			);
		}

		return new WP_Error( 'wti_product_write_not_implemented', 'Product write operations are not implemented yet.' );
	}

	private static function build_simple_action( $action, $product_id, $offer, $args ) {
		return array(
			'action'      => $action,
			'product_id'  => $product_id,
			'offer_id'    => $offer['id'],
			'sku'         => $offer['sku'],
			'name'        => $offer['name'],
			'category_id' => $offer['category_id'],
			'price'       => $offer['price'],
			'stock'       => $offer['quantity_in_stock'],
			'stock_status' => $offer['stock_status'],
			'meta'        => self::build_common_meta( $offer, $args ),
		);
	}

	private static function build_variable_action( $action, $product_id, $variable, $args ) {
		$parent = $variable['parent'];

		return array(
			'action'          => $action,
			'product_id'      => $product_id,
			'group_id'        => $variable['group_id'],
			'offer_id'        => $parent['id'],
			'sku'             => $parent['sku'],
			'name'            => $parent['name'],
			'category_id'     => $parent['category_id'],
			'variation_count' => count( $variable['variations'] ),
			'attributes'      => self::collect_variable_attributes( $variable['variations'] ),
			'meta'            => self::build_common_meta( $parent, $args ),
		);
	}

	private static function build_variation_action( $action, $variation_id, $parent_id, $parent_offer, $variation, $args ) {
		return array(
			'action'       => $action,
			'variation_id' => $variation_id,
			'parent_id'    => $parent_id,
			'group_id'     => $variation['group_id'],
			'offer_id'     => $variation['id'],
			'sku'          => $variation['sku'],
			'name'         => $parent_offer['name'],
			'size'         => $variation['size'],
			'color'        => $variation['color'],
			'price'        => $variation['price'],
			'stock'        => $variation['quantity_in_stock'],
			'stock_status' => $variation['stock_status'],
			'meta'         => self::build_common_meta( $variation, $args ),
		);
	}

	private static function build_common_meta( $offer, $args ) {
		return array(
			self::META_SOURCE       => 'totobi',
			self::META_OFFER_ID     => $offer['id'],
			self::META_GROUP_ID     => $offer['group_id'],
			self::META_VENDOR_CODE  => $offer['vendor_code'],
			self::META_CATEGORY_ID  => $offer['category_id'],
			self::META_SOURCE_URL   => $offer['url'],
			self::META_RAW_HASH     => $offer['raw_hash'],
			self::META_CATALOG_DATE => isset( $args['catalog_date'] ) ? $args['catalog_date'] : '',
		);
	}

	private static function collect_variable_attributes( $variations ) {
		$sizes  = array();
		$colors = array();

		foreach ( $variations as $variation ) {
			if ( ! empty( $variation['size'] ) ) {
				$sizes[] = $variation['size'];
			}

			if ( ! empty( $variation['color'] ) ) {
				$colors[] = $variation['color'];
			}
		}

		return array(
			'size'  => array_values( array_unique( $sizes ) ),
			'color' => array_values( array_unique( $colors ) ),
		);
	}

	private static function find_existing_simple_product_id( $offer ) {
		$product_id = self::find_product_id_by_meta( self::META_OFFER_ID, $offer['id'], array( 'product' ) );

		if ( $product_id ) {
			return $product_id;
		}

		return self::find_product_id_by_sku( $offer['sku'] );
	}

	private static function find_existing_variable_product_id( $variable ) {
		$product_id = self::find_product_id_by_meta( self::META_GROUP_ID, $variable['group_id'], array( 'product' ) );

		if ( $product_id ) {
			return $product_id;
		}

		return self::find_product_id_by_sku( $variable['parent']['sku'] );
	}

	private static function find_existing_variation_id( $variation, $parent_id = 0 ) {
		$product_id = self::find_product_id_by_meta( self::META_OFFER_ID, $variation['id'], array( 'product_variation' ) );

		if ( $product_id ) {
			return $product_id;
		}

		$product_id = self::find_product_id_by_sku( $variation['sku'] );

		if ( ! $product_id || ! $parent_id ) {
			return $product_id;
		}

		return (int) wp_get_post_parent_id( $product_id ) === (int) $parent_id ? $product_id : 0;
	}

	private static function find_product_id_by_sku( $sku ) {
		if ( '' === (string) $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}

		return (int) wc_get_product_id_by_sku( $sku );
	}

	private static function find_product_id_by_meta( $meta_key, $meta_value, $post_types ) {
		if ( '' === (string) $meta_value ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'fields'         => 'ids',
				'post_type'      => $post_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);

		return empty( $query->posts ) ? 0 : (int) $query->posts[0];
	}
}

