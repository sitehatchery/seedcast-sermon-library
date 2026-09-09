<?php
namespace SeedcastSermonLibrary\Admin;

use SeedcastSermonLibrary\Scripture\Summary;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Where scripture summaries are read, written and approved.
 *
 * A screen of its own rather than the taxonomy's, because the two screens are
 * for different things and only one of them is safe.
 *
 * Scripture terms are not authored. They are a projection of what each sermon
 * says its passages are: saving a sermon creates the terms it needs and the
 * book and chapter above them. Editing that projection directly does not
 * change the sermons it came from, so a renamed passage stops matching the
 * sermons that made it, a deleted one comes back on the next save, and the
 * summary written against it does not, because term meta dies with the term.
 * Passages belong to the sermon, and that is where they are edited.
 *
 * What genuinely lives on the term is the summary, so that is what this screen
 * offers, and nothing else.
 *
 * @package SeedcastSermonLibrary\Admin
 */
class ScriptureSummaries {

	/** Page slug. */
	public const PAGE = 'scsl-scripture-summaries';

	/** How many rows a page of the list holds. */
	private const PER_PAGE = 30;

	/**
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 16 );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );

		/*
		 * The taxonomy's own screen is taken out of the menu rather than
		 * switched off. Off would take the term links on the sermon list with
		 * it, and an administrator who genuinely has to repair a term should
		 * still be able to reach the screen by its address. It is simply not
		 * offered, because using it is almost always a mistake.
		 */
		add_action( 'admin_menu', [ $this, 'hide_taxonomy_screen' ], 20 );
	}

	/**
	 * @return void
	 */
	public function add_menu(): void {
		$waiting = Summary::pending_count();

		/*
		 * The count rides on the menu title, which is the one place somebody
		 * looking at an unrelated screen will still see it. Work that waits
		 * quietly for review is work that does not happen.
		 */
		$label = __( 'Scripture Summaries', 'seedcast-sermon-library' );

		if ( $waiting ) {
			$label .= sprintf(
				' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
				(int) $waiting
			);
		}

		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Scripture Summaries', 'seedcast-sermon-library' ),
			$label,
			'manage_options',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/**
	 * Take the taxonomy's term screen out of the menu.
	 *
	 * @return void
	 */
	public function hide_taxonomy_screen(): void {
		remove_submenu_page( 'edit.php?post_type=scsl_sermon', 'edit-tags.php?taxonomy=scsl_scripture&amp;post_type=scsl_sermon' );
		remove_submenu_page( 'edit.php?post_type=scsl_sermon', 'edit-tags.php?taxonomy=scsl_scripture&post_type=scsl_sermon' );
	}

	/**
	 * Approve, discard or save, then go back where the work was happening.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$term_id = isset( $_REQUEST['term_id'] ) ? absint( $_REQUEST['term_id'] ) : 0;
		$action  = isset( $_REQUEST['scsl_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['scsl_action'] ) ) : '';

		if ( ! $term_id || ! $action ) {
			return;
		}

		check_admin_referer( 'scsl_summary_' . $action . '_' . $term_id );

		$done = '';

		switch ( $action ) {
			case 'approve':
				$done = Summary::approve( $term_id ) ? 'approved' : '';
				break;

			case 'discard':
				$done = Summary::discard( $term_id ) ? 'discarded' : '';
				break;

			case 'save':
				$prose = isset( $_POST['scsl_summary'] ) ? wp_unslash( $_POST['scsl_summary'] ) : '';

				/*
				 * With no generator connected the question is never put, so the
				 * answer is taken as no. That is what makes connecting one
				 * later safe: nothing somebody wrote is already carrying
				 * permission it was never asked for.
				 */
				$allow = Summary::generator_connected() && ! empty( $_POST['scsl_may_rewrite'] );

				Summary::write( $term_id, (string) $prose, $allow );
				$done = 'saved';
				break;
		}

		/*
		 * Back where the work was happening.
		 *
		 * Saving a summary and landing on an unfiltered list means losing both
		 * the thing just written and the set of rows being worked through. A
		 * save stays on the summary; anything done from the list goes back to
		 * the list, with its filters, its search and its page intact.
		 */
		$back = ( 'save' === $action || ! empty( $_REQUEST['stay'] ) )
			? [ 'edit' => $term_id ]
			: [];

		wp_safe_redirect( add_query_arg(
			array_filter( array_merge(
				[ 'page' => self::PAGE, 'done' => $done ],
				$this->context( $_REQUEST ),
				$back
			) ),
			admin_url( 'admin.php' )
		) );

		exit;
	}

	/**
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['edit'] ) ) {
			$this->render_editor( absint( $_GET['edit'] ) );

			return;
		}

		$this->render_list();
	}

	// ── The list ────────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	private function render_list(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$level  = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );

		$orderby = isset( $_GET['orderby'] ) && 'count' === $_GET['orderby'] ? 'count' : 'passage';
		$order   = isset( $_GET['order'] ) && 'desc' === $_GET['order'] ? 'desc' : 'asc';

		$rows   = $this->rows( $status, $level, $search, $orderby, $order );
		$counts = $this->counts();
		$total  = count( $rows );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $paged, $pages );
		$slice  = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scripture Summaries', 'seedcast-sermon-library' ); ?></h1>

			<p class="description" style="max-width:56em;">
				<?php esc_html_e( 'The short piece of writing at the top of a book or chapter page, describing what that group of sermons covers. Passages themselves are not edited here: they come from the sermons that name them, and are changed on the sermon.', 'seedcast-sermon-library' ); ?>
			</p>

			<p class="description" style="max-width:56em;">
				<?php
				switch ( Summary::generator_state() ) {
					case 'writing':
						esc_html_e( 'These are written for you. A passage with no summary gets one the first time its page is visited after the sermons on it change, and it goes live straight away because there is nothing there to replace. After that, a rewrite waits here for you to approve before it appears. Anything you write yourself is left alone unless you say otherwise.', 'seedcast-sermon-library' );
						break;

					case 'engine':
						esc_html_e( 'Sermon Library AI writes sermon content, but not these. Summaries are written by hand for now, and nothing will change or replace one.', 'seedcast-sermon-library' );
						break;

					default:
						esc_html_e( 'Summaries are written by hand on this site. With a writing service connected they would be written for you, with each rewrite waiting here for approval.', 'seedcast-sermon-library' );
				}
				?>
			</p>

			<?php $this->notice(); ?>

			<ul class="subsubsub">
				<?php
				$views = [
					'all'       => __( 'All', 'seedcast-sermon-library' ),
					'pending'   => __( 'Needs review', 'seedcast-sermon-library' ),
					'published' => __( 'Published', 'seedcast-sermon-library' ),
					'hand'      => __( 'Written by hand', 'seedcast-sermon-library' ),
					'open'      => __( 'Yours, rewriting allowed', 'seedcast-sermon-library' ),
					'none'      => __( 'No summary', 'seedcast-sermon-library' ),
				];

				$last = array_key_last( $views );

				foreach ( $views as $key => $label ) :
					// A different set of rows, ordered the same way, from the top.
					$url = $this->url( [
						'status' => 'all' === $key ? null : $key,
						'paged'  => null,
					] );
					?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>" <?php echo $status === $key ? 'class="current"' : ''; ?>>
							<?php echo esc_html( $label ); ?>
							<span class="count">(<?php echo (int) ( $counts[ $key ] ?? 0 ); ?>)</span>
						</a>
						<?php echo $key === $last ? '' : ' |'; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<br class="clear" />

			<?php
			/*
			 * The order WordPress uses on its own list screens: the search box
			 * above the toolbar, then the toolbar with its filters on the left
			 * and the page links on the right, then a clear.
			 *
			 * Both the search box and the page links are floated right, so a
			 * layout that puts them in the same row has them fighting for it
			 * and one drops below the other.
			 */
			?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
				<?php if ( 'passage' !== $orderby ) : ?>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>" />
				<?php endif; ?>
				<?php if ( 'asc' !== $order ) : ?>
					<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>" />
				<?php endif; ?>

				<p class="search-box">
					<label class="screen-reader-text" for="scsl-search"><?php esc_html_e( 'Search passages', 'seedcast-sermon-library' ); ?></label>
					<input type="search" id="scsl-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
					<?php submit_button( __( 'Search', 'seedcast-sermon-library' ), '', '', false ); ?>
				</p>

				<div class="tablenav top">
					<div class="alignleft actions">
						<label class="screen-reader-text" for="scsl-level"><?php esc_html_e( 'Filter by level', 'seedcast-sermon-library' ); ?></label>
						<?php // Wide enough for the longest option, which is otherwise clipped. ?>
						<select name="level" id="scsl-level" style="max-width:none;">
							<?php
							$levels = [
								'all'     => __( 'Books, chapters and passages', 'seedcast-sermon-library' ),
								'book'    => __( 'Books only', 'seedcast-sermon-library' ),
								'chapter' => __( 'Chapters only', 'seedcast-sermon-library' ),
								'passage' => __( 'Passages only', 'seedcast-sermon-library' ),
							];

							foreach ( $levels as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $level, $key ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Filter', 'seedcast-sermon-library' ), '', 'filter', false ); ?>
					</div>

					<?php $this->pagination( $total, $paged, $pages, $status, $level, $search ); ?>

					<br class="clear" />
				</div>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<?php
						$this->sortable_header( __( 'Passage', 'seedcast-sermon-library' ), 'passage', $orderby, $order, 'width:16em;' );
						$this->sortable_header( __( 'Sermons', 'seedcast-sermon-library' ), 'count', $orderby, $order, 'width:7em;' );
						?>
						<th scope="col" style="width:11em;"><?php esc_html_e( 'Status', 'seedcast-sermon-library' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Summary', 'seedcast-sermon-library' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $slice ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Nothing here.', 'seedcast-sermon-library' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $slice as $row ) : ?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( $this->url( [ 'edit' => $row['id'] ] ) ); ?>">
										<?php echo esc_html( $row['name'] ); ?>
									</a>
								</strong>
								<div class="row-actions">
									<span><a href="<?php echo esc_url( $this->url( [ 'edit' => $row['id'] ] ) ); ?>"><?php esc_html_e( 'Edit', 'seedcast-sermon-library' ); ?></a></span>
									<?php if ( 'pending' === $row['status'] ) : ?>
										| <span><a href="<?php echo esc_url( $this->action_url( 'approve', $row['id'] ) ); ?>"><?php esc_html_e( 'Approve', 'seedcast-sermon-library' ); ?></a></span>
										| <span class="delete"><a href="<?php echo esc_url( $this->action_url( 'discard', $row['id'] ) ); ?>"><?php esc_html_e( 'Discard', 'seedcast-sermon-library' ); ?></a></span>
									<?php endif; ?>
									| <span><a href="<?php echo esc_url( $row['link'] ); ?>" target="_blank"><?php esc_html_e( 'View page', 'seedcast-sermon-library' ); ?></a></span>
								</div>
							</td>
							<td><?php echo (int) $row['count']; ?></td>
							<td><?php echo $this->badge( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></td>
							<td>
								<?php if ( 'pending' === $row['status'] ) : ?>
									<div style="color:#646970;"><?php echo esc_html( $this->excerpt( $row['text'] ) ); ?></div>
									<div style="margin-top:.4rem;padding-left:.6rem;border-left:3px solid #dba617;">
										<strong><?php esc_html_e( 'Waiting:', 'seedcast-sermon-library' ); ?></strong>
										<?php echo esc_html( $this->excerpt( $row['pending'] ) ); ?>
									</div>
								<?php else : ?>
									<?php echo esc_html( $this->excerpt( $row['text'] ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── One summary ─────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	private function render_editor( int $term_id ): void {
		$term = get_term( $term_id, 'scsl_scripture' );

		if ( ! $term instanceof \WP_Term ) {
			echo '<div class="wrap"><p>' . esc_html__( 'That passage no longer exists.', 'seedcast-sermon-library' ) . '</p></div>';

			return;
		}

		$status  = Summary::status( $term_id );
		$pending = Summary::pending( $term_id );
		$text    = (string) get_term_meta( $term_id, '_scsl_summary', true );
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( $term->name ); ?>
				<?php echo $this->badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
			</h1>

			<p class="description">
				<?php
				printf(
					/* translators: %d: number of sermons. */
					esc_html( _n( '%d sermon on this passage.', '%d sermons on this passage.', (int) $term->count, 'seedcast-sermon-library' ) ),
					(int) $term->count
				);
				?>
				<a href="<?php echo esc_url( (string) get_term_link( $term ) ); ?>" target="_blank"><?php esc_html_e( 'View the page', 'seedcast-sermon-library' ); ?></a>
			</p>

			<?php $this->notice(); ?>

			<?php if ( '' !== $pending ) : ?>
				<div class="notice notice-warning" style="padding:1rem;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'A rewrite is waiting', 'seedcast-sermon-library' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'The sermons on this passage changed, so a new summary was written. The page is still showing the old one until you decide.', 'seedcast-sermon-library' ); ?>
					</p>
					<p style="background:#fff;padding:.75rem;border-left:3px solid #dba617;"><?php echo esc_html( $pending ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $this->action_url( 'approve', $term_id, true ) ); ?>"><?php esc_html_e( 'Publish this', 'seedcast-sermon-library' ); ?></a>
						<a class="button" href="<?php echo esc_url( $this->action_url( 'discard', $term_id, true ) ); ?>"><?php esc_html_e( 'Keep what is published', 'seedcast-sermon-library' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
				<?php wp_nonce_field( 'scsl_summary_save_' . $term_id ); ?>
				<input type="hidden" name="scsl_action" value="save" />
				<input type="hidden" name="term_id" value="<?php echo (int) $term_id; ?>" />
				<?php
				/*
				 * A POST carries nothing from the address it was sent from, so
				 * the rows being worked through travel with it. Without this,
				 * saving one summary out of a filtered list drops you back into
				 * all four hundred.
				 */
				foreach ( array_filter( $this->context( $_GET ) ) as $scsl_key => $scsl_value ) :
					?>
					<input type="hidden" name="<?php echo esc_attr( $scsl_key ); ?>" value="<?php echo esc_attr( (string) $scsl_value ); ?>" />
				<?php endforeach; ?>

				<h2><?php esc_html_e( 'Published summary', 'seedcast-sermon-library' ); ?></h2>
				<textarea name="scsl_summary" rows="8" class="large-text" style="max-width:56em;"><?php echo esc_textarea( $text ); ?></textarea>

				<p class="description" style="max-width:56em;">
					<?php esc_html_e( 'Plain text. Two short paragraphs at most, separated by a blank line. Emptying the box removes the summary and hands the passage back to be written automatically.', 'seedcast-sermon-library' ); ?>
				</p>

				<?php if ( Summary::generator_connected() ) : ?>
					<fieldset style="margin:1.25rem 0;max-width:56em;">
						<label>
							<input type="checkbox" name="scsl_may_rewrite" value="1"
								   <?php checked( 'open' === $status ); ?> />
							<strong><?php esc_html_e( 'Let this be rewritten later', 'seedcast-sermon-library' ); ?></strong>
						</label>
						<p class="description" style="margin:.4rem 0 0 1.8rem;">
							<?php esc_html_e( 'Off by default: once you write a summary yourself, nothing replaces it. Turn this on and a new one will be written when the sermons on this passage change. It still will not go live on its own, it arrives here for you to approve first.', 'seedcast-sermon-library' ); ?>
						</p>
					</fieldset>
				<?php else : ?>
					<?php
					/*
					 * The question is not put when nothing can act on the answer.
					 * Saving records it as no all the same, which is what stops
					 * a generator connected later from treating silence as
					 * consent.
					 *
					 * Which of the two reasons applies matters to whoever is
					 * reading. "Nothing is connected" on a site that plainly has
					 * the AI Engine installed reads as a fault.
					 */
					?>
					<p class="description" style="max-width:56em;margin-top:1rem;">
						<?php
						echo 'engine' === Summary::generator_state()
							? esc_html__( 'Sermon Library AI writes sermon content, but not the summaries at the top of passage pages. Nothing here will be replaced.', 'seedcast-sermon-library' )
							: esc_html__( 'Nothing is connected to write summaries on this site, so yours will not be replaced.', 'seedcast-sermon-library' );
						?>
					</p>
				<?php endif; ?>

				<p>
					<?php submit_button( __( 'Save summary', 'seedcast-sermon-library' ), 'primary', 'submit', false ); ?>
					<a class="button-link" href="<?php echo esc_url( $this->url() ); ?>" style="margin-left:.5rem;"><?php esc_html_e( 'Back to the list', 'seedcast-sermon-library' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	// ── Data ────────────────────────────────────────────────────────────────

	/**
	 * Every term worth showing, with its summary state.
	 *
	 * Terms with fewer sermons than a summary is written for are left out
	 * entirely. On a library of any size those are most of them, and a list
	 * that is nine parts things nobody can act on is not a list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $status, string $level, string $search, string $orderby = 'passage', string $order = 'asc' ): array {
		$terms = get_terms( [
			'taxonomy'   => 'scsl_scripture',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		] );

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$out = [];

		foreach ( $terms as $term ) {
			if ( (int) $term->count < Summary::MIN_SERMONS ) {
				continue;
			}

			$row_level = $this->level_of( $term );

			if ( 'all' !== $level && $row_level !== $level ) {
				continue;
			}

			$row_status = Summary::status( (int) $term->term_id );

			if ( 'all' !== $status && $row_status !== $status ) {
				continue;
			}

			if ( '' !== $search && false === stripos( $term->name, $search ) ) {
				continue;
			}

			$link = get_term_link( $term );

			$out[] = [
				'id'      => (int) $term->term_id,
				'name'    => $term->name,
				'sort'    => $this->sort_key( $term->name ),
				'count'   => (int) $term->count,
				'status'  => $row_status,
				'level'   => $row_level,
				'text'    => (string) get_term_meta( $term->term_id, '_scsl_summary', true ),
				'pending' => Summary::pending( (int) $term->term_id ),
				'link'    => is_wp_error( $link ) ? '' : $link,
			];
		}

		$flip = ( 'desc' === $order ) ? -1 : 1;

		usort( $out, static function ( $a, $b ) use ( $orderby, $flip ) {
			if ( 'count' === $orderby ) {
				$by = $a['count'] <=> $b['count'];

				// Equal counts fall back to where the passage sits, so a column
				// of identical numbers is still a readable list rather than
				// whatever order the database happened to return.
				return $by ? $by * $flip : ( $a['sort'] <=> $b['sort'] );
			}

			return ( $a['sort'] <=> $b['sort'] ) * $flip;
		} );

		return $out;
	}

	/**
	 * Where a passage sits, for ordering: book, then chapter, then verse.
	 *
	 * Books in the order they come in the Bible rather than alphabetically,
	 * which is the order anybody scanning this list is reading in. Alphabetical
	 * puts Amos above Genesis and Titus above Matthew, which is not an order
	 * anybody wants a Bible in.
	 *
	 * Chapter and verse are compared as numbers, so chapter 9 comes before
	 * chapter 10 rather than after it.
	 *
	 * @return array{0:int, 1:int, 2:int}
	 */
	private function sort_key( string $name ): array {
		static $order = null;

		if ( null === $order ) {
			$order = array_flip( array_values( \SeedcastSermonLibrary\Scripture\ScriptureParser::all_books() ) );
		}

		$book = (string) \SeedcastSermonLibrary\Scripture\ScriptureParser::extract_book( $name );

		// An unrecognised book sorts after every real one rather than before,
		// so oddities collect at the end instead of heading the list.
		$rank = isset( $order[ $book ] ) ? (int) $order[ $book ] : 999;
		$rest = trim( substr( $name, strlen( $book ) ) );

		$chapter = 0;
		$verse   = 0;

		if ( preg_match( '/^(\d+)(?::(\d+))?/', $rest, $m ) ) {
			$chapter = (int) $m[1];
			$verse   = isset( $m[2] ) ? (int) $m[2] : 0;
		}

		return [ $rank, $chapter, $verse ];
	}

	/**
	 * How many terms are in each state, for the filter links.
	 *
	 * @return array<string, int>
	 */
	private function counts(): array {
		$all = $this->rows( 'all', 'all', '' );

		$counts = [ 'all' => count( $all ), 'pending' => 0, 'published' => 0, 'hand' => 0, 'open' => 0, 'none' => 0 ];

		foreach ( $all as $row ) {
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Whether a term is a book, a chapter or a single passage.
	 */
	private function level_of( \WP_Term $term ): string {
		if ( 0 === (int) $term->parent ) {
			return 'book';
		}

		$parent = get_term( (int) $term->parent, 'scsl_scripture' );

		return ( $parent instanceof \WP_Term && 0 === (int) $parent->parent ) ? 'chapter' : 'passage';
	}

	// ── Small pieces ────────────────────────────────────────────────────────

	/**
	 * A column header that can be clicked to order by it.
	 *
	 * Each column starts in the direction that makes sense for what is in it:
	 * passages from Genesis forwards, sermon counts from the busiest down,
	 * because nobody's first question about a count column is which passage has
	 * fewest. Clicking the column already in use reverses it.
	 *
	 * @return void
	 */
	private function sortable_header( string $label, string $key, string $orderby, string $order, string $style = '' ): void {
		$active = ( $orderby === $key );
		$first  = ( 'count' === $key ) ? 'desc' : 'asc';
		$next   = $active ? ( 'asc' === $order ? 'desc' : 'asc' ) : $first;

		$class = $active ? 'manage-column sorted ' . $order : 'manage-column sortable ' . ( 'asc' === $first ? 'desc' : 'asc' );

		$url = $this->url( [
			'orderby' => 'passage' === $key ? null : $key,
			'order'   => 'asc' === $next ? null : $next,
			'paged'   => null,
		] );

		printf(
			'<th scope="col" class="%s" style="%s"><a href="%s"><span>%s</span><span class="sorting-indicator"></span></a></th>',
			esc_attr( $class ),
			esc_attr( $style ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	private function badge( string $status ): string {
		$badges = [
			'pending'   => [ __( 'Needs review', 'seedcast-sermon-library' ), '#dba617' ],
			'published' => [ __( 'Published', 'seedcast-sermon-library' ), '#00a32a' ],
			'hand'      => [ __( 'Written by hand', 'seedcast-sermon-library' ), '#2271b1' ],
			'open'      => [ __( 'Yours, rewriting allowed', 'seedcast-sermon-library' ), '#996800' ],
			'none'      => [ __( 'No summary', 'seedcast-sermon-library' ), '#8c8f94' ],
		];

		if ( ! isset( $badges[ $status ] ) ) {
			return '';
		}

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:9px;font-size:12px;color:#fff;background:%s;">%s</span>',
			esc_attr( $badges[ $status ][1] ),
			esc_html( $badges[ $status ][0] )
		);
	}

	private function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > 190 ? mb_substr( $text, 0, 190 ) . '…' : $text;
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private function url( array $args = [] ): string {
		return add_query_arg(
			array_filter( array_merge( [ 'page' => self::PAGE ], $this->context( $_GET ), $args ) ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param bool $stay Come back to this summary rather than to the list.
	 */
	private function action_url( string $action, int $term_id, bool $stay = false ): string {
		$args = [ 'scsl_action' => $action, 'term_id' => $term_id ];

		if ( $stay ) {
			$args['stay'] = 1;
		}

		return wp_nonce_url(
			$this->url( $args ),
			'scsl_summary_' . $action . '_' . $term_id
		);
	}

	/**
	 * Which rows were being looked at: the filters, the search and the page.
	 *
	 * Carried on every link and posted with the form, so working through a
	 * filtered list is not undone by acting on one row in it.
	 *
	 * @param array<string, mixed> $source $_GET or $_REQUEST.
	 * @return array<string, mixed>
	 */
	private function context( array $source ): array {
		$status = isset( $source['status'] ) ? sanitize_key( (string) $source['status'] ) : '';
		$level  = isset( $source['level'] ) ? sanitize_key( (string) $source['level'] ) : '';
		$paged  = isset( $source['paged'] ) ? absint( $source['paged'] ) : 0;
		$search = isset( $source['s'] ) ? sanitize_text_field( wp_unslash( (string) $source['s'] ) ) : '';

		$orderby = isset( $source['orderby'] ) ? sanitize_key( (string) $source['orderby'] ) : '';
		$order   = isset( $source['order'] ) ? sanitize_key( (string) $source['order'] ) : '';

		return [
			'status'  => ( '' === $status || 'all' === $status ) ? null : $status,
			'level'   => ( '' === $level || 'all' === $level ) ? null : $level,
			'paged'   => $paged > 1 ? $paged : null,
			's'       => '' === $search ? null : $search,
			// Only the non-default is worth carrying, so the plain address
			// stays plain.
			'orderby' => 'count' === $orderby ? 'count' : null,
			'order'   => 'desc' === $order ? 'desc' : null,
		];
	}

	/**
	 * @return void
	 */
	private function notice(): void {
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';

		$messages = [
			'approved'  => __( 'Published. That page now shows the new summary.', 'seedcast-sermon-library' ),
			'discarded' => __( 'Thrown away. The page goes on showing what it was showing.', 'seedcast-sermon-library' ),
			'saved'     => __( 'Saved.', 'seedcast-sermon-library' ),
		];

		if ( ! isset( $messages[ $done ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $messages[ $done ] )
		);
	}

	/**
	 * @return void
	 */
	private function pagination( int $total, int $paged, int $pages, string $status, string $level, string $search ): void {
		if ( $pages < 2 ) {
			printf(
				'<div class="tablenav-pages one-page"><span class="displaying-num">%s</span></div>',
				esc_html( sprintf(
					/* translators: %d: number of passages. */
					_n( '%d passage', '%d passages', $total, 'seedcast-sermon-library' ),
					$total
				) )
			);

			return;
		}

		$base = $this->url( [ 'paged' => null ] );

		echo '<div class="tablenav-pages">';

		printf(
			'<span class="displaying-num">%s</span>',
			esc_html( sprintf(
				/* translators: %d: number of passages. */
				_n( '%d passage', '%d passages', $total, 'seedcast-sermon-library' ),
				$total
			) )
		);

		echo wp_kses_post( paginate_links( [
			'base'      => add_query_arg( 'paged', '%#%', $base ),
			'format'    => '',
			'current'   => $paged,
			'total'     => $pages,
			'prev_text' => '‹',
			'next_text' => '›',
		] ) );

		echo '</div>';
	}
}
