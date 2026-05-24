<?php

defined( 'ABSPATH' ) || exit;

class WTI_Parser {
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

