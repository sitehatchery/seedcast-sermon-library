<?php
/**
 * Revisions for sermon content.
 *
 * WordPress revisions only ever store the post title, content and excerpt.
 * Everything a sermon actually holds, the description, the article, the Bible
 * study, the transcript, lives in post meta, so none of it had any history at
 * all. A field replaced by hand or by a generation was simply gone.
 *
 * This registers those fields with the revision system, so the ordinary
 * Revisions screen shows them, compares them and restores them like any other
 * content. Nothing here is specific to AI: an edit somebody makes by hand is
 * just as recoverable, which is the point.
 *
 * == Why this is more awkward than it looks ==
 *
 * WordPress creates the revision inside wp_insert_post(), which runs before
 * save_post, and metaboxes save their fields on save_post. So at the moment a
 * revision is made, the post's meta is still the previous values. Copying it
 * then produces a revision holding the new title beside the old article, and
 * asking "did the meta change" then always answers no, so a change to nothing
 * but the article never triggers a revision at all.
 *
 * The work is therefore deferred: note which revision was made, wait until
 * every metabox has saved, and only then write the fields onto it. When the
 * only thing that changed was meta, no revision will have been made yet, so
 * one is asked for at that point instead, by which time the comparison has
 * real values to look at.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

use SeedcastSermonLibrary\Import\FieldMap;

if ( ! defined( 'ABSPATH' ) ) exit;

class Revisions {

	/**
	 * Set on every revision this class writes to, so a revision from before
	 * can be told apart from one where the fields genuinely were empty.
	 */
	const MARKER = '_scsl_revision_fields';

	/**
	 * Revisions created during this request, keyed by the post they belong to.
	 *
	 * @var array<int, int>
	 */
	private static array $pending = [];

	/**
	 * Meta keys kept in revision history, named as the Content tabs name them.
	 *
	 * Only fields holding text a person would want back. Ids, dates and flags
	 * are excluded: they are cheap to set again and would make every diff
	 * noisy enough that nobody reads it.
	 *
	 * @return array<string, string>
	 */
	public static function fields(): array {
		$fields = FieldMap::labels();

		// Already a revision field in its own right.
		unset( $fields['post_title'] );

		$fields['_scsl_notes_intro'] = __( 'Notes introduction', 'seedcast-sermon-library' );
		$fields['_scsl_more_label']  = __( 'More tab name', 'seedcast-sermon-library' );

		/**
		 * Sermon fields kept in revision history.
		 *
		 * @param array $fields Meta key to label.
		 */
		return (array) apply_filters( 'scsl_revision_fields', $fields );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( '_wp_post_revision_fields', [ $this, 'add_fields' ], 10, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', [ $this, 'has_changed' ], 10, 3 );
		add_action( '_wp_put_post_revision', [ $this, 'note_revision' ] );

		// After every metabox has written its fields. The whole point is to
		// run once the post actually holds what was just submitted.
		add_action( 'save_post', [ $this, 'store_fields' ], 9999, 2 );

		add_action( 'wp_restore_post_revision', [ $this, 'restore_from_revision' ], 10, 2 );

		// For anything that writes these fields outside a form submission, an
		// import or a generation, where save_post never fires.
		add_action( 'scsl_capture_revision', [ $this, 'capture' ] );

		foreach ( array_keys( self::fields() ) as $key ) {
			add_filter( '_wp_post_revision_field_' . $key, [ $this, 'render_field' ], 10, 2 );
		}
	}

	/**
	 * Whether a post is one this applies to.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_sermon( int $post_id ): bool {
		return 'scsl_sermon' === get_post_type( $post_id );
	}

	/**
	 * Declare the extra fields to the revision system.
	 *
	 * @param array $fields Revision fields.
	 * @param array $post   The post being revised, as an array.
	 * @return array
	 */
	public function add_fields( $fields, $post = null ) {
		if ( ! is_array( $fields ) ) return $fields;

		// The screen that lists revisions calls this without a post, so the
		// fields have to be declared then too or the comparison is empty.
		$post_type = is_array( $post ) ? ( $post['post_type'] ?? '' ) : '';

		if ( '' !== $post_type && 'scsl_sermon' !== $post_type ) return $fields;

		foreach ( self::fields() as $key => $label ) {
			$fields[ $key ] = $label;
		}

		return $fields;
	}

	/**
	 * Make a change to any of these fields worth a revision.
	 *
	 * A sermon has no main editor, so its post_content never changes and
	 * WordPress would otherwise conclude nothing happened and store nothing,
	 * however much of the article was rewritten.
	 *
	 * Only meaningful once the meta has been saved, which is why the request
	 * for a revision is made late rather than left to wp_insert_post.
	 *
	 * @param bool     $has_changed Whether WordPress thinks anything changed.
	 * @param \WP_Post $last        The most recent revision.
	 * @param \WP_Post $post        The post being saved.
	 * @return bool
	 */
	public function has_changed( $has_changed, $last, $post ) {
		if ( $has_changed ) return true;

		if ( ! $post instanceof \WP_Post || 'scsl_sermon' !== $post->post_type ) return $has_changed;
		if ( ! $last instanceof \WP_Post ) return $has_changed;

		// An older revision, from before any of this existed. It records
		// nothing about these fields, so a new revision that does is worth
		// having.
		if ( ! get_metadata( 'post', $last->ID, self::MARKER, true ) ) return true;

		foreach ( array_keys( self::fields() ) as $key ) {
			$now    = (string) get_post_meta( $post->ID, $key, true );
			$before = (string) get_metadata( 'post', $last->ID, $key, true );

			if ( $now !== $before ) return true;
		}

		return $has_changed;
	}

	/**
	 * Remember a revision WordPress just made, to be filled in shortly.
	 *
	 * Nothing is copied here. The meta still holds the previous values at this
	 * point in the save, so writing it now is exactly the bug this avoids.
	 *
	 * @param int $revision_id Revision ID.
	 * @return void
	 */
	public function note_revision( $revision_id ): void {
		$revision_id = absint( $revision_id );
		$parent_id   = absint( wp_is_post_revision( $revision_id ) );

		if ( $parent_id && $this->is_sermon( $parent_id ) ) {
			self::$pending[ $parent_id ] = $revision_id;
		}
	}

	/**
	 * Write the fields onto this save's revision, once they have been saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function store_fields( $post_id, $post ): void {
		$post_id = absint( $post_id );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( ! $post instanceof \WP_Post || 'scsl_sermon' !== $post->post_type ) return;
		if ( 'auto-draft' === $post->post_status ) return;

		// WordPress made a revision earlier in this save, because the title
		// changed. It is the right one to describe this save.
		if ( isset( self::$pending[ $post_id ] ) ) {
			$this->copy_to( self::$pending[ $post_id ], $post_id );
			unset( self::$pending[ $post_id ] );
			return;
		}

		// Nothing WordPress watches changed, so no revision was made. Ask
		// again now that the fields hold what was submitted, and this time
		// has_changed can see the difference.
		wp_save_post_revision( $post_id );

		if ( isset( self::$pending[ $post_id ] ) ) {
			$this->copy_to( self::$pending[ $post_id ], $post_id );
			unset( self::$pending[ $post_id ] );
		}
	}

	/**
	 * Record the current state, for a change made outside a form submission.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function capture( $post_id ): void {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! $this->is_sermon( $post_id ) ) return;

		wp_save_post_revision( $post_id );

		if ( isset( self::$pending[ $post_id ] ) ) {
			$this->copy_to( self::$pending[ $post_id ], $post_id );
			unset( self::$pending[ $post_id ] );
		}
	}

	/**
	 * Copy the current fields onto a revision.
	 *
	 * Uses the raw metadata functions because a revision is not a post type
	 * the usual helpers will write to.
	 *
	 * @param int $revision_id Revision ID.
	 * @param int $post_id     Post the revision belongs to.
	 * @return void
	 */
	private function copy_to( int $revision_id, int $post_id ): void {
		// Marks this revision as one that carries sermon fields. Revisions
		// made before this existed hold none, and restoring one of those must
		// not be read as "every field was empty back then" and wipe them.
		update_metadata( 'post', $revision_id, self::MARKER, 1 );

		foreach ( array_keys( self::fields() ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( '' === $value || null === $value ) {
				delete_metadata( 'post', $revision_id, $key );
				continue;
			}

			update_metadata( 'post', $revision_id, $key, $value );
		}
	}

	/**
	 * Put the fields back when a revision is restored.
	 *
	 * @param int $post_id     Post being restored to.
	 * @param int $revision_id Revision being restored from.
	 * @return void
	 */
	public function restore_from_revision( $post_id, $revision_id ): void {
		$post_id     = absint( $post_id );
		$revision_id = absint( $revision_id );

		if ( ! $this->is_sermon( $post_id ) ) return;

		// An older revision, from before sermon fields were kept. It has
		// nothing to say about them, so it says nothing rather than emptying
		// them. The title and dates still restore normally.
		if ( ! get_metadata( 'post', $revision_id, self::MARKER, true ) ) return;

		foreach ( array_keys( self::fields() ) as $key ) {
			$value = get_metadata( 'post', $revision_id, $key, true );

			// A field that was empty at that point in history should come back
			// empty, rather than keeping whatever is there now.
			if ( '' === $value || null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}

			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Make a field readable on the comparison screen.
	 *
	 * The diff is line by line, so markup is stripped and block endings become
	 * line breaks. Comparing raw HTML would show a wall of tag changes and
	 * hide the words, which are the thing anybody is actually looking for.
	 *
	 * @param string $value Stored value.
	 * @param string $field Field name.
	 * @return string
	 */
	public function render_field( $value, $field = '' ) {
		$value = (string) $value;

		if ( '' === $value ) return '';

		$value = str_replace(
			[ '</p>', '<br />', '<br>', '</li>', '</h2>', '</h3>', '</h4>' ],
			"\n",
			$value
		);

		return trim( wp_strip_all_tags( $value ) );
	}
}
