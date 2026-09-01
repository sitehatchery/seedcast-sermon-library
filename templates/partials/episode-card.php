<?php
/**
 * Partial: Episode Card
 * Variables: $post (WP_Post), $scsl_featured (bool)
 *
 * Layout: [speaker photo] | [title + meta + desc] | [buttons]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$scsl_post_id    = $post->ID;
$scsl_rec_date   = get_post_meta( $scsl_post_id, '_scsl_recorded_date',       true );
$scsl_speaker_id = get_post_meta( $scsl_post_id, '_scsl_speaker_id',          true );
$scsl_series_id  = get_post_meta( $scsl_post_id, '_scsl_series_id',           true );
$scsl_desc       = get_post_meta( $scsl_post_id, '_scsl_content_description', true );
$scsl_focus      = get_post_meta( $scsl_post_id, '_scsl_focus_passage',       true );
$scsl_video_url  = get_post_meta( $scsl_post_id, '_scsl_video_url',           true );
$scsl_audio_url  = get_post_meta( $scsl_post_id, '_scsl_audio_url',           true );
$scsl_featured   = isset( $scsl_featured ) ? (bool) $scsl_featured : false;

/**
 * Where this card points.
 *
 * A listing of articles sends people to the sermon with the right tab named in
 * the address, so the same card can serve both without a second URL for the
 * same words.
 *
 * @param string $permalink The sermon's own address.
 * @param int    $post_id   Sermon ID.
 */
$scsl_permalink  = (string) apply_filters( 'scsl_card_permalink', get_permalink( $scsl_post_id ), $scsl_post_id );
/**
 * A listing about written content wants one button that says Read, not two
 * that offer to play something. Whatever is drawing the card decides.
 *
 * @var string $read_label Text for the single action, if there is to be one.
 */
$scsl_read_only  = ! empty( $read_label );

$scsl_watch_url  = ( ! $scsl_read_only && $scsl_video_url ) ? $scsl_permalink : '';
$scsl_listen_url = ( ! $scsl_read_only && $scsl_audio_url ) ? $scsl_permalink : '';

// Speaker photo with fallback chain: speaker → sermon thumbnail → series thumbnail
/**
 * Whether a face is the right picture here.
 *
 * On a list of sermons it is: the speaker is the thing being browsed. On a
 * list of articles it is not, because a row of faces says nothing about what
 * any of the pieces are about, so the sermon's own artwork comes first.
 *
 * @var bool $prefer_artwork Set by whatever is drawing this card.
 */
$scsl_prefer_artwork = ! empty( $prefer_artwork );

$scsl_has_speaker_photo = ! $scsl_prefer_artwork && $scsl_speaker_id && has_post_thumbnail( $scsl_speaker_id );
$scsl_has_sermon_thumb  = has_post_thumbnail( $scsl_post_id );
$scsl_has_series_thumb  = $scsl_series_id && has_post_thumbnail( $scsl_series_id );
$scsl_has_photo         = $scsl_has_speaker_photo || $scsl_has_sermon_thumb || $scsl_has_series_thumb;

// Determine the image URL and alt for the left column
$scsl_left_img_url = '';
$scsl_left_img_alt = '';
$scsl_left_img_class = 'scsl-episode-card__speaker-img';
$scsl_left_img_link  = '';
if ( $scsl_has_speaker_photo ) {
	$scsl_photo_id = get_post_meta( $scsl_speaker_id, '_scsl_speaker_photo_id', true );
	if ( $scsl_photo_id ) {
		$scsl_left_img_url = wp_get_attachment_image_url( (int) $scsl_photo_id, 'scsl_speaker_thumb' ) ?: wp_get_attachment_image_url( (int) $scsl_photo_id, 'thumbnail' );
	} else {
		$scsl_left_img_url = get_the_post_thumbnail_url( $scsl_speaker_id, 'scsl_speaker_thumb' ) ?: get_the_post_thumbnail_url( $scsl_speaker_id, 'thumbnail' );
	}
	$scsl_left_img_alt   = get_the_title( $scsl_speaker_id );
	$scsl_left_img_link  = get_permalink( $scsl_speaker_id );
} elseif ( $scsl_has_sermon_thumb ) {
	$scsl_left_img_url   = get_the_post_thumbnail_url( $scsl_post_id, 'thumbnail' );
	$scsl_left_img_alt   = '';
	$scsl_left_img_class = 'scsl-episode-card__speaker-img scsl-episode-card__speaker-img--thumb';
	$scsl_left_img_link  = $scsl_permalink;
} elseif ( $scsl_has_series_thumb ) {
	$scsl_left_img_url   = get_the_post_thumbnail_url( $scsl_series_id, 'thumbnail' );
	$scsl_left_img_alt   = '';
	$scsl_left_img_class = 'scsl-episode-card__speaker-img scsl-episode-card__speaker-img--thumb';
	$scsl_left_img_link  = $scsl_series_id ? get_permalink( $scsl_series_id ) : $scsl_permalink;
}
?>

