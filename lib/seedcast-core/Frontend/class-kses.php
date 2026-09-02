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
 * The allowlist for HTML that one suite plugin renders into another's page.
 *
 * Service pages assemble themselves from sections contributed over a filter,
 * and a sidebar collected from an action. Bulletin Library prints markup that
 * Visitor Card and Sermon Library produced. That output used to be echoed raw
 * with a phpcs:ignore, on the grounds that it came from our own plugins.
 *
 * The reason it was echoed raw rather than passed through wp_kses_post() is
 * real: wp_kses_post() strips <input>, <form>, <button> and <svg>, which would
 * silently gut the Visitor Card into a heading with no fields, and blank every
 * share icon. Nothing would error; the form would just quietly stop existing.
 *
 * So the answer is not "don't escape", it is "escape against a list that admits
 * the markup these sections legitimately contain". That is what this is: post
 * content, plus the form controls and inline SVG the suite actually emits, and
 * nothing else. Script tags, event handlers and javascript: URLs are still
 * removed, which is the part that matters.
 *
 * Call it like this, at the point of output:
 *
 *     echo wp_kses( $html, Kses::tags() );
 *
 * Not through a helper of your own that wraps both. This class used to offer a
 * section() method that did exactly that, and it had to go: WordPress.org's
 * Plugin Check runs WordPress.Security.EscapeOutput, which recognises escaping
 * by function name against a fixed list. wp_kses() is on that list; anything
 * wrapping it is not, so a wrapper reads as unescaped output and fails the
 * check - four errors in one template, on code that was already safe. Keeping
 * the allowlist shared and the wp_kses() call at the call site satisfies both
 * the sniff and the reason the sniff exists.
 */
final class Kses {

	/**
	 * Cached allowlist. Built once per request.
	 *
	 * @var array|null
	 */
	private static $tags = null;

	/**
	 * Post content, plus form controls and inline SVG.
	 *
	 * @return array
	 */
	public static function tags(): array {
		if ( null !== self::$tags ) {
			return self::$tags;
		}

		/*
		 * Allowed on every element we add below. The wildcards are why this
		 * stays readable: WordPress has supported 'data-*' and 'aria-*' in a
		 * kses allowlist since 5.5, and the library requires 6.2.
		 */
		$common = array(
			'id'     => true,
			'class'  => true,
			'style'  => true,
			'title'  => true,
			'hidden' => true,
			'role'   => true,
			'data-*' => true,
			'aria-*' => true,
		);

		$form = array(
			'form'     => array(
				'action'         => true,
				'method'         => true,
				'enctype'        => true,
				'target'         => true,
				'novalidate'     => true,
				'accept-charset' => true,
				'autocomplete'   => true,
				'name'           => true,
			),
			'input'    => array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'placeholder'  => true,
				'required'     => true,
				'checked'      => true,
				'disabled'     => true,
				'readonly'     => true,
				'min'          => true,
				'max'          => true,
				'step'         => true,
				'minlength'    => true,
				'maxlength'    => true,
				'pattern'      => true,
				'size'         => true,
				'list'         => true,
				'multiple'     => true,
				'accept'       => true,
				'autocomplete' => true,
				'tabindex'     => true,
			),
			'textarea' => array(
				'name'         => true,
				'rows'         => true,
				'cols'         => true,
				'placeholder'  => true,
				'required'     => true,
				'disabled'     => true,
				'readonly'     => true,
				'maxlength'    => true,
				'minlength'    => true,
				'autocomplete' => true,
				'tabindex'     => true,
			),
			'select'   => array(
				'name'         => true,
				'required'     => true,
				'disabled'     => true,
				'multiple'     => true,
				'size'         => true,
				'autocomplete' => true,
				'tabindex'     => true,
			),
			'option'   => array(
				'value'    => true,
				'selected' => true,
				'disabled' => true,
				'label'    => true,
			),
			'optgroup' => array(
				'label'    => true,
				'disabled' => true,
			),
			'button'   => array(
				'type'     => true,
				'name'     => true,
				'value'    => true,
				'disabled' => true,
				'tabindex' => true,
			),
			'label'    => array( 'for' => true ),
			'fieldset' => array( 'disabled' => true ),
			'legend'   => array(),
			'datalist' => array(),
			'output'   => array( 'for' => true ),
			'progress' => array(
				'value' => true,
				'max'   => true,
			),
		);

		/*
		 * Inline SVG, for the share and card icons. wp_kses lowercases
		 * attribute names, so viewBox arrives as viewbox - which is correct
		 * anyway, because an HTML parser case-corrects SVG attributes. Writing
		 * it lowercase here is the honest spelling of what kses will accept.
		 */
		$svg_shape = array(
			'fill'             => true,
			'fill-rule'        => true,
			'fill-opacity'     => true,
			'stroke'           => true,
			'stroke-width'     => true,
			'stroke-linecap'   => true,
			'stroke-linejoin'  => true,
			'stroke-dasharray' => true,
			'opacity'          => true,
			'transform'        => true,
		);

		$svg = array(
			'svg'      => array_merge(
				$svg_shape,
				array(
					'xmlns'       => true,
					'viewbox'     => true,
					'width'       => true,
					'height'      => true,
					'focusable'   => true,
					'preserveaspectratio' => true,
				)
			),
			'g'        => $svg_shape,
			'path'     => array_merge( $svg_shape, array( 'd' => true ) ),
			'circle'   => array_merge(
				$svg_shape,
				array(
					'cx' => true,
					'cy' => true,
					'r'  => true,
				)
			),
			'ellipse'  => array_merge(
				$svg_shape,
				array(
					'cx' => true,
					'cy' => true,
					'rx' => true,
					'ry' => true,
				)
			),
			'rect'     => array_merge(
				$svg_shape,
				array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
					'rx'     => true,
					'ry'     => true,
				)
			),
			'line'     => array_merge(
				$svg_shape,
				array(
					'x1' => true,
					'y1' => true,
					'x2' => true,
					'y2' => true,
				)
			),
			'polygon'  => array_merge( $svg_shape, array( 'points' => true ) ),
			'polyline' => array_merge( $svg_shape, array( 'points' => true ) ),
			'title'    => array(),
			'desc'     => array(),
		);

		$tags = array_merge( wp_kses_allowed_html( 'post' ), $form, $svg );

		foreach ( $tags as $tag => $attrs ) {
			$tags[ $tag ] = array_merge( is_array( $attrs ) ? $attrs : array(), $common );
		}

		/**
		 * The allowlist used for cross-plugin section and sidebar HTML.
		 *
		 * A plugin rendering a section that needs an element this does not
		 * cover adds it here rather than echoing unescaped.
		 *
		 * @param array $tags Allowed tags and attributes, in wp_kses form.
		 */
		self::$tags = apply_filters( 'seedcast/kses/section_tags', $tags );

		return self::$tags;
	}
}
