<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Frontend
 */
namespace Seedcast\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generic filter/search bar shared across Seedcast plugins.
 *
 * Two pieces, usable together or apart:
 *   - pills(): a row of filter links (e.g. taxonomy terms) with an active state,
 *     generalising Sermon Library's topic filter nav.
 *   - search(): a simple GET search input that plugins point at their archive.
 *
 * Both emit the shared .sc-filter-bar / .sc-form styling.
 */
class FilterBar {

	/**
	 * Render a row of filter pills.
	 *
	 * @param array $items Each: [ 'label' => string, 'url' => string, 'active' => bool ].
	 * @param array $args  { @type string $all_label. @type string $all_url. @type bool $all_active. @type string $aria. }
	 */
	public static function pills( array $items, array $args = [] ): void {
		$args = wp_parse_args( $args, [
			'all_label'  => __( 'All', 'seedcast-sermon-library' ),
			'all_url'    => '',
			'all_active' => false,
			'aria'       => __( 'Filter', 'seedcast-sermon-library' ),
		] );

		echo '<nav class="sc-filter-bar" aria-label="' . esc_attr( $args['aria'] ) . '">';

		if ( $args['all_url'] !== '' ) {
			printf(
				'<a href="%s" class="sc-filter-btn %s">%s</a>',
				esc_url( $args['all_url'] ),
				$args['all_active'] ? 'is-active' : '',
				esc_html( $args['all_label'] )
			);
		}

		foreach ( $items as $item ) {
			printf(
				'<a href="%s" class="sc-filter-btn %s">%s</a>',
				esc_url( $item['url'] ?? '' ),
				! empty( $item['active'] ) ? 'is-active' : '',
				esc_html( $item['label'] ?? '' )
			);
		}

		echo '</nav>';
	}

	/**
	 * Render a GET search input.
	 *
	 * @param array $args { @type string $action Form target URL. @type string $param Query var.
	 *                      @type string $value Current value. @type string $placeholder.
	 *                      @type array $hidden Extra hidden fields [name => value]. }
	 */
	public static function search( array $args = [] ): void {
		$args = wp_parse_args( $args, [
			'action'      => '',
			'param'       => 's',
			'value'       => '',
			'placeholder' => __( 'Search…', 'seedcast-sermon-library' ),
			'hidden'      => [],
		] );

		echo '<form class="sc-form sc-search" method="get" action="' . esc_url( $args['action'] ) . '" role="search">';
		foreach ( (array) $args['hidden'] as $name => $val ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( $val ) );
		}
		printf(
			'<input type="text" name="%s" value="%s" placeholder="%s" aria-label="%s" />',
			esc_attr( $args['param'] ),
			esc_attr( $args['value'] ),
			esc_attr( $args['placeholder'] ),
			esc_attr( $args['placeholder'] )
		);
		echo '<button type="submit" class="sc-btn">' . esc_html__( 'Search', 'seedcast-sermon-library' ) . '</button>';
		echo '</form>';
	}
}
