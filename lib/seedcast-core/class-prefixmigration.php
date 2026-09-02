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
 * One-time rename of the library's stored options from the `sc_` prefix to
 * `seedcast_`.
 *
 * WordPress.org requires a prefix of more than four characters, and `sc_` is
 * short enough to collide with any other plugin that reached for the same two
 * letters. The code moved to `seedcast_` wholesale; this moves the values that
 * are already sitting in existing sites' options tables so the rename is
 * invisible to them.
 *
 * Without this, an upgrade silently reverts a church's identity, theme choice
 * and captcha keys to defaults, because the new code reads names nothing ever
 * wrote. That failure is quiet: the settings screens render fine, just empty.
 *
 * The church options in particular are shared by the whole suite, so this runs
 * from Core::boot() rather than from any one plugin's activation hook. Whichever
 * bundled copy wins version negotiation performs it once, for everybody.
 */
final class PrefixMigration {

	/**
	 * Records that the migration has run. Autoloaded, so the guard below costs
	 * no query once it is set.
	 */
	private const DONE_OPTION = 'seedcast_prefix_migrated';

	/**
	 * Bump to re-run the migration after adding names to the list.
	 */
	private const VERSION = '1';

	/**
	 * Options carrying a value, old name => new name is just the prefix swap.
	 *
	 * The completeness and submissions schema markers are included so an
	 * upgraded site does not re-run dbDelta needlessly on the next request.
	 * They would self-heal without this, but doing a full table check on every
	 * site on the release after an upgrade is a cost worth not paying.
	 */
	private const OPTIONS = array(
		'sc_theme',
		'sc_captcha_provider',
		'sc_captcha_site_key',
		'sc_captcha_secret',
		'sc_honeypot_enabled',
		'sc_church_name',
		'sc_church_address',
		'sc_church_city',
		'sc_church_state',
		'sc_church_postcode',
		'sc_church_phone',
		'sc_church_email',
		'sc_church_service_day',
		'sc_church_service_times',
		'sc_church_events_url',
		'sc_church_visitor_note',
		'sc_church_country',
		'sc_church_description',
		'sc_church_facebook',
		'sc_church_instagram',
		'sc_church_youtube',
		'sc_submissions_schema',
		'sc_completeness_schema',
	);

	/**
	 * Run once per site.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( self::VERSION === get_option( self::DONE_OPTION ) ) {
			return;
		}

		self::migrate_fixed();
		self::migrate_completeness_start();

		update_option( self::DONE_OPTION, self::VERSION, true );
	}

	/**
	 * Move the options whose names are known ahead of time.
	 *
	 * @return void
	 */
	private static function migrate_fixed(): void {
		foreach ( self::OPTIONS as $old ) {
			self::move( $old, 'seedcast_' . substr( $old, 3 ) );
		}
	}

	/**
	 * Move the per-plugin completeness start dates.
	 *
	 * These are `sc_completeness_start_<source>`, one per registered plugin,
	 * so the names are not knowable in advance and the options table has to be
	 * asked. The three completeness caches alongside them are transients with a
	 * fifteen minute lifetime and are deliberately left to expire rather than
	 * being copied: they are derived data, and a stale copy under a new name is
	 * worse than a rebuild.
	 *
	 * @return void
	 */
	private static function migrate_completeness_start(): void {
		global $wpdb;

		// No core API lists options by prefix, and this runs once per site.
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'sc_completeness_start_' ) . '%'
			)
		);

		foreach ( (array) $names as $old ) {
			$old = (string) $old;
			self::move( $old, 'seedcast_' . substr( $old, 3 ) );
		}
	}

	/**
	 * Copy one option to its new name and drop the old one.
	 *
	 * A value already present under the new name wins and the old one is just
	 * removed. That is the case where this has already run, or where somebody
	 * saved the settings screen after upgrading but before this fired, and in
	 * both the newer value is the one meant to survive.
	 *
	 * @param string $old Existing option name.
	 * @param string $new Replacement option name.
	 * @return void
	 */
	private static function move( string $old, string $new ): void {
		$value = get_option( $old, null );
		if ( null === $value ) {
			return;
		}

		if ( null === get_option( $new, null ) ) {
			update_option( $new, $value );
		}

		delete_option( $old );
	}
}
