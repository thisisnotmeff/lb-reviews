<?php
/**
 * Front-end output: [lb_reviews] grid and [lb_reviews_trustbox].
 */

defined( 'ABSPATH' ) || exit;

class LBR_Render {

	protected static $instance = 0;

	public static function init() {
		add_shortcode( 'lb_reviews', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'lb_reviews_trustbox', array( __CLASS__, 'trustbox' ) );
		add_shortcode( 'lb_reviews_button', array( __CLASS__, 'button' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function defaults() {
		return array(
			'source'      => 'all',   // all | google | trustpilot | google,trustpilot
			'limit'       => 12,
			'columns'     => 3,
			'min_rating'  => 1,
			'order'       => 'date',  // date | rating | random
			'summary'     => 'yes',
			'cta'         => 'yes',   // "Leave a review" links in the summary
			'lines'       => 6,       // visible lines before "Read more"
			'per_page'    => 0,       // cards shown before "Show more" (0 = all)
			'lang'        => '',      // e.g. "en" or "en,it"
			'only_text'   => 'yes',   // hide rating-only reviews (no text)
			'show_avatar' => 'yes',
			'show_date'   => 'yes',
			'show_badge'  => 'yes',
			'show_reply'  => 'no',
			'heading'     => '',
			'class'       => '',
			'layout'      => 'grid',  // grid | slider
			'list'        => '',      // list slug(s), comma separated
			'ids'         => '',      // specific review IDs, in the order to show
			'autoplay'    => 0,       // slider: seconds between slides (0 = off)
			'arrows'      => 'yes',   // slider
			'dots'        => 'yes',   // slider
		);
	}

	/* ---------- Assets ---------- */

	public static function register_assets() {
		wp_register_style( 'lb-reviews', LBR_URL . 'assets/css/lb-reviews.css', array(), LBR_VERSION );
		wp_register_script( 'lb-reviews', LBR_URL . 'assets/js/lb-reviews.js', array(), LBR_VERSION, true );

		// Load in <head> when the current page contains the shortcode (also inside WPBakery rows).
		$post = get_queried_object();
		if ( $post instanceof WP_Post && ( false !== strpos( $post->post_content, '[lb_reviews' ) ) ) {
			self::enqueue();
		}
	}

	protected static function enqueue() {
		wp_enqueue_style( 'lb-reviews' );
		wp_enqueue_script( 'lb-reviews' );
	}

	protected static function yes( $v ) {
		return in_array( strtolower( (string) $v ), array( 'yes', '1', 'true', 'on' ), true );
	}

	/* ---------- [lb_reviews] ---------- */

	public static function shortcode( $atts ) {
		$a = shortcode_atts( self::defaults(), $atts, 'lb_reviews' );
		self::enqueue();
		self::$instance++;

		$s = LBR_Settings::get();

		$sources = ( 'all' === $a['source'] || '' === $a['source'] ) ? array( 'google', 'trustpilot' ) : array_filter( array_map( 'sanitize_key', explode( ',', $a['source'] ) ) );
		$langs   = $a['lang'] ? array_filter( array_map( 'sanitize_key', explode( ',', $a['lang'] ) ) ) : array();

		// Hand-picked IDs keep the order they were written in, unless an order is given.
		if ( $a['ids'] && ( ! is_array( $atts ) || ! isset( $atts['order'] ) ) ) {
			$a['order'] = 'manual';
		}

		$lists = $a['list'] ? array_filter( array_map( 'sanitize_title', explode( ',', $a['list'] ) ) ) : array();
		$ids   = $a['ids'] ? array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $a['ids'] ) ) ) ) : array();

		$reviews = LBR_Repository::query(
			array(
				'sources'    => $sources,
				'min_rating' => max( 1, min( 5, (int) $a['min_rating'] ) ),
				'lang'       => $langs,
				'order'      => in_array( $a['order'], array( 'date', 'rating', 'random', 'manual' ), true ) ? $a['order'] : 'date',
				'limit'      => (int) $a['limit'],
				'with_text'  => self::yes( $a['only_text'] ),
				'lists'      => $lists,
				'ids'        => $ids,
			)
		);

		$is_slider = ( 'slider' === $a['layout'] );
		$columns   = max( 1, min( 4, (int) $a['columns'] ) );
		$lines     = max( 2, min( 20, (int) $a['lines'] ) );
		$per_page  = $is_slider ? 0 : max( 0, (int) $a['per_page'] );
		// Unique also across AJAX renders (the page builder re-renders one element at a time).
		$uid       = 'lbr-' . self::$instance . '-' . substr( md5( wp_json_encode( $a ) ), 0, 6 );

