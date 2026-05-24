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
		$groups  = array();
		$simples = array();

		foreach ( $offers as $offer ) {
			if ( ! empty( $offer['group_id'] ) ) {
				$groups[ $offer['group_id'] ][] = $offer;
				continue;
			}

			$simples[] = $offer;
		}

		$variables = array();

		foreach ( $groups as $group_id => $group_offers ) {
			$parent = self::pick_parent_offer( $group_offers );

			$variables[] = array(
				'group_id'   => $group_id,
				'parent'     => $parent,
				'variations' => self::sort_variations( $group_offers ),
			);
		}

		return array(
			'simple'   => $simples,
			'variable' => $variables,
		);
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
		);
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

			if ( '' !== $url ) {
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
			'color'             => isset( $params['Колір'] ) ? $params['Колір'] : '',
			'raw_hash'          => md5( $xml ),
		);

		$offer['is_group_row'] = '' !== $offer['group_id'] && $offer['id'] === $offer['group_id'];
		$offer['sku']          = self::resolve_sku( $offer );
		$offer['stock_status'] = $offer['available'] && $offer['quantity_in_stock'] > 0 ? 'instock' : 'outofstock';

		return $offer;
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
