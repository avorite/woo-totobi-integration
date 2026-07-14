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
	const ATTR_SIZE         = 'pa_size';
	const ATTR_COLOR        = 'pa_kolir';
	const ATTR_GENDER       = 'pa_vyd';

	public static function build_action_plan( $plan, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'      => true,
				'catalog_date' => '',
				'category_map' => array(),
				'markup_percent' => 0,
			)
		);

		$actions = array(
			'simple'     => array(),
			'variable'   => array(),
			'variations' => array(),
			'deleted'    => array(),
			'summary'    => array(
				'create_simple'     => 0,
				'update_simple'     => 0,
				'create_variable'   => 0,
				'update_variable'   => 0,
				'create_variation'  => 0,
				'update_variation'  => 0,
				'mark_deleted_outofstock' => 0,
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
			$parent_unchanged = $existing_id && self::is_offer_unchanged( $existing_id, $parent, $args ) && self::variable_product_attributes_match( $existing_id, $variable['variations'] );
			$has_changed_variations = false;

			$variable_action = self::build_variable_action( $action, $existing_id, $variable, $args );

			foreach ( $variable['variations'] as $variation ) {
				$variation_id     = self::find_existing_variation_id( $variation, $existing_id );
				$variation_action = $variation_id ? 'update_variation' : 'create_variation';

				if ( $variation_id && self::is_offer_unchanged( $variation_id, $variation, array_merge( $args, array( 'import_images' => false ) ) ) ) {
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

		foreach ( isset( $plan['deleted'] ) && is_array( $plan['deleted'] ) ? $plan['deleted'] : array() as $deleted ) {
			if ( empty( $deleted['product_id'] ) ) {
				continue;
			}

			$actions['summary']['mark_deleted_outofstock']++;
			$actions['deleted'][] = array(
				'action'     => 'mark_deleted_outofstock',
				'product_id' => absint( $deleted['product_id'] ),
				'offer_id'   => isset( $deleted['offer_id'] ) ? (string) $deleted['offer_id'] : '',
			);
		}

		return $actions;
	}

	public static function offer_needs_image_sync( $offer ) {
		if ( empty( $offer['pictures'] ) || ! class_exists( 'WTI_Image_Sync' ) ) {
			return false;
		}

		$product_id = self::find_product_id_for_validation( isset( $offer['id'] ) ? (string) $offer['id'] : '', isset( $offer['sku'] ) ? (string) $offer['sku'] : '' );

		if ( ! $product_id ) {
			return true;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return true;
		}

		$current_image_set  = (string) get_post_meta( $product_id, WTI_Image_Sync::META_IMAGE_SET_HASH, true );
		$expected_image_set = WTI_Image_Sync::build_image_set_hash_from_urls( $offer['pictures'] );
		$current_count      = self::product_image_count( $product );
		$stored_attachments = (string) get_post_meta( $product_id, WTI_Image_Sync::META_ATTACHMENT_SET_HASH, true );
		$current_attachments = WTI_Image_Sync::build_attachment_set_hash_from_product( $product );

		if ( '' !== $current_image_set && $current_image_set === $expected_image_set && $current_count > 0 && '' !== $stored_attachments && hash_equals( $stored_attachments, $current_attachments ) ) {
			return false;
		}

		return true;
	}

	public static function sync_offer_images_batch( $offers, $dry_run = false ) {
		$result = array(
			'status'             => $dry_run ? 'dry_run' : 'written',
			'processed'          => 0,
			'created_simple'     => 0,
			'updated_simple'     => 0,
			'created_variable'   => 0,
			'updated_variable'   => 0,
			'created_variation'  => 0,
			'updated_variation'  => 0,
			'skipped_unchanged'  => 0,
			'deleted_outofstock' => 0,
			'imported_images'    => 0,
			'reused_images'      => 0,
			'skipped_images'     => 0,
			'report_rows'        => array(),
			'image_errors'       => array(),
			'errors'             => array(),
		);

		foreach ( (array) $offers as $offer ) {
			$result['processed']++;

			if ( $dry_run ) {
				continue;
			}

			$product_id = self::find_product_id_for_validation( isset( $offer['offer_id'] ) ? (string) $offer['offer_id'] : '', isset( $offer['sku'] ) ? (string) $offer['sku'] : '' );

			if ( ! $product_id ) {
				$result['errors'][] = array(
					'action' => 'sync_images',
					'sku'    => isset( $offer['sku'] ) ? $offer['sku'] : '',
					'error'  => 'Product not found for image sync.',
				);
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				$result['errors'][] = array(
					'action' => 'sync_images',
					'sku'    => isset( $offer['sku'] ) ? $offer['sku'] : '',
					'error'  => 'Product could not be loaded for image sync.',
				);
				continue;
			}

			$image_result = WTI_Image_Sync::sync_product_images( $product, isset( $offer['pictures'] ) ? $offer['pictures'] : array() );
			$product->save();
			self::sync_polylang_product_fields( $product );
			self::merge_image_result( $result, array( 'image_result' => $image_result ) );

			$result['report_rows'][] = self::build_report_row( isset( $offer['type'] ) ? $offer['type'] : 'media', 'sync_images', $offer, $product_id, 'Images synchronized.' );
		}

		return $result;
	}

	public static function find_product_id_for_validation( $offer_id, $sku = '' ) {
		$product_id = self::find_product_id_by_offer_id( $offer_id );

		if ( $product_id ) {
			return $product_id;
		}

		return self::find_product_id_by_sku( $sku );
	}

	public static function execute_action_plan( $actions, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'        => true,
				'import_limit'   => 10,
				'variable_limit' => 1,
				'deleted_limit'  => 50,
				'simple_offset'  => 0,
				'variable_offset' => 0,
				'deleted_offset' => 0,
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
		$deleted_limit  = max( 0, absint( $args['deleted_limit'] ) );
		$simple_offset  = max( 0, absint( $args['simple_offset'] ) );
		$variable_offset = max( 0, absint( $args['variable_offset'] ) );
		$deleted_offset = max( 0, absint( $args['deleted_offset'] ) );
		$simple_total   = count( $actions['simple'] );
		$variable_total = count( $actions['variable'] );
		$deleted_total  = isset( $actions['deleted'] ) ? count( $actions['deleted'] ) : 0;
		$simple_batch   = $simple_limit > 0 ? array_slice( $actions['simple'], $simple_offset, $simple_limit ) : array();
		$variable_batch = $variable_limit > 0 ? array_slice( $actions['variable'], $variable_offset, $variable_limit ) : array();
		$deleted_batch  = $deleted_limit > 0 && ! empty( $actions['deleted'] ) ? array_slice( $actions['deleted'], $deleted_offset, $deleted_limit ) : array();
		$status         = in_array( $args['product_status'], array( 'draft', 'publish' ), true ) ? $args['product_status'] : 'draft';
		$result         = array(
			'status'             => 'written',
			'processed'          => 0,
			'simple_total'       => $simple_total,
			'variable_total'     => $variable_total,
			'deleted_total'      => $deleted_total,
			'simple_offset'      => $simple_offset,
			'variable_offset'    => $variable_offset,
			'deleted_offset'     => $deleted_offset,
			'next_simple_offset' => min( $simple_total, $simple_offset + count( $simple_batch ) ),
			'next_variable_offset' => min( $variable_total, $variable_offset + count( $variable_batch ) ),
			'next_deleted_offset' => min( $deleted_total, $deleted_offset + count( $deleted_batch ) ),
			'simple_complete'    => $simple_offset + count( $simple_batch ) >= $simple_total,
			'variable_complete'  => $variable_offset + count( $variable_batch ) >= $variable_total,
			'deleted_complete'   => $deleted_offset + count( $deleted_batch ) >= $deleted_total,
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
			'deleted_outofstock' => 0,
			'imported_images'    => 0,
			'reused_images'      => 0,
			'skipped_images'     => 0,
			'report_rows'        => array(),
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
			$result['report_rows'][] = self::build_report_row( 'simple', $action['action'], $action, $write['product_id'], '' );
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
			$variation_details = array();

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

				$variation_details[] = trim( sprintf( '%s %s %s', $variation_action['sku'], $variation_action['size'], $variation_action['action'] ) );
			}

			$result['report_rows'][] = self::build_report_row( 'variable', $action['action'], $action, $write['product_id'], implode( '; ', array_filter( $variation_details ) ) );
		}

		foreach ( $deleted_batch as $action ) {
			$deleted = self::mark_product_outofstock( $action['product_id'] );

			if ( is_wp_error( $deleted ) ) {
				$result['errors'][] = array(
					'action' => $action['action'],
					'sku'    => $action['offer_id'],
					'error'  => $deleted->get_error_message(),
				);
				continue;
			}

			$result['processed']++;
			$result['deleted_outofstock']++;
			$result['report_rows'][] = self::build_report_row( 'missing', 'outofstock', $action, $action['product_id'], 'Missing from latest Totobi feed; set out of stock.' );
		}

		$result['skipped_simple']    = max( 0, $simple_total - $result['next_simple_offset'] );
		$result['skipped_variable']  = max( 0, $variable_total - $result['next_variable_offset'] );
		$result['skipped_variation'] = max( 0, count( $actions['variations'] ) - $result['created_variation'] - $result['updated_variation'] );

		return $result;
	}

	private static function build_report_row( $type, $action, $action_data, $product_id, $details ) {
		$product = $product_id ? wc_get_product( $product_id ) : false;

		return array(
			'type'    => $type,
			'action'  => $action,
			'name'    => isset( $action_data['name'] ) ? $action_data['name'] : ( $product ? $product->get_name() : '' ),
			'sku'     => isset( $action_data['sku'] ) ? $action_data['sku'] : ( $product ? $product->get_sku() : '' ),
			'url'     => $product_id ? get_permalink( $product_id ) : '',
			'details' => $details,
		);
	}

	private static function build_simple_action( $action, $product_id, $offer, $args ) {
		return array(
			'action'      => $action,
			'product_id'  => $product_id,
			'offer_id'    => $offer['id'],
			'sku'         => $offer['sku'],
			'name'        => $offer['name'],
			'category_id' => $offer['category_id'],
			'price'       => self::apply_markup( $offer['price'], $args ),
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

			$sku_filter = self::add_totobi_duplicate_sku_filter( $action );

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

			$product_id = self::save_product_with_totobi_sku_scope( $product, $action );
			self::remove_totobi_duplicate_sku_filter( $sku_filter );
			self::sync_product_taxonomy_attribute_terms( $product );
			self::cleanup_unclaimed_duplicate_variations( $product_id );
			self::sync_polylang_product_fields( $product );
			self::cleanup_unclaimed_duplicate_variations( $product_id );

			if ( 'create_simple' === $action['action'] && class_exists( 'WTI_Autopoly_Integration' ) ) {
				WTI_Autopoly_Integration::maybe_translate_product( $product_id );
			}

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
				$product = ! empty( $action['product_id'] ) ? self::convert_product_to_variable( absint( $action['product_id'] ) ) : new WC_Product_Variable();
			}

			if ( ! $product || ! is_a( $product, 'WC_Product_Variable' ) ) {
				$product = new WC_Product_Variable();
			}

			$product->set_name( wp_strip_all_tags( $action['name'] ) );
			$product->set_status( $status );
			$product->set_catalog_visibility( 'visible' );
			$sku_filter = self::add_totobi_duplicate_sku_filter( $action );
			$product->set_sku( self::is_synthetic_group_id( $action['group_id'] ) ? '' : $action['sku'] );
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

			$product_id = self::save_product_with_totobi_sku_scope( $product, $action );
			self::remove_totobi_duplicate_sku_filter( $sku_filter );
			self::sync_product_taxonomy_attribute_terms( $product );
			self::sync_polylang_product_fields( $product );

			if ( 'create_variable' === $action['action'] && class_exists( 'WTI_Autopoly_Integration' ) ) {
				WTI_Autopoly_Integration::maybe_translate_product( $product_id );
			}

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
			$source_simple_id = self::find_source_simple_product_for_variation( $action );
			$source_image_id  = $source_simple_id ? (int) get_post_thumbnail_id( $source_simple_id ) : 0;

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
			$variation->set_attributes( self::build_variation_attributes( $action ) );

			if ( $source_image_id && ! $variation->get_image_id() ) {
				$variation->set_image_id( $source_image_id );
			}

			$sku_filter = self::add_totobi_duplicate_sku_filter( $action );

			if ( ! empty( $action['sku'] ) && $variation->get_sku() !== $action['sku'] ) {
				$variation->set_sku( $action['sku'] );
			}

			foreach ( $action['meta'] as $key => $value ) {
				$variation->update_meta_data( $key, $value );
			}

			$variation_id = self::save_product_with_totobi_sku_scope( $variation, $action );
			self::remove_totobi_duplicate_sku_filter( $sku_filter );
			self::retire_source_simple_product_for_variation( $source_simple_id, $variation_id, absint( $action['parent_id'] ) );
			self::cleanup_unclaimed_duplicate_variations( absint( $action['parent_id'] ) );
			self::set_parent_default_variation_attributes( absint( $action['parent_id'] ) );
			self::sync_polylang_variation_fields( $variation );
			self::cleanup_unclaimed_duplicate_variations( absint( $action['parent_id'] ) );

			return array(
				'variation_id' => $variation_id,
			);
		} catch ( Exception $exception ) {
			return new WP_Error( 'wti_variation_write_failed', $exception->getMessage() );
		}
	}

	private static function convert_product_to_variable( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		wp_set_object_terms( $product_id, 'variable', 'product_type', false );
		clean_post_cache( $product_id );
		wc_delete_product_transients( $product_id );

		return wc_get_product( $product_id );
	}

	private static function set_parent_default_variation_attributes( $parent_id ) {
		$parent_id = absint( $parent_id );

		if ( ! $parent_id ) {
			return false;
		}

		$parent = wc_get_product( $parent_id );

		if ( ! $parent || ! is_a( $parent, 'WC_Product_Variable' ) ) {
			return false;
		}

		$children = $parent->get_children();

		if ( empty( $children ) ) {
			return false;
		}

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$attributes = array_filter( (array) $variation->get_attributes() );

			if ( empty( $attributes ) ) {
				continue;
			}

			$parent->set_default_attributes( $attributes );
			$parent->save();
			wc_delete_product_transients( $parent_id );

			self::sync_polylang_product_fields( $parent );

			return true;
		}

		return false;
	}

	private static function find_source_simple_product_for_variation( $action ) {
		global $wpdb;

		$parent_id = isset( $action['parent_id'] ) ? absint( $action['parent_id'] ) : 0;
		$offer_id  = isset( $action['offer_id'] ) ? (string) $action['offer_id'] : '';
		$sku       = isset( $action['sku'] ) ? (string) $action['sku'] : '';

		if ( '' !== $offer_id ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'product'
					AND p.ID <> %d
					AND p.post_status IN ('publish','draft','pending','private')
					AND pm.meta_key = %s
					AND pm.meta_value = %s
					ORDER BY p.ID ASC",
					$parent_id,
					self::META_OFFER_ID,
					$offer_id
				)
			);

			$id = self::choose_source_language_candidate( $ids );
			if ( $id ) {
				return $id;
			}
		}

		if ( '' === $sku ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = 'product'
				AND p.ID <> %d
				AND p.post_status IN ('publish','draft','pending','private')
				AND pm.meta_key = '_sku'
				AND pm.meta_value = %s
				ORDER BY p.ID ASC",
				$parent_id,
				$sku
			)
		);

		return self::choose_source_language_candidate( $ids );
	}

	private static function retire_source_simple_product_for_variation( $source_simple_id, $variation_id, $parent_id ) {
		$source_simple_id = absint( $source_simple_id );

		if ( ! $source_simple_id || $source_simple_id === absint( $variation_id ) || $source_simple_id === absint( $parent_id ) ) {
			return;
		}

		if ( 'product' !== get_post_type( $source_simple_id ) ) {
			return;
		}

		wp_update_post(
			array(
				'ID'          => $source_simple_id,
				'post_status' => 'trash',
			)
		);
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

	private static function save_product_with_totobi_sku_scope( $product, $action ) {
		$filter = self::add_totobi_duplicate_sku_filter( $action );

		try {
			return $product->save();
		} finally {
			self::remove_totobi_duplicate_sku_filter( $filter );
		}
	}

	private static function add_totobi_duplicate_sku_filter( $action ) {
		$offer_id = isset( $action['offer_id'] ) ? (string) $action['offer_id'] : '';
		$group_id = isset( $action['group_id'] ) ? (string) $action['group_id'] : '';
		$sku      = isset( $action['sku'] ) ? (string) $action['sku'] : '';

		$sku_is_safe_for_totobi = static function ( $product_id, $checked_sku ) use ( $offer_id, $group_id, $sku ) {
			if ( '' === $sku || (string) $checked_sku !== $sku ) {
				return false;
			}

			$duplicates = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'meta_key'       => '_sku',
					'meta_value'     => $sku,
				)
			);

			if ( empty( $duplicates ) ) {
				return false;
			}

			foreach ( $duplicates as $duplicate_id ) {
				$duplicate_id = absint( $duplicate_id );

				if ( $duplicate_id && $duplicate_id === absint( $product_id ) ) {
					continue;
				}

				$duplicate_source = (string) get_post_meta( $duplicate_id, self::META_SOURCE, true );
				$duplicate_offer = (string) get_post_meta( $duplicate_id, self::META_OFFER_ID, true );
				$duplicate_group = (string) get_post_meta( $duplicate_id, self::META_GROUP_ID, true );

				if ( 'totobi' !== $duplicate_source && '' === $duplicate_offer && '' === $duplicate_group ) {
					return false;
				}

				if ( '' !== $offer_id && $duplicate_offer === $offer_id ) {
					continue;
				}

				if ( '' !== $group_id && $duplicate_group === $group_id ) {
					continue;
				}

				return false;
			}

			return true;
		};

		$filters = array(
			'pre'  => static function ( $pre_unique, $product_id, $checked_sku ) use ( $sku_is_safe_for_totobi ) {
				return $sku_is_safe_for_totobi( $product_id, $checked_sku ) ? true : $pre_unique;
			},
			'post' => static function ( $sku_found, $product_id, $checked_sku ) use ( $sku_is_safe_for_totobi ) {
				return $sku_is_safe_for_totobi( $product_id, $checked_sku ) ? false : $sku_found;
			},
		);

		add_filter( 'wc_product_pre_has_unique_sku', $filters['pre'], 1, 3 );
		add_filter( 'wc_product_has_unique_sku', $filters['post'], 999, 3 );

		return $filters;
	}

	private static function remove_totobi_duplicate_sku_filter( $filters ) {
		if ( is_array( $filters ) ) {
			if ( ! empty( $filters['pre'] ) ) {
				remove_filter( 'wc_product_pre_has_unique_sku', $filters['pre'], 1 );
			}

			if ( ! empty( $filters['post'] ) ) {
				remove_filter( 'wc_product_has_unique_sku', $filters['post'], 999 );
			}
		}
	}

	private static function product_image_count( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_image_id' ) ) {
			return 0;
		}

		$count = $product->get_image_id() ? 1 : 0;

		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			$count += count( array_filter( array_map( 'absint', (array) $product->get_gallery_image_ids() ) ) );
		}

		return $count;
	}

	private static function mark_product_outofstock( $product_id ) {
		try {
			$product = wc_get_product( absint( $product_id ) );

			if ( ! $product ) {
				return new WP_Error( 'wti_missing_deleted_product', 'Product not found.' );
			}

			$product->set_manage_stock( true );
			$product->set_stock_quantity( 0 );
			$product->set_stock_status( 'outofstock' );
			$product->update_meta_data( '_wti_missing_from_feed', current_time( 'mysql' ) );
			$product->save();
			self::sync_polylang_product_fields( $product );

			return true;
		} catch ( Exception $exception ) {
			return new WP_Error( 'wti_deleted_product_update_failed', $exception->getMessage() );
		}
	}

	private static function sync_polylang_product_fields( $source_product ) {
		if ( ! $source_product || ! function_exists( 'pll_get_post_translations' ) ) {
			return;
		}

		$source_id = (int) $source_product->get_id();
		$translations = self::get_polylang_translation_ids( $source_id, 'product' );

		foreach ( $translations as $translation_id ) {
			$target = wc_get_product( $translation_id );

			if ( ! $target || $target->get_type() !== $source_product->get_type() ) {
				continue;
			}

			self::copy_commercial_product_fields( $source_product, $target );
			$target->save();

			if ( $source_product->is_type( 'variable' ) ) {
				self::sync_polylang_variable_variations( $source_product, $target );
				self::cleanup_unclaimed_duplicate_variations( (int) $target->get_id() );
			}
		}
	}

	private static function cleanup_unclaimed_duplicate_variations( $parent_id ) {
		global $wpdb;

		$parent_id = absint( $parent_id );

		if ( ! $parent_id ) {
			return;
		}

		$children = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'post_parent'    => $parent_id,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$claimed = array();

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) {
				continue;
			}

			$sku      = (string) $variation->get_sku();
			$offer_id = (string) get_post_meta( $child_id, self::META_OFFER_ID, true );

			if ( '' !== $sku && '' !== $offer_id ) {
				$claimed[ $sku ] = true;
			}
		}

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) {
				continue;
			}

			$sku      = (string) $variation->get_sku();
			$offer_id = (string) get_post_meta( $child_id, self::META_OFFER_ID, true );

			if ( '' !== $sku && '' === $offer_id && ! empty( $claimed[ $sku ] ) ) {
				self::delete_variation_directly( $child_id );
			}
		}

	}

	private static function delete_variation_directly( $variation_id ) {
		global $wpdb;

		$variation_id = absint( $variation_id );

		if ( ! $variation_id ) {
			return;
		}

		$wpdb->delete( $wpdb->posts, array( 'ID' => $variation_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $variation_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $variation_id ), array( '%d' ) );
		clean_post_cache( $variation_id );
	}

	private static function sync_polylang_variation_fields( $source_variation ) {
		if ( ! $source_variation || ! function_exists( 'pll_get_post_translations' ) ) {
			return;
		}

		foreach ( self::find_polylang_variation_targets( $source_variation ) as $translation_id ) {
			$target = wc_get_product( $translation_id );

			if ( ! $target || ! is_a( $target, 'WC_Product_Variation' ) ) {
				continue;
			}

			self::copy_commercial_product_fields( $source_variation, $target );
			$target->save();
		}
	}

	private static function sync_polylang_variable_variations( $source_parent, $target_parent ) {
		foreach ( $source_parent->get_children() as $source_variation_id ) {
			$source_variation = wc_get_product( $source_variation_id );

			if ( ! $source_variation || ! is_a( $source_variation, 'WC_Product_Variation' ) ) {
				continue;
			}

			foreach ( self::find_matching_translated_variations( $source_variation, (int) $target_parent->get_id() ) as $target_variation_id ) {
				$target_variation = wc_get_product( $target_variation_id );

				if ( ! $target_variation || ! is_a( $target_variation, 'WC_Product_Variation' ) ) {
					continue;
				}

				self::copy_commercial_product_fields( $source_variation, $target_variation );
				$target_variation->save();
			}
		}
	}

	private static function copy_commercial_product_fields( $source, $target ) {
		$target->set_manage_stock( $source->get_manage_stock() );
		$target->set_stock_quantity( $source->get_stock_quantity() );
		$target->set_stock_status( $source->get_stock_status() );

		if ( method_exists( $target, 'set_regular_price' ) && method_exists( $source, 'get_regular_price' ) ) {
			$target->set_regular_price( $source->get_regular_price() );
		}

		if ( method_exists( $target, 'set_sale_price' ) && method_exists( $source, 'get_sale_price' ) ) {
			$target->set_sale_price( $source->get_sale_price() );
		}

		if ( method_exists( $target, 'set_price' ) && method_exists( $source, 'get_price' ) ) {
			$target->set_price( $source->get_price() );
		}

		if ( method_exists( $target, 'set_attributes' ) && method_exists( $source, 'get_attributes' ) && ( is_a( $source, 'WC_Product_Variable' ) || is_a( $source, 'WC_Product_Variation' ) ) ) {
			$target->set_attributes( $source->get_attributes() );
		}

		if ( is_a( $source, 'WC_Product_Variable' ) && is_a( $target, 'WC_Product_Variable' ) && method_exists( $source, 'get_default_attributes' ) && method_exists( $target, 'set_default_attributes' ) ) {
			$target->set_default_attributes( $source->get_default_attributes() );
		}

		if ( method_exists( $target, 'set_image_id' ) && method_exists( $source, 'get_image_id' ) ) {
			$target->set_image_id( $source->get_image_id() );
		}

		if ( method_exists( $target, 'set_gallery_image_ids' ) && method_exists( $source, 'get_gallery_image_ids' ) ) {
			$target->set_gallery_image_ids( $source->get_gallery_image_ids() );
		}

		foreach ( self::commercial_meta_keys_to_copy() as $meta_key ) {
			$value = $source->get_meta( $meta_key, true );

			if ( '' !== $value && null !== $value ) {
				$target->update_meta_data( $meta_key, $value );
			}
		}
	}

	private static function commercial_meta_keys_to_copy() {
		$keys = array(
			self::META_SOURCE,
			self::META_OFFER_ID,
			self::META_GROUP_ID,
			self::META_VENDOR_CODE,
			self::META_CATEGORY_ID,
			self::META_SOURCE_URL,
			self::META_RAW_HASH,
			self::META_CATALOG_DATE,
			'_wti_params',
			'_wti_picture_urls',
		);

		if ( class_exists( 'WTI_Image_Sync' ) ) {
			$keys[] = WTI_Image_Sync::META_IMAGE_SET_HASH;
		}

		if ( class_exists( 'WTI_Layout_PDF' ) ) {
			$keys[] = WTI_Layout_PDF::META_URL;
			$keys[] = WTI_Layout_PDF::META_LABEL;
			$keys[] = WTI_Layout_PDF::META_FILENAME;
			$keys[] = WTI_Layout_PDF::META_CHECKED_AT;
		}

		return $keys;
	}

	private static function get_polylang_translation_ids( $post_id, $post_type ) {
		$ids = array();

		if ( class_exists( 'PLLWC_Data_Store' ) ) {
			$data_store = PLLWC_Data_Store::load( 'product_language' );

			if ( $data_store && method_exists( $data_store, 'get_translations' ) ) {
				$translations = $data_store->get_translations( $post_id );

				if ( is_array( $translations ) ) {
					foreach ( $translations as $translation_id ) {
						$translation_id = absint( $translation_id );

						if ( $translation_id && $translation_id !== (int) $post_id && get_post_type( $translation_id ) === $post_type ) {
							$ids[] = $translation_id;
						}
					}
				}
			}
		}

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );

			if ( is_array( $translations ) ) {
				foreach ( $translations as $translation_id ) {
					$translation_id = absint( $translation_id );

					if ( $translation_id && $translation_id !== (int) $post_id && get_post_type( $translation_id ) === $post_type ) {
						$ids[] = $translation_id;
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function find_polylang_variation_targets( $source_variation ) {
		$targets = self::get_polylang_translation_ids( (int) $source_variation->get_id(), 'product_variation' );

		if ( $targets ) {
			return $targets;
		}

		$parent_id = (int) $source_variation->get_parent_id();

		foreach ( self::get_polylang_translation_ids( $parent_id, 'product' ) as $translated_parent_id ) {
			$targets = array_merge( $targets, self::find_matching_translated_variations( $source_variation, $translated_parent_id ) );
		}

		return array_values( array_unique( array_map( 'absint', $targets ) ) );
	}

	private static function find_matching_translated_variations( $source_variation, $translated_parent_id ) {
		$matches = array();
		$sku     = (string) $source_variation->get_sku();

		$candidate_ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private' ),
				'post_parent'    => absint( $translated_parent_id ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		foreach ( $candidate_ids as $candidate_id ) {
			$candidate = wc_get_product( $candidate_id );

			if ( ! is_a( $candidate, 'WC_Product_Variation' ) ) {
				continue;
			}

			if ( '' !== $sku && $sku === (string) $candidate->get_sku() ) {
				$matches[] = (int) $candidate->get_id();
				continue;
			}

			if ( self::variation_attributes_match( $source_variation->get_attributes(), $candidate->get_attributes() ) ) {
				$matches[] = (int) $candidate->get_id();
			}
		}

		return $matches;
	}

	private static function variation_attributes_match( $source_attributes, $target_attributes ) {
		$normalize = static function ( $attributes ) {
			$normalized = array();

			foreach ( (array) $attributes as $key => $value ) {
				$key   = strtolower( str_replace( 'attribute_', '', (string) $key ) );
				$value = strtolower( trim( (string) $value ) );

				if ( '' !== $value ) {
					$normalized[ $key ] = $value;
				}
			}

			ksort( $normalized );

			return $normalized;
		};

		return $normalize( $source_attributes ) === $normalize( $target_attributes );
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
			'price'        => self::apply_markup( $variation['price'], $args ),
			'stock'        => $variation['quantity_in_stock'],
			'stock_status' => $variation['stock_status'],
			'pictures'     => isset( $variation['pictures'] ) ? $variation['pictures'] : array(),
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

	private static function apply_markup( $price, $args ) {
		$price   = (float) $price;
		$percent = isset( $args['markup_percent'] ) ? max( 0, (float) $args['markup_percent'] ) : 0;

		if ( $price <= 0 || $percent <= 0 ) {
			return $price;
		}

		return round( $price * ( 1 + ( $percent / 100 ) ), wc_get_price_decimals() );
	}

	private static function is_offer_unchanged( $product_id, $offer, $args ) {
		if ( ! $product_id || empty( $offer['raw_hash'] ) ) {
			return false;
		}

		$current_hash = (string) get_post_meta( $product_id, self::META_RAW_HASH, true );

		if ( $current_hash !== (string) $offer['raw_hash'] ) {
			return false;
		}

		$expected_price = self::apply_markup( isset( $offer['price'] ) ? $offer['price'] : 0, $args );
		$product        = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;

		if ( $product && ! $product->is_type( 'variable' ) && (float) wc_format_decimal( $product->get_regular_price() ) !== (float) wc_format_decimal( $expected_price ) ) {
			return false;
		}

		if ( $product && ! $product->is_type( 'variable' ) ) {
			$expected_stock_quantity = max( 0, isset( $offer['quantity_in_stock'] ) ? (int) $offer['quantity_in_stock'] : 0 );
			$expected_stock_status   = ! empty( $offer['stock_status'] ) ? (string) $offer['stock_status'] : 'outofstock';
			$current_stock_quantity  = null === $product->get_stock_quantity() ? 0 : (int) $product->get_stock_quantity();

			if ( ! $product->get_manage_stock() || $current_stock_quantity !== $expected_stock_quantity || $product->get_stock_status() !== $expected_stock_status ) {
				return false;
			}

			if ( is_a( $product, 'WC_Product_Variation' ) && ! self::variation_attributes_match_offer( $product, $offer ) ) {
				return false;
			}
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

		$special_category_id = self::resolve_special_woo_category_id( $offer );

		if ( $special_category_id ) {
			return array( $special_category_id );
		}

		if ( '' === $category_id || empty( $map[ $category_id ] ) ) {
			return array();
		}

		return array( absint( $map[ $category_id ] ) );
	}

	private static function resolve_special_woo_category_id( $offer ) {
		$category_id = isset( $offer['category_id'] ) ? (string) $offer['category_id'] : '';
		$name        = isset( $offer['name'] ) ? mb_strtolower( (string) $offer['name'] ) : '';

		if ( '185' === $category_id ) {
			if ( false !== mb_strpos( $name, 'термос' ) && false === mb_strpos( $name, 'термокруж' ) ) {
				return self::resolve_product_category_term_id_by_slug( 'druk-na-termosah' );
			}

			return self::resolve_product_category_term_id_by_slug( 'druk-na-termokruzhkah' );
		}

		return 0;
	}

	private static function resolve_product_category_term_id_by_slug( $slug ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'slug'       => sanitize_title( $slug ),
				'lang'       => '',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		foreach ( $terms as $term ) {
			if ( function_exists( 'pll_get_term_language' ) && 'uk' !== pll_get_term_language( $term->term_id ) ) {
				continue;
			}

			return absint( $term->term_id );
		}

		return absint( $terms[0]->term_id );
	}

	private static function variable_product_attributes_match( $product_id, $variations ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return false;
		}

		$expected = self::collect_variable_attributes( $variations );
		$attrs    = $product->get_attributes();

		foreach ( array( 'size', 'color' ) as $key ) {
			if ( empty( $expected[ $key ] ) ) {
				continue;
			}

			$attribute_key = self::variation_attribute_taxonomy( $key );

			if ( empty( $attrs[ $attribute_key ] ) || ! $attrs[ $attribute_key ] instanceof WC_Product_Attribute || ! $attrs[ $attribute_key ]->get_variation() ) {
				return false;
			}

			$current = self::normalize_attribute_values( self::attribute_option_names( $attrs[ $attribute_key ] ) );
			$wanted  = self::normalize_attribute_values( $expected[ $key ] );

			sort( $current );
			sort( $wanted );

			if ( $current !== $wanted ) {
				return false;
			}
		}

		return true;
	}

	private static function variation_attributes_match_offer( $variation, $offer ) {
		$attributes = $variation->get_attributes();

		foreach ( array( 'size', 'color' ) as $key ) {
			$expected = isset( $offer[ $key ] ) ? trim( (string) $offer[ $key ] ) : '';

			if ( '' === $expected ) {
				continue;
			}

			$attribute_key = self::variation_attribute_taxonomy( $key );
			$current       = isset( $attributes[ $attribute_key ] ) ? trim( (string) $attributes[ $attribute_key ] ) : '';
			$expected_slug = self::attribute_value_slug( $attribute_key, $expected );

			if ( $current !== $expected && $current !== $expected_slug ) {
				return false;
			}
		}

		return true;
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

		foreach ( array( 'size' => __( 'Розмір', WTI_TEXT_DOMAIN ), 'color' => __( 'Колір', WTI_TEXT_DOMAIN ) ) as $key => $label ) {
			if ( empty( $attributes[ $key ] ) ) {
				continue;
			}

			$taxonomy = self::variation_attribute_taxonomy( $key );
			self::ensure_product_attribute_taxonomy( $taxonomy, $label );
			$term_ids = self::ensure_attribute_terms( $taxonomy, array_values( array_unique( $attributes[ $key ] ) ) );

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( $term_ids );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$product_attributes[ $taxonomy ] = $attribute;
		}

		return $product_attributes;
	}

	private static function build_variation_attributes( $action ) {
		$attributes = array();

		foreach ( array( 'size', 'color' ) as $key ) {
			$value = isset( $action[ $key ] ) ? trim( (string) $action[ $key ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			$taxonomy = self::variation_attribute_taxonomy( $key );
			self::ensure_product_attribute_taxonomy( $taxonomy, 'size' === $key ? __( 'Розмір', WTI_TEXT_DOMAIN ) : __( 'Колір', WTI_TEXT_DOMAIN ) );
			self::ensure_attribute_terms( $taxonomy, array( $value ) );
			$attributes[ $taxonomy ] = self::attribute_value_slug( $taxonomy, $value );
		}

		return $attributes;
	}

	private static function variation_attribute_taxonomy( $key ) {
		return 'size' === $key ? self::ATTR_SIZE : self::ATTR_COLOR;
	}

	private static function ensure_product_attribute_taxonomy( $taxonomy, $label ) {
		if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) || wc_attribute_taxonomy_id_by_name( $taxonomy ) ) {
			return;
		}

		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return;
		}

		$name = wc_attribute_taxonomy_slug( $taxonomy );
		wc_create_attribute(
			array(
				'name'         => $label,
				'slug'         => $name,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);
		delete_transient( 'wc_attribute_taxonomies' );
	}

	private static function ensure_attribute_terms( $taxonomy, $values ) {
		$term_ids = array();

		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false ) );
		}

		foreach ( $values as $value ) {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$term = get_term_by( 'name', $value, $taxonomy );

			if ( ! $term ) {
				$inserted = wp_insert_term( $value, $taxonomy );

				if ( is_wp_error( $inserted ) ) {
					$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
				} else {
					$term = get_term( (int) $inserted['term_id'], $taxonomy );
				}
			}

			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( array_filter( $term_ids ) ) );
	}

	private static function sync_product_taxonomy_attribute_terms( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_attributes' ) ) {
			return;
		}

		$product_id = (int) $product->get_id();

		if ( ! $product_id ) {
			return;
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) || ! $attribute->is_taxonomy() ) {
				continue;
			}

			$taxonomy = $attribute->get_name();

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array_values( array_filter( array_map( 'absint', (array) $attribute->get_options() ) ) );

			if ( $term_ids ) {
				wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );
			}
		}
	}

	private static function attribute_value_slug( $taxonomy, $value ) {
		$term = get_term_by( 'name', trim( (string) $value ), $taxonomy );

		if ( $term && ! is_wp_error( $term ) ) {
			return (string) $term->slug;
		}

		return sanitize_title( $value );
	}

	private static function attribute_option_names( $attribute ) {
		$names    = array();
		$taxonomy = $attribute->get_name();

		foreach ( (array) $attribute->get_options() as $option ) {
			if ( is_numeric( $option ) && taxonomy_exists( $taxonomy ) ) {
				$term = get_term( (int) $option, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = (string) $term->name;
					continue;
				}
			}

			$names[] = (string) $option;
		}

		return $names;
	}

	private static function normalize_attribute_values( $values ) {
		return array_values(
			array_filter(
				array_map(
					static function ( $value ) {
						return mb_strtolower( trim( (string) $value ) );
					},
					(array) $values
				),
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);
	}

	private static function build_display_attributes( $params, $start_position = 0 ) {
		$product_attributes = array();
		$position           = $start_position;
		$skip               = array( 'розмір', 'розмiр', 'размер', 'size', 'колір', 'колiр', 'цвет', 'color', 'група кольорів', 'вид' );

		foreach ( (array) $params as $name => $value ) {
			$name  = trim( (string) $name );
			$value = trim( (string) $value );

			if ( 'вид' === mb_strtolower( $name ) ) {
				$gender_attribute = self::build_gender_attribute( $value, $position++ );

				if ( $gender_attribute ) {
					$product_attributes[ self::ATTR_GENDER ] = $gender_attribute;
				}

				continue;
			}

			if ( '' === $name || '' === $value || in_array( mb_strtolower( $name ), $skip, true ) ) {
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

	private static function build_gender_attribute( $value, $position ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		self::ensure_product_attribute_taxonomy( self::ATTR_GENDER, __( 'Вид', WTI_TEXT_DOMAIN ) );
		$term_ids = self::ensure_attribute_terms( self::ATTR_GENDER, array( $value ) );

		if ( empty( $term_ids ) ) {
			return null;
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( self::ATTR_GENDER ) );
		$attribute->set_name( self::ATTR_GENDER );
		$attribute->set_options( $term_ids );
		$attribute->set_position( $position );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		return $attribute;
	}

	private static function merge_product_attributes( $variation_attributes, $display_attributes ) {
		foreach ( $display_attributes as $key => $attribute ) {
			if ( isset( $variation_attributes[ $key ] ) ) {
				continue;
			}

			$variation_attributes[ $key ] = $attribute;
		}

		return $variation_attributes;
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

		if ( self::is_synthetic_group_id( $variable['group_id'] ) ) {
			$product_id = self::find_existing_color_group_parent_id( $variable );

			if ( $product_id ) {
				return $product_id;
			}
		}

		$product_id = self::find_product_id_by_sku( $variable['parent']['sku'] );

		if ( $product_id ) {
			return $product_id;
		}

		return self::find_unclaimed_product_id_by_exact_title( $variable['parent']['name'] );
	}

	private static function is_synthetic_group_id( $group_id ) {
		return 0 === strpos( (string) $group_id, 'wti-color-' );
	}

	private static function find_existing_color_group_parent_id( $variable ) {
		global $wpdb;

		$name = isset( $variable['parent']['name'] ) ? trim( (string) $variable['parent']['name'] ) : '';

		if ( '' === $name ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = %s AND src.meta_value = 'totobi'
				WHERE p.post_type = 'product'
				AND p.post_status IN ('publish','draft','pending','private')
				AND p.post_title = %s
				ORDER BY p.ID ASC",
				self::META_SOURCE,
				$name
			)
		);

		return self::choose_source_language_candidate( $ids );
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
		global $wpdb;

		if ( '' === (string) $sku ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type IN ('product','product_variation')
				AND p.post_status IN ('publish','draft','pending','private')
				AND pm.meta_key = '_sku'
				AND pm.meta_value = %s
				ORDER BY p.ID ASC",
				(string) $sku
			)
		);

		return self::choose_source_language_candidate( $ids );
	}

	private static function find_product_id_by_offer_id( $offer_id ) {
		return self::find_product_id_by_meta( self::META_OFFER_ID, $offer_id, array( 'product', 'product_variation' ) );
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
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);

		return self::choose_source_language_candidate( $query->posts );
	}

	private static function choose_source_language_candidate( $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return (int) $ids[0];
		}

		$source_language = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

		foreach ( $ids as $id ) {
			$language_id = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : (int) $id;
			$language    = $language_id ? (string) pll_get_post_language( $language_id ) : '';

			if ( '' !== $source_language && $language === $source_language ) {
				return (int) $id;
			}
		}

		foreach ( $ids as $id ) {
			$language_id = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : (int) $id;
			$language    = $language_id ? (string) pll_get_post_language( $language_id ) : '';

			if ( '' === $language ) {
				return (int) $id;
			}
		}

		return 0;
	}
}
