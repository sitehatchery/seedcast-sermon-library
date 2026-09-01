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
 * Shared stylesheet, shared script, theme body class.
 *
 * Whichever core copy wins negotiation emits the :root token block, which is
 * why theming works the same whether a site runs one plugin or five.
 *
 * Handles: 'seedcast-core' and 'seedcast-core-admin'. Declare them as
 * dependencies from plugin assets rather than re-enqueueing.
 */
final class Assets {

	/**
	 * Front-end style and script handle.
	 */
	public const HANDLE = 'seedcast-core';

	/**
	 * Admin style and script handle.
	 */
	public const ADMIN_HANDLE = 'seedcast-core-admin';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin' ) );
		add_filter( 'body_class', array( $this, 'theme_body_class' ) );

		// Elementor renders in its own iframes, which do not inherit the
		// front-end enqueue. Church sites are predominantly Elementor and Divi,
		// so this matters more here than it would elsewhere.
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'frontend' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( $this, 'frontend' ) );
	}

	/**
	 * Shared front-end assets.
	 *
	 * @return void
	 */
	public function frontend(): void {
		wp_enqueue_style(
			self::HANDLE,
			SEEDCAST_CORE_URL . 'assets/css/seedcast-core.css',
			array(),
			SEEDCAST_CORE_VERSION
		);
		wp_enqueue_script(
			self::HANDLE,
			SEEDCAST_CORE_URL . 'assets/js/seedcast-core.js',
			array(),
			SEEDCAST_CORE_VERSION,
			true
		);
		wp_localize_script(
			self::HANDLE,
			'seedcastCore',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Shared admin assets.
	 *
	 * Registered unconditionally so any plugin can declare them as a
	 * dependency on any screen. Registration alone does not load anything.
	 * They are enqueued on core's own screens and on screens owned by a
	 * registered suite plugin.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function admin( string $hook ): void {
		wp_register_style(
			self::ADMIN_HANDLE,
			SEEDCAST_CORE_URL . 'assets/css/seedcast-core-admin.css',
			array(),
			SEEDCAST_CORE_VERSION
		);
		wp_register_script(
			self::ADMIN_HANDLE,
			SEEDCAST_CORE_URL . 'assets/js/seedcast-core-admin.js',
			array( 'jquery' ),
			SEEDCAST_CORE_VERSION,
			true
		);
		wp_localize_script(
			self::ADMIN_HANDLE,
			'seedcastAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sc_admin_nonce' ),
				'labels'  => array(
					'active'   => __( 'Active', 'seedcast-sermon-library' ),
					'inactive' => __( 'Inactive', 'seedcast-sermon-library' ),
					'failed'   => __( 'Update failed.', 'seedcast-sermon-library' ),
				),
			)
		);

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// strpos rather than str_contains: the suite supports PHP 7.4, and
		// str_contains is PHP 8.0. WordPress does polyfill it from 5.9, but
		// leaning on that makes the minimum WordPress version load bearing for
		// something unrelated to WordPress, and it reads as an 8.0 dependency
		// to every compatibility scanner including Plugin Check.
		$load = false !== strpos( $hook, 'seedcast' )
			|| Registry::instance()->owns_screen( $screen );

		if ( $load ) {
			wp_enqueue_style( self::ADMIN_HANDLE );
			wp_enqueue_script( self::ADMIN_HANDLE );
		}
	}

	/**
	 * Apply the chosen theme site wide, so any suite plugin's front-end output
	 * picks up matching token values regardless of which template renders it.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function theme_body_class( array $classes ): array {
		$theme     = (string) get_option( 'sc_theme', 'light' );
		$classes[] = 'sc-theme-' . sanitize_html_class( $theme );
		return $classes;
	}
}
