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
 * Daily counters any suite plugin can increment.
 *
 * One row per entity, metric and day, holding a tally. Nothing in a row ties
 * it to a person: no address, no session, no user agent. That is deliberate
 * and load-bearing, because it keeps the table outside GDPR and CCPA and
 * leaves a church nothing to declare. Do not add a column that changes that.
 *
 * Counting lives in the library rather than in Harvest or the assistant so
 * that a plugin can record whether or not anything that reads the counts is
 * installed. Harvest then arrives on a site with history behind it.
 *
 * Writes are buffered for the request and flushed once at shutdown as a single
 * upsert, so recording is cheap enough to do on a page load and repeated
 * calls for the same row in one request cost nothing extra. A failure to
 * write is logged and swallowed: a lost count must never break a page.
 *
 * Deciding what is worth counting (bots, logged-in staff, cached pages) is
 * the caller's job. This class records what it is told.
 *
 *     \Seedcast\Core\Counters::record( 'sermon', $post_id, 'view' );
 *
 * Feature detect before calling, since an older library may have won
 * negotiation: `Seedcast_Core::version_at_least( '1.29.0' )`.
 */
final class Counters {

	/**
	 * Schema version. Bump whenever the table definition below changes.
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Option recording the installed schema version.
	 */
	public const SCHEMA_OPTION = 'seedcast_counters_schema';

	/**
	 * Entity types and metrics are short machine names, so a typo or a
	 * stray label cannot quietly start a new series.
	 */
	private const NAME_PATTERN = '/^[a-z0-9_]{1,32}$/';

	/**
	 * Increments waiting for shutdown, keyed by row.
	 *
	 * @var array<string, array{0:string,1:int,2:string,3:string,4:int}>
	 */
	private static $pending = array();