		$classes = array( 'lbr', 'lbr--' . $s['card_style'], $is_slider ? 'lbr--slider' : 'lbr--grid' );
		if ( $a['class'] ) {
			$classes = array_merge( $classes, array_map( 'sanitize_html_class', explode( ' ', $a['class'] ) ) );
		}

		$style = sprintf( '--lbr-cols:%d;--lbr-lines:%d;--lbr-star:%s', $columns, $lines, esc_attr( $s['star_color'] ) );

		$section_attrs = sprintf(
			'id="%s" class="%s" style="%s" data-per-page="%d"',
			esc_attr( $uid ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $style ),
			(int) $per_page
		);
		if ( $is_slider ) {
			$section_attrs .= sprintf(
				' aria-roledescription="carousel" aria-label="%s" data-autoplay="%d"',
				esc_attr( '' !== $a['heading'] ? $a['heading'] : __( 'Reviews', 'lb-reviews' ) ),
				max( 0, (int) $a['autoplay'] )
			);
		}

		/*
		 * The root is a plain one-line <div>: the WPBakery / Uncode front-end editor merges its
		 * controls into the first tag of the output, and with a <section> spread over several lines
		 * it left one of its wrappers open – the rest of the AJAX response (a whole page) then ended
		 * up inside the element and was cloned on every save, freezing the browser.
		 */
		ob_start();
		echo '<div class="lbr-wrap">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<section ' . $section_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		?>
			<?php if ( '' !== $a['heading'] ) : ?>
				<h2 class="lbr__heading"><?php echo esc_html( $a['heading'] ); ?></h2>
			<?php endif; ?>

