<?php
/**
 * Series meta box callback.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Provides the meta box callback and save handling for sl_series posts.
 */
class SeriesMeta {

	// ── SERIES ──────────────────────────────────────────────────────────────

	public function series_details_cb( \WP_Post $post ): void {
		wp_nonce_field( 'scsl_series_meta', 'scsl_series_nonce' );
		$start_date  = get_post_meta( $post->ID, '_scsl_series_start_date', true );
		$end_date    = get_post_meta( $post->ID, '_scsl_series_end_date',   true );
		$series_type = get_post_meta( $post->ID, '_scsl_series_type',       true ) ?: 'sermon';
		?>
		<table class="scsl-meta-table">
			<tr>
				<th><label for="scsl_series_start_date"><?php esc_html_e( 'Start Date', 'seedcast-sermon-library' ); ?></label></th>
				<td><input type="date" id="scsl_series_start_date" name="scsl_series_start_date" value="<?php echo esc_attr( $start_date ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="scsl_series_end_date"><?php esc_html_e( 'End Date', 'seedcast-sermon-library' ); ?></label></th>
				<td><input type="date" id="scsl_series_end_date" name="scsl_series_end_date" value="<?php echo esc_attr( $end_date ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="scsl_series_type"><?php esc_html_e( 'Type', 'seedcast-sermon-library' ); ?></label></th>
				<td>
					<select id="scsl_series_type" name="scsl_series_type">
						<option value="sermon"     <?php selected( $series_type, 'sermon'     ); ?>><?php esc_html_e( 'Sermon Series',  'seedcast-sermon-library' ); ?></option>
						<option value="study"      <?php selected( $series_type, 'study'      ); ?>><?php esc_html_e( 'Bible Study',    'seedcast-sermon-library' ); ?></option>
						<option value="conference" <?php selected( $series_type, 'conference' ); ?>><?php esc_html_e( 'Conference',     'seedcast-sermon-library' ); ?></option>
						<option value="special"    <?php selected( $series_type, 'special'    ); ?>><?php esc_html_e( 'Special Event',  'seedcast-sermon-library' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}


	public function save( int $post_id ): void {
		if ( ! isset( $_POST['scsl_series_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_series_nonce'] ) ), 'scsl_series_meta' )
		) return;

		$fields = [
			'_scsl_series_start_date' => 'text',
			'_scsl_series_end_date'   => 'text',
			'_scsl_series_type'       => 'text',
		];
		foreach ( $fields as $meta_key => $type ) {
			$post_key = ltrim( $meta_key, '_' );
			if ( ! isset( $_POST[ $post_key ] ) ) continue;
			$raw   = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
			update_post_meta( $post_id, $meta_key, $raw );
		}
	}
}
