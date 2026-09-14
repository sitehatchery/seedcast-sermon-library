<?php
namespace SeedcastSermonLibrary\Scripture;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds book and chapter hub terms above the flat verse-level scripture terms.
 *
 * The scsl_scripture taxonomy accumulates one term per verse reference, which
 * leaves most terms with a single sermon attached. This class groups them into
 * a Book > Chapter > Verse hierarchy so that "Romans" and "Romans 8" become
 * real archive pages, without changing any existing verse term or its URL.
 */
class Hierarchy {

	/**
	 * Terms whose names carry no reference and should never be reparented.
	 *
	 * @var string[]
	 */
	private const DEBRIS = [ 'book', 'chapter', 'verse', 'scripture', 'passage' ];

	/**
	 * Books with a single chapter. In these, "Jude 1-4" names verses, not a
	 * chapter, so a chapter hub would merely duplicate the book hub.
	 *
	 * @var string[]
	 */
	private const SINGLE_CHAPTER = [ 'Obadiah', 'Philemon', '2 John', '3 John', 'Jude' ];

	/**
	 * Build the hierarchy.
	 *
	 * @param array{dry_run?: bool, assign?: bool} $args
	 *        dry_run - report only, write nothing. Default true.
	 *        assign  - also attach each sermon directly to its book and chapter
	 *                  terms. Default false, and normally leave it that way: the
	 *                  sermon scripture panel renders every attached term, so
	 *                  direct hub assignments make it read "Romans | Romans 8 |
	 *                  Romans 8:28" instead of just the verse. Hub archives get
	 *                  their posts from hierarchical include_children instead,
	 *                  and their counts from recount().
	 * @return array Report keyed by action.
	 */
	public static function build( array $args = [] ): array {
		$dry_run = $args['dry_run'] ?? true;
		$assign  = $args['assign']  ?? false;

		$report = [
			'books_created'    => [],
			'chapters_created' => [],
			'reparented'       => 0,
			'assigned'         => 0,
			'skipped'          => [],
		];

		$terms = get_terms( [
			'taxonomy'   => 'scsl_scripture',
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) ) {
			return $report;
		}

		// Resolve hub terms once so repeated lookups stay cheap.
		$hubs = [];

		foreach ( $terms as $term ) {
			$parsed = self::parse( $term->name );

			if ( null === $parsed ) {
				$report['skipped'][] = $term->name;
				continue;
			}

			// A term that is itself a book or chapter hub needs no parent work
			// beyond being linked under its book.
			$is_hub = $parsed['is_hub'];

			$book_id = self::ensure_term( $parsed['book'], 0, $dry_run, $hubs, $report, 'books_created' );

			if ( null === $book_id ) {
				continue;
			}

			$chapter_id = null;
			if ( null !== $parsed['chapter'] ) {
				$chapter_label = $parsed['book'] . ' ' . $parsed['chapter'];
				$chapter_id    = self::ensure_term( $chapter_label, $book_id, $dry_run, $hubs, $report, 'chapters_created' );
			}

			// Decide the parent: chapter if we have one, otherwise the book.
			$parent_id = $chapter_id ?? $book_id;

			if ( $is_hub ) {
				// "Ephesians 6" parents to "Ephesians"; "Ephesians" stays at root.
				$parent_id = ( null !== $parsed['chapter'] ) ? $book_id : 0;
			}

			if ( $term->parent !== $parent_id && $term->term_id !== $parent_id ) {
				if ( ! $dry_run ) {
					wp_update_term( $term->term_id, 'scsl_scripture', [ 'parent' => $parent_id ] );
				}
				$report['reparented']++;
			}

			if ( $assign && ! $is_hub ) {
				$report['assigned'] += self::assign_posts( $term, array_filter( [ $book_id, $chapter_id ] ), $dry_run );
			}
		}

		return $report;
	}

