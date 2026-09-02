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
 * Completeness: is the church's story actually being told, week by week.
 *
 * Two numbers, answering two different questions.
 *
 * The weekly score is a record of how a given week went, locked once the week
 * has closed. It cannot be improved after the fact, because the point is
 * whether the work happened while it was the week's work. Cadence is part of
 * the score on purpose: a week with no sermon scores zero rather than being
 * skipped, the same way a night without sleep is not simply left off a sleep
 * chart.
 *
 * The archive score is current state across everything, recomputed on demand.
 * It moves whenever anything is filled in, including a bulk backfill, and it
 * is the number a church is meant to be improving.
 *
 * The gap between them is itself informative. A church that habitually posts
 * late carries a weekly average well below its archive score. When finishing a
 * sermon stops being work, the two converge.
 *
 * Plugins do not implement any of this. They register what complete means for
 * their own content and core does the rest.
 */
final class Completeness {

	/**
	 * Option prefix recording when measurement began for a source. Weeks
	 * before this are not scored, because a church cannot be held to a
	 * standard that was not being applied yet, and inventing history would
	 * poison any before and after comparison.
	 */
	private const START_OPTION = 'seedcast_completeness_start_';

	/**
	 * Transient prefix for the archive score, which is expensive enough to be
	 * worth caching and current enough that a short life is fine.
	 */
	private const ARCHIVE_CACHE = 'seedcast_completeness_archive_';

	/**
	 * Transient prefix for the per-item breakdown.
	 */
	private const BREAKDOWN_CACHE = 'seedcast_completeness_items_';

	/**
	 * Transient prefix for the completion gap.
	 */
	private const GAP_CACHE = 'seedcast_completeness_gap_';

	/**
	 * How long the archive score stays cached.
	 */
	private const ARCHIVE_TTL = 900;

	/**
	 * Cron hook that closes finished weeks.
	 */
	public const CRON_HOOK = 'seedcast_completeness_rollup';

	/**
	 * Registered sources keyed by slug.
	 *
	 * @var array<string, array>
	 */
	private static array $sources = array();

	/**
	 * Register hooks. Called once from Core::boot().
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'rollup' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	/**
	 * Make sure the daily rollup is scheduled.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( empty( self::$sources ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Declare what complete means for a content type.
	 *
	 * @param string $source Plugin slug.
	 * @param array  $args {
	 *   @type string $label      Human label, for example "Sermons".
	 *   @type string $version    Definition version. Bump when the field list
	 *                            changes, so locked weeks stay comparable only
	 *                            to weeks scored the same way.
	 *   @type string $post_type  Post type to measure.
	 *   @type string $date_meta  Meta key holding the content's own date, in
	 *                            Y-m-d. Items without it are not measured.
	 *   @type string $seen_meta  Meta key holding the immutable first seen
	 *                            date. Used to decide whether an item existed
	 *                            during a week it now claims.
	 *   @type array  $fields     Field definitions. Each is an array with
	 *                            'key', 'label', 'bucket' (metadata|content),
	 *                            optional 'weight' (default 1), and one of
	 *                            'meta', 'any_meta', or 'test'.
	 * }
	 * @return void
	 */
	public static function register( string $source, array $args ): void {
		$source = sanitize_key( $source );
		if ( '' === $source || empty( $args['fields'] ) || empty( $args['post_type'] ) ) {
			return;
		}

		self::$sources[ $source ] = wp_parse_args(
			$args,
			array(
				'label'     => $source,
				'version'   => '1',
				'date_meta' => '',
				'seen_meta' => '',
				'fields'    => array(),
			)
		);

		// Record when this site began measuring, once.
		$option = self::START_OPTION . $source;
		if ( ! get_option( $option ) ) {
			update_option( $option, Church::week_start_for( null ), false );
		}
	}

	/**
	 * All registered sources.
	 *
	 * @return array<string, array>
	 */
	public static function get_sources(): array {
		return self::$sources;
	}

	/**
	 * One registered source, or null.
	 *
	 * @param string $source Plugin slug.
	 * @return array|null
	 */
	public static function get_source( string $source ): ?array {
		return self::$sources[ $source ] ?? null;
	}

	/**
	 * The week measurement began for a source.
	 *
	 * @param string $source Plugin slug.
	 * @return string Y-m-d, or an empty string.
	 */
	public static function measurement_start( string $source ): string {
		return (string) get_option( self::START_OPTION . $source, '' );
	}

