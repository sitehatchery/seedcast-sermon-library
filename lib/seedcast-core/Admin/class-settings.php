<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Admin
 */

namespace Seedcast\Core\Admin;

use Seedcast\Core\Church;
use Seedcast\Core\Frontend\ChurchDetails;
use Seedcast\Core\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One settings page for the whole suite, under Settings, in the same place no
 * matter what is installed.
 *
 * Each section is its own form with its own registered group, because the
 * Settings API only processes one group per submit. Switching sections is
 * client side; saving is not.
 *
 * Plugins register like this:
 *
 *     add_action( 'seedcast_core_register_settings', function ( $settings ) {
 *         $settings->add_section(
 *             'scsl_general',
 *             __( 'Sermon Library', 'seedcast-core' ),
 *             [ Settings::class, 'render' ],
 *             'scsl_settings'
 *         );
 *     } );
 */
final class Settings {

	/**
	 * Page slug under options-general.php.
	 */
	public const PAGE = 'seedcast-settings';

	/**
	 * Core's own settings group.
	 */
	private const GROUP = 'seedcast_settings';

	/**
	 * Church identity settings group. Separate from GROUP because each
	 * section on this page is its own form and the Settings API only
	 * processes one group per submit.
	 */
	private const CHURCH_GROUP = 'seedcast_church_settings';

	/**
	 * Registered sections.
	 *
	 * @var array<string, array>
	 */
	private array $sections = array();

