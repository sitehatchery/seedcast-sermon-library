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
 * Social share block: Facebook, X, LinkedIn, Email, Copy Link. Rendered
 * as a small stack of accessible buttons that any suite plugin can drop
 * into a single-view template.
 *
 * Sunday uses it on the single service page. Other suite plugins can
 * call `Seedcast\Core\Frontend\ShareButtons::render( $url, $title )` from
 * their own templates for a consistent share UI.
 */
class ShareButtons {

	/**
	 * Print the share block. Safe to call from a template.
	 *
	 * @param string $url   The URL to share.
	 * @param string $title The page title, used for the tweet and email subject.
	 * @param array  $args  Optional overrides. Keys:
	 *                      - heading: label above the buttons (default "Share").
	 *                      - class:   extra CSS class on the wrapper.
	 */
	public static function render( string $url, string $title, array $args = [] ): void {
		$heading = (string) ( $args['heading'] ?? __( 'Share', 'seedcast-sermon-library' ) );
		$extra   = (string) ( $args['class']   ?? '' );

		$enc_url    = rawurlencode( $url );
		$enc_title  = rawurlencode( $title );
		$mailto_sub = rawurlencode( $title );
		$mailto_bod = rawurlencode( $title . "\n\n" . $url );

		$fb  = 'https://www.facebook.com/sharer/sharer.php?u=' . $enc_url;
		$x   = 'https://twitter.com/intent/tweet?url=' . $enc_url . '&text=' . $enc_title;
		$li  = 'https://www.linkedin.com/sharing/share-offsite/?url=' . $enc_url;
		$em  = 'mailto:?subject=' . $mailto_sub . '&body=' . $mailto_bod;

		$class = trim( 'sc-share ' . $extra );
		?>
		<aside class="<?php echo esc_attr( $class ); ?>" aria-labelledby="sc-share-heading">
			<h2 id="sc-share-heading" class="sc-share__heading"><?php echo esc_html( $heading ); ?></h2>
			<ul class="sc-share__list">
				<li>
					<a class="sc-share__btn sc-share__btn--facebook"
					   href="<?php echo esc_url( $fb ); ?>"
					   target="_blank" rel="noopener noreferrer"
					   aria-label="<?php esc_attr_e( 'Share on Facebook. Opens in a new tab.', 'seedcast-sermon-library' ); ?>">
						<span class="sc-share__icon" aria-hidden="true"><?php self::icon_facebook(); ?></span>
						<span class="sc-share__label">Facebook</span>
					</a>
				</li>
				<li>
					<a class="sc-share__btn sc-share__btn--x"
					   href="<?php echo esc_url( $x ); ?>"
					   target="_blank" rel="noopener noreferrer"
					   aria-label="<?php esc_attr_e( 'Share on X. Opens in a new tab.', 'seedcast-sermon-library' ); ?>">
						<span class="sc-share__icon" aria-hidden="true"><?php self::icon_x(); ?></span>
						<span class="sc-share__label">X</span>
					</a>
				</li>
				<li>
					<a class="sc-share__btn sc-share__btn--linkedin"
					   href="<?php echo esc_url( $li ); ?>"
					   target="_blank" rel="noopener noreferrer"
					   aria-label="<?php esc_attr_e( 'Share on LinkedIn. Opens in a new tab.', 'seedcast-sermon-library' ); ?>">
						<span class="sc-share__icon" aria-hidden="true"><?php self::icon_linkedin(); ?></span>
						<span class="sc-share__label">LinkedIn</span>
					</a>
				</li>
				<li>
					<a class="sc-share__btn sc-share__btn--email"
					   href="<?php echo esc_url( $em ); ?>"
					   aria-label="<?php esc_attr_e( 'Share by email', 'seedcast-sermon-library' ); ?>">
						<span class="sc-share__icon" aria-hidden="true"><?php self::icon_email(); ?></span>
						<span class="sc-share__label"><?php esc_html_e( 'Email', 'seedcast-sermon-library' ); ?></span>
					</a>
				</li>
				<li>
					<button type="button"
					        class="sc-share__btn sc-share__btn--copy"
					        data-sc-copy-url="<?php echo esc_attr( $url ); ?>"
					        aria-label="<?php esc_attr_e( 'Copy link', 'seedcast-sermon-library' ); ?>">
						<span class="sc-share__icon" aria-hidden="true"><?php self::icon_link(); ?></span>
						<span class="sc-share__label sc-share__label--copy"><?php esc_html_e( 'Copy Link', 'seedcast-sermon-library' ); ?></span>
						<span class="sc-share__label sc-share__label--copied" hidden><?php esc_html_e( 'Copied', 'seedcast-sermon-library' ); ?></span>
					</button>
					<span class="sc-screen-reader-text" role="status" aria-live="polite" data-sc-copy-status></span>
				</li>
			</ul>
		</aside>
		<?php
	}

	private static function icon_facebook(): void {
		echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function icon_x(): void {
		echo '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function icon_linkedin(): void {
		echo '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function icon_email(): void {
		echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function icon_link(): void {
		echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
