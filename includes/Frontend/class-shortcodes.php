<?php
namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Scripture\ScriptureParser;
use Seedcast\Core\Frontend\Pagination;
use SeedcastSermonLibrary\Frontend\Unlisted;

class Shortcodes {

	/** Tracks whether inline assets have already been output this request */
	private static bool $assets_printed = false;

	/**
	 * Ensure frontend CSS and JS are loaded when a shortcode renders.
	 *
	 * Strategy (in order of reliability):
	 * 1. Always call wp_enqueue_style/script so WordPress outputs them if wp_head hasn't fired yet.
	 * 2. If wp_head has already fired (late shortcode, page builder, AJAX), inject a <link> tag
	 *    directly into the shortcode HTML so the styles definitely reach the browser.
	 * 3. Use a static flag so the inline <link> is only injected once even with multiple shortcodes.
	 */
	private function maybe_print_assets(): string {
		// Assets are always enqueued sitewide via wp_enqueue_scripts.
		// This method is kept for compatibility but nothing needs to be injected inline.
		return '';
	}

	public function init(): void {
		// A sermon saved with a new focus passage should turn up in the filter,
		// not ten minutes later.
		add_action( 'save_post_scsl_sermon', static function () {
			delete_transient( 'scsl_focus_books' );
		} );

		// Current shortcode names
		add_shortcode( 'scsl_series_grid',  [ $this, 'series_grid'  ] );
		add_shortcode( 'scsl_sermon_list',  [ $this, 'sermon_list'  ] );
		add_shortcode( 'scsl_latest',       [ $this, 'latest'       ] );
		add_shortcode( 'scsl_topic_list',   [ $this, 'topic_list'   ] );
		add_shortcode( 'scsl_speaker_grid', [ $this, 'speaker_grid' ] );
		add_shortcode( 'scsl_scripture_list', [ $this, 'scripture_list' ] );
		add_shortcode( 'scsl_sermon_index',   [ $this, 'sermon_index'   ] );

		// Legacy aliases for backward compatibility with existing page content
		add_shortcode( 'scsl_series_grid',  [ $this, 'series_grid'  ] );
		add_shortcode( 'scsl_sermon_list',  [ $this, 'sermon_list'  ] );
		add_shortcode( 'scsl_latest',       [ $this, 'latest'       ] );
		add_shortcode( 'scsl_topic_list',   [ $this, 'topic_list'   ] );
		add_shortcode( 'scsl_speaker_grid', [ $this, 'speaker_grid' ] );

		// AJAX handler for filtered sermon list
		add_action( 'wp_ajax_scsl_filter_sermons',        [ $this, 'ajax_filter' ] );
		add_action( 'wp_ajax_nopriv_scsl_filter_sermons', [ $this, 'ajax_filter' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_list_script' ] );
	}

	public function enqueue_list_script(): void {
		wp_register_script(
			'scsl-sermon-list',
			SCSL_PLUGIN_URL . 'assets/js/sermon-list.js',
			[ 'jquery' ],
			SCSL_VERSION,
			true
		);
		// Localize at registration so it's available whenever the script is enqueued
		wp_localize_script( 'scsl-sermon-list', 'scslList', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'scsl_list_nonce' ),
			'loading' => __( 'Loading…', 'seedcast-sermon-library' ),
		] );
	}

	// ── [sl_sermon_list] ──────────────────────────────────────────────────

