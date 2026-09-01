<?php
/**
 * Template: Sermon Archive (Sermon Library)
 * Override by copying to: your-theme/seedcast-sermon-library/archive/archive-sermon.php
 *
 * Renders the /sermon/ archive with the same styling as the sermon list shortcode.
 * For full filter/AJAX functionality, use the [scsl_sermon_list] shortcode on a page instead.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\TemplateLoader;

get_header();

$scsl_label_sermons = get_option( 'scsl_label_sermons', '' ) ?: __( 'Sermons', 'seedcast-sermon-library' );
$scsl_per_page      = (int) get_option( 'scsl_sermons_per_page', 10 );
$scsl_paged            = max( 1, get_query_var( 'paged' ) );

// Re-run the query with our ordering so archive respects recorded date
$scsl_query = new WP_Query( [
	'post_type'      => 'scsl_sermon',
	'posts_per_page' => $scsl_per_page,
	'paged' => $scsl_paged,
	'post_status'    => 'publish',
	'orderby'        => 'meta_value',
	'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	'order'          => 'DESC',
] );
?>

<div class="sc-wrap">

	<header class="scsl-archive-header">
		<h1 class="scsl-archive-title"><?php echo esc_html( $scsl_label_sermons ); ?></h1>
	</header>

	<?php if ( $scsl_query->have_posts() ) : ?>

		<div class="scsl-sermon-list" role="list">
			<?php while ( $scsl_query->have_posts() ) : $scsl_query->the_post(); ?>
				<?php TemplateLoader::partial( 'sermon-list-item', [ 'post' => get_post() ] ); ?>
			<?php endwhile; ?>
		</div>

		<?php
		$scsl_total_pages = $scsl_query->max_num_pages;
		if ( $scsl_total_pages > 1 ) :
		?>
		<nav class="scsl-series-pagination" aria-label="<?php esc_attr_e( 'Sermon pages', 'seedcast-sermon-library' ); ?>">
			<?php if ( $scsl_paged > 1 ) : ?>
				<a href="<?php echo esc_url( get_pagenum_link( $scsl_paged - 1 ) ); ?>"
				   class="scsl-page-prev"
				   aria-label="<?php esc_attr_e( 'Previous page', 'seedcast-sermon-library' ); ?>">&#8592;</a>
			<?php endif; ?>

			<?php for ( $scsl_p = 1; $scsl_p <= $scsl_total_pages; $scsl_p++ ) : ?>
				<?php if ( $scsl_p === $scsl_paged ) : ?>
					<span class="scsl-page-current" aria-current="page"><?php echo esc_html( $scsl_p ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( get_pagenum_link( $scsl_p ) ); ?>"
					   class="scsl-page-link"><?php echo esc_html( $scsl_p ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>

			<?php if ( $scsl_paged < $scsl_total_pages ) : ?>
				<a href="<?php echo esc_url( get_pagenum_link( $scsl_paged + 1 ) ); ?>"
				   class="scsl-page-next"
				   aria-label="<?php esc_attr_e( 'Next page', 'seedcast-sermon-library' ); ?>">&#8594;</a>
			<?php endif; ?>
		</nav>
		<?php endif; ?>

	<?php else : ?>
		<p class="scsl-no-results"><?php esc_html_e( 'No sermons found.', 'seedcast-sermon-library' ); ?></p>
	<?php endif; ?>

	<?php wp_reset_postdata(); ?>

</div>

<?php get_footer(); ?>
