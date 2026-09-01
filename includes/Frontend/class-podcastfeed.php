<?php
namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generates a valid podcast RSS feed from sermon audio files.
 *
 * Feed URLs:
 *   /sermons/feed/podcast/          : all sermons with audio
 *   /sermons/feed/podcast/?series=123 : sermons in a specific series
 *   /sermons/feed/podcast/?speaker=456: sermons by a specific speaker
 *   /sermons/feed/podcast/?topic=slug : sermons with a specific topic
 *
 * Compatible with Apple Podcasts, Spotify, and any standard RSS reader.
 */
class PodcastFeed {

	public function init(): void {
		add_action( 'init',             [ $this, 'add_feed'    ] );
		add_action( 'wp_head',          [ $this, 'add_link_tag'] );
		add_filter( 'query_vars',       [ $this, 'query_vars'  ] );
	}

	public function query_vars( array $vars ): array {
		$vars[] = 'scsl_podcast_series';
		$vars[] = 'scsl_podcast_speaker';
		$vars[] = 'scsl_podcast_topic';
		return $vars;
	}

	public function add_feed(): void {
		add_feed( 'podcast', [ $this, 'render_feed' ] );
	}

	public function add_link_tag(): void {
		if ( ! is_singular( 'scsl_sermon' ) && ! is_post_type_archive( 'scsl_series' ) ) return;
		$site_name = get_bloginfo( 'name' );
		$feed_url  = get_feed_link( 'podcast' );
		printf(
			'<link rel="alternate" type="application/rss+xml" title="%s: Sermons" href="%s" />' . "\n",
			esc_attr( $site_name ),
			esc_url( $feed_url )
		);
	}

