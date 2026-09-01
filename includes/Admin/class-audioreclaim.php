<?php
/**
 * Audio reclaim.
 *
 * Copies sermon audio hosted by Seedcast into this site's own media library,
 * repointing each sermon as it goes.
 *
 * This lives in the free plugin on purpose. By the time a church wants it,
 * The AI Engine may already be deactivated or its key rejected, and a way out
 * that depends on the thing you have lost is not a way out. The media bucket
 * is public, so nothing here needs an API key.
 *
 * The point is not lapsed subscriptions. It is that a church should always be
 * able to take its own recordings back, whatever happens to Seedcast. Nothing
 * here is something a church has to do.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class AudioReclaim {

	const NONCE = 'scsl_reclaim';

	/**
	 * The original address, kept after a copy. Records where a file came from,
	 * and makes a second run able to see the work is already done.
	 */
	const META_ORIGIN = '_scsl_audio_origin';

	/**
	 * Sermons handled per request. Small, because these are whole recordings
	 * and a shared host will not thank anyone for ten at once.
	 */
	const BATCH = 2;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'scsl_podcast_screen_after', [ $this, 'render' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_scsl_reclaim_size', [ $this, 'ajax_size' ] );
		add_action( 'wp_ajax_scsl_reclaim_copy', [ $this, 'ajax_copy' ] );
	}

	/**
	 * Hosts whose files this offers to copy.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		/**
		 * Audio hosts a site may reclaim from.
		 *
		 * @param string[] $hosts Hostnames.
		 */
		return (array) apply_filters( 'scsl_reclaimable_audio_hosts', [ 'media.seedcast.ai' ] );
	}

	/**
	 * Whether an address points at somewhere reclaimable.
	 *
	 * @param string $url Audio address.
	 * @return bool
	 */
	private static function is_remote( string $url ): bool {
		if ( '' === $url ) return false;

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return $host && in_array( strtolower( $host ), array_map( 'strtolower', self::hosts() ), true );
	}

	/**
	 * Sermons still playing audio from a reclaimable host.
	 *
	 * @return int[]
	 */
	public static function pending(): array {
		$sermons = get_posts( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'any',
			'posts_per_page' => 1000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin screen only, and there is no other way to find these.
			'meta_query'     => [ [ 'key' => '_scsl_audio_url', 'compare' => 'EXISTS' ] ],
		] );

		$pending = [];

		foreach ( (array) $sermons as $post_id ) {
			if ( self::is_remote( (string) get_post_meta( $post_id, '_scsl_audio_url', true ) ) ) {
				$pending[] = absint( $post_id );
			}
		}

		return $pending;
	}

	/**
	 * Load the script when there is something to do.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function assets( string $hook ): void {
		if ( false === strpos( $hook, PodcastScreen::PAGE ) ) return;

		wp_enqueue_script(
			'scsl-reclaim',
			SCSL_PLUGIN_URL . 'assets/js/reclaim.js',
			[ 'jquery' ],
			SCSL_VERSION,
			true
		);

		wp_localize_script( 'scsl-reclaim', 'scslReclaim', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'posts'   => self::pending(),
			'batch'   => self::BATCH,
			'i18n'    => [
				'checking'  => __( 'Checking file sizes...', 'seedcast-sermon-library' ),
				'copying'   => __( 'Copying', 'seedcast-sermon-library' ),
				'done'      => __( 'Finished. Every sermon now plays audio from this site.', 'seedcast-sermon-library' ),
				'someFailed' => __( 'Finished, but some files could not be copied. The sermons they belong to are unchanged and still play from Seedcast.', 'seedcast-sermon-library' ),
				'failed'    => __( 'Something went wrong. Nothing was changed for the remaining sermons.', 'seedcast-sermon-library' ),
				'confirm'   => __( 'This downloads every one of those files into your media library. It can take a while and it will use that much space on your hosting. Continue?', 'seedcast-sermon-library' ),
				/* translators: %s: total size of the files, for example 1.2 GB. */
				'sizeIs'    => __( 'That is about %s in total.', 'seedcast-sermon-library' ),
				'sizeUnknown' => __( 'The total size could not be checked. Make sure your hosting has room before continuing.', 'seedcast-sermon-library' ),
			],
		] );
	}

	/**
	 * The panel, at the foot of the Podcast screen.
	 *
	 * Deliberately worded as a statement of fact rather than a task. A church
	 * whose audio is hosted by Seedcast has nothing to fix, and the copy says
	 * so before it says what the button does.
	 *
	 * @return void
	 */
	public function render(): void {
		$pending = self::pending();

		if ( ! $pending ) {
			$reclaimed = get_posts( [
				'post_type'      => 'scsl_sermon',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single existence check.
				'meta_query'     => [ [ 'key' => self::META_ORIGIN, 'compare' => 'EXISTS' ] ],
			] );

			if ( $reclaimed ) {
				printf(
					'<h2>%s</h2><p class="description">%s</p>',
					esc_html__( 'Where your audio lives', 'seedcast-sermon-library' ),
					esc_html__( 'Every sermon plays audio hosted on this site.', 'seedcast-sermon-library' )
				);
			}

			return;
		}
		?>
		<h2><?php esc_html_e( 'Where your audio lives', 'seedcast-sermon-library' ); ?></h2>

		<div class="scsl-reclaim">
			<p>
				<?php
				printf(
					/* translators: %d: number of sermons. */
					esc_html( _n(
						'%d sermon plays audio hosted by Seedcast.',
						'%d sermons play audio hosted by Seedcast.',
						count( $pending ),
						'seedcast-sermon-library'
					) ),
					count( $pending )
				);
				?>
			</p>

			<p class="description">
				<?php esc_html_e( 'That is normal and there is nothing to fix. Listening apps and your website both play it from there quite happily.', 'seedcast-sermon-library' ); ?>
			</p>

			<p class="description">
				<?php esc_html_e( 'If you would rather host the audio yourself, or you are moving away from Seedcast, you can copy every file into this site\'s media library. Each sermon is repointed as its file arrives, so the audio feed and your website keep working throughout. Your recordings on Seedcast are left alone.', 'seedcast-sermon-library' ); ?>
			</p>

			<p>
				<button type="button" class="button scsl-reclaim-go"><?php esc_html_e( 'Copy audio to this site', 'seedcast-sermon-library' ); ?></button>
				<span class="scsl-reclaim-status description"></span>
			</p>

			<div class="scsl-reclaim-log" hidden></div>
		</div>
		<?php
	}

	/**
	 * Shared guard.
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'seedcast-sermon-library' ) ], 403 );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().

	/**
	 * Total bytes for a batch, so the size is known before anything downloads.
	 *
	 * @return void
	 */
	public function ajax_size(): void {
		$this->guard();

		$bytes   = 0;
		$unknown = 0;

		foreach ( $this->posts_from_request() as $post_id ) {
			$url = (string) get_post_meta( $post_id, '_scsl_audio_url', true );

			if ( ! self::is_remote( $url ) ) continue;

			$head   = wp_remote_head( $url, [ 'timeout' => 10, 'redirection' => 3 ] );
			$length = is_wp_error( $head ) ? '' : wp_remote_retrieve_header( $head, 'content-length' );

			if ( '' === $length ) {
				$unknown++;
				continue;
			}

			$bytes += absint( $length );
		}

		wp_send_json_success( [ 'bytes' => $bytes, 'unknown' => $unknown ] );
	}

	/**
	 * Copy a batch into the media library.
	 *
	 * @return void
	 */
	public function ajax_copy(): void {
		$this->guard();

		$results = [];

		foreach ( $this->posts_from_request() as $post_id ) {
			$results[] = $this->reclaim_one( $post_id );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * The post ids for this request, filtered to ones still worth doing.
	 *
	 * @return int[]
	 */
	private function posts_from_request(): array {
		$raw = isset( $_POST['posts'] ) ? wp_unslash( $_POST['posts'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to integers below.

		$ids = array_map( 'absint', array_filter( explode( ',', (string) $raw ) ) );

		// Bounded, so one request cannot be made to download the whole library.
		$ids = array_slice( $ids, 0, self::BATCH );

		return array_values( array_filter( $ids, static function ( $id ) {
			return 'scsl_sermon' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
		} ) );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	/**
	 * Copy one sermon's audio and repoint it.
	 *
	 * The order matters. The sermon is only repointed once the attachment
	 * exists and hands back a usable address, so a failure part way through
	 * leaves that sermon exactly as it was, still playing from Seedcast.
	 *
	 * @param int $post_id Sermon ID.
	 * @return array { post_id, title, ok, message }
	 */
	private function reclaim_one( int $post_id ): array {
		$title = get_the_title( $post_id );
		$url   = (string) get_post_meta( $post_id, '_scsl_audio_url', true );

		$fail = static function ( $message ) use ( $post_id, $title ) {
			return [ 'post_id' => $post_id, 'title' => $title, 'ok' => false, 'message' => $message ];
		};

		// Already done, or never applied. Either way there is nothing to do,
		// which is what makes running this twice harmless.
		if ( ! self::is_remote( $url ) ) {
			return [ 'post_id' => $post_id, 'title' => $title, 'ok' => true, 'message' => __( 'Already on this site', 'seedcast-sermon-library' ) ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Generous, because these are whole recordings on church broadband.
		$tmp = download_url( $url, 600 );

		if ( is_wp_error( $tmp ) ) {
			return $fail( $tmp->get_error_message() );
		}

		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		// The storage key ends in source.mp4 for every sermon, which would
		// give a media library full of identically named files.
		if ( '' === $name || in_array( strtolower( $name ), [ 'source.mp3', 'source.m4a', 'source.mp4', 'audio.mp3' ], true ) ) {
			$ext  = pathinfo( $name, PATHINFO_EXTENSION ) ?: 'mp3';
			$name = sanitize_title( $title ?: 'sermon-' . $post_id ) . '.' . $ext;
		}

		$attachment_id = media_handle_sideload(
			[ 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp ],
			$post_id,
			null,
			[ 'post_title' => $title ]
		);

		if ( is_wp_error( $attachment_id ) ) {
			// media_handle_sideload removes the temp file on success only.
			if ( file_exists( $tmp ) ) wp_delete_file( $tmp );

			return $fail( $attachment_id->get_error_message() );
		}

		$local = wp_get_attachment_url( absint( $attachment_id ) );

		if ( ! $local ) {
			return $fail( __( 'The file copied across but WordPress did not give it an address, so the sermon was left alone.', 'seedcast-sermon-library' ) );
		}

		update_post_meta( $post_id, self::META_ORIGIN, esc_url_raw( $url ) );
		update_post_meta( $post_id, '_scsl_audio_url', esc_url_raw( $local ) );

		/**
		 * Fires after a sermon's audio has been copied to this site.
		 *
		 * @param int    $post_id       Sermon ID.
		 * @param int    $attachment_id New attachment ID.
		 * @param string $origin        Where the file came from.
		 */
		do_action( 'scsl_audio_reclaimed', $post_id, absint( $attachment_id ), $url );

		return [ 'post_id' => $post_id, 'title' => $title, 'ok' => true, 'message' => __( 'Copied', 'seedcast-sermon-library' ) ];
	}
}
