<?php
namespace SeedcastSermonLibrary\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

class TemplateLoader {

	public function init(): void {
		add_filter( 'template_include',         [ $this, 'load_template'           ] );
		add_filter( 'query_vars',               [ $this, 'register_query_vars'     ] );
		add_filter( 'redirect_canonical',       [ $this, 'prevent_sf_redirect'     ], 10, 2 );
		add_action( 'pre_get_posts',            [ $this, 'modify_archive_query'    ] );
	}

	/**
	 * Modify the main query for our CPT archives to honour plugin settings.
	 * Only fires on the frontend main query: never on admin or secondary queries.
	 *
	 * @param \WP_Query $query The current WP_Query instance.
	 */
	public function modify_archive_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) return;

		if ( $query->is_post_type_archive( 'scsl_series' ) || $query->is_tax( 'scsl_topic' ) ) {
			$per_page = absint( get_option( 'scsl_series_per_page', 12 ) );
			if ( $per_page > 0 ) {
				$query->set( 'posts_per_page', $per_page );
			}
			$query->set( 'orderby', [ 'menu_order' => 'ASC', 'date' => 'DESC' ] );
		}

		if ( $query->is_post_type_archive( 'scsl_speaker' ) ) {
			$query->set( 'posts_per_page', -1 );
			$query->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] );
		}
	}

	/**
	 * Register sf_page so WordPress passes it through on pretty permalink URLs
	 * without stripping it or triggering a canonical redirect.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'scsl_page';
		return $vars;
	}

	/**
	 * Prevent WordPress canonical redirect from stripping ?sf_page
	 * on single CPT pages (series, sermon, speaker).
	 */
	public function prevent_sf_redirect( $redirect_url, $requested_url ) {
		if ( is_singular( [ 'scsl_series', 'scsl_sermon', 'scsl_speaker' ] ) ) {
			if ( get_query_var( 'scsl_page' ) ) {
				return false; // Don't redirect: preserve scsl_page param
			}
		}
		return $redirect_url;
	}

	public function load_template( string $template ): string {
		if ( is_singular( 'scsl_sermon' ) )              return $this->locate( 'single/single-sermon.php',             $template );
		if ( is_singular( 'scsl_series' ) )              return $this->locate( 'single/single-series.php',             $template );
		if ( is_singular( 'scsl_speaker' ) )             return $this->locate( 'single/single-speaker.php',            $template );
		if ( is_post_type_archive( 'scsl_series' ) )     return $this->locate( 'archive/archive-series.php',           $template );
		if ( is_post_type_archive( 'scsl_sermon' ) )     return $this->locate( 'archive/archive-sermon.php',           $template );
		if ( is_post_type_archive( 'scsl_speaker' ) )    return $this->locate( 'archive/archive-speaker.php',          $template );
		if ( is_tax( 'scsl_topic' ) )                    return $this->locate( 'taxonomy/taxonomy-scsl_topic.php',       $template );
		if ( is_tax( 'scsl_scripture' ) )                return $this->locate( 'taxonomy/taxonomy-scsl_scripture.php',   $template );
		return $template;
	}

	private function locate( string $path, string $fallback ): string {
		$theme_file = locate_template( 'sermon-library/' . $path );
		if ( $theme_file ) return $theme_file;
		$plugin_file = SCSL_PLUGIN_DIR . 'templates/' . $path;
		return file_exists( $plugin_file ) ? $plugin_file : $fallback;
	}

	public static function partial( string $name, array $args = [] ): void {
		$theme_file = locate_template( 'sermon-library/partials/' . $name . '.php' );
		$file = $theme_file ?: SCSL_PLUGIN_DIR . 'templates/partials/' . $name . '.php';
		if ( ! file_exists( $file ) ) return;
		if ( $args ) extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- intentional: passing named variables to template partials
		include $file;
	}
}
