<?php
namespace SeedcastSermonLibrary\Ask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ask box: a single input, in the place a visitor already looks for
 * search.
 *
 * Not a floating bubble. A bubble reads as live chat, so visitors wait for a
 * person, and it covers content on a phone. A box where search lives is
 * understood at once and sets the right expectation: you ask, you get a page.
 *
 * REPLACING THE THEME'S SEARCH, BY DEFAULT. The open question was whether
 * replacing it would cost a church ordinary post search. It does not, because
 * the ask page runs that search for the same words underneath the answer, so
 * the box gives everything search gave and an answer on top. A church that
 * wants search left exactly as it was can switch the takeover off and place
 * the box with [seedcast_ask] instead.
 *
 * Not entitled, it never takes anything over, and the shortcode prints the
 * theme's ordinary search form. A site without the assistant notices nothing.
 */
class Box {

	public const MODE_OPTION = 'scsl_ask_box';
	public const MODE_SEARCH = 'replace';
	public const MODE_MANUAL = 'shortcode';

	public static function init(): void {
		add_filter( 'get_search_form', [ __CLASS__, 'search_form' ], 20 );
		add_filter( 'render_block_core/search', [ __CLASS__, 'search_block' ], 20, 2 );
		add_shortcode( 'seedcast_ask', [ __CLASS__, 'shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ], 20 );
	}

	public static function mode(): string {
		return self::MODE_MANUAL === get_option( self::MODE_OPTION, self::MODE_SEARCH ) ? self::MODE_MANUAL : self::MODE_SEARCH;
	}

	private static function takes_over(): bool {
		return self::MODE_SEARCH === self::mode() && Page::available();
	}

	public static function search_form( $form ) {
		return self::takes_over() ? self::form() : $form;
	}

	public static function search_block( $content, $block ) {
		return self::takes_over() ? self::form( [ 'class' => 'wp-block-search' ] ) : $content;
	}

	public static function shortcode( $atts = [] ): string {
		$atts = shortcode_atts(
			[ 'placeholder' => '' ],
			is_array( $atts ) ? $atts : [],
			'seedcast_ask'
		);

		if ( ! Page::available() ) {
			return get_search_form( [ 'echo' => false ] );
		}

		return self::form( [ 'placeholder' => (string) $atts['placeholder'] ] );
	}

	/**
	 * The form itself.
	 *
	 * Carries the page it is on, as a post or term id, so the question can be
	 * answered in the light of where it was asked and promoted back onto that
	 * page later.
	 */
	public static function form( array $args = [] ): string {
		static $n = 0;
		$n++;

		$state       = Page::state();
		$value       = Page::is_ask() ? $state['question'] : '';
		$placeholder = '' !== ( $args['placeholder'] ?? '' ) ? (string) $args['placeholder'] : __( 'Ask us a question', 'seedcast-sermon-library' );
		$id          = 'scsl-ask-q-' . $n;

		$out  = '<form role="search" method="get" class="search-form scsl-ask-form ' . esc_attr( (string) ( $args['class'] ?? '' ) ) . '" action="' . esc_url( Page::url() ) . '">';

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$out .= '<input type="hidden" name="' . esc_attr( Page::QUERY_VAR ) . '" value="1" />';
		}

		$out .= '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Ask a question', 'seedcast-sermon-library' ) . '</label>';
		$out .= '<input type="search" id="' . esc_attr( $id ) . '" class="search-field scsl-ask-form__input" name="q" maxlength="' . esc_attr( (string) Page::MAX_CHARS ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
		$out .= '<input type="hidden" name="from" value="' . esc_attr( self::from() ) . '" />';
		$out .= '<button type="submit" class="search-submit scsl-ask-form__submit">' . esc_html__( 'Ask', 'seedcast-sermon-library' ) . '</button>';

		return $out . '</form>';
	}

	/**
	 * Where the visitor is standing, as the box knows it.
	 */
	private static function from(): string {
		if ( Page::is_ask() ) {
			return Page::state()['from_raw'];
		}

		if ( is_singular() ) {
			return 'post:' . (int) get_queried_object_id();
		}

		if ( is_tax() || is_category() || is_tag() ) {
			return 'term:' . (int) get_queried_object_id();
		}

		return '';
	}

	/**
	 * A few lines so the box sits in a header like a search field does, and
	 * the ask page reads cleanly. Only when the box can appear.
	 */
	public static function styles(): void {
		if ( ! Page::available() || ! wp_style_is( 'scsl-frontend', 'enqueued' ) ) {
			return;
		}

		wp_add_inline_style(
			'scsl-frontend',
			'.scsl-ask-form{display:flex;gap:.4em;align-items:stretch;max-width:100%}'
			. '.scsl-ask-form__input{flex:1 1 auto;min-width:0}'
			. '.scsl-ask-form__submit{flex:0 0 auto;cursor:pointer}'
			. '.scsl-ask{max-width:46rem;margin:2.5rem auto;padding:0 16px}'
			. '.scsl-ask__answer{font-size:1.1em;line-height:1.6;margin:1.5rem 0}'
			. '.scsl-ask__sources{list-style:none;padding:0;margin:0 0 2rem}'
			. '.scsl-ask__sources li{padding:.6em 0;border-top:1px solid rgba(0,0,0,.1)}'
			. '.scsl-ask__reason{display:block;font-size:.875em;opacity:.75}'
			. '.scsl-ask__note{opacity:.8}'
			. '.scsl-ask__results{margin-top:2.5rem}'
		);
	}
}
