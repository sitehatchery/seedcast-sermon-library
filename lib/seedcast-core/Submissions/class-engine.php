<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Submissions
 */
namespace Seedcast\Core\Submissions;

use Seedcast\Core\Install;
use Seedcast\Core\Log;
use Seedcast\Core\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generic front-end submission engine shared across Seedcast plugins.
 *
 * Child plugins do not talk to the database directly. They:
 *   1. Render a form via Seedcast\Core\Frontend\FormRenderer (or their own markup
 *      that posts to the `sc_submit` action).
 *   2. Receive a validated, spam-checked, stored submission.
 *   3. Hook `seedcast/submission/stored` to react (e.g. notify admins).
 *
 * Nothing here is testimony- or sermon-specific; the `source` and `type`
 * fields plus the free-form `payload` keep it fully generic.
 */
class SubmissionEngine {

	public function init(): void {
		// Public (logged-out) and private (logged-in) form posts.
		add_action( 'admin_post_nopriv_sc_submit', [ $this, 'handle_post' ] );
		add_action( 'admin_post_sc_submit',        [ $this, 'handle_post' ] );
		// AJAX path (no page reload; notice shown inline in the posting form).
		add_action( 'wp_ajax_nopriv_sc_ajax_submit', [ $this, 'ajax_submit' ] );
		add_action( 'wp_ajax_sc_ajax_submit',        [ $this, 'ajax_submit' ] );
	}

	/**
	 * Shared validation + insert used by both the classic POST handler and the
	 * AJAX handler. Returns [ true, '' ] on success or [ false, message ].
	 *
	 * @return array{0:bool,1:string}
	 */
	private function process( array $post ): array {
		$source = isset( $post['sc_source'] ) ? sanitize_key( wp_unslash( $post['sc_source'] ) ) : '';
		$type   = isset( $post['sc_type'] )   ? sanitize_key( wp_unslash( $post['sc_type'] ) )   : '';

		$nonce_action = "sc_submit_{$source}_{$type}";
		if ( ! isset( $post['sc_nonce'] ) || ! wp_verify_nonce( wp_unslash( $post['sc_nonce'] ), $nonce_action ) ) {
			return [ false, __( 'Security check failed. Please try again.', 'seedcast-sermon-library' ) ];
		}

		if ( ! Captcha::passes_honeypot() ) {
			Log::debug( 'submission blocked: honeypot field was filled (likely a bot, or rarely a browser autofilling a hidden field).' );
			return [ false, __( 'Your submission could not be verified.', 'seedcast-sermon-library' ) ];
		}
		if ( ! Captcha::verify() ) {
			return [ false, __( 'Your submission could not be verified.', 'seedcast-sermon-library' ) ];
		}

		$payload = apply_filters( "seedcast/submission/parse_payload/{$source}/{$type}", [], $post );
		if ( is_wp_error( $payload ) ) {
			return [ false, $payload->get_error_message() ];
		}

		$result = $this->insert( [
			'source'       => $source,
			'type'         => $type,
			'payload'      => $payload,
			'author_name'  => isset( $post['sc_author_name'] )  ? sanitize_text_field( wp_unslash( $post['sc_author_name'] ) ) : '',
			'author_email' => isset( $post['sc_author_email'] ) ? sanitize_email( wp_unslash( $post['sc_author_email'] ) )     : '',
			'service_id'   => isset( $post['sc_service_id'] )   ? absint( $post['sc_service_id'] ) : 0,
			'source_url'   => isset( $post['sc_source_url'] )   ? esc_url_raw( wp_unslash( $post['sc_source_url'] ) ) : '',
			/*
			 * Which post the form was on, as an id rather than only a URL.
			 * The URL alone breaks as a grouping key the moment a permalink
			 * changes or a query string is appended, so counting submissions
			 * per piece of content needs the id.
			 */
			'linked_post'  => isset( $post['sc_linked_post'] )  ? absint( $post['sc_linked_post'] ) : 0,
		] );

		if ( is_wp_error( $result ) ) {
			return [ false, $result->get_error_message() ];
		}

		return [ true, '' ];
	}

