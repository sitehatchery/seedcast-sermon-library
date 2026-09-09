<?php
namespace SeedcastSermonLibrary\Frontend;

use SeedcastSermonLibrary\Scripture\ScriptureParser;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A single file describing the whole sermon library, for language models.
 *
 * An assistant asked what a church teaches about forgiveness has two options:
 * crawl a hundred and thirty sermon pages, or read one file that says what each
 * sermon covers and where it lives. The second is what this serves, at
 * /llms-full.txt.
 *
 * It is deliberately not the transcripts. Those run to several megabytes and
 * nothing would read them all; a title, the passage it was built on, the
 * speaker, the date and a sentence of substance is enough to decide which
 * sermon answers a question, and the address is right there to go and read it.
 *
 * This exists because the llms.txt an SEO plugin generates lists a handful of
 * recent items per content type. That is a reasonable summary of a blog and a
 * poor summary of a library whose whole value is the hundred and thirty sermons
 * it does not mention.
 */
class LlmsIndex {

	/** Query var that selects this document. */
	private const VAR = 'scsl_llms_full';

	/** Where the built file is kept between sermon edits. */
	private const CACHE_KEY = 'scsl_llms_full_txt';

	/** Longest description carried per sermon. */
	private const ABSTRACT_CHARS = 400;

	/**
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );

		/*
		 * WordPress adds a trailing slash to anything it treats as a page, so
		 * /llms-full.txt answered with a redirect to /llms-full.txt/ before it
		 * answered with the file. A crawler asking for the documented address
		 * should get the document, not a hop.
		 */
		add_filter( 'redirect_canonical', [ $this, 'no_canonical_redirect' ] );
		add_filter( 'robots_txt', [ $this, 'advertise' ], 10, 2 );

