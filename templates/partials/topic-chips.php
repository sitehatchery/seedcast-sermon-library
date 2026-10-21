<?php
/**
 * Partial: a labelled row of topic chips.
 *
 * Used beside a heading to say what a set of sermons is about: the passage at
 * the top of a scripture page, and the wider book below it. One partial
 * because the two say the same kind of thing about different sets, and two
 * copies of it would have drifted the first time one was adjusted.
 *
 * The label tells the truth about what is shown. Where the set has more
 * subjects than fit, these are the ones it returns to most, and the label says
 * so; where they all fit, "most used" would be a claim about nothing.
 *
 * Variables:
 *   $scsl_terms (WP_Term[]) every topic in the set, most used first
 *   $scsl_limit (int)       how many to show
 *   $scsl_align (string)    'end' to align against the right edge
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$scsl_terms = isset( $scsl_terms ) ? (array) $scsl_terms : [];

if ( ! $scsl_terms ) return;

$scsl_limit = isset( $scsl_limit ) ? (int) $scsl_limit : 5;
$scsl_align = isset( $scsl_align ) ? (string) $scsl_align : '';

$scsl_shown     = array_slice( $scsl_terms, 0, max( 1, $scsl_limit ) );
$scsl_truncated = count( $scsl_terms ) > count( $scsl_shown );
?>

<div class="scsl-topic-block<?php echo 'end' === $scsl_align ? ' scsl-topic-block--end' : ''; ?>">
	<p class="scsl-topic-block__label">
		<?php
		if ( $scsl_truncated ) {
			esc_html_e( 'Most used topics:', 'seedcast-sermon-library' );
		} else {
			esc_html_e( 'Topics:', 'seedcast-sermon-library' );
		}
		?>
	</p>

	<div class="scsl-topic-chips">
		<?php foreach ( $scsl_shown as $scsl_chip ) : ?>
			<a href="<?php echo esc_url( get_term_link( $scsl_chip ) ); ?>" class="scsl-topic-tag">
				<?php echo esc_html( $scsl_chip->name ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</div>
