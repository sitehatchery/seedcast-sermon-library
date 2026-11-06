<?php
namespace SeedcastSermonLibrary\Ask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Questions people asked, answered and filed under a topic.
 *
 * This is where an approved question has somewhere worth being. The answer
 * itself is never a page; a topic page is one already, with a summary and the
 * sermons filed under it, and a handful of real questions about the subject
 * makes it substantially better. Rendered with FAQPage structured data, so
 * they are indexable because the page they sit on has substance of its own.
 *
 * Kept on the term as question and answer pairs, the same shape a sermon's
 * FAQ uses. Editable, and removable, on the topic's own edit screen.
 */
class TopicFaq {

	public const META = '_scsl_topic_faq';

	public static function init(): void {
		add_action( 'wp_head', [ __CLASS__, 'schema' ], 30 );
		add_action( 'scsl_topic_edit_form_fields', [ __CLASS__, 'edit_fields' ], 20 );
		add_action( 'edited_scsl_topic', [ __CLASS__, 'save_fields' ] );
	}

	/**
	 * @return array<int, array{question:string, answer:string}>
	 */
	public static function get( int $term_id ): array {
		$raw = get_term_meta( $term_id, self::META, true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];

		foreach ( $raw as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}

			$q = trim( wp_strip_all_tags( (string) ( $pair['question'] ?? '' ) ) );
			$a = trim( (string) ( $pair['answer'] ?? '' ) );

			if ( '' !== $q && '' !== wp_strip_all_tags( $a ) ) {
				$out[] = [ 'question' => $q, 'answer' => $a ];
			}
		}

		return $out;
	}

	/**
	 * File one pair under a topic. A question already there is replaced
	 * rather than repeated.
	 */
	public static function add( int $term_id, string $question, string $answer ): void {
		$pairs = array_values( array_filter(
			self::get( $term_id ),
			static function ( array $pair ) use ( $question ): bool {
				return 0 !== strcasecmp( $pair['question'], $question );
			}
		) );

		$pairs[] = [
			'question' => sanitize_text_field( $question ),
			'answer'   => wp_kses_post( $answer ),
		];

		update_term_meta( $term_id, self::META, $pairs );
	}

	/**
	 * The list, for the topic page.
	 */
	public static function render( int $term_id ): string {
		$pairs = self::get( $term_id );

		if ( ! $pairs ) {
			return '';
		}

		$out  = '<section class="scsl-topic-faq">';
		$out .= '<h2 class="scsl-section-title">' . esc_html__( 'Questions people have asked', 'seedcast-sermon-library' ) . '</h2>';
		$out .= '<ol class="scsl-faq">';

		foreach ( $pairs as $pair ) {
			$out .= '<li class="scsl-faq__item"><div class="scsl-faq__body">';
			$out .= '<h3 class="scsl-faq__question">' . esc_html( $pair['question'] ) . '</h3>';
			$out .= '<div class="scsl-faq__answer">' . wp_kses_post( wpautop( $pair['answer'] ) ) . '</div>';
			$out .= '</div></li>';
		}

		return $out . '</ol></section>';
	}

	/**
	 * FAQPage structured data on a topic page that has questions.
	 */
	public static function schema(): void {
		if ( ! is_tax( 'scsl_topic' ) || is_paged() ) {
			return;
		}

		$term_id = get_queried_object_id();
		$pairs   = self::get( $term_id );

		if ( ! $pairs || ! class_exists( '\Seedcast\Core\Frontend\Schema' ) ) {
			return;
		}

		$link = get_term_link( $term_id, 'scsl_topic' );

		$entities = [];

		foreach ( $pairs as $pair ) {
			$entities[] = [
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $pair['answer'] ) ) ),
				],
			];
		}

		\Seedcast\Core\Frontend\Schema::emit( [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'@id'        => ( is_wp_error( $link ) ? '' : $link ) . '#faq',
			'mainEntity' => $entities,
		] );
	}

	/**
	 * The questions filed under a topic, editable on its own screen.
	 */
	public static function edit_fields( $term ): void {
		$pairs = self::get( (int) $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Questions people have asked', 'seedcast-sermon-library' ); ?></th>
			<td>
				<?php wp_nonce_field( 'scsl_topic_faq', 'scsl_topic_faq_nonce' ); ?>
				<?php if ( ! $pairs ) : ?>
					<p class="description"><?php esc_html_e( 'None yet. Questions visitors ask can be filed here from Sermon Library > Questions.', 'seedcast-sermon-library' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $pairs as $i => $pair ) : ?>
					<div class="scsl-topic-faq-edit" style="margin-bottom:1em;">
						<input type="text" class="large-text" name="scsl_topic_faq[<?php echo esc_attr( (string) $i ); ?>][question]" value="<?php echo esc_attr( $pair['question'] ); ?>" />
						<textarea class="large-text" rows="3" name="scsl_topic_faq[<?php echo esc_attr( (string) $i ); ?>][answer]"><?php echo esc_textarea( $pair['answer'] ); ?></textarea>
						<label><input type="checkbox" name="scsl_topic_faq[<?php echo esc_attr( (string) $i ); ?>][remove]" value="1" /> <?php esc_html_e( 'Remove', 'seedcast-sermon-library' ); ?></label>
					</div>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	public static function save_fields( $term_id ): void {
		if ( ! isset( $_POST['scsl_topic_faq_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scsl_topic_faq_nonce'] ) ), 'scsl_topic_faq' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		$rows  = isset( $_POST['scsl_topic_faq'] ) && is_array( $_POST['scsl_topic_faq'] ) ? wp_unslash( $_POST['scsl_topic_faq'] ) : [];
		$pairs = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$question = sanitize_text_field( (string) ( $row['question'] ?? '' ) );
			$answer   = wp_kses_post( (string) ( $row['answer'] ?? '' ) );

			if ( '' !== $question && '' !== trim( wp_strip_all_tags( $answer ) ) ) {
				$pairs[] = [ 'question' => $question, 'answer' => $answer ];
			}
		}

		if ( $pairs ) {
			update_term_meta( (int) $term_id, self::META, $pairs );
		} else {
			delete_term_meta( (int) $term_id, self::META );
		}
	}
}
