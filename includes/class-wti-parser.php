<?php

defined( 'ABSPATH' ) || exit;

class WTI_Parser {
	const DEFAULT_ALLOWED_PATHS = array(
		'/ruchki/',
		'/podorozh-ta-vdpochinok/lhtariki/',
		'/podorozh-ta-vdpochinok/plyashki-dlya-pittya/',
		'/podorozh-ta-vdpochinok/termosi-ta-termokruzhki/',
		'/odyag/reglani/',
		'/odyag/zhiletki/',
		'/odyag/polo/',
		'/golovn-ubori/kepki-ta-panami/',
		'/sumki/ryukzaki/',
		'/ofs-uk/bloknoti/',
		'/elektronka/godinniki/',
		'/elektronka/zaryadn-pristro/',
		'/upakovka-uk/podarunkova-upakovka/',
	);

	public static function parse_catalog_meta( $xml_string ) {
		$reader = self::open_reader( $xml_string );

		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT === $reader->nodeType && 'yml_catalog' === $reader->name ) {
				$date = (string) $reader->getAttribute( 'date' );
				$reader->close();

				return array(
					'date' => $date,
				);
			}
		}

		$reader->close();

		return new WP_Error( 'wti_missing_catalog', 'Cannot find yml_catalog root element.' );
	}

	public static function parse_categories( $xml_string ) {
		$reader = self::open_reader( $xml_string );

		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$categories = array();

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'category' !== $reader->name ) {
				continue;
			}

			$id = (string) $reader->getAttribute( 'id' );

			if ( '' === $id ) {
				continue;
			}

			$categories[ $id ] = array(
				'id'        => $id,
				'parent_id' => (string) $reader->getAttribute( 'parentId' ),
				'name'      => trim( $reader->readString() ),
			);
		}

		$reader->close();

		return $categories;
	}

	public static function parse_offers( $xml_string, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'allowed_paths'        => self::DEFAULT_ALLOWED_PATHS,
				'allowed_category_ids' => array(),
				'limit'                => 0,
			)
		);

		$reader = self::open_reader( $xml_string );

		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$offers = array();

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'offer' !== $reader->name ) {
				continue;
			}

			$offer = self::parse_offer_node( $reader );

			if ( ! self::offer_allowed( $offer, $args['allowed_paths'], $args['allowed_category_ids'] ) ) {
				continue;
			}

			$offers[] = $offer;

			if ( $args['limit'] && count( $offers ) >= absint( $args['limit'] ) ) {
				break;
			}
		}

		$reader->close();

		return $offers;
	}

	public static function build_import_plan( $offers ) {
		$offers     = self::fill_missing_variation_prices( $offers );
		$offers     = self::inherit_group_row_variation_data( $offers );
		$validation = self::validate_offers( $offers );
		$offers     = self::assign_color_group_ids( $validation['valid'] );
		$groups     = array();
		$simples    = array();

		foreach ( $offers as $offer ) {
			if ( ! empty( $offer['group_id'] ) ) {
				$groups[ $offer['group_id'] ][] = $offer;
				continue;
			}

			$simples[] = $offer;
		}

		$variables      = array();
		$skipped_groups = array();

		foreach ( $groups as $group_id => $group_offers ) {
			$parent     = self::pick_parent_offer( $group_offers );
			$variations = self::sort_variations( $group_offers );

			if ( empty( $variations ) ) {
				$skipped_groups[] = array(
					'group_id' => $group_id,
					'name'     => isset( $parent['name'] ) ? $parent['name'] : '',
					'reason'   => 'no_valid_variations',
				);
				continue;
			}

			$variables[] = array(
				'group_id'   => $group_id,
				'parent'     => self::prepare_parent_offer( $parent, $group_id ),
				'variations' => $variations,
			);
		}

		$simples = self::remove_simple_products_covered_by_variable_models( $simples, $variables );

		return array(
			'simple'         => $simples,
			'variable'       => $variables,
			'skipped_groups' => $skipped_groups,
			'validation'     => $validation,
		);
	}

	private static function remove_simple_products_covered_by_variable_models( $simples, $variables ) {
		$variable_keys = array();

		foreach ( $variables as $variable ) {
			if ( empty( $variable['parent'] ) || empty( $variable['variations'] ) ) {
				continue;
			}

			$key = self::color_group_key( $variable['parent'] );

			if ( '' !== $key ) {
				$variable_keys[ $key ] = true;
			}
		}

		if ( empty( $variable_keys ) ) {
			return $simples;
		}

		return array_values(
			array_filter(
				$simples,
				static function ( $simple ) use ( $variable_keys ) {
					$key = self::color_group_key( $simple );

					return '' === $key || empty( $variable_keys[ $key ] );
				}
			)
		);
	}

	private static function fill_missing_variation_prices( $offers ) {
		$group_prices = array();

		foreach ( $offers as $offer ) {
			if ( empty( $offer['group_id'] ) || empty( $offer['price'] ) || $offer['price'] <= 0 ) {
				continue;
			}

			$group_id = (string) $offer['group_id'];

			if ( empty( $group_prices[ $group_id ] ) || ! empty( $offer['is_group_row'] ) ) {
				$group_prices[ $group_id ] = (float) $offer['price'];
			}
		}

		foreach ( $offers as &$offer ) {
			if ( empty( $offer['group_id'] ) || ! empty( $offer['is_group_row'] ) || ( isset( $offer['price'] ) && $offer['price'] > 0 ) ) {
				continue;
			}

			$group_id = (string) $offer['group_id'];

			if ( ! empty( $group_prices[ $group_id ] ) ) {
				$offer['price'] = (float) $group_prices[ $group_id ];
			}
		}
		unset( $offer );

		return $offers;
	}

	private static function inherit_group_row_variation_data( $offers ) {
		$group_rows = array();

		foreach ( $offers as $offer ) {
			if ( empty( $offer['is_group_row'] ) || empty( $offer['group_id'] ) ) {
				continue;
			}

			$group_rows[ (string) $offer['group_id'] ] = $offer;
		}

		if ( empty( $group_rows ) ) {
			return $offers;
		}

		foreach ( $offers as &$offer ) {
			if ( ! empty( $offer['is_group_row'] ) || empty( $offer['group_id'] ) ) {
				continue;
			}

			$group_id = (string) $offer['group_id'];

			if ( empty( $group_rows[ $group_id ] ) ) {
				continue;
			}

			$group_row = $group_rows[ $group_id ];

			if ( empty( $offer['color'] ) && ! empty( $group_row['color'] ) ) {
				$offer['color'] = $group_row['color'];
			}

			if ( empty( $offer['pictures'] ) && ! empty( $group_row['pictures'] ) ) {
				$offer['pictures'] = $group_row['pictures'];
			}

			if ( empty( $offer['params']['Колір'] ) && ! empty( $group_row['color'] ) ) {
				$offer['params']['Колір'] = $group_row['color'];
			}

			if ( empty( $offer['description'] ) && ! empty( $group_row['description'] ) ) {
				$offer['description'] = $group_row['description'];
			}

			$offer['raw_hash'] = self::build_offer_hash( $offer );
		}
		unset( $offer );

		return $offers;
	}

	public static function summarize_plan( $plan ) {
		$variation_count = 0;

		foreach ( $plan['variable'] as $variable ) {
			$variation_count += count( $variable['variations'] );
		}

		return array(
			'simple_products'   => count( $plan['simple'] ),
			'variable_products' => count( $plan['variable'] ),
			'variations'        => $variation_count,
			'total_products'    => count( $plan['simple'] ) + count( $plan['variable'] ),
			'invalid_offers'    => isset( $plan['validation']['invalid_count'] ) ? $plan['validation']['invalid_count'] : 0,
			'skipped_groups'    => isset( $plan['skipped_groups'] ) ? count( $plan['skipped_groups'] ) : 0,
		);
	}

	public static function validate_offers( $offers ) {
		$valid   = array();
		$invalid = array();
		$reasons = array();

		foreach ( $offers as $offer ) {
			$offer_reasons = self::validate_offer( $offer );

			if ( empty( $offer_reasons ) ) {
				$valid[] = $offer;
				continue;
			}

			foreach ( $offer_reasons as $reason ) {
				$reasons[ $reason ] = isset( $reasons[ $reason ] ) ? $reasons[ $reason ] + 1 : 1;
			}

			$invalid[] = array(
				'id'        => isset( $offer['id'] ) ? $offer['id'] : '',
				'group_id'  => isset( $offer['group_id'] ) ? $offer['group_id'] : '',
				'name'      => isset( $offer['name'] ) ? $offer['name'] : '',
				'url'       => isset( $offer['url'] ) ? $offer['url'] : '',
				'reasons'   => $offer_reasons,
			);
		}

		return array(
			'valid'         => $valid,
			'invalid'       => $invalid,
			'invalid_count' => count( $invalid ),
			'reasons'       => $reasons,
		);
	}

	public static function validate_offer( $offer ) {
		$reasons = array();

		foreach ( array( 'id', 'url', 'name', 'category_id' ) as $field ) {
			if ( empty( $offer[ $field ] ) ) {
				$reasons[] = 'missing_' . $field;
			}
		}

		if ( empty( $offer['is_group_row'] ) ) {
			if ( empty( $offer['sku'] ) ) {
				$reasons[] = 'missing_sku';
			}

			if ( ! isset( $offer['price'] ) || $offer['price'] <= 0 ) {
				$reasons[] = 'missing_or_zero_price';
			}
		}

		if ( ! empty( $offer['group_id'] ) && empty( $offer['is_group_row'] ) && empty( $offer['size'] ) ) {
			$reasons[] = 'missing_variation_size';
		}

		if ( empty( $offer['pictures'] ) ) {
			$reasons[] = 'missing_picture';
		}

		return array_values( array_unique( $reasons ) );
	}

	private static function parse_offer_node( XMLReader $reader ) {
		$xml = $reader->readOuterXML();

		if ( '' === $xml ) {
			return array();
		}

		$element = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );

		if ( false === $element ) {
			return array();
		}

		$attributes = $element->attributes();
		$params     = array();
		$pictures   = array();

		foreach ( $element->picture as $picture ) {
			$url = trim( (string) $picture );

			if ( self::is_supported_image_url( $url ) ) {
				$pictures[] = $url;
			}
		}

		foreach ( $element->param as $param ) {
			$name = trim( (string) $param['name'] );

			if ( '' === $name ) {
				continue;
			}

			$params[ $name ] = trim( (string) $param );
		}

		$offer = array(
			'id'                => (string) $attributes['id'],
			'group_id'          => (string) $attributes['group_id'],
			'available'         => self::to_bool( (string) $attributes['available'] ),
			'url'               => self::text( $element, 'url' ),
			'quantity_in_stock' => self::to_int( self::text( $element, 'quantity_in_stock' ) ),
			'price'             => self::to_float( self::text( $element, 'price' ) ),
			'oldprice'          => self::to_float( self::text( $element, 'oldprice' ) ),
			'currency'          => self::text( $element, 'currencyId' ),
			'category_id'       => self::text( $element, 'categoryId' ),
			'pictures'          => array_values( array_unique( $pictures ) ),
			'store'             => self::to_bool( self::text( $element, 'store' ) ),
			'pickup'            => self::to_bool( self::text( $element, 'pickup' ) ),
			'delivery'          => self::to_bool( self::text( $element, 'delivery' ) ),
			'name'              => self::text( $element, 'name' ),
			'vendor_code'       => self::text( $element, 'vendorCode' ),
			'description'       => self::text( $element, 'description' ),
			'weight'            => self::to_float( self::text( $element, 'weight' ) ),
			'params'            => $params,
			'size'              => isset( $params['Розмір'] ) ? $params['Розмір'] : '',
			'color'             => self::resolve_color( $params, self::text( $element, 'vendorCode' ), (string) $attributes['id'] ),
		);

		$offer['is_group_row'] = '' !== $offer['group_id'] && $offer['id'] === $offer['group_id'];
		$offer['sku']          = self::resolve_sku( $offer );
		$offer['stock_status'] = $offer['available'] && $offer['quantity_in_stock'] > 0 ? 'instock' : 'outofstock';
		$offer['raw_hash']     = self::build_offer_hash( $offer );

		return $offer;
	}

	private static function build_offer_hash( $offer ) {
		$params = isset( $offer['params'] ) && is_array( $offer['params'] ) ? $offer['params'] : array();
		ksort( $params, SORT_NATURAL );

		$data = array(
			'id'                => isset( $offer['id'] ) ? (string) $offer['id'] : '',
			'group_id'          => isset( $offer['group_id'] ) ? (string) $offer['group_id'] : '',
			'available'         => ! empty( $offer['available'] ) ? 1 : 0,
			'url'               => isset( $offer['url'] ) ? (string) $offer['url'] : '',
			'quantity_in_stock' => isset( $offer['quantity_in_stock'] ) ? (int) $offer['quantity_in_stock'] : 0,
			'price'             => isset( $offer['price'] ) ? wc_format_decimal( (float) $offer['price'] ) : '0',
			'oldprice'          => isset( $offer['oldprice'] ) ? wc_format_decimal( (float) $offer['oldprice'] ) : '0',
			'currency'          => isset( $offer['currency'] ) ? (string) $offer['currency'] : '',
			'category_id'       => isset( $offer['category_id'] ) ? (string) $offer['category_id'] : '',
			'name'              => isset( $offer['name'] ) ? (string) $offer['name'] : '',
			'vendor_code'       => isset( $offer['vendor_code'] ) ? (string) $offer['vendor_code'] : '',
			'description'       => isset( $offer['description'] ) ? (string) $offer['description'] : '',
			'weight'            => isset( $offer['weight'] ) ? wc_format_decimal( (float) $offer['weight'] ) : '0',
			'params'            => $params,
			'size'              => isset( $offer['size'] ) ? (string) $offer['size'] : '',
			'color'             => isset( $offer['color'] ) ? (string) $offer['color'] : '',
			'sku'               => isset( $offer['sku'] ) ? (string) $offer['sku'] : '',
			'stock_status'      => isset( $offer['stock_status'] ) ? (string) $offer['stock_status'] : '',
		);

		return md5( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function offer_allowed( $offer, $allowed_paths, $allowed_category_ids ) {
		if ( empty( $offer ) || empty( $offer['id'] ) ) {
			return false;
		}

		$allowed_category_ids = array_filter( array_map( 'strval', (array) $allowed_category_ids ) );

		if ( $allowed_category_ids && in_array( (string) $offer['category_id'], $allowed_category_ids, true ) ) {
			return true;
		}

		$allowed_paths = array_filter( array_map( 'strval', (array) $allowed_paths ) );

		if ( ! $allowed_paths ) {
			return true;
		}

		foreach ( $allowed_paths as $path ) {
			if ( '' !== $path && false !== strpos( $offer['url'], $path ) ) {
				return true;
			}
		}

		return false;
	}

	private static function assign_color_group_ids( $offers ) {
		$buckets = array();

		foreach ( $offers as $index => $offer ) {
			if ( empty( $offer['color'] ) || empty( $offer['name'] ) ) {
				continue;
			}

			if ( ! empty( $offer['group_id'] ) && ! self::should_group_by_model_color( $offer ) ) {
				continue;
			}

			$key = self::color_group_key( $offer );
			if ( '' === $key ) {
				continue;
			}

			$buckets[ $key ][] = $index;
		}

		foreach ( $buckets as $key => $indexes ) {
			if ( count( $indexes ) < 2 ) {
				continue;
			}

			$colors = array();
			foreach ( $indexes as $index ) {
				$colors[] = mb_strtolower( trim( (string) $offers[ $index ]['color'] ) );
			}

			if ( count( array_unique( array_filter( $colors ) ) ) < 2 ) {
				continue;
			}

			$group_id = 'wti-color-' . md5( $key );
			foreach ( $indexes as $index ) {
				$offers[ $index ]['group_id'] = $group_id;
			}
		}

		return $offers;
	}

	private static function should_group_by_model_color( $offer ) {
		$category_id = isset( $offer['category_id'] ) ? (string) $offer['category_id'] : '';

		return in_array(
			$category_id,
			array( '184', '185', '187', '188', '205', '215', '226', '238', '246', '251', '304' ),
			true
		);
	}

	private static function color_group_key( $offer ) {
		$name        = isset( $offer['name'] ) ? trim( (string) $offer['name'] ) : '';
		$category_id = isset( $offer['category_id'] ) ? trim( (string) $offer['category_id'] ) : '';

		if ( '' === $name || '' === $category_id ) {
			return '';
		}

		return $category_id . '|' . mb_strtolower( preg_replace( '/\s+/u', ' ', $name ) );
	}

	private static function resolve_color( $params, $vendor_code = '', $offer_id = '' ) {
		foreach ( array( 'Колір', 'Колiр', 'Цвет' ) as $key ) {
			if ( isset( $params[ $key ] ) && '' !== trim( (string) $params[ $key ] ) ) {
				return trim( (string) $params[ $key ] );
			}
		}

		if ( isset( $params['Група Кольорів'] ) && '' !== trim( (string) $params['Група Кольорів'] ) ) {
			$suffix_source = '' !== (string) $vendor_code ? (string) $vendor_code : (string) $offer_id;
			$suffix        = '';

			if ( preg_match( '/-([^-]+)$/', $suffix_source, $matches ) ) {
				$suffix = $matches[1];
			}

			return trim( (string) $params['Група Кольорів'] . ( '' !== $suffix ? ' ' . $suffix : '' ) );
		}

		return '';
	}

	private static function pick_parent_offer( $offers ) {
		foreach ( $offers as $offer ) {
			if ( ! empty( $offer['is_group_row'] ) ) {
				return $offer;
			}
		}

		usort(
			$offers,
			function ( $left, $right ) {
				$left_stock  = 'instock' === $left['stock_status'] ? 0 : 1;
				$right_stock = 'instock' === $right['stock_status'] ? 0 : 1;

				if ( $left_stock !== $right_stock ) {
					return $left_stock <=> $right_stock;
				}

				return strcmp( (string) $left['id'], (string) $right['id'] );
			}
		);

		return reset( $offers );
	}

	private static function prepare_parent_offer( $parent, $group_id ) {
		if ( 0 !== strpos( (string) $group_id, 'wti-color-' ) ) {
			return $parent;
		}

		$parent['id']          = (string) $group_id;
		$parent['sku']         = '';
		$parent['vendor_code'] = '';
		$parent['raw_hash']    = self::build_offer_hash( $parent );

		return $parent;
	}

	private static function sort_variations( $offers ) {
		$offers = array_values(
			array_filter(
				$offers,
				function ( $offer ) {
					return empty( $offer['is_group_row'] );
				}
			)
		);

		usort(
			$offers,
			function ( $left, $right ) {
				$left_size_order  = self::size_order( $left['size'] );
				$right_size_order = self::size_order( $right['size'] );

				if ( $left_size_order !== $right_size_order ) {
					return $left_size_order <=> $right_size_order;
				}

				return strnatcasecmp( (string) $left['sku'], (string) $right['sku'] );
			}
		);

		return $offers;
	}

	private static function size_order( $size ) {
		$order = array(
			'XXS'  => 10,
			'XS'   => 20,
			'S'    => 30,
			'M'    => 40,
			'L'    => 50,
			'XL'   => 60,
			'2XL'  => 70,
			'XXL'  => 70,
			'3XL'  => 80,
			'XXXL' => 80,
			'4XL'  => 90,
			'5XL'  => 100,
		);

		$size = strtoupper( trim( (string) $size ) );

		return isset( $order[ $size ] ) ? $order[ $size ] : 1000;
	}

	private static function resolve_sku( $offer ) {
		if ( ! empty( $offer['group_id'] ) && empty( $offer['is_group_row'] ) && ! empty( $offer['id'] ) ) {
			return (string) $offer['id'];
		}

		return ! empty( $offer['vendor_code'] ) ? (string) $offer['vendor_code'] : (string) $offer['id'];
	}

	private static function text( SimpleXMLElement $element, $name ) {
		return isset( $element->{$name} ) ? trim( (string) $element->{$name} ) : '';
	}

	private static function is_supported_image_url( $url ) {
		$parts = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$path      = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true );
	}

	private static function to_bool( $value ) {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'y' ), true );
	}

	private static function to_int( $value ) {
		return (int) preg_replace( '/[^\d-]/', '', (string) $value );
	}

	private static function to_float( $value ) {
		$value = str_replace( ',', '.', (string) $value );

		return (float) preg_replace( '/[^\d.-]/', '', $value );
	}

	public static function open_reader( $xml_string ) {
		if ( ! class_exists( 'XMLReader' ) ) {
			return new WP_Error( 'wti_xmlreader_missing', 'PHP XMLReader extension is required.' );
		}

		$reader = new XMLReader();
		$ok     = $reader->XML( (string) $xml_string, null, LIBXML_NONET | LIBXML_COMPACT );

		if ( ! $ok ) {
			return new WP_Error( 'wti_xml_open_failed', 'Cannot open Totobi XML feed.' );
		}

		return $reader;
	}
}
