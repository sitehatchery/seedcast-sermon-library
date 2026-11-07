<?php
namespace SeedcastSermonLibrary\Ask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ask page: one address where a visitor asks the library questions and
 * reads the answers, the way a search results page works.
 *
 * NOTHING IS PUBLISHED HERE. An answer is shown to the person who asked and is
 * not kept as a page. A page per question would be thin content by definition,
 * and a few hundred of them is the doorway-page pattern search engines
 * penalize, on a church's domain, written by strangers. So the page is
 * noindex, sends the header as well, is never cached, and is in no sitemap
 * because it is not a post. Durable content comes only from a person promoting
 * an answer onto a page that already has substance.
 *
 * It reads as a conversation. The first question arrives with the page and is
 * answered on the server, so it works with no JavaScript at all. After that,
 * questions are asked in place through the REST route below, which answers
 * with the same rendered turn the page would have printed, so the two can
 * never look different.
 *
 * WordPress is told this is a page of its own. Left alone, a request with
 * nothing but a query var is the blog home, and a page builder dresses it in
 * whatever the posts page was built with.
 */
class Page {

	public const QUERY_VAR      = 'scsl_ask';
	public const REWRITE_OPTION = 'scsl_ask_rewrite';
	private const REWRITE_VERSION = '1';

	public const REST_NAMESPACE = 'scsl/v1';
	public const REST_ROUTE     = '/ask';

	/** Longest question taken. */
	public const MAX_CHARS = 300;

