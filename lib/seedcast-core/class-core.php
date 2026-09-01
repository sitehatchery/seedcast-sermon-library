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
 * Boots the shared machinery. Plugins init at plugins_loaded 5 or later, by
 * which point core is loaded.
 */
final class Core {

	/**
	 * Wire up everything core owns. Called once from bootstrap.php.
	 *
	 * @return void
	 */
	public static function boot(): void {
		( new Assets() )->init();
		( new Admin\Settings() )->init();
		( new Admin\SuiteGrid() )->init();
		( new Frontend\ChurchDetails() )->init();
		( new Frontend\ElementorChurch() )->init();
		( new Completeness() )->init();
		( new ChurchSchema() )->init();

		// Submissions are opt in. The stack writes to the database and accepts
		// public form posts, so a plugin that never collects anything from
		// visitors should not be hooking it at all. Deferred to init because
		// plugins register at plugins_loaded 5, after core boots at 0, and the
		// endpoints it registers all fire later than init anyway.
		add_action( 'init', array( __CLASS__, 'maybe_boot_submissions' ), 0 );
	}

	/**
	 * Wire the submission engine only when a registered plugin asked for it by
	 * declaring 'submissions' => true.
	 *
	 * @return void
	 */
	public static function maybe_boot_submissions(): void {
		$wanted = false;
		foreach ( Registry::instance()->get_plugins() as $plugin ) {
			if ( ! empty( $plugin['submissions'] ) ) {
				$wanted = true;
				break;
			}
		}

		if ( ! $wanted ) {
			return;
		}

		( new Submissions\SubmissionEngine() )->init();
		( new Submissions\Moderation() )->init();
	}

	/**
	 * The active core version.
	 *
	 * @return string
	 */
	public static function version(): string {
		return SEEDCAST_CORE_VERSION;
	}

	/**
	 * Feature detection. An older bundled copy from another plugin may be the
	 * one that won negotiation, so check before using anything recent.
	 *
	 * @param string $version Minimum version required.
	 * @return bool
	 */
	public static function version_at_least( string $version ): bool {
		return version_compare( SEEDCAST_CORE_VERSION, $version, '>=' );
	}
}
