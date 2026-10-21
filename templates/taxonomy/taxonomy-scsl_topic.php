<?php
/**
 * Template: Topic Archive (scsl_topic taxonomy)
 *
 * A reader arrives here having clicked a subject on a sermon, so the subject is
 * what they asked about and the sermons on it come first, all of them, with no
 * pagination. These lists run to a few dozen at most, and a page break in a few
 * dozen is a wall put up for no reason.
 *
 * Beneath that the page widens rather than repeats: what this church has
 * actually taught on the subject, the articles written out of those sermons,
 * and then the subjects it is taught alongside. That last section is what
 * carries a thin page. A topic with three sermons on it is not a destination,
 * and the way out of that is not more cards from the same three sermons but a
 * way through to the subjects they sit beside.
 *
 * Related subjects are worked out from the sermons themselves. Two topics are
 * related here because this church has preached them together, which is a fact
 * about the library rather than a vocabulary somebody has to maintain.
 *
 * Uses the same sermon card the service and scripture pages use. A church
 * should not find three lists of the same sermons on one site that look nothing
 * alike, and keeping one component means they cannot drift.
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\BulletinLibraryBridge;
use SeedcastSermonLibrary\Frontend\TemplateLoader;
use SeedcastSermonLibrary\Scripture\ListLoader as ScriptureList;
use SeedcastSermonLibrary\Topics\CardTopics;
use SeedcastSermonLibrary\Topics\ListLoader;
use SeedcastSermonLibrary\Topics\Summary;

get_header();

$scsl_term = get_queried_object();

/*
 * Every sermon on this topic, worked out once and shared with the endpoint that
 * appends later batches, so a fetched batch is drawn from exactly the same set
 * as the batch above it.
 *
 * Ids only. The full set is needed for the summary, for excluding these from
 * the related sections, and for counting articles, but only the first batch is
 * drawn, so fetching every post object would be work thrown away.
 */
$scsl_per_page = ScriptureList::per_page();

$scsl_listed = wp_list_pluck( ListLoader::for_topic( $scsl_term, -1 )->posts, 'ID' );
$scsl_total  = count( $scsl_listed );
$scsl_visible = array_slice( $scsl_listed, 0, $scsl_per_page );

/*
 * What this church has taught on the subject, in prose.
 *
 * Empty unless a church has connected a service to write it, and never asked
 * for on a topic carrying one sermon. See the Summary class for why.
 */
$scsl_summary = Summary::get( (int) $scsl_term->term_id, $scsl_listed );

// The subjects these same sermons are also filed under, strongest first.
$scsl_related = ListLoader::related( $scsl_term, $scsl_listed );

/*
 * The same subjects named beside the title, as a way out of the page.
 *
 * Already worked out for the sections below, so this costs the markup and
 * nothing else. Those sections say why each subject is here and carry its
 * sermons; this is for the reader who wants the next page rather than the
 * reasoning behind it.
 */
$scsl_header_related = '1' === (string) get_option( 'scsl_show_related_on_topic', '1' )
	? array_values( array_filter( array_map(
		static function ( $scsl_near ) {
			return $scsl_near['term'] ?? null;
		},
		$scsl_related
	) ) )
	: [];

/*
 * Each card says which other subjects its sermon carries.
 *
 * Not this one: it is true of every card on the page, so it is the one thing
 * the reader already knows.
 */
CardTopics::begin( (int) $scsl_term->term_id );
?>