<article class="scsl-episode-card <?php echo esc_attr( $scsl_featured ? 'scsl-episode-card--featured' : '' ); ?> <?php echo ! $scsl_has_photo ? 'scsl-episode-card--no-photo' : ''; ?>"
		 id="episode-<?php echo esc_attr( $scsl_post_id ); ?>">

	<!-- Left column: speaker photo / fallback image -->
	<?php if ( $scsl_has_photo && $scsl_left_img_url ) : ?>
	<div class="scsl-episode-card__speaker-photo">
		<a href="<?php echo esc_url( $scsl_left_img_link ); ?>"
		   tabindex="-1" aria-hidden="true">
			<img src="<?php echo esc_url( $scsl_left_img_url ); ?>"
				 alt="<?php echo esc_attr( $scsl_left_img_alt ); ?>"
				 class="<?php echo esc_attr( $scsl_left_img_class ); ?>"
				 width="56" height="56" />
		</a>
	</div>
	<?php endif; ?>

	<!-- Main content: middle column -->
	<div class="scsl-episode-card__body">
		<h3 class="scsl-episode-card__title">
			<a href="<?php echo esc_url( $scsl_permalink ); ?>">
				<?php echo esc_html( $post->post_title ); ?>
			</a>
		</h3>

		<div class="scsl-episode-card__meta">
			<?php if ( $scsl_speaker_id ) : ?>
				<span class="scsl-meta-speaker">
					<a href="<?php echo esc_url( get_permalink( $scsl_speaker_id ) ); ?>"
					   class="scsl-meta-speaker__link">
						<?php echo esc_html( get_the_title( $scsl_speaker_id ) ); ?>
					</a>
				</span>
				<span class="scsl-meta-sep" aria-hidden="true">·</span>
			<?php endif; ?>
			<?php if ( $scsl_rec_date ) : ?>
				<time class="scsl-meta-date" datetime="<?php echo esc_attr( $scsl_rec_date ); ?>">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $scsl_rec_date ) ) ); ?>
				</time>
			<?php endif; ?>
		</div>

		<?php if ( $scsl_desc ) : ?>
			<p class="scsl-episode-card__desc"><?php echo esc_html( wp_trim_words( $scsl_desc, 20 ) ); ?></p>
		<?php endif; ?>

		<?php if ( $scsl_focus ) : ?>
			<span class="sc-list-item__passage sc-list-item__passage--focus">
				<?php echo esc_html( $scsl_focus ); ?>
			</span>
		<?php endif; ?>
	</div>

	<!-- Actions: right column -->
	<div class="scsl-episode-card__actions">
		<?php if ( $scsl_watch_url ) : ?>
		<?php
		// translators: %s is the sermon title
		// translators: %s is the sermon title
		$scsl_aria_watch = sprintf( __( 'Watch %s', 'seedcast-sermon-library' ), $post->post_title );
		?>
		<a href="<?php echo esc_url( $scsl_watch_url ); ?>"
		   class="scsl-btn scsl-btn--ghost scsl-btn--sm"
		   aria-label="<?php echo esc_attr( $scsl_aria_watch ); ?>">
			<?php esc_html_e( 'Watch', 'seedcast-sermon-library' ); ?>
		</a>
		<?php endif; ?>
		<?php if ( $scsl_listen_url ) : ?>
		<?php
		// translators: %s is the sermon title
		// translators: %s is the sermon title
		$scsl_aria_listen = sprintf( __( 'Listen to %s', 'seedcast-sermon-library' ), $post->post_title );
		?>
		<a href="<?php echo esc_url( $scsl_listen_url ); ?>"
		   class="scsl-btn scsl-btn--ghost scsl-btn--sm"
		   aria-label="<?php echo esc_attr( $scsl_aria_listen ); ?>">
			<?php esc_html_e( 'Listen', 'seedcast-sermon-library' ); ?>
		</a>
		<?php endif; ?>
		<?php if ( ! $scsl_watch_url && ! $scsl_listen_url ) : ?>
		<?php
		// translators: %s is the sermon title
		// translators: %s is the sermon title
		$scsl_aria_view = sprintf( __( 'View %s', 'seedcast-sermon-library' ), $post->post_title );

		// A listing of written pieces says Read, because that is what the
		// button does. Everything else keeps the general word.
		$scsl_view_label = $scsl_read_only ? $read_label : __( 'View', 'seedcast-sermon-library' ) . ' &rarr;';
		?>
		<a href="<?php echo esc_url( $scsl_permalink ); ?>"
		   class="scsl-btn scsl-btn--ghost scsl-btn--sm"
		   aria-label="<?php echo esc_attr( $scsl_aria_view ); ?>">
			<?php echo esc_html( $scsl_view_label ); ?>
		</a>
		<?php endif; ?>
	</div>

</article>
