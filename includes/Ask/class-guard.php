<?php
namespace SeedcastSermonLibrary\Ask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ceilings in front of a public box that costs money per use.
 *
 * Three layers, checked before anything is asked:
 *
 *  1. A blocked visitor, which a church set from the questions screen.
 *  2. A per-visitor rate, by hashed address, so a script asking in a loop is
 *     stopped within a few requests.
 *  3. A per-site daily ceiling the church can see and change, so one site
 *     cannot run up an unbounded bill whatever happens to it.
 *
 * The service has a monthly meter of its own behind all of this. These are the
 * site's, so they work before anything leaves it.
 *
 * Whatever stops a visitor, they see an ordinary sentence, not an error.
 */
class Guard {

	/** Questions one visitor may ask in a minute. */
	public const PER_MINUTE = 4;

	/** Questions one visitor may ask in a day. */
	public const PER_DAY = 25;

	/** The church's own daily ceiling, option and default. */
	public const DAILY_OPTION  = 'scsl_ask_daily_limit';
	public const DAILY_DEFAULT = 150;

	public const OK      = 'ok';
	public const BLOCKED = 'blocked';
	public const LIMITED = 'limited';
	public const CEILING = 'ceiling';

	/**
	 * Whether this visitor may ask now. Does not count the question: call
	 * count() once it is actually sent.
	 *
	 * @return string One of the constants above.
	 */
	public static function check(): string {
		if ( Questions::is_blocked() ) {
			return self::BLOCKED;
		}

		[ $minute, $day ] = self::visitor_keys();

		if ( (int) get_transient( $minute ) >= self::PER_MINUTE ) {
			return self::LIMITED;
		}

		if ( (int) get_transient( $day ) >= self::PER_DAY ) {
			return self::LIMITED;
		}

		if ( self::asked_today() >= self::daily_limit() ) {
			return self::CEILING;
		}

		return self::OK;
	}

	/**
	 * Count one question against the visitor and the site.
	 */
	public static function count(): void {
		[ $minute, $day ] = self::visitor_keys();

		self::bump( $minute, 2 * MINUTE_IN_SECONDS );
		self::bump( $day, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		self::bump( self::today_key(), DAY_IN_SECONDS + HOUR_IN_SECONDS );

		// And in the library's daily counters, so the history of how much the
		// assistant is used builds up from the first day it is on.
		if ( class_exists( '\Seedcast\Core\Counters' ) ) {
			\Seedcast\Core\Counters::record( 'site', 0, 'ask' );
		}
	}

	/**
	 * Questions this site has sent today.
	 */
	public static function asked_today(): int {
		return (int) get_transient( self::today_key() );
	}

	/**
	 * The church's daily ceiling.
	 */
	public static function daily_limit(): int {
		$limit = (int) get_option( self::DAILY_OPTION, self::DAILY_DEFAULT );
		return $limit > 0 ? $limit : self::DAILY_DEFAULT;
	}

	/**
	 * What a visitor stopped by any of these reads.
	 */
	public static function message( string $why ): string {
		if ( self::LIMITED === $why ) {
			return __( 'That is a lot of questions in a short time. Please wait a little and try again.', 'seedcast-sermon-library' );
		}

		return __( 'We can\'t answer any more questions right now. Please try again later, or get in touch with us directly.', 'seedcast-sermon-library' );
	}

	private static function today_key(): string {
		return 'scsl_ask_day_' . wp_date( 'Ymd' );
	}

	/**
	 * This visitor's counters for this minute and this day.
	 *
	 * Fixed windows, named by the window itself. A counter whose expiry moved
	 * on every write would never empty for somebody asking one question a
	 * minute, and would stop a patient reader on their fifth question.
	 *
	 * @return array{0:string, 1:string}
	 */
	private static function visitor_keys(): array {
		$visitor = substr( Questions::visitor(), 0, 32 );

		return [
			'scsl_ask_m_' . $visitor . '_' . (int) floor( time() / MINUTE_IN_SECONDS ),
			'scsl_ask_d_' . $visitor . '_' . wp_date( 'Ymd' ),
		];
	}

	private static function bump( string $key, int $ttl ): void {
		set_transient( $key, (int) get_transient( $key ) + 1, $ttl );
	}
}
