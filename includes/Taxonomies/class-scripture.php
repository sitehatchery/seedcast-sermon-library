<?php
namespace SeedcastSermonLibrary\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) exit;

class Scripture {

	public function register(): void {
		$labels = [
			'name'          => __( 'Scripture References', 'seedcast-sermon-library' ),
			'singular_name' => __( 'Scripture',            'seedcast-sermon-library' ),
			'search_items'  => __( 'Search Scriptures',   'seedcast-sermon-library' ),
			'all_items'     => __( 'All Scriptures',       'seedcast-sermon-library' ),
			'edit_item'     => __( 'Edit Scripture',       'seedcast-sermon-library' ),
			'update_item'   => __( 'Update Scripture',     'seedcast-sermon-library' ),
			'add_new_item'  => __( 'Add New Scripture',    'seedcast-sermon-library' ),
			'new_item_name' => __( 'New Scripture',        'seedcast-sermon-library' ),
			'menu_name'     => __( 'Scriptures',           'seedcast-sermon-library' ),
		];

		register_taxonomy( 'scsl_scripture', [ 'scsl_sermon' ], [
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			/*
			 * No feeds. A passage archive has nothing a reader would subscribe
			 * to, and the feed route was answering slowly enough that crawlers
			 * recorded server errors against it. Turning the route off is the
			 * whole fix: the pages themselves are unaffected.
			 *
			 * 'feed', not 'feeds'. A taxonomy hands its rewrite arguments to
			 * add_permastruct, whose option is singular; the plural is the post
			 * type spelling and is ignored here without complaint.
			 */
			'rewrite'           => [ 'slug' => 'scripture', 'feed' => false ],
		] );
	}
}
