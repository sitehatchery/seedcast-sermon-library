<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Submissions
 */
namespace Seedcast\Core\Submissions;

use Seedcast\Core\Install;
use Seedcast\Core\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared moderation layer over the seedcast_submissions table.
 *
 * Provides data access (query/count/get/update status/link) plus the AJAX
 * endpoints the admin queue uses. The actual per-plugin queue *screens*
 * live in each child plugin, but they all call these methods so behaviour
 * (statuses, notes, linking) stays consistent across the suite.
 *
 * Canonical statuses: pending, approved, published, rejected, needs_edit, archived.
 */
class Moderation {

	public const STATUSES = [ 'pending', 'approved', 'published', 'rejected', 'needs_edit', 'archived' ];

	public function init(): void {
		add_action( 'wp_ajax_seedcast_moderate', [ $this, 'ajax_moderate' ] );
	}

	private static function table(): string {
		return Install::submissions_table();
	}

	/**
	 * Query submissions for a source (+ optional type/status).
	 *
	 * @return array<int, object>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;
		$args = wp_parse_args( $args, [
			'source' => '', 'type' => '', 'status' => '',
			'per_page' => 20, 'page' => 1, 'orderby' => 'submitted_at', 'order' => 'DESC',
		] );

		$where = [ '1=1' ];
		$params = [];
		if ( $args['source'] ) { $where[] = 'source = %s'; $params[] = $args['source']; }
		if ( $args['type'] )   { $where[] = 'type = %s';   $params[] = $args['type']; }
		if ( $args['status'] ) { $where[] = 'status = %s'; $params[] = $args['status']; }

		$orderby = in_array( $args['orderby'], [ 'submitted_at', 'moderated_at', 'status' ], true ) ? $args['orderby'] : 'submitted_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$per     = max( 1, absint( $args['per_page'] ) );
		$offset  = max( 0, ( absint( $args['page'] ) - 1 ) * $per );

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where )
			. " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per;
		$params[] = $offset;

		// Custom table read: no core API exists; the query is prepared, the table
		// name is a trusted literal from $wpdb->prefix, and results are
		// request-scoped so object caching adds no benefit.
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];
	}

	public static function count( string $source = '', string $type = '', string $status = '' ): int {
		global $wpdb;
		$where = [ '1=1' ]; $params = [];
		if ( $source ) { $where[] = 'source = %s'; $params[] = $source; }
		if ( $type )   { $where[] = 'type = %s';   $params[] = $type; }
		if ( $status ) { $where[] = 'status = %s'; $params[] = $status; }
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		// Custom table read: table name is a trusted literal from $wpdb->prefix;
		// the query is prepared when it carries params, and a COUNT is
		// request-scoped so object caching adds no benefit.
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		// Custom table read: table name is a trusted literal from $wpdb->prefix,
		// the query is prepared, and a single-row lookup is request-scoped.
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $row ?: null;
	}

	/**
	 * Find the submission row that was converted into a given post, if any.
	 * Used to show "submitted from / on" info on the resulting post's editor.
	 */
	public static function get_by_linked_post( int $post_id ): ?object {
		global $wpdb;
		// Custom table read: table name is a trusted literal from $wpdb->prefix,
		// the query is prepared, and this lookup is request-scoped.
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE linked_post = %d ORDER BY id DESC LIMIT 1', $post_id ) );
		return $row ?: null;
	}

	public static function set_status( int $id, string $status, ?string $notes = null ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) return false;

		$data = [
			'status'       => $status,
			'moderated_at' => current_time( 'mysql' ),
			'moderated_by' => get_current_user_id(),
		];
		if ( null !== $notes ) $data['admin_notes'] = $notes;

		// Custom table write via $wpdb->update() (values prepared internally); a write is not a cacheable read.
		$ok = $wpdb->update( self::table(), $data, [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false !== $ok ) {
			/**
			 * Fires after a submission's status changes. Child plugins hook
			 * this to sync a linked post, send follow-ups, etc.
			 */
			do_action( 'seedcast/submission/status_changed', $id, $status );
		}
		return false !== $ok;
	}

	/**
	 * Update just the admin note, without changing status or moderated_at.
	 * Used to clear a note once it's been actioned.
	 */
	public static function set_status_notes_only( int $id, string $notes ): bool {
		global $wpdb;
		// Custom table write via $wpdb->update() (values prepared internally); a write is not a cacheable read.
		return false !== $wpdb->update( self::table(), [ 'admin_notes' => $notes ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** Link a submission to a published post (e.g. a testimony resolving a prayer). */
	public static function link_post( int $id, int $post_id ): bool {
		global $wpdb;
		// Custom table write via $wpdb->update() (values prepared internally); a write is not a cacheable read.
		return false !== $wpdb->update( self::table(), [ 'linked_post' => $post_id ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function ajax_moderate(): void {
		check_ajax_referer( 'seedcast_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'seedcast-sermon-library' ) ], 403 );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : null;

		if ( ! $id || ! self::set_status( $id, $status, $notes ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not update submission.', 'seedcast-sermon-library' ) ] );
		}
		wp_send_json_success( [ 'id' => $id, 'status' => $status ] );
	}
}
