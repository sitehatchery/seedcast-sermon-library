<?php
namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Falls back when a sermon has no featured image of its own.
 *
 * Order is sermon, then the series it belongs to, then the site-wide default.
 * Hooking `post_thumbnail_id` rather than patching templates means every path
 * benefits at once: single sermon pages, archives, all five shortcodes,
 * structured data and the audio feed. It also means `has_post_thumbnail()`
 * answers truthfully, which templates already branch on.
 *
 * Front end only. In the admin the featured image box should show what is
 * genuinely set on that post, not an inherited one, or people end up unable to
 * tell whether they have chosen an image.
 */
class ImageFallback {

	public function init(): void {
		add_filter( 'post_thumbnail_id', [ $this, 'resolve' ], 10, 2 );
	}

	/**
	 * @param int|false           $thumbnail_id Existing thumbnail ID.
	 * @param int|\WP_Post|null   $post         Post or ID.
	 * @return int|false
	 */
	public function resolve( $thumbnail_id, $post ) {
		if ( $thumbnail_id || is_admin() ) {
			return $thumbnail_id;
		}

		$post = get_post( $post );
		if ( ! $post || 'scsl_sermon' !== $post->post_type ) {
			return $thumbnail_id;
		}

		if ( get_option( 'scsl_image_fallback_series', '1' ) === '1' ) {
			$series_id = absint( get_post_meta( $post->ID, '_scsl_series_id', true ) );
			if ( $series_id ) {
				// get_post_thumbnail_id() would re-enter this filter for the
				// series post, so read the meta directly.
				$series_thumb = absint( get_post_meta( $series_id, '_thumbnail_id', true ) );
				if ( $series_thumb ) {
					return $series_thumb;
				}
			}
		}

		$default = absint( get_option( 'scsl_default_image', 0 ) );

		return $default ?: $thumbnail_id;
	}
}
