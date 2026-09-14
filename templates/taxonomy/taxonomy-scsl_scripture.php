<?php
/**
 * Template: Scripture Archive (scsl_scripture taxonomy)
 *
 * A reader arrives here by clicking a passage on a sermon page, so the passage
 * is what they asked about and the sermons on it come first, all of them, with
 * no pagination. These lists run to a handful, and a page break in a handful is
 * a wall put up for no reason.
 *
 * Beneath that the page widens rather than repeats: what this set of sermons
 * covers, the articles written out of them, and then the rest of the book. That
 * last section is what carries the long tail. Most passages have exactly one
 * sermon, and for those the book section is the difference between a page with
 * a single card on it and a page worth arriving at.
 *
 * Terms are assigned from the focus passage and the other passages together, so
 * a sermon that referenced this in passing belongs here as much as one built on
 * it.
 *
 * Uses the same sermon card the service page uses. A church should not find two
 * lists of the same sermons on the same site that look nothing alike, and
 * keeping one component means the two cannot drift.
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\BulletinLibraryBridge;
use SeedcastSermonLibrary\Scripture\ScriptureParser;
use SeedcastSermonLibrary\Scripture\Summary;
use SeedcastSermonLibrary\Scripture\CardPassages;
use SeedcastSermonLibrary\Scripture\ListLoader;

get_header();

$scsl_term = get_queried_object();

/*
 * Every sermon whose passages touch this one, worked out once and shared with
 * the endpoint that appends later batches, so a fetched batch is drawn from
 * exactly the same set as the batch above it.
 *
 * Ids only. The full set is needed for the summary, for excluding these from
 * the book section, and for counting articles, but only the first batch is
 * drawn, so fetching every post object would be work thrown away.
 */
$scsl_per_page = ListLoader::per_page();

$scsl_listed = ListLoader::cross_referenced( $scsl_term, -1, 0 )->posts;
$scsl_listed = wp_list_pluck( $scsl_listed, 'ID' );

$scsl_total   = count( $scsl_listed );
$scsl_visible = array_slice( $scsl_listed, 0, $scsl_per_page );

/*
 * What this set of sermons covers, in prose.
 *
 * Empty unless a church has connected a service to write it, and never asked
 * for on a single-sermon passage. See the Summary class for why.
 */
$scsl_summary = Summary::get( (int) $scsl_term->term_id, $scsl_listed );

/*
 * The book this passage sits in, for the section that widens the page out.
 *
 * On a book term there is nothing wider to offer, so the section is skipped
 * rather than shown listing the same sermons a second time.
 */
$scsl_book      = ScriptureParser::extract_book( (string) $scsl_term->name );
$scsl_book_term = $scsl_book ? get_term_by( 'name', $scsl_book, 'scsl_scripture' ) : null;
$scsl_is_book   = ( $scsl_book_term instanceof \WP_Term && (int) $scsl_book_term->term_id === (int) $scsl_term->term_id );

$scsl_book_sermons = null;

if ( $scsl_book_term instanceof \WP_Term && ! $scsl_is_book ) {
	/*
	 * Excluding what is already above. The reader has just been shown those,
	 * and a second copy under a heading promising something else is a broken
	 * promise. If that empties the section it does not render at all.
	 */
	$scsl_book_sermons = ListLoader::rest_of_book( $scsl_book_term, $scsl_listed, $scsl_per_page, 0 );
}

/*
 * A way to read the passage, not the passage itself.
 *
 * Almost every translation a church might choose is under copyright, and only
 * the reader's own Bible site is licensed to show it. Printing the words here
 * would put every church running this plugin in breach of a licence they never
 * agreed to and do not know exists, on the strength of picking a translation
 * from a dropdown. So this links out, in whichever translation the church has
 * configured, exactly as the panel on a sermon page already does.
 *
 * There is nothing lost by it. Passage text is the most duplicated writing on
 * the internet, and a page made mostly of words found on ten thousand other
 * sites is weaker than one made of this church's own sermons.
 */
