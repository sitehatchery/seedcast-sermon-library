<?php
namespace SeedcastSermonLibrary\Scripture;

use SeedcastSermonLibrary\Frontend\BulletinLibraryBridge;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Appending further batches to the lists on a scripture page.
 *
 * A passage page shows a handful of sermons and needs nothing more. A book page
 * can run to every sermon a church has preached from that book, and that grows
 * for as long as the church keeps preaching, so the lists are capped and the
 * rest is fetched on request.
 *
 * The first batch is in the HTML, which is the part that matters for search and
 * for anything reading the page without running scripts. Later batches are an
 * unashamed convenience: nothing is only reachable through them, because every
 * sermon is also linked from the sermon index and listed in the sitemap.
 *
 * The overlap set lives here rather than in the template so that a fetched
 * batch is drawn from exactly the same query as the batch above it.
 */
class ListLoader {

	/** How many rows a batch holds, unless a church has said otherwise. */
	private const PER_PAGE = 12;

	/** Columns in the article grid, which the batch size has to divide by. */
	public const ARTICLE_COLUMNS = 3;

	/**
	 * Register the endpoint and the script that calls it.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_route' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'register_script' ] );
	}

	/**
	 * How many rows to show before the button appears.
	 *
	 * @return int
	 */
	public static function per_page(): int {
		$per_page = (int) get_option( 'scsl_sermons_per_page', self::PER_PAGE );

		return max( 1, min( 48, $per_page ) );
	}

	/**
	 * How many articles to show, rounded down to whole rows.
	 *
	 * The article grid is three across. A batch that is not a multiple of that
	 * leaves a last row holding one card against two empty columns, and every
	 * further batch inherits the gap because it starts on a fresh row.
	 *
	 * @return int
	 */
	public static function article_batch(): int {
		$rows = (int) floor( self::per_page() / self::ARTICLE_COLUMNS );

		return max( 1, $rows ) * self::ARTICLE_COLUMNS;
	}

	/**
	 * Read-only, public, and cacheable: this returns what the page already shows.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route( 'scsl/v1', '/scripture-list', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'handle' ],
			'args'                => [
				'term'   => [ 'required' => true,  'sanitize_callback' => 'absint' ],
				'list'   => [ 'required' => true,  'sanitize_callback' => 'sanitize_key' ],
				'offset' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
			],
		] );
	}

	/**
	 * Make the script available. Enqueued by the template that needs it.
	 *
	 * @return void
	 */
	public static function register_script(): void {
		wp_register_script(
			'scsl-load-more',
			SCSL_PLUGIN_URL . 'assets/js/load-more.js',
			[],
			scsl_asset_version( 'assets/js/load-more.js' ),
			true
		);

		wp_localize_script( 'scsl-load-more', 'scslLoadMore', [
			'endpoint' => esc_url_raw( rest_url( 'scsl/v1/scripture-list' ) ),
		] );
	}

	/**
	 * Every term whose passage touches this one.
	 *
	 * Shared with the template so a later batch cannot be drawn from a
	 * different set than the first.
	 *
	 * @return int[]
	 */
	public static function overlapping_terms( \WP_Term $term ): array {
		$ids = [ (int) $term->term_id ];

		$all = get_terms( [ 'taxonomy' => 'scsl_scripture', 'hide_empty' => true ] );

		if ( is_wp_error( $all ) ) {
			return $ids;
		}

		foreach ( $all as $other ) {
			if ( (int) $other->term_id === (int) $term->term_id ) {
				continue;
			}

			if ( ScriptureParser::overlaps( (string) $term->name, (string) $other->name ) ) {
				$ids[] = (int) $other->term_id;
			}
		}

		return $ids;
	}

