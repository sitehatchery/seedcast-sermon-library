<?php
/**
 * Imports from Sermon Manager, Sermon Works and Mattytap Sermons.
 *
 * All three share the wpfc_ schema, so one importer covers every site in that
 * lineage. It reads the database directly rather than asking for a file: the
 * data is already on the site, and a church admin on abandoned software should
 * not have to work out how to produce an export first.
 *
 * Nothing is modified on the way through. The wpfc_ posts, terms and meta are
 * left exactly as they were, so the old plugin keeps working and the import can
 * be run again after fixing something.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

use SeedcastSermonLibrary\Scripture\ScriptureParser;

if ( ! defined( 'ABSPATH' ) ) exit;

class SermonManagerImporter {

	/** Source post type, identical across all three plugins. */
	const SOURCE_TYPE = 'wpfc_sermon';

	/** Records which wpfc post produced which sermon, so a re-run updates rather than duplicates. */
	const SOURCE_ID_META = '_scsl_imported_from_wpfc';

	/** The source taxonomies, and the destination each one feeds. */
	const SOURCE_TAXONOMIES = [
		'wpfc_preacher',
		'wpfc_sermon_series',
		'wpfc_sermon_topics',
		'wpfc_bible_book',
		'wpfc_service_type',
	];

	public function init(): void {
		add_action( 'wp_ajax_scsl_wpfc_scan',   [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_scsl_wpfc_import', [ $this, 'ajax_import' ] );
	}

	/**
	 * Make the source taxonomies readable when the plugin that created them is
	 * gone.
	 *
	 * The sermons themselves survive deactivation because get_posts() will
	 * happily query an unregistered post type, but get_terms() and
	 * get_the_terms() both refuse an unregistered taxonomy and return
	 * WP_Error. Without this, importing from a site that has already switched
	 * Sermon Manager off would quietly bring across sermons with no preacher,
	 * series or topics attached, which is worse than failing outright.
	 *
	 * Registered private and UI-less, and only for the duration of the import
	 * request, so nothing appears in the admin and no rewrite rules change.
	 */
	private function ensure_source_taxonomies(): void {
		foreach ( self::SOURCE_TAXONOMIES as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			register_taxonomy(
				$taxonomy,
				self::SOURCE_TYPE,
				[
					'public'            => false,
					'show_ui'           => false,
					'show_in_menu'      => false,
					'show_in_nav_menus' => false,
					'show_in_rest'      => false,
					'rewrite'           => false,
					'query_var'         => false,
				]
			);
		}
	}

	/**
	 * Is there anything to import? True whenever wpfc_sermon posts exist,
	 * whether or not the plugin that created them is still active. A church
	 * that already deactivated Sermon Manager still has all of its data, and
	 * should not have to reactivate abandoned software to get it out.
	 */
	public static function source_present(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'auto-draft', 'trash' )",
				self::SOURCE_TYPE
			)
		);

		return (int) $count > 0;
	}

	// ── Step 1: scan ────────────────────────────────────────────────────────

	/**
	 * Report what is there, and where the problems are, before anything is
	 * written. The term duplicates matter most: the source plugin creates a new
	 * term whenever a name is typed slightly differently, so a long-running
	 * site accumulates several spellings of the same preacher, each with its
	 * own archive page.
	 */
	public function ajax_scan(): void {
		$this->guard();
		$this->ensure_source_taxonomies();

		$sermons = get_posts( [
			'post_type'      => self::SOURCE_TYPE,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$report = [
			'sermons'    => count( $sermons ),
			'preachers'  => $this->term_report( 'wpfc_preacher' ),
			'series'     => $this->term_report( 'wpfc_sermon_series' ),
			'topics'     => $this->term_report( 'wpfc_sermon_topics' ),
			'books'      => $this->term_report( 'wpfc_bible_book' ),
			'services'   => $this->term_report( 'wpfc_service_type' ),
			'already'    => 0,
			'unparsable' => [],
			'bulletins'  => 0,
		];

		if ( $sermons ) {
			update_postmeta_cache( $sermons );
		}

		foreach ( $sermons as $id ) {
			if ( $this->existing_sermon( $id ) ) {
				$report['already']++;
			}

			$passage = trim( (string) get_post_meta( $id, 'bible_passage', true ) );
			if ( '' !== $passage && ! ScriptureParser::extract_book( $passage ) ) {
				$report['unparsable'][] = [
					'id'      => $id,
					'title'   => get_the_title( $id ),
					'passage' => $passage,
				];
			}

			if ( get_post_meta( $id, 'sermon_bulletin', true ) ) {
				$report['bulletins']++;
			}
		}

		// Only ever report the first handful; a site with a thousand odd
		// passages does not need a thousand rows on screen.
		$report['unparsable_total'] = count( $report['unparsable'] );
		$report['unparsable']       = array_slice( $report['unparsable'], 0, 15 );
		$report['sunday_active']    = post_type_exists( 'sunday_service' );

		wp_send_json_success( $report );
	}

	/**
	 * Terms in a source taxonomy, with likely duplicates grouped.
	 *
	 * @param string $taxonomy Source taxonomy.
	 * @return array
	 */
	private function term_report( string $taxonomy ): array {
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return [ 'count' => 0, 'terms' => [], 'duplicates' => [] ];
		}

		$rows = [];
		foreach ( $terms as $t ) {
			$rows[] = [
				'id'          => $t->term_id,
				'name'        => $t->name,
				'slug'        => $t->slug,
				'count'       => $t->count,
				'description' => $t->description,
			];
		}

		return [
			'count'      => count( $rows ),
			'terms'      => $rows,
			'duplicates' => $this->find_duplicates( $rows ),
		];
	}

	/**
	 * Group terms that are probably the same person or series written
	 * differently.
	 *
	 * The comparison is deliberately loose: lowercase, strip punctuation and
	 * common titles, then treat one name as a match for another if it is a
	 * prefix of it. That catches "Matt" against "Matt Watkins" and "Pastor Matt
	 * Watkins", which is the shape this actually takes in the wild. It will
	 * occasionally group two genuinely different people, which is why the
	 * result is shown for confirmation rather than acted on.
	 *
	 * @param array $rows Term rows.
	 * @return array Groups of two or more term IDs.
	 */
	private function find_duplicates( array $rows ): array {
		$normalise = static function ( string $name ): string {
			$name = strtolower( $name );
			$name = preg_replace( '/\b(pastor|rev\.?|reverend|dr\.?|ps|fr\.?|bishop|elder)\b/', '', $name );
			$name = preg_replace( '/[^a-z0-9 ]/', '', $name );
			return trim( preg_replace( '/\s+/', ' ', $name ) );
		};

		$groups = [];
		$used   = [];

		foreach ( $rows as $i => $a ) {
			if ( isset( $used[ $a['id'] ] ) ) {
				continue;
			}
			$na    = $normalise( $a['name'] );
			$group = [ $a ];

			foreach ( array_slice( $rows, $i + 1 ) as $b ) {
				if ( isset( $used[ $b['id'] ] ) ) {
					continue;
				}
				$nb = $normalise( $b['name'] );
				if ( '' === $na || '' === $nb ) {
					continue;
				}
				if ( $na === $nb || 0 === strpos( $na, $nb ) || 0 === strpos( $nb, $na ) ) {
					$group[]           = $b;
					$used[ $b['id'] ] = true;
				}
			}

			if ( count( $group ) > 1 ) {
				$used[ $a['id'] ] = true;
				// The term with a description, or failing that the longest
				// name, is the better record to keep.
				usort(
					$group,
					static function ( $x, $y ) {
						$dx = '' !== trim( (string) $x['description'] ) ? 1 : 0;
						$dy = '' !== trim( (string) $y['description'] ) ? 1 : 0;
						return $dx === $dy ? strlen( $y['name'] ) <=> strlen( $x['name'] ) : $dy <=> $dx;
					}
				);
				$groups[] = $group;
			}
		}

		return $groups;
	}

	// ── Step 2: import ──────────────────────────────────────────────────────

	public function ajax_import(): void {
		$this->guard();
		$this->ensure_source_taxonomies();

		// Merge decisions: [ source_term_id => keep_term_id ]. Anything absent
		// keeps its own identity.
		// Nonce and capability are both checked in guard() immediately above,
		// which the sniff cannot follow across a method call.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$merges = [];
		if ( isset( $_POST['merges'] ) ) {
			$raw = json_decode( sanitize_textarea_field( wp_unslash( $_POST['merges'] ) ), true );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $from => $to ) {
					$merges[ absint( $from ) ] = absint( $to );
				}
			}
		}

		$offset = absint( $_POST['offset'] ?? 0 );
		$status = sanitize_key( $_POST['status'] ?? 'keep' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$batch   = 20;
		$results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'log' => [] ];

		// Speakers and series first: every sermon needs their post IDs.
		if ( 0 === $offset ) {
			$this->import_terms_as_posts( 'wpfc_preacher', 'scsl_speaker', $merges, $results, $status );
			$this->import_terms_as_posts( 'wpfc_sermon_series', 'scsl_series', $merges, $results, $status );
		}

		$sermons = get_posts( [
			'post_type'      => self::SOURCE_TYPE,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => $batch,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );

		foreach ( $sermons as $source_id ) {
			$this->import_sermon( $source_id, $merges, $results, $status );
		}

		$total = (int) wp_count_posts( self::SOURCE_TYPE )->publish
			+ (int) wp_count_posts( self::SOURCE_TYPE )->draft
			+ (int) wp_count_posts( self::SOURCE_TYPE )->pending
			+ (int) wp_count_posts( self::SOURCE_TYPE )->private
			+ (int) wp_count_posts( self::SOURCE_TYPE )->future;

		// The Series and Speaker pickers cache their lists, so anything created
		// here would otherwise not appear on a sermon edit screen for an hour.
		delete_transient( 'scsl_cached_scsl_series' );
		delete_transient( 'scsl_cached_scsl_speaker' );

		$next = $offset + $batch;

		wp_send_json_success(
			[
				'results'  => $results,
				'offset'   => $next,
				'total'    => $total,
				'complete' => $next >= $total,
			]
		);
	}

	/**
	 * Turn a source taxonomy into Sermon Library posts.
	 *
	 * This is the real work of the migration. A term can hold a name and a
	 * description; a speaker post holds a headshot, a bio, a title, social
	 * links and its own page. Everything the term had comes across, and the
	 * rest is there to fill in afterwards.
	 *
	 * @param string $taxonomy  Source taxonomy.
	 * @param string $post_type Destination post type.
	 * @param array  $merges    Term merge decisions.
	 * @param array  $results   Running totals, by reference.
	 * @param string $status    'keep' or 'draft'.
	 */
	private function import_terms_as_posts( string $taxonomy, string $post_type, array $merges, array &$results, string $status = 'keep' ): void {
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			// Merged-away terms produce no post of their own; sermons pointing
			// at them are redirected to the survivor.
			if ( isset( $merges[ $term->term_id ] ) && $merges[ $term->term_id ] !== $term->term_id ) {
				continue;
			}

			if ( $this->post_for_term( $term->term_id, $post_type ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				[
					'post_type'    => $post_type,
					// A speaker created from a term has a name and whatever
					// description the term carried, but no headshot, title or
					// links yet. Drafting gives you a chance to fill those in
					// before anyone sees the page.
					'post_status'  => 'draft' === $status ? 'draft' : 'publish',
					'post_title'   => $term->name,
					'post_name'    => $term->slug,
					'post_content' => $term->description,
				],
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$results['log'][] = sprintf(
					/* translators: 1: term name, 2: error message */
					__( 'Could not create "%1$s": %2$s', 'seedcast-sermon-library' ),
					$term->name,
					$post_id->get_error_message()
				);
				continue;
			}

			update_post_meta( $post_id, self::SOURCE_ID_META, $term->term_id );
			$results['log'][] = sprintf(
				/* translators: 1: post type label, 2: name */
				__( 'Created %1$s: %2$s', 'seedcast-sermon-library' ),
				'scsl_speaker' === $post_type ? __( 'speaker', 'seedcast-sermon-library' ) : __( 'series', 'seedcast-sermon-library' ),
				$term->name
			);
		}
	}

	/**
	 * The Sermon Library post created from a given source term, if any.
	 *
	 * @param int    $term_id   Source term ID.
	 * @param string $post_type Destination post type.
	 * @return int|null
	 */
	private function post_for_term( int $term_id, string $post_type ): ?int {
		$found = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'   => self::SOURCE_ID_META,
						'value' => $term_id,
					],
				],
			]
		);

		return $found ? (int) $found[0] : null;
	}

	/**
	 * The sermon already imported from a given source post, if any.
	 *
	 * @param int $source_id Source post ID.
	 * @return int|null
	 */
	private function existing_sermon( int $source_id ): ?int {
		$found = get_posts(
			[
				'post_type'      => 'scsl_sermon',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'   => self::SOURCE_ID_META,
						'value' => $source_id,
					],
				],
			]
		);

		return $found ? (int) $found[0] : null;
	}

	/**
	 * Convert one wpfc_sermon into a scsl_sermon.
	 *
	 * @param int   $source_id Source post ID.
	 * @param array  $merges   Term merge decisions.
	 * @param array  $results  Running totals, by reference.
	 * @param string $status   'keep' to mirror the source, 'draft' to hold
	 *                         everything back for review.
	 */
	private function import_sermon( int $source_id, array $merges, array &$results, string $status = 'keep' ): void {
		$source = get_post( $source_id );
		if ( ! $source ) {
			$results['skipped']++;
			return;
		}

		$existing = $this->existing_sermon( $source_id );

		$postarr = [
			'post_type'    => 'scsl_sermon',
			'post_status'  => 'draft' === $status ? 'draft' : $source->post_status,
			'post_title'   => $source->post_title,
			'post_name'    => $source->post_name,
			'post_date'    => $source->post_date,
			'post_excerpt' => $source->post_excerpt,
			// The source plugin writes a generated string such as
			// "Bible Text: John 3:16 | ..." into post_content. That is display
			// output, not authored content, so it is not carried across.
			'post_content' => '',
		];

		if ( $existing ) {
			$postarr['ID'] = $existing;
		}

		$sermon_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $sermon_id ) ) {
			$results['skipped']++;
			$results['log'][] = sprintf(
				/* translators: 1: sermon title, 2: error message */
				__( 'Skipped "%1$s": %2$s', 'seedcast-sermon-library' ),
				$source->post_title,
				$sermon_id->get_error_message()
			);
			return;
		}

		update_post_meta( $sermon_id, self::SOURCE_ID_META, $source_id );

		$this->copy_meta( $source_id, $sermon_id );
		$this->copy_thumbnail( $source_id, $sermon_id );
		$linked = $this->link_terms( $source_id, $sermon_id, $merges );

		// A sermon that had a preacher or series in the source but came across
		// without one is the failure worth knowing about, so say it plainly
		// rather than leaving it to be discovered later.
		$had_preacher = (bool) get_the_terms( $source_id, 'wpfc_preacher' );
		$had_series   = (bool) get_the_terms( $source_id, 'wpfc_sermon_series' );

		if ( $had_preacher && ! $linked['speaker'] ) {
			$results['log'][] = sprintf(
				/* translators: %s: sermon title */
				__( '"%s" had a preacher but no matching speaker was found.', 'seedcast-sermon-library' ),
				$source->post_title
			);
		}
		if ( $had_series && ! $linked['series'] ) {
			$results['log'][] = sprintf(
				/* translators: %s: sermon title */
				__( '"%s" had a series but no matching series was found.', 'seedcast-sermon-library' ),
				$source->post_title
			);
		}

		if ( $existing ) {
			$results['updated']++;
		} else {
			$results['imported']++;
		}
	}

	/**
	 * Field by field conversion.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $sermon_id Destination post ID.
	 */
	private function copy_meta( int $source_id, int $sermon_id ): void {
		// Preached date is stored as a Unix timestamp.
		$timestamp = get_post_meta( $source_id, 'sermon_date', true );
		if ( $timestamp ) {
			update_post_meta( $sermon_id, '_scsl_recorded_date', gmdate( 'Y-m-d', (int) $timestamp ) );
		}

		$description = (string) get_post_meta( $source_id, 'sermon_description', true );
		if ( '' !== $description ) {
			update_post_meta( $sermon_id, '_scsl_content_description', $description );
		}

		$audio = (string) get_post_meta( $source_id, 'sermon_audio', true );
		if ( '' !== $audio ) {
			update_post_meta( $sermon_id, '_scsl_audio_url', esc_url_raw( $audio ) );
		}

		// Two possible video fields: a plain link, and an embed code block that
		// the source plugin asks people to paste in by hand. Prefer the link,
		// and dig a URL out of the embed if that is all there is.
		$video = (string) get_post_meta( $source_id, 'sermon_video_link', true );
		if ( '' === $video ) {
			$embed = (string) get_post_meta( $source_id, 'sermon_video', true );
			if ( '' !== $embed && preg_match( '~src=["\']([^"\']+)["\']~i', $embed, $m ) ) {
				$video = $m[1];
			}
		}
		if ( '' !== $video ) {
			update_post_meta( $sermon_id, '_scsl_video_url', esc_url_raw( $video ) );
		}

		$duration = (string) get_post_meta( $source_id, '_wpfc_sermon_duration', true );
		if ( '' !== $duration ) {
			update_post_meta( $sermon_id, '_scsl_podcast_audio_length', $duration );
		}

		$size = (string) get_post_meta( $source_id, '_wpfc_sermon_size', true );
		if ( '' !== $size ) {
			update_post_meta( $sermon_id, '_scsl_podcast_audio_size', $size );
		}

		// Free text passage into the structured field. Anything that will not
		// parse is stored verbatim and reported, rather than guessed at.
		$passage = trim( (string) get_post_meta( $source_id, 'bible_passage', true ) );
		if ( '' !== $passage ) {
			update_post_meta( $sermon_id, '_scsl_focus_passage', $passage );
		}

		$this->copy_attachments( $source_id, $sermon_id );

		$views = absint( get_post_meta( $source_id, 'Views', true ) );
		if ( $views ) {
			update_post_meta( $sermon_id, '_scsl_view_count', $views );
		}
	}

	/**
	 * Notes, bulletins and anything else attached, gathered into the sermon's
	 * downloadable files.
	 *
	 * The source keeps a serialized id => url map when there are several notes
	 * and single keys when there is one, so both shapes are handled. Labels
	 * come from the attachment title where there is one, because "Sermon
	 * Notes" is more use to a visitor than "guest-teacher-profile.png".
	 *
	 * @param int $source_id Source post ID.
	 * @param int $sermon_id Destination post ID.
	 */
	private function copy_attachments( int $source_id, int $sermon_id ): void {
		$files = [];

		$add = function ( $attachment_id, $url, $fallback_label ) use ( &$files ) {
			$attachment_id = absint( $attachment_id );
			$url           = (string) $url;

			if ( $attachment_id && ! $url ) {
				$url = (string) wp_get_attachment_url( $attachment_id );
			}
			if ( ! $url ) {
				return;
			}

			$label = $attachment_id ? get_the_title( $attachment_id ) : '';
			if ( '' === trim( (string) $label ) ) {
				$label = $fallback_label;
			}

			$files[ $url ] = [
				'url'   => esc_url_raw( $url ),
				'label' => $label,
				'type'  => strtolower( (string) pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) ),
			];
		};

		$multiple = get_post_meta( $source_id, 'sermon_notes_multiple', true );
		if ( is_array( $multiple ) ) {
			foreach ( $multiple as $attachment_id => $url ) {
				$add( $attachment_id, $url, __( 'Sermon notes', 'seedcast-sermon-library' ) );
			}
		} else {
			$add(
				get_post_meta( $source_id, 'sermon_notes_id', true ),
				get_post_meta( $source_id, 'sermon_notes', true ),
				__( 'Sermon notes', 'seedcast-sermon-library' )
			);
		}

		// A bulletin describes the whole service rather than the sermon, but a
		// church that attached one wants it available, and an unlabelled file
		// nobody can find is worse than a slightly loose category.
		$add(
			get_post_meta( $source_id, 'sermon_bulletin_id', true ),
			get_post_meta( $source_id, 'sermon_bulletin', true ),
			__( 'Bulletin', 'seedcast-sermon-library' )
		);

		if ( $files ) {
			update_post_meta( $sermon_id, '_scsl_notes_files', array_values( $files ) );
		}
	}

	/**
	 * @param int $source_id Source post ID.
	 * @param int $sermon_id Destination post ID.
	 */
	private function copy_thumbnail( int $source_id, int $sermon_id ): void {
		$thumb = absint( get_post_meta( $source_id, '_thumbnail_id', true ) );
		if ( $thumb ) {
			set_post_thumbnail( $sermon_id, $thumb );
		}
	}

	/**
	 * Attach speaker, series, topics and scripture.
	 *
	 * Preacher and series become meta references to posts; topics and books
	 * stay taxonomies, so they map across directly.
	 *
	 * @param int   $source_id Source post ID.
	 * @param int   $sermon_id Destination post ID.
	 * @param array $merges    Term merge decisions.
	 * @return array Which links were made, for the log.
	 */
	private function link_terms( int $source_id, int $sermon_id, array $merges ): array {
		$resolve = function ( $term, string $post_type ) use ( $merges ): ?int {
			$term_id = isset( $merges[ $term->term_id ] ) ? $merges[ $term->term_id ] : $term->term_id;
			return $this->post_for_term( $term_id, $post_type );
		};

		$linked = [ 'speaker' => false, 'series' => false ];

		$preachers = get_the_terms( $source_id, 'wpfc_preacher' );
		if ( $preachers && ! is_wp_error( $preachers ) ) {
			// Sermon Library records one speaker per sermon. Where the source
			// listed several, the first becomes the speaker and the rest are
			// noted in the description so nothing is lost silently.
			$speaker_id = $resolve( $preachers[0], 'scsl_speaker' );
			if ( $speaker_id ) {
				update_post_meta( $sermon_id, '_scsl_speaker_id', $speaker_id );
				$linked['speaker'] = true;
			}
			if ( count( $preachers ) > 1 ) {
				$names = wp_list_pluck( array_slice( $preachers, 1 ), 'name' );
				update_post_meta( $sermon_id, '_scsl_additional_speakers', implode( ', ', $names ) );
			}
		}

		$series = get_the_terms( $source_id, 'wpfc_sermon_series' );
		if ( $series && ! is_wp_error( $series ) ) {
			$series_id = $resolve( $series[0], 'scsl_series' );
			if ( $series_id ) {
				update_post_meta( $sermon_id, '_scsl_series_id', $series_id );
				$linked['series'] = true;
			}
		}

		$topics = get_the_terms( $source_id, 'wpfc_sermon_topics' );
		$names  = ( $topics && ! is_wp_error( $topics ) ) ? wp_list_pluck( $topics, 'name' ) : [];

		// Service type has no equivalent concept, but "Sunday Morning" or
		// "Midweek" is worth keeping and browsable, so it joins the topics.
		$services = get_the_terms( $source_id, 'wpfc_service_type' );
		if ( $services && ! is_wp_error( $services ) ) {
			$names = array_merge( $names, wp_list_pluck( $services, 'name' ) );
		}

		if ( $names ) {
			wp_set_object_terms( $sermon_id, $names, 'scsl_topic' );
		}

		$books = get_the_terms( $source_id, 'wpfc_bible_book' );
		if ( $books && ! is_wp_error( $books ) ) {
			wp_set_object_terms( $sermon_id, wp_list_pluck( $books, 'name' ), 'scsl_scripture' );
		}

		return $linked;
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'seedcast-sermon-library' ) ], 403 );
		}
		check_ajax_referer( 'scsl_admin_nonce', 'nonce' );
	}
}
