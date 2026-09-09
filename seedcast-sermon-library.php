<?php
/**
 * Plugin Name: Seedcast Sermon Library
 * Plugin URI:  https://seedcast.ai/sermon-library
 * Description: A complete sermon series and content management system for churches. Manage sermons, series, speakers, transcripts, Bible studies, and more.
 * Version:     2.72.1
 * Author:      Seedcast
 * Author URI:  https://seedcast.ai
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seedcast-sermon-library
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * @package SermonLibrary
 */

namespace SeedcastSermonLibrary;

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCSL_VERSION',          '2.72.1' );
define( 'SCSL_PLUGIN_FILE',      __FILE__ );
define( 'SCSL_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'SCSL_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'SCSL_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );

/**
 * The core version this copy bundles, and the minimum it needs for all of its
 * features. They match today; they diverge as soon as another Seedcast plugin
 * ships a newer core and this one has not caught up.
 */
define( 'SCSL_CORE_VERSION',     '1.26.2' );
define( 'SCSL_CORE_MIN_VERSION', '1.17.1' );

/*
 * Declare this copy of the shared library. Runs immediately rather than on a
 * hook: every plugin main file executes before plugins_loaded, so by the time
 * the registry loads at priority 0 all copies have declared themselves and the
 * highest version wins. Nothing loads here; this only registers a candidate.
 *
 * Sermon Library works exactly as it did standalone. The bundled library adds
 * shared settings and shared theming, and takes nothing away.
 */
require_once SCSL_PLUGIN_DIR . 'lib/seedcast-core/loader.php';
\Seedcast_Core_Registry::register(
	SCSL_CORE_VERSION,
	SCSL_PLUGIN_DIR . 'lib/seedcast-core/bootstrap.php'
);

require_once SCSL_PLUGIN_DIR . 'includes/helpers.php';
require_once SCSL_PLUGIN_DIR . 'includes/class-autoloader.php';
Autoloader::register();

final class SermonLibrary {

	private static ?SermonLibrary $instance = null;

	public static function instance(): SermonLibrary {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks(): void {
		add_action( 'init',            [ $this, 'maybe_migrate'             ], 1 );
		add_action( 'init',            [ $this, 'register_post_types'       ] );
		add_action( 'init',            [ $this, 'register_taxonomies'        ] );
		add_action( 'init',            [ $this, 'maybe_flush_rewrite_rules'  ], 99 );
		add_action( 'admin_enqueue_scripts',             [ $this, 'admin_assets'     ] );
		add_action( 'wp_enqueue_scripts',                [ $this, 'frontend_assets'  ] );
		// Elementor editor/preview iframes need styles enqueued separately
		add_action( 'elementor/preview/enqueue_styles',  [ $this, 'elementor_assets' ] );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'elementor_assets' ] );

		add_action( 'init', [ $this, 'register_with_core' ], 0 );

		( new Admin\AdminMenu() )->init();
		( new Admin\MetaBoxes() )->init();
		( new Admin\AdminColumns() )->init();
		( new Admin\CompletenessFilter() )->init();
		( new Frontend\Unlisted() )->init();
		( new Admin\SettingsPage() )->init();
		( new Admin\TopicsManager() )->init();
		( new Admin\ShortcodeGenerator() )->init();
		( new Frontend\TemplateLoader() )->init();
		( new Frontend\Shortcodes() )->init();
		( new Frontend\ContentList() )->init();
		( new Frontend\Schema() )->init();
		Scripture\Summary::init();
		Scripture\ListLoader::init();
		Scripture\Indexing::init();
		Frontend\LegacyRedirects::init();
		( new Frontend\ViewCounter() )->init();
		( new Frontend\ImageFallback() )->init();
		( new Frontend\PodcastFeed() )->init();
		( new Frontend\BulletinLibraryBridge() )->init();
		( new PDF\PDFGenerator() )->init();
		( new Admin\SeriesEngineImporter() )->init();
		( new Admin\SermonManagerImporter() )->init();
		( new Admin\Exporter() )->init();
		( new Admin\JsonImporter() )->init();
		( new Admin\ManifestImporter() )->init();
		( new Admin\PodcastScreen() )->init();
		( new Admin\AudioReclaim() )->init();
		( new Admin\Revisions() )->init();
		( new Admin\Completeness() )->init();
		( new Pro\ProBridge() )->init();
	}

	/**
	 * Declare this plugin to the shared library.
	 *
	 * Lets core load shared admin assets on Sermon Library's screens, and warn
	 * rather than fail if an older bundled core wins version negotiation.
	 *
	 * Runs on init at priority 0 rather than during construction, because
	 * construction happens on plugins_loaded, before translations may be
	 * loaded. The name is also a plain string rather than a translated one:
	 * it is a product name, and keeping it untranslated means moving this
	 * call back to construction could not silently reintroduce the
	 * "translations loaded too early" notice on WordPress 6.7 and later.
	 *
	 * @return void
	 */
	public function register_with_core(): void {
		if ( ! class_exists( '\\Seedcast_Core' ) ) return;

		\Seedcast_Core::register_plugin( 'seedcast-sermon-library', [
			'name'        => 'Sermon Library',
			'tagline'     => 'Sermons with series, speakers, scripture, and a podcast feed.',
			'icon'        => 'dashicons-playlist-audio',
			'admin'       => 'edit.php?post_type=scsl_sermon',
			'channel'     => 'free',
			'file'        => 'seedcast-sermon-library/seedcast-sermon-library.php',
			'version'     => SCSL_VERSION,
			'admin_menu'  => 'seedcast-sermon-library',
			'post_types'  => [ 'scsl_sermon', 'scsl_series', 'scsl_speaker' ],
			'submissions' => false, // No front-end submission forms, so the shared engine stays unhooked.
			'min_core'    => SCSL_CORE_MIN_VERSION,
		] );
	}

	/**
	 * Version-keyed migration check. Cheap on every load, and it self-heals
	 * installs where the files were updated in place, for example re-uploaded
	 * over FTP, without WordPress ever re-firing the activation hook.
	 */
	public function maybe_migrate(): void {
		if ( get_option( 'scsl_migrated_version' ) === SCSL_VERSION ) return;

		Installer::migrate_theme_option();
		update_option( 'scsl_migrated_version', SCSL_VERSION );

	}

	public function register_post_types(): void {
		// Registration order decides submenu order, and Sermons is what people
		// come here for.
		( new CPT\Sermon() )->register();
		( new CPT\Series() )->register();
		( new CPT\Speaker() )->register();
	}

	public function maybe_flush_rewrite_rules(): void {
		// Never flush rewrite rules during AJAX: expensive and unnecessary
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
		if ( get_option( 'scsl_flush_rewrite_rules' ) ) {
			delete_option( 'scsl_flush_rewrite_rules' );
			flush_rewrite_rules();
		}
	}


	public function register_taxonomies(): void {
		( new Taxonomies\Topic() )->register();
		( new Taxonomies\Scripture() )->register();
	}

	public function admin_assets( string $hook ): void {
		$screen    = get_current_screen();
		$cpts      = [ 'scsl_series', 'scsl_sermon', 'scsl_speaker' ];
		$on_cpt    = $screen && in_array( $screen->post_type, $cpts, true );
		// strpos rather than str_contains, which is PHP 8.0; the plugin header
		// declares 7.4 and this is the only thing that contradicted it.
		$on_sl = false !== strpos( $hook, 'seedcast-sermon-library' );

		// The plugin's settings now live on the shared Seedcast page, so the
		// admin assets have to load there too or the cover art picker has no
		// script behind it.
		$on_settings = false !== strpos( $hook, 'seedcast-settings' );

		if ( $on_cpt || $on_sl || $on_settings ) {
			wp_enqueue_style(  'scsl-admin', SCSL_PLUGIN_URL . 'assets/css/admin.css',   [ 'seedcast-core-admin' ], SCSL_VERSION );
			wp_enqueue_script( 'scsl-admin', SCSL_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], SCSL_VERSION, true );
			wp_localize_script( 'scsl-admin', 'scslAdmin', [
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'scsl_admin_nonce' ),
				'generating' => __( 'Generating…', 'seedcast-sermon-library' ),
			] );

			// wp.media is not loaded on settings screens by default.
			if ( $on_settings ) {
				wp_enqueue_media();
			}
		}
	}

	public function frontend_assets(): void {
		/*
		 * Loaded everywhere, on purpose.
		 *
		 * Loading these only where something of ours appears was tried and
		 * reverted. A page builder can hold a shortcode in a template stored
		 * as a separate post, so a page that plainly uses the plugin looks
		 * like a page that does not, and the styles come off a page that
		 * needs them. A stylesheet nobody needed is a cost; a page that
		 * renders wrongly is a fault, and the two are not worth trading.
		 *
		 * Worth revisiting with a rule that errs the other way: load unless
		 * certain, rather than skip unless certain.
		 */
		if ( get_option( 'scsl_disable_css' ) !== '1' ) {
			// Declaring the core stylesheet as a dependency guarantees the token
			// block loads first, so every var(--sc-*) below it resolves.
			wp_enqueue_style( 'scsl-frontend', SCSL_PLUGIN_URL . 'assets/css/frontend.css', [ 'seedcast-core' ], scsl_asset_version( 'assets/css/frontend.css' ) );
		}

		wp_enqueue_script( 'scsl-frontend', SCSL_PLUGIN_URL . 'assets/js/frontend.js', [ 'jquery' ], scsl_asset_version( 'assets/js/frontend.js' ), true );
	}

	/**
	 * Enqueue frontend CSS inside Elementor's editor and preview iframes.
	 * Called from init_hooks() via Elementor-specific hooks.
	 */
	public function elementor_assets(): void {
		if ( get_option( 'scsl_disable_css' ) === '1' ) return;
		wp_enqueue_style( 'scsl-frontend', SCSL_PLUGIN_URL . 'assets/css/frontend.css', [ 'seedcast-core' ], scsl_asset_version( 'assets/css/frontend.css' ) );
	}
}

register_activation_hook(   SCSL_PLUGIN_FILE, [ Installer::class, 'activate'   ] );
register_deactivation_hook( SCSL_PLUGIN_FILE, [ Installer::class, 'deactivate' ] );

// Priority 5: after the core registry loads at 0, so core is present.
add_action( 'plugins_loaded', function() {
	SermonLibrary::instance();
}, 5 );
