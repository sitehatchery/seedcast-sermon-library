<?php
namespace SeedcastSermonLibrary\Ask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The church's own pages and articles as material for an answer.
 *
 * Not Seedcast content, but the church's own words all the same, and where
 * most of what a first-time visitor asks is answered: what to expect, what to
 * wear, how to give, what there is for children, and above all what the
 * church believes. Answering from its Statement of Faith is the church
 * speaking; it is general Bible knowledge that stays out.
 *
 * Long pages are split at their own section breaks and only the sections
 * nearest the question are sent, so "do you believe in the Trinity" carries
 * the article on God rather than seventeen thousand characters of every
 * article.
 *
 * Published, unprotected pages and posts only. A church can leave any of them
 * out with the scsl_ask_site_exclude filter. Transcript posts are left out,
 * since the sermon they belong to is already material of its own.
 */
class SitePages {

	/** Most pages or posts sent for one question. */
	private const MAX = 4;

	/** Characters of any one page sent. */
	private const MAX_TEXT = 1400;

	/** A section is cut near this length. */
	private const CHUNK = 450;

	/**
	 * Words a visitor uses for what a page calls something else. Plain words
	 * here; they are stemmed the way questions are before use.
	 */
	private const ALSO = [
		'kids'     => 'children child nursery babies',
		'children' => 'kids nursery babies',
		'trinity'  => 'father son spirit persons triune',
		'wear'     => 'dress casual clothes',
		'parking'  => 'park lot',
		'give'     => 'giving online donate tithe generosity',
		'donate'   => 'give giving online',
		'tithe'    => 'give giving',
		'start'    => 'began emerged plant planted launched story history',
		'started'  => 'began emerged plant planted launched story history',
		'begin'    => 'began emerged plant launched story',
		'history'  => 'story began emerged',
		'believe'  => 'faith statement',
		'beliefs'  => 'faith statement believe',
		'doctrine' => 'faith statement believe',
		'teens'    => 'youth students',
		'teenager' => 'youth students',
		'students' => 'youth',
		'join'     => 'membership member',
		'member'   => 'membership join',
	];

	public static function init(): void {
		add_filter( Retrieval::FILTER, [ __CLASS__, 'retrieve' ], 20, 3 );
	}

