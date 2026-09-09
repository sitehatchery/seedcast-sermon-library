<?php
/**
 * Meta box orchestrator: registers all meta boxes and routes save calls.
 *
 * The actual callbacks and save logic live in dedicated classes:
 *  - SermonMeta  (includes/Admin/class-sermon-meta.php)
 *  - SeriesMeta  (includes/Admin/class-series-meta.php)
 *  - SpeakerMeta (includes/Admin/class-speaker-meta.php)
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers all meta boxes for sl_sermon, sl_series, and sl_speaker post types,
 * removes auto-generated taxonomy boxes, and dispatches save_post callbacks.
 */
class MetaBoxes {

	/** @var SermonMeta  Handles sermon meta box callbacks and saving. */
	private SermonMeta $sermon_meta;

	/** @var SeriesMeta  Handles series meta box callback and saving. */
	private SeriesMeta $series_meta;

	/** @var SpeakerMeta Handles speaker meta box callback and saving. */
	private SpeakerMeta $speaker_meta;

	public function __construct() {
		$this->sermon_meta  = new SermonMeta();
		$this->series_meta  = new SeriesMeta();
		$this->speaker_meta = new SpeakerMeta();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes',        [ $this, 'register'          ] );

		/*
		 * Unlisted belongs beside Update, not in a content box.
		 *
		 * It is a decision about whether people can find the sermon, which is
		 * the same kind of decision as published or private. Somebody about to
		 * press Update is thinking about exactly that, and looking for it
		 * among the series and speaker is looking in the wrong place.
		 */
		add_action( 'post_submitbox_misc_actions', [ $this->sermon_meta, 'submitbox_unlisted' ] );
		add_action( 'add_meta_boxes',        [ $this, 'reorder_meta_boxes' ], 99 );
		add_filter( 'get_user_option_meta-box-order_scsl_sermon', [ $this, 'place_new_meta_boxes' ] );
		add_action( 'save_post',             [ $this, 'save'              ], 10, 2 );
		add_action( 'save_post',             [ $this, 'clear_post_cache'  ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts'   ] );
	}

	/**
	 * Register all meta boxes for sermon, series, and speaker post types.
	 */
	public function register(): void {
		// Sermon meta boxes
		add_meta_box( 'scsl_sermon_source',     __( 'Source & Media',       'seedcast-sermon-library' ), [ $this->sermon_meta,  'sermon_source_cb'     ], 'scsl_sermon', 'normal', 'high'    );
		add_meta_box( 'scsl_sermon_details',    __( 'Sermon Details',       'seedcast-sermon-library' ), [ $this->sermon_meta,  'sermon_details_cb'    ], 'scsl_sermon', 'normal', 'high'    );
		add_meta_box( 'scsl_sermon_podcast',    __( 'Audio Feed',           'seedcast-sermon-library' ), [ $this->sermon_meta,  'sermon_podcast_cb'    ], 'scsl_sermon', 'normal', 'default' );
		add_meta_box( 'scsl_sermon_content',    __( 'Content',              'seedcast-sermon-library' ), [ $this->sermon_meta,  'sermon_content_cb'    ], 'scsl_sermon', 'normal', 'default' );
		add_meta_box( 'scsl_sermon_questions',  __( 'Questions',            'seedcast-sermon-library' ), [ $this->sermon_meta,  'sermon_questions_cb'  ], 'scsl_sermon', 'normal', 'default' );
		add_meta_box( 'scsl_sermon_scripture',  __( 'Scripture',            'seedcast-sermon-library' ), [ $this->sermon_meta,  'sermon_scripture_cb'  ], 'scsl_sermon', 'normal', 'default' );
		add_meta_box( 'scsl_sermon_notes',      __( 'Sermon Notes',         'seedcast-sermon-library' ), [ $this->sermon_meta,  'sermon_notes_cb'      ], 'scsl_sermon', 'normal', 'default' );

		// Series and speaker meta boxes
		add_meta_box( 'scsl_series_details',    __( 'Series Details',  'seedcast-sermon-library' ), [ $this->series_meta,  'series_details_cb'    ], 'scsl_series',  'normal', 'high' );
		add_meta_box( 'scsl_speaker_details',   __( 'Speaker Details', 'seedcast-sermon-library' ), [ $this->speaker_meta, 'speaker_details_cb'   ], 'scsl_speaker', 'normal', 'high' );

		/*
		 * Remove the taxonomy boxes WordPress adds for itself. Both are managed
		 * from the Scripture and Topics boxes instead, and a checklist of
		 * seventeen hundred passages is not something anybody is going to tick.
		 *
		 * Both ids, because the one WordPress uses depends on the taxonomy:
		 * a flat one gets `tagsdiv-{taxonomy}` and a hierarchical one gets
		 * `{taxonomy}div`. Scripture became hierarchical in 2.72.0, which
		 * silently stopped the old removal from matching and put the checklist
		 * back on screen.
		 */
		foreach ( [ 'scsl_scripture', 'scsl_topic' ] as $taxonomy ) {
			foreach ( [ 'side', 'normal', 'advanced' ] as $context ) {
				remove_meta_box( 'tagsdiv-' . $taxonomy, 'scsl_sermon', $context );
				remove_meta_box( $taxonomy . 'div', 'scsl_sermon', $context );
			}
		}
	}

	/**
	 * Enqueue admin JS on sl_sermon, sl_series, sl_speaker edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, [ 'scsl_sermon', 'scsl_series', 'scsl_speaker' ], true ) ) return;

		wp_enqueue_style(  'scsl-admin', SCSL_PLUGIN_URL . 'assets/css/admin.css',   [], scsl_asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'scsl-admin', SCSL_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], scsl_asset_version( 'assets/js/admin.js' ), true );
		wp_localize_script( 'scsl-admin', 'scslAdmin', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'scsl_admin_nonce' ),
			'generating' => __( 'Generating…', 'seedcast-sermon-library' ),
		] );
	}

	/**
	 * Put a newly added box where it belongs in somebody's own arrangement.
	 *
	 * WordPress remembers the order each person dragged their meta boxes into,
	 * and that memory wins over the order they were registered in. A box added
	 * in a later release is not in that saved list, so it lands at the bottom
	 * of the screen for everybody who has ever moved anything, which is not
	 * where it was put and not where it reads.
	 *
	 * So the saved order is amended rather than overridden: each new box is
	 * inserted after the one it belongs with, and everything somebody arranged
	 * deliberately stays where they put it.
	 *
	 * @param mixed $order Saved order, or false when nothing was ever saved.
	 * @return mixed
	 */
	public function place_new_meta_boxes( $order ) {
		// Nothing saved means the registration order is already in force.
		if ( ! is_array( $order ) ) {
			return $order;
		}

		// New box id => the box it should follow.
		$after = [
			'scsl_sermon_questions' => 'scsl_sermon_content',
		];

		foreach ( $after as $box => $follows ) {
			$seen = false;

			foreach ( $order as $ids ) {
				if ( in_array( $box, array_filter( explode( ',', (string) $ids ) ), true ) ) {
					$seen = true;
					break;
				}
			}

			if ( $seen ) {
				continue;
			}

			foreach ( $order as $context => $ids ) {
				$list = array_filter( explode( ',', (string) $ids ) );
				$at   = array_search( $follows, $list, true );

				if ( false === $at ) {
					continue;
				}

				array_splice( $list, $at + 1, 0, [ $box ] );
				$order[ $context ] = implode( ',', $list );
				break;
			}
		}

		return $order;
	}

	/**
	 * Route save_post to the correct handler based on post type.
	 *
	 * @param int      $post_id Post ID being saved.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		switch ( $post->post_type ) {
			case 'scsl_sermon':
				$this->sermon_meta->save( $post_id );
				break;
			case 'scsl_series':
				$this->series_meta->save( $post_id );
				break;
			case 'scsl_speaker':
				$this->speaker_meta->save( $post_id );
				break;
		}
	}

	/**
	 * Set a preferred meta box order on the Edit Sermon screen.
	 */
	public function reorder_meta_boxes(): void {
		global $wp_meta_boxes;

		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'scsl_sermon' ) return;

		// Desired order for the 'normal' context
		$order = [
			'scsl_manifest_import',
			'scsl_sermon_source',
			'scsl_sermon_details',
			'scsl_sermon_content',
			'scsl_sermon_questions',
			'scsl_sermon_scripture',
			'scsl_sermon_podcast',
			'scsl_sermon_notes',
			'scsl_sermon_resources',
		];

		$context  = 'normal';
		$existing = $wp_meta_boxes['scsl_sermon'][ $context ] ?? [];
		$reordered = [];

		// Move boxes into the desired order, then append any extras at the end
		foreach ( [ 'high', 'sorted', 'core', 'default', 'low' ] as $priority ) {
			$reordered[ $priority ] = [];
		}

		foreach ( $order as $id ) {
			foreach ( $existing as $priority => $boxes ) {
				if ( isset( $boxes[ $id ] ) ) {
					$reordered[ $priority ][ $id ] = $boxes[ $id ];
					unset( $existing[ $priority ][ $id ] );
				}
			}
		}
		// Append any remaining boxes (custom / third-party)
		foreach ( $existing as $priority => $boxes ) {
			foreach ( $boxes as $id => $box ) {
				$reordered[ $priority ][ $id ] = $box;
			}
		}

		$wp_meta_boxes['scsl_sermon'][ $context ] = $reordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional reorder
	}

	/**
	 * Clear the post type meta cache after a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function clear_post_cache( int $post_id, \WP_Post $post ): void {
		if ( in_array( $post->post_type, [ 'scsl_sermon', 'scsl_series', 'scsl_speaker' ], true ) ) {
			delete_transient( 'scsl_cached_' . $post->post_type );
		}
	}
}
