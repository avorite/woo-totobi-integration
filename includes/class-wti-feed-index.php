<?php

defined( 'ABSPATH' ) || exit;

class WTI_Feed_Index {
	const OPTION_KEY = 'wti_feed_index';

	public static function filter_changed_plan( $plan, $import_images = true, $mark_missing_outofstock = false, $category_ids = array(), $args = array() ) {
		$previous      = get_option( self::OPTION_KEY, array() );
		$previous_rows = isset( $previous['offers'] ) && is_array( $previous['offers'] ) ? $previous['offers'] : array();
		$current_rows  = self::build_plan_rows( $plan );
		$markup_changed = self::markup_changed_from_previous( $previous, $args );
		$filtered      = $plan;
		$filtered['simple']   = array();
		$filtered['variable'] = array();
		$unchanged     = 0;

		foreach ( $plan['simple'] as $offer ) {
			if ( ! $markup_changed && self::row_is_unchanged( $offer, $previous_rows, $import_images, $args ) ) {
				$unchanged++;
				continue;
			}

			$filtered['simple'][] = $offer;
		}

		foreach ( $plan['variable'] as $variable ) {
			$parent             = $variable['parent'];
			$parent_unchanged   = ! $markup_changed && self::row_is_unchanged( $parent, $previous_rows, $import_images, $args ) && self::variable_product_attributes_match( $parent, $variable['variations'], $previous_rows );
			$changed_variations = array();

			foreach ( $variable['variations'] as $variation ) {
				if ( ! $markup_changed && self::row_is_unchanged( $variation, $previous_rows, false, $args ) ) {
					$unchanged++;
					continue;
				}

				$changed_variations[] = $variation;
			}

			if ( $parent_unchanged && empty( $changed_variations ) ) {
				$unchanged++;
				continue;
			}

			$variable['variations'] = $parent_unchanged ? $changed_variations : $variable['variations'];
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

	public static function save_from_store( $catalog_date = '', $args = array() ) {
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

			if ( isset( $rows[ $offer_id ] ) && ! self::is_source_language_post( $product_id ) ) {
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
				'markup_percent' => self::normalize_markup_percent( isset( $args['markup_percent'] ) ? $args['markup_percent'] : 0 ),
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

	public static function markup_changed_from_store( $args = array() ) {
		return self::markup_changed_from_previous( get_option( self::OPTION_KEY, array() ), $args );
	}

	private static function markup_changed_from_previous( $previous, $args ) {
		$previous_markup = isset( $previous['markup_percent'] ) ? self::normalize_markup_percent( $previous['markup_percent'] ) : '0.0000';
		$current_markup  = self::normalize_markup_percent( isset( $args['markup_percent'] ) ? $args['markup_percent'] : 0 );

		return $previous_markup !== $current_markup;
	}

	private static function normalize_markup_percent( $percent ) {
		return number_format( max( 0, (float) $percent ), 4, '.', '' );
	}

	private static function row_is_unchanged( $offer, $previous_rows, $import_images, $args = array() ) {
		$offer_id = isset( $offer['id'] ) ? (string) $offer['id'] : '';

		if ( '' === $offer_id || empty( $previous_rows[ $offer_id ] ) ) {
			return false;
		}

		$previous = $previous_rows[ $offer_id ];

		if ( empty( $previous['product_id'] ) || empty( $previous['raw_hash'] ) || (string) $previous['raw_hash'] !== (string) $offer['raw_hash'] ) {
			return false;
		}

		if ( ! self::is_source_language_post( (int) $previous['product_id'] ) ) {
			return false;
		}

		if ( isset( $offer['price'] ) && ! self::product_price_matches_offer( (int) $previous['product_id'], $offer, $args ) ) {
			return false;
		}

		if ( ! self::product_stock_matches_offer( (int) $previous['product_id'], $offer ) ) {
			return false;
		}

		if ( ! self::variation_attributes_match_offer( (int) $previous['product_id'], $offer ) ) {
			return false;
		}

		if ( ! $import_images ) {
			return true;
		}

		$expected_image_hash = class_exists( 'WTI_Image_Sync' ) ? WTI_Image_Sync::build_image_set_hash_from_urls( isset( $offer['pictures'] ) ? $offer['pictures'] : array() ) : '';

		return '' !== $expected_image_hash && ! empty( $previous['image_hash'] ) && (string) $previous['image_hash'] === $expected_image_hash;
	}

	private static function product_price_matches_offer( $product_id, $offer, $args = array() ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( $product->is_type( 'variable' ) ) {
			return true;
		}

		$expected_price = self::apply_markup( isset( $offer['price'] ) ? $offer['price'] : 0, $args );
		$current_price  = (float) $product->get_regular_price();

		return wc_format_decimal( $current_price ) === wc_format_decimal( $expected_price );
	}

	private static function product_stock_matches_offer( $product_id, $offer ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( $product->is_type( 'variable' ) ) {
			return true;
		}

		$expected_quantity = max( 0, isset( $offer['quantity_in_stock'] ) ? (int) $offer['quantity_in_stock'] : 0 );
		$expected_status   = ! empty( $offer['stock_status'] ) ? (string) $offer['stock_status'] : 'outofstock';
		$current_quantity  = null === $product->get_stock_quantity() ? 0 : (int) $product->get_stock_quantity();

		return $product->get_manage_stock() && $current_quantity === $expected_quantity && $product->get_stock_status() === $expected_status;
	}

	private static function variation_attributes_match_offer( $product_id, $offer ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! is_a( $product, 'WC_Product_Variation' ) ) {
			return true;
		}

		$attributes = $product->get_attributes();

		foreach ( array( 'size', 'color' ) as $key ) {
			$expected = isset( $offer[ $key ] ) ? trim( (string) $offer[ $key ] ) : '';

			if ( '' === $expected ) {
				continue;
			}

			$attribute_key = self::variation_attribute_taxonomy( $key );
			$current       = isset( $attributes[ $attribute_key ] ) ? trim( (string) $attributes[ $attribute_key ] ) : '';

			if ( $current !== $expected && $current !== self::attribute_value_slug( $attribute_key, $expected ) ) {
				return false;
			}
		}

		return true;
	}

	private static function variable_product_attributes_match( $parent, $variations, $previous_rows ) {
		$offer_id = isset( $parent['id'] ) ? (string) $parent['id'] : '';

		if ( '' === $offer_id || empty( $previous_rows[ $offer_id ]['product_id'] ) ) {
			return false;
		}

		$product = wc_get_product( (int) $previous_rows[ $offer_id ]['product_id'] );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return false;
		}

		if ( ! self::is_source_language_post( (int) $product->get_id() ) ) {
			return false;
		}

		$expected = array(
			'size'  => array(),
			'color' => array(),
		);

		foreach ( (array) $variations as $variation ) {
			foreach ( array( 'size', 'color' ) as $key ) {
				if ( ! empty( $variation[ $key ] ) ) {
					$expected[ $key ][] = (string) $variation[ $key ];
				}
			}
		}

		$attrs = $product->get_attributes();

		foreach ( $expected as $key => $values ) {
			$values = array_values( array_unique( array_filter( $values ) ) );

			if ( ! $values ) {
				continue;
			}

			$attribute_key = self::variation_attribute_taxonomy( $key );

			if ( empty( $attrs[ $attribute_key ] ) || ! $attrs[ $attribute_key ] instanceof WC_Product_Attribute || ! $attrs[ $attribute_key ]->get_variation() ) {
				return false;
			}

			$current = self::normalize_attribute_values( self::attribute_option_names( $attrs[ $attribute_key ] ) );
			$values  = self::normalize_attribute_values( $values );
			sort( $current );
			sort( $values );

			if ( $current !== $values ) {
				return false;
			}
		}

		return true;
	}

	private static function variation_attribute_taxonomy( $key ) {
		return 'size' === $key ? 'pa_size' : 'pa_kolir';
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

	private static function is_source_language_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! function_exists( 'pll_get_post_language' ) ) {
			return true;
		}

		$language_id = 'product_variation' === get_post_type( $post_id ) ? (int) wp_get_post_parent_id( $post_id ) : $post_id;
		$language    = $language_id ? (string) pll_get_post_language( $language_id ) : '';

		if ( '' === $language ) {
			return true;
		}

		$source_language = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

		return '' === $source_language || $language === $source_language;
	}

	private static function apply_markup( $price, $args = array() ) {
		$price   = (float) $price;
		$percent = isset( $args['markup_percent'] ) ? max( 0, (float) $args['markup_percent'] ) : 0;

		if ( $price <= 0 || $percent <= 0 ) {
			return $price;
		}

		return round( $price * ( 1 + ( $percent / 100 ) ), wc_get_price_decimals() );
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
