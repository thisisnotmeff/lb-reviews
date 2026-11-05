<?php
/**
 * Data access: stored reviews, upsert with de-duplication, merged collections and stats.
 *
 * Normalised review array:
 *  id, source, author, author_url, avatar, rating, title, text, time (UTC timestamp),
 *  relative, url, lang, reply, pinned, origin (api|import|manual), translated
 */

defined( 'ABSPATH' ) || exit;

class LBR_Repository {

	const CACHE_VER_OPTION = 'lbr_cache_ver';

	/** Reason the last upsert() returned "skipped" (for import diagnostics). */
	public static $last_error = '';

	public static function init() {
		add_action( 'save_post_' . LBR_Post_Type::CPT, array( __CLASS__, 'bump_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'maybe_bump_on_delete' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'maybe_bump_on_delete' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'maybe_bump_on_delete' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_bump_on_status' ), 10, 3 );
	}

	/* ---------- Cache ---------- */

	public static function bump_cache() {
		update_option( self::CACHE_VER_OPTION, (int) get_option( self::CACHE_VER_OPTION, 1 ) + 1, false );
	}

	public static function maybe_bump_on_delete( $post_id, $post = null ) {
		$type = $post ? $post->post_type : get_post_type( $post_id );
		if ( LBR_Post_Type::CPT === $type ) {
			self::bump_cache();
		}
	}

	public static function maybe_bump_on_status( $new, $old, $post ) {
		if ( $new !== $old && LBR_Post_Type::CPT === $post->post_type ) {
			self::bump_cache();
		}
	}

	/* ---------- Helpers ---------- */

	public static function sanitize_lang( $lang ) {
		$lang = strtolower( trim( (string) $lang ) );
		$lang = preg_replace( '/[^a-z\-_]/', '', $lang );
		$lang = str_replace( '_', '-', $lang );
		return substr( $lang, 0, 10 );
	}

