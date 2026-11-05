<?php
/**
 * Stored reviews: Google archive (Takeout import), Trustpilot (CSV import), manual entries.
 *
 * Title = reviewer name · Content = review text · Post date = review date
 * Published = visible · Draft = hidden.
 */

defined( 'ABSPATH' ) || exit;

class LBR_Post_Type {

	const CPT = 'lbr_review';
	const TAX = 'lbr_list';

	public static function sources() {
		return array(
			'google'     => 'Google',
			'trustpilot' => 'Trustpilot',
			'other'      => __( 'Other', 'lb-reviews' ),
		);
	}

	public static function init() {
		// Early, so the lists exist when WPBakery maps its elements.
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'set_object_terms', array( __CLASS__, 'terms_changed' ), 10, 4 );
		foreach ( array( 'created_' . self::TAX, 'edited_' . self::TAX, 'delete_' . self::TAX ) as $hook ) {
			add_action( $hook, array( 'LBR_Repository', 'bump_cache' ) );
		}
		add_filter( 'manage_edit-' . self::TAX . '_columns', array( __CLASS__, 'list_columns' ) );
		add_filter( 'manage_' . self::TAX . '_custom_column', array( __CLASS__, 'list_column_content' ), 10, 3 );
		add_action( self::TAX . '_pre_add_form', array( __CLASS__, 'list_intro' ) );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'list_table_css' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'source_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_source_filter' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Reviews', 'lb-reviews' ),
					'singular_name' => __( 'Review', 'lb-reviews' ),
					'add_new_item'  => __( 'Add review', 'lb-reviews' ),
					'edit_item'     => __( 'Edit review', 'lb-reviews' ),
					'all_items'     => __( 'All reviews', 'lb-reviews' ),
					'menu_name'     => __( 'LB Reviews', 'lb-reviews' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-star-filled',
				'supports'            => array( 'title', 'editor' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);

		register_taxonomy(
			self::TAX,
			self::CPT,
			array(
				'labels'             => array(
					'name'          => __( 'Lists', 'lb-reviews' ),
					'singular_name' => __( 'List', 'lb-reviews' ),
					'menu_name'     => __( 'Lists', 'lb-reviews' ),
					'all_items'     => __( 'All lists', 'lb-reviews' ),
					'add_new_item'  => __( 'Add new list', 'lb-reviews' ),
					'edit_item'     => __( 'Edit list', 'lb-reviews' ),
					'search_items'  => __( 'Search lists', 'lb-reviews' ),
					'not_found'     => __( 'No lists yet.', 'lb-reviews' ),
				),
				'hierarchical'       => true, // Checkboxes in the editor and in bulk edit.
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_admin_column'  => true,
				'show_in_quick_edit' => true,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_in_rest'       => false,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}

	public static function list_table_css() {
		if ( isset( $_GET['post_type'] ) && self::CPT === $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<style>.fixed .column-lbr_id{width:4em}.fixed .column-lbr_rating{width:6em}.fixed .column-lbr_lang{width:4em}</style>';
		}
	}

	public static function terms_changed( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( self::TAX === $taxonomy ) {
			LBR_Repository::bump_cache();
		}
	}

	public static function list_intro() {
		echo '<p>' . esc_html__( 'Group reviews to show them on specific pages: create a list here, tick it on the reviews (one by one, or several at once with Bulk actions → Edit), then use the shortcode shown in the table or pick the list in the LB Reviews element.', 'lb-reviews' ) . '</p>';
	}

	public static function list_columns( $cols ) {
		unset( $cols['description'] );
		$cols['lbr_shortcode'] = __( 'Shortcode', 'lb-reviews' );
		return $cols;
	}

	public static function list_column_content( $content, $column, $term_id ) {
		if ( 'lbr_shortcode' === $column ) {
			$term = get_term( $term_id, self::TAX );
			if ( $term && ! is_wp_error( $term ) ) {
				$code = '[lb_reviews list="' . $term->slug . '"]';
				return '<input type="text" readonly class="large-text code" value="' . esc_attr( $code ) . '" onclick="this.select()">';
			}
		}
		return $content;
	}

	public static function title_placeholder( $text, $post ) {
		return self::CPT === $post->post_type ? __( 'Reviewer name', 'lb-reviews' ) : $text;
	}

	/* ---------- Meta box ---------- */

	public static function add_meta_box() {
		add_meta_box( 'lbr_review_meta', __( 'Review details', 'lb-reviews' ), array( __CLASS__, 'render_meta_box' ), self::CPT, 'side', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'lbr_save_meta', 'lbr_meta_nonce' );
		$m = LBR_Repository::get_meta( $post->ID );
		?>
		<p>
			<label for="lbr_source"><strong><?php esc_html_e( 'Source', 'lb-reviews' ); ?></strong></label><br>
			<select name="lbr_source" id="lbr_source" class="widefat">
				<?php foreach ( self::sources() as $k => $label ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $m['source'], $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="lbr_rating"><strong><?php esc_html_e( 'Rating', 'lb-reviews' ); ?></strong></label><br>
			<select name="lbr_rating" id="lbr_rating" class="widefat">
				<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
					<option value="<?php echo esc_attr( $i ); ?>" <?php selected( (int) $m['rating'], $i ); ?>><?php echo esc_html( str_repeat( '★', $i ) . ' (' . $i . ')' ); ?></option>
				<?php endfor; ?>
			</select>
		</p>
		<p>
			<label for="lbr_title"><strong><?php esc_html_e( 'Review title (Trustpilot)', 'lb-reviews' ); ?></strong></label>
			<input type="text" class="widefat" name="lbr_title" id="lbr_title" value="<?php echo esc_attr( $m['title'] ); ?>">
		</p>
		<p>
			<label for="lbr_url"><strong><?php esc_html_e( 'Link to the original review', 'lb-reviews' ); ?></strong></label>
			<input type="url" class="widefat" name="lbr_url" id="lbr_url" value="<?php echo esc_attr( $m['url'] ); ?>">
		</p>
		<p>
			<label for="lbr_author_url"><strong><?php esc_html_e( 'Reviewer profile URL (optional)', 'lb-reviews' ); ?></strong></label>
			<input type="url" class="widefat" name="lbr_author_url" id="lbr_author_url" value="<?php echo esc_attr( $m['author_url'] ); ?>">
		</p>
		<p>
			<label for="lbr_avatar"><strong><?php esc_html_e( 'Avatar URL (optional)', 'lb-reviews' ); ?></strong></label>
			<input type="url" class="widefat" name="lbr_avatar" id="lbr_avatar" value="<?php echo esc_attr( $m['avatar'] ); ?>">
		</p>
		<p>
			<label for="lbr_lang"><strong><?php esc_html_e( 'Language code (en, es, de, it…)', 'lb-reviews' ); ?></strong></label>
			<input type="text" class="widefat" name="lbr_lang" id="lbr_lang" maxlength="10" value="<?php echo esc_attr( $m['lang'] ); ?>">
		</p>
		<p>
			<label for="lbr_reply"><strong><?php esc_html_e( 'Owner reply (optional)', 'lb-reviews' ); ?></strong></label>
			<textarea class="widefat" rows="3" name="lbr_reply" id="lbr_reply"><?php echo esc_textarea( $m['reply'] ); ?></textarea>
		</p>
		<p>
			<label><input type="checkbox" name="lbr_pinned" value="1" <?php checked( $m['pinned'] ); ?>> <?php esc_html_e( 'Pin (always shown first)', 'lb-reviews' ); ?></label>
		</p>
		<p class="description"><?php esc_html_e( 'Publish = visible on the site. Save as draft = hidden. The post date is the review date.', 'lb-reviews' ); ?></p>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['lbr_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['lbr_meta_nonce'] ), 'lbr_save_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$source = isset( $_POST['lbr_source'] ) ? sanitize_key( $_POST['lbr_source'] ) : 'other';
		$source = array_key_exists( $source, self::sources() ) ? $source : 'other';
		$rating = isset( $_POST['lbr_rating'] ) ? max( 1, min( 5, absint( $_POST['lbr_rating'] ) ) ) : 5;

		update_post_meta( $post_id, '_lbr_source', $source );
		update_post_meta( $post_id, '_lbr_rating', $rating );
		update_post_meta( $post_id, '_lbr_title', isset( $_POST['lbr_title'] ) ? sanitize_text_field( wp_unslash( $_POST['lbr_title'] ) ) : '' );
		update_post_meta( $post_id, '_lbr_url', isset( $_POST['lbr_url'] ) ? esc_url_raw( wp_unslash( $_POST['lbr_url'] ) ) : '' );
		update_post_meta( $post_id, '_lbr_author_url', isset( $_POST['lbr_author_url'] ) ? esc_url_raw( wp_unslash( $_POST['lbr_author_url'] ) ) : '' );
		update_post_meta( $post_id, '_lbr_avatar', isset( $_POST['lbr_avatar'] ) ? esc_url_raw( wp_unslash( $_POST['lbr_avatar'] ) ) : '' );
		update_post_meta( $post_id, '_lbr_lang', isset( $_POST['lbr_lang'] ) ? LBR_Repository::sanitize_lang( wp_unslash( $_POST['lbr_lang'] ) ) : '' );
		update_post_meta( $post_id, '_lbr_reply', isset( $_POST['lbr_reply'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lbr_reply'] ) ) : '' );
		update_post_meta( $post_id, '_lbr_pinned', empty( $_POST['lbr_pinned'] ) ? 0 : 1 );

		if ( ! get_post_meta( $post_id, '_lbr_origin', true ) ) {
			update_post_meta( $post_id, '_lbr_origin', 'manual' );
		}
	}

	/* ---------- List table ---------- */

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				continue;
			}
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['lbr_id']     = 'ID';
				$new['lbr_source'] = __( 'Source', 'lb-reviews' );
				$new['lbr_rating'] = __( 'Rating', 'lb-reviews' );
				$new['lbr_text']   = __( 'Review', 'lb-reviews' );
				$new['lbr_lang']   = __( 'Lang', 'lb-reviews' );
			}
		}
		$new['date'] = __( 'Review date', 'lb-reviews' );
		return $new;
	}

	public static function column_content( $col, $post_id ) {
		$m = LBR_Repository::get_meta( $post_id );
		switch ( $col ) {
			case 'lbr_id':
				echo (int) $post_id;
				break;
			case 'lbr_source':
				$sources = self::sources();
				echo esc_html( isset( $sources[ $m['source'] ] ) ? $sources[ $m['source'] ] : $m['source'] );
				if ( $m['pinned'] ) {
					echo ' <span class="dashicons dashicons-sticky" title="' . esc_attr__( 'Pinned', 'lb-reviews' ) . '"></span>';
				}
				break;
			case 'lbr_rating':
				echo '<span style="color:#c9a45c;letter-spacing:1px">' . esc_html( str_repeat( '★', (int) $m['rating'] ) ) . '</span>';
				break;
			case 'lbr_text':
				echo esc_html( wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 18 ) );
				break;
			case 'lbr_lang':
				echo esc_html( $m['lang'] );
				break;
		}
	}

	public static function source_filter( $post_type ) {
		if ( self::CPT !== $post_type ) {
			return;
		}
		$current = isset( $_GET['lbr_source'] ) ? sanitize_key( $_GET['lbr_source'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<select name="lbr_source"><option value="">' . esc_html__( 'All sources', 'lb-reviews' ) . '</option>';
		foreach ( self::sources() as $k => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $current, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public static function apply_source_filter( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || self::CPT !== $q->get( 'post_type' ) ) {
			return;
		}
		if ( ! empty( $_GET['lbr_source'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$q->set( 'meta_key', '_lbr_source' );
			$q->set( 'meta_value', sanitize_key( $_GET['lbr_source'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
}
