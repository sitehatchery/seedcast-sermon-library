<?php
/**
 * Sermon meta box callbacks and save logic.
 *
 * Handles all meta boxes displayed on the Edit Sermon screen:
 * Source & Media, Sermon Details, Scripture, Podcast Details,
 * Transcript, Content, Sermon Notes, More, and Save.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\PDF\PDFGenerator;
use SeedcastSermonLibrary\Scripture\ScriptureParser;

/**
 * Provides all meta box render callbacks and save handling for sl_sermon posts.
 *
 * Intentionally a single class covering all sermon meta fields so that
 * the shared private helpers (save_fields, sync_scripture_taxonomy,
 * render_passage_picker) are not split across multiple files.
 */
class SermonMeta {

	// ── SERMON: Source & Media ──────────────────────────────────────────────

	public function sermon_source_cb( \WP_Post $post ): void {
		wp_nonce_field( 'scsl_sermon_meta', 'scsl_sermon_nonce' );
		$video_url   = get_post_meta( $post->ID, '_scsl_video_url',      true );
		$audio_url   = get_post_meta( $post->ID, '_scsl_audio_url',      true );
		$short_url   = get_post_meta( $post->ID, '_scsl_short_url',      true );
		$video_start = get_post_meta( $post->ID, '_scsl_video_start',    true );
		$ext_raw     = get_post_meta( $post->ID, '_scsl_external_links', true );
		$ext_links  = $ext_raw ? json_decode( $ext_raw, true ) : [];
		if ( ! is_array( $ext_links ) ) $ext_links = [];

		// Enqueue media uploader
		wp_enqueue_media();

		$platforms = [
			'spotify'    => 'Listen on Spotify',
			'apple'      => 'Listen on Apple Podcasts',
			'youtube'    => 'Watch on YouTube',
			'vimeo'      => 'Watch on Vimeo',
			'sermon'     => 'Listen on Sermon Audio',
			'soundcloud' => 'Listen on SoundCloud',
			'other'      => 'External Link',
		];
		?>
		<table class="scsl-meta-table">
			<tr>
				<th><label for="scsl_video_url"><?php esc_html_e( 'Video', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<div class="scsl-media-field">
						<input type="url" id="scsl_video_url" name="scsl_video_url"
							   value="<?php echo esc_attr( $video_url ); ?>"
							   class="large-text scsl-media-url"
							   placeholder="<?php esc_attr_e( 'https://youtube.com/watch?v=... or upload an MP4', 'seedcast-sermon-library' ); ?>" />
						<button type="button"
								class="button scsl-upload-media"
								data-target="scsl_video_url"
								data-title="<?php esc_attr_e( 'Select or Upload Video', 'seedcast-sermon-library' ); ?>"
								data-type="video"
								data-button="<?php esc_attr_e( 'Use this video', 'seedcast-sermon-library' ); ?>">
							<?php esc_html_e( '↑ Upload / Select', 'seedcast-sermon-library' ); ?>
						</button>
						<?php if ( $video_url ) : ?>
						<button type="button" class="button scsl-clear-media" data-target="scsl_video_url">
							<?php esc_html_e( 'Clear', 'seedcast-sermon-library' ); ?>
						</button>
						<?php endif; ?>
					</div>
					<p class="description">
						<?php esc_html_e( 'Paste a YouTube or Vimeo URL, or upload an MP4 directly to your Media Library.', 'seedcast-sermon-library' ); ?>
					</p>
					<?php if ( $video_url && ! filter_var( $video_url, FILTER_VALIDATE_URL ) === false ) :
						// Show inline preview for self-hosted files
						if ( strpos( $video_url, 'youtube' ) === false && strpos( $video_url, 'vimeo' ) === false ) : ?>
						<video src="<?php echo esc_url( $video_url ); ?>" controls style="max-width:100%;max-height:120px;margin-top:.5rem;border-radius:4px;"></video>
					<?php endif; endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_video_start"><?php esc_html_e( 'Sermon Start Time', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<input type="text" id="scsl_video_start" name="scsl_video_start"
						   value="<?php echo esc_attr( $video_start ); ?>"
						   class="small-text"
						   placeholder="0:00" />
					<p class="description">
						<?php esc_html_e( 'Skip to where the sermon starts in the video, e.g. 12:34. Useful for full-service recordings.', 'seedcast-sermon-library' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_audio_url"><?php esc_html_e( 'Audio', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<div class="scsl-media-field">
						<input type="url" id="scsl_audio_url" name="scsl_audio_url"
							   value="<?php echo esc_attr( $audio_url ); ?>"
							   class="large-text scsl-media-url"
							   placeholder="<?php esc_attr_e( 'https://example.com/sermon.mp3', 'seedcast-sermon-library' ); ?>" />
						<button type="button"
								class="button scsl-upload-media"
								data-target="scsl_audio_url"
								data-title="<?php esc_attr_e( 'Select or Upload Audio', 'seedcast-sermon-library' ); ?>"
								data-type="audio"
								data-button="<?php esc_attr_e( 'Use this audio', 'seedcast-sermon-library' ); ?>">
							<?php esc_html_e( '↑ Upload / Select', 'seedcast-sermon-library' ); ?>
						</button>
						<?php if ( $audio_url ) : ?>
						<button type="button" class="button scsl-clear-media" data-target="scsl_audio_url">
							<?php esc_html_e( 'Clear', 'seedcast-sermon-library' ); ?>
						</button>
						<?php endif; ?>
					</div>
					<p class="description">
						<?php esc_html_e( 'Upload an MP3 to your Media Library, or paste a direct audio file URL.', 'seedcast-sermon-library' ); ?>
					</p>
					<?php if ( $audio_url && strpos( $audio_url, 'spotify' ) === false && strpos( $audio_url, 'podcast' ) === false ) : ?>
					<audio src="<?php echo esc_url( $audio_url ); ?>" controls preload="none" style="max-width:100%;margin-top:.5rem;"></audio>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_short_url"><?php esc_html_e( 'Short Clip', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<div class="scsl-media-field">
						<input type="url" id="scsl_short_url" name="scsl_short_url"
							   value="<?php echo esc_attr( $short_url ); ?>"
							   class="large-text scsl-media-url"
							   placeholder="<?php esc_attr_e( 'https://youtube.com/shorts/... or Instagram Reel / TikTok URL', 'seedcast-sermon-library' ); ?>" />
						<?php if ( $short_url ) : ?>
						<button type="button" class="button scsl-clear-media" data-target="scsl_short_url">
							<?php esc_html_e( 'Clear', 'seedcast-sermon-library' ); ?>
						</button>
						<?php endif; ?>
					</div>
					<p class="description">
						<?php esc_html_e( 'YouTube Short, Instagram Reel, or TikTok clip URL.', 'seedcast-sermon-library' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<!-- External streaming / hosting links -->
		<div style="margin-top:1.25rem;">
			<label style="font-weight:600;display:block;margin-bottom:.5rem;">
				<?php esc_html_e( 'External Platform Links', 'seedcast-sermon-library' ); ?>
			</label>
			<p class="description" style="margin-bottom:.75rem;">
				<?php esc_html_e( 'Add links to streaming platforms (Spotify, Apple Podcasts, etc.). These appear as buttons on the sermon page.', 'seedcast-sermon-library' ); ?>
			</p>
			<div id="scsl-ext-links-wrap">
				<?php foreach ( $ext_links as $i => $link ) : ?>
				<div class="scsl-ext-link-row">
					<select name="scsl_ext_links[<?php echo esc_attr( $i ); ?>][platform]">
						<?php foreach ( $platforms as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $link['platform'] ?? '', $val ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="scsl_ext_links[<?php echo esc_attr( $i ); ?>][label]"
						   value="<?php echo esc_attr( $link['label'] ?? '' ); ?>"
						   placeholder="<?php esc_attr_e( 'Button label', 'seedcast-sermon-library' ); ?>"
						   class="regular-text" />
					<input type="url" name="scsl_ext_links[<?php echo esc_attr( $i ); ?>][url]"
						   value="<?php echo esc_attr( $link['url'] ?? '' ); ?>"
						   placeholder="https://..." class="regular-text" />
					<button type="button" class="button scsl-remove-ext-link"><?php esc_html_e( 'Remove', 'seedcast-sermon-library' ); ?></button>
				</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button scsl-add-ext-link" style="margin-top:6px;" data-platforms='<?php echo esc_attr( wp_json_encode( $platforms ) ); ?>'>
				+ <?php esc_html_e( 'Add Platform Link', 'seedcast-sermon-library' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Unlisted, in the Publish box beside Update.
	 *
	 * Sits with visibility and status because it is the same kind of choice:
	 * whether people can find this sermon. The description is not optional
	 * here, since unlisted and private are confused constantly and guessing
	 * wrong means a sermon nobody can reach.
	 *
	 * @param \WP_Post $post The post being edited.
	 * @return void
	 */
	public function submitbox_unlisted( $post ): void {
		if ( ! $post instanceof \WP_Post || 'scsl_sermon' !== $post->post_type ) return;

		$unlisted = '1' === (string) get_post_meta( $post->ID, '_scsl_unlisted', true );
		?>
		<div class="misc-pub-section scsl-unlisted-section">
			<?php wp_nonce_field( 'scsl_unlisted_save', 'scsl_unlisted_nonce' ); ?>
			<label>
				<?php
				/*
				 * A zero is posted first. An unticked box sends nothing, and
				 * the save skips what is absent, so without this the setting
				 * could be turned on and never off.
				 */
				?>
				<input type="hidden" name="scsl_unlisted" value="0" />
				<input type="checkbox" name="scsl_unlisted" value="1" <?php checked( $unlisted ); ?> />
				<strong><?php esc_html_e( 'Unlisted', 'seedcast-sermon-library' ); ?></strong>
			</label>
			<p class="description" style="margin:.4em 0 0;">
				<?php esc_html_e( 'Keeps this sermon out of the sermon lists. It stays published: the page keeps working, existing links keep working, search engines keep it, and it remains on the full sermon index.', 'seedcast-sermon-library' ); ?>
			</p>
		</div>
		<?php
	}

	public function sermon_details_cb( \WP_Post $post ): void {
		$series_id   = get_post_meta( $post->ID, '_scsl_series_id',      true );
		$speaker_id  = get_post_meta( $post->ID, '_scsl_speaker_id',     true );
		$rec_date    = get_post_meta( $post->ID, '_scsl_recorded_date',  true );

		$series   = $this->get_cached_posts( 'scsl_series' );
		$speakers = $this->get_cached_posts( 'scsl_speaker' );
		?>
		<table class="scsl-meta-table">
			<tr>
				<th><label for="scsl_series_id"><?php esc_html_e( 'Series', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<select id="scsl_series_id" name="scsl_series_id">
						<option value=""><?php esc_html_e( 'Select Series', 'seedcast-sermon-library' ); ?></option>
						<?php foreach ( $series as $s ) : ?>
							<option value="<?php echo esc_attr( $s->ID ); ?>" <?php selected( $series_id, $s->ID ); ?>><?php
								echo esc_html( $s->post_title );
								if ( 'publish' !== $s->post_status ) {
									echo ' ' . esc_html( sprintf( '(%s)', get_post_status_object( $s->post_status )->label ?? $s->post_status ) );
								}
							?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_speaker_id"><?php esc_html_e( 'Speaker', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<select id="scsl_speaker_id" name="scsl_speaker_id">
						<option value=""><?php esc_html_e( 'Select Speaker', 'seedcast-sermon-library' ); ?></option>
						<?php foreach ( $speakers as $sp ) : ?>
							<option value="<?php echo esc_attr( $sp->ID ); ?>" <?php selected( $speaker_id, $sp->ID ); ?>><?php
								echo esc_html( $sp->post_title );
								if ( 'publish' !== $sp->post_status ) {
									echo ' ' . esc_html( sprintf( '(%s)', get_post_status_object( $sp->post_status )->label ?? $sp->post_status ) );
								}
							?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_recorded_date"><?php esc_html_e( 'Recorded Date', 'seedcast-sermon-library' ); ?></label></th>
				<td><input type="date" id="scsl_recorded_date" name="scsl_recorded_date" value="<?php echo esc_attr( $rec_date ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	// ── SERMON: Scripture ───────────────────────────────────────────────────

	public function sermon_scripture_cb( \WP_Post $post ): void {
		$focus    = get_post_meta( $post->ID, '_scsl_focus_passage',  true );
		$passages = get_post_meta( $post->ID, '_scsl_other_passages', true );
		if ( ! is_array( $passages ) ) $passages = [];

		$books = ScriptureParser::all_books();
		sort( $books );
		$books_json = wp_json_encode( $books );
		?>
		<p class="description" style="margin-bottom:1rem;">
			<?php esc_html_e( 'Select Book, Chapter, and Verse range. This ensures Bible.com links work correctly.', 'seedcast-sermon-library' ); ?>
		</p>

		<div style="margin-bottom:1.25rem;">
			<label style="font-weight:600;display:block;margin-bottom:.5rem;">
				<?php esc_html_e( 'Focus Passage', 'seedcast-sermon-library' ); ?>
				<span style="font-weight:400;color:#646970;">: <?php esc_html_e( 'primary scripture', 'seedcast-sermon-library' ); ?></span>
			</label>
			<?php $this->render_passage_picker( 'scsl_focus_passage', $focus, $books ); ?>
		</div>

		<div>
			<label style="font-weight:600;display:block;margin-bottom:.5rem;">
				<?php esc_html_e( 'Additional Passages', 'seedcast-sermon-library' ); ?>
			</label>
			<div id="scsl-passages-wrap">
				<?php foreach ( $passages as $p ) :
					if ( ! $p ) continue; ?>
					<div class="scsl-passage-row scsl-passage-row--extra">
						<?php $this->render_passage_picker( 'scsl_other_passages[]', $p, $books ); ?>
						<button type="button" class="button scsl-remove-passage"><?php esc_html_e( 'Remove', 'seedcast-sermon-library' ); ?></button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button scsl-add-passage" style="margin-top:6px;"
					data-books="<?php echo esc_attr( $books_json ); ?>">
				+ <?php esc_html_e( 'Add Passage', 'seedcast-sermon-library' ); ?>
			</button>
		</div>
		<input type="hidden" id="scsl-books-json" value="<?php echo esc_attr( $books_json ); ?>" />
		<?php
	}

	/**
	 * Render a structured passage picker (Book + Chapter + optional verse range).
	 * Outputs a hidden field with the formatted reference, e.g. "Luke 6:37-45"
	 */
	private function render_passage_picker( string $field_name, string $value, array $books ): void {
		// Parse existing value into parts
		$book    = '';
		$chapter = '';
		$v_start = '';
		$c_end   = '';
		$v_end   = '';

		if ( $value ) {
			// Extract book
			$book = ScriptureParser::extract_book( $value );
			if ( $book ) {
				/*
				 * A passage can run into the next chapter.
				 *
				 * Preachers work through a paragraph, and paragraphs do not
				 * stop where chapters do: "1:5-2:14" is an ordinary thing to
				 * preach and could not be written here before, so the end
				 * chapter was dropped the moment anybody saved.
				 */
				if ( preg_match( '/(\d+):(\d+)\s*[-\x{2013}]\s*(\d+):(\d+)/u', $value, $m ) ) {
					$chapter = $m[1];
					$v_start = $m[2];
					$c_end   = $m[3];
					$v_end   = $m[4];
				} elseif ( preg_match( '/(\d+):(\d+)(?:\s*[-\x{2013}]\s*(\d+))?/u', $value, $m ) ) {
					$chapter = $m[1];
					$v_start = $m[2];
					$v_end   = $m[3] ?? '';
				} elseif ( preg_match( '/(\d+)$/', trim( substr( $value, strlen( $book ) ) ), $m ) ) {
					$chapter = $m[1];
				}
			}
		}

		$uid = 'sp-' . uniqid();
		?>
		<div class="scsl-passage-picker" data-field="<?php echo esc_attr( $field_name ); ?>">
			<select class="scsl-pp-book" aria-label="<?php esc_attr_e( 'Book', 'seedcast-sermon-library' ); ?>">
				<option value=""><?php esc_html_e( 'Book', 'seedcast-sermon-library' ); ?></option>
				<?php foreach ( $books as $b ) : ?>
					<option value="<?php echo esc_attr( $b ); ?>" <?php selected( $book, $b ); ?>>
						<?php echo esc_html( $b ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<input type="number" class="scsl-pp-chapter" min="1" max="150"
				   value="<?php echo esc_attr( $chapter ); ?>"
				   placeholder="<?php esc_attr_e( 'Ch', 'seedcast-sermon-library' ); ?>"
				   aria-label="<?php esc_attr_e( 'Chapter', 'seedcast-sermon-library' ); ?>"
				   style="width:60px;" />
			<span class="scsl-pp-colon">:</span>
			<input type="number" class="scsl-pp-verse-start" min="1" max="176"
				   value="<?php echo esc_attr( $v_start ); ?>"
				   placeholder="<?php esc_attr_e( 'v', 'seedcast-sermon-library' ); ?>"
				   aria-label="<?php esc_attr_e( 'Start verse', 'seedcast-sermon-library' ); ?>"
				   style="width:55px;" />
			<span class="scsl-pp-dash">-</span>
			<?php
			/*
			 * The end chapter, left blank for a passage inside one chapter.
			 *
			 * Blank is the normal case and reads as "same chapter", so it is
			 * not asked for unless the passage actually crosses one.
			 */
			?>
			<?php
			/*
			 * Kept out of the way until it is needed.
			 *
			 * Nearly every passage sits inside one chapter, and a permanently
			 * empty box between two numbers reads as something forgotten
			 * rather than something optional. Shown only when a passage
			 * already crosses a chapter, or when somebody asks for it.
			 */
			$scsl_crosses = '' !== $c_end;
			?>
			<button type="button" class="button-link scsl-pp-more"
					<?php echo $scsl_crosses ? 'hidden' : ''; ?>
					aria-label="<?php esc_attr_e( 'Add an end chapter, for a passage that crosses one', 'seedcast-sermon-library' ); ?>">
				<?php esc_html_e( 'Ch', 'seedcast-sermon-library' ); ?> &raquo;
			</button>
			<input type="number" class="scsl-pp-chapter-end" min="1" max="150"
				   value="<?php echo esc_attr( $c_end ); ?>"
				   <?php echo $scsl_crosses ? '' : 'hidden'; ?>
				   placeholder="<?php esc_attr_e( 'Ch', 'seedcast-sermon-library' ); ?>"
				   aria-label="<?php esc_attr_e( 'End chapter', 'seedcast-sermon-library' ); ?>"
				   style="width:60px;" />
			<span class="scsl-pp-colon-end" <?php echo $scsl_crosses ? '' : 'hidden'; ?>>:</span>
			<input type="number" class="scsl-pp-verse-end" min="1" max="176"
				   value="<?php echo esc_attr( $v_end ); ?>"
				   placeholder="<?php esc_attr_e( 'v', 'seedcast-sermon-library' ); ?>"
				   aria-label="<?php esc_attr_e( 'End verse (optional)', 'seedcast-sermon-library' ); ?>"
				   style="width:55px;" />
			<span class="scsl-pp-preview"><?php echo esc_html( $value ?: '-' ); ?></span>
			<input type="hidden" class="scsl-pp-value" name="<?php echo esc_attr( $field_name ); ?>"
				   value="<?php echo esc_attr( $value ); ?>" />
		</div>
		<?php
	}

	// ── SERMON: Podcast Details ───────────────────────────────────────────

	public function sermon_podcast_cb( \WP_Post $post ): void {
		wp_enqueue_media();
		$audio_url     = get_post_meta( $post->ID, '_scsl_audio_url',     true );
		$podcast_image = get_post_meta( $post->ID, '_scsl_podcast_image', true );
		$exclude       = get_post_meta( $post->ID, '_scsl_podcast_exclude', true );

		if ( ! $audio_url ) {
			?>
			<p class="description" style="color:#646970;">
				<?php esc_html_e( 'No audio file set. Add an Audio URL in Source & Media above to include this sermon in your audio feed.', 'seedcast-sermon-library' ); ?>
			</p>
			<?php
			return;
		}
		?>
		<p class="description" style="margin-bottom:1rem;">
			<?php esc_html_e( 'This sermon will appear automatically in your audio feed. The fields below are optional.', 'seedcast-sermon-library' ); ?>
			<a href="<?php echo esc_url( get_feed_link( 'podcast' ) ); ?>" target="_blank" style="margin-left:6px;">
				<?php esc_html_e( 'Preview feed ↗', 'seedcast-sermon-library' ); ?>
			</a>
		</p>
		<table class="form-table" style="margin:0;">
			<tr>
				<th style="width:160px;"><label for="scsl_podcast_image"><?php esc_html_e( 'Episode Image', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
						<input type="url" id="scsl_podcast_image" name="scsl_podcast_image"
							   value="<?php echo esc_attr( $podcast_image ); ?>"
							   placeholder="https://..." class="regular-text" />
						<button type="button" class="button scsl-upload-podcast-image">
							<?php esc_html_e( '↑ Upload', 'seedcast-sermon-library' ); ?>
						</button>
					</div>
					<?php if ( $podcast_image ) : ?>
						<img src="<?php echo esc_url( $podcast_image ); ?>" alt=""
							 style="max-width:80px;margin-top:6px;border-radius:4px;display:block;" />
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Optional. Overrides the series image for this episode. Square, at least 1400×1400px.', 'seedcast-sermon-library' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="scsl_podcast_exclude"><?php esc_html_e( 'Exclude from Feed', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" id="scsl_podcast_exclude" name="scsl_podcast_exclude" value="yes"
							   <?php checked( $exclude, 'yes' ); ?> />
						<?php esc_html_e( 'Keep this sermon out of the audio feed', 'seedcast-sermon-library' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	// ── SERMON: Transcripts ─────────────────────────────────────────────────

	// ── SERMON: Generated Content ───────────────────────────────────────────

	public function sermon_content_cb( \WP_Post $post ): void {
		wp_enqueue_media(); // needed for More tab's editor media button
		$article_title = get_post_meta( $post->ID, '_scsl_article_title',       true );
		$article_body  = get_post_meta( $post->ID, '_scsl_article_body',        true );
		$bible_study   = get_post_meta( $post->ID, '_scsl_bible_study',         true );
		$description   = get_post_meta( $post->ID, '_scsl_content_description', true );
		$transcript    = get_post_meta( $post->ID, '_scsl_transcript_clean',    true );

		$art_pdf     = PDFGenerator::download_button( $post->ID, 'article',    '⬇ PDF' );
		$bs_pdf      = PDFGenerator::download_button( $post->ID, 'bible_study','⬇ PDF' );
		$trans_pdf   = PDFGenerator::download_button( $post->ID, 'transcript', '⬇ PDF' );
		?>
		<?php
		/**
		 * A note for a section this church has said it does not use.
		 *
		 * The tab stays where it is rather than disappearing, because a tab
		 * that vanishes leaves somebody wondering where their writing went.
		 * The panel says plainly why it is greyed and where to change it.
		 */
		$scsl_off_note = static function ( $key ) {
			if ( \SeedcastSermonLibrary\Import\FieldMap::uses( $key ) ) return '';

			return '<div class="scsl-section-off"><p>'
				. esc_html__( 'Your church has this switched off, so it does not appear on your sermon pages.', 'seedcast-sermon-library' )
				. ' <a href="' . esc_url( \Seedcast\Core\Admin\Settings::url( SettingsPage::SECTION ) ) . '">'
				. esc_html__( 'Change which content you use', 'seedcast-sermon-library' )
				. '</a></p></div>';
		};
		?>
		<div class="scsl-tab-wrap">
			<nav class="scsl-tabs">
				<button type="button" class="scsl-tab active" data-target="scsl-content-desc"      ><?php esc_html_e( 'Description', 'seedcast-sermon-library' ); ?></button>
				<button type="button" class="scsl-tab"        data-target="scsl-content-article"   ><?php esc_html_e( 'Article',     'seedcast-sermon-library' ); ?></button>
				<button type="button" class="scsl-tab"        data-target="scsl-content-study"     ><?php esc_html_e( 'Bible Study', 'seedcast-sermon-library' ); ?></button>
				<button type="button" class="scsl-tab"        data-target="scsl-content-transcript"><?php esc_html_e( 'Transcript',  'seedcast-sermon-library' ); ?></button>
				<button type="button" class="scsl-tab"        data-target="scsl-content-more"      ><?php esc_html_e( 'More',        'seedcast-sermon-library' ); ?></button>
			</nav>

			<div id="scsl-content-desc" class="sc-tab-panel active">
				<?php echo $scsl_off_note( '_scsl_content_description' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?>
				<textarea name="scsl_content_description" rows="5" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Short description for archives, cards, and SEO.', 'seedcast-sermon-library' ); ?></p>
			</div>

			<div id="scsl-content-article" class="sc-tab-panel">
				<?php echo $scsl_off_note( '_scsl_article_body' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?>
				<p>
					<label style="font-weight:600;"><?php esc_html_e( 'Article Title', 'seedcast-sermon-library' ); ?></label>
					<input type="text" name="scsl_article_title" value="<?php echo esc_attr( $article_title ); ?>" class="large-text" style="margin-top:4px;" />
				</p>
				<?php
				wp_editor( $article_body, 'scsl_article_body', [
					'textarea_name' => 'scsl_article_body',
					'media_buttons' => true,
					'textarea_rows' => 16,
					'teeny'         => false,
				] );
				?>
				<p style="margin-top:8px;"><?php echo $art_pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDFGenerator::download_button() returns escaped HTML ?></p>

				<div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #dcdcde;">
					<label style="font-weight:600;display:block;margin-bottom:.5rem;">
						<?php esc_html_e( 'Featured Image', 'seedcast-sermon-library' ); ?>
					</label>
					<p class="description" style="margin-bottom:.75rem;">
						<?php esc_html_e( 'Used as the sermon thumbnail, Google search image, and social media preview. Set this using the Featured Image box in the right sidebar, or upload here.', 'seedcast-sermon-library' ); ?>
					</p>
					<?php
					$thumbnail_id = get_post_thumbnail_id( $post->ID );
					if ( $thumbnail_id ) :
						$thumb_url = wp_get_attachment_image_url( $thumbnail_id, 'medium' );
					?>
					<div class="scsl-featured-image-preview">
						<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" style="max-width:200px;border-radius:4px;border:1px solid #dcdcde;" />
						<p style="margin:.5rem 0 0;font-size:12px;color:#646970;">
							<?php esc_html_e( 'Current featured image', 'seedcast-sermon-library' ); ?>
							<a href="#" onclick="document.querySelector('#set-post-thumbnail').click();return false;">
								<?php esc_html_e( 'Change', 'seedcast-sermon-library' ); ?>
							</a>
						</p>
					</div>
					<?php else : ?>
					<p>
						<a href="#" class="button" onclick="document.querySelector('#set-post-thumbnail').click();return false;">
							<?php esc_html_e( '+ Set Featured Image', 'seedcast-sermon-library' ); ?>
						</a>
						<span style="margin-left:.5rem;font-size:12px;color:#646970;"><?php esc_html_e( 'Used for Google, social media, and sermon cards', 'seedcast-sermon-library' ); ?></span>
					</p>
					<?php endif; ?>
				</div>
			</div>

			<div id="scsl-content-study" class="sc-tab-panel">
				<?php echo $scsl_off_note( '_scsl_bible_study' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?>
				<?php
				wp_editor( $bible_study, 'scsl_bible_study', [
					'textarea_name' => 'scsl_bible_study',
					'media_buttons' => true,
					'textarea_rows' => 16,
					'teeny'         => false,
				] );
				?>
				<p style="margin-top:8px;"><?php echo $bs_pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output escaped by PDFGenerator::download_button() ?></p>
			</div>

			<div id="scsl-content-transcript" class="sc-tab-panel">
				<?php echo $scsl_off_note( '_scsl_transcript_clean' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?>
				<p class="description" style="margin-bottom:.75rem;">
					<?php esc_html_e( 'Paste or type the sermon transcript. It appears in the Transcript tab on the sermon page.', 'seedcast-sermon-library' ); ?>
				</p>
				<textarea id="scsl_transcript_clean" name="scsl_transcript_clean" rows="16" class="large-text scsl-textarea"><?php echo esc_textarea( $transcript ); ?></textarea>
				<p style="margin-top:8px;"><?php echo wp_kses_post( $trans_pdf ); ?></p>
			</div>

			<div id="scsl-content-more" class="sc-tab-panel">
				<?php echo $scsl_off_note( '_scsl_resources' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?>
				<p class="description" style="margin-bottom:.75rem;">
					<?php esc_html_e( 'Additional content shown in the "More" tab on the sermon page: links, resources, discussion questions, etc.', 'seedcast-sermon-library' ); ?>
				</p>
				<?php
				$scsl_more_label   = get_post_meta( $post->ID, '_scsl_more_label', true );
				$scsl_global_label = get_option( 'scsl_tab_more_label', __( 'More', 'seedcast-sermon-library' ) );
				?>
				<p style="margin-bottom:1rem;">
					<label style="font-weight:600;display:block;margin-bottom:4px;">
						<?php esc_html_e( 'Tab Label (optional)', 'seedcast-sermon-library' ); ?>
					</label>
					<input type="text" name="scsl_more_label"
						   value="<?php echo esc_attr( $scsl_more_label ); ?>"
						   placeholder="<?php echo esc_attr( $scsl_global_label ); ?>"
						   class="regular-text" />
					<span class="description">&nbsp;<?php esc_html_e( 'Leave blank to use the global default.', 'seedcast-sermon-library' ); ?></span>
				</p>
				<?php
				$more_content = get_post_meta( $post->ID, '_scsl_resources', true );
				wp_editor( $more_content, 'scsl_resources', [
					'textarea_name' => 'scsl_resources',
					'textarea_rows' => 10,
					'media_buttons' => true,
				] );
				?>
			</div>

		</div><!-- /.scsl-tab-wrap -->
		<?php
	}

	// ── SERMON: Sermon Notes ────────────────────────────────────────────────

	public function sermon_notes_cb( \WP_Post $post ): void {
		wp_enqueue_media();
		$notes_intro = get_post_meta( $post->ID, '_scsl_notes_intro', true );
		$notes_files = get_post_meta( $post->ID, '_scsl_notes_files', true );
		if ( ! is_array( $notes_files ) ) $notes_files = [];
		?>
		<p class="description" style="margin-bottom:1rem;">
			<?php esc_html_e( 'Attach sermon notes, bulletins, study handouts, or slides. Each appears as a download button on the sermon page.', 'seedcast-sermon-library' ); ?>
		</p>

		<div style="margin-bottom:1.25rem;">
			<label style="font-weight:600;display:block;margin-bottom:.5rem;"><?php esc_html_e( 'Intro Text (optional)', 'seedcast-sermon-library' ); ?></label>
			<textarea name="scsl_notes_intro" rows="3" class="large-text"><?php echo esc_textarea( $notes_intro ); ?></textarea>
		</div>

		<div>
			<label style="font-weight:600;display:block;margin-bottom:.5rem;"><?php esc_html_e( 'Attachments', 'seedcast-sermon-library' ); ?></label>
			<div id="scsl-notes-files-wrap">
				<?php foreach ( $notes_files as $i => $file ) :
					if ( empty( $file['url'] ) ) continue;
					$scsl_type = $file['type'] ?? 'upload'; ?>
				<div class="scsl-notes-file-row" style="margin-bottom:10px;padding:10px 12px;background:#f9f9f9;border:1px solid #dcdcde;border-radius:4px;">
					<div style="margin-bottom:6px;">
						<input type="text"
							   name="scsl_notes_files[<?php echo esc_attr( $i ); ?>][label]"
							   value="<?php echo esc_attr( $file['label'] ?? '' ); ?>"
							   placeholder="<?php esc_attr_e( 'Label, e.g. Sermon Notes', 'seedcast-sermon-library' ); ?>"
							   class="regular-text" style="width:100%;max-width:400px;" />
					</div>
					<div style="display:flex;align-items:center;gap:16px;margin-bottom:8px;">
						<label style="cursor:pointer;font-weight:normal;">
							<input type="radio" name="scsl_notes_files[<?php echo esc_attr( $i ); ?>][type]"
								   value="upload" class="scsl-notes-type"
								   <?php checked( $scsl_type, 'upload' ); ?> />
							<?php esc_html_e( 'Upload File', 'seedcast-sermon-library' ); ?>
						</label>
						<label style="cursor:pointer;font-weight:normal;">
							<input type="radio" name="scsl_notes_files[<?php echo esc_attr( $i ); ?>][type]"
								   value="url" class="scsl-notes-type"
								   <?php checked( $scsl_type, 'url' ); ?> />
							<?php esc_html_e( 'URL / Link', 'seedcast-sermon-library' ); ?>
						</label>
						<button type="button" class="button-link scsl-remove-notes-file"
								style="color:#b32d2e;margin-left:auto;">
							✕ <?php esc_html_e( 'Remove', 'seedcast-sermon-library' ); ?>
						</button>
					</div>
					<input type="hidden" name="scsl_notes_files[<?php echo esc_attr( $i ); ?>][url]"
						   value="<?php echo esc_attr( $file['url'] ?? '' ); ?>"
						   class="scsl-notes-url-value" />
					<div class="scsl-notes-upload-area"<?php if ( 'url' === $scsl_type ) echo ' style="display:none;"'; ?>>
						<?php if ( ! empty( $file['url'] ) && $scsl_type === 'upload' ) : ?>
						<a href="<?php echo esc_url( $file['url'] ); ?>" target="_blank"
						   class="scsl-notes-file-link" style="font-size:12px;display:inline-block;margin-bottom:4px;">
							<?php echo esc_html( basename( $file['url'] ) ); ?>
						</a><br>
						<?php endif; ?>
						<button type="button" class="button scsl-upload-notes-file">
							↑ <?php esc_html_e( 'Choose File', 'seedcast-sermon-library' ); ?>
						</button>
					</div>
					<div class="scsl-notes-url-area"<?php if ( 'upload' === $scsl_type ) echo ' style="display:none;"'; ?>>
						<input type="url" class="regular-text scsl-notes-url-input"
							   value="<?php echo esc_attr( $scsl_type === 'url' ? ( $file['url'] ?? '' ) : '' ); ?>"
							   placeholder="https://..."
							   style="width:100%;max-width:400px;" />
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button scsl-add-notes-file" style="margin-top:8px;">
				+ <?php esc_html_e( 'Add Attachment', 'seedcast-sermon-library' ); ?>
			</button>
		</div>
		<?php
	}

	// ── SERMON: More (was Resources) ────────────────────────────────────────

	public function sermon_resources_cb( \WP_Post $post ): void {
		$resources  = get_post_meta( $post->ID, '_scsl_resources', true );
		$more_label = get_post_meta( $post->ID, '_scsl_more_label', true );
		$global_label = get_option( 'scsl_tab_more_label', __( 'More', 'seedcast-sermon-library' ) );
		?>
		<p>
			<label style="font-weight:600;display:block;margin-bottom:4px;">
				<?php esc_html_e( 'Tab Label', 'seedcast-sermon-library' ); ?>
				<span style="font-weight:400;color:#646970;">: <?php esc_html_e( 'overrides the global setting for this sermon', 'seedcast-sermon-library' ); ?></span>
			</label>
			<input type="text" name="scsl_more_label"
				   value="<?php echo esc_attr( $more_label ); ?>"
				   class="regular-text"
				   placeholder="<?php echo esc_attr( $global_label ); ?>" />
		</p>
		<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'Links, notes, books, or any additional resources for this sermon.', 'seedcast-sermon-library' ); ?></p>
		<?php
		wp_editor( $resources, 'scsl_resources', [
			'textarea_name' => 'scsl_resources',
			'media_buttons' => true,
			'textarea_rows' => 8,
			'teeny'         => false,
		] );
	}

	// ── SERMON: Social Assets ───────────────────────────────────────────────

	public function sermon_social_cb( \WP_Post $post ): void {
		$facebook  = get_post_meta( $post->ID, '_scsl_social_facebook',  true );
		$instagram = get_post_meta( $post->ID, '_scsl_social_instagram', true );
		$twitter   = get_post_meta( $post->ID, '_scsl_social_twitter',   true );
		$youtube   = get_post_meta( $post->ID, '_scsl_social_youtube',   true );
		?>
		<p>
			<label for="scsl_social_facebook"><strong><?php esc_html_e( 'Facebook', 'seedcast-sermon-library' ); ?></strong></label>
			<textarea id="scsl_social_facebook" name="scsl_social_facebook" rows="3" class="large-text"><?php echo esc_textarea( $facebook ); ?></textarea>
		</p>
		<p>
			<label for="scsl_social_instagram"><strong><?php esc_html_e( 'Instagram', 'seedcast-sermon-library' ); ?></strong></label>
			<textarea id="scsl_social_instagram" name="scsl_social_instagram" rows="3" class="large-text"><?php echo esc_textarea( $instagram ); ?></textarea>
		</p>
		<p>
			<label for="scsl_social_twitter"><strong><?php esc_html_e( 'X / Twitter', 'seedcast-sermon-library' ); ?></strong></label>
			<textarea id="scsl_social_twitter" name="scsl_social_twitter" rows="3" class="large-text"><?php echo esc_textarea( $twitter ); ?></textarea>
		</p>
		<p>
			<label for="scsl_social_youtube"><strong><?php esc_html_e( 'YouTube Description', 'seedcast-sermon-library' ); ?></strong></label>
			<textarea id="scsl_social_youtube" name="scsl_social_youtube" rows="3" class="large-text"><?php echo esc_textarea( $youtube ); ?></textarea>
		</p>
		<?php
	}

	// ── SERMON: Asset Status ────────────────────────────────────────────────

	public function sermon_status_cb( \WP_Post $post ): void {
		$assets = [
			'transcript_clean' => __( 'Clean Transcript', 'seedcast-sermon-library' ),
			'article'          => __( 'Article',          'seedcast-sermon-library' ),
			'bible_study'      => __( 'Bible Study',      'seedcast-sermon-library' ),
			'description'      => __( 'Description',      'seedcast-sermon-library' ),
			'featured_image'   => __( 'Featured Image',   'seedcast-sermon-library' ),
			'social_facebook'  => __( 'Facebook',         'seedcast-sermon-library' ),
			'social_instagram' => __( 'Instagram',        'seedcast-sermon-library' ),
			'social_twitter'   => __( 'X / Twitter',      'seedcast-sermon-library' ),
			'social_youtube'   => __( 'YouTube Desc',     'seedcast-sermon-library' ),
		];
		?>
		<table class="scsl-status-table">
		<?php foreach ( $assets as $key => $label ) :
			$status = $this->get_asset_status( $post->ID, $key );
			?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td><span class="scsl-status-badge scsl-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span></td>
			</tr>
		<?php endforeach; ?>
		</table>
		<?php
	}

	// ── SAVE ────────────────────────────────────────────────────────────────

	/**
	 * Save all sermon meta fields.
	 *
	 * Called by MetaBoxes::save() after nonce and capability checks pass.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public function save( int $post_id ): void {
		if ( ! isset( $_POST['scsl_sermon_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_sermon_nonce'] ) ), 'scsl_sermon_meta' )
		) return;

		// Nonce verified above: capture POST data once for safe passing to save_fields()
		$post_data = wp_unslash( $_POST ); // Nonce verified above; all values sanitized before use

		$text_fields = [
			'_scsl_video_url'           => 'url',
			'_scsl_audio_url'           => 'url',
			'_scsl_short_url'           => 'url',
			'_scsl_video_start'         => 'text',
			'_scsl_series_id'           => 'int',
			'_scsl_speaker_id'          => 'int',
			'_scsl_recorded_date'       => 'text',
			'_scsl_episode_number'      => 'int',
			'_scsl_transcript_clean'    => 'textarea',
			'_scsl_article_title'       => 'text',
			'_scsl_article_body'        => 'html',
			'_scsl_bible_study'         => 'html',
			'_scsl_content_description' => 'textarea',
			'_scsl_notes_intro'         => 'textarea',
			'_scsl_resources'           => 'html',
			'_scsl_more_label'          => 'text',
			'_scsl_social_facebook'     => 'textarea',
			'_scsl_social_instagram'    => 'textarea',
			'_scsl_social_twitter'      => 'textarea',
			'_scsl_social_youtube'      => 'textarea',
			'_scsl_focus_passage'       => 'text',
			// Podcast Details
			'_scsl_podcast_audio_length' => 'text',
			'_scsl_podcast_audio_size'   => 'int',
			'_scsl_podcast_image'        => 'url',
			'_scsl_podcast_include_series' => 'text',
			'_scsl_podcast_exclude'       => 'text',
			'_scsl_unlisted'              => 'text',
		];

		$this->save_fields( $post_id, $text_fields, $post_data );

		// Sermon notes file attachments
		$notes_files = [];
		if ( isset( $post_data['scsl_notes_files'] ) && is_array( $post_data['scsl_notes_files'] ) ) {
			$raw_files = array_values( (array) $post_data['scsl_notes_files'] );
			foreach ( $raw_files as $file ) {
				if ( ! is_array( $file ) ) continue;
				$type  = sanitize_key( $file['type'] ?? 'upload' );
				$url   = esc_url_raw( $file['url'] ?? '' );
				$label = sanitize_text_field( $file['label'] ?? '' );
				if ( $url ) {
					$notes_files[] = [
						'url'   => $url,
						'label' => $label ?: basename( $url ),
						'type'  => $type,
					];
				}
			}
		}
		update_post_meta( $post_id, '_scsl_notes_files', $notes_files );

		// External platform links
		$ext_links = [];
		if ( isset( $post_data['scsl_ext_links'] ) && is_array( $post_data['scsl_ext_links'] ) ) {
			$raw_links = array_values( (array) $post_data['scsl_ext_links'] );
			foreach ( $raw_links as $link ) {
				if ( ! is_array( $link ) ) continue;
				$platform = sanitize_key( $link['platform'] ?? '' );
				$label    = sanitize_text_field( $link['label']    ?? '' );
				$url      = esc_url_raw( $link['url']              ?? '' );
				if ( $url ) {
					$ext_links[] = [ 'platform' => $platform, 'label' => $label, 'url' => $url ];
				}
			}
		}
		update_post_meta( $post_id, '_scsl_external_links', wp_json_encode( $ext_links ) );

		// Repeatable passages
		$passages = [];
		if ( isset( $post_data['scsl_other_passages'] ) && is_array( $post_data['scsl_other_passages'] ) ) {
			foreach ( array_map( 'sanitize_text_field', (array) $post_data['scsl_other_passages'] ) as $p ) {
				$clean = sanitize_text_field( wp_unslash( $p ) );
				if ( $clean ) $passages[] = $clean;
			}
		}
		update_post_meta( $post_id, '_scsl_other_passages', $passages );

		// Focus passage: saved explicitly since field name differs from meta key
		$focus_for_sync = isset( $post_data['scsl_focus_passage'] )
			? sanitize_text_field( wp_unslash( $post_data['scsl_focus_passage'] ) )
			: '';
		update_post_meta( $post_id, '_scsl_focus_passage', $focus_for_sync );

		// Sync scripture taxonomy
		$this->sync_scripture_taxonomy( $post_id, $focus_for_sync, $passages );
	}

	/**
	 * Put a sermon into the scripture terms for the passages it uses.
	 *
	 * Public and static because the sermon screen is not the only place
	 * passages are written. AI generation adds references it found, and both
	 * importers set them wholesale, and every one of those wrote the meta and
	 * stopped there. The result was a sermon showing its passages on the page,
	 * each one linking to a passage page that said it had no sermons, because
	 * the words were stored and the sermon was never filed under them.
	 *
	 * Called with no arguments it reads what is on the sermon, so any writer
	 * can hand off to it after saving without knowing what it saved.
	 *
	 * @param int    $post_id  Sermon ID.
	 * @param string $focus    Focus passage, or empty to read from the sermon.
	 * @param array  $passages Other passages, or empty to read from the sermon.
	 * @return void
	 */
	public static function sync_scripture( int $post_id, string $focus = '', array $passages = [] ): void {
		( new self() )->sync_scripture_taxonomy( $post_id, $focus, $passages );
	}

	private function sync_scripture_taxonomy( int $post_id, string $focus = '', array $passages = [] ): void {
		// Fall back to DB if not provided (e.g. called from outside save context)
		if ( $focus === '' && empty( $passages ) ) {
			$focus    = (string) get_post_meta( $post_id, '_scsl_focus_passage',  true );
			$passages = (array)  get_post_meta( $post_id, '_scsl_other_passages', true );
		}

		$all = array_filter( array_merge( [ $focus ], $passages ) );
		if ( ! $all ) return;

		$term_ids = [];
		foreach ( $all as $ref ) {
			$ref = sanitize_text_field( $ref );
			if ( ! $ref ) continue;

			// An explicit slug, because the derived one loses the colon and
			// runs chapter into verse.
			$result = wp_insert_term( $ref, 'scsl_scripture', [ 'slug' => \SeedcastSermonLibrary\Slug::from( $ref ) ] );

			if ( is_wp_error( $result ) ) {
				// Term already exists: fetch its ID
				$existing = get_term_by( 'name', $ref, 'scsl_scripture' );
				if ( $existing ) {
					$term_ids[] = (int) $existing->term_id;
				}
			} else {
				$term_ids[] = (int) $result['term_id'];
			}
		}

		if ( $term_ids ) {
			wp_set_object_terms( $post_id, $term_ids, 'scsl_scripture' );
		}
	}

	private function save_fields( int $post_id, array $fields, array $post_data ): void {
		// Prime the meta cache in a single query before we start comparing values.
		// All subsequent get_post_meta() calls for this post are cache hits.
		$existing_meta = get_post_meta( $post_id );

		foreach ( $fields as $meta_key => $type ) {
			$post_key = ltrim( $meta_key, '_' );

			// Skip fields not present in the POST (allows partial saves)
			if ( ! isset( $post_data[ $post_key ] ) ) continue;

			$raw = wp_unslash( $post_data[ $post_key ] );

			switch ( $type ) {
				case 'url':
					$value = esc_url_raw( $raw );
					break;
				case 'email':
					$value = sanitize_email( $raw );
					break;
				case 'int':
					$value = absint( $raw );
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $raw );
					break;
				case 'html':
					// Strip AI-generated data attributes before sanitizing: they bloat the content
					$raw   = preg_replace( '/ data-(?:start|end|is-[a-z-]+)="[^"]*"/', '', $raw );
					$value = wp_kses_post( $raw );
					break;
				default:
					$value = sanitize_text_field( $raw );
					break;
			}

			// Skip the DB write if the value hasn't changed.
			// get_post_meta() returns an array of values (with outer array from cache);
			// the [0] is the stored serialized value.
			$current = $existing_meta[ $meta_key ][0] ?? '';
			if ( (string) $current === (string) $value ) continue;

			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	private function get_asset_status( int $post_id, string $key ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}scsl_asset_status WHERE sermon_id = %d AND asset_key = %s",
			$post_id, $key
		) );
		return $status ? $status : 'pending';
	}

	/**
	 * Get posts with transient caching to avoid repeated queries on the edit screen.
	 * Cache is cleared by MetaBoxes::clear_post_cache() when a post of that type is saved.
	 *
	 * @param string $post_type Post type slug.
	 * @return \WP_Post[] Array of post objects.
	 */
	private function get_cached_posts( string $post_type ): array {
		$cache_key = 'scsl_cached_' . $post_type;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$posts = get_posts( [
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			// Drafts belong in the list. A series can be written up before it
			// goes live, and an imported speaker starts as a draft until
			// someone adds a photo. Listing only published posts meant a
			// sermon that was correctly linked to one still showed "Select
			// Series", which reads as data loss when it is not.
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
		] );
		set_transient( $cache_key, $posts, HOUR_IN_SECONDS );
		return $posts;
	}

}