	/**
	 * What this request asked and got, worked out before the template runs.
	 *
	 * @var array|null
	 */
	private static $state = null;

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'rewrite' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'parse_query', [ __CLASS__, 'own_page' ] );
		add_filter( 'posts_pre_query', [ __CLASS__, 'no_posts' ], 10, 2 );
		// A main query with no posts is a 404 unless WordPress is told otherwise.
		add_filter( 'pre_handle_404', [ __CLASS__, 'not_404' ], 10, 2 );
		add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle' ], 5 );
		add_filter( 'template_include', [ __CLASS__, 'template' ], 99 );
		add_filter( 'wp_robots', [ __CLASS__, 'robots' ] );
		add_filter( 'document_title_parts', [ __CLASS__, 'title' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ], 30 );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );
	}

	/**
	 * The address, /ask/ unless a church already has something there.
	 */
	public static function slug(): string {
		$slug = sanitize_title( (string) apply_filters( 'scsl_ask_slug', 'ask' ) );
		return '' !== $slug ? $slug : 'ask';
	}

	public static function url( array $args = [] ): string {
		$base = home_url( user_trailingslashit( self::slug() ) );

		// Without pretty permalinks the rewrite never matches.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$base = add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
		}

		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $base ) : $base;
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^' . preg_quote( self::slug(), '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		// Once, when the rule is new, rather than on every request.
		if ( get_option( self::REWRITE_OPTION ) !== self::REWRITE_VERSION . ':' . self::slug() ) {
			update_option( self::REWRITE_OPTION, self::REWRITE_VERSION . ':' . self::slug() );
			update_option( 'scsl_flush_rewrite_rules', '1' );
		}
	}

	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function is_ask(): bool {
		return '1' === (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Not the blog home, not an archive, not a 404: a page of its own.
	 *
	 * @param \WP_Query $query The query being parsed.
	 */
	public static function own_page( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || '1' !== (string) $query->get( self::QUERY_VAR ) ) {
			return;
		}

		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_404      = false;
		$query->is_singular = false;
		$query->is_page     = false;
	}

	/**
	 * The main query on the ask page fetches nothing: there are no posts here.
	 *
	 * @param array|null $posts Posts, or null to let WordPress query.
	 * @param \WP_Query  $query The query.
	 * @return array|null
	 */
	public static function no_posts( $posts, $query ) {
		if ( $query instanceof \WP_Query && $query->is_main_query() && '1' === (string) $query->get( self::QUERY_VAR ) ) {
			return [];
		}

		return $posts;
	}

	/**
	 * The ask page is found, whatever its main query held.
	 *
	 * @param bool      $handled Whether something already handled it.
	 * @param \WP_Query $query   The main query.
	 * @return bool
	 */
	public static function not_404( $handled, $query ) {
		if ( $query instanceof \WP_Query && '1' === (string) $query->get( self::QUERY_VAR ) ) {
			status_header( 200 );
			return true;
		}

		return $handled;
	}

	public static function body_class( array $classes ): array {
		if ( self::is_ask() ) {
			$classes   = array_values( array_diff( $classes, [ 'blog', 'home' ] ) );
			$classes[] = 'scsl-ask-page';
		}

		return $classes;
	}

	/**
	 * Whether this site can answer questions at all.
	 */
	public static function available(): bool {
		return (bool) apply_filters( 'seedcast/ask/available', false );
	}

	/**
	 * Work out the first answer before anything is printed, so a redirect is
	 * still possible.
	 */
	public static function handle(): void {
		if ( ! self::is_ask() ) {
			return;
		}

		// Never cached, by anybody. The page answers one person.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, follow', true );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public read-only form, like search.
		$question = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$from_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Not entitled: the box was ordinary search all along, so this is too.
		if ( ! self::available() ) {
			wp_safe_redirect( add_query_arg( 's', rawurlencode( $question ), home_url( '/' ) ) );
			exit;
		}

		self::$state = self::ask( $question, $from_raw );
	}

	/**
	 * Ask one question and record it. Shared by the page and the REST route,
	 * so a question asked either way is guarded, counted and recorded the
	 * same way.
	 *
	 * @param string $question As typed.
	 * @param string $from_raw post:ID, term:ID, or empty.
	 * @return array{question:string, from:array, from_raw:string, result:?array, message:string}
	 */
	public static function ask( string $question, string $from_raw ): array {
		$question = trim( mb_substr( $question, 0, self::MAX_CHARS ) );
		$from     = self::resolve_from( $from_raw );

		$state = [
			'question' => $question,
			'from'     => $from,
			'from_raw' => $from_raw,
			'result'   => null,
			'message'  => '',
		];

		if ( '' === $question ) {
			return $state;
		}

		/*
		 * The same visitor asking the same thing again within a few minutes,
		 * usually a refresh. Shown what they were shown, without asking,
		 * counting or recording it twice.
		 */
		$again = 'scsl_ask_r_' . md5( Questions::visitor() . '|' . Questions::group_key( $question ) );
		$seen  = get_transient( $again );

		if ( is_array( $seen ) ) {
			$state['result'] = $seen;
			return $state;
		}

		$allowed = Guard::check();

		if ( Guard::OK !== $allowed ) {
			$state['message'] = Guard::message( $allowed );
			return $state;
		}

		Guard::count();

		$result = apply_filters( 'seedcast/ask/answer', null, $question, [ 'from' => $from ] );

		if ( ! is_array( $result ) ) {
			$result = [
				'status' => 'unavailable',
				'answer' => '',
				'found'  => false,
				'cited'  => [],
			];
		}

		Questions::record( $question, $from, $result, Questions::visitor() );

		set_transient( $again, $result, 10 * MINUTE_IN_SECONDS );

		$state['result'] = $result;

		return $state;
	}

	/**
	 * POST /wp-json/scsl/v1/ask { q, from }: the next turn of the
	 * conversation, rendered.
	 *
	 * No nonce, for the same reason the view counter has none: the ask page
	 * may be served from a cache for longer than a nonce lives, and every
	 * question after that would be refused. What a forged request could do is
	 * ask a question, which anybody can do anyway, and the guard holds the
	 * same ceilings here as on the page.
	 */
	public static function rest(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => [
					'q'    => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'from' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
				'callback'            => [ __CLASS__, 'rest_answer' ],
			]
		);
	}

	public static function rest_answer( \WP_REST_Request $request ): \WP_REST_Response {
		nocache_headers();

		if ( ! self::available() ) {
			return new \WP_REST_Response( [ 'status' => 'unavailable', 'html' => '' ], 200 );
		}

		$state = self::ask( (string) $request->get_param( 'q' ), (string) $request->get_param( 'from' ) );

		$response = new \WP_REST_Response(
			[
				'status' => '' !== $state['message'] ? 'limited' : (string) ( $state['result']['status'] ?? '' ),
				'html'   => self::render_answer( $state ),
			],
			200
		);
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}

	/**
	 * What the page was asked from, as a post or term where it can be.
	 *
	 * From the box's hidden field first, which knows exactly which page it was
	 * on; then the referring address, for a link someone followed in.
	 *
	 * @return array{type:string, id:int, title:string, url:string}
	 */
	public static function resolve_from( string $raw ): array {
		$none = [ 'type' => '', 'id' => 0, 'title' => '', 'url' => '' ];

		if ( preg_match( '/^(post|term):(\d+)$/', $raw, $found ) ) {
			if ( 'post' === $found[1] ) {
				return self::from_post( (int) $found[2] ) ?? $none;
			}

			return self::from_term( (int) $found[2] ) ?? $none;
		}

		$referer = wp_get_referer();

		if ( $referer && 0 === strpos( $referer, home_url() ) && false === strpos( $referer, '/' . self::slug() ) ) {
			$id = url_to_postid( $referer );

			if ( $id ) {
				return self::from_post( $id ) ?? $none;
			}

			if ( untrailingslashit( $referer ) === untrailingslashit( home_url() ) ) {
				return [ 'type' => 'home', 'id' => 0, 'title' => __( 'Home page', 'seedcast-sermon-library' ), 'url' => home_url( '/' ) ];
			}
		}

		return $none;
	}

	private static function from_post( int $id ): ?array {
		$post = get_post( $id );

		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return null;
		}

		return [
			'type'  => 'scsl_sermon' === $post->post_type ? 'sermon' : sanitize_key( $post->post_type ),
			'id'    => (int) $post->ID,
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'   => (string) get_permalink( $post ),
		];
	}

	private static function from_term( int $id ): ?array {
		$term = get_term( $id );

		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$link = get_term_link( $term );
		$map  = [
			'scsl_topic'     => 'topic',
			'scsl_scripture' => 'passage',
		];

		return [
			'type'  => $map[ $term->taxonomy ] ?? sanitize_key( $term->taxonomy ),
			'id'    => (int) $term->term_id,
			'title' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'url'   => is_wp_error( $link ) ? '' : (string) $link,
		];
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * One turn of the conversation: the question, and what came back.
	 *
	 * Rendered here so the page and the REST route print exactly the same
	 * thing. Everything in it is escaped as it is built.
	 *
	 * @param array $state As ask() returns it.
	 * @return string
	 */
	public static function render_turn( array $state ): string {
		if ( '' === $state['question'] ) {
			return '';
		}

		$out  = '<div class="scsl-ask__turn">';
		$out .= '<div class="scsl-ask__q"><p>' . esc_html( $state['question'] ) . '</p></div>';
		$out .= self::render_answer( $state );

		return $out . '</div>';
	}

	/**
	 * The answer half of a turn.
	 *
	 * @param array $state As ask() returns it.
	 * @return string
	 */
	public static function render_answer( array $state ): string {
		$result = is_array( $state['result'] ) ? $state['result'] : [];
		$status = (string) ( $result['status'] ?? '' );
		$cited  = (array) ( $result['cited'] ?? [] );

		$out = '<div class="scsl-ask__a" data-status="' . esc_attr( '' !== $state['message'] ? 'limited' : $status ) . '">';
		$out .= '<span class="scsl-ask__mark" aria-hidden="true">' . self::mark() . '</span>';
		$out .= '<div class="scsl-ask__body">';

		if ( '' !== $state['message'] ) {
			$out .= '<p class="scsl-ask__text">' . esc_html( $state['message'] ) . '</p>';
		} elseif ( 'capped' === $status || 'unavailable' === $status || '' === $status ) {
			$out .= '<p class="scsl-ask__text">' . esc_html__( 'We can\'t answer questions right now. The links below may still help.', 'seedcast-sermon-library' ) . '</p>';
		} else {
			$out .= '<div class="scsl-ask__text">' . wpautop( esc_html( (string) ( $result['answer'] ?? '' ) ) ) . '</div>';

			if ( $cited ) {
				$out .= '<p class="scsl-ask__label">' . esc_html( 'answered' === $status ? __( 'Where this came from', 'seedcast-sermon-library' ) : __( 'The nearest we have', 'seedcast-sermon-library' ) ) . '</p>';
				$out .= '<ul class="scsl-ask__sources">';

				foreach ( $cited as $source ) {
					$out .= '<li><a class="scsl-ask__source" href="' . esc_url( (string) ( $source['url'] ?? '' ) ) . '" rel="nofollow">';
					$out .= '<span class="scsl-ask__kind">' . esc_html( self::kind_label( (string) ( $source['type'] ?? '' ) ) ) . '</span>';
					$out .= '<span class="scsl-ask__title">' . esc_html( (string) ( $source['title'] ?? '' ) ) . '</span>';

					if ( ! empty( $source['reason'] ) ) {
						$out .= '<span class="scsl-ask__why">' . esc_html( ucfirst( (string) $source['reason'] ) ) . '</span>';
					}

					$out .= '</a></li>';
				}

				$out .= '</ul>';
			}

			$out .= self::render_follow_ups( $cited );
		}

		$out .= self::render_pages( $state['question'] );
		$out .= '</div></div>';

		return $out;
	}

	/**
	 * Questions worth asking next, from what this answer drew on.
	 */
	private static function render_follow_ups( array $cited ): string {
		$asks = [];

		foreach ( $cited as $source ) {
			$title = (string) ( $source['title'] ?? '' );

			if ( 'topic' === ( $source['type'] ?? '' ) ) {
				/* translators: %s: a topic. */
				$asks[] = sprintf( __( 'What else have you taught about %s?', 'seedcast-sermon-library' ), $title );
			} elseif ( 'passage' === ( $source['type'] ?? '' ) ) {
				/* translators: %s: a passage of scripture. */
				$asks[] = sprintf( __( 'What have you preached from %s?', 'seedcast-sermon-library' ), $title );
			}
		}

		return self::render_chips( array_slice( array_unique( $asks ), 0, 2 ), 'scsl-ask__next' );
	}

	/**
	 * The site's ordinary search for the same words, folded away under the
	 * answer, so the box can stand where the theme's search did without
	 * anybody losing it.
	 */
	private static function render_pages( string $question ): string {
		$search = new \WP_Query( [
			's'              => $question,
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'no_found_rows'  => true,
			'has_password'   => false,
		] );

		// A long question rarely matches every word; its longest word might.
		if ( ! $search->have_posts() ) {
			$words = Questions::words( $question );
			usort(
				$words,
				static function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);

			if ( $words ) {
				$search = new \WP_Query( [
					's'              => (string) $words[0],
					'post_type'      => 'any',
					'post_status'    => 'publish',
					'posts_per_page' => 5,
					'no_found_rows'  => true,
					'has_password'   => false,
				] );
			}
		}

		if ( ! $search->have_posts() ) {
			return '';
		}

		$out = '<details class="scsl-ask__pages"><summary>' . esc_html__( 'Pages on this site that mention it', 'seedcast-sermon-library' ) . '</summary><ul>';

		foreach ( $search->posts as $post ) {
			$out .= '<li><a href="' . esc_url( (string) get_permalink( $post ) ) . '" rel="nofollow">' . esc_html( get_the_title( $post ) ) . '</a></li>';
		}

		return $out . '</ul></details>';
	}

	/**
	 * Questions a visitor could start with, from this church's own content:
	 * service times where the church has given them, then the subjects it has
	 * preached on most.
	 *
	 * @return string[]
	 */
	public static function suggestions(): array {
		$out = [];

		if ( class_exists( '\Seedcast\Core\Church' ) && '' !== \Seedcast\Core\Church::service_times() ) {
			$out[] = __( 'What time are your services?', 'seedcast-sermon-library' );
		}

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
				$out[] = sprintf( __( 'What have you taught about %s?', 'seedcast-sermon-library' ), html_entity_decode( $topic->name, ENT_QUOTES, 'UTF-8' ) );
			}
		}

		return array_slice( $out, 0, 4 );
	}

	/**
	 * Questions as buttons. With JavaScript they are asked in place; without
	 * it they are links that ask the same question.
	 *
	 * @param string[] $questions Questions.
	 * @param string   $class     Extra class on the list.
	 */
	public static function render_chips( array $questions, string $class = '' ): string {
		if ( ! $questions ) {
			return '';
		}

		$out = '<ul class="scsl-ask__chips ' . esc_attr( $class ) . '">';

		foreach ( $questions as $question ) {
			$out .= '<li><a class="scsl-ask__chip" href="' . esc_url( self::url( [ 'q' => $question ] ) ) . '" data-ask="' . esc_attr( $question ) . '" rel="nofollow">' . esc_html( $question ) . '</a></li>';
		}

		return $out . '</ul>';
	}

	private static function kind_label( string $type ): string {
		$labels = [
			'sermon'  => __( 'Sermon', 'seedcast-sermon-library' ),
			'topic'   => __( 'Topic', 'seedcast-sermon-library' ),
			'passage' => __( 'Scripture', 'seedcast-sermon-library' ),
			'speaker'      => __( 'Speaker', 'seedcast-sermon-library' ),
			'announcement' => __( 'Announcement', 'seedcast-sermon-library' ),
			'church'  => __( 'About us', 'seedcast-sermon-library' ),
		];

		return $labels[ $type ] ?? __( 'Page', 'seedcast-sermon-library' );
	}

	/**
	 * The small mark beside each answer: a few lines of sound, in the
	 * church's accent color.
	 */
	public static function mark(): string {
		return '<svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><path fill="currentColor" d="M11 3h2v18h-2zM6 8h2v8H6zM16 6h2v12h-2zM1 11h2v2H1zM21 10h2v4h-2z"/></svg>';
	}

	/* ---------------------------------------------------------------------
	 * Page plumbing
	 * ------------------------------------------------------------------ */

	public static function template( string $template ): string {
		if ( ! self::is_ask() ) {
			return $template;
		}

		$theme = locate_template( [ 'seedcast/ask-page.php' ] );

		return $theme ? $theme : SCSL_PLUGIN_DIR . 'templates/ask/ask-page.php';
	}

	public static function assets(): void {
		if ( ! self::is_ask() ) {
			return;
		}

		wp_enqueue_style( 'scsl-ask', SCSL_PLUGIN_URL . 'assets/css/ask.css', [], scsl_asset_version( 'assets/css/ask.css' ) );
		wp_enqueue_script( 'scsl-ask', SCSL_PLUGIN_URL . 'assets/js/ask.js', [], scsl_asset_version( 'assets/js/ask.js' ), true );
		wp_localize_script( 'scsl-ask', 'scslAsk', [
			'endpoint' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
			'url'      => self::url(),
			'mark'     => self::mark(),
			'thinking' => [
				__( 'Looking through our sermons', 'seedcast-sermon-library' ),
				__( 'Reading what we have taught', 'seedcast-sermon-library' ),
				__( 'Writing an answer', 'seedcast-sermon-library' ),
			],
			'failed'   => __( 'Something went wrong asking that. Please try again in a moment.', 'seedcast-sermon-library' ),
			// Logged-in staff only, so a REST request knows who they are and
			// the per-visitor limit leaves them alone. A visitor's page, which
			// may be cached, carries none.
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		] );
	}

	public static function robots( array $robots ): array {
		if ( self::is_ask() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'], $robots['max-image-preview'] );
		}

		return $robots;
	}

	public static function title( array $parts ): array {
		if ( self::is_ask() ) {
			$parts['title'] = __( 'Ask a question', 'seedcast-sermon-library' );
		}

		return $parts;
	}

	/**
	 * What the template prints from.
	 *
	 * @return array{question:string, from:array, from_raw:string, result:?array, message:string}
	 */
	public static function state(): array {
		return self::$state ?? [
			'question' => '',
			'from'     => [ 'type' => '', 'id' => 0, 'title' => '', 'url' => '' ],
			'from_raw' => '',
			'result'   => null,
			'message'  => '',
		];
	}
}
