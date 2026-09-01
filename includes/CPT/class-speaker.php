<?php
namespace SeedcastSermonLibrary\CPT;

if ( ! defined( 'ABSPATH' ) ) exit;

class Speaker {

	public function register(): void {
		$slug = get_option( 'scsl_speaker_slug', 'speakers' );

		$labels = [
			'name'               => __( 'Speakers',             'seedcast-sermon-library' ),
			'singular_name'      => __( 'Speaker',              'seedcast-sermon-library' ),
			'add_new'            => __( 'Add New Speaker',      'seedcast-sermon-library' ),
			'add_new_item'       => __( 'Add New Speaker',      'seedcast-sermon-library' ),
			'edit_item'          => __( 'Edit Speaker',         'seedcast-sermon-library' ),
			'new_item'           => __( 'New Speaker',          'seedcast-sermon-library' ),
			'view_item'          => __( 'View Speaker',         'seedcast-sermon-library' ),
			'search_items'       => __( 'Search Speakers',      'seedcast-sermon-library' ),
			'not_found'          => __( 'No speakers found',    'seedcast-sermon-library' ),
			'not_found_in_trash' => __( 'No speakers in trash', 'seedcast-sermon-library' ),
			'menu_name'          => __( 'Speakers',             'seedcast-sermon-library' ),
		];

		register_post_type( 'scsl_speaker', [
			'labels'             => $labels,
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
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
		] );

		// Speaker card: same size as series card: 2× display width for retina
		add_image_size( 'scsl_speaker_card', 760, 0, false );
		// Speaker thumbnail: small circle on episode cards (2× of ~56px display size)
		add_image_size( 'scsl_speaker_thumb', 160, 160, true );
	}
}
