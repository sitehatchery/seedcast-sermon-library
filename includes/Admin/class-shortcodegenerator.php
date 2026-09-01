<?php
namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shortcode Generator
 *
 * Visual builder that generates SermonLibrary shortcodes without
 * requiring users to know attribute syntax.
 */
class ShortcodeGenerator {

	public function init(): void {
		add_action( 'admin_menu',            [ $this, 'add_page'        ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Shortcode Generator', 'seedcast-sermon-library' ),
			__( 'Shortcodes',          'seedcast-sermon-library' ),
			'edit_posts',
			'seedcast-sermon-library-shortcodes',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) return;

		// Fetch data for dropdowns
		$series   = get_posts( [ 'post_type' => 'scsl_series',  'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ] );
		$speakers = get_posts( [ 'post_type' => 'scsl_speaker', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ] );
		$topics   = get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ] );

		// Topics worth offering here, which are the ones carried by sermons
		// that belong to a series. A series takes its subject from what is
		// preached in it, so a topic no series covers would find nothing.
		$series_topics = [];

		foreach ( ( is_wp_error( $topics ) ? [] : $topics ) as $topic ) {
			$sermons = get_posts( [
				'post_type'      => 'scsl_sermon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => [ [ 'taxonomy' => 'scsl_topic', 'field' => 'term_id', 'terms' => $topic->term_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Building the list of topics worth offering.
			] );

			foreach ( $sermons as $sermon_id ) {
				if ( absint( get_post_meta( $sermon_id, '_scsl_series_id', true ) ) ) {
					$series_topics[] = $topic;
					break;
				}
			}
		}

		// All speakers, for the list somebody chooses from rather than typing
		// slugs read off a URL.
		$speakers = get_posts( [
			'post_type'      => 'scsl_speaker',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );
		// Only the books this church actually preaches from. Offering all
		// sixty-six means a long list where most choices find nothing.
		$books = \SeedcastSermonLibrary\Frontend\Shortcodes::focus_books();
		sort( $books );
		?>
		<div class="wrap scsl-sc-wrap">
			<h1><?php esc_html_e( 'Shortcode Generator', 'seedcast-sermon-library' ); ?></h1>
			<p class="scsl-sc-intro"><?php esc_html_e( 'Choose what you want to display, configure the options, then copy the shortcode into any page or post.', 'seedcast-sermon-library' ); ?></p>

			<div class="scsl-sc-layout">

				<!-- Left: Builder -->
				<div class="scsl-sc-builder">

					<!-- Step 1: Choose display type -->
					<div class="scsl-sc-card">
						<h2 class="scsl-sc-card__title">
							<span class="scsl-sc-step">1</span>
							<?php esc_html_e( 'What do you want to display?', 'seedcast-sermon-library' ); ?>
						</h2>
						<div class="scsl-sc-options">
							<?php
							$display_options = [
								'latest'       => [ 'icon' => '⚡', 'label' => __( 'Latest Sermon',    'seedcast-sermon-library' ), 'desc' => __( 'The most recent sermon only',              'seedcast-sermon-library' ) ],
								'sermon_list'  => [ 'icon' => '☰', 'label' => __( 'Sermon List',      'seedcast-sermon-library' ), 'desc' => __( 'Paginated list with filters',               'seedcast-sermon-library' ) ],
								'series_grid'  => [ 'icon' => '⊞', 'label' => __( 'Series',           'seedcast-sermon-library' ), 'desc' => __( 'Grid or slider of sermon series',            'seedcast-sermon-library' ) ],
								'speaker_grid' => [ 'icon' => '👤', 'label' => __( 'Speakers',         'seedcast-sermon-library' ), 'desc' => __( 'Grid or slider of speakers',                'seedcast-sermon-library' ) ],
								'topic_list'   => [ 'icon' => '🏷', 'label' => __( 'Topics',           'seedcast-sermon-library' ), 'desc' => __( 'The topics your sermons are tagged with',     'seedcast-sermon-library' ) ],
								'content'      => [ 'icon' => '📰', 'label' => __( 'Content',          'seedcast-sermon-library' ), 'desc' => __( 'Recent articles or Bible studies',   'seedcast-sermon-library' ) ],
							];
							foreach ( $display_options as $val => $opt ) : ?>
							<label class="scsl-sc-option <?php echo $val === 'latest' ? esc_attr( 'is-selected' ) : ''; ?>">
								<input type="radio" name="scsl_display" value="<?php echo esc_attr( $val ); ?>"
									   <?php checked( $val, 'latest' ); ?> class="scsl-sc-radio" />
								<span class="scsl-sc-option__icon" aria-hidden="true"><?php echo esc_html( $opt['icon'] ); ?></span>
								<span class="scsl-sc-option__body">
									<strong><?php echo esc_html( $opt['label'] ); ?></strong>
									<span><?php echo esc_html( $opt['desc'] ); ?></span>
								</span>
							</label>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Step 2: Filters (conditional) -->
					<div class="scsl-sc-card" id="scsl-sc-filters" style="display:none;">
						<h2 class="scsl-sc-card__title">
							<span class="scsl-sc-step">2</span>
							<?php esc_html_e( 'Filtering', 'seedcast-sermon-library' ); ?>
						</h2>
						<p class="description"><?php esc_html_e( 'Leave blank to show all. Setting a filter pre-selects it in the dropdown on the page.', 'seedcast-sermon-library' ); ?></p>

						<!-- sermon_list filters -->
						<div class="scsl-sc-filter-group" id="scsl-filters-sermon_list">
							<table class="scsl-sc-table">
								<tr>
									<th><label for="scsl_filter_series"><?php esc_html_e( 'Series', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_filter_series" class="scsl-sc-select" data-param="series_id">
											<option value=""><?php esc_html_e( 'All series', 'seedcast-sermon-library' ); ?></option>
											<?php foreach ( $series as $s ) : ?>
												<option value="<?php echo esc_attr( $s->ID ); ?>"><?php echo esc_html( $s->post_title ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_filter_speaker"><?php esc_html_e( 'Speaker', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_filter_speaker" class="scsl-sc-select" data-param="speaker_id">
											<option value=""><?php esc_html_e( 'All speakers', 'seedcast-sermon-library' ); ?></option>
											<?php foreach ( $speakers as $sp ) : ?>
												<option value="<?php echo esc_attr( $sp->ID ); ?>"><?php echo esc_html( $sp->post_title ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_filter_topic"><?php esc_html_e( 'Topic', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_filter_topic" class="scsl-sc-select" data-param="topic">
											<option value=""><?php esc_html_e( 'All topics', 'seedcast-sermon-library' ); ?></option>
											<?php if ( ! is_wp_error( $topics ) ) :
												foreach ( $topics as $t ) : ?>
													<option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?> (<?php echo esc_html( $t->count ); ?>)</option>
												<?php endforeach;
											endif; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_filter_book"><?php esc_html_e( 'Book of Bible', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_filter_book" class="scsl-sc-select" data-param="book">
											<option value=""><?php esc_html_e( 'All books', 'seedcast-sermon-library' ); ?></option>
											<?php foreach ( $books as $book ) : ?>
												<option value="<?php echo esc_attr( $book ); ?>"><?php echo esc_html( $book ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							</table>
						</div>

						<!-- series_grid filters -->
						<div class="scsl-sc-filter-group" id="scsl-filters-speaker_grid" style="display:none;">
							<table class="scsl-sc-table">
								<tr>
									<th><label for="scsl_speaker_exclude"><?php esc_html_e( 'Leave out', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_speaker_exclude" class="scsl-sc-multi" data-param="hide" multiple size="6" style="min-width:16rem;">
											<?php foreach ( $speakers as $speaker ) : ?>
												<option value="<?php echo esc_attr( $speaker->post_name ); ?>"><?php echo esc_html( get_the_title( $speaker ) ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Hold Ctrl or Cmd to pick more than one. Useful for a Guest Teachers entry that stands for several people rather than one.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="scsl-sc-filter-group" id="scsl-filters-series_grid" style="display:none;">
							<table class="scsl-sc-table">
								<tr <?php echo $series_topics ? '' : 'style="display:none;"'; ?>>
									<th><label for="scsl_grid_topic"><?php esc_html_e( 'Topic', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_grid_topic" class="scsl-sc-multi" data-param="topic" multiple size="6" style="min-width:16rem;">
											<?php foreach ( $series_topics as $t ) : ?>
												<option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description"><?php esc_html_e( 'Hold Ctrl or Cmd to pick more than one. Leave nothing selected for all topics.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
					</div>

					<!-- Step 3: Display options (conditional) -->
					<div class="scsl-sc-card" id="scsl-sc-options-panel" style="display:none;">
						<h2 class="scsl-sc-card__title">
							<span class="scsl-sc-step" id="scsl-step3-num">3</span>
							<?php esc_html_e( 'Display options', 'seedcast-sermon-library' ); ?>
						</h2>

						<!-- sermon_list options -->
						<div class="scsl-sc-opts-group" id="scsl-opts-sermon_list">
							<table class="scsl-sc-table">
								<tr>
									<th><label for="scsl_opt_per_page"><?php esc_html_e( 'Sermons per page', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<input type="number" id="scsl_opt_per_page" min="1" max="50" value="10"
											   class="small-text scsl-sc-input" data-param="per_page" />
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Show filter bar', 'seedcast-sermon-library' ); ?></th>
									<td>
										<label>
											<input type="checkbox" id="scsl_opt_filters" checked class="scsl-sc-check" data-param="show_filters" data-value-on="true" data-value-off="false" />
											<?php esc_html_e( 'Show series, speaker, topic, and date filters', 'seedcast-sermon-library' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_opt_order"><?php esc_html_e( 'Order', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_opt_order" class="scsl-sc-select" data-param="order">
											<option value="DESC" selected><?php esc_html_e( 'Newest first', 'seedcast-sermon-library' ); ?></option>
											<option value="ASC"><?php esc_html_e( 'Oldest first', 'seedcast-sermon-library' ); ?></option>
										</select>
									</td>
								</tr>
							</table>
						</div>

						<!-- series_grid options -->
						<div class="scsl-sc-opts-group" id="scsl-opts-series_grid" style="display:none;">
							<table class="scsl-sc-table">
								<tr id="scsl-series-perpage-row">
									<th><label for="scsl_series_per_page"><?php esc_html_e( 'Series per page', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<input type="number" id="scsl_series_per_page" min="1" max="60" class="small-text scsl-sc-input" data-param="per_page" data-default="<?php echo esc_attr( (string) get_option( 'scsl_series_per_page', 12 ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'scsl_series_per_page', 12 ) ); ?>" />
										<p class="description"><?php esc_html_e( 'How many before pagination. Ignored when a fixed number is set below, or when shown as a slider.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>

								<tr>
									<th><label for="scsl_opt_slider_a"><?php esc_html_e( 'Show as', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_opt_slider_a" class="scsl-sc-select" data-param="slider">
											<option value="false" selected><?php esc_html_e( 'Grid', 'seedcast-sermon-library' ); ?></option>
											<option value="true"><?php esc_html_e( 'Slider', 'seedcast-sermon-library' ); ?></option>
										</select>
									</td>
								</tr>
								<tr id="scsl-opt-limit-row">
									<th><label for="scsl_opt_limit"><?php esc_html_e( 'Limit', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<input type="number" id="scsl_opt_limit" min="1" max="100" placeholder="<?php esc_attr_e( 'All (paginated)', 'seedcast-sermon-library' ); ?>"
											   class="small-text scsl-sc-input" data-param="limit" data-default="" />
										<p class="description"><?php esc_html_e( 'Leave blank to show all with pagination. Enter a number to show exactly that many: works for both grid and slider.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
								<tr id="scsl-opt-columns-row">
									<th><label for="scsl_opt_columns"><?php esc_html_e( 'Columns', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_opt_columns" class="scsl-sc-select" data-param="columns">
											<option value="2"><?php esc_html_e( '2 columns', 'seedcast-sermon-library' ); ?></option>
											<option value="3" selected><?php esc_html_e( '3 columns', 'seedcast-sermon-library' ); ?></option>
											<option value="4"><?php esc_html_e( '4 columns', 'seedcast-sermon-library' ); ?></option>
										</select>
									</td>
								</tr>
							</table>
						</div>

						<!-- speaker_grid options -->
						<div class="scsl-sc-opts-group" id="scsl-opts-topic_list" style="display:none;">
							<table class="scsl-sc-table">
								<tr>
									<th><label for="scsl_topic_columns"><?php esc_html_e( 'Columns', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_topic_columns" class="scsl-sc-select" data-param="columns">
											<option value="2"><?php esc_html_e( '2 per row', 'seedcast-sermon-library' ); ?></option>
											<option value="3" selected><?php esc_html_e( '3 per row', 'seedcast-sermon-library' ); ?></option>
											<option value="4"><?php esc_html_e( '4 per row', 'seedcast-sermon-library' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_topic_orderby"><?php esc_html_e( 'Order', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_topic_orderby" class="scsl-sc-select" data-param="orderby">
											<option value="count" selected><?php esc_html_e( 'Most used first', 'seedcast-sermon-library' ); ?></option>
											<option value="name"><?php esc_html_e( 'Alphabetical', 'seedcast-sermon-library' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Sermon counts', 'seedcast-sermon-library' ); ?></th>
									<td>
										<label>
											<input type="checkbox" id="scsl_topic_count" class="scsl-sc-check" data-param="show_count" data-value-on="true" data-value-off="false" checked />
											<?php esc_html_e( 'Show how many sermons each topic has', 'seedcast-sermon-library' ); ?>
										</label>
									</td>
								</tr>
							</table>
						</div>

						<div class="scsl-sc-opts-group" id="scsl-opts-content" style="display:none;">
							<table class="scsl-sc-table">
								<tr>
									<th><label for="scsl_content_type"><?php esc_html_e( 'Show', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_content_type" class="scsl-sc-select" data-param="type">
											<option value="articles" selected><?php esc_html_e( 'Articles', 'seedcast-sermon-library' ); ?></option>
											<option value="studies"><?php esc_html_e( 'Bible studies', 'seedcast-sermon-library' ); ?></option>
											<option value="sermons"><?php esc_html_e( 'Sermons', 'seedcast-sermon-library' ); ?></option>
													</select>
										<p class="description"><?php esc_html_e( 'Articles and Bible studies link to the sermon page and open straight to that tab.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_content_paginate"><?php esc_html_e( 'Pagination', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<label>
											<input type="checkbox" id="scsl_content_paginate" class="scsl-sc-check" data-param="paginate" data-value-on="true" data-value-off="false" data-default-off="1" />
											<?php esc_html_e( 'Show page links', 'seedcast-sermon-library' ); ?>
										</label>
										<p class="description"><?php esc_html_e( 'Without this the block shows only the newest few and there is no way to reach anything older.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_content_count"><span class="scsl-count-label"><?php esc_html_e( 'How many', 'seedcast-sermon-library' ); ?></span></label></th>
									<td><input type="number" id="scsl_content_count" min="1" max="24" value="6" class="small-text scsl-sc-input" data-param="count" /></td>
								</tr>
								<tr>
									<th><label for="scsl_content_layout"><?php esc_html_e( 'Show as', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_content_layout" class="scsl-sc-select" data-param="layout">
											<option value="list" selected><?php esc_html_e( 'List', 'seedcast-sermon-library' ); ?></option>
											<option value="grid"><?php esc_html_e( 'Grid', 'seedcast-sermon-library' ); ?></option>
											<option value="slider"><?php esc_html_e( 'Slider', 'seedcast-sermon-library' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'A list of sermons shows the speaker photo. Everything else uses the sermon or series artwork.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_content_columns"><?php esc_html_e( 'Columns', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_content_columns" class="scsl-sc-select" data-param="columns">
											<option value="2"><?php esc_html_e( '2 per row', 'seedcast-sermon-library' ); ?></option>
											<option value="3" selected><?php esc_html_e( '3 per row', 'seedcast-sermon-library' ); ?></option>
											<option value="4"><?php esc_html_e( '4 per row', 'seedcast-sermon-library' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'Grid only, and it drops to fewer on smaller screens.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_content_order"><?php esc_html_e( 'Order', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_content_order" class="scsl-sc-select" data-param="order">
											<option value="recent" selected><?php esc_html_e( 'Most recent', 'seedcast-sermon-library' ); ?></option>
											<option value="popular"><?php esc_html_e( 'Most viewed', 'seedcast-sermon-library' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'Most viewed leaves out anything nobody has opened yet, so a young archive may show fewer than asked for.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="scsl_content_title"><?php esc_html_e( 'Heading', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<input type="text" id="scsl_content_title" class="regular-text scsl-sc-input" data-param="title" data-default="" />
										<p class="description"><?php esc_html_e( 'Leave blank for no heading.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="scsl-sc-opts-group" id="scsl-opts-speaker_grid" style="display:none;">
							<table class="scsl-sc-table">
								<tr>
									<th><label for="scsl_speaker_orderby"><?php esc_html_e( 'Order', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_speaker_orderby" class="scsl-sc-select" data-param="orderby">
											<option value="menu_order" selected><?php esc_html_e( 'The order you arranged them in', 'seedcast-sermon-library' ); ?></option>
											<option value="sermons"><?php esc_html_e( 'Most sermons first', 'seedcast-sermon-library' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'Most sermons first usually puts your pastor at the front without arranging anything by hand.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>

								<tr>
									<th><label for="scsl_opt_slider_b"><?php esc_html_e( 'Show as', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select id="scsl_opt_slider_b" class="scsl-sc-select" data-param="slider">
											<option value="false" selected><?php esc_html_e( 'Grid', 'seedcast-sermon-library' ); ?></option>
											<option value="true"><?php esc_html_e( 'Slider', 'seedcast-sermon-library' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th><label><?php esc_html_e( 'Limit', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<input type="number" min="1" max="100" placeholder="<?php esc_attr_e( 'All', 'seedcast-sermon-library' ); ?>"
											   class="small-text scsl-sc-input" data-param="limit" data-default="" />
										<p class="description"><?php esc_html_e( 'Leave blank to show all speakers. Enter a number to show exactly that many.', 'seedcast-sermon-library' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label><?php esc_html_e( 'Columns', 'seedcast-sermon-library' ); ?></label></th>
									<td>
										<select class="scsl-sc-select" data-param="columns">
											<option value="2"><?php esc_html_e( '2 columns', 'seedcast-sermon-library' ); ?></option>
											<option value="3" selected><?php esc_html_e( '3 columns', 'seedcast-sermon-library' ); ?></option>
											<option value="4"><?php esc_html_e( '4 columns', 'seedcast-sermon-library' ); ?></option>
										</select>
									</td>
								</tr>
							</table>
						</div>
					</div>

				</div><!-- /.scsl-sc-builder -->

				<!-- Right: Output -->
				<div class="scsl-sc-output-wrap">
					<div class="scsl-sc-output-card" id="scsl-sc-output-card">
						<div class="scsl-sc-output-header">
							<h2><?php esc_html_e( 'Your shortcode', 'seedcast-sermon-library' ); ?></h2>
						</div>

						<div class="scsl-sc-output-code-wrap">
							<code id="scsl-sc-output" class="scsl-sc-code">[scsl_sermon_list]</code>
						</div>

						<button type="button" id="scsl-sc-copy-btn" class="button button-primary scsl-sc-copy-btn">
							<?php esc_html_e( 'Copy Shortcode', 'seedcast-sermon-library' ); ?>
						</button>
						<span id="scsl-sc-copied" class="scsl-sc-copied" aria-live="polite" style="display:none;">
							<?php esc_html_e( '✓ Copied!', 'seedcast-sermon-library' ); ?>
						</span>

						<div class="scsl-sc-divider"></div>

						<h3><?php esc_html_e( 'How to use it', 'seedcast-sermon-library' ); ?></h3>
						<ol class="scsl-sc-instructions">
							<li><?php esc_html_e( 'Copy the shortcode above', 'seedcast-sermon-library' ); ?></li>
							<li><?php esc_html_e( 'Open any page or post in WordPress', 'seedcast-sermon-library' ); ?></li>
							<li><?php esc_html_e( 'Add a Shortcode block (Gutenberg) or paste directly into the editor', 'seedcast-sermon-library' ); ?></li>
							<li><?php esc_html_e( 'Save the page', 'seedcast-sermon-library' ); ?></li>
						</ol>

						<div class="scsl-sc-divider"></div>

						<h3><?php esc_html_e( 'All available shortcodes', 'seedcast-sermon-library' ); ?></h3>
						<table class="scsl-sc-ref-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Shortcode', 'seedcast-sermon-library' ); ?></th>
									<th><?php esc_html_e( 'What it displays', 'seedcast-sermon-library' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code>[scsl_latest]</code></td>
									<td><?php esc_html_e( 'Most recent sermon', 'seedcast-sermon-library' ); ?></td>
								</tr>
								<tr>
									<td><code>[scsl_sermon_list]</code></td>
									<td><?php esc_html_e( 'Full sermon list with filters', 'seedcast-sermon-library' ); ?></td>
								</tr>
								<tr>
									<td><code>[scsl_series_grid]</code></td>
									<td><?php esc_html_e( 'Grid of all series', 'seedcast-sermon-library' ); ?></td>
								</tr>
								<tr>
									<td><code>[scsl_speaker_grid]</code></td>
									<td><?php esc_html_e( 'Grid of all speakers', 'seedcast-sermon-library' ); ?></td>
								</tr>
								<tr>
									<td><code>[scsl_content]</code></td>
									<td><?php esc_html_e( 'Recent articles, Bible studies, sermons or series', 'seedcast-sermon-library' ); ?></td>
								</tr>
								<tr>
									<td><code>[scsl_topic_list]</code></td>
									<td><?php esc_html_e( 'Grid of topic pills', 'seedcast-sermon-library' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</div><!-- /.scsl-sc-output-wrap -->

			</div><!-- /.scsl-sc-layout -->
		</div>

		<?php
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'seedcast-sermon-library-shortcodes' ) === false ) return;
		wp_enqueue_style(  'scsl-admin', SCSL_PLUGIN_URL . 'assets/css/admin.css', [], SCSL_VERSION );
		wp_enqueue_script( 'scsl-admin', SCSL_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], SCSL_VERSION, true );
		wp_add_inline_style(  'scsl-admin', <<<'CSS'
/* ── Layout ───────────────────────────── */
.scsl-sc-wrap { max-width: 1100px; }
.scsl-sc-intro { color: #646970; margin-bottom: 1.5rem; font-size: 14px; }
.scsl-sc-layout {
	display: grid;
	grid-template-columns: 1fr 320px;
	gap: 1.5rem;
	align-items: start;
}
@media (max-width: 900px) { .scsl-sc-layout { grid-template-columns: 1fr; } }

/* ── Cards ────────────────────────────── */
.scsl-sc-card {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 6px;
	padding: 1.25rem 1.5rem;
	margin-bottom: 1rem;
}
.scsl-sc-card__title {
	font-size: 14px;
	font-weight: 700;
	color: #1d2327;
	margin: 0 0 1rem;
	display: flex;
	align-items: center;
	gap: .5rem;
}
.scsl-sc-step {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	background: #2271b1;
	color: #fff;
	border-radius: 50%;
	font-size: 12px;
	font-weight: 800;
	flex-shrink: 0;
}

/* ── Display type options ─────────────── */
.scsl-sc-options { display: flex; flex-direction: column; gap: .5rem; }
.scsl-sc-option {
	display: flex;
	align-items: center;
	gap: .75rem;
	padding: .75rem 1rem;
	border: 2px solid #dcdcde;
	border-radius: 6px;
	cursor: pointer;
	transition: border-color .15s, background .15s;
	background: #fff;
}
.scsl-sc-option:hover { border-color: #2271b1; background: #f0f6fc; }
.scsl-sc-option.is-selected { border-color: #2271b1; background: #f0f6fc; }
.scsl-sc-radio { position: absolute; opacity: 0; width: 0; height: 0; }
.scsl-sc-option__icon {
	font-size: 1.25rem;
	width: 32px;
	text-align: center;
	flex-shrink: 0;
}
.scsl-sc-option__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
.scsl-sc-option__body strong { font-size: 13px; color: #1d2327; }
.scsl-sc-option__body span   { font-size: 12px; color: #646970; }

/* ── Filter / Options table ───────────── */
.scsl-sc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.scsl-sc-table th {
	width: 160px;
	text-align: left;
	padding: .5rem .5rem .5rem 0;
	font-weight: 600;
	color: #1d2327;
	vertical-align: middle;
}
.scsl-sc-table td { padding: .4rem 0; vertical-align: middle; }
.scsl-sc-table select,
.scsl-sc-table input[type="number"] { min-height: 32px; }

/* ── Output card ──────────────────────── */
.scsl-sc-output-card {
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 6px;
	padding: 1.25rem 1.5rem;
	position: sticky;
	top: 32px;
}
.scsl-sc-output-header h2 {
	font-size: 14px;
	font-weight: 700;
	color: #1d2327;
	margin: 0 0 .75rem;
}
.scsl-sc-output-code-wrap {
	background: #1e1e1e;
	border-radius: 4px;
	padding: .85rem 1rem;
	margin-bottom: .75rem;
	overflow-x: auto;
}
.scsl-sc-code {
	font-family: 'Courier New', Courier, monospace;
	font-size: 13px;
	color: #9cdcfe;
	white-space: pre;
	display: block;
	word-break: break-all;
}
.scsl-sc-copy-btn { width: 100%; justify-content: center; }
.scsl-sc-copied {
	display: block;
	text-align: center;
	color: #00a32a;
	font-weight: 600;
	font-size: 13px;
	margin-top: .4rem;
}
.scsl-sc-divider {
	border: none;
	border-top: 1px solid #f0f0f1;
	margin: 1rem 0;
}
.scsl-sc-output-card h3 {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .05em;
	color: #646970;
	margin: 0 0 .6rem;
}
.scsl-sc-instructions {
	font-size: 12px;
	color: #646970;
	padding-left: 1.25rem;
	margin: 0;
	line-height: 1.8;
}

/* ── Reference table ──────────────────── */
.scsl-sc-ref-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 12px;
}
.scsl-sc-ref-table th {
	text-align: left;
	padding: .3rem .4rem;
	background: #f6f7f7;
	border-bottom: 1px solid #dcdcde;
	font-weight: 600;
	color: #646970;
	text-transform: uppercase;
	letter-spacing: .04em;
	font-size: 10px;
}
.scsl-sc-ref-table td {
	padding: .4rem .4rem;
	border-bottom: 1px solid #f0f0f1;
	vertical-align: middle;
	color: #646970;
}
.scsl-sc-ref-table tr:last-child td { border-bottom: none; }
.scsl-sc-ref-table code {
	background: #f0f0f1;
	padding: .1rem .3rem;
	border-radius: 3px;
	font-size: 11px;
	color: #2271b1;
}
CSS
		);
		// The script is a nowdoc, so nothing inside it is translated. The two
		// labels that change as the form is used are handed in from here
		// instead of written into the JavaScript in English.
		wp_localize_script( 'scsl-admin', 'scslSc', [
			'howMany' => __( 'How many', 'seedcast-sermon-library' ),
			'perPage' => __( 'How many per page', 'seedcast-sermon-library' ),
		] );

		wp_add_inline_script( 'scsl-admin', <<<'JS'
jQuery( function( $ ) {

	// ── State ──────────────────────────────────────────────────
	var state = {
display    : 'latest',
params     : {},
	};

	// ── Display type selection ─────────────────────────────────
	$( '.scsl-sc-option' ).on( 'click', function() {
$( '.scsl-sc-option' ).removeClass( 'is-selected' );
$( this ).addClass( 'is-selected' );
var val = $( this ).find( '.scsl-sc-radio' ).val();
state.display = val;
state.params  = {};
// Put every control back to its first option, so switching display type
// does not carry a choice across from the one before it.
$( '.scsl-sc-opts-group select, .scsl-sc-filter-group select' ).each( function() {
	// Back to whichever option is marked as the default in the markup, not
	// simply the first. Showing the first while rendering the default means
	// choosing what is already displayed does nothing at all.
	var i = 0;

	$.each( this.options, function( index, option ) {
		if ( option.defaultSelected ) {
			i = index;
			return false;
		}
	} );

	this.selectedIndex = i;
} );
$( '.scsl-sc-opts-group input[type="checkbox"], .scsl-sc-filter-group input[type="checkbox"]' ).each( function() {
	// Back to how the markup has it, not simply off. A box that is ticked by
	// default appeared unticked here while the shortcode behaved as though it
	// were ticked, so the two disagreed from the moment the panel opened.
	this.checked = this.defaultChecked;
} );
$( '.scsl-sc-multi' ).val( [] );

// Text and number fields go back to whatever the markup carries, so a
// heading typed under one display type does not follow somebody to the next.
$( '.scsl-sc-opts-group .scsl-sc-input, .scsl-sc-filter-group .scsl-sc-input' ).each( function() {
	this.value = this.defaultValue;
} );
$( '#scsl-opt-limit-row' ).show();
$( '#scsl-opt-columns-row' ).show();
$( '#scsl_content_columns' ).closest( 'tr' ).show();
updatePanels( val );
buildShortcode();
	} );

	function updatePanels( display ) {
// Show/hide filter panel
if ( display === 'latest' ) {
	$( '#scsl-sc-filters' ).hide();
	$( '#scsl-sc-options-panel' ).hide();
} else if ( display === 'content' || display === 'topic_list' ) {
	// Its own options cover what to show, so the shared filter panel would
	// appear with a heading and nothing underneath it.
	$( '#scsl-sc-filters' ).hide();
	$( '#scsl-sc-options-panel' ).show();
} else {
	$( '#scsl-sc-filters' ).show();
	$( '#scsl-sc-options-panel' ).show();
}

// Toggle filter groups
$( '.scsl-sc-filter-group' ).hide();
$( '#scsl-filters-' + display ).show();

// Toggle options groups
$( '.scsl-sc-opts-group' ).hide();
$( '#scsl-opts-' + display ).show();

// Update step numbers
$( '#scsl-step3-num' ).text( ( display === 'latest' || display === 'content' || display === 'topic_list' ) ? '2' : '3' );
	}

	// ── Param collection ───────────────────────────────────────
	$( document ).on( 'change', '.scsl-sc-select', function() {
var param = $( this ).data( 'param' );
var val   = $( this ).val();

// Whatever the markup marks as selected is what the shortcode does anyway,
// so naming it would only be noise in what somebody copies out.
var dflt = '';

$.each( this.options, function( i, option ) {
	if ( option.defaultSelected ) {
		dflt = option.value;
		return false;
	}
} );

if ( val && val !== dflt ) {
	state.params[ param ] = val;
} else {
	delete state.params[ param ];
}

// Columns only mean something in a grid. A slider is one row however many
// were asked for, so the control is put away rather than left to mislead.
if ( param === 'slider' || param === 'layout' ) {
	var isGrid = ( 'slider' === param ) ? ( 'true' !== val ) : ( 'grid' === val );

	$( '#scsl-opt-columns-row' ).toggle( isGrid );
	$( '#scsl_content_columns' ).closest( 'tr' ).toggle( isGrid );

	if ( ! isGrid ) delete state.params['columns'];
}

buildShortcode();
	} );

	$( document ).on( 'input change', '.scsl-sc-input', function() {
var param    = $( this ).data( 'param' );
var val      = $( this ).val();
var def      = $( this ).data( 'default' );
var defaults = { per_page: '10', limit: '', columns: '3' };
var dflt     = ( def !== undefined ) ? String( def ) : ( defaults[ param ] !== undefined ? defaults[ param ] : '' );
if ( val !== '' && val !== dflt ) {
	state.params[ param ] = val;
} else {
	delete state.params[ param ];
}
buildShortcode();
	} );

	// A multiple select returns an array, which the ordinary select handler
	// would turn into something meaningless, so it is joined into the comma
	// separated list the shortcode reads.
	$( document ).on( 'change', '.scsl-sc-multi', function() {
var param = $( this ).data( 'param' );
var vals  = $( this ).val() || [];

if ( vals.length ) {
	state.params[ param ] = vals.join( ',' );
} else {
	delete state.params[ param ];
}
buildShortcode();
	} );

	$( document ).on( 'change', '.scsl-sc-check', function() {
var param   = $( this ).data( 'param' );
var valOn   = $( this ).data( 'value-on' );
var valOff  = $( this ).data( 'value-off' );
var checked = $( this ).is( ':checked' );

// A shortcode only needs to name what differs from its own default, so which
// state is worth writing depends on which way the box starts. Ticked by
// default means naming it when unticked, and unticked by default means the
// reverse. Treating both the same writes the wrong one every time.
if ( $( this ).data( 'default-off' ) ) {
	if ( checked ) {
		state.params[ param ] = valOn;
	} else {
		delete state.params[ param ];
	}
} else if ( ! checked ) {
	state.params[ param ] = valOff;
} else {
	delete state.params[ param ];
}
buildShortcode();
	} );

	/*
	 * A slider has no pages, so the choice does not apply to it and is taken
	 * off the screen rather than left there doing nothing. The count means
	 * something different once pages are on, so it says so.
	 */
	function reflectContentPagination() {
		var $row = $( '#scsl_content_paginate' ).closest( 'tr' );

		if ( ! $row.length ) return;

		var slider = $( '#scsl_content_layout' ).val() === 'slider';

		$row.toggle( ! slider );

		if ( slider && $( '#scsl_content_paginate' ).is( ':checked' ) ) {
			$( '#scsl_content_paginate' ).prop( 'checked', false ).trigger( 'change' );
		}

		var paging = ! slider && $( '#scsl_content_paginate' ).is( ':checked' );

		$( '.scsl-count-label' ).text( paging ? scslSc.perPage : scslSc.howMany );
	}

	$( document ).on( 'change', '#scsl_content_layout, #scsl_content_paginate', reflectContentPagination );
	reflectContentPagination();

	// ── Build shortcode ────────────────────────────────────────
	function buildShortcode() {
var tag = {
	'latest'       : 'scsl_latest',
	'sermon_list'  : 'scsl_sermon_list',
	'series_grid'  : 'scsl_series_grid',
	'speaker_grid' : 'scsl_speaker_grid',
	'content'      : 'scsl_content',
	'topic_list'   : 'scsl_topic_list',
}[ state.display ] || 'scsl_sermon_list';

var sc = '[' + tag;
$.each( state.params, function( key, val ) {
	if ( val !== '' && val !== null && val !== undefined ) {
sc += ' ' + key + '="' + val + '"';
	}
} );
sc += ']';

$( '#scsl-sc-output' ).text( sc );
	}

	// ── Copy button ────────────────────────────────────────────
	$( '#scsl-sc-copy-btn' ).on( 'click', function() {
var text = $( '#scsl-sc-output' ).text();
if ( navigator.clipboard ) {
	navigator.clipboard.writeText( text ).then( function() {
showCopied();
	} );
} else {
	// Fallback
	var $tmp = $( '<textarea>' ).val( text ).appendTo( 'body' ).select();
	document.execCommand( 'copy' );
	$tmp.remove();
	showCopied();
}
	} );

	function showCopied() {
$( '#scsl-sc-copied' ).show();
setTimeout( function() { $( '#scsl-sc-copied' ).hide(); }, 2000 );
	}

	// ── Init ───────────────────────────────────────────────────
	updatePanels( 'sermon_list' );
	buildShortcode();

} );
JS
		);
	}
}
