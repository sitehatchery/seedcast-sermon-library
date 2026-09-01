<?php
/**
 * Template: Single Series
 * Override: your-theme/seedcast-sermon-library/single/single-series.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\TemplateLoader;
use SeedcastSermonLibrary\Frontend\Shortcodes;

get_header();

while ( have_posts() ) :
	the_post();
	$scsl_post_id      = get_the_ID();
	$scsl_start_date   = get_post_meta( $scsl_post_id, '_scsl_series_start_date', true );
	$scsl_end_date     = get_post_meta( $scsl_post_id, '_scsl_series_end_date',   true );
	$scsl_series_type  = get_post_meta( $scsl_post_id, '_scsl_series_type',       true );
	$scsl_series_label = get_option( 'scsl_label_series', '' ) ?: __( 'Series', 'seedcast-sermon-library' );
	$scsl_per_page     = absint( get_option( 'scsl_sermons_per_page', 10 ) );

	// Total count for hero
	$scsl_total_query = new \WP_Query( [
		'post_type'      => 'scsl_sermon',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [ [ 'key' => '_scsl_series_id', 'value' => $scsl_post_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	] );
	$scsl_episode_count = $scsl_total_query->found_posts;
	wp_reset_postdata();

	$scsl_page_raw = get_query_var( 'scsl_page', 1 );
	$scsl_paged = max( 1, absint( $scsl_page_raw ) );

	// First page of sermons: newest first
	$scsl_sermons = new \WP_Query( [
		'post_type'      => 'scsl_sermon',
		'post_status'    => 'publish',
		'posts_per_page' => $scsl_per_page,
		'paged'          => $scsl_paged,
		'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'order'          => 'DESC',
		'meta_query'     => [ [ 'key' => '_scsl_series_id', 'value' => $scsl_post_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	] );

	// Speakers in this series for filter dropdown
	$scsl_all_sermon_ids = get_posts( [
		'post_type'      => 'scsl_sermon',
		'post_status'    => 'publish',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'meta_key'       => '_scsl_series_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => $scsl_post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	] );
	$scsl_speaker_ids = [];
	foreach ( $scsl_all_sermon_ids as $scsl_sid ) {
		$scsl_spk = get_post_meta( $scsl_sid, '_scsl_speaker_id', true );
		if ( $scsl_spk && ! in_array( $scsl_spk, $scsl_speaker_ids, true ) ) {
			$scsl_speaker_ids[] = $scsl_spk;
		}
	}

	// Topics for this series
	$scsl_series_topics = get_the_terms( $scsl_post_id, 'scsl_topic' );

	// Enqueue AJAX list script
	wp_enqueue_script( 'scsl-sermon-list' );
	?>

<div class="sc-wrap">

	<!-- Breadcrumb -->
	<?php
	\Seedcast\Core\Frontend\Breadcrumb::render( [
		[ 'label' => __( 'Sermon Library', 'seedcast-sermon-library' ), 'url' => get_post_type_archive_link( 'scsl_series' ) ],
		[ 'label' => get_the_title() ],
	] );
	?>

	<!-- Series Hero: image left, content right -->
	<header class="scsl-series-hero <?php echo has_post_thumbnail() ? 'scsl-series-hero--has-image' : 'scsl-series-hero--no-image'; ?>">
		<?php if ( has_post_thumbnail() ) : ?>
		<div class="scsl-series-hero__image">
			<?php the_post_thumbnail( 'large', [ 'alt' => esc_attr( get_the_title() ) ] ); ?>
		</div>
		<?php endif; ?>

		<div class="scsl-series-hero__content">
			<p class="scsl-series-hero__eyebrow"><?php echo esc_html( $scsl_series_label ); ?></p>

			<?php if ( $scsl_series_type && $scsl_series_type !== 'sermon' ) : ?>
				<span class="scsl-series-type-badge"><?php echo esc_html( ucfirst( $scsl_series_type ) ); ?></span>
			<?php endif; ?>

			<h1 class="scsl-series-title"><?php the_title(); ?></h1>

			<div class="scsl-series-meta">
				<?php if ( $scsl_episode_count ) : ?>
					<span class="scsl-meta-count">
						<?php
						// translators: %d is the number of sermons in the series
						$scsl_episode_label = _n( '%d sermon', '%d sermons', $scsl_episode_count, 'seedcast-sermon-library' );
						printf( esc_html( $scsl_episode_label ), esc_html( $scsl_episode_count ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $scsl_start_date ) : ?>
					<span class="scsl-meta-date">
						<?php echo esc_html( date_i18n( 'Y', strtotime( $scsl_start_date ) ) ); ?>
						<?php if ( $scsl_end_date && gmdate( 'Y', strtotime( $scsl_end_date ) ) !== gmdate( 'Y', strtotime( $scsl_start_date ) ) ) : ?>
							&ndash; <?php echo esc_html( date_i18n( 'Y', strtotime( $scsl_end_date ) ) ); ?>
						<?php endif; ?>
					</span>
				<?php endif; ?>
			</div>

			<?php
			// Show editor content first, fall back to excerpt
			$scsl_series_content = get_the_content();
			if ( $scsl_series_content ) : ?>
				<div class="scsl-series-description"><?php the_content(); ?></div>
			<?php elseif ( has_excerpt() ) : ?>
				<div class="scsl-series-description"><?php the_excerpt(); ?></div>
			<?php endif; ?>

			<!-- Topics -->
			<?php $scsl_show_topics = get_option( 'scsl_show_topics_on_series', '1' );
			if ( $scsl_show_topics && $scsl_series_topics && ! is_wp_error( $scsl_series_topics ) ) : ?>
			<div class="scsl-series-topics">
				<?php foreach ( $scsl_series_topics as $scsl_topic ) : ?>
					<span class="scsl-topic-tag"><?php echo esc_html( $scsl_topic->name ); ?></span>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</header>

	<!-- Episode List with full filter bar -->
	<?php if ( $scsl_episode_count > 0 ) : ?>
	<section class="scsl-episode-list" aria-label="<?php esc_attr_e( 'Sermons in this series', 'seedcast-sermon-library' ); ?>">
		<h2 class="sc-section-title"><?php esc_html_e( 'Sermons', 'seedcast-sermon-library' ); ?></h2>

		<div class="scsl-sermon-list-wrap"
			 data-series-id="<?php echo esc_attr( $scsl_post_id ); ?>"
			 data-per-page="<?php echo esc_attr( $scsl_per_page ); ?>"
			 data-speaker-id=""
			 data-topic=""
			 data-book=""
			 data-orderby="recorded_date"
			 data-order="DESC"
			 data-template="episode-card"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( 'scsl_list_nonce' ) ); ?>">

			<!-- Filter bar -->
			<?php
			// Build extra filters specific to this series context
			$scsl_extra = [];
			if ( count( $scsl_speaker_ids ) > 1 ) {
				ob_start();
				echo '<select name="scsl_speaker_id" class="scsl-filter-select" data-filter="speaker_id" aria-label="' . esc_attr__( 'Speaker', 'seedcast-sermon-library' ) . '">';
				echo '<option value="">' . esc_html__( 'All speakers', 'seedcast-sermon-library' ) . '</option>';
				foreach ( $scsl_speaker_ids as $scsl_spk_id ) {
					echo '<option value="' . esc_attr( $scsl_spk_id ) . '">' . esc_html( get_the_title( $scsl_spk_id ) ) . '</option>';
				}
				echo '</select>';
				$scsl_extra[] = ob_get_clean();
			}
			// Topics that appear within this series
			$scsl_series_topic_terms = [];
			foreach ( $scsl_all_sermon_ids as $scsl_sid ) {
				$scsl_s_topics = get_the_terms( $scsl_sid, 'scsl_topic' );
				if ( $scsl_s_topics && ! is_wp_error( $scsl_s_topics ) ) {
					foreach ( $scsl_s_topics as $scsl_st ) $scsl_series_topic_terms[ $scsl_st->term_id ] = $scsl_st;
				}
			}
			if ( $scsl_series_topic_terms ) {
				ob_start();
				echo '<select name="scsl_topic" class="scsl-filter-select" data-filter="topic" aria-label="' . esc_attr__( 'Topic', 'seedcast-sermon-library' ) . '">';
				echo '<option value="">' . esc_html__( 'All topics', 'seedcast-sermon-library' ) . '</option>';
				foreach ( $scsl_series_topic_terms as $scsl_st ) {
					echo '<option value="' . esc_attr( $scsl_st->slug ) . '">' . esc_html( $scsl_st->name ) . '</option>';
				}
				echo '</select>';
				$scsl_extra[] = ob_get_clean();
			}
			// Sort order
			ob_start();
			echo '<select name="scsl_order" class="scsl-filter-select" data-filter="order" aria-label="' . esc_attr__( 'Sort order', 'seedcast-sermon-library' ) . '">';
			echo '<option value="DESC">' . esc_html__( 'Newest First', 'seedcast-sermon-library' ) . '</option>';
			echo '<option value="ASC">'  . esc_html__( 'Oldest First', 'seedcast-sermon-library' ) . '</option>';
			echo '</select>';
			$scsl_extra[] = ob_get_clean();

			$scsl_threshold = absint( get_option( 'scsl_filter_threshold', 0 ) );
			$scsl_count = isset( $scsl_sermons ) ? $scsl_sermons->found_posts : 999;
			if ( $scsl_threshold === 0 || $scsl_count > $scsl_threshold ) :
			( new Shortcodes() )->render_filter_bar( [
				'uid'           => 'scsl-series-' . $scsl_post_id,
				'lock_series'   => true,   // already on a series page
				'lock_topic'    => true,   // topics handled via extra_filters above
				'lock_speaker'  => true,   // speaker handled via extra_filters above (scoped to series)
				'extra_filters' => $scsl_extra,
			] );
			endif;
			?>

			<!-- Results -->
			<div class="scsl-list-results scsl-list-results--cards">
				<?php while ( $scsl_sermons->have_posts() ) : $scsl_sermons->the_post(); ?>
					<?php TemplateLoader::partial( 'episode-card', [ 'post' => get_post() ] ); ?>
				<?php endwhile; wp_reset_postdata(); ?>
			</div>

			<!-- Pagination: real links on initial load, AJAX after filter -->
			<?php if ( $scsl_sermons->max_num_pages > 1 ) : ?>
			<div class="scsl-list-pagination scsl-list-pagination--seo">
				<nav class="sc-pagination sc-pagination--links" aria-label="<?php esc_attr_e( 'Pages', 'seedcast-sermon-library' ); ?>">
					<?php if ( $scsl_paged > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged - 1 ) ); ?>"
						   class="scsl-btn scsl-btn--ghost scsl-page-link" rel="prev">&larr; <?php esc_html_e( 'Prev', 'seedcast-sermon-library' ); ?></a>
					<?php endif; ?>
					<span class="scsl-page-info">
						<?php
						// translators: %1$d is the current page number, %2$d is the total number of pages
						printf( esc_html__( 'Page %1$d of %2$d', 'seedcast-sermon-library' ), esc_html( $scsl_paged ), esc_html( $scsl_sermons->max_num_pages ) ); ?>
					</span>
					<?php if ( $scsl_paged < $scsl_sermons->max_num_pages ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged + 1 ) ); ?>"
						   class="scsl-btn scsl-btn--ghost scsl-page-link" rel="next"><?php esc_html_e( 'Next', 'seedcast-sermon-library' ); ?> &rarr;</a>
					<?php endif; ?>
				</nav>
			</div>
			<div class="scsl-list-pagination scsl-list-pagination--ajax" style="display:none;"></div>
			<?php endif; ?>

			<div class="scsl-list-loading" style="display:none;"></div>
		</div>

	</section>
	<?php else : ?>
		<p class="scsl-no-content"><?php esc_html_e( 'No sermons in this series yet.', 'seedcast-sermon-library' ); ?></p>
	<?php endif; ?>

</div>

<?php endwhile;

get_footer();
