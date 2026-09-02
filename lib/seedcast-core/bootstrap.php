<?php
/**
 * Seedcast Core: bootstrap for the winning copy.
 *
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * Only ever executed once per request, by Seedcast_Core_Registry::load(),
 * for whichever bundled copy declared the highest version. Everything below
 * therefore runs exactly once no matter how many suite plugins are active.
 *
 * @package Seedcast\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'SEEDCAST_CORE_VERSION' ) ) {
	return;
}

define( 'SEEDCAST_CORE_VERSION', '1.24.4' );
define( 'SEEDCAST_CORE_DIR', __DIR__ . '/' );
define( 'SEEDCAST_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once SEEDCAST_CORE_DIR . 'class-log.php';
require_once SEEDCAST_CORE_DIR . 'class-registry.php';
require_once SEEDCAST_CORE_DIR . 'class-install.php';
require_once SEEDCAST_CORE_DIR . 'class-prefixmigration.php';
require_once SEEDCAST_CORE_DIR . 'class-assets.php';
require_once SEEDCAST_CORE_DIR . 'class-church.php';
require_once SEEDCAST_CORE_DIR . 'class-blurb.php';
require_once SEEDCAST_CORE_DIR . 'class-churchschema.php';
require_once SEEDCAST_CORE_DIR . 'class-completeness.php';
require_once SEEDCAST_CORE_DIR . 'class-core.php';

require_once SEEDCAST_CORE_DIR . 'Submissions/class-captcha.php';
require_once SEEDCAST_CORE_DIR . 'Submissions/class-engine.php';
require_once SEEDCAST_CORE_DIR . 'Submissions/class-moderation.php';

require_once SEEDCAST_CORE_DIR . 'Frontend/class-meta.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-schema.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-kses.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-breadcrumb.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-videoembed.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-formrenderer.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-grid.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-slider.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-filterbar.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-pagination.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-audioplayer.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-sharebuttons.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-churchdetails.php';
require_once SEEDCAST_CORE_DIR . 'Frontend/class-elementorchurch.php';

require_once SEEDCAST_CORE_DIR . 'Admin/class-settings.php';
require_once SEEDCAST_CORE_DIR . 'Admin/class-suitegrid.php';
require_once SEEDCAST_CORE_DIR . 'Admin/class-completenessreport.php';
require_once SEEDCAST_CORE_DIR . 'Admin/class-mediafield.php';

/**
 * Convenience alias in the global namespace, so plugin code can feature detect
 * without importing anything:
 *
 *     if ( Seedcast_Core::version_at_least( '1.4.0' ) ) { ... }
 */
if ( ! class_exists( 'Seedcast_Core', false ) ) {

	/**
	 * Global-namespace facade over Seedcast\Core\Core.
	 */
	class Seedcast_Core {

		/**
		 * The active core version.
		 *
		 * @return string
		 */
		public static function version() {
			return SEEDCAST_CORE_VERSION;
		}

		/**
		 * Whether the active core is at least the given version.
		 *
		 * @param string $version Minimum version required.
		 * @return bool
		 */
		public static function version_at_least( $version ) {
			return version_compare( SEEDCAST_CORE_VERSION, $version, '>=' );
		}

		/**
		 * Declare a suite plugin to core. See Seedcast\Core\Registry.
		 *
		 * @param string $slug Plugin slug.
		 * @param array  $args Plugin metadata.
		 * @return void
		 */
		public static function register_plugin( $slug, array $args = array() ) {
			\Seedcast\Core\Registry::instance()->register_plugin( $slug, $args );
		}

		/**
		 * Ensure the shared submissions table exists. Plugins that collect
		 * front-end submissions call this from their activation hook.
		 *
		 * @return void
		 */
		public static function require_submissions() {
			\Seedcast\Core\Install::ensure_submissions_table();
		}

		/**
		 * Ensure the weekly completeness table exists. Plugins that register a
		 * completeness definition call this from their activation hook.
		 *
		 * @return void
		 */
		public static function require_completeness() {
			\Seedcast\Core\Install::ensure_completeness_table();
		}

		/**
		 * Declare what complete means for a plugin's content.
		 *
		 * @param string $source Plugin slug.
		 * @param array  $args   Definition. See Seedcast\Core\Completeness.
		 * @return void
		 */
		public static function register_completeness( $source, array $args ) {
			\Seedcast\Core\Completeness::register( $source, $args );
		}
	}
}

\Seedcast\Core\Core::boot();
