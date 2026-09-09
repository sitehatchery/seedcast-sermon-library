<?php
namespace SeedcastSermonLibrary\Frontend;

use SeedcastSermonLibrary\Scripture\ScriptureParser;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Send old sermon and passage addresses to where the content lives now.
 *
 * Two shapes of dead link accumulate around a sermon library, and both are
 * mechanical, which is why this is a rule rather than a list somebody has to
 * keep adding to.
 *
 * The first is the address of whatever plugin the church used before. Those
 * URLs carry the same slug the sermon still has, often with a query string the
 * old plugin used for paging, so they can be matched by shape.
 *
 * The second is a slug written before the site put a separator between chapter
 * and verse: /scripture/john-844/ for what is now /scripture/john-8-44/. The
 * ambiguity that makes this hard to express as a pattern, whether 844 means
 * 8:44 or 84:4, disappears once you compare against the passages that actually
 * exist. Removing every separator from both sides and comparing what is left
 * gives exactly one answer.
 *
 * Only runs on a request that has already failed, so it costs nothing on a page
 * that resolves, and it cannot shadow a real page.
 */
class LegacyRedirects {

	/**
	 * Paths this class knows how to rescue, and the method that does it.
	 *
	 * The key is matched against the first segment of the request path.
	 *
	 * @var array<string, string>
	 */
	private const HANDLERS = [
		'messages'  => 'sermon_for',
		'sermon'    => 'sermon_for',
		'scripture' => 'passage_for',
		'topic'     => 'topic_for',
	];

	/**
	 * Prefixes whose feed addresses should go to the page instead.
	 *
	 * Turning feeds off at register_taxonomy removes the taxonomy's own feed
	 * routes, but WordPress has a general rule that catches anything ending in
	 * /feed/ and treats the rest as a page name. That answers with an empty
	 * feed and a 200, which reads to a crawler as a real document. Sermons are
	 * deliberately absent: a sermon feed is a thing a church might actually
	 * publish.
	 *
	 * @var string[]
	 */
	private const NO_FEED = [ 'scripture', 'topic' ];

	/**
	 * @return void
	 */
	public static function init(): void {
		// Early, so nothing else has begun rendering the 404 page.
		add_action( 'template_redirect', [ self::class, 'maybe_redirect' ], 1 );
	}

	/**
	 * Look at a failed request and send it somewhere real, if we can.
	 *
	 * @return void
	 */
	public static function maybe_redirect(): void {
		$is_dead_feed = is_feed() && self::feed_should_go_to_page();

		if ( ! is_404() && ! $is_dead_feed ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed and matched below, never echoed.
		$path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );

		if ( '' === $path ) {
			return;
		}

		$parts = explode( '/', $path );
		$first = strtolower( $parts[0] );
		$slug  = isset( $parts[1] ) ? $parts[1] : '';

		if ( ! isset( self::HANDLERS[ $first ] ) || '' === $slug ) {
			return;
		}

		$method = self::HANDLERS[ $first ];
		$target = self::$method( sanitize_title( urldecode( $slug ) ) );

		if ( '' === $target ) {
			return;
		}

		// A redirect to the address we are already on is a loop.
		if ( untrailingslashit( $target ) === untrailingslashit( home_url( $path ) ) ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Whether this request is a feed under a prefix that has no feeds.
	 *
	 * @return bool
	 */
	private static function feed_should_go_to_page(): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched against a fixed list.
		$path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
		$first = strtolower( (string) strtok( $path, '/' ) );

		return in_array( $first, self::NO_FEED, true );
	}

	/**
	 * The sermon a legacy slug refers to.
	 *
	 * @return string Permalink, or an empty string.
	 */
	private static function sermon_for( string $slug ): string {
		$post = get_page_by_path( $slug, OBJECT, 'scsl_sermon' );

		if ( ! $post ) {
			$post = self::by_loose_slug( $slug );
		}

		if ( ! $post || 'publish' !== get_post_status( $post ) ) {
			return '';
		}

		return (string) get_permalink( $post );
	}

	/**
	 * A sermon whose slug differs only in its separators.
	 *
	 * Covers the apostrophe difference as well as the missing chapter break,
	 * since "elijah-s-prayer" and "elijahs-prayer" are the same once every
	 * dash is removed.
	 *
	 * @return \WP_Post|null
	 */
	private static function by_loose_slug( string $slug ): ?\WP_Post {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			  WHERE post_type = 'scsl_sermon'
				AND post_status = 'publish'
				AND REPLACE( post_name, '-', '' ) = REPLACE( %s, '-', '' )
			  LIMIT 1",
			$slug
		) );