	public function sermon_list( array $atts ): string {
		$atts = shortcode_atts( [
			'per_page'     => get_option( 'scsl_sermons_per_page', 10 ),
			'series_id'    => 0,
			'speaker_id'   => 0,
			'topic'        => '',
			'book'         => '',
			'show_filters' => 'true',
			'show_search'  => get_option( 'scsl_show_search_filter', '1' ) ? 'true' : 'false',
			'show_year'    => get_option( 'scsl_show_year_filter',   '1' ) ? 'true' : 'false',
			'orderby'      => 'recorded_date',
			'order'        => 'DESC',
		], $atts, 'scsl_sermon_list' );

		// Ensure assets load even when placed via page builders
		wp_enqueue_style(  'scsl-frontend' );
		wp_enqueue_script( 'scsl-sermon-list' );

		$asset_html = $this->maybe_print_assets();

		$instance_id = 'scsl-list-' . uniqid();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scsl_page_raw = get_query_var( 'scsl_page' ) ?: ( isset( $_GET['scsl_page'] ) ? absint( wp_unslash( $_GET['scsl_page'] ) ) : 1 );
		$paged = max( 1, absint( $scsl_page_raw ) );

		// Build data attributes for JS (used after filters are applied)
		$data = [
			'per-page'   => absint( $atts['per_page'] ),
			'series-id'  => absint( $atts['series_id'] ),
			'speaker-id' => absint( $atts['speaker_id'] ),
			'topic'      => sanitize_text_field( $atts['topic'] ),
			'book'       => sanitize_text_field( $atts['book'] ),
			'orderby'    => sanitize_key( $atts['orderby'] ),
			'order'      => in_array( strtoupper( $atts['order'] ), [ 'ASC', 'DESC' ] ) ? strtoupper( $atts['order'] ) : 'DESC',
			'nonce'      => wp_create_nonce( 'scsl_list_nonce' ),
			'page-url'   => get_permalink(),
			'template'   => 'episode-card',
		];

		$data_attrs = '';
		foreach ( $data as $key => $val ) {
			$data_attrs .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}

		// Initial server-side query
		$atts['paged']    = $paged;
		$atts['template'] = 'episode-card';
		$initial_html     = $this->fetch_sermons( $atts );
		$total_pages      = $this->last_query ? $this->last_query->max_num_pages : 1;
		$total_found      = $this->last_query ? $this->last_query->found_posts   : 0;
		$per_page_int     = absint( $atts['per_page'] );

		// Smart filter suppression: analyse the full result set
		$smart = $total_found > $per_page_int
			? $this->analyse_filter_set( $this->last_query )
			: [ 'hide_all' => true ];

		// Also use episode-card results container
		$results_class = 'scsl-list-results scsl-list-results--cards';

		ob_start();
		echo wp_kses( $asset_html, [ 'link' => [ 'rel' => true, 'href' => true, 'type' => true ] ] );
		?>
		<div class="scsl-sermon-list-wrap" id="<?php echo esc_attr( $instance_id ); ?>" <?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data_attrs is built exclusively from esc_attr() calls, safe HTML attributes ?>>

			<?php if ( $atts['show_filters'] === 'true' && empty( $smart['hide_all'] ) ) :
				$this->render_filter_bar( [
					'uid'           => $instance_id,
					'show_search'   => $atts['show_search']  !== 'false',
					'show_year'     => ( $atts['show_year'] !== 'false' ) && empty( $smart['hide_year'] ),
					'series_id'     => absint( $atts['series_id'] ),
					'speaker_id'    => absint( $atts['speaker_id'] ),
					'topic'         => $atts['topic'],
					'book'          => $atts['book'],
					'lock_series'   => absint( $atts['series_id'] ) > 0,
					'lock_speaker'  => ! empty( $smart['hide_speaker'] ),
					'lock_book'     => ! empty( $smart['hide_book'] ),
				] );
			endif; ?>

			<div class="<?php echo esc_attr( $results_class ); ?>">
				<?php echo wp_kses_post( $initial_html ); ?>
			</div>

			<!-- SEO pagination: real links, shown when no filter is active -->
			<?php if ( $total_pages > 1 ) : ?>
			<div class="scsl-list-pagination scsl-list-pagination--seo" id="<?php echo esc_attr( $instance_id ); ?>-pagination">
				<?php echo wp_kses_post( $this->build_seo_pagination( $paged, $total_pages ) ); ?>
			</div>
			<?php endif; ?>

			<!-- AJAX pagination: shown after a filter is applied via JS -->
			<div class="scsl-list-pagination scsl-list-pagination--ajax" style="display:none;"></div>

			<div class="scsl-list-loading" style="display:none;"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Build SEO-friendly pagination with real URL links using ?sf_page=N.
	 */
	private function build_seo_pagination( int $current, int $total ): string {
		$base = get_permalink();
		ob_start();
		echo '<nav class="sc-pagination sc-pagination--links" aria-label="' . esc_attr__( 'Sermon list pages', 'seedcast-sermon-library' ) . '">';

		if ( $current > 1 ) {
			$url = add_query_arg( 'scsl_page', $current - 1, $base );
			echo '<a href="' . esc_url( $url ) . '" class="scsl-btn scsl-btn--ghost scsl-page-link" rel="prev">&larr; ' . esc_html__( 'Prev', 'seedcast-sermon-library' ) . '</a>';
		}

		echo '<span class="scsl-page-info">' . sprintf(
			// translators: %1$d is the current page number, %2$d is the total number of pages
			esc_html__( 'Page %1$d of %2$d', 'seedcast-sermon-library' ),
			esc_html( $current ),
			esc_html( $total )
		) . '</span>';

		if ( $current < $total ) {
			$url = add_query_arg( 'scsl_page', $current + 1, $base );
			echo '<a href="' . esc_url( $url ) . '" class="scsl-btn scsl-btn--ghost scsl-page-link" rel="next">' . esc_html__( 'Next', 'seedcast-sermon-library' ) . ' &rarr;</a>';
		}

		echo '</nav>';
		return ob_get_clean();
	}

	// ── Filter Dropdowns ──────────────────────────────────────────────────

	private function render_series_filter( int $selected = 0, string $uid = '' ): void {
		$series = get_posts( [ 'post_type' => 'scsl_series', 'numberposts' => -1, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ] );
		if ( ! $series ) return;
		$id = $uid ? $uid . '-series' : 'scsl-series-filter';
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="scsl_series_id"
				class="scsl-filter-select" data-filter="series_id"
				aria-label="<?php esc_attr_e( 'Series', 'seedcast-sermon-library' ); ?>">
			<option value=""><?php esc_html_e( 'Series', 'seedcast-sermon-library' ); ?></option>
			<?php foreach ( $series as $s ) : ?>
				<option value="<?php echo esc_attr( $s->ID ); ?>" <?php selected( $selected, $s->ID ); ?>>
					<?php echo esc_html( $s->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_speaker_filter( int $selected = 0, string $uid = '' ): void {
		$speakers = get_posts( [ 'post_type' => 'scsl_speaker', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		if ( ! $speakers ) return;
		$id = $uid ? $uid . '-speaker' : 'scsl-speaker-filter';
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="scsl_speaker_id"
				class="scsl-filter-select" data-filter="speaker_id"
				aria-label="<?php esc_attr_e( 'Speaker', 'seedcast-sermon-library' ); ?>">
			<option value=""><?php esc_html_e( 'Speaker', 'seedcast-sermon-library' ); ?></option>
			<?php foreach ( $speakers as $sp ) : ?>
				<option value="<?php echo esc_attr( $sp->ID ); ?>" <?php selected( $selected, $sp->ID ); ?>>
					<?php echo esc_html( $sp->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_topic_filter( string $selected = '', string $uid = '' ): void {
		$topics = get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => true ] );
		if ( ! $topics || is_wp_error( $topics ) ) return;
		$id = $uid ? $uid . '-topic' : 'scsl-topic-filter';
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="scsl_topic"
				class="scsl-filter-select" data-filter="topic"
				aria-label="<?php esc_attr_e( 'Topic', 'seedcast-sermon-library' ); ?>">
			<option value=""><?php esc_html_e( 'Topic', 'seedcast-sermon-library' ); ?></option>
			<?php foreach ( $topics as $t ) : ?>
				<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $selected, $t->slug ); ?>>
					<?php echo esc_html( $t->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_book_filter( string $selected = '', string $uid = '' ): void {
		$books = self::focus_books();

		if ( ! $books ) return;

		$id = $uid ? $uid . '-book' : 'scsl-book-filter';
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="scsl_book"
				class="scsl-filter-select" data-filter="book"
				aria-label="<?php esc_attr_e( 'Bible Book', 'seedcast-sermon-library' ); ?>">
			<option value=""><?php esc_html_e( 'Bible Book', 'seedcast-sermon-library' ); ?></option>
			<?php foreach ( $books as $book ) : ?>
				<option value="<?php echo esc_attr( $book ); ?>" <?php selected( $selected, $book ); ?>>
					<?php echo esc_html( $book ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Analyse the full result set to determine which filter dropdowns add value.
	 * Returns array of flags: hide_all, hide_speaker, hide_year, hide_book.
	 */
	private function analyse_filter_set( \WP_Query $query ): array {
		$flags    = [];
		$post_ids = $query->posts ? wp_list_pluck( $query->posts, 'ID' ) : [];

		if ( empty( $post_ids ) ) {
			return [ 'hide_all' => true ];
		}

		// Re-query without pagination to get ALL post IDs for analysis
		$all = get_posts( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $query->get( 'meta_query' ) ?: [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'tax_query'      => $query->get( 'tax_query'  ) ?: [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		] );

		$_threshold = absint( get_option( 'scsl_filter_threshold', 0 ) );
		if ( $_threshold > 0 && count( $all ) <= $_threshold ) {
			return [ 'hide_all' => true ];
		}

		// Check speaker diversity
		$speakers = array_unique( array_filter( array_map( function( $id ) {
			return get_post_meta( $id, '_scsl_speaker_id', true );
		}, $all ) ) );
		if ( count( $speakers ) <= 1 ) $flags['hide_speaker'] = true;

		// Check year diversity
		$years = array_unique( array_filter( array_map( function( $id ) {
			$d = get_post_meta( $id, '_scsl_recorded_date', true );
			return $d ? substr( $d, 0, 4 ) : '';
		}, $all ) ) );
		if ( count( $years ) <= 1 ) $flags['hide_year'] = true;

		// Check Bible book diversity
		$books = array_unique( array_filter( array_map( function( $id ) {
			$p = get_post_meta( $id, '_scsl_focus_passage', true );
			if ( ! $p ) return '';
			// Extract book name (first word or two)
			preg_match( '/^(\d?\s?[A-Za-z]+)/', $p, $m );
			return $m[1] ?? '';
		}, $all ) ) );
		if ( count( $books ) <= 1 ) $flags['hide_book'] = true;

		return $flags;
	}

	/**
	 * The books sermons are actually about.
	 *
	 * Taken from the focus passage rather than the scripture taxonomy, which
	 * holds every reference a sermon mentions. A sermon on Luke that quotes
	 * Romans once is not a sermon on Romans, and offering Romans in the filter
	 * only to return nothing useful is worse than not offering it.
	 *
	 * @return array<string, string>
	 */
	public static function focus_books(): array {
		$cached = get_transient( 'scsl_focus_books' );

		if ( is_array( $cached ) ) return $cached;

		$ids = get_posts( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$books = [];

		foreach ( $ids as $id ) {
			$passage = (string) get_post_meta( $id, '_scsl_focus_passage', true );

			if ( '' === trim( $passage ) ) continue;

			$book = \SeedcastSermonLibrary\Scripture\ScriptureParser::extract_book( $passage );

			if ( $book ) $books[ $book ] = $book;
		}

		ksort( $books );

		// Short, because a new sermon should show up in the filter without
		// somebody wondering why it has not.
		set_transient( 'scsl_focus_books', $books, 10 * MINUTE_IN_SECONDS );

		return $books;
	}

	/**
	 * Shared filter bar: used by shortcode, series page, and topic template.
	 *
	 * Config keys:
	 *   uid          string  unique prefix for IDs
	 *   show_search  bool    show search field
	 *   show_year    bool    show year dropdown
	 *   series_id    int     pre-selected series (0 = none)
	 *   speaker_id   int     pre-selected speaker (0 = none)
	 *   topic        string  pre-selected topic slug
	 *   book         string  pre-selected bible book
	 *   lock_series  bool    hide series dropdown
	 *   lock_speaker bool    hide speaker dropdown
	 *   lock_topic   bool    hide topic dropdown
	 *   lock_book    bool    hide bible book dropdown
	 *   extra_filters array  extra <select> HTML after book filter
	 */
	public function render_filter_bar( array $config = [] ): void {
		$uid          = $config['uid']          ?? 'scsl-filter';
		$show_search  = $config['show_search']  ?? (bool) get_option( 'scsl_show_search_filter', '1' );
		$show_year    = $config['show_year']    ?? (bool) get_option( 'scsl_show_year_filter',   '1' );
		$series_id    = absint( $config['series_id']  ?? 0 );
		$speaker_id   = absint( $config['speaker_id'] ?? 0 );
		$topic        = $config['topic']        ?? '';
		$book         = $config['book']         ?? '';
		$lock_series  = $config['lock_series']  ?? false;
		$lock_topic   = $config['lock_topic']   ?? false;
		$lock_speaker = $config['lock_speaker'] ?? false;
		$lock_book    = $config['lock_book']    ?? false;
		$extra        = $config['extra_filters'] ?? [];
		?>
		<div class="scsl-list-filters">
			<div class="scsl-filters-row scsl-filters-row--dropdowns">

				<?php if ( $show_search ) : ?>
				<div class="scsl-filter-search-wrap">
					<input type="search"
						   id="<?php echo esc_attr( $uid ); ?>-search"
						   name="scsl_search"
						   class="scsl-filter-search"
						   placeholder="<?php esc_attr_e( 'Search…', 'seedcast-sermon-library' ); ?>"
						   aria-label="<?php esc_attr_e( 'Search sermons', 'seedcast-sermon-library' ); ?>"
						   data-filter="search" />
					<button type="button" class="scsl-search-btn" aria-label="<?php esc_attr_e( 'Search', 'seedcast-sermon-library' ); ?>">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					</button>
				</div>
				<?php endif; ?>

				<?php if ( ! $lock_series  ) $this->render_series_filter(  $series_id, $uid ); ?>
				<?php if ( ! $lock_speaker ) $this->render_speaker_filter( $speaker_id, $uid ); ?>
				<?php if ( ! $lock_topic   ) $this->render_topic_filter(   $topic, $uid ); ?>
				<?php if ( ! $lock_book    ) $this->render_book_filter( $book, $uid ); ?>

				<?php foreach ( $extra as $html ) echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filter output, responsibility of filter callbacks ?>

				<?php if ( $show_year ) $this->render_year_filter( $uid ); ?>

				<button type="button" class="scsl-filter-clear scsl-btn scsl-btn--ghost scsl-btn--sm" style="display:none;">
					<?php esc_html_e( 'Clear', 'seedcast-sermon-library' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_year_filter( string $uid = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$years = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT YEAR(meta_value) as yr FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value!='' ORDER BY yr DESC",
			'_scsl_recorded_date'
		) );
		// Only show if there are at least 2 years of content
		if ( ! $years || count( $years ) < 2 ) return;
		$id = $uid ? $uid . '-year' : 'scsl-year-filter';
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="scsl_year"
				class="scsl-filter-select" data-filter="year"
				aria-label="<?php esc_attr_e( 'Year', 'seedcast-sermon-library' ); ?>">
			<option value=""><?php esc_html_e( 'Year', 'seedcast-sermon-library' ); ?></option>
			<?php foreach ( $years as $year ) : ?>
				<option value="<?php echo esc_attr( $year ); ?>"><?php echo esc_html( $year ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	// ── AJAX Handler ──────────────────────────────────────────────────────

	public function ajax_filter(): void {
		check_ajax_referer( 'scsl_list_nonce', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$atts = [
			'per_page'   => absint( wp_unslash( $_POST['per_page']   ?? 10 ) ),
			'series_id'  => absint( wp_unslash( $_POST['series_id']  ?? 0 ) ),
			'speaker_id' => absint( wp_unslash( $_POST['speaker_id'] ?? 0 ) ),
			'topic'      => sanitize_text_field( wp_unslash( $_POST['topic']   ?? '' ) ),
			'book'       => sanitize_text_field( wp_unslash( $_POST['book']    ?? '' ) ),
			'year'       => absint( wp_unslash( $_POST['year']       ?? 0 ) ),
			'paged'      => absint( wp_unslash( $_POST['paged']      ?? 1 ) ),
			'orderby'    => sanitize_key( wp_unslash( $_POST['orderby'] ?? 'recorded_date' ) ),
			'order'      => sanitize_key( wp_unslash( $_POST['order']   ?? 'DESC' ) ) === 'asc' ? 'ASC' : 'DESC',
			'template'   => sanitize_key( wp_unslash( $_POST['template'] ?? 'sermon-list-item' ) ),
			'search'     => sanitize_text_field( wp_unslash( $_POST['search']    ?? '' ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$html       = $this->fetch_sermons( $atts );
		$pagination = $this->fetch_pagination( $atts );

		wp_send_json_success( [ 'html' => $html, 'pagination' => $pagination ] );
	}

	// ── Query Builder ─────────────────────────────────────────────────────

	private function fetch_sermons( array $atts ): string {
		$args = [
			'post_type'      => 'scsl_sermon',
			'posts_per_page' => absint( $atts['per_page'] ?? 10 ),
			'paged'          => absint( $atts['paged']    ?? 1 ),
			'post_status'    => 'publish',
		];

		// Keyword search
		if ( ! empty( $atts['search'] ) ) {
			$args['s'] = sanitize_text_field( $atts['search'] );
		}

		// Ordering
		if ( ( $atts['orderby'] ?? 'recorded_date' ) === 'recorded_date' ) {
			$args['orderby']  = 'meta_value';
			$args['meta_key'] = '_scsl_recorded_date';  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- sermon plugin requires these queries
			$args['order']    = $atts['order'] ?? 'DESC';
		}

		$meta_query = [];
		if ( ! empty( $atts['series_id'] ) ) {
			$meta_query[] = [ 'key' => '_scsl_series_id', 'value' => absint( $atts['series_id'] ) ];
		}
		if ( ! empty( $atts['speaker_id'] ) ) {
			$meta_query[] = [ 'key' => '_scsl_speaker_id', 'value' => absint( $atts['speaker_id'] ) ];
		}
		if ( ! empty( $atts['year'] ) ) {
			$meta_query[] = [
				'key'     => '_scsl_recorded_date',
				'value'   => [ absint( $atts['year'] ) . '-01-01', absint( $atts['year'] ) . '-12-31' ],
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			];
		}
		if ( ! empty( $atts['book'] ) ) {
			$book = sanitize_text_field( $atts['book'] );

			// The book, then a space before the chapter. Without the space,
			// John would also match 1 John, which is a different book.
			$meta_query[] = [
				'relation' => 'OR',
				[
					'key'     => '_scsl_focus_passage',
					'value'   => '^' . preg_quote( $book, '/' ) . '\\s',
					'compare' => 'REGEXP',
				],
				[
					'key'     => '_scsl_focus_passage',
					'value'   => $book,
					'compare' => '=',
				],
			];
		}
		if ( $meta_query ) $args['meta_query'] = $meta_query;  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- sermon plugin requires these queries

		$tax_query = [];
		if ( ! empty( $atts['topic'] ) ) {
			$tax_query[] = [ 'taxonomy' => 'scsl_topic', 'field' => 'slug', 'terms' => sanitize_text_field( $atts['topic'] ) ];
		}
		if ( $tax_query ) $args['tax_query'] = $tax_query;  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- sermon plugin requires these queries

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '<p class="scsl-no-content">' . esc_html__( 'No sermons found.', 'seedcast-sermon-library' ) . '</p>';
		}

		// Store for pagination
		$this->last_query = $query;

		ob_start();
		while ( $query->have_posts() ) {
			$query->the_post();
			$tpl = ( $atts['template'] ?? 'sermon-list-item' ) === 'episode-card'
				? 'episode-card'
				: 'sermon-list-item';
			TemplateLoader::partial( $tpl, [ 'post' => get_post() ] );
		}
		wp_reset_postdata();
		return ob_get_clean();
	}

	private ?\WP_Query $last_query = null;

	private function fetch_pagination( array $atts ): string {
		if ( ! $this->last_query ) return '';
		$total = $this->last_query->max_num_pages;
		$paged = absint( $atts['paged'] ?? 1 );
		if ( $total <= 1 ) return '';

		ob_start();
		echo '<div class="sc-pagination">';
		if ( $paged > 1 ) {
			echo '<button class="scsl-page-btn scsl-btn scsl-btn--ghost" data-page="' . esc_attr( $paged - 1 ) . '">&larr; ' . esc_html__( 'Prev', 'seedcast-sermon-library' ) . '</button>';
		}
		// translators: %1$d is the current page number, %2$d is the total number of pages
		echo '<span class="scsl-page-info">' . sprintf( esc_html__( 'Page %1$d of %2$d', 'seedcast-sermon-library' ), esc_html( $paged ), esc_html( $total ) ) . '</span>';
		if ( $paged < $total ) {
			echo '<button class="scsl-page-btn scsl-btn scsl-btn--ghost" data-page="' . esc_attr( $paged + 1 ) . '">' . esc_html__( 'Next', 'seedcast-sermon-library' ) . ' &rarr;</button>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	// ── [sl_series_grid] ──────────────────────────────────────────────────

	public function series_grid( array $atts ): string {
		$atts = shortcode_atts( [
			'limit'   => '',             // empty = use per_page with pagination; positive int = exact count, no pagination
			'per_page'=> get_option( 'scsl_series_per_page', 12 ),
			'columns' => 3,
			'topic'   => '',
			'slider'  => 'false',
		], $atts, 'scsl_series_grid' );

		$asset_html  = $this->maybe_print_assets();
		$is_slider   = $atts['slider'] === 'true';
		$per_page    = absint( $atts['per_page'] );
		$limit       = strlen( $atts['limit'] ) ? absint( $atts['limit'] ) : 0;

		// limit=0 or unset → paginate. limit=N → show exactly N, no pagination.
		$use_pagination = ( $limit === 0 ) && ! $is_slider;
		$paged = $use_pagination
			? max( 1, absint( get_query_var( 'scsl_page', 1 ) ) )
			: 1;

		$args = [
			'post_type'      => 'scsl_series',
			'posts_per_page' => $is_slider
				? ( $limit > 0 ? $limit : -1 )   // slider: respect limit if set, otherwise all
				: ( $limit > 0 ? $limit : $per_page ), // grid: limit or paginate
			'paged'          => $paged,
			'post_status'    => 'publish',
			'orderby'        => [ 'menu_order' => 'ASC', 'date' => 'DESC' ],
		];
		if ( $atts['topic'] ) {
			// A series is a container, so its subject is whatever its sermons
			// are about. Matching topics on the series post itself asks
			// somebody to tag the container by hand, which is work nobody
			// should have to do twice.
			$in_topic = get_posts( [
				'post_type'      => 'scsl_sermon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// One topic or several. Any match counts, since somebody picking
				// three topics wants series touching any of them rather than
				// the rare series covering all three.
				'tax_query'      => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Finding the sermons is the point.
					'taxonomy' => 'scsl_topic',
					'field'    => 'slug',
					'terms'    => array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['topic'] ) ) ),
					'operator' => 'IN',
				] ],
			] );

			$series_ids = [];

			foreach ( $in_topic as $sermon_id ) {
				$sid = absint( get_post_meta( $sermon_id, '_scsl_series_id', true ) );

				if ( $sid ) $series_ids[ $sid ] = $sid;
			}

			// Nothing rather than everything, since a topic with no sermons in
			// any series is a real answer.
			$args['post__in'] = $series_ids ? array_values( $series_ids ) : [ 0 ];

		}

		$query = new \WP_Query( $args );
		if ( ! $query->have_posts() ) return '';

		ob_start();
		echo wp_kses_post( $asset_html );

		if ( $is_slider ) :
			// ── Slider layout ───────────────────────────────────────────
			?>
			<div class="scsl-series-slider-wrap">
				<div class="scsl-series-slider" role="region" aria-label="<?php esc_attr_e( 'Series', 'seedcast-sermon-library' ); ?>">
					<?php while ( $query->have_posts() ) {
						$query->the_post();
						echo '<div class="scsl-series-slider__item">';
						TemplateLoader::partial( 'series-card', [ 'post' => get_post() ] );
						echo '</div>';
					}
					wp_reset_postdata(); ?>
				</div>
				<button class="scsl-slider-btn scsl-slider-btn--prev" aria-label="<?php esc_attr_e( 'Previous', 'seedcast-sermon-library' ); ?>">&#8592;</button>
				<button class="scsl-slider-btn scsl-slider-btn--next" aria-label="<?php esc_attr_e( 'Next', 'seedcast-sermon-library' ); ?>">&#8594;</button>
			</div>
			<?php
		else :
			// ── Grid layout ─────────────────────────────────────────────
			echo '<div class="scsl-series-grid scsl-grid-cols-' . esc_attr( $atts['columns'] ) . '">';
			while ( $query->have_posts() ) {
				$query->the_post();
				TemplateLoader::partial( 'series-card', [ 'post' => get_post() ] );
			}
			wp_reset_postdata();
			echo '</div>';

			/*
			 * Pagination, only when a count was not fixed and this is not a
			 * slider.
			 *
			 * Core's renderer rather than a loop over every page. Printing one
			 * link per page is fine while a church has four series and unusable
			 * once it has forty, and it also drifts from the styling the rest
			 * of the suite uses. The shared component collapses the middle and
			 * carries the arrows and current-page marking already.
			 */
			if ( $use_pagination && $query->max_num_pages > 1 ) {
				echo '<div class="scsl-series-pagination">';
				Pagination::render( [
					'total'   => (int) $query->max_num_pages,
					'current' => $paged,
					'base'    => add_query_arg( 'scsl_page', '%#%' ),
					'format'  => '',
				] );
				echo '</div>';
			}
		endif;

		return ob_get_clean();
	}

	// ── [sl_latest] ───────────────────────────────────────────────────────

	public function latest( array $atts ): string {
		wp_enqueue_style( 'scsl-frontend' );
		$asset_html = $this->maybe_print_assets();

		$query = new \WP_Query( [
			'post_type'      => 'scsl_sermon',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'order'          => 'DESC',
		] );
		if ( ! $query->have_posts() ) return '';
		$query->the_post();
		$post_id     = get_the_ID();
		$video_url   = get_post_meta( $post_id, '_scsl_video_url',           true );
		$audio_url   = get_post_meta( $post_id, '_scsl_audio_url',           true );
		$description = get_post_meta( $post_id, '_scsl_content_description', true );
		$speaker_id  = get_post_meta( $post_id, '_scsl_speaker_id',          true );
		$rec_date    = get_post_meta( $post_id, '_scsl_recorded_date',       true );
		$series_id   = get_post_meta( $post_id, '_scsl_series_id',           true );
		$permalink   = get_permalink( $post_id );
		$watch_url   = $video_url ? $permalink : '';
		$listen_url  = $audio_url ? $permalink : '';

		ob_start();
		echo wp_kses_post( $asset_html );
		?>
		<div class="scsl-latest">
			<div class="scsl-latest__meta">
				<?php if ( $speaker_id ) : ?>
					<span class="scsl-latest__speaker">
						<a href="<?php echo esc_url( get_permalink( $speaker_id ) ); ?>">
							<?php echo esc_html( get_the_title( $speaker_id ) ); ?>
						</a>
					</span>
					<?php if ( $rec_date ) : ?>
						<span class="scsl-latest__sep" aria-hidden="true"> – </span>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $rec_date ) : ?>
					<time class="scsl-latest__date" datetime="<?php echo esc_attr( $rec_date ); ?>">
						<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $rec_date ) ) ); ?>
					</time>
				<?php endif; ?>
			</div>

			<h2 class="scsl-latest__title">
				<a href="<?php echo esc_url( $permalink ); ?>"><?php the_title(); ?></a>
			</h2>

			<?php if ( $video_url ) : ?>
			<div class="scsl-latest__video scsl-media-wrap">
				<?php TemplateLoader::partial( 'video-embed', [ 'url' => $video_url, 'type' => 'youtube' ] ); ?>
			</div>
			<?php elseif ( $audio_url ) : ?>
			<div class="scsl-latest__audio">
				<audio controls preload="none" style="width:100%">
					<source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg" />
				</audio>
			</div>
			<?php elseif ( has_post_thumbnail( $post_id ) ) : ?>
			<div class="scsl-latest__thumbnail">
				<a href="<?php echo esc_url( $permalink ); ?>">
					<?php the_post_thumbnail( 'large', [ 'class' => 'scsl-latest__thumb-img' ] ); ?>
				</a>
			</div>
			<?php endif; ?>

			<?php if ( $description ) : ?>
			<p class="scsl-latest__desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>

			<div class="scsl-latest__actions">
				<a href="<?php echo esc_url( $permalink ); ?>" class="scsl-btn scsl-btn--ghost">
					<?php esc_html_e( 'View Sermon →', 'seedcast-sermon-library' ); ?>
				</a>
			</div>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	// ── [sl_topic_list] ───────────────────────────────────────────────────
	// Displays all topics as clickable pills with sermon counts.
	// Attributes: columns (2|3|4, default 3), orderby (name|count, default count), show_count (true|false)

	public function topic_list( array $atts ): string {
		$atts = shortcode_atts( [
			'columns'    => '3',
			'orderby'    => 'count',
			'show_count' => 'true',
		], $atts, 'scsl_topic_list' );

		wp_enqueue_style( 'scsl-frontend' );

		wp_enqueue_style( 'scsl-frontend' );
		$asset_html = $this->maybe_print_assets();

		$terms = get_terms( [
			'taxonomy'   => 'scsl_topic',
			'hide_empty' => true,
			'orderby'    => $atts['orderby'] === 'count' ? 'count' : 'name',
			'order'      => $atts['orderby'] === 'count' ? 'DESC' : 'ASC',
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) return '';

		$cols = in_array( $atts['columns'], [ '2', '3', '4' ] ) ? $atts['columns'] : '3';
		$show_count = $atts['show_count'] !== 'false';

		ob_start();
		echo wp_kses_post( $asset_html );
		echo '<div class="scsl-topic-list scsl-topic-list--cols-' . esc_attr( $cols ) . '">';
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			echo '<a href="' . esc_url( $url ) . '" class="scsl-topic-pill">';
			echo esc_html( $term->name );
			if ( $show_count ) {
				echo ' <span class="scsl-topic-pill__count">' . esc_html( $term->count ) . '</span>';
			}
			echo '</a>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	// ── [scsl_sermon_index] ────────────────────────────────────────────────

	/**
	 * Every sermon on one page, grouped by year.
	 *
	 * Search engines already have the list: the sitemap carries every sermon
	 * whether or not anything links to it. What a sitemap does not do is say
	 * which pages matter, and a sermon eleven pages into an archive is reached
	 * by one deep link and nothing else. This puts every sermon two clicks
	 * from anywhere on the site, which is what the footer link is for.
	 *
	 * It deliberately ignores the unlisted flag. A sermon taken out of the
	 * lists is meant to be out of the way, not lost, and this page is what
	 * makes that difference real.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function sermon_index( array $atts ): string {
		$atts = shortcode_atts( [
			'show_speaker' => 'true',
		], $atts, 'scsl_sermon_index' );

		wp_enqueue_style( 'scsl-frontend' );
		$asset_html = $this->maybe_print_assets();

		$sermons = new \WP_Query( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'order'          => 'DESC',
			'no_found_rows'  => true,
			// Everything, including what has been kept out of the lists. This
			// page is the reason unlisted is not the same as lost.
			Unlisted::INCLUDE_ARG => true,
		] );

		if ( ! $sermons->have_posts() ) return '';

		$show_speaker = 'false' !== $atts['show_speaker'];
		$by_year      = [];

		foreach ( $sermons->posts as $sermon ) {
			$date = (string) get_post_meta( $sermon->ID, '_scsl_recorded_date', true );

			// A sermon with no date still belongs here, since being hard to
			// file is not a reason to leave it unreachable.
			$year = preg_match( '/^(\d{4})/', $date, $m ) ? $m[1] : __( 'Undated', 'seedcast-sermon-library' );

			$by_year[ $year ][] = $sermon;
		}

		ob_start();
		echo wp_kses_post( $asset_html );
		echo '<div class="scsl-sermon-index">';

		foreach ( $by_year as $year => $group ) {
			echo '<section class="scsl-sermon-index__year">';
			echo '<h3 class="scsl-sermon-index__heading">' . esc_html( (string) $year ) . '</h3>';
			echo '<ul class="scsl-sermon-index__list">';

			foreach ( $group as $sermon ) {
				echo '<li class="scsl-sermon-index__item">';
				printf(
					'<a href="%s">%s</a>',
					esc_url( (string) get_permalink( $sermon ) ),
					esc_html( get_the_title( $sermon ) )
				);

				if ( $show_speaker ) {
					$speaker_id = (int) get_post_meta( $sermon->ID, '_scsl_speaker_id', true );
					$speaker    = $speaker_id ? get_post( $speaker_id ) : null;

					if ( $speaker ) {
						echo ' <span class="scsl-sermon-index__speaker">' . esc_html( get_the_title( $speaker ) ) . '</span>';
					}
				}

				echo '</li>';
			}

			echo '</ul></section>';
		}

		echo '</div>';

		wp_reset_postdata();

		return ob_get_clean();
	}

	// ── [scsl_scripture_list] ──────────────────────────────────────────────

	/**
	 * Every passage this church has preached from, grouped by book.
	 *
	 * The passage pages had nothing pointing at them. The panel on a sermon
	 * now links the passages that sermon used, which reaches them one sermon at
	 * a time and only for somebody already reading that sermon. This is the
	 * other way in: a page a church can put under Sermons that shows the whole
	 * of what has been preached, in the order a Bible is in.
	 *
	 * Grouped rather than listed flat, because a church with years behind it
	 * has hundreds of passages and an alphabetical run of them is not something
	 * anybody reads.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function scripture_list( array $atts ): string {
		$atts = shortcode_atts( [
			'show_count' => 'true',
		], $atts, 'scsl_scripture_list' );

		wp_enqueue_style( 'scsl-frontend' );
		$asset_html = $this->maybe_print_assets();

		$terms = get_terms( [
			'taxonomy'   => 'scsl_scripture',
			'hide_empty' => true,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) return '';

		$show_count = 'false' !== $atts['show_count'];

		// Canonical order, so Genesis comes before Exodus rather than before
		// Habakkuk. Anything unrecognised is kept and put at the end, because
		// a church's own wording is not a reason to hide its sermons.
		$order  = array_flip( ScriptureParser::all_books() );
		$books  = [];

		foreach ( $terms as $term ) {
			$book = ScriptureParser::extract_book( (string) $term->name ) ?? __( 'Other', 'seedcast-sermon-library' );
			$books[ $book ][] = $term;
		}

		uksort( $books, static function ( $a, $b ) use ( $order ) {
			return ( $order[ $a ] ?? PHP_INT_MAX ) <=> ( $order[ $b ] ?? PHP_INT_MAX );
		} );

		ob_start();
		echo wp_kses_post( $asset_html );
		echo '<div class="scsl-scripture-index">';

		foreach ( $books as $book => $book_terms ) {
			echo '<section class="scsl-scripture-index__book">';
			echo '<h3 class="scsl-scripture-index__title">' . esc_html( (string) $book ) . '</h3>';
			echo '<div class="scsl-topic-list scsl-topic-list--cols-3">';

			foreach ( $book_terms as $term ) {
				$url = get_term_link( $term );

				if ( is_wp_error( $url ) ) continue;

				echo '<a href="' . esc_url( $url ) . '" class="scsl-topic-pill">';
				echo esc_html( $term->name );

				if ( $show_count ) {
					echo ' <span class="scsl-topic-pill__count">' . esc_html( $term->count ) . '</span>';
				}

				echo '</a>';
			}

			echo '</div></section>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	// ── [sl_speaker_grid] ──────────────────────────────────────────────────

	public function speaker_grid( array $atts ): string {
		$atts = shortcode_atts( [
			'limit'    => '',
			'columns'  => 3,
			'slider'   => 'false',
			'orderby'  => 'menu_order',
			'hide'     => '',
		], $atts, 'scsl_speaker_grid' );

		$is_slider = $atts['slider'] === 'true';
		$limit     = strlen( $atts['limit'] ) ? absint( $atts['limit'] ) : 0;

		$args = [
			'post_type'      => 'scsl_speaker',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'post_status'    => 'publish',
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
		];

		// Somewhere to leave out a Guest Teachers entry, which is a placeholder
		// rather than a person and looks odd beside real names. Named by slug,
		// since that is what somebody writing a shortcode can see.
		if ( '' !== trim( (string) $atts['hide'] ) ) {
			$skip = [];

			foreach ( explode( ',', (string) $atts['hide'] ) as $slug ) {
				$found = get_page_by_path( sanitize_title( trim( $slug ) ), OBJECT, 'scsl_speaker' );

				if ( $found ) $skip[] = (int) $found->ID;
			}

			// Asking for the ones wanted rather than excluding the ones not,
			// which is the cheaper question and the one the database prefers.
			if ( $skip ) {
				$keep = get_posts( [
					'post_type'      => 'scsl_speaker',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				] );

				$keep = array_values( array_diff( $keep, $skip ) );

				$args['post__in'] = $keep ?: [ 0 ];
			}
		}

		// Whoever preaches most, first. The count is worked out per speaker
		// rather than stored, so the sorting happens here rather than in the
		// query. There are rarely more than a dozen speakers, so this costs
		// little and saves keeping a running total in step.
		if ( 'sermons' === $atts['orderby'] ) {
			$args['posts_per_page'] = -1;
			$args['fields']         = 'ids';

			$ids = get_posts( $args );

			$counted = [];

			foreach ( $ids as $speaker_id ) {
				$counted[ (int) $speaker_id ] = count( get_posts( [
					'post_type'      => 'scsl_sermon',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_scsl_speaker_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Counting by speaker is the point.
					'meta_value'     => (string) $speaker_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				] ) );
			}

			arsort( $counted );

			$order = array_keys( $counted );

			if ( $limit > 0 ) $order = array_slice( $order, 0, $limit );

			if ( ! $order ) return '';

			$args = [
				'post_type'      => 'scsl_speaker',
				'post_status'    => 'publish',
				'post__in'       => $order,
				'orderby'        => 'post__in',
				'posts_per_page' => count( $order ),
			];
		}

		$query = new \WP_Query( $args );
		if ( ! $query->have_posts() ) return '';

		ob_start();

		if ( $is_slider ) {
			?>
			<div class="scsl-series-slider-wrap">
				<div class="scsl-series-slider scsl-speaker-slider" role="region" aria-label="<?php esc_attr_e( 'Speakers', 'seedcast-sermon-library' ); ?>">
					<?php while ( $query->have_posts() ) {
						$query->the_post();
						echo '<div class="scsl-series-slider__item">';
						TemplateLoader::partial( 'speaker-card', [ 'post' => get_post() ] );
						echo '</div>';
					}
					wp_reset_postdata(); ?>
				</div>
				<button class="scsl-slider-btn scsl-slider-btn--prev" aria-label="<?php esc_attr_e( 'Previous', 'seedcast-sermon-library' ); ?>">&#8592;</button>
				<button class="scsl-slider-btn scsl-slider-btn--next" aria-label="<?php esc_attr_e( 'Next', 'seedcast-sermon-library' ); ?>">&#8594;</button>
			</div>
			<?php
		} else {
			echo '<div class="scsl-speaker-grid scsl-grid-cols-' . esc_attr( $atts['columns'] ) . '">';
			while ( $query->have_posts() ) {
				$query->the_post();
				TemplateLoader::partial( 'speaker-card', [ 'post' => get_post() ] );
			}
			wp_reset_postdata();
			echo '</div>';
		}

		return ob_get_clean();
	}

}
