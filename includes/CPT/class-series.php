<?php
namespace SeedcastSermonLibrary\CPT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Series {

	public function register(): void {
		$slug = get_option( 'scsl_series_slug', 'series' );

		$labels = [
			'name'               => __( 'Series',             'seedcast-sermon-library' ),
			'singular_name'      => __( 'Series',             'seedcast-sermon-library' ),
			'add_new'            => __( 'Add New Series',     'seedcast-sermon-library' ),
			'add_new_item'       => __( 'Add New Series',     'seedcast-sermon-library' ),
			'edit_item'          => __( 'Edit Series',        'seedcast-sermon-library' ),
			'new_item'           => __( 'New Series',         'seedcast-sermon-library' ),
			'view_item'          => __( 'View Series',        'seedcast-sermon-library' ),
			'search_items'       => __( 'Search Series',      'seedcast-sermon-library' ),
			'not_found'          => __( 'No series found',    'seedcast-sermon-library' ),
			'not_found_in_trash' => __( 'No series in trash', 'seedcast-sermon-library' ),
			'menu_name'          => __( 'Series',             'seedcast-sermon-library' ),
		];

		register_post_type( 'scsl_series', [
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => 'seedcast-sermon-library',
			'show_in_rest'        => true,
			'query_var'           => true,
			'rewrite'             => [ 'slug' => $slug, 'with_front' => false ],
			'capability_type'     => 'post',
			'has_archive'         => true,
			'hierarchical'        => false,
			'menu_position'       => null,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'page-attributes' ],
		] );

		// Series card image: 760px wide, proportional height (no crop).
		// Double the card display width for retina screens.
		add_image_size( 'scsl_series_card', 760, 0, false );
	}
}
