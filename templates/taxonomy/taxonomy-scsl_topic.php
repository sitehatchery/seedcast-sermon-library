<?php
/**
 * Template: Topic Archive (sf_topic taxonomy)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
use SeedcastSermonLibrary\Frontend\TemplateLoader;
use SeedcastSermonLibrary\Frontend\Shortcodes;

get_header();

$scsl_term     = get_queried_object();
$scsl_per_page = absint( get_option( 'scsl_sermons_per_page', 10 ) );
$scsl_page_raw = get_query_var( 'scsl_page', 1 );
$scsl_paged = max( 1, absint( $scsl_page_raw ) );

$scsl_sermons = new \WP_Query( [
	'post_type'      => 'scsl_sermon',
	'post_status'    => 'publish',
	'posts_per_page' => $scsl_per_page,
	'paged'          => $scsl_paged,
	'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	'order'          => 'DESC',
	'tax_query'      => [ [ 'taxonomy' => 'scsl_topic', 'field' => 'term_id', 'terms' => $scsl_term->term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
] );

wp_enqueue_script( 'scsl-sermon-list' );
wp_enqueue_style( 'scsl-frontend' );
$scsl_uid = 'scsl-topic-' . $scsl_term->term_id;
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
		<p class="scsl-archive-count">
			<?php
			// translators: %d is the number of sermons
			printf( esc_html( _n( '%d sermon', '%d sermons', $scsl_sermons->found_posts, 'seedcast-sermon-library' ) ), esc_html( number_format_i18n( $scsl_sermons->found_posts ) ) ); ?>
		</p>
	</header>

	<div class="scsl-sermon-list-wrap"
		 id="<?php echo esc_attr( $scsl_uid ); ?>"
		 data-topic="<?php echo esc_attr( $scsl_term->slug ); ?>"
		 data-series-id=""
		 data-speaker-id=""
		 data-book=""
		 data-per-page="<?php echo esc_attr( $scsl_per_page ); ?>"
		 data-orderby="recorded_date"
		 data-order="DESC"
		 data-template="episode-card"
		 data-nonce="<?php echo esc_attr( wp_create_nonce( 'scsl_list_nonce' ) ); ?>">

		<!-- Filter bar -->
		<?php
		// Sort order as extra filter for topic context
		$scsl_topic_extra = [];
		ob_start();
		echo '<select id="' . esc_attr( $scsl_uid ) . '-order" name="scsl_order" class="scsl-filter-select" data-filter="order" aria-label="' . esc_attr__( 'Sort order', 'seedcast-sermon-library' ) . '">';
		echo '<option value="DESC">' . esc_html__( 'Newest First', 'seedcast-sermon-library' ) . '</option>';
		echo '<option value="ASC">'  . esc_html__( 'Oldest First', 'seedcast-sermon-library' ) . '</option>';
		echo '</select>';
		$scsl_topic_extra[] = ob_get_clean();

		$scsl_threshold = absint( get_option( 'scsl_filter_threshold', 0 ) );
		$scsl_count = isset( $scsl_sermons ) ? $scsl_sermons->found_posts : 999;
		if ( $scsl_threshold === 0 || $scsl_count > $scsl_threshold ) :
		( new Shortcodes() )->render_filter_bar( [
			'uid'           => $scsl_uid,
			'lock_topic'    => true, // already on a topic page
			'extra_filters' => $scsl_topic_extra,
		] );
		endif;
		?>

		<!-- Results -->
		<div class="scsl-list-results scsl-list-results--cards">
			<?php while ( $scsl_sermons->have_posts() ) : $scsl_sermons->the_post();
				TemplateLoader::partial( 'episode-card', [ 'post' => get_post() ] );
			endwhile; wp_reset_postdata(); ?>
		</div>

		<!-- SEO Pagination -->
		<?php if ( $scsl_sermons->max_num_pages > 1 ) : ?>
		<div class="scsl-list-pagination scsl-list-pagination--seo">
			<nav class="sc-pagination sc-pagination--links" aria-label="<?php esc_attr_e( 'Pages', 'seedcast-sermon-library' ); ?>">
				<?php if ( $scsl_paged > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged - 1 ) ); ?>" class="scsl-btn scsl-btn--ghost scsl-page-link" rel="prev">&larr; <?php esc_html_e( 'Prev', 'seedcast-sermon-library' ); ?></a><?php endif; ?>
				<span class="scsl-page-info"><?php
				// translators: %1$d is the current page number, %2$d is the total number of pages
				printf( esc_html__( 'Page %1$d of %2$d', 'seedcast-sermon-library' ), esc_html( $scsl_paged ), esc_html( $scsl_sermons->max_num_pages ) ); ?></span>
				<?php if ( $scsl_paged < $scsl_sermons->max_num_pages ) : ?><a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged + 1 ) ); ?>" class="scsl-btn scsl-btn--ghost scsl-page-link" rel="next"><?php esc_html_e( 'Next', 'seedcast-sermon-library' ); ?> &rarr;</a><?php endif; ?>
			</nav>
		</div>
		<div class="scsl-list-pagination scsl-list-pagination--ajax" style="display:none;"></div>
		<?php endif; ?>

		<div class="scsl-list-loading" style="display:none;"></div>
	</div>

</div>

<?php get_footer();