<div class="sc-wrap">

	<nav class="sc-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'seedcast-sermon-library' ); ?>">
		<ol class="sc-breadcrumb__list">
			<li><a href="<?php echo esc_url( get_post_type_archive_link( 'scsl_series' ) ); ?>"><?php esc_html_e( 'Sermon Library', 'seedcast-sermon-library' ); ?></a></li>
			<li aria-hidden="true" class="sc-breadcrumb__sep">/</li>
			<li aria-current="page"><?php echo esc_html( $scsl_term->name ); ?></li>
		</ol>
	</nav>

	<header class="scsl-archive-header<?php echo $scsl_header_related ? ' scsl-archive-header--split' : ''; ?>">
		<div class="scsl-archive-header__main">
			<h1 class="scsl-archive-title"><?php echo esc_html( $scsl_term->name ); ?></h1>

			<?php if ( $scsl_term->description ) : ?>
				<p class="scsl-archive-desc"><?php echo esc_html( $scsl_term->description ); ?></p>
			<?php endif; ?>

			<p class="scsl-archive-count">
				<?php
				printf(
					/* translators: %s: number of sermons. */
					esc_html( _n( '%s sermon', '%s sermons', $scsl_total, 'seedcast-sermon-library' ) ),
					esc_html( number_format_i18n( $scsl_total ) )
				);
				?>
			</p>
		</div>

		<?php if ( $scsl_header_related ) : ?>
			<div class="scsl-archive-header__topics">
				<?php
				/** This filter is documented in templates/taxonomy/taxonomy-scsl_scripture.php */
				$scsl_header_limit = (int) apply_filters( 'scsl_topic_chip_limit', 5 );

				TemplateLoader::partial( 'topic-chips', [
					'scsl_terms' => $scsl_header_related,
					'scsl_limit' => $scsl_header_limit,
					'scsl_align' => 'end',
					'scsl_label' => __( 'Related topics:', 'seedcast-sermon-library' ),
				] );
				?>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( '' !== $scsl_summary ) : ?>
		<div class="scsl-topic-summary scsl-scripture-summary">
			<?php echo wp_kses_post( wpautop( $scsl_summary ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $scsl_visible ) : ?>

		<div class="scsl-service-sermons scsl-page-sermons">
			<?php
			foreach ( $scsl_visible as $scsl_id ) {
				// The service page's card, unchanged. Already escaped by the
				// component that built it.
				echo BulletinLibraryBridge::card( get_post( (int) $scsl_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>

		<?php
		if ( $scsl_total > $scsl_per_page ) {
			wp_enqueue_script( 'scsl-load-more' );

			// Built first and echoed on a line of its own. A phpcs:ignore covers
			// only the line it sits on, so the arguments of a call spread over
			// several lines were being reported as unescaped output.
			$scsl_more_button = ListLoader::button(
				$scsl_term,
				'topic',
				count( $scsl_visible ),
				__( 'Show more sermons', 'seedcast-sermon-library' ),
				get_post_type_archive_link( 'scsl_sermon' )
			);

			echo $scsl_more_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListLoader::button() escapes everything it prints.
		}
		?>

	<?php else : ?>

		<p class="scsl-archive-empty"><?php esc_html_e( 'No sermons on this topic yet.', 'seedcast-sermon-library' ); ?></p>

	<?php endif; ?>

	<?php
	/*
	 * Articles written out of these same sermons.
	 *
	 * Held back below two, because a lone card cannot fill a row and a heading
	 * over a single item reads as a section that failed to load. The shortcode
	 * renders nothing when none of these sermons carry an article, so a church
	 * that does not write them never sees the heading either.
	 */
	$scsl_with_article = 0;

	foreach ( $scsl_listed as $scsl_id ) {
		if ( '' !== trim( (string) get_post_meta( (int) $scsl_id, '_scsl_article_body', true ) ) ) {
			$scsl_with_article++;
		}
	}

	if ( $scsl_with_article > 1 ) {
		$scsl_article_batch = ScriptureList::article_batch();

		$scsl_articles = do_shortcode(
			sprintf(
				'[scsl_content type="articles" include="%s" count="%d" columns="3" layout="grid" title="%s"]',
				esc_attr( implode( ',', $scsl_listed ) ),
				$scsl_article_batch,
				/* translators: %s: a topic, for example Forgiveness. */
				esc_attr( sprintf( __( 'Reading on %s', 'seedcast-sermon-library' ), $scsl_term->name ) )
			)
		);

		if ( '' !== trim( $scsl_articles ) ) {
			echo '<section class="scsl-topic-articles scsl-scripture-articles">' . $scsl_articles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output.

			if ( $scsl_with_article > $scsl_article_batch ) {
				wp_enqueue_script( 'scsl-load-more' );

				$scsl_more_articles = ListLoader::button(
					$scsl_term,
					'articles',
					$scsl_article_batch,
					__( 'Show more articles', 'seedcast-sermon-library' ),
					get_post_type_archive_link( 'scsl_sermon' )
				);

				echo $scsl_more_articles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListLoader::button() escapes everything it prints.
			}

			echo '</section>';
		}
	}
	?>

	<?php if ( $scsl_related ) : ?>

		<?php
		/*
		 * Why these subjects are here, which decides what the heading can
		 * claim. Where this church has taught two subjects together, that is
		 * what the section is; where it has not, these are the subjects
		 * preached from the same books, and saying so is the difference
		 * between a true heading and a flattering one.
		 */
		$scsl_by_passage = 'passages' === (string) ( $scsl_related[0]['reason'] ?? '' );
		?>

		<section class="scsl-topic-related">
			<h2 class="sc-content-section-heading scsl-topic-related__title">
				<?php
				if ( $scsl_by_passage ) {
					printf(
						/* translators: %s: a topic, for example Forgiveness. */
						esc_html__( 'Preached From the Same Scriptures as %s', 'seedcast-sermon-library' ),
						esc_html( $scsl_term->name )
					);
				} else {
					printf(
						/* translators: %s: a topic, for example Forgiveness. */
						esc_html__( 'Taught Alongside %s', 'seedcast-sermon-library' ),
						esc_html( $scsl_term->name )
					);
				}
				?>
			</h2>

			<?php
			foreach ( $scsl_related as $scsl_near ) :
				$scsl_near_term = $scsl_near['term'];

				/*
				 * Excluding what is already above. The reader has just been
				 * shown those, and a second copy under a heading promising
				 * something else is a broken promise. If that empties a
				 * subject it is left out rather than shown as a heading with
				 * nothing under it.
				 */
				$scsl_near_sermons = ListLoader::from_related(
					$scsl_near_term,
					$scsl_listed,
					ListLoader::RELATED_SERMONS
				);

				if ( ! $scsl_near_sermons->have_posts() ) {
					continue;
				}
				?>

				<div class="scsl-topic-related__group">
					<h3 class="scsl-topic-related__name">
						<a href="<?php echo esc_url( get_term_link( $scsl_near_term ) ); ?>"><?php echo esc_html( $scsl_near_term->name ); ?></a>
						<span class="scsl-topic-related__shared">
							<?php
							if ( 'passages' === (string) $scsl_near['reason'] ) {
								printf(
									/* translators: %s: a list of books of the Bible, for example "Romans, Luke and Acts". */
									esc_html__( 'Also preached from %s', 'seedcast-sermon-library' ),
									esc_html( wp_sprintf( '%l', (array) ( $scsl_near['books'] ?? [] ) ) )
								);
							} else {
								printf(
									/* translators: %s: number of sermons both topics are on. */
									esc_html( _n( '%s sermon in common', '%s sermons in common', (int) $scsl_near['shared'], 'seedcast-sermon-library' ) ),
									esc_html( number_format_i18n( (int) $scsl_near['shared'] ) )
								);
							}
							?>
						</span>
					</h3>

					<div class="scsl-service-sermons scsl-page-sermons">
						<?php
						while ( $scsl_near_sermons->have_posts() ) :
							$scsl_near_sermons->the_post();

							// Escaped by the component that built it.
							echo BulletinLibraryBridge::card( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						endwhile;

						wp_reset_postdata();
						?>
					</div>
				</div>

			<?php endforeach; ?>

		</section>

	<?php endif; ?>

</div>

<?php
CardTopics::end();

get_footer();
