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
 * The church's own identity: who they are, where they meet, when they meet.
 *
 * This lives in core rather than in any one plugin because more than one
 * plugin needs it and none of them should own it. Sermon Library wants the
 * name for schema output, a visitor form wants the service day for its
 * confirmation message, a bulletin wants the service times, and the site
 * footer wants the address. Entered once, here, and every plugin installed
 * later picks it up with no configuration.
 *
 * Nothing in the suite requires these to be filled in. Every accessor returns
 * an empty string when unset, and callers should check has_details() before
 * rendering a block that would otherwise be empty.
 */
final class Church {

	/**
	 * Option names and their defaults.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULTS = array(
		'seedcast_church_name'          => '',
		'seedcast_church_address'       => '',
		'seedcast_church_phone'         => '',
		'seedcast_church_email'         => '',
		'seedcast_church_service_day'   => '0',
		'seedcast_church_service_times' => '',
		'seedcast_church_events_url'    => '',
		'seedcast_church_visitor_note'  => '',
	);

	/**
	 * The church name.
	 *
	 * Falls back to the site title, which is the church name on the great
	 * majority of church sites and is a better default than nothing.
	 *
	 * @return string
	 */
	public static function name(): string {
		$name = trim( (string) get_option( 'seedcast_church_name', '' ) );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}
		return $name;
	}

	/**
	 * The street address, as entered. May be multi line.
	 *
	 * @return string
	 */
	public static function address(): string {
		return trim( (string) get_option( 'seedcast_church_address', '' ) );
	}

	/**
	 * The address collapsed to a single line, for links and meta tags.
	 *
	 * @return string
	 */
	public static function address_one_line(): string {
		$lines = [];

		$street = self::address();
		if ( '' !== $street ) {
			$parts = preg_split( '/\r\n|\r|\n/', $street );
			if ( is_array( $parts ) ) {
				$lines = array_filter( array_map( 'trim', $parts ), 'strlen' );
			} else {
				$lines = [ $street ];
			}
		}

		/*
		 * City, state and postcode are separate fields, but they were not
		 * always. A church that filled the address in before they existed has
		 * the whole thing sitting in the street field, and appending an empty
		 * city to it must not leave a trailing comma. Anything empty simply
		 * does not appear.
		 */
		$locality = self::locality();
		if ( '' !== $locality ) {
			$lines[] = $locality;
		}

		$postcode = self::postcode();
		if ( '' !== $postcode ) {
			$lines[] = $postcode;
		}

		return implode( ', ', $lines );
	}

	/**
	 * The address across several lines, the way it would be written on an
	 * envelope.
	 *
	 * Wanted wherever the address is read rather than clicked: a plain text
	 * email, a printed page. address() is the street alone, so anything
	 * showing that on its own loses the town.
	 *
	 * @return string
	 */
	public static function address_block(): string {
		$lines = [];

		$street = self::address();
		if ( '' !== $street ) {
			$parts = preg_split( '/\r\n|\r|\n/', $street );
			if ( is_array( $parts ) ) {
				$lines = array_filter( array_map( 'trim', $parts ), 'strlen' );
			} else {
				$lines = [ $street ];
			}
		}

		$locality = self::locality();
		$postcode = self::postcode();

		if ( '' !== $locality && '' !== $postcode ) {
			$lines[] = $locality . ' ' . $postcode;
		} elseif ( '' !== $locality ) {
			$lines[] = $locality;
		} elseif ( '' !== $postcode ) {
			$lines[] = $postcode;
		}

		return implode( "\n", $lines );
	}

	/**
	 * The city.
	 *
	 * @return string
	 */
	public static function city(): string {
		return trim( (string) get_option( 'seedcast_church_city', '' ) );
	}

	/**
	 * The state, province or county.
	 *
	 * @return string
	 */
	public static function state(): string {
		return trim( (string) get_option( 'seedcast_church_state', '' ) );
	}

	/**
	 * The postcode or zip code.
	 *
	 * @return string
	 */
	public static function postcode(): string {
		return trim( (string) get_option( 'seedcast_church_postcode', '' ) );
	}

	/**
	 * City and state together, as somebody would say them.
	 *
	 * Either on its own is still worth having: a church in a city nobody could
	 * confuse does not need the state, and one that has filled in only the
	 * state should not lose it.
	 *
	 * @return string
	 */
	public static function locality(): string {
		$city  = self::city();
		$state = self::state();

		if ( '' !== $city && '' !== $state ) {
			return $city . ', ' . $state;
		}

		return '' !== $city ? $city : $state;
	}

	/**
	 * Whether the church has said where it is, in a way that can be written
	 * into a sentence.
	 *
	 * @return bool
	 */
	public static function has_locality(): bool {
		return '' !== self::locality();
	}

	/**
	 * Contact phone number, as entered.
	 *
	 * @return string
	 */
	/**
	 * The two-letter country code.
	 *
	 * Defaulted rather than left empty, because an address without a country
	 * is incomplete to a search engine and most churches running this are in
	 * one country. Wrong for a few is better than absent for all, and it is
	 * one field to correct.
	 *
	 * @return string
	 */
	public static function country(): string {
		$code = strtoupper( trim( (string) get_option( 'seedcast_church_country', 'US' ) ) );

		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * A sentence or two saying what this church is.
	 *
	 * @return string
	 */
	public static function description(): string {
		return trim( (string) get_option( 'seedcast_church_description', '' ) );
	}

	/**
	 * The church's official addresses elsewhere.
	 *
	 * Only whole addresses. A field somebody half filled in would otherwise
	 * be handed to a search engine as a claim about where this church is.
	 *
	 * @return string[]
	 */
	public static function profiles(): array {
		$out = array();

		foreach ( array( 'seedcast_church_facebook', 'seedcast_church_instagram', 'seedcast_church_youtube' ) as $key ) {
			$url = trim( (string) get_option( $key, '' ) );

			if ( '' !== $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$out[] = $url;
			}
		}

		return $out;
	}

	public static function phone(): string {
		return trim( (string) get_option( 'seedcast_church_phone', '' ) );
	}

	/**
	 * Contact email address.
	 *
	 * Falls back to the site admin email so a plugin sending mail on the
	 * church's behalf always has a usable reply-to.
	 *
	 * @return string
	 */
	public static function email(): string {
		$email = trim( (string) get_option( 'seedcast_church_email', '' ) );
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email', '' );
		}
		return $email;
	}

	/**
	 * Day of the week the main service falls on, as PHP's 'w': 0 is Sunday.
	 *
	 * @return int
	 */
	public static function service_day(): int {
		$day = (int) get_option( 'seedcast_church_service_day', 0 );
		return ( $day >= 0 && $day <= 6 ) ? $day : 0;
	}

	/**
	 * The service day as a localized weekday name, for example "Sunday".
	 *
	 * Uses WordPress's locale object rather than date() so the word matches
	 * the site language. Any plugin writing visitor-facing copy should use
	 * this rather than hardcoding Sunday: a real number of churches meet on
	 * Saturday, and some plant congregations meet midweek.
	 *
	 * @return string
	 */
	public static function service_day_label(): string {
		global $wp_locale;
		$day = self::service_day();

		if ( $wp_locale instanceof \WP_Locale ) {
			$label = $wp_locale->get_weekday( $day );
			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}

		$fallback = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		return $fallback[ $day ];
	}

	/**
	 * Service times, as entered. May be multi line, for example one line per
	 * service. Deliberately free text: churches describe their schedule in
	 * ways no structured field survives contact with.
	 *
	 * @return string
	 */
	public static function service_times(): string {
		return trim( (string) get_option( 'seedcast_church_service_times', '' ) );
	}

	/**
	 * Service times split into individual lines.
	 *
	 * @return array<int, string>
	 */
	public static function service_times_lines(): array {
		$times = self::service_times();
		if ( '' === $times ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\r|\n/', $times );
		if ( ! is_array( $lines ) ) {
			return array( $times );
		}
		return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}

	/**
	 * URL of the church's events or calendar page.
	 *
	 * @return string
	 */
	public static function events_url(): string {
		return trim( (string) get_option( 'seedcast_church_events_url', '' ) );
	}

	/**
	 * A short note addressed to someone thinking about visiting. Rendered on
	 * the details block and available to any plugin writing to a visitor.
	 *
	 * @return string
	 */
	public static function visitor_note(): string {
		return trim( (string) get_option( 'seedcast_church_visitor_note', '' ) );
	}

	/**
	 * A directions link built from the address.
	 *
	 * Uses the Google Maps universal URL, which hands off to the device's
	 * default maps application on both iOS and Android rather than forcing a
	 * particular app. Returns an empty string when there is no address, so
	 * callers can treat it as optional without a second check.
	 *
	 * @return string
	 */
	public static function directions_url(): string {
		$address = self::address_one_line();
		if ( '' === $address ) {
			return '';
		}
		return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $address );
	}

	/**
	 * Whether enough has been filled in to be worth rendering.
	 *
	 * The name is excluded because it falls back to the site title and would
	 * therefore always be truthy, which would make this always return true.
	 *
	 * @return bool
	 */
	public static function has_details(): bool {
		$fields = array(
			self::address(),
			self::phone(),
			trim( (string) get_option( 'seedcast_church_email', '' ) ),
			self::service_times(),
			self::events_url(),
			self::visitor_note(),
		);

		foreach ( $fields as $value ) {
			if ( '' !== $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Timestamp of the end of the next service day, in site time.
	 *
	 * Today counts: a visitor filling out a form on a Sunday morning is
	 * coming to that morning's service, not to next week's. Callers wanting
	 * "has the service happened yet" should compare against this rather than
	 * doing weekday arithmetic themselves, because the site timezone is
	 * frequently not the server timezone.
	 *
	 * @return int Unix timestamp.
	 */
	public static function next_service_timestamp(): int {
		$timezone = wp_timezone();

		try {
			$now = new \DateTimeImmutable( 'now', $timezone );
		} catch ( \Exception $e ) {
			return time() + DAY_IN_SECONDS;
		}

		$target  = self::service_day();
		$current = (int) $now->format( 'w' );
		$ahead   = ( $target - $current + 7 ) % 7;

		$service = $now->modify( '+' . $ahead . ' days' )->setTime( 23, 59, 59 );

		return $service->getTimestamp();
	}

	/**
	 * The start of the service week containing the given moment.
	 *
	 * A church's week runs from its service day to the day before the next
	 * one, which is what makes "was this week's sermon posted" answerable. A
	 * sermon preached on the service day therefore has until the following
	 * service day to be finished, and that grace comes from the definition of
	 * the week rather than from an arbitrary window.
	 *
	 * @param int|string|null $when Timestamp, Y-m-d string, or null for now.
	 * @return string Y-m-d of the week start, in site time.
	 */
	public static function week_start_for( $when = null ): string {
		$timezone = wp_timezone();

		try {
			if ( null === $when || '' === $when ) {
				$date = new \DateTimeImmutable( 'now', $timezone );
			} elseif ( is_int( $when ) ) {
				$date = ( new \DateTimeImmutable( '@' . $when ) )->setTimezone( $timezone );
			} else {
				$date = new \DateTimeImmutable( (string) $when, $timezone );
			}
		} catch ( \Exception $e ) {
			return '';
		}

		$date    = $date->setTime( 0, 0, 0 );
		$target  = self::service_day();
		$current = (int) $date->format( 'w' );

		// How many days back to the most recent service day, today included.
		$back = ( $current - $target + 7 ) % 7;

		return $date->modify( '-' . $back . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * First and last day of the service week containing the given moment.
	 *
	 * @param int|string|null $when Timestamp, Y-m-d string, or null for now.
	 * @return array{0:string,1:string} Y-m-d start and end, both inclusive.
	 */
	public static function week_bounds_for( $when = null ): array {
		$start = self::week_start_for( $when );
		if ( '' === $start ) {
			return array( '', '' );
		}

		try {
			$end = ( new \DateTimeImmutable( $start, wp_timezone() ) )
				->modify( '+6 days' )
				->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return array( $start, $start );
		}

		return array( $start, $end );
	}

	/**
	 * Whether the service week starting on the given day has finished.
	 *
	 * @param string $week_start Y-m-d week start.
	 * @return bool
	 */
	public static function week_has_closed( string $week_start ): bool {
		$current = self::week_start_for( null );
		if ( '' === $current || '' === $week_start ) {
			return false;
		}
		return $week_start < $current;
	}

	/**
	 * Every field as an array, for templates and for passing into
	 * mail templates without eight separate calls.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'name'             => self::name(),
			'address'          => self::address(),
			'address_one_line' => self::address_one_line(),
			'phone'            => self::phone(),
			'email'            => self::email(),
			'service_day'      => self::service_day_label(),
			'service_times'    => self::service_times(),
			'events_url'       => self::events_url(),
			'visitor_note'     => self::visitor_note(),
			'directions_url'   => self::directions_url(),
		);
	}

	/**
	 * Sanitize callback for the service day. Anything outside 0 to 6 falls
	 * back to Sunday rather than being stored as nonsense.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	public static function sanitize_service_day( $value ): string {
		$day = (int) $value;
		if ( $day < 0 || $day > 6 ) {
			$day = 0;
		}
		return (string) $day;
	}
}