	/**
	 * AJAX submission: returns JSON so the form can show an inline notice
	 * without a page reload (and without both forms on a page reacting).
	 *
	 * Wrapped in an output buffer that's discarded before the JSON is sent.
	 * Without this, any stray output ahead of wp_send_json_*() - a PHP
	 * notice/warning/deprecation from this plugin, a conflicting plugin, or
	 * the active theme, triggered anywhere earlier in the request - breaks
	 * the JSON the front-end is expecting and the form shows a generic
	 * "Request failed" with no way to tell why. Discarding stray output here
	 * guarantees a clean, parseable response regardless of where a warning
	 * came from.
	 */
	public function ajax_submit(): void {
		// Defensive headroom: if the captcha provider's verification call
		// (see Captcha::verify()) is slow, a low host-configured
		// max_execution_time can kill the whole script before our own 5s
		// request timeout even triggers - an uncatchable fatal that looks
		// like a random crash rather than "the outbound call was slow."
		// No-op (silently) on hosts where set_time_limit() is disabled.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 20 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- defensive headroom for a slow captcha call; intentionally silent because some hosts disable this function entirely, which throws a warning we don't want surfaced.
		}

		ob_start();
		// Nonce is verified inside process() before any posted value is used;
		// process() unslashes and sanitizes each field individually.
		list( $ok, $message ) = $this->process( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stray = trim( (string) ob_get_clean() );
		if ( $stray ) {
			// Log::debug() only writes to the server's PHP error log - never
			// shown to the visitor - so this is safe to log unconditionally
			// rather than gating behind WP_DEBUG, which is commonly off on
			// production and would otherwise hide exactly this diagnosis.
			Log::debug( 'AJAX submit: discarded unexpected output before JSON response: ' . substr( $stray, 0, 500 ) );
		}

		if ( $ok ) {
			$source = isset( $_POST['sc_source'] ) ? sanitize_key( wp_unslash( $_POST['sc_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside process() above.
			$type   = isset( $_POST['sc_type'] ) ? sanitize_key( wp_unslash( $_POST['sc_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			/**
			 * The JSON sent back after a successful submission.
			 *
			 * The default wording suits something that is moderated before it
			 * appears. Plenty of forms are not: a visitor telling a church they
			 * are coming is not waiting for approval, and being told their
			 * submission will be reviewed is both wrong and cold.
			 *
			 * A child plugin can also return `replace`, a block of HTML that
			 * takes the place of the form's nearest ancestor carrying
			 * data-sc-replace. That is how a form becomes a confirmation in
			 * place rather than growing a notice above it that nobody scrolled
			 * back up to read.
			 *
			 * @param array $payload message, and optionally replace.
			 * @param array $post    The posted values.
			 */
			$payload = apply_filters(
				"seedcast/submission/response/{$source}/{$type}",
				[ 'message' => __( 'Thank you - your submission has been received and will be reviewed.', 'seedcast-sermon-library' ) ],
				$_POST // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside process() above.
			);

			wp_send_json_success( $payload );
		}
		wp_send_json_error( [ 'message' => $message ] );
	}

	public function handle_post(): void {
		// Nonce is verified inside process() before any posted value is used.
		list( $ok, $message ) = $this->process( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$source = isset( $_POST['sc_source'] ) ? sanitize_key( wp_unslash( $_POST['sc_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type   = isset( $_POST['sc_type'] )   ? sanitize_key( wp_unslash( $_POST['sc_type'] ) )   : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->redirect_back( $ok, $ok ? '' : $message, $source, $type );
	}

	/**
	 * Programmatic insert - the canonical write path. Child plugins can call
	 * this directly (e.g. when staff add an entry from the admin).
	 *
	 * @param array $data {
	 *   @type string $source   Child plugin slug (required).
	 *   @type string $type     Submission type, e.g. 'testimony' (required).
	 *   @type array  $payload  Arbitrary structured fields.
	 *   @type string $status   Defaults to 'pending'.
	 *   @type string $author_name
	 *   @type string $author_email
	 *   @type int    $service_id
	 * }
	 * @return int|\WP_Error  Insert ID or error.
	 */
	public function insert( array $data ) {
		global $wpdb;

		if ( empty( $data['source'] ) || empty( $data['type'] ) ) {
			return new \WP_Error( 'sc_missing_source', __( 'Submission source and type are required.', 'seedcast-sermon-library' ) );
		}

		$row = [
			'source'       => sanitize_key( $data['source'] ),
			'type'         => sanitize_key( $data['type'] ),
			'status'       => sanitize_key( $data['status'] ?? 'pending' ),
			'payload'      => wp_json_encode( $data['payload'] ?? [] ),
			'author_name'  => isset( $data['author_name'] )  ? sanitize_text_field( $data['author_name'] )  : null,
			'author_email' => isset( $data['author_email'] ) ? sanitize_email( $data['author_email'] )      : null,
			'service_id'   => ! empty( $data['service_id'] ) ? absint( $data['service_id'] ) : null,
			'source_url'   => ! empty( $data['source_url'] ) ? esc_url_raw( $data['source_url'] ) : null,
			'linked_post'  => ! empty( $data['linked_post'] ) ? absint( $data['linked_post'] ) : null,
			'submitted_at' => current_time( 'mysql' ),
			'ip_hash'      => self::ip_hash(),
		];

		// Custom table has no core API; $wpdb->insert() prepares all values
		// internally, and an INSERT is not a cacheable read.
		$ok = $wpdb->insert( Install::submissions_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $ok ) {
			// The most common real-world cause here isn't malformed data - it's
			// the table being missing or out of date on a site where the
			// plugin's files were updated in place (e.g. re-uploaded over
			// FTP/cPanel) without WordPress ever re-firing the activation
			// hook, so the activation installer never ran. dbDelta() is safe to
			// re-run - it only adds what's missing - so we self-heal once
			// before giving up.
			Install::ensure_submissions_table( true );
			$ok = $wpdb->insert( Install::submissions_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		if ( false === $ok ) {
			if ( $wpdb->last_error ) {
				// Not shown to the visitor (could leak schema details); logged
				// so an admin checking the PHP error log can see the real
				// cause instead of just "Could not save submission."
				Log::debug( 'submission insert failed: ' . $wpdb->last_error );
			}
			return new \WP_Error( 'sc_db_error', __( 'Could not save submission.', 'seedcast-sermon-library' ) );
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a submission is stored.
		 *
		 * @param int   $id   Submission row ID.
		 * @param array $row  Stored (sanitized) row data.
		 */
		try {
			do_action( 'seedcast/submission/stored', $id, $row );
		} catch ( \Throwable $e ) {
			// The submission itself already succeeded - a broken listener
			// (e.g. a notification email failing) must not turn a real
			// success into an opaque "Request failed" for the visitor.
			Log::debug( 'a seedcast/submission/stored listener threw: ' . $e->getMessage() );
		}

		return $id;
	}

	private function redirect_back( bool $success, string $message, string $source = '', string $type = '' ): void {
		$referer = wp_get_referer() ?: home_url( '/' );
		$url = add_query_arg(
			[
				'sc_submitted' => $success ? '1' : '0',
				'sc_msg'       => $message ? rawurlencode( $message ) : false,
				'sc_src'       => $source ?: false,
				'sc_stype'     => $type ?: false,
			],
			$referer
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * One-way IP hash for rate limiting / abuse tracing without storing PII.
	 */
	private static function ip_hash(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! $ip ) return null;
		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}
}
