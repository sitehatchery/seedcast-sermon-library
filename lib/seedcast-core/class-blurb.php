<?php
/**
 * A sentence saying who preached, where, and when.
 *
 * @package Seedcast\Core
 */

namespace Seedcast\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a short paragraph of context for a piece of content.
 *
 * Two reasons it exists.
 *
 * For a person: a page that opens with a sermon title and a play button says
 * nothing about whose church this is or where it is, and somebody who arrived
 * from a search has no idea whether it is down the road or on another
 * continent.
 *
 * For a search engine: the same sentence connects a named person, a named
 * church and a named place in ordinary prose, which is what actually gets read
 * as a signal about location. Structured data says the same thing in a way
 * only machines see; this says it in a way both do.
 *
 * Every clause is optional and each is dropped when the parts it needs are
 * missing. A church that has filled in nothing gets no paragraph at all rather
 * than a sentence with holes in it.
 */
final class Blurb {

	/**
	 * Build the paragraph.
	 *
	 * @param array $args speaker, date, series, subject.
	 * @return string Plain text, or an empty string.
	 */
	public static function sermon( array $args = [] ): string {
		$args = array_merge(
			[
				'speaker' => '',
				'date'    => '',
				'series'  => '',
				'subject' => __( 'this message', 'seedcast-sermon-library' ),
			],
			$args
		);

		$sentences = [];

		$opening = self::opening( $args );
		if ( '' !== $opening ) {
			$sentences[] = $opening;
		}

		if ( '' !== trim( (string) $args['series'] ) ) {
			$sentences[] = sprintf(
				/* translators: %s: name of the sermon series. */
				__( 'It was part of the %s series.', 'seedcast-sermon-library' ),
				trim( (string) $args['series'] )
			);
		}

		$gathering = self::gathering();
		if ( '' !== $gathering ) {
			$sentences[] = $gathering;
		}

		/*
		 * A paragraph that is only the service times is an advert rather than
		 * context, and it would appear identically at the foot of every sermon
		 * on a site that has filled in nothing else. It needs the opening
		 * sentence to be worth printing.
		 */
		if ( '' === $opening ) {
			return '';
		}

		/**
		 * Filter the finished paragraph.
		 *
		 * @param string $text The paragraph.
		 * @param array  $args The parts it was built from.
		 */
		return (string) apply_filters( 'seedcast/blurb/sermon', implode( ' ', $sentences ), $args );
	}

	/**
	 * Who preached it, where and when.
	 *
	 * @param array $args Parts.
	 * @return string
	 */
	private static function opening( array $args ): string {
		$speaker  = trim( (string) $args['speaker'] );
		$date     = trim( (string) $args['date'] );
		$church   = Church::name();
		$locality = Church::locality();
		$subject  = trim( (string) $args['subject'] );

		// With no church name there is nothing to anchor the sentence to, and
		// the site title stands in for it, which is usually the church anyway.
		if ( '' === $church ) {
			return '';
		}

		// Nothing beyond the church name is worth a sentence: "This message
		// was preached at Abide Church." tells a reader nothing they did not
		// already know from the page they are on.
		if ( '' === $speaker && '' === $date && '' === $locality ) {
			return '';
		}

		$where = '' !== $locality
			? sprintf(
				/* translators: 1: church name, 2: city and state. */
				__( '%1$s in %2$s', 'seedcast-sermon-library' ),
				$church,
				$locality
			)
			: $church;

		if ( '' !== $speaker && '' !== $date ) {
			return sprintf(
				/* translators: 1: speaker, 2: what was preached, 3: church and place, 4: date. */
				__( '%1$s preached %2$s at %3$s on %4$s.', 'seedcast-sermon-library' ),
				$speaker,
				$subject,
				$where,
				$date
			);
		}

		if ( '' !== $speaker ) {
			return sprintf(
				/* translators: 1: speaker, 2: what was preached, 3: church and place. */
				__( '%1$s preached %2$s at %3$s.', 'seedcast-sermon-library' ),
				$speaker,
				$subject,
				$where
			);
		}

		if ( '' !== $date ) {
			return sprintf(
				/* translators: 1: what was preached, 2: church and place, 3: date. */
				__( '%1$s was preached at %2$s on %3$s.', 'seedcast-sermon-library' ),
				ucfirst( $subject ),
				$where,
				$date
			);
		}

		return sprintf(
			/* translators: 1: what was preached, 2: church and place. */
			__( '%1$s was preached at %2$s.', 'seedcast-sermon-library' ),
			ucfirst( $subject ),
			$where
		);
	}

	/**
	 * When the church gathers.
	 *
	 * @return string
	 */
	private static function gathering(): string {
		$day   = Church::service_day_label();
		$times = Church::service_times_lines();

		/*
		 * The day alone is not worth saying. The service day option defaults
		 * to Sunday whether or not anybody set it, so a church that has filled
		 * in nothing would otherwise have every sermon page assert it gathers
		 * on Sundays as though somebody had said so.
		 */
		if ( '' === $day || ! $times ) {
			return '';
		}

		return sprintf(
			/* translators: 1: day of the week, plural, 2: service times. */
			__( 'We gather %1$s at %2$s.', 'seedcast-sermon-library' ),
			self::plural_day( $day ),
			self::join( $times )
		);
	}

	/**
	 * "Sundays" rather than "Sunday", since this describes a habit.
	 *
	 * Only for languages where adding an s is right. Anywhere else the day is
	 * left as it is, which reads as a single occasion but is at least correct,
	 * and a translator can fix the whole sentence through the filter.
	 *
	 * @param string $day Day name.
	 * @return string
	 */
	private static function plural_day( string $day ): string {
		if ( ! self::is_english() ) {
			return $day;
		}
		return $day . 's';
	}

	/**
	 * Whether the site is running in English.
	 *
	 * @return bool
	 */
	private static function is_english(): bool {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		return 0 === strpos( $locale, 'en' );
	}

	/**
	 * Join a list the way somebody would say it.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	private static function join( array $items ): string {
		$items = array_values( array_filter( array_map( 'trim', $items ), 'strlen' ) );

		if ( ! $items ) {
			return '';
		}
		if ( 1 === count( $items ) ) {
			return $items[0];
		}

		$last = array_pop( $items );

		return sprintf(
			/* translators: 1: all but the last item, comma separated, 2: the last item. */
			__( '%1$s and %2$s', 'seedcast-sermon-library' ),
			implode( ', ', $items ),
			$last
		);
	}
}
