<?php
namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class AdminColumns {

	public function init(): void {
		add_filter( 'manage_scsl_sermon_posts_columns', [ $this, 'add_content_column' ], 20 );
		add_action( 'manage_scsl_sermon_posts_custom_column', [ $this, 'render_content_column' ], 20, 2 );
		// Sermon columns
		add_filter( 'manage_scsl_sermon_posts_columns',         [ $this, 'sermon_columns'    ] );
		add_action( 'manage_scsl_sermon_posts_custom_column',   [ $this, 'sermon_column_data' ], 10, 2 );
		add_filter( 'manage_edit-scsl_sermon_sortable_columns', [ $this, 'sermon_sortable'   ] );

		// Sermon list filters (Series, Speaker, search is built-in)
		add_action( 'restrict_manage_posts', [ $this, 'sermon_filter_dropdowns' ] );
		add_filter( 'parse_query',           [ $this, 'sermon_filter_query'     ] );

		// Quick Edit: series selector
		add_action( 'quick_edit_custom_box',  [ $this, 'quick_edit_series_field' ], 10, 2 );
		add_action( 'quick_edit_custom_box',  [ $this, 'quick_edit_unlisted_field' ], 10, 2 );

		/*
		 * Say which sermons are unlisted, on the list itself.
		 *
		 * Without it the setting is invisible from the screen where somebody
		 * is deciding about it: a sermon that has quietly dropped out of the
		 * lists looks exactly like one that has not.
		 */
		add_filter( 'display_post_states', [ $this, 'unlisted_state' ], 10, 2 );

		/*
		 * The post type is scsl_sermon, so save_post_sl_sermon never fires and
		 * the series chosen in Quick Edit has never been kept. Both are hooked
		 * to the right name here.
		 */
		add_action( 'save_post_scsl_sermon',  [ $this, 'quick_edit_save_series'   ] );
		add_action( 'save_post_scsl_sermon',  [ $this, 'quick_edit_save_unlisted' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_quick_edit_js'   ] );

		// Series columns + drag-and-drop ordering
		add_filter( 'manage_scsl_series_posts_columns',         [ $this, 'series_columns'    ] );
		add_action( 'manage_scsl_series_posts_custom_column',   [ $this, 'series_column_data' ], 10, 2 );
		add_action( 'admin_enqueue_scripts',                  [ $this, 'enqueue_sortable'  ] );
		add_action( 'wp_ajax_scsl_reorder_series',              [ $this, 'ajax_reorder'      ] );

		// Speaker columns + drag-and-drop ordering
		add_filter( 'manage_scsl_speaker_posts_columns',        [ $this, 'speaker_columns'   ] );
		add_action( 'manage_scsl_speaker_posts_custom_column',  [ $this, 'speaker_column_data' ], 10, 2 );
		add_action( 'wp_ajax_scsl_reorder_speakers',            [ $this, 'ajax_reorder_speakers' ] );

		// Pre-sort series and speaker list by menu_order
		add_action( 'pre_get_posts', [ $this, 'sort_series_by_order' ] );

		// Topic save handler
		add_action( 'admin_post_sl_save_topic', [ $this, 'handle_save_topic' ] );
	}

	// ── Series ordering ───────────────────────────────────────────────────

	public function sort_series_by_order( \WP_Query $query ): void {
		// Admin list: sort series and speakers by menu_order
		if ( is_admin() && $query->is_main_query() && ! $query->get( 'orderby' ) ) {
			$pt = $query->get( 'post_type' );
			if ( $pt === 'scsl_series' || $pt === 'scsl_speaker' ) {
				$query->set( 'orderby', 'menu_order' );
				$query->set( 'order',   'ASC' );
				return;
			}
		}
		// Front-end archive: sort series by menu_order first, then date
		if ( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'scsl_series' ) ) {
			$query->set( 'orderby', [ 'menu_order' => 'ASC', 'date' => 'DESC' ] );
		}
	}

	public function enqueue_sortable( string $hook ): void {
		if ( $hook !== 'edit.php' ) return;
		$screen = get_current_screen();
		if ( ! $screen ) return;

		if ( $screen->post_type === 'scsl_series' ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_add_inline_script( 'jquery-ui-sortable', $this->sortable_js( 'scsl_series', 'scsl_reorder_series' ) );
			wp_add_inline_style( 'list-tables', $this->sortable_css() );
		}

		if ( $screen->post_type === 'scsl_speaker' ) {
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_add_inline_script( 'jquery-ui-sortable', $this->sortable_js( 'scsl_speaker', 'scsl_reorder_speakers' ) );
			wp_add_inline_style( 'list-tables', $this->sortable_css() );
		}
	}

	private function sortable_css(): string {
		return '
			#the-list tr { cursor: grab; }
			#the-list tr.scsl-drag-placeholder { background: #f0e9d7 !important; outline: 2px dashed #c9a84c; }
			.scsl-order-handle { color: #aaa; cursor: grab; font-size: 16px; padding: 0 6px; }
			.column-scsl_order { width: 40px; }
		';
	}

	private function sortable_js( string $post_type, string $action ): string {
		$nonce    = wp_create_nonce( $action );
		$ajax_url = admin_url( 'admin-ajax.php' );
		return 'jQuery( function( $ ) { '
			. 'var $list = $( "#the-list" ); '
			. 'if ( ! $list.length ) return; '
			. '$list.sortable( { items: "tr", axis: "y", handle: ".scsl-order-handle", '
			. 'placeholder: "scsl-drag-placeholder", tolerance: "pointer", '
			. 'update: function() { '
			. 'var order = []; '
			. '$list.find( "tr[id]" ).each( function() { '
			. 'var id = $( this ).attr( "id" ).replace( "post-", "" ); '
			. 'if ( id ) order.push( id ); '
			. '} ); '
			. '$.post( ' . wp_json_encode( $ajax_url ) . ', { '
			. 'action: ' . wp_json_encode( $action ) . ', '
			. 'nonce: ' . wp_json_encode( $nonce ) . ', '
			. 'order: order } ); '
			. '} } ); '
			. '} );';
	}

	public function ajax_reorder(): void {
		check_ajax_referer( 'scsl_reorder_series', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();
		$order = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : [];
		foreach ( $order as $position => $post_id ) {
			if ( ! $post_id ) continue;
			wp_update_post( [ 'ID' => $post_id, 'menu_order' => $position ] );
		}
		wp_send_json_success();
	}

	public function ajax_reorder_speakers(): void {
		check_ajax_referer( 'scsl_reorder_speakers', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();
		$order = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : [];
		foreach ( $order as $position => $post_id ) {
			if ( ! $post_id ) continue;
			wp_update_post( [ 'ID' => $post_id, 'menu_order' => $position ] );
		}
		wp_send_json_success();
	}

	public function speaker_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( $key === 'title' ) {
				$new['scsl_order'] = '<span title="' . esc_attr__( 'Drag to reorder', 'seedcast-sermon-library' ) . '">☰</span>';
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	public function speaker_column_data( string $column, int $post_id ): void {
		if ( $column === 'scsl_order' ) {
			echo '<span class="scsl-order-handle" title="' . esc_attr__( 'Drag to reorder', 'seedcast-sermon-library' ) . '">⠿</span>';
		}
	}

	// ── Sermon admin list filters ─────────────────────────────────────────────

	public function sermon_filter_dropdowns(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'scsl_sermon' ) return;

		// Series filter
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin list filter, no data mutation
		$selected_series  = isset( $_GET['scsl_series_filter']  ) ? absint( wp_unslash( $_GET['scsl_series_filter']  ) ) : 0;
		$selected_speaker = isset( $_GET['scsl_speaker_filter'] ) ? absint( wp_unslash( $_GET['scsl_speaker_filter'] ) ) : 0;
		$selected_topic   = isset( $_GET['scsl_topic_filter']   ) ? absint( wp_unslash( $_GET['scsl_topic_filter']   ) ) : 0;
		// phpcs:enable

		$series   = get_posts( [ 'post_type' => 'scsl_series',  'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$speakers = get_posts( [ 'post_type' => 'scsl_speaker', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		$topics   = get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => false ] );

		echo '<select name="scsl_series_filter">';
		echo '<option value="">' . esc_html__( 'All Series', 'seedcast-sermon-library' ) . '</option>';
		foreach ( $series as $s ) {
			printf( '<option value="%d" %s>%s</option>',
				esc_attr( $s->ID ),
				selected( $selected_series, $s->ID, false ),
				esc_html( $s->post_title )
			);
		}
		echo '</select>';

		echo '<select name="scsl_speaker_filter">';
		echo '<option value="">' . esc_html__( 'All Speakers', 'seedcast-sermon-library' ) . '</option>';
		foreach ( $speakers as $sp ) {
			printf( '<option value="%d" %s>%s</option>',
				esc_attr( $sp->ID ),
				selected( $selected_speaker, $sp->ID, false ),
				esc_html( $sp->post_title )
			);
		}
		echo '</select>';

		if ( ! is_wp_error( $topics ) && $topics ) {
			echo '<select name="scsl_topic_filter">';
			echo '<option value="">' . esc_html__( 'All Topics', 'seedcast-sermon-library' ) . '</option>';
			foreach ( $topics as $t ) {
				printf( '<option value="%d" %s>%s (%d)</option>',
					esc_attr( $t->term_id ),
					selected( $selected_topic, $t->term_id, false ),
					esc_html( $t->name ),
					esc_html( $t->count )
				);
			}
			echo '</select>';
		}
	}

	public function sermon_filter_query( \WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || $pagenow !== 'edit.php' || ! $query->is_main_query() ) return;
		if ( ( $query->query['post_type'] ?? '' ) !== 'scsl_sermon' ) return;

		$meta_query = $query->get( 'meta_query' ) ?: [];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin list filter, no data mutation
		$series_id  = isset( $_GET['scsl_series_filter']  ) ? absint( wp_unslash( $_GET['scsl_series_filter']  ) ) : 0;
		$speaker_id = isset( $_GET['scsl_speaker_filter'] ) ? absint( wp_unslash( $_GET['scsl_speaker_filter'] ) ) : 0;
		$topic_id   = isset( $_GET['scsl_topic_filter']   ) ? absint( wp_unslash( $_GET['scsl_topic_filter']   ) ) : 0;
		// phpcs:enable

		if ( $series_id ) {
			$meta_query[] = [ 'key' => '_scsl_series_id', 'value' => $series_id ];
		}
		if ( $speaker_id ) {
			$meta_query[] = [ 'key' => '_scsl_speaker_id', 'value' => $speaker_id ];
		}
		if ( $meta_query ) {
			$query->set( 'meta_query', $meta_query );
		}
		if ( $topic_id ) {
			$query->set( 'tax_query', [ [
				'taxonomy' => 'scsl_topic',
				'field'    => 'term_id',
				'terms'    => $topic_id,
			] ] );
		}
	}

	// ── Sermon columns ────────────────────────────────────────────────────────

	public function sermon_columns( array $columns ): array {
		return [
			'cb'                 => $columns['cb'] ?? '<input type="checkbox" />',
			'title'              => $columns['title'] ?? __( 'Title', 'seedcast-sermon-library' ),
			'scsl_series'          => __( 'Series',  'seedcast-sermon-library' ),
			'scsl_speaker'         => __( 'Speaker', 'seedcast-sermon-library' ),
			'scsl_recorded_date'   => __( 'Date',    'seedcast-sermon-library' ),
			'scsl_views'           => __( 'Views',   'seedcast-sermon-library' ),
		];
	}

	public function sermon_column_data( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'scsl_series':
				/*
				 * The unlisted state, carried on the row.
				 *
				 * Quick Edit builds one form and reuses it, so the box cannot
				 * be drawn already ticked. It is read from here when the form
				 * opens. Hidden, because the column is about the series and a
				 * second thing shown in it would only be noise.
				 */
				printf(
					'<span class="scsl-row-unlisted" data-unlisted="%d" hidden></span>',
					\SeedcastSermonLibrary\Frontend\Unlisted::is_unlisted( $post_id ) ? 1 : 0
				);

				$series_id = get_post_meta( $post_id, '_scsl_series_id', true );
				if ( $series_id ) {
					// data-series-id used by Quick Edit JS to pre-populate dropdown
					echo '<a href="' . esc_url( get_edit_post_link( $series_id ) ) . '" data-series-id="' . esc_attr( $series_id ) . '">'
						. esc_html( get_the_title( $series_id ) ) . '</a>';
				} else {
					echo '<span style="color:#aaa">-</span>';
				}
				break;

			case 'scsl_speaker':
				$speaker_id = get_post_meta( $post_id, '_scsl_speaker_id', true );
				echo $speaker_id // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output escaped in both ternary branches
					? '<a href="' . esc_url( get_edit_post_link( $speaker_id ) ) . '">' . esc_html( get_the_title( $speaker_id ) ) . '</a>'
					: '<span style="color:#aaa">-</span>';
				break;

			case 'scsl_recorded_date':
				$date = get_post_meta( $post_id, '_scsl_recorded_date', true );
				echo $date // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output escaped in both ternary branches
					? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) )
					: '<span style="color:#aaa">-</span>';
				break;

			case 'scsl_views':
				$views = absint( get_post_meta( $post_id, '_scsl_view_count', true ) );
				echo $views ? esc_html( number_format_i18n( $views ) ) : wp_kses( '<span style="color:#aaa">0</span>', [ 'span' => [ 'style' => true ] ] );
				break;
		}
	}

	public function sermon_sortable( array $columns ): array {
		$columns['scsl_recorded_date'] = '_scsl_recorded_date';
		$columns['scsl_series']        = '_scsl_series_id';
		$columns['scsl_views']         = '_scsl_view_count';
		return $columns;
	}

	// ── Quick Edit: Series ────────────────────────────────────────────────────

	public function quick_edit_series_field( string $column_name, string $post_type ): void {
		if ( $column_name !== 'scsl_series' || $post_type !== 'scsl_sermon' ) return;
		$series = get_posts( [
			'post_type'   => 'scsl_series',
			'numberposts' => -1,
			'orderby'     => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'post_status' => 'publish',
		] );
		wp_nonce_field( 'scsl_quick_edit_series', 'scsl_quick_edit_nonce' );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Series', 'seedcast-sermon-library' ); ?></span>
					<select name="scsl_quick_series_id">
						<option value="0"><?php esc_html_e( 'No Series', 'seedcast-sermon-library' ); ?></option>
						<?php foreach ( $series as $s ) : ?>
							<option value="<?php echo esc_attr( $s->ID ); ?>"><?php echo esc_html( $s->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	// ── Quick Edit: Unlisted ──────────────────────────────────────────────────

	/**
	 * The unlisted box, in Quick Edit.
	 *
	 * Deciding a sermon should be out of the way is usually a thought had
	 * while looking down the list, not a reason to open one.
	 *
	 * Its current state cannot be printed here. WordPress builds one Quick
	 * Edit form and reuses it for whichever row is being edited, so anything
	 * ticked in the markup would appear ticked for every sermon. The value is
	 * put on the row instead and read across when the form opens.
	 *
	 * @param string $column_name Column being rendered.
	 * @param string $post_type   Post type of the list.
	 * @return void
	 */
	/**
	 * A red mark beside the title of an unlisted sermon.
	 *
	 * @param array    $states Existing states.
	 * @param \WP_Post $post   The sermon.
	 * @return array
	 */
	public function unlisted_state( $states, $post ) {
		if ( ! $post instanceof \WP_Post || 'scsl_sermon' !== $post->post_type ) return $states;

		if ( '1' === (string) get_post_meta( $post->ID, '_scsl_unlisted', true ) ) {
			$states['scsl_unlisted'] = '<span style="color:#b32d2e;">'
				. esc_html__( 'Unlisted', 'seedcast-sermon-library' )
				. '</span>';
		}

		return $states;
	}

	public function quick_edit_unlisted_field( string $column_name, string $post_type ): void {
		if ( 'scsl_views' !== $column_name || 'scsl_sermon' !== $post_type ) return;

		wp_nonce_field( 'scsl_quick_edit_unlisted', 'scsl_quick_unlisted_nonce' );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label style="display:block;">
					<input type="hidden" name="scsl_quick_unlisted" value="0" />
					<input type="checkbox" name="scsl_quick_unlisted" value="1" class="scsl-quick-unlisted" />
					<span class="checkbox-title"><?php esc_html_e( 'Unlisted', 'seedcast-sermon-library' ); ?></span>
				</label>
				<p class="description" style="margin:.3em 0 0; clear:both;">
					<?php
					/*
					 * Said here too. A lone word and a checkbox tells somebody
					 * nothing about what it does, and unlisted is exactly the
					 * setting people assume means unpublished.
					 */
					esc_html_e( 'Keeps it out of the sermon lists. Stays published, keeps its page, keeps its links.', 'seedcast-sermon-library' );
					?>
				</p>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Keep the unlisted choice made in Quick Edit.
	 *
	 * @param int $post_id Sermon ID.
	 * @return void
	 */
	public function quick_edit_save_unlisted( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! isset( $_POST['scsl_quick_unlisted_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_quick_unlisted_nonce'] ) ), 'scsl_quick_edit_unlisted' ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;
		if ( ! isset( $_POST['scsl_quick_unlisted'] ) ) return;

		$unlisted = '1' === (string) wp_unslash( $_POST['scsl_quick_unlisted'] );

		if ( $unlisted ) {
			update_post_meta( $post_id, '_scsl_unlisted', '1' );
		} else {
			delete_post_meta( $post_id, '_scsl_unlisted' );
		}
	}

	public function quick_edit_save_series( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! isset( $_POST['scsl_quick_edit_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_quick_edit_nonce'] ) ), 'scsl_quick_edit_series' ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;
		if ( ! isset( $_POST['scsl_quick_series_id'] ) ) return;

		$series_id = absint( wp_unslash( $_POST['scsl_quick_series_id'] ) );
		if ( $series_id ) {
			update_post_meta( $post_id, '_scsl_series_id', $series_id );
		} else {
			delete_post_meta( $post_id, '_scsl_series_id' );
		}
	}

	public function enqueue_quick_edit_js( string $hook ): void {
		if ( $hook !== 'edit.php' ) return;
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'scsl_sermon' ) return;

		wp_add_inline_script( 'inline-edit-post', "
( function( $ ) {
	var \$wp_inline_edit = inlineEditPost.edit;
	inlineEditPost.edit = function( id ) {
		\$wp_inline_edit.apply( this, arguments );
		var post_id = ( typeof id === 'object' ) ? parseInt( this.getId( id ) ) : id;
		var \$row = $( '#post-' + post_id );
		var series_id = \$row.find( '.column-scsl_series a' ).data( 'series-id' ) || 0;
		$( '#edit-' + post_id + ' select[name=\"scsl_quick_series_id\"]' ).val( series_id );

		// One form is built and reused for every row, so the box has to be set
		// from the row being edited. Left alone it would keep whatever the last
		// sermon had and quietly unlist the wrong one.
		var unlisted = \$row.find( '.scsl-row-unlisted' ).data( 'unlisted' );
		$( '#edit-' + post_id + ' .scsl-quick-unlisted' ).prop( 'checked', unlisted === 1 );
	};
} )( jQuery );
		" );
	}

	// ── Series columns ────────────────────────────────────────────────────────

	public function series_columns( array $columns ): array {
		// Topics are tagged on sermons, not on the series, so the taxonomy
		// column WordPress adds here is always empty.
		unset( $columns['taxonomy-scsl_topic'] );

		$new = [ 'scsl_order' => '<span class="dashicons dashicons-move" title="Drag to reorder"></span>' ];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['scsl_episode_count'] = __( 'Sermons', 'seedcast-sermon-library' );
			}
		}
		return $new;
	}

	public function series_column_data( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'scsl_order':
				echo '<span class="scsl-order-handle dashicons dashicons-move" title="' . esc_attr__( 'Drag to reorder', 'seedcast-sermon-library' ) . '"></span>';
				break;
			case 'scsl_episode_count':
				$count      = $this->get_sermon_count( $post_id );
				$filter_url = add_query_arg( [
					'post_type'        => 'scsl_sermon',
					'scsl_series_filter' => $post_id,
				], admin_url( 'edit.php' ) );
				echo $count // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output escaped in both ternary branches
					? '<a href="' . esc_url( $filter_url ) . '" style="font-weight:700;">' . esc_html( $count ) . '</a>'
					: '<span style="color:#aaa">0</span>';
				break;

		}
	}

	// ── Topic save handler ────────────────────────────────────────────────────

	public function handle_save_topic(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'seedcast-sermon-library' ) );
		if ( ! isset( $_POST['scsl_topic_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_topic_nonce'] ) ), 'scsl_save_topic' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'seedcast-sermon-library' ) );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['topic_name']        ?? '' ) );
		$slug    = sanitize_title(      wp_unslash( $_POST['topic_slug']        ?? '' ) );
		$desc    = sanitize_textarea_field( wp_unslash( $_POST['topic_description'] ?? '' ) );
		$term_id = absint( $_POST['term_id'] ?? 0 );

		if ( ! $name ) {
			wp_safe_redirect( admin_url( 'admin.php?page=seedcast-sermon-library-topics&error=1' ) );
			exit;
		}

		$args = [ 'description' => $desc ];
		if ( $slug ) $args['slug'] = $slug;

		if ( $term_id ) {
			wp_update_term( $term_id, 'scsl_topic', array_merge( $args, [ 'name' => $name ] ) );
		} else {
			wp_insert_term( $name, 'scsl_topic', $args );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=seedcast-sermon-library-topics&saved=1' ) );
		exit;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function get_sermon_count( int $series_id ): int {
		$query = new \WP_Query( [
			'post_type'      => 'scsl_sermon',
			'meta_key'       => '_scsl_series_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $series_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		return $query->found_posts;
	}

	/**
	 * Add a column summarising what has been written.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_content_column( $columns ) {
		if ( ! is_array( $columns ) ) return $columns;

		$out = [];

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			// Straight after the title, where the eye already is.
			if ( 'title' === $key ) {
				$out['scsl_content'] = __( 'Content', 'seedcast-sermon-library' );
			}
		}

		if ( ! isset( $out['scsl_content'] ) ) {
			$out['scsl_content'] = __( 'Content', 'seedcast-sermon-library' );
		}

		return $out;
	}

	/**
	 * Which parts of a sermon are written, and whether it could be filled in.
	 *
	 * Useful on its own, and the thing to look at before asking for content to
	 * be generated in bulk: a sermon with neither a transcript nor a recording
	 * has nothing to write from and has to be sorted out by hand first.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Sermon ID.
	 * @return void
	 */
	public function render_content_column( $column, $post_id ): void {
		if ( 'scsl_content' !== $column ) return;

		$post_id = absint( $post_id );
		$present = [];
		$missing = [];

		// Only what a generation would actually produce. The article title is
		// derived from the article body rather than written, so calling it
		// missing would be asking for something nothing can supply.
		// Only sections this church says it uses. Calling something missing
		// that nobody wants would be nagging rather than reporting.
		// The More tab is assembled from fields nothing here asks for, so it
		// can never be filled by generating and listing it as missing would be
		// pointing at a gap with no way to close it.
		$sections = \SeedcastSermonLibrary\Import\FieldMap::in_use();

		unset( $sections['_scsl_resources'] );

		foreach ( $sections as $meta_key => $label ) {
			// A list, not a string. Trimming an array would say nothing useful
			// about whether there is anything in it.
			if ( '_scsl_other_passages' === $meta_key ) {
				$passages = get_post_meta( $post_id, $meta_key, true );

				if ( is_array( $passages ) && array_filter( $passages ) ) {
					$present[] = $label;
				} else {
					$missing[] = $label;
				}

				continue;
			}

			if ( '' !== trim( (string) get_post_meta( $post_id, $meta_key, true ) ) ) {
				$present[] = $label;
			} else {
				$missing[] = $label;
			}
		}

		if ( $present ) {
			echo '<span class="scsl-has">' . esc_html( implode( ', ', $present ) ) . '</span>';
		} else {
			echo '<span class="scsl-none">' . esc_html__( 'Nothing yet', 'seedcast-sermon-library' ) . '</span>';
		}

		if ( $missing ) {
			printf(
				'<br /><br /><span class="scsl-missing"><strong>%s</strong> %s</span>',
				esc_html__( 'Missing:', 'seedcast-sermon-library' ),
				esc_html( implode( ', ', $missing ) )
			);
		}

		// The transcript field may still hold the unprocessed text it was read
		// from, which counts as present but is not what anybody wants on the
		// website. Saying so is what stops the queue looking like it is
		// rewriting something for no reason.
		$raw = trim( (string) get_post_meta( $post_id, '_scpro_raw_transcript', true ) );

		if ( '' !== $raw && $raw === trim( (string) get_post_meta( $post_id, '_scsl_transcript_clean', true ) ) ) {
			printf(
				'<br /><span class="scsl-missing">%s</span>',
				esc_html__( 'Transcript is still the raw text it was read from', 'seedcast-sermon-library' )
			);
		}

	}
}
