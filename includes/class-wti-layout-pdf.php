<?php
defined( 'ABSPATH' ) || exit;

class WTI_Layout_PDF {
	const META_URL        = '_wti_layout_pdf_url';
	const META_LABEL      = '_wti_layout_pdf_label';
	const META_FILENAME   = '_wti_layout_pdf_filename';
	const META_CHECKED_AT = '_wti_layout_pdf_checked_at';
	const CACHE_TTL       = 604800;

	private static $runtime_cache = array();

	public static function add_product_tab( $tabs ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $tabs;
		}

		global $product;

		if ( ! $product instanceof WC_Product ) {
			return $tabs;
		}

		$layout = self::get_for_product( $product );

		if ( empty( $layout['url'] ) ) {
			return $tabs;
		}

		$tabs['wti_layout_pdf'] = array(
			'title'    => __( 'Макет', WTI_TEXT_DOMAIN ),
			'priority' => 65,
			'callback' => array( __CLASS__, 'render_product_tab' ),
		);

		return $tabs;
	}

	public static function render_product_tab() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$layout = self::get_for_product( $product );

		if ( empty( $layout['url'] ) ) {
			return;
		}

		$label = ! empty( $layout['label'] ) ? $layout['label'] : $layout['filename'];

		echo '<div class="wti-layout-pdf-tab">';
		echo '<p><span class="wti-layout-pdf-file">' . esc_html( $label ) . '</span> ';
		echo '<a class="button wti-layout-pdf-link" href="' . esc_url( $layout['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Завантажити', WTI_TEXT_DOMAIN ) . '</a></p>';
		echo '</div>';
	}

	public static function get_for_product( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( absint( $product ) ) : $product;

		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$product_id = $product->get_id();

		if ( isset( self::$runtime_cache[ $product_id ] ) ) {
			return self::$runtime_cache[ $product_id ];
		}

		$cached = self::get_cached_layout( $product );
		if ( null !== $cached ) {
			self::$runtime_cache[ $product_id ] = $cached;
			return $cached;
		}

		$layout = array();
		foreach ( self::get_source_urls( $product ) as $source_url ) {
			$layout = self::find_on_totobi_page( $source_url );

			if ( ! empty( $layout['url'] ) ) {
				break;
			}
		}

		self::store_layout( $product, $layout );
		self::$runtime_cache[ $product_id ] = $layout;

		return $layout;
	}

	private static function get_cached_layout( WC_Product $product ) {
		$checked_at = (int) $product->get_meta( self::META_CHECKED_AT, true );
		$url        = (string) $product->get_meta( self::META_URL, true );

		if ( $checked_at && ( time() - $checked_at ) < self::CACHE_TTL ) {
			if ( '' === $url ) {
				return array();
			}

			return array(
				'url'      => $url,
				'label'    => (string) $product->get_meta( self::META_LABEL, true ),
				'filename' => (string) $product->get_meta( self::META_FILENAME, true ),
			);
		}

		return null;
	}

	private static function store_layout( WC_Product $product, array $layout ) {
		if ( empty( $layout['url'] ) ) {
			$product->delete_meta_data( self::META_URL );
			$product->delete_meta_data( self::META_LABEL );
			$product->delete_meta_data( self::META_FILENAME );
			$product->update_meta_data( self::META_CHECKED_AT, time() );
			$product->save();
			return;
		}

		$product->update_meta_data( self::META_URL, esc_url_raw( $layout['url'] ) );
		$product->update_meta_data( self::META_LABEL, sanitize_text_field( $layout['label'] ) );
		$product->update_meta_data( self::META_FILENAME, sanitize_file_name( $layout['filename'] ) );
		$product->update_meta_data( self::META_CHECKED_AT, time() );
		$product->save();
	}

	private static function get_source_urls( WC_Product $product ) {
		$urls = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child_url = (string) get_post_meta( $child_id, '_wti_source_url', true );

				if ( $child_url ) {
					$urls[] = $child_url;
				}
			}
		}

		$source_url = (string) $product->get_meta( '_wti_source_url', true );
		if ( $source_url ) {
			$urls[] = $source_url;
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		$slug = (string) get_post_field( 'post_name', $product->get_id() );

		usort(
			$urls,
			static function ( $left, $right ) use ( $slug ) {
				return self::source_url_score( $right, $slug ) <=> self::source_url_score( $left, $slug );
			}
		);

		return array_slice( $urls, 0, 8 );
	}

	private static function source_url_score( $url, $product_slug ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$slug = trim( basename( untrailingslashit( $path ) ) );

		if ( '' === $slug || '' === $product_slug ) {
			return 0;
		}

		if ( $slug === $product_slug ) {
			return 100;
		}

		if ( false !== strpos( $slug, $product_slug ) || false !== strpos( $product_slug, $slug ) ) {
			return 80;
		}

		$product_parts = array_filter( explode( '-', $product_slug ) );
		$url_parts     = array_filter( explode( '-', $slug ) );

		return count( array_intersect( $product_parts, $url_parts ) );
	}

	private static function find_on_totobi_page( $source_url ) {
		if ( ! self::is_totobi_url( $source_url ) ) {
			return array();
		}

		$response = wp_remote_get(
			$source_url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'Woo Totobi Integration/' . WTI_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$html = (string) wp_remote_retrieve_body( $response );

		if ( '' === $html || false === stripos( $html, 'content_attachments' ) ) {
			return array();
		}

		if ( ! preg_match( '#<div[^>]+id=["\']content_attachments["\'][\s\S]*?</div>\s*</div>#i', $html, $matches ) ) {
			return array();
		}

		$block = html_entity_decode( $matches[0], ENT_QUOTES, 'UTF-8' );

		if ( ! preg_match( '#<a[^>]+href=["\']([^"\']*dispatch=attachments\.getfile[^"\']*)["\'][^>]*>([\s\S]*?)</a>#i', $block, $link_matches ) ) {
			return array();
		}

		$url      = self::absolute_url( $link_matches[1], $source_url );
		$text     = trim( wp_strip_all_tags( $block ) );
		$filename = self::extract_filename( $text, $url );

		return array(
			'url'      => $url,
			'label'    => self::clean_label( $text ),
			'filename' => $filename,
		);
	}

	private static function clean_label( $text ) {
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		$text = preg_replace( '/\s*\[\s*Завантажити\s*\]\s*$/iu', '', $text );

		return trim( $text );
	}

	private static function extract_filename( $text, $url ) {
		if ( preg_match( '/([A-Za-z0-9._-]+\.pdf)/i', $text, $matches ) ) {
			return $matches[1];
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return $path ? wp_basename( $path ) : 'totobi-layout.pdf';
	}

	private static function absolute_url( $href, $base_url ) {
		$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );

		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}

		$base = wp_parse_url( $base_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return $href;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			return $base['scheme'] . ':' . $href;
		}

		if ( 0 === strpos( $href, '/' ) ) {
			return $base['scheme'] . '://' . $base['host'] . $href;
		}

		$path = empty( $base['path'] ) ? '/' : $base['path'];
		$dir  = trailingslashit( dirname( $path ) );

		return $base['scheme'] . '://' . $base['host'] . $dir . $href;
	}

	private static function is_totobi_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && false !== stripos( $host, 'totobi.com.ua' );
	}
}
