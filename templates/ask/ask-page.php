<?php
/**
 * Template: the ask page.
 *
 * A conversation with the library. The first question arrives with the page
 * and is answered here on the server, so everything works without JavaScript;
 * assets/js/ask.js then asks the next ones in place. Every turn is printed by
 * Ask\Page::render_turn(), here and through the REST route alike.
 *
 * Nothing on it is kept, and it is noindex. See Ask\Page.
 *
 * A theme can override this with seedcast/ask-page.php.
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SeedcastSermonLibrary\Ask\Page;

$scsl_state  = Page::state();
$scsl_church = class_exists( '\Seedcast\Core\Church' ) ? \Seedcast\Core\Church::name() : get_bloginfo( 'name' );

get_header();
?>

<main id="primary" class="site-main scsl-ask<?php echo '' !== $scsl_state['question'] ? ' scsl-ask--started' : ''; ?>">

	<header class="scsl-ask__intro">
		<span class="scsl-ask__badge" aria-hidden="true"><?php echo Page::mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></span>
		<h1 class="scsl-ask__heading">
			<?php
			/* translators: %s: church name. */
			echo esc_html( sprintf( __( 'Ask %s', 'seedcast-sermon-library' ), $scsl_church ) );
			?>
		</h1>
		<p class="scsl-ask__lede"><?php esc_html_e( 'Ask about anything we have taught, or anything about us, in your own words. Answers come from our own sermons and pages, with links to where they came from.', 'seedcast-sermon-library' ); ?></p>
		<?php echo Page::render_chips( Page::suggestions(), 'scsl-ask__starters' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_chips() escapes everything it prints. ?>
	</header>

	<div class="scsl-ask__thread" aria-live="polite">
		<?php echo Page::render_turn( $scsl_state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_turn() escapes everything it prints. ?>
	</div>

	<form class="scsl-ask__composer" method="get" action="<?php echo esc_url( Page::url() ); ?>">
		<?php if ( '' === (string) get_option( 'permalink_structure' ) ) : ?>
			<input type="hidden" name="<?php echo esc_attr( Page::QUERY_VAR ); ?>" value="1" />
		<?php endif; ?>
		<input type="hidden" name="from" value="<?php echo esc_attr( $scsl_state['from_raw'] ); ?>" />
		<label class="screen-reader-text" for="scsl-ask-input"><?php esc_html_e( 'Your question', 'seedcast-sermon-library' ); ?></label>
		<textarea id="scsl-ask-input" class="scsl-ask__input" name="q" rows="1" maxlength="<?php echo esc_attr( (string) Page::MAX_CHARS ); ?>" placeholder="<?php echo esc_attr( '' !== $scsl_state['question'] ? __( 'Ask something else', 'seedcast-sermon-library' ) : __( 'Ask us a question', 'seedcast-sermon-library' ) ); ?>" required></textarea>
		<button type="submit" class="scsl-ask__send">
			<span class="screen-reader-text"><?php esc_html_e( 'Ask', 'seedcast-sermon-library' ); ?></span>
			<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg>
		</button>
	</form>

	<p class="scsl-ask__fine"><?php esc_html_e( 'Answers are written automatically from our own content and can be wrong. The links are the best place to read more.', 'seedcast-sermon-library' ); ?></p>

</main>

<?php
get_footer();
