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
 * The shared submissions table.
 *
 * Created on demand, not on every activation, so a site that never collects
 * anything doesn't carry an empty table. Plugins that do collect call
 * Seedcast_Core::require_submissions() on activation; the write path also
 * self-heals if the table goes missing some other way.
 */
final class Install {

	/**
	 * Schema version. Bump whenever the column list below changes.
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Option recording the installed schema version.
	 */
	public const SCHEMA_OPTION = 'seedcast_submissions_schema';

	/**
	 * The shared submissions table name.
	 *
	 * @return string
	 */
	public static function submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seedcast_submissions';
	}

	/**
	 * Create or update the shared submissions table if needed.
	 *
	 * @param bool $force Skip the version short circuit.
	 * @return void
	 */
	public static function ensure_submissions_table( bool $force = false ): void {
		if ( ! $force && get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::submissions_table();

		$sql = "CREATE TABLE {$table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source       VARCHAR(64)     NOT NULL,
			type         VARCHAR(64)     NOT NULL,
			status       VARCHAR(32)     NOT NULL DEFAULT 'pending',
			payload      LONGTEXT        NULL,
			author_name  VARCHAR(191)    NULL,
			author_email VARCHAR(191)    NULL,
			linked_post  BIGINT UNSIGNED NULL,
			service_id   BIGINT UNSIGNED NULL,
			source_url   VARCHAR(500)    NULL,
			admin_notes  TEXT            NULL,
			submitted_at DATETIME        NOT NULL,
			moderated_at DATETIME        NULL,
			moderated_by BIGINT UNSIGNED NULL,
			ip_hash      CHAR(64)        NULL,
			PRIMARY KEY  (id),
			KEY source_type  (source, type),
			KEY status       (status),
			KEY linked_post  (linked_post),
			KEY service_id   (service_id),
			KEY submitted_at (submitted_at)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::repair_columns();

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Guarantee every column the write path uses actually exists.
	 *
	 * dbDelta is normally sufficient, but its SQL parsing has proven finicky
	 * in ways that are hard to detect from the outside: a live site was found
	 * missing source_url despite dbDelta having run repeatedly. This checks
	 * the table's real columns and adds anything genuinely absent, so the
	 * outcome does not depend on how dbDelta parsed the statement above.
	 *
	 * @return void
	 */
	private static function repair_columns(): void {
		global $wpdb;
		$table = self::submissions_table();

		// Schema introspection on a custom table. $table is a trusted literal
		// built from $wpdb->prefix plus a hardcoded string, and an identifier
		// in SHOW COLUMNS cannot be passed through $wpdb->prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			return;
		}

		$expected = array(
			'source'       => "VARCHAR(64) NOT NULL DEFAULT ''",
			'type'         => "VARCHAR(64) NOT NULL DEFAULT ''",
			'status'       => "VARCHAR(32) NOT NULL DEFAULT 'pending'",
			'payload'      => 'LONGTEXT NULL',
			'author_name'  => 'VARCHAR(191) NULL',
			'author_email' => 'VARCHAR(191) NULL',
			'linked_post'  => 'BIGINT UNSIGNED NULL',
			'service_id'   => 'BIGINT UNSIGNED NULL',
			'source_url'   => 'VARCHAR(500) NULL',
			'admin_notes'  => 'TEXT NULL',
			'submitted_at' => 'DATETIME NULL',
			'moderated_at' => 'DATETIME NULL',
			'moderated_by' => 'BIGINT UNSIGNED NULL',
			'ip_hash'      => 'CHAR(64) NULL',
		);

		foreach ( $expected as $column => $definition ) {
			if ( in_array( $column, $existing, true ) ) {
				continue;
			}
			// $table, $column and $definition are all trusted literals from the
			// hardcoded list above. Identifiers and DDL cannot go through prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
			if ( false === $result ) {
				Log::debug( "failed to add missing column '{$column}' to {$table}: " . $wpdb->last_error );
			}
		}
	}

	/**
	 * Completeness schema version. Independent of the submissions schema so
	 * the two evolve without forcing each other to re-run.
	 */
	public const COMPLETENESS_SCHEMA_VERSION = '2';

	/**
	 * Option recording the installed completeness schema version.
	 */
	public const COMPLETENESS_SCHEMA_OPTION = 'seedcast_completeness_schema';

	/**
	 * The weekly completeness table name.
	 *
	 * @return string
	 */
	public static function completeness_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'seedcast_completeness';
	}

	/**
	 * Create or update the completeness table if needed.
	 *
	 * @param bool $force Skip the version short circuit.
	 * @return void
	 */
	public static function ensure_completeness_table( bool $force = false ): void {
		if ( ! $force && get_option( self::COMPLETENESS_SCHEMA_OPTION ) === self::COMPLETENESS_SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::completeness_table();

		$sql = "CREATE TABLE {$table} (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source             VARCHAR(64)     NOT NULL,
			week_start         DATE            NOT NULL,
			score              TINYINT UNSIGNED NOT NULL DEFAULT 0,
			metadata_score     TINYINT UNSIGNED NOT NULL DEFAULT 0,
			content_score      TINYINT UNSIGNED NOT NULL DEFAULT 0,
			comprehensive_score   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			comprehensive_content TINYINT UNSIGNED NOT NULL DEFAULT 0,
			config             LONGTEXT        NULL,
			item_count         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			state              VARCHAR(32)     NOT NULL DEFAULT 'missing',
			definition_version VARCHAR(32)     NOT NULL DEFAULT '1',
			is_locked          TINYINT(1)      NOT NULL DEFAULT 0,
			is_amended         TINYINT(1)      NOT NULL DEFAULT 0,
			details            LONGTEXT        NULL,
			computed_at        DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_week (source, week_start),
			KEY locked (source, is_locked)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::COMPLETENESS_SCHEMA_OPTION, self::COMPLETENESS_SCHEMA_VERSION );
	}

	/**
	 * Seed core's option defaults. Idempotent.
	 *
	 * @return void
	 */
	public static function set_defaults(): void {
		$defaults = array_merge(
			array(
				'seedcast_theme'             => 'light',
				'seedcast_captcha_provider'  => 'none',
				'seedcast_captcha_site_key'  => '',
				'seedcast_captcha_secret'    => '',
				'seedcast_honeypot_enabled'  => '1',
			),
			Church::DEFAULTS
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