	/**
	 * @param mixed  $found    Anything found before this.
	 * @param string $question What was asked.
	 * @param array  $context  Unused.
	 * @return array
	 */
	public static function retrieve( $found, $question, $context = [] ): array {
		$found = is_array( $found ) ? $found : [];
		$words = self::question_words( (string) $question );
		$typed = Questions::words( (string) $question );

		if ( ! $words ) {
			return $found;
		}

		$exclude = array_map( 'intval', (array) apply_filters( 'scsl_ask_site_exclude', [] ) );
		$posts   = get_posts( [
			'post_type'      => [ 'page', 'post' ],
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'has_password'   => false,
			'post__not_in'   => $exclude,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		$matches = [];

		foreach ( $posts as $post ) {
			// "Transcript: …" and "Video Transcript: …" alike: the sermon is
			// material of its own.
			if ( 'post' === $post->post_type && false !== stripos( $post->post_title, 'transcript' ) ) {
				continue;
			}

			$chunks = self::chunks( $post );

			if ( ! $chunks ) {
				continue;
			}

			$title_words = Questions::words( $post->post_title );
			$in_title    = count( array_intersect( $words, $title_words ) );

			/*
			 * Within a page, a word in most of its sections tells one section
			 * from another not at all. Every article of a statement of faith
			 * says "we believe", so "what do you believe about the Bible" has
			 * to be found by "Bible", or the section sent can be any of them:
			 * one run answered it with the article on gender.
			 */
			$chunk_words = [];
			$spread      = array_fill_keys( $words, 0 );

			foreach ( $chunks as $i => $chunk ) {
				$chunk_words[ $i ] = array_intersect( $words, Questions::words( $chunk ) );

				foreach ( $chunk_words[ $i ] as $word ) {
					$spread[ $word ]++;
				}
			}

			$common = count( $chunks ) > 3 ? count( $chunks ) * 0.4 : PHP_INT_MAX;
			$scored = [];

			foreach ( $chunk_words as $i => $hits ) {
				$scored[ $i ] = 0.0;

				foreach ( $hits as $word ) {
					$weight = $spread[ $word ] > $common ? 0.25 : 1.0;

					// A word the visitor typed outweighs one added for them:
					// "believe" brings in "faith", which must not outrank
					// "Bible" when choosing the article on the Bible.
					$scored[ $i ] += in_array( $word, $typed, true ) ? $weight : $weight / 2;
				}

				// A section's own heading says what it is about better than
				// anything in it: "6. The Holy Spirit", where "Spirit" is in
				// every other article too.
				$heading = array_intersect( $typed, Questions::words( mb_substr( $chunks[ $i ], 0, 40 ) ) );
				$scored[ $i ] += 1.5 * count( $heading );
			}

			arsort( $scored );
			$best  = array_slice( $scored, 0, 3, true );
			$top   = (float) reset( $best );
			$score = $in_title * 3 + $top * 2 + (float) next( $best );

			if ( $score < 4 ) {
				continue;
			}

			// Pages are about the church; posts are one moment of it.
			if ( 'post' === $post->post_type ) {
				$score -= 1;
			}

			$matches[] = [
				'post'   => $post,
				'score'  => (float) $score,
				'chunks' => $chunks,
				'best'   => array_keys( array_filter( $best ) ),
			];
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		foreach ( array_slice( $matches, 0, self::MAX ) as $match ) {
			$found[] = self::item( $match );
		}

		return $found;
	}

	/**
	 * The question's words, with what a page might call the same thing.
	 *
	 * @return string[]
	 */
	private static function question_words( string $question ): array {
		$plain = mb_strtolower( $question );
		$extra = [];

		foreach ( self::ALSO as $word => $also ) {
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/u', $plain ) ) {
				$extra[] = $also;
			}
		}

		$words = Questions::words( $question . ' ' . implode( ' ', $extra ) );

		// The church's own name is on every page, so it finds nothing in
		// particular, unless the question is about the name itself.
		if ( preg_match( '/\b(name|mean|meaning|called)\b/u', $plain ) ) {
			return array_values( array_unique( $words ) );
		}

		$name = Questions::words( class_exists( '\Seedcast\Core\Church' ) ? \Seedcast\Core\Church::name() : get_bloginfo( 'name' ) );

		return array_values( array_unique( array_diff( $words, array_merge( $name, [ 'church' ] ) ) ) );
	}

	/**
	 * A page's text in sections, at the breaks the page itself makes.
	 *
	 * @return string[]
	 */
	private static function chunks( \WP_Post $post ): array {
		$text   = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$text   = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$blocks = preg_split( '/\s*\n\s*/u', $text );
		$out    = [];
		$buffer = '';
		$body   = false;

		foreach ( (array) $blocks as $block ) {
			$block = trim( (string) preg_replace( '/\s+/u', ' ', $block ) );

			if ( '' === $block ) {
				continue;
			}

			$heading = mb_strlen( $block ) <= 60;

			/*
			 * A short line is a heading and starts the section it heads. Kept
			 * with the text before it, "2. The Bible" ended the article on
			 * God, and the article on the Bible went out without its name.
			 */
			if ( $body && ( $heading || mb_strlen( $buffer ) + mb_strlen( $block ) > self::CHUNK ) ) {
				$out[]  = $buffer;
				$buffer = '';
				$body   = false;
			}

			$buffer = '' === $buffer ? $block : $buffer . ' ' . $block;
			$body   = $body || ! $heading;
		}

		if ( '' !== $buffer ) {
			$out[] = $buffer;
		}

		// Nothing worth answering from.
		return mb_strlen( implode( ' ', $out ) ) < 80 ? [] : $out;
	}

	private static function item( array $match ): array {
		$post   = $match['post'];
		$chunks = $match['chunks'];

		// The sections nearest the question, in the page's own order; the
		// first section too when the page is short enough to carry it.
		$keep = $match['best'] ? $match['best'] : [ 0 ];
		sort( $keep );

		$text = '';
		$seen = [];

		foreach ( $keep as $i ) {
			// A page carrying the same section twice (a statement printed
			// once plain and once with its references) sends it once.
			$start = mb_strtolower( mb_substr( $chunks[ $i ], 0, 60 ) );

			if ( isset( $seen[ $start ] ) ) {
				continue;
			}

			$seen[ $start ] = true;
			$candidate      = trim( $text . "\n" . $chunks[ $i ] );

			if ( mb_strlen( $candidate ) > self::MAX_TEXT ) {
				break;
			}

			$text = $candidate;
		}

		if ( '' === $text ) {
			$text = mb_substr( $chunks[ $keep[0] ], 0, self::MAX_TEXT );
		}

		$is_post = 'post' === $post->post_type;

		return [
			'ref'      => ( $is_post ? 'article:' : 'page:' ) . $post->ID,
			'type'     => $is_post ? 'article' : 'page',
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'      => (string) get_permalink( $post ),
			'reason'   => $is_post
				/* translators: %s: date. */
				? sprintf( __( 'an article from %s', 'seedcast-sermon-library' ), get_the_date( 'F j, Y', $post ) )
				: __( 'one of our pages', 'seedcast-sermon-library' ),
			'text'     => $text,
			'modified' => (string) get_post_modified_time( 'U', true, $post ) . '|' . md5( $text ),
			'score'    => $match['score'],
		];
	}
}
