<?php
/**
 * Admin screens: Settings · Import · Usage.
 */

defined( 'ABSPATH' ) || exit;

class LBR_Admin {

	const PAGE = 'lbr-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_lbr_google_refresh', array( __CLASS__, 'google_refresh' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LBR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( $tab = 'settings' ) {
		return admin_url( 'edit.php?post_type=' . LBR_Post_Type::CPT . '&page=' . self::PAGE . '&tab=' . $tab );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'lb-reviews' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . LBR_Post_Type::CPT,
			__( 'LB Reviews settings', 'lb-reviews' ),
			__( 'Settings & import', 'lb-reviews' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register_setting() {
		register_setting(
			'lbr_settings_group',
			LBR_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'LBR_Settings', 'sanitize' ),
				'default'           => LBR_Settings::defaults(),
			)
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'lb-reviews-admin', LBR_URL . 'assets/css/admin.css', array(), LBR_VERSION );
	}

	public static function google_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'lb-reviews' ) );
		}
		check_admin_referer( 'lbr_google_refresh' );
		LBR_Google::clear_cache();
		LBR_Google::refresh();
		wp_safe_redirect( self::url( 'settings' ) . '#lbr-google' );
		exit;
	}

	/* ---------- Page ---------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'settings' => __( 'Settings', 'lb-reviews' ),
			'import'   => __( 'Import', 'lb-reviews' ),
			'usage'    => __( 'How to use', 'lb-reviews' ),
		);
		?>
		<div class="wrap lbr-admin">
			<h1><?php esc_html_e( 'LB Reviews – Google & Trustpilot', 'lb-reviews' ); ?></h1>
			<?php
			if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				add_settings_error( 'lbr_messages', 'lbr_saved', __( 'Settings saved.', 'lb-reviews' ), 'updated' );
			}
			settings_errors( 'lbr_messages' );
			?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $k ) ); ?>" class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'import' === $tab ) {
				self::render_import();
			} elseif ( 'usage' === $tab ) {
				self::render_usage();
			} else {
				self::render_settings();
			}
			?>
		</div>
		<?php
	}

	protected static function field( $key, $label, $type = 'text', $help = '', $attrs = '' ) {
		$s    = LBR_Settings::get();
		$name = LBR_Settings::OPTION . '[' . $key . ']';
		echo '<tr><th scope="row"><label for="lbr_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'checkbox' === $type ) {
			printf( '<label><input type="checkbox" id="lbr_%1$s" name="%2$s" value="1" %3$s> %4$s</label>', esc_attr( $key ), esc_attr( $name ), checked( $s[ $key ], 1, false ), esc_html( $help ) );
			$help = '';
		} elseif ( 'textarea' === $type ) {
			printf( '<textarea id="lbr_%1$s" name="%2$s" rows="5" class="large-text code" %4$s>%3$s</textarea>', esc_attr( $key ), esc_attr( $name ), esc_textarea( $s[ $key ] ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			printf( '<input type="%1$s" id="lbr_%2$s" name="%3$s" value="%4$s" class="regular-text" %5$s>', esc_attr( $type ), esc_attr( $key ), esc_attr( $name ), esc_attr( $s[ $key ] ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $help ) {
			echo '<p class="description">' . wp_kses( $help, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'code' => array(), 'strong' => array() ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	protected static function render_settings() {
		$s      = LBR_Settings::get();
		$status = get_option( LBR_Google::STATUS_OPT );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'lbr_settings_group' ); ?>

			<h2 id="lbr-google"><?php esc_html_e( 'Google – live reviews (Places API)', 'lb-reviews' ); ?></h2>
			<p class="description lbr-note">
				<?php esc_html_e( 'The Places API returns the average rating, the total number of reviews and up to 5 reviews. They are kept only in a temporary cache (never saved in the database) and shown with Google Maps attribution, as required by Google’s policies. For the full archive, import your Google Takeout export in the Import tab.', 'lb-reviews' ); ?>
			</p>
			<?php if ( is_array( $status ) ) : ?>
				<div class="notice inline <?php echo $status['ok'] ? 'notice-success' : 'notice-error'; ?>">
					<p>
						<strong><?php echo $status['ok'] ? esc_html__( 'Last update OK', 'lb-reviews' ) : esc_html__( 'Last update failed', 'lb-reviews' ); ?></strong>
						(<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['time'] ) ); ?>): <?php echo esc_html( $status['message'] ); ?>
					</p>
				</div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<?php
				self::field( 'google_enabled', __( 'Enable', 'lb-reviews' ), 'checkbox', __( 'Fetch live reviews and rating from Google', 'lb-reviews' ) );
				self::field( 'google_api_key', __( 'API key', 'lb-reviews' ), 'password', __( 'Google Cloud key with <strong>Places API (New)</strong> enabled. Restrict it to that API (and, if possible, to the server IP).', 'lb-reviews' ), 'autocomplete="off"' );
				self::field( 'google_place_id', __( 'Place ID', 'lb-reviews' ), 'text', __( 'Find it with the <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener">Place ID Finder</a> (starts with <code>ChIJ…</code>).', 'lb-reviews' ) );
				self::field( 'google_language', __( 'Language', 'lb-reviews' ), 'text', __( 'Optional, e.g. <code>en</code>. Empty = Google default.', 'lb-reviews' ), 'maxlength="10" style="width:6em"' );
				self::field( 'google_show_original', __( 'Original text', 'lb-reviews' ), 'checkbox', __( 'Show reviews in the language they were written in (no Google translation)', 'lb-reviews' ) );
				self::field( 'google_cache_hours', __( 'Refresh every (hours)', 'lb-reviews' ), 'number', __( '1–168. Each refresh is one Place Details request (billed by Google above the free monthly quota).', 'lb-reviews' ), 'min="1" max="168" style="width:6em"' );
				self::field( 'google_review_url', __( '"Leave a review" link', 'lb-reviews' ), 'url', __( 'Google Business Profile → <strong>Ask for reviews</strong> → copy the link (e.g. <code>https://g.page/r/XXXX/review</code>). Works without the API. Used by the “Review on Google” links and by <code>[lb_reviews_button]</code>.', 'lb-reviews' ) );
				self::field( 'google_profile_url', __( '"See all reviews" link', 'lb-reviews' ), 'url', __( 'Link to the Google page with all the reviews (Maps listing). Used by the summary and as “View on Google” on reviews that have no link of their own. Empty = the Google Maps link returned by the API.', 'lb-reviews' ) );
				self::field( 'google_rating', __( 'Average (override)', 'lb-reviews' ), 'text', __( 'Optional. Empty = automatic.', 'lb-reviews' ), 'style="width:6em"' );
				self::field( 'google_count', __( 'Total (override)', 'lb-reviews' ), 'text', __( 'Optional. Empty = automatic.', 'lb-reviews' ), 'style="width:6em"' );
				?>
			</table>

			<h2><?php esc_html_e( 'Trustpilot', 'lb-reviews' ); ?></h2>
			<p class="description lbr-note">
				<?php esc_html_e( 'On the free plan Trustpilot offers TrustBox widgets; custom displays and API access depend on the plan. Import reviews from CSV in the Import tab, or paste a TrustBox snippet below and use [lb_reviews_trustbox]. Follow Trustpilot’s brand guidelines.', 'lb-reviews' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<?php
				self::field( 'trustpilot_url', __( 'Profile URL', 'lb-reviews' ), 'url', __( 'e.g. <code>https://www.trustpilot.com/review/lucillabellini.net</code>', 'lb-reviews' ) );
				self::field( 'trustpilot_rating', __( 'TrustScore / average (override)', 'lb-reviews' ), 'text', __( 'Optional. Empty = average of the imported reviews.', 'lb-reviews' ), 'style="width:6em"' );
				self::field( 'trustpilot_count', __( 'Total (override)', 'lb-reviews' ), 'text', __( 'Optional. Empty = number of imported reviews.', 'lb-reviews' ), 'style="width:6em"' );
				if ( current_user_can( 'unfiltered_html' ) ) {
					self::field( 'trustbox_code', __( 'TrustBox code', 'lb-reviews' ), 'textarea', __( 'Paste the snippet from Trustpilot Business → Integrations → TrustBox. Output with <code>[lb_reviews_trustbox]</code>.', 'lb-reviews' ) );
				}
				?>
			</table>

			<h2><?php esc_html_e( 'Appearance', 'lb-reviews' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php self::field( 'star_color', __( 'Star colour', 'lb-reviews' ), 'color' ); ?>
				<tr>
					<th scope="row"><label for="lbr_card_style"><?php esc_html_e( 'Card style', 'lb-reviews' ); ?></label></th>
					<td>
						<select id="lbr_card_style" name="<?php echo esc_attr( LBR_Settings::OPTION ); ?>[card_style]">
							<?php
							foreach ( array( 'minimal' => __( 'Minimal (thin line)', 'lb-reviews' ), 'bordered' => __( 'Bordered', 'lb-reviews' ), 'soft' => __( 'Soft background', 'lb-reviews' ) ) as $k => $label ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $s['card_style'], $k, false ), esc_html( $label ) );
							}
							?>
						</select>
					</td>
				</tr>
				<?php
				self::field( 'google_logo_url', __( 'Google logo (image URL)', 'lb-reviews' ), 'url', __( 'Optional: official logo from Google’s brand resources. Empty = text “Google”.', 'lb-reviews' ) );
				self::field( 'trustpilot_logo_url', __( 'Trustpilot logo (image URL)', 'lb-reviews' ), 'url', __( 'Optional: official logo from Trustpilot’s brand assets. Empty = text “Trustpilot”.', 'lb-reviews' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Structured data', 'lb-reviews' ); ?></h2>
			<p class="description lbr-note"><?php esc_html_e( 'Off by default. Google does not show stars for a business’s own reviews on its own site, and Rank Math may already output schema for the business. If you enable it, set the @id of the Rank Math entity so the rating is merged into it instead of creating a second business.', 'lb-reviews' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				self::field( 'schema_enabled', __( 'aggregateRating', 'lb-reviews' ), 'checkbox', __( 'Add JSON-LD on pages that show the reviews', 'lb-reviews' ) );
				self::field( 'schema_id', __( 'Entity @id', 'lb-reviews' ), 'url', __( 'e.g. <code>https://www.lucillabellini.net/#organization</code> (check the Rank Math output in the page source).', 'lb-reviews' ) );
				?>
				<tr>
					<th scope="row"><label for="lbr_schema_type"><?php esc_html_e( 'Entity type', 'lb-reviews' ); ?></label></th>
					<td>
						<select id="lbr_schema_type" name="<?php echo esc_attr( LBR_Settings::OPTION ); ?>[schema_type]">
							<?php
							foreach ( array( 'LocalBusiness', 'ProfessionalService', 'Organization', 'Person' ) as $t ) {
								printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $t ), selected( $s['schema_type'], $t, false ) );
							}
							?>
						</select>
					</td>
				</tr>
				<?php self::field( 'schema_name', __( 'Name', 'lb-reviews' ), 'text', __( 'Empty = site title.', 'lb-reviews' ) ); ?>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'lb-reviews' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php self::field( 'delete_on_uninstall', __( 'Delete data', 'lb-reviews' ), 'checkbox', __( 'Delete all stored reviews and settings when the plugin is deleted', 'lb-reviews' ) ); ?>
			</table>

			<?php submit_button(); ?>
		</form>

		<?php if ( LBR_Google::is_configured() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lbr_google_refresh">
				<?php wp_nonce_field( 'lbr_google_refresh' ); ?>
				<?php submit_button( __( 'Refresh Google reviews now', 'lb-reviews' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	protected static function render_import() {
		$result = get_transient( 'lbr_import_result_' . get_current_user_id() );
		if ( $result ) {
			delete_transient( 'lbr_import_result_' . get_current_user_id() );
			$class = $result['errors'] ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>';
			/* translators: 1: created, 2: updated, 3: skipped */
			echo esc_html( sprintf( __( 'Import finished: %1$d new, %2$d updated, %3$d skipped.', 'lb-reviews' ), $result['created'], $result['updated'], $result['skipped'] ) );
			echo '</p>';
			if ( ! empty( $result['files'] ) ) {
				echo '<ul class="lbr-import-files">';
				foreach ( $result['files'] as $f ) {
					/* translators: 1: file, 2: found, 3: new, 4: updated, 5: skipped */
					echo '<li>' . esc_html( sprintf( __( '%1$s – %2$d reviews found: %3$d new, %4$d updated, %5$d skipped', 'lb-reviews' ), $f['name'], $f['found'], $f['created'], $f['updated'], $f['skipped'] ) ) . '</li>';
				}
				echo '</ul>';
			}
			if ( ! empty( $result['notes'] ) ) {
				foreach ( $result['notes'] as $note ) {
					echo '<p>' . esc_html( $note ) . '</p>';
				}
			}
			if ( $result['created'] || $result['updated'] ) {
				echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . LBR_Post_Type::CPT ) ) . '">' . esc_html__( 'Open the reviews list', 'lb-reviews' ) . ' →</a></p>';
			}
			foreach ( $result['errors'] as $err ) {
				echo '<p>' . esc_html( $err ) . '</p>';
			}
			echo '</div>';
		}
		?>
		<div class="lbr-cards">
			<div class="lbr-box">
				<h2><?php esc_html_e( 'Google archive (Takeout)', 'lb-reviews' ); ?></h2>
				<ol>
					<li><?php echo wp_kses( __( 'Go to <a href="https://takeout.google.com" target="_blank" rel="noopener">takeout.google.com</a> with the account that owns the Business Profile.', 'lb-reviews' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
					<li><?php esc_html_e( 'Deselect all, then select only “Google Business Profile” and create the export.', 'lb-reviews' ); ?></li>
					<li><?php esc_html_e( 'Upload the downloaded ZIP directly, or the reviews*.json files from the location folder inside it. Do not use “Maps (your places)”: that contains the reviews the account has written, not the ones it received.', 'lb-reviews' ); ?></li>
				</ol>
			</div>
			<div class="lbr-box">
				<h2><?php esc_html_e( 'Trustpilot / any CSV', 'lb-reviews' ); ?></h2>
				<p><?php esc_html_e( 'Recognised columns (any order, many names accepted): author/name, rating/stars, text/content, title, date, url, language, id, reply, source.', 'lb-reviews' ); ?></p>
				<p><?php esc_html_e( 'Without an export you can fill a spreadsheet by hand with these columns and save it as CSV (UTF-8).', 'lb-reviews' ); ?></p>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="lbr-box">
			<input type="hidden" name="action" value="lbr_import">
			<?php wp_nonce_field( 'lbr_import' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lbr_files"><?php esc_html_e( 'Files', 'lb-reviews' ); ?></label></th>
					<td><input type="file" id="lbr_files" name="lbr_files[]" accept=".json,.csv,.tsv,.txt,.zip" multiple required></td>
				</tr>
				<tr>
					<th scope="row"><label for="lbr_import_source"><?php esc_html_e( 'Source', 'lb-reviews' ); ?></label></th>
					<td>
						<select id="lbr_import_source" name="lbr_import_source">
							<option value="auto"><?php esc_html_e( 'Automatic (JSON = Google, CSV = Trustpilot unless a “source” column says otherwise)', 'lb-reviews' ); ?></option>
							<option value="google">Google</option>
							<option value="trustpilot">Trustpilot</option>
							<option value="other"><?php esc_html_e( 'Other', 'lb-reviews' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Re-importing the same file is safe: existing reviews are updated, not duplicated. Hidden reviews (drafts) stay hidden.', 'lb-reviews' ); ?></p>
			<?php submit_button( __( 'Import', 'lb-reviews' ) ); ?>
		</form>
		<?php
	}

	protected static function render_usage() {
		?>
		<div class="lbr-box">
			<h2><?php esc_html_e( 'Shortcode', 'lb-reviews' ); ?></h2>
			<p><code>[lb_reviews]</code> — <?php esc_html_e( 'Google + Trustpilot grid with summary.', 'lb-reviews' ); ?></p>
			<p><code>[lb_reviews source="google" limit="9" columns="3" per_page="6" order="date"]</code></p>
			<p><code>[lb_reviews source="trustpilot" summary="no" lines="4"]</code></p>
			<p><code>[lb_reviews lang="es"]</code> — <?php esc_html_e( 'only reviews written in Spanish (for the ES version of the site).', 'lb-reviews' ); ?></p>
			<p><code>[lb_reviews layout="slider" columns="3" autoplay="6"]</code> — <?php esc_html_e( 'slider: 3 reviews per view on desktop, 2 on tablet, 1 on phone; changes every 6 seconds.', 'lb-reviews' ); ?></p>
			<p><code>[lb_reviews list="family"]</code> — <?php esc_html_e( 'only the reviews in the list “family” (LB Reviews → Lists).', 'lb-reviews' ); ?></p>
			<p><code>[lb_reviews ids="812,790,775" layout="slider"]</code> — <?php esc_html_e( 'hand-picked reviews, in this order (IDs are in the reviews table).', 'lb-reviews' ); ?></p>
			<p><code>[lb_reviews_button source="google"]</code> — <?php esc_html_e( '“Leave a review on Google” button. type="read" links to the page with all reviews; style="outline|solid|link"; label="…".', 'lb-reviews' ); ?></p>
			<p><code>[lb_reviews_trustbox]</code> — <?php esc_html_e( 'the TrustBox widget pasted in the settings.', 'lb-reviews' ); ?></p>
			<p><?php esc_html_e( 'In Uncode / WPBakery: Add element → “LB Reviews” or “LB Review button”.', 'lb-reviews' ); ?></p>
			<p class="description"><?php esc_html_e( 'Lists and IDs use the stored reviews (imported or added by hand). Live Google reviews from the API cannot be added to lists.', 'lb-reviews' ); ?></p>

			<h3><?php esc_html_e( 'Attributes', 'lb-reviews' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Attribute', 'lb-reviews' ); ?></th><th><?php esc_html_e( 'Default', 'lb-reviews' ); ?></th><th><?php esc_html_e( 'Values', 'lb-reviews' ); ?></th></tr></thead>
				<tbody>
					<?php
					$rows = array(
						array( 'layout', 'grid', 'grid · slider' ),
						array( 'source', 'all', 'all · google · trustpilot' ),
						array( 'list', '', __( 'list slug(s), comma separated', 'lb-reviews' ) ),
						array( 'ids', '', __( 'review IDs, comma separated (shown in that order)', 'lb-reviews' ) ),
						array( 'limit', '12', '0 = all' ),
						array( 'columns', '3', __( '1–4 columns, or reviews per view in the slider (max 2 on tablet, 1 on phone)', 'lb-reviews' ) ),
						array( 'per_page', '0', __( 'grid: cards shown before “Show more” (0 = off)', 'lb-reviews' ) ),
						array( 'order', 'date', 'date · rating · random · manual (order of ids)' ),
						array( 'autoplay', '0', __( 'slider: seconds between slides (0 = off)', 'lb-reviews' ) ),
						array( 'arrows / dots', 'yes', __( 'slider: yes · no', 'lb-reviews' ) ),
						array( 'min_rating', '1', '1–5' ),
						array( 'lang', '', 'en · es · de · it … (comma separated)' ),
						array( 'lines', '6', __( 'lines before “Read more”', 'lb-reviews' ) ),
						array( 'summary', 'yes', 'yes · no' ),
						array( 'cta', 'yes', __( '“Review on Google / Trustpilot” links', 'lb-reviews' ) ),
						array( 'only_text', 'yes', __( 'hide rating-only reviews', 'lb-reviews' ) ),
						array( 'show_avatar / show_date / show_badge', 'yes', 'yes · no' ),
						array( 'show_reply', 'no', 'yes · no' ),
						array( 'heading', '', __( 'optional H2', 'lb-reviews' ) ),
						array( 'class', '', __( 'extra CSS class', 'lb-reviews' ) ),
					);
					foreach ( $rows as $r ) {
						echo '<tr><td><code>' . esc_html( $r[0] ) . '</code></td><td>' . esc_html( $r[1] ) . '</td><td>' . esc_html( $r[2] ) . '</td></tr>';
					}
					?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Styling', 'lb-reviews' ); ?></h3>
			<p><?php esc_html_e( 'The grid inherits the theme fonts. Fine-tune with CSS variables on .lbr, e.g.:', 'lb-reviews' ); ?></p>
			<pre><code>.lbr { --lbr-gap: 2rem; --lbr-radius: 0; --lbr-text: #222; --lbr-muted: #777; --lbr-line: rgba(0,0,0,.12); --lbr-bg: #faf8f5; }</code></pre>
		</div>
		<?php
	}
}
