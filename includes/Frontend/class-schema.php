<?php
namespace SeedcastSermonLibrary\Frontend;

use Seedcast\Core\Frontend\Meta as CoreMeta;
use Seedcast\Core\Frontend\Schema as CoreSchema;
use Seedcast\Core\Frontend\VideoEmbed as CoreVideo;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Outputs JSON-LD structured data and SEO meta tags for SermonLibrary content types.
 * Handles VideoObject, AudioObject, BreadcrumbList, Person, and ItemList schemas.
 *
 * Meta tags (description, OG, Twitter) are only output when no dedicated SEO plugin
 * (Yoast, RankMath, All-in-One SEO) is active: they handle it better than we can.
 */
class Schema {

	public function init(): void {
		add_action( 'wp_head', [ $this, 'output'      ], 5  );
		add_action( 'wp_head', [ $this, 'output_meta' ], 2  ); // before schema, before Yoast at 1
	}

	/**
	 * True when a dedicated SEO plugin is active and handling meta tags.
	 * We defer to them rather than outputting duplicate tags.
	 */
	private function seo_plugin_active(): bool {
		return CoreMeta::seo_plugin_active();
	}

	/**
	 * Output meta description, Open Graph, and Twitter Card tags.
	 * Only fires on our CPT pages and only when no SEO plugin is active.
	 */
	public function output_meta(): void {
		if ( $this->seo_plugin_active() ) return;

		if ( is_singular( 'scsl_sermon' ) ) {
			$this->sermon_meta();
		} elseif ( is_singular( 'scsl_series' ) ) {
			$this->series_meta();
		} elseif ( is_singular( 'scsl_speaker' ) ) {
			$this->speaker_meta();
		}
	}

