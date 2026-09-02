<?php
/**
 * Completeness: what a finished sermon looks like, and the screen that reports on it.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

use SeedcastSermonLibrary\Import\FieldMap;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Declares Sermon Library's completeness definition to the shared core engine
 * and renders the report.
 *
 * The scoring, week arithmetic, locking and storage all live in core. This
 * class only answers the question core cannot: which fields make a sermon
 * finished, and which of them are the pastor's work versus the writing.
 *
 * That split matters. The details bucket is what a pastor fills in whatever
 * happens. The written content bucket is what the AI Engine produces. Keeping
 * them apart means a church turning on generation can see the number that
 * actually moved, rather than a blended figure diluted by fields generation
 * never touches.
 */
class Completeness {

	/**
	 * The source slug registered with core.
	 */
	public const SOURCE = 'seedcast-sermon-library';

	/**
	 * Definition version. Bump when the field list below changes, so weeks
	 * scored under the old rules stay identifiable and are not silently
	 * compared against weeks scored under new ones.
	 */
	public const VERSION = '3';

	/**
	 * Meta key holding the immutable date this sermon was first saved.
	 *
	 * Deliberately not post_date, which is editable from the editor and would
	 * let a locked week be reopened by rescheduling a post.
	 */
	public const SEEN_META = '_scsl_first_seen';

	/**
	 * Meta key holding the date this sermon first reached fully complete.
	 */
	public const COMPLETED_META = '_scsl_completed_on';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		if ( ! class_exists( '\Seedcast\Core\Completeness' ) ) {
			return;
		}

		add_action( 'init', [ $this, 'register_definition' ], 5 );

		// Stamp first seen on every write path, not just the metabox: the AI
		// Engine, the importers and the series engine all create sermons too.
		add_action( 'save_post_scsl_sermon', [ $this, 'stamp_first_seen' ], 1, 3 );

		// Meta hooks rather than the metabox save handler, for the same reason.
		add_action( 'updated_post_meta', [ $this, 'watch_meta' ], 10, 4 );
		add_action( 'added_post_meta',   [ $this, 'watch_meta' ], 10, 4 );

		/*
		 * The scores are cached, and until now only a date change or a tracked
		 * meta write cleared them. Changing which content sections are in use
		 * changes every score on the site, so leaving the cache alone meant a
		 * church could switch a section on, save, and go on being shown the
		 * figures from before it did. Deleting a sermon has the same effect on
		 * the average, so both clear the cache.
		 */
		add_action( 'updated_option', [ $this, 'flush_on_option_change' ] );
		add_action( 'added_option',   [ $this, 'flush_on_option_change' ] );

		foreach ( [ 'deleted_post', 'trashed_post', 'untrashed_post' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_on_post_change' ] );
		}
		add_action( 'save_post_scsl_sermon', [ $this, 'flush_on_post_change' ], 99 );

		/*
		 * Measurement above this line runs regardless of the display setting.
		 * A weekly score cannot be worked out after the fact, so a church that
		 * hides the scores for a year and then wants them back would otherwise
		 * find a year-shaped hole in the record. Nothing leaves the site, so
		 * the only cost of continuing is a row a week.
		 *
		 * Everything below is display, and honours the setting.
		 */
		if ( ! self::display_enabled() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'add_page' ], 20 );

		add_action( 'seedcast/completeness/comprehensive_footer', [ $this, 'comprehensive_footer' ] );

		/*
		 * The score goes inside the existing Content cell rather than into a
		 * column of its own. Two columns saying overlapping things about the
		 * same sermon is how a list screen becomes unreadable, and the number
		 * only makes sense next to the list it summarises.
		 *
		 * Priority 5 puts the badge above AdminColumns' output at 20, and 25
		 * puts the detail gaps below it. Those gaps have to be shown here:
		 * without them a sermon listing every content section but scoring 73
		 * looks like a bug.
		 */
		add_action( 'manage_scsl_sermon_posts_custom_column', [ $this, 'render_score_badge' ], 5, 2 );
		add_action( 'manage_scsl_sermon_posts_custom_column', [ $this, 'render_detail_gaps' ], 25, 2 );

		add_action( 'add_meta_boxes_scsl_sermon', [ $this, 'add_meta_box' ] );

