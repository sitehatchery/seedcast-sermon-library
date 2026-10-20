<?php
namespace SeedcastSermonLibrary\Topics;

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Summaries\PageSummary;

/**
 * The summary at the top of a topic page.
 *
 * Everything about caching one, serving a stale copy while a new one is
 * written, holding a rewrite of a church's own words for review and backing
 * off when nothing is listening is shared with scripture pages and lives in
 * PageSummary. What is here is what a topic is: which sermons carry it, and
 * the shape worth describing it by.
 *
 * That shape is the whole difference between a page worth reading and a
 * paragraph about forgiveness in general. A topic page cannot lean on a book
 * and its chapters the way a passage page does, so it says where the teaching
 * comes from instead: the passages behind it, the series that carried it, the
 * subjects it is taught alongside, and how far back it goes. Those four are
 * true of this church and of nobody else.
 *
 * @package SeedcastSermonLibrary
 */
class Summary extends PageSummary {

	/**
	 * How many of each part of the shape to send.
	 *
	 * Enough to show where the weight is, few enough that the answer leads
	 * with the main thing rather than reciting a list.
	 */
	private const SHAPE_PARTS = 5;

	protected static function taxonomy(): string {
		return 'scsl_topic';
	}

	protected static function cron_hook(): string {
		return 'scsl_generate_topic_summary';
	}

	protected static function generate_filter(): string {
		return 'scsl_topic_summary_generate';
	}

	/**
	 * Every sermon filed under this topic.
	 *
	 * Simpler than a passage page, which has to gather sermons that reference
	 * a text in passing as well as those built on it. A topic is assigned to a
	 * sermon or it is not.
	 *
	 * @param \WP_Term $term Topic term.
	 * @return int[]
	 */
	protected static function sermon_ids( \WP_Term $term ): array {
		return wp_list_pluck( ListLoader::for_topic( $term, -1 )->posts, 'ID' );
	}

	/**
	 * The shape of a topic's teaching: where it comes from and what it sits with.
	 *
	 * @param \WP_Term $term       Topic term.
	 * @param int[]    $sermon_ids The set.
	 * @return array{sermons: int, span: string, passages: array<string,int>, series: array<string,int>, topics: array<string,int>}
	 */
	public static function context( \WP_Term $term, array $sermon_ids ): array {
		$passages = [];
		$series   = [];
		$topics   = [];
		$years    = [];

		foreach ( $sermon_ids as $sermon_id ) {
			$sermon_id = (int) $sermon_id;

			$sid = (int) get_post_meta( $sermon_id, '_scsl_series_id', true );

			if ( $sid ) {
				$name = get_the_title( $sid );

				if ( '' !== $name ) {
					$series[ $name ] = ( $series[ $name ] ?? 0 ) + 1;
				}
			}

			/*
			 * Counted by chapter rather than by verse.
			 *
			 * "Matthew 18" says where this church's teaching on forgiveness
			 * lives; "Matthew 18:21-35" beside "Matthew 18:15" says the same
			 * thing twice and leaves no room for the next passage.
			 */
			foreach ( static::passages_for( $sermon_id ) as $passage ) {
				$chapter = static::chapter_of( (string) $passage );

				if ( '' === $chapter ) {
					continue;
				}

				$passages[ $chapter ] = ( $passages[ $chapter ] ?? 0 ) + 1;
			}

			// What else this sermon was filed under, which is what makes the
			// "taught alongside" line true rather than a guess.
			foreach ( (array) get_the_terms( $sermon_id, 'scsl_topic' ) as $other ) {
				if ( ! $other instanceof \WP_Term || (int) $other->term_id === (int) $term->term_id ) {
					continue;
				}

				$topics[ $other->name ] = ( $topics[ $other->name ] ?? 0 ) + 1;
			}

			$date = (string) get_post_meta( $sermon_id, '_scsl_recorded_date', true );
			$year = (int) substr( $date, 0, 4 );

			if ( $year > 1900 ) {
				$years[] = $year;
			}
		}

		arsort( $passages );
		arsort( $series );
		arsort( $topics );

		return [
			'sermons'  => count( $sermon_ids ),
			'span'     => static::span( $years ),
			'passages' => array_slice( $passages, 0, self::SHAPE_PARTS, true ),
			'series'   => array_slice( $series, 0, self::SHAPE_PARTS, true ),
			'topics'   => array_slice( $topics, 0, self::SHAPE_PARTS, true ),
		];
	}

	/**
	 * The chapter a reference sits in, as "Book Chapter".
	 *
	 * @param string $passage A reference such as "Matthew 18:21-35".
	 * @return string "Matthew 18", or an empty string where there is no chapter.
	 */
	private static function chapter_of( string $passage ): string {
		$passage = trim( $passage );

		// Book, then chapter. A book name may carry a leading number and more
		// than one word: "1 Corinthians", "Song of Solomon".
		if ( ! preg_match( '/^((?:[1-3]\s+)?[A-Za-z][A-Za-z\s]*?)\s+(\d+)/', $passage, $m ) ) {
			return '';
		}

		return trim( $m[1] ) . ' ' . (int) $m[2];
	}

	/**
	 * How far back the teaching goes, said the way somebody would say it.
	 *
	 * @param int[] $years Years with a sermon in them.
	 * @return string "2019 to 2026", "2024", or an empty string.
	 */
	private static function span( array $years ): string {
		if ( ! $years ) {
			return '';
		}

		$first = min( $years );
		$last  = max( $years );

		if ( $first === $last ) {
			return (string) $first;
		}

		/* translators: 1: earliest year, 2: latest year. */
		return sprintf( __( '%1$d to %2$d', 'seedcast-sermon-library' ), $first, $last );
	}
}
