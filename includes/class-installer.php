<?php
namespace SeedcastSermonLibrary;

use SeedcastSermonLibrary\CPT;
use SeedcastSermonLibrary\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) exit;

class Installer {

	public static function activate(): void {
		self::create_tables();
		self::set_defaults();

		// The shared weekly completeness table. Created on demand rather than
		// unconditionally, so a site that never registers a definition does not
		// carry an empty table.
		if ( class_exists( '\Seedcast_Core' ) && method_exists( '\Seedcast_Core', 'require_completeness' ) ) {
			\Seedcast_Core::require_completeness();
		}

		// Register CPTs and taxonomies so rewrite rules exist before flushing.
		// The activation hook fires before 'init', so we trigger registration manually.
		( new CPT\Sermon() )->register();
		( new CPT\Series() )->register();
		( new CPT\Speaker() )->register();
		( new Taxonomies\Topic() )->register();
		( new Taxonomies\Scripture() )->register();

		flush_rewrite_rules();
		update_option( 'scsl_flush_rewrite_rules', '1' );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
		// Clear SEO plugin config flags so templates are re-written on next activation
		delete_option( 'scsl_yoast_configured' );
		delete_option( 'scsl_rankmath_configured' );
		delete_option( 'scsl_aioseo_configured' );
	}

	private static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}scsl_asset_status (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sermon_id   BIGINT UNSIGNED NOT NULL,
			asset_key   VARCHAR(64)     NOT NULL,
			status      VARCHAR(32)     NOT NULL DEFAULT 'pending',
			generated_at DATETIME       NULL,
			published_at DATETIME       NULL,
			PRIMARY KEY (id),
			UNIQUE KEY sermon_asset (sermon_id, asset_key),
			KEY sermon_id (sermon_id)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'scsl_db_version', SCSL_VERSION );
	}

	/**
	 * One-time move of the theme setting onto the shared library's key.
	 *
	 * Sermon Library used to carry its own theme option and its own token set.
	 * Both are now core's, so a church that sets a theme once has it apply to
	 * every Seedcast plugin rather than configuring each separately.
	 *
	 * The existing value is carried across, so a site already running the warm
	 * theme keeps it. Core is only written if it has no value yet, so a site
	 * that already chose a theme through another Seedcast plugin is never
	 * overwritten by an older Sermon Library value.
	 *
	 * This migration is temporary and should be deleted once it has run
	 * everywhere, rather than carried forward indefinitely.
	 */
	public static function migrate_theme_option(): void {
		if ( get_option( 'scsl_core_migration_done' ) === '1' ) return;

		$theme = get_option( 'scsl_theme', false );
		if ( false !== $theme && false === get_option( 'sc_theme', false ) ) {
			update_option( 'sc_theme', $theme );
		}
		delete_option( 'scsl_theme' );

		update_option( 'scsl_core_migration_done', '1' );
	}

	private static function set_defaults(): void {
		self::migrate_theme_option();

		$defaults = [
			'scsl_podcast_items'         => '50',
			'scsl_image_fallback_series' => '1',
			'scsl_disable_css'           => '0',
			'scsl_series_slug'           => 'series',
			'scsl_sermon_slug'           => 'sermon',
			'scsl_speaker_slug'          => 'speakers',
			'scsl_sermons_per_page'      => '10',
			'scsl_series_per_page'       => '12',
			'scsl_show_topics_on_series' => '1',
			'scsl_show_search_filter'    => '1',
			'scsl_show_year_filter'      => '1',
			'scsl_show_scripture_panel'  => '1',
			'scsl_tab_more_label'        => 'More',
			'scsl_bible_provider'        => 'bible.com',
			'scsl_bible_translation'     => 'NIV',
		];

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
