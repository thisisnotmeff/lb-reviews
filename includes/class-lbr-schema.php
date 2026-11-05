<?php
/**
 * Optional JSON-LD aggregateRating, attached to the existing business entity via @id.
 *
 * Off by default. Note: Google does not show review stars for a business's own reviews
 * on its own site (self-serving reviews), so this is mainly useful for entity consistency
 * and AI/answer engines. Keep it off if Rank Math already outputs an aggregateRating.
 */

defined( 'ABSPATH' ) || exit;

class LBR_Schema {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 30 );
	}

	public static function output() {
		$s = LBR_Settings::get();
		if ( ! $s['schema_enabled'] || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || false === strpos( $post->post_content, '[lb_reviews' ) ) {
			return;
		}

		$sum   = 0;
		$count = 0;
		foreach ( array( 'google', 'trustpilot' ) as $src ) {
			$st = LBR_Repository::stats( $src );
			if ( $st['count'] ) {
				$sum   += $st['rating'] * $st['count'];
				$count += $st['count'];
			}
		}
		if ( ! $count ) {
			return;
		}

		$node = array(
			'@context'        => 'https://schema.org',
			'@type'           => $s['schema_type'],
			'aggregateRating' => array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( $sum / $count, 1 ),
				'reviewCount' => $count,
				'bestRating'  => 5,
				'worstRating' => 1,
			),
		);
		if ( $s['schema_id'] ) {
			$node['@id'] = $s['schema_id'];
		}
		$node['name'] = $s['schema_name'] ? $s['schema_name'] : get_bloginfo( 'name' );
		$node['url']  = home_url( '/' );

		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
