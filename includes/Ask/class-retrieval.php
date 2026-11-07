<?php
namespace SeedcastSermonLibrary\Ask;

use SeedcastSermonLibrary\Frontend\Faq;
use SeedcastSermonLibrary\Frontend\Unlisted;
use SeedcastSermonLibrary\Scripture\ScriptureParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds what in this library a question is about, using the structure the
 * library already has, before anything like embeddings.
 *
 * "What have we taught about forgiveness in Ephesians" is two term filters and
 * a date sort. Topics come from the church's own closed vocabulary, passages
 * are terms, questions the church has already answered are FAQ pairs, and
 * every sermon carries a description. Matching on those is cheaper and sharper
 * than chunked prose, and every match comes with a reason a visitor can be
 * shown: "filed under Forgiveness", "preached from Ephesians 4", "answers the
 * question ...".
 *
 * Answers the filter this plugin names in its corpus declaration, so the
 * engine can ask without knowing what a sermon is. Returns entities, each:
 *
 *     ref, type, title, url, reason, text, modified, score
 *
 * Only published, listed, unprotected sermons are ever returned. An unlisted
 * sermon is hidden from lists on purpose, and citing it would undo that.
 */
class Retrieval {

	public const FILTER = 'scsl_ask_retrieve';

	/** Most entities returned. The engine adds the church's details. */
	private const MAX = 10;

	/** Most sermons drawn from any one topic or passage. */
	private const PER_TERM = 6;

	public static function init(): void {
		add_filter( self::FILTER, [ __CLASS__, 'retrieve' ], 10, 3 );
	}

	/**
	 * @param array  $found    Anything an earlier filter found.
	 * @param string $question What the visitor asked.
	 * @param array  $context  from => { type, id }.
	 * @return array
	 */
	public static function retrieve( $found, $question, $context = [] ): array {
		$found    = is_array( $found ) ? $found : [];
		$question = (string) $question;
		$words    = Questions::words( $question );

		$sermons = [];
		$terms   = [];

		// 1. Passages the question names.
		foreach ( self::passages_in( $question ) as $term ) {
			$terms[ 'passage:' . $term->term_id ] = [
				'term'   => $term,
				'type'   => 'passage',
				'score'  => 6,
				/* translators: %s: a passage of scripture. */
				'reason' => sprintf( __( 'about %s', 'seedcast-sermon-library' ), $term->name ),
			];

			foreach ( self::sermons_in( 'scsl_scripture', (int) $term->term_id ) as $id ) {
				/* translators: %s: a passage of scripture. */
				self::add( $sermons, $id, 5, sprintf( __( 'preached from %s', 'seedcast-sermon-library' ), $term->name ), 'passage:' . $term->term_id );
			}
		}

		// 2. Topics from the church's own vocabulary.
		foreach ( self::topics_in( $words ) as $match ) {
			$term = $match['term'];
			$name = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );

			$terms[ 'topic:' . $term->term_id ] = [
				'term'   => $term,
				'type'   => 'topic',
				'score'  => 3 + $match['score'],
				/* translators: %s: a topic name. */
				'reason' => sprintf( __( 'the topic %s', 'seedcast-sermon-library' ), $name ),
			];

			foreach ( self::sermons_in( 'scsl_topic', (int) $term->term_id ) as $id ) {
				/* translators: %s: a topic name. */
				self::add( $sermons, $id, 2 + $match['score'], sprintf( __( 'filed under %s', 'seedcast-sermon-library' ), $name ), 'topic:' . $term->term_id );
			}
		}

		// 3. Questions this church has already answered.
		$faq_hits = self::faq_matches( $words );

		foreach ( $faq_hits as $id => $hit ) {
			/* translators: %s: a question from a sermon's FAQ. */
			self::add( $sermons, $id, 3 + $hit['score'], sprintf( __( 'answers "%s"', 'seedcast-sermon-library' ), $hit['question'] ), 'faq' );
		}

		// 4. Words in titles and descriptions.
		foreach ( self::keyword_matches( $words ) as $id => $hit ) {
			/* translators: %s: words from the question. */
			self::add( $sermons, $id, $hit['score'], sprintf( __( 'mentions %s', 'seedcast-sermon-library' ), implode( ', ', $hit['words'] ) ), 'words' );
		}

		// 5. The people who preach here: asked about by role, or by name.
		foreach ( self::speakers_in( $words ) as $speaker ) {
			$terms[ 'speaker:' . $speaker['post']->ID ] = $speaker;
		}

		// 6. The page it was asked from, when that is a sermon. "What does this
		// mean?" from a baptism sermon is a question about that sermon.
		$from = is_array( $context['from'] ?? null ) ? $context['from'] : [];

