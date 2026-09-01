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
 * Generic grid + card renderers shared across Seedcast plugins.
 *
 * These emit the standard .sc-grid / .sc-card markup (styled by Seedcast's
 * shared CSS) so every plugin's card grids look and behave identically.
 * A plugin supplies an array of "card" arrays; nothing here is specific
 * to sermons or testimonies.
 *
 * Card array shape (all keys optional except title + url):
 *   [
 *     'title'       => string,
 *     'url'         => string,
 *     'image'       => string  (HTML <img> or full <img> tag),
 *     'image_url'   => string  (alternative: a src to wrap),
 *     'placeholder' => string  (fallback text, e.g. initials),
 *     'meta'        => string[] (small meta chips),
 *     'excerpt'     => string,
 *     'badge'       => string,
 *   ]
 */
class Grid {

	/**
	 * Render a responsive grid of cards.
	 *
	 * @param array $cards Array of card arrays (see class docblock).
	 * @param array $args  { @type int $columns 2|3|4. @type string $empty Empty-state text. }
	 */
	public static function render( array $cards, array $args = [] ): void {
		$args = wp_parse_args( $args, [
			'columns' => 3,
			'empty'   => __( 'Nothing to show yet.', 'seedcast-sermon-library' ),
		] );

		if ( empty( $cards ) ) {
			echo '<div class="sc-empty">' . esc_html( $args['empty'] ) . '</div>';
			return;
		}

		$cols = in_array( (int) $args['columns'], [ 2, 3, 4 ], true ) ? (int) $args['columns'] : 3;
		echo '<div class="sc-grid sc-grid-cols-' . esc_attr( (string) $cols ) . '">';
		foreach ( $cards as $card ) {
			self::card( $card );
		}
		echo '</div>';
	}

	/**
	 * Render a single card. Public so plugins can use it inside sliders too.
	 */
	public static function card( array $card ): void {
		$title = $card['title'] ?? '';
		$url   = $card['url'] ?? '';
		?>
		<article class="sc-card">
			<?php if ( $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" class="sc-card__stretched-link" tabindex="0"
				   aria-label="<?php echo esc_attr( $title ); ?>">
					<span class="sc-screen-reader-text"><?php echo esc_html( $title ); ?></span>
				</a>
			<?php endif; ?>

			<div class="sc-card__image">
				<?php
				if ( ! empty( $card['image'] ) ) {
					echo wp_kses_post( $card['image'] );
				} elseif ( ! empty( $card['image_url'] ) ) {
					printf( '<img src="%s" alt="%s" loading="lazy" />', esc_url( $card['image_url'] ), esc_attr( $title ) );
				} else {
					$ph = $card['placeholder'] ?? mb_strtoupper( mb_substr( $title, 0, 2 ) );
					echo '<div class="sc-card__placeholder"><span>' . esc_html( $ph ) . '</span></div>';
				}
				?>
			</div>

			<div class="sc-card__body">
				<?php if ( ! empty( $card['badge'] ) ) : ?>
					<span class="sc-badge"><?php echo esc_html( $card['badge'] ); ?></span>
				<?php endif; ?>
				<h3 class="sc-card__title"><?php echo esc_html( $title ); ?></h3>
				<?php if ( ! empty( $card['meta'] ) && is_array( $card['meta'] ) ) : ?>
					<div class="sc-card__meta">
						<?php foreach ( $card['meta'] as $chip ) : ?>
							<span><?php echo esc_html( $chip ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $card['excerpt'] ) ) : ?>
					<p class="sc-card__excerpt"><?php echo esc_html( $card['excerpt'] ); ?></p>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}
}
