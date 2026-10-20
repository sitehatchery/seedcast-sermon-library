<?php
/**
 * Partial: Topics Panel
 *
 * The subjects a sermon was filed under, in the sidebar above the scripture
 * panel. A reader who has just listened to one sermon on forgiveness is the
 * reader most likely to want the rest of them, and the topic page now has the
 * rest of them on it.
 *
 * Three at most. Topics were taken off this page once because a sermon filed
 * under six of them turned the sidebar into a tag cloud, which says nothing and
 * leads everywhere. Three is enough to be a route out of the page and few
 * enough that each one is a deliberate suggestion.
 *
 * Variables: $scsl_post_id (int)
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( '1' !== (string) get_option( 'scsl_show_topics_on_sermon', '1' ) ) return;

$scsl_topics = get_the_terms( $scsl_post_id, 'scsl_topic' );

if ( ! is_array( $scsl_topics ) || ! $scsl_topics ) return;

/**
 * How many subjects to offer on a sermon.
 *
 * @param int $count   How many.
 * @param int $post_id Sermon being displayed.
 */
$scsl_topic_limit = (int) apply_filters( 'scsl_sermon_topic_limit', 3, $scsl_post_id );

/*
 * The best filled in first, so a sermon with more subjects than will fit offers
 * the ones that lead to a page worth arriving at rather than the ones that
 * happen to sort first.
 */
usort(
	$scsl_topics,
	static function ( $a, $b ) {
		return (int) $b->count <=> (int) $a->count;
	}
);

$scsl_topics = array_slice( $scsl_topics, 0, max( 1, $scsl_topic_limit ) );
?>

<aside class="scsl-topics-panel" aria-label="<?php esc_attr_e( 'Topics', 'seedcast-sermon-library' ); ?>">
	<h2 class="scsl-topics-panel__title"><?php esc_html_e( 'Topics', 'seedcast-sermon-library' ); ?></h2>

	<ul class="scsl-topics-panel__list">
		<?php foreach ( $scsl_topics as $scsl_topic ) : ?>
			<li>
				<a href="<?php echo esc_url( get_term_link( $scsl_topic ) ); ?>" class="scsl-topic-tag">
					<?php echo esc_html( $scsl_topic->name ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</aside>
