<?php
/**
 * Series Engine Import Runner: core import logic and helpers.
 *
 * Contains the data-processing logic separated from the admin UI so that
 * the import can be invoked independently and unit-tested.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Executes a Series Engine CSV import and returns a results array.
 *
 * All methods are private; the class is instantiated and called by
 * SeriesEngineImporter::ajax_run() after the CSV has been parsed and
 * user options have been validated.
 */
class SeriesEngineRunner {

	public function run_import( string $csv, array $series_map, bool $dry_run, string $duplicate ): array {
		$log    = [];
		$counts = [ 'speakers' => 0, 'series' => 0, 'topics' => 0, 'sermons' => 0, 'skipped' => 0, 'errors' => 0 ];
		$mode   = $dry_run ? '[DRY RUN] ' : '';

		$data = $this->parse_csv( $csv );

		// ── Topics ───────────────────────────────────────────────────────
		$log[]     = '── Topics ──';
		$topic_map = [];
		foreach ( $data['topic'] as $t ) {
			$name = $t[1] ?? '';
			if ( ! $name ) continue;
			if ( ! $dry_run ) {
				$term = wp_insert_term( $name, 'scsl_topic' );
				if ( is_wp_error( $term ) ) {
					$ex = get_term_by( 'name', $name, 'scsl_topic' );
					$topic_map[ $t[0] ] = $ex ? $ex->term_id : 0;
				} else {
					$topic_map[ $t[0] ] = $term['term_id'];
					$counts['topics']++;
				}
			} else {
				$topic_map[ $t[0] ] = 0;
				$counts['topics']++;
			}
			$log[] = $mode . "Topic: {$name}";
		}

		// ── Speakers ─────────────────────────────────────────────────────
		$log[]            = '';
		$log[]            = '── Speakers ──';
		$speaker_name_map = [];
		foreach ( $data['speaker'] as $sp ) {
			$name = trim( trim( $sp[1] ?? '' ) . ' ' . trim( $sp[2] ?? '' ) );
			if ( ! $name ) continue;
			if ( ! $dry_run ) {
				$ex = get_posts( [ 'post_type' => 'scsl_speaker', 'title' => $name, 'fields' => 'ids', 'numberposts' => 1 ] );
				if ( $ex ) {
					$speaker_name_map[ $name ] = $ex[0];
					$log[] = "Speaker exists: {$name}";
				} else {
					$pid = wp_insert_post( [ 'post_type' => 'scsl_speaker', 'post_title' => $name, 'post_status' => 'publish' ] );
					$speaker_name_map[ $name ] = $pid;
					$counts['speakers']++;
					$log[] = $mode . "Created speaker: {$name} → #{$pid}";
				}
			} else {
				$speaker_name_map[ $name ] = 0;
				$counts['speakers']++;
				$log[] = $mode . "Would create speaker: {$name}";
			}
		}

		// ── Series (with mapping) ─────────────────────────────────────────
		$log[]      = '';
		$log[]      = '── Series ──';
		$series_wp  = []; // se_series_id => wp_post_id (0 = skip)

		foreach ( $data['series'] as $sr ) {
			$se_id    = $sr[0];
			$se_title = $sr[1] ?? 'Untitled';
			$action   = $series_map[ $se_id ] ?? 'new:' . $se_title;

			if ( $action === 'skip' ) {
				$series_wp[ $se_id ] = 0;
				$log[] = "Series SKIPPED: {$se_title}";
				continue;
			}

			if ( strpos( $action, 'existing:' ) === 0 ) {
				$wp_id = absint( substr( $action, 9 ) );
				$series_wp[ $se_id ] = $wp_id;
				$log[] = "Series mapped to existing #{$wp_id}: {$se_title}";
				continue;
			}

			// Create new
			$new_title = strpos( $action, 'new:' ) === 0 ? substr( $action, 4 ) : $se_title;
			if ( ! $dry_run ) {
				$ex = get_posts( [ 'post_type' => 'scsl_series', 'title' => $new_title, 'fields' => 'ids', 'numberposts' => 1 ] );
				if ( $ex ) {
					$series_wp[ $se_id ] = $ex[0];
					$log[] = "Series exists, using #{$ex[0]}: {$new_title}";
				} else {
					$pid = wp_insert_post( [
						'post_type'   => 'scsl_series',
						'post_title'  => $new_title,
						'post_status' => 'publish',
					] );
					if ( $sr[6] ?? '' ) update_post_meta( $pid, '_scsl_series_start_date', $sr[6] );
					$series_wp[ $se_id ] = $pid;
					$counts['series']++;
					$log[] = $mode . "Created series: {$new_title} → #{$pid}";
				}
			} else {
				$series_wp[ $se_id ] = 0;
				$counts['series']++;
				$log[] = $mode . "Would create series: {$new_title}";
			}
		}

		// ── Build relationship indexes ────────────────────────────────────
		$msg_topics   = [];
		foreach ( $data['mtm'] as $r ) { $msg_topics[ $r[1] ][]  = $r[2]; } // r[1]=message_id, r[2]=topic_id

		$msg_series   = [];
		foreach ( $data['smm'] as $r ) { $msg_series[ $r[1] ] = $r[2]; }

		$msg_scripture = [];
		foreach ( $data['scm'] as $r ) { $msg_scripture[ $r[2] ] = $r[1]; }

		$scripture_idx = [];
		foreach ( $data['scripture'] as $r ) { $scripture_idx[ $r[0] ] = $r[11] ?? ( $r[10] ?? '' ); }

		$msg_files = [];
		foreach ( $data['mfm'] as $r ) { $msg_files[ $r[1] ][] = $r[2]; }

		$file_idx  = [];
		foreach ( $data['file'] as $r ) { $file_idx[ $r[0] ] = [ 'label' => $r[1] ?? '', 'url' => $r[2] ?? '' ]; }

		// ── Sermons ───────────────────────────────────────────────────────
		$log[] = '';
		$log[] = '── Sermons ──';

		foreach ( $data['message'] as $msg ) {
			// NOTE: array_slice( $row, 1 ) removes col[0]="message", so all indices are -1 from CSV
			// CSV col[1]=series_id → msg[0], CSV col[2]=title → msg[1], etc.
			$se_id         = $msg[0];   // CSV col[1] series_id
			$title         = $msg[1] ?? 'Untitled'; // CSV col[2]
			$speaker_str   = trim( $msg[2] ?? '' ); // CSV col[3]
			$date          = $msg[3] ?? '';          // CSV col[4]
			$description   = $msg[5] ?? '';          // CSV col[6]
			$speaker_photo = $msg[7] ?? '';          // CSV col[8]
			$audio_url_raw = $msg[8] ?? '';          // CSV col[9]
			$youtube_embed = $msg[11] ?? '';         // CSV col[12]
			$audio_length  = $msg[15] ?? '';         // CSV col[16]
			$youtube_url   = $msg[17] ?? '';         // CSV col[18]
			$video_mp4     = $msg[18] ?? '';         // CSV col[19]
			$link_label    = $msg[26] ?? '';         // CSV col[27]
			$link_url      = $msg[27] ?? '';         // CSV col[28]
			$podcast_img   = $msg[29] ?? '';         // CSV col[30]
			$passage_col31 = trim( $msg[30] ?? '' ); // CSV col[31]
			// Publish flags (after slice: col[32]=msg[31], col[33]=msg[32], col[34]=msg[33])
			$show_on_website = $msg[32] ?? '1';      // CSV col[33]: 0=hidden, 1=visible, ''=old data (treat as visible)
			$is_published    = $msg[33] ?? '1';      // CSV col[34]: 1=published

			// Determine WordPress post status
			// Empty flags = old SE data without publish controls: treat as published
			$post_status = 'publish';
			if ( $show_on_website === '0' ) {
				$post_status = 'draft'; // explicitly hidden from website
			} elseif ( $is_published === '0' ) {
				$post_status = 'draft'; // not yet published in SE
			}

			if ( $date === '0000-00-00' ) $date = '';

			// Audio: prefer MP3 over Spotify (Spotify goes to external links)
			$audio_url   = '';
			$spotify_url = '';
			if ( strpos( $audio_url_raw, 'spotify.com' ) !== false ) {
				$spotify_url = $audio_url_raw;
			} elseif ( $audio_url_raw && $audio_url_raw !== '0' ) {
				$audio_url = $audio_url_raw;
			}

			// Video: prefer embed iframe → extract URL, then direct youtu.be URL, then MP4
			$video_url = '';
			if ( $youtube_embed && $youtube_embed !== '0' ) {
				$video_url = $this->extract_youtube_url( $youtube_embed );
			}
			if ( ! $video_url && $youtube_url && $youtube_url !== '0' ) {
				$video_url = $youtube_url;
			}
			if ( ! $video_url && $video_mp4 && $video_mp4 !== '0' ) {
				$video_url = $video_mp4;
			}

			// Description: if it's just HTML links, parse them intelligently by label
			$clean_desc   = trim( wp_strip_all_tags( $description ) );
			$is_link_only = strlen( $clean_desc ) < 200 && strpos( $description, 'href' ) !== false;
			if ( $is_link_only ) {
				$clean_desc = '';
			}

			// All links → categorise by label into appropriate fields
			$notes_files = [];
			$desc_ext_links = []; // web article/transcript links → external platform buttons

			if ( $is_link_only && preg_match_all( '/<a[^>]+href=[\'\\\\]*([^\'"\\\\>]+)[\'\\\\]*[^>]*>([^<]+)<\/a>/i', $description, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$url   = trim( $m[1], "\\\"'" );
					$label = trim( wp_strip_all_tags( $m[2] ) );
					if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) continue;

					$label_lower = strtolower( $label );

					// Article / Transcript links → external platform buttons (not downloads)
					if ( strpos( $label_lower, 'article' ) !== false
						|| strpos( $label_lower, 'transcript' ) !== false
						|| strpos( $label_lower, 'read' ) !== false
						|| strpos( $label_lower, 'view' ) !== false
					) {
						$desc_ext_links[] = [ 'platform' => 'other', 'label' => $label, 'url' => $url ];
					} else {
						// Other links (PDFs, notes, downloads) → Sermon Notes
						$notes_files[] = [ 'label' => $label ?: basename( $url ), 'url' => $url ];
					}
				}
			}

			// Link attachment columns (col[27]/col[28]) → always Sermon Notes
			if ( $link_url && $link_url !== '0' && filter_var( $link_url, FILTER_VALIDATE_URL ) ) {
				$notes_files[] = [
					'label' => $link_label ?: basename( $link_url ),
					'url'   => $link_url,
				];
			}

			// File junction table entries → Sermon Notes
			if ( isset( $msg_files[ $se_id ] ) ) {
				foreach ( $msg_files[ $se_id ] as $fid ) {
					$f = $file_idx[ $fid ] ?? null;
					if ( ! $f || ! ( $f['url'] ?? '' ) || strpos( $f['url'], 'seriesengine.com' ) !== false ) continue;
					$notes_files[] = [ 'label' => $f['label'] ?? basename( $f['url'] ), 'url' => $f['url'] ];
				}
			}

			// Resolve series: skip if series is set to skip
			$se_series_id = $msg_series[ $se_id ] ?? 0;
			$wp_series_id = $se_series_id ? ( $series_wp[ $se_series_id ] ?? 0 ) : 0;

			if ( $se_series_id && isset( $series_wp[ $se_series_id ] ) && $series_wp[ $se_series_id ] === 0
				&& isset( $series_map[ $se_series_id ] ) && $series_map[ $se_series_id ] === 'skip' ) {
				$log[] = "  Skipped (series skipped): {$title}";
				$counts['skipped']++;
				continue;
			}

			$speaker_id = $speaker_name_map[ $speaker_str ] ?? 0;

			// Focus passage: col[31] most reliable
			$focus = '';
			if ( $passage_col31 ) {
				$focus = $passage_col31;
			} elseif ( isset( $msg_scripture[ $se_id ] ) ) {
				$focus = $scripture_idx[ $msg_scripture[ $se_id ] ] ?? '';
			}
			if ( ! $focus ) {
				$col = $this->find_passage_column( $msg );
				if ( $col !== null ) $focus = $msg[ $col ];
			}

			// Topic term IDs
			$term_ids = [];
			if ( isset( $msg_topics[ $se_id ] ) ) {
				foreach ( $msg_topics[ $se_id ] as $tid ) {
					if ( isset( $topic_map[ $tid ] ) && $topic_map[ $tid ] ) {
						$term_ids[] = $topic_map[ $tid ];
					}
				}
			}

			$log[] = sprintf( '%s"%s" | %s | %s | Video: %s | Audio: %s | Scripture: %s | Topics: %s | Notes: %s | Status: %s',
				$mode, $title, $speaker_str ?: '-', $date ?: '-',
				$video_url ? 'Y' : 'N',
				( $audio_url ?: $spotify_url ) ? 'Y' : 'N',
				$focus ?: '-',
				count( $term_ids ),
				count( $notes_files ),
				$post_status
			);

			if ( $dry_run ) { $counts['sermons']++; continue; }

			// Duplicate handling
			$existing = get_posts( [ 'post_type' => 'scsl_sermon', 'title' => $title, 'fields' => 'ids', 'numberposts' => 1 ] );

			if ( $existing && $duplicate === 'skip' ) {
				$log[] = "  → Skipped (duplicate)";
				$counts['skipped']++;
				continue;
			}

			if ( $existing && $duplicate === 'overwrite' ) {
				$post_id = $existing[0];
				$log[] = "  → Overwriting #{$post_id}";
			} else {
				$post_id = wp_insert_post( [
					'post_type'    => 'scsl_sermon',
					'post_title'   => $title,
					'post_excerpt' => $clean_desc,
					'post_status'  => $post_status,
					'post_date'    => $date ? $date . ' 00:00:00' : current_time( 'mysql' ),
				] );
				if ( is_wp_error( $post_id ) ) {
					$log[] = "  → ERROR: " . $post_id->get_error_message();
					$counts['errors']++;
					continue;
				}
				$log[] = "  → Created #{$post_id}";
				$counts['sermons']++;
			}

			// External links: Spotify + article/transcript links from description
			$ext_links = [];
			if ( $spotify_url ) {
				$ext_links[] = [ 'platform' => 'spotify', 'label' => 'Listen on Spotify', 'url' => $spotify_url ];
			}
			// Merge article/transcript links extracted from description
			if ( ! empty( $desc_ext_links ) ) {
				$ext_links = array_merge( $ext_links, $desc_ext_links );
			}

			// Write meta
			$meta = [
				'_scsl_video_url'           => $video_url,
				'_scsl_audio_url'           => $audio_url,
				'_scsl_recorded_date'       => $date,
				'_scsl_speaker_id'          => $speaker_id,
				'_scsl_series_id'           => $wp_series_id,
				'_scsl_content_description' => $clean_desc,
				'_scsl_focus_passage'       => $focus,
				'_scsl_audio_duration'      => $audio_length ? (string) $audio_length : '',
			];
			foreach ( $meta as $key => $val ) {
				if ( $val !== '' && $val !== null && $val !== 0 ) {
					update_post_meta( $post_id, $key, $val );
				}
			}
			if ( $ext_links ) {
				update_post_meta( $post_id, '_scsl_external_links', wp_json_encode( $ext_links ) );
			}
			if ( $notes_files ) {
				update_post_meta( $post_id, '_scsl_notes_files', $notes_files );
			}

			// Speaker photo → download and set as speaker featured image
			if ( $speaker_id && $speaker_photo && ! has_post_thumbnail( $speaker_id ) ) {
				$attachment_id = $this->sideload_image( $speaker_photo, $speaker_id );
				if ( $attachment_id ) set_post_thumbnail( $speaker_id, $attachment_id );
			}

			// Taxonomies
			if ( $term_ids ) wp_set_object_terms( $post_id, $term_ids, 'scsl_topic' );
			if ( $focus ) {
				$st = wp_insert_term( $focus, 'scsl_scripture' );
				$st_id = is_wp_error( $st )
					? ( get_term_by( 'name', $focus, 'scsl_scripture' )->term_id ?? 0 )
					: $st['term_id'];
				if ( $st_id ) wp_set_object_terms( $post_id, [ (int) $st_id ], 'scsl_scripture' );
			}
		}