	/**
	 * Whether the shutdown flush has been hooked this request.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * The counters table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seedcast_counters';
	}

	/**
	 * Add to today's count for an entity and metric.
	 *
	 * The day is the site's own day, in its configured timezone, because a
	 * church thinks about Sunday as Sunday where it is.
	 *
	 * @param string $entity_type What kind of thing, e.g. 'sermon', 'topic', 'passage'.
	 * @param int    $entity_id   Its id. 0 for something site wide.
	 * @param string $metric      What happened, e.g. 'view', 'ask', 'play'.
	 * @param int    $amount      How much to add. Defaults to 1.
	 * @return bool False when the arguments were rejected, true when queued.
	 */
	public static function record( string $entity_type, int $entity_id, string $metric, int $amount = 1 ): bool {
		if ( ! self::valid_name( $entity_type ) || ! self::valid_name( $metric ) ) {
			Log::debug( "counters: rejected name '{$entity_type}' / '{$metric}'" );
			return false;
		}
		if ( $entity_id < 0 || $amount < 1 ) {
			return false;
		}

		$day = wp_date( 'Y-m-d' );
		$key = $entity_type . '|' . $entity_id . '|' . $metric . '|' . $day;

		if ( isset( self::$pending[ $key ] ) ) {
			self::$pending[ $key ][4] += $amount;
		} else {
			self::$pending[ $key ] = array( $entity_type, $entity_id, $metric, $day, $amount );
		}

		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 0 );
		}

		return true;
	}

	/**
	 * Write every pending increment in one statement.
	 *
	 * Runs at shutdown. Public so a long-running process, such as a cron job
	 * or a CLI command, can flush without waiting for the end of the request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( empty( self::$pending ) ) {
			return;
		}

		$rows          = self::$pending;
		self::$pending = array();

		/**
		 * Whether counting is on for this site. Off discards the increments.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'seedcast/counters/enabled', true ) ) {
			return;
		}

		self::ensure_table();

		global $wpdb;
		$table        = self::table();
		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$placeholders[] = '(%s, %d, %s, %s, %d)';
			array_push( $values, ...$row );
		}

		// One upsert per request. $table is $wpdb->prefix plus a literal, and
		// the placeholder list is built from a fixed string, so every value
		// still goes through prepare(). VALUES() rather than the row alias
		// syntax because MariaDB and MySQL before 8.0.19 have no alias form.
		$sql = "INSERT INTO {$table} (entity_type, entity_id, metric, day, tally) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE tally = tally + VALUES(tally)';

		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		$wpdb->suppress_errors( $suppressed );

		if ( false === $result ) {
			Log::debug( 'counters: write failed: ' . $wpdb->last_error );
			// The table may have been dropped behind the schema option's back.
			// Rebuild it so the next request counts, rather than failing forever.
			delete_option( self::SCHEMA_OPTION );
		}
	}

	/**
	 * Sum of one entity's metric over a range of days, inclusive.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity id.
	 * @param string $metric      Metric.
	 * @param string $from        First day, Y-m-d.
	 * @param string $to          Last day, Y-m-d.
	 * @return int
	 */
	public static function total( string $entity_type, int $entity_id, string $metric, string $from, string $to ): int {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();

		// $table is $wpdb->prefix plus a literal; every value is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(tally) FROM {$table} WHERE entity_type = %s AND entity_id = %d AND metric = %s AND day BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entity_type,
				$entity_id,
				$metric,
				$from,
				$to
			)
		);

		return (int) $sum;
	}

	/**
	 * Create or update the counters table if needed.
	 *
	 * Created on first use rather than on activation, so it appears the first
	 * time anything counts, on any site, whichever plugin did the counting.
	 *
	 * The primary key leads with entity, metric, day, which is the question
	 * almost everything asks: this sermon's views over this range. The second
	 * key answers the other one, which entities of a type led a metric over a
	 * range, without scanning every entity.
	 *
	 * @param bool $force Skip the version short circuit.
	 * @return void
	 */
	public static function ensure_table( bool $force = false ): void {
		if ( ! $force && get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			entity_type VARCHAR(32)     NOT NULL,
			entity_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			metric      VARCHAR(32)     NOT NULL,
			day         DATE            NOT NULL,
			tally       INT UNSIGNED    NOT NULL DEFAULT 0,
			PRIMARY KEY  (entity_type, entity_id, metric, day),
			KEY type_metric_day (entity_type, metric, day)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Whether a user agent belongs to a crawler, preview fetcher or script.
	 *
	 * For callers to check at the point of counting, so a crawler opening
	 * every sermon does not make the unread parts of a library look read.
	 * The agent is only ever looked at, never stored. Deliberately broad:
	 * missing a real reader costs one count, while counting a crawler
	 * misleads every report built on the number. An empty agent counts as a
	 * crawler, since no browser sends one.
	 *
	 * @param string|null $user_agent Agent to test. Null reads the current request's.
	 * @return bool
	 */
	public static function is_crawler( ?string $user_agent = null ): bool {
		if ( null === $user_agent ) {
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		}

		$user_agent = trim( $user_agent );
		if ( '' === $user_agent ) {
			return true;
		}

		$pattern = '/bot\b|bot\/|crawl|spider|slurp|archiver|scrape|fetch|monitor|uptime|preview|facebookexternalhit|embedly|whatsapp|telegram|discord|slack|skype|headless|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|httpclient|libwww|guzzle|wordpress\//i';

		/**
		 * The pattern a user agent is tested against.
		 *
		 * @param string $pattern PCRE pattern.
		 */
		$pattern = (string) apply_filters( 'seedcast/counters/crawler_pattern', $pattern );

		return 1 === preg_match( $pattern, $user_agent );
	}

	/**
	 * Whether a string is an acceptable entity type or metric name.
	 *
	 * @param string $name Candidate.
	 * @return bool
	 */
	private static function valid_name( string $name ): bool {
		return 1 === preg_match( self::NAME_PATTERN, $name );
	}
}