			<?php
			if ( self::yes( $a['summary'] ) ) {
				echo self::summary( $sources, self::yes( $a['cta'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>

			<?php if ( $reviews ) : ?>
				<?php if ( $is_slider ) : ?>
				<div class="lbr__viewport">
				<?php endif; ?>
				<div class="lbr__grid" <?php echo $is_slider ? 'id="' . esc_attr( $uid ) . '-track" tabindex="0" aria-live="off"' : ''; ?>>
					<?php
					$total = count( $reviews );
					foreach ( $reviews as $i => $r ) {
						$extra = ( $per_page && $i >= $per_page ) ? ' lbr-card--more' : '';
						$attrs = $is_slider ? sprintf( ' role="group" aria-roledescription="slide" aria-label="%s"', esc_attr( sprintf( /* translators: 1: position, 2: total */ __( '%1$d of %2$d', 'lb-reviews' ), $i + 1, $total ) ) ) : '';
						echo self::card( $r, $a, $extra, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</div>
				<?php if ( $is_slider ) : ?>
				</div>
				<div class="lbr__nav">
					<?php if ( self::yes( $a['arrows'] ) ) : ?>
						<button type="button" class="lbr__arrow lbr__arrow--prev" aria-controls="<?php echo esc_attr( $uid ); ?>-track" aria-label="<?php esc_attr_e( 'Previous reviews', 'lb-reviews' ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg></button>
					<?php endif; ?>
					<?php if ( self::yes( $a['dots'] ) ) : ?>
						<div class="lbr__dots" role="group" aria-label="<?php esc_attr_e( 'Choose slide', 'lb-reviews' ); ?>"></div>
					<?php endif; ?>
					<?php if ( self::yes( $a['arrows'] ) ) : ?>
						<button type="button" class="lbr__arrow lbr__arrow--next" aria-controls="<?php echo esc_attr( $uid ); ?>-track" aria-label="<?php esc_attr_e( 'Next reviews', 'lb-reviews' ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg></button>
					<?php endif; ?>
					<?php if ( (int) $a['autoplay'] > 0 ) : ?>
						<button type="button" class="lbr__pause" aria-pressed="false" data-label-play="<?php esc_attr_e( 'Play', 'lb-reviews' ); ?>" data-label-pause="<?php esc_attr_e( 'Pause', 'lb-reviews' ); ?>"><?php esc_html_e( 'Pause', 'lb-reviews' ); ?></button>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<?php if ( $per_page && count( $reviews ) > $per_page ) : ?>
					<noscript><style>#<?php echo esc_attr( $uid ); ?> .lbr-card--more{display:flex}</style></noscript>
					<div class="lbr__actions">
						<button type="button" class="lbr__loadmore" aria-controls="<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Show more reviews', 'lb-reviews' ); ?></button>
					</div>
				<?php endif; ?>
			<?php elseif ( current_user_can( 'edit_posts' ) ) : ?>
				<p class="lbr__empty"><?php esc_html_e( 'No reviews to show yet (message visible to editors only).', 'lb-reviews' ); ?></p>
			<?php endif; ?>
		<?php
		echo '</section></div>';
		return trim( ob_get_clean() );
	}

	/* ---------- Summary ---------- */

	protected static function summary( array $sources, $cta ) {
		$s     = LBR_Settings::get();
		$items = '';
		$ctas  = '';

		foreach ( $sources as $src ) {
			$profile = self::profile_url( $src );
			$write   = self::write_url( $src );

			if ( $cta && $write ) {
				$ctas .= sprintf(
					'<a class="lbr-summary__cta" href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( $write ),
					/* translators: %s: platform */
					esc_html( sprintf( __( 'Review on %s', 'lb-reviews' ), self::source_label( $src ) ) )
				);
			}

			$st = LBR_Repository::stats( $src );
			if ( ! $st['count'] ) {
				continue;
			}

			// Figures coming from the Places API are attributed to "Google Maps".
			$origin = ( 'google' === $src && '' === $s['google_count'] && LBR_Google::live_stats() ) ? 'api' : '';
			$label  = self::source_label( $src, $origin );
			$inner  = '<span class="lbr-summary__score">' . esc_html( number_format_i18n( $st['rating'], 1 ) ) . '</span>'
				. '<span class="lbr-summary__body">'
				. self::stars( $st['rating'] )
				. '<span class="lbr-summary__label">' . self::source_mark( $src, $origin ) . ' <span class="lbr-summary__count">'
				/* translators: %s: number of reviews */
				. esc_html( sprintf( _n( '%s review', '%s reviews', $st['count'], 'lb-reviews' ), number_format_i18n( $st['count'] ) ) )
				. '</span></span></span>';

			if ( $profile ) {
				$items .= sprintf(
					'<a class="lbr-summary__item lbr-summary__item--%1$s" href="%2$s" target="_blank" rel="noopener" aria-label="%3$s">%4$s</a>',
					esc_attr( $src ),
					esc_url( $profile ),
					/* translators: 1: rating, 2: platform */
					esc_attr( sprintf( __( 'Rated %1$s out of 5 on %2$s – see all reviews', 'lb-reviews' ), number_format_i18n( $st['rating'], 1 ), $label ) ),
					$inner
				);
			} else {
				$items .= '<div class="lbr-summary__item lbr-summary__item--' . esc_attr( $src ) . '">' . $inner . '</div>';
			}
		}

		if ( '' === $items && '' === $ctas ) {
			return '';
		}

		return '<div class="lbr-summary">' . $items . ( $ctas ? '<div class="lbr-summary__ctas">' . $ctas . '</div>' : '' ) . '</div>';
	}

	/* ---------- Card ---------- */

	protected static function card( array $r, array $a, $extra_class = '', $attrs = '' ) {
		$label    = self::source_label( $r['source'], $r['origin'] );
		$name     = esc_html( $r['author'] );
		$is_api   = ( 'api' === $r['origin'] );
		$link_rel = 'nofollow noopener';

		if ( $r['author_url'] ) {
			$name = sprintf( '<a href="%s" target="_blank" rel="%s">%s</a>', esc_url( $r['author_url'] ), $link_rel, $name );
		}

		$html  = '<article class="lbr-card lbr-card--' . esc_attr( $r['source'] ) . esc_attr( $extra_class ) . '"' . ( $r['lang'] ? ' lang="' . esc_attr( $r['lang'] ) . '"' : '' ) . $attrs . '>';
		$html .= '<header class="lbr-card__head">';

		// Avatar is mandatory for Places API content (minimum author attribution).
		if ( self::yes( $a['show_avatar'] ) || $is_api ) {
			$html .= self::avatar( $r );
		}

		$html .= '<div class="lbr-card__who"><span class="lbr-card__name">' . $name . '</span>';
		if ( self::yes( $a['show_date'] ) && $r['time'] ) {
			$html .= '<time class="lbr-card__date" datetime="' . esc_attr( gmdate( 'c', $r['time'] ) ) . '">' . esc_html( wp_date( 'F Y', $r['time'] ) ) . '</time>';
		}
		$html .= '</div>';
		$html .= '</header>';

		$html .= '<div class="lbr-card__rating">' . self::stars( $r['rating'] );
		if ( self::yes( $a['show_badge'] ) || $is_api ) {
			$html .= '<span class="lbr-card__source" title="' . esc_attr( $label ) . '">' . self::source_mark( $r['source'], $r['origin'] ) . '</span>';
		}
		$html .= '</div>';

		// Trustpilot fills the title with the first words of the review when the author leaves it empty.
		// Title on its own line → drop it from the text; title is just the start of a sentence → hide the title.
		$title = trim( $r['title'] );
		if ( $title && 0 === stripos( ltrim( $r['text'] ), rtrim( $title, '.…!? ' ) ) ) {
			$rest = substr( ltrim( $r['text'] ), strlen( $title ) );
			if ( 0 === strpos( ltrim( $r['text'] ), $title ) && preg_match( '/^[ \t]*\r?\n/', $rest ) ) {
				$r['text'] = ltrim( $rest );
			} else {
				$title = '';
			}
		}

		if ( $title ) {
			$html .= '<p class="lbr-card__title">' . esc_html( $title ) . '</p>';
		}

		if ( '' !== trim( $r['text'] ) ) {
			$html .= '<div class="lbr-card__text">' . wpautop( esc_html( $r['text'] ) ) . '</div>';
			$html .= '<button type="button" class="lbr-card__more" aria-expanded="false" data-less="' . esc_attr__( 'Read less', 'lb-reviews' ) . '" hidden>' . esc_html__( 'Read more', 'lb-reviews' ) . '</button>';
		}

		if ( ! empty( $r['translated'] ) ) {
			$html .= '<p class="lbr-card__note">' . esc_html__( 'Translated by Google', 'lb-reviews' ) . '</p>';
		}

		if ( self::yes( $a['show_reply'] ) && $r['reply'] ) {
			$html .= '<div class="lbr-card__reply"><span class="lbr-card__reply-label">' . esc_html__( 'Reply from the owner', 'lb-reviews' ) . '</span>' . wpautop( esc_html( $r['reply'] ) ) . '</div>';
		}

		// No link to the single review (e.g. Google Takeout) → link to the platform page with all reviews.
		if ( ! $r['url'] ) {
			$r['url'] = self::profile_url( $r['source'] );
		}

		if ( $r['url'] ) {
			$html .= sprintf(
				'<footer class="lbr-card__foot"><a href="%s" target="_blank" rel="%s">%s</a></footer>',
				esc_url( $r['url'] ),
				$link_rel,
				/* translators: %s: platform */
				esc_html( sprintf( __( 'View on %s', 'lb-reviews' ), $label ) )
			);
		}

		$html .= '</article>';
		return $html;
	}

	protected static function avatar( array $r ) {
		$initial = function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( trim( $r['author'] ), 0, 1 ) ) : strtoupper( substr( trim( $r['author'] ), 0, 1 ) );
		$initial = $initial ? $initial : '•';
		$hue     = (int) ( hexdec( substr( md5( $r['author'] ), 0, 2 ) ) * 360 / 256 );

		if ( $r['avatar'] ) {
			// data-initial lets the script fall back to initials if the photo URL has expired.
			return sprintf(
				'<img class="lbr-card__avatar" src="%s" alt="" width="44" height="44" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-initial="%s" data-hue="%d">',
				esc_url( $r['avatar'] ),
				esc_attr( $initial ),
				$hue
			);
		}
		return sprintf( '<span class="lbr-card__avatar lbr-card__avatar--initial" style="--lbr-hue:%d" aria-hidden="true">%s</span>', $hue, esc_html( $initial ) );
	}

	/* ---------- Links ---------- */

	/**
	 * Page with all the reviews on the platform.
	 */
	public static function profile_url( $source ) {
		$s = LBR_Settings::get();
		if ( 'google' === $source ) {
			return $s['google_profile_url'] ? $s['google_profile_url'] : LBR_Google::maps_uri();
		}
		if ( 'trustpilot' === $source ) {
			return $s['trustpilot_url'];
		}
		return '';
	}

	/**
	 * "Leave a review" link. Google: link from the Business Profile (g.page/r/…/review) or built from the Place ID.
	 */
	public static function write_url( $source ) {
		$s = LBR_Settings::get();
		if ( 'google' === $source ) {
			if ( $s['google_review_url'] ) {
				return $s['google_review_url'];
			}
			return $s['google_place_id'] ? 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $s['google_place_id'] ) : '';
		}
		if ( 'trustpilot' === $source && $s['trustpilot_url'] ) {
			return str_replace( '/review/', '/evaluate/', $s['trustpilot_url'] );
		}
		return '';
	}

	/**
	 * [lb_reviews_button source="google" type="write|read" label=""]
	 */
	public static function button( $atts ) {
		$a = shortcode_atts(
			array(
				'source' => 'google',
				'type'   => 'write',
				'label'  => '',
				'style'  => 'outline', // outline | solid | link
				'align'  => 'center',  // left | center | right
				'class'  => '',
			),
			$atts,
			'lb_reviews_button'
		);
		self::enqueue();

		$source = sanitize_key( $a['source'] );
		$read   = ( 'read' === $a['type'] );
		$url    = $read ? self::profile_url( $source ) : self::write_url( $source );

		if ( ! $url ) {
			return current_user_can( 'edit_posts' ) ? '<p class="lbr__empty">' . esc_html__( 'Review link not set (LB Reviews → Settings).', 'lb-reviews' ) . '</p>' : '';
		}

		$platform = self::source_label( $source );
		/* translators: %s: platform */
		$label = '' !== $a['label'] ? $a['label'] : ( $read ? sprintf( __( 'Read all reviews on %s', 'lb-reviews' ), $platform ) : sprintf( __( 'Leave a review on %s', 'lb-reviews' ), $platform ) );
		$style = in_array( $a['style'], array( 'outline', 'solid', 'link' ), true ) ? $a['style'] : 'outline';
		$align = in_array( $a['align'], array( 'left', 'center', 'right' ), true ) ? $a['align'] : 'center';
		$extra = $a['class'] ? ' ' . implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $a['class'] ) ) ) : '';

		return sprintf(
			'<p class="lbr-button-wrap lbr-button-wrap--%1$s%5$s"><a class="lbr-button lbr-button--%2$s lbr-button--%6$s" href="%3$s" target="_blank" rel="noopener">%4$s</a></p>',
			esc_attr( $align ),
			esc_attr( $style ),
			esc_url( $url ),
			esc_html( $label ),
			esc_attr( $extra ),
			esc_attr( $source )
		);
	}

