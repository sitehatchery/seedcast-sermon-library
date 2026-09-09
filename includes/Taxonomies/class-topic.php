<?php
namespace SeedcastSermonLibrary\Taxonomies;

if ( ! defined( 'ABSPATH' ) ) exit;

class Topic {

	public function register(): void {
		$labels = [
			'name'              => __( 'Topics',          'seedcast-sermon-library' ),
			'singular_name'     => __( 'Topic',           'seedcast-sermon-library' ),
			'search_items'      => __( 'Search Topics',   'seedcast-sermon-library' ),
			'all_items'         => __( 'All Topics',      'seedcast-sermon-library' ),
			'edit_item'         => __( 'Edit Topic',      'seedcast-sermon-library' ),
			'update_item'       => __( 'Update Topic',    'seedcast-sermon-library' ),
			'add_new_item'      => __( 'Add New Topic',   'seedcast-sermon-library' ),
			'new_item_name'     => __( 'New Topic Name',  'seedcast-sermon-library' ),
			'menu_name'         => __( 'Topics',          'seedcast-sermon-library' ),
		];

		register_taxonomy( 'scsl_topic', [ 'scsl_sermon', 'scsl_series' ], [
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
			 */
			'rewrite'           => [ 'slug' => 'topic', 'feed' => false ],
		] );
	}
}
