<?php
namespace SeedcastSermonLibrary\Topics;

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\BulletinLibraryBridge;
use SeedcastSermonLibrary\Scripture\ListLoader as ScriptureList;
use SeedcastSermonLibrary\Scripture\ScriptureParser;

/**
 * The lists on a topic page, and the batches that follow them.
 *
 * A topic page shows every sermon filed under it, unpaginated, then the
 * articles written out of those sermons, then the subjects it is taught
 * alongside. The first two are the same idea as a scripture page's lists and
 * the third is what widens the page out, which on a passage page is the rest of
 * the book.
 *
 * Related topics are worked out from the sermons themselves rather than from
 * anything somebody maintains: two subjects are related here because this
 * church has preached them together, which is a fact about the library and
 * needs no vocabulary of its own. A topic with three sermons on it is not a
 * destination, and this section is the difference between a page with three
 * cards and a page worth arriving at.
 *
 * Batch sizes and the article grid come from the scripture loader, because a
 * church should not find two lists on the same site that page differently.
 *
 * @package SeedcastSermonLibrary
 */
class ListLoader {

	/**
	 * How many related topics to offer.
	 *
	 * Enough to lead somewhere, few enough that each one can be shown with its
	 * own sermons rather than as a list of words.
	 */
	public const RELATED = 4;

	/**
	 * How many sermons to show under each related topic.
	 */
	public const RELATED_SERMONS = 3;