		return $id ? get_post( (int) $id ) : null;
	}

	/**
	 * The topic archive a slug refers to.
	 *
	 * A live topic page resolves on its own and never gets this far, so this is
	 * reached in two cases: a feed address under /topic/, and a topic that has
	 * since been deleted.
	 *
	 * @return string Term link, or an empty string.
	 */
	private static function topic_for( string $slug ): string {
		$term = get_term_by( 'slug', $slug, 'scsl_topic' );

		if ( ! $term instanceof \WP_Term ) {
			return self::retired( 'scsl_topic', $slug );
		}

		$link = get_term_link( $term );

		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/**
	 * Where a term that no longer exists should send somebody.
	 *
	 * A church that reorganises its topics leaves the old addresses behind,
	 * and those are the ones search engines already know. Deleting a term
	 * turns them into 404s, which is a worse outcome than the untidy
	 * vocabulary that prompted the tidying.
	 *
	 * The map is the site's to supply, because only the site knows that its
	 * old "Hope & Encouragement" is now "Hope". Values may be a slug in the
	 * same taxonomy or a full address; anything unrecognised is ignored and
	 * the 404 stands, which is correct for a topic that had no successor and
	 * was not worth keeping.
	 *
	 * @return string Address, or an empty string.
	 */
	private static function retired( string $taxonomy, string $slug ): string {
		/*
		 * Kept as an option rather than in code, because the site that retires
		 * a term is the site that knows where it went, and the person doing it
		 * is reorganising a vocabulary rather than editing a plugin.
		 */
		$stored = get_option( 'scsl_retired_terms', [] );
		$stored = is_array( $stored ) && isset( $stored[ $taxonomy ] ) && is_array( $stored[ $taxonomy ] )
			? $stored[ $taxonomy ]
			: [];

		/**
		 * Retired terms and where they now point.
		 *
		 * @param array<string, string> $stored   Old slug to slug or URL.
		 * @param string                $taxonomy Taxonomy the slug was in.
		 */
		$map = (array) apply_filters( 'scsl_retired_terms', $stored, $taxonomy );

		if ( ! isset( $map[ $slug ] ) ) {
			return '';
		}

		$target = (string) $map[ $slug ];

		if ( '' === $target ) {
			return '';
		}

		// A full address is used as given; anything else is read as a slug in
		// the taxonomy the old term belonged to.
		if ( 0 === strpos( $target, 'http' ) || 0 === strpos( $target, '/' ) ) {
			return 0 === strpos( $target, '/' ) ? home_url( $target ) : $target;
		}

		$term = get_term_by( 'slug', $target, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$link = get_term_link( $term );

		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/**
	 * The passage a legacy slug refers to.
	 *
	 * @return string Term link, or an empty string.
	 */
	private static function passage_for( string $slug ): string {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT t.term_id
			   FROM {$wpdb->terms} t
			   JOIN {$wpdb->term_taxonomy} tt
				 ON tt.term_id = t.term_id AND tt.taxonomy = 'scsl_scripture'
			  WHERE REPLACE( t.slug, '-', '' ) = REPLACE( %s, '-', '' )
			  LIMIT 1",
			$slug
		) );

		if ( ! $id ) {
			return '';
		}

		$link = get_term_link( (int) $id, 'scsl_scripture' );

		return is_wp_error( $link ) ? '' : (string) $link;
	}
}
