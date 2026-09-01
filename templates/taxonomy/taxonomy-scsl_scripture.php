<?php
/**
 * Template: Scripture Archive (scsl_scripture taxonomy)
 *
 * Every sermon that touched this passage, not only the ones that preached it as
 * the main text. Terms are assigned from the focus passage and the other
 * passages together, so a sermon that referenced this in passing belongs here
 * as much as one built on it.
 *
 * Uses the same sermon card the service page uses. A church should not find two
 * lists of the same sermons on the same site that look nothing alike, and
 * keeping one component means the two cannot drift.
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\BulletinLibraryBridge;
use SeedcastSermonLibrary\Scripture\ScriptureParser;

get_header();

$scsl_term     = get_queried_object();
$scsl_per_page = absint( get_option( 'scsl_sermons_per_page', 10 ) );
$scsl_page_raw = get_query_var( 'scsl_page', 1 );
$scsl_paged    = max( 1, absint( $scsl_page_raw ) );

/*
 * Every passage that touches this one, not only the identical wording.
 *
 * References are stored as somebody typed them, so a page for Ephesians 6:15
 * would sit empty while a sermon on Ephesians 6:10-20 was one click away and
 * plainly about the same verse. A church does not think of those as different
 * passages and neither should this page.
 *
 * Comparison rather than text matching: a whole chapter contains its verses, a
 * range contains what falls inside it, and a book contains all of it.
 */
$scsl_overlapping = [ $scsl_term->term_id ];
$scsl_all_terms   = get_terms( [ 'taxonomy' => 'scsl_scripture', 'hide_empty' => true ] );

if ( ! is_wp_error( $scsl_all_terms ) ) {
	foreach ( $scsl_all_terms as $scsl_other ) {
		if ( (int) $scsl_other->term_id === (int) $scsl_term->term_id ) continue;

		if ( ScriptureParser::overlaps( (string) $scsl_term->name, (string) $scsl_other->name ) ) {
			$scsl_overlapping[] = (int) $scsl_other->term_id;
		}
	}
}

$scsl_sermons = new \WP_Query( [
	'post_type'      => 'scsl_sermon',
	'post_status'    => 'publish',
	'posts_per_page' => $scsl_per_page,
	'paged'          => $scsl_paged,
	'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	'order'          => 'DESC',
	'tax_query'      => [ [ 'taxonomy' => 'scsl_scripture', 'field' => 'term_id', 'terms' => $scsl_overlapping ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
] );

/*
 * A way to read the passage, not the passage itself.
 *
 * Almost every translation a church might choose is under copyright, and only
 * the reader's own Bible site is licensed to show it. Printing the words here
 * would put every church running this plugin in breach of a licence they never
 * agreed to and do not know exists, on the strength of picking a translation
 * from a dropdown. So this links out, in whichever translation the church has
 * configured, exactly as the panel on a sermon page already does.
 *
 * There is nothing lost by it. Passage text is the most duplicated writing on
 * the internet, and a page made mostly of words found on ten thousand other
 * sites is weaker than one made of this church's own sermons.
 */
$scsl_read_url   = ScriptureParser::build_bible_url( (string) $scsl_term->name );
$scsl_translation = (string) get_option( 'scsl_bible_translation', 'NIV' );

wp_enqueue_style( 'scsl-frontend' );
?>

<div class="sc-wrap">

	<nav class="sc-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'seedcast-sermon-library' ); ?>">
		<ol class="sc-breadcrumb__list">
			<li><a href="<?php echo esc_url( get_post_type_archive_link( 'scsl_series' ) ); ?>"><?php esc_html_e( 'Sermon Library', 'seedcast-sermon-library' ); ?></a></li>
			<li aria-hidden="true" class="sc-breadcrumb__sep">/</li>
			<li aria-current="page"><?php echo esc_html( $scsl_term->name ); ?></li>
		</ol>
	</nav>

	<header class="scsl-archive-header">
		<h1 class="scsl-archive-title"><?php echo esc_html( $scsl_term->name ); ?></h1>

		<?php if ( $scsl_term->description ) : ?>
			<p class="scsl-archive-desc"><?php echo esc_html( $scsl_term->description ); ?></p>
		<?php endif; ?>

		<?php if ( $scsl_read_url ) : ?>
			<p class="scsl-archive-read">
				<a href="<?php echo esc_url( $scsl_read_url ); ?>"
				   class="scsl-scripture-ref scsl-scripture-ref--focus"
				   target="_blank"
				   rel="noopener noreferrer">
					<?php
					printf(
						/* translators: 1: a passage, for example 2 Thessalonians 3:3-18. 2: a translation, for example NIV. */
						esc_html__( 'Read %1$s in the %2$s', 'seedcast-sermon-library' ),
						esc_html( $scsl_term->name ),
						esc_html( $scsl_translation )
					);
					?>
					<span class="scsl-scripture-ref__icon" aria-hidden="true">↗</span>
				</a>
			</p>
		<?php endif; ?>

		<p class="scsl-archive-count">
			<?php
			printf(
				esc_html(
					/* translators: %s: number of sermons. */
					_n( '%s sermon from this passage', '%s sermons from this passage or a passage containing it', $scsl_sermons->found_posts, 'seedcast-sermon-library' )
				),
				esc_html( number_format_i18n( $scsl_sermons->found_posts ) )
			);
			?>
		</p>
	</header>

	<?php if ( $scsl_sermons->have_posts() ) : ?>

		<div class="scsl-service-sermons scsl-scripture-sermons">
			<?php
			while ( $scsl_sermons->have_posts() ) :
				$scsl_sermons->the_post();

				// The service page's card, unchanged. Already escaped by the
				// component that built it.
				echo BulletinLibraryBridge::card( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			endwhile;
			?>
		</div>

		<?php
		if ( $scsl_sermons->max_num_pages > 1 ) {
			echo '<div class="scsl-scripture-pagination">';
			\Seedcast\Core\Frontend\Pagination::render( [
				'total'   => (int) $scsl_sermons->max_num_pages,
				'current' => $scsl_paged,
				'base'    => add_query_arg( 'scsl_page', '%#%' ),
				'format'  => '',
			] );
			echo '</div>';
		}
		?>

	<?php else : ?>

		<p class="scsl-archive-empty"><?php esc_html_e( 'No sermons from this passage yet.', 'seedcast-sermon-library' ); ?></p>

	<?php endif; ?>

</div>

<?php
wp_reset_postdata();
get_footer();
