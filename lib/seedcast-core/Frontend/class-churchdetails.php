<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Frontend
 */

namespace Seedcast\Core\Frontend;

use Seedcast\Core\Church;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the church's own details anywhere on the site.
 *
 * Exists so the address in the footer, the service times on the contact page
 * and the directions link in a visitor email all read from one place. A church
 * that moves, or changes its service time, edits one field.
 *
 * Placement is entirely the church's: a shortcode, an Elementor widget, or a
 * direct call from a template. Core never injects this anywhere on its own.
 */
class ChurchDetails {

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE = 'seedcast_church_details';

	/**
	 * Fields shown when the caller does not say otherwise.
	 *
	 * The visitor note is off by default because it is addressed to a
	 * specific audience and would read oddly in a footer, which is where
	 * this block most often ends up first.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_FIELDS = array( 'name', 'address', 'times', 'phone', 'email', 'directions' );

	/**
	 * Every field this block knows how to render.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = array( 'name', 'address', 'times', 'phone', 'email', 'events', 'note', 'directions' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'show'    => implode( ',', self::DEFAULT_FIELDS ),
				'heading' => '',
				'layout'  => 'stacked',
				'class'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return self::render(
			array(
				'fields'  => self::parse_fields( $atts['show'] ),
				'heading' => sanitize_text_field( $atts['heading'] ),
				'layout'  => sanitize_key( $atts['layout'] ),
				'class'   => sanitize_html_class( $atts['class'] ),
			)
		);
	}

	/**
	 * Turn a comma separated show attribute into a validated field list.
	 *
	 * Unknown names are dropped rather than rendered as nothing, and an
	 * attribute that resolves to nothing at all falls back to the defaults
	 * so a typo produces the normal block instead of an empty one.
	 *
	 * @param string $show Comma separated field names.
	 * @return array<int, string>
	 */
	private static function parse_fields( string $show ): array {
		$requested = array_filter( array_map( 'trim', explode( ',', $show ) ), 'strlen' );
		$fields    = array_values( array_intersect( $requested, self::FIELDS ) );

		return empty( $fields ) ? self::DEFAULT_FIELDS : $fields;
	}

	/**
	 * Render the block.
	 *
	 * A theme can take this over completely by providing
	 * seedcast/church-details.php, which receives $args and $church. Short of
	 * that, the seedcast/church/details_html filter gets the final markup.
	 *
	 * @param array $args {
	 *   @type array  $fields  Field names to render, in order.
	 *   @type string $heading Optional heading above the block.
	 *   @type string $layout  'stacked' or 'inline'.
	 *   @type string $class   Extra class on the wrapper.
	 * }
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				'fields'  => self::DEFAULT_FIELDS,
				'heading' => '',
				'layout'  => 'stacked',
				'class'   => '',
			)
		);

		$church = Church::all();

		/*
		 * Nothing configured yet. Render nothing rather than a block of empty
		 * labels, so dropping the shortcode into a footer before filling the
		 * settings in does not visibly break the page.
		 */
		$has_content = false;
		foreach ( $args['fields'] as $field ) {
			if ( self::field_has_value( (string) $field, $church ) ) {
				$has_content = true;
				break;
			}
		}

		if ( ! $has_content ) {
			return '';
		}

		$template = locate_template( array( 'seedcast/church-details.php' ) );
		if ( $template ) {
			ob_start();
			// Theme template. $args and $church are in scope for it.
			include $template;
			return (string) ob_get_clean();
		}

		$layout  = in_array( $args['layout'], array( 'stacked', 'inline' ), true ) ? $args['layout'] : 'stacked';
		$classes = 'sc-church sc-church--' . $layout;
		if ( '' !== $args['class'] ) {
			$classes .= ' ' . $args['class'];
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<?php if ( '' !== $args['heading'] ) : ?>
				<h2 class="sc-church__heading"><?php echo esc_html( $args['heading'] ); ?></h2>
			<?php endif; ?>

			<?php foreach ( $args['fields'] as $field ) : ?>
				<?php
				// Each branch escapes its own output.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::render_field( (string) $field, $church );
				?>
			<?php endforeach; ?>
		</div>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filter the rendered church details markup.
		 *
		 * @param string $html   Rendered markup.
		 * @param array  $args   Render arguments.
		 * @param array  $church Church field values.
		 */
		return (string) apply_filters( 'seedcast/church/details_html', $html, $args, $church );
	}

