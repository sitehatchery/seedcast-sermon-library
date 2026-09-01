<?php
namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds a Sermon section to Bulletin Library service pages.
 *
 * Hooks into the `scbl/service/sections` filter, which Bulletin Library
 * applies while rendering a single service page. When there are sermons
 * whose recorded date matches the service date, they render as cards
 * inside a section positioned above Programs and Announcements.
 *
 * If Bulletin Library is not installed, the filter is never applied and
 * this class does nothing. Sermon Library keeps working standalone.
 */
final class BulletinLibraryBridge {

	/**
	 * Weight for the sermon section. Lower renders first. Programs are 5
	 * and Announcements are 10, so 3 puts sermons at the top of the
	 * sections list, matching how they anchor a Sunday visually.
	 */
	private const SECTION_WEIGHT = 3;

	public function init(): void {
		add_filter( 'scbl/service/sections', [ $this, 'add_sermon_section' ], 10, 3 );
	}

	/**
	 * Contribute a section descriptor if any sermons match the service date.
	 *
	 * @param array   $sections     Existing section descriptors from other plugins.
	 * @param \WP_Post $post         The service post being rendered.
	 * @param string   $service_date Y-m-d date of the service.
	 * @return array
	 */
	public function add_sermon_section( array $sections, $post, string $service_date ): array {
		if ( '' === $service_date ) {
			return $sections;
		}

		$sermons = $this->sermons_on_date( $service_date );
		if ( empty( $sermons ) ) {
			return $sections;
		}

		$html = $this->render_section( $sermons );
		if ( '' === $html ) {
			return $sections;
		}

		$sections[] = [
			'html'   => $html,
			'weight' => self::SECTION_WEIGHT,
			'column' => 'full', // Sermon is the primary content of the day; render above the two-column split so it gets full page width.
		];

		return $sections;
	}

	/**
	 * Query sermons whose recorded date matches the given date.
	 *
	 * Uses `_scsl_recorded_date` explicitly rather than falling back to
	 * post_date. A sermon that happens to be published on a Sunday but
	 * was preached weeks earlier should not surface as "today's sermon."
	 *
	 * @param string $date Y-m-d.
	 * @return \WP_Post[]
	 */
	private function sermons_on_date( string $date ): array {
		$query = new \WP_Query( [
			'post_type'              => 'scsl_sermon',
			'post_status'            => 'publish',
			'posts_per_page'         => 10,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			/*
			 * Finding a sermon by the date it was recorded is what this does,
			 * and the date is post meta, so there is no version of this without
			 * a meta lookup. It is an exact match on a single indexed key,
			 * capped at ten rows, with row counting and term caching off, which
			 * is about as small as such a query gets.
			 */
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact match on one key, bounded and uncounted.
			'meta_query'             => [
				[
					'key'   => '_scsl_recorded_date',
					'value' => $date,
				],
			],
		] );

		return $query->posts ?: [];
	}

