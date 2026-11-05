<?php
/**
 * Live Google reviews via Places API (New) – Place Details.
 *
 * Google Maps Platform policies do not allow Places content (reviews, rating) to be stored
 * permanently. This class keeps the response only in a short-lived transient that is
 * refreshed automatically, and never writes it into the reviews database.
 * The permanent archive of Google reviews comes from the owner's own Google Takeout export.
 */

defined( 'ABSPATH' ) || exit;

class LBR_Google {

	const CRON_HOOK  = 'lbr_google_refresh';
	const CACHE_KEY  = 'lbr_google_live';
	const STATUS_OPT = 'lbr_google_status';
	const LOCK_KEY   = 'lbr_google_lock';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_refresh' ) );
		add_action( 'update_option_' . LBR_Settings::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'add_option_' . LBR_Settings::OPTION, array( __CLASS__, 'schedule' ) );
	}

	public static function is_configured() {
		$s = LBR_Settings::get();
		return $s['google_enabled'] && $s['google_api_key'] && $s['google_place_id'];
	}

	public static function schedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( self::is_configured() ) {
			if ( ! $next ) {
				wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	public static function cron_refresh() {
		if ( self::is_configured() && false === get_transient( self::CACHE_KEY ) ) {
			self::refresh();
		}
	}

	/**
	 * Cached payload, fetching it if missing (with a lock so only one request hits the API).
	 */
	protected static function payload() {
		if ( ! self::is_configured() ) {
			return null;
		}
		$data = get_transient( self::CACHE_KEY );
		if ( is_array( $data ) ) {
			return $data;
		}
		if ( get_transient( self::LOCK_KEY ) ) {
			return null;
		}
		$result = self::refresh();
		return is_wp_error( $result ) ? null : $result;
	}

	public static function live_reviews() {
		$p = self::payload();
		return ( $p && ! empty( $p['reviews'] ) ) ? $p['reviews'] : array();
	}

	public static function live_stats() {
		$p = self::payload();
		if ( ! $p || empty( $p['count'] ) ) {
			return null;
		}
		return array(
			'rating'   => (float) $p['rating'],
			'count'    => (int) $p['count'],
			'maps_uri' => isset( $p['maps_uri'] ) ? $p['maps_uri'] : '',
		);
	}

	public static function maps_uri() {
		$p = self::payload();
		return ( $p && ! empty( $p['maps_uri'] ) ) ? $p['maps_uri'] : '';
	}

	/**
	 * Call the API and cache the normalised result.
	 *
	 * @return array|WP_Error
	 */
	public static function refresh() {
		$s = LBR_Settings::get();
		if ( ! $s['google_api_key'] || ! $s['google_place_id'] ) {
			return new WP_Error( 'lbr_google_config', __( 'API key or Place ID missing.', 'lb-reviews' ) );
		}

		set_transient( self::LOCK_KEY, 1, MINUTE_IN_SECONDS );

		$url = 'https://places.googleapis.com/v1/places/' . rawurlencode( $s['google_place_id'] );
		if ( $s['google_language'] ) {
			$url = add_query_arg( 'languageCode', $s['google_language'], $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'X-Goog-Api-Key'   => $s['google_api_key'],
					'X-Goog-FieldMask' => 'rating,userRatingCount,googleMapsUri,reviews',
					'Referer'          => home_url( '/' ),
				),
			)
		);

		delete_transient( self::LOCK_KEY );

		if ( is_wp_error( $response ) ) {
			return self::fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = ( is_array( $body ) && isset( $body['error']['message'] ) ) ? $body['error']['message'] : sprintf( 'HTTP %d', $code );
			return self::fail( $msg );
		}

		$reviews = array();
		if ( ! empty( $body['reviews'] ) && is_array( $body['reviews'] ) ) {
			foreach ( $body['reviews'] as $r ) {
				$reviews[] = self::normalize( $r, (bool) $s['google_show_original'] );
			}
		}

		$data = array(
			'rating'   => isset( $body['rating'] ) ? (float) $body['rating'] : 0,
			'count'    => isset( $body['userRatingCount'] ) ? (int) $body['userRatingCount'] : 0,
			'maps_uri' => isset( $body['googleMapsUri'] ) ? esc_url_raw( $body['googleMapsUri'] ) : '',
			'reviews'  => $reviews,
			'fetched'  => time(),
		);

		set_transient( self::CACHE_KEY, $data, (int) $s['google_cache_hours'] * HOUR_IN_SECONDS );
		update_option(
			self::STATUS_OPT,
			array(
				'ok'      => true,
				'time'    => time(),
				'message' => sprintf(
					/* translators: 1: reviews received, 2: rating, 3: total reviews */
					__( '%1$d reviews received · rating %2$s · %3$d reviews in total', 'lb-reviews' ),
					count( $reviews ),
					number_format_i18n( $data['rating'], 1 ),
					$data['count']
				),
			),
			false
		);
		LBR_Repository::bump_cache();

		return $data;
	}

	protected static function fail( $message ) {
		// Negative cache: avoid hammering the API on every page view after an error.
		set_transient( self::CACHE_KEY, array( 'reviews' => array(), 'rating' => 0, 'count' => 0, 'maps_uri' => '', 'fetched' => time(), 'error' => true ), 30 * MINUTE_IN_SECONDS );
		update_option(
			self::STATUS_OPT,
			array(
				'ok'      => false,
				'time'    => time(),
				'message' => sanitize_text_field( $message ),
			),
			false
		);
		return new WP_Error( 'lbr_google_api', $message );
	}

	protected static function normalize( array $r, $prefer_original ) {
		$translated_text = isset( $r['text']['text'] ) ? $r['text']['text'] : '';
		$original_text   = isset( $r['originalText']['text'] ) ? $r['originalText']['text'] : '';
		$text_lang       = isset( $r['text']['languageCode'] ) ? $r['text']['languageCode'] : '';
		$orig_lang       = isset( $r['originalText']['languageCode'] ) ? $r['originalText']['languageCode'] : $text_lang;

		if ( $prefer_original && $original_text ) {
			$text       = $original_text;
			$lang       = $orig_lang;
			$translated = false;
		} else {
			$text       = $translated_text ? $translated_text : $original_text;
			$lang       = $translated_text ? $text_lang : $orig_lang;
			$translated = $translated_text && $orig_lang && $text_lang && substr( $orig_lang, 0, 2 ) !== substr( $text_lang, 0, 2 );
		}

		$author = isset( $r['authorAttribution'] ) ? $r['authorAttribution'] : array();

		return array(
			'id'         => 'g' . md5( isset( $r['name'] ) ? $r['name'] : wp_json_encode( $r ) ),
			'post_id'    => 0,
			'lists'      => array(),
			'source'     => 'google',
			'author'     => isset( $author['displayName'] ) ? sanitize_text_field( $author['displayName'] ) : __( 'Google user', 'lb-reviews' ),
			'author_url' => isset( $author['uri'] ) ? esc_url_raw( $author['uri'] ) : '',
			'avatar'     => isset( $author['photoUri'] ) ? esc_url_raw( $author['photoUri'] ) : '',
			'rating'     => isset( $r['rating'] ) ? (int) $r['rating'] : 5,
			'title'      => '',
			'text'       => sanitize_textarea_field( $text ),
			'time'       => isset( $r['publishTime'] ) ? (int) strtotime( $r['publishTime'] ) : 0,
			'relative'   => isset( $r['relativePublishTimeDescription'] ) ? sanitize_text_field( $r['relativePublishTimeDescription'] ) : '',
			'url'        => isset( $r['googleMapsUri'] ) ? esc_url_raw( $r['googleMapsUri'] ) : '',
			'lang'       => LBR_Repository::sanitize_lang( $lang ),
			'reply'      => '',
			'pinned'     => false,
			'origin'     => 'api',
			'translated' => (bool) $translated,
		);
	}
}
