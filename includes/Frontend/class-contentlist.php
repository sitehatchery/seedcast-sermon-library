<?php
/**
 * A list of sermon content for anywhere on the site.
 *
 * One shortcode rather than one per kind of content. They all ask the same
 * question of the same post type and differ only in which field has to be
 * filled in and where the link points, so a new kind costs a line here rather
 * than a file of its own.
 *
 * Nothing here draws its own cards. It uses the same partials and the same
 * slider as the series and sermon shortcodes, so a church that has chosen a
 * theme gets that theme, and a card looks the same wherever it appears.
 *
 * Articles and Bible studies link to the sermon with the right tab named in
 * the address, so the same words are never published at a second URL.
 *
 * @package SeedcastSermonLibrary
 */

namespace SeedcastSermonLibrary\Frontend;

use SeedcastSermonLibrary\Import\FieldMap;

if ( ! defined( 'ABSPATH' ) ) exit;

class ContentList {

	/**
	 * The tab a card should open, while one is being drawn.
	 *
	 * @var string
	 */
	private $tab = '';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'scsl_content', [ $this, 'render' ] );
		add_filter( 'scsl_card_permalink', [ $this, 'card_permalink' ], 10, 2 );
	}

	/**
	 * What each kind of content needs and where it goes.
	 *
	 * @return array<string, array>
	 */
	private function kinds(): array {
		return [
			'articles' => [
				'meta'    => '_scsl_article_body',
				'tab'     => 'article',
				'heading' => __( 'Recent Articles', 'seedcast-sermon-library' ),
			],
			'studies'  => [
				'meta'    => '_scsl_bible_study',
				'tab'     => 'bible-study',
				'heading' => __( 'Recent Bible Studies', 'seedcast-sermon-library' ),
			],
			'sermons'  => [
				'meta'    => '',
				'tab'     => '',
				'heading' => __( 'Recent Sermons', 'seedcast-sermon-library' ),
			],
		];
	}

	/**
	 * The title a card should carry.
	 *
	 * An article has one of its own, and it is a better promise than the
	 * sermon's: it says what the piece is about rather than which Sunday it
	 * came from.
	 *
	 * @param int   $post_id Sermon ID.
	 * @param array $kind    One entry from kinds().
	 * @return string Empty to use the sermon's own title.
	 */
	private function card_title( int $post_id, array $kind ): string {
		if ( 'article' !== $kind['tab'] ) return '';

		return trim( (string) get_post_meta( $post_id, '_scsl_article_title', true ) );
	}

	/**
	 * Point a card at the tab this listing is about.
	 *
	 * @param string $permalink The sermon's own address.
	 * @param int    $post_id   Sermon ID.
	 * @return string
	 */
	public function card_permalink( $permalink, $post_id ) {
		return $this->tab ? $permalink . '#' . $this->tab : $permalink;
	}

	/**
	 * Render the list.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( [
			'type'    => 'articles',
			'count'   => 6,
			'columns' => 3,
			'layout'  => 'list',
			'order'   => 'recent',
			'title'   => '',
			'series'  => '',
			'paginate'=> 'false',
		], (array) $atts, 'scsl_content' );

		$type = sanitize_key( (string) $atts['type'] );


		$kinds = $this->kinds();

		if ( ! isset( $kinds[ $type ] ) ) return '';

		$kind = $kinds[ $type ];

		// A church that does not publish this kind of content should not have
		// a block advertising it.
		if ( $kind['meta'] && ! FieldMap::uses( $kind['meta'] ) ) return '';

		$query = new \WP_Query( $this->args( $kind, $atts ) );

		if ( ! $query->have_posts() ) return '';

		return $this->markup( $query, $kind, $atts );
	}

	/**
	 * Whether this block pages through everything or just shows the newest.
	 *
	 * A slider is a row you drag sideways with no notion of a page, so the
	 * option is meaningless there and is refused rather than rendered into
	 * something that cannot work.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return bool
	 */
	private function paginates( array $atts ): bool {
		if ( 'slider' === sanitize_key( (string) $atts['layout'] ) ) return false;

		return in_array( strtolower( (string) $atts['paginate'] ), [ 'true', '1', 'yes', 'on' ], true );
	}

	/**
	 * The page being viewed.
	 *
	 * @return int
	 */
	private function current_page(): int {
		return max( 1, absint( get_query_var( 'scsl_page', 1 ) ) );
	}

	/**
	 * What to ask for.
	 *
	 * @param array $kind One entry from kinds().
	 * @param array $atts Shortcode attributes.
	 * @return array
	 */
	private function args( array $kind, array $atts ): array {
		/*
		 * Counting rows costs a second query, so it is only done when the
		 * count is going to be used. Without pagination this block shows the
		 * newest few and that is the whole intent, so there is nothing to
		 * count.
		 */
		$paginate = $this->paginates( $atts );

		$args = [
			'post_type'           => 'scsl_sermon',
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 24, absint( $atts['count'] ) ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => ! $paginate,
		];

		if ( $paginate ) {
			$args['paged'] = $this->current_page();
		}

		// Only sermons that actually have this content, since a card leading
		// to an empty tab is worse than one card fewer.
		if ( $kind['meta'] ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering on content is the point of this block.
				[
					'key'     => $kind['meta'],
					'value'   => '',
					'compare' => '!=',
				],
			];
		}

		if ( 'popular' === $atts['order'] ) {
			// A named clause, so it can be ordered by without meta_key, which
			// would otherwise fight the clause that filters on content.
			$args['meta_query']['views'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- As above.
				'key'     => '_scsl_view_count',
				'compare' => 'EXISTS',
			];

			$args['orderby'] = [ 'views' => 'DESC', 'date' => 'DESC' ];
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		if ( $atts['series'] ) {
			// A series is a post here, not a taxonomy term, so sermons are
			// linked to one by meta. Querying it as a taxonomy matched nothing
			// at all and did so silently.
			$ids = [];

			foreach ( explode( ',', (string) $atts['series'] ) as $slug ) {
				$slug = sanitize_title( trim( $slug ) );

				if ( '' === $slug ) continue;

				$found = get_page_by_path( $slug, OBJECT, 'scsl_series' );

				if ( $found ) $ids[] = (int) $found->ID;
			}

			// Named but not found. An empty argument list would run a query
			// with WordPress's own defaults and return unrelated posts, so
			// this asks for something that cannot match instead.
			if ( ! $ids ) $args['post__in'] = [ 0 ];

			$args['meta_query'][] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering by series is a documented option.
				'key'     => '_scsl_series_id',
				'value'   => $ids,
				'compare' => 'IN',
			];
		}

		/**
		 * The query behind a content list.
		 *
		 * @param array $args Query arguments.
		 * @param array $atts Shortcode attributes.
		 */
		return (array) apply_filters( 'scsl_content_query', $args, $atts );
	}

	/**
	 * Draw it, in whichever shape was asked for.
	 *
	 * Three shapes, because they answer different questions. A list is for
	 * reading down: the sermon card with its speaker photo is right there. A
	 * grid and a slider are for looking across, so they use the artwork and
	 * the shared card that every other Seedcast block uses.
	 *
	 * @param \WP_Query $query Posts to show.
	 * @param array     $kind  One entry from kinds().
	 * @param array     $atts  Shortcode attributes.
	 * @return string
	 */
	private function markup( \WP_Query $query, array $kind, array $atts ): string {
		// No heading unless one was asked for. Inventing one would put words
		// on somebody's page that they did not write.
		$heading = trim( (string) $atts['title'] );
		$layout  = in_array( $atts['layout'], [ 'list', 'grid', 'slider' ], true ) ? $atts['layout'] : 'list';

		// Set while the cards are drawn, so each one points at the tab this
		// listing is about, then put back so nothing else is affected.
		$this->tab = (string) $kind['tab'];

		ob_start();
		?>
		<section class="scsl-content-list">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="sc-content-section-heading scsl-content-list__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>

			<?php
			if ( 'list' === $layout ) {
				echo '<div class="scsl-episode-list"><div class="scsl-list-results scsl-list-results--cards">';

				while ( $query->have_posts() ) {
					$query->the_post();

					TemplateLoader::partial( 'episode-card', [
						'post' => get_post(),
						// A row of faces says nothing about what any of these
						// pieces are about.
						'prefer_artwork' => '' !== (string) $kind['tab'],
						// One button that says what it does, rather than two
						// offering to play something nobody came to hear.
						'read_label'     => '' !== (string) $kind['tab'] ? __( 'Read', 'seedcast-sermon-library' ) : '',
					] );
				}

				echo '</div></div>';
			} else {
				$open = 'slider' === $layout;

				if ( $open ) {
					\Seedcast\Core\Frontend\Slider::open( $heading ?: $kind['heading'] );
				} else {
					$cols = in_array( absint( $atts['columns'] ), [ 2, 3, 4 ], true ) ? absint( $atts['columns'] ) : 3;

					echo '<div class="scsl-series-grid scsl-grid-cols-' . esc_attr( (string) $cols ) . '">';
				}

				while ( $query->have_posts() ) {
					$query->the_post();

					if ( $open ) echo '<div class="sc-slider__slide">';

					TemplateLoader::partial( 'sermon-grid-card', [
						'post'    => get_post(),
						'tab'     => (string) $kind['tab'],
						'heading' => $this->card_title( (int) get_the_ID(), $kind ),
					] );

					if ( $open ) echo '</div>';
				}

				if ( $open ) {
					\Seedcast\Core\Frontend\Slider::close();
				} else {
					echo '</div>';
				}
			}

			/*
			 * Without this a church's older articles are simply unreachable:
			 * the block shows the newest few and there is no way to walk back
			 * past them from the page they sit on.
			 */
			if ( $this->paginates( $atts ) && $query->max_num_pages > 1 ) {
				echo '<div class="scsl-content-pagination">';
				\Seedcast\Core\Frontend\Pagination::render( [
					'total'   => (int) $query->max_num_pages,
					'current' => $this->current_page(),
					'base'    => add_query_arg( 'scsl_page', '%#%' ),
					'format'  => '',
				] );
				echo '</div>';
			}
			?>
		</section>
		<?php

		wp_reset_postdata();

		$this->tab = '';

		return (string) ob_get_clean();
	}

	/**
	 * One card for the shared grid and slider.
	 *
	 * Artwork rather than a speaker photo. A listing of articles is about the
	 * pieces themselves, and a row of faces says nothing about what any of
	 * them are about.
	 *
	 * @param \WP_Post $post Sermon.
	 * @param array    $kind One entry from kinds().
	 * @return array
	 */
	private function card( \WP_Post $post, array $kind ): array {
		$id    = (int) $post->ID;
		$title = '';

		// An article has a title of its own, and it is a better promise than
		// the sermon's: it says what the piece is about rather than which
		// Sunday it came from.
		if ( 'article' === $kind['tab'] ) {
			$title = trim( (string) get_post_meta( $id, '_scsl_article_title', true ) );
		}

		if ( '' === $title ) $title = get_the_title( $id );

		$url = get_permalink( $id );

		if ( $kind['tab'] ) $url .= '#' . $kind['tab'];

		// The sermon's own image, then its series artwork. The site default is
		// applied by the same fallback everything else here uses, so a church
		// that set one gets it without this knowing anything about it.
		$image = get_the_post_thumbnail( $id, 'medium' );

		if ( ! $image ) {
			$series = absint( get_post_meta( $id, '_scsl_series_id', true ) );

			if ( $series ) $image = get_the_post_thumbnail( $series, 'medium' );
		}

		/*
		 * An article is summarised by itself, not by the sermon.
		 *
		 * The description belongs to the sermon: it says what was preached
		 * that Sunday. Under a list of articles that is the wrong promise,
		 * because every card ends up introducing the sermon rather than the
		 * piece somebody is about to read, and several articles from one
		 * series read as though they are all the same thing.
		 *
		 * The article's own heading is stripped first. It is already shown as
		 * the card's title, so leaving it in would open every excerpt by
		 * repeating the line directly above it.
		 */
		if ( 'article' === $kind['tab'] ) {
			$body = (string) get_post_meta( $id, '_scsl_article_body', true );
			$body = (string) preg_replace( '#<h[1-4][^>]*>.*?</h[1-4]>#is', ' ', $body );

			$excerpt = trim( wp_strip_all_tags( strip_shortcodes( $body ) ) );

			// Falls back to the sermon's description rather than showing
			// nothing, for an article that is only a heading so far.
			if ( '' === $excerpt ) {
				$excerpt = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_scsl_content_description', true ) ) );
			}
		} else {
			$excerpt = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_scsl_content_description', true ) ) );
		}

		return [
			'title'   => $title,
			'url'     => $url,
			'image'   => $image,
			'meta'    => array_filter( [ get_the_date( '', $id ) ] ),
			'excerpt' => $excerpt ? wp_trim_words( $excerpt, 24 ) : '',
		];
	}

}