	/**
	 * Score a single item.
	 *
	 * Two scores, always computed together.
	 *
	 * The configured score counts only the fields this church has said it
	 * uses. It is the number a pastor sees, so a church that has deliberately
	 * turned off transcripts is not told forever that it is incomplete.
	 *
	 * The comprehensive score counts every field regardless of configuration.
	 * It exists because the configured score is self-referential: a church can
	 * reach 100 by wanting less, which would make the number useless for
	 * comparing one church against another or for measuring whether anything
	 * actually changed. Anything reporting across sites reads the
	 * comprehensive figure.
	 *
	 * @param string $source  Plugin slug.
	 * @param int    $post_id Post to score.
	 * @return array
	 */
	public static function score_item( string $source, int $post_id ): array {
		$empty = array(
			'overall'               => 0,
			'metadata'              => 0,
			'content'               => 0,
			'comprehensive'         => 0,
			'comprehensive_content' => 0,
			'missing'               => array(),
			'comprehensive_missing' => array(),
			'excluded'              => array(),
			'satisfied'             => array(),
		);

		$definition = self::get_source( $source );
		if ( null === $definition ) {
			return $empty;
		}

		$buckets = array(
			'metadata' => array( 'have' => 0, 'total' => 0 ),
			'content'  => array( 'have' => 0, 'total' => 0 ),
		);

		// Comprehensive totals run in parallel, ignoring configuration.
		$all = array(
			'metadata' => array( 'have' => 0, 'total' => 0 ),
			'content'  => array( 'have' => 0, 'total' => 0 ),
		);

		$missing      = array();
		$all_missing  = array();
		$excluded     = array();
		$satisfied    = array();

		foreach ( $definition['fields'] as $field ) {
			$bucket = ( isset( $field['bucket'] ) && 'content' === $field['bucket'] ) ? 'content' : 'metadata';
			$weight = isset( $field['weight'] ) ? max( 1, (int) $field['weight'] ) : 1;
			$label  = isset( $field['label'] ) ? (string) $field['label'] : (string) ( $field['key'] ?? '' );
			$filled = self::field_satisfied( $field, $post_id );
			$wanted = self::field_wanted( $field );

			$all[ $bucket ]['total'] += $weight;
			if ( $filled ) {
				$all[ $bucket ]['have'] += $weight;
			} else {
				$all_missing[] = $label;
			}

			if ( ! $wanted ) {
				$excluded[] = $label;
				continue;
			}

			$buckets[ $bucket ]['total'] += $weight;

			if ( $filled ) {
				$buckets[ $bucket ]['have'] += $weight;
				$satisfied[]                 = $label;
			} else {
				$missing[] = $label;
			}
		}

		$total     = $buckets['metadata']['total'] + $buckets['content']['total'];
		$have      = $buckets['metadata']['have'] + $buckets['content']['have'];
		$all_total = $all['metadata']['total'] + $all['content']['total'];
		$all_have  = $all['metadata']['have'] + $all['content']['have'];

		return array(
			'overall'               => self::percent( $have, $total ),
			'metadata'              => self::percent( $buckets['metadata']['have'], $buckets['metadata']['total'] ),
			'content'               => self::percent( $buckets['content']['have'], $buckets['content']['total'] ),
			'comprehensive'         => self::percent( $all_have, $all_total ),
			'comprehensive_content' => self::percent( $all['content']['have'], $all['content']['total'] ),
			'missing'               => $missing,
			'comprehensive_missing' => $all_missing,
			'excluded'              => $excluded,
			'satisfied'             => $satisfied,
		);
	}

	/**
	 * Whether a field is one this church has asked for.
	 *
	 * Fields with no wanted callback are always counted. That is deliberate:
	 * only written content is configurable, because the details that make a
	 * sermon page usable at all are not something a church would sensibly
	 * describe as optional.
	 *
	 * @param array $field Field definition.
	 * @return bool
	 */
	private static function field_wanted( array $field ): bool {
		if ( isset( $field['wanted'] ) && is_callable( $field['wanted'] ) ) {
			return (bool) call_user_func( $field['wanted'] );
		}
		return true;
	}

	/**
	 * Whether one field is satisfied for a post.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post ID.
	 * @return bool
	 */
	private static function field_satisfied( array $field, int $post_id ): bool {
		if ( isset( $field['test'] ) && is_callable( $field['test'] ) ) {
			return (bool) call_user_func( $field['test'], $post_id );
		}

		if ( ! empty( $field['any_meta'] ) && is_array( $field['any_meta'] ) ) {
			foreach ( $field['any_meta'] as $key ) {
				if ( self::meta_filled( $post_id, (string) $key ) ) {
					return true;
				}
			}
			return false;
		}

		if ( ! empty( $field['meta'] ) ) {
			return self::meta_filled( $post_id, (string) $field['meta'] );
		}

		return false;
	}

