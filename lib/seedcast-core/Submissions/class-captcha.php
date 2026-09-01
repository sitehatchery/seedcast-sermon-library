<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Submissions
 */
namespace Seedcast\Core\Submissions;

use Seedcast\Core\Log;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared spam protection: honeypot plus pluggable captcha providers
 * (reCAPTCHA v2/v3, hCaptcha, Cloudflare Turnstile). Provider is chosen
 * in Seedcast settings so every child plugin inherits the same protection.
 */
class Captcha {

	/**
	 * Returns true if the honeypot field is empty (i.e. likely human).
	 * The field name is fixed so FormRenderer and this checker agree.
	 */
	public static function passes_honeypot(): bool {
		if ( get_option( 'sc_honeypot_enabled', '1' ) !== '1' ) return true;
		// Nonce is verified in SubmissionEngine::process() before this runs; this
		// check only compares the honeypot field against an empty string.
		$val = isset( $_POST['sc_hp_note'] ) ? sanitize_text_field( wp_unslash( $_POST['sc_hp_note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return $val === '';
	}

	/**
	 * Verifies the active captcha provider's token, if one is configured.
	 * Returns true when no provider is set (feature simply off).
	 */
	public static function verify(): bool {
		$provider = get_option( 'sc_captcha_provider', 'none' );
		if ( $provider === 'none' || $provider === '' ) return true;

		$secret = get_option( 'sc_captcha_secret', '' );
		if ( ! $secret ) return true; // Misconfigured - don't lock users out.

		$token = self::posted_token( $provider );
		if ( ! $token ) {
			Log::debug( "captcha ({$provider}) blocked: no response token was posted - the widget likely wasn't completed, or its script failed to load." );
			return false;
		}

		$endpoint = self::verify_endpoint( $provider );
		if ( ! $endpoint ) return true;

		$resp = wp_remote_post( $endpoint, [
			// Kept short and well under typical shared-hosting PHP
			// max_execution_time budgets (commonly 10-30s). If this call
			// hangs and PHP's own time limit is lower than the timeout here,
			// PHP kills the whole script with an uncatchable fatal - which
			// looks identical to a random crash rather than "captcha
			// provider was slow to respond."
			'timeout' => 5,
			'body'    => [
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			],
		] );

		if ( is_wp_error( $resp ) ) {
			// A network-level failure reaching the provider (e.g. outbound
			// HTTPS blocked by the host's firewall) looks identical to a
			// rejected captcha to the visitor, but is a server config issue,
			// not spam - log it distinctly so it isn't mistaken for one.
			Log::debug( "captcha ({$provider}) verification request failed: " . $resp->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['success'] ) ) {
			$codes = isset( $body['error-codes'] ) ? implode( ', ', (array) $body['error-codes'] ) : 'none reported';
			Log::debug( "captcha ({$provider}) rejected the token. Error codes: {$codes}" );
			return false;
		}
		return true;
	}

	private static function posted_token( string $provider ): string {
		$field = $provider === 'hcaptcha' ? 'h-captcha-response'
			: ( $provider === 'turnstile' ? 'cf-turnstile-response' : 'g-recaptcha-response' );
		// Nonce is verified in SubmissionEngine::process() before this runs.
		return isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private static function verify_endpoint( string $provider ): string {
		/*
		 * These are server to server verification endpoints, not assets. The
		 * offloading check looks for a remote host in a string and cannot tell
		 * the difference, so it reads google.com here as a script being loaded
		 * from someone else's server. Nothing is enqueued from these hosts;
		 * the site posts a challenge token to them and reads back a yes or no,
		 * and only when an administrator has entered keys for one.
		 */
		switch ( $provider ) {
			case 'hcaptcha':  return 'https://hcaptcha.com/siteverify';
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Verification API, not a CDN asset.
			case 'turnstile': return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
			case 'recaptcha': return 'https://www.google.com/recaptcha/api/siteverify';
			default:          return '';
		}
	}

	/**
	 * Prints the provider widget's mount point. Deliberately NOT the
	 * provider's own auto-render class/attributes (e.g. "g-recaptcha") -
	 * this is rendered explicitly by the shared core script instead, because implicit
	 * auto-render tokens are single-use and, on a failed submission (wrong
	 * captcha, honeypot, a temporary server error, anything), there is no
	 * reliable way to reset just that one widget without a full page reload.
	 * Explicit rendering lets us track each widget's ID and reset only the
	 * one in the form that failed. Called by FormRenderer.
	 */
	public static function render_widget(): void {
		$provider = get_option( 'sc_captcha_provider', 'none' );
		$site_key = get_option( 'sc_captcha_site_key', '' );
		if ( $provider === 'none' || ! $site_key ) return;

		printf(
			'<div class="sc-captcha" data-provider="%s" data-sitekey="%s"></div>',
			esc_attr( $provider ),
			esc_attr( $site_key )
		);
	}
}
