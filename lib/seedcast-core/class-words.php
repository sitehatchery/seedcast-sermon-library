<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core
 */

namespace Seedcast\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words of a question, the way every suite plugin matches them.
 *
 * The assistant asks each plugin what it holds for a question, and each one
 * matches the question's words against its own content. Matched differently,
 * "times" finds "time" in one plugin and not the next. So the matching lives
 * here, once.
 *
 * Crude on purpose: lowercased, punctuation and filler words dropped, and
 * enough stemming that "forgiving" finds "forgive" and "services" finds
 * "service". Numbers are kept whatever their length, since "Romans 8" and
 * "Romans 12" are different questions.
 */
final class Words {

	/**
	 * Words that do not make one question different from another.
	 */
	public const STOPWORDS = array(
		'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'for', 'from',
		'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'of', 'on', 'or', 'our', 'please', 'say',
		'says', 'so', 'tell', 'that', 'the', 'their', 'there', 'this', 'to', 'us', 'was', 'we', 'what', 'whats',
		'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your', 'about', 'any', 'have',
		'has', 'should', 'church', 'know', 'want',
	);

	/**
	 * The words of a text that carry meaning, lightly stemmed.
	 *
	 * @param string $text Any text: a question, a title, a page.
	 * @return string[]
	 */
	public static function of( string $text ): array {
		$text  = mb_strtolower( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text  = (string) preg_replace( "/[^\\p{L}\\p{N}\\s]+/u", ' ', str_replace( array( "'", '’' ), '', $text ) );
		$words = preg_split( '/\s+/u', trim( $text ) );
		$out   = array();

		foreach ( (array) $words as $word ) {
			if ( '' === $word || ( mb_strlen( $word ) < 2 && ! ctype_digit( $word ) ) || in_array( $word, self::STOPWORDS, true ) ) {
				continue;
			}

			$out[] = self::stem( $word );
		}

		return $out;
	}

	/**
	 * Enough stemming that "times" finds "time" and "forgiving" finds
	 * "forgive", and no more.
	 *
	 * @param string $word One lowercased word.
	 * @return string
	 */
	public static function stem( string $word ): string {
		if ( mb_strlen( $word ) <= 3 ) {
			return $word;
		}

		foreach ( array( 'ings' => '', 'ing' => '', 'ness' => '', 'ies' => 'y', 'es' => '', 's' => '', 'ed' => '' ) as $suffix => $swap ) {
			$len = strlen( $suffix );

			if ( substr( $word, -$len ) === $suffix && mb_strlen( $word ) - $len >= 3 ) {
				$word = substr( $word, 0, -$len ) . $swap;
				break;
			}
		}

		// The same either way, so "time" and "times" meet at "tim".
		return mb_strlen( $word ) > 3 ? rtrim( $word, 'e' ) : $word;
	}

	/**
	 * A key for a question that forty phrasings of it share: its words,
	 * sorted.
	 *
	 * @param string $question The question as asked.
	 * @return string
	 */
	public static function key( string $question ): string {
		$words = self::of( $question );

		if ( ! $words ) {
			$words = array( mb_strtolower( trim( $question ) ) );
		}

		$words = array_unique( $words );
		sort( $words );

		return sha1( implode( ' ', $words ) );
	}
}
