<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Frontend
 */
namespace Seedcast\Core\Frontend;

use Seedcast\Core\Submissions\Captcha;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the shared submission form chrome: the <form> wrapper, security
 * (nonce + honeypot + captcha), the standard author fields, success/error
 * notices, and the submit button. Child plugins pass in their own field
 * markup for the middle of the form via a callback or HTML string.
 *
 * Usage from a child plugin:
 *
 *   Seedcast\Core\Frontend\FormRenderer::open( 'praise-report', 'testimony' );
 *   // ...child-specific fields...
 *   Seedcast\Core\Frontend\FormRenderer::author_fields();
 *   Seedcast\Core\Frontend\FormRenderer::close( __( 'Share testimony', 'praise-report' ) );
 */
class FormRenderer {

	/**
	 * The current front-end page URL, used to record where a form was
	 * submitted from (a form can be placed on more than one page).
	 */
	private static function current_url(): string {
		global $wp;
		if ( isset( $wp ) && is_object( $wp ) ) {
			return home_url( $wp->request ? '/' . $wp->request . '/' : '/' );
		}
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}

	public function init(): void {
		// Reserved for future shortcode/asset needs; kept for parity with SL init pattern.
	}

	/**
	 * Renders the success/error notice from a prior submission redirect.
	 */
	/**
	 * Renders the success/error notice from a prior submission redirect -
	 * the no-JS fallback path. Scoped to the specific source+type that was
	 * submitted, so with two forms on one page only the one that was actually
	 * submitted shows a message.
	 */
	public static function notice( string $source, string $type ): void {
		// These GET values are only read to display a post-redirect success/error
		// notice (the no-JS fallback). No data is written or acted on here; the
		// submission itself was already nonce-verified in SubmissionEngine before
		// this redirect happened. All values are unslashed and sanitized below.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['sc_submitted'] ) ) return;
		$req_source = isset( $_GET['sc_src'] )   ? sanitize_key( wp_unslash( $_GET['sc_src'] ) )   : '';
		$req_type   = isset( $_GET['sc_stype'] ) ? sanitize_key( wp_unslash( $_GET['sc_stype'] ) ) : '';
		if ( $req_source !== $source || $req_type !== $type ) return;

		$ok  = sanitize_key( wp_unslash( $_GET['sc_submitted'] ) ) === '1';
		$msg = isset( $_GET['sc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sc_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $ok && ! $msg ) {
			$msg = __( 'Thank you - your submission has been received and will be reviewed.', 'seedcast-sermon-library' );
		}
		printf(
			'<div class="sc-notice sc-notice--%s sc-notice--scroll-target">%s</div>',
			$ok ? 'success' : 'error',
			esc_html( $msg )
		);
	}

	public static function open( string $source, string $type, array $atts = [] ): void {
		echo '<form class="sc-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" data-sc-ajax="1">';
		// No-JS fallback: only renders if THIS form's source+type was submitted.
		self::notice( $source, $type );
		// Per-form inline notice target (AJAX writes here; only this form reacts).
		echo '<div class="sc-form__notice" role="status" aria-live="polite"></div>';
		echo '<input type="hidden" name="action" value="sc_submit" />';
		echo '<input type="hidden" name="sc_source" value="' . esc_attr( $source ) . '" />';
		echo '<input type="hidden" name="sc_type" value="' . esc_attr( $type ) . '" />';
		if ( ! empty( $atts['service_id'] ) ) {
			echo '<input type="hidden" name="sc_service_id" value="' . absint( $atts['service_id'] ) . '" />';
		}
		echo '<input type="hidden" name="sc_source_url" value="' . esc_url( self::current_url() ) . '" />';

		/*
		 * The post the form is sitting on. Sent alongside the URL rather than
		 * instead of it: the URL is what a person reads, the id is what
		 * survives a permalink change and can be counted on.
		 */
		$linked_post = ! empty( $atts['linked_post'] ) ? absint( $atts['linked_post'] ) : 0;
		if ( ! $linked_post && is_singular() ) {
			$linked_post = (int) get_queried_object_id();
		}
		if ( $linked_post > 0 ) {
			echo '<input type="hidden" name="sc_linked_post" value="' . absint( $linked_post ) . '" />';
		}
		wp_nonce_field( "sc_submit_{$source}_{$type}", 'sc_nonce' );

		// Honeypot - placed early, visually hidden via .sc-form__hp. The field
		// name deliberately avoids "website"/"url"/"email"/"phone" - browser
		// autofill (Chrome in particular) targets those names heuristically
		// and WILL fill them even with autocomplete="off", silently tripping
		// the honeypot on a completely legitimate submission. "sc_hp_note" is
		// unrecognizable to autofill heuristics.
		if ( get_option( 'sc_honeypot_enabled', '1' ) === '1' ) {
			echo '<div class="sc-form__hp" aria-hidden="true">';
			echo '<label>' . esc_html__( 'Leave this field empty', 'seedcast-sermon-library' ) . '</label>';
			echo '<input type="text" name="sc_hp_note" tabindex="-1" autocomplete="off" />';
			echo '</div>';
		}
	}

	/**
	 * Standard optional name/email fields shared by all submission forms.
	 */
	public static function author_fields( bool $require_name = false ): void {
		?>
		<div class="sc-form__row">
			<label class="sc-form__label" for="sc_author_name">
				<?php esc_html_e( 'Your name', 'seedcast-sermon-library' ); ?>
				<?php if ( $require_name ) : ?>
					<span class="sc-form__req" aria-hidden="true">*</span>
					<span class="sc-screen-reader-text"><?php esc_html_e( '(required)', 'seedcast-sermon-library' ); ?></span>
				<?php endif; ?>
			</label>
			<input type="text" id="sc_author_name" name="sc_author_name" <?php echo $require_name ? 'required' : ''; ?> />
			<p class="sc-form__help"><?php esc_html_e( 'How you would like to be credited. You can choose to stay anonymous below.', 'seedcast-sermon-library' ); ?></p>
		</div>
		<div class="sc-form__row">
			<label class="sc-form__label" for="sc_author_email"><?php esc_html_e( 'Email (optional)', 'seedcast-sermon-library' ); ?></label>
			<input type="email" id="sc_author_email" name="sc_author_email" />
			<p class="sc-form__help"><?php esc_html_e( 'Kept private. Only used if we need to follow up with you.', 'seedcast-sermon-library' ); ?></p>
		</div>
		<?php
	}

	public static function close( string $submit_label ): void {
		echo '<div class="sc-form__row">';
		Captcha::render_widget();
		echo '</div>';
		echo '<button type="submit" class="sc-btn">' . esc_html( $submit_label ) . '</button>';
		echo '</form>';
	}
}