$scsl_read_url    = ScriptureParser::build_bible_url( (string) $scsl_term->name );
$scsl_translation = (string) get_option( 'scsl_bible_translation', 'NIV' );

/*
 * Cards on this page say which passages in this book each sermon touches.
 * Switched on around the loops rather than globally, so cards drawn elsewhere
 * on the site are unaffected.
 */
if ( $scsl_book ) {
	CardPassages::begin( (string) $scsl_book );
}

wp_enqueue_style( 'scsl-frontend' );
?>

<div class="sc-wrap">

	<nav class="sc-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'seedcast-sermon-library' ); ?>">
		<ol class="sc-breadcrumb__list">
			<li><a href="<?php echo esc_url( get_post_type_archive_link( 'scsl_series' ) ); ?>"><?php esc_html_e( 'Sermon Library', 'seedcast-sermon-library' ); ?></a></li>
			<li aria-hidden="true" class="sc-breadcrumb__sep">/</li>

			<?php if ( $scsl_book_term instanceof \WP_Term && ! $scsl_is_book ) : ?>
				<li><a href="<?php echo esc_url( get_term_link( $scsl_book_term ) ); ?>"><?php echo esc_html( $scsl_book_term->name ); ?></a></li>
				<li aria-hidden="true" class="sc-breadcrumb__sep">/</li>
			<?php endif; ?>

			<li aria-current="page"><?php echo esc_html( $scsl_term->name ); ?></li>
		</ol>
	</nav>

	<header class="scsl-archive-header">
		<h1 class="scsl-archive-title"><?php echo esc_html( $scsl_term->name ); ?></h1>

		<?php if ( $scsl_term->description ) : ?>
			<p class="scsl-archive-desc"><?php echo esc_html( $scsl_term->description ); ?></p>
		<?php endif; ?>

		<?php if ( $scsl_read_url ) : ?>
			<p class="scsl-archive-read">
				<a href="<?php echo esc_url( $scsl_read_url ); ?>"
				   class="scsl-scripture-ref scsl-scripture-ref--focus"
				   target="_blank"
				   rel="noopener noreferrer">
					<?php
					printf(
						/* translators: 1: a passage, for example 2 Thessalonians 3:3-18. 2: a translation, for example NIV. */
						esc_html__( 'Read %1$s in the %2$s', 'seedcast-sermon-library' ),
						esc_html( $scsl_term->name ),
						esc_html( $scsl_translation )
					);
					?>
					<span class="scsl-scripture-ref__icon" aria-hidden="true">↗</span>
				</a>
			</p>
		<?php endif; ?>

		<p class="scsl-archive-count">
			<?php
			printf(
				esc_html(
					/* translators: %s: number of sermons. */
					_n( '%s sermon from this passage', '%s sermons from this passage or a passage containing it', $scsl_total, 'seedcast-sermon-library' )
				),
				esc_html( number_format_i18n( $scsl_total ) )
			);
			?>
		</p>
	</header>

	<?php if ( '' !== $scsl_summary ) : ?>
		<div class="scsl-scripture-summary">
			<?php echo wp_kses_post( wpautop( $scsl_summary ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $scsl_visible ) : ?>

		<div class="scsl-service-sermons scsl-scripture-sermons">
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
				'cross',
				count( $scsl_visible ),
				__( 'Show more sermons', 'seedcast-sermon-library' ),
				$scsl_book_term instanceof \WP_Term ? get_term_link( $scsl_book_term ) : get_post_type_archive_link( 'scsl_sermon' )
			);

			echo $scsl_more_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListLoader::button() escapes everything it prints.
		}
		?>

	<?php else : ?>

		<p class="scsl-archive-empty"><?php esc_html_e( 'No sermons from this passage yet.', 'seedcast-sermon-library' ); ?></p>

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

	// Counting the sermons rather than the markup they produce. Card classes
	// belong to the card component and change when it does, and a guard that
	// silently stops matching is a section that silently stops appearing.
	if ( $scsl_with_article > 1 ) {
		/*
		 * On a book page the heading says so. "Articles on this passage" is
		 * true of a verse, but a reader looking at the whole of Ephesians is
		 * not looking at a passage and the word sounds like a mistake.
		 */
		$scsl_articles_title = $scsl_is_book
			? __( 'Articles on this book', 'seedcast-sermon-library' )
			: __( 'Articles on this passage', 'seedcast-sermon-library' );

		// Whole rows only, so the grid never ends on a lone card with two
		// empty columns beside it.
		$scsl_article_batch = ListLoader::article_batch();

		$scsl_articles = do_shortcode(
			sprintf(
				'[scsl_content type="articles" include="%s" count="%d" columns="%d" layout="grid" title="%s"]',
				esc_attr( implode( ',', $scsl_listed ) ),
				$scsl_article_batch,
				ListLoader::ARTICLE_COLUMNS,
				esc_attr( $scsl_articles_title )
			)
		);

		// The shortcode returns nothing when the church has this content type
		// switched off, and an empty section heading is worse than no section.
		if ( '' !== trim( $scsl_articles ) ) {
			echo '<section class="scsl-scripture-articles">' . $scsl_articles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped by the components that built it.

			if ( $scsl_with_article > $scsl_article_batch ) {
				wp_enqueue_script( 'scsl-load-more' );

				$scsl_more_button = ListLoader::button(
					$scsl_term,
					'articles',
					$scsl_article_batch,
					__( 'Show more articles', 'seedcast-sermon-library' ),
					$scsl_book_term instanceof \WP_Term ? get_term_link( $scsl_book_term ) : get_permalink()
				);

				echo $scsl_more_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListLoader::button() escapes everything it prints.
			}

			echo '</section>';
		}
	}
	?>

	<?php if ( $scsl_book_sermons instanceof \WP_Query && $scsl_book_sermons->have_posts() ) : ?>

		<section class="scsl-scripture-book">
			<h2 class="sc-content-section-heading scsl-scripture-book__title">
				<?php
				printf(
					/* translators: %s: a book of the Bible, for example Romans. */
					esc_html__( 'Explore Other Sermons Relating to %s', 'seedcast-sermon-library' ),
					esc_html( $scsl_book_term->name )
				);
				?>
			</h2>

			<div class="scsl-service-sermons scsl-scripture-sermons">
				<?php
				while ( $scsl_book_sermons->have_posts() ) :
					$scsl_book_sermons->the_post();

					echo BulletinLibraryBridge::card( get_post() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				endwhile;

				wp_reset_postdata();
				?>
			</div>

			<?php
			if ( $scsl_book_sermons->found_posts > $scsl_per_page ) {
				wp_enqueue_script( 'scsl-load-more' );

				$scsl_more_button = ListLoader::button(
					$scsl_term,
					'book',
					$scsl_book_sermons->post_count,
					/* translators: %s: a book of the Bible, for example Romans. */
					sprintf( __( 'Show more from %s', 'seedcast-sermon-library' ), $scsl_book_term->name ),
					get_term_link( $scsl_book_term )
				);

				echo $scsl_more_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ListLoader::button() escapes everything it prints.
			}
			?>

			<p class="scsl-scripture-book__more">
				<a class="sc-btn sc-btn--ghost sc-btn--sm" href="<?php echo esc_url( get_term_link( $scsl_book_term ) ); ?>">
					<?php
					printf(
						/* translators: %s: a book of the Bible, for example Romans. */
						esc_html__( 'All sermons in %s', 'seedcast-sermon-library' ),
						esc_html( $scsl_book_term->name )
					);
					?>
				</a>
			</p>

		</section>

	<?php endif; ?>

</div>

<?php
CardPassages::end();

get_footer();
