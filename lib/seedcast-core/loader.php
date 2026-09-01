<?php
/**
 * Seedcast Core: version negotiation loader.
 *
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository. Any edit made to a
 * vendored copy is silently discarded on the next sync and, worse, makes the
 * copies non-identical so runtime behaviour starts depending on which one wins.
 *
 * FROZEN. Don't change the public surface of this file.
 *
 * Every plugin ships a byte-identical copy. Whichever loads first wins the
 * class_exists guard, and that won't be the newest one, because WordPress
 * loads plugins alphabetically by path. So this file stays put and everything
 * else evolves in bootstrap.php.
 *
 * @package Seedcast\Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Seedcast_Core_Registry', false ) ) {

	/**
	 * Collects every bundled core copy, picks the highest version, loads it.
	 */
	class Seedcast_Core_Registry {

		/**
		 * Candidate copies declared by plugin main files.
		 *
		 * @var array<int, array{version:string,bootstrap:string}>
		 */
		private static $candidates = array();

		/**
		 * Whether load() has already run.
		 *
		 * @var bool
		 */
		private static $loaded = false;

		/**
		 * Declare a bundled copy. Called directly from each plugin main file,
		 * not on a hook: all plugin main files execute before plugins_loaded
		 * fires, so every copy has declared itself by the time load() runs.
		 *
		 * @param string $version        Version of THIS bundled copy.
		 * @param string $bootstrap_path Absolute path to this copy's bootstrap.php.
		 * @return void
		 */
		public static function register( $version, $bootstrap_path ) {
			self::$candidates[] = array(
				'version'   => $version,
				'bootstrap' => $bootstrap_path,
			);
		}

		/**
		 * Load the highest declared version. Runs once per request.
		 *
		 * @return void
		 */
		public static function load() {
			if ( self::$loaded || empty( self::$candidates ) ) {
				return;
			}

			$winner = self::$candidates[0];

			foreach ( self::$candidates as $candidate ) {
				if ( version_compare( $candidate['version'], $winner['version'], '>' ) ) {
					$winner = $candidate;
				}
			}

			self::$loaded = true;

			if ( file_exists( $winner['bootstrap'] ) ) {
				require_once $winner['bootstrap'];
			}
		}
	}

	add_action( 'plugins_loaded', array( 'Seedcast_Core_Registry', 'load' ), 0 );
}