	/**
	 * Monotonic counter used to break weight ties by registration order.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'core_version_notice' ) );
	}

	/**
	 * URL of the settings page, optionally opening a specific section.
	 *
	 * @param string $section_id Section to open on arrival.
	 * @return string
	 */
	public static function url( string $section_id = '' ): string {
		$args = array( 'page' => self::PAGE );
		if ( '' !== $section_id ) {
			$args['section'] = $section_id;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * The menu slug a plugin passes to add_submenu_page to produce a link to
	 * this page from its own CPT menu. Passing a full relative URL as the slug
	 * produces a link rather than a page, which is exactly what is wanted:
	 * people look for settings where they work, not where they were filed.
	 *
	 * @param string $section_id Section to open on arrival.
	 * @return string
	 */
	public static function menu_link( string $section_id = '' ): string {
		$slug = 'options-general.php?page=' . self::PAGE;
		if ( '' !== $section_id ) {
			$slug .= '&section=' . rawurlencode( $section_id );
		}
		return $slug;
	}

	/**
	 * Register the page under the parent Settings menu.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Seedcast', 'seedcast-sermon-library' ),
			__( 'Seedcast', 'seedcast-sermon-library' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Add a section to the shared page.
	 *
	 * @param string   $id     Section ID, plugin prefixed.
	 * @param string   $label  Section label shown in the nav.
	 * @param callable $render Renders only the section's fields. No form tag,
	 *                         no heading, no submit button; the page supplies
	 *                         those around each section.
	 * @param string   $group  Registered settings group for settings_fields().
	 *                         Pass an empty string for a section that is not a
	 *                         settings form (a list or report, say). Such a
	 *                         section renders bare, with no form wrapper and no
	 *                         submit button, so it can own its own forms.
	 * @param int      $weight Lower shows first. Core's own sections are 0.
	 * @return void
	 */
	public function add_section( string $id, string $label, callable $render, string $group, int $weight = 10 ): void {
		$this->sections[ $id ] = array(
			'label'  => $label,
			'render' => $render,
			'group'  => $group,
			'weight' => $weight,
			'seq'    => $this->seq++,
		);
	}

	/**
	 * Sections in display order: declared weight first, then registration
	 * order as a stable tiebreak.
	 *
	 * @return array<string, array>
	 */
	public function get_sections(): array {
		$sections = $this->sections;
		uasort(
			$sections,
			static function ( $a, $b ) {
				return $a['weight'] === $b['weight']
					? $a['seq'] <=> $b['seq']
					: $a['weight'] <=> $b['weight'];
			}
		);
		return $sections;
	}

	/**
	 * Register core's own options and collect plugin sections.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$fields = array(
			'seedcast_theme',
			'seedcast_captcha_provider',
			'seedcast_captcha_site_key',
			'seedcast_captcha_secret',
			'seedcast_honeypot_enabled',
		);
		foreach ( $fields as $field ) {
			register_setting( self::GROUP, $field, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		}

		/*
		 * Church identity has its own group so it saves independently of the
		 * appearance and spam fields, which share the core group. The Settings
		 * API processes one group per submit, and these are three separate
		 * forms on the page.
		 */
		$church_fields = array(
			'seedcast_church_name'          => 'sanitize_text_field',
			'seedcast_church_address'       => 'sanitize_textarea_field',
			'seedcast_church_city'          => 'sanitize_text_field',
			'seedcast_church_state'         => 'sanitize_text_field',
			'seedcast_church_postcode'      => 'sanitize_text_field',
			'seedcast_church_phone'         => 'sanitize_text_field',
			'seedcast_church_email'         => 'sanitize_email',
			'seedcast_church_service_day'   => array( Church::class, 'sanitize_service_day' ),
			'seedcast_church_service_times' => 'sanitize_textarea_field',
			'seedcast_church_events_url'    => 'esc_url_raw',
			'seedcast_church_visitor_note'  => 'sanitize_textarea_field',
			'seedcast_church_country'       => 'sanitize_text_field',
			'seedcast_church_description'   => 'sanitize_textarea_field',
			'seedcast_church_facebook'      => 'esc_url_raw',
			'seedcast_church_instagram'     => 'esc_url_raw',
			'seedcast_church_youtube'       => 'esc_url_raw',
		);
		foreach ( $church_fields as $field => $sanitize ) {
			register_setting( self::CHURCH_GROUP, $field, array( 'sanitize_callback' => $sanitize ) );
		}

		$this->add_section(
			'seedcast_suite',
			__( 'Suite', 'seedcast-sermon-library' ),
			array( $this, 'render_suite_section' ),
			'',
			0
		);
		$this->add_section(
			'seedcast_church',
			__( 'Church', 'seedcast-sermon-library' ),
			array( $this, 'render_church_section' ),
			self::CHURCH_GROUP,
			1
		);
		$this->add_section(
			'seedcast_appearance',
			__( 'Appearance', 'seedcast-sermon-library' ),
			array( $this, 'render_appearance_section' ),
			self::GROUP,
			2
		);
		$this->add_section(
			'seedcast_spam',
			__( 'Spam protection', 'seedcast-sermon-library' ),
			array( $this, 'render_spam_section' ),
			self::GROUP,
			3
		);

		/**
		 * Fires so suite plugins can add their own sections to the shared page.
		 *
		 * @param Settings $settings The settings page instance.
		 */
		do_action( 'seedcast_core_register_settings', $this );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sections = $this->get_sections();
		if ( empty( $sections ) ) {
			return;
		}

		// Read only section selection for display. Saving happens through
		// options.php, which performs its own nonce verification.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		$active    = ( $requested && isset( $sections[ $requested ] ) ) ? $requested : array_key_first( $sections );
		?>
		<div class="wrap sc-settings-wrap">
			<h1><?php esc_html_e( 'Seedcast', 'seedcast-sermon-library' ); ?></h1>
			<?php
			/*
			 * No settings_errors() call here. This page lives under the
			 * Settings menu, and WordPress already prints them from
			 * options-head.php on those screens. Calling it again showed
			 * "Settings saved." twice.
			 */
			?>

			<nav class="sc-settings-nav nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'seedcast-sermon-library' ); ?>">
				<?php foreach ( $sections as $id => $section ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>"
						class="sc-settings-nav__item nav-tab<?php echo esc_attr( $id === $active ? ' nav-tab-active is-active' : '' ); ?>"
						<?php echo $id === $active ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $section['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			$section = $sections[ $active ];
			if ( '' === $section['group'] ) :
				call_user_func( $section['render'] );
			else :
				?>
				<form method="post" action="options.php">
					<?php
					settings_fields( $section['group'] );
					/*
					 * options.php redirects to _wp_http_referer after saving.
					 * That is this page's URL including the section, so the
					 * default behaviour is already right, but set it explicitly
					 * rather than depending on how the person arrived.
					 */
					$return_url = add_query_arg( 'settings-updated', 'true', self::url( $active ) );
					?>
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $return_url ); ?>" />
					<?php
					call_user_func( $section['render'] );
					submit_button();
					?>
				</form>
				<?php
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * The suite section: which Seedcast plugins exist and what state they are
	 * in. This is the landing view because plugin discovery matters most to a
	 * church running exactly one plugin who does not know the others exist.
	 *
	 * @return void
	 */
	public function render_suite_section(): void {
		SuiteGrid::render();

	}

	/**
	 * Church: who the church is, where they meet, when they meet.
	 *
	 * Shared by every plugin in the suite. A visitor form uses the service day
	 * for its confirmation wording, a bulletin uses the service times, the
	 * footer uses the address, and an email to a visitor uses all of it.
	 * Entered once, here.
	 *
	 * @return void
	 */
	public function render_church_section(): void {
		global $wp_locale;

		$service_day = Church::service_day();
		?>
		<div class="sc-settings-card">
			<h2><?php esc_html_e( 'Church', 'seedcast-sermon-library' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Your church details, shared across every Seedcast plugin on this site. Change them here and everywhere they appear updates together.', 'seedcast-sermon-library' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="seedcast_church_name"><?php esc_html_e( 'Church name', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="seedcast_church_name" id="seedcast_church_name"
							value="<?php echo esc_attr( (string) get_option( 'seedcast_church_name', '' ) ); ?>" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: the site title, used as the fallback church name. */
								esc_html__( 'Leave blank to use the site title, %s.', 'seedcast-sermon-library' ),
								'<strong>' . esc_html( (string) get_bloginfo( 'name' ) ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_address"><?php esc_html_e( 'Street address', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="3" name="seedcast_church_address" id="seedcast_church_address"><?php echo esc_textarea( (string) get_option( 'seedcast_church_address', '' ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'The street and building only. City, state and postcode go in the fields below.', 'seedcast-sermon-library' ); ?>
						</p>

						<?php
						/*
						 * City and state used to live in this box, so an
						 * existing address probably still has them in it. They
						 * are needed on their own now, for sentences that name
						 * where a church is, and leaving them in both places
						 * would print the city twice.
						 */
						$seedcast_address_lines = array_filter(
							array_map( 'trim', (array) preg_split( '/\r\n|\r|\n/', (string) get_option( 'seedcast_church_address', '' ) ) ),
							'strlen'
						);
						?>
						<?php if ( count( $seedcast_address_lines ) > 1 && '' === \Seedcast\Core\Church::city() ) : ?>
							<div class="notice notice-warning inline">
								<p>
									<?php esc_html_e( 'This looks like it still holds the city and state. Move them into the fields below and leave only the street here, or they will appear twice.', 'seedcast-sermon-library' ); ?>
								</p>
							</div>
						<?php endif; ?>
						<?php
						/*
						 * The directions link is built from whatever is typed
						 * above, and a typo produces a link that goes somewhere
						 * else entirely. Nothing on this screen would show
						 * that, and the first person to find out would be a
						 * visitor in a car. This is the same link, so pressing
						 * it is the check.
						 */
						$seedcast_directions = \Seedcast\Core\Church::directions_url();
						?>
						<?php if ( '' !== $seedcast_directions ) : ?>
							<p>
								<a href="<?php echo esc_url( $seedcast_directions ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Check this address on the map', 'seedcast-sermon-library' ); ?>
								</a>
								<span class="description">
									<?php esc_html_e( 'Opens the exact link visitors are given. If it lands somewhere else, fix the address above.', 'seedcast-sermon-library' ); ?>
								</span>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_city"><?php esc_html_e( 'City', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="seedcast_church_city" id="seedcast_church_city"
							value="<?php echo esc_attr( (string) get_option( 'seedcast_church_city', '' ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Used in sentences that say where you are, which is also what search engines read to connect you to a place.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_state"><?php esc_html_e( 'State or county', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="seedcast_church_state" id="seedcast_church_state"
							value="<?php echo esc_attr( (string) get_option( 'seedcast_church_state', '' ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Written out rather than abbreviated reads better in a sentence: California, not CA.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_country"><?php esc_html_e( 'Country', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="text" class="small-text" name="seedcast_church_country" id="seedcast_church_country" maxlength="2"
							value="<?php echo esc_attr( (string) get_option( 'seedcast_church_country', 'US' ) ); ?>" />
						<p class="description">
							<?php
							/*
							 * Two letters, because this one is read by machines
							 * rather than people. It appears in the description
							 * of the church given to search engines and nowhere
							 * a visitor would see.
							 */
							esc_html_e( 'Two-letter country code, such as US, CA or GB. Used when describing your church to search engines.', 'seedcast-sermon-library' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_postcode"><?php esc_html_e( 'Postcode or zip', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="seedcast_church_postcode" id="seedcast_church_postcode"
							value="<?php echo esc_attr( (string) get_option( 'seedcast_church_postcode', '' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_service_day"><?php esc_html_e( 'Service day', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<select name="seedcast_church_service_day" id="seedcast_church_service_day">
							<?php
							for ( $day = 0; $day <= 6; $day++ ) :
								$label = ( $wp_locale instanceof \WP_Locale ) ? $wp_locale->get_weekday( $day ) : (string) $day;
								?>
								<option value="<?php echo esc_attr( (string) $day ); ?>" <?php selected( $service_day, $day ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endfor; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'The day your main gathering falls on. Plugins use this rather than assuming Sunday.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_service_times"><?php esc_html_e( 'Service times', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="3" name="seedcast_church_service_times" id="seedcast_church_service_times"><?php echo esc_textarea( (string) get_option( 'seedcast_church_service_times', '' ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One per line, in your own words. For example: 9:00 AM Traditional, 11:00 AM Contemporary.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_phone"><?php esc_html_e( 'Phone', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="text" class="regular-text" name="seedcast_church_phone" id="seedcast_church_phone"
						value="<?php echo esc_attr( (string) get_option( 'seedcast_church_phone', '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_email"><?php esc_html_e( 'Contact email', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" name="seedcast_church_email" id="seedcast_church_email"
							value="<?php echo esc_attr( (string) get_option( 'seedcast_church_email', '' ) ); ?>" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: the site admin email address, used as the fallback contact address. */
								esc_html__( 'Used as the reply-to address on mail sent on the church\'s behalf. Leave blank to use %s.', 'seedcast-sermon-library' ),
								'<strong>' . esc_html( (string) get_option( 'admin_email', '' ) ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_events_url"><?php esc_html_e( 'Events page', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="url" class="regular-text" name="seedcast_church_events_url" id="seedcast_church_events_url"
							value="<?php echo esc_attr( (string) get_option( 'seedcast_church_events_url', '' ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Optional. Where someone can see what is coming up.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_description"><?php esc_html_e( 'About this church', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="3" name="seedcast_church_description" id="seedcast_church_description"><?php echo esc_textarea( (string) get_option( 'seedcast_church_description', '' ) ); ?></textarea>
						<p class="description">
							<?php
							/*
							 * A sentence or two, written for a stranger.
							 *
							 * This is what a search engine is told the church
							 * is, so it wants the plain facts somebody would
							 * want before visiting rather than a mission
							 * statement.
							 */
							esc_html_e( 'A sentence or two describing your church: where it is, what it believes, what a visitor would find. Given to search engines when they ask what this church is.', 'seedcast-sermon-library' );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Where else you are', 'seedcast-sermon-library' ); ?></th>
					<td>
						<?php
						/*
						 * Official profiles, so a search engine can tie them
						 * together with the church.
						 *
						 * Separate fields rather than one box, because each
						 * has to be a whole address on its own and a list
						 * somebody typed would arrive in whatever shape they
						 * chose.
						 */
						foreach ( array(
							'seedcast_church_facebook'  => __( 'Facebook page', 'seedcast-sermon-library' ),
							'seedcast_church_instagram' => __( 'Instagram profile', 'seedcast-sermon-library' ),
							'seedcast_church_youtube'   => __( 'YouTube channel', 'seedcast-sermon-library' ),
						) as $seedcast_key => $seedcast_label ) :
							?>
							<p>
								<label for="<?php echo esc_attr( $seedcast_key ); ?>" style="display:inline-block;min-width:9em;">
									<?php echo esc_html( $seedcast_label ); ?>
								</label>
								<input type="url" class="regular-text" name="<?php echo esc_attr( $seedcast_key ); ?>" id="<?php echo esc_attr( $seedcast_key ); ?>"
									placeholder="https://"
									value="<?php echo esc_attr( (string) get_option( $seedcast_key, '' ) ); ?>" />
							</p>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'The official addresses of your church on each. These tell a search engine that all of them are the same church.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_church_visitor_note"><?php esc_html_e( 'Note to visitors', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="4" name="seedcast_church_visitor_note" id="seedcast_church_visitor_note"><?php echo esc_textarea( (string) get_option( 'seedcast_church_visitor_note', '' ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'A short word to someone thinking about coming for the first time. Where to park, what people wear, what happens with their kids.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Showing these on your site', 'seedcast-sermon-library' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Place this shortcode anywhere, or use the Church Details widget if you build with Elementor.', 'seedcast-sermon-library' ); ?>
			</p>
			<p>
				<code>[<?php echo esc_html( ChurchDetails::SHORTCODE ); ?>]</code>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: a list of shortcode field names. */
					esc_html__( 'Choose which parts to show with the show attribute: %s.', 'seedcast-sermon-library' ),
					'<code>' . esc_html( implode( ', ', ChurchDetails::FIELDS ) ) . '</code>'
				);
				?>
			</p>
			<p>
				<code>[<?php echo esc_html( ChurchDetails::SHORTCODE ); ?> show="address,times,directions"]</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Appearance: the shared theme tokens.
	 *
	 * @return void
	 */
	public function render_appearance_section(): void {
		$theme = get_option( 'seedcast_theme', 'light' );

		$themes = array(
			'light'   => array(
				'label'   => __( 'Light', 'seedcast-sermon-library' ),
				'desc'    => __( 'Clean white with a warm accent', 'seedcast-sermon-library' ),
				'preview' => array( '#ffffff', '#c9975a', '#1f2937' ),
			),
			'dark'    => array(
				'label'   => __( 'Dark', 'seedcast-sermon-library' ),
				'desc'    => __( 'Deep charcoal with a soft gold accent', 'seedcast-sermon-library' ),
				'preview' => array( '#1e293b', '#e0b872', '#e2e8f0' ),
			),
			'warm'    => array(
				'label'   => __( 'Warm', 'seedcast-sermon-library' ),
				'desc'    => __( 'Gold accents on white, near-black hover', 'seedcast-sermon-library' ),
				'preview' => array( '#ffffff', '#d3b673', '#0c0c0e' ),
			),
			'minimal' => array(
				'label'   => __( 'Minimal', 'seedcast-sermon-library' ),
				'desc'    => __( 'No shadows, pure typography', 'seedcast-sermon-library' ),
				'preview' => array( '#ffffff', '#111827', '#6b7280' ),
			),
		);
		?>
		<div class="sc-settings-card">
			<h2><?php esc_html_e( 'Appearance', 'seedcast-sermon-library' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Applied across every Seedcast plugin on this site. Set it once here and any plugin you install later picks it up with no configuration.', 'seedcast-sermon-library' ); ?>
			</p>
			<div class="sc-appearance-picker">
				<?php foreach ( $themes as $key => $option ) : ?>
					<label class="sc-appearance-option<?php echo esc_attr( $theme === $key ? ' is-selected' : '' ); ?>">
						<input type="radio" name="seedcast_theme" value="<?php echo esc_attr( $key ); ?>"
							<?php checked( $theme, $key ); ?> class="sc-appearance-radio" />
						<span class="sc-appearance-preview">
							<?php foreach ( $option['preview'] as $color ) : ?>
								<span class="sc-appearance-swatch" style="background:<?php echo esc_attr( $color ); ?>;"></span>
							<?php endforeach; ?>
						</span>
						<span class="sc-appearance-info">
							<strong><?php echo esc_html( $option['label'] ); ?></strong>
							<span><?php echo esc_html( $option['desc'] ); ?></span>
						</span>
						<span class="sc-appearance-check">&check;</span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Spam protection: honeypot and captcha, configured once for every form in
	 * the suite.
	 *
	 * @return void
	 */
	public function render_spam_section(): void {
		$provider = get_option( 'seedcast_captcha_provider', 'none' );
		$honeypot = get_option( 'seedcast_honeypot_enabled', '1' );
		?>
		<div class="sc-settings-card">
			<h2><?php esc_html_e( 'Spam protection', 'seedcast-sermon-library' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Applies to every submission form across the suite. Configured once, here.', 'seedcast-sermon-library' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="seedcast_captcha_provider"><?php esc_html_e( 'Captcha provider', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<select name="seedcast_captcha_provider" id="seedcast_captcha_provider">
							<option value="none" <?php selected( $provider, 'none' ); ?>><?php esc_html_e( 'None', 'seedcast-sermon-library' ); ?></option>
							<option value="recaptcha" <?php selected( $provider, 'recaptcha' ); ?>><?php esc_html_e( 'Google reCAPTCHA v2 (checkbox)', 'seedcast-sermon-library' ); ?></option>
							<option value="hcaptcha" <?php selected( $provider, 'hcaptcha' ); ?>><?php esc_html_e( 'hCaptcha', 'seedcast-sermon-library' ); ?></option>
							<option value="turnstile" <?php selected( $provider, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'seedcast-sermon-library' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'The reCAPTCHA option renders the standard checkbox widget (v2). Use the site key and secret from a v2 reCAPTCHA in your Google admin console. v3 is not currently supported.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_captcha_site_key"><?php esc_html_e( 'Captcha site key', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="text" class="regular-text" name="seedcast_captcha_site_key" id="seedcast_captcha_site_key" value="<?php echo esc_attr( get_option( 'seedcast_captcha_site_key', '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="seedcast_captcha_secret"><?php esc_html_e( 'Captcha secret key', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="text" class="regular-text" name="seedcast_captcha_secret" id="seedcast_captcha_secret" value="<?php echo esc_attr( get_option( 'seedcast_captcha_secret', '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Honeypot', 'seedcast-sermon-library' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="seedcast_honeypot_enabled" value="1" <?php checked( $honeypot, '1' ); ?> />
							<?php esc_html_e( 'Enable the hidden honeypot spam trap on all forms', 'seedcast-sermon-library' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Warn, without fataling, when a plugin needs a newer core than the copy
	 * that won negotiation. The fix is to update the other Seedcast plugins.
	 *
	 * @return void
	 */
	public function core_version_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$behind = Registry::instance()->plugins_needing_newer_core();
		if ( empty( $behind ) ) {
			return;
		}
		$names = wp_list_pluck( $behind, 'name' );
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: comma separated plugin names, 2: active shared library version. */
					__( 'These Seedcast plugins expect a newer shared library than the one currently active (%2$s): %1$s. Updating your other Seedcast plugins resolves this. Some features stay hidden until then.', 'seedcast-sermon-library' ),
					implode( ', ', $names ),
					SEEDCAST_CORE_VERSION
				)
			)
		);
	}
}
