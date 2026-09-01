<?php
/**
 * Slugs that keep a passage readable.
 *
 * WordPress removes a colon rather than treating it as a break, so
 * "Isaiah 59:16-17" becomes isaiah-5916-17. The chapter and the verse run
 * together into a number that is not either of them, which is ambiguous to a
 * reader and invisible to a search engine: 5916 is one word, and nobody looks
 * for it. Separated, the same address carries isaiah, 59, 16 and 17, which are
 * the words somebody actually types.
 *
 * This is permanent. The one-off that repaired the addresses already published
 * is separate and meant to be deleted.
 *
 * @package SeedcastSermonLibrary
 */

namespace SeedcastSermonLibrary;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Slug {

	/**
	 * A slug with chapter and verse kept apart.
	 *
	 * @param string $text A reference or a sermon title.
	 * @return string
	 */
	public static function from( string $text ): string {
		// Every separator a reference is written with, turned into the one
		// WordPress treats as a word break. Colons and full stops divide
		// chapter from verse, and en and em dashes turn up in ranges typed by
		// anyone whose editor helpfully replaced the hyphen.
		$text = (string) preg_replace( '/[:.\x{2013}\x{2014}]+/u', '-', $text );

		return sanitize_title( $text );
	}

	/**
	 * Whether a slug would be written differently now.
	 *
	 * @param string $text    The reference or title it came from.
	 * @param string $current The slug in use.
	 * @return bool
	 */
	public static function differs( string $text, string $current ): bool {
		$wanted = self::from( $text );

		return '' !== $wanted && $wanted !== $current;
	}
}
