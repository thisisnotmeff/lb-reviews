<?php
/**
 * WPBakery / Uncode Page Builder element. Maps directly onto the [lb_reviews] shortcode.
 */

defined( 'ABSPATH' ) || exit;

class LBR_WPBakery {

	public static function init() {
		add_action( 'vc_before_init', array( __CLASS__, 'map' ) );
	}

	public static function map() {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}

		$yes_no = array(
			__( 'Yes', 'lb-reviews' ) => 'yes',
			__( 'No', 'lb-reviews' )  => 'no',
		);
		$group_display = __( 'Display', 'lb-reviews' );
		$group_slider  = __( 'Slider', 'lb-reviews' );

		// Lists for the dropdown.
		$lists = array( __( '— All reviews —', 'lb-reviews' ) => '' );
		$terms = get_terms(
			array(
				'taxonomy'   => LBR_Post_Type::TAX,
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$lists[ sprintf( '%s (%d)', $term->name, $term->count ) ] = $term->slug;
			}
		}
		$slider_dep = array(
			'element' => 'layout',
			'value'   => 'slider',
		);

		vc_map(
			array(
				'name'        => __( 'LB Reviews', 'lb-reviews' ),
				'base'        => 'lb_reviews',
				'icon'        => 'dashicons dashicons-star-filled',
				'category'    => __( 'Content', 'lb-reviews' ),
				'description' => __( 'Google & Trustpilot reviews grid', 'lb-reviews' ),
				'params'      => array(
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Heading (optional)', 'lb-reviews' ),
						'param_name'  => 'heading',
						'admin_label' => true,
					),
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Layout', 'lb-reviews' ),
						'param_name'  => 'layout',
						'value'       => array(
							__( 'Grid', 'lb-reviews' )   => 'grid',
							__( 'Slider', 'lb-reviews' ) => 'slider',
						),
						'std'         => 'grid',
						'admin_label' => true,
					),
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'List', 'lb-reviews' ),
						'param_name'  => 'list',
						'value'       => $lists,
						'std'         => '',
						'admin_label' => true,
						'description' => __( 'Create lists in LB Reviews → Lists and tick them on the reviews.', 'lb-reviews' ),
					),
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Specific reviews (IDs)', 'lb-reviews' ),
						'param_name'  => 'ids',
						'description' => __( 'Optional, e.g. 812,790,775 – shown in this order. IDs are in the reviews table.', 'lb-reviews' ),
					),
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Source', 'lb-reviews' ),
						'param_name'  => 'source',
						'value'       => array(
							__( 'Google + Trustpilot', 'lb-reviews' ) => 'all',
							'Google'                                  => 'google',
							'Trustpilot'                              => 'trustpilot',
						),
						'std'         => 'all',
						'admin_label' => true,
					),
					array(
						'type'       => 'textfield',
						'heading'    => __( 'Maximum number of reviews (0 = all)', 'lb-reviews' ),
						'param_name' => 'limit',
						'value'      => '12',
					),
					array(
						'type'        => 'textfield',
						'heading'     => __( '"Show more" button: reviews shown at first', 'lb-reviews' ),
						'param_name'  => 'per_page',
						'value'       => '0',
						'description' => __( 'E.g. 6 = show 6 reviews, then a “Show more reviews” button loads 6 more each click. 0 = show all at once. Grid layout only.', 'lb-reviews' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Order', 'lb-reviews' ),
						'param_name' => 'order',
						'value'      => array(
							__( 'Newest first', 'lb-reviews' )  => 'date',
							__( 'Highest rating', 'lb-reviews' ) => 'rating',
							__( 'Random', 'lb-reviews' )        => 'random',
							__( 'As typed in the IDs field', 'lb-reviews' ) => 'manual',
						),
						'std'        => 'date',
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Minimum rating', 'lb-reviews' ),
						'param_name' => 'min_rating',
						'value'      => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5' ),
						'std'        => '1',
					),
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Languages (e.g. en or en,it – empty = all)', 'lb-reviews' ),
						'param_name'  => 'lang',
						'description' => __( 'Useful for the future ES / DE / IT versions of the site.', 'lb-reviews' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Columns / reviews per view (desktop)', 'lb-reviews' ),
						'param_name' => 'columns',
						'value'      => array( '1' => '1', '2' => '2', '3' => '3', '4' => '4' ),
						'std'        => '3',
						'group'      => $group_display,
					),
					array(
						'type'       => 'textfield',
						'heading'     => __( 'Text lines per review before "Read more"', 'lb-reviews' ),
						'description' => __( 'Cuts long texts inside each card. It does not change how many reviews are shown.', 'lb-reviews' ),
						'param_name' => 'lines',
						'value'      => '6',
						'group'      => $group_display,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Summary (average + total)', 'lb-reviews' ),
						'param_name' => 'summary',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_display,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( '"Leave a review" links', 'lb-reviews' ),
						'param_name' => 'cta',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_display,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Hide reviews without text', 'lb-reviews' ),
						'param_name' => 'only_text',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_display,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Avatar', 'lb-reviews' ),
						'param_name' => 'show_avatar',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_display,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Date', 'lb-reviews' ),
						'param_name' => 'show_date',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_display,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Source badge', 'lb-reviews' ),
						'param_name' => 'show_badge',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_display,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Owner replies', 'lb-reviews' ),
						'param_name' => 'show_reply',
						'value'      => array_reverse( $yes_no, true ),
						'std'        => 'no',
						'group'      => $group_display,
					),
					array(
						'type'       => 'textfield',
						'heading'    => __( 'Extra CSS class', 'lb-reviews' ),
						'param_name' => 'class',
						'group'      => $group_display,
					),
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Autoplay (seconds, 0 = off)', 'lb-reviews' ),
						'param_name'  => 'autoplay',
						'value'       => '0',
						'group'       => $group_slider,
						'dependency'  => $slider_dep,
						'description' => __( 'Pauses on hover and focus; off for visitors who prefer reduced motion.', 'lb-reviews' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Arrows', 'lb-reviews' ),
						'param_name' => 'arrows',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_slider,
						'dependency' => $slider_dep,
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Dots', 'lb-reviews' ),
						'param_name' => 'dots',
						'value'      => $yes_no,
						'std'        => 'yes',
						'group'      => $group_slider,
						'dependency' => $slider_dep,
					),
				),
			)
		);

		vc_map(
			array(
				'name'        => __( 'LB Review button', 'lb-reviews' ),
				'base'        => 'lb_reviews_button',
				'icon'        => 'dashicons dashicons-star-half',
				'category'    => __( 'Content', 'lb-reviews' ),
				'description' => __( '“Leave a review” / “Read all reviews” link', 'lb-reviews' ),
				'params'      => array(
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Platform', 'lb-reviews' ),
						'param_name'  => 'source',
						'value'       => array(
							'Google'     => 'google',
							'Trustpilot' => 'trustpilot',
						),
						'std'         => 'google',
						'admin_label' => true,
					),
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Link', 'lb-reviews' ),
						'param_name'  => 'type',
						'value'       => array(
							__( 'Leave a review', 'lb-reviews' )   => 'write',
							__( 'Read all reviews', 'lb-reviews' ) => 'read',
						),
						'std'         => 'write',
						'admin_label' => true,
					),
					array(
						'type'        => 'textfield',
						'heading'     => __( 'Label (optional)', 'lb-reviews' ),
						'param_name'  => 'label',
						'description' => __( 'Empty = “Leave a review on Google”.', 'lb-reviews' ),
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Style', 'lb-reviews' ),
						'param_name' => 'style',
						'value'      => array(
							__( 'Outline', 'lb-reviews' )        => 'outline',
							__( 'Solid', 'lb-reviews' )          => 'solid',
							__( 'Underlined link', 'lb-reviews' ) => 'link',
						),
						'std'        => 'outline',
					),
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Alignment', 'lb-reviews' ),
						'param_name' => 'align',
						'value'      => array(
							__( 'Center', 'lb-reviews' ) => 'center',
							__( 'Left', 'lb-reviews' )   => 'left',
							__( 'Right', 'lb-reviews' )  => 'right',
						),
						'std'        => 'center',
					),
				),
			)
		);
	}
}
