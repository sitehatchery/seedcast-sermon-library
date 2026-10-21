<?php
/**
 * Template: Series Archive (Sermon Library)
 * Override by copying to: your-theme/seedcast-sermon-library/archive/archive-series.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\TemplateLoader;

get_header();

$scsl_current_topic = get_query_var( 'scsl_topic', '' );

/*
 * The row of subjects above the grid, which a church can turn off.
 *
 * It was drawn whatever anybody wanted, and it is the one list on the site
 * that grows with the vocabulary rather than with the library: two dozen
 * links above the series is a different page from four.
 */
$scsl_topics = '1' === (string) get_option( 'scsl_show_topics_on_series_archive', '1' )
	? get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => true ] )
	: [];
?>

<div class="sc-wrap">

	<header class="scsl-archive-header">
		<h1 class="scsl-archive-title"><?php esc_html_e( 'Sermon Library', 'seedcast-sermon-library' ); ?></h1>
	</header>

	<!-- Topic Filter -->
	<?php if ( $scsl_topics && ! is_wp_error( $scsl_topics ) ) : ?>
	<?php
	/*
	 * The same chip a topic wears everywhere else on the site.
	 *
	 * These were the theme's filter pills, which a warm theme paints gold:
	 * the same topic then looked like one thing above the series grid, another
	 * on a sermon, and another on a passage page. A topic is a topic.
	 */
	?>
	<nav class="scsl-topic-chips scsl-topic-chips--bar" aria-label="<?php esc_attr_e( 'Browse by topic', 'seedcast-sermon-library' ); ?>">
		<a href="<?php echo esc_url( get_post_type_archive_link( 'scsl_series' ) ); ?>"
		   class="scsl-topic-tag <?php echo esc_attr( ! $scsl_current_topic ? 'is-active' : '' ); ?>">
			<?php esc_html_e( 'All', 'seedcast-sermon-library' ); ?>
		</a>
		<?php foreach ( $scsl_topics as $scsl_topic ) : ?>
			<a href="<?php echo esc_url( get_term_link( $scsl_topic ) ); ?>"
			   class="scsl-topic-tag <?php echo esc_attr( ( $scsl_current_topic === $scsl_topic->slug ) ? 'is-active' : '' ); ?>">
				<?php echo esc_html( $scsl_topic->name ); ?>
			</a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<!-- Series Grid -->
	<?php if ( have_posts() ) : ?>
	<div class="scsl-series-grid sc-grid-cols-3">
		<?php while ( have_posts() ) : the_post(); ?>
			<?php TemplateLoader::partial( 'series-card', [ 'post' => get_post() ] ); ?>
		<?php endwhile; ?>
	</div>

	<?php
	global $wp_query;
	$scsl_total_pages = $wp_query->max_num_pages;
	$scsl_paged       = max( 1, get_query_var( 'paged' ) );
	if ( $scsl_total_pages > 1 ) :
	?>
	<nav class="scsl-series-pagination" aria-label="<?php esc_attr_e( 'Series pages', 'seedcast-sermon-library' ); ?>">
		<?php if ( $scsl_paged > 1 ) : ?>
			<a href="<?php echo esc_url( get_pagenum_link( $scsl_paged - 1 ) ); ?>" class="scsl-page-prev" aria-label="<?php esc_attr_e( 'Previous page', 'seedcast-sermon-library' ); ?>">&#8592;</a>
		<?php endif; ?>

		<?php for ( $scsl_p = 1; $scsl_p <= $scsl_total_pages; $scsl_p++ ) : ?>
			<?php if ( $scsl_p === $scsl_paged ) : ?>
				<span class="scsl-page-current" aria-current="page"><?php echo esc_html( $scsl_p ); ?></span>
			<?php else : ?>
				<a href="<?php echo esc_url( get_pagenum_link( $scsl_p ) ); ?>"><?php echo esc_html( $scsl_p ); ?></a>
			<?php endif; ?>
		<?php endfor; ?>

		<?php if ( $scsl_paged < $scsl_total_pages ) : ?>
			<a href="<?php echo esc_url( get_pagenum_link( $scsl_paged + 1 ) ); ?>" class="scsl-page-next" aria-label="<?php esc_attr_e( 'Next page', 'seedcast-sermon-library' ); ?>">&#8594;</a>
		<?php endif; ?>
	</nav>
	<?php endif; ?>

	<?php else : ?>
		<p class="scsl-no-content"><?php esc_html_e( 'No series found.', 'seedcast-sermon-library' ); ?></p>
	<?php endif; ?>

</div>

<?php get_footer(); ?>