		/*
		 * admin.css is enqueued on post.php and post-new.php only, so the
		 * sermon list has been rendering .scsl-has, .scsl-missing and
		 * .scsl-none unstyled since they were written. The score badge needs
		 * it too, so load it here rather than adding a second stylesheet.
		 */
		add_action( 'admin_enqueue_scripts', [ $this, 'list_assets' ] );

		// After the heading and the status links, before the table.
		add_action( 'admin_notices', [ $this, 'render_list_summary' ] );
	}

	/**
	 * Load the plugin stylesheet on the sermon list screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function list_assets( $hook ): void {
		if ( 'edit.php' !== $hook ) return;

		$screen = get_current_screen();
		if ( ! $screen || 'scsl_sermon' !== $screen->post_type ) return;

		wp_enqueue_style( 'scsl-admin', SCSL_PLUGIN_URL . 'assets/css/admin.css', [], SCSL_VERSION );
	}

	/**
	 * Tell core what a complete sermon looks like.
	 */
	public function register_definition(): void {
		\Seedcast\Core\Completeness::register( self::SOURCE, [
			'label'          => __( 'Sermons', 'seedcast-sermon-library' ),
			'version'        => self::VERSION,
			'post_type'      => 'scsl_sermon',
			'date_meta'      => '_scsl_recorded_date',
			'seen_meta'      => self::SEEN_META,
			'completed_meta' => self::COMPLETED_META,
			'fields'         => self::fields(),
		] );
	}

	/**
	 * The field definition.
	 *
	 * Details always count. Speaker, series, passage, a recording and an image
	 * are what make a sermon page usable at all, and no church would sensibly
	 * describe them as optional.
	 *
	 * Written content is gated on what the church has said it uses, via the
	 * same setting that already drives generation and the sermon tabs. That
	 * setting is the single source of truth: a church that has switched off
	 * Bible studies is not told every week that it is incomplete, and a church
	 * that wants them has them counted.
	 *
	 * Only the score shown to the pastor respects that. Core computes a
	 * comprehensive score alongside it, over every field regardless of
	 * configuration, because a score a church can raise by wanting less cannot
	 * be compared across churches or used to tell whether anything changed.
	 *
	 * @return array<int, array>
	 */
	public static function fields(): array {
		$fields = [
			[
				'key'    => 'speaker',
				'label'  => __( 'Speaker', 'seedcast-sermon-library' ),
				'bucket' => 'metadata',
				'meta'   => '_scsl_speaker_id',
			],
			[
				'key'    => 'series',
				'label'  => __( 'Series', 'seedcast-sermon-library' ),
				'bucket' => 'metadata',
				'meta'   => '_scsl_series_id',
			],
			[
				'key'    => 'passage',
				'label'  => __( 'Focus passage', 'seedcast-sermon-library' ),
				'bucket' => 'metadata',
				'meta'   => '_scsl_focus_passage',
			],
			[
				// Audio or video, not both: a church that streams video has a
				// recording, and requiring the other format would score them
				// down for a choice rather than for missing work.
				'key'      => 'media',
				'label'    => __( 'Audio or video', 'seedcast-sermon-library' ),
				'bucket'   => 'metadata',
				'any_meta' => [ '_scsl_audio_url', '_scsl_video_url' ],
			],
			[
				// get_post_thumbnail_id() runs through the fallback filter, so
				// a sermon in a series with an image already counts. Nothing
				// extra is needed here.
				'key'    => 'image',
				'label'  => __( 'Featured image', 'seedcast-sermon-library' ),
				'bucket' => 'metadata',
				'test'   => static function ( $post_id ) {
					return (bool) get_post_thumbnail_id( (int) $post_id );
				},
			],
		];

		// Written content, one entry per configurable section.
		foreach ( FieldMap::sections() as $meta_key => $label ) {
			/*
			 * The More tab is assembled from files and links nothing generates
			 * and most sermons never have. The sermon list column already
			 * leaves it out for exactly that reason, and scoring against it
			 * here would contradict that: every sermon would sit below 100 for
			 * a gap there is no way to close. Left out so the two agree.
			 */
			if ( '_scsl_resources' === $meta_key ) {
				continue;
			}

			$fields[] = [
				'key'    => ltrim( (string) $meta_key, '_' ),
				'label'  => $label,
				'bucket' => 'content',
				'meta'   => $meta_key,
				'wanted' => static function () use ( $meta_key ) {
					return FieldMap::uses( (string) $meta_key );
				},
			];
		}

		return $fields;
	}

	/**
	 * The detail fields only, for places that report on them separately.
	 *
	 * @return array<int, array>
	 */
	public static function detail_fields(): array {
		return array_values(
			array_filter(
				self::fields(),
				static function ( $field ) {
					return 'metadata' === ( $field['bucket'] ?? 'metadata' );
				}
			)
		);
	}

	/**
	 * Stamp the first seen date once, and never again.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function stamp_first_seen( int $post_id, $post = null, $update = false ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( '' !== trim( (string) get_post_meta( $post_id, self::SEEN_META, true ) ) ) {
			return;
		}
		update_post_meta( $post_id, self::SEEN_META, current_time( 'Y-m-d' ) );
	}

	/**
	 * React to a tracked field changing.
	 *
	 * A date change is the only thing that can reopen a closed week, and core
	 * enforces the rule about whether the sermon existed at the time. Any other
	 * tracked field changing only refreshes the completion stamp and the cached
	 * archive score.
	 *
	 * @param int    $meta_id  Meta row ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    New value.
	 */
	public function watch_meta( $meta_id, $post_id, $meta_key, $value ): void {
		$post_id = (int) $post_id;

		if ( 'scsl_sermon' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( '_scsl_recorded_date' === $meta_key ) {
			/*
			 * Stamp first seen here as well as on save_post, because the order
			 * is not guaranteed. The metabox writes meta during save_post, and
			 * whether the date lands before or after the stamp depends on hook
			 * registration order. If the date is processed first and no stamp
			 * exists yet, core's existed-during-the-week rule excludes the
			 * sermon from its own week and the week scores zero. Stamping
			 * before the date is processed removes the dependency on ordering
			 * entirely.
			 */
			$this->stamp_first_seen( $post_id );

			$previous = get_post_meta( $post_id, '_scsl_previous_date', true );
			$current  = trim( (string) $value );

			if ( (string) $previous !== $current ) {
				\Seedcast\Core\Completeness::on_date_change(
					self::SOURCE,
					trim( (string) $previous ),
					$current
				);
				update_post_meta( $post_id, '_scsl_previous_date', $current );
			}
			return;
		}

		if ( ! in_array( $meta_key, self::tracked_keys(), true ) ) {
			return;
		}

		$this->maybe_stamp_completed( $post_id );
		\Seedcast\Core\Completeness::flush_archive_cache( self::SOURCE );
	}

	/**
	 * Record the first date a sermon reached fully complete.
	 *
	 * Elapsed time from the sermon's own date to this is what the report shows
	 * as the completion gap. It is not a measure of effort, and is not labelled
	 * as one: a pastor who drafts in a word processor and pastes in still shows
	 * correctly here, which is exactly why it is preferred over timing editor
	 * sessions.
	 *
	 * @param int $post_id Post ID.
	 */
	private function maybe_stamp_completed( int $post_id ): void {
		if ( '' !== trim( (string) get_post_meta( $post_id, self::COMPLETED_META, true ) ) ) {
			return;
		}

		$score = \Seedcast\Core\Completeness::score_item( self::SOURCE, $post_id );
		if ( 100 === (int) $score['overall'] ) {
			update_post_meta( $post_id, self::COMPLETED_META, current_time( 'Y-m-d' ) );
		}
	}

	/**
	 * Every meta key that feeds a score.
	 *
	 * @return array<int, string>
	 */
	private static function tracked_keys(): array {
		$keys = [];
		foreach ( self::fields() as $field ) {
			if ( ! empty( $field['meta'] ) ) {
				$keys[] = (string) $field['meta'];
			}
			if ( ! empty( $field['any_meta'] ) ) {
				foreach ( (array) $field['any_meta'] as $key ) {
					$keys[] = (string) $key;
				}
			}
		}
		return $keys;
	}

	/**
	 * Options that change what every score means.
	 *
	 * @return array<int, string>
	 */
	private static function score_changing_options(): array {
		return [
			'scsl_sections_in_use',
			'scsl_sections_known',
			'scsl_image_fallback_series',
			'scsl_default_image',
		];
	}

	/**
	 * Clear the cached scores when a setting that feeds them changes.
	 *
	 * @param string $option Option name.
	 */
	public function flush_on_option_change( $option ): void {
		if ( ! in_array( (string) $option, self::score_changing_options(), true ) ) {
			return;
		}
		if ( ! class_exists( '\Seedcast\Core\Completeness' ) ) {
			return;
		}
		\Seedcast\Core\Completeness::flush_archive_cache( self::SOURCE );
	}

	/**
	 * Clear the cached scores when the set of sermons changes.
	 *
	 * @param int $post_id Post ID.
	 */
	public function flush_on_post_change( $post_id ): void {
		if ( 'scsl_sermon' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		if ( ! class_exists( '\Seedcast\Core\Completeness' ) ) {
			return;
		}
		\Seedcast\Core\Completeness::flush_archive_cache( self::SOURCE );
	}

	/**
	 * Whether the scores are shown at all.
	 *
	 * A church publishing recordings and nothing else has no use for a wall of
	 * labels telling it so every time it opens the sermon list.
	 *
	 * @return bool
	 */
	public static function display_enabled(): bool {
		return '0' !== (string) get_option( 'scsl_show_completeness', '1' );
	}

	/**
	 * The library score, above the sermon list.
	 *
	 * The same answer the report leads with, in the place the work actually
	 * happens. Somebody scanning their sermons should not have to go looking
	 * for the number, and having it here is what makes the per-row badges read
	 * as parts of a whole rather than as scattered marks.
	 */
	public function render_list_summary(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-scsl_sermon' !== $screen->id ) return;
		if ( ! class_exists( '\Seedcast\Core\Completeness' ) ) return;

		$archive = \Seedcast\Core\Completeness::archive_score( self::SOURCE );

		if ( (int) $archive['total'] < 1 ) return;

		$score  = (int) $archive['overall'];
		$tier   = \Seedcast\Core\Completeness::tier( $score );
		$counts = \Seedcast\Core\Completeness::tier_counts( self::SOURCE );
		$report = admin_url( 'admin.php?page=seedcast-sermon-library-completeness' );

		/*
		 * The comprehensive score only appears when something is switched off,
		 * matching the report. With nothing excluded the two numbers are
		 * identical, and two identical rings side by side read as a fault
		 * rather than as extra information.
		 */
		$show_comprehensive = \Seedcast\Core\Completeness::has_excluded_fields( self::SOURCE );
		$comprehensive      = (int) $archive['comprehensive'];
		$comp_tier          = \Seedcast\Core\Completeness::tier( $comprehensive );
		?>
		<div class="scsl-list-summary">
			<div class="scsl-list-summary__scores">
				<a class="scsl-list-summary__ring" href="<?php echo esc_url( $report ); ?>">
					<span class="scsl-list-summary__score scsl-list-summary__score--<?php echo esc_attr( $tier['band'] ); ?>">
						<span class="scsl-list-summary__value"><?php echo esc_html( (string) $score ); ?></span>
					</span>
					<span class="scsl-list-summary__caption"><?php esc_html_e( 'Library', 'seedcast-sermon-library' ); ?></span>
				</a>

				<?php if ( $show_comprehensive ) : ?>
					<a class="scsl-list-summary__ring"
						href="<?php echo esc_url( add_query_arg( 'seedcast_view', 'comprehensive', $report ) ); ?>">
						<span class="scsl-list-summary__score scsl-list-summary__score--<?php echo esc_attr( $comp_tier['band'] ); ?>">
							<span class="scsl-list-summary__value"><?php echo esc_html( (string) $comprehensive ); ?></span>
						</span>
						<span class="scsl-list-summary__caption"><?php esc_html_e( 'Comprehensive', 'seedcast-sermon-library' ); ?></span>
					</a>
				<?php endif; ?>
			</div>

			<div class="scsl-list-summary__body">
				<p class="scsl-list-summary__title">
					<a href="<?php echo esc_url( $report ); ?>"><?php esc_html_e( 'Library score', 'seedcast-sermon-library' ); ?></a>
				</p>
				<ul class="scsl-list-summary__tiers">
					<?php foreach ( \Seedcast\Core\Completeness::tiers() as $t ) : ?>
						<li>
							<a href="<?php echo esc_url( add_query_arg( [ 'seedcast_view' => 'items', 'seedcast_filter' => $t['key'] ], $report ) ); ?>">
								<span class="scsl-list-summary__dot scsl-list-summary__dot--<?php echo esc_attr( $t['band'] ); ?>"></span>
								<strong><?php echo esc_html( (string) (int) $counts[ $t['key'] ] ); ?></strong>
								<?php echo esc_html( $t['label'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * A closing note on the comprehensive tab.
	 *
	 * States where the missing content would come from and stops. No button,
	 * no pricing, no urgency: a church looking at its own numbers should not
	 * find an advert sitting under them, and the difference between the two
	 * scores already makes the point better than any copy would.
	 *
	 * @param string $source Source being reported on.
	 */
	public function comprehensive_footer( $source ): void {
		if ( self::SOURCE !== $source ) return;
		?>
		<p class="description" style="max-width:70ch;margin-top:1.5rem;">
			<?php
			printf(
				/* translators: %s: link to the Seedcast AI Engine page. */
				esc_html__( 'Written content can be produced from a sermon recording with %s, or written by hand in the sermon editor.', 'seedcast-sermon-library' ),
				'<a href="https://seedcast.ai/sermon-library/" target="_blank" rel="noopener">'
					. esc_html__( 'Seedcast AI Engine', 'seedcast-sermon-library' )
					. '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Add the report submenu.
	 */
	public function add_page(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Completeness', 'seedcast-sermon-library' ),
			__( 'Completeness', 'seedcast-sermon-library' ),
			'edit_posts',
			'seedcast-sermon-library-completeness',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the report.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) return;

		wp_enqueue_style( 'seedcast-core-admin' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sermon Completeness', 'seedcast-sermon-library' ); ?></h1>
			<?php \Seedcast\Core\Admin\CompletenessReport::render( self::SOURCE ); ?>
		</div>
		<?php
	}

	/**
	 * The score, at the top of the Content cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_score_badge( $column, $post_id ): void {
		if ( 'scsl_content' !== $column ) return;

		$post_id = (int) $post_id;
		$date    = trim( (string) get_post_meta( $post_id, '_scsl_recorded_date', true ) );

		if ( '' === $date ) {
			printf(
				'<span class="scsl-score scsl-score--nodate">%s</span><br />',
				esc_html__( 'No date, not counted', 'seedcast-sermon-library' )
			);
			return;
		}

		$score = \Seedcast\Core\Completeness::score_item( self::SOURCE, $post_id );
		$tier  = \Seedcast\Core\Completeness::tier( (int) $score['overall'] );

		printf(
			'<a class="scsl-score scsl-score--%1$s" href="%2$s">%3$d%% %4$s</a><br />',
			esc_attr( $tier['band'] ),
			esc_url(
				admin_url(
					'admin.php?page=seedcast-sermon-library-completeness&seedcast_view=items&seedcast_filter='
					. rawurlencode( $tier['key'] )
				)
			),
			(int) $score['overall'],
			esc_html( $tier['label'] )
		);
	}

	/**
	 * Detail gaps, under the content list.
	 *
	 * The Content cell lists written sections only, but the score also counts
	 * speaker, series, passage, a recording and an image. Without this a
	 * sermon showing every section present and a score of 73 reads as a fault
	 * rather than as five fields nobody has filled in.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_detail_gaps( $column, $post_id ): void {
		if ( 'scsl_content' !== $column ) return;

		$post_id = (int) $post_id;

		if ( '' === trim( (string) get_post_meta( $post_id, '_scsl_recorded_date', true ) ) ) {
			return;
		}

		$missing = [];

		foreach ( self::detail_fields() as $field ) {
			if ( ! self::field_filled( $field, $post_id ) ) {
				$missing[] = (string) $field['label'];
			}
		}

		if ( empty( $missing ) ) return;

		printf(
			'<br /><span class="scsl-missing"><strong>%s</strong> %s</span>',
			esc_html__( 'Also missing:', 'seedcast-sermon-library' ),
			esc_html( implode( ', ', $missing ) )
		);
	}

	/**
	 * Whether a detail field is filled, mirroring core's own test.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post ID.
	 * @return bool
	 */
	private static function field_filled( array $field, int $post_id ): bool {
		if ( isset( $field['test'] ) && is_callable( $field['test'] ) ) {
			return (bool) call_user_func( $field['test'], $post_id );
		}

		$keys = [];
		if ( ! empty( $field['any_meta'] ) ) {
			$keys = (array) $field['any_meta'];
		} elseif ( ! empty( $field['meta'] ) ) {
			$keys = [ $field['meta'] ];
		}

		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, (string) $key, true );

			if ( is_array( $value ) ) {
				if ( array_filter( $value ) ) return true;
				continue;
			}

			$string = trim( (string) $value );
			if ( '' !== $string && '0' !== $string ) return true;
		}

		return false;
	}

	/**
	 * The completeness box on the sermon edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'scsl_completeness',
			__( 'Completeness', 'seedcast-sermon-library' ),
			[ $this, 'render_meta_box' ],
			'scsl_sermon',
			'side',
			'high'
		);
	}

	/**
	 * Render the box: the score, what is there, and what is not.
	 *
	 * @param \WP_Post $post Sermon being edited.
	 */
	public function render_meta_box( $post ): void {
		$post_id = (int) $post->ID;
		$date    = trim( (string) get_post_meta( $post_id, '_scsl_recorded_date', true ) );
		$score   = \Seedcast\Core\Completeness::score_item( self::SOURCE, $post_id );
		$tier    = \Seedcast\Core\Completeness::tier( (int) $score['overall'] );
		?>
		<div class="scsl-completeness-box">
			<div class="scsl-completeness-box__score scsl-completeness-box__score--<?php echo esc_attr( $tier['band'] ); ?>">
				<span class="scsl-completeness-box__value"><?php echo esc_html( (string) (int) $score['overall'] ); ?>%</span>
				<span class="scsl-completeness-box__tier"><?php echo esc_html( $tier['label'] ); ?></span>
			</div>

			<?php if ( '' === $date ) : ?>
				<p class="scsl-completeness-box__warn">
					<?php esc_html_e( 'This sermon has no recorded date, so it is not counted in any week.', 'seedcast-sermon-library' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $score['missing'] ) ) : ?>
				<p class="scsl-completeness-box__heading"><?php esc_html_e( 'Missing', 'seedcast-sermon-library' ); ?></p>
				<ul class="scsl-completeness-box__list scsl-completeness-box__list--missing">
					<?php foreach ( $score['missing'] as $label ) : ?>
						<li><?php echo esc_html( (string) $label ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="scsl-completeness-box__done">
					<?php esc_html_e( 'Nothing missing. This sermon is complete.', 'seedcast-sermon-library' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $score['satisfied'] ) ) : ?>
				<p class="scsl-completeness-box__heading"><?php esc_html_e( 'Present', 'seedcast-sermon-library' ); ?></p>
				<ul class="scsl-completeness-box__list scsl-completeness-box__list--present">
					<?php foreach ( $score['satisfied'] as $label ) : ?>
						<li><?php echo esc_html( (string) $label ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $score['excluded'] ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: comma separated list of switched off sections. */
						esc_html__( 'Not counted because you have it switched off: %s', 'seedcast-sermon-library' ),
						esc_html( implode( ', ', array_map( 'strval', $score['excluded'] ) ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php
			$gap = \Seedcast\Core\Completeness::item_completion_gap( self::SOURCE, $post_id );
			if ( null !== $gap ) :
				?>
				<p class="description">
					<?php
					if ( 0 === $gap ) {
						esc_html_e( 'Finished on the day it was preached.', 'seedcast-sermon-library' );
					} else {
						printf(
							/* translators: %d: number of days. */
							esc_html( _n( 'Finished %d day after it was preached.', 'Finished %d days after it was preached.', $gap, 'seedcast-sermon-library' ) ),
							(int) $gap
						);
					}
					?>
				</p>
			<?php endif; ?>

			<p class="scsl-completeness-box__link">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=seedcast-sermon-library-completeness' ) ); ?>">
					<?php esc_html_e( 'See how your library is doing', 'seedcast-sermon-library' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
