<?php
namespace SeedcastSermonLibrary\Scripture;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Passage rows for sermon cards on a scripture archive.
 *
 * A scripture page can list a sermon for either of two reasons: the passage is
 * what the sermon was built on, or the sermon merely touched it along the way.
 * From a list of cards those look identical, which makes the longer lists hard
 * to read and hides the sermons a reader most wants.
 *
 * So each card says which passages it actually touches, narrowed to the book
 * being viewed. Passages from other books are left out on purpose: on a page
 * about Acts, a sermon's references to Romans are noise.
 */
class CardPassages {

	/**
	 * The book whose page is being drawn, or an empty string when it is not a
	 * scripture archive.
	 *
	 * @var string
	 */
	private static $book = '';

	/**
	 * Start contributing rows for a book.
	 *
	 * @param string $book Canonical book name.
	 * @return void
	 */
	public static function begin( string $book ): void {
		self::$book = $book;

		add_filter( 'scsl_sermon_card_meta_rows', [ self::class, 'rows' ], 10, 2 );
	}

	/**
	 * Stop contributing, so cards drawn later on the page are unaffected.
	 *
	 * @return void
	 */
	public static function end(): void {
		remove_filter( 'scsl_sermon_card_meta_rows', [ self::class, 'rows' ], 10 );

		self::$book = '';
	}

	/**
	 * Build the rows for one sermon.
	 *
	 * @param string $rows      Rows contributed so far.
	 * @param int    $sermon_id Sermon being drawn.
	 * @return string Escaped HTML.
	 */
	public static function rows( $rows, $sermon_id ): string {
		if ( '' === self::$book ) {
			return (string) $rows;
		}

		$sermon_id = (int) $sermon_id;

		$focus = trim( (string) get_post_meta( $sermon_id, '_scsl_focus_passage', true ) );

		$others = get_post_meta( $sermon_id, '_scsl_other_passages', true );
		$others = is_array( $others ) ? array_filter( $others ) : [];

		// Runs joined and put in Bible order, the same treatment the sermon's
		// own panel gives them, so the two never disagree.
		$others = ScriptureParser::tidy( $others );

		// Only this book. A sermon on Acts that quotes Romans in passing has
		// nothing to say to somebody reading the Acts page.
		$others = array_values( array_filter( $others, static function ( $passage ) {
			return ScriptureParser::extract_book( (string) $passage ) === self::$book;
		} ) );

		/*
		 * A focus passage that is also the only listed passage would otherwise
		 * be printed twice under two labels, which reads as though the sermon
		 * covered more ground than it did.
		 */
		$others = array_values( array_filter( $others, static function ( $passage ) use ( $focus ) {
			return $passage !== $focus;
		} ) );

		$out = (string) $rows;

		if ( '' !== $focus ) {
			$out .= self::row(
				__( 'Focus Passage:', 'seedcast-sermon-library' ),
				[ $focus ]
			);
		}

		if ( $others ) {
			$out .= self::row(
				sprintf(
					/* translators: %s: a book of the Bible, for example Acts. */
					__( '%s Passages:', 'seedcast-sermon-library' ),
					self::$book
				),
				$others
			);
		}

		return $out;
	}

	/**
	 * One labelled row of passages, each linking out to the reader's Bible.
	 *
	 * @param string   $label
	 * @param string[] $passages
	 * @return string Escaped HTML.
	 */
	private static function row( string $label, array $passages ): string {
		$links = [];

		foreach ( $passages as $passage ) {
			$url = ScriptureParser::build_bible_url( (string) $passage );

			if ( '' === $url ) {
				$links[] = esc_html( $passage );
				continue;
			}

			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $url ),
				esc_html( $passage )
			);
		}

		if ( ! $links ) {
			return '';
		}

		return sprintf(
			'<p class="scsl-service-sermon-card__meta-row scsl-card-passages">'
			. '<span class="scsl-service-sermon-card__meta-label">%s</span> '
			. '<span class="scsl-service-sermon-card__meta-value">%s</span></p>',
			esc_html( $label ),
			implode( '; ', $links )
		);
	}
}
