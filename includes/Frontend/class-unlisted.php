<?php
/**
 * Sermons kept out of the way without being lost.
 *
 * A church sometimes wants a sermon gone from the lists: a guest who asked not
 * to be featured, a series being reworked, a recording with a problem in it.
 * Unpublishing does that, and also takes the page down, breaks anybody's link
 * to it, and drops it out of the sitemap. What is wanted is quieter than that.
 *
 * So an unlisted sermon stays published. Its page works, its address keeps
 * working, and search engines keep it. It simply stops appearing in the lists,
 * and the full index still carries it, which is what stops out of the way
 * turning into lost.
 *
 * Done as a filter on the queries rather than a status, because a status that
 * is not `publish` would be excluded from the sitemap by WordPress itself, and
 * dropping out of the sitemap is precisely the thing being avoided.
 *
 * @package SeedcastSermonLibrary
 */

namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

class Unlisted {

	/**
	 * The flag on the sermon.
	 */
	const META = '_scsl_unlisted';

	/**
	 * Ask a query to carry unlisted sermons anyway.
	 *
	 * Set by the full index, which exists to show everything.
	 */
	const INCLUDE_ARG = 'scsl_include_unlisted';

	public function init(): void {
		add_action( 'pre_get_posts', [ $this, 'exclude' ] );
	}

	/**
	 * Leave unlisted sermons out of the lists.
	 *
	 * @param \WP_Query $query The query about to run.
	 * @return void
	 */
	public function exclude( $query ): void {
		/*
		 * An ajax request is not an admin screen, whatever is_admin says.
		 *
		 * The sermon lists fetch through admin-ajax.php, where is_admin() is
		 * true, so bailing on it skipped exactly the queries this exists to
		 * filter. The setting looked like it did nothing because on the page
		 * it was asked about, it never ran.
		 */
		if ( is_admin() && ! wp_doing_ajax() ) return;

		/*
		 * post_type is a string or an array, depending on who built the query.
		 *
		 * Casting it to a string to compare turned every array into the word
		 * "Array", which never matched, so the filter quietly did nothing on
		 * those queries and PHP logged a conversion warning for each one. On a
		 * busy site that was several warnings a second.
		 *
		 * A query for sermons alone is filtered however it was spelled. A
		 * query for sermons alongside other post types is left alone: the
		 * condition below is a meta_query, and meta conditions apply to every
		 * row a query returns, so filtering a mixed query would also drop
		 * pages and posts that simply have no such meta.
		 */
		$types = array_values( array_filter( (array) $query->get( 'post_type' ) ) );

		if ( [ 'scsl_sermon' ] !== $types ) return;

		/*
		 * Never a single sermon.
		 *
		 * The whole point is that the page still works. Excluding it here
		 * would turn an unlisted sermon into a 404, which is unpublishing by
		 * another name and worse, because nothing in the wording warned that
		 * it would happen.
		 */
		if ( $query->is_singular() ) return;

		if ( $query->get( self::INCLUDE_ARG ) ) return;

		$meta = (array) $query->get( 'meta_query' );

		/*
		 * The two conditions go in a group of their own.
		 *
		 * Putting relation OR at the top level applied it to every condition
		 * already there, so an unlisted sermon that matched anything else
		 * stayed in the list. The list has its own conditions, so this was
		 * every unlisted sermon: the setting appeared to do nothing at all.
		 *
		 * Nested, the group says "listed" as one condition and joins whatever
		 * else the list asks for.
		 */
		$meta[] = [
			'relation' => 'OR',

			// Never marked, so there is no row to compare.
			[
				'key'     => self::META,
				'compare' => 'NOT EXISTS',
			],

			// Marked once and unmarked since, which stores a zero rather than
			// removing the row.
			[
				'key'     => self::META,
				'value'   => '1',
				'compare' => '!=',
			],
		];

		$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	/**
	 * Whether a sermon is being kept out of the lists.
	 *
	 * @param int $post_id Sermon ID.
	 * @return bool
	 */
	public static function is_unlisted( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::META, true );
	}
}
