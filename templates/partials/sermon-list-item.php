<?php
/**
 * Partial: Sermon List Item
 * Variables: $post (WP_Post)
 *
 * Layout: [thumbnail] | [meta / title / speaker / desc / passages] | [actions]
 * Override by copying to: your-theme/seedcast-sermon-library/partials/sermon-list-item.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$scsl_post_id    = $post->ID;
$scsl_rec_date   = get_post_meta( $scsl_post_id, '_scsl_recorded_date',       true );
$scsl_speaker_id = (int) get_post_meta( $scsl_post_id, '_scsl_speaker_id',    true );
$scsl_series_id  = (int) get_post_meta( $scsl_post_id, '_scsl_series_id',     true );
$scsl_desc       = get_post_meta( $scsl_post_id, '_scsl_content_description', true );
$scsl_focus      = get_post_meta( $scsl_post_id, '_scsl_focus_passage',       true );
$scsl_passages   = \SeedcastSermonLibrary\Import\FieldMap::uses( '_scsl_other_passages' )
	? get_post_meta( $scsl_post_id, '_scsl_other_passages', true )
	: [];
$scsl_video_url  = get_post_meta( $scsl_post_id, '_scsl_video_url',           true );
$scsl_audio_url  = get_post_meta( $scsl_post_id, '_scsl_audio_url',           true );
if ( ! is_array( $scsl_passages ) ) $scsl_passages = [];
$scsl_title     = get_the_title( $scsl_post_id );
$scsl_permalink = get_permalink( $scsl_post_id );
?>

<article class="sc-list-item" id="sc-list-item-<?php echo esc_attr( $scsl_post_id ); ?>">

	<?php if ( has_post_thumbnail( $scsl_post_id ) ) : ?>
	<a href="<?php echo esc_url( $scsl_permalink ); ?>"
	   class="sc-list-item__thumb"
	   tabindex="-1"
	   aria-hidden="true">
		<?php echo wp_kses_post( get_the_post_thumbnail( $scsl_post_id, 'medium', [ 'alt' => '' ] ) ); ?>
	</a>
	<?php else : ?>
	<div class="sc-list-item__thumb sc-list-item__thumb--empty" aria-hidden="true"></div>
	<?php endif; ?>

	<div class="sc-list-item__body">

		<div class="sc-list-item__meta">
			<?php if ( $scsl_series_id ) : ?>
				<a href="<?php echo esc_url( get_permalink( $scsl_series_id ) ); ?>"
				   class="sc-list-item__series">
					<?php
					/* translators: %s is the series title */
					printf( esc_html__( 'Series: %s', 'seedcast-sermon-library' ), esc_html( get_the_title( $scsl_series_id ) ) );
					?>
				</a>
			<?php endif; ?>
			<?php if ( $scsl_rec_date ) : ?>
				<time class="sc-list-item__date" datetime="<?php echo esc_attr( $scsl_rec_date ); ?>">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $scsl_rec_date ) ) ); ?>
				</time>
			<?php endif; ?>
		</div>

		<h3 class="sc-list-item__title">
			<a href="<?php echo esc_url( $scsl_permalink ); ?>">
				<?php echo esc_html( $scsl_title ); ?>
			</a>
		</h3>

		<?php if ( $scsl_speaker_id ) :
			$scsl_avatar_id  = (int) get_post_meta( $scsl_speaker_id, '_scsl_speaker_photo_id', true );
			$scsl_avatar_url = $scsl_avatar_id
				? wp_get_attachment_image_url( $scsl_avatar_id, 'thumbnail' )
				: get_the_post_thumbnail_url( $scsl_speaker_id, 'thumbnail' );
		?>
		<div class="sc-list-item__speaker">
			<?php if ( $scsl_avatar_url ) : ?>
				<img src="<?php echo esc_url( $scsl_avatar_url ); ?>"
					 alt="<?php echo esc_attr( get_the_title( $scsl_speaker_id ) ); ?>"
					 class="sc-list-item__avatar"
					 width="22" height="22" />
			<?php endif; ?>
			<a href="<?php echo esc_url( get_permalink( $scsl_speaker_id ) ); ?>">
				<?php echo esc_html( get_the_title( $scsl_speaker_id ) ); ?>
			</a>
		</div>
		<?php endif; ?>

		<?php if ( $scsl_desc ) : ?>
			<p class="sc-list-item__desc"><?php echo esc_html( wp_trim_words( $scsl_desc, 25 ) ); ?></p>
		<?php endif; ?>

		<?php if ( $scsl_focus || $scsl_passages ) : ?>
		<div class="sc-list-item__scripture"
			 aria-label="<?php esc_attr_e( 'Scripture references', 'seedcast-sermon-library' ); ?>">
			<?php if ( $scsl_focus ) : ?>
				<span class="sc-list-item__passage sc-list-item__passage--focus">
					<?php echo esc_html( $scsl_focus ); ?>
				</span>
			<?php endif; ?>
			<?php foreach ( array_slice( $scsl_passages, 0, 2 ) as $scsl_p ) : ?>
				<span class="sc-list-item__passage"><?php echo esc_html( $scsl_p ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

	</div>

	<div class="sc-list-item__actions">
		<?php if ( $scsl_video_url || $scsl_audio_url ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'scsl_play', $scsl_video_url ? 'video' : 'audio', $scsl_permalink ) ); ?>"
			   rel="nofollow"
			   class="scsl-btn scsl-btn--ghost scsl-btn--sm"
			   aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s is the sermon title */
					__( 'Listen to %s', 'seedcast-sermon-library' ), $scsl_title
				) ); ?>">
				<?php echo $scsl_video_url ? esc_html__( 'Watch', 'seedcast-sermon-library' ) : esc_html__( 'Listen', 'seedcast-sermon-library' ); ?>
			</a>
		<?php else : ?>
			<a href="<?php echo esc_url( $scsl_permalink ); ?>"
			   class="scsl-btn scsl-btn--ghost scsl-btn--sm"
			   aria-label="<?php echo esc_attr( sprintf(
					/* translators: %s is the sermon title */
					__( 'View %s', 'seedcast-sermon-library' ), $scsl_title
				) ); ?>">
				<?php esc_html_e( 'View →', 'seedcast-sermon-library' ); ?>
			</a>
		<?php endif; ?>
	</div>

</article>
