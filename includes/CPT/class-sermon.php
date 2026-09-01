<?php
namespace SeedcastSermonLibrary\CPT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Sermon {

	/**
	 * Keep chapter and verse apart in a new sermon's address.
	 *
	 * A sermon titled "Isaiah 59:16-17" is given the address isaiah-5916-17,
	 * because the colon is dropped rather than treated as a break. The result
	 * names a chapter and verse that do not exist and matches nothing anybody
	 * searches for.
	 *
	 * Only new sermons. An address already published is somebody's link, and
	 * one that has been corrected by hand is a decision. Neither is ours to
	 * overwrite on a later save.
	 *
	 * @param array $data    Post data about to be written.
	 * @param array $postarr The submitted post, including its ID if it has one.
	 * @return array
	 */
	public function readable_slug( array $data, array $postarr ): array {
		if ( 'scsl_sermon' !== ( $data['post_type'] ?? '' ) ) return $data;

		// Anything with an ID already exists and already has an address.
		if ( ! empty( $postarr['ID'] ) ) return $data;

		$title = trim( (string) ( $data['post_title'] ?? '' ) );

		if ( '' === $title ) return $data;

		$wanted = \SeedcastSermonLibrary\Slug::from( $title );

		if ( '' === $wanted || $wanted === ( $data['post_name'] ?? '' ) ) return $data;

		// Uniqueness is normally settled before this filter runs, so replacing
		// the slug here means settling it again or risking a collision.
		$data['post_name'] = wp_unique_post_slug(
			$wanted,
			0,
			(string) ( $data['post_status'] ?? 'draft' ),
			'scsl_sermon',
			(int) ( $data['post_parent'] ?? 0 )
		);

		return $data;
	}

	public function register(): void {
		add_filter( 'wp_insert_post_data', [ $this, 'readable_slug' ], 10, 2 );

		$slug     = get_option( 'scsl_sermon_slug',  'sermon' );
		$singular = get_option( 'scsl_label_sermon',  '' ) ?: __( 'Sermon',  'seedcast-sermon-library' );
		$plural   = get_option( 'scsl_label_sermons', '' ) ?: __( 'Sermons', 'seedcast-sermon-library' );

		register_post_type( 'scsl_sermon', [
			'labels' => [
				'name'               => $plural,
				'singular_name'      => $singular,
				'add_new'            => __( 'Add New', 'seedcast-sermon-library' ),
				// translators: %s is the post type singular name
				'add_new_item'       => sprintf( __( 'Add New %s', 'seedcast-sermon-library' ), $singular ),
				// translators: %s is the post type singular name
				'edit_item'          => sprintf( __( 'Edit %s', 'seedcast-sermon-library' ), $singular ),
				// translators: %s is the post type singular name
				'new_item'           => sprintf( __( 'New %s', 'seedcast-sermon-library' ), $singular ),
				// translators: %s is the post type singular name
				'view_item'          => sprintf( __( 'View %s', 'seedcast-sermon-library' ), $singular ),
				// translators: %s is the post type plural name
				'search_items'       => sprintf( __( 'Search %s', 'seedcast-sermon-library' ), $plural ),
				// translators: %s is the post type plural name
				'not_found'          => sprintf( __( 'No %s found', 'seedcast-sermon-library' ), strtolower( $plural ) ),
				// translators: %s is the post type plural name
				'not_found_in_trash' => sprintf( __( 'No %s in trash', 'seedcast-sermon-library' ), strtolower( $plural ) ),
				'menu_name'          => $plural,
			],
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => 'seedcast-sermon-library',
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => $slug, 'with_front' => false ],
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'supports'           => [ 'title', 'thumbnail', 'revisions' ],
		] );
	}
}
