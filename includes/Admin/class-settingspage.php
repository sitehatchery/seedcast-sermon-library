<?php
namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class SettingsPage {

	/**
	 * Section ID on the shared settings page.
	 */
	const SECTION = 'scsl_general';

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'seedcast_core_register_settings', [ $this, 'register_section' ] );
		add_action( 'admin_menu', [ $this, 'add_menu_link' ], 20 );
		add_filter( 'plugin_action_links_' . SCSL_PLUGIN_BASENAME, [ $this, 'action_link' ] );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
	}

	public function plugin_row_meta( array $links, string $file ): array {
		if ( strpos( $file, 'sermon-library.php' ) !== false ) {
			$links[] = '<a href="https://seedcast.ai/docs/" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'seedcast-sermon-library' ) . '</a>';
			$links[] = '<a href="https://seedcast.ai/sermon-library/" target="_blank" rel="noopener">' . esc_html__( 'Seedcast AI Engine', 'seedcast-sermon-library' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Register this plugin's section on the shared settings page.
	 *
	 * Guarded on the section API existing. If an older core copy bundled by
	 * another Seedcast plugin happens to win version negotiation, the section
	 * is simply absent rather than fatal.
	 *
	 * @param \Seedcast\Core\Admin\Settings $settings Shared settings page.
	 */
	public function register_section( $settings ): void {
		if ( ! is_object( $settings ) || ! method_exists( $settings, 'add_section' ) ) return;

		$settings->add_section(
			self::SECTION,
			__( 'Sermon Library', 'seedcast-sermon-library' ),
			[ $this, 'render' ],
			'scsl_settings',
			10
		);

	}

	/**
	 * Show level podcast settings, rendered as a card inside this plugin's
	 * settings section.
	 *
	 * These describe the show, not an episode. Apple and Spotify read them
	 * from the channel when the feed is submitted, and a couple of them are
	 * required rather than nice to have: without an owner email Apple will not
	 * accept the feed at all, and artwork below 1400px square is rejected.
	 *
	 * Everything falls back to the site's own title, tagline and icon, so a
	 * church that fills in nothing still gets a valid feed.
	 */
	private function render_podcast(): void {

		$artwork_id  = absint( get_option( 'scsl_podcast_artwork', 0 ) );
		$artwork_url = $artwork_id ? wp_get_attachment_image_url( $artwork_id, 'medium' ) : '';
		$category    = get_option( 'scsl_podcast_category', 'Religion & Spirituality > Christianity' );

		$categories = [
			'Religion & Spirituality > Christianity' => __( 'Christianity', 'seedcast-sermon-library' ),
			'Religion & Spirituality > Spirituality' => __( 'Spirituality', 'seedcast-sermon-library' ),
			'Religion & Spirituality > Religion'     => __( 'Religion', 'seedcast-sermon-library' ),
			'Religion & Spirituality > Judaism'      => __( 'Judaism', 'seedcast-sermon-library' ),
			'Education > Courses'                    => __( 'Education: Courses', 'seedcast-sermon-library' ),
		];
		?>
		<div class="sc-settings-card">
			<h2><?php esc_html_e( 'Show details', 'seedcast-sermon-library' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s is the audio feed URL. */
					esc_html__( 'These describe the show itself, and are what Apple, Spotify and other listening apps read when you submit your feed at %s. Anything left blank falls back to your site title, tagline and site icon.', 'seedcast-sermon-library' ),
					'<code>' . esc_url( get_feed_link( 'podcast' ) ) . '</code>'
				);
				?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Cover art', 'seedcast-sermon-library' ); ?></th>
					<td>
						<div class="sc-media-field">
							<input type="hidden" id="scsl_podcast_artwork" name="scsl_podcast_artwork" value="<?php echo esc_attr( $artwork_id ); ?>" />
							<button type="button" class="button scsl-artwork-select" data-target="scsl_podcast_artwork" data-title="<?php esc_attr_e( 'Cover art', 'seedcast-sermon-library' ); ?>"><?php esc_html_e( 'Choose image', 'seedcast-sermon-library' ); ?></button>
							<button type="button" class="button scsl-artwork-clear" data-target="scsl_podcast_artwork" <?php echo $artwork_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Clear', 'seedcast-sermon-library' ); ?></button>
						</div>
						<?php if ( $artwork_url ) : ?>
							<img src="<?php echo esc_url( $artwork_url ); ?>" alt="" class="scsl-artwork-preview" style="max-width:180px;margin-top:.75rem;display:block;" />
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Square, at least 1400x1400px. JPG or PNG. Apple rejects anything smaller.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_title"><?php esc_html_e( 'Show title', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="scsl_podcast_title" name="scsl_podcast_title" value="<?php echo esc_attr( get_option( 'scsl_podcast_title', '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) . ' Sermons' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_description"><?php esc_html_e( 'Description', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="4" id="scsl_podcast_description" name="scsl_podcast_description" placeholder="<?php esc_attr_e( 'What your show is about.', 'seedcast-sermon-library' ); ?>"><?php echo esc_textarea( get_option( 'scsl_podcast_description', '' ) ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_author"><?php esc_html_e( 'Author / church name', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="text" class="regular-text" id="scsl_podcast_author" name="scsl_podcast_author" value="<?php echo esc_attr( get_option( 'scsl_podcast_author', '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_owner_email"><?php esc_html_e( 'Owner email', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" id="scsl_podcast_owner_email" name="scsl_podcast_owner_email" value="<?php echo esc_attr( get_option( 'scsl_podcast_owner_email', '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Apple sends the ownership verification email here. It is not shown publicly in most players, but the feed does contain it.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_subtitle"><?php esc_html_e( 'Subtitle', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="scsl_podcast_subtitle" name="scsl_podcast_subtitle" value="<?php echo esc_attr( get_option( 'scsl_podcast_subtitle', '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'One line telling a listener what to expect. Shown under the show title in most apps.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_copyright"><?php esc_html_e( 'Copyright', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="text" class="regular-text" id="scsl_podcast_copyright" name="scsl_podcast_copyright" value="<?php echo esc_attr( get_option( 'scsl_podcast_copyright', '' ) ); ?>" placeholder="<?php echo esc_attr( '&copy; ' . gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_language"><?php esc_html_e( 'Language', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="text" class="small-text" id="scsl_podcast_language" name="scsl_podcast_language" value="<?php echo esc_attr( get_option( 'scsl_podcast_language', 'en-us' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_category"><?php esc_html_e( 'Category', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<select id="scsl_podcast_category" name="scsl_podcast_category">
							<?php foreach ( $categories as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $category, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_website"><?php esc_html_e( 'Show website', 'seedcast-sermon-library' ); ?></label></th>
					<td><input type="url" class="regular-text" id="scsl_podcast_website" name="scsl_podcast_website" value="<?php echo esc_attr( get_option( 'scsl_podcast_website', '' ) ); ?>" placeholder="<?php echo esc_attr( home_url() ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed URL', 'seedcast-sermon-library' ); ?></th>
					<td>
						<code><?php echo esc_url( get_feed_link( 'podcast' ) ); ?></code>
						<p class="description">
							<?php esc_html_e( 'Submit this to Apple Podcasts, Spotify and the rest. Append', 'seedcast-sermon-library' ); ?>
							<code>?series=ID</code>, <code>?speaker=ID</code> <?php esc_html_e( 'or', 'seedcast-sermon-library' ); ?> <code>?topic=slug</code>
							<?php esc_html_e( 'for a filtered feed.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_include_series"><?php esc_html_e( 'Include series in episode titles', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="scsl_podcast_include_series" name="scsl_podcast_include_series"
								value="yes" <?php checked( get_option( 'scsl_podcast_include_series', 'yes' ), 'yes' ); ?> />
							<?php esc_html_e( 'Append the series name to each episode title in the feed', 'seedcast-sermon-library' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_items"><?php esc_html_e( 'Episodes in the feed', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="number" min="1" max="500" class="small-text" id="scsl_podcast_items" name="scsl_podcast_items" value="<?php echo esc_attr( get_option( 'scsl_podcast_items' ) ?: '50' ); ?>" />
						<p class="description"><?php esc_html_e( 'How many of the most recent sermons the feed contains. Apps fetch the whole file every time they refresh, so a church with hundreds of sermons should keep this modest.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scsl_podcast_redirect"><?php esc_html_e( 'Moved to a new feed', 'seedcast-sermon-library' ); ?></label></th>
					<td>
						<input type="url" class="regular-text" id="scsl_podcast_redirect" name="scsl_podcast_redirect" value="<?php echo esc_attr( get_option( 'scsl_podcast_redirect', '' ) ); ?>" placeholder="https://" />
						<p class="description">
							<?php esc_html_e( 'Leave this blank unless you are moving your show somewhere else. Enter the new feed URL and directories will follow it, taking your subscribers with them. Keep this feed online for a couple of weeks afterwards so every app has time to notice.', 'seedcast-sermon-library' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Explicit', 'seedcast-sermon-library' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="scsl_podcast_explicit" value="yes" <?php checked( get_option( 'scsl_podcast_explicit', 'no' ), 'yes' ); ?> />
							<?php esc_html_e( 'Mark this show as explicit', 'seedcast-sermon-library' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * A Settings entry under the Sermon Library menu that links to the shared
	 * page, opened on this plugin's section. People look for settings where
	 * they work, not where they were filed. Passing a full relative URL as the
	 * menu slug produces a link rather than a page, which is exactly what is
	 * wanted here.
	 */
	public function add_menu_link(): void {
		if ( ! class_exists( '\\Seedcast\\Core\\Admin\\Settings' ) ) return;

		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Settings', 'seedcast-sermon-library' ),
			__( 'Settings', 'seedcast-sermon-library' ),
			'manage_options',
			\Seedcast\Core\Admin\Settings::menu_link( self::SECTION )
		);
	}

	/**
	 * Settings link on the Plugins screen, pointing at the same place.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_link( array $links ): array {
		if ( ! class_exists( '\\Seedcast\\Core\\Admin\\Settings' ) ) return $links;

		array_unshift(
			$links,
			'<a href="' . esc_url( \Seedcast\Core\Admin\Settings::url( self::SECTION ) ) . '">'
				. esc_html__( 'Settings', 'seedcast-sermon-library' ) . '</a>'
		);
		return $links;
	}

	public function register_settings(): void {
		$options = [
			
			'scsl_series_slug', 'scsl_sermon_slug', 'scsl_speaker_slug',
			'scsl_bible_provider', 'scsl_bible_translation', 'scsl_show_scripture_panel',
			'scsl_sermons_per_page',
			'scsl_filter_threshold', 'scsl_series_per_page',
			'scsl_tab_more_label',
			'scsl_auto_clean_transcript',
			'scsl_podcast_include_series',
			'scsl_podcast_title',
			'scsl_podcast_description',
			'scsl_podcast_author',
			'scsl_podcast_owner_email',
			'scsl_podcast_language',
			'scsl_podcast_category',
			'scsl_podcast_website',
			'scsl_podcast_explicit',
			'scsl_podcast_artwork',
			'scsl_podcast_copyright',
			'scsl_podcast_subtitle',
			'scsl_podcast_items',
			'scsl_podcast_redirect',
			'scsl_default_image',
			'scsl_image_fallback_series',
			'scsl_disable_css',
			'scsl_label_sermon', 'scsl_label_sermons', 'scsl_label_series', 'scsl_label_speaker', 'scsl_label_speakers',
			'scsl_show_topics_on_series',
			'scsl_show_search_filter',
			'scsl_show_year_filter',
			'scsl_show_completeness',
		];
		foreach ( $options as $option ) {
			register_setting( 'scsl_settings', $option, [
				'sanitize_callback' => 'sanitize_text_field',
			] );
		}

		// A list rather than a string, so it needs its own sanitiser. Passing
		// it through the text one above would turn the whole choice into the
		// word "Array".
		register_setting( 'scsl_settings', 'scsl_sections_known', [
			'type'    => 'array',
			'default' => [],
		] );

		register_setting( 'scsl_settings', 'scsl_sections_in_use', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_sections' ],
			'default'           => [],
		] );
	}

	/**
	 * Keep only real section keys, and remember an empty choice as empty.
	 *
	 * An unticked-everything answer is a legitimate one, so it is stored as an
	 * empty list rather than falling back to the default of everything.
	 *
	 * @param mixed $value Submitted value.
	 * @return array
	 */
	public function sanitize_sections( $value ): array {
		$known = array_map( 'sanitize_key', array_keys( \SeedcastSermonLibrary\Import\FieldMap::sections() ) );

		// What was on offer at the moment this was saved, so a section added
		// later is not mistaken for one somebody deliberately unticked.
		update_option( 'scsl_sections_known', $known, false );

		if ( ! is_array( $value ) ) return [];

		return array_values( array_intersect( array_map( 'sanitize_key', $value ), $known ) );
	}

	/**
	 * Render this plugin's fields. No form tag, no heading and no submit
	 * button; the shared settings page supplies all three around each section.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		?>
				<!-- ── Display ── -->
				<div class="sc-settings-card">
					<h2><?php esc_html_e( 'Display', 'seedcast-sermon-library' ); ?></h2>
					<table class="form-table">
						<tr>
							<tr>
							<th><label for="scsl_filter_threshold"><?php esc_html_e( 'Show Filters After', 'seedcast-sermon-library' ); ?></label></th>
							<td>
								<input type="number" id="scsl_filter_threshold" name="scsl_filter_threshold"
									   value="<?php echo esc_attr( get_option( 'scsl_filter_threshold', 0 ) ); ?>"
									   min="0" max="100" style="width:80px;" />
								<span class="description">&nbsp;<?php esc_html_e( 'sermons (0 = always show filters)', 'seedcast-sermon-library' ); ?></span>
							</td>
						</tr>
						<tr>
							<th><label for="scsl_sermons_per_page"><?php esc_html_e( 'Sermons per page', 'seedcast-sermon-library' ); ?></label></th>
							<td>
								<input type="number" id="scsl_sermons_per_page" name="scsl_sermons_per_page"
									   value="<?php echo esc_attr( get_option( 'scsl_sermons_per_page', '10' ) ); ?>"
									   min="1" max="100" class="small-text" />
								<p class="description"><?php esc_html_e( 'Default number of sermons shown per page. Can be overridden per shortcode with per_page="X".', 'seedcast-sermon-library' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="scsl_series_per_page"><?php esc_html_e( 'Series per page', 'seedcast-sermon-library' ); ?></label></th>
							<td>
								<input type="number" id="scsl_series_per_page" name="scsl_series_per_page"
									   value="<?php echo esc_attr( get_option( 'scsl_series_per_page', '12' ) ); ?>"
									   min="1" max="100" class="small-text" />
								<p class="description"><?php esc_html_e( 'Number of series shown per page on the series grid. Used when limit="0" is set on the shortcode.', 'seedcast-sermon-library' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Completeness', 'seedcast-sermon-library' ); ?></th>
							<td>
								<label>
									<?php
									/*
									 * options.php stores an empty string for any
									 * checkbox that is not posted, and an empty
									 * string is not '0', so a box that defaults
									 * to on would read as on again the moment it
									 * was switched off. The hidden field posts an
									 * explicit off so the stored value is always
									 * one of two things.
									 */
									?>
									<input type="hidden" name="scsl_show_completeness" value="0" />
									<input type="checkbox" name="scsl_show_completeness" value="1"
										   <?php checked( '0' !== (string) get_option( 'scsl_show_completeness', '1' ) ); ?> />
									<?php esc_html_e( 'Show completeness scores', 'seedcast-sermon-library' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Adds a score to the sermon list and editor, and a Completeness report under Sermons. Turn it off if you only publish recordings and the labels are noise.', 'seedcast-sermon-library' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Weekly scores keep being recorded either way. They cannot be worked out after the fact, so switching the display off does not leave a gap in the record if you switch it back on later.', 'seedcast-sermon-library' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Transcripts', 'seedcast-sermon-library' ); ?></th>
							<td>
								<label>
									<?php // Same explicit off as above. ?>
									<input type="hidden" name="scsl_auto_clean_transcript" value="0" />
									<input type="checkbox" name="scsl_auto_clean_transcript" value="1"
										   <?php checked( '0' !== (string) get_option( 'scsl_auto_clean_transcript', '1' ) ); ?> />
									<?php esc_html_e( 'Tidy transcripts automatically', 'seedcast-sermon-library' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'A recording is transcribed word for word, timestamps and stumbles included. With this on, the readable version is written in the same run, so the Transcript tab is ready without a second pass.', 'seedcast-sermon-library' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Content you use', 'seedcast-sermon-library' ); ?></th>
							<td>
								<?php
								$scsl_in_use = \SeedcastSermonLibrary\Import\FieldMap::in_use();

								foreach ( \SeedcastSermonLibrary\Import\FieldMap::sections() as $scsl_key => $scsl_label ) :
									?>
									<label style="display:block;margin-bottom:.35rem;">
										<input type="checkbox" name="scsl_sections_in_use[]"
											   value="<?php echo esc_attr( (string) $scsl_key ); ?>"
											   <?php checked( isset( $scsl_in_use[ $scsl_key ] ) ); ?> />
										<?php echo esc_html( (string) $scsl_label ); ?>
									</label>
								<?php endforeach; ?>

								<p class="description" style="margin-top:.6rem;">
									<?php esc_html_e( 'Untick anything your church does not use. Those sections stop appearing on your sermon pages and stop being counted as missing.', 'seedcast-sermon-library' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Files uploaded under Sermon Notes are unaffected and still appear as downloads, so a study guide can be a handout without the section being switched on.', 'seedcast-sermon-library' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><label for="scsl_tab_more_label"><?php esc_html_e( '"More" tab label', 'seedcast-sermon-library' ); ?></label></th>
							<td>
								<input type="text" id="scsl_tab_more_label" name="scsl_tab_more_label"
									   value="<?php echo esc_attr( get_option( 'scsl_tab_more_label', 'More' ) ); ?>"
									   class="regular-text" placeholder="More" />
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Show topics on series page', 'seedcast-sermon-library' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="scsl_show_topics_on_series" value="1"
										   <?php checked( get_option( 'scsl_show_topics_on_series', '1' ), '1' ); ?> />
									<?php esc_html_e( 'Show topic tags in the series hero', 'seedcast-sermon-library' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Show search in filter bar', 'seedcast-sermon-library' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="scsl_show_search_filter" value="1"
										   <?php checked( get_option( 'scsl_show_search_filter', '1' ), '1' ); ?> />
									<?php esc_html_e( 'Show keyword search field in sermon list filter bars', 'seedcast-sermon-library' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Can also be overridden per shortcode with show_search="false".', 'seedcast-sermon-library' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Show year filter', 'seedcast-sermon-library' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="scsl_show_year_filter" value="1"
										   <?php checked( get_option( 'scsl_show_year_filter', '1' ), '1' ); ?> />
									<?php esc_html_e( 'Show year dropdown in sermon list filter bars (hidden automatically when all sermons are in one year)', 'seedcast-sermon-library' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Can also be overridden per shortcode with show_year="false".', 'seedcast-sermon-library' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

		<div class="sc-settings-card">
			<h2><?php esc_html_e( 'Images', 'seedcast-sermon-library' ); ?></h2>
			<?php
			$scsl_default_id  = absint( get_option( 'scsl_default_image', 0 ) );
			$scsl_default_url = $scsl_default_id ? wp_get_attachment_image_url( $scsl_default_id, 'medium' ) : '';
			?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Fall back to the series image', 'seedcast-sermon-library' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="scsl_image_fallback_series" value="1" <?php checked( get_option( 'scsl_image_fallback_series', '1' ), '1' ); ?> />
							<?php esc_html_e( 'When a sermon has no image of its own, use its series artwork', 'seedcast-sermon-library' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Most churches design artwork per series rather than per sermon, so this is on by default.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default image', 'seedcast-sermon-library' ); ?></th>
					<td>
						<div class="sc-media-field">
							<input type="hidden" id="scsl_default_image" name="scsl_default_image" value="<?php echo esc_attr( $scsl_default_id ); ?>" />
							<button type="button" class="button scsl-image-select" data-target="scsl_default_image" data-title="<?php esc_attr_e( 'Default sermon image', 'seedcast-sermon-library' ); ?>"><?php esc_html_e( 'Choose image', 'seedcast-sermon-library' ); ?></button>
							<button type="button" class="button scsl-image-clear" data-target="scsl_default_image" <?php echo $scsl_default_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Clear', 'seedcast-sermon-library' ); ?></button>
						</div>
						<?php if ( $scsl_default_url ) : ?>
							<img src="<?php echo esc_url( $scsl_default_url ); ?>" alt="" class="scsl-image-preview" style="max-width:180px;margin-top:.75rem;display:block;" />
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Used when neither the sermon nor its series has one. Leave empty to show no image at all.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="sc-settings-card">
			<h2><?php esc_html_e( 'Styles', 'seedcast-sermon-library' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin styles', 'seedcast-sermon-library' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="scsl_disable_css" value="1" <?php checked( get_option( 'scsl_disable_css' ), '1' ); ?> />
							<?php esc_html_e( 'Do not load any of this plugin\'s front-end CSS', 'seedcast-sermon-library' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'For designers who would rather style everything themselves. Cleaner than overriding rule by rule, and the markup and class names stay exactly the same.', 'seedcast-sermon-library' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

				<?php $this->render_podcast(); ?>

				<!-- ── Custom Labels ── -->
				<div class="sc-settings-card">
					<h2><?php esc_html_e( 'Custom Labels', 'seedcast-sermon-library' ); ?></h2>
					<p class="description" style="margin-bottom:1rem;"><?php esc_html_e( 'Rename content types to match your church\'s language. Leave blank to use the defaults.', 'seedcast-sermon-library' ); ?></p>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Sermon (singular)', 'seedcast-sermon-library' ); ?></th>
							<td><input type="text" name="scsl_label_sermon" value="<?php echo esc_attr( get_option( 'scsl_label_sermon', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Sermon', 'seedcast-sermon-library' ); ?>" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Sermons (plural)', 'seedcast-sermon-library' ); ?></th>
							<td><input type="text" name="scsl_label_sermons" value="<?php echo esc_attr( get_option( 'scsl_label_sermons', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Sermons', 'seedcast-sermon-library' ); ?>" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Series', 'seedcast-sermon-library' ); ?></th>
							<td><input type="text" name="scsl_label_series" value="<?php echo esc_attr( get_option( 'scsl_label_series', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Series', 'seedcast-sermon-library' ); ?>" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Speaker (singular)', 'seedcast-sermon-library' ); ?></th>
							<td><input type="text" name="scsl_label_speaker" value="<?php echo esc_attr( get_option( 'scsl_label_speaker', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Speaker', 'seedcast-sermon-library' ); ?>" /></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Speakers (plural)', 'seedcast-sermon-library' ); ?></th>
							<td><input type="text" name="scsl_label_speakers" value="<?php echo esc_attr( get_option( 'scsl_label_speakers', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Speakers', 'seedcast-sermon-library' ); ?>" /></td>
						</tr>
					</table>
					<p class="description"><?php esc_html_e( 'Examples: Sermon → Message, Sermons → Messages, Series → Teaching Series, Speaker → Teacher.', 'seedcast-sermon-library' ); ?></p>
				</div>

				<!-- ── URL Slugs ── -->
				<div class="sc-settings-card">
					<h2><?php esc_html_e( 'URL Slugs', 'seedcast-sermon-library' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Change before publishing. Changing after will break existing URLs.', 'seedcast-sermon-library' ); ?></p>
					<table class="form-table">
						<tr>
							<th><label for="scsl_series_slug"><?php esc_html_e( 'Series Slug', 'seedcast-sermon-library' ); ?></label></th>
							<td><input type="text" id="scsl_series_slug" name="scsl_series_slug" value="<?php echo esc_attr( get_option( 'scsl_series_slug', 'series' ) ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="scsl_sermon_slug"><?php esc_html_e( 'Sermon Slug', 'seedcast-sermon-library' ); ?></label></th>
							<td><input type="text" id="scsl_sermon_slug" name="scsl_sermon_slug" value="<?php echo esc_attr( get_option( 'scsl_sermon_slug', 'sermon' ) ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="scsl_speaker_slug"><?php esc_html_e( 'Speaker Slug', 'seedcast-sermon-library' ); ?></label></th>
							<td><input type="text" id="scsl_speaker_slug" name="scsl_speaker_slug" value="<?php echo esc_attr( get_option( 'scsl_speaker_slug', 'speakers' ) ); ?>" class="regular-text" /></td>
						</tr>
					</table>
				</div>

				<!-- ── Scripture ── -->
				<div class="sc-settings-card">
					<h2><?php esc_html_e( 'Scripture', 'seedcast-sermon-library' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><label for="scsl_show_scripture_panel"><?php esc_html_e( 'Scripture Panel', 'seedcast-sermon-library' ); ?></label></th>
							<td><label><input type="checkbox" id="scsl_show_scripture_panel" name="scsl_show_scripture_panel" value="1" <?php checked( get_option( 'scsl_show_scripture_panel', '1' ), '1' ); ?> /> <?php esc_html_e( 'Show scripture references on sermon pages', 'seedcast-sermon-library' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="scsl_bible_provider"><?php esc_html_e( 'Bible Link Provider', 'seedcast-sermon-library' ); ?></label></th>
							<td>
								<select id="scsl_bible_provider" name="scsl_bible_provider">
									<option value="bible.com"    <?php selected( get_option( 'scsl_bible_provider', 'bible.com' ), 'bible.com'    ); ?>><?php esc_html_e( 'Bible.com (YouVersion)', 'seedcast-sermon-library' ); ?></option>
									<option value="biblegateway" <?php selected( get_option( 'scsl_bible_provider', 'bible.com' ), 'biblegateway' ); ?>><?php esc_html_e( 'BibleGateway',           'seedcast-sermon-library' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="scsl_bible_translation"><?php esc_html_e( 'Default Translation', 'seedcast-sermon-library' ); ?></label></th>
							<td>
								<select id="scsl_bible_translation" name="scsl_bible_translation">
									<?php $cur = get_option( 'scsl_bible_translation', 'NIV' );
									foreach ( [ 'NIV', 'ESV', 'NLT', 'KJV', 'NASB', 'CSB', 'MSG' ] as $t ) : ?>
										<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $cur, $t ); ?>><?php echo esc_html( $t ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
				</div>

		<?php
	}
}