	private function sermon_meta(): void {
		$post_id    = get_the_ID();
		$post       = get_post( $post_id );
		$desc_raw   = get_post_meta( $post_id, '_scsl_content_description', true );
		$speaker_id = get_post_meta( $post_id, '_scsl_speaker_id', true );

		$title = get_the_title();
		if ( $speaker_id ) {
			$title .= ': ' . get_the_title( $speaker_id );
		}

		$description = $desc_raw
			? wp_trim_words( wp_strip_all_tags( $desc_raw ), 30 )
			: wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: '' ), 30 );

		$image = get_the_post_thumbnail_url( $post_id, 'large' )
			?: get_post_meta( $post_id, '_scsl_podcast_image', true );

		$this->print_meta_tags( $title, $description, $image, get_permalink( $post_id ), 'article' );
	}

	private function series_meta(): void {
		$post_id     = get_the_ID();
		$post        = get_post( $post_id );
		$description = wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 );
		$image       = get_the_post_thumbnail_url( $post_id, 'large' );
		$this->print_meta_tags( get_the_title(), $description, $image, get_permalink( $post_id ), 'article' );
	}

	private function speaker_meta(): void {
		$post_id     = get_the_ID();
		$post        = get_post( $post_id );
		$description = wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 );
		$image       = get_the_post_thumbnail_url( $post_id, 'large' );
		$this->print_meta_tags( get_the_title(), $description, $image, get_permalink( $post_id ), 'profile' );
	}

	/**
	 * Output the actual <meta> tags.
	 *
	 * @param string $title       Page/post title
	 * @param string $description Plain-text description (max ~160 chars)
	 * @param string $image       Absolute image URL or empty string
	 * @param string $url         Canonical URL
	 * @param string $og_type     Open Graph type (article, profile, website)
	 */
	/**
	 * Tag output lives in core. This plugin decides the title, description and
	 * image; the tag set is identical across the suite.
	 */
	private function print_meta_tags( string $title, string $description, string $image, string $url, string $og_type = 'article' ): void {
		CoreMeta::render( [
			'title'       => $title,
			'description' => $description,
			'image'       => $image,
			'url'         => $url,
			'type'        => $og_type,
		] );
	}

	public function output(): void {
		if ( is_singular( 'scsl_sermon' ) ) {
			$this->sermon_schema();
			return;
		}
		if ( is_singular( 'scsl_series' ) ) {
			$this->series_schema();
			return;
		}
		if ( is_singular( 'scsl_speaker' ) ) {
			$this->speaker_schema();
			return;
		}
		if ( is_post_type_archive( 'scsl_series' ) ) {
			$this->archive_schema();
		}
	}

	// ── Sermon Schema ─────────────────────────────────────────────────────

	private function sermon_schema(): void {
		$post_id    = get_the_ID();
		$post       = get_post( $post_id );
		$video_url  = get_post_meta( $post_id, '_scsl_video_url',           true );
		$audio_url  = get_post_meta( $post_id, '_scsl_audio_url',           true );
		$rec_date   = get_post_meta( $post_id, '_scsl_recorded_date',       true );
		$speaker_id = get_post_meta( $post_id, '_scsl_speaker_id',          true );
		$series_id  = get_post_meta( $post_id, '_scsl_series_id',           true );
		$desc       = get_post_meta( $post_id, '_scsl_content_description', true );
		$focus      = get_post_meta( $post_id, '_scsl_focus_passage',       true );

		$description = $desc
			? wp_strip_all_tags( $desc )
			: wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 40 );

		$thumbnail   = get_the_post_thumbnail_url( $post_id, 'large' );
		$permalink   = get_permalink( $post_id );
		$date_iso    = $rec_date ? gmdate( 'c', strtotime( $rec_date ) ) : gmdate( 'c', strtotime( $post->post_date ) );
		$modified    = gmdate( 'c', strtotime( $post->post_modified ) );
		$site_name   = get_bloginfo( 'name' );

		// Speaker data
		$speaker_schema = null;
		if ( $speaker_id ) {
			$speaker_schema = $this->person_schema( $speaker_id );
		}

		// Series data
		$series_schema = null;
		if ( $series_id ) {
			$series_schema = [
				'@type' => 'Series',
				'name'  => get_the_title( $series_id ),
				'url'   => get_permalink( $series_id ),
			];
		}

		// Build graph
		$graph = [];

		// Primary: Article / BlogPosting for the sermon
		$article = [
			'@type'            => 'Article',
			'@id'              => $permalink . '#article',
			'headline'         => get_the_title( $post_id ),
			'description'      => $description,
			'url'              => $permalink,
			'datePublished'    => $date_iso,
			'dateModified'     => $modified,
			'publisher'        => [
				'@type' => 'Organization',
				'name'  => $site_name,
				'url'   => home_url(),
			],
		];
		if ( $thumbnail ) $article['image'] = [ '@type' => 'ImageObject', 'url' => $thumbnail ];
		if ( $speaker_schema ) $article['author'] = $speaker_schema;
		if ( $series_schema )  $article['isPartOf'] = $series_schema;
		if ( $focus ) $article['keywords'] = $focus;
		$graph[] = $article;

		// VideoObject
		if ( $video_url ) {
			$video_id = $this->extract_youtube_id( $video_url );
			$video    = [
				'@type'           => 'VideoObject',
				'@id'             => $permalink . '#video',
				'name'            => get_the_title( $post_id ),
				'description'     => $description,
				'uploadDate'      => $date_iso,
				'url'             => $video_url,
			];
			if ( $thumbnail ) $video['thumbnailUrl'] = $thumbnail;
			if ( $speaker_schema ) $video['author'] = $speaker_schema;
			if ( $video_id ) {
				$video['embedUrl']   = 'https://www.youtube.com/embed/' . $video_id;
				$video['contentUrl'] = $video_url;
			}
			$graph[] = $video;
		}

		// AudioObject
		if ( $audio_url ) {
			$audio = [
				'@type'        => 'AudioObject',
				'@id'          => $permalink . '#audio',
				'name'         => get_the_title( $post_id ),
				'description'  => $description,
				'contentUrl'   => $audio_url,
				'uploadDate'   => $date_iso,
				'encodingFormat' => 'audio/mpeg',
			];
			if ( $speaker_schema ) $audio['author'] = $speaker_schema;
			$graph[] = $audio;
		}

		// BreadcrumbList
		$crumbs = [
			[ 'label' => $site_name, 'url' => home_url() ],
			[ 'label' => __( 'Sermon Library', 'seedcast-sermon-library' ), 'url' => get_post_type_archive_link( 'scsl_series' ) ],
		];
		if ( $series_id ) {
			$crumbs[] = [ 'label' => get_the_title( $series_id ), 'url' => get_permalink( $series_id ) ];
		}
		$crumbs[] = [ 'label' => get_the_title( $post_id ), 'url' => $permalink ];
		$graph[]  = $this->breadcrumb_schema( $crumbs );

		/*
		 * Only describes questions the page actually shows. Structured data is
		 * not allowed to claim content a visitor cannot read, and Faq::schema()
		 * returns null whenever the section did not render.
		 */
		$faq = Faq::schema( $post_id );

		if ( $faq ) {
			$graph[] = $faq;
		}

		$this->print_schema( $graph );
	}

	// ── Series Schema ─────────────────────────────────────────────────────

	private function series_schema(): void {
		$post_id    = get_the_ID();
		$post       = get_post( $post_id );
		$permalink  = get_permalink( $post_id );
		$thumbnail  = get_the_post_thumbnail_url( $post_id, 'large' );
		$start_date = get_post_meta( $post_id, '_scsl_series_start_date', true );
		$end_date   = get_post_meta( $post_id, '_scsl_series_end_date',   true );
		$site_name  = get_bloginfo( 'name' );

		$description = wp_trim_words(
			wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ),
			40
		);

		// Get sermons in series
		$sermons = get_posts( [
			'post_type'      => 'scsl_sermon',
			'meta_key'       => '_scsl_series_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );

		$graph = [];

		$series = [
			'@type'       => 'ItemList',
			'@id'         => $permalink . '#series',
			'name'        => get_the_title( $post_id ),
			'description' => $description,
			'url'         => $permalink,
			'numberOfItems' => count( $sermons ),
		];
		if ( $thumbnail ) $series['image']     = $thumbnail;
		if ( $start_date ) $series['startDate'] = gmdate( 'c', strtotime( $start_date ) );
		if ( $end_date )   $series['endDate']   = gmdate( 'c', strtotime( $end_date ) );

		if ( $sermons ) {
			$items = [];
			$pos   = 1;
			foreach ( $sermons as $sid ) {
				$items[] = [
					'@type'    => 'ListItem',
					'position' => $pos++,
					'url'      => get_permalink( $sid ),
					'name'     => get_the_title( $sid ),
				];
			}
			$series['itemListElement'] = $items;
		}
		$graph[] = $series;

		// Breadcrumb
		$graph[] = $this->breadcrumb_schema( [
			[ 'label' => $site_name, 'url' => home_url() ],
			[ 'label' => __( 'Sermon Library', 'seedcast-sermon-library' ), 'url' => get_post_type_archive_link( 'scsl_series' ) ],
			[ 'label' => get_the_title( $post_id ), 'url' => $permalink ],
		] );

		$this->print_schema( $graph );
	}

	// ── Speaker Schema ────────────────────────────────────────────────────

	private function speaker_schema(): void {
		$post_id   = get_the_ID();
		$permalink = get_permalink( $post_id );
		$site_name = get_bloginfo( 'name' );

		$graph     = [];
		$graph[]   = $this->person_schema( $post_id, true );
		$graph[]   = $this->breadcrumb_schema( [
			[ 'label' => $site_name, 'url' => home_url() ],
			[ 'label' => get_the_title( $post_id ), 'url' => $permalink ],
		] );

		$this->print_schema( $graph );
	}

	// ── Archive Schema ────────────────────────────────────────────────────

	private function archive_schema(): void {
		global $wp_query;

		$site_name = get_bloginfo( 'name' );
		$url       = get_post_type_archive_link( 'scsl_series' );

		$items = [];
		$pos   = 1;
		while ( have_posts() ) {
			the_post();
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'url'      => get_permalink(),
				'name'     => get_the_title(),
			];
		}
		rewind_posts();

		$graph = [ [
			'@type'           => 'CollectionPage',
			'name'            => __( 'Sermon Library', 'seedcast-sermon-library' ) . ': ' . $site_name,
			'url'             => $url,
			'numberOfItems'   => $wp_query->found_posts,
			'itemListElement' => $items,
		] ];

		$this->print_schema( $graph );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function person_schema( int $speaker_id, bool $full = false ): array {
		$name    = get_the_title( $speaker_id );
		$title   = get_post_meta( $speaker_id, '_scsl_speaker_title',   true );
		$website = get_post_meta( $speaker_id, '_scsl_speaker_website', true );
		$twitter = get_post_meta( $speaker_id, '_scsl_speaker_twitter', true );
		$photo   = get_the_post_thumbnail_url( $speaker_id, 'medium' );

		$schema = [
			'@type' => 'Person',
			'name'  => $name,
			'url'   => get_permalink( $speaker_id ),
		];

		if ( $title )   $schema['jobTitle'] = $title;
		if ( $photo )   $schema['image']    = $photo;
		if ( $website ) $schema['sameAs']   = [ $website ];
		if ( $twitter ) {
			$handles = isset( $schema['sameAs'] ) ? $schema['sameAs'] : [];
			$handles[] = 'https://twitter.com/' . ltrim( $twitter, '@' );
			$schema['sameAs'] = $handles;
		}

		if ( $full ) {
			$post        = get_post( $speaker_id );
			$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
			if ( $description ) $schema['description'] = $description;

			// Link to their sermons
			$sermon_ids = get_posts( [
				'post_type'      => 'scsl_sermon',
				'meta_key'       => '_scsl_speaker_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $speaker_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 5,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			] );
			if ( $sermon_ids ) {
				$schema['workExample'] = array_map( function( $sid ) {
					return [ '@type' => 'CreativeWork', 'url' => get_permalink( $sid ), 'name' => get_the_title( $sid ) ];
				}, $sermon_ids );
			}
		}

		return $schema;
	}

	/**
	 * Built by core from the same crumb array the visible trail uses, so the
	 * two cannot drift apart.
	 */
	private function breadcrumb_schema( array $crumbs ): array {
		return CoreSchema::breadcrumbs( $crumbs );
	}

	private function extract_youtube_id( string $url ): string {
		return CoreVideo::youtube_id( $url );
	}

	private function print_schema( array $graph ): void {
		CoreSchema::emit( $graph );
	}
	/**
	 * Ensure our CPTs appear in Yoast and RankMath sitemaps.
	 * Both plugins auto-include public CPTs, but this makes it explicit.
	 */
	private function register_sitemap_hooks(): void {
		// Yoast: ensure CPTs are not excluded
		add_filter( 'wpseo_sitemap_post_types', function( $types ) {
			$types[] = 'scsl_sermon';
			$types[] = 'scsl_series';
			$types[] = 'scsl_speaker';
			return array_unique( $types );
		} );
	}


}