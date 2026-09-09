<?php
namespace SeedcastSermonLibrary\Topics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Which topics a sermon is filed under.
 *
 * A sermon touches a dozen subjects and only two or three of them are what it
 * is actually about. Left to a person with a checklist the result is either
 * nothing ticked or everything ticked, and everything ticked is the same as
 * nothing: a topic carried by four sermons in five is not a way into the
 * library, it is a label on the whole library.
 *
 * So topics are picked, not ticked, and this puts two limits on the picking.
 *
 * The list is closed. Whatever is choosing may only choose from the terms the
 * church already has. Allowed to invent one, any generator will produce
 * "Forgiveness", "Forgiving Others" and "Grace and Forgiveness" within a month,
 * and a vocabulary that splits three ways is worse than a coarse one. New
 * topics are a decision somebody makes on purpose, in the Topics screen.
 *
 * The count is capped. Three is the most a sermon may carry, and none is a
 * permitted answer. A sermon that is about one thing should say so.
 */
class Assignment {

	/**
	 * Most topics one sermon may carry.
	 *
	 * Three, because the fourth is always the one that would have fitted any
	 * sermon in the library.
	 */
	public const MAX = 3;

	/**
	 * The topics anything may choose from, slug to name.
	 *
	 * Read from the terms that exist rather than from a list in the code: the
	 * vocabulary is the church's, and it changes without a release.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$terms = get_terms( [
			'taxonomy'   => 'scsl_topic',
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$out = [];

		foreach ( $terms as $term ) {
			$out[ $term->slug ] = $term->name;
		}

		/**
		 * The topics a sermon may be filed under.
		 *
		 * @param array<string, string> $out Slug to name.
		 */
		return (array) apply_filters( 'scsl_topic_choices', $out );
	}

	/**
	 * Turn whatever was picked into the term ids that actually exist.
	 *
	 * Accepts slugs or names, in either case, because a generator asked for
	 * "Fear & Anxiety" may reasonably return the name it was shown.
	 *
	 * Order is kept: the picks arrive ranked, and the cap should keep the best
	 * three rather than three at random. Anything unrecognised is dropped
	 * without comment, which is the whole point of a closed list.
	 *
	 * @param string[] $picked
	 * @return int[] Term ids.
	 */
	public static function resolve( array $picked ): array {
		$choices = self::choices();

		// A second index by name, so a pick can arrive either way.
		$by_name = [];

		foreach ( $choices as $slug => $name ) {
			$by_name[ sanitize_title( $name ) ] = $slug;
		}

		$slugs = [];

		foreach ( $picked as $pick ) {
			if ( ! is_string( $pick ) && ! is_numeric( $pick ) ) {
				continue;
			}

			$key = sanitize_title( (string) $pick );

			if ( '' === $key ) {
				continue;
			}

			if ( isset( $choices[ $key ] ) ) {
				$slugs[ $key ] = true;
			} elseif ( isset( $by_name[ $key ] ) ) {
				$slugs[ $by_name[ $key ] ] = true;
			}
		}

		/**
		 * Most topics one sermon may carry.
		 *
		 * @param int $max
		 */
		$max = (int) apply_filters( 'scsl_max_topics', self::MAX );
		$max = max( 0, $max );

		$slugs = array_slice( array_keys( $slugs ), 0, $max );
		$ids   = [];

		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'scsl_topic' );

			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return $ids;
	}

	/**
	 * File a sermon under the topics that were picked for it.
	 *
	 * Leaves a sermon that already has topics alone unless told otherwise.
	 * Somebody may have chosen those by hand, and a generated set arriving
	 * later has no way to tell the difference, so it does not get to decide.
	 *
	 * @param string[] $picked  Slugs or names.
	 * @param bool     $replace Overwrite topics the sermon already has.
	 * @return int[] The term ids now on the sermon, empty if nothing changed.
	 */
	public static function assign( int $post_id, array $picked, bool $replace = false ): array {
		if ( ! $replace ) {
			$existing = wp_get_object_terms( $post_id, 'scsl_topic', [ 'fields' => 'ids' ] );

			if ( ! is_wp_error( $existing ) && $existing ) {
				return [];
			}
		}

		$ids = self::resolve( $picked );

		if ( ! $ids ) {
			return [];
		}

		$result = wp_set_object_terms( $post_id, $ids, 'scsl_topic', false );

		return is_wp_error( $result ) ? [] : $ids;
	}
}