	/**
	 * Whether a meta value counts as filled.
	 *
	 * Deliberately stricter than a truthiness check: an empty array, an array
	 * of empty strings, and the string "0" all show up in real data and none
	 * of them represent work done. "0" is treated as filled only because a
	 * numeric ID of zero is not a thing WordPress produces for a real post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	private static function meta_filled( int $post_id, string $key ): bool {
		$value = get_post_meta( $post_id, $key, true );

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
					return true;
				}
				if ( is_array( $item ) && ! empty( $item ) ) {
					return true;
				}
			}
			return false;
		}

		if ( is_scalar( $value ) ) {
			$string = trim( (string) $value );
			return '' !== $string && '0' !== $string;
		}

		return false;
	}

	/**
	 * Integer percentage, guarding division by zero.
	 *
	 * @param int $have  Satisfied weight.
	 * @param int $total Total weight.
	 * @return int
	 */
	private static function percent( int $have, int $total ): int {
		if ( $total <= 0 ) {
			return 0;
		}
		return (int) round( ( $have / $total ) * 100 );
	}

	/**
	 * Post IDs belonging to a given week.
	 *
	 * A post belongs to a week when its own date falls inside the week. When a
	 * seen_meta key is configured, it must also have existed during that week:
	 * a sermon dated backwards weeks later did not happen in that week's work,
	 * and counting it would make history editable.
	 *
	 * @param string $source     Plugin slug.
	 * @param string $week_start Y-m-d week start.
	 * @param bool   $enforce    Apply the existed-during-the-week rule.
	 * @return array<int, int>
	 */
	public static function items_for_week( string $source, string $week_start, bool $enforce = true ): array {
		$definition = self::get_source( $source );
		if ( null === $definition || '' === (string) $definition['date_meta'] ) {
			return array();
		}

		$bounds = Church::week_bounds_for( $week_start );
		if ( '' === $bounds[0] ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $definition['post_type'],
				'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				// Bounded by the week and by posts_per_page; not a slow query
				// in practice, and there is no other way to ask this question.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'     => $definition['date_meta'],
						'value'   => array( $bounds[0], $bounds[1] ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );

		if ( ! $enforce || '' === (string) $definition['seen_meta'] ) {
			return $ids;
		}

		$seen_key = (string) $definition['seen_meta'];

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $seen_key, $bounds ) {
					$seen = trim( (string) get_post_meta( $id, $seen_key, true ) );
					if ( '' === $seen ) {
						/*
						 * No stamp means the item predates the stamping, which
						 * means it predates measurement. Excluded rather than
						 * assumed present, on the same principle as imports.
						 */
						return false;
					}
					return $seen <= $bounds[1];
				}
			)
		);
	}

	/**
	 * Compute a week's score without storing it.
	 *
	 * @param string $source     Plugin slug.
	 * @param string $week_start Y-m-d week start.
	 * @return array
	 */
	public static function compute_week( string $source, string $week_start ): array {
		$definition = self::get_source( $source );
		$version    = null !== $definition ? (string) $definition['version'] : '0';

		$ids = self::items_for_week( $source, $week_start );

		if ( empty( $ids ) ) {
			return array(
				'week_start'            => $week_start,
				'score'                 => 0,
				'metadata_score'        => 0,
				'content_score'         => 0,
				'comprehensive_score'   => 0,
				'comprehensive_content' => 0,
				'item_count'            => 0,
				'state'                 => 'missing',
				'definition_version'    => $version,
				'config'                => self::active_config( $source ),
				'items'                 => array(),
			);
		}

		$overall  = 0;
		$metadata = 0;
		$content  = 0;
		$compre   = 0;
		$compre_c = 0;
		$items    = array();

		foreach ( $ids as $id ) {
			$score     = self::score_item( $source, $id );
			$overall  += $score['overall'];
			$metadata += $score['metadata'];
			$content  += $score['content'];
			$compre   += $score['comprehensive'];
			$compre_c += $score['comprehensive_content'];

			$items[] = array(
				'id'            => $id,
				'title'         => get_the_title( $id ),
				'overall'       => $score['overall'],
				'metadata'      => $score['metadata'],
				'content'       => $score['content'],
				'comprehensive' => $score['comprehensive'],
				'missing'       => $score['missing'],
				'all_missing'   => $score['comprehensive_missing'],
			);
		}

		$count = count( $ids );

		return array(
			'week_start'            => $week_start,
			'score'                 => (int) round( $overall / $count ),
			'metadata_score'        => (int) round( $metadata / $count ),
			'content_score'         => (int) round( $content / $count ),
			'comprehensive_score'   => (int) round( $compre / $count ),
			'comprehensive_content' => (int) round( $compre_c / $count ),
			'item_count'            => $count,
			'state'                 => 'scored',
			'definition_version'    => $version,
			'config'                => self::active_config( $source ),
			'items'                 => $items,
		);
	}

	/**
	 * Read a stored week, or null.
	 *
	 * @param string $source     Plugin slug.
	 * @param string $week_start Y-m-d week start.
	 * @return array|null
	 */
	public static function get_week( string $source, string $week_start ): ?array {
		global $wpdb;

		/*
		 * %i is the identifier placeholder, so the table name is quoted by
		 * prepare() rather than interpolated into the string. Interpolating it
		 * worked, but static analysis cannot see that the name is built from
		 * $wpdb->prefix and a literal, so it has to assume the worst.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source = %s AND week_start = %s LIMIT 1',
				Install::completeness_table(),
				$source,
				$week_start
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['details'] = json_decode( (string) $row['details'], true );
		if ( ! is_array( $row['details'] ) ) {
			$row['details'] = array();
		}

		return $row;
	}

	/**
	 * Store a week's score.
	 *
	 * @param string $source  Plugin slug.
	 * @param array  $data    Result of compute_week().
	 * @param bool   $locked  Whether the week is closed.
	 * @param bool   $amended Whether this overwrote an already locked week.
	 * @return void
	 */
	public static function store_week( string $source, array $data, bool $locked, bool $amended = false ): void {
		global $wpdb;

		$row = array(
			'source'             => $source,
			'week_start'         => $data['week_start'],
			'score'              => (int) $data['score'],
			'metadata_score'     => (int) $data['metadata_score'],
			'content_score'      => (int) $data['content_score'],
			'comprehensive_score'   => (int) $data['comprehensive_score'],
			'comprehensive_content' => (int) $data['comprehensive_content'],
			'config'                => wp_json_encode( $data['config'] ),
			'item_count'         => (int) $data['item_count'],
			'state'              => (string) $data['state'],
			'definition_version' => (string) $data['definition_version'],
			'is_locked'          => $locked ? 1 : 0,
			'is_amended'         => $amended ? 1 : 0,
			'details'            => wp_json_encode( $data['items'] ),
			'computed_at'        => current_time( 'mysql' ),
		);

		$existing = self::get_week( $source, $data['week_start'] );

		if ( $existing ) {
			// Once amended, always amended: a later recompute must not erase
			// the fact that the week was touched after it closed.
			if ( ! empty( $existing['is_amended'] ) ) {
				$row['is_amended'] = 1;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( Install::completeness_table(), $row, array( 'id' => (int) $existing['id'] ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( Install::completeness_table(), $row );
	}

	/**
	 * Compute and store every week that has closed but not yet been locked.
	 *
	 * Runs daily. Also self-heals: a site whose cron did not fire for a month
	 * catches up the next time this runs, because it walks from the last
	 * locked week rather than assuming only one week is outstanding.
	 *
	 * @return void
	 */
	public static function rollup(): void {
		foreach ( array_keys( self::$sources ) as $source ) {
			self::rollup_source( $source );
		}
	}

	/**
	 * Roll up one source.
	 *
	 * @param string $source Plugin slug.
	 * @return void
	 */
	public static function rollup_source( string $source ): void {
		$start = self::measurement_start( $source );
		if ( '' === $start ) {
			return;
		}

		$current = Church::week_start_for( null );
		$week    = self::last_locked_week( $source );

		if ( '' === $week ) {
			$week = $start;
		} else {
			$week = self::advance_week( $week );
		}

		// Hard ceiling so a bad clock or a corrupt start date cannot spin.
		$guard = 0;

		while ( '' !== $week && $week < $current && $guard < 520 ) {
			$data = self::compute_week( $source, $week );
			self::store_week( $source, $data, true );
			$week = self::advance_week( $week );
			$guard++;
		}

		self::flush_archive_cache( $source );
	}

	/**
	 * The most recent locked week for a source.
	 *
	 * @param string $source Plugin slug.
	 * @return string Y-m-d, or an empty string.
	 */
	private static function last_locked_week( string $source ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT week_start FROM %i WHERE source = %s AND is_locked = 1 ORDER BY week_start DESC LIMIT 1',
				Install::completeness_table(),
				$source
			)
		);

		return $value ? (string) $value : '';
	}

	/**
	 * The week after the given one.
	 *
	 * @param string $week_start Y-m-d week start.
	 * @return string
	 */
	private static function advance_week( string $week_start ): string {
		try {
			return ( new \DateTimeImmutable( $week_start, wp_timezone() ) )
				->modify( '+7 days' )
				->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Recompute the weeks affected by an item's date changing.
	 *
	 * The only recompute path that touches a closed week. A content edit never
	 * reopens one, because the weekly score records whether the work happened
	 * during the week, not whether it was eventually done. A date correction
	 * is different: the week the item claims is now a different week, and both
	 * the vacated week and the newly claimed one have to be re-answered.
	 *
	 * Whether an item counts toward a reopened week is still governed by the
	 * existed-during-the-week rule in items_for_week(), so correcting a typo
	 * fixes the number while dating something backwards months later does not.
	 *
	 * @param string $source   Plugin slug.
	 * @param string $old_date Y-m-d previously stored, may be empty.
	 * @param string $new_date Y-m-d now stored, may be empty.
	 * @return void
	 */
	public static function on_date_change( string $source, string $old_date, string $new_date ): void {
		if ( null === self::get_source( $source ) ) {
			return;
		}

		$weeks = array();
		foreach ( array( $old_date, $new_date ) as $date ) {
			if ( '' === trim( $date ) ) {
				continue;
			}
			$week = Church::week_start_for( $date );
			if ( '' !== $week ) {
				$weeks[ $week ] = true;
			}
		}

		$start = self::measurement_start( $source );

		foreach ( array_keys( $weeks ) as $week ) {
			if ( '' !== $start && $week < $start ) {
				continue;
			}

			$closed = Church::week_has_closed( $week );
			$data   = self::compute_week( $source, $week );

			// A week that had already closed and been recorded is amended, and
			// says so. A week still open is simply recomputed.
			$existing = self::get_week( $source, $week );
			$amended  = $closed && null !== $existing;

			self::store_week( $source, $data, $closed, $amended );
		}

		self::flush_archive_cache( $source );
	}

	/**
	 * Stored weeks in a range, oldest first.
	 *
	 * @param string $source Plugin slug.
	 * @param string $from   Y-m-d inclusive.
	 * @param string $to     Y-m-d inclusive.
	 * @return array<int, array>
	 */
	public static function get_weeks( string $source, string $from, string $to ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source = %s AND week_start BETWEEN %s AND %s ORDER BY week_start ASC',
				Install::completeness_table(),
				$source,
				$from,
				$to
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Summary of the locked weekly history.
	 *
	 * @param string $source Plugin slug.
	 * @param int    $weeks  How many recent weeks to average over.
	 * @return array{average:int,best:int,count:int,recent:array}
	 */
	public static function weekly_summary( string $source, int $weeks = 12 ): array {
		$current = Church::week_start_for( null );
		$from    = self::measurement_start( $source );

		if ( '' === $from || '' === $current ) {
			return array(
				'average' => 0,
				'best'    => 0,
				'count'   => 0,
				'recent'  => array(),
			);
		}

		$rows = self::get_weeks( $source, $from, $current );

		$locked = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return ! empty( $row['is_locked'] );
				}
			)
		);

		if ( empty( $locked ) ) {
			return array(
				'average' => 0,
				'best'    => 0,
				'count'   => 0,
				'recent'  => array(),
			);
		}

		$scores = array_map(
			static function ( $row ) {
				return (int) $row['score'];
			},
			$locked
		);

		$recent = array_slice( $locked, -1 * max( 1, $weeks ) );
		$recent_scores = array_map(
			static function ( $row ) {
				return (int) $row['score'];
			},
			$recent
		);

		return array(
			'average' => (int) round( array_sum( $recent_scores ) / count( $recent_scores ) ),
			'best'    => max( $scores ),
			'count'   => count( $locked ),
			'recent'  => $recent,
		);
	}

	/**
	 * Current state across everything, ignoring weeks entirely.
	 *
	 * This is the number a church improves. Unlike the weekly score it
	 * includes imported and undated items, because the question is what the
	 * library looks like now, not whether a particular week's work happened.
	 *
	 * @param string $source Plugin slug.
	 * @param bool   $fresh  Skip the cache.
	 * @return array{overall:int,metadata:int,content:int,total:int,complete:int,undated:int}
	 */
	public static function archive_score( string $source, bool $fresh = false ): array {
		$cache_key = self::ARCHIVE_CACHE . $source;

		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$definition = self::get_source( $source );
		$empty      = array(
			'overall'               => 0,
			'metadata'              => 0,
			'content'               => 0,
			'comprehensive'         => 0,
			'comprehensive_content' => 0,
			'total'                 => 0,
			'complete'              => 0,
			'undated'               => 0,
		);

		if ( null === $definition ) {
			return $empty;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $definition['post_type'],
				'post_status'            => array( 'publish', 'future', 'private' ),
				'posts_per_page'         => 2000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );
		if ( empty( $ids ) ) {
			set_transient( $cache_key, $empty, self::ARCHIVE_TTL );
			return $empty;
		}

		$overall  = 0;
		$metadata = 0;
		$content  = 0;
		$compre   = 0;
		$compre_c = 0;
		$complete = 0;
		$undated  = 0;

		foreach ( $ids as $id ) {
			$score     = self::score_item( $source, $id );
			$overall  += $score['overall'];
			$metadata += $score['metadata'];
			$content  += $score['content'];
			$compre   += $score['comprehensive'];
			$compre_c += $score['comprehensive_content'];

			if ( 100 === $score['overall'] ) {
				$complete++;
			}

			if ( '' !== (string) $definition['date_meta'] ) {
				$date = trim( (string) get_post_meta( $id, (string) $definition['date_meta'], true ) );
				if ( '' === $date ) {
					$undated++;
				}
			}
		}

		$count = count( $ids );

		$result = array(
			'overall'               => (int) round( $overall / $count ),
			'metadata'              => (int) round( $metadata / $count ),
			'content'               => (int) round( $content / $count ),
			'comprehensive'         => (int) round( $compre / $count ),
			'comprehensive_content' => (int) round( $compre_c / $count ),
			'total'                 => $count,
			'complete'              => $complete,
			'undated'               => $undated,
		);

		set_transient( $cache_key, $result, self::ARCHIVE_TTL );

		return $result;
	}

	/**
	 * Every item scored, plus a count of which fields are missing most often.
	 *
	 * The counts are the useful half. A pastor looking at a low score needs to
	 * know which single thing to fix, and "transcript missing on 120 sermons"
	 * answers that in a way a list of 133 rows does not.
	 *
	 * Scoring the whole library is the expensive part, so this is cached on
	 * the same key lifetime as the archive score and invalidated by the same
	 * calls.
	 *
	 * @param string $source Plugin slug.
	 * @param bool   $fresh  Skip the cache.
	 * @return array{items:array,gaps:array,total:int}
	 */
	public static function item_breakdown( string $source, bool $fresh = false ): array {
		$cache_key = self::BREAKDOWN_CACHE . $source;

		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$definition = self::get_source( $source );
		$empty      = array(
			'items' => array(),
			'gaps'  => array(),
			'total' => 0,
		);

		if ( null === $definition ) {
			return $empty;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $definition['post_type'],
				'post_status'            => array( 'publish', 'future', 'private', 'draft', 'pending' ),
				'posts_per_page'         => 2000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );
		if ( empty( $ids ) ) {
			set_transient( $cache_key, $empty, self::ARCHIVE_TTL );
			return $empty;
		}

		// Seed every wanted field at zero so a field missing nowhere still
		// appears, which is what makes the list readable as a checklist.
		$gaps = array();
		foreach ( $definition['fields'] as $field ) {
			if ( ! self::field_wanted( $field ) ) {
				continue;
			}
			$label          = isset( $field['label'] ) ? (string) $field['label'] : (string) ( $field['key'] ?? '' );
			$gaps[ $label ] = 0;
		}

		$items     = array();
		$date_meta = (string) $definition['date_meta'];

		foreach ( $ids as $id ) {
			$score = self::score_item( $source, $id );

			foreach ( $score['missing'] as $label ) {
				if ( ! isset( $gaps[ $label ] ) ) {
					$gaps[ $label ] = 0;
				}
				$gaps[ $label ]++;
			}

			$date = '' !== $date_meta ? trim( (string) get_post_meta( $id, $date_meta, true ) ) : '';

			$items[] = array(
				'id'            => $id,
				'title'         => get_the_title( $id ),
				'score'         => $score['overall'],
				'comprehensive' => $score['comprehensive'],
				'missing'       => $score['missing'],
				'date'          => $date,
				'week'          => '' !== $date ? Church::week_start_for( $date ) : '',
			);
		}

		// Worst first: the screen exists to show what needs attention.
		usort(
			$items,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return strcmp( (string) $b['date'], (string) $a['date'] );
				}
				return $a['score'] <=> $b['score'];
			}
		);

		arsort( $gaps );

		$result = array(
			'items' => $items,
			'gaps'  => $gaps,
			'total' => count( $items ),
		);

		set_transient( $cache_key, $result, self::ARCHIVE_TTL );

		return $result;
	}

	/**
	 * Drop the cached archive score.
	 *
	 * @param string $source Plugin slug.
	 * @return void
	 */
	public static function flush_archive_cache( string $source ): void {
		delete_transient( self::ARCHIVE_CACHE . $source );
		delete_transient( self::BREAKDOWN_CACHE . $source );
		delete_transient( self::GAP_CACHE . $source );
	}

	/**
	 * Median days from an item's own date to it reaching complete.
	 *
	 * Measures friction, not labour. A pastor drafting in a word processor and
	 * pasting in still shows correctly here, which is why this is preferred
	 * over timing editor sessions.
	 *
	 * @param string $source Plugin slug.
	 * @param array  $ids    Post IDs to consider.
	 * @return int|null Median days, or null when nothing qualifies.
	 */
	public static function median_completion_gap( string $source, array $ids ): ?int {
		$definition = self::get_source( $source );
		if ( null === $definition || empty( $definition['completed_meta'] ) || '' === (string) $definition['date_meta'] ) {
			return null;
		}

		$gaps = array();

		foreach ( $ids as $id ) {
			$id        = (int) $id;
			$dated     = trim( (string) get_post_meta( $id, (string) $definition['date_meta'], true ) );
			$completed = trim( (string) get_post_meta( $id, (string) $definition['completed_meta'], true ) );

			if ( '' === $dated || '' === $completed ) {
				continue;
			}

			try {
				$from = new \DateTimeImmutable( $dated, wp_timezone() );
				$to   = new \DateTimeImmutable( $completed, wp_timezone() );
			} catch ( \Exception $e ) {
				continue;
			}

			$days = (int) $from->diff( $to )->format( '%r%a' );

			// Clamp: everything ready before the service is same day, not
			// negative.
			$gaps[] = max( 0, $days );
		}

		if ( empty( $gaps ) ) {
			return null;
		}

		sort( $gaps );
		$count  = count( $gaps );
		$middle = (int) floor( ( $count - 1 ) / 2 );

		if ( 0 === $count % 2 ) {
			return (int) round( ( $gaps[ $middle ] + $gaps[ $middle + 1 ] ) / 2 );
		}

		return $gaps[ $middle ];
	}

	/**
	 * The labels of every field this church has currently switched off.
	 *
	 * Stored alongside each locked week so an old week can explain itself. A
	 * week showing 100 on the front and 40 comprehensively is not a bug, it is
	 * a church that had written content switched off at the time, and the
	 * drill-down should be able to say so rather than leaving it looking
	 * broken.
	 *
	 * @param string $source Plugin slug.
	 * @return array<int, string>
	 */
	public static function active_config( string $source ): array {
		$definition = self::get_source( $source );
		if ( null === $definition ) {
			return array();
		}

		$off = array();
		foreach ( $definition['fields'] as $field ) {
			if ( ! self::field_wanted( $field ) ) {
				$off[] = isset( $field['label'] ) ? (string) $field['label'] : (string) ( $field['key'] ?? '' );
			}
		}

		return $off;
	}

	/**
	 * Whether anything is switched off, meaning the two scores can differ.
	 *
	 * When nothing is off the numbers are identical, and showing both would
	 * read as a fault rather than as extra information.
	 *
	 * @param string $source Plugin slug.
	 * @return bool
	 */
	public static function has_excluded_fields( string $source ): bool {
		return ! empty( self::active_config( $source ) );
	}

	/**
	 * How long items take to go from their own date to complete.
	 *
	 * Restricted to items the plugin watched from the start. Anything that
	 * existed before measurement began is excluded, and the reason is the same
	 * one that keeps backfill out of locked weeks: a library of two hundred
	 * old sermons bulk generated in one afternoon would stamp every completion
	 * on the same day, producing gaps of hundreds of days that describe the
	 * date of the backfill rather than how long anything took. The median
	 * would be enormous and meaningless.
	 *
	 * Measures friction, not effort. A pastor drafting in a word processor and
	 * pasting in still measures correctly here, which is why this is preferred
	 * to timing editor sessions.
	 *
	 * @param string $source Plugin slug.
	 * @param bool   $fresh  Skip the cache.
	 * @return array{median:int|null,count:int,pending:int}
	 */
	public static function completion_gap( string $source, bool $fresh = false ): array {
		$cache_key = self::GAP_CACHE . $source;

		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$empty      = array(
			'median'  => null,
			'count'   => 0,
			'pending' => 0,
		);
		$definition = self::get_source( $source );

		if ( null === $definition
			|| empty( $definition['completed_meta'] )
			|| '' === (string) $definition['seen_meta'] ) {
			return $empty;
		}

		$start = self::measurement_start( $source );
		if ( '' === $start ) {
			return $empty;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $definition['post_type'],
				'post_status'            => array( 'publish', 'future', 'private' ),
				'posts_per_page'         => 2000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);

		$seen_key = (string) $definition['seen_meta'];
		$eligible = array();
		$pending  = 0;

		foreach ( array_map( 'intval', (array) $query->posts ) as $id ) {
			$seen = trim( (string) get_post_meta( $id, $seen_key, true ) );

			// No stamp, or first seen before measurement: not ours to judge.
			if ( '' === $seen || $seen < $start ) {
				continue;
			}

			if ( '' === trim( (string) get_post_meta( $id, (string) $definition['completed_meta'], true ) ) ) {
				$pending++;
				continue;
			}

			$eligible[] = $id;
		}

		$result = array(
			'median'  => self::median_completion_gap( $source, $eligible ),
			'count'   => count( $eligible ),
			'pending' => $pending,
		);

		set_transient( $cache_key, $result, self::ARCHIVE_TTL );

		return $result;
	}

	/**
	 * Days from one item's own date to it reaching complete.
	 *
	 * @param string $source  Plugin slug.
	 * @param int    $post_id Post ID.
	 * @return int|null Days, or null when it is not complete yet.
	 */
	public static function item_completion_gap( string $source, int $post_id ): ?int {
		$gap = self::median_completion_gap( $source, array( $post_id ) );
		return $gap;
	}

	/**
	 * The named tier a score falls into.
	 *
	 * Shares its cut points with band() deliberately. Two scales would let an
	 * item render green and read "needs work" at the same time, and a report
	 * that appears to contradict itself stops being trusted.
	 *
	 * The wording matters more than it looks. A pastor opening this is being
	 * told how far behind he is on work he already feels behind on, so the
	 * bottom tier gets the gentlest label rather than the harshest: "just
	 * started" is honest about the state, implies motion, and is literally
	 * accurate for a sermon carrying a title and a recording and nothing else.
	 * Reserving the softer wording for the higher tiers would scold the people
	 * doing better and console the ones furthest behind.
	 *
	 * @param int $score Score out of 100.
	 * @return array{key:string,label:string,band:string}
	 */
	public static function tier( int $score ): array {
		if ( $score >= 100 ) {
			return array(
				'key'   => 'complete',
				'label' => __( 'Complete', 'seedcast-sermon-library' ),
				'band'  => 'good',
			);
		}

		if ( $score >= 80 ) {
			return array(
				'key'   => 'almost',
				'label' => __( 'Almost there', 'seedcast-sermon-library' ),
				'band'  => 'good',
			);
		}

		if ( $score >= 50 ) {
			return array(
				'key'   => 'needs_work',
				'label' => __( 'Needs work', 'seedcast-sermon-library' ),
				'band'  => 'fair',
			);
		}

		return array(
			'key'   => 'started',
			'label' => __( 'Just started', 'seedcast-sermon-library' ),
			'band'  => 'poor',
		);
	}

	/**
	 * Every tier, in order, for filters and legends.
	 *
	 * @return array<int, array{key:string,label:string,band:string}>
	 */
	public static function tiers(): array {
		return array(
			self::tier( 100 ),
			self::tier( 80 ),
			self::tier( 50 ),
			self::tier( 0 ),
		);
	}

	/**
	 * How many items sit in each tier.
	 *
	 * @param string $source Plugin slug.
	 * @return array<string, int> Tier key to count, in tier order.
	 */
	public static function tier_counts( string $source ): array {
		$counts = array();
		foreach ( self::tiers() as $tier ) {
			$counts[ $tier['key'] ] = 0;
		}

		$breakdown = self::item_breakdown( $source );

		foreach ( $breakdown['items'] as $item ) {
			$key = self::tier( (int) $item['score'] )['key'];
			$counts[ $key ]++;
		}

		return $counts;
	}

	/**
	 * Band a score falls into, for colouring.
	 *
	 * @param int $score Score out of 100.
	 * @return string good, fair, or poor.
	 */
	public static function band( int $score ): string {
		if ( $score >= 80 ) {
			return 'good';
		}
		if ( $score >= 50 ) {
			return 'fair';
		}
		return 'poor';
	}
}
