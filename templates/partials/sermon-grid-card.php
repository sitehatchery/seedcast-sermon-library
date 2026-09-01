<?php
/**
 * A sermon as a card for a grid or a slider.
 *
 * Deliberately built on the series card's classes rather than its own. Those
 * carry the gold hover, the shadow and the placeholder that every other card
 * on the site already has, and a second set would be a second thing to keep
 * in step with whatever theme a church picks.
 *
 * The speaker photo belongs on the list card, where there is room for a face
 * beside the words. In a grid the artwork is what says which sermon this is.
 *
 * @package SeedcastSermonLibrary
 *
 * @var \WP_Post $post    Sermon.
 * @var string   $tab     Tab to open on arrival, if any.
 * @var string   $heading Title to show, if the sermon's own is not wanted.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$scsl_post_id = (int) $post->ID;
$scsl_title   = isset( $heading ) && '' !== $heading ? $heading : get_the_title( $scsl_post_id );

$scsl_link = get_permalink( $scsl_post_id );

if ( ! empty( $tab ) ) $scsl_link .= '#' . $tab;

$scsl_series_id = absint( get_post_meta( $scsl_post_id, '_scsl_series_id', true ) );
/*
 * An article is summarised by itself, not by the sermon.
 *
 * The description belongs to the sermon and says what was preached that
 * Sunday. Under a list of articles that is the wrong promise: every card
 * introduces the service rather than the piece somebody is about to read, and
 * several articles from one series read as though they were the same thing.
 *
 * The article's own heading is taken out first. It is already the card's
 * title, so leaving it in would open every excerpt by repeating the line
 * directly above it.
 */
if ( 'article' === ( $tab ?? '' ) ) {
	$scsl_body = (string) get_post_meta( $scsl_post_id, '_scsl_article_body', true );
	$scsl_body = (string) preg_replace( '#<h[1-4][^>]*>.*?</h[1-4]>#is', ' ', $scsl_body );

	$scsl_desc = trim( wp_strip_all_tags( strip_shortcodes( $scsl_body ) ) );
} else {
	$scsl_desc = '';
}

// Falls back to the sermon's description rather than showing nothing, for an
// article that is still only a heading.
if ( '' === $scsl_desc ) {
	$scsl_desc = trim( wp_strip_all_tags( (string) get_post_meta( $scsl_post_id, '_scsl_content_description', true ) ) );
}
$scsl_focus     = trim( (string) get_post_meta( $scsl_post_id, '_scsl_focus_passage', true ) );

$scsl_video = trim( (string) get_post_meta( $scsl_post_id, '_scsl_video_url', true ) );
$scsl_audio = trim( (string) get_post_meta( $scsl_post_id, '_scsl_audio_url', true ) );
?>
<article class="scsl-series-card scsl-sermon-card">
	<a href="<?php echo esc_url( $scsl_link ); ?>"
	   class="scsl-series-card__stretched-link"
	   tabindex="0"
	   aria-label="<?php echo esc_attr( $scsl_title ); ?>">
		<span class="sc-screen-reader-text"><?php echo esc_html( $scsl_title ); ?></span>
	</a>

	<div class="scsl-series-card__image">
		<?php
		// The sermon's own image, then its series artwork, then initials. The
		// site-wide default is applied by the same filter everything else
		// uses, so a church that set one gets it without this knowing.
		if ( has_post_thumbnail( $scsl_post_id ) ) {
			echo wp_kses_post( get_the_post_thumbnail( $scsl_post_id, 'scsl_series_card' ) );
		} elseif ( $scsl_series_id && has_post_thumbnail( $scsl_series_id ) ) {
			echo wp_kses_post( get_the_post_thumbnail( $scsl_series_id, 'scsl_series_card' ) );
		} else {
			printf(
				'<div class="scsl-series-card__placeholder"><span>%s</span></div>',
				esc_html( mb_strtoupper( mb_substr( $scsl_title, 0, 2 ) ) )
			);
		}
		?>
	</div>

	<div class="scsl-series-card__body">
		<h3 class="scsl-series-card__title"><?php echo esc_html( $scsl_title ); ?></h3>

		<div class="scsl-series-card__meta">
			<span><?php echo esc_html( get_the_date( '', $scsl_post_id ) ); ?></span>
			<?php if ( $scsl_focus ) : ?>
				<span class="scsl-scripture-chip"><?php echo esc_html( $scsl_focus ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $scsl_desc ) : ?>
			<p class="scsl-series-card__excerpt"><?php echo esc_html( wp_trim_words( $scsl_desc, 20 ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $tab ) ) : ?>
			<div class="scsl-sermon-card__actions">
				<a class="scsl-btn scsl-btn--ghost scsl-btn--sm" href="<?php echo esc_url( $scsl_link ); ?>">
					<?php esc_html_e( 'Read', 'seedcast-sermon-library' ); ?>
				</a>
			</div>
		<?php elseif ( $scsl_video || $scsl_audio ) : ?>
			<div class="scsl-sermon-card__actions">
				<?php if ( $scsl_video ) : ?>
					<a class="scsl-btn scsl-btn--ghost scsl-btn--sm" rel="nofollow"
					   href="<?php echo esc_url( add_query_arg( 'scsl_play', 'video', get_permalink( $scsl_post_id ) ) ); ?>">
						<?php esc_html_e( 'Watch', 'seedcast-sermon-library' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $scsl_audio ) : ?>
					<a class="scsl-btn scsl-btn--ghost scsl-btn--sm" rel="nofollow"
					   href="<?php echo esc_url( add_query_arg( 'scsl_play', 'audio', get_permalink( $scsl_post_id ) ) ); ?>">
						<?php esc_html_e( 'Listen', 'seedcast-sermon-library' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</article>
