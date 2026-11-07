<?php
namespace SeedcastSermonLibrary\Ask;

use SeedcastSermonLibrary\Frontend\Faq;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What Sermon Library tells the AI Engine's assistant about itself.
 *
 * The assistant is the engine's: the box, the ask page, the questions, the
 * AI Assistant screen. This plugin supplies what only it knows, through the
 * engine's filters, and costs nothing where the engine is not installed:
 *
 *  - what its post types and taxonomies are called in a conversation;
 *  - questions worth starting with, from its most-preached topics;
 *  - where an answer can be published: a sermon's FAQ, or under topics.
 *
 * Its content itself is offered through the corpus declaration on the
 * product, and found by Retrieval.
 */
class Bridge {

	private const TYPES = [
		'scsl_sermon'    => 'sermon',
		'scsl_topic'     => 'topic',
		'scsl_scripture' => 'passage',
		'scsl_series'    => 'series',
		'scsl_speaker'   => 'speaker',
	];

	public static function init(): void {
		add_filter( 'seedcast/ask/type_of', [ __CLASS__, 'type_of' ], 10, 2 );
		add_filter( 'seedcast/ask/suggestions', [ __CLASS__, 'suggestions' ] );
		add_filter( 'seedcast/ask/promote_fields', [ __CLASS__, 'promote_fields' ], 10, 3 );
		add_filter( 'seedcast/ask/promote', [ __CLASS__, 'promote' ], 10, 4 );
	}

	public static function type_of( $type, $registered = '' ): string {
		return self::TYPES[ (string) $registered ] ?? (string) $type;
	}

	/**
	 * @param mixed $suggestions What the engine and others suggested.
	 * @return string[]
	 */
	public static function suggestions( $suggestions ): array {
		$suggestions = is_array( $suggestions ) ? $suggestions : [];

		$topics = get_terms( [
			'taxonomy'   => 'scsl_topic',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 3,
		] );

		if ( is_array( $topics ) ) {
			foreach ( $topics as $topic ) {
				/* translators: %s: a topic. */
				$suggestions[] = sprintf( __( 'What have you taught about %s?', 'seedcast-sermon-library' ), html_entity_decode( $topic->name, ENT_QUOTES, 'UTF-8' ) );
			}
		}

		return $suggestions;
	}

