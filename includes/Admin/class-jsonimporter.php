<?php
/**
 * Sermon Library JSON Importer
 *
 * Imports sermon data from a JSON file produced by the Sermon Library exporter.
 * Import order: topics → speakers → series → sermons (dependencies first).
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles JSON import from our own export format.
 */
class JsonImporter {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_post_scsl_json_import', [ $this, 'handle_import' ] );
	}

	/**
	 * Render the JSON import UI (called from SeriesEngineImporter::render()).
	 */
	public function render_panel(): void {
		?>
		<div class="scsl-import-section">
			<h2><?php esc_html_e( 'Import from Seedcast JSON Export', 'seedcast-sermon-library' ); ?></h2>
			<p>
				<?php esc_html_e( 'Upload a JSON file previously exported from Sermon Library. This will import topics, speakers, series, and sermons: skipping any that already exist (matched by slug).', 'seedcast-sermon-library' ); ?>
			</p>

			<div id="scsl-json-import-result" style="display:none;margin-bottom:1rem;padding:1rem;background:#f0f6fc;border-left:4px solid #2271b1;border-radius:3px;"></div>

			<form method="post"
				  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				  enctype="multipart/form-data"
				  id="scsl-json-import-form">

				<input type="hidden" name="action" value="scsl_json_import" />
				<?php wp_nonce_field( 'scsl_json_import_nonce', 'scsl_json_import_nonce' ); ?>

				<table class="form-table" style="max-width:600px;">
					<tr>
						<th scope="row">
							<label for="scsl_json_file">
								<?php esc_html_e( 'JSON File', 'seedcast-sermon-library' ); ?>
							</label>
						</th>
						<td>
							<input type="file"
								   id="scsl_json_file"
								   name="scsl_json_file"
								   accept=".json,application/json"
								   required />
							<p class="description">
								<?php esc_html_e( 'Select the .json file from your Sermon Library export.', 'seedcast-sermon-library' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Duplicate handling', 'seedcast-sermon-library' ); ?>
						</th>
						<td>
							<label>
								<input type="radio" name="scsl_duplicate" value="skip" checked />
								<?php esc_html_e( 'Skip existing (safe: matched by slug)', 'seedcast-sermon-library' ); ?>
							</label><br>
							<label>
								<input type="radio" name="scsl_duplicate" value="update" />
								<?php esc_html_e( 'Update existing (overwrites meta fields)', 'seedcast-sermon-library' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Images', 'seedcast-sermon-library' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" name="scsl_import_images" value="1" />
								<?php esc_html_e( 'Download and import featured images from source URLs', 'seedcast-sermon-library' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Requires the source site to be publicly accessible. May be slow for large libraries.', 'seedcast-sermon-library' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p>
					<?php submit_button( __( 'Start Import', 'seedcast-sermon-library' ), 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the JSON import form submission.
	 */
	public function handle_import(): void {
		if ( ! isset( $_POST['scsl_json_import_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['scsl_json_import_nonce'] ) ),
				'scsl_json_import_nonce'
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'seedcast-sermon-library' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'seedcast-sermon-library' ) );
		}

		// Validate upload
		if ( empty( $_FILES['scsl_json_file']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No file uploaded.', 'seedcast-sermon-library' ) );
		}

		$file     = $_FILES['scsl_json_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$tmp_path = $file['tmp_name'];

		if ( ! is_uploaded_file( $tmp_path ) ) {
			wp_die( esc_html__( 'Invalid file upload.', 'seedcast-sermon-library' ) );
		}

		// Read and decode JSON
		$raw = file_get_contents( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $raw ) {
			wp_die( esc_html__( 'Could not read the uploaded file.', 'seedcast-sermon-library' ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['format'] ) || $data['format'] !== 'seedcast-sermon-library' ) {
			wp_die( esc_html__( 'Invalid export file. Please upload a file exported from Sermon Library.', 'seedcast-sermon-library' ) );
		}

		$duplicate      = isset( $_POST['scsl_duplicate'] ) && sanitize_key( $_POST['scsl_duplicate'] ) === 'update' ? 'update' : 'skip';
		$import_images  = isset( $_POST['scsl_import_images'] ) && '1' === sanitize_key( $_POST['scsl_import_images'] );

		$result = $this->run_import( $data, $duplicate, $import_images );

		// Flush rewrite rules
		flush_rewrite_rules();

		// Redirect with results
		wp_safe_redirect( add_query_arg( [
			'page'              => 'seedcast-sermon-library-import',
			'json_import'       => '1',
			'topics_created'    => $result['topics']['created'],
			'speakers_created'  => $result['speakers']['created'],
			'series_created'    => $result['series']['created'],
			'sermons_created'   => $result['sermons']['created'],
			'sermons_updated'   => $result['sermons']['updated'],
			'sermons_skipped'   => $result['sermons']['skipped'],
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Run the full import and return result counts.
	 *
	 * @param array  $data          Decoded export JSON.
	 * @param string $duplicate     'skip' or 'update'.
	 * @param bool   $import_images Whether to download featured images.
	 * @return array<string, array<string, int>>
	 */
	private function run_import( array $data, string $duplicate, bool $import_images ): array {
		$result = [
			'topics'   => [ 'created' => 0, 'skipped' => 0 ],
			'speakers' => [ 'created' => 0, 'skipped' => 0 ],
			'series'   => [ 'created' => 0, 'skipped' => 0 ],
			'sermons'  => [ 'created' => 0, 'updated' => 0, 'skipped' => 0 ],
		];

		// ── 1. Topics ──────────────────────────────────────────────────────
		$topic_slug_map = []; // old slug → new term_id
		foreach ( $data['topics'] ?? [] as $topic ) {
			$name = sanitize_text_field( $topic['name'] ?? '' );
			$slug = sanitize_title( $topic['slug'] ?? $name );
			if ( ! $name ) continue;

			$existing = get_term_by( 'slug', $slug, 'scsl_topic' );
			if ( $existing ) {
				$topic_slug_map[ $slug ] = $existing->term_id;
				$result['topics']['skipped']++;
				continue;
			}

			$inserted = wp_insert_term( $name, 'scsl_topic', [
				'slug'        => $slug,
				'description' => sanitize_textarea_field( $topic['description'] ?? '' ),
			] );

			if ( ! is_wp_error( $inserted ) ) {
				$topic_slug_map[ $slug ] = $inserted['term_id'];
				$result['topics']['created']++;
			}
		}

		// ── 2. Speakers ────────────────────────────────────────────────────
		$speaker_title_map = []; // speaker title → new post_id
		foreach ( $data['speakers'] ?? [] as $sp ) {
			$title = sanitize_text_field( $sp['title'] ?? '' );
			$slug  = sanitize_title( $sp['slug'] ?? $title );
			if ( ! $title ) continue;

			$existing_id = $this->find_post_by_slug( $slug, 'scsl_speaker' );

			if ( $existing_id && $duplicate === 'skip' ) {
				$speaker_title_map[ $title ] = $existing_id;
				$result['speakers']['skipped']++;
				continue;
			}

			$post_data = [
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => wp_kses_post( $sp['bio'] ?? '' ),
				'post_status'  => sanitize_key( $sp['status'] ?? 'publish' ),
				'post_type'    => 'scsl_speaker',
			];

			if ( $existing_id ) {
				$post_data['ID'] = $existing_id;
				wp_update_post( $post_data );
				$post_id = $existing_id;
			} else {
				$post_id = wp_insert_post( $post_data );
				if ( is_wp_error( $post_id ) ) continue;
				$result['speakers']['created']++;
			}

			$speaker_title_map[ $title ] = $post_id;

			// Meta
			$meta_map = [
				'_scsl_speaker_title'    => sanitize_text_field( $sp['speaker_title'] ?? '' ),
				'_scsl_speaker_website'  => esc_url_raw( $sp['website']  ?? '' ),
				'_scsl_speaker_twitter'  => sanitize_text_field( $sp['twitter']  ?? '' ),
				'_scsl_speaker_facebook' => sanitize_text_field( $sp['facebook'] ?? '' ),
				'_scsl_speaker_instagram'=> sanitize_text_field( $sp['instagram']?? '' ),
			];
			foreach ( $meta_map as $key => $val ) {
				if ( $val ) update_post_meta( $post_id, $key, $val );
			}

			// Featured image
			if ( $import_images && ! empty( $sp['thumbnail_url'] ) ) {
				$this->maybe_sideload_image( esc_url_raw( $sp['thumbnail_url'] ), $post_id );
			}
		}

		// ── 3. Series ──────────────────────────────────────────────────────
		$series_title_map = []; // series title → new post_id
		foreach ( $data['series'] ?? [] as $s ) {
			$title = sanitize_text_field( $s['title'] ?? '' );
			$slug  = sanitize_title( $s['slug'] ?? $title );
			if ( ! $title ) continue;

			$existing_id = $this->find_post_by_slug( $slug, 'scsl_series' );

			if ( $existing_id && $duplicate === 'skip' ) {
				$series_title_map[ $title ] = $existing_id;
				$result['series']['skipped']++;
				continue;
			}

			$post_data = [
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => wp_kses_post( $s['description'] ?? '' ),
				'post_status'  => sanitize_key( $s['status'] ?? 'publish' ),
				'post_type'    => 'scsl_series',
				'menu_order'   => absint( $s['menu_order'] ?? 0 ),
			];

			if ( $existing_id ) {
				$post_data['ID'] = $existing_id;
				wp_update_post( $post_data );
				$post_id = $existing_id;
			} else {
				$post_id = wp_insert_post( $post_data );
				if ( is_wp_error( $post_id ) ) continue;
				$result['series']['created']++;
			}

			$series_title_map[ $title ] = $post_id;

			foreach ( [
				'_scsl_series_start_date' => sanitize_text_field( $s['start_date'] ?? '' ),
				'_scsl_series_end_date'   => sanitize_text_field( $s['end_date']   ?? '' ),
				'_scsl_series_type'       => sanitize_text_field( $s['series_type']?? '' ),
			] as $key => $val ) {
				if ( $val ) update_post_meta( $post_id, $key, $val );
			}

			if ( $import_images && ! empty( $s['thumbnail_url'] ) ) {
				$this->maybe_sideload_image( esc_url_raw( $s['thumbnail_url'] ), $post_id );
			}
		}

		// ── 4. Sermons ─────────────────────────────────────────────────────
		foreach ( $data['sermons'] ?? [] as $sermon ) {
			$title = sanitize_text_field( $sermon['title'] ?? '' );
			$slug  = sanitize_title( $sermon['slug'] ?? $title );
			if ( ! $title ) continue;

			$existing_id = $this->find_post_by_slug( $slug, 'scsl_sermon' );

			if ( $existing_id && $duplicate === 'skip' ) {
				$result['sermons']['skipped']++;
				continue;
			}

			$post_data = [
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_excerpt' => sanitize_textarea_field( $sermon['excerpt'] ?? '' ),
				'post_status'  => sanitize_key( $sermon['status'] ?? 'publish' ),
				'post_type'    => 'scsl_sermon',
			];

			if ( $existing_id ) {
				$post_data['ID'] = $existing_id;
				wp_update_post( $post_data );
				$post_id = $existing_id;
				$result['sermons']['updated']++;
			} else {
				$post_id = wp_insert_post( $post_data );
				if ( is_wp_error( $post_id ) ) continue;
				$result['sermons']['created']++;
			}

			// Resolve speaker and series IDs from titles
			$speaker_title = sanitize_text_field( $sermon['speaker_title'] ?? '' );
			$series_title  = sanitize_text_field( $sermon['series_title']  ?? '' );
			$speaker_id    = $speaker_title ? ( $speaker_title_map[ $speaker_title ] ?? 0 ) : 0;
			$series_id     = $series_title  ? ( $series_title_map[ $series_title ]  ?? 0 ) : 0;

			// Scalar meta
			$meta = [
				'_scsl_recorded_date'        => sanitize_text_field( $sermon['recorded_date'] ?? '' ),
				'_scsl_video_url'            => esc_url_raw( $sermon['video_url'] ?? '' ),
				'_scsl_audio_url'            => esc_url_raw( $sermon['audio_url'] ?? '' ),
				'_scsl_content_description'  => sanitize_textarea_field( $sermon['description'] ?? '' ),
				'_scsl_transcript_clean'     => sanitize_textarea_field( $sermon['transcript']  ?? '' ),
				'_scsl_article_title'        => sanitize_text_field( $sermon['article_title']  ?? '' ),
				'_scsl_article_body'         => wp_kses_post( $sermon['article_body']         ?? '' ),
				'_scsl_bible_study'          => wp_kses_post( $sermon['bible_study']          ?? '' ),
				'_scsl_focus_passage'        => sanitize_text_field( $sermon['focus_passage']   ?? '' ),
				'_scsl_resources'            => wp_kses_post( $sermon['resources'] ?? '' ),
				'_scsl_more_label'           => sanitize_text_field( $sermon['more_label'] ?? '' ),
				'_scsl_speaker_id'           => $speaker_id,
				'_scsl_series_id'            => $series_id,
				'_scsl_podcast_audio_length' => sanitize_text_field( $sermon['podcast_audio_length'] ?? '' ),
				'_scsl_podcast_audio_size'   => absint( $sermon['podcast_audio_size'] ?? 0 ),
				'_scsl_podcast_image'        => esc_url_raw( $sermon['podcast_image'] ?? '' ),
				'_scsl_podcast_include_series'=> sanitize_text_field( $sermon['podcast_include_series'] ?? '' ),
				'_scsl_podcast_exclude'      => sanitize_text_field( $sermon['podcast_exclude'] ?? '' ),
			];
			foreach ( $meta as $key => $val ) {
				update_post_meta( $post_id, $key, $val );
			}

			// Other passages (array)
			$other = array_map(
				'sanitize_text_field',
				(array) ( $sermon['other_passages'] ?? [] )
			);
			update_post_meta( $post_id, '_scsl_other_passages', array_filter( $other ) );

			// Imported passages need filing under their terms too, or the
			// passage pages come out empty for every sermon brought in.
			SermonMeta::sync_scripture( $post_id );

			// Notes files (array of arrays)
			$notes = [];
			foreach ( (array) ( $sermon['notes_files'] ?? [] ) as $nf ) {
				if ( ! is_array( $nf ) || empty( $nf['url'] ) ) continue;
				$notes[] = [
					'url'   => esc_url_raw( $nf['url'] ),
					'label' => sanitize_text_field( $nf['label'] ?? '' ),
					'type'  => sanitize_key( $nf['type'] ?? 'upload' ),
				];
			}
			update_post_meta( $post_id, '_scsl_notes_files', $notes );

			// External links (JSON encoded)
			$ext = [];
			foreach ( (array) ( $sermon['external_links'] ?? [] ) as $link ) {
				if ( ! is_array( $link ) || empty( $link['url'] ) ) continue;
				$ext[] = [
					'platform' => sanitize_key( $link['platform'] ?? 'other' ),
					'label'    => sanitize_text_field( $link['label'] ?? '' ),
					'url'      => esc_url_raw( $link['url'] ),
				];
			}
			update_post_meta( $post_id, '_scsl_external_links', wp_json_encode( $ext ) );

			// Topics
			$topic_ids = [];
			foreach ( (array) ( $sermon['topics'] ?? [] ) as $slug_str ) {
				$slug_str = sanitize_title( $slug_str );
				if ( isset( $topic_slug_map[ $slug_str ] ) ) {
					$topic_ids[] = $topic_slug_map[ $slug_str ];
				}
			}
			if ( $topic_ids ) {
				wp_set_object_terms( $post_id, $topic_ids, 'scsl_topic', false );
			}

			// Scripture taxonomy
			$scripture_terms = array_map( 'sanitize_text_field', (array) ( $sermon['scripture_terms'] ?? [] ) );
			if ( $scripture_terms ) {
				wp_set_object_terms( $post_id, $scripture_terms, 'scsl_scripture', false );
			}

			// Featured image
			if ( $import_images && ! empty( $sermon['thumbnail_url'] ) ) {
				$this->maybe_sideload_image( esc_url_raw( $sermon['thumbnail_url'] ), $post_id );
			}
		}

		return $result;
	}

	/**
	 * Find an existing post by slug and type.
	 *
	 * @param string $slug      Post slug to look for.
	 * @param string $post_type Custom post type.
	 * @return int Post ID or 0 if not found.
	 */
	private function find_post_by_slug( string $slug, string $post_type ): int {
		$posts = get_posts( [
			'name'           => $slug,
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Sideload a remote image and set it as the post's featured image.
	 * Skips if the post already has a featured image set.
	 *
	 * @param string $url     Remote image URL.
	 * @param int    $post_id Destination post ID.
	 */
	private function maybe_sideload_image( string $url, int $post_id ): void {
		if ( has_post_thumbnail( $post_id ) ) return;
		if ( ! $url ) return;

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $post_id, '', 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}
}