	/**
	 * Whether a given field has anything to show.
	 *
	 * @param string $field  Field name.
	 * @param array  $church Church values.
	 * @return bool
	 */
	private static function field_has_value( string $field, array $church ): bool {
		/*
		 * Name and email both fall back to site values, so asking the accessor
		 * would always say yes and a block dropped in before the settings are
		 * filled would render a lone site title. Check the stored option for
		 * those two, and the resolved value for everything else.
		 */
		if ( 'name' === $field ) {
			return '' !== trim( (string) get_option( 'seedcast_church_name', '' ) );
		}

		if ( 'email' === $field ) {
			return '' !== trim( (string) get_option( 'seedcast_church_email', '' ) );
		}

		$map = array(
			'address'    => 'address',
			'times'      => 'service_times',
			'phone'      => 'phone',
			'events'     => 'events_url',
			'note'       => 'visitor_note',
			'directions' => 'directions_url',
		);

		if ( ! isset( $map[ $field ] ) ) {
			return false;
		}

		return '' !== (string) $church[ $map[ $field ] ];
	}

	/**
	 * Render one field.
	 *
	 * @param string $field  Field name.
	 * @param array  $church Church values.
	 * @return string Escaped markup, or an empty string when unset.
	 */
	private static function render_field( string $field, array $church ): string {
		switch ( $field ) {

			case 'name':
				if ( '' === $church['name'] ) {
					return '';
				}
				return '<p class="sc-church__name">' . esc_html( $church['name'] ) . '</p>';

			case 'address':
				if ( '' === $church['address'] ) {
					return '';
				}
				return '<p class="sc-church__address">' . nl2br( esc_html( $church['address'] ) ) . '</p>';

			case 'times':
				$lines = Church::service_times_lines();
				if ( empty( $lines ) ) {
					return '';
				}
				$out = '<div class="sc-church__times">';
				foreach ( $lines as $line ) {
					$out .= '<p class="sc-church__time">' . esc_html( $line ) . '</p>';
				}
				return $out . '</div>';

			case 'phone':
				if ( '' === $church['phone'] ) {
					return '';
				}
				$tel = preg_replace( '/[^0-9+]/', '', $church['phone'] );
				return '<p class="sc-church__phone"><a href="tel:' . esc_attr( (string) $tel ) . '">'
					. esc_html( $church['phone'] ) . '</a></p>';

			case 'email':
				if ( '' === trim( (string) get_option( 'seedcast_church_email', '' ) ) ) {
					return '';
				}
				return '<p class="sc-church__email"><a href="' . esc_url( 'mailto:' . $church['email'] ) . '">'
					. esc_html( $church['email'] ) . '</a></p>';

			case 'events':
				if ( '' === $church['events_url'] ) {
					return '';
				}
				return '<p class="sc-church__events"><a href="' . esc_url( $church['events_url'] ) . '">'
					. esc_html__( 'See what is coming up', 'seedcast-sermon-library' ) . '</a></p>';

			case 'note':
				if ( '' === $church['visitor_note'] ) {
					return '';
				}
				return '<p class="sc-church__note">' . nl2br( esc_html( $church['visitor_note'] ) ) . '</p>';

			case 'directions':
				if ( '' === $church['directions_url'] ) {
					return '';
				}
				return '<p class="sc-church__directions"><a class="sc-btn sc-btn--ghost sc-btn--sm" href="'
					. esc_url( $church['directions_url'] ) . '" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Get directions', 'seedcast-sermon-library' ) . '</a></p>';
		}

		return '';
	}
}
