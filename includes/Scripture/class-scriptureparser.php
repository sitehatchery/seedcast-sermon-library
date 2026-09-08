<?php
namespace SeedcastSermonLibrary\Scripture;

if ( ! defined( 'ABSPATH' ) ) exit;

class ScriptureParser {

	/**
	 * All 66 books with common abbreviations mapped to canonical name.
	 */
	private static array $book_map = [
		// Old Testament
		'genesis' => 'Genesis', 'gen' => 'Genesis', 'ge' => 'Genesis',
		'exodus' => 'Exodus', 'exod' => 'Exodus', 'ex' => 'Exodus',
		'leviticus' => 'Leviticus', 'lev' => 'Leviticus', 'le' => 'Leviticus',
		'numbers' => 'Numbers', 'num' => 'Numbers', 'nu' => 'Numbers',
		'deuteronomy' => 'Deuteronomy', 'deut' => 'Deuteronomy', 'dt' => 'Deuteronomy',
		'joshua' => 'Joshua', 'josh' => 'Joshua', 'jos' => 'Joshua',
		'judges' => 'Judges', 'judg' => 'Judges', 'jdg' => 'Judges',
		'ruth' => 'Ruth', 'ru' => 'Ruth',
		'1 samuel' => '1 Samuel', '1samuel' => '1 Samuel', '1sam' => '1 Samuel', '1sa' => '1 Samuel',
		'2 samuel' => '2 Samuel', '2samuel' => '2 Samuel', '2sam' => '2 Samuel', '2sa' => '2 Samuel',
		'1 kings' => '1 Kings', '1kings' => '1 Kings', '1kgs' => '1 Kings', '1ki' => '1 Kings',
		'2 kings' => '2 Kings', '2kings' => '2 Kings', '2kgs' => '2 Kings', '2ki' => '2 Kings',
		'1 chronicles' => '1 Chronicles', '1chronicles' => '1 Chronicles', '1chr' => '1 Chronicles', '1ch' => '1 Chronicles',
		'2 chronicles' => '2 Chronicles', '2chronicles' => '2 Chronicles', '2chr' => '2 Chronicles', '2ch' => '2 Chronicles',
		'ezra' => 'Ezra', 'ezr' => 'Ezra',
		'nehemiah' => 'Nehemiah', 'neh' => 'Nehemiah', 'ne' => 'Nehemiah',
		'esther' => 'Esther', 'est' => 'Esther', 'es' => 'Esther',
		'job' => 'Job', 'jb' => 'Job',
		'psalms' => 'Psalms', 'psalm' => 'Psalms', 'ps' => 'Psalms', 'psa' => 'Psalms',
		'proverbs' => 'Proverbs', 'prov' => 'Proverbs', 'pr' => 'Proverbs',
		'ecclesiastes' => 'Ecclesiastes', 'eccl' => 'Ecclesiastes', 'ec' => 'Ecclesiastes',
		'song of solomon' => 'Song of Solomon', 'song' => 'Song of Solomon', 'sos' => 'Song of Solomon', 'ss' => 'Song of Solomon',
		'isaiah' => 'Isaiah', 'isa' => 'Isaiah',
		'jeremiah' => 'Jeremiah', 'jer' => 'Jeremiah', 'je' => 'Jeremiah',
		'lamentations' => 'Lamentations', 'lam' => 'Lamentations', 'la' => 'Lamentations',
		'ezekiel' => 'Ezekiel', 'ezek' => 'Ezekiel', 'eze' => 'Ezekiel',
		'daniel' => 'Daniel', 'dan' => 'Daniel', 'da' => 'Daniel',
		'hosea' => 'Hosea', 'hos' => 'Hosea', 'ho' => 'Hosea',
		'joel' => 'Joel', 'joe' => 'Joel',
		'amos' => 'Amos', 'am' => 'Amos',
		'obadiah' => 'Obadiah', 'obad' => 'Obadiah', 'ob' => 'Obadiah',
		'jonah' => 'Jonah', 'jon' => 'Jonah',
		'micah' => 'Micah', 'mic' => 'Micah',
		'nahum' => 'Nahum', 'nah' => 'Nahum', 'na' => 'Nahum',
		'habakkuk' => 'Habakkuk', 'hab' => 'Habakkuk',
		'zephaniah' => 'Zephaniah', 'zeph' => 'Zephaniah', 'zep' => 'Zephaniah',
		'haggai' => 'Haggai', 'hag' => 'Haggai',
		'zechariah' => 'Zechariah', 'zech' => 'Zechariah', 'zec' => 'Zechariah',
		'malachi' => 'Malachi', 'mal' => 'Malachi',
		// New Testament
		'matthew' => 'Matthew', 'matt' => 'Matthew', 'mt' => 'Matthew',
		'mark' => 'Mark', 'mk' => 'Mark', 'mr' => 'Mark',
		'luke' => 'Luke', 'lk' => 'Luke', 'lu' => 'Luke',
		'john' => 'John', 'jn' => 'John', 'joh' => 'John',
		'acts' => 'Acts', 'ac' => 'Acts',
		'romans' => 'Romans', 'rom' => 'Romans', 'ro' => 'Romans',
		'1 corinthians' => '1 Corinthians', '1corinthians' => '1 Corinthians', '1cor' => '1 Corinthians', '1co' => '1 Corinthians',
		'2 corinthians' => '2 Corinthians', '2corinthians' => '2 Corinthians', '2cor' => '2 Corinthians', '2co' => '2 Corinthians',
		'galatians' => 'Galatians', 'gal' => 'Galatians', 'ga' => 'Galatians',
		'ephesians' => 'Ephesians', 'eph' => 'Ephesians',
		'philippians' => 'Philippians', 'phil' => 'Philippians', 'php' => 'Philippians',
		'colossians' => 'Colossians', 'col' => 'Colossians',
		'1 thessalonians' => '1 Thessalonians', '1thessalonians' => '1 Thessalonians', '1thes' => '1 Thessalonians',
		'1thess' => '1 Thessalonians', '1th' => '1 Thessalonians',
		'2 thessalonians' => '2 Thessalonians', '2thessalonians' => '2 Thessalonians', '2thes' => '2 Thessalonians',
		'2thess' => '2 Thessalonians', '2th' => '2 Thessalonians',
		'1 timothy' => '1 Timothy', '1timothy' => '1 Timothy', '1tim' => '1 Timothy', '1ti' => '1 Timothy',
		'2 timothy' => '2 Timothy', '2timothy' => '2 Timothy', '2tim' => '2 Timothy', '2ti' => '2 Timothy',
		'titus' => 'Titus', 'tit' => 'Titus',
		'philemon' => 'Philemon', 'phlm' => 'Philemon', 'phm' => 'Philemon',
		'hebrews' => 'Hebrews', 'heb' => 'Hebrews',
		'james' => 'James', 'jas' => 'James', 'jm' => 'James',
		'1 peter' => '1 Peter', '1peter' => '1 Peter', '1pet' => '1 Peter', '1pe' => '1 Peter',
		'2 peter' => '2 Peter', '2peter' => '2 Peter', '2pet' => '2 Peter', '2pe' => '2 Peter',
		'1 john' => '1 John', '1john' => '1 John', '1jn' => '1 John', '1jo' => '1 John',
		'2 john' => '2 John', '2john' => '2 John', '2jn' => '2 John', '2jo' => '2 John',
		'3 john' => '3 John', '3john' => '3 John', '3jn' => '3 John', '3jo' => '3 John',
		'jude' => 'Jude', 'jud' => 'Jude',
		'revelation' => 'Revelation', 'rev' => 'Revelation', 're' => 'Revelation',
	];

