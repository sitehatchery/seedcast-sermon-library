<?php
/**
 * Sermon Library Exporter: exports all sermon data to a JSON file.
 *
 * The export format is versioned so the importer can handle future schema changes.
 * Exports: sermons, series, speakers, topics, and all related meta.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles the Export admin page and download action.
 */
class Exporter {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_post_scsl_export', [ $this, 'handle_export' ] );
	}

	/**
	 * Render the export page (called from the Import/Export submenu).
	 */
	public function render(): void {
		?>
		<div class="scsl-import-section">
			<h2><?php esc_html_e( 'Export', 'seedcast-sermon-library' ); ?></h2>
			<p><?php esc_html_e( 'Download all your sermon data as a JSON file. Use this to back up your content or migrate to another site.', 'seedcast-sermon-library' ); ?></p>

			<p><?php esc_html_e( 'The export includes:', 'seedcast-sermon-library' ); ?></p>
			<ul style="list-style:disc;padding-left:1.5rem;margin:.5rem 0 1rem;">
				<li><?php esc_html_e( 'Sermons: title, date, description, video, audio, transcript, notes, scripture, topics', 'seedcast-sermon-library' ); ?></li>
				<li><?php esc_html_e( 'Series: title, description, image URL, dates', 'seedcast-sermon-library' ); ?></li>
				<li><?php esc_html_e( 'Speakers: name, bio, photo', 'seedcast-sermon-library' ); ?></li>
				<li><?php esc_html_e( 'Topics: all taxonomy terms', 'seedcast-sermon-library' ); ?></li>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scsl_export" />
				<?php wp_nonce_field( 'scsl_export_nonce', 'scsl_export_nonce' ); ?>
				<?php submit_button( __( 'Download Export File', 'seedcast-sermon-library' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the export form submission: build JSON and stream as download.
	 */
	public function handle_export(): void {
		if ( ! isset( $_POST['scsl_export_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_export_nonce'] ) ), 'scsl_export_nonce' )
		) wp_die( esc_html__( 'Security check failed.', 'seedcast-sermon-library' ) );

		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'seedcast-sermon-library' ) );

		$data = [
			'format'    => 'seedcast-sermon-library',
			'version'   => '1.0',
			'exported'  => gmdate( 'c' ),
			'site_url'  => get_site_url(),
			'sermons'   => $this->export_sermons(),
			'series'    => $this->export_series(),
			'speakers'  => $this->export_speakers(),
			'topics'    => $this->export_topics(),
		];

		$filename = 'sermon-library-export-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- streaming JSON file download
		echo wp_json_encode( $data, JSON_PRETTY_PRINT );
		exit;
	}


	/**
	 * Export all published and draft sermons.
	 *
	 * @return array[]
	 */
	private function export_sermons(): array {
		$sermons = get_posts( [
			'post_type'      => 'scsl_sermon',
			'posts_per_page' => -1,
			'post_status'    => [ 'publish', 'draft' ],
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$out = [];
		foreach ( $sermons as $sermon ) {
			$id         = $sermon->ID;
			$ext_raw    = get_post_meta( $id, '_scsl_external_links', true );
			$notes_raw  = get_post_meta( $id, '_scsl_notes_files', true );
			// Try new prefix first, fall back to legacy prefix for sites not yet migrated
			$speaker_id = get_post_meta( $id, '_scsl_speaker_id', true );
			$series_id  = get_post_meta( $id, '_scsl_series_id', true );
			$topics     = wp_get_object_terms( $id, 'scsl_topic', [ 'fields' => 'slugs' ] );
			$scripture  = wp_get_object_terms( $id, 'scsl_scripture', [ 'fields' => 'names' ] );

			$out[] = [
				'id'               => $id,
				'title'            => $sermon->post_title,
				'slug'             => $sermon->post_name,
				'status'           => $sermon->post_status,
				'excerpt'          => $sermon->post_excerpt,
				'recorded_date'    => get_post_meta( $id, '_scsl_recorded_date', true ),
				'video_url'        => get_post_meta( $id, '_scsl_video_url', true ),
				'audio_url'        => get_post_meta( $id, '_scsl_audio_url', true ),
				'description'      => get_post_meta( $id, '_scsl_content_description', true ),
				'transcript'       => get_post_meta( $id, '_scsl_transcript_clean', true ),
				'focus_passage'    => get_post_meta( $id, '_scsl_focus_passage', true ),
				'other_passages'   => get_post_meta( $id, '_scsl_other_passages', true ) ?: [],
				'article_title'    => get_post_meta( $id, '_scsl_article_title', true ),
				'article_body'     => get_post_meta( $id, '_scsl_article_body', true ),
				'bible_study'      => get_post_meta( $id, '_scsl_bible_study', true ),
				'resources'        => get_post_meta( $id, '_scsl_resources', true ),
				'more_label'       => get_post_meta( $id, '_scsl_more_label', true ),
				'notes_files'      => is_array( $notes_raw ) ? $notes_raw : [],
				'external_links'   => $ext_raw ? json_decode( $ext_raw, true ) : [],
				'speaker_title'    => $speaker_id ? get_the_title( $speaker_id ) : '',
				'series_title'     => $series_id  ? get_the_title( $series_id )  : '',
				'topics'           => is_wp_error( $topics )    ? [] : $topics,
				'scripture_terms'  => is_wp_error( $scripture ) ? [] : $scripture,
				'thumbnail_url'    => get_the_post_thumbnail_url( $id, 'large' ) ?: '',
				// Podcast Details
				'podcast_audio_length'   => get_post_meta( $id, '_scsl_podcast_audio_length', true ),
				'podcast_audio_size'     => get_post_meta( $id, '_scsl_podcast_audio_size', true ),
				'podcast_image'          => get_post_meta( $id, '_scsl_podcast_image', true ),
				'podcast_include_series' => get_post_meta( $id, '_scsl_podcast_include_series', true ),
				'podcast_exclude'        => get_post_meta( $id, '_scsl_podcast_exclude', true ),
			];
		}
		return $out;
	}

	/**
	 * Export all series.
	 *
	 * @return array[]
	 */
	private function export_series(): array {
		$series = get_posts( [
			'post_type'      => 'scsl_series',
			'posts_per_page' => -1,
			'post_status'    => [ 'publish', 'draft' ],
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );

		$out = [];
		foreach ( $series as $s ) {
			$id    = $s->ID;
			$out[] = [
				'id'           => $id,
				'title'        => $s->post_title,
				'slug'         => $s->post_name,
				'status'       => $s->post_status,
				'description'  => $s->post_content,
				'menu_order'   => $s->menu_order,
				'thumbnail_url'=> get_the_post_thumbnail_url( $id, 'large' ) ?: '',
				'start_date'   => get_post_meta( $id, '_scsl_series_start_date', true ),
				'end_date'     => get_post_meta( $id, '_scsl_series_end_date', true ),
				'series_type'  => get_post_meta( $id, '_scsl_series_type', true ),
			];
		}
		return $out;
	}

	/**
	 * Export all speakers.
	 *
	 * @return array[]
	 */
	private function export_speakers(): array {
		$speakers = get_posts( [
			'post_type'      => 'scsl_speaker',
			'posts_per_page' => -1,
			'post_status'    => [ 'publish', 'draft' ],
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$out = [];
		foreach ( $speakers as $sp ) {
			$id    = $sp->ID;
			$out[] = [
				'id'            => $id,
				'title'         => $sp->post_title,
				'slug'          => $sp->post_name,
				'status'        => $sp->post_status,
				'bio'           => $sp->post_content,
				'thumbnail_url' => get_the_post_thumbnail_url( $id, 'large' ) ?: '',
				'speaker_title' => get_post_meta( $id, '_scsl_speaker_title', true ),
				'website'       => get_post_meta( $id, '_scsl_speaker_website', true ),
				'twitter'       => get_post_meta( $id, '_scsl_speaker_twitter', true ),
				'facebook'      => get_post_meta( $id, '_scsl_speaker_facebook', true ),
				'instagram'     => get_post_meta( $id, '_scsl_speaker_instagram', true ),
			];
		}
		return $out;
	}

	/**
	 * Export all topic taxonomy terms.
	 *
	 * @return array[]
	 */
	private function export_topics(): array {
		$terms = get_terms( [
			'taxonomy'   => 'scsl_topic',
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) ) return [];

		return array_map( fn( $t ) => [
			'term_id'     => $t->term_id,
			'name'        => $t->name,
			'slug'        => $t->slug,
			'description' => $t->description,
		], $terms );
	}
}