	public function render_feed(): void {
		$series_id  = absint( get_query_var( 'scsl_podcast_series',  0 ) );
		$speaker_id = absint( get_query_var( 'scsl_podcast_speaker', 0 ) );
		$topic_slug = sanitize_text_field( get_query_var( 'scsl_podcast_topic', '' ) );

		// Also support ?series=, ?speaker=, ?topic= query params for clean URLs
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! $series_id  && isset( $_GET['series']  ) ) $series_id  = absint( wp_unslash( $_GET['series']  ) );
		if ( ! $speaker_id && isset( $_GET['speaker'] ) ) $speaker_id = absint( wp_unslash( $_GET['speaker'] ) );
		if ( ! $topic_slug && isset( $_GET['topic']   ) ) $topic_slug = sanitize_text_field( wp_unslash( $_GET['topic'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, absint( get_option( 'scsl_podcast_items' ) ?: 50 ) ),
			'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'order'          => 'DESC',
		];

		$meta_query = [ [
			'key'     => '_scsl_audio_url',
			'value'   => '',
			'compare' => '!=',
		] ];

		// Kept out means kept out. This feed had only ever asked whether a
		// sermon has audio, so a sermon ticked to stay out of the feed on the
		// sermon screen went into it anyway, and the setting did nothing on
		// the one screen it appears on.
		$meta_query[] = [
			'relation' => 'OR',
			[
				'key'     => '_scsl_podcast_exclude',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => '_scsl_podcast_exclude',
				'value'   => 'yes',
				'compare' => '!=',
			],
		];

		if ( $series_id )  $meta_query[] = [ 'key' => '_scsl_series_id',  'value' => $series_id ];
		if ( $speaker_id ) $meta_query[] = [ 'key' => '_scsl_speaker_id', 'value' => $speaker_id ];
		if ( $meta_query ) $args['meta_query'] = $meta_query;  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- sermon plugin requires these queries

		if ( $topic_slug ) {
			$args['tax_query'] = [ [  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- sermon plugin requires these queries
				'taxonomy' => 'scsl_topic',
				'field'    => 'slug',
				'terms'    => $topic_slug,
			] ];
		}

		$sermons   = new \WP_Query( $args );
		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' ) ?: $site_name . ' Sermon Podcast';
		$site_url  = home_url();
		$feed_url  = get_feed_link( 'podcast' );

		// Show level settings, each falling back to site information so a feed
		// is valid even if nothing has been filled in.
		$show_title    = trim( (string) get_option( 'scsl_podcast_title', '' ) );
		$show_desc     = trim( (string) get_option( 'scsl_podcast_description', '' ) );
		$show_author   = trim( (string) get_option( 'scsl_podcast_author', '' ) ) ?: $site_name;
		$owner_email   = trim( (string) get_option( 'scsl_podcast_owner_email', '' ) ) ?: get_option( 'admin_email' );
		$language      = trim( (string) get_option( 'scsl_podcast_language', '' ) ) ?: 'en-us';
		$explicit      = get_option( 'scsl_podcast_explicit', 'no' ) === 'yes' ? 'true' : 'false';
		$show_website  = trim( (string) get_option( 'scsl_podcast_website', '' ) ) ?: $site_url;
		$subtitle      = trim( (string) get_option( 'scsl_podcast_subtitle', '' ) );
		$copyright     = trim( (string) get_option( 'scsl_podcast_copyright', '' ) )
			?: html_entity_decode( '&copy;', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' ' . gmdate( 'Y' ) . ' ' . $site_name;
		$new_feed_url  = trim( (string) get_option( 'scsl_podcast_redirect', '' ) );

		if ( $show_desc ) {
			$site_desc = $show_desc;
		}

		// Category is stored as "Parent > Child"; the child is optional.
		$category      = (string) get_option( 'scsl_podcast_category', 'Religion & Spirituality > Christianity' );
		$category_bits = array_map( 'trim', explode( '>', $category ) );
		$cat_parent    = $category_bits[0] ?? 'Religion & Spirituality';
		$cat_child     = $category_bits[1] ?? '';

		// Podcast title varies by filter
		$podcast_title = $show_title ?: $site_name . ' - Sermons';
		if ( $series_id  ) $podcast_title = get_the_title( $series_id )  . ' | ' . $site_name;
		if ( $speaker_id ) $podcast_title = get_the_title( $speaker_id ) . ' | ' . $site_name;
		if ( $topic_slug ) {
			$term = get_term_by( 'slug', $topic_slug, 'scsl_topic' );
			if ( $term ) $podcast_title = $term->name . ' | ' . $site_name;
		}

		// Cover art: the podcast setting first, then the site icon.
		$artwork_url = '';
		$artwork_id  = absint( get_option( 'scsl_podcast_artwork', 0 ) );
		if ( $artwork_id ) {
			$artwork_url = (string) wp_get_attachment_image_url( $artwork_id, 'full' );
		}
		if ( ! $artwork_url ) {
			$artwork_url = (string) get_site_icon_url( 1400 );
		}

		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		?>
		<rss version="2.0"
			 xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
			 xmlns:content="http://purl.org/rss/1.0/modules/content/"
			 xmlns:atom="http://www.w3.org/2005/Atom">
		  <channel>
			<title><?php echo esc_html( $podcast_title ); ?></title>
			<link><?php echo esc_url( $show_website ); ?></link>
			<description><?php echo esc_html( $site_desc ); ?></description>
			<language><?php echo esc_html( $language ); ?></language>
			<atom:link href="<?php echo esc_url( $feed_url ); ?>" rel="self" type="application/rss+xml" />
			<itunes:author><?php echo esc_html( $show_author ); ?></itunes:author>
			<itunes:summary><?php echo esc_html( $site_desc ); ?></itunes:summary>
			<itunes:explicit><?php echo esc_html( $explicit ); ?></itunes:explicit>
			<itunes:type>episodic</itunes:type>
			<?php if ( $new_feed_url ) : ?>
			<?php /* Tells directories the show has moved. Keep the old feed online for a couple of weeks so every app sees this. */ ?>
			<itunes:new-feed-url><?php echo esc_url( $new_feed_url ); ?></itunes:new-feed-url>
			<?php endif; ?>
			<?php if ( $subtitle ) : ?>
			<itunes:subtitle><?php echo esc_html( $subtitle ); ?></itunes:subtitle>
			<?php endif; ?>
			<copyright><?php echo esc_html( $copyright ); ?></copyright>
			<itunes:owner>
				<itunes:name><?php echo esc_html( $show_author ); ?></itunes:name>
				<itunes:email><?php echo esc_html( $owner_email ); ?></itunes:email>
			</itunes:owner>
			<itunes:category text="<?php echo esc_attr( $cat_parent ); ?>">
				<?php if ( $cat_child ) : ?>
				<itunes:category text="<?php echo esc_attr( $cat_child ); ?>" />
				<?php endif; ?>
			</itunes:category>
			<?php if ( $artwork_url ) : ?>
			<itunes:image href="<?php echo esc_url( $artwork_url ); ?>" />
			<image>
				<url><?php echo esc_url( $artwork_url ); ?></url>
				<title><?php echo esc_html( $podcast_title ); ?></title>
				<link><?php echo esc_url( $show_website ); ?></link>
			</image>
			<?php endif; ?>

			<?php while ( $sermons->have_posts() ) : $sermons->the_post();
				$post_id     = get_the_ID();
				$audio_url   = get_post_meta( $post_id, '_scsl_audio_url',           true );
				$rec_date    = get_post_meta( $post_id, '_scsl_recorded_date',       true );
				$speaker_id_ = get_post_meta( $post_id, '_scsl_speaker_id',          true );
				$series_id_  = get_post_meta( $post_id, '_scsl_series_id',           true );
				$description = get_post_meta( $post_id, '_scsl_content_description', true );
				$transcript  = get_post_meta( $post_id, '_scsl_transcript_clean',    true );
				$focus       = get_post_meta( $post_id, '_scsl_focus_passage',       true );

				// Podcast Details overrides
				$pd_exclude       = get_post_meta( $post_id, '_scsl_podcast_exclude',        true );
				$pd_audio_size    = get_post_meta( $post_id, '_scsl_podcast_audio_size',      true );
				$pd_image         = get_post_meta( $post_id, '_scsl_podcast_image',           true );
				$pd_include_series = get_option( 'scsl_podcast_include_series', 'yes' );

				if ( ! $audio_url || $pd_exclude === 'yes' ) continue;

				// Check if audio is a direct file (not a streaming platform link)
				$is_streaming = (
					strpos( $audio_url, 'spotify.com' ) !== false ||
					strpos( $audio_url, 'podcasts.apple' ) !== false ||
					strpos( $audio_url, 'soundcloud.com' ) !== false
				);
				if ( $is_streaming ) continue;

				// File size: manual override first, then HTTP header lookup
				$file_size = $pd_audio_size ? absint( $pd_audio_size ) : 0;
				if ( ! $file_size ) {
					$head_response = wp_remote_head( $audio_url );
					if ( ! is_wp_error( $head_response ) ) {
						$content_length = wp_remote_retrieve_header( $head_response, 'content-length' );
						if ( $content_length ) {
							$file_size = absint( $content_length );
						}
					}
				}

				$pub_date     = $rec_date ? gmdate( 'D, d M Y H:i:s +0000', strtotime( $rec_date ) ) : get_the_date( 'D, d M Y H:i:s +0000' );
				$speaker_name = $speaker_id_ ? get_the_title( $speaker_id_ ) : $site_name;
				$summary      = $description ?: wp_trim_words( $transcript ?: get_the_excerpt(), 40 );

				// Episode title: optionally append series name
				$ep_title = get_the_title();
				if ( $pd_include_series === 'yes' && $series_id_ ) {
					$ep_title .= ': ' . get_the_title( $series_id_ );
				}

				// Image priority: per-sermon override → sermon thumbnail → series thumbnail → site artwork
				$thumb = $pd_image ?: get_the_post_thumbnail_url( $post_id, 'medium' );
				if ( ! $thumb && $series_id_ ) {
					$thumb = get_the_post_thumbnail_url( $series_id_, 'medium' );
				}
				?>
			<item>
				<title><?php echo esc_html( $ep_title ); ?></title>
				<link><?php echo esc_url( get_permalink() ); ?></link>
				<guid isPermaLink="true"><?php echo esc_url( get_permalink() ); ?></guid>
				<pubDate><?php echo esc_html( $pub_date ); ?></pubDate>
				<description><?php echo esc_html( $summary ); ?></description>
				<content:encoded><![CDATA[<?php echo wp_kses_post( wpautop( $summary ) ); ?>]]></content:encoded>
				<enclosure url="<?php echo esc_url( $audio_url ); ?>"
						   length="<?php echo esc_attr( $file_size ); ?>"
						   type="audio/mpeg" />
				<itunes:author><?php echo esc_html( $speaker_name ); ?></itunes:author>
				<itunes:summary><?php echo esc_html( $summary ); ?></itunes:summary>
				<itunes:explicit><?php echo esc_html( $explicit ); ?></itunes:explicit>
				<itunes:duration><?php echo esc_html( $this->get_duration( $post_id ) ); ?></itunes:duration>
				<?php if ( $focus ) : ?>
				<itunes:subtitle><?php echo esc_html( $focus ); ?></itunes:subtitle>
				<?php endif; ?>
				<?php if ( $thumb ) : ?>
				<itunes:image href="<?php echo esc_url( $thumb ); ?>" />
				<?php endif; ?>
			</item>
			<?php endwhile; wp_reset_postdata(); ?>

		  </channel>
		</rss>
		<?php
		exit;
	}

	/**
	 * Get stored duration. Prefers manual Podcast Details override,
	 * falls back to the auto-detected audio duration from import.
	 */
	private function get_duration( int $post_id ): string {
		$manual = get_post_meta( $post_id, '_scsl_podcast_audio_length', true );
		if ( $manual ) return sanitize_text_field( $manual );
		$dur = get_post_meta( $post_id, '_scsl_audio_duration', true );
		return $dur ? sanitize_text_field( $dur ) : '';
	}
}
