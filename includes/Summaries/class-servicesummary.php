<?php
/**
 * The opening paragraph of a service bulletin, written from its sermon.
 *
 * Not a description of the sermon, which has its own page and its own
 * summary. The paragraph says something encouraging about what the sermon was
 * about, as the emphasis of the morning it was preached at, so a bulletin
 * reads as the week's rather than as a second copy of the sermon page.
 *
 * Two ways in, one way of asking:
 *
 *   A bulletin started from a sermon as it is published opens with the
 *   sermon's own description, which is there at once. The paragraph written
 *   for it replaces that a minute later, on a scheduled event, so publishing
 *   never waits on a service. It replaces it only where nobody has changed it
 *   in the meantime: a bulletin somebody has started writing is theirs.
 *
 *   A bulletin being edited asks through Bulletin Library's own filter, from a
 *   button on its screen. This answers with the sermon filed under that week.
 *
 * The writing itself is the engine's, through the filter declared in
 * Pro\ProBridge. With no engine connected neither way in is offered.
 *
 * @package SeedcastSermonLibrary
 */

namespace SeedcastSermonLibrary\Summaries;

if ( ! defined( 'ABSPATH' ) ) exit;

class ServiceSummary {

	/** The filter the engine answers. */
	const FILTER = 'scsl_service_summary_generate';

	/** The scheduled write for a bulletin started from a sermon. */
	const CRON_HOOK = 'scsl_service_summary_write';

	/**
	 * What a new bulletin opened with, kept on the bulletin until the written
	 * paragraph replaces it. Its presence is also the test for whether the
	 * overview is still ours to replace.
	 */
	const META_SEED = '_scsl_service_overview_seed';

	/** How many times the scheduled write has come back empty. */
	const META_TRIES = '_scsl_service_summary_tries';