		$summary = $dry_run
			? sprintf( 'Dry run complete: would import: %d sermons, %d speakers, %d series, %d topics.',
				$counts['sermons'], $counts['speakers'], $counts['series'], $counts['topics'] )
			: sprintf( 'Import complete: created: %d sermons, %d speakers, %d series, %d topics. Skipped: %d. Errors: %d.',
				$counts['sermons'], $counts['speakers'], $counts['series'], $counts['topics'], $counts['skipped'], $counts['errors'] );

		$log[] = '';
		$log[] = '══ ' . $summary . ' ══';

		return [ 'summary' => $summary, 'log' => implode( "\n", $log ), 'counts' => $counts ];
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	public function parse_csv( string $csv ): array {
		$data = array_fill_keys( [ 'message','speaker','series','topic','file','scripture','msp','mtm','smm','scm','mfm','bmm' ], [] );
		foreach ( explode( "\n", $csv ) as $line ) {
			$line = trim( $line );
			if ( ! $line ) continue;
			// Escape character passed explicitly. Its default changes in a future
			// PHP version, and the parameter has existed since 5.3, so stating it
			// is safe on 7.4 and keeps the behaviour fixed either way.
			$row  = str_getcsv( $line, ',', '"', '\\' );
			$type = $row[0] ?? '';
			if ( isset( $data[ $type ] ) ) {
				$data[ $type ][] = array_slice( $row, 1 );
			}
		}
		return $data;
	}

	private function sideload_image( string $url, int $post_id ): int {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$result = media_sideload_image( $url, $post_id, null, 'id' );
		return is_wp_error( $result ) ? 0 : (int) $result;
	}

	private function extract_youtube_url( string $embed ): string {
		if ( ! $embed || $embed === '0' ) return '';
		if ( strpos( $embed, 'youtube.com/watch' ) !== false || strpos( $embed, 'youtu.be/' ) !== false ) return $embed;
		if ( preg_match( '/youtube(?:-nocookie)?\.com\/embed\/([a-zA-Z0-9_-]{11})/', $embed, $m ) ) {
			return 'https://www.youtube.com/watch?v=' . $m[1];
		}
		return '';
	}

	private function find_passage_column( array $row ): ?int {
		foreach ( $row as $i => $val ) {
			if ( $i < 10 ) continue;
			if ( preg_match( '/^[1-3]?\s?[A-Z][a-z]+\s+\d+:\d+/', trim( $val ) ) ) return $i;
		}
		return null;
	}
}
