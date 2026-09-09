<?php
namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Questions a sermon answers, stored against the sermon and shown on its page.
 *
 * A sermon is forty minutes of someone answering questions people actually
 * have. Left as prose, that is only reachable by reading the whole thing. Set
 * out as questions and answers it is reachable by anybody scanning the page,
 * and by anything reading it.
 *
 * A note on what this is worth. Google stopped showing FAQ rich results for
 * ordinary sites in 2023, so this is not a route to a rich snippet and should
 * not be sold as one. It earns its place because the questions are visible
 * content that answers what somebody typed, and because assistants extract a
 * clean question and answer far more reliably than they extract the same point
 * buried in a transcript.
 *
 * Written by whatever produces the rest of a sermon's content: this class only
 * reads, renders and describes.
 *
 * Stored in `_scsl_faq` as a list of [ 'question' => ..., 'answer' => ... ].
 */
class Faq {

	/** Where the pairs live. */
	public const META = '_scsl_faq';

	/**
	 * The questions for a sermon, cleaned and in order.
	 *
	 * Anything without both halves is dropped: a question with no answer is
	 * worse than nothing, on the page and in the structured data alike.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function get( int $post_id ): array {
		/**
		 * The questions shown for a sermon.
		 *
		 * @param array $out     Cleaned pairs.
		 * @param int   $post_id Sermon.
		 */
		return (array) apply_filters( 'scsl_sermon_faq', self::stored( $post_id ), $post_id );
	}

	/**
	 * The pairs as they are actually stored, cleaned but unfiltered.
	 *
	 * The editor writes back what it was given, so it has to read what is in
	 * the database rather than what a filter added on the way out. Otherwise a
	 * site injecting a standing question would find it saved into every sermon
	 * the first time somebody pressed Update.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function stored( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META, true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : [];
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];

		foreach ( $raw as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}

			$q = trim( wp_strip_all_tags( (string) ( $pair['question'] ?? $pair['q'] ?? '' ) ) );
			$a = self::unwrap( (string) ( $pair['answer'] ?? $pair['a'] ?? '' ) );

			if ( '' === $q || '' === wp_strip_all_tags( $a ) ) {
				continue;
			}

			$out[] = [ 'question' => $q, 'answer' => $a ];
		}

		return $out;
	}

	/**
	 * An answer as text, with its paragraphs as blank lines.
	 *
	 * The API returns an answer wrapped in paragraph tags, and so does anything
	 * pasted out of a document. Those tags carry nothing a blank line does not,
	 * and they make the field unpleasant to write in by hand, so they come off
	 * here and wpautop() puts them back at the point of display. Markup that
	 * does carry something, a list or an emphasis, is left where it is.
	 *
	 * Done on the way out rather than by a migration, so an answer stored by an
	 * earlier version reads the same as one typed this morning.
	 */
	private static function unwrap( string $answer ): string {
		$answer = (string) preg_replace( '#<br\s*/?>#i', "\n", $answer );
		$answer = (string) preg_replace( '#</p\s*>#i', "\n\n", $answer );
		$answer = (string) preg_replace( '#<p[^>]*>#i', '', $answer );

		// Trailing spaces on a line and a third blank line are artefacts of the
		// unwrapping, not something anybody wrote.
		$answer = (string) preg_replace( '/[ \t]+\n/', "\n", $answer );
		$answer = (string) preg_replace( '/\n{3,}/', "\n\n", $answer );

		return trim( $answer );
	}
	/**
	 * @return bool
	 */
	public static function has( int $post_id ): bool {
		return (bool) self::get( $post_id );
	}

	/**
	 * The visible section.
	 *
	 * Real headings and real paragraphs, not a script that reveals them on
	 * click. Structured data is only allowed to describe what a visitor can
	 * actually read, and an assistant reading the page needs the same thing.
	 *
	 * @return string Escaped HTML.
	 */
	public static function render( int $post_id ): string {
		$pairs = self::get( $post_id );

		if ( ! $pairs ) {
			return '';
		}

		/*
		 * An ordered list, because the numbers are part of how this reads and a
		 * screen reader should announce them too. The visible badge is drawn
		 * from CSS rather than the list marker so it can be styled, and the
		 * marker itself is hidden.
		 */
		$out = '<ol class="scsl-faq">';

		foreach ( $pairs as $pair ) {
			$out .= '<li class="scsl-faq__item">';
			$out .= '<div class="scsl-faq__body">';
			$out .= '<h3 class="scsl-faq__question">' . esc_html( $pair['question'] ) . '</h3>';
			$out .= '<div class="scsl-faq__answer">' . wp_kses_post( wpautop( $pair['answer'] ) ) . '</div>';
			$out .= '</div>';
			$out .= '</li>';
		}

		return $out . '</ol>';
	}

	/**
	 * A FAQPage node for the sermon's schema graph.
	 *
	 * @return array|null Null when there is nothing to describe.
	 */
	public static function schema( int $post_id ): ?array {
		$pairs = self::get( $post_id );

		if ( ! $pairs ) {
			return null;
		}

		$entities = [];

		foreach ( $pairs as $pair ) {
			$entities[] = [
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => [
					'@type' => 'Answer',
					// Plain text: the answer as a reader would hear it, without
					// the markup used to lay it out.
					'text'  => trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $pair['answer'] ) ) ),
				],
			];
		}

		return [
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $post_id ) . '#faq',
			'mainEntity' => $entities,
		];
	}
}
