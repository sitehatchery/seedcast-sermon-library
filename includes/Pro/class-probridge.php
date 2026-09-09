<?php
/**
 * Seedcast AI Engine bridge.
 *
 * Sermon Library owns the Generate Sermon menu item and always shows it, even
 * with no Pro plugin and no key. Without Pro the page explains what generation
 * does and links to seedcast.ai; with Pro and a covering key, Pro filters in
 * the real form.
 *
 * The split is deliberate. The menu registration and the pitch change roughly
 * never, so they belong in the plugin that updates through WordPress.org. The
 * API client behind them changes whenever the API does, so it belongs in Pro,
 * which updates on Seedcast's own channel and only reaches the churches paying
 * for it.
 *
 * This file also declares the sermon product to Pro. The declaration carries
 * the field map, which is the single source of truth for where a generated
 * field lands. Both Pro and this plugin's own manifest zip importer read it,
 * so the two import paths cannot drift apart.
 *
 * @package SeedcastSermonLibrary\Pro
 */

namespace SeedcastSermonLibrary\Pro;

use SeedcastSermonLibrary\Import\FieldMap;
use SeedcastSermonLibrary\Topics\Assignment;
use SeedcastSermonLibrary\Scripture\ScriptureParser;
use SeedcastSermonLibrary\Admin\SermonMeta;

if ( ! defined( 'ABSPATH' ) ) exit;

class ProBridge {

	/**
	 * The API product slug this plugin maps to.
	 */
	const PRODUCT = 'sermon';

	/**
	 * The post type this product writes to.
	 */
	const POST_TYPE = 'scsl_sermon';

	/**
	 * The top level menu this plugin's screens live under.
	 */
	const MENU = 'seedcast-sermon-library';

