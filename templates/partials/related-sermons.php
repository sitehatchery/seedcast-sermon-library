<?php
/**
 * Partial: More From This Series
 * Variables: $series_id (int), $scsl_exclude (int)
 *
 * Renders an AJAX-capable paginated list with filters,
 * pre-filtered to this series and excluding the current sermon.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\TemplateLoader;
use SeedcastSermonLibrary\Frontend\Shortcodes;

$scsl_per_page = absint( get_option( 'scsl_sermons_per_page', 10 ) );

// Read current page: registered query var with $_GET fallback
$scsl_page_raw = get_query_var( 'scsl_page', 1 );
$scsl_paged = max( 1, absint( $scsl_page_raw ) );

// Query sermons in this series ordered by date, then exclude current in PHP
// This avoids post__not_in (slow on large tables) and meta_key ordering warning
$scsl_query = new \WP_Query( [
    'post_type'      => 'scsl_sermon',
    'post_status'    => 'publish',
    'posts_per_page' => $scsl_per_page + 1, // fetch one extra to account for exclusion
    'paged'          => $scsl_paged,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'meta_query'     => [ [ 'key' => '_scsl_series_id', 'value' => $series_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
] );

// Filter out the excluded post in PHP
if ( $scsl_query->have_posts() ) {
    $scsl_query->posts = array_values( array_filter(
        $scsl_query->posts,
        function( $p ) use ( $scsl_exclude ) { return $p->ID !== $scsl_exclude; }
    ) );
    $scsl_query->post_count = count( $scsl_query->posts );
    if ( $scsl_query->post_count > $scsl_per_page ) {
        array_pop( $scsl_query->posts );
        $scsl_query->post_count = $scsl_per_page;
    }
}

if ( ! $scsl_query->have_posts() ) return;

// Get speakers in this series for filter
$scsl_all_in_series = get_posts( [
    'post_type'   => 'scsl_sermon',
    'post_status' => 'publish',
    'numberposts' => -1,
    'fields'      => 'ids',
    'meta_query'  => [ [ 'key' => '_scsl_series_id', 'value' => $series_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
] );
$scsl_speaker_ids = [];
foreach ( $scsl_all_in_series as $scsl_sid ) {
    $scsl_spk = get_post_meta( $scsl_sid, '_scsl_speaker_id', true );
    if ( $scsl_spk && ! in_array( $scsl_spk, $scsl_speaker_ids, true ) ) {
        $scsl_speaker_ids[] = $scsl_spk;
    }
}

wp_enqueue_script( 'scsl-sermon-list' );
?>

<section class="scsl-related">
    <h2 class="scsl-related__title"><?php esc_html_e( 'More from this series', 'seedcast-sermon-library' ); ?></h2>

    <div class="scsl-sermon-list-wrap scsl-related__list-wrap"
         data-series-id="<?php echo esc_attr( $series_id ); ?>"
         data-exclude="<?php echo esc_attr( $scsl_exclude ); ?>"
         data-speaker-id=""
         data-topic=""
         data-book=""
         data-per-page="<?php echo esc_attr( $scsl_per_page ); ?>"
         data-orderby="recorded_date"
         data-order="DESC"
         data-template="episode-card"
         data-nonce="<?php echo esc_attr( wp_create_nonce( 'scsl_list_nonce' ) ); ?>">

        <?php
        // Extra filters: sort order only (series is locked, speaker locked if only one)
        $scsl_related_extra = [];
        ob_start();
        echo '<select name="scsl_order" class="scsl-filter-select" data-filter="order" aria-label="' . esc_attr__( 'Sort order', 'seedcast-sermon-library' ) . '">';
        echo '<option value="DESC">' . esc_html__( 'Newest First', 'seedcast-sermon-library' ) . '</option>';
        echo '<option value="ASC">'  . esc_html__( 'Oldest First', 'seedcast-sermon-library' ) . '</option>';
        echo '</select>';
        $scsl_related_extra[] = ob_get_clean();

        ( new Shortcodes() )->render_filter_bar( [
            'uid'           => 'scsl-related-' . $series_id,
            'lock_series'   => true,
            'lock_topic'    => false,
            'lock_speaker'  => count( $scsl_speaker_ids ) <= 1,
            'extra_filters' => $scsl_related_extra,
        ] );
        ?>

        <div class="scsl-list-results scsl-list-results--cards">
            <?php while ( $scsl_query->have_posts() ) : $scsl_query->the_post(); ?>
                <?php TemplateLoader::partial( 'episode-card', [ 'post' => get_post() ] ); ?>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>

        <?php if ( $scsl_query->max_num_pages > 1 ) : ?>
        <div class="scsl-list-pagination scsl-list-pagination--seo">
            <nav class="sc-pagination sc-pagination--links" aria-label="<?php esc_attr_e( 'Pages', 'seedcast-sermon-library' ); ?>">
                <?php if ( $scsl_paged > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged - 1 ) ); ?>"
                       class="scsl-btn scsl-btn--ghost scsl-page-link" rel="prev nofollow">&larr; <?php esc_html_e( 'Prev', 'seedcast-sermon-library' ); ?></a>
                <?php endif; ?>
                <span class="scsl-page-info">
                    <?php
                    // translators: %1$d is the current page number, %2$d is the total number of pages
                    printf( esc_html__( 'Page %1$d of %2$d', 'seedcast-sermon-library' ), esc_html( $scsl_paged ), esc_html( $scsl_query->max_num_pages ) ); ?>
                </span>
                <?php if ( $scsl_paged < $scsl_query->max_num_pages ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged + 1 ) ); ?>"
                       class="scsl-btn scsl-btn--ghost scsl-page-link" rel="next nofollow"><?php esc_html_e( 'Next', 'seedcast-sermon-library' ); ?> &rarr;</a>
                <?php endif; ?>
            </nav>
        </div>
        <div class="scsl-list-pagination scsl-list-pagination--ajax" style="display:none;"></div>
        <?php endif; ?>

        <div class="scsl-list-loading" style="display:none;"></div>
    </div>
</section>
