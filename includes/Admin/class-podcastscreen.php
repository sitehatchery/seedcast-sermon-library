<?php
/**
 * Audio feed screen.
 *
 * One place to see what is in the church's audio feed and why anything is
 * missing. Part of the free plugin, because the feed it manages is generated
 * here from sermons on this site and needs no API key to control.
 *
 * The AI Engine appends a second section for the feed hosted on seedcast.ai.
 * That one does need a key, so it lives in Pro and hooks in below.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class PodcastScreen {

	const PAGE  = 'seedcast-sermon-library-podcast';
	const NONCE = 'scsl_podcast_screen';

	/**
	 * The value the feed treats as excluded. Compared exactly, so nothing else
	 * counts, including a truthy 1.
	 */
	const EXCLUDED = 'yes';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 16 );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	/**
	 * Admin URL of this screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Add the submenu, between Generate Sermon and Settings.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Audio Feed', 'seedcast-sermon-library' ),
			__( 'Audio Feed', 'seedcast-sermon-library' ),
			'publish_posts',
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/**
	 * Handle an include or exclude toggle.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
		if ( ! isset( $_GET['page'], $_GET['scsl_feed'], $_GET['post'] ) ) return;
		if ( self::PAGE !== $_GET['page'] ) return;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$post_id = absint( $_GET['post'] );
		$action  = sanitize_key( wp_unslash( $_GET['scsl_feed'] ) );

		check_admin_referer( self::NONCE . '_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) || 'scsl_sermon' !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to change that sermon.', 'seedcast-sermon-library' ) );
		}

		update_post_meta( $post_id, '_scsl_podcast_exclude', 'remove' === $action ? self::EXCLUDED : '' );

		wp_safe_redirect( add_query_arg( 'scsl_updated', '1', self::url() ) );
		exit;
	}

	/**
	 * Sermons that could plausibly belong in the feed, newest first.
	 *
	 * @return \WP_Post[]
	 */
	private function sermons(): array {
		return get_posts( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );
	}

	/**
	 * Why a sermon is not in the feed, or an empty string when it is.
	 *
	 * The feed's own conditions, stated in the order it applies them, so this
	 * screen and the feed can never disagree about a given sermon.
	 *
	 * @param \WP_Post $sermon Sermon post.
	 * @return string
	 */
	private function reason_missing( \WP_Post $sermon ): string {
		if ( 'publish' !== $sermon->post_status ) {
			return __( 'Not published yet', 'seedcast-sermon-library' );
		}

		if ( '' === (string) get_post_meta( $sermon->ID, '_scsl_audio_url', true ) ) {
			return __( 'No audio file', 'seedcast-sermon-library' );
		}

		if ( self::EXCLUDED === (string) get_post_meta( $sermon->ID, '_scsl_podcast_exclude', true ) ) {
			return __( 'Kept out of the feed', 'seedcast-sermon-library' );
		}

		return '';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'publish_posts' ) ) return;

		$sermons = $this->sermons();
		$in      = [];
		$out     = [];

		foreach ( $sermons as $sermon ) {
			$reason = $this->reason_missing( $sermon );

			if ( '' === $reason ) {
				$in[] = $sermon;
			} else {
				$out[] = [ 'post' => $sermon, 'reason' => $reason ];
			}
		}
		?>
		<div class="wrap scsl-podcast-screen">
			<h1><?php esc_html_e( 'Sermon Audio Feed', 'seedcast-sermon-library' ); ?></h1>

			<p class="scsl-feed-intro">
				<?php esc_html_e( 'iTunes-compatible RSS, ready for Apple, Spotify and every other listening app.', 'seedcast-sermon-library' ); ?>
			</p>

			<?php if ( isset( $_GET['scsl_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Feed updated.', 'seedcast-sermon-library' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_feed_url(); ?>

			<h2>
				<?php
				printf(
					/* translators: %d: number of sermons in the feed. */
					esc_html__( 'In your feed (%d)', 'seedcast-sermon-library' ),
					count( $in )
				);
				?>
			</h2>

			<?php if ( ! $in ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing is in the feed yet. A sermon appears here once it is published and has an audio file.', 'seedcast-sermon-library' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sermon', 'seedcast-sermon-library' ); ?></th>
							<th><?php esc_html_e( 'Date', 'seedcast-sermon-library' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $in as $sermon ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $sermon ) ); ?>"><?php echo esc_html( get_the_title( $sermon ) ); ?></a></td>
							<td><?php echo esc_html( get_the_date( '', $sermon ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( $this->action_url( $sermon->ID, 'remove' ) ); ?>">
									<?php esc_html_e( 'Remove from feed', 'seedcast-sermon-library' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Not in your feed', 'seedcast-sermon-library' ); ?></h2>

			<?php if ( ! $out ) : ?>
				<p class="description"><?php esc_html_e( 'Every sermon is in the feed.', 'seedcast-sermon-library' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sermon', 'seedcast-sermon-library' ); ?></th>
							<th><?php esc_html_e( 'Why not', 'seedcast-sermon-library' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $out as $row ) :
						$sermon = $row['post'];
						// Only the deliberate exclusion is undoable from here.
						// The other two reasons are facts about the sermon.
						$excluded = self::EXCLUDED === (string) get_post_meta( $sermon->ID, '_scsl_podcast_exclude', true );
						?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $sermon ) ); ?>"><?php echo esc_html( get_the_title( $sermon ) ); ?></a></td>
							<td><span class="description"><?php echo esc_html( $row['reason'] ); ?></span></td>
							<td>
								<?php if ( $excluded ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->action_url( $sermon->ID, 'add' ) ); ?>">
										<?php esc_html_e( 'Add to feed', 'seedcast-sermon-library' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			/**
			 * The AI Engine renders the feed hosted on seedcast.ai here.
			 *
			 * Nothing without a key, so the free plugin cannot show it.
			 */
			do_action( 'scsl_podcast_screen_after' );
			?>
		</div>
		<?php
	}

	/**
	 * The feed address, and the forwarding address if the church has moved.
	 *
	 * @return void
	 */
	private function render_feed_url(): void {
		$feed_url = home_url( '/feed/podcast/' );
		$redirect = (string) get_option( 'scsl_podcast_redirect', '' );
		?>
		<div class="scsl-podcast-header">
			<p>
				<strong><?php esc_html_e( 'Feed address', 'seedcast-sermon-library' ); ?></strong><br />
				<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $feed_url ); ?>" onfocus="this.select();" />
			</p>

			<?php if ( $redirect ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'This feed is forwarding subscribers to:', 'seedcast-sermon-library' ); ?>
						<code><?php echo esc_html( $redirect ); ?></code>
					</p>
					<p class="description">
						<?php esc_html_e( 'Keep this feed switched on for at least two weeks after setting that, so every listening app has time to notice and move its subscribers across.', 'seedcast-sermon-library' ); ?>
						<a href="<?php echo esc_url( \Seedcast\Core\Admin\Settings::url( SettingsPage::SECTION ) ); ?>"><?php esc_html_e( 'Change it', 'seedcast-sermon-library' ); ?></a>
					</p>
				</div>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'If this feed ever moves somewhere else, set a forwarding address so existing subscribers follow it rather than losing the show.', 'seedcast-sermon-library' ); ?>
					<a href="<?php echo esc_url( \Seedcast\Core\Admin\Settings::url( SettingsPage::SECTION ) ); ?>"><?php esc_html_e( 'Feed settings', 'seedcast-sermon-library' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A nonced toggle link.
	 *
	 * @param int    $post_id Sermon ID.
	 * @param string $action  add or remove.
	 * @return string
	 */
	private function action_url( int $post_id, string $action ): string {
		return wp_nonce_url(
			add_query_arg(
				[ 'page' => self::PAGE, 'scsl_feed' => $action, 'post' => $post_id ],
				admin_url( 'admin.php' )
			),
			self::NONCE . '_' . $post_id
		);
	}
}
