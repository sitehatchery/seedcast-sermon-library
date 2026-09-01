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
 * Generic horizontal slider/carousel shared across Seedcast plugins.
 *
 * Emits the .sc-slider markup (track + prev/next nav) that Seedcast's
 * the shared core script wires up automatically on DOMContentLoaded. Each slide's
 * content is supplied by the caller, either as an array of card arrays
 * (rendered via Grid::card) or as a callback that echoes slide inner HTML.
 */
class Slider {

	/**
	 * Render a slider from an array of card arrays (uses the shared card).
	 *
	 * @param array  $cards Array of card arrays (see Grid docblock).
	 * @param array  $args  { @type string $label ARIA label. @type string $empty Empty text. }
	 */
	public static function render( array $cards, array $args = [] ): void {
		$args = wp_parse_args( $args, [
			'label' => __( 'Carousel', 'seedcast-sermon-library' ),
			'empty' => __( 'Nothing to show yet.', 'seedcast-sermon-library' ),
		] );

		if ( empty( $cards ) ) {
			echo '<div class="sc-empty">' . esc_html( $args['empty'] ) . '</div>';
			return;
		}

		self::open( $args['label'] );
		foreach ( $cards as $card ) {
			echo '<div class="sc-slider__slide">';
			Grid::card( $card );
			echo '</div>';
		}
		self::close();
	}

	/**
	 * Open a slider wrapper + track. Use with close() when you want to echo
	 * custom slide markup between them (wrap each slide in .sc-slider__slide).
	 */
	public static function open( string $label = '' ): void {
		echo '<div class="sc-slider">';
		echo '<button class="sc-slider__nav sc-slider__nav--prev" aria-label="' . esc_attr__( 'Previous', 'seedcast-sermon-library' ) . '">&#8592;</button>';
		echo '<div class="sc-slider__track" role="region" aria-label="' . esc_attr( $label ) . '">';
	}

	public static function close(): void {
		echo '</div>'; // .sc-slider__track
		echo '<button class="sc-slider__nav sc-slider__nav--next" aria-label="' . esc_attr__( 'Next', 'seedcast-sermon-library' ) . '">&#8594;</button>';
		echo '</div>'; // .sc-slider
	}
}