	/**
	 * Book map keys, longest first. Built on first use.
	 *
	 * @var string[]|null
	 */
	private static $sorted_keys = null;

	/**
	 * Parsed spans, keyed by the reference they came from.
	 *
	 * The same passage is parsed repeatedly within a single request, most of
	 * all by overlaps() comparing one term against the whole taxonomy.
	 *
	 * @var array<string, array|null>
	 */
	private static $span_cache = [];

	/**
	 * Extract canonical book name from a free-text reference like "John 3:16-17"
	 */
	public static function extract_book( string $reference ): ?string {
		$ref = trim( $reference );

		// Handle numbered books: "1 John", "1John", "1st John", "First John"
		$ref = preg_replace( '/\b(1st|first)\s*/i',   '1 ', $ref );
		$ref = preg_replace( '/\b(2nd|second)\s*/i',  '2 ', $ref );
		$ref = preg_replace( '/\b(3rd|third)\s*/i',   '3 ', $ref );

		// Strip verse/chapter numbers to isolate book name
		// "John 3:16-17" -> try progressively shorter prefixes
		$lower = strtolower( $ref );

		/*
		 * Longest match first, so "Song of Solomon" is not cut short by "song".
		 *
		 * Sorted once per request rather than once per call. A scripture page
		 * compares its passage against every other term in the taxonomy, twice
		 * each, so on a site with a couple of thousand references this ran a
		 * two-hundred element sort several thousand times and cost seconds.
		 */
		if ( null === self::$sorted_keys ) {
			$keys = array_keys( self::$book_map );
			usort( $keys, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
			self::$sorted_keys = $keys;
		}

		foreach ( self::$sorted_keys as $key ) {
			if ( strpos( $lower, $key ) === 0 ) {
				return self::$book_map[ $key ];
			}
		}

		return null;
	}

	/**
	 * Extract all unique book names from an array of references.
	 */
	public static function extract_books( array $references ): array {
		$books = [];
		foreach ( $references as $ref ) {
			$book = self::extract_book( $ref );
			if ( $book && ! in_array( $book, $books, true ) ) {
				$books[] = $book;
			}
		}
		return $books;
	}

	/**
	 * Build a Bible.com URL for a reference.
	 * Bible.com format: /bible/{version_id}/{BOOK_ABBREV}.{chapter}.{verse}
	 * e.g. https://www.bible.com/bible/59/LUK.6.37-45
	 */
	/**
	 * The page on this site for a passage, when there is one.
	 *
	 * These pages list every sermon a church has preached from a passage, and
	 * nothing anywhere linked to them. They existed, they sat in the sitemap,
	 * search engines indexed them, and no visitor could reach one by clicking
	 * anything. The scripture panel is the natural way in, because it is the
	 * only place a passage is already named on a page somebody is reading.
	 *
	 * Empty when the reference was never made into a term, which happens for
	 * anything entered outside the sermon screen. The caller shows plain text
	 * rather than a link to nowhere.
	 *
	 * @param string $reference A passage as written on the sermon.
	 * @return string
	 */
	/**
	 * A reference as a span that can be compared with another.
	 *
	 * Passages are stored as whatever somebody typed, so "Ephesians 6:15" and
	 * "Ephesians 6:10-20" are unrelated pieces of text even though the first
	 * sits inside the second. Comparing the text finds only exact matches,
	 * which is why a page for one verse could sit empty while a sermon on the
	 * paragraph containing it was a click away.
	 *
	 * Chapter and verse are folded into one number so a span is two integers
	 * and overlap is arithmetic. A reference with no verse covers the whole
	 * chapter, and one with no chapter covers the whole book, because that is
	 * what somebody writing "Ephesians 6" means.
	 *
	 * @param string $reference A passage as written.
	 * @return array{book: string, start: int, end: int}|null
	 */
	public static function to_span( string $reference ): ?array {
		// Memoised: overlaps() parses the queried passage once per term in the
		// taxonomy, and the answer for a given string never changes.
		if ( array_key_exists( $reference, self::$span_cache ) ) {
			return self::$span_cache[ $reference ];
		}

		$span = self::compute_span( $reference );

		self::$span_cache[ $reference ] = $span;

		return $span;
	}

	/**
	 * Work out a span without consulting the cache.
	 *
	 * @return array{book: string, start: int, end: int}|null
	 */
	private static function compute_span( string $reference ): ?array {
		$book = self::extract_book( $reference );

		if ( ! $book ) return null;

		// Everything after the book name is the chapter and verse part.
		$tail = trim( (string) preg_replace( '/^\s*\d?\s*[a-z]+(\s+of\s+\w+)?\s*/i', '', trim( $reference ) ) );

		$point = static function ( int $chapter, int $verse ): int {
			return ( $chapter * 1000 ) + $verse;
		};

		// Whole book.
		if ( '' === $tail || ! preg_match( '/\d/', $tail ) ) {
			return [ 'book' => $book, 'start' => $point( 0, 0 ), 'end' => $point( 999, 999 ) ];
		}

		// "5:1-6:9" spans chapters. "6:10-20" stays in one. "6:15" is a point.
		// "6" is a whole chapter.
		if ( preg_match( '/^(\d+):(\d+)\s*[-–—]\s*(\d+):(\d+)/', $tail, $m ) ) {
			return [ 'book' => $book, 'start' => $point( (int) $m[1], (int) $m[2] ), 'end' => $point( (int) $m[3], (int) $m[4] ) ];
		}

		if ( preg_match( '/^(\d+):(\d+)\s*[-–—]\s*(\d+)/', $tail, $m ) ) {
			return [ 'book' => $book, 'start' => $point( (int) $m[1], (int) $m[2] ), 'end' => $point( (int) $m[1], (int) $m[3] ) ];
		}

		if ( preg_match( '/^(\d+):(\d+)/', $tail, $m ) ) {
			return [ 'book' => $book, 'start' => $point( (int) $m[1], (int) $m[2] ), 'end' => $point( (int) $m[1], (int) $m[2] ) ];
		}

		if ( preg_match( '/^(\d+)\s*[-–—]\s*(\d+)/', $tail, $m ) ) {
			return [ 'book' => $book, 'start' => $point( (int) $m[1], 0 ), 'end' => $point( (int) $m[2], 999 ) ];
		}

		if ( preg_match( '/^(\d+)/', $tail, $m ) ) {
			return [ 'book' => $book, 'start' => $point( (int) $m[1], 0 ), 'end' => $point( (int) $m[1], 999 ) ];
		}

		return [ 'book' => $book, 'start' => $point( 0, 0 ), 'end' => $point( 999, 999 ) ];
	}

	/**
	 * Whether two references touch the same words.
	 *
	 * @param string $a A passage.
	 * @param string $b Another passage.
	 * @return bool
	 */
	/**
	 * Passages in the order a Bible is in, and joined where they run together.
	 *
	 * A sermon that quotes eight consecutive verses lists eight passages, in
	 * whatever order they were typed. Sorted and joined it reads as the one
	 * span it actually was.
	 *
	 * Joining requires the next passage to begin no later than one verse after
	 * the last one ended. Anything looser would claim a verse was covered when
	 * it was skipped: a sermon on 6:10 and 6:12 did not preach 6:11, and saying
	 * "6:10-12" would put words in the preacher's mouth.
	 *
	 * Anything unparseable is kept exactly as written and placed at the end.
	 * A church's own phrasing is not a reason to drop it.
	 *
	 * @param string[] $references Passages as written.
	 * @return string[] Passages to show.
	 */
	public static function tidy( array $references ): array {
		$order   = array_flip( self::all_books() );
		$spans   = [];
		$unknown = [];

		foreach ( $references as $reference ) {
			$reference = trim( (string) $reference );

			if ( '' === $reference ) continue;

			$span = self::to_span( $reference );

			if ( ! $span ) {
				$unknown[] = $reference;
				continue;
			}

			$span['label'] = $reference;
			$spans[]       = $span;
		}

		usort( $spans, static function ( $a, $b ) use ( $order ) {
			$book = ( $order[ $a['book'] ] ?? PHP_INT_MAX ) <=> ( $order[ $b['book'] ] ?? PHP_INT_MAX );

			return 0 !== $book ? $book : ( $a['start'] <=> $b['start'] );
		} );

		$out = [];

		foreach ( $spans as $span ) {
			$last = $out ? $out[ count( $out ) - 1 ] : null;

			$joins = $last
				&& $last['book'] === $span['book']
				&& $span['start'] <= $last['end'] + 1;

			if ( ! $joins ) {
				$out[] = $span;
				continue;
			}

			// Wholly inside what is already there, so it adds nothing.
			if ( $span['end'] <= $last['end'] ) continue;

			$out[ count( $out ) - 1 ]['end']   = $span['end'];
			$out[ count( $out ) - 1 ]['label'] = self::join_labels( $last['label'], $span['label'] );
		}

		return array_merge( array_column( $out, 'label' ), $unknown );
	}

	/**
	 * A label covering two passages that run together.
	 *
	 * @param string $first  The earlier passage, as written.
	 * @param string $second The later passage, as written.
	 * @return string
	 */
	private static function join_labels( string $first, string $second ): string {
		// The end of the later passage, which is the only part of it the joined
		// label needs. Everything before that already appears in the first.
		if ( preg_match( '/(\d+)\s*$/', $second, $m ) ) {
			$tail = $m[1];

			// Strip any range the first already carried, so joining 6:10-12
			// with 6:13 gives 6:10-13 rather than 6:10-12-13.
			$head = (string) preg_replace( '/\s*[-\x{2013}\x{2014}]\s*\d+\s*$/u', '', $first );

			return $head . '-' . $tail;
		}

		return $first;
	}

	public static function overlaps( string $a, string $b ): bool {
		$sa = self::to_span( $a );
		$sb = self::to_span( $b );

		if ( ! $sa || ! $sb || $sa['book'] !== $sb['book'] ) return false;

		return $sa['start'] <= $sb['end'] && $sb['start'] <= $sa['end'];
	}

	public static function term_url( string $reference ): string {
		$reference = trim( $reference );

		if ( '' === $reference ) return '';

		$term = get_term_by( 'name', $reference, 'scsl_scripture' );

		/*
		 * Nothing recorded under that exact wording, so the nearest passage
		 * that covers it will do.
		 *
		 * Joining a run of verses produces a label like "Ephesians 6:10-12"
		 * that no sermon was filed under, because the sermons were filed under
		 * the verses separately. Without this the joined line would lose its
		 * link and the tidier list would cost the reader a way through.
		 *
		 * The page it lands on is the right one regardless of which of the
		 * overlapping passages it picks, because that archive matches anything
		 * covering the same words rather than the identical wording.
		 */
		if ( ! $term instanceof \WP_Term ) {
			$term = self::nearest_term( $reference );
		}

		if ( ! $term instanceof \WP_Term ) return '';

		$url = get_term_link( $term );

		return is_wp_error( $url ) ? '' : (string) $url;
	}

	/**
	 * A recorded passage that covers the words of this one.
	 *
	 * @param string $reference A passage as written.
	 * @return \WP_Term|null
	 */
	private static function nearest_term( string $reference ): ?\WP_Term {
		$span = self::to_span( $reference );

		if ( ! $span ) return null;

		$terms = get_terms( [ 'taxonomy' => 'scsl_scripture', 'hide_empty' => true ] );

		if ( is_wp_error( $terms ) ) return null;

		$best = null;

		foreach ( $terms as $term ) {
			if ( ! self::overlaps( $reference, (string) $term->name ) ) continue;

			$other = self::to_span( (string) $term->name );

			if ( ! $other ) continue;

			// The one starting closest to where this one starts, so a joined
			// run lands on its own first verse rather than on whichever
			// passage happens to be first alphabetically.
			if ( null === $best || abs( $other['start'] - $span['start'] ) < $best['distance'] ) {
				$best = [ 'term' => $term, 'distance' => abs( $other['start'] - $span['start'] ) ];
			}
		}

		return $best ? $best['term'] : null;
	}

	public static function build_bible_url( string $reference, string $translation = 'NIV' ): string {
		$provider = get_option( 'scsl_bible_provider', 'bible.com' );
		$trans    = get_option( 'scsl_bible_translation', 'NIV' );

		$trans_codes = [
			'NIV'  => 111,
			'ESV'  => 59,
			'NLT'  => 116,
			'KJV'  => 1,
			'NASB' => 100,
			'CSB'  => 1713,
			'MSG'  => 97,
		];

		$code = $trans_codes[ $trans ] ?? 111;

		if ( $provider === 'biblegateway' ) {
			return 'https://www.biblegateway.com/passage/?search=' . rawurlencode( $reference ) . '&version=' . $trans;
		}

		// Build Bible.com URL in format: /bible/{code}/{ABBREV}.{chapter}.{verse}
		// e.g. "Luke 6:37-45" → LUK.6.37-45
		$usfm = self::to_usfm( $reference );
		if ( $usfm ) {
			return "https://www.bible.com/bible/{$code}/{$usfm}";
		}

		// Fallback: search page
		return 'https://www.bible.com/search/bible?q=' . rawurlencode( $reference ) . '&version_id=' . $code;
	}

	/**
	 * Convert a free-text reference to USFM format for Bible.com URLs.
	 * "Luke 6:37-45"  → "LUK.6.37-45"
	 * "John 3:16"     → "JHN.3.16"
	 * "Romans 8:1-4"  → "ROM.8.1-4"
	 */
	public static function to_usfm( string $reference ): string {
		// Map canonical book names to USFM 3-letter codes
		$usfm_codes = [
			'Genesis' => 'GEN', 'Exodus' => 'EXO', 'Leviticus' => 'LEV',
			'Numbers' => 'NUM', 'Deuteronomy' => 'DEU', 'Joshua' => 'JOS',
			'Judges' => 'JDG', 'Ruth' => 'RUT', '1 Samuel' => '1SA',
			'2 Samuel' => '2SA', '1 Kings' => '1KI', '2 Kings' => '2KI',
			'1 Chronicles' => '1CH', '2 Chronicles' => '2CH', 'Ezra' => 'EZR',
			'Nehemiah' => 'NEH', 'Esther' => 'EST', 'Job' => 'JOB',
			'Psalms' => 'PSA', 'Proverbs' => 'PRO', 'Ecclesiastes' => 'ECC',
			'Song of Solomon' => 'SNG', 'Isaiah' => 'ISA', 'Jeremiah' => 'JER',
			'Lamentations' => 'LAM', 'Ezekiel' => 'EZK', 'Daniel' => 'DAN',
			'Hosea' => 'HOS', 'Joel' => 'JOL', 'Amos' => 'AMO',
			'Obadiah' => 'OBA', 'Jonah' => 'JON', 'Micah' => 'MIC',
			'Nahum' => 'NAM', 'Habakkuk' => 'HAB', 'Zephaniah' => 'ZEP',
			'Haggai' => 'HAG', 'Zechariah' => 'ZEC', 'Malachi' => 'MAL',
			'Matthew' => 'MAT', 'Mark' => 'MRK', 'Luke' => 'LUK',
			'John' => 'JHN', 'Acts' => 'ACT', 'Romans' => 'ROM',
			'1 Corinthians' => '1CO', '2 Corinthians' => '2CO', 'Galatians' => 'GAL',
			'Ephesians' => 'EPH', 'Philippians' => 'PHP', 'Colossians' => 'COL',
			'1 Thessalonians' => '1TH', '2 Thessalonians' => '2TH',
			'1 Timothy' => '1TI', '2 Timothy' => '2TI', 'Titus' => 'TIT',
			'Philemon' => 'PHM', 'Hebrews' => 'HEB', 'James' => 'JAS',
			'1 Peter' => '1PE', '2 Peter' => '2PE', '1 John' => '1JN',
			'2 John' => '2JN', '3 John' => '3JN', 'Jude' => 'JUD',
			'Revelation' => 'REV',
		];

		// Extract the book name
		$book = self::extract_book( $reference );
		if ( ! $book || ! isset( $usfm_codes[ $book ] ) ) return '';

		$abbrev = $usfm_codes[ $book ];

		// Strip the book prefix from the ORIGINAL reference (not using canonical name length)
		// e.g. "Lk 6:37-45": book maps to "Luke" but reference uses "Lk"
		// Find where the digits start after any book abbreviation
		if ( preg_match( '/^[1-3]?\s*[A-Za-z]+(?:\s+[A-Za-z]+)*\s+(\d+(?::\d+(?:-\d+)?)?)/', trim( $reference ), $m ) ) {
			$chapter_verse = $m[1];
		} else {
			return $abbrev;
		}

		// Parse "6:37-45" or "6:37" or just "6"
		if ( preg_match( '/^(\d+):(\d+(?:-\d+)?)/', $chapter_verse, $cv ) ) {
			return "{$abbrev}.{$cv[1]}.{$cv[2]}";
		}
		if ( preg_match( '/^(\d+)$/', $chapter_verse, $cv ) ) {
			return "{$abbrev}.{$cv[1]}";
		}

		return $abbrev;
	}

	/**
	 * Return all 66 canonical book names for filter dropdowns.
	 */
	/**
	 * The passage a sermon title is about, when the title makes it plain.
	 *
	 * Church titles carry a series name and a passage in the same line, and
	 * the two look alike: "1 & 2 Thessalonians #2 - Example - 1 Thes 1:5b-2:14"
	 * names a book twice and means the second one. A person reads that without
	 * effort because the second reference is more specific, and that is the
	 * whole rule here.
	 *
	 * References are ranked by how exactly they point: a range beats a single
	 * verse, a verse beats a chapter, a chapter beats a bare book name. The
	 * most exact one wins. Two equally exact ones mean the title is genuinely
	 * ambiguous and nothing is offered, which is the honest answer: guessing
	 * between them would be wrong half the time and wrong quietly.
	 *
	 * @param string $title A sermon title.
	 * @return string Empty when the title does not say, or says two things.
	 */
	public static function focus_from_title( string $title ): string {
		$title = str_replace( [ '&amp;', '&#038;', '–', '—' ], [ '&', '&', '-', '-' ], $title );

		// Every book name and abbreviation the map knows, longest first so
		// that "1 thessalonians" is matched before "1 th".
		$names = array_keys( self::$book_map );

		usort( $names, static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		} );

		$alternates = [];

		foreach ( $names as $name ) {
			/*
			 * Very short abbreviations are left out.
			 *
			 * The map holds keys like "es" and "am", and with a chapter after
			 * them a title reading "Am 3 things to know" becomes Amos 3.
			 * Three letters is enough to be a deliberate abbreviation rather
			 * than a coincidence, and nobody shortens a book past that when
			 * writing a passage down.
			 */
			$letters = (string) preg_replace( '/[^a-z]/i', '', $name );

			if ( strlen( $letters ) < 3 ) continue;

			/*
			 * A space is optional wherever the name has one, and after a
			 * leading number whether or not the key has one.
			 *
			 * The map holds both "1 thessalonians" and "1thess" and people
			 * type either, so matching each key exactly as stored meant
			 * "1 Thes" could find neither.
			 */
			$quoted = preg_quote( $name, '/' );
			$quoted = str_replace( ' ', '\s*', $quoted );
			$quoted = (string) preg_replace( '/^(\d)/', '$1\s*', $quoted );

			$alternates[] = $quoted;
		}

		$books = implode( '|', $alternates );

		// Chapter, then optionally verse, then optionally an end that may
		// cross into another chapter. A letter after a verse is how half a
		// verse is written and is kept.
		/*
		 * Anchored to word edges, and only counted when a number follows.
		 *
		 * Without the first, a short abbreviation is found inside an ordinary
		 * word: "Example" contains "Es" and produced Esther, which is not a
		 * decline but a confident wrong answer that reads as though somebody
		 * meant it. Without the second, any word that happens to be a book name
		 * is treated as a passage, which is how a series called Elijah becomes
		 * one.
		 */
		$pattern = '/(?<![A-Za-z])(' . $books . ')\.?\s*'
			. '(\d+)'
			. '(?::(\d+)[a-z]?)?'
			. '(?:\s*-\s*(?:(\d+):)?(\d+)[a-z]?)?'
			. '(?![A-Za-z])/i';

		if ( ! preg_match_all( $pattern, $title, $matches, PREG_SET_ORDER ) ) return '';

		$best  = null;
		$score = -1;
		$tied  = false;

		foreach ( $matches as $m ) {
			$text = trim( (string) $m[0] );

			if ( '' === $text ) continue;

			$rank = 0;

			$rank = 1;                                      // a chapter, always
			if ( ! empty( $m[3] ) ) $rank = 2;              // a verse
			if ( ! empty( $m[5] ) ) $rank = 3;              // a range

			if ( $rank > $score ) {
				$score = $rank;
				$best  = $text;
				$tied  = false;
			} elseif ( $rank === $score && $rank > 0 ) {
				$tied = true;
			}
		}

		// A title naming only books says which letter was preached from and
		// not which part, which is a series name rather than a passage.
		if ( $score < 1 || $tied || null === $best ) return '';

		return self::tidy_reference( (string) $best );
	}

