<?php
namespace SeedcastSermonLibrary\Topics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keep thin topic pages out of the index, without hiding what they link to.
 *
 * A topic with one sermon on it is a page with one card, and a site that offers
 * a search engine two dozen of those is offering it two dozen near-empty pages
 * with the same furniture. Follow stays on: the sermon linked from the page is
 * worth crawling even when the page listing it is not worth listing.
 *
 * The rule is deliberately about the page rather than the subject. A topic that
 * is thin today fills out as the church preaches it, and the page starts being
 * indexed on its own, with no list to maintain anywhere.
 *
 * The same reasoning as scripture pages, which have their own version of this
 * because what makes one of those thin is the book behind it rather than the
 * count on the term.
 *
 * @package SeedcastSermonLibrary
 */
class Indexing {

	/**
	 * Fewest sermons a topic needs before its page is worth indexing.
	 *
	 * Two, because the page's own reason to exist is that it gathers sermons:
	 * one sermon is the sermon's page with a heading over it, and the sections
	 * that widen the page out need a second sermon before they have anything to
	 * work from either.
	 */
	private const MIN_SERMONS = 2;

	/**
	 * Cache of the decision per term, for the several times it is asked.
	 *
	 * @var array<int, bool>
	 */
	private static $thin = [];

	/**
	 * @return void
	 */
	public static function init(): void {
		/*
		 * Two filters, two shapes. Yoast keys its array by directive type
		 * ('index' => 'noindex'); core keys it by the directive itself
		 * ('noindex' => true). Feeding either shape to the other emits
		 * nonsense like "index:noindex", so they get separate handlers.
		 */
		add_filter( 'wpseo_robots_array', [ self::class, 'yoast_robots' ] );
		add_filter( 'wp_robots', [ self::class, 'core_robots' ] );

		// A noindexed page has no business in the sitemap either.
		add_filter( 'wpseo_exclude_from_sitemap_by_term_ids', [ self::class, 'exclude_from_sitemap' ] );
	}

	/**
	 * Whether the page being rendered is a thin topic page.
	 *
	 * @return bool
	 */
	private static function current_is_thin(): bool {
		if ( ! is_tax( 'scsl_topic' ) ) {
			return false;
		}

		$term = get_queried_object();

		return ( $term instanceof \WP_Term && self::is_thin( $term ) );
	}

	/**
	 * Yoast's robots array, keyed by directive type.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function yoast_robots( $robots ) {
		if ( ! self::current_is_thin() ) {
			return $robots;
		}

		$robots = (array) $robots;

		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';

		return $robots;
	}

	/**
	 * Core's robots array, keyed by the directive itself.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function core_robots( $robots ) {
		if ( ! self::current_is_thin() ) {
			return $robots;
		}

		$robots = (array) $robots;

		$robots['noindex'] = true;
		$robots['follow']  = true;

		unset( $robots['index'] );

		return $robots;
	}

	/**
	 * Keep the same pages out of Yoast's sitemap.
	 *
	 * Answered from the term counts WordPress already maintains, so this is one
	 * query rather than a walk of every topic.
	 *
	 * @param array $excluded Term ids already excluded.
	 * @return array
	 */
	public static function exclude_from_sitemap( $excluded ) {
		global $wpdb;

		$excluded = (array) $excluded;

		/** This filter is documented in includes/Topics/class-indexing.php */
		$min = (int) apply_filters( 'scsl_min_topic_sermons_for_index', self::MIN_SERMONS, null );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed query against term counts, in place of asking every term in turn; no API answers "every term under this count". Caching it would hold a topic out of the sitemap after it had earned its place, for a query a sitemap build runs once.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT tt.term_id
			   FROM {$wpdb->term_taxonomy} tt
			  WHERE tt.taxonomy = 'scsl_topic'
				AND tt.count < %d",
			$min
		) );

		foreach ( (array) $ids as $id ) {
			$excluded[] = (int) $id;
		}

		return $excluded;
	}

	/**
	 * Whether a topic page would have too little on it to be worth indexing.
	 *
	 * @param \WP_Term $term Topic term.
	 * @return bool
	 */
	public static function is_thin( \WP_Term $term ): bool {
		$id = (int) $term->term_id;

		if ( isset( self::$thin[ $id ] ) ) {
			return self::$thin[ $id ];
		}

		/**
		 * How many sermons a topic needs before its page is indexed.
		 *
		 * @param int           $min  Default threshold.
		 * @param \WP_Term|null $term The topic being judged, where one is in hand.
		 */
		$min = (int) apply_filters( 'scsl_min_topic_sermons_for_index', self::MIN_SERMONS, $term );

		return self::$thin[ $id ] = ( (int) $term->count < $min );
	}
}
