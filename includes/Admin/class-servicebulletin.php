<?php
/**
 * Start a service bulletin from a sermon as it is published.
 *
 * A church that preaches every Sunday also publishes a bulletin every Sunday,
 * and the two describe the same morning. The bulletin's week, overview and
 * picture are all things the sermon already knows, so the sermon offers to
 * start one rather than leaving somebody to build the same page by hand a
 * month at a time.
 *
 * What it makes is deliberately a beginning rather than an ending: the week, an
 * overview and an image, as a draft unless the church says otherwise. The
 * announcements, programs and the rest are already set up in Bulletin Library
 * and are not this plugin's business.
 *
 * The overview opens as the sermon's own description, because that is there
 * at once. Where an engine is connected, Summaries\ServiceSummary replaces it a
 * moment later with a paragraph about the morning rather than the sermon.
 *
 * Offered only where Bulletin Library is running, and only on a sermon that has
 * not been saved yet: it is an offer to start something, which stops making
 * sense once the answer would be "another one".
 *
 * @package SeedcastSermonLibrary
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class ServiceBulletin {

	/**
	 * Bulletin Library's own post type. Its presence is the whole test for
	 * whether that plugin is running: one fact, rather than a version number
	 * to keep in step with.
	 */
	const SERVICE = 'scbl_service';

	/**
	 * The week a bulletin is for, as Bulletin Library stores it.
	 */
	const SERVICE_DATE = '_scbl_service_date';

	/**
	 * The answer, kept on the sermon until it is published.
	 *
	 * A sermon written on Thursday and published on Sunday is the ordinary way
	 * round, so the question is asked once and acted on when the sermon goes
	 * live rather than when it is first typed.
	 */
	const META_WANTED = '_scsl_make_bulletin';

	const NONCE = 'scsl_make_bulletin_nonce';

	/**
	 * @return void
	 */
	public function init(): void {
		add_action( 'add_meta_boxes_scsl_sermon', [ $this, 'add_box' ] );

		// Late, so the sermon's own fields are saved before a bulletin is built
		// out of them.
		add_action( 'save_post_scsl_sermon', [ $this, 'on_save' ], 30, 2 );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * Whether Bulletin Library is running.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return post_type_exists( self::SERVICE );
	}

	/**
	 * The box, on a sermon nobody has saved yet.
	 *
	 * @param \WP_Post $post Sermon being added.
	 * @return void
	 */
	public function add_box( $post ): void {
		if ( ! self::available() || ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		// Asked while this is still a sermon being written rather than one
		// that exists.
		if ( 'auto-draft' !== (string) $post->post_status ) {
			return;
		}

		add_meta_box(
			'scsl_service_bulletin',
			__( 'Service Bulletin', 'seedcast-sermon-library' ),
			[ $this, 'render_box' ],
			'scsl_sermon',
			'side',
			'low'
		);
	}

	/**
	 * @param \WP_Post $post Sermon being added.
	 * @return void
	 */
	public function render_box( $post ): void {
		$on      = '1' === (string) get_option( 'scsl_bulletin_on_publish', '1' );
		$publish = '1' === (string) get_option( 'scsl_bulletin_publish', '0' );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<label>
			<input type="checkbox" name="scsl_make_bulletin" value="1" <?php checked( $on ); ?> />
			<?php esc_html_e( 'Create service bulletin automatically', 'seedcast-sermon-library' ); ?>
		</label>

		<p class="description">
			<?php
			$written = \SeedcastSermonLibrary\Summaries\ServiceSummary::connected();

			if ( $publish && $written ) {
				esc_html_e( 'When this sermon is published, a bulletin for its week is published with it, carrying this sermon&#8217;s image and an overview written from it.', 'seedcast-sermon-library' );
			} elseif ( $publish ) {
				esc_html_e( 'When this sermon is published, a bulletin for its week is published with it, carrying this sermon&#8217;s overview and image.', 'seedcast-sermon-library' );
			} elseif ( $written ) {
				esc_html_e( 'When this sermon is published, a draft bulletin for its week is started, carrying this sermon&#8217;s image and an overview written from it.', 'seedcast-sermon-library' );
			} else {
				esc_html_e( 'When this sermon is published, a draft bulletin for its week is started, carrying this sermon&#8217;s overview and image.', 'seedcast-sermon-library' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Remember the answer, and act on it once the sermon is live.
	 *
	 * Both on the same save, deliberately. A sermon published straight from the
	 * Add New screen answers the question and goes live in one step; a sermon
	 * saved as a draft first answers it now and goes live later, by which time
	 * the box is long gone from the screen.
	 *
	 * @param int      $post_id Sermon ID.
	 * @param \WP_Post $post    Sermon.
	 * @return void
	 */
	public function on_save( $post_id, $post ): void {
		$post_id = absint( $post_id );

		if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		/*
		 * Only where the box was on the screen. Every later save comes from a
		 * screen that does not draw it, and reading an absent checkbox as "no"
		 * would cancel the answer somebody gave on the way in.
		 */
		$submitted = isset( $_POST[ self::NONCE ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE );

		if ( $submitted ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the line above.
			if ( ! empty( $_POST['scsl_make_bulletin'] ) ) {
				update_post_meta( $post_id, self::META_WANTED, '1' );
			} else {
				delete_post_meta( $post_id, self::META_WANTED );
			}
		}

		if ( ! self::available() || 'publish' !== (string) ( $post->post_status ?? '' ) ) {
			return;
		}

		if ( '1' !== (string) get_post_meta( $post_id, self::META_WANTED, true ) ) {
			return;
		}

		// Asked and answered, whatever happens next. A bulletin that could not
		// be started is not a question to put again on every save.
		delete_post_meta( $post_id, self::META_WANTED );

		$this->remember( $this->make_for( $post_id ) );
	}

	/**
	 * Make the bulletin.
	 *
	 * @param int $post_id Sermon ID.
	 * @return array{state:string,id:int,date:string}
	 */
	private function make_for( int $post_id ): array {
		$date = $this->week_of( $post_id );

		if ( '' === $date ) {
			return [ 'state' => 'no_date', 'id' => 0, 'date' => '' ];
		}

		$existing = $this->service_on( $date );

		if ( $existing ) {
			return [ 'state' => 'exists', 'id' => $existing, 'date' => $date ];
		}

		$publish = '1' === (string) get_option( 'scsl_bulletin_publish', '0' );

		$service_id = wp_insert_post( [
			'post_type'    => self::SERVICE,
			'post_status'  => $publish ? 'publish' : 'draft',
			'post_title'   => $this->title_for( $date ),
			'post_content' => $this->overview_for( $post_id ),
		], true );

		if ( is_wp_error( $service_id ) || ! $service_id ) {
			return [ 'state' => 'failed', 'id' => 0, 'date' => $date ];
		}

		update_post_meta( $service_id, self::SERVICE_DATE, $date );

		$image = $this->image_for( $post_id );

		if ( $image ) {
			set_post_thumbnail( $service_id, $image );
		}

		/**
		 * A bulletin started from a sermon.
		 *
		 * @param int    $service_id The new bulletin.
		 * @param int    $post_id    The sermon it was started from.
		 * @param string $date       The week, as Y-m-d.
		 */
		do_action( 'scsl_service_bulletin_created', (int) $service_id, $post_id, $date );

		return [
			'state' => $publish ? 'published' : 'draft',
			'id'    => (int) $service_id,
			'date'  => $date,
		];
	}

	/**
	 * The week this sermon belongs to.
	 *
	 * The date it was preached, which is what a bulletin is filed under and
	 * what Bulletin Library matches sermons on. Falling back to the day it was
	 * published would file a sermon entered on a Tuesday under Tuesday.
	 *
	 * @param int $post_id Sermon ID.
	 * @return string Y-m-d, or empty where the sermon has no date.
	 */
	private function week_of( int $post_id ): string {
		$date = trim( (string) get_post_meta( $post_id, '_scsl_recorded_date', true ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * The bulletin already filed under that week, if there is one.
	 *
	 * @param string $date Y-m-d.
	 * @return int
	 */
	private function service_on( string $date ): int {
		$found = get_posts( [
			'post_type'              => self::SERVICE,
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A bulletin's week is post meta, so there is no version of this question without it.
			'meta_query'             => [
				[
					'key'   => self::SERVICE_DATE,
					'value' => $date,
				],
			],
		] );

		return $found ? absint( $found[0] ) : 0;
	}

	/**
	 * Named the way the bulletins already on the site are named.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	private function title_for( string $date ): string {
		$stamp = strtotime( $date . ' 12:00:00' );

		if ( ! $stamp ) {
			return $date;
		}

		/**
		 * How a bulletin started from a sermon is named.
		 *
		 * @param string $format Date format.
		 * @param string $date   The week, as Y-m-d.
		 */
		$format = (string) apply_filters( 'scsl_service_bulletin_title_format', 'l, F j, Y', $date );

		return date_i18n( $format, $stamp );
	}

	/**
	 * The paragraph the bulletin opens with.
	 *
	 * The sermon's own description for now, which describes the sermon rather
	 * than the morning. A summary written about the whole service is the right
	 * answer, and is what this filter is for.
	 *
	 * @param int $post_id Sermon ID.
	 * @return string
	 */
	private function overview_for( int $post_id ): string {
		$text = trim( (string) get_post_meta( $post_id, '_scsl_content_description', true ) );

		if ( '' === $text ) {
			$text = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
		}

		/**
		 * The overview a new bulletin opens with.
		 *
		 * @param string $text    What the sermon could offer.
		 * @param int    $post_id The sermon.
		 */
		return (string) apply_filters( 'scsl_service_bulletin_overview', $text, $post_id );
	}

	/**
	 * The picture: the sermon's own, or the series it belongs to.
	 *
	 * The same order a sermon page falls back in, so a bulletin started from a
	 * sermon with no picture of its own still opens with the series artwork
	 * rather than with nothing.
	 *
	 * @param int $post_id Sermon ID.
	 * @return int Attachment ID, or 0.
	 */
	private function image_for( int $post_id ): int {
		$own = absint( get_post_meta( $post_id, '_thumbnail_id', true ) );

		if ( $own ) {
			return $own;
		}

		$series_id = absint( get_post_meta( $post_id, '_scsl_series_id', true ) );

		return $series_id ? absint( get_post_meta( $series_id, '_thumbnail_id', true ) ) : 0;
	}

	/**
	 * Keep what happened, to say so on the next screen.
	 *
	 * @param array $result From make_for().
	 * @return void
	 */
	private function remember( array $result ): void {
		set_transient( 'scsl_bulletin_made_' . get_current_user_id(), $result, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Say what was started, once.
	 *
	 * @return void
	 */
	public function notice(): void {
		$key    = 'scsl_bulletin_made_' . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) || empty( $result['state'] ) ) {
			return;
		}

		delete_transient( $key );

		$state = (string) $result['state'];
		$id    = absint( $result['id'] ?? 0 );
		$link  = $id ? get_edit_post_link( $id ) : '';

		if ( 'no_date' === $state ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'No service bulletin was started: this sermon has no date, and a bulletin is filed under the week it is for.', 'seedcast-sermon-library' )
			);

			return;
		}

		if ( 'failed' === $state ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'The service bulletin could not be started. The sermon itself was saved.', 'seedcast-sermon-library' )
			);

			return;
		}

		$messages = [
			'exists'    => __( 'A service bulletin already exists for this week, so another was not started.', 'seedcast-sermon-library' ),
			'draft'     => __( 'A draft service bulletin was started for this week.', 'seedcast-sermon-library' ),
			'published' => __( 'A service bulletin was published for this week.', 'seedcast-sermon-library' ),
		];

		if ( ! isset( $messages[ $state ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s%s</p></div>',
			esc_html( $messages[ $state ] ),
			$link
				? sprintf(
					' <a href="%s">%s</a>',
					esc_url( $link ),
					esc_html__( 'Open it', 'seedcast-sermon-library' )
				)
				: ''
		);
	}
}