	/**
	 * How much of two subjects' scripture has to be the same before one is
	 * offered on the other's page.
	 *
	 * A quarter. Below that the overlap is the handful of books every subject
	 * in a church's library passes through, and the section fills with the
	 * biggest topics rather than the nearest ones.
	 */
	private const NEAR_ENOUGH = 0.25;

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_route' ] );
	}

	/**
	 * Read-only, public, and cacheable: this returns what the page already shows.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route( 'scsl/v1', '/topic-list', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'handle' ],
			'args'                => [
				'term'   => [ 'required' => true,  'sanitize_callback' => 'absint' ],
				'list'   => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'offset' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
			],
		] );
	}

	/**
	 * Where a button sends its request.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
		return esc_url_raw( rest_url( 'scsl/v1/topic-list' ) );
	}

	/**
	 * Every sermon filed under a topic, newest first.
	 *
	 * @param \WP_Term $term     Topic term.
	 * @param int      $per_page How many, or -1 for all of them.
	 * @param int      $offset   Where to start.
	 * @return \WP_Query
	 */
	public static function for_topic( \WP_Term $term, int $per_page, int $offset = 0 ): \WP_Query {
		return new \WP_Query( array_merge(
			self::base_args( $per_page, $offset ),
			[
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query' => [
					[
						'taxonomy' => 'scsl_topic',
						'field'    => 'term_id',
						'terms'    => [ (int) $term->term_id ],
					],
				],
			]
		) );
	}

	/**
	 * The subjects a given set of sermons is filed under, most first.
	 *
	 * For a page that is about something else and wants to say what its
	 * sermons are about: a passage page, a series, anywhere a set has already
	 * been worked out. Counted rather than listed, so the subject this church
	 * keeps returning to out of a passage comes first.
	 *
	 * @param int[] $sermon_ids The set.
	 * @param int   $most       How many to return, or 0 for all of them. A
	 *                          caller that trims the list itself can then say
	 *                          whether anything was left out.
	 * @return \WP_Term[]
	 */
	public static function for_set( array $sermon_ids, int $most = 0 ): array {
		$weight = [];
		$terms  = [];

		/*
		 * One query for the lot.
		 *
		 * A book page runs this over seventy sermons, and get_the_terms() asks
		 * the database per post until something has primed the cache.
		 */
		update_object_term_cache( array_map( 'intval', $sermon_ids ), 'scsl_sermon' );

		foreach ( $sermon_ids as $sermon_id ) {
			foreach ( (array) get_the_terms( (int) $sermon_id, 'scsl_topic' ) as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$terms[ $term->term_id ]  = $term;
				$weight[ $term->term_id ] = ( $weight[ $term->term_id ] ?? 0 ) + 1;
			}
		}

		arsort( $weight );

		$out = [];

		$keys = array_keys( $weight );

		foreach ( $most > 0 ? array_slice( $keys, 0, $most ) : $keys as $id ) {
			$out[] = $terms[ $id ];
		}

		return $out;
	}

	/**
	 * The subjects this one is taught alongside, most shared sermons first.
	 *
	 * Counted over the set the page is already showing, so the number beside a
	 * topic is a count the reader could verify by looking.
	 *
	 * Where a church files one subject per sermon there is nothing to count,
	 * and on this library that is most of them: of a hundred and forty
	 * sermons, fifty-nine carry exactly one topic. Eight of twenty-four topic
	 * pages had no subject to offer at all, among them Prayer and False
	 * Teaching, which are not thin pages by any other measure. So where
	 * nothing has been taught alongside this, the page widens by scripture
	 * instead: the subjects preached from the same books.
	 *
	 * @param \WP_Term $term       Topic term.
	 * @param int[]    $sermon_ids The sermons on this page.
	 * @return array<int, array{term: \WP_Term, shared: int, reason: string}> Strongest first.
	 */
	public static function related( \WP_Term $term, array $sermon_ids ): array {
		$counts = [];
		$terms  = [];

		foreach ( $sermon_ids as $sermon_id ) {
			foreach ( (array) get_the_terms( (int) $sermon_id, 'scsl_topic' ) as $other ) {
				if ( ! $other instanceof \WP_Term || (int) $other->term_id === (int) $term->term_id ) {
					continue;
				}

				$id            = (int) $other->term_id;
				$terms[ $id ]  = $other;
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );

		$out = [];

		foreach ( array_slice( $counts, 0, self::RELATED, true ) as $id => $shared ) {
			/*
			 * One shared sermon is a coincidence rather than a relationship.
			 *
			 * Two subjects that met once on one sermon say nothing about how a
			 * church teaches either of them, and a section built out of those
			 * reads as a tag cloud.
			 */
			if ( $shared < 2 ) {
				continue;
			}

			$out[] = [ 'term' => $terms[ $id ], 'shared' => (int) $shared, 'reason' => 'together' ];
		}

		return $out ? $out : self::by_scripture( $term, $sermon_ids );
	}

	/**
	 * The subjects preached from the same books, for a page with nothing taught
	 * alongside it.
	 *
	 * Counted by book rather than by chapter or verse. Two subjects that both
	 * come out of Romans are related in a way a reader can see; two that share
	 * Romans 8:28 are the same sermon twice.
	 *
	 * Scored as a share of the two sets together rather than as a count of what
	 * they have in common, because a count only ever finds the biggest
	 * subjects: on this library every page's answer came back Forgiveness,
	 * Obedience and Salvation, which have sermons in most books and so share
	 * books with everything. What a reader wants is the subject that comes out
	 * of the same places, which is a proportion.
	 *
	 * Cached, because answering it reads every topic's sermons and their
	 * passages: fine once, not on every view of a page a crawler is walking.
	 *
	 * @param \WP_Term $term       Topic term.
	 * @param int[]    $sermon_ids The sermons on this page.
	 * @return array<int, array{term: \WP_Term, shared: int, books: string[], reason: string}>
	 */
	private static function by_scripture( \WP_Term $term, array $sermon_ids ): array {
		$ids = array_map( 'intval', $sermon_ids );
		sort( $ids );

		$key    = 'scsl_topic_near_' . (int) $term->term_id . '_' . substr( md5( implode( ',', $ids ) ), 0, 8 );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return self::rehydrate( $cached );
		}

		$books = self::books_of( $sermon_ids );

		if ( ! $books ) {
			return [];
		}

		$scores = [];
		$shared = [];
		$terms  = [];

		foreach ( get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => true ] ) as $other ) {
			if ( ! $other instanceof \WP_Term || (int) $other->term_id === (int) $term->term_id ) {
				continue;
			}

			$theirs = self::books_of( wp_list_pluck( self::for_topic( $other, -1 )->posts, 'ID' ) );

			/*
			 * Ordered by what the other subject leans on, not this one.
			 *
			 * Every row of the section shares this page's main books by
			 * definition, so ordering by those names the same three books four
			 * times over. Ordered by theirs, each row says what the reader
			 * would find if they followed it.
			 */
			$common = array_values( array_intersect( array_keys( $theirs ), array_keys( $books ) ) );
			$union  = count( array_unique( array_merge( array_keys( $books ), array_keys( $theirs ) ) ) );

			/*
			 * More than one book in common, because almost any two subjects in
			 * a church's library meet in one of them, and a fair share of the
			 * two sets together rather than a long tail of coincidence.
			 */
			if ( count( $common ) < 2 || $union < 1 ) {
				continue;
			}

			$score = count( $common ) / $union;

			if ( $score < self::NEAR_ENOUGH ) {
				continue;
			}

			$id            = (int) $other->term_id;
			$terms[ $id ]  = $other;
			$shared[ $id ] = $common;
			$scores[ $id ] = $score;
		}

		arsort( $scores );

		$out   = [];
		$store = [];

		foreach ( array_slice( $scores, 0, self::RELATED, true ) as $id => $score ) {
			$out[] = [
				'term'   => $terms[ $id ],
				'shared' => count( $shared[ $id ] ),

				// Named rather than counted. "Also from Romans and Luke" is
				// something a reader can check by clicking; "7 books in
				// common" is a number they have to take on trust.
				'books'  => array_slice( $shared[ $id ], 0, 3 ),
				'reason' => 'passages',
			];

			$store[] = [
				'term_id' => $id,
				'shared'  => count( $shared[ $id ] ),
				'books'   => array_slice( $shared[ $id ], 0, 3 ),
			];
		}

		set_transient( $key, $store, 12 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Turn a cached answer back into terms.
	 *
	 * Ids are stored rather than term objects, so a renamed topic is not
	 * served under its old name for half a day.
	 *
	 * @param array $cached What was stored.
	 * @return array<int, array{term: \WP_Term, shared: int, books: string[], reason: string}>
	 */
	private static function rehydrate( array $cached ): array {
		$out = [];

		foreach ( $cached as $row ) {
			$term = get_term( (int) ( $row['term_id'] ?? 0 ), 'scsl_topic' );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$out[] = [
				'term'   => $term,
				'shared' => (int) ( $row['shared'] ?? 0 ),
				'books'  => (array) ( $row['books'] ?? [] ),
				'reason' => 'passages',
			];
		}

		return $out;
	}

	/**
	 * Which books of the Bible a set of sermons preaches from, and how much.
	 *
	 * Counted so the books can be named in the order they matter to the
	 * subject. Without that they come out alphabetically and every row of the
	 * section reads the same: 1 Corinthians, 1 Thessalonians, Ephesians,
	 * whatever the subject.
	 *
	 * One count per sermon per book, not per reference, or a sermon working
	 * through one chapter verse by verse would outweigh a series.
	 *
	 * @param int[] $sermon_ids Sermons.
	 * @return array<string,int> Book name to the number of sermons in it, most first.
	 */
	private static function books_of( array $sermon_ids ): array {
		$books = [];

		foreach ( $sermon_ids as $sermon_id ) {
			$seen = [];

			foreach ( (array) get_the_terms( (int) $sermon_id, 'scsl_scripture' ) as $passage ) {
				if ( ! $passage instanceof \WP_Term ) {
					continue;
				}

				$book = ScriptureParser::extract_book( (string) $passage->name );

				if ( ! $book || isset( $seen[ $book ] ) ) {
					continue;
				}

				$seen[ $book ]  = true;
				$books[ $book ] = ( $books[ $book ] ?? 0 ) + 1;
			}
		}

		arsort( $books );

		return $books;
	}

	/**
	 * Sermons from a related topic that this page has not already shown.
	 *
	 * @param \WP_Term $term     The related topic.
	 * @param int[]    $exclude  Sermons already on the page.
	 * @param int      $per_page How many.
	 * @param int      $offset   Where to start.
	 * @return \WP_Query
	 */
	public static function from_related( \WP_Term $term, array $exclude, int $per_page, int $offset = 0 ): \WP_Query {
		return new \WP_Query( array_merge(
			self::base_args( $per_page, $offset ),
			[
				'post__not_in' => array_map( 'intval', $exclude ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'tax_query'    => [
					[
						'taxonomy' => 'scsl_topic',
						'field'    => 'term_id',
						'terms'    => [ (int) $term->term_id ],
					],
				],
			]
		) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function base_args( int $per_page, int $offset ): array {
		return [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'order'          => 'DESC',
		];
	}

	/**
	 * Return one further batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$term = get_term( (int) $request['term'], 'scsl_topic' );

		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'scsl_no_term', __( 'Unknown topic.', 'seedcast-sermon-library' ), [ 'status' => 404 ] );
		}

		$list     = (string) $request['list'];
		$offset   = (int) ( $request['offset'] ?? 0 );
		$per_page = ScriptureList::per_page();

		if ( 'articles' === $list ) {
			return self::articles_batch( $term, $offset );
		}

		$query = self::for_topic( $term, $per_page, $offset );

		$html = '';

		foreach ( $query->posts as $post ) {
			$html .= BulletinLibraryBridge::card( $post );
		}

		return new \WP_REST_Response( [
			'html' => $html,
			'more' => ( $offset + count( $query->posts ) ) < (int) $query->found_posts,
		] );
	}

	/**
	 * One further batch of article cards.
	 *
	 * Rendered through the same shortcode the page uses, so the cards match.
	 *
	 * @param \WP_Term $term   Topic term.
	 * @param int      $offset Where to start.
	 * @return \WP_REST_Response
	 */
	private static function articles_batch( \WP_Term $term, int $offset ): \WP_REST_Response {
		$listed = wp_list_pluck( self::for_topic( $term, -1 )->posts, 'ID' );

		$with_article = array_values( array_filter( $listed, static function ( $id ) {
			return '' !== trim( (string) get_post_meta( (int) $id, '_scsl_article_body', true ) );
		} ) );

		$batch = ScriptureList::article_batch();

		// Bare cards. The page already has the section, heading and grid, and
		// this batch continues the row that is open rather than starting a
		// second grid alongside the first.
		$html = do_shortcode( sprintf(
			'[scsl_content type="articles" include="%s" count="%d" offset="%d" columns="%d" layout="grid" title="" wrapper="false"]',
			esc_attr( implode( ',', $listed ) ),
			$batch,
			$offset,
			3
		) );

		return new \WP_REST_Response( [
			'html' => $html,
			'more' => ( $offset + $batch ) < count( $with_article ),
		] );
	}

	/**
	 * The button that fetches the next batch.
	 *
	 * An anchor rather than a button, pointing at somewhere the reader can
	 * actually go, so it still leads somewhere with scripts unavailable. The
	 * endpoint travels on the button, which is what lets topic pages share one
	 * script with scripture pages.
	 *
	 * @param \WP_Term $term         Topic term.
	 * @param string   $list         Which list.
	 * @param int      $offset       Where the next batch starts.
	 * @param string   $label        Button text.
	 * @param string   $fallback_url Where to go without JavaScript.
	 * @return string Escaped HTML.
	 */
	public static function button( \WP_Term $term, string $list, int $offset, string $label, string $fallback_url ): string {
		return sprintf(
			'<p class="scsl-loadmore"><a class="sc-btn sc-btn--ghost sc-btn--sm" href="%s"'
			. ' data-scsl-loadmore="%s" data-endpoint="%s" data-term="%d" data-offset="%d">%s</a></p>',
			esc_url( $fallback_url ),
			esc_attr( $list ),
			esc_url( self::endpoint() ),
			(int) $term->term_id,
			$offset,
			esc_html( $label )
		);
	}
}
