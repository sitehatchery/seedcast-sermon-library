<?php
/**
 * Admin Menu: top-level Sermon Library menu and Help page.
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers the Sermon Library admin menu and the inline Help page.
 */
class AdminMenu {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_top_menu'  ] );
		add_action( 'admin_menu', [ $this, 'add_help_menu' ], 999 ); // 999 = always last
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
	}

	/**
	 * Register the top-level Sermon Library menu item.
	 */
	public function add_top_menu(): void {
		add_menu_page(
			__( 'Sermon Library', 'seedcast-sermon-library' ),
			__( 'Sermon Library', 'seedcast-sermon-library' ),
			'manage_options',
			'seedcast-sermon-library',
			'__return_null',
			'dashicons-playlist-audio',
			30
		);
	}

	/**
	 * Register the Help submenu item at lowest priority so it always appears last.
	 */
	public function add_help_menu(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Help', 'seedcast-sermon-library' ),
			__( 'Help', 'seedcast-sermon-library' ),
			'edit_posts',
			'seedcast-sermon-library-help',
			[ $this, 'render_help' ]
		);
	}

	/**
	 * Render the inline Help page.
	 */
	public function render_help(): void {
		if ( ! current_user_can( 'edit_posts' ) ) return;
		$docs_url = 'https://seedcast.ai/docs/';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sermon Library Help', 'seedcast-sermon-library' ); ?></h1>

			<div style="max-width:900px;display:grid;grid-template-columns:2fr 1fr;gap:2rem;margin-top:1.5rem;">

				<div>
					<h2 style="margin-top:0;"><?php esc_html_e( 'Documentation', 'seedcast-sermon-library' ); ?></h2>
					<table class="widefat fixed striped" style="margin-top:1rem;">
						<thead><tr>
							<th><?php esc_html_e( 'Topic', 'seedcast-sermon-library' ); ?></th>
							<th><?php esc_html_e( 'Link',  'seedcast-sermon-library' ); ?></th>
						</tr></thead>
						<tbody>
						<?php
						$docs = [
							__( 'Installation',                    'seedcast-sermon-library' ) => 'installation.html',
							__( 'Adding Your First Sermon',        'seedcast-sermon-library' ) => 'first-sermon.html',
							__( 'Importing from Series Engine',    'seedcast-sermon-library' ) => 'importing.html',
							__( 'Managing Sermons',                'seedcast-sermon-library' ) => 'sermons.html',
							__( 'Series',                          'seedcast-sermon-library' ) => 'series.html',
							__( 'Speakers',                        'seedcast-sermon-library' ) => 'speakers.html',
							__( 'Topics',                          'seedcast-sermon-library' ) => 'topics.html',
							__( 'Shortcodes',                      'seedcast-sermon-library' ) => 'shortcodes.html',
							__( 'Scripture Passages',              'seedcast-sermon-library' ) => 'scripture.html',
							__( 'Video & Audio',                   'seedcast-sermon-library' ) => 'media.html',
							__( 'Sermon Notes & Files',            'seedcast-sermon-library' ) => 'sermon-notes.html',
							__( 'Audio Feed',                      'seedcast-sermon-library' ) => 'podcast.html',
							__( 'Themes & Custom CSS',             'seedcast-sermon-library' ) => 'themes.html',
							__( 'Settings Reference',              'seedcast-sermon-library' ) => 'settings.html',
							__( 'Troubleshooting',                 'seedcast-sermon-library' ) => 'troubleshooting.html',
							__( 'FAQ',                             'seedcast-sermon-library' ) => 'faq.html',
						];
						foreach ( $docs as $label => $page ) :
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><a href="<?php echo esc_url( $docs_url . $page ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'View →', 'seedcast-sermon-library' ); ?>
							</a></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div>
					<h2 style="margin-top:0;"><?php esc_html_e( 'Quick Links', 'seedcast-sermon-library' ); ?></h2>
					<ul style="line-height:2;">
						<li><a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '📖 Full Documentation', 'seedcast-sermon-library' ); ?></a></li>
						<li><a href="https://wordpress.org/support/plugin/sermon-library" target="_blank" rel="noopener"><?php esc_html_e( '💬 Support Forum', 'seedcast-sermon-library' ); ?></a></li>
						<li><a href="https://seedcast.ai" target="_blank" rel="noopener"><?php esc_html_e( '🌐 Seedcast.ai', 'seedcast-sermon-library' ); ?></a></li>
						<li><a href="mailto:hello@seedcast.ai"><?php esc_html_e( '✉ Contact Us', 'seedcast-sermon-library' ); ?></a></li>
					</ul>

					<h2><?php esc_html_e( 'Shortcode Reference', 'seedcast-sermon-library' ); ?></h2>
					<table class="widefat">
						<tbody>
							<tr><td><code>[scsl_sermon_list]</code></td><td><?php esc_html_e( 'Sermon list with filters', 'seedcast-sermon-library' ); ?></td></tr>
							<tr><td><code>[scsl_series_grid]</code></td><td><?php esc_html_e( 'Grid of sermon series', 'seedcast-sermon-library' ); ?></td></tr>
							<tr><td><code>[scsl_latest]</code></td><td><?php esc_html_e( 'Latest sermon card', 'seedcast-sermon-library' ); ?></td></tr>
							<tr><td><code>[scsl_topic_list]</code></td><td><?php esc_html_e( 'Topic pills grid', 'seedcast-sermon-library' ); ?></td></tr>
						</tbody>
					</table>

					<h2><?php esc_html_e( 'Plugin Version', 'seedcast-sermon-library' ); ?></h2>
					<p><?php echo esc_html( SCSL_VERSION ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Add Documentation and Seedcast AI Engine links to the plugin row on the Plugins screen.
	 *
	 * @param string[] $links Existing plugin meta links.
	 * @param string   $file  Plugin file path relative to plugins directory.
	 * @return string[] Modified links array.
	 */
	public function plugin_row_meta( array $links, string $file ): array {
		if ( strpos( $file, 'sermon-library.php' ) !== false ) {
			$links[] = '<a href="https://seedcast.ai/docs/" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'seedcast-sermon-library' ) . '</a>';
			$links[] = '<a href="https://seedcast.ai/sermon-library/" target="_blank" rel="noopener">' . esc_html__( 'Seedcast AI Engine', 'seedcast-sermon-library' ) . '</a>';
		}
		return $links;
	}
}
