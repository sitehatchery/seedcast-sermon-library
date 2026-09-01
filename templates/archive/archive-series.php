<?php
/**
 * Template: Series Archive (Sermon Library)
 * Override by copying to: your-theme/seedcast-sermon-library/archive/archive-series.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\TemplateLoader;

get_header();

$scsl_current_topic = get_query_var( 'scsl_topic', '' );
$scsl_topics = get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => true ] );
?>

<div class="sc-wrap">

	<header class="scsl-archive-header">
		<h1 class="scsl-archive-title"><?php esc_html_e( 'Sermon Library', 'seedcast-sermon-library' ); ?></h1>
	</header>

	<!-- Topic Filter -->
	<?php if ( $scsl_topics && ! is_wp_error( $scsl_topics ) ) : ?>
	<nav class="sc-filter-bar" aria-label="<?php esc_attr_e( 'Filter by topic', 'seedcast-sermon-library' ); ?>">
		<a href="<?php echo esc_url( get_post_type_archive_link( 'scsl_series' ) ); ?>"
		   class="sc-filter-btn <?php echo esc_attr( ! $scsl_current_topic ? 'is-active' : '' ); ?>">
			<?php esc_html_e( 'All', 'seedcast-sermon-library' ); ?>
		</a>
		<?php foreach ( $scsl_topics as $scsl_topic ) : ?>
			<a href="<?php echo esc_url( get_term_link( $scsl_topic ) ); ?>"
			   class="sc-filter-btn <?php echo esc_attr( ( $scsl_current_topic === $scsl_topic->slug ) ? 'is-active' : '' ); ?>">
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