	/**
	 * Render the section wrapper and its stacked sermon cards.
	 *
	 * @param \WP_Post[] $sermons
	 * @return string
	 */
	private function render_section( array $sermons ): string {
		ob_start();
		?>
		<section class="scbl-section scbl-section--sermons" aria-labelledby="scbl-sermons-heading">
			<h2 id="scbl-sermons-heading" class="scbl-section__title">
				<?php echo esc_html( _n( 'Sermon', 'Sermons', count( $sermons ), 'seedcast-sermon-library' ) ); ?>
			</h2>
			<div class="scsl-service-sermons">
				<?php foreach ( $sermons as $sermon ) : ?>
					<?php echo $this->render_card( $sermon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_card escapes internally ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a single sermon card.
	 *
	 * The sermon's own featured image is used (not the speaker headshot).
	 * ImageFallback still applies, so a sermon with no image of its own
	 * shows the series image or the site default.
	 *
	 * The card renders as three columns on wide viewports: image, body,
	 * and a metadata aside. The body wraps title, excerpt, and a View
	 * Sermon call to action. The aside carries speaker, series link,
	 * article link, and deep links into the Bible Study and Transcript
	 * tabs on the sermon page. Each row in the aside renders only when
	 * that field has content, so a bare sermon collapses cleanly.
	 *
	 * The card is not wrapped in a single anchor. The image, title, and
	 * View Sermon pill each link to the sermon page in their own right,
	 * and the aside carries its own real anchors. Nested anchors are
	 * invalid HTML, so any card with tab deep links needs the anchors
	 * to be siblings, not children of another anchor.
	 *
	 * @param \WP_Post $sermon
	 * @return string
	 */
	/**
	 * One sermon, as it appears in a list.
	 *
	 * Public and static because this is Sermon Library's own card and the
	 * service page is only the first place it appears. A scripture archive
	 * listing every sermon that touched a passage wants the same item, and
	 * building a second one that drifts from this is how two lists of the same
	 * thing end up looking different on the same site.
	 *
	 * Reads nothing but sermon meta, so it does not care which screen it is on
	 * or whether Living Bulletin is installed at all.
	 *
	 * @param \WP_Post $sermon Sermon post.
	 * @return string
	 */
	public static function card( \WP_Post $sermon ): string {
		return ( new self() )->render_card( $sermon );
	}

	private function render_card( \WP_Post $sermon ): string {
		$permalink   = get_permalink( $sermon );
		$title       = get_the_title( $sermon );
		$speaker     = $this->speaker_name_for( $sermon->ID );
		$excerpt     = $this->excerpt_for( $sermon );
		$has_image   = has_post_thumbnail( $sermon );

		$series_id      = (int) get_post_meta( $sermon->ID, '_scsl_series_id', true );
		$series_name    = $series_id ? get_the_title( $series_id ) : '';
		$series_link    = $series_id ? get_permalink( $series_id )  : '';

		$article_title  = trim( (string) get_post_meta( $sermon->ID, '_scsl_article_title', true ) );
		$has_article    = '' !== trim( (string) get_post_meta( $sermon->ID, '_scsl_article_body', true ) );
		$has_bible_std  = '' !== trim( (string) get_post_meta( $sermon->ID, '_scsl_bible_study', true ) );
		$has_transcript = '' !== trim( (string) get_post_meta( $sermon->ID, '_scsl_transcript_clean', true ) );

		// Whether the metadata aside will have anything to show. Only render
		// the column when there is at least one line, otherwise the vertical
		// separator would sit against an empty region.
		$has_meta = ( '' !== $speaker )
			|| ( '' !== $series_name )
			|| ( '' !== $article_title && $has_article )
			|| $has_bible_std
			|| $has_transcript;

		ob_start();
		?>
		<article class="scsl-service-sermon-card <?php echo esc_attr( $has_meta ? 'has-meta' : '' ); ?>">
			<?php if ( $has_image ) : ?>
				<a class="scsl-service-sermon-card__image" href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true">
					<?php echo get_the_post_thumbnail( $sermon, 'medium', [ 'alt' => '', 'loading' => 'lazy' ] ); ?>
				</a>
			<?php endif; ?>

			<div class="scsl-service-sermon-card__body">
				<h3 class="scsl-service-sermon-card__title">
					<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
				</h3>
				<?php if ( '' !== $excerpt ) : ?>
					<p class="scsl-service-sermon-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
				<?php endif; ?>
				<p class="scsl-service-sermon-card__cta-wrap">
					<a class="sc-btn sc-btn--ghost sc-btn--sm" href="<?php echo esc_url( $permalink ); ?>">
						<?php esc_html_e( 'View Sermon', 'seedcast-sermon-library' ); ?>
					</a>
				</p>
			</div>

			<?php if ( $has_meta ) : ?>
				<aside class="scsl-service-sermon-card__meta" aria-label="<?php esc_attr_e( 'Sermon details', 'seedcast-sermon-library' ); ?>">
					<?php if ( '' !== $speaker ) : ?>
						<p class="scsl-service-sermon-card__meta-row">
							<span class="scsl-service-sermon-card__meta-label"><?php esc_html_e( 'Speaker:', 'seedcast-sermon-library' ); ?></span>
							<span class="scsl-service-sermon-card__meta-value"><?php echo esc_html( $speaker ); ?></span>
						</p>
					<?php endif; ?>

					<?php if ( '' !== $series_name && '' !== $series_link ) : ?>
						<p class="scsl-service-sermon-card__meta-row">
							<span class="scsl-service-sermon-card__meta-label"><?php esc_html_e( 'Series:', 'seedcast-sermon-library' ); ?></span>
							<a class="scsl-service-sermon-card__meta-value" href="<?php echo esc_url( $series_link ); ?>"><?php echo esc_html( $series_name ); ?></a>
						</p>
					<?php endif; ?>

					<?php if ( '' !== $article_title && $has_article ) : ?>
						<p class="scsl-service-sermon-card__meta-row">
							<span class="scsl-service-sermon-card__meta-label"><?php esc_html_e( 'Article:', 'seedcast-sermon-library' ); ?></span>
							<a class="scsl-service-sermon-card__meta-value" href="<?php echo esc_url( $permalink . '#article' ); ?>"><?php echo esc_html( $article_title ); ?></a>
						</p>
					<?php endif; ?>

					<?php if ( $has_bible_std ) : ?>
						<p class="scsl-service-sermon-card__meta-row scsl-service-sermon-card__meta-row--action">
							<a href="<?php echo esc_url( $permalink . '#bible-study' ); ?>">
								<?php esc_html_e( 'Bible Study', 'seedcast-sermon-library' ); ?>
							</a>
						</p>
					<?php endif; ?>

					<?php if ( $has_transcript ) : ?>
						<p class="scsl-service-sermon-card__meta-row scsl-service-sermon-card__meta-row--action">
							<a href="<?php echo esc_url( $permalink . '#transcript' ); ?>">
								<?php esc_html_e( 'Transcript', 'seedcast-sermon-library' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</aside>
			<?php endif; ?>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Speaker display name for the sermon.
	 *
	 * Sermon Library stores a single speaker per sermon in `_scsl_speaker_id`
	 * as a post ID pointing to a `scsl_speaker` CPT.
	 *
	 * @param int $sermon_id
	 * @return string
	 */
	private function speaker_name_for( int $sermon_id ): string {
		$speaker_id = (int) get_post_meta( $sermon_id, '_scsl_speaker_id', true );
		if ( ! $speaker_id ) return '';
		$speaker = get_post( $speaker_id );
		if ( ! $speaker || 'scsl_speaker' !== $speaker->post_type ) return '';
		return (string) get_the_title( $speaker );
	}

	/**
	 * Card excerpt, trimmed to something card-sized.
	 *
	 * Fallback chain: an explicit post excerpt wins, then the
	 * sermon-authored content description field, then the article body,
	 * and finally post_content. Sermon Library sermons often carry
	 * no post_content because the sermon page renders from meta fields;
	 * without this chain the card is left without a description on
	 * exactly the sermons most likely to have rich content.
	 *
	 * @param \WP_Post $sermon
	 * @return string
	 */
	private function excerpt_for( \WP_Post $sermon ): string {
		/*
		 * The hand written excerpt is tried first and then simply joins the
		 * queue, rather than being returned whatever it turns out to be.
		 *
		 * has_excerpt() only says the field is not empty in the database. It
		 * can still come back as whitespace, or be emptied by a theme filtering
		 * the excerpt, and returning it at that point meant the card showed no
		 * text at all while a perfectly good description sat one line below
		 * waiting to be used.
		 */
		$candidates = [];

		if ( has_excerpt( $sermon ) ) {
			$candidates[] = (string) get_the_excerpt( $sermon );
		}

		$candidates[] = (string) get_post_meta( $sermon->ID, '_scsl_content_description', true );
		$candidates[] = (string) get_post_meta( $sermon->ID, '_scsl_article_body', true );
		$candidates[] = (string) $sermon->post_content;

		foreach ( $candidates as $raw ) {
			$text = trim( wp_strip_all_tags( strip_shortcodes( $raw ) ) );

			if ( '' !== $text ) {
				return wp_trim_words( $text, 30, '&hellip;' );
			}
		}

		return '';
	}
}
