<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Frontend
 */

namespace Seedcast\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the church details block as an Elementor widget.
 *
 * Church sites are predominantly Elementor, and a pastor building a contact
 * page in Elementor should not have to know what a shortcode is. The widget is
 * a thin wrapper: it collects the same arguments the shortcode takes and hands
 * them to ChurchDetails::render().
 *
 * Everything here is deferred until Elementor announces itself, so a site
 * without Elementor never loads a line of it.
 */
final class ElementorChurch {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'elementor/widgets/register', array( $this, 'register' ) );
	}

	/**
	 * Register the widget with Elementor.
	 *
	 * @param mixed $widgets_manager Elementor widget manager.
	 * @return void
	 */
	public function register( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $widgets_manager ) ) {
			return;
		}
		if ( ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		require_once __DIR__ . '/class-elementorchurchwidget.php';

		if ( ! class_exists( '\Seedcast\Core\Frontend\ElementorChurchWidget' ) ) {
			return;
		}

		$widgets_manager->register( new ElementorChurchWidget() );
	}
}