	/* ---------- Bits ---------- */

	protected static function source_label( $source, $origin = '' ) {
		if ( 'google' === $source ) {
			// Places API content must be attributed to "Google Maps".
			return 'api' === $origin ? 'Google Maps' : 'Google';
		}
		$sources = LBR_Post_Type::sources();
		return isset( $sources[ $source ] ) ? $sources[ $source ] : ucfirst( $source );
	}

	/**
	 * Official logo when an image URL is set in the settings, otherwise the platform name as text.
	 */
	protected static function source_mark( $source, $origin = '' ) {
		$s     = LBR_Settings::get();
		$label = self::source_label( $source, $origin );
		$logo  = '';
		if ( 'google' === $source ) {
			$logo = $s['google_logo_url'];
		} elseif ( 'trustpilot' === $source ) {
			$logo = $s['trustpilot_logo_url'];
		}
		if ( $logo ) {
			return sprintf( '<img class="lbr-logo lbr-logo--%s" src="%s" alt="%s" height="18" loading="lazy" decoding="async">', esc_attr( $source ), esc_url( $logo ), esc_attr( $label ) );
		}
		return '<span class="lbr-logo lbr-logo--text lbr-logo--' . esc_attr( $source ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function stars( $rating ) {
		$rating = max( 0, min( 5, (float) $rating ) );
		$pct    = round( $rating / 5 * 100, 1 );
		$star   = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg>';
		$row    = str_repeat( $star, 5 );
		/* translators: %s: rating */
		$label = sprintf( __( 'Rated %s out of 5', 'lb-reviews' ), number_format_i18n( $rating, ( floor( $rating ) == $rating ) ? 0 : 1 ) ); // phpcs:ignore Universal.Operators.StrictComparisons
		return '<span class="lbr-stars" role="img" aria-label="' . esc_attr( $label ) . '"><span class="lbr-stars__bg">' . $row . '</span><span class="lbr-stars__fg" style="width:' . esc_attr( $pct ) . '%">' . $row . '</span></span>';
	}

	/* ---------- [lb_reviews_trustbox] ---------- */

	public static function trustbox() {
		$code = LBR_Settings::get( 'trustbox_code' );
		if ( ! $code ) {
			return current_user_can( 'edit_posts' ) ? '<p class="lbr__empty">' . esc_html__( 'TrustBox code not set (LB Reviews → Settings).', 'lb-reviews' ) . '</p>' : '';
		}
		// Saved only by users with unfiltered_html (see LBR_Settings::sanitize).
		return '<div class="lbr-trustbox">' . $code . '</div>';
	}
}
