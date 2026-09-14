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

	/** Meta key holding the unix time generation was last attempted. */
	private const META_TRIED = '_scsl_summary_tried';

	/**
	 * Meta key holding a rewrite waiting to be looked at.
	 *
	 * A first summary goes straight out: there is nothing on the page to
	 * protect and holding it back would leave the section blank. A rewrite is
	 * different. Something already published is about to be replaced by
	 * machine-written prose nobody has read, on a page that is already
	 * indexed, so it waits here until somebody says yes.
	 */
	public const META_PENDING = '_scsl_summary_pending';

	/**
	 * Meta key marking a summary as somebody's own writing.
	 *
	 * Set when a person edits one by hand. Nothing regenerates over it, ever,
	 * and the way back is to clear the flag rather than to wait for the set to
	 * change. Without this, adding one sermon to a book silently replaces
	 * paragraphs somebody wrote.
	 */
	public const META_BY_HAND = '_scsl_summary_by_hand';

	/**
	 * Meta key recording that a hand-written summary may be replaced anyway.
	 *
	 * Only meaningful alongside the flag above: generated prose is rewritable
	 * by definition, and the question is only ever asked about somebody's own
	 * writing. Off unless it was deliberately turned on, so a church that
	 * connects a generator later does not find its own paragraphs queued for
	 * replacement by having done nothing.
	 */
	public const META_REWRITABLE = '_scsl_summary_rewritable';

	/** How long to wait before attempting generation again after a failure. */
	private const RETRY_AFTER = DAY_IN_SECONDS;

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
		add_action( 'scsl_generate_scripture_summary', [ self::class, 'generate' ], 10, 1 );
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

		$stored = (string) get_term_meta( $term_id, self::META_TEXT, true );

		// Somebody's own words, with no permission given to replace them. Not
		// stale, not regenerated, not queued.
		if ( $stored && ! self::may_rewrite( $term_id ) ) {
			return $stored;
		}

		$hash   = self::hash( $sermon_ids );
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
	 * The summary a page is showing now, and whose writing it is.
	 *
	 * Whose it is matters as much as what it says. A generated paragraph is a
	 * starting point to carry forward. A church's own, which only reaches here
	 * when they have said it may be rewritten, is their wording and their
	 * emphasis, and the most a rewrite should do is add what the set now
	 * supports.
	 *
	 * @return array{text: string, source: string}
	 */
	private static function previous( int $term_id ): array {
		return [
			'text'   => (string) get_term_meta( $term_id, self::META_TEXT, true ),
			'source' => get_term_meta( $term_id, self::META_BY_HAND, true ) ? 'church' : 'generated',
		];
	}

	/**
	 * Schedule regeneration, unless it is already scheduled.
	 *
	 * @param int[] $sermon_ids
	 * @return void
	 */
	private static function queue( int $term_id, array $sermon_ids ): void {
		/*
		 * Nothing is listening, so there is nothing to wait for. Without this
		 * every view of every passage page queues a job that cannot succeed,
		 * which never updates the stored hash, so the next view queues it
		 * again. On a site being crawled that is thousands of pointless jobs,
		 * each one reading meta for every sermon on the page.
		 */
		if ( ! has_filter( 'scsl_scripture_summary_generate' ) ) {
			return;
		}

		// Tried recently and produced nothing. Back off rather than hammer.
		$tried = (int) get_term_meta( $term_id, self::META_TRIED, true );

		if ( $tried && ( time() - $tried ) < self::RETRY_AFTER ) {
			return;
		}

		/*
		 * The term alone, not the sermon list. Cron arguments are stored in an
		 * autoloaded option and hashed to identify the job, so passing an array
		 * of every sermon id would put that array into an option read on every
		 * single request, and a set that changed by one sermon would look like
		 * a different job rather than a replacement for the old one.
		 */
		$args = [ $term_id ];

		if ( wp_next_scheduled( 'scsl_generate_scripture_summary', $args ) ) {
			return;
		}

		update_term_meta( $term_id, self::META_TRIED, time() );

		wp_schedule_single_event( time() + 30, 'scsl_generate_scripture_summary', $args );
	}

	/**
	 * Write a summary for a term. Runs on the scheduled event, never in a page load.
	 *
	 * @return void
	 */
	public static function generate( int $term_id ): void {
		$term = get_term( $term_id, 'scsl_scripture' );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		// Worked out here rather than carried through cron, so the job stays a
		// single integer and the set is whatever it is when the job runs.
		$sermon_ids = wp_list_pluck( ListLoader::cross_referenced( $term, -1 )->posts, 'ID' );

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
		 * $context carries the shape of the set rather than its contents, and it
		 * is what keeps a summary specific. Knowing that a book's teaching sits
		 * mostly in two chapters, or arrived through one series, produces a
		 * sentence true of this church; the sermon list alone tends to produce
		 * commentary that would fit any church preaching the same book.
		 *
		 * $previous is what the page says now. A rewrite happens because the
		 * set changed, usually by a sermon or two, and a writer given nothing
		 * to work from writes the page again from scratch: the paragraph
		 * somebody approved last month comes back saying the same thing in
		 * different words, for no reason a reader could see.
		 *
		 * @param string   $summary  Empty by default, meaning no service is connected.
		 * @param \WP_Term $term     The passage the page is about.
		 * @param array    $sources  One entry per sermon: id, title, passage, abstract.
		 * @param array    $context  level, book, chapters, series, totals.
		 * @param array    $previous { text: what the page says now, source: generated or church }.
		 */
		$summary = (string) apply_filters(
			'scsl_scripture_summary_generate',
			'',
			$term,
			$sources,
			self::context( $term, $sermon_ids ),
			self::previous( $term_id )
		);

		$summary = trim( wp_strip_all_tags( $summary ) );

		if ( '' === $summary ) {
			return;
		}

		update_term_meta( $term_id, self::META_HASH, self::hash( $sermon_ids ) );
		update_term_meta( $term_id, self::META_TIME, time() );

		/*
		 * Replacing something that is already public is somebody's decision.
		 *
		 * The hash and the time are written either way, so a set that has been
		 * summarised once is not queued again while its rewrite sits waiting.
		 * The page goes on showing what it was showing.
		 */
		if ( '' !== (string) get_term_meta( $term_id, self::META_TEXT, true ) ) {
			update_term_meta( $term_id, self::META_PENDING, $summary );

			return;
		}

		update_term_meta( $term_id, self::META_TEXT, $summary );
	}

	/**
	 * What state a term's summary is in.
	 *
	 * @return string none | published | pending | hand
	 */
	public static function status( int $term_id ): string {
		if ( '' !== (string) get_term_meta( $term_id, self::META_PENDING, true ) ) {
			return 'pending';
		}

		if ( '' === (string) get_term_meta( $term_id, self::META_TEXT, true ) ) {
			return 'none';
		}

		if ( ! get_term_meta( $term_id, self::META_BY_HAND, true ) ) {
			return 'published';
		}

		return get_term_meta( $term_id, self::META_REWRITABLE, true ) ? 'open' : 'hand';
	}

	/**
	 * Whether anything is allowed to replace a term's summary.
	 *
	 * True for generated prose, and for somebody's own writing where they said
	 * so. Note that allowing it is not the same as losing it: a rewrite of an
	 * existing summary is staged for review rather than published, so the worst
	 * that happens is a suggestion appearing in the queue.
	 */
	public static function may_rewrite( int $term_id ): bool {
		if ( ! get_term_meta( $term_id, self::META_BY_HAND, true ) ) {
			return true;
		}

		return (bool) get_term_meta( $term_id, self::META_REWRITABLE, true );
	}

	/**
	 * Whether anything is listening to write summaries at all.
	 *
	 * With nothing connected the question of permission does not arise, so it
	 * is not put. The answer is still recorded, as a no, which is what makes
	 * connecting a generator later safe.
	 */
	public static function generator_connected(): bool {
		return 'writing' === self::generator_state();
	}

	/**
	 * Who, if anyone, is going to write these.
	 *
	 * Three answers rather than two, because "nothing is connected" is a
	 * misleading thing to tell somebody looking at a site with the AI Engine
	 * installed. The engine writes sermon content; writing the summary at the
	 * top of a passage page is a separate job it does not do yet, and saying so
	 * is the difference between a screen that seems broken and one that is
	 * merely waiting.
	 *
	 * @return string writing | engine | none
	 */
	public static function generator_state(): string {
		if ( has_filter( 'scsl_scripture_summary_generate' ) ) {
			return 'writing';
		}

		return defined( 'SCPRO_VERSION' ) ? 'engine' : 'none';
	}

	/**
	 * The rewrite waiting on a term, if there is one.
	 */
	public static function pending( int $term_id ): string {
		return (string) get_term_meta( $term_id, self::META_PENDING, true );
	}

	/**
	 * How many rewrites are waiting to be looked at.
	 *
	 * One query rather than a walk of seventeen hundred terms, because this is
	 * asked on every admin page load to draw the count beside the menu.
	 */
	public static function pending_count(): int {
		global $wpdb;

		$count = wp_cache_get( 'scsl_pending_summaries', 'scsl' );

		if ( false === $count ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached either side of this; no API counts term meta by key and value.
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value <> ''",
				self::META_PENDING
			) );

			wp_cache_set( 'scsl_pending_summaries', $count, 'scsl', 5 * MINUTE_IN_SECONDS );
		}

		return (int) $count;
	}

	/**
	 * Publish the rewrite waiting on a term.
	 *
	 * @return bool Whether there was one to publish.
	 */
	public static function approve( int $term_id ): bool {
		$pending = self::pending( $term_id );

		if ( '' === $pending ) {
			return false;
		}

		update_term_meta( $term_id, self::META_TEXT, $pending );
		update_term_meta( $term_id, self::META_TIME, time() );

		// Approving generated prose means it is not somebody's own writing,
		// whatever it was before.
		delete_term_meta( $term_id, self::META_BY_HAND );
		delete_term_meta( $term_id, self::META_REWRITABLE );
		delete_term_meta( $term_id, self::META_PENDING );

		self::forget_count();

		return true;
	}

	/**
	 * Throw away the rewrite waiting on a term and keep what is published.
	 *
	 * The hash stays as it is, so the set that produced the rejected version
	 * is not immediately queued again. Rejecting a rewrite should be quiet,
	 * not a request for another one straight away.
	 *
	 * @return bool Whether there was one to discard.
	 */
	public static function discard( int $term_id ): bool {
		if ( '' === self::pending( $term_id ) ) {
			return false;
		}

		delete_term_meta( $term_id, self::META_PENDING );
		self::forget_count();

		return true;
	}

	/**
	 * Save prose somebody wrote or edited themselves.
	 *
	 * Marks the term as hand written, which stops regeneration for good, and
	 * clears any rewrite that was waiting: a person who has just written the
	 * thing has answered the question the queue was asking.
	 *
	 * An empty string clears the summary and the flag with it, which is how a
	 * term goes back to being generated.
	 *
	 * @return void
	 */
	public static function write( int $term_id, string $prose, bool $may_rewrite = false ): void {
		$prose = trim( wp_strip_all_tags( $prose ) );

		delete_term_meta( $term_id, self::META_PENDING );

		if ( '' === $prose ) {
			delete_term_meta( $term_id, self::META_TEXT );
			delete_term_meta( $term_id, self::META_BY_HAND );
			delete_term_meta( $term_id, self::META_REWRITABLE );
			delete_term_meta( $term_id, self::META_HASH );

			self::forget_count();

			return;
		}

		update_term_meta( $term_id, self::META_TEXT, $prose );
		update_term_meta( $term_id, self::META_BY_HAND, 1 );
		update_term_meta( $term_id, self::META_TIME, time() );

		if ( $may_rewrite ) {
			update_term_meta( $term_id, self::META_REWRITABLE, 1 );
		} else {
			delete_term_meta( $term_id, self::META_REWRITABLE );
		}

		self::forget_count();
	}

	/**
	 * Hand a term back to the generator.
	 *
	 * @return void
	 */
	public static function release( int $term_id ): void {
		update_term_meta( $term_id, self::META_REWRITABLE, 1 );
	}

	/**
	 * @return void
	 */
	private static function forget_count(): void {
		wp_cache_delete( 'scsl_pending_summaries', 'scsl' );
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

			foreach ( self::passages_for( (int) $sermon_id ) as $passage ) {
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
