<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Frontend
 */

namespace Seedcast\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta description, Open Graph and Twitter Card tags.
 *
 * The tag set is the same everywhere. Only the title, description and image
 * differ, and the caller supplies those.
 */
final class Meta {

	/**
	 * Is an SEO plugin already writing head tags?
	 *
	 * If so, stay out of its way. Two sets of Open Graph tags is worse than
	 * one, and the SEO plugin is the one they chose.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active(): bool {
		$active = defined( 'WPSEO_VERSION' )            // Yoast SEO.
			|| defined( 'RANK_MATH_VERSION' )           // Rank Math.
			|| defined( 'AIOSEOP_VERSION' )             // All in One SEO.
			|| class_exists( 'AIOSEOP_Core' )
			|| defined( 'SQ_VERSION' )                  // Squirrly SEO.
			|| defined( 'SEOPRESS_VERSION' );           // SEOPress.

		/**
		 * Filter whether an SEO plugin is considered to be handling head output.
		 *
		 * @param bool $active Detection result.
		 */
		return (bool) apply_filters( 'seedcast/seo_plugin_active', $active );
	}

	/**
	 * Print the meta description, Open Graph and Twitter Card tags.
	 *
	 * Callers are responsible for deciding whether this page is theirs and for
	 * checking seo_plugin_active(). This method just prints.
	 *
	 * @param array $args {
	 *   @type string $title       Page title, without the site name.
	 *   @type string $description Plain text description. Omitted if empty.
	 *   @type string $image       Absolute image URL. Omitted if empty.
	 *   @type string $url         Canonical URL.
	 *   @type string $type        Open Graph type. Default 'article'.
	 *   @type bool   $canonical   Emit a canonical link. Default true.
	 * }
	 * @return void
	 */
	public static function render( array $args ): void {
		$args = wp_parse_args(
			$args,
			array(
				'title'       => '',
				'description' => '',
				'image'       => '',
				'url'         => '',
				'type'        => 'article',
				'canonical'   => true,
			)
		);

		/**
		 * Filter the tag values before output, so a plugin can override any
		 * single field without reimplementing the block.
		 *
		 * @param array $args Tag values.
		 */
		$args = (array) apply_filters( 'seedcast/meta_tags', $args );

		$site_name  = get_bloginfo( 'name' );
		$full_title = '' !== $args['title'] ? $args['title'] . ' | ' . $site_name : $site_name;
		$desc       = (string) $args['description'];
		$image      = (string) $args['image'];
		$url        = (string) $args['url'];

		if ( '' !== $desc ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
		}

		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $args['type'] ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $full_title ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( $site_name ) );

		if ( '' !== $desc ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( '' !== $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
			echo '<meta property="og:image:width" content="1200" />' . "\n";
			echo '<meta property="og:image:height" content="630" />' . "\n";
		}

		printf(
			'<meta name="twitter:card" content="%s" />' . "\n",
			esc_attr( '' !== $image ? 'summary_large_image' : 'summary' )
		);
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $full_title ) );

		if ( '' !== $desc ) {
			printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( '' !== $image ) {
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
		}

		if ( $args['canonical'] && '' !== $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}

}
