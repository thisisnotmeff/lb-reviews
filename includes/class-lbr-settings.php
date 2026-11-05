<?php
/**
 * Settings storage (single option array).
 */

defined( 'ABSPATH' ) || exit;

class LBR_Settings {

	const OPTION = 'lbr_settings';

	public static function defaults() {
		return array(
			// Google (Places API – live reviews, not stored permanently).
			'google_enabled'       => 0,
			'google_api_key'       => '',
			'google_place_id'      => '',
			'google_language'      => '',   // e.g. "en"; empty = Google default.
			'google_show_original' => 1,    // show reviews in their original language.
			'google_cache_hours'   => 24,
			'google_profile_url'   => '',   // "See all reviews" link (Maps / GBP).
			'google_review_url'    => '',   // "Leave a review" link from the Business Profile (g.page/r/…/review).
			'google_rating'        => '',   // manual override of the average.
			'google_count'         => '',   // manual override of the total.

			// Trustpilot.
			'trustpilot_url'       => '',   // https://www.trustpilot.com/review/your-domain
			'trustpilot_rating'    => '',   // manual override (TrustScore / average).
			'trustpilot_count'     => '',
			'trustbox_code'        => '',   // raw TrustBox snippet (unfiltered_html only).

			// Display.
			'google_logo_url'      => '',   // official logo image (optional).
			'trustpilot_logo_url'  => '',
			'star_color'           => '#c9a45c',
			'card_style'           => 'minimal', // minimal | bordered | soft.

			// Structured data.
			'schema_enabled'       => 0,
			'schema_id'            => '',   // @id of the existing entity (e.g. Rank Math #organization).
			'schema_type'          => 'LocalBusiness',
			'schema_name'          => '',

			// Misc.
			'delete_on_uninstall'  => 0,
		);
	}

	public static function get( $key = null ) {
		$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $opts;
		}
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$old   = self::get();
		$d     = self::defaults();
		$out   = array();

		foreach ( array( 'google_enabled', 'google_show_original', 'schema_enabled', 'delete_on_uninstall' ) as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}

		$out['google_api_key']  = isset( $input['google_api_key'] ) ? sanitize_text_field( $input['google_api_key'] ) : '';
		$out['google_place_id'] = isset( $input['google_place_id'] ) ? sanitize_text_field( $input['google_place_id'] ) : '';
		$out['google_language'] = isset( $input['google_language'] ) ? strtolower( preg_replace( '/[^A-Za-z\-]/', '', $input['google_language'] ) ) : '';

		$hours                     = isset( $input['google_cache_hours'] ) ? absint( $input['google_cache_hours'] ) : $d['google_cache_hours'];
		$out['google_cache_hours'] = max( 1, min( 168, $hours ? $hours : $d['google_cache_hours'] ) );

		foreach ( array( 'google_profile_url', 'google_review_url', 'trustpilot_url', 'google_logo_url', 'trustpilot_logo_url', 'schema_id' ) as $k ) {
			$out[ $k ] = isset( $input[ $k ] ) ? esc_url_raw( trim( $input[ $k ] ) ) : '';
		}

		foreach ( array( 'google_rating', 'trustpilot_rating' ) as $k ) {
			$v         = isset( $input[ $k ] ) ? str_replace( ',', '.', trim( $input[ $k ] ) ) : '';
			$out[ $k ] = ( '' === $v || ! is_numeric( $v ) ) ? '' : (string) max( 0, min( 5, round( (float) $v, 1 ) ) );
		}
		foreach ( array( 'google_count', 'trustpilot_count' ) as $k ) {
			$v         = isset( $input[ $k ] ) ? trim( $input[ $k ] ) : '';
			$out[ $k ] = ( '' === $v ) ? '' : (string) absint( $v );
		}

		$color             = isset( $input['star_color'] ) ? sanitize_hex_color( $input['star_color'] ) : '';
		$out['star_color'] = $color ? $color : $d['star_color'];

		$styles            = array( 'minimal', 'bordered', 'soft' );
		$out['card_style'] = ( isset( $input['card_style'] ) && in_array( $input['card_style'], $styles, true ) ) ? $input['card_style'] : 'minimal';

		$types              = array( 'LocalBusiness', 'ProfessionalService', 'Organization', 'Person' );
		$out['schema_type'] = ( isset( $input['schema_type'] ) && in_array( $input['schema_type'], $types, true ) ) ? $input['schema_type'] : 'LocalBusiness';
		$out['schema_name'] = isset( $input['schema_name'] ) ? sanitize_text_field( $input['schema_name'] ) : '';

		// TrustBox snippet contains <script>: only users allowed to post unfiltered HTML may change it.
		if ( current_user_can( 'unfiltered_html' ) ) {
			$out['trustbox_code'] = isset( $input['trustbox_code'] ) ? trim( $input['trustbox_code'] ) : '';
		} else {
			$out['trustbox_code'] = $old['trustbox_code'];
		}

		// Google credentials changed → drop the live cache.
		if ( $out['google_api_key'] !== $old['google_api_key'] || $out['google_place_id'] !== $old['google_place_id'] || $out['google_language'] !== $old['google_language'] ) {
			LBR_Google::clear_cache();
		}

		return $out;
	}
}
