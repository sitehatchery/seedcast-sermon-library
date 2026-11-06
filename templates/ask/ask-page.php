<?php
/**
 * Template: the ask page.
 *
 * One address for every question, like a search results page. Shows the
 * answer, what it drew on, and the site's ordinary search for the same words.
 * Nothing on it is kept, and it is noindex. See Ask\Page.
 *
 * The citations are the point of the page. They are how a visitor gets from a
 * question into the library, and they make an answer checkable rather than
 * authoritative, so they are shown whether the answer found something or not.
 *
 * A theme can override this with seedcast/ask-page.php.
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SeedcastSermonLibrary\Ask\Box;
use SeedcastSermonLibrary\Ask\Page;

$scsl_state    = Page::state();
$scsl_question = $scsl_state['question'];
$scsl_result   = $scsl_state['result'];
$scsl_status   = is_array( $scsl_result ) ? (string) ( $scsl_result['status'] ?? '' ) : '';
$scsl_cited    = is_array( $scsl_result ) ? (array) ( $scsl_result['cited'] ?? [] ) : [];

get_header();
?>

<main id="primary" class="site-main scsl-ask">

	<h1 class="scsl-ask__title"><?php esc_html_e( 'Ask a question', 'seedcast-sermon-library' ); ?></h1>

	<?php echo Box::form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Box::form() escapes everything it prints. ?>

	<?php if ( '' === $scsl_question ) : ?>

		<p class="scsl-ask__note"><?php esc_html_e( 'Ask about anything we have taught or anything about us, in your own words. Answers come from our own sermons and pages, with links to where they came from.', 'seedcast-sermon-library' ); ?></p>

	<?php elseif ( '' !== $scsl_state['message'] ) : ?>

		<p class="scsl-ask__note"><?php echo esc_html( $scsl_state['message'] ); ?></p>

	<?php elseif ( 'capped' === $scsl_status || 'unavailable' === $scsl_status ) : ?>

		<p class="scsl-ask__note"><?php esc_html_e( 'We can\'t answer questions right now. The search results below may still help.', 'seedcast-sermon-library' ); ?></p>

	<?php else : ?>

		<div class="scsl-ask__answer">
			<?php echo wp_kses_post( wpautop( esc_html( (string) ( $scsl_result['answer'] ?? '' ) ) ) ); ?>
		</div>

		<?php if ( $scsl_cited ) : ?>
			<h2 class="scsl-ask__heading">
				<?php
				if ( 'answered' === $scsl_status ) {
					esc_html_e( 'Where this came from', 'seedcast-sermon-library' );
				} else {
					esc_html_e( 'The nearest we have', 'seedcast-sermon-library' );
				}
				?>
			</h2>
			<ul class="scsl-ask__sources">
				<?php foreach ( $scsl_cited as $scsl_source ) : ?>
					<li>
						<?php // nofollow: an answer page links into the library, and nothing should treat these links as votes. ?>
						<a href="<?php echo esc_url( (string) ( $scsl_source['url'] ?? '' ) ); ?>" rel="nofollow"><?php echo esc_html( (string) ( $scsl_source['title'] ?? '' ) ); ?></a>
						<?php if ( ! empty( $scsl_source['reason'] ) ) : ?>
							<span class="scsl-ask__reason"><?php echo esc_html( ucfirst( (string) $scsl_source['reason'] ) ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p class="scsl-ask__note"><small><?php esc_html_e( 'This answer was written automatically from our own content. The links above are the best place to read more.', 'seedcast-sermon-library' ); ?></small></p>

	<?php endif; ?>

	<?php
	/*
	 * The site's ordinary search for the same words. This is what lets the
	 * ask box stand where the theme's search did without anybody losing it.
	 */
	if ( '' !== $scsl_question ) :
		$scsl_search = new WP_Query( [
			's'              => $scsl_question,
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => 6,
			'no_found_rows'  => true,
			'has_password'   => false,
		] );

		// A long question rarely matches every word; its longest words might.
		if ( ! $scsl_search->have_posts() ) {
			$scsl_words = \SeedcastSermonLibrary\Ask\Questions::words( $scsl_question );
			usort(
				$scsl_words,
				static function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);

			if ( $scsl_words ) {
				$scsl_search = new WP_Query( [
					's'              => (string) $scsl_words[0],
					'post_type'      => 'any',
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'no_found_rows'  => true,
					'has_password'   => false,
				] );
			}
		}

		if ( $scsl_search->have_posts() ) :
			?>
			<section class="scsl-ask__results">
				<h2 class="scsl-ask__heading"><?php esc_html_e( 'Pages on this site', 'seedcast-sermon-library' ); ?></h2>
				<ul class="scsl-ask__sources">
					<?php
					while ( $scsl_search->have_posts() ) :
						$scsl_search->the_post();
						?>
						<li>
							<a href="<?php the_permalink(); ?>" rel="nofollow"><?php the_title(); ?></a>
							<span class="scsl-ask__reason"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 24 ) ); ?></span>
						</li>
					<?php endwhile; ?>
				</ul>
			</section>
			<?php
			wp_reset_postdata();
		endif;
	endif;
	?>

</main>

<?php
get_footer();