	/**
	 * Recalculate every scsl_scripture term count to include descendants.
	 *
	 * WordPress counts only directly attached posts, which leaves book and
	 * chapter hubs at zero. A zero count hides them from get_terms() with
	 * hide_empty and drops them from the XML sitemap, so roll the descendants
	 * up explicitly. Distinct post ids, so a sermon tagged with three verses in
	 * one chapter still counts once.
	 *
	 * @return int Number of terms whose stored count changed.
	 */
	public static function recount(): int {
		global $wpdb;

		$terms = get_terms( [
			'taxonomy'   => 'scsl_scripture',
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) ) {
			return 0;
		}

		$changed = 0;

		foreach ( $terms as $term ) {
			$ids   = get_term_children( $term->term_id, 'scsl_scripture' );
			$ids   = is_wp_error( $ids ) ? [] : $ids;
			$ids[] = $term->term_id;
			$ids   = array_map( 'intval', $ids );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A recount has to read the relationships as they are now; a cached answer is the stale count being corrected.
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.object_id)
				 FROM {$wpdb->term_relationships} tr
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 WHERE tt.taxonomy = 'scsl_scripture'
				   AND tt.term_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ")
				   AND p.post_status = 'publish'",
				$ids
			) );

			if ( (int) $term->count !== $count ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No API stores a count that includes the passages beneath a term; core's recount counts direct relationships only.
				$wpdb->update(
					$wpdb->term_taxonomy,
					[ 'count' => $count ],
					[ 'term_taxonomy_id' => $term->term_taxonomy_id ],
					[ '%d' ],
					[ '%d' ]
				);
				$changed++;
			}
		}

		clean_term_cache( [], 'scsl_scripture' );

		return $changed;
	}

	/**
	 * Split a reference into its book and leading chapter number.
	 *
	 * @return array{book: string, chapter: ?int, is_hub: bool}|null
	 */
	public static function parse( string $name ): ?array {
		$name = trim( $name );

		if ( '' === $name || in_array( strtolower( $name ), self::DEBRIS, true ) ) {
			return null;
		}

		$book = ScriptureParser::extract_book( $name );

		if ( null === $book ) {
			return null;
		}

		// Whatever follows the book name; the first integer there is the chapter.
		$tail    = trim( substr( $name, strlen( rtrim( $book ) ) ) );
		$chapter = null;

		if ( preg_match( '/^[^0-9]*([0-9]+)/', $tail, $m ) && ! in_array( $book, self::SINGLE_CHAPTER, true ) ) {
			$chapter = (int) $m[1];
		}

		// A hub term is one whose name is exactly "Book" or "Book N", with no
		// verse portion. Those already are the pages we want; everything else
		// is a verse-level term that hangs beneath them.
		$label  = ( null === $chapter ) ? $book : $book . ' ' . $chapter;
		$is_hub = ( $name === $label );

		return [
			'book'    => $book,
			'chapter' => $chapter,
			'is_hub'  => $is_hub,
		];
	}

	/**
	 * Find or create a hub term, memoising the result.
	 *
	 * @param array<string,int> $hubs   Lookup cache, passed by reference.
	 * @param array             $report Report accumulator, passed by reference.
	 */
	private static function ensure_term( string $label, int $parent, bool $dry_run, array &$hubs, array &$report, string $bucket ): ?int {
		if ( isset( $hubs[ $label ] ) ) {
			return $hubs[ $label ];
		}

		$existing = get_term_by( 'name', $label, 'scsl_scripture' );

		if ( $existing instanceof \WP_Term ) {
			$hubs[ $label ] = $existing->term_id;
			return $existing->term_id;
		}

		$report[ $bucket ][] = $label;

		if ( $dry_run ) {
			// Negative sentinel keeps the dry run traversable without writing.
			$hubs[ $label ] = -1 * ( count( $report[ $bucket ] ) + 1000 );
			return $hubs[ $label ];
		}

		$created = wp_insert_term( $label, 'scsl_scripture', [ 'parent' => $parent ] );

		if ( is_wp_error( $created ) ) {
			return null;
		}

		$hubs[ $label ] = (int) $created['term_id'];

		return $hubs[ $label ];
	}

	/**
	 * Attach every post in $term to the given hub terms.
	 *
	 * @param int[] $hub_ids
	 * @return int Number of relationships added.
	 */
	private static function assign_posts( \WP_Term $term, array $hub_ids, bool $dry_run ): int {
		$hub_ids = array_values( array_filter( $hub_ids, static function ( $id ) {
			return is_int( $id ) && $id > 0;
		} ) );

		if ( empty( $hub_ids ) ) {
			return 0;
		}

		$post_ids = get_objects_in_term( [ $term->term_id ], 'scsl_scripture' );

		if ( is_wp_error( $post_ids ) || empty( $post_ids ) ) {
			return 0;
		}

		$added = 0;

		foreach ( $post_ids as $post_id ) {
			foreach ( $hub_ids as $hub_id ) {
				if ( has_term( $hub_id, 'scsl_scripture', (int) $post_id ) ) {
					continue;
				}

				if ( ! $dry_run ) {
					wp_set_object_terms( (int) $post_id, [ $hub_id ], 'scsl_scripture', true );
				}

				$added++;
			}
		}

		return $added;
	}
}