	/**
	 * The sermon and topic rows on the engine's answer form.
	 *
	 * Sermons that can hold it: where it was asked, what the answer drew on,
	 * and whatever the search found. Only sermons, because only a sermon page
	 * renders an FAQ, and offering anything else would swallow it silently.
	 *
	 * @param mixed  $html   Fields so far.
	 * @param array  $latest The question as recorded.
	 * @param string $find   What was searched for.
	 * @return string
	 */
	public static function promote_fields( $html, $latest, $find = '' ): string {
		$html    = (string) $html;
		$latest  = is_array( $latest ) ? $latest : [];
		$sermons = [];
		$checked = [];

		if ( 'sermon' === ( $latest['from_type'] ?? '' ) && ! empty( $latest['from_id'] ) ) {
			$sermons[ (int) $latest['from_id'] ] = __( 'where it was asked', 'seedcast-sermon-library' );
		}

		foreach ( (array) ( $latest['cited'] ?? [] ) as $one ) {
			$ref = (string) ( $one['ref'] ?? '' );

			if ( preg_match( '/^sermon:(\d+)$/', $ref, $m ) && ! isset( $sermons[ (int) $m[1] ] ) ) {
				$sermons[ (int) $m[1] ] = __( 'the answer drew on it', 'seedcast-sermon-library' );
			}

			if ( preg_match( '/^topic:(\d+)$/', $ref, $m ) ) {
				$checked[] = (int) $m[1];
			}
		}

		if ( '' !== (string) $find ) {
			foreach ( get_posts( [ 'post_type' => 'scsl_sermon', 'post_status' => 'publish', 's' => (string) $find, 'posts_per_page' => 10, 'fields' => 'ids' ] ) as $id ) {
				if ( ! isset( $sermons[ (int) $id ] ) ) {
					$sermons[ (int) $id ] = __( 'search result', 'seedcast-sermon-library' );
				}
			}
		}

		ob_start();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'On a sermon', 'seedcast-sermon-library' ); ?></th>
			<td>
				<label><input type="radio" name="scsl_sermon" value="0" checked /> <?php esc_html_e( 'Not on a sermon', 'seedcast-sermon-library' ); ?></label><br />
				<?php foreach ( $sermons as $id => $why ) : ?>
					<label><input type="radio" name="scsl_sermon" value="<?php echo esc_attr( (string) $id ); ?>" /> <?php echo esc_html( get_the_title( (int) $id ) ); ?> <span class="description">(<?php echo esc_html( $why ); ?>)</span></label><br />
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Added to that sermon\'s questions and answers, where it is published and counts toward its completeness. Search above to find another sermon.', 'seedcast-sermon-library' ); ?></p>
			</td>
		</tr>
		<?php
		$terms = get_terms( [ 'taxonomy' => 'scsl_topic', 'hide_empty' => false ] );

		if ( is_array( $terms ) && $terms ) :
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Under topics', 'seedcast-sermon-library' ); ?></th>
				<td>
					<?php foreach ( $terms as $term ) : ?>
						<label style="display:inline-block;min-width:14em;"><input type="checkbox" name="scsl_topics[]" value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $checked, true ) ); ?> /> <?php echo esc_html( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Shown on each topic\'s page under "Questions people have asked". Only your existing topics can be chosen.', 'seedcast-sermon-library' ); ?></p>
				</td>
			</tr>
			<?php
		endif;

		return $html . (string) ob_get_clean();
	}

	/**
	 * Publish the answer where the church chose on the form above.
	 *
	 * Onto a sermon it joins that sermon's FAQ through the same meta a person
	 * typing one in would write, so its completeness score sees it exactly as
	 * it sees a hand-written one. Under a topic it changes no sermon's score.
	 *
	 * @param mixed  $where    Where it went so far.
	 * @param string $question The question.
	 * @param string $answer   The answer, already kses'd.
	 * @param array  $posted   The form, unslashed.
	 * @return string[]
	 */
	public static function promote( $where, $question, $answer, $posted ): array {
		$where    = is_array( $where ) ? $where : [];
		$posted   = is_array( $posted ) ? $posted : [];
		$question = sanitize_text_field( (string) $question );
		$answer   = wp_kses_post( (string) $answer );
		$sermon   = absint( $posted['scsl_sermon'] ?? 0 );
		$topics   = array_map( 'absint', (array) ( $posted['scsl_topics'] ?? [] ) );

		if ( $sermon && 'scsl_sermon' === get_post_type( $sermon ) && current_user_can( 'edit_post', $sermon ) ) {
			$pairs = array_values( array_filter(
				Faq::stored( $sermon ),
				static function ( array $pair ) use ( $question ): bool {
					return 0 !== strcasecmp( $pair['question'], $question );
				}
			) );

			$pairs[] = [ 'question' => $question, 'answer' => $answer ];
			update_post_meta( $sermon, Faq::META, $pairs );

			$where[] = 'sermon:' . $sermon;
		}

		// Existing topics only. A topic is the church's vocabulary, and
		// nothing here gets to add a word to it.
		$filed = [];

		foreach ( array_unique( array_filter( $topics ) ) as $term_id ) {
			$term = get_term( $term_id, 'scsl_topic' );

			if ( $term instanceof \WP_Term && current_user_can( 'edit_term', $term->term_id ) ) {
				TopicFaq::add( (int) $term->term_id, $question, $answer );
				$filed[] = (int) $term->term_id;
			}
		}

		if ( $filed ) {
			$where[] = 'topic:' . implode( ',', $filed );
		}

		return $where;
	}
}
