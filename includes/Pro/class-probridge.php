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
	 * The passages the last generation found, as normalized keys.
	 *
	 * Kept so the next one can clear its own set and leave a church's own
	 * references, and the focus passage, where they are.
	 */
	const META_FOUND_PASSAGES = '_scsl_passages_found';

	/**
	 * When the model last said none of the church's topics fit this sermon.
	 */
	const META_TOPICS_DECLINED = '_scsl_topics_declined';

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

		// What the engine asks of a sermon while it generates. These cost
		// nothing when the engine is absent, because nothing fires them.
		add_filter( 'seedcast_pro_existing_context_' . self::PRODUCT, [ $this, 'existing_context' ], 10, 2 );
		add_action( 'seedcast_pro_imported_' . self::PRODUCT, [ $this, 'import_passages' ], 10, 2 );
		add_action( 'seedcast_pro_imported_' . self::PRODUCT, [ $this, 'import_topics' ], 10, 2 );

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
		add_filter( 'seedcast_pro_staged_' . self::PRODUCT, [ $this, 'stage_passages' ], 10, 2 );
		add_filter( 'seedcast_pro_stage_fields_' . self::PRODUCT, [ $this, 'stage_passages' ], 10, 2 );
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
		 * The passage picker's own behavior.
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

				/*
				 * And the same for a topic page, which is a different thing to
				 * describe out of the same parts: what this church has taught
				 * about a subject, rather than what it has preached from a
				 * text. Declared here beside the other one so the engine goes
				 * on knowing nothing about this plugin's hooks.
				 */
				'topic_summary' => [
					'filter' => 'scsl_topic_summary_generate',
					'kind'   => 'topic',
					'args'   => 5,
				],

				/*
				 * The opening paragraph of a service bulletin, written from
				 * the sermon preached at it: not a description of the sermon
				 * but the emphasis of the morning, something encouraging
				 * about what it was about. Two arguments, the summary and the
				 * sermon as Summaries\ServiceSummary gathers it.
				 */
				'service_summary' => [
					'filter' => 'scsl_service_summary_generate',
					'kind'   => 'service',
					'args'   => 2,
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

				/*
				 * Topics land as terms rather than in a field, so this says
				 * which taxonomy instead of which meta key. The engine asks
				 * whether a sermon already carries any the same way it asks
				 * about a field, and the list of what may be chosen travels
				 * with the job as context.
				 */
				'topics' => [
					'label'    => __( 'Topics', 'seedcast-sermon-library' ),
					'taxonomy' => 'scsl_topic',

					// Where "none of these fit" is recorded, so a sermon the
					// model declined to file is not asked about again and the
					// coverage report can tell declined from never asked.
					'declined_meta' => self::META_TOPICS_DECLINED,
				],
			],

			/*
			 * What the public assistant may answer from.
			 *
			 * Public, said in as many words: sermons, the questions they and
			 * the topic pages answer, topic and passage pages. Nothing here is
			 * anything a visitor could not already read on the site. The
			 * filter is this plugin's own, so the engine asks without knowing
			 * what a sermon is.
			 */
			'corpus'      => [
				'sensitivity' => 'public',
				'retrieve'    => \SeedcastSermonLibrary\Ask\Retrieval::FILTER,
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
				/*
				 * The source fields, so a recording given in the dialog shows
				 * up in the box it belongs to.
				 *
				 * The engine writes the address onto the sermon as soon as it
				 * is given, which left the Audio box on an open screen empty:
				 * the sermon had the recording and the screen did not, and
				 * pressing Update posted that empty box back over it.
				 */
				// Passages come through as rows already split into book,
				// chapter and verse. See stage_passages().
				'_scsl_other_passages'      => [ 'name' => 'scsl_passages_incoming',   'type' => 'textarea' ],
				'_scsl_audio_url'           => [ 'name' => 'scsl_audio_url',           'type' => 'url' ],
				'_scsl_video_url'           => [ 'name' => 'scsl_video_url',           'type' => 'url' ],
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
		 * Whether to offer the page at all.
		 *
		 * Without the engine it is the one place that explains what generation
		 * does and where to get it, so it stays: the page is the pitch.
		 *
		 * With the engine installed it is a second way to do what the sermon's
		 * own screen now does better. The sermon is where the recording, the
		 * details and the content already live, and the generate screen was a
		 * separate implementation of the same job: its own form, its own
		 * script, its own idea of what had been written. Two routes to one job
		 * is how the two drift apart, and the one nobody uses is the one that
		 * breaks quietly.
		 *
		 * @param bool $show Whether the menu item appears.
		 */
		if ( ! apply_filters( 'scsl_show_generate_page', ! defined( 'SCPRO_VERSION' ) ) ) return;

		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Generate Sermon', 'seedcast-sermon-library' ),
			__( 'Generate Sermon', 'seedcast-sermon-library' ),
			'publish_posts',
			self::PAGE,
			[ $this, 'render_upsell' ]
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

		/*
		 * The church's own topics, and how many of them one sermon may carry.
		 *
		 * Sent with every job because the list is theirs and changes when they
		 * change it: a vocabulary baked into the service would be a different
		 * church's. Anything outside it is dropped at this end anyway, so this
		 * is what stops the service guessing rather than what enforces it.
		 */
		$choices = Assignment::choices();

		if ( $choices ) {
			// As words, not markup. WordPress stores an ampersand in a term
			// name as an entity, and a model told the option is "Community
			// &amp; The Church" may hand that back as the name.
			$choices = array_map(
				static function ( $name ) {
					return html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
				},
				$choices
			);

			$fields['topic_options'] = sanitize_text_field( implode( ', ', $choices ) );
			$fields['topic_max']     = (string) (int) apply_filters( 'scsl_max_topics', Assignment::MAX );
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
	/**
	 * Hand the sermon's passages to the screen that is open on it.
	 *
	 * Generated passages are merged straight onto the sermon, because they
	 * belong to it rather than to a box somebody is typing in. That left them
	 * invisible: the sermon had eleven and the Scripture box showed none, so a
	 * run that found them looked like a run that had not.
	 *
	 * Sent already split into book, chapter and verse. Working that out is
	 * this plugin's job and the code for it is in PHP; a second copy in the
	 * browser would be two answers to one question.
	 *
	 * @param array $fields  Meta key to value.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public function stage_passages( $fields, $post_id ) {
		$passages = (array) get_post_meta( absint( $post_id ), '_scsl_other_passages', true );
		$passages = array_values( array_filter( array_map( 'strval', $passages ) ) );

		if ( ! $passages ) {
			return (array) $fields;
		}

		$rows = [];

		foreach ( $passages as $passage ) {
			$rows[] = \SeedcastSermonLibrary\Admin\SermonMeta::passage_parts( $passage );
		}

		$fields = (array) $fields;

		$fields['_scsl_other_passages'] = (string) wp_json_encode( $rows );

		return $fields;
	}

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

		$focus = $this->normalize_passage( (string) get_post_meta( $post_id, '_scsl_focus_passage', true ) );

		$existing = (array) get_post_meta( $post_id, '_scsl_other_passages', true );

		/*
		 * What the last generation put here, so this one replaces it rather
		 * than piling on top of it.
		 *
		 * Passages are added to a sermon rather than swapped in, because a
		 * list somebody typed is theirs and a generation asked for references
		 * is a request for the rest. Run twice, though, that put a second
		 * reading of the same sermon beside the first: one sermon ended up
		 * carrying thirty-nine references, the same passages among them in
		 * looser forms, Matthew 4 beside Matthew 4:1-11.
		 *
		 * So a generation now clears its own previous set and leaves
		 * everything else alone. Anything the church added by hand stays, and
		 * so does a passage this reading found again.
		 */
		$mine = get_post_meta( $post_id, self::META_FOUND_PASSAGES, true );
		$mine = is_array( $mine ) ? array_filter( array_map( 'strval', $mine ) ) : [];

		$fresh = [];

		foreach ( $found as $passage ) {
			$key = $this->normalize_passage( (string) $passage );

			if ( '' !== $key ) {
				$fresh[ $key ] = true;
			}
		}

		$keep = [];
		$seen = [];

		foreach ( array_merge( $existing, $found ) as $passage ) {
			$passage = sanitize_text_field( (string) $passage );
			$key     = $this->normalize_passage( $passage );

			if ( '' === $key || $key === $focus || isset( $seen[ $key ] ) ) continue;

			// Ours from last time and not found again, so it goes with the
			// reading it came from.
			if ( in_array( $key, $mine, true ) && ! isset( $fresh[ $key ] ) ) continue;

			$seen[ $key ] = true;
			$keep[]       = $passage;
		}

		if ( $keep ) {
			update_post_meta( $post_id, '_scsl_other_passages', $keep );
			update_post_meta( $post_id, self::META_FOUND_PASSAGES, array_keys( $fresh ) );

			// File the sermon under the passages as well as recording them.
			// Without this the passage links on the sermon lead to pages that
			// say no sermon used them.
			\SeedcastSermonLibrary\Admin\SermonMeta::sync_scripture( $post_id );
		}
	}

	/**
	 * File a sermon under the subjects the service chose.
	 *
	 * The service is given this church's list and told to pick from it, and
	 * what comes back is checked against that list again here: a request can be
	 * answered by anything, and a topic this church does not have must not
	 * become one because a model wrote it down. Assignment does the checking
	 * and the capping, so the rules live in one place whether a person or a
	 * generation did the choosing.
	 *
	 * Never over a choice already made. A church that has filed a sermon itself
	 * has said what it is about, and a run asked for topics is a request to
	 * fill in a blank rather than to argue.
	 *
	 * @param int   $post_id Sermon ID.
	 * @param mixed $record  One result record.
	 * @return void
	 */
	public function import_topics( $post_id, $record ): void {
		$post_id = absint( $post_id );

		if ( ! is_array( $record ) || empty( $record['topics'] ) ) return;

		$field = (array) $record['topics'];

		if ( ! empty( $field['pending'] ) ) return;

		$picked = $this->topic_list( $field['value'] ?? '' );

		// Asked, answered, and nothing fitted. Remembered, so the sermon is
		// not asked again and does not look like one nobody ever asked about.
		if ( ! $picked ) {
			if ( ! wp_get_object_terms( $post_id, 'scsl_topic', [ 'fields' => 'ids' ] ) ) {
				update_post_meta( $post_id, self::META_TOPICS_DECLINED, current_time( 'mysql' ) );
			}

			return;
		}

		$assigned = Assignment::assign( $post_id, $picked, false );

		if ( $assigned ) {
			/**
			 * Topics a generation filed a sermon under.
			 *
			 * @param int   $post_id  Sermon ID.
			 * @param int[] $assigned Term ids now on the sermon.
			 */
			do_action( 'scsl_topics_assigned', $post_id, $assigned );
		}
	}

	/**
	 * Pull topic names out of whatever shape they arrive in.
	 *
	 * Not the passage parser, which requires a digit in every entry because a
	 * reference has one: run through that, every topic a church has would be
	 * dropped on the floor and a run asked for topics would quietly file
	 * nothing.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private function topic_list( $value ): array {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$text  = (string) $value;
			$text  = preg_replace( '#</li>|<br\s*/?>|</p>#i', "\n", $text );
			$text  = wp_strip_all_tags( $text );
			$parts = preg_split( '/[\r\n;,]+/', $text );
		}

		$out = [];

		foreach ( (array) $parts as $part ) {
			$part = trim( html_entity_decode( (string) $part, ENT_QUOTES, 'UTF-8' ) );
			$part = trim( $part, "-* \t" );
			$part = ltrim( $part, "\xE2\x80\xA2" );
			$part = trim( $part );

			// A topic is a name, not a sentence about one. Anything long is a
			// model explaining its choice, which is not a topic.
			if ( '' === $part || strlen( $part ) > 60 ) continue;

			$out[] = $part;
		}

		return $out;
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
	private function normalize_passage( string $passage ): string {
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
