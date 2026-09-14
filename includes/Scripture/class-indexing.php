<?php
namespace SeedcastSermonLibrary\Scripture;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keep genuinely empty scripture pages out of the index.
 *
 * A passage page is not thin because few sermons name that exact verse. It also
 * carries the articles written out of those sermons and the rest of the book
 * beneath them, which on a well covered book runs to several hundred words and
 * a dozen sermons. Those pages belong in the index, and people do search for
 * particular verses.
 *
 * What is genuinely thin is a passage in a book the church has barely touched,
 * where the section meant to fill the page has nothing to put in it either.
 *
 * So this is a rule rather than a list. A book gains sermons over time, and a
 * page that grows past the threshold becomes indexable again without anybody
 * remembering to come back and change it. A hardcoded list of term ids would
 * quietly suppress pages that had since become worth reading.
 */
class Indexing {

	/**
	 * Fewest sermons a book needs before its passage pages are worth indexing.
	 */
	private const MIN_BOOK_SERMONS = 3;

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
	 * Whether the page being rendered is a thin passage page.
	 *
	 * @return bool
	 */
	private static function current_is_thin(): bool {
		if ( ! is_tax( 'scsl_scripture' ) ) {
			return false;
		}

		$term = get_queried_object();

		return ( $term instanceof \WP_Term && self::is_thin( $term ) );
	}

	/**
	 * Yoast's robots array, keyed by directive type.
	 *
	 * Follow is kept: the sermons linked from these pages are worth crawling
	 * even when the page itself is not worth listing.
	 *
	 * @param array $robots
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
	 * @param array $robots
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
	 * Term ids Yoast should leave out of the sitemap.
	 *
	 * @param array $excluded
	 * @return array
	 */
	public static function exclude_from_sitemap( $excluded ) {
		global $wpdb;

		/*
		 * One query, not one per term.
		 *
		 * Asking is_thin() about every term walked the tree and looked up a
		 * book for each of them, which on a couple of thousand references is
		 * thousands of queries every time a sitemap is built. Sitemaps are
		 * fetched by crawlers, repeatedly, so that was enough to hold a request
		 * open long enough for the edge to give up on it.
		 */
		$min = (int) apply_filters( 'scsl_min_book_sermons_for_index', self::MIN_BOOK_SERMONS, null );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One query in place of thousands, as above; no API selects terms by the sermon count of their book.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT tt.term_id
			   FROM {$wpdb->term_taxonomy} tt
			   LEFT JOIN {$wpdb->term_taxonomy} chapter
				 ON chapter.term_id = tt.parent AND chapter.taxonomy = 'scsl_scripture'
			   LEFT JOIN {$wpdb->term_taxonomy} book
				 ON book.term_id = COALESCE( NULLIF( chapter.parent, 0 ), tt.parent )
				AND book.taxonomy = 'scsl_scripture'
			  WHERE tt.taxonomy = 'scsl_scripture'
				AND NOT EXISTS (
					  SELECT 1 FROM {$wpdb->term_taxonomy} child
					   WHERE child.taxonomy = 'scsl_scripture'
						 AND child.parent = tt.term_id
					)
				AND COALESCE( book.count, 0 ) < %d",
			$min
		) );

		foreach ( (array) $ids as $id ) {
			$excluded[] = (int) $id;
		}

		return $excluded;
	}

	/**
	 * Whether a passage page would have too little on it to be worth indexing.
	 *
	 * @return bool
	 */
	public static function is_thin( \WP_Term $term ): bool {
		$id = (int) $term->term_id;

		if ( isset( self::$thin[ $id ] ) ) {
			return self::$thin[ $id ];
		}

		// A book or chapter hub is never thin on its own account: it carries
		// everything beneath it.
		$children = get_term_children( $id, 'scsl_scripture' );

		if ( ! is_wp_error( $children ) && $children ) {
			return self::$thin[ $id ] = false;
		}

		$book = ScriptureParser::extract_book( (string) $term->name );

		// Not a passage at all, such as an import artefact. Nothing to show.
		if ( null === $book ) {
			return self::$thin[ $id ] = true;
		}

		$book_term = get_term_by( 'name', $book, 'scsl_scripture' );
		$count     = ( $book_term instanceof \WP_Term ) ? (int) $book_term->count : 0;

		/**
		 * How many sermons a book needs before its passage pages are indexed.
		 *
		 * @param int      $min  Default threshold.
		 * @param \WP_Term $term The passage being judged.
		 */
		$min = (int) apply_filters( 'scsl_min_book_sermons_for_index', self::MIN_BOOK_SERMONS, $term );

		return self::$thin[ $id ] = ( $count < $min );
	}
}
