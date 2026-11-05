<?php
/**
 * Import reviews from:
 *  - Google Takeout / Business Profile JSON (reviews.json, one or more files)
 *  - CSV (Trustpilot export or any spreadsheet with author / rating / text / date columns)
 */

defined( 'ABSPATH' ) || exit;

class LBR_Importer {

	const MAX_BYTES = 10485760; // 10 MB per file.

	public static function init() {
		add_action( 'admin_post_lbr_import', array( __CLASS__, 'handle_upload' ) );
	}

	protected static function result_url() {
		return admin_url( 'edit.php?post_type=' . LBR_Post_Type::CPT . '&page=lbr-settings&tab=import' );
	}

	protected static function finish( array $totals ) {
		LBR_Repository::bump_cache();
		set_transient( 'lbr_import_result_' . get_current_user_id(), $totals, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::result_url() );
		exit;
	}

	public static function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'lb-reviews' ) );
		}

		$totals = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'files' => array(), 'notes' => array() );

		// Upload bigger than post_max_size: PHP empties $_POST and $_FILES, so the nonce is missing too.
		if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) {
			/* translators: %s: server limit */
			$totals['errors'][] = sprintf( __( 'The upload is larger than the server limit (%s). Upload only the reviews*.json files instead of the whole archive.', 'lb-reviews' ), ini_get( 'post_max_size' ) );
			self::finish( $totals );
		}

		check_admin_referer( 'lbr_import' );

		$source = isset( $_POST['lbr_import_source'] ) ? sanitize_key( $_POST['lbr_import_source'] ) : 'auto';

		if ( empty( $_FILES['lbr_files'] ) || empty( $_FILES['lbr_files']['name'] ) ) {
			$totals['errors'][] = __( 'No file selected.', 'lb-reviews' );
			self::finish( $totals );
		}

		$files = self::normalize_files( $_FILES['lbr_files'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $files as $file ) {
			$name = sanitize_file_name( $file['name'] );
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				/* translators: 1: file name, 2: PHP upload error code */
				$totals['errors'][] = sprintf( __( '%1$s: upload failed (PHP error %2$d, server limit %3$s).', 'lb-reviews' ), $name, (int) $file['error'], ini_get( 'upload_max_filesize' ) );
				continue;
			}

			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( 'zip' === $ext ) {
				self::import_zip( $file['tmp_name'], $name, $source, $totals );
				continue;
			}

			if ( (int) $file['size'] > self::MAX_BYTES ) {
				/* translators: %s: file name */
				$totals['errors'][] = sprintf( __( '%s: file too large (max 10 MB).', 'lb-reviews' ), $name );
				continue;
			}

			$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $content ) {
				continue;
			}
			self::import_content( $content, $name, $ext, $source, $totals );
		}

		self::finish( $totals );
	}

	/**
	 * Takeout ZIP: import every JSON whose path mentions "review".
	 */
	protected static function import_zip( $path, $name, $source, array &$totals ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			/* translators: %s: file name */
			$totals['errors'][] = sprintf( __( '%s: this server cannot open ZIP files. Extract it and upload the reviews*.json files.', 'lb-reviews' ), $name );
			return;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			/* translators: %s: file name */
			$totals['errors'][] = sprintf( __( '%s: cannot open the ZIP file.', 'lb-reviews' ), $name );
			return;
		}
		$found = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( ! preg_match( '/review[^\/]*\.json$/i', $entry ) ) {
				continue;
			}
			$stat = $zip->statIndex( $i );
			if ( ! $stat || $stat['size'] > self::MAX_BYTES ) {
				continue;
			}
			$found++;
			self::import_content( (string) $zip->getFromIndex( $i ), $name . ' › ' . $entry, 'json', $source, $totals );
		}
		$zip->close();
		if ( ! $found ) {
			/* translators: %s: file name */
			$totals['errors'][] = sprintf( __( '%s: no reviews*.json file found inside the ZIP.', 'lb-reviews' ), $name );
		}
	}

	protected static function import_content( $content, $label, $ext, $source, array &$totals ) {
		if ( 'json' === $ext ) {
			$rows = self::parse_google_json( $content, 'auto' === $source ? '' : $source );
			if ( ! is_wp_error( $rows ) && self::$last_summary ) {
				self::save_summary( self::$last_summary, $totals );
			}
		} elseif ( in_array( $ext, array( 'csv', 'tsv', 'txt' ), true ) ) {
			$rows = self::parse_csv( $content, 'auto' === $source ? '' : $source );
		} else {
			/* translators: %s: file name */
			$totals['errors'][] = sprintf( __( '%s: unsupported format (use .json, .csv or the Takeout .zip).', 'lb-reviews' ), $label );
			return;
		}

		if ( is_wp_error( $rows ) ) {
			$totals['errors'][] = $label . ': ' . $rows->get_error_message();
			return;
		}

		$file = array( 'name' => $label, 'found' => count( $rows ), 'created' => 0, 'updated' => 0, 'skipped' => 0 );
		foreach ( $rows as $row ) {
			$status = LBR_Repository::upsert( $row );
			$totals[ $status ]++;
			$file[ $status ]++;
			if ( 'skipped' === $status && LBR_Repository::$last_error && count( $totals['errors'] ) < 8 ) {
				$totals['errors'][] = $label . ': ' . LBR_Repository::$last_error;
			}
		}
		$totals['files'][] = $file;
	}

	protected static function normalize_files( $files ) {
		$out = array();
		if ( is_array( $files['name'] ) ) {
			foreach ( $files['name'] as $i => $n ) {
				$out[] = array(
					'name'     => $n,
					'tmp_name' => $files['tmp_name'][ $i ],
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				);
			}
		} else {
			$out[] = $files;
		}
		return $out;
	}

	/* ---------- Google Takeout JSON ---------- */

	/**
	 * Accepts {"reviews":[...]} or a plain array. Each item uses the Business Profile format:
	 * reviewer.displayName, reviewer.profilePhotoUrl, starRating ("FIVE"), comment, createTime,
	 * updateTime, name, reviewReply.comment.
	 *
	 * @return array|WP_Error
	 */
	/** Platform totals found in the last parsed JSON (TrustScore, total, profile URL). */
	public static $last_summary = array();

	public static function normalize_source( $raw ) {
		$raw = strtolower( (string) $raw );
		if ( false !== strpos( $raw, 'trust' ) ) {
			return 'trustpilot';
		}
		if ( false !== strpos( $raw, 'google' ) ) {
			return 'google';
		}
		return '' === $raw ? '' : 'other';
	}

	/**
	 * Store the platform rating/total from the export, used by the summary when no manual override is set.
	 */
	protected static function save_summary( array $sum, array &$totals ) {
		$stats                   = (array) get_option( 'lbr_platform_stats', array() );
		$stats[ $sum['source'] ] = array(
			'rating' => $sum['rating'],
			'count'  => $sum['count'],
			'time'   => time(),
		);
		update_option( 'lbr_platform_stats', $stats, false );

		if ( 'trustpilot' === $sum['source'] && $sum['url'] && ! LBR_Settings::get( 'trustpilot_url' ) ) {
			$opts                   = (array) get_option( LBR_Settings::OPTION, array() );
			$opts['trustpilot_url'] = esc_url_raw( $sum['url'] );
			update_option( LBR_Settings::OPTION, $opts );
		}

		$totals['notes'][] = sprintf(
			/* translators: 1: platform, 2: rating, 3: total */
			__( '%1$s rating from the file: %2$s / 5 on %3$d reviews (used in the summary unless you set an override).', 'lb-reviews' ),
			'trustpilot' === $sum['source'] ? 'TrustScore' : ucfirst( $sum['source'] ),
			number_format_i18n( $sum['rating'], 1 ),
			$sum['count']
		);
	}

	public static function parse_google_json( $content, $source = 'google' ) {
		self::$last_summary = array();
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $content );
		$data    = json_decode( $content, true );

		// JSON Lines (one object per line).
		if ( ! is_array( $data ) && false !== strpos( trim( $content ), "\n" ) ) {
			$lines = array();
			foreach ( preg_split( '/\r?\n/', trim( $content ) ) as $line ) {
				$obj = json_decode( $line, true );
				if ( is_array( $obj ) ) {
					$lines[] = $obj;
				}
			}
			$data = $lines ? $lines : null;
		}

		if ( ! is_array( $data ) ) {
			/* translators: %s: JSON error */
			return new WP_Error( 'lbr_json', sprintf( __( 'Invalid JSON (%s).', 'lb-reviews' ), json_last_error_msg() ) );
		}

		// Google Maps "Your places" export: reviews WRITTEN by the account, not received by the business.
		if ( isset( $data['type'] ) && 'FeatureCollection' === $data['type'] ) {
			return new WP_Error(
				'lbr_json_maps',
				__( 'This file is the Google Maps export of the reviews this account has WRITTEN about other places, not the reviews your business has received. In Google Takeout select “Google Business Profile” (not “Maps (your places)”) and upload the reviews*.json files from that export.', 'lb-reviews' )
			);
		}

		$list = self::find_review_list( $data );
		if ( ! $list ) {
			$keys = array_slice( array_keys( $data ), 0, 8 );
			/* translators: %s: top-level keys */
			return new WP_Error( 'lbr_json', sprintf( __( 'No reviews found in this JSON file. Top-level keys: %s', 'lb-reviews' ), implode( ', ', array_map( 'strval', $keys ) ) ) );
		}

		// Source: chosen in the form > field on each review > field at the top of the file > Google.
		$file_source = isset( $data['source'] ) && is_scalar( $data['source'] ) ? self::normalize_source( $data['source'] ) : '';

		$rows = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$src = $source;
			if ( ! $src ) {
				$src = isset( $item['source'] ) && is_scalar( $item['source'] ) ? self::normalize_source( $item['source'] ) : '';
			}
			if ( ! $src ) {
				$src = $file_source ? $file_source : 'google';
			}
			$rows[] = self::map_google_item( $item, $src );
		}

		// Platform totals (e.g. "business": {"trustscore": 4.6, "total_reviews": 21, "profile_url": …}).
		$biz = isset( $data['business'] ) && is_array( $data['business'] ) ? $data['business'] : $data;
		$avg = null;
		foreach ( array( 'trustscore', 'trustScore', 'rating', 'average_rating', 'averageRating' ) as $k ) {
			if ( isset( $biz[ $k ] ) && is_numeric( $biz[ $k ] ) ) {
				$avg = (float) $biz[ $k ];
				break;
			}
		}
		$cnt = null;
		foreach ( array( 'total_reviews', 'totalReviews', 'numberOfReviews', 'userRatingCount', 'review_count', 'reviewCount' ) as $k ) {
			if ( isset( $biz[ $k ] ) && is_numeric( $biz[ $k ] ) ) {
				$cnt = (int) $biz[ $k ];
				break;
			}
		}
		$sum_source = $source ? $source : ( $file_source ? $file_source : ( $rows ? $rows[0]['source'] : '' ) );
		if ( null !== $avg && $cnt && $sum_source ) {
			self::$last_summary = array(
				'source' => $sum_source,
				'rating' => max( 0, min( 5, round( $avg, 1 ) ) ),
				'count'  => $cnt,
				'url'    => isset( $biz['profile_url'] ) && is_string( $biz['profile_url'] ) ? $biz['profile_url'] : '',
			);
		}

		// Every entry came out empty → the structure is not the expected one: report its keys.
		$usable = array_filter(
			$rows,
			function ( $r ) {
				return '' !== trim( $r['author'] ) || '' !== trim( $r['text'] );
			}
		);
		if ( ! $usable ) {
			$first = reset( $list );
			/* translators: %s: keys of the first entry */
			return new WP_Error( 'lbr_json', sprintf( __( 'Entries found but in an unknown format. Keys of the first entry: %s', 'lb-reviews' ), is_array( $first ) ? implode( ', ', array_keys( $first ) ) : gettype( $first ) ) );
		}

		return $rows;
	}

	/**
	 * Locate the array of reviews anywhere in the decoded JSON (depth ≤ 4).
	 */
	protected static function find_review_list( $data, $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 4 ) {
			return array();
		}
		if ( isset( $data['reviews'] ) && is_array( $data['reviews'] ) && $data['reviews'] ) {
			return array_values( $data['reviews'] );
		}
		if ( isset( $data[0] ) && is_array( $data[0] ) && self::looks_like_review( $data[0] ) ) {
			return $data;
		}
		// Single review object.
		if ( self::looks_like_review( $data ) ) {
			return array( $data );
		}
		foreach ( $data as $value ) {
			$found = self::find_review_list( $value, $depth + 1 );
			if ( $found ) {
				return $found;
			}
		}
		return array();
	}

	protected static function looks_like_review( array $item ) {
		foreach ( array( 'starRating', 'reviewer', 'comment', 'rating', 'stars', 'authorAttribution', 'review', 'text', 'snippet' ) as $k ) {
			if ( array_key_exists( $k, $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map one review entry. Covers the Business Profile / Takeout format, the Places API format
	 * and common third-party exports (author/rating/text/date).
	 */
	protected static function map_google_item( array $item, $source ) {
		$pick = function ( array $keys ) use ( $item ) {
			foreach ( $keys as $path ) {
				$v = $item;
				foreach ( explode( '.', $path ) as $seg ) {
					if ( ! is_array( $v ) || ! array_key_exists( $seg, $v ) ) {
						$v = null;
						break;
					}
					$v = $v[ $seg ];
				}
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					return (string) $v;
				}
			}
			return '';
		};

		$comment = $pick( array( 'comment', 'originalText.text', 'text.text', 'text', 'review', 'snippet', 'content', 'body' ) );
		list( $text ) = self::strip_google_translation( $comment );

		// "name" is a resource path in Google's format (accounts/…/reviews/…), a person's name elsewhere.
		$name    = $pick( array( 'name' ) );
		$is_path = ( false !== strpos( $name, '/' ) );
		$id      = $is_path ? $name : $pick( array( 'reviewId', 'review_id', 'id' ) );
		$author  = $pick( array( 'reviewer.displayName', 'authorAttribution.displayName', 'author', 'author_name', 'authorName', 'user.name', 'reviewer' ) );
		if ( '' === $author && ! $is_path ) {
			$author = $name;
		}

		return array(
			'source'     => $source,
			'ext_id'     => $id ? $source . ':' . $id : '',
			'author'     => $author,
			'author_url' => $pick( array( 'authorAttribution.uri', 'author_url', 'authorUrl', 'reviewer.profileUrl', 'user.link' ) ),
			'avatar'     => $pick( array( 'reviewer.profilePhotoUrl', 'authorAttribution.photoUri', 'author_photo', 'authorPhoto', 'profile_photo_url', 'user.thumbnail', 'avatar' ) ),
			'rating'     => self::parse_rating( $pick( array( 'starRating', 'rating', 'stars', 'score' ) ) ),
			'title'      => $pick( array( 'title', 'headline' ) ),
			'text'       => $text,
			'time'       => self::parse_date( $pick( array( 'createTime', 'publishTime', 'updateTime', 'iso_date', 'date', 'createdAt', 'time' ) ) ),
			'url'        => $pick( array( 'review_url', 'reviewUrl', 'googleMapsUri', 'url', 'link' ) ),
			'reply'      => $pick( array( 'reviewReply.comment', 'reply.text', 'reply.comment', 'response.text', 'companyReply.text', 'reply' ) ),
			'lang'       => $pick( array( 'originalText.languageCode', 'text.languageCode', 'language', 'lang' ) ),
			'origin'     => 'import',
		);
	}

	/**
	 * Google adds "(Translated by Google) … (Original) …" to translated comments. Keep the original.
	 *
	 * @return array [text, lang]
	 */
	public static function strip_google_translation( $comment ) {
		$comment = trim( (string) $comment );
		if ( preg_match( '/\(Original\)\s*(.+)$/s', $comment, $m ) ) {
			return array( trim( $m[1] ), '' );
		}
		if ( preg_match( '/^\(Translated by Google\)\s*(.+)$/s', $comment, $m ) ) {
			return array( trim( $m[1] ), '' );
		}
		return array( $comment, '' );
	}

	/* ---------- CSV ---------- */

	/**
	 * Column aliases, in priority order (normalised: lowercase, no punctuation).
	 */
	protected static function aliases() {
		return array(
			'author' => array( 'author', 'name', 'reviewer', 'reviewer name', 'consumer name', 'review consumer user name', 'consumer user name', 'customer name', 'display name', 'user', 'nome', 'autore' ),
			'rating' => array( 'rating', 'stars', 'review stars', 'star rating', 'score', 'stelle', 'voto' ),
			'text'   => array( 'text', 'review content', 'review text', 'content', 'review', 'comment', 'body', 'message', 'testo', 'recensione' ),
			'title'  => array( 'title', 'review title', 'headline', 'titolo' ),
			'date'   => array( 'date', 'review created utc', 'review created', 'created at', 'created', 'published', 'review date', 'date of experience', 'data' ),
			'url'    => array( 'url', 'link', 'review url', 'review link', 'review page url' ),
			'lang'   => array( 'language', 'lang', 'review language', 'locale', 'lingua' ),
			'id'     => array( 'id', 'review id', 'reference id', 'review reference id' ),
			'reply'  => array( 'reply', 'company reply', 'review reply', 'reply content', 'response', 'risposta' ),
			'source' => array( 'source', 'platform', 'fonte' ),
			'avatar' => array( 'avatar', 'photo', 'image', 'profile photo' ),
		);
	}

	protected static function norm_header( $h ) {
		$h = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h );
		$h = strtolower( trim( $h ) );
		$h = preg_replace( '/[^a-z0-9]+/', ' ', $h );
		return trim( $h );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function parse_csv( $content, $source = '' ) {
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $content );
		if ( ! seems_utf8( $content ) && function_exists( 'mb_convert_encoding' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}

		// Detect delimiter on the first line.
		$first = strtok( $content, "\n" );
		$delim = ',';
		$best  = 0;
		foreach ( array( ',', ';', "\t", '|' ) as $d ) {
			$n = substr_count( (string) $first, $d );
			if ( $n > $best ) {
				$best  = $n;
				$delim = $d;
			}
		}

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );

		$headers = fgetcsv( $fh, 0, $delim, '"', '\\' );
		if ( ! $headers ) {
			return new WP_Error( 'lbr_csv', __( 'Empty CSV file.', 'lb-reviews' ) );
		}
		$headers = array_map( array( __CLASS__, 'norm_header' ), $headers );

		// Map fields → column index.
		$map = array();
		foreach ( self::aliases() as $field => $aliases ) {
			foreach ( $aliases as $alias ) {
				$idx = array_search( $alias, $headers, true );
				if ( false !== $idx && ! in_array( $idx, $map, true ) ) {
					$map[ $field ] = $idx;
					break;
				}
			}
		}

		if ( ! isset( $map['rating'] ) || ( ! isset( $map['text'] ) && ! isset( $map['author'] ) ) ) {
			return new WP_Error(
				'lbr_csv',
				/* translators: %s: detected columns */
				sprintf( __( 'Could not find the required columns (rating + author or text). Columns found: %s', 'lb-reviews' ), implode( ', ', $headers ) )
			);
		}

		$get = function ( $row, $field ) use ( $map ) {
			return ( isset( $map[ $field ] ) && isset( $row[ $map[ $field ] ] ) ) ? trim( (string) $row[ $map[ $field ] ] ) : '';
		};

		$rows = array();
		while ( false !== ( $row = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( ! is_array( $row ) || ( 1 === count( $row ) && null === $row[0] ) ) {
				continue;
			}

			$src = $source;
			if ( ! $src ) {
				$raw = strtolower( $get( $row, 'source' ) );
				$src = ( false !== strpos( $raw, 'google' ) ) ? 'google' : ( ( false !== strpos( $raw, 'trust' ) || '' === $raw ) ? 'trustpilot' : 'other' );
			}

			$author = $get( $row, 'author' );
			$text   = $get( $row, 'text' );
			$time   = self::parse_date( $get( $row, 'date' ) );
			$id     = $get( $row, 'id' );

			$rows[] = array(
				'source' => $src,
				'ext_id' => $id ? $src . ':' . $id : $src . ':' . md5( strtolower( $author ) . '|' . gmdate( 'Y-m-d', $time ? $time : 0 ) . '|' . substr( $text, 0, 80 ) ),
				'author' => $author,
				'rating' => self::parse_rating( $get( $row, 'rating' ) ),
				'title'  => $get( $row, 'title' ),
				'text'   => $text,
				'time'   => $time,
				'url'    => $get( $row, 'url' ),
				'lang'   => $get( $row, 'lang' ),
				'reply'  => $get( $row, 'reply' ),
				'avatar' => $get( $row, 'avatar' ),
				'origin' => 'import',
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $rows;
	}

	/* ---------- Value parsers ---------- */

	public static function parse_rating( $v ) {
		if ( is_numeric( $v ) ) {
			return max( 1, min( 5, (int) round( (float) $v ) ) );
		}
		$words = array( 'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5 );
		$up    = strtoupper( trim( (string) $v ) );
		if ( isset( $words[ $up ] ) ) {
			return $words[ $up ];
		}
		if ( preg_match( '/([1-5])([.,]\d)?/', (string) $v, $m ) ) {
			return (int) $m[1];
		}
		$stars = substr_count( (string) $v, '★' );
		return $stars ? min( 5, $stars ) : 5;
	}

	/**
	 * Returns a UTC timestamp. Ambiguous "01/02/2026" dates are read as day/month (European).
	 */
	public static function parse_date( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return 0;
		}
		if ( is_numeric( $v ) && (int) $v > 100000000 ) {
			return (int) $v > 9999999999 ? (int) ( $v / 1000 ) : (int) $v;
		}
		if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?#', $v, $m ) ) {
			$a = (int) $m[1];
			$b = (int) $m[2];
			if ( $b > 12 && $a <= 12 ) { // US style m/d/Y.
				list( $a, $b ) = array( $b, $a );
			}
			$ts = gmmktime( isset( $m[4] ) ? (int) $m[4] : 12, isset( $m[5] ) ? (int) $m[5] : 0, isset( $m[6] ) ? (int) $m[6] : 0, $b, $a, (int) $m[3] );
			return $ts ? $ts : 0;
		}
		$has_tz = (bool) preg_match( '/(Z|[+\-]\d{2}:?\d{2}|UTC|GMT)$/i', $v );
		$ts     = strtotime( $has_tz ? $v : $v . ' UTC' );
		return $ts ? (int) $ts : 0;
	}
}