	/**
	 * Attempts before a bulletin keeps the sermon's description for good. An
	 * empty answer is usually a day's writing already spent, so the next try is
	 * an hour away and the last one is the next day.
	 */
	const MAX_TRIES = 3;

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'scsl_service_bulletin_created', [ __CLASS__, 'schedule' ], 10, 3 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'write_scheduled' ], 10, 2 );

		// Bulletin Library's Generate button.
		add_filter( 'scbl/service/can_summarize', [ __CLASS__, 'can_summarize' ] );
		add_filter( 'scbl/service/summary', [ __CLASS__, 'for_service' ], 10, 3 );
	}

	/**
	 * Whether anything will answer.
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return has_filter( self::FILTER );
	}

	/**
	 * Write the paragraph for one sermon.
	 *
	 * @param int $sermon_id Sermon ID.
	 * @return string Plain text, paragraphs separated by a blank line; empty for "not this time".
	 */
	public static function write( int $sermon_id ): string {
		if ( ! self::connected() || 'scsl_sermon' !== get_post_type( $sermon_id ) ) {
			return '';
		}

		/**
		 * Write a service bulletin's opening paragraph from its sermon.
		 *
		 * Something encouraging about the subject the sermon was about, as the
		 * emphasis of the service. Not an overview of the sermon, and it should
		 * not mention one.
		 *
		 * @param string $summary Empty by default, meaning no service is connected.
		 * @param array  $sermon  id, title, scripture, topics, summary, transcript.
		 */
		return trim( (string) apply_filters( self::FILTER, '', self::gather( $sermon_id ) ) );
	}

	/**
	 * What a sermon offers to write from.
	 *
	 * @param int $sermon_id Sermon ID.
	 * @return array
	 */
	public static function gather( int $sermon_id ): array {
		$scripture = [ (string) get_post_meta( $sermon_id, '_scsl_focus_passage', true ) ];
		$others    = get_post_meta( $sermon_id, '_scsl_other_passages', true );

		if ( is_array( $others ) ) {
			$scripture = array_merge( $scripture, array_map( 'strval', $others ) );
		}

		$topics = wp_get_object_terms( $sermon_id, 'scsl_topic', [ 'fields' => 'names' ] );

		$summary = trim( (string) get_post_meta( $sermon_id, '_scsl_content_description', true ) );

		if ( '' === $summary ) {
			$summary = trim( (string) get_post_field( 'post_excerpt', $sermon_id ) );
		}

		return [
			'id'         => $sermon_id,
			'title'      => get_the_title( $sermon_id ),
			'scripture'  => array_values( array_unique( array_filter( array_map( 'trim', $scripture ) ) ) ),
			'topics'     => is_array( $topics ) ? $topics : [],
			'summary'    => $summary,
			'transcript' => (string) get_post_meta( $sermon_id, '_scsl_transcript_clean', true ),
		];
	}

	/* ---------------------------------------------------------------------
	 * A bulletin started from a sermon
	 * ------------------------------------------------------------------- */

	/**
	 * Remember what the bulletin opened with, and write the paragraph shortly.
	 *
	 * @param int    $service_id The new bulletin.
	 * @param int    $sermon_id  The sermon it was started from.
	 * @param string $date       The week, as Y-m-d.
	 * @return void
	 */
	public static function schedule( $service_id, $sermon_id, $date = '' ): void {
		unset( $date );

		$service_id = absint( $service_id );
		$sermon_id  = absint( $sermon_id );

		if ( ! $service_id || ! $sermon_id || ! self::connected() ) {
			return;
		}

		update_post_meta( $service_id, self::META_SEED, self::fingerprint( (string) get_post_field( 'post_content', $service_id ) ) );

		$args = [ $service_id, $sermon_id ];

		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, $args );
		}
	}

	/**
	 * Replace the bulletin's opening paragraph. Runs on the scheduled event.
	 *
	 * @param int $service_id Bulletin ID.
	 * @param int $sermon_id  Sermon ID.
	 * @return void
	 */
	public static function write_scheduled( $service_id, $sermon_id ): void {
		$service_id = absint( $service_id );
		$sermon_id  = absint( $sermon_id );
		$service    = get_post( $service_id );

		if ( ! $service || 'scbl_service' !== $service->post_type || 'trash' === $service->post_status ) {
			return;
		}

		$seed = (string) get_post_meta( $service_id, self::META_SEED, true );

		// Somebody has written in it since. Theirs now, not ours to replace.
		if ( '' === $seed || self::fingerprint( (string) $service->post_content ) !== $seed ) {
			self::forget( $service_id );

			return;
		}

		$written = self::write( $sermon_id );

		if ( '' === $written ) {
			$tries = (int) get_post_meta( $service_id, self::META_TRIES, true ) + 1;

			if ( $tries >= self::MAX_TRIES ) {
				self::forget( $service_id );

				return;
			}

			update_post_meta( $service_id, self::META_TRIES, $tries );
			wp_schedule_single_event( time() + ( 1 === $tries ? HOUR_IN_SECONDS : DAY_IN_SECONDS ), self::CRON_HOOK, [ $service_id, $sermon_id ] );

			return;
		}

		wp_update_post( [
			'ID'           => $service_id,
			'post_content' => self::to_html( $written ),
		] );

		self::forget( $service_id );
	}

	/**
	 * @param int $service_id Bulletin ID.
	 * @return void
	 */
	private static function forget( int $service_id ): void {
		delete_post_meta( $service_id, self::META_SEED );
		delete_post_meta( $service_id, self::META_TRIES );
	}

	/**
	 * The overview's text, compared without the whitespace an editor adds on
	 * a save that changed nothing.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private static function fingerprint( string $content ): string {
		return md5( (string) preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( $content ) ) ) );
	}

	/**
	 * Paragraphs, as both editors keep them.
	 *
	 * @param string $text Plain text, paragraphs separated by a blank line.
	 * @return string
	 */
	public static function to_html( string $text ): string {
		$paragraphs = array_filter( array_map( 'trim', preg_split( '/\n\s*\n/', $text ) ?: [] ) );

		return implode( "\n\n", array_map(
			static function ( string $p ): string {
				return '<p>' . esc_html( $p ) . '</p>';
			},
			$paragraphs
		) );
	}

	/* ---------------------------------------------------------------------
	 * Bulletin Library's Generate button
	 * ------------------------------------------------------------------- */

	/**
	 * @param bool $can Whether anything can write one.
	 * @return bool
	 */
	public static function can_summarize( $can ): bool {
		return (bool) $can || self::connected();
	}

	/**
	 * Answer a bulletin's request with the sermon filed under its week.
	 *
	 * Several sermons on one date is a morning with two services or a guest
	 * and a pastor; the one with the most to write from is the better source.
	 *
	 * @param string|\WP_Error|null $result     What an earlier answer said.
	 * @param int                   $service_id Bulletin ID.
	 * @param string                $date       The week, as Y-m-d.
	 * @return string|\WP_Error|null
	 */
	public static function for_service( $result, $service_id, $date ) {
		if ( is_string( $result ) && '' !== $result ) {
			return $result;
		}

		if ( ! self::connected() ) {
			return $result;
		}

		$date = (string) $date;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new \WP_Error( 'no_date', __( 'Set the service date first, so the sermon for that week can be found.', 'seedcast-sermon-library' ) );
		}

		$sermon_id = self::sermon_on( $date );

		if ( ! $sermon_id ) {
			return new \WP_Error( 'no_sermon', __( 'No sermon is dated for this service yet. Add the sermon to Sermon Library first.', 'seedcast-sermon-library' ) );
		}

		$written = self::write( $sermon_id );

		if ( '' === $written ) {
			return new \WP_Error( 'not_written', __( 'A summary could not be written right now. Please try again later.', 'seedcast-sermon-library' ) );
		}

		return $written;
	}

	/**
	 * The sermon preached on a date, preferring the one with the most material.
	 *
	 * @param string $date Y-m-d.
	 * @return int
	 */
	private static function sermon_on( string $date ): int {
		$ids = get_posts( [
			'post_type'              => 'scsl_sermon',
			'post_status'            => [ 'publish', 'future', 'draft', 'pending', 'private' ],
			'posts_per_page'         => 10,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A sermon's date is post meta; exact match on one key, bounded.
			'meta_query'             => [
				[
					'key'   => '_scsl_recorded_date',
					'value' => $date,
				],
			],
		] );

		$best  = 0;
		$score = -1;

		foreach ( $ids as $id ) {
			$material = strlen( (string) get_post_meta( (int) $id, '_scsl_transcript_clean', true ) )
				+ strlen( (string) get_post_meta( (int) $id, '_scsl_content_description', true ) );

			if ( $material > $score ) {
				$best  = (int) $id;
				$score = $material;
			}
		}

		return $best;
	}
}
