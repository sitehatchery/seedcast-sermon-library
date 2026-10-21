<?php
namespace SeedcastSermonLibrary\Topics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Topic chips on the sermon cards of a passage or topic page.
 *
 * The same idea as the passage rows on a scripture page: a list of cards tells
 * a reader what each sermon is called and when it was preached, and nothing
 * about why it is on this page or where else it would take them. A sermon on
 * Ephesians 6 filed under Spiritual Warfare and Prayer says more in two words
 * than its title does.
 *
 * On a topic page the page's own subject is left off every card. It is true of
 * all of them, which makes it the one piece of information the reader already
 * has, and repeating it down the page crowds out the ones they do not.
 *
 * Contributes through the card's own filter and stops when the list ends, so
 * cards drawn elsewhere on the page are untouched.
 *
 * @package SeedcastSermonLibrary
 */
class CardTopics {

	/**
	 * How many to show on one card.
	 *
	 * The same three a sermon may carry, so this only ever trims a sermon
	 * filed before the cap existed.
	 */
	private const MOST = 3;

	/**
	 * The topic whose page is being drawn, left off its own cards.
	 *
	 * @var int
	 */
	private static $skip = 0;

	/**
	 * Whether this is contributing at all.
	 *
	 * @var bool
	 */
	private static $on = false;

	/**
	 * Start contributing a topics row to each card.
	 *
	 * @param int $skip_term_id A topic to leave off, where the page is about one.
	 * @return void
	 */
	public static function begin( int $skip_term_id = 0 ): void {
		if ( '1' !== (string) get_option( 'scsl_show_topics_on_cards', '1' ) ) {
			return;
		}

		self::$skip = $skip_term_id;
		self::$on   = true;

		add_filter( 'scsl_sermon_card_meta_rows', [ self::class, 'rows' ], 20, 2 );
	}

	/**
	 * Stop contributing, so cards drawn later on the page are unaffected.
	 *
	 * @return void
	 */
	public static function end(): void {
		if ( ! self::$on ) {
			return;
		}

		remove_filter( 'scsl_sermon_card_meta_rows', [ self::class, 'rows' ], 20 );

		self::$skip = 0;
		self::$on   = false;
	}

	/**
	 * Append the topics row.
	 *
	 * @param string $rows      Rows contributed so far.
	 * @param int    $sermon_id Sermon being drawn.
	 * @return string Escaped HTML.
	 */
	public static function rows( $rows, $sermon_id ): string {
		$rows      = (string) $rows;
		$sermon_id = (int) $sermon_id;

		$terms = get_the_terms( $sermon_id, 'scsl_topic' );

		if ( ! is_array( $terms ) || ! $terms ) {
			return $rows;
		}

		$terms = array_values( array_filter(
			$terms,
			static function ( $term ) {
				return $term instanceof \WP_Term && (int) $term->term_id !== self::$skip;
			}
		) );

		if ( ! $terms ) {
			return $rows;
		}

		// Best filled in first, so a card trimmed to three offers the topics
		// that lead to a page worth arriving at.
		usort(
			$terms,
			static function ( $a, $b ) {
				return (int) $b->count <=> (int) $a->count;
			}
		);

		$chips = [];

		foreach ( array_slice( $terms, 0, self::MOST ) as $term ) {
			$url = get_term_link( $term );

			if ( is_wp_error( $url ) ) {
				continue;
			}

			$chips[] = sprintf(
				'<a href="%s" class="scsl-topic-tag">%s</a>',
				esc_url( $url ),
				esc_html( $term->name )
			);
		}

		if ( ! $chips ) {
			return $rows;
		}

		return $rows . sprintf(
			'<p class="scsl-service-sermon-card__meta-row scsl-card-topics">'
			. '<span class="scsl-service-sermon-card__meta-label">%s</span>%s</p>',
			esc_html__( 'Topics', 'seedcast-sermon-library' ),
			implode( ' ', $chips )
		);
	}
}
