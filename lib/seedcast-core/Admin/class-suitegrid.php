<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Admin
 */

namespace Seedcast\Core\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which Seedcast plugins exist, what state they're in, and controls to install
 * or activate them.
 *
 * Two card states, and the split is a WordPress.org rule rather than a
 * preference: free plugins install inline through plugins_api() because the
 * code comes from the repository, Pro plugins get a link and nothing else
 * because a repository plugin may not install code hosted anywhere else.
 *
 * The data comes from two places:
 *
 *   1. Installed suite plugins register themselves via
 *      Seedcast_Core::register_plugin(), and the metadata they carry is the
 *      source of truth for their card.
 *   2. A small catalog below covers plugins the user hasn't installed yet,
 *      so the grid can still surface them for discovery.
 *
 * A plugin present in both is treated as registered; the catalog entry is
 * only used when the plugin isn't loaded. This means adding a new plugin
 * to the suite is a matter of the new plugin registering itself, and the
 * catalog only needs an entry for "known but not yet installed."
 *
 * Keep the presentation dull. A grid that reads as an ad annoys people and
 * attracts reviewer attention.
 */
final class SuiteGrid {

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_ajax_seedcast_suite_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_seedcast_suite_install', array( $this, 'ajax_install' ) );
	}

	/**
	 * The discovery catalog: plugins the suite knows exist, whether or not
	 * they are installed. Registered plugins overlay these entries; each
	 * only needs to appear here for the "install this" state.
	 *
	 * @return array<int, array>
	 */
	public static function catalog(): array {
		return array(
			array(
				'slug'    => 'seedcast-living-bulletin',
				'channel' => 'free',
				'name'    => 'Living Bulletin',
				'tagline' => __( 'The bulletin, alive online. Weekly service pages that gather programs, announcements, and handouts.', 'seedcast-sermon-library' ),
				'file'    => 'seedcast-living-bulletin/seedcast-living-bulletin.php',
				'admin'   => 'admin.php?page=sunday',
				'icon'    => 'dashicons-calendar-alt',
			),
			array(
				'slug'    => 'seedcast-sermon-library',
				'channel' => 'free',
				'name'    => 'Sermon Library',
				'tagline' => __( 'Sermons with series, speakers, scripture, and a podcast feed.', 'seedcast-sermon-library' ),
				'file'    => 'seedcast-sermon-library/seedcast-sermon-library.php',
				'admin'   => 'edit.php?post_type=scsl_sermon',
				'icon'    => 'dashicons-playlist-audio',
			),
			array(
				'slug'    => 'seedcast-ai-engine',
				'channel' => 'engine',
				'name'    => 'Seedcast AI Engine',
				'tagline' => __( 'Transcription, summaries, and automatic content preparation for the plugins you already run.', 'seedcast-sermon-library' ),
				'file'    => 'seedcast-ai-engine/seedcast-ai-engine.php',
				'admin'   => 'options-general.php?page=seedcast-settings',
				'url'     => 'https://seedcast.ai',
				'icon'    => 'dashicons-superhero',
			),
		);
	}

	/**
	 * Kept for backwards compatibility with any callers still asking for
	 * the manifest. Prefer `cards()` for new code.
	 *
	 * @return array<int, array>
	 */
	public static function manifest(): array {
		return self::catalog();
	}

	/**
	 * The suite as a list of cards, ready to render.
	 *
	 * Merges registered plugins (source of truth for anything installed) with
	 * the catalog (for anything else). When a plugin appears in both, the
	 * registered data wins on the fields it provides and the catalog fills
	 * in any gaps.
	 *
	 * @return array<int, array>
	 */
	public static function cards(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$registered = \Seedcast\Core\Registry::instance()->get_plugins();
		$catalog    = array();
		foreach ( self::catalog() as $entry ) {
			$catalog[ $entry['slug'] ] = $entry;
		}

		// Merge: registered wins, catalog fills in fields the registered
		// plugin didn't carry (typically `channel`, `icon` if the plugin
		// registered with defaults, `url` for external Pro).
		$slugs  = array_unique( array_merge( array_keys( $registered ), array_keys( $catalog ) ) );
		$cards  = array();
		foreach ( $slugs as $slug ) {
			$reg = isset( $registered[ $slug ] ) ? $registered[ $slug ] : array();
			$cat = isset( $catalog[ $slug ] )    ? $catalog[ $slug ]    : array();
			// Skip empties. wp_parse_args prefers left over right, so
			// registered data wins and catalog fills gaps.
			$card = wp_parse_args( $reg, $cat );
			// Ensure slug is present.
			$card['slug'] = $slug;

			// Compute installed/active from the file, if known.
			$file               = ! empty( $card['file'] ) ? (string) $card['file'] : '';
			$card['installed']  = $file && file_exists( WP_PLUGIN_DIR . '/' . $file );
			$card['active']     = isset( $registered[ $slug ] ) || ( $card['installed'] && is_plugin_active( $file ) );

			$cards[] = $card;
		}
		return $cards;
	}

	/**
	 * Render the grid.
	 *
	 * @return void
	 */
	public static function render(): void {
		$nonce = wp_create_nonce( 'seedcast_suite' );
		?>
		<p class="description">
			<?php esc_html_e( 'Seedcast plugins share one set of settings and one look. Anything you install later inherits both automatically.', 'seedcast-sermon-library' ); ?>
		</p>
		<div class="sc-suite-grid" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php foreach ( self::cards() as $card ) : ?>
				<?php self::render_card( $card ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a single card.
	 *
	 * @param array $card Resolved card data.
	 * @return void
	 */
	private static function render_card( array $card ): void {
		$state  = $card['active'] ? 'is-active' : ( $card['installed'] ? 'is-inactive' : 'is-available' );
		$is_pro = ! $card['installed'] && ! empty( $card['url'] );
		?>
		<div class="sc-tile <?php echo esc_attr( $state ); ?>"
			data-slug="<?php echo esc_attr( $card['slug'] ); ?>"
			data-file="<?php echo esc_attr( $card['file'] ?? '' ); ?>">
			<div class="sc-tile__head">
				<span class="sc-tile__icon dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span>
				<h3 class="sc-tile__name"><?php echo esc_html( $card['name'] ); ?></h3>
			</div>
			<p class="sc-tile__tagline"><?php echo esc_html( $card['tagline'] ); ?></p>
			<div class="sc-tile__foot">
				<?php if ( $is_pro ) : ?>
					<a class="sc-tile__link" href="<?php echo esc_url( $card['url'] ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Learn more', 'seedcast-sermon-library' ); ?>
					</a>
					<span class="sc-tile__status"><?php esc_html_e( 'Add-on', 'seedcast-sermon-library' ); ?></span>
				<?php elseif ( $card['active'] ) : ?>
					<a class="sc-tile__link" href="<?php echo esc_url( admin_url( $card['admin'] ) ); ?>">
						<?php esc_html_e( 'Open', 'seedcast-sermon-library' ); ?>
					</a>
					<span class="sc-tile__status"><?php esc_html_e( 'Active', 'seedcast-sermon-library' ); ?></span>
				<?php elseif ( $card['installed'] ) : ?>
					<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
						<button type="button" class="button button-small sc-tile__activate">
							<?php esc_html_e( 'Activate', 'seedcast-sermon-library' ); ?>
						</button>
					<?php endif; ?>
					<span class="sc-tile__status"><?php esc_html_e( 'Inactive', 'seedcast-sermon-library' ); ?></span>
				<?php else : ?>
					<?php if ( current_user_can( 'install_plugins' ) ) : ?>
						<button type="button" class="button button-small sc-tile__install">
							<?php esc_html_e( 'Install', 'seedcast-sermon-library' ); ?>
						</button>
					<?php endif; ?>
					<span class="sc-tile__status"><?php esc_html_e( 'Not installed', 'seedcast-sermon-library' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The manifest entry for a slug, or null. Everything the AJAX handlers act
	 * on is looked up here first, so no request can reach an unrelated plugin.
	 *
	 * @param string $slug Plugin slug.
	 * @return array|null
	 */
	private static function entry( string $slug ): ?array {
		foreach ( self::manifest() as $entry ) {
			if ( $entry['slug'] === $slug && 'free' === $entry['channel'] ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * AJAX: activate an installed suite plugin.
	 *
	 * @return void
	 */
	public function ajax_activate(): void {
		check_ajax_referer( 'seedcast_suite', 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seedcast-sermon-library' ) ), 403 );
		}

		$slug  = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$entry = self::entry( $slug );
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'Not a Seedcast plugin.', 'seedcast-sermon-library' ) ), 400 );
		}

		// The file comes from the manifest, never from the request, so no
		// crafted post can reach a plugin outside the suite.
		$file = $entry['file'];

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		wp_send_json_success( array( 'active' => true ) );
	}

	/**
	 * AJAX: install a free suite plugin from the WordPress.org repository.
	 *
	 * Only ever installs slugs present in the manifest above, and only from
	 * the repository. Nothing here fetches code from seedcast.ai.
	 *
	 * @return void
	 */
	public function ajax_install(): void {
		check_ajax_referer( 'seedcast_suite', 'nonce' );
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seedcast-sermon-library' ) ), 403 );
		}

		$slug  = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$entry = self::entry( $slug );
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'Not a Seedcast plugin.', 'seedcast-sermon-library' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			wp_send_json_error( array( 'message' => $api->get_error_message() ), 500 );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		if ( true !== $result ) {
			wp_send_json_error( array( 'message' => __( 'Install did not complete.', 'seedcast-sermon-library' ) ), 500 );
		}

		$file = $upgrader->plugin_info();
		if ( $file && current_user_can( 'activate_plugins' ) ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				wp_send_json_success( array( 'installed' => true, 'active' => false ) );
			}
		}

		wp_send_json_success( array( 'installed' => true, 'active' => true ) );
	}
}
