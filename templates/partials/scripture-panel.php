<?php
/**
 * Partial: Scripture Panel
 * Variables: $scsl_post_id (int)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Scripture\ScriptureParser;


if ( get_option( 'scsl_show_scripture_panel', '1' ) !== '1' ) return;

$scsl_focus    = get_post_meta( $scsl_post_id, '_scsl_focus_passage',  true );
$scsl_passages = get_post_meta( $scsl_post_id, '_scsl_other_passages', true );
if ( ! is_array( $scsl_passages ) ) $scsl_passages = [];
$scsl_passages = array_filter( $scsl_passages );

// A church that does not use the reference list still has a focus passage,
// which is the sermon's own subject rather than part of that list.
if ( ! \SeedcastSermonLibrary\Import\FieldMap::uses( '_scsl_other_passages' ) ) {
	$scsl_passages = [];
}

if ( ! $scsl_focus && ! $scsl_passages ) return;

/*
 * In the order a Bible is in, with runs joined.
 *
 * A sermon that walks through a paragraph lists every verse separately, in
 * whatever order they were entered, and eight rows saying almost the same
 * thing is not a reference list anybody reads.
 *
 * The joined label may name a span that is not itself a term, so it may not
 * link. That is deliberate rather than a shortcoming: a page for a passage
 * inside the span already lists the sermons covering it, because the archive
 * matches overlapping passages rather than identical wording, so nothing is
 * unreachable either way.
 */
$scsl_passages = ScriptureParser::tidy( $scsl_passages );
?>

<aside class="scsl-scripture-panel" aria-label="<?php esc_attr_e( 'Scripture References', 'seedcast-sermon-library' ); ?>">
	<h2 class="scsl-scripture-panel__title"><?php esc_html_e( 'Scripture', 'seedcast-sermon-library' ); ?></h2>

	<?php if ( $scsl_focus ) : ?>
	<div class="scsl-scripture-panel__section">
		<p class="scsl-scripture-panel__label" id="scsl-focus-label">
			<?php esc_html_e( 'Focus Passage', 'seedcast-sermon-library' ); ?>
		</p>
		<?php $scsl_focus_term = ScriptureParser::term_url( $scsl_focus ); ?>

		<?php if ( $scsl_focus_term ) : ?>
			<a href="<?php echo esc_url( $scsl_focus_term ); ?>"
			   class="scsl-scripture-ref scsl-scripture-ref--focus"
			   aria-describedby="scsl-focus-label">
				<?php echo esc_html( $scsl_focus ); ?>
			</a>
		<?php else : ?>
			<span class="scsl-scripture-ref scsl-scripture-ref--focus"><?php echo esc_html( $scsl_focus ); ?></span>
		<?php endif; ?>

		<a href="<?php echo esc_url( ScriptureParser::build_bible_url( $scsl_focus ) ); ?>"
		   class="scsl-scripture-ref__read"
		   target="_blank"
		   rel="noopener noreferrer"
		   aria-describedby="scsl-external-notice">
			<?php esc_html_e( 'Read it', 'seedcast-sermon-library' ); ?>
			<span class="scsl-scripture-ref__icon" aria-hidden="true">↗</span>
		</a>
	</div>
	<?php endif; ?>

	<?php if ( $scsl_passages ) : ?>
	<div class="scsl-scripture-panel__section">
		<p class="scsl-scripture-panel__label" id="scsl-passages-label">
			<?php esc_html_e( 'Other Passages', 'seedcast-sermon-library' ); ?>
		</p>
		<ul class="scsl-scripture-panel__list" aria-labelledby="scsl-passages-label">
			<?php foreach ( $scsl_passages as $scsl_passage ) : ?>
			<li>
				<?php $scsl_passage_term = ScriptureParser::term_url( $scsl_passage ); ?>

				<?php if ( $scsl_passage_term ) : ?>
					<a href="<?php echo esc_url( $scsl_passage_term ); ?>" class="scsl-scripture-ref">
						<?php echo esc_html( $scsl_passage ); ?>
					</a>
				<?php else : ?>
					<span class="scsl-scripture-ref"><?php echo esc_html( $scsl_passage ); ?></span>
				<?php endif; ?>

				<a href="<?php echo esc_url( ScriptureParser::build_bible_url( $scsl_passage ) ); ?>"
				   class="scsl-scripture-ref__read"
				   target="_blank"
				   rel="noopener noreferrer"
				   aria-describedby="scsl-external-notice">
					<span class="scsl-scripture-ref__icon" aria-hidden="true">↗</span>
					<span class="scsl-sr-only"><?php esc_html_e( 'Read this passage', 'seedcast-sermon-library' ); ?></span>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<!-- Screen reader notice for external links -->
	<p id="scsl-external-notice" class="scsl-sr-only">
		<?php esc_html_e( 'Opens in a new tab', 'seedcast-sermon-library' ); ?>
	</p>
</aside>
