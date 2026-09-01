<?php
/**
 * Uninstall SermonLibrary
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin options and custom database tables.
 *
 * @package SermonLibrary
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		exit;
}

global $wpdb;

// ── Remove plugin options ─────────────────────────────────────────────────
$scsl_options = [
	'scsl_series_slug',
	'scsl_sermon_slug',
	'scsl_speaker_slug',
	'scsl_bible_provider',
	'scsl_bible_translation',
	'scsl_show_scripture_panel',
	'scsl_theme',
	'scsl_db_version',
	'scsl_sermons_per_page',
	'scsl_filter_threshold',
	'scsl_podcast_include_series',
	'scsl_tab_more_label',
	'scsl_series_per_page',
	'scsl_show_topics_on_series',
	'scsl_show_search_filter',
	'scsl_show_year_filter',
	'scsl_bible_provider',
	'scsl_bible_translation',
	'scsl_label_sermon',
	'scsl_label_sermons',
	'scsl_label_series',
	'scsl_label_speaker',
	'scsl_label_speakers',
	'scsl_flush_rewrite_rules',
			'scsl_yoast_configured',
	'scsl_rankmath_configured',
	'scsl_aioseo_configured',
];

foreach ( $scsl_options as $scsl_option ) {
		delete_option( $scsl_option );
}

// ── Remove custom table ───────────────────────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scsl_asset_status" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// ── Remove post meta (optional: preserves content if plugin is reinstalled)
// Uncomment the lines below only if you want a hard delete of all sermon data.
// Warning: this is irreversible.
//
// $scsl_post_types = [ 'scsl_sermon', 'scsl_series', 'scsl_speaker' ];
// foreach ( $scsl_post_types as $type ) {
//     $scsl_post_ids = get_posts( [ 'post_type' => $type, 'numberposts' => -1, 'fields' => 'ids' ] );
//     foreach ( $scsl_post_ids as $id ) {
//         wp_delete_post( $id, true );
//     }
// }
