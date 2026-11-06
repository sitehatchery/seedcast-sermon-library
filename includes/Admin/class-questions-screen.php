<?php
namespace SeedcastSermonLibrary\Admin;

use SeedcastSermonLibrary\Ask\Box;
use SeedcastSermonLibrary\Ask\Guard;
use SeedcastSermonLibrary\Ask\Page;
use SeedcastSermonLibrary\Ask\Questions;
use SeedcastSermonLibrary\Ask\TopicFaq;
use SeedcastSermonLibrary\Frontend\Faq;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a church reads what the public asked.
 *
 * Short enough that somebody actually reads it. Ordered by what deserves
 * attention rather than by time: questions more than one person asked first,
 * then the ones the library had nothing for, with near-duplicates grouped so
 * forty phrasings of one question are one row. Everything else is a click
 * away and out of the way.
 *
 * Nothing on the site is public until somebody here puts it there, so this is
 * a list of opportunities rather than a safety queue. Leaving it for a month
 * costs only content nobody wrote.
 *
 * Each row: promote it onto a sermon or file it under topics, ignore it for
 * good, or block whoever asked.
 */
class QuestionsScreen {

	public const PAGE = 'seedcast-sermon-library-questions';

	/** Who may act on questions. Editors run the content. */
	private const CAP = 'edit_others_posts';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 18 );

		foreach ( [ 'ignore', 'unignore', 'block', 'unblock', 'promote', 'settings', 'clear' ] as $action ) {
			add_action( 'admin_post_scsl_q_' . $action, [ $this, 'do_' . $action ] );
		}
	}

	public function menu(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Questions', 'seedcast-sermon-library' ),
			__( 'Questions', 'seedcast-sermon-library' ),
			self::CAP,
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

		echo '<div class="wrap scsl-questions">';
		echo '<h1>' . esc_html__( 'Questions', 'seedcast-sermon-library' ) . '</h1>';

		$this->notice();

		if ( 'promote' === $view ) {
			$group = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '';
			$find  = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : '';
			$this->render_promote( $group, $find );
		} else {
			$this->render_list();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '</div>';
	}

	private function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a message key, nothing more.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';

		$messages = [
			'ignored'   => __( 'Ignored. Later repeats of that question will be ignored too.', 'seedcast-sermon-library' ),
			'unignored' => __( 'That question is back on the list.', 'seedcast-sermon-library' ),
			'blocked'   => __( 'Blocked. That visitor cannot ask again until you lift it.', 'seedcast-sermon-library' ),
			'unblocked' => __( 'Block lifted.', 'seedcast-sermon-library' ),
			'promoted'  => __( 'Added. It is on the page now, and off this list.', 'seedcast-sermon-library' ),
			'saved'     => __( 'Settings saved.', 'seedcast-sermon-library' ),
			'cleared'   => __( 'Saved answers cleared. Each question is answered afresh the next time it is asked.', 'seedcast-sermon-library' ),
			'nowhere'   => __( 'Choose a sermon or at least one topic to add it to.', 'seedcast-sermon-library' ),
		];

		if ( isset( $messages[ $done ] ) ) {
			$class = 'nowhere' === $done ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $done ] ) . '</p></div>';
		}
	}

	private function render_list(): void {
		$this->render_usage();

		$groups = Questions::groups( Questions::STATUS_NEW );

		$worth = array_values( array_filter(
			$groups,
			static function ( array $group ): bool {
				return $group['visitors'] > 1 || $group['nothing'];
			}
		) );
		$rest  = array_values( array_filter(
			$groups,
			static function ( array $group ): bool {
				return ! ( $group['visitors'] > 1 || $group['nothing'] );
			}
		) );

		echo '<h2>' . esc_html__( 'Worth a look', 'seedcast-sermon-library' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Questions more than one person asked, then questions the library had nothing for. Each is a chance to add something people are looking for.', 'seedcast-sermon-library' ) . '</p>';

		if ( $worth ) {
			foreach ( $worth as $group ) {
				$this->render_group( $group );
			}
		} else {
			echo '<p>' . esc_html__( 'Nothing needs attention right now.', 'seedcast-sermon-library' ) . '</p>';
		}

		if ( $rest ) {
			echo '<details class="scsl-questions__rest" style="margin-top:2em;"><summary><strong>';
			/* translators: %d: number of questions. */
			echo esc_html( sprintf( _n( 'Everything else (%d)', 'Everything else (%d)', count( $rest ), 'seedcast-sermon-library' ), count( $rest ) ) );
			echo '</strong></summary>';

			foreach ( $rest as $group ) {
				$this->render_group( $group );
			}

			echo '</details>';
		}

		$this->render_handled();
		$this->render_settings();
	}

	private function render_usage(): void {
		$usage = (array) apply_filters( 'seedcast/ask/usage', [], true );

		echo '<div class="card" style="max-width:none;">';

		if ( ! Page::available() ) {
			echo '<p><strong>' . esc_html__( 'The assistant is not switched on for this site.', 'seedcast-sermon-library' ) . '</strong> ';
			echo esc_html__( 'It needs the Seedcast AI Engine with an assistant subscription. Until then the search box works exactly as it always has.', 'seedcast-sermon-library' ) . '</p>';
		} else {
			$url = Page::url();
			echo '<p>';
			/* translators: %s: link to the ask page. */
			echo wp_kses_post( sprintf( __( 'Visitors ask at %s, or from the search box.', 'seedcast-sermon-library' ), '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>' ) );
			echo '</p>';
		}

		if ( isset( $usage['monthly_cap'], $usage['used_this_month'] ) ) {
			$resets = ! empty( $usage['resets_at'] ) ? strtotime( (string) $usage['resets_at'] ) : 0;
			echo '<p>';
			echo esc_html( sprintf(
				/* translators: 1: answers written this month, 2: monthly allowance, 3: date it resets. */
				__( 'This month: %1$s of %2$s answers written. Resets %3$s.', 'seedcast-sermon-library' ),
				number_format_i18n( (int) $usage['used_this_month'] ),
				number_format_i18n( (int) $usage['monthly_cap'] ),
				$resets ? wp_date( get_option( 'date_format' ), $resets ) : __( 'next month', 'seedcast-sermon-library' )
			) );
			echo ' ' . esc_html__( 'A question answered before, about content that has not changed, is answered again for free.', 'seedcast-sermon-library' );
			echo '</p>';
		}

		echo '<p>';
		echo esc_html( sprintf(
			/* translators: 1: questions today, 2: daily limit. */
			__( 'Today: %1$s of this site\'s limit of %2$s questions.', 'seedcast-sermon-library' ),
			number_format_i18n( Guard::asked_today() ),
			number_format_i18n( Guard::daily_limit() )
		) );
		echo '</p></div>';
	}

	private function render_group( array $group ): void {
		$latest = $group['latest'];

		echo '<div class="card" style="max-width:none;margin-top:1em;">';
		echo '<h3 style="margin-top:0;">' . esc_html( $latest['question'] ) . '</h3>';

		$facts = [];

		/* translators: 1: times asked, 2: number of people. */
		$facts[] = sprintf( _n( 'Asked %1$d time', 'Asked %1$d times', $group['count'], 'seedcast-sermon-library' ), $group['count'] )
			. ( $group['visitors'] > 1 ? ' ' . sprintf( _n( 'by %d person', 'by %d people', $group['visitors'], 'seedcast-sermon-library' ), $group['visitors'] ) : '' );

		if ( $group['nothing'] ) {
			$facts[] = '<strong>' . esc_html__( 'The library had nothing for it', 'seedcast-sermon-library' ) . '</strong>';
		}

		if ( '' !== $latest['from_url'] ) {
			$title   = $this->from_title( $latest );
			/* translators: %s: link to the page the question was asked from. */
			$facts[] = sprintf( __( 'asked from %s', 'seedcast-sermon-library' ), '<a href="' . esc_url( $latest['from_url'] ) . '">' . esc_html( $title ) . '</a>' );
		}

		/* translators: %s: date. */
		$facts[] = sprintf( __( 'last on %s', 'seedcast-sermon-library' ), esc_html( mysql2date( get_option( 'date_format' ), $latest['asked_at'] ) ) );

		echo '<p>' . wp_kses_post( implode( ' &middot; ', array_map( 'wp_kses_post', $facts ) ) ) . '</p>';

		if ( 'answered' === $latest['outcome'] && '' !== $latest['answer'] ) {
			echo '<blockquote style="margin:0 0 1em;padding-left:1em;border-left:3px solid #ccd0d4;">' . wp_kses_post( wpautop( esc_html( $latest['answer'] ) ) ) . '</blockquote>';
		}

		if ( $latest['cited'] ) {
			$links = array_map(
				static function ( array $one ): string {
					return '<a href="' . esc_url( (string) $one['url'] ) . '">' . esc_html( (string) $one['title'] ) . '</a>';
				},
				$latest['cited']
			);
			echo '<p>' . esc_html( 'answered' === $latest['outcome'] ? __( 'Drew on:', 'seedcast-sermon-library' ) : __( 'Nearest:', 'seedcast-sermon-library' ) ) . ' ' . wp_kses_post( implode( ', ', $links ) ) . '</p>';
		}

		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url( self::url( [ 'view' => 'promote', 'group' => $group['key'] ] ) ) . '">' . esc_html__( 'Add to a page', 'seedcast-sermon-library' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $this->action_url( 'ignore', [ 'group' => $group['key'] ] ) ) . '">' . esc_html__( 'Ignore', 'seedcast-sermon-library' ) . '</a>';
		echo '</p>';

		echo '<details><summary>' . esc_html__( 'Every time it was asked', 'seedcast-sermon-library' ) . '</summary><ul>';

		foreach ( $group['rows'] as $row ) {
			echo '<li>' . esc_html( $row['question'] ) . ' <span class="description">' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['asked_at'] ) ) . '</span> ';
			echo '<a href="' . esc_url( $this->action_url( 'block', [ 'id' => $row['id'] ] ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Block whoever asked this from asking again?', 'seedcast-sermon-library' ) ) . '\');">' . esc_html__( 'Block this visitor', 'seedcast-sermon-library' ) . '</a></li>';
		}

		echo '</ul></details></div>';
	}

	private function from_title( array $row ): string {
		if ( $row['from_id'] && in_array( $row['from_type'], [ 'topic', 'passage' ], true ) ) {
			$term = get_term( $row['from_id'] );

			if ( $term instanceof \WP_Term ) {
				return html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}

		if ( $row['from_id'] && 'home' !== $row['from_type'] ) {
			$title = get_the_title( $row['from_id'] );

			if ( '' !== $title ) {
				return $title;
			}
		}

		return 'home' === $row['from_type'] ? __( 'the home page', 'seedcast-sermon-library' ) : $row['from_url'];
	}

	private function render_handled(): void {
		$ignored = Questions::groups( Questions::STATUS_IGNORED, 200 );
		$blocks  = Questions::blocks();

		if ( ! $ignored && ! $blocks ) {
			return;
		}

		echo '<h2 style="margin-top:2em;">' . esc_html__( 'Ignored and blocked', 'seedcast-sermon-library' ) . '</h2>';

		if ( $ignored ) {
			echo '<h3>' . esc_html__( 'Ignored', 'seedcast-sermon-library' ) . '</h3><ul>';

			foreach ( $ignored as $group ) {
				echo '<li>' . esc_html( $group['latest']['question'] ) . ' <a href="' . esc_url( $this->action_url( 'unignore', [ 'group' => $group['key'] ] ) ) . '">' . esc_html__( 'Undo', 'seedcast-sermon-library' ) . '</a></li>';
			}

			echo '</ul>';
		}

		if ( $blocks ) {
			echo '<h3>' . esc_html__( 'Blocked visitors', 'seedcast-sermon-library' ) . '</h3><ul>';

			foreach ( $blocks as $id => $block ) {
				echo '<li>';
				/* translators: 1: a question the visitor asked, 2: date blocked. */
				echo esc_html( sprintf( __( 'Asked "%1$s", blocked %2$s', 'seedcast-sermon-library' ), (string) ( $block['question'] ?? '' ), mysql2date( get_option( 'date_format' ), (string) ( $block['at'] ?? '' ) ) ) );
				echo ' <a href="' . esc_url( $this->action_url( 'unblock', [ 'block' => (string) $id ] ) ) . '">' . esc_html__( 'Lift', 'seedcast-sermon-library' ) . '</a></li>';
			}

			echo '</ul>';
		}
	}

	private function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mode = Box::mode();
		?>
		<h2 style="margin-top:2em;"><?php esc_html_e( 'Settings', 'seedcast-sermon-library' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scsl_q_settings" />
			<?php wp_nonce_field( 'scsl_q_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Ask box', 'seedcast-sermon-library' ); ?></th>
					<td>
						<label><input type="radio" name="scsl_ask_box" value="<?php echo esc_attr( Box::MODE_SEARCH ); ?>" <?php checked( Box::MODE_SEARCH, $mode ); ?> /> <?php esc_html_e( 'Use the site\'s search box. Ordinary search results still appear under every answer.', 'seedcast-sermon-library' ); ?></label><br />
						<label><input type="radio" name="scsl_ask_box" value="<?php echo esc_attr( Box::MODE_MANUAL ); ?>" <?php checked( Box::MODE_MANUAL, $mode ); ?> /> <?php esc_html_e( 'Leave search alone. I will place the box with [seedcast_ask].', 'seedcast-sermon-library' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_ask_daily_limit"><?php esc_html_e( 'Questions per day', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="number" min="1" max="10000" id="scsl_ask_daily_limit" name="scsl_ask_daily_limit" value="<?php echo esc_attr( (string) Guard::daily_limit() ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'The most questions this site will send in a day, whatever happens. Each visitor is also limited to a few a minute.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'seedcast-sermon-library' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scsl_q_clear" />
			<?php wp_nonce_field( 'scsl_q_clear' ); ?>
			<p>
				<?php submit_button( __( 'Clear saved answers', 'seedcast-sermon-library' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Answers are reused until the content they came from changes. Editing a sermon refreshes the answers that used it; this refreshes all of them.', 'seedcast-sermon-library' ); ?></span>
			</p>
		</form>
		<?php
	}

	/**
	 * Turn a question into a real FAQ on a real page.
	 */
	private function render_promote( string $group, string $find ): void {
		$rows = array_values( array_filter(
			Questions::groups( Questions::STATUS_NEW ),
			static function ( array $one ) use ( $group ): bool {
				return $one['key'] === $group;
			}
		) );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'That question is no longer on the list.', 'seedcast-sermon-library' ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to questions', 'seedcast-sermon-library' ) . '</a></p>';
			return;
		}

		$latest = $rows[0]['latest'];

		// The best answer the group got, for a starting point.
		$answer = '';

		foreach ( $rows[0]['rows'] as $row ) {
			if ( 'answered' === $row['outcome'] && '' !== $row['answer'] ) {
				$answer = $row['answer'];
				break;
			}
		}

		// Sermons that can hold it: where it was asked, what the answer drew on,
		// and whatever a search finds. Only sermons, because only a sermon page
		// renders an FAQ, and offering anything else would swallow it silently.
		$sermons = [];

		if ( 'sermon' === $latest['from_type'] && $latest['from_id'] ) {
			$sermons[ $latest['from_id'] ] = __( 'where it was asked', 'seedcast-sermon-library' );
		}

		$topics_checked = [];

		foreach ( $latest['cited'] as $one ) {
			if ( preg_match( '/^sermon:(\d+)$/', (string) $one['ref'], $m ) && ! isset( $sermons[ (int) $m[1] ] ) ) {
				$sermons[ (int) $m[1] ] = __( 'the answer drew on it', 'seedcast-sermon-library' );
			}

			if ( preg_match( '/^topic:(\d+)$/', (string) $one['ref'], $m ) ) {
				$topics_checked[] = (int) $m[1];
			}
		}

		if ( '' !== $find ) {
			$search = get_posts( [
				'post_type'      => 'scsl_sermon',
				'post_status'    => 'publish',
				's'              => $find,
				'posts_per_page' => 10,
				'fields'         => 'ids',
			] );

			foreach ( $search as $id ) {
				if ( ! isset( $sermons[ (int) $id ] ) ) {
					$sermons[ (int) $id ] = __( 'search result', 'seedcast-sermon-library' );
				}
			}
		}

		$first = (int) array_key_first( $sermons );
		$terms = get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => false ] );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Back to questions', 'seedcast-sermon-library' ); ?></a></p>
		<h2><?php esc_html_e( 'Add a question to a page', 'seedcast-sermon-library' ); ?></h2>
		<p class="description"><?php esc_html_e( 'It becomes a real question and answer on the page you choose, under your name. Edit both until they read the way you would say them.', 'seedcast-sermon-library' ); ?></p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:1em 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="view" value="promote" />
			<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
			<label for="scsl-find"><?php esc_html_e( 'Find a sermon', 'seedcast-sermon-library' ); ?></label>
			<input type="search" id="scsl-find" name="find" value="<?php echo esc_attr( $find ); ?>" />
			<?php submit_button( __( 'Search', 'seedcast-sermon-library' ), 'secondary', '', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scsl_q_promote" />
			<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>" />
			<?php wp_nonce_field( 'scsl_q_promote_' . $group ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="scsl-q"><?php esc_html_e( 'Question', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="text" id="scsl-q" name="question" class="large-text" value="<?php echo esc_attr( $latest['question'] ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl-a"><?php esc_html_e( 'Answer', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<textarea id="scsl-a" name="answer" class="large-text" rows="6" required><?php echo esc_textarea( $answer ); ?></textarea>
						<?php if ( '' === $answer ) : ?>
							<p class="description"><?php esc_html_e( 'The library had no answer for this, so write one.', 'seedcast-sermon-library' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On a sermon', 'seedcast-sermon-library' ); ?></th>
					<td>
						<label><input type="radio" name="sermon" value="0" <?php checked( 0, $first ); ?> /> <?php esc_html_e( 'Not on a sermon', 'seedcast-sermon-library' ); ?></label><br />
						<?php foreach ( $sermons as $id => $why ) : ?>
							<label><input type="radio" name="sermon" value="<?php echo esc_attr( (string) $id ); ?>" <?php checked( $first, (int) $id ); ?> /> <?php echo esc_html( get_the_title( (int) $id ) ); ?> <span class="description">(<?php echo esc_html( $why ); ?>)</span></label><br />
						<?php endforeach; ?>
						<?php if ( ! $sermons ) : ?>
							<p class="description"><?php esc_html_e( 'Search above to find the sermon it belongs on.', 'seedcast-sermon-library' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Under topics', 'seedcast-sermon-library' ); ?></th>
					<td>
						<?php if ( is_array( $terms ) && $terms ) : ?>
							<?php foreach ( $terms as $term ) : ?>
								<label style="display:inline-block;min-width:14em;"><input type="checkbox" name="topics[]" value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $topics_checked, true ) ); ?> /> <?php echo esc_html( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Shown on each topic\'s page under "Questions people have asked". Only your existing topics can be chosen.', 'seedcast-sermon-library' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'This site has no topics yet.', 'seedcast-sermon-library' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Add it', 'seedcast-sermon-library' ) ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	private function action_url( string $action, array $args ): string {
		return wp_nonce_url(
			add_query_arg( array_merge( [ 'action' => 'scsl_q_' . $action ], $args ), admin_url( 'admin-post.php' ) ),
			'scsl_q_' . $action
		);
	}

	private function guard( string $action, string $cap = self::CAP ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'seedcast-sermon-library' ) );
		}

		check_admin_referer( $action );
	}

	private function back( string $done ): void {
		wp_safe_redirect( self::url( [ 'done' => $done ] ) );
		exit;
	}

	public function do_ignore(): void {
		$this->guard( 'scsl_q_ignore' );
		Questions::ignore_group( isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '' );
		$this->back( 'ignored' );
	}

	public function do_unignore(): void {
		$this->guard( 'scsl_q_unignore' );
		Questions::unignore_group( isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '' );
		$this->back( 'unignored' );
	}

	public function do_block(): void {
		$this->guard( 'scsl_q_block' );
		Questions::block( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		$this->back( 'blocked' );
	}

	public function do_unblock(): void {
		$this->guard( 'scsl_q_unblock' );
		Questions::unblock( isset( $_GET['block'] ) ? sanitize_text_field( wp_unslash( $_GET['block'] ) ) : '' );
		$this->back( 'unblocked' );
	}

	public function do_settings(): void {
		$this->guard( 'scsl_q_settings', 'manage_options' );

		$mode = isset( $_POST['scsl_ask_box'] ) ? sanitize_key( wp_unslash( $_POST['scsl_ask_box'] ) ) : Box::MODE_SEARCH;
		update_option( Box::MODE_OPTION, Box::MODE_MANUAL === $mode ? Box::MODE_MANUAL : Box::MODE_SEARCH );

		$limit = isset( $_POST['scsl_ask_daily_limit'] ) ? absint( $_POST['scsl_ask_daily_limit'] ) : Guard::DAILY_DEFAULT;
		update_option( Guard::DAILY_OPTION, max( 1, min( 10000, $limit ) ) );

		$this->back( 'saved' );
	}

	public function do_clear(): void {
		$this->guard( 'scsl_q_clear', 'manage_options' );
		do_action( 'seedcast/ask/clear' );
		$this->back( 'cleared' );
	}

	/**
	 * Put the pair where the church chose, and take the question off the list.
	 *
	 * Onto a sermon it joins that sermon's FAQ through the same meta a person
	 * typing one in would write, so the completeness score sees it exactly as
	 * it sees a hand-written one. Under a topic it changes no sermon's score,
	 * because topics stay out of it.
	 */
	public function do_promote(): void {
		$group = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
		$this->guard( 'scsl_q_promote_' . $group );

		$question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
		$answer   = isset( $_POST['answer'] ) ? wp_kses_post( wp_unslash( $_POST['answer'] ) ) : '';
		$sermon   = isset( $_POST['sermon'] ) ? absint( $_POST['sermon'] ) : 0;
		$topics   = isset( $_POST['topics'] ) && is_array( $_POST['topics'] ) ? array_map( 'absint', wp_unslash( $_POST['topics'] ) ) : [];

		if ( '' === $question || '' === trim( wp_strip_all_tags( $answer ) ) ) {
			wp_safe_redirect( self::url( [ 'view' => 'promote', 'group' => $group ] ) );
			exit;
		}

		$where = [];

		if ( $sermon && 'scsl_sermon' === get_post_type( $sermon ) && current_user_can( 'edit_post', $sermon ) ) {
			$pairs = array_values( array_filter(
				Faq::stored( $sermon ),
				static function ( array $pair ) use ( $question ): bool {
					return 0 !== strcasecmp( $pair['question'], $question );
				}
			) );

			$pairs[] = [ 'question' => $question, 'answer' => $answer ];
			update_post_meta( $sermon, Faq::META, $pairs );

			$where[] = 'sermon:' . $sermon;
		}

		// Existing topics only. A topic is the church's vocabulary, and
		// nothing here gets to add a word to it.
		$filed = [];

		foreach ( array_unique( array_filter( $topics ) ) as $term_id ) {
			$term = get_term( $term_id, 'scsl_topic' );

			if ( $term instanceof \WP_Term ) {
				TopicFaq::add( (int) $term->term_id, $question, $answer );
				$filed[] = (int) $term->term_id;
			}
		}

		if ( $filed ) {
			$where[] = 'topic:' . implode( ',', $filed );
		}

		if ( ! $where ) {
			wp_safe_redirect( self::url( [ 'view' => 'promote', 'group' => $group, 'done' => 'nowhere' ] ) );
			exit;
		}

		Questions::promote_group( $group, implode( ';', $where ) );

		$this->back( 'promoted' );
	}
}
