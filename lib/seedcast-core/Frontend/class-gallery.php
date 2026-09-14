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
 * Makes WordPress's own [gallery] work on the pages a Seedcast plugin asks for.
 *
 * Add Media > Create Gallery inserts a [gallery] shortcode, and what that
 * prints is left to the theme. A theme that declares HTML5 galleries switches
 * WordPress's own gallery CSS off, and plenty of those then style nothing, so
 * the photos stack full width. Clicking one leaves the page for a bare image,
 * because WordPress's lightbox belongs to the block editor's Image and Gallery
 * blocks and never reaches a [gallery].
 *
 * On the pages a plugin names, this lays a gallery out as a grid of even tiles
 * that follows its column setting, points each photo at its image file rather
 * than an attachment page, and opens photos in a lightbox with previous and
 * next.
 *
 * Nothing loads anywhere else, and on those pages only when there is a gallery
 * to show: a [gallery] in post_content, or photos a plugin prints elsewhere in
 * the same markup and reports through the seedcast/gallery/has_photos filter.
 *
 * Usage, from a plugin's init:
 *
 *     \Seedcast\Core\Frontend\Gallery::enable_for( array( 'scsl_sermon' ) );
 */
class Gallery {

	/**
	 * Handle for the gallery stylesheet and the lightbox script.
	 */
	public const HANDLE = 'seedcast-core-gallery';

	/**
	 * Body class on pages where this applies. The stylesheet is scoped to it,
	 * so nothing it says can reach another page, and two classes deep is
	 * enough to out-rank a page builder's `.elementor img`.
	 */
	public const BODY_CLASS = 'sc-gallery';

	/**
	 * Post types whose single pages get the fix.
	 *
	 * @var string[]
	 */
	private static $post_types = array();

	/**
	 * Whether the hooks are in place.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Switch the fix on for single pages of these post types. Safe to call
	 * from more than one plugin, and more than once.
	 *
	 * @param string[] $post_types Post type names.
	 * @return void
	 */
	public static function enable_for( array $post_types ): void {
		self::$post_types = array_values( array_unique( array_merge( self::$post_types, array_map( 'strval', $post_types ) ) ) );

		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_filter( 'use_default_gallery_style', array( __CLASS__, 'default_style' ) );
		add_filter( 'shortcode_atts_gallery', array( __CLASS__, 'link_to_files' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the current request is a single page of an enabled post type.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return ! empty( self::$post_types ) && is_singular( self::$post_types );
	}

	/**
	 * WordPress's own float-based gallery CSS gives way to the grid, so the
	 * two cannot fight over the same markup.
	 *
	 * @param bool $use Whether WordPress prints its default gallery styles.
	 * @return bool
	 */
	public static function default_style( $use ) {
		return self::applies() ? false : $use;
	}

	/**
	 * A gallery that would link each photo to its attachment page links to
	 * the image file instead, so the lightbox opens the full photo rather than
	 * a cropped thumbnail size, and anyone without JavaScript still gets the
	 * photo rather than a bare attachment page. A gallery set to link to
	 * nothing is left alone.
	 *
	 * @param array $out Gallery attributes after defaults.
	 * @return array
	 */
	public static function link_to_files( $out ) {
		if ( is_array( $out ) && self::applies() && 'none' !== ( $out['link'] ?? '' ) ) {
			$out['link'] = 'file';
		}
		return $out;
	}

	/**
	 * Mark the page, so the stylesheet applies here and nowhere else.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::applies() ) {
			$classes[] = self::BODY_CLASS;
		}
		return $classes;
	}

	/**
	 * The grid styles and the lightbox, only where there are photos to show.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::applies() ) {
			return;
		}

		$post       = get_post();
		$post       = $post instanceof \WP_Post ? $post : null;
		$has_photos = null !== $post && has_shortcode( (string) $post->post_content, 'gallery' );

		/**
		 * Whether this page has photos for the gallery styles and lightbox.
		 *
		 * True already when post_content holds a [gallery]. A plugin returns
		 * true when it prints one somewhere else, such as a custom field, or
		 * prints its own photo grid in WordPress's gallery markup.
		 *
		 * @param bool          $has_photos Whether post_content has a [gallery].
		 * @param \WP_Post|null $post       The page's post.
		 */
		if ( ! apply_filters( 'seedcast/gallery/has_photos', $has_photos, $post ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, SEEDCAST_CORE_URL . 'assets/css/seedcast-gallery.css', array(), SEEDCAST_CORE_VERSION );
		wp_enqueue_script( self::HANDLE, SEEDCAST_CORE_URL . 'assets/js/seedcast-lightbox.js', array(), SEEDCAST_CORE_VERSION, true );
		wp_localize_script(
			self::HANDLE,
			'seedcastLightbox',
			array(
				'label' => __( 'Image viewer', 'seedcast-sermon-library' ),
				'close' => __( 'Close', 'seedcast-sermon-library' ),
				'prev'  => __( 'Previous image', 'seedcast-sermon-library' ),
				'next'  => __( 'Next image', 'seedcast-sermon-library' ),
				/* translators: 1: this image's number, 2: how many images the gallery has. */
				'count' => __( '%1$s of %2$s', 'seedcast-sermon-library' ),
			)
		);
	}
}