	/**
	 * Sermons whose passages touch this term. Exact terms only.
	 *
	 * Children are off deliberately: the taxonomy is hierarchical and the
	 * overlap set includes containing passages, so walking children would turn
	 * a verse page into its whole book.
	 *
	 * @return \WP_Query
	 */
	public static function cross_referenced( \WP_Term $term, int $per_page, int $offset = 0 ): \WP_Query {
		return new \WP_Query( self::base_args( $per_page, $offset ) + [
			'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => 'scsl_scripture',
					'field'            => 'term_id',
					'terms'            => self::overlapping_terms( $term ),
					'include_children' => false,
				],
			],
		] );
	}

	/**
	 * The rest of the book, excluding whatever the page already listed.
	 *
	 * @param int[] $exclude
	 * @return \WP_Query
	 */
	public static function rest_of_book( \WP_Term $book, array $exclude, int $per_page, int $offset = 0 ): \WP_Query {
		return new \WP_Query( self::base_args( $per_page, $offset ) + [
			'post__not_in' => array_map( 'intval', $exclude ),
			'tax_query'    => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy'         => 'scsl_scripture',
					'field'            => 'term_id',
					'terms'            => (int) $book->term_id,
					'include_children' => true,
				],
			],
		] );
	}

	/**
	 * Query arguments common to both lists.
	 *
	 * @return array
	 */
	private static function base_args( int $per_page, int $offset ): array {
		return [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'order'          => 'DESC',
		];
	}

	/**
	 * Return one further batch.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$term = get_term( (int) $request['term'], 'scsl_scripture' );

		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'scsl_no_term', __( 'Unknown passage.', 'seedcast-sermon-library' ), [ 'status' => 404 ] );
		}

		$list     = (string) $request['list'];
		$offset   = (int) ( $request['offset'] ?? 0 );
		$per_page = self::per_page();

		$book      = ScriptureParser::extract_book( (string) $term->name );
		$book_term = $book ? get_term_by( 'name', $book, 'scsl_scripture' ) : null;

		if ( 'articles' === $list ) {
			return self::articles_batch( $term, $per_page, $offset );
		}

		if ( 'book' === $list ) {
			if ( ! $book_term instanceof \WP_Term ) {
				return new \WP_Error( 'scsl_no_book', __( 'Unknown book.', 'seedcast-sermon-library' ), [ 'status' => 404 ] );
			}

			// The page excludes its own cross-referenced sermons from this
			// list, so a batch has to exclude the same ones or rows would
			// repeat as the reader pages through.
			$listed = wp_list_pluck( self::cross_referenced( $term, -1 )->posts, 'ID' );
			$query  = self::rest_of_book( $book_term, $listed, $per_page, $offset );
		} else {
			$query = self::cross_referenced( $term, $per_page, $offset );
		}

		$html = '';

		foreach ( $query->posts as $post ) {
			$html .= BulletinLibraryBridge::card( $post );
		}

		return new \WP_REST_Response( [
			'html' => $html,
			'more' => ( $offset + count( $query->posts ) ) < (int) $query->found_posts,
		] );
	}

	/**
	 * One further batch of article cards.
	 *
	 * Rendered through the same shortcode the page uses, so the cards match.
	 *
	 * @return \WP_REST_Response
	 */
	private static function articles_batch( \WP_Term $term, int $per_page, int $offset ): \WP_REST_Response {
		$listed = wp_list_pluck( self::cross_referenced( $term, -1 )->posts, 'ID' );

		$with_article = array_values( array_filter( $listed, static function ( $id ) {
			return '' !== trim( (string) get_post_meta( (int) $id, '_scsl_article_body', true ) );
		} ) );

		$batch = self::article_batch();

		// Bare cards. The page already has the section, heading and grid, and
		// this batch continues the row that is open rather than starting a
		// second grid alongside the first.
		$html = do_shortcode( sprintf(
			'[scsl_content type="articles" include="%s" count="%d" offset="%d" columns="%d" layout="grid" title="" wrapper="false"]',
			esc_attr( implode( ',', $listed ) ),
			$batch,
			$offset,
			self::ARTICLE_COLUMNS
		) );

		return new \WP_REST_Response( [
			'html' => $html,
			'more' => ( $offset + $batch ) < count( $with_article ),
		] );
	}

	/**
	 * The button that fetches the next batch.
	 *
	 * An anchor rather than a button, pointing at somewhere the reader can
	 * actually go, so it still leads somewhere with scripts unavailable.
	 *
	 * @param string $fallback_url Where to go without JavaScript.
	 * @return string Escaped HTML.
	 */
	public static function button( \WP_Term $term, string $list, int $offset, string $label, string $fallback_url ): string {
		return sprintf(
			'<p class="scsl-loadmore"><a class="sc-btn sc-btn--ghost sc-btn--sm" href="%s"'
			. ' data-scsl-loadmore="%s" data-term="%d" data-offset="%d">%s</a></p>',
			esc_url( $fallback_url ),
			esc_attr( $list ),
			(int) $term->term_id,
			$offset,
			esc_html( $label )
		);
	}
}
