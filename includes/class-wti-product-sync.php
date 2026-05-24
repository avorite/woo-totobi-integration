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
				'category_map' => array(),
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
				'skip_unchanged_simple' => 0,
				'skip_unchanged_variable' => 0,
				'skip_unchanged_variation' => 0,
				'skipped'           => 0,
			),
		);

		foreach ( $plan['simple'] as $offer ) {
			$existing_id = self::find_existing_simple_product_id( $offer );
			$action      = $existing_id ? 'update_simple' : 'create_simple';

			if ( $existing_id && self::is_offer_unchanged( $existing_id, $offer, $args ) ) {
				$action = 'skip_unchanged_simple';
			}

			$actions['summary'][ $action ]++;
			$actions['simple'][] = self::build_simple_action( $action, $existing_id, $offer, $args );
		}

		foreach ( $plan['variable'] as $variable ) {
			$parent      = $variable['parent'];
			$existing_id = self::find_existing_variable_product_id( $variable );
			$action      = $existing_id ? 'update_variable' : 'create_variable';
			$parent_unchanged = $existing_id && self::is_offer_unchanged( $existing_id, $parent, $args );
			$has_changed_variations = false;

			$variable_action = self::build_variable_action( $action, $existing_id, $variable, $args );

			foreach ( $variable['variations'] as $variation ) {
				$variation_id     = self::find_existing_variation_id( $variation, $existing_id );
				$variation_action = $variation_id ? 'update_variation' : 'create_variation';

				if ( $variation_id && self::is_offer_unchanged( $variation_id, $variation, array( 'import_images' => false ) ) ) {
					$variation_action = 'skip_unchanged_variation';
				} else {
					$has_changed_variations = true;
				}

				$actions['summary'][ $variation_action ]++;
				$actions['variations'][] = self::build_variation_action( $variation_action, $variation_id, $existing_id, $parent, $variation, $args );
			}

			if ( $parent_unchanged && ! $has_changed_variations ) {
				$action = 'skip_unchanged_variable';
				$variable_action['action'] = $action;
			}

			$actions['summary'][ $action ]++;

			$actions['variable'][] = $variable_action;
		}

		return $actions;
	}

	public static function execute_action_plan( $actions, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'        => true,
				'import_limit'   => 10,
				'variable_limit' => 1,
				'simple_offset'  => 0,
				'variable_offset' => 0,
				'import_images'  => false,
				'product_status' => 'draft',
			)
		);

		if ( ! empty( $args['dry_run'] ) ) {
			return array(
				'status'  => 'dry_run',
				'message' => 'Product creation/update is disabled in dry-run mode.',
				'summary' => isset( $actions['summary'] ) ? $actions['summary'] : array(),
			);
		}

		if ( ! class_exists( 'WC_Product_Simple' ) || ! class_exists( 'WC_Product_Variable' ) || ! class_exists( 'WC_Product_Variation' ) ) {
			return new WP_Error( 'wti_woocommerce_missing', 'WooCommerce product classes are not available.' );
		}

		$simple_limit   = max( 0, absint( $args['import_limit'] ) );
		$variable_limit = max( 0, absint( $args['variable_limit'] ) );
		$simple_offset  = max( 0, absint( $args['simple_offset'] ) );
		$variable_offset = max( 0, absint( $args['variable_offset'] ) );
		$simple_total   = count( $actions['simple'] );
		$variable_total = count( $actions['variable'] );
		$simple_batch   = $simple_limit > 0 ? array_slice( $actions['simple'], $simple_offset, $simple_limit ) : array();
		$variable_batch = $variable_limit > 0 ? array_slice( $actions['variable'], $variable_offset, $variable_limit ) : array();
		$status         = in_array( $args['product_status'], array( 'draft', 'publish' ), true ) ? $args['product_status'] : 'draft';
		$result         = array(
			'status'             => 'written',
			'processed'          => 0,
			'simple_total'       => $simple_total,
			'variable_total'     => $variable_total,
			'simple_offset'      => $simple_offset,
			'variable_offset'    => $variable_offset,
			'next_simple_offset' => min( $simple_total, $simple_offset + count( $simple_batch ) ),
			'next_variable_offset' => min( $variable_total, $variable_offset + count( $variable_batch ) ),
			'simple_complete'    => $simple_offset + count( $simple_batch ) >= $simple_total,
			'variable_complete'  => $variable_offset + count( $variable_batch ) >= $variable_total,
			'created_simple'     => 0,
			'updated_simple'     => 0,
			'created_variable'   => 0,
			'updated_variable'   => 0,
			'created_variation'  => 0,
			'updated_variation'  => 0,
			'skipped_unchanged'  => 0,
			'skipped_simple'     => 0,
			'skipped_variable'   => 0,
			'skipped_variation'  => 0,
			'imported_images'    => 0,
			'reused_images'      => 0,
			'skipped_images'     => 0,
			'image_errors'       => array(),
			'errors'             => array(),
		);

		foreach ( $simple_batch as $action ) {
			if ( 'skip_unchanged_simple' === $action['action'] ) {
				$result['processed']++;
				$result['skipped_unchanged']++;
				continue;
			}

			$write = self::write_simple_product( $action, $status, ! empty( $args['import_images'] ) );

			if ( is_wp_error( $write ) ) {
				$result['errors'][] = array(
					'action' => $action['action'],
					'sku'    => $action['sku'],
					'error'  => $write->get_error_message(),
				);
				continue;
			}

			$result['processed']++;

			if ( 'create_simple' === $action['action'] ) {
				$result['created_simple']++;
			} else {
				$result['updated_simple']++;
			}

			self::merge_image_result( $result, $write );
		}

		$variation_actions = self::index_variation_actions_by_group( $actions['variations'] );

		foreach ( $variable_batch as $action ) {
			if ( 'skip_unchanged_variable' === $action['action'] ) {
				$result['processed']++;
				$result['skipped_unchanged']++;
				continue;
			}

			$write = self::write_variable_product( $action, $status, ! empty( $args['import_images'] ) );

			if ( is_wp_error( $write ) ) {
				$result['errors'][] = array(
					'action' => $action['action'],
					'sku'    => $action['sku'],
					'error'  => $write->get_error_message(),
				);
				continue;
			}

			$result['processed']++;

			if ( 'create_variable' === $action['action'] ) {
				$result['created_variable']++;
			} else {
				$result['updated_variable']++;
			}

			self::merge_image_result( $result, $write );

			$group_variations = isset( $variation_actions[ $action['group_id'] ] ) ? $variation_actions[ $action['group_id'] ] : array();

			foreach ( $group_variations as $variation_action ) {
				if ( 'skip_unchanged_variation' === $variation_action['action'] ) {
					$result['skipped_unchanged']++;
					continue;
				}

				$variation_action['parent_id'] = $write['product_id'];
				$variation_write               = self::write_variation( $variation_action );

				if ( is_wp_error( $variation_write ) ) {
					$result['errors'][] = array(
						'action' => $variation_action['action'],
						'sku'    => $variation_action['sku'],
						'error'  => $variation_write->get_error_message(),
					);
					continue;
				}

				if ( 'create_variation' === $variation_action['action'] ) {
					$result['created_variation']++;
				} else {
					$result['updated_variation']++;
				}
			}
		}

		$result['skipped_simple']    = max( 0, $simple_total - $result['next_simple_offset'] );
		$result['skipped_variable']  = max( 0, $variable_total - $result['next_variable_offset'] );
		$result['skipped_variation'] = max( 0, count( $actions['variations'] ) - $result['created_variation'] - $result['updated_variation'] );

		return $result;
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
			'woo_category_ids' => self::resolve_woo_category_ids( $offer, $args ),
			'description' => $offer['description'],
			'params'      => $offer['params'],
			'pictures'    => $offer['pictures'],
			'meta'        => self::build_common_meta( $offer, $args ),
		);
	}

	private static function write_simple_product( $action, $status, $import_images = false ) {
		try {
			$product = ! empty( $action['product_id'] ) ? wc_get_product( $action['product_id'] ) : new WC_Product_Simple();

			if ( ! $product || ! is_a( $product, 'WC_Product_Simple' ) ) {
				$product = new WC_Product_Simple();
			}

			$product->set_name( wp_strip_all_tags( $action['name'] ) );
			$product->set_status( $status );
			$product->set_catalog_visibility( 'visible' );
			$product->set_description( wp_kses_post( $action['description'] ) );
			$product->set_attributes( self::build_display_attributes( $action['params'] ) );
			$product->set_regular_price( wc_format_decimal( $action['price'] ) );
			$product->set_price( wc_format_decimal( $action['price'] ) );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( max( 0, (int) $action['stock'] ) );
			$product->set_stock_status( $action['stock_status'] );

			if ( ! empty( $action['woo_category_ids'] ) ) {
				$product->set_category_ids( array_map( 'absint', $action['woo_category_ids'] ) );
			}

			if ( ! empty( $action['sku'] ) && $product->get_sku() !== $action['sku'] ) {
				$product->set_sku( $action['sku'] );
			}

			foreach ( $action['meta'] as $key => $value ) {
				$product->update_meta_data( $key, $value );
			}

			$product->update_meta_data( '_wti_params', wp_json_encode( $action['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$product->update_meta_data( '_wti_picture_urls', wp_json_encode( $action['pictures'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

			$image_result = array();

			if ( $import_images ) {
				$image_result = WTI_Image_Sync::sync_product_images( $product, $action['pictures'] );
			}

			$product_id = $product->save();

			return array(
				'product_id'   => $product_id,
				'image_result' => $image_result,
			);
		} catch ( Exception $exception ) {
			return new WP_Error( 'wti_simple_product_write_failed', $exception->getMessage() );
		}
	}

	private static function write_variable_product( $action, $status, $import_images = false ) {
		try {
			$product = ! empty( $action['product_id'] ) ? wc_get_product( $action['product_id'] ) : new WC_Product_Variable();

			if ( ! $product || ! is_a( $product, 'WC_Product_Variable' ) ) {
				$product = new WC_Product_Variable();
			}

			$product->set_name( wp_strip_all_tags( $action['name'] ) );
			$product->set_status( $status );
			$product->set_catalog_visibility( 'visible' );
			$product->set_sku( $action['sku'] );
			$product->set_attributes( self::merge_product_attributes( self::build_product_attributes( $action['attributes'] ), self::build_display_attributes( $action['params'], 10 ) ) );

			if ( ! empty( $action['woo_category_ids'] ) ) {
				$product->set_category_ids( array_map( 'absint', $action['woo_category_ids'] ) );
			}

			foreach ( $action['meta'] as $key => $value ) {
				$product->update_meta_data( $key, $value );
			}

			$image_result = array();

			if ( $import_images ) {
				$image_result = WTI_Image_Sync::sync_product_images( $product, $action['pictures'] );
			}

			$product_id = $product->save();

			return array(
				'product_id'   => $product_id,
				'image_result' => $image_result,
			);
		} catch ( Exception $exception ) {
			return new WP_Error( 'wti_variable_product_write_failed', $exception->getMessage() );
		}
	}

	private static function write_variation( $action ) {
		try {
			$variation = ! empty( $action['variation_id'] ) ? wc_get_product( $action['variation_id'] ) : new WC_Product_Variation();

			if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) {
				$variation = new WC_Product_Variation();
			}

			$variation->set_parent_id( absint( $action['parent_id'] ) );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( wc_format_decimal( $action['price'] ) );
			$variation->set_price( wc_format_decimal( $action['price'] ) );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( max( 0, (int) $action['stock'] ) );
			$variation->set_stock_status( $action['stock_status'] );
			$variation->set_attributes( array_filter( array( 'size' => $action['size'], 'color' => $action['color'] ) ) );

			if ( ! empty( $action['sku'] ) && $variation->get_sku() !== $action['sku'] ) {
				$variation->set_sku( $action['sku'] );
			}

			foreach ( $action['meta'] as $key => $value ) {
				$variation->update_meta_data( $key, $value );
			}

			$variation_id = $variation->save();

			return array(
				'variation_id' => $variation_id,
			);
		} catch ( Exception $exception ) {
			return new WP_Error( 'wti_variation_write_failed', $exception->getMessage() );
		}
	}

	private static function merge_image_result( &$result, $write ) {
		if ( empty( $write['image_result'] ) || ! is_array( $write['image_result'] ) ) {
			return;
		}

		$result['imported_images'] += isset( $write['image_result']['imported_count'] ) ? (int) $write['image_result']['imported_count'] : 0;
		$result['reused_images']   += isset( $write['image_result']['reused_count'] ) ? (int) $write['image_result']['reused_count'] : 0;
		$result['skipped_images']  += isset( $write['image_result']['skipped_count'] ) ? (int) $write['image_result']['skipped_count'] : 0;

		if ( ! empty( $write['image_result']['errors'] ) ) {
			$result['image_errors'] = array_merge( $result['image_errors'], $write['image_result']['errors'] );
		}
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
			'woo_category_ids' => self::resolve_woo_category_ids( $parent, $args ),
			'pictures'        => $parent['pictures'],
			'params'          => $parent['params'],
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

	private static function is_offer_unchanged( $product_id, $offer, $args ) {
		if ( ! $product_id || empty( $offer['raw_hash'] ) ) {
			return false;
		}

		$current_hash = (string) get_post_meta( $product_id, self::META_RAW_HASH, true );

		if ( $current_hash !== (string) $offer['raw_hash'] ) {
			return false;
		}

		if ( empty( $args['import_images'] ) ) {
			return true;
		}

		if ( ! class_exists( 'WTI_Image_Sync' ) ) {
			return false;
		}

		$current_image_set = (string) get_post_meta( $product_id, WTI_Image_Sync::META_IMAGE_SET_HASH, true );
		$expected_image_set = WTI_Image_Sync::build_image_set_hash_from_urls( isset( $offer['pictures'] ) ? $offer['pictures'] : array() );

		return '' !== $current_image_set && $current_image_set === $expected_image_set;
	}

	private static function resolve_woo_category_ids( $offer, $args ) {
		$map         = isset( $args['category_map'] ) && is_array( $args['category_map'] ) ? $args['category_map'] : array();
		$category_id = isset( $offer['category_id'] ) ? (string) $offer['category_id'] : '';

		if ( '' === $category_id || empty( $map[ $category_id ] ) ) {
			return array();
		}

		return array( absint( $map[ $category_id ] ) );
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

	private static function build_product_attributes( $attributes ) {
		$product_attributes = array();
		$position           = 0;

		foreach ( array( 'size' => 'Size', 'color' => 'Color' ) as $key => $label ) {
			if ( empty( $attributes[ $key ] ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $key );
			$attribute->set_options( array_values( array_unique( $attributes[ $key ] ) ) );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$product_attributes[ $key ] = $attribute;
		}

		return $product_attributes;
	}

	private static function build_display_attributes( $params, $start_position = 0 ) {
		$product_attributes = array();
		$position           = $start_position;
		$skip               = array( 'Розмір', 'Колір' );

		foreach ( (array) $params as $name => $value ) {
			$name  = trim( (string) $name );
			$value = trim( (string) $value );

			if ( '' === $name || '' === $value || in_array( $name, $skip, true ) ) {
				continue;
			}

			$key       = sanitize_title( $name );
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( false );

			$product_attributes[ $key ] = $attribute;
		}

		return $product_attributes;
	}

	private static function merge_product_attributes( $variation_attributes, $display_attributes ) {
		return array_merge( $variation_attributes, $display_attributes );
	}

	private static function index_variation_actions_by_group( $variation_actions ) {
		$indexed = array();

		foreach ( $variation_actions as $variation_action ) {
			$indexed[ $variation_action['group_id'] ][] = $variation_action;
		}

		return $indexed;
	}

	private static function find_existing_simple_product_id( $offer ) {
		$product_id = self::find_product_id_by_meta( self::META_OFFER_ID, $offer['id'], array( 'product' ) );

		if ( $product_id ) {
			return $product_id;
		}

		$product_id = self::find_product_id_by_sku( $offer['sku'] );

		if ( $product_id ) {
			return $product_id;
		}

		return self::find_unclaimed_product_id_by_exact_title( $offer['name'] );
	}

	private static function find_existing_variable_product_id( $variable ) {
		$product_id = self::find_product_id_by_meta( self::META_GROUP_ID, $variable['group_id'], array( 'product' ) );

		if ( $product_id ) {
			return $product_id;
		}

		$product_id = self::find_product_id_by_sku( $variable['parent']['sku'] );

		if ( $product_id ) {
			return $product_id;
		}

		return self::find_unclaimed_product_id_by_exact_title( $variable['parent']['name'] );
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

	private static function find_unclaimed_product_id_by_exact_title( $title ) {
		global $wpdb;

		$title = trim( (string) $title );

		if ( '' === $title ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private') AND post_title = %s LIMIT 3",
				'product',
				$title
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$candidates = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( get_post_meta( $id, self::META_OFFER_ID, true ) || get_post_meta( $id, self::META_GROUP_ID, true ) ) {
				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

			if ( $product && '' !== (string) $product->get_sku() ) {
				continue;
			}

			$candidates[] = $id;
		}

		return 1 === count( $candidates ) ? (int) $candidates[0] : 0;
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