		if ( 'sermon' === ( $from['type'] ?? '' ) && self::citable( (int) ( $from['id'] ?? 0 ) ) ) {
			self::add( $sermons, (int) $from['id'], 4, __( 'the page you asked from', 'seedcast-sermon-library' ), 'from' );
		}

		/*
		 * The point of the structure: a sermon found by two different routes
		 * (a topic and a passage, say) is far more likely to be what was
		 * meant than one found by either alone.
		 */
		foreach ( $sermons as $id => $sermon ) {
			if ( count( array_unique( $sermon['routes'] ) ) > 1 ) {
				$sermons[ $id ]['score'] += 3;
			}
		}

		$out = [];

		foreach ( $terms as $key => $term ) {
			$item = 'speaker' === $term['type'] ? self::speaker_item( $term ) : self::term_item( $term );

			if ( $item ) {
				$out[] = $item;
			}
		}

		uasort(
			$sermons,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return $b['date'] <=> $a['date'];
				}

				return $b['score'] <=> $a['score'];
			}
		);

		foreach ( array_slice( $sermons, 0, self::MAX, true ) as $id => $sermon ) {
			$out[] = self::sermon_item( (int) $id, $sermon, $words, $faq_hits[ $id ] ?? null );
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_merge( $found, array_slice( $out, 0, self::MAX ) );
	}

	/* ---------------------------------------------------------------------
	 * Finding
	 * ------------------------------------------------------------------ */

	private static function add( array &$sermons, int $id, float $score, string $reason, string $route ): void {
		if ( ! isset( $sermons[ $id ] ) ) {
			$sermons[ $id ] = [
				'score'   => 0.0,
				'reasons' => [],
				'routes'  => [],
				'date'    => (int) get_post_time( 'U', true, $id ),
			];
		}

		$sermons[ $id ]['score']    += $score;
		$sermons[ $id ]['reasons'][] = $reason;
		$sermons[ $id ]['routes'][]  = preg_replace( '/:.*/', '', $route );
	}

	/**
	 * Passage terms named in the question: a book, and its chapter where the
	 * question gives one and the library has a page for it.
	 *
	 * @return \WP_Term[]
	 */
	private static function passages_in( string $question ): array {
		$out = [];

		foreach ( ScriptureParser::all_books() as $book ) {
			$pattern = '/\b' . preg_quote( $book, '/' ) . '\b(?:\s+(\d{1,3}))?/i';

			if ( ! preg_match( $pattern, $question, $found ) ) {
				continue;
			}

			$term = null;

			if ( ! empty( $found[1] ) ) {
				$term = get_term_by( 'name', $book . ' ' . (int) $found[1], 'scsl_scripture' );
			}

			if ( ! $term instanceof \WP_Term ) {
				$term = get_term_by( 'name', $book, 'scsl_scripture' );
			}

			if ( $term instanceof \WP_Term && $term->count > 0 ) {
				$out[ $term->term_id ] = $term;
			}

			if ( count( $out ) >= 2 ) {
				break;
			}
		}

		return array_values( $out );
	}

	/**
	 * Topics the question's words point at, from the church's own list.
	 *
	 * A word of the topic's name counts for more than a word of its
	 * description, and the whole name appearing counts most.
	 *
	 * @param string[] $words Stemmed words of the question.
	 * @return array<int, array{term:\WP_Term, score:float}>
	 */
	private static function topics_in( array $words ): array {
		if ( ! $words ) {
			return [];
		}

		$terms = get_terms( [
			'taxonomy'   => 'scsl_topic',
			'hide_empty' => true,
		] );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return [];
		}

		$matches = [];

		foreach ( $terms as $term ) {
			$name_words = Questions::words( $term->name );
			$desc_words = Questions::words( (string) $term->description );

			if ( ! $name_words ) {
				continue;
			}

			$in_name = count( array_intersect( $name_words, $words ) );
			$in_desc = count( array_intersect( array_diff( $desc_words, $name_words ), $words ) );

			/*
			 * Half the topic's name at least. One word in common with a
			 * three-word name is a coincidence: "what time are services"
			 * shares "times" with "Prophecy & The End Times" and is not about
			 * prophecy.
			 */
			if ( $in_name * 2 < count( $name_words ) ) {
				continue;
			}

			$score = $in_name * 2 + min( 2, $in_desc ) * 0.5;

			if ( $in_name && $in_name === count( $name_words ) ) {
				$score += 2;
			}

			if ( $score >= 2 ) {
				$matches[] = [
					'term'  => $term,
					'score' => $score,
				];
			}
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $matches, 0, 3 );
	}

	/**
	 * Words that ask who preaches here, stemmed the way questions are.
	 */
	private const ROLE_WORDS = [ 'pastor', 'elder', 'teacher', 'speaker', 'preacher', 'preach', 'leader', 'minister', 'staff', 'teach' ];

	/**
	 * Speakers the question is about: everyone who preaches here when it asks
	 * by role ("who are your pastors"), or the ones it names.
	 *
	 * A speaker's own profile is where a church says who is a pastor and who
	 * is an elder, so it is sent whole. Most sermons first, because the
	 * person who has preached ninety times is the likeliest answer to "who is
	 * your pastor".
	 *
	 * @param string[] $words Stemmed words.
	 * @return array<int, array{post:\WP_Post, type:string, score:float, reason:string, sermons:int}>
	 */
	private static function speakers_in( array $words ): array {
		if ( ! $words || ! post_type_exists( 'scsl_speaker' ) ) {
			return [];
		}

		$by_role = (bool) array_intersect( $words, self::ROLE_WORDS );
		$people  = get_posts( [
			'post_type'      => 'scsl_speaker',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'has_password'   => false,
		] );

		$out = [];

		foreach ( $people as $person ) {
			// A name, not a role: "Guest Teachers" is not named by "teachers".
			$name_words = array_filter(
				Questions::words( $person->post_title ),
				static function ( string $word ): bool {
					return mb_strlen( $word ) >= 3 && ! in_array( $word, self::ROLE_WORDS, true );
				}
			);
			$named      = (bool) array_intersect( $name_words, $words );

			if ( ! $by_role && ! $named ) {
				continue;
			}

			$sermons = self::sermon_count_for( (int) $person->ID );

			// Somebody named on no sermon and with nothing written about them
			// has nothing to say about them.
			if ( ! $sermons && '' === trim( wp_strip_all_tags( $person->post_content ) ) ) {
				continue;
			}

			$profile = Questions::words( $person->post_content . ' ' . get_post_meta( $person->ID, '_scsl_speaker_title', true ) );
			$score   = $named ? 7.0 : 4.0;

			// A profile that says "pastor" answers "who is your pastor" better
			// than one that does not.
			if ( $by_role && array_intersect( array_intersect( $words, self::ROLE_WORDS ), $profile ) ) {
				$score += 2;
			}

			$score += min( 2.0, $sermons / 25 );

			$out[] = [
				'post'    => $person,
				'type'    => 'speaker',
				'score'   => $score,
				'reason'  => $named ? __( 'named in the question', 'seedcast-sermon-library' ) : __( 'preaches here', 'seedcast-sermon-library' ),
				'sermons' => $sermons,
			];
		}

		// A question that names somebody is about them, even when it also
		// says "preached".
		$named_only = array_values( array_filter(
			$out,
			static function ( array $one ): bool {
				return $one['score'] >= 7.0;
			}
		) );

		if ( $named_only ) {
			$out = $named_only;
		}

		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $out, 0, 6 );
	}

	private static function sermon_count_for( int $speaker_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_scsl_speaker_id' AND pm.meta_value = %s AND p.post_type = 'scsl_sermon' AND p.post_status = 'publish'",
			(string) $speaker_id
		) );
	}

	/**
	 * A speaker as material: their profile, and what they have preached.
	 */
	private static function speaker_item( array $match ): ?array {
		$person = $match['post'];
		$lines  = [];

		$role = trim( (string) get_post_meta( $person->ID, '_scsl_speaker_title', true ) );

		if ( '' !== $role ) {
			$lines[] = $role;
		}

		$profile = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $person->post_content ) ) );

		if ( '' !== $profile ) {
			$lines[] = $profile;
		}

		$recent = get_posts( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => 4,
			'has_password'   => false,
			'meta_query'     => array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[ 'relation' => 'AND' ],
				[ [ 'key' => '_scsl_speaker_id', 'value' => (string) $person->ID ] ],
				[ self::listed_only() ]
			),
		] );

		if ( $match['sermons'] ) {
			$titles = array_map(
				static function ( \WP_Post $post ): string {
					return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) . ' (' . get_the_date( 'F j, Y', $post ) . ')';
				},
				$recent
			);

			/* translators: %d: number of sermons. */
			$lines[] = sprintf( _n( 'Has preached %d sermon here', 'Has preached %d sermons here', $match['sermons'], 'seedcast-sermon-library' ), $match['sermons'] )
				. ( $titles ? ', most recently: ' . implode( '; ', $titles ) : '' );
		}

		$text = implode( "\n", $lines );

		return [
			'ref'      => 'speaker:' . $person->ID,
			'type'     => 'speaker',
			'title'    => html_entity_decode( get_the_title( $person ), ENT_QUOTES, 'UTF-8' ),
			'url'      => (string) get_permalink( $person ),
			'reason'   => $match['reason'],
			'text'     => $text,
			'modified' => (string) get_post_modified_time( 'U', true, $person ) . '|' . md5( $text ),
			'score'    => (float) $match['score'],
		];
	}

	/**
	 * Sermons under a term, newest first.
	 *
	 * @return int[]
	 */
	private static function sermons_in( string $taxonomy, int $term_id ): array {
		$query = new \WP_Query( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_TERM,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'has_password'   => false,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => self::listed_only(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => $taxonomy,
					'terms'            => [ $term_id ],
					'include_children' => true,
				],
			],
		] );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Sermons whose FAQ holds a question close to this one, with the closest
	 * pair.
	 *
	 * @param string[] $words Stemmed words.
	 * @return array<int, array{score:float, question:string, answer:string}>
	 */
	private static function faq_matches( array $words ): array {
		$long = self::longest( $words, 3 );

		if ( ! $long ) {
			return [];
		}

		global $wpdb;

		$likes = [];
		$args  = [ Faq::META ];

		foreach ( $long as $word ) {
			$likes[] = 'pm.meta_value LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $word ) . '%';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = 'scsl_sermon' AND p.post_status = 'publish' AND p.post_password = ''
			AND (" . implode( ' OR ', $likes ) . ') LIMIT 40',
			$args
		) );

		$out = [];

		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( ! self::citable( $id ) ) {
				continue;
			}

			$best = null;

			foreach ( Faq::get( $id ) as $pair ) {
				$overlap = count( array_intersect( Questions::words( $pair['question'] ), $words ) );

				if ( $overlap >= 2 && ( ! $best || $overlap > $best['score'] ) ) {
					$best = [
						'score'    => (float) $overlap,
						'question' => $pair['question'],
						'answer'   => $pair['answer'],
					];
				}
			}

			if ( $best ) {
				$out[ $id ] = $best;
			}
		}

		return $out;
	}

	/**
	 * Sermons whose title or description carries the question's words.
	 *
	 * One search per word rather than one for all of them: WordPress search
	 * wants every word, and a visitor's question is full of words no sermon
	 * title contains.
	 *
	 * @param string[] $words Stemmed words.
	 * @return array<int, array{score:float, words:string[]}>
	 */
	private static function keyword_matches( array $words ): array {
		$out = [];

		foreach ( self::longest( $words, 4 ) as $word ) {
			$query = new \WP_Query( [
				'post_type'      => 'scsl_sermon',
				'post_status'    => 'publish',
				's'              => $word,
				'posts_per_page' => 8,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'has_password'   => false,
				'meta_query'     => self::listed_only(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			] );

			foreach ( array_map( 'intval', $query->posts ) as $id ) {
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = [ 'score' => 0.0, 'words' => [] ];
				}

				$title_hit = false !== stripos( (string) get_the_title( $id ), $word );

				$out[ $id ]['score']  += $title_hit ? 2 : 1;
				$out[ $id ]['words'][] = $word;
			}
		}

		// One word in common is noise; it takes two, or one in the title.
		return array_filter(
			$out,
			static function ( array $hit ): bool {
				return $hit['score'] >= 2;
			}
		);
	}

	/**
	 * The longest few words, which are the ones most likely to mean something.
	 *
	 * @return string[]
	 */
	private static function longest( array $words, int $count ): array {
		$words = array_values( array_unique( array_filter(
			$words,
			static function ( string $word ): bool {
				return mb_strlen( $word ) >= 4 && ! is_numeric( $word );
			}
		) ) );

		usort(
			$words,
			static function ( string $a, string $b ): int {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);

		return array_slice( $words, 0, $count );
	}

	private static function listed_only(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => Unlisted::META,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => Unlisted::META,
				'value'   => [ '1', 'yes' ],
				'compare' => 'NOT IN',
			],
		];
	}

	private static function citable( int $id ): bool {
		if ( ! $id || 'scsl_sermon' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || post_password_required( $id ) ) {
			return false;
		}

		return ! Unlisted::is_unlisted( $id );
	}

	/* ---------------------------------------------------------------------
	 * Describing
	 * ------------------------------------------------------------------ */

	/**
	 * A sermon as material: what it is, what it is about, and the questions
	 * it answers that are closest to this one.
	 */
	private static function sermon_item( int $id, array $sermon, array $words, ?array $faq_hit ): array {
		$lines = [];

		$date = get_the_date( 'F j, Y', $id );
		$meta = [];

		if ( $date ) {
			$meta[] = $date;
		}

		$speaker_id = absint( get_post_meta( $id, '_scsl_speaker_id', true ) );

		if ( $speaker_id ) {
			$meta[] = get_the_title( $speaker_id );
		}

		$series_id = absint( get_post_meta( $id, '_scsl_series_id', true ) );

		if ( $series_id ) {
			/* translators: %s: series name. */
			$meta[] = sprintf( __( 'series: %s', 'seedcast-sermon-library' ), get_the_title( $series_id ) );
		}

		if ( $meta ) {
			$lines[] = implode( '; ', $meta );
		}

		$passages = wp_get_object_terms( $id, 'scsl_scripture', [ 'fields' => 'names' ] );

		if ( ! is_wp_error( $passages ) && $passages ) {
			$lines[] = __( 'Scripture:', 'seedcast-sermon-library' ) . ' ' . implode( ', ', array_slice( $passages, 0, 6 ) );
		}

		$topics = wp_get_object_terms( $id, 'scsl_topic', [ 'fields' => 'names' ] );

		if ( ! is_wp_error( $topics ) && $topics ) {
			$lines[] = __( 'Topics:', 'seedcast-sermon-library' ) . ' ' . html_entity_decode( implode( ', ', $topics ), ENT_QUOTES, 'UTF-8' );
		}

		$description = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_scsl_content_description', true ) ) );

		if ( '' === $description ) {
			$description = trim( wp_strip_all_tags( (string) get_the_excerpt( $id ) ) );
		}

		if ( '' !== $description ) {
			$lines[] = $description;
		}

		// The questions it already answers. The closest to this one first,
		// then whatever else fits.
		$pairs = Faq::get( $id );

		if ( $faq_hit ) {
			array_unshift( $pairs, [ 'question' => $faq_hit['question'], 'answer' => $faq_hit['answer'] ] );
		}

		$seen = [];

		foreach ( array_slice( $pairs, 0, 4 ) as $pair ) {
			if ( isset( $seen[ $pair['question'] ] ) ) {
				continue;
			}

			$seen[ $pair['question'] ] = true;
			$lines[] = 'Q: ' . $pair['question'] . ' A: ' . trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $pair['answer'] ) ) );
		}

		$reasons = array_values( array_unique( $sermon['reasons'] ) );

		return [
			'ref'      => 'sermon:' . $id,
			'type'     => 'sermon',
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'url'      => get_permalink( $id ),
			'reason'   => implode( '; ', array_slice( $reasons, 0, 2 ) ),
			'text'     => implode( "\n", $lines ),
			'modified' => (string) get_post_modified_time( 'U', true, $id ),
			'score'    => (float) $sermon['score'],
		];
	}

	/**
	 * A topic or passage page as material: its summary, the questions filed
	 * under it, and what has been preached on it.
	 */
	private static function term_item( array $match ): ?array {
		$term = $match['term'];
		$link = get_term_link( $term );

		if ( is_wp_error( $link ) ) {
			return null;
		}

		$lines   = [];
		$summary = trim( wp_strip_all_tags( (string) get_term_meta( $term->term_id, '_scsl_summary', true ) ) );

		if ( '' !== $summary ) {
			$lines[] = $summary;
		}

		$description = trim( wp_strip_all_tags( (string) $term->description ) );

		if ( '' !== $description && '' === $summary ) {
			$lines[] = $description;
		}

		if ( 'topic' === $match['type'] ) {
			foreach ( array_slice( TopicFaq::get( (int) $term->term_id ), 0, 4 ) as $pair ) {
				$lines[] = 'Q: ' . $pair['question'] . ' A: ' . trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $pair['answer'] ) ) );
			}
		}

		$taxonomy = 'topic' === $match['type'] ? 'scsl_topic' : 'scsl_scripture';
		$titles   = array_map(
			static function ( int $id ): string {
				return html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			},
			self::sermons_in( $taxonomy, (int) $term->term_id )
		);

		if ( $titles ) {
			/* translators: %d: number of sermons. */
			$lines[] = sprintf( _n( '%d sermon, including:', '%d sermons, including:', (int) $term->count, 'seedcast-sermon-library' ), (int) $term->count ) . ' ' . implode( '; ', $titles );
		}

		$text = implode( "\n", $lines );

		return [
			'ref'      => $match['type'] . ':' . $term->term_id,
			'type'     => $match['type'],
			'title'    => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'url'      => $link,
			'reason'   => $match['reason'],
			'text'     => $text,
			'modified' => md5( $text ),
			'score'    => (float) $match['score'],
		];
	}
}
