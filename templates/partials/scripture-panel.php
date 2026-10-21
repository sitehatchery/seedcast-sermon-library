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

<?php
/*
 * The heading stands above the box rather than inside it, level with Topics
 * and Share. The three are the same kind of thing in the same column, and one
 * of them starting a line lower because its heading is boxed made it look
 * like a different kind of thing.
 */
?>
<aside class="scsl-scripture-block" aria-labelledby="scsl-scripture-label">
	<h2 class="scsl-scripture-panel__title" id="scsl-scripture-label"><?php esc_html_e( 'Scripture', 'seedcast-sermon-library' ); ?></h2>

	<div class="scsl-scripture-panel">

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
		<?php
		/*
		 * "Other" is only other than something. Where the sermon has no focus
		 * passage this is the whole list, so the panel's own heading names it
		 * and a second heading saying "Other Passages" is a distinction the
		 * page is not making.
		 */
		?>
		<?php if ( $scsl_focus ) : ?>
		<p class="scsl-scripture-panel__label" id="scsl-passages-label">
			<?php esc_html_e( 'Other Passages', 'seedcast-sermon-library' ); ?>
		</p>
		<?php endif; ?>

		<ul class="scsl-scripture-panel__list" aria-labelledby="<?php echo $scsl_focus ? 'scsl-passages-label' : 'scsl-scripture-label'; ?>">
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
	</div>
</aside>
