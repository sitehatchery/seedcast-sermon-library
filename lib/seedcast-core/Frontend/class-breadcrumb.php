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
 * The visible breadcrumb trail. Pair it with Schema::breadcrumbs(), which takes
 * the same array.
 */
final class Breadcrumb {

	/**
	 * Print the trail.
	 *
	 * @param array $crumbs Each: [ 'label' => string, 'url' => string ]. The
	 *                      last crumb is the current page and should omit 'url'.
	 * @param array $args   { @type string $label ARIA label. @type string $sep Separator. }
	 * @return void
	 */
	public static function render( array $crumbs, array $args = array() ): void {
		$crumbs = array_values(
			array_filter(
				array_map(
					static function ( $crumb ) {
						$crumb['label'] = (string) ( $crumb['label'] ?? '' );
						return $crumb;
					},
					$crumbs
				),
				static fn( $c ) => '' !== $c['label']
			)
		);
		if ( count( $crumbs ) < 2 ) {
			return;
		}

		$args = wp_parse_args(
			$args,
			array(
				'label' => __( 'Breadcrumb', 'seedcast-sermon-library' ),
				'sep'   => '/',
			)
		);

		$last = count( $crumbs ) - 1;
		?>
		<nav class="sc-breadcrumb" aria-label="<?php echo esc_attr( $args['label'] ); ?>">
			<ol class="sc-breadcrumb__list">
				<?php foreach ( $crumbs as $index => $crumb ) : ?>
					<li class="sc-breadcrumb__item">
						<?php if ( ! empty( $crumb['url'] ) && $index !== $last ) : ?>
							<a href="<?php echo esc_url( $crumb['url'] ); ?>"><?php echo esc_html( $crumb['label'] ); ?></a>
						<?php else : ?>
							<span aria-current="page"><?php echo esc_html( $crumb['label'] ); ?></span>
						<?php endif; ?>
					</li>
					<?php if ( $index !== $last ) : ?>
						<li aria-hidden="true" class="sc-breadcrumb__sep"><?php echo esc_html( $args['sep'] ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ol>
		</nav>
		<?php
	}
}