	/**
	 * Submenu page slug.
	 */
	const PAGE = 'seedcast-sermon-library-generate';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'seedcast_pro_products', [ $this, 'declare_product' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ], 15 );
		add_action( 'admin_menu', [ $this, 'position_menu' ], 999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );

		// Pro calls these while rendering and submitting its form. They cost
		// nothing when Pro is absent, because nothing fires them.
		add_action( 'seedcast_pro_fields_' . self::PRODUCT, [ $this, 'render_fields' ] );
		add_filter( 'seedcast_pro_context_' . self::PRODUCT, [ $this, 'collect_context' ] );
		add_action( 'seedcast_pro_draft_created_' . self::PRODUCT, [ $this, 'seed_draft' ] );
		add_filter( 'seedcast_pro_existing_context_' . self::PRODUCT, [ $this, 'existing_context' ], 10, 2 );
		add_action( 'seedcast_pro_imported_' . self::PRODUCT, [ $this, 'import_passages' ], 10, 2 );

		/*
		 * Its own handler, and later, on purpose.
		 *
		 * import_passages() stops as soon as the answer carries no scripture,
		 * which is exactly the case where reading the title is worth doing.
		 * Running after it also means anything the passages did fill is
		 * already there to be found.
		 */
		add_action( 'seedcast_pro_imported_' . self::PRODUCT, [ $this, 'focus_from_title' ], 20, 1 );
		add_filter( 'seedcast_pro_staged_' . self::PRODUCT, [ $this, 'stage_article_title' ], 10, 2 );
		// Late, so the article body has already been saved by its metabox.
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'derive_article_title' ], 100, 2 );

		// Generated content is written straight to post meta and never passes
		// through a form, so save_post does not fire and the derived title
		// would stay empty until somebody happened to press Update.
		add_action( 'seedcast_pro_imported_' . self::PRODUCT, [ $this, 'derive_after_import' ] );

		// Content held for review has not touched the sermon yet, so nothing
		// has run to work the article title out. Deriving it here means it
		// arrives in the field with everything else rather than appearing
		// later, after a save nobody connected it to.
		add_filter( 'seedcast_pro_stage_fields_' . self::PRODUCT, [ $this, 'stage_article_title' ], 10, 2 );
	}

	/**
	 * Load the admin stylesheet on the Generate screen.
	 *
	 * Pro loads its own stylesheet when it takes the page over, but the upsell
	 * is rendered by this plugin and nothing else on that screen would pull
	 * admin.css in.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) return;

		wp_enqueue_style( 'scsl-admin', SCSL_PLUGIN_URL . 'assets/css/admin.css', [], scsl_asset_version( 'assets/css/admin.css' ) );

		/*
		 * The passage picker's own behaviour.
		 *
		 * It is the sermon screen's control, and what makes it more than four
		 * boxes -- the end chapter appearing when a passage needs one, the
		 * reference building itself, the preview -- lives in this script. The
		 * handlers are bound to the document, so they work wherever the markup
		 * is; the script simply has to be on the page.
		 */
		wp_enqueue_script( 'scsl-admin', SCSL_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], scsl_asset_version( 'assets/js/admin.js' ), true );
	}

	/**
	 * Admin URL of the Generate Sermon screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Tell Pro about the sermon product.
	 *
	 * @param array $products Products declared so far.
	 * @return array
	 */
	public function declare_product( $products ) {
		if ( ! is_array( $products ) ) $products = [];

		$products[ self::PRODUCT ] = [
			'label'       => __( 'Sermon', 'seedcast-sermon-library' ),
			'post_type'   => self::POST_TYPE,
			'page_slug'   => self::PAGE,

			// Where Pro should hang any screens of its own, so they appear
			// beside this plugin's rather than somewhere unrelated.
			'admin_menu'  => self::MENU,

			// Named on API calls so entitlement can be checked per plugin.
			'plugin_slug' => 'seedcast-sermon-library',
			'purchase_url' => 'https://seedcast.ai/sermon-library/',
			'intro'       => __( 'Turn a sermon recording into a summary, study guide, article, and a clean transcript. Everything lands on a draft sermon for you to review.', 'seedcast-sermon-library' ),
			'field_map'   => FieldMap::api_to_meta(),

			/*
			 * Fields that belong to another field rather than standing alone.
			 *
			 * The article's title is its first heading, worked out from the
			 * body and rewritten from it on every save. So it is not offered
			 * as something to generate, and it is not named as something that
			 * was written -- but it does have to travel with the article. Put
			 * the old article back on its own and the title left behind is a
			 * heading for a version that is no longer there.
			 */
			'derived'     => [
				'_scsl_article_title' => '_scsl_article_body',
			],
			'plain_text'  => FieldMap::plain_text_meta(),

			/*
			 * Fields where a named part of the response is the value.
			 *
			 * Each entry says which key to take instead of the rendered text:
			 * faqs arrives with both a `value` of HTML and the `pairs` it was
			 * built from, and this plugin stores the pairs. Pro should write
			 * whatever `source` names, unchanged, and fall back to `value` if
			 * that key is absent so an older API cannot leave the field empty.
			 */
			'structured'  => FieldMap::structured(),

			/*
			 * Fields that become terms rather than content.
			 *
			 * `choices` is the whole of what may be picked, and it travels with
			 * the request so the model chooses from this church's vocabulary
			 * instead of inventing one. `max` is the most that may come back and
			 * fewer is a valid answer, including none: a sermon about one thing
			 * filed under three topics has two topics that are not true.
			 *
			 * Whatever comes back is intersected against `choices` at this end
			 * before anything is written, so an invented topic cannot land even
			 * if one is returned.
			 */
			'taxonomy'    => [
				'topics' => [
					'taxonomy' => 'scsl_topic',
					'source'   => 'terms',
					'max'      => Assignment::MAX,
					'closed'   => true,
					'choices'  => Assignment::choices(),
				],
			],
			/*
			 * Writing that belongs to a page rather than to a sermon.
			 *
			 * A passage page can say what its set of sermons adds up to, which
			 * is writing this site does not otherwise have: the sermons each
			 * speak for themselves and nothing says what they come to
			 * together. It is named here rather than hooked over there so the
			 * engine goes on knowing nothing about this plugin's hooks.
			 *
			 * Five arguments, because the filter passes the summary already on
			 * the page alongside the term, the sermons and the shape of the
			 * set.
			 */
			'prose'       => [
				'scripture_summary' => [
					'filter' => 'scsl_scripture_summary_generate',
					'kind'   => 'scripture',
					'args'   => 5,
				],
			],
			'append'      => [
				'meta'   => '_scsl_resources',
				'fields' => FieldMap::appended_fields(),
			],
			'regenerable' => FieldMap::regenerable(),
			'labels'      => FieldMap::labels(),
			'audio_meta'  => '_scsl_audio_url',
			// The video link a sermon already carries. Most churches with an
			// archive have this and nothing else: the video went to YouTube
			// and the recording came off the laptop years ago.
			'video_meta'  => '_scsl_video_url',

			// Where this plugin keeps the text a generation can be written
			// from. Declared rather than assumed, so Pro never needs to know
			// what a sermon's fields are called.
			'transcript_meta' => '_scsl_transcript_clean',

			// Whether a transcription should be tidied as part of the same run
			// rather than left for somebody to ask for separately.
			'auto_clean'  => '0' !== (string) get_option( 'scsl_auto_clean_transcript', '1' ),
			'clean_field' => 'cleaned_transcript',

			// Content that does not live in a box somebody types into, so it
			// cannot be one of the fields above. Offered as a choice all the
			// same, because a church that wants the passages listed should be
			// able to ask for them.
			'extras'      => [
				'scripture' => [
					'label' => __( 'Scripture references', 'seedcast-sermon-library' ),
					'meta'  => '_scsl_other_passages',
				],
			],

			// The sections this church uses. Anything left out is not written
			// automatically and is not offered as ticked.
			'sections_in_use' => array_keys( FieldMap::in_use() ),

			// How this plugin's audio feed decides what belongs in it, and
			// which field describes an episode.
			'podcast'     => [
				'exclude_meta'  => '_scsl_podcast_exclude',
				'exclude_value' => 'yes',
				'summary_meta'  => '_scsl_content_description',
				'screen'        => \SeedcastSermonLibrary\Admin\PodcastScreen::PAGE,
			],

			// Which form control holds each field on the Edit Sermon screen,
			// so generated content can be loaded into the editor and left for
			// the person to save, rather than written to the database behind
			// them. Input names are the meta key without its leading
			// underscore; three of them are rich editors.
			'inputs'      => [
				'_scsl_content_description' => [ 'name' => 'scsl_content_description', 'type' => 'textarea' ],
				'_scsl_transcript_clean'    => [ 'name' => 'scsl_transcript_clean',    'type' => 'textarea' ],
				'_scsl_article_title'       => [ 'name' => 'scsl_article_title',       'type' => 'text' ],
				'_scsl_article_body'        => [ 'name' => 'scsl_article_body',        'type' => 'editor' ],
				'_scsl_bible_study'         => [ 'name' => 'scsl_bible_study',         'type' => 'editor' ],
				'_scsl_resources'           => [ 'name' => 'scsl_resources',           'type' => 'editor' ],
				// Questions arrive as a list rather than as text, so the control
				// named here is a hidden field that carries them as JSON. The
				// visible rows are built from it on the change event that filling
				// any control fires, so what lands on screen is the same editable
				// pairs a person would have typed.
				'_scsl_faq'                 => [ 'name' => 'scsl_faq',              'type' => 'textarea' ],
			],
			// What this screen actually produces, which is what the generate
			// request asks for. Listing anything else promises content that
			// never arrives.
			'outputs'     => [
				__( 'Description', 'seedcast-sermon-library' ),
				__( 'Article', 'seedcast-sermon-library' ),
				__( 'Bible Study', 'seedcast-sermon-library' ),
				__( 'Clean transcript', 'seedcast-sermon-library' ),
			],
		];

		return $products;
	}

	/**
	 * Register the Generate Sermon submenu.
	 *
	 * Always, regardless of whether Pro is installed. A church running only
	 * the free plugin should be able to see that generation exists.
	 *
	 * Priority 15 puts it after the post type submenus, so it does not
	 * displace Sermons as the page the top level menu opens, and ahead of
	 * Settings at 20 and Help at 999.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		/**
		 * The AI Engine swaps in the real generate form here when the site's
		 * API key covers sermon generation. Without it, the upsell renders.
		 *
		 * @param callable $renderer Default renderer.
		 * @param string   $product  Product slug.
		 */
		$renderer = apply_filters( 'seedcast_pro_renderer', [ $this, 'render_upsell' ], self::PRODUCT );

		if ( ! is_callable( $renderer ) ) {
			$renderer = [ $this, 'render_upsell' ];
		}

		/**
		 * Whether to offer the page at all.
		 *
		 * On for everybody. The page is worth reaching without the engine
		 * installed, because it is the one place that explains what generation
		 * does and where to get it. Filterable so a site can still remove it.
		 *
		 * @param bool $show Whether the menu item appears.
		 */
		if ( ! apply_filters( 'scsl_show_generate_page', true ) ) return;

		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Generate Sermon', 'seedcast-sermon-library' ),
			__( 'Generate Sermon', 'seedcast-sermon-library' ),
			'publish_posts',
			self::PAGE,
			$renderer
		);
	}

	/**
	 * Move Generate Sermon directly beneath Sermons.
	 *
	 * WordPress builds the submenu in registration order, and the post types
	 * all register together, so a priority alone cannot place this between
	 * Sermons and Series. The array has to be spliced instead, once every
	 * other plugin has finished adding to it.
	 *
	 * Purely cosmetic, so anything unexpected in the menu structure is left
	 * exactly as it is rather than risked.
	 *
	 * @return void
	 */
	public function position_menu(): void {
		global $submenu;

		$parent = 'seedcast-sermon-library';

		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) return;

		$items    = array_values( $submenu[ $parent ] );
		$generate = null;
		$sermons  = null;

		foreach ( $items as $index => $item ) {
			// Slot 2 is the menu slug.
			$slug = $item[2] ?? '';

			if ( self::PAGE === $slug ) {
				$generate = $index;
			} elseif ( 'edit.php?post_type=scsl_sermon' === $slug ) {
				$sermons = $index;
			}
		}

		if ( null === $generate || null === $sermons ) return;

		$moving = $items[ $generate ];
		unset( $items[ $generate ] );
		$items = array_values( $items );

		// Recomputed, because removing an earlier entry shifts everything.
		$after = array_search( 'edit.php?post_type=scsl_sermon', array_column( $items, 2 ), true );

		if ( false === $after ) return;

		array_splice( $items, $after + 1, 0, [ $moving ] );

		$submenu[ $parent ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering this plugin's own submenu.
	}


	/**
	 * What the screen shows without Pro, or without a key that covers sermons.
	 *
	 * @return void
	 */
	public function render_upsell(): void {
		$pro_active = defined( 'SCPRO_VERSION' );
		?>
		<div class="wrap scsl-upsell">
			<h1><?php esc_html_e( 'Generate Sermon', 'seedcast-sermon-library' ); ?></h1>

			<p class="scsl-upsell-intro">
				<?php esc_html_e( 'Seedcast AI Engine turns a sermon recording into written content and puts it straight onto a draft sermon here. Paste a YouTube link, upload the audio or video, choose one already in your media library, or hand it a transcript you already have.', 'seedcast-sermon-library' ); ?>
			</p>

			<div class="scsl-upsell-box">
				<h2><?php esc_html_e( 'What you get from one recording', 'seedcast-sermon-library' ); ?></h2>

				<ul class="scsl-upsell-list">
					<li><?php esc_html_e( 'A description for the sermon page and your podcast feed', 'seedcast-sermon-library' ); ?></li>
					<li><?php esc_html_e( 'An article, written to be read rather than listened to', 'seedcast-sermon-library' ); ?></li>
					<li><?php esc_html_e( 'A small group Bible study', 'seedcast-sermon-library' ); ?></li>
					<li><?php esc_html_e( 'A clean transcript, without the timestamps and stumbles', 'seedcast-sermon-library' ); ?></li>
					<li><?php esc_html_e( 'The passages the sermon actually covers', 'seedcast-sermon-library' ); ?></li>
				</ul>

				<p class="description">
					<?php esc_html_e( 'Everything arrives as a draft. Nothing is published until you review it, and any part can be rewritten afterwards without uploading the recording again. It works on sermons you already have, one at a time or a whole archive at once.', 'seedcast-sermon-library' ); ?>
				</p>

				<div class="scsl-upsell-actions">
					<?php if ( $pro_active ) : ?>
						<a class="button button-primary button-hero" href="<?php echo esc_url( \Seedcast\Core\Admin\Settings::url( 'scpro_license' ) ); ?>">
							<?php esc_html_e( 'Add your API key', 'seedcast-sermon-library' ); ?>
						</a>
						<span class="description">
							<?php esc_html_e( 'Seedcast AI Engine is installed. Enter a key that includes sermon generation to switch this page on.', 'seedcast-sermon-library' ); ?>
						</span>
					<?php else : ?>
						<a class="button button-primary button-hero" href="https://seedcast.ai/sermon-library/" target="_blank" rel="noopener">
							<?php esc_html_e( 'See what it does', 'seedcast-sermon-library' ); ?>
						</a>
						<span class="description">
							<?php esc_html_e( 'Everything else in Sermon Library stays free and works without it.', 'seedcast-sermon-library' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<h2><?php esc_html_e( 'Already have generated content?', 'seedcast-sermon-library' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Every sermon has a content importer on its edit screen that reads a Seedcast content zip. That is part of the free plugin and needs no key.', 'seedcast-sermon-library' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The sermon detail fields, rendered inside Pro's form.
	 *
	 * Outputs table rows only; Pro provides the table. Every input carries a
	 * data-scpro name, which is how Pro's script collects values without
	 * knowing anything about sermons.
	 *
	 * @return void
	 */
	public function render_fields(): void {
		$speakers = get_posts( [
			'post_type'      => 'scsl_speaker',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => [ 'publish', 'draft' ],
			'no_found_rows'  => true,
		] );
		?>
		<tr>
			<th scope="row"><label for="scsl-gen-title"><?php esc_html_e( 'Sermon title', 'seedcast-sermon-library' ); ?></label></th>
			<td>
				<input type="text" id="scsl-gen-title" class="regular-text" data-scpro="title" />
				<p class="description"><?php esc_html_e( 'Leave blank and a title is written for you.', 'seedcast-sermon-library' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Focus Passage', 'seedcast-sermon-library' ); ?></th>
			<td>
				<?php
				/*
				 * The same control the sermon screen uses, called the same
				 * thing.
				 *
				 * This screen had a simpler one of its own: it could not
				 * express a passage that crosses a chapter, and it called the
				 * field "Primary scripture" while the sermon screen called the
				 * very same field "Focus Passage". Two names and two behaviours
				 * for one thing is a difference somebody has to learn for no
				 * reason.
				 *
				 * The hidden field carries the assembled reference, and
				 * data-scpro is what the engine reads off this form.
				 */
				SermonMeta::passage_picker(
					'scsl_focus_passage',
					'',
					ScriptureParser::all_books(),
					[ 'data-scpro' => 'primary_scripture' ]
				);
				?>
				<p class="description"><?php esc_html_e( 'The verse range is optional.', 'seedcast-sermon-library' ); ?></p>
			</td>
		</tr>

		<tr class="scpro-not-youtube">
			<th scope="row"><label for="scsl-gen-video"><?php esc_html_e( 'Video', 'seedcast-sermon-library' ); ?></label></th>
			<td>
				<input type="url" id="scsl-gen-video" name="scsl_video_url" data-scpro="scsl_video_url" class="regular-text" placeholder="https://www.youtube.com/watch?v=..." />
				<p class="description"><?php esc_html_e( 'A YouTube or Vimeo address. Saved onto the sermon so the page can show it.', 'seedcast-sermon-library' ); ?></p>
			</td>
		</tr>

		<tr class="scpro-when-transcript">
			<th scope="row"><label for="scsl-gen-audio"><?php esc_html_e( 'Audio', 'seedcast-sermon-library' ); ?></label></th>
			<td>
				<input type="url" id="scsl-gen-audio" name="scsl_audio_url" data-scpro="scsl_audio_url" class="regular-text" placeholder="https://example.com/sermon.mp3" />
				<p class="description"><?php esc_html_e( 'A direct link to the recording. Worth adding even when pasting a transcript, since it is what the audio player and the feed use.', 'seedcast-sermon-library' ); ?></p>

			</td>
		</tr>

		<tr class="scpro-not-youtube">
			<th scope="row"><?php esc_html_e( 'Audio feed', 'seedcast-sermon-library' ); ?></th>
			<td>
				<label class="scpro-check">
					<input type="checkbox" id="scsl-gen-podcast" data-scpro="podcast_exclude" />
					<?php esc_html_e( 'Keep this one out of the audio feed', 'seedcast-sermon-library' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Sermons go into the feed once published. Tick this to hold one back.', 'seedcast-sermon-library' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="scsl-gen-series"><?php esc_html_e( 'Series', 'seedcast-sermon-library' ); ?></label></th>
			<td>
				<?php
				$series = get_posts( [
					'post_type'      => 'scsl_series',
					'posts_per_page' => 200,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'post_status'    => [ 'publish', 'draft' ],
					'no_found_rows'  => true,
				] );
				?>
				<select id="scsl-gen-series" data-scpro="series_id">
					<option value=""><?php esc_html_e( 'No series', 'seedcast-sermon-library' ); ?></option>
					<?php foreach ( $series as $item ) : ?>
						<option value="<?php echo absint( $item->ID ); ?>"><?php echo esc_html( $item->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Set now and the draft arrives already filed under the right series.', 'seedcast-sermon-library' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="scsl-gen-speaker"><?php esc_html_e( 'Speaker', 'seedcast-sermon-library' ); ?></label></th>
			<td>
				<input type="text" id="scsl-gen-speaker" class="regular-text" list="scsl-gen-speaker-list" data-scpro="speaker" />
				<datalist id="scsl-gen-speaker-list">
					<?php foreach ( $speakers as $speaker ) : ?>
						<option value="<?php echo esc_attr( $speaker->post_title ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<p class="description"><?php esc_html_e( 'Matching an existing speaker links the sermon to them automatically.', 'seedcast-sermon-library' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="scsl-gen-date">
					<?php esc_html_e( 'Date preached', 'seedcast-sermon-library' ); ?>
					<span class="scpro-required" aria-hidden="true">*</span>
				</label>
			</th>
			<td>
				<input type="date" id="scsl-gen-date" data-scpro="service_date" required />
				<p class="description">
					<?php
					/*
					 * Required, unlike the rest.
					 *
					 * The date is what ties a sermon to the rest of a Sunday.
					 * Everything else on this form describes the sermon; this
					 * is the one field other things look it up by, and a
					 * sermon without one cannot be found by the service it
					 * belonged to.
					 */
					esc_html_e( 'The Sunday this was preached. Other parts of the site find a sermon by its date, so this one is needed.', 'seedcast-sermon-library' );
					?>
				</p>
			</td>
		</tr>

		<?php
	}

	/**
	 * Turn the submitted fields into API context.
	 *
	 * The sermon product defines exactly three context fields. The date is not
	 * one of them: it is not a generation input, so it is stored locally and
	 * passed to the upload only to shape the storage key path.
	 *
	 * @param array $context Context so far.
	 * @return array
	 */
	public function collect_context( $context ) {
		if ( ! is_array( $context ) ) $context = [];

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Pro verifies its own nonce before running this filter.
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$speaker = isset( $_POST['speaker'] ) ? sanitize_text_field( wp_unslash( $_POST['speaker'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $title ) $context['title'] = $title;
		if ( '' !== $speaker ) $context['speaker'] = $speaker;

		$passage = $this->passage_from_request();
		if ( '' !== $passage ) $context['primary_scripture'] = $passage;

		return $context;
	}

	/**
	 * Assemble a scripture reference from the book, chapter and verse inputs.
	 *
	 * @return string Empty when no book was chosen.
	 */
	private function passage_from_request(): string {
		/*
		 * One assembled reference, not four loose parts.
		 *
		 * The picker on this screen is now the same one the sermon screen
		 * uses, and it hands over a finished reference such as "Ruth 3:3" or
		 * "Luke 6:37-7:2" in a single field. Rebuilding it here from a book, a
		 * chapter and two verses could not express a passage that crosses a
		 * chapter, which is exactly what the shared picker exists to allow.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Pro verifies its own nonce before this runs.
		$reference = isset( $_POST['primary_scripture'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_scripture'] ) ) : '';

		$reference = trim( $reference );

		if ( '' === $reference ) {
			return '';
		}

		/*
		 * Only a real book name, so a hand-edited request cannot put arbitrary
		 * text into the generation prompt. The rest of the reference is
		 * checked by shape rather than by meaning: this plugin does not know
		 * how many verses a chapter has, and refusing what it cannot verify
		 * would refuse correct passages.
		 */
		$book = ScriptureParser::extract_book( $reference );

		if ( '' === $book || ! in_array( $book, ScriptureParser::all_books(), true ) ) {
			return '';
		}

		$rest = trim( substr( $reference, strlen( $book ) ) );

		if ( '' === $rest ) {
			return $book;
		}

		// Chapter, optional verse, optional end chapter and verse.
		if ( ! preg_match( '/^\d{1,3}(:\d{1,3})?(-\d{1,3}(:\d{1,3})?)?$/', $rest ) ) {
			return $book;
		}

		return $book . ' ' . $rest;
	}

	/**
	 * Write what was typed onto the fresh draft.
	 *
	 * This is what makes the draft useful even if generation later fails.
	 *
	 * @param int $post_id New draft sermon ID.
	 * @return void
	 */
	public function seed_draft( $post_id ): void {
		$post_id = absint( $post_id );
		if ( ! $post_id ) return;

		$passage = $this->passage_from_request();
		if ( '' !== $passage ) {
			update_post_meta( $post_id, '_scsl_focus_passage', $passage );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Pro verifies its own nonce before this runs.
		$video = isset( $_POST['scsl_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['scsl_video_url'] ) ) : '';
		$audio = isset( $_POST['scsl_audio_url'] ) ? esc_url_raw( wp_unslash( $_POST['scsl_audio_url'] ) ) : '';

		if ( '' !== $video ) update_post_meta( $post_id, '_scsl_video_url', $video );
		if ( '' !== $audio ) update_post_meta( $post_id, '_scsl_audio_url', $audio );

		$speaker = isset( $_POST['speaker'] ) ? sanitize_text_field( wp_unslash( $_POST['speaker'] ) ) : '';
		$date    = isset( $_POST['service_date'] ) ? sanitize_text_field( wp_unslash( $_POST['service_date'] ) ) : '';
		$no_feed   = ! empty( $_POST['podcast_exclude'] );
		$series_id = isset( $_POST['series_id'] ) ? absint( $_POST['series_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $series_id && 'scsl_series' === get_post_type( $series_id ) ) {
			update_post_meta( $post_id, '_scsl_series_id', $series_id );
		}

		if ( '' !== $speaker ) {
			$found = get_posts( [
				'post_type'      => 'scsl_speaker',
				'title'          => $speaker,
				'posts_per_page' => 1,
				'post_status'    => [ 'publish', 'draft' ],
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );

			if ( ! empty( $found ) ) {
				update_post_meta( $post_id, '_scsl_speaker_id', absint( $found[0] ) );
			}
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			update_post_meta( $post_id, '_scsl_recorded_date', $date );
		}

		// Written either way, so the choice made on the form is visible on the
		// sermon rather than being inferred from an absent value.
		//
		// The feed compares this against the exact string "yes", so anything
		// else, including "1", silently means included. Getting that wrong is
		// why this checkbox previously appeared to do nothing at all.
		// Everything goes in the feed unless somebody says otherwise, which is
		// how the sermon screen has always behaved.
		update_post_meta( $post_id, '_scsl_podcast_exclude', $no_feed ? 'yes' : '' );
	}

	/**
	 * What a sermon already knows about itself, as generation hints.
	 *
	 * Pro asks for this rather than reading sermon fields itself, which is
	 * what keeps it from having to know that a sermon has a focus passage or
	 * a linked speaker.
	 *
	 * @param array $fields  Context so far.
	 * @param int   $post_id Sermon ID.
	 * @return array
	 */
	public function existing_context( $fields, $post_id ) {
		if ( ! is_array( $fields ) ) $fields = [];

		$post_id = absint( $post_id );

		$title = trim( (string) get_the_title( $post_id ) );

		if ( '' !== $title ) $fields['title'] = sanitize_text_field( $title );

		$passage = trim( (string) get_post_meta( $post_id, '_scsl_focus_passage', true ) );

		if ( '' !== $passage ) $fields['primary_scripture'] = sanitize_text_field( $passage );

		$speaker_id = absint( get_post_meta( $post_id, '_scsl_speaker_id', true ) );

		if ( $speaker_id ) {
			$fields['speaker'] = sanitize_text_field( get_the_title( $speaker_id ) );
		}

		return $fields;
	}

	/**
	 * Add the article title to content waiting for review.
	 *
	 * Taken from the article's own first heading, which is where it comes from
	 * everywhere else. Only added when the article is part of what is waiting:
	 * a title without its article would be a heading for something that has
	 * not arrived.
	 *
	 * @param array $fields  Meta key to value.
	 * @param int   $post_id Sermon ID.
	 * @return array
	 */
	public function stage_article_title( $fields, $post_id ) {
		if ( ! is_array( $fields ) || empty( $fields['_scsl_article_body'] ) ) return $fields;

		$title = $this->title_from_article( (string) $fields['_scsl_article_body'] );

		if ( '' !== $title ) {
			$fields['_scsl_article_title'] = $title;
		}

		return $fields;
	}

	/**
	 * The first heading in an article, which is its title.
	 *
	 * @param string $body Article HTML.
	 * @return string
	 */
	private function title_from_article( string $body ): string {
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) return '';

		if ( ! preg_match( '/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $body, $heading ) ) return '';

		return sanitize_text_field( wp_strip_all_tags( $heading[1] ) );
	}


	/**
	 * Ask for the passages as well as the written fields.
	 *
	 * They go into Additional Passages rather than a content field of their
	 * own, so they are not in the field map and would otherwise never be
	 * requested.
	 *
	 * @param array $fields API field keys.
	 * @return array
	 */
	public function also_ask_for_scripture( $fields ) {
		$fields = (array) $fields;

		if ( ! in_array( 'scripture', $fields, true ) ) $fields[] = 'scripture';

		return $fields;
	}

	/**
	 * Store the passages a generation found, leaving out the focus passage.
	 *
	 * The focus passage was typed on the form and is already saved. Listing it
	 * again under Additional Passages would have the same reference showing
	 * twice on the sermon page.
	 *
	 * @param int   $post_id Sermon ID.
	 * @param array $record  The result record.
	 * @return void
	 */
	/**
	 * Fill the focus passage from the sermon's title, when the title says.
	 *
	 * Church titles usually carry the passage: "The Gospel of Luke - Built on
	 * the Rock (Luke 6:46-49)". Reading it costs nothing and saves somebody
	 * typing what is already written on the screen in front of them.
	 *
	 * Only when the field is empty. A passage already there was either chosen
	 * by a person or returned by the generation, and both know more about the
	 * sermon than its title does.
	 *
	 * The title has to say plainly. Where two passages are equally specific,
	 * or none carries a chapter, nothing is filled: a wrong focus passage
	 * looks deliberate and files the sermon under the wrong words, which is
	 * worse than an empty field somebody can see is empty.
	 *
	 * @param int $post_id Sermon ID.
	 * @return void
	 */
	public function focus_from_title( $post_id ): void {
		$post_id = absint( $post_id );

		if ( ! $post_id ) return;

		$existing = trim( (string) get_post_meta( $post_id, '_scsl_focus_passage', true ) );

		if ( '' !== $existing ) return;

		$title = (string) get_the_title( $post_id );

		if ( '' === trim( $title ) ) return;

		$passage = ScriptureParser::focus_from_title( $title );

		if ( '' === $passage ) return;

		update_post_meta( $post_id, '_scsl_focus_passage', $passage );

		// Filed under it as well, so the passage page finds this sermon
		// rather than the reference sitting on the sermon and nowhere else.
		SermonMeta::sync_scripture( $post_id );
	}

	public function import_passages( $post_id, $record ): void {
		$post_id = absint( $post_id );

		if ( ! is_array( $record ) || empty( $record['scripture'] ) ) return;

		$field = (array) $record['scripture'];

		if ( ! empty( $field['pending'] ) ) return;

		$value = isset( $field['value'] ) ? $field['value'] : '';

		$found = $this->passage_list( $value );

		if ( ! $found ) return;

		$focus = $this->normalise_passage( (string) get_post_meta( $post_id, '_scsl_focus_passage', true ) );

		$existing = (array) get_post_meta( $post_id, '_scsl_other_passages', true );
		$keep     = [];
		$seen     = [];

		foreach ( array_merge( $existing, $found ) as $passage ) {
			$passage = sanitize_text_field( (string) $passage );
			$key     = $this->normalise_passage( $passage );

			if ( '' === $key || $key === $focus || isset( $seen[ $key ] ) ) continue;

			$seen[ $key ] = true;
			$keep[]       = $passage;
		}

		if ( $keep ) {
			update_post_meta( $post_id, '_scsl_other_passages', $keep );

			// File the sermon under the passages as well as recording them.
			// Without this the passage links on the sermon lead to pages that
			// say no sermon used them.
			\SeedcastSermonLibrary\Admin\SermonMeta::sync_scripture( $post_id );
		}
	}

	/**
	 * Pull references out of whatever shape the passages arrive in.
	 *
	 * A list, a paragraph, or an array: the API decides, and a reference is
	 * short enough that anything long is prose rather than a citation.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private function passage_list( $value ): array {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$text = (string) $value;

			// List items and line breaks are separators before tags go.
			$text  = preg_replace( '#</li>|<br\s*/?>|</p>#i', "\n", $text );
			$text  = wp_strip_all_tags( $text );
			$parts = preg_split( '/[\r\n;]+/', $text );
		}

		$out = [];

		foreach ( (array) $parts as $part ) {
			$part = trim( html_entity_decode( (string) $part, ENT_QUOTES, 'UTF-8' ) );

			// The first entry is prefixed to mark it as the primary passage.
			// That is a label rather than part of the reference.
			$part = preg_replace( '/^main\s*:\s*/i', '', $part );
			$part = trim( $part, "-*\u{2022} \t" );

			// A citation is short. Anything longer is a sentence about one.
			if ( '' === $part || strlen( $part ) > 60 ) continue;

			// Something that looks like a reference has a number in it.
			if ( ! preg_match( '/\d/', $part ) ) continue;

			$out[] = $part;
		}

		return $out;
	}

	/**
	 * A comparable form of a reference, so spacing and case do not make two
	 * copies of the same passage look different.
	 *
	 * @param string $passage Reference.
	 * @return string
	 */
	private function normalise_passage( string $passage ): string {
		return strtolower( preg_replace( '/\s+/', '', $passage ) );
	}

	/**
	 * Refresh the article title after generated content has been written.
	 *
	 * @param int $post_id Sermon ID.
	 * @return void
	 */
	public function derive_after_import( $post_id ): void {
		$this->set_article_title( absint( $post_id ) );
	}

	/**
	 * Keep the article title in step with the article it is taken from.
	 *
	 * @param int      $post_id Sermon ID.
	 * @param \WP_Post $post    Sermon being saved.
	 * @return void
	 */
	public function derive_article_title( $post_id, $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
		if ( ! $post instanceof \WP_Post ) return;

		$this->set_article_title( absint( $post_id ) );
	}

	/**
	 * Take the article title from the article's first heading.
	 *
	 * @param int $post_id Sermon ID.
	 * @return void
	 */
	private function set_article_title( int $post_id ): void {
		$title = $this->title_from_article( (string) get_post_meta( $post_id, '_scsl_article_body', true ) );

		if ( '' === $title ) return;

		// Only when it has actually moved, so a title somebody typed over the
		// derived one is not put back on every save.
		if ( $title !== (string) get_post_meta( $post_id, '_scsl_article_title', true ) ) {
			update_post_meta( absint( $post_id ), '_scsl_article_title', $title );
		}
	}
}
