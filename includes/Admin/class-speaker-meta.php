<?php
/**
 * Speaker meta box callback.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Provides the meta box callback and save handling for sl_speaker posts.
 */
class SpeakerMeta {

	// ── SPEAKER ─────────────────────────────────────────────────────────────

	public function speaker_details_cb( \WP_Post $post ): void {
		wp_nonce_field( 'scsl_speaker_meta', 'scsl_speaker_nonce' );
		$title    = get_post_meta( $post->ID, '_scsl_speaker_title',   true );
		$email    = get_post_meta( $post->ID, '_scsl_speaker_email',   true );
		$phone    = get_post_meta( $post->ID, '_scsl_speaker_phone',   true );
		$website  = get_post_meta( $post->ID, '_scsl_speaker_website', true );
		$twitter  = get_post_meta( $post->ID, '_scsl_speaker_twitter', true );
		$facebook = get_post_meta( $post->ID, '_scsl_speaker_facebook',true );
		$instagram= get_post_meta( $post->ID, '_scsl_speaker_instagram',true );
		$wp_user  = get_post_meta( $post->ID, '_scsl_speaker_wp_user', true );
		$photo_id = (int) get_post_meta( $post->ID, '_scsl_speaker_photo_id', true );
		$photo_url= $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';

		$users = get_users( [ 'role__in' => [ 'administrator', 'editor', 'author', 'contributor' ] ] );
		?>
		<p class="description" style="margin-bottom:1rem;">
			<?php esc_html_e( 'The Featured Image is used for the speaker grid/slider card. The Headshot below is used for the small circular photo on sermon list cards.', 'seedcast-sermon-library' ); ?>
		</p>
		<table class="scsl-meta-table">
			<tr>
				<th><label><?php esc_html_e( 'Headshot', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<div class="scsl-img-upload-wrap">
						<div class="scsl-img-preview" style="width:80px;height:80px;border-radius:50%;overflow:hidden;border:2px solid var(--sl-color-border);background:#f0f0f0;margin-bottom:.5rem;">
							<?php if ( $photo_url ) : ?>
								<img id="scsl_speaker_photo_preview" src="<?php echo esc_url( $photo_url ); ?>" style="width:100%;height:100%;object-fit:cover;" />
							<?php else : ?>
								<img id="scsl_speaker_photo_preview" src="" style="width:100%;height:100%;object-fit:cover;display:none;" />
							<?php endif; ?>
						</div>
						<input type="hidden" id="scsl_speaker_photo_id" name="scsl_speaker_photo_id" value="<?php echo esc_attr( $photo_id ?: '' ); ?>" />
						<button type="button" class="button" id="scsl_speaker_photo_btn">
							<?php if ( $photo_url ) : ?>
							<?php esc_html_e( 'Change Headshot', 'seedcast-sermon-library' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Upload Headshot', 'seedcast-sermon-library' ); ?>
						<?php endif; ?>
						</button>
						<?php if ( $photo_url ) : ?>
							<button type="button" class="button scsl-remove-img" data-target="scsl_speaker_photo_id" data-preview="scsl_speaker_photo_preview" style="margin-left:4px;">
								<?php esc_html_e( 'Remove', 'seedcast-sermon-library' ); ?>
							</button>
						<?php endif; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Small circular headshot: shown next to sermon cards in list view. Square crop recommended.', 'seedcast-sermon-library' ); ?></p>
					<?php
					wp_localize_script( 'scsl-admin', 'scslSpeakerI18n', [
						'selectHeadshot' => __( 'Select Headshot',   'seedcast-sermon-library' ),
						'useThisPhoto'   => __( 'Use this photo',    'seedcast-sermon-library' ),
						'changeHeadshot' => __( 'Change Headshot',   'seedcast-sermon-library' ),
					] );
					?>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_speaker_title"><?php esc_html_e( 'Title / Role', 'seedcast-sermon-library' ); ?></label></th>
				<td><input type="text" id="scsl_speaker_title" name="scsl_speaker_title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Lead Pastor', 'seedcast-sermon-library' ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Contact', 'seedcast-sermon-library' ); ?></th>
				<td>
					<input type="email" name="scsl_speaker_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Email', 'seedcast-sermon-library' ); ?>" style="margin-bottom:6px;" /><br>
					<input type="text"  name="scsl_speaker_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Phone', 'seedcast-sermon-library' ); ?>" style="margin-bottom:6px;" /><br>
					<input type="url"   name="scsl_speaker_website" value="<?php echo esc_attr( $website ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Website URL', 'seedcast-sermon-library' ); ?>" />
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Social', 'seedcast-sermon-library' ); ?></th>
				<td>
					<div class="scsl-input-prefix-wrap" style="margin-bottom:6px;">
						<span class="scsl-input-prefix">@</span>
						<input type="text" name="scsl_speaker_twitter" value="<?php echo esc_attr( $twitter ); ?>" placeholder="<?php esc_attr_e( 'X / Twitter', 'seedcast-sermon-library' ); ?>" />
					</div>
					<div class="scsl-input-prefix-wrap" style="margin-bottom:6px;">
						<span class="scsl-input-prefix">@</span>
						<input type="text" name="scsl_speaker_facebook" value="<?php echo esc_attr( $facebook ); ?>" placeholder="<?php esc_attr_e( 'Facebook', 'seedcast-sermon-library' ); ?>" />
					</div>
					<div class="scsl-input-prefix-wrap">
						<span class="scsl-input-prefix">@</span>
						<input type="text" name="scsl_speaker_instagram" value="<?php echo esc_attr( $instagram ); ?>" placeholder="<?php esc_attr_e( 'Instagram', 'seedcast-sermon-library' ); ?>" />
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_speaker_wp_user"><?php esc_html_e( 'Blog Author', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<select id="scsl_speaker_wp_user" name="scsl_speaker_wp_user">
						<option value=""><?php esc_html_e( 'None', 'seedcast-sermon-library' ); ?></option>
						<?php foreach ( $users as $u ) : ?>
							<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $wp_user, $u->ID ); ?>>
								<?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Link this speaker to a WordPress user to cross-reference blog posts.', 'seedcast-sermon-library' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}


	public function save( int $post_id ): void {
		if ( ! isset( $_POST['scsl_speaker_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_speaker_nonce'] ) ), 'scsl_speaker_meta' )
		) return;

		$text_fields = [
			'_scsl_speaker_title'    => 'text',
			'_scsl_speaker_email'    => 'email',
			'_scsl_speaker_phone'    => 'text',
			'_scsl_speaker_website'  => 'url',
			'_scsl_speaker_twitter'  => 'url',
			'_scsl_speaker_facebook' => 'url',
			'_scsl_speaker_instagram'=> 'url',
			'_scsl_speaker_wp_user'  => 'int',
			'_scsl_speaker_photo_id' => 'int',
		];
		foreach ( $text_fields as $meta_key => $type ) {
			$post_key = ltrim( $meta_key, '_' );
			if ( ! isset( $_POST[ $post_key ] ) ) continue;
			$raw   = wp_unslash( $_POST[ $post_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below
			switch ( $type ) {
				case 'url':   $val = esc_url_raw( $raw ); break;
				case 'email': $val = sanitize_email( $raw ); break;
				case 'int':   $val = absint( $raw ); break;
				default:       $val = sanitize_text_field( $raw ); break;
			}
			update_post_meta( $post_id, $meta_key, $val );
		}
	}
}
