<?php

defined( 'ABSPATH' ) || exit;

class WTI_Autopoly_Integration {
	public static function maybe_translate_product( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || ! self::is_available() ) {
			return false;
		}

		$provider = self::get_server_provider();

		if ( ! $provider ) {
			WTI_Logger::log( 'AutoPoly product translation skipped: no server-side provider is configured.' );
			return false;
		}

		$source_lang = self::get_product_language( $product_id );

		if ( '' === $source_lang ) {
			$source_lang = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';

			if ( '' !== $source_lang && function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $product_id, $source_lang );
			}
		}

		if ( '' === $source_lang || ! function_exists( 'pll_languages_list' ) ) {
			return false;
		}

		$created = 0;

		foreach ( pll_languages_list() as $target_lang ) {
			$target_lang = (string) $target_lang;

			if ( $target_lang === $source_lang || self::has_translation( $product_id, $target_lang ) ) {
				continue;
			}

			$translated = self::translate_product_strings( $product_id, $source_lang, $target_lang, $provider );

			if ( is_wp_error( $translated ) ) {
				WTI_Logger::log( 'AutoPoly product translation failed. ' . $translated->get_error_message() );
				continue;
			}

			$new_id = self::create_autopoly_translation( $product_id, $source_lang, $target_lang, $translated );

			if ( $new_id ) {
				$created++;
				WTI_Logger::log( sprintf( 'AutoPoly product translation created. Source #%d, target %s #%d.', $product_id, $target_lang, $new_id ) );
			}
		}

		return $created;
	}

	public static function get_server_provider() {
		if ( ! class_exists( 'ATFPP_Helper' ) ) {
			return '';
		}

		$active = ATFPP_Helper::get_active_providers();
		$keys   = ATFPP_Helper::get_providers_key( array( 'openai', 'google', 'deepl' ), false );

		if ( in_array( 'openai', $active, true ) && ! empty( $keys['openai'] ) ) {
			return 'openai';
		}

		if ( in_array( 'gemini', $active, true ) && ! empty( $keys['google'] ) ) {
			return 'google';
		}

		if ( in_array( 'deepl', $active, true ) && ! empty( $keys['deepl'] ) ) {
			return 'deepl';
		}

		return '';
	}

	private static function is_available() {
		return class_exists( 'ATFP_Posts_Clone' )
			&& class_exists( 'ATFP_Register_Route' )
			&& function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_get_post_translations' );
	}

	private static function get_product_language( $product_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return (string) pll_get_post_language( $product_id );
		}

		return '';
	}

	private static function has_translation( $product_id, $target_lang ) {
		$translations = pll_get_post_translations( $product_id );

		return is_array( $translations ) && ! empty( $translations[ $target_lang ] );
	}

	private static function translate_product_strings( $product_id, $source_lang, $target_lang, $provider ) {
		$post = get_post( $product_id );

		if ( ! $post ) {
			return new WP_Error( 'wti_autopoly_missing_post', 'Source product post was not found.' );
		}

		$strings = array(
			'title'   => html_entity_decode( get_the_title( $product_id ), ENT_QUOTES, 'UTF-8' ),
			'excerpt' => (string) $post->post_excerpt,
			'content' => (string) $post->post_content,
		);

		$prompt = sprintf(
			'Translate this WooCommerce product from %s to %s. Return only valid JSON with keys title, excerpt, content. Preserve HTML tags, shortcode syntax, URLs, numbers, SKUs, product codes and brand names. JSON: %s',
			$source_lang,
			$target_lang,
			wp_json_encode( $strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		try {
			$route  = new ATFP_Register_Route( 'wti-autopoly-bridge' );
			$method = new ReflectionMethod( $route, 'translate_text' );
			$method->setAccessible( true );
			$model  = self::get_selected_model( $provider );
			$result = $method->invoke( $route, $prompt, $provider, $model, 120 );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'wti_autopoly_translate_failed', $exception->getMessage() );
		}

		$result = preg_replace( '/(^```json\s*|```$)/', '', trim( (string) $result ) );
		$data   = json_decode( $result, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wti_autopoly_bad_response', 'AutoPoly provider returned a non-JSON translation response.' );
		}

		return array(
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $strings['title'],
			'post_excerpt' => isset( $data['excerpt'] ) ? wp_kses_post( $data['excerpt'] ) : $strings['excerpt'],
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : $strings['content'],
			'post_meta_fields' => array(),
		);
	}

	private static function get_selected_model( $provider ) {
		if ( 'openai' === $provider ) {
			return get_option( 'atfpp_selected_openai_model', 'gpt-4o-mini' );
		}

		if ( 'google' === $provider ) {
			return get_option( 'atfpp_selected_google_model', 'gemini-2.5-flash' );
		}

		return '';
	}

	private static function create_autopoly_translation( $product_id, $source_lang, $target_lang, $post_data ) {
		global $polylang;

		if ( ! $polylang ) {
			return 0;
		}

		$clone = new ATFP_Posts_Clone( $polylang );
		$new_id = $clone->copy_post( $product_id, $source_lang, $target_lang, false, $post_data );

		if ( $new_id && class_exists( 'WTI_Product_Sync' ) ) {
			$product = wc_get_product( $product_id );

			if ( $product ) {
				$method = new ReflectionMethod( 'WTI_Product_Sync', 'sync_polylang_product_fields' );
				$method->setAccessible( true );
				$method->invoke( null, $product );
			}
		}

		return absint( $new_id );
	}
}
