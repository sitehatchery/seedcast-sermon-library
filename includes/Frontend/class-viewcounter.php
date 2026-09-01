<?php
namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tracks sermon view counts.
 * Counted in the browser rather than in PHP, so a cached page still reports a
 * view and a crawler that never runs scripts does not. Deduplicated per reader
 * per hour on the server, which is the part the browser cannot be trusted with.
 * Exposes view count in admin column and via get_post_meta( $id, '_scsl_view_count' ).
 */
class ViewCounter {

	public function init(): void {
		add_action( 'wp',                          [ $this, 'maybe_count_view' ] );
		add_action( 'wp_ajax_scsl_track_view',       [ $this, 'ajax_track'       ] );
		add_action( 'wp_ajax_nopriv_scsl_track_view',[ $this, 'ajax_track'       ] );
	}

	/**
	 * On single sermon pages, enqueue a lightweight JS call to track the view.
	 * Using AJAX rather than direct PHP so the count isn't incremented by
	 * search engine crawlers, page caches, or admin previews.
	 */
	public function maybe_count_view(): void {
		if ( ! is_singular( 'scsl_sermon' ) ) return;
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) return; // Don't count admin/editor views

		$post_id = get_queried_object_id();

		// No nonce. It would be generated here and baked into the page, and
		// on a cached site that page is served for far longer than a nonce
		// lasts. Every view after that fails verification and is thrown away,
		// so counting works until the cache warms up and then quietly stops.
		// Nothing here is worth protecting with one: the worst somebody can
		// do by forging a request is inflate a number, and the check below
		// limits that better than a nonce would.
		wp_localize_script( 'scsl-frontend', 'scslViewData', [
			'postId'  => $post_id,
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		] );
		wp_add_inline_script( 'scsl-frontend', <<<'JS'
( function() {
	if ( typeof scslViewData === 'undefined' ) return;
	var key = 'scsl_viewed_' + scslViewData.postId;
	if ( sessionStorage.getItem( key ) ) return;
	sessionStorage.setItem( key, '1' );
	fetch( scslViewData.ajaxUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: 'action=scsl_track_view&post_id=' + scslViewData.postId
	} );
} )();
JS
		);
	}

	public function ajax_track(): void {
		// No nonce, deliberately. One is written into the page, and caching
		// serves that page for far longer than a nonce lasts, so every view
		// would be silently discarded once the cache outlived it. Nothing here
		// is worth protecting with one either: a forged request can only
		// inflate a counter, and the once per reader check below limits that
		// better than a nonce would.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		if ( ! $post_id ) wp_send_json_error();

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'scsl_sermon' ) wp_send_json_error();

		if ( 'publish' !== $post->post_status ) wp_send_json_error();

		// One per reader per sermon per hour, decided here rather than in the
		// browser. Session storage is easily cleared and easily skipped, so on
		// its own it counts a refresh in a new tab as a new reader.
		$who = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'scsl_v_' . md5( $who . '|' . $post_id );

		if ( get_transient( $key ) ) wp_send_json_success( [ 'counted' => false ] );

		set_transient( $key, 1, HOUR_IN_SECONDS );

		$count = absint( get_post_meta( $post_id, '_scsl_view_count', true ) );
		update_post_meta( $post_id, '_scsl_view_count', $count + 1 );

		wp_send_json_success( [ 'count' => $count + 1 ] );
	}

	/**
	 * Get the formatted view count for a sermon.
	 */
	public static function get_count( int $post_id ): int {
		return absint( get_post_meta( $post_id, '_scsl_view_count', true ) );
	}
}
