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
 * Debug logging. Everything routes through here so there's one phpcs exemption
 * instead of a dozen, and so production stays quiet unless WP_DEBUG is on.
 */
final class Log {

	/**
	 * Write a diagnostic line when debug logging is enabled.
	 *
	 * @param string $message Message to record.
	 * @return void
	 */
	public static function debug( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated behind WP_DEBUG and WP_DEBUG_LOG; this is the suite's single logging entry point.
		error_log( 'Seedcast: ' . $message );
	}
}
