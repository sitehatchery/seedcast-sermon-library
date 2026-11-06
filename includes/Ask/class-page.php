<?php
namespace SeedcastSermonLibrary\Ask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ask page: one address that shows the answer to whatever was just asked,
 * the way a search results page does.
 *
 * NOTHING IS PUBLISHED HERE. An answer is shown to the person who asked and is
 * not kept as a page. A page per question would be thin content by definition,
 * and a few hundred of them is the doorway-page pattern search engines
 * penalize, on a church's domain, written by strangers. So the page is
 * noindex, sends the header as well, is never cached, and is in no sitemap
 * because it is not a post. Durable content comes only from a person promoting
 * an answer onto a page that already has substance.
 *
 * Rendered on the server, so it works with no JavaScript. Below the answer it
 * runs the site's ordinary search for the same words, which is what lets the
 * ask box take the place of the theme's search without a church losing post
 * search: the results are still here, under the answer.
 */
class Page {

	public const QUERY_VAR      = 'scsl_ask';
	public const REWRITE_OPTION = 'scsl_ask_rewrite';
	private const REWRITE_VERSION = '1';

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
		add_action( 'template_redirect', [ __CLASS__, 'handle' ], 5 );
		add_filter( 'template_include', [ __CLASS__, 'template' ], 99 );
		add_filter( 'wp_robots', [ __CLASS__, 'robots' ] );
		add_filter( 'document_title_parts', [ __CLASS__, 'title' ] );
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
	 * Whether this site can answer questions at all.
	 */
	public static function available(): bool {
		return (bool) apply_filters( 'seedcast/ask/available', false );
	}

	/**
	 * Work out the answer before anything is printed, so a redirect is still
	 * possible.
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

		$question = trim( mb_substr( $question, 0, self::MAX_CHARS ) );

		// Not entitled: the box was ordinary search all along, so this is too.
		if ( ! self::available() ) {
			wp_safe_redirect( add_query_arg( 's', rawurlencode( $question ), home_url( '/' ) ) );
			exit;
		}

		$from = self::resolve_from( $from_raw );

		self::$state = [
			'question' => $question,
			'from'     => $from,
			'from_raw' => $from_raw,
			'result'   => null,
			'message'  => '',
		];

		if ( '' === $question ) {
			return;
		}

		/*
		 * The same visitor asking the same thing again within a few minutes,
		 * usually a refresh. Shown what they were shown, without asking,
		 * counting or recording it twice.
		 */
		$again = 'scsl_ask_r_' . md5( Questions::visitor() . '|' . Questions::group_key( $question ) );
		$seen  = get_transient( $again );

		if ( is_array( $seen ) ) {
			self::$state['result'] = $seen;
			return;
		}

		$allowed = Guard::check();

		if ( Guard::OK !== $allowed ) {
			self::$state['message'] = Guard::message( $allowed );
			return;
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

		self::$state['result'] = $result;
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

		if ( $referer && 0 === strpos( $referer, home_url() ) ) {
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

	public static function template( string $template ): string {
		if ( ! self::is_ask() ) {
			return $template;
		}

		$theme = locate_template( [ 'seedcast/ask-page.php' ] );

		return $theme ? $theme : SCSL_PLUGIN_DIR . 'templates/ask/ask-page.php';
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
