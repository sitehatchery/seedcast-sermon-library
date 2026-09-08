<?php
namespace SeedcastSermonLibrary\Scripture;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cached prose summaries for a scripture page's set of sermons.
 *
 * A page that lists several sermons on one passage can say, in its own words,
 * what that set covers. That is writing the site does not otherwise have: the
 * sermons each speak for themselves, but nothing says what they add up to.
 *
 * Nothing here writes the summary. Generation is somebody else's job, reached
 * through the scsl_scripture_summary_generate filter, so the page works
 * unchanged with no service connected and a church that never wires one up
 * simply never sees this section.
 *
 * Storage is term meta rather than a table. The summary belongs to a term, it
 * is one row, and it needs no history, so a table would buy nothing and cost a
 * migration.
 *
 * Freshness is stale-while-revalidate: the stored copy is always what gets
 * served, and an expired one is handed back while regeneration happens on a
 * scheduled event. No visitor ever waits on a network call, and nobody is
 * shown a blank space where a summary used to be.
 */
class Summary {

	/** Meta key holding the prose. */
	private const META_TEXT = '_scsl_summary';

	/** Meta key holding the hash of the set the prose was written from. */
	private const META_HASH = '_scsl_summary_hash';

	/** Meta key holding the unix time the prose was last confirmed current. */
	private const META_TIME = '_scsl_summary_at';

	/** How long a summary stays fresh. */
	private const TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Fewest sermons worth summarising.
	 *
	 * Below this there is no set to describe, only a single sermon that already
	 * has its own page. Generating here would republish one sermon's substance
	 * at a second URL across the long tail of single-sermon passages, which is
	 * both worse for the reader and the shape of thing search engines penalise.
	 */
	public const MIN_SERMONS = 2;

	/**
	 * Register the regeneration hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'scsl_generate_scripture_summary', [ self::class, 'generate' ], 10, 2 );
	}

	/**
	 * The summary to show for a term, if there is one.
	 *
	 * @param int   $term_id    Scripture term.
	 * @param int[] $sermon_ids Sermons currently on the page.
	 * @return string Prose, or an empty string when there is nothing to show.
	 */
	public static function get( int $term_id, array $sermon_ids ): string {
		if ( count( $sermon_ids ) < self::MIN_SERMONS ) {
			return '';
		}

		$hash   = self::hash( $sermon_ids );
		$stored = (string) get_term_meta( $term_id, self::META_TEXT, true );
		$known  = (string) get_term_meta( $term_id, self::META_HASH, true );
		$at     = (int) get_term_meta( $term_id, self::META_TIME, true );

		$same_set = ( $known === $hash );
		$expired  = ( time() - $at ) > self::TTL;

		/*
		 * An unchanged set that has merely aged needs no new writing. Touch the
		 * timestamp so the check stops firing, and serve what is already there.
		 */
		if ( $stored && $same_set && $expired ) {
			update_term_meta( $term_id, self::META_TIME, time() );

			return $stored;
		}

		if ( $stored && $same_set ) {
			return $stored;
		}

		self::queue( $term_id, $sermon_ids );

		// A changed set still shows the old summary until the new one lands.
		return $stored;
	}

	/**
	 * Schedule regeneration, unless it is already scheduled.
	 *
	 * @param int[] $sermon_ids
	 * @return void
	 */
	private static function queue( int $term_id, array $sermon_ids ): void {
		$args = [ $term_id, $sermon_ids ];

		if ( wp_next_scheduled( 'scsl_generate_scripture_summary', $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, 'scsl_generate_scripture_summary', $args );
	}

	/**
	 * Write a summary for a term. Runs on the scheduled event, never in a page load.
	 *
	 * @param int[] $sermon_ids
	 * @return void
	 */
	public static function generate( int $term_id, array $sermon_ids ): void {
		$term = get_term( $term_id, 'scsl_scripture' );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$sources = [];

		foreach ( $sermon_ids as $sermon_id ) {
			$description = trim( (string) get_post_meta( (int) $sermon_id, '_scsl_content_description', true ) );

			if ( '' === $description ) {
				continue;
			}

			$sources[] = [
				'id'       => (int) $sermon_id,
				'title'    => get_the_title( (int) $sermon_id ),
				'passage'  => self::passages_for( (int) $sermon_id ),
				'abstract' => $description,
			];
		}

		if ( count( $sources ) < self::MIN_SERMONS ) {
			return;
		}

		/**
		 * Write the summary.
		 *
		 * Return a paragraph or two saying what this set of sermons covers as a
		 * body of teaching. Not a list of the sermons, which the page already
		 * shows, and not a restatement of any one of them.
		 *
		 * @param string     $summary  Empty by default, meaning no service is connected.
		 * @param \WP_Term   $term     The passage the page is about.
		 * @param array      $sources  One entry per sermon: id, title, passage, abstract.
		 */
		$summary = (string) apply_filters( 'scsl_scripture_summary_generate', '', $term, $sources );

		$summary = trim( wp_strip_all_tags( $summary ) );

		if ( '' === $summary ) {
			return;
		}

		update_term_meta( $term_id, self::META_TEXT, $summary );
		update_term_meta( $term_id, self::META_HASH, self::hash( $sermon_ids ) );
		update_term_meta( $term_id, self::META_TIME, time() );
	}

	/**
	 * The scripture references attached to a sermon, as plain names.
	 *
	 * @return string[]
	 */
	private static function passages_for( int $sermon_id ): array {
		$terms = get_the_terms( $sermon_id, 'scsl_scripture' );

		if ( ! is_array( $terms ) ) {
			return [];
		}

		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Fingerprint of a sermon set, order-independent.
	 *
	 * Keyed on the set rather than the passage so that re-running against the
	 * same sermons costs nothing, while a sermon added or removed produces a
	 * different key and earns fresh writing.
	 *
	 * @param int[] $sermon_ids
	 */
	private static function hash( array $sermon_ids ): string {
		$ids = array_map( 'intval', $sermon_ids );
		sort( $ids );

		return md5( implode( ',', $ids ) );
	}
}
