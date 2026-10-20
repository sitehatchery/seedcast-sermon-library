<?php
namespace SeedcastSermonLibrary\Scripture;

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Summaries\PageSummary;

/**
 * The summary at the top of a scripture page.
 *
 * Everything about caching one, serving a stale copy while a new one is
 * written, holding a rewrite of a church's own words for review and backing off
 * when nothing is listening is shared with topic pages and lives in
 * PageSummary. What is here is what a passage is: which sermons belong to it,
 * and the shape worth describing it by, which for a passage is where in the
 * book the teaching sits and which series carried it.
 *
 * @package SeedcastSermonLibrary
 */
class Summary extends PageSummary {

	/**
	 * How many sermons a page needs before a summary is worth writing.
	 *
	 * Inherited rather than redeclared, and kept public because the template
	 * and the admin screen both ask.
	 */

	protected static function taxonomy(): string {
		return 'scsl_scripture';
	}

	protected static function cron_hook(): string {
		return 'scsl_generate_scripture_summary';
	}

	protected static function generate_filter(): string {
		return 'scsl_scripture_summary_generate';
	}

	/**
	 * Every sermon whose passages touch this one.
	 *
	 * A sermon that referenced the passage in passing belongs on its page as
	 * much as one built on it, which is what cross_referenced() works out.
	 *
	 * @param \WP_Term $term Scripture term.
	 * @return int[]
	 */
	protected static function sermon_ids( \WP_Term $term ): array {
		return wp_list_pluck( ListLoader::cross_referenced( $term, -1 )->posts, 'ID' );
	}

	/**
	 * The shape of a passage's teaching: where it sits and how it arrived.
	 *
	 * @param int[] $sermon_ids
	 * @return array{level: string, book: string, chapters: array<int,int>, series: array<string,int>, sermons: int}
	 */
	public static function context( \WP_Term $term, array $sermon_ids ): array {
		$book = (string) ScriptureParser::extract_book( (string) $term->name );

		$children = get_term_children( (int) $term->term_id, 'scsl_scripture' );
		$children = is_wp_error( $children ) ? [] : $children;

		if ( $children ) {
			$level = ( 0 === (int) $term->parent ) ? 'book' : 'chapter';
		} else {
			$level = 'passage';
		}

		$chapters = [];
		$series   = [];

		foreach ( $sermon_ids as $sermon_id ) {
			$sid = (int) get_post_meta( (int) $sermon_id, '_scsl_series_id', true );

			if ( $sid ) {
				$name = get_the_title( $sid );

				if ( '' !== $name ) {
					$series[ $name ] = ( $series[ $name ] ?? 0 ) + 1;
				}
			}

			foreach ( static::passages_for( (int) $sermon_id ) as $passage ) {
				if ( ScriptureParser::extract_book( $passage ) !== $book ) {
					continue;
				}

				if ( preg_match( '/^' . preg_quote( $book, '/' ) . '\s+(\d+)/', $passage, $m ) ) {
					$chapters[ (int) $m[1] ] = ( $chapters[ (int) $m[1] ] ?? 0 ) + 1;
				}
			}
		}

		arsort( $series );
		arsort( $chapters );

		return [
			'level'    => $level,
			'book'     => $book,
			'chapters' => $chapters,
			'series'   => array_slice( $series, 0, 5, true ),
			'sermons'  => count( $sermon_ids ),
		];
	}


}
