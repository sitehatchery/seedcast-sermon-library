<?php
/**
 * Filter the sermon list by how complete each one is.
 *
 * The score is worked out from what is on a sermon each time it is asked for,
 * not stored anywhere, so there is nothing to hand to the database. Filtering
 * therefore means scoring the library and asking for the ones that matched,
 * which is fine at the size a church's library actually is and is held briefly
 * so that paging through results does not do it again.
 *
 * @package SeedcastSermonLibrary
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class CompletenessFilter {

	/**
	 * Where the scored library is kept between page loads.
	 */
	const CACHE = 'scsl_completeness_map';

	/**
	 * How long a scored library is trusted.
	 *
	 * Short. A church filtering for what is unfinished is usually in the
	 * middle of finishing things, and a list that still shows a sermon as
	 * incomplete minutes after it was completed is worse than a slightly
	 * slower page.
	 */
	const CACHE_FOR = 2 * MINUTE_IN_SECONDS;

	public function init(): void {
		add_action( 'restrict_manage_posts', [ $this, 'render' ] );
		add_action( 'pre_get_posts', [ $this, 'apply' ] );

		// Any change to a sermon can change its score, so the scored library
		// stops being true the moment one is saved.
		add_action( 'save_post_scsl_sermon', [ $this, 'forget' ] );
		add_action( 'deleted_post', [ $this, 'forget' ] );
	}

	/**
	 * The dropdown, above the list.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function render( $post_type ): void {
		if ( 'scsl_sermon' !== $post_type ) return;

		if ( '0' === (string) get_option( 'scsl_show_completeness', '1' ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a filter to draw it back, not acting on it.
		$current = isset( $_GET['scsl_completeness'] ) ? sanitize_key( wp_unslash( $_GET['scsl_completeness'] ) ) : '';

		$options = [
			'complete' => __( 'Complete', 'seedcast-sermon-library' ),
			'almost'   => __( 'Almost there', 'seedcast-sermon-library' ),
			'needs'    => __( 'Needs work', 'seedcast-sermon-library' ),
			'started'  => __( 'Just started', 'seedcast-sermon-library' ),
			'unlisted' => __( 'Unlisted', 'seedcast-sermon-library' ),
		];

		echo '<select name="scsl_completeness">';
		printf(
			'<option value="">%s</option>',
			esc_html__( 'All completeness', 'seedcast-sermon-library' )
		);

		foreach ( $options as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Narrow the list to the sermons that matched.
	 *
	 * @param \WP_Query $query The query about to run.
	 * @return void
	 */
	public function apply( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) return;

		if ( 'scsl_sermon' !== (string) $query->get( 'post_type' ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a filter, not acting on it.
		$want = isset( $_GET['scsl_completeness'] ) ? sanitize_key( wp_unslash( $_GET['scsl_completeness'] ) ) : '';

		if ( '' === $want ) return;

		$matched = $this->matching( $want );

		/*
		 * An empty result has to be said explicitly.
		 *
		 * Passing an empty list to post__in is ignored, which would show the
		 * whole library and read as the filter being broken. Nought is not a
		 * post id, so it matches nothing and says so.
		 */
		$query->set( 'post__in', $matched ?: [ 0 ] );
	}

	/**
	 * Sermon ids sitting in one band.
	 *
	 * @param string $want Tier key.
	 * @return int[]
	 */
	private function matching( string $want ): array {
		/*
		 * Unlisted is a flag, not a score.
		 *
		 * Scoring the library to find it would be work for an answer already
		 * written on each sermon, so it is asked for straight.
		 */
		if ( 'unlisted' === $want ) {
			return get_posts( [
				'post_type'      => 'scsl_sermon',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [ [ 'key' => '_scsl_unlisted', 'value' => '1' ] ],
			] );
		}

		$map = get_transient( self::CACHE );

		if ( ! is_array( $map ) ) {
			$map = $this->score_all();

			set_transient( self::CACHE, $map, self::CACHE_FOR );
		}

		return array_keys( array_filter( $map, static function ( $tier ) use ( $want ) {
			return $tier === $want;
		} ) );
	}

	/**
	 * Every sermon, and which band it is in.
	 *
	 * @return array<int, string>
	 */
	private function score_all(): array {
		$ids = get_posts( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$map = [];

		foreach ( $ids as $id ) {
			$score = \Seedcast\Core\Completeness::score_item( Completeness::SOURCE, (int) $id );

			// The comprehensive figure, so the filter agrees with the number
			// shown beside each sermon rather than quietly using a different
			// one and looking wrong.
			$percent = (int) ( $score['comprehensive'] ?? 0 );
			$tier    = \Seedcast\Core\Completeness::tier( $percent );

			$map[ (int) $id ] = (string) ( $tier['key'] ?? '' );
		}

		return $map;
	}

	/**
	 * Throw away the scored library.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_transient( self::CACHE );
	}
}
