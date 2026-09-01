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
 * Where suite plugins declare themselves.
 *
 * Optional metadata, never a dependency. A plugin that skips this still works,
 * it just misses shared admin assets on its own screens.
 *
 *     add_action( 'plugins_loaded', function () {
 *         Seedcast_Core::register_plugin( 'sermon-library', [
 *             'name'       => 'Sermon Library',
 *             'version'    => SCSL_VERSION,
 *             'admin_menu' => 'seedcast-core',
 *             'min_core'   => '1.0.0',
 *         ] );
 *     }, 5 );
 */
final class Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $instance = null;

	/**
	 * Registered plugins keyed by slug.
	 *
	 * @var array<string, array>
	 */
	private array $plugins = array();

	/**
	 * Accessor.
	 *
	 * @return Registry
	 */
	public static function instance(): Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Declare a suite plugin.
	 *
	 * The metadata fed here is the source of truth for the suite grid:
	 * `name`, `tagline`, `icon`, `admin`, `channel`, and `file` populate
	 * the plugin's card when it's installed. A separate catalog in
	 * `SuiteGrid` covers plugins that aren't installed yet.
	 *
	 * @param string $slug Plugin slug, matching the suite catalog when applicable.
	 * @param array  $args Plugin metadata. Fields:
	 *                     - name (string): display name
	 *                     - tagline (string): short description for the suite grid
	 *                     - icon (string): dashicons class
	 *                     - admin (string): admin URL fragment (e.g. edit.php?post_type=x)
	 *                     - channel (string): 'free' | 'engine' | future
	 *                     - file (string): plugin folder/main-file (used to detect installed state elsewhere)
	 *                     - version (string): plugin's own version
	 *                     - admin_menu (string): admin menu slug, for screen ownership
	 *                     - post_types (array): CPTs the plugin owns
	 *                     - submissions (bool): opt into the shared submission engine
	 *                     - submission_types (array): submission types the plugin accepts
	 *                     - min_core (string): minimum core version required
	 * @return void
	 */
	public function register_plugin( string $slug, array $args = array() ): void {
		$this->plugins[ $slug ] = wp_parse_args(
			$args,
			array(
				'name'             => $slug,
				'tagline'          => '',
				'icon'             => 'dashicons-admin-plugins',
				'admin'            => '',
				'channel'          => 'free',
				'file'             => '',
				'version'          => '',
				'admin_menu'       => '',
				'post_types'       => array(),
				// Opt in to the shared submission engine. Leave false unless the
				// plugin actually collects something from visitors.
				'submissions'      => false,
				'submission_types' => array(),
				'min_core'         => '1.0.0',
			)
		);
	}

	/**
	 * Every registered plugin.
	 *
	 * @return array<string, array>
	 */
	public function get_plugins(): array {
		return $this->plugins;
	}

	/**
	 * Whether a registered plugin owns the given admin screen, so core knows
	 * to load shared admin assets there.
	 *
	 * @param \WP_Screen $screen Current screen.
	 * @return bool
	 */
	public function owns_screen( \WP_Screen $screen ): bool {
		foreach ( $this->plugins as $plugin ) {
			// strpos rather than str_contains, which is PHP 8.0; the suite
			// supports 7.4.
			if ( $plugin['admin_menu'] && false !== strpos( (string) $screen->id, (string) $plugin['admin_menu'] ) ) {
				return true;
			}
			foreach ( (array) $plugin['post_types'] as $post_type ) {
				if ( $post_type === $screen->post_type ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Human readable label for a submission source, used in moderation UI.
	 *
	 * @param string $slug Source slug.
	 * @return string
	 */
	public function source_label( string $slug ): string {
		return $this->plugins[ $slug ]['name'] ?? ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * Registered plugins whose declared minimum core version is newer than the
	 * core copy that actually won negotiation. Surfaced as an admin notice so
	 * the fix (update the other Seedcast plugins) is obvious. Never fatal.
	 *
	 * @return array<string, array>
	 */
	public function plugins_needing_newer_core(): array {
		$behind = array();
		foreach ( $this->plugins as $slug => $plugin ) {
			if ( ! Core::version_at_least( (string) $plugin['min_core'] ) ) {
				$behind[ $slug ] = $plugin;
			}
		}
		return $behind;
	}
}