	/**
	 * A matched reference written the way the rest of the plugin writes them.
	 *
	 * @param string $reference As it appeared in the title.
	 * @return string
	 */
	private static function tidy_reference( string $reference ): string {
		$reference = trim( (string) preg_replace( '/\s+/', ' ', $reference ) );

		/*
		 * A letter after a verse is dropped.
		 *
		 * "1:5b" means the second half of a verse, and the picker has no
		 * way to hold that: it would be stored and then lost the next time
		 * somebody saved. Half a verse is close enough to the verse for a
		 * passage reference, and losing it here is deliberate rather than
		 * something that happens quietly later.
		 */
		$reference = (string) preg_replace( '/(\d)[a-z]\b/', '$1', $reference );

		// The book as the map spells it, so "1 Thes" is stored as
		// "1 Thessalonians" and matches everything else filed under it.
		if ( preg_match( '/^(.*?)(\d+[:\-].*|\d+\s*$)/', $reference, $m ) ) {
			/*
			 * Spaces taken out before looking the book up.
			 *
			 * The map stores "1thes" without one while people write "1 Thes"
			 * with one, so the lookup misses and the abbreviation is left as
			 * typed. Two sermons on the same letter then file under two
			 * different names.
			 */
			$typed  = trim( $m[1] );
			$book   = self::extract_book( $typed . ' 1' );

			if ( ! $book ) {
				$book = self::extract_book( str_replace( ' ', '', $typed ) . ' 1' );
			}

			$rest = trim( $m[2] );

			if ( $book ) return $book . ' ' . $rest;
		}

		return $reference;
	}

	public static function all_books(): array {
		return array_unique( array_values( self::$book_map ) );
	}
}
