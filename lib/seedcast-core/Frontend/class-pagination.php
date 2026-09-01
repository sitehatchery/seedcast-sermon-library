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
 * Shared pagination renderer emitting .sc-pagination markup. Thin wrapper
 * over core paginate_links() with the suite's classes and sensible defaults.
 */
class Pagination {

	/**
	 * @param array $args {
	 *   @type int    $total   Total pages (defaults to global $wp_query max).
	 *   @type int    $current Current page (defaults to current query page).
	 *   @type string $base    Base URL pattern for paginate_links.
	 * }
	 */
	public static function render( array $args = [] ): void {
		global $wp_query;

		$total   = $args['total']   ?? ( $wp_query->max_num_pages ?? 1 );
		$current = $args['current'] ?? max( 1, (int) get_query_var( 'paged' ) );

		if ( $total <= 1 ) return;

		$links = paginate_links( wp_parse_args( $args, [
			'total'     => $total,
			'current'   => $current,
			'type'      => 'array',
			'prev_text' => '&larr;',
			'next_text' => '&rarr;',
			'mid_size'  => 1,
		] ) );

		if ( empty( $links ) ) return;

		echo '<nav class="sc-pagination" aria-label="' . esc_attr__( 'Pagination', 'seedcast-sermon-library' ) . '">';
		foreach ( $links as $link ) {
			// paginate_links returns pre-escaped anchor/span markup.
			echo wp_kses_post( $link );
		}
		echo '</nav>';
	}
}
