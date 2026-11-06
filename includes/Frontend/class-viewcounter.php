<?php
namespace SeedcastSermonLibrary\Frontend;

use Seedcast\Core\Counters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts views of sermons, topic pages and passage pages.
 *
 * Counted in the browser rather than in PHP, so a page served from a cache
 * still reports a view and a crawler that never runs scripts does not. The
 * ones that do run scripts are filtered by user agent on the server, and a
 * reader is counted once per page per hour, decided on the server because the
 * browser cannot be trusted with it.
 *
 * Every view goes to the library's daily counters as entity 'sermon', 'topic'
 * or 'passage' with metric 'view', keyed by post id or term id. Sermons also
 * keep the running total in _scsl_view_count, which the admin column and the
 * "most viewed" ordering read.
 */
class ViewCounter {

	/**
	 * Counted entity types, and the post type or taxonomy each one is.
	 */
	private const TYPES = [
		'sermon'  => [ 'post', 'scsl_sermon' ],
		'topic'   => [ 'term', 'scsl_topic' ],
		'passage' => [ 'term', 'scsl_scripture' ],
	];

	public function init(): void {
		// After the plugin enqueues scsl-frontend at the default priority. The
		// beacon is attached to that script, and attaching to a handle that is
		// not registered yet does nothing at all, silently. This used to run
		// at 'wp', which is exactly that, so no view was ever counted.
		add_action( 'wp_enqueue_scripts',             [ $this, 'maybe_count_view' ], 20 );
		add_action( 'wp_ajax_scsl_track_view',        [ $this, 'ajax_track' ] );
		add_action( 'wp_ajax_nopriv_scsl_track_view', [ $this, 'ajax_track' ] );
	}

	/**
	 * What the current page is a view of, if anything we count.
	 *
	 * @return array{0:string,1:int}|null Entity type and id.
	 */
	private function current_target(): ?array {
		$id = get_queried_object_id();
		if ( ! $id ) {
			return null;
		}

		if ( is_singular( 'scsl_sermon' ) ) {
			return [ 'sermon', $id ];
		}
		if ( is_tax( 'scsl_topic' ) ) {
			return [ 'topic', $id ];
		}
		if ( is_tax( 'scsl_scripture' ) ) {
			return [ 'passage', $id ];
		}

		return null;
	}

	/**
	 * On a sermon, topic or passage page, attach a small script that reports
	 * the view once the page has loaded in a real browser.
	 */
	public function maybe_count_view(): void {
		$target = $this->current_target();
		if ( null === $target ) {
			return;
		}
		if ( $this->is_staff() ) {
			return;
		}
		if ( ! wp_script_is( 'scsl-frontend', 'enqueued' ) ) {
			return;
		}

		// No nonce. It would be baked into the page, and a cached page is
		// served for far longer than a nonce lasts, so counting would work
		// until the cache warmed up and then quietly stop. A forged request
		// can only inflate a number, and the per reader check limits that
		// better than a nonce would.
		wp_localize_script( 'scsl-frontend', 'scslViewData', [
			'type'    => $target[0],
			'id'      => $target[1],
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		] );
		wp_add_inline_script( 'scsl-frontend', <<<'JS'
( function() {
	if ( typeof scslViewData === 'undefined' || ! window.fetch ) return;
	var key = 'scsl_viewed_' + scslViewData.type + '_' + scslViewData.id;
	try {
		if ( sessionStorage.getItem( key ) ) return;
		sessionStorage.setItem( key, '1' );
	} catch ( e ) {}
	fetch( scslViewData.ajaxUrl, {
		method: 'POST',
		keepalive: true,
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: 'action=scsl_track_view&type=' + encodeURIComponent( scslViewData.type ) + '&id=' + encodeURIComponent( scslViewData.id )
	} ).catch( function() {} );
} )();
JS
		);
	}

	public function ajax_track(): void {
		// No nonce, deliberately: see maybe_count_view().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'sermon';
		// Pages cached before topics and passages were counted still send
		// post_id, and every one of those is a sermon.
		$raw_id = $_POST['id'] ?? ( $_POST['post_id'] ?? 0 );
		$id     = absint( wp_unslash( $raw_id ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $id || ! isset( self::TYPES[ $type ] ) ) {
			wp_send_json_error();
		}

		if ( $this->is_staff() || $this->is_crawler() ) {
			wp_send_json_success( [ 'counted' => false ] );
		}

		if ( ! $this->exists( $type, $id ) ) {
			wp_send_json_error();
		}

		// Once per reader per page per hour. The address is only ever an
		// input to a salted hash that names a transient, and the transient
		// holds nothing but a 1 and expires within the hour.
		$who = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'scsl_v_' . wp_hash( $who . '|' . $type . '|' . $id );

		if ( get_transient( $key ) ) {
			wp_send_json_success( [ 'counted' => false ] );
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );

		if ( 'sermon' === $type ) {
			$count = absint( get_post_meta( $id, '_scsl_view_count', true ) );
			update_post_meta( $id, '_scsl_view_count', $count + 1 );
		}

		if ( class_exists( Counters::class ) ) {
			Counters::record( $type, $id, 'view' );
			// Written now rather than at shutdown, so the count does not
			// depend on how this request ends.
			Counters::flush();
		}

		wp_send_json_success( [ 'counted' => true ] );
	}

	/**
	 * Whether the entity is something a visitor could have been looking at.
	 */
	private function exists( string $type, int $id ): bool {
		[ $kind, $name ] = self::TYPES[ $type ];

		if ( 'post' === $kind ) {
			$post = get_post( $id );
			return $post && $name === $post->post_type && 'publish' === $post->post_status;
		}

		$term = get_term( $id, $name );
		return $term instanceof \WP_Term;
	}

	/**
	 * Editors looking at their own site are not readers.
	 */
	private function is_staff(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * Crawlers that run scripts still announce themselves in the user agent.
	 */
	private function is_crawler(): bool {
		if ( class_exists( Counters::class ) && method_exists( Counters::class, 'is_crawler' ) ) {
			return Counters::is_crawler();
		}
		return false;
	}

	/**
	 * Get the running view count for a sermon.
	 */
	public static function get_count( int $post_id ): int {
		return absint( get_post_meta( $post_id, '_scsl_view_count', true ) );
	}
}