		// Any change to a sermon changes the document.
		add_action( 'save_post_scsl_sermon', [ $this, 'flush' ] );
		add_action( 'deleted_post', [ $this, 'flush' ] );
	}

	/**
	 * @return void
	 */
	public function add_rewrite(): void {
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::VAR . '=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::VAR;

		return $vars;
	}

	/**
	 * Leave this request's address alone.
	 *
	 * @param string|false $redirect
	 * @return string|false
	 */
	public function no_canonical_redirect( $redirect ) {
		return get_query_var( self::VAR ) ? false : $redirect;
	}

	/**
	 * Point crawlers at it from the one file they all fetch first.
	 *
	 * Only reaches a site whose robots.txt WordPress actually serves. A real
	 * robots.txt file in the web root is returned by the server before PHP is
	 * involved, and this filter never runs; such a site needs the line added to
	 * that file by hand.
	 *
	 * @param string $output
	 * @param string $public
	 * @return string
	 */
	public function advertise( $output, $public ): string {
		if ( '1' !== (string) $public ) {
			return (string) $output;
		}

		return rtrim( (string) $output, "\n" ) . "\n\n# Sermon library index for language models\n"
			. 'Llms-full: ' . home_url( '/llms-full.txt' ) . "\n";
	}

	/**
	 * Throw the built document away so the next request rebuilds it.
	 *
	 * @return void
	 */
	public function flush(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Serve the document, if this request asked for it.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( ! get_query_var( self::VAR ) ) {
			return;
		}

		$body = get_transient( self::CACHE_KEY );

		if ( false === $body ) {
			$body = $this->build();

			set_transient( self::CACHE_KEY, $body, WEEK_IN_SECONDS );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text document, assembled below.
		exit;
	}

	/**
	 * Assemble the document.
	 *
	 * Grouped by series, because that is how the teaching was actually given
	 * and it tells a reader which sermons belong together.
	 *
	 * @return string
	 */
	private function build(): string {
		$sermons = get_posts( [
			'post_type'      => 'scsl_sermon',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$out  = '# ' . get_bloginfo( 'name' ) . ' sermon library' . "\n\n";
		$out .= '> ' . get_bloginfo( 'description' ) . "\n\n";
		$out .= 'Every sermon this church has published, with the passage it was built on and what it covers. '
			. 'Full text, audio and video are on each sermon page.' . "\n\n";
		$out .= 'Sermons: ' . count( $sermons ) . '. Generated ' . gmdate( 'Y-m-d' ) . ".\n\n";

		$by_series = [];

		foreach ( $sermons as $sermon ) {
			$series_id = (int) get_post_meta( $sermon->ID, '_scsl_series_id', true );
			$name      = $series_id ? get_the_title( $series_id ) : '';
			$name      = ( '' !== $name ) ? $name : 'Other messages';

			$by_series[ $name ][] = $sermon;
		}

		// Largest series first: the sustained teaching matters more than a
		// one-off, and a reader skimming should meet it sooner.
		uasort( $by_series, function ( $a, $b ) {
			return count( $b ) <=> count( $a );
		} );

		foreach ( $by_series as $series => $group ) {
			$out .= '## ' . $this->plain( $series ) . "\n\n";

			foreach ( $group as $sermon ) {
				$out .= $this->entry( $sermon );
			}

			$out .= "\n";
		}

		$out .= $this->passage_section();

		return $out;
	}

	/**
	 * One sermon.
	 *
	 * @return string
	 */
	private function entry( \WP_Post $sermon ): string {
		$speaker_id = (int) get_post_meta( $sermon->ID, '_scsl_speaker_id', true );
		$speaker    = $speaker_id ? $this->plain( get_the_title( $speaker_id ) ) : '';
		$focus      = $this->plain( (string) get_post_meta( $sermon->ID, '_scsl_focus_passage', true ) );
		$date       = (string) get_post_meta( $sermon->ID, '_scsl_recorded_date', true );
		$date       = $date ? substr( $date, 0, 10 ) : get_the_date( 'Y-m-d', $sermon );

		$line = '- [' . $this->plain( get_the_title( $sermon ) ) . '](' . get_permalink( $sermon ) . ')';

		$facts = array_filter( [ $focus, $speaker, $date ] );

		if ( $facts ) {
			$line .= ' — ' . implode( ' · ', $facts );
		}

		$line .= "\n";

		$abstract = trim( (string) get_post_meta( $sermon->ID, '_scsl_content_description', true ) );

		if ( '' === $abstract ) {
			$abstract = trim( (string) $sermon->post_excerpt );
		}

		if ( '' !== $abstract ) {
			$line .= '  ' . $this->trim_to( $this->plain( $abstract ), self::ABSTRACT_CHARS ) . "\n";
		}

		return $line;
	}

	/**
	 * Where the teaching sits in the Bible, book by book.
	 *
	 * A question about a passage is one of the commonest things anybody asks,
	 * and this answers it without crawling seventeen hundred term pages.
	 *
	 * @return string
	 */
	private function passage_section(): string {
		$books = get_terms( [
			'taxonomy'   => 'scsl_scripture',
			'hide_empty' => true,
			'parent'     => 0,
		] );

		if ( is_wp_error( $books ) || ! $books ) {
			return '';
		}

		usort( $books, function ( $a, $b ) {
			return $b->count <=> $a->count;
		} );

		$out = "## Where this teaching sits in the Bible\n\n";

		foreach ( $books as $book ) {
			if ( null === ScriptureParser::extract_book( $book->name ) ) {
				continue;
			}

			$link = get_term_link( $book );

			if ( is_wp_error( $link ) ) {
				continue;
			}

			$out .= sprintf(
				"- [%s](%s) — %d sermon%s\n",
				$this->plain( $book->name ),
				$link,
				(int) $book->count,
				1 === (int) $book->count ? '' : 's'
			);
		}

		return $out . "\n";
	}

	/**
	 * Entities decoded, tags gone, whitespace flattened.
	 *
	 * Titles are stored with entities in them, and a file meant to be read as
	 * text should not contain "&#8211;".
	 *
	 * @return string
	 */
	private function plain( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Cut at a sentence if one is near the limit, otherwise at a word.
	 *
	 * @return string
	 */
	private function trim_to( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut = mb_substr( $text, 0, $limit );
		$end = max( mb_strrpos( $cut, '. ' ), mb_strrpos( $cut, '? ' ), mb_strrpos( $cut, '! ' ) );

		if ( false !== $end && $end > (int) ( $limit * 0.6 ) ) {
			return mb_substr( $cut, 0, $end + 1 );
		}

		$space = mb_strrpos( $cut, ' ' );

		return rtrim( false !== $space ? mb_substr( $cut, 0, $space ) : $cut, " ,;:" ) . '…';
	}
}
