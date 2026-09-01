<?php
/**
 * Partial: Speaker Card
 * Variables: $post (WP_Post)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$scsl_post_id = $post->ID;
$scsl_title   = get_post_meta( $scsl_post_id, '_scsl_speaker_title', true );

global $wpdb;
$scsl_count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
	 WHERE p.post_type = 'scsl_sermon'
	   AND p.post_status = 'publish'
	   AND pm.meta_key = '_scsl_speaker_id'
	   AND pm.meta_value = %d",
	$scsl_post_id
) );

$scsl_sermon_label  = get_option( 'scsl_label_sermon',  '' ) ?: __( 'Sermon',  'seedcast-sermon-library' );
$scsl_sermons_label = get_option( 'scsl_label_sermons', '' ) ?: __( 'Sermons', 'seedcast-sermon-library' );
$scsl_count_label   = $scsl_count ? sprintf( '%d %s', $scsl_count, $scsl_count === 1 ? $scsl_sermon_label : $scsl_sermons_label ) : '';
?>
<article class="scsl-speaker-card" id="speaker-<?php echo esc_attr( $scsl_post_id ); ?>">
	<a href="<?php echo esc_url( get_permalink( $scsl_post_id ) ); ?>"
	   class="scsl-series-card__stretched-link"
	   tabindex="0"
	   aria-label="<?php echo esc_attr( get_the_title( $scsl_post_id ) ); ?>">
		<span class="sc-screen-reader-text"><?php echo esc_html( get_the_title( $scsl_post_id ) ); ?></span>
	</a>

	<div class="scsl-speaker-card__image">
		<?php if ( has_post_thumbnail( $scsl_post_id ) ) : ?>
			<?php echo wp_kses_post( get_the_post_thumbnail( $scsl_post_id, 'scsl_speaker_card' ) ); ?>
		<?php else : ?>
			<div class="scsl-speaker-card__placeholder">
				<span><?php echo esc_html( mb_strtoupper( mb_substr( get_the_title( $scsl_post_id ), 0, 2 ) ) ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<div class="scsl-speaker-card__body">
		<h3 class="scsl-speaker-card__name"><?php echo esc_html( get_the_title( $scsl_post_id ) ); ?></h3>
		<?php if ( $scsl_title ) : ?>
			<p class="scsl-speaker-card__title"><?php echo esc_html( $scsl_title ); ?></p>
		<?php endif; ?>
		<?php if ( $scsl_count_label ) : ?>
			<span class="scsl-meta-count"><?php echo esc_html( $scsl_count_label ); ?></span>
		<?php endif; ?>
	</div>
</article>