	public static function normalize_name( $name ) {
		$name = remove_accents( wp_strip_all_tags( (string) $name ) );
		$name = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $name ) );
		return $name;
	}

	public static function get_meta( $post_id ) {
		return array(
			'source' => get_post_meta( $post_id, '_lbr_source', true ) ?: 'other',
			'rating' => (int) get_post_meta( $post_id, '_lbr_rating', true ) ?: 5,
			'title'  => (string) get_post_meta( $post_id, '_lbr_title', true ),
			'url'    => (string) get_post_meta( $post_id, '_lbr_url', true ),
			'avatar' => (string) get_post_meta( $post_id, '_lbr_avatar', true ),
			'author_url' => (string) get_post_meta( $post_id, '_lbr_author_url', true ),
			'lang'   => (string) get_post_meta( $post_id, '_lbr_lang', true ),
			'reply'  => (string) get_post_meta( $post_id, '_lbr_reply', true ),
			'pinned' => (bool) get_post_meta( $post_id, '_lbr_pinned', true ),
			'origin' => (string) get_post_meta( $post_id, '_lbr_origin', true ),
		);
	}

	/* ---------- Stored reviews ---------- */

	/**
	 * All published stored reviews (normalised), cached until something changes.
	 */
	public static function stored() {
		$key    = 'lbr_stored_' . (int) get_option( self::CACHE_VER_OPTION, 1 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = get_posts(
			array(
				'post_type'        => LBR_Post_Type::CPT,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		// Prime the term cache so lists don't cost one query per review.
		if ( $ids ) {
			update_object_term_cache( $ids, LBR_Post_Type::CPT );
		}

		$out = array();
		foreach ( $ids as $id ) {
			$m     = self::get_meta( $id );
			$terms = get_the_terms( $id, LBR_Post_Type::TAX );
			$out[] = array(
				'id'         => 'p' . $id,
				'post_id'    => (int) $id,
				'lists'      => ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'slug' ) : array(),
				'source'     => $m['source'],
				'author'     => get_the_title( $id ),
				'author_url' => $m['author_url'],
				'avatar'     => $m['avatar'],
				'rating'     => $m['rating'],
				'title'      => $m['title'],
				'text'       => wp_strip_all_tags( get_post_field( 'post_content', $id ) ),
				'time'       => (int) get_post_time( 'U', true, $id ),
				'relative'   => '',
				'url'        => $m['url'],
				'lang'       => $m['lang'],
				'reply'      => $m['reply'],
				'pinned'     => $m['pinned'],
				'origin'     => $m['origin'] ?: 'manual',
				'translated' => false,
			);
		}

		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * Find an existing stored review: by external id first, then by author + date (±2 days) + source.
	 */
	public static function find_existing( $source, $ext_id, $author, $time ) {
		if ( $ext_id ) {
			$found = get_posts(
				array(
					'post_type'      => LBR_Post_Type::CPT,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lbr_ext_id',
					'meta_value'     => $ext_id,
					'no_found_rows'  => true,
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}

		if ( '' === trim( (string) $author ) ) {
			return 0;
		}

		$candidates = get_posts(
			array(
				'post_type'      => LBR_Post_Type::CPT,
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'title'          => $author,
				'meta_key'       => '_lbr_source',
				'meta_value'     => $source,
				'no_found_rows'  => true,
			)
		);
		foreach ( $candidates as $cid ) {
			if ( ! $time || abs( (int) get_post_time( 'U', true, $cid ) - (int) $time ) <= 2 * DAY_IN_SECONDS ) {
				return (int) $cid;
			}
		}
		return 0;
	}

	/**
	 * Insert or update a stored review.
	 *
	 * @param array $r keys: source, ext_id, author, rating, text, time, title, url, avatar, lang, reply, origin.
	 * @return string created|updated|skipped
	 */
	public static function upsert( array $r ) {
		$r = wp_parse_args(
			$r,
			array(
				'source' => 'other',
				'ext_id' => '',
				'author' => '',
				'rating' => 5,
				'text'   => '',
				'time'   => 0,
				'title'  => '',
				'url'    => '',
				'avatar' => '',
				'author_url' => '',
				'lang'   => '',
				'reply'  => '',
				'origin' => 'import',
			)
		);

		self::$last_error = '';

		$author = trim( wp_strip_all_tags( $r['author'] ) );
		$text   = trim( sanitize_textarea_field( $r['text'] ) );
		$rating = max( 1, min( 5, (int) round( (float) $r['rating'] ) ) );

		if ( '' === $author && '' === $text ) {
			self::$last_error = __( 'entry without author and text, skipped.', 'lb-reviews' );
			return 'skipped';
		}
		if ( '' === $author ) {
			$author = __( 'Anonymous', 'lb-reviews' );
		}

		$time     = $r['time'] ? (int) $r['time'] : time();
		$gmt      = gmdate( 'Y-m-d H:i:s', $time );
		$existing = self::find_existing( $r['source'], $r['ext_id'], $author, $time );

		$postarr = array(
			'post_type'     => LBR_Post_Type::CPT,
			'post_title'    => $author,
			'post_content'  => $text,
			'post_date'     => get_date_from_gmt( $gmt ),
			'post_date_gmt' => $gmt,
		);

		if ( $existing ) {
			$postarr['ID'] = $existing; // Status and "pinned" are left untouched.
			$result        = wp_update_post( wp_slash( $postarr ), true );
			$status        = 'updated';
		} else {
			$postarr['post_status'] = 'publish';
			$result                 = wp_insert_post( wp_slash( $postarr ), true );
			$status                 = 'created';
		}

		if ( is_wp_error( $result ) || ! $result ) {
			global $wpdb;
			$msg              = is_wp_error( $result ) ? $result->get_error_message() : __( 'database error', 'lb-reviews' );
			self::$last_error = sprintf( '%s – %s %s', $author, $msg, $wpdb->last_error ? '(' . $wpdb->last_error . ')' : '' );
			return 'skipped';
		}
		$post_id = (int) $result;

		update_post_meta( $post_id, '_lbr_source', sanitize_key( $r['source'] ) );
		update_post_meta( $post_id, '_lbr_rating', $rating );
		update_post_meta( $post_id, '_lbr_title', sanitize_text_field( $r['title'] ) );
		update_post_meta( $post_id, '_lbr_lang', self::sanitize_lang( $r['lang'] ) );
		if ( $r['url'] ) {
			update_post_meta( $post_id, '_lbr_url', esc_url_raw( $r['url'] ) );
		}
		if ( $r['avatar'] ) {
			update_post_meta( $post_id, '_lbr_avatar', esc_url_raw( $r['avatar'] ) );
		}
		if ( $r['author_url'] ) {
			update_post_meta( $post_id, '_lbr_author_url', esc_url_raw( $r['author_url'] ) );
		}
		if ( $r['reply'] ) {
			update_post_meta( $post_id, '_lbr_reply', sanitize_textarea_field( $r['reply'] ) );
		}
		if ( ! get_post_meta( $post_id, '_lbr_origin', true ) ) {
			update_post_meta( $post_id, '_lbr_origin', sanitize_key( $r['origin'] ) );
		}
		// Several external ids may point to the same review (e.g. Takeout id + CSV id).
		if ( $r['ext_id'] ) {
			$ids = get_post_meta( $post_id, '_lbr_ext_id' );
			if ( ! in_array( $r['ext_id'], $ids, true ) ) {
				add_post_meta( $post_id, '_lbr_ext_id', $r['ext_id'] );
			}
		}

		return $status;
	}

	/* ---------- Merged collection ---------- */

	/**
	 * Stored reviews + live Google reviews (deduplicated: stored version wins).
	 */
	public static function all() {
		$stored = self::stored();
		$live   = LBR_Google::live_reviews();

		if ( $live ) {
			$index = array();
			foreach ( $stored as $s ) {
				if ( 'google' === $s['source'] ) {
					$index[ self::normalize_name( $s['author'] ) ][] = $s['time'];
				}
			}
			foreach ( $live as $l ) {
				$key = self::normalize_name( $l['author'] );
				$dup = false;
				if ( isset( $index[ $key ] ) ) {
					foreach ( $index[ $key ] as $t ) {
						if ( abs( $t - $l['time'] ) <= 2 * DAY_IN_SECONDS ) {
							$dup = true;
							break;
						}
					}
				}
				if ( ! $dup ) {
					$stored[] = $l;
				}
			}
		}

		return $stored;
	}

	/**
	 * Filter, sort and limit.
	 *
	 * @param array $args sources(array), min_rating, lang(array), order(date|rating|random), limit, pinned_first.
	 */
	public static function query( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'sources'    => array( 'google', 'trustpilot' ),
				'min_rating' => 1,
				'lang'       => array(),
				'order'      => 'date',
				'limit'      => 12,
				'with_text'  => false,
				'lists'      => array(),
				'ids'        => array(),
			)
		);

		// A custom selection (list or IDs) only uses stored reviews: live Google reviews cannot be assigned.
		$selection = ( $args['lists'] || $args['ids'] );
		$pool      = $selection ? self::stored() : self::all();

		$items = array_filter(
			$pool,
			function ( $r ) use ( $args ) {
				$pid = isset( $r['post_id'] ) ? (int) $r['post_id'] : 0;
				if ( $args['ids'] ) {
					// Hand-picked reviews: shown whatever their source.
					if ( ! in_array( $pid, $args['ids'], true ) ) {
						return false;
					}
				} elseif ( ! in_array( $r['source'], $args['sources'], true ) ) {
					return false;
				}
				if ( $args['lists'] && ! array_intersect( $args['lists'], isset( $r['lists'] ) ? $r['lists'] : array() ) ) {
					return false;
				}
				if ( (int) $r['rating'] < (int) $args['min_rating'] ) {
					return false;
				}
				if ( $args['lang'] && $r['lang'] && ! in_array( substr( $r['lang'], 0, 2 ), $args['lang'], true ) ) {
					return false;
				}
				if ( $args['with_text'] && '' === trim( $r['text'] ) ) {
					return false;
				}
				return true;
			}
		);
		$items = array_values( $items );

		if ( $args['ids'] && 'manual' === $args['order'] ) {
			$pos = array_flip( $args['ids'] );
			usort(
				$items,
				function ( $a, $b ) use ( $pos ) {
					return $pos[ $a['post_id'] ] <=> $pos[ $b['post_id'] ];
				}
			);
		} elseif ( 'random' === $args['order'] ) {
			shuffle( $items );
		} else {
			$by_rating = ( 'rating' === $args['order'] );
			usort(
				$items,
				function ( $a, $b ) use ( $by_rating ) {
					if ( $a['pinned'] !== $b['pinned'] ) {
						return $a['pinned'] ? -1 : 1;
					}
					if ( $by_rating && $a['rating'] !== $b['rating'] ) {
						return $b['rating'] <=> $a['rating'];
					}
					return $b['time'] <=> $a['time'];
				}
			);
		}

		// Pinned reviews also go first when shuffled.
		if ( 'random' === $args['order'] ) {
			usort(
				$items,
				function ( $a, $b ) {
					return (int) $b['pinned'] <=> (int) $a['pinned'];
				}
			);
		}

		$limit = (int) $args['limit'];
		return $limit > 0 ? array_slice( $items, 0, $limit ) : $items;
	}

	/* ---------- Stats ---------- */

	/**
	 * Average rating + total count per source.
	 * Google: manual override > Places API aggregate > computed from stored.
	 * Trustpilot: manual override > computed from stored.
	 */
	public static function stats( $source ) {
		$s        = LBR_Settings::get();
		$rating   = '';
		$count    = '';
		$computed = self::computed_stats( $source );

		// Totals read from an imported export (e.g. TrustScore + total in the Trustpilot JSON).
		$platform = (array) get_option( 'lbr_platform_stats', array() );
		$file     = isset( $platform[ $source ] ) ? $platform[ $source ] : null;
		$fallback = $file ? array( 'rating' => $file['rating'], 'count' => $file['count'] ) : $computed;

		if ( 'google' === $source ) {
			$api    = LBR_Google::live_stats();
			$auto   = $api ? $api : $fallback;
			$rating = '' !== $s['google_rating'] ? (float) $s['google_rating'] : $auto['rating'];
			$count  = '' !== $s['google_count'] ? (int) $s['google_count'] : $auto['count'];
		} elseif ( 'trustpilot' === $source ) {
			$rating = '' !== $s['trustpilot_rating'] ? (float) $s['trustpilot_rating'] : $fallback['rating'];
			$count  = '' !== $s['trustpilot_count'] ? (int) $s['trustpilot_count'] : $fallback['count'];
		} else {
			$rating = $computed['rating'];
			$count  = $computed['count'];
		}

		return array(
			'rating' => round( (float) $rating, 1 ),
			'count'  => (int) $count,
		);
	}

	public static function computed_stats( $source ) {
		$sum = 0;
		$n   = 0;
		foreach ( self::all() as $r ) {
			if ( $r['source'] === $source ) {
				$sum += (int) $r['rating'];
				$n++;
			}
		}
		return array(
			'rating' => $n ? $sum / $n : 0,
			'count'  => $n,
		);
	}
}
