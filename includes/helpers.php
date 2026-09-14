<?php
/**
 * Template helpers.
 *
 * Plain functions rather than a class, because templates call them directly
 * and a theme that overrides a template should be able to use them too.
 *
 * @package SeedcastSermonLibrary
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'scsl_demote_headings' ) ) {
	/**
	 * Push a block of written content one level down the outline.
	 *
	 * A sermon page is one document: the sermon title is its H1 and each
	 * content section is an H2 beneath it. The article and the Bible study
	 * arrive with headings of their own, written without any knowledge of
	 * where they would end up, and those start at H2. Left alone they sit
	 * level with the section heading above them rather than inside it, which
	 * reads to a crawler and a screen reader as a flat page rather than a
	 * structured one.
	 *
	 * Only the output is changed. What is stored stays exactly as it was, so
	 * the editor, the PDFs and anything else reading these fields are
	 * unaffected, and switching this off would restore the old markup exactly.
	 *
	 * @param string $html Stored content.
	 * @param int    $by   How many levels to move down.
	 * @return string
	 */
	function scsl_demote_headings( $html, $by = 1 ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) return $html;

		$by = max( 1, min( 4, (int) $by ) );

		/**
		 * Whether to renumber headings inside written content.
		 *
		 * @param bool   $enabled Currently true.
		 * @param string $html    The content about to be rendered.
		 */
		if ( ! apply_filters( 'scsl_demote_headings', true, $html ) ) return $html;

		// Descending, so an h2 that becomes an h3 is not then found again and
		// pushed on to h4. H6 has nowhere lower to go and is left where it is.
		for ( $level = 6; $level >= 1; $level-- ) {
			$target = min( 6, $level + $by );

			if ( $target === $level ) continue;

			$html = preg_replace(
				'#<(/?)h' . $level . '(\s[^>]*)?>#i',
				'<$1h' . $target . '$2>',
				$html
			);
		}

		return $html;
	}
}

/**
 * A cache-busting version for a bundled asset.
 *
 * The plugin version alone is not enough: it changes on release, but a
 * stylesheet edited between releases keeps the same URL, so browsers and page
 * caches keep serving the old file and the change appears not to have worked.
 * The file's own timestamp changes exactly when its contents do, which is the
 * question being asked.
 *
 * @param string $relative Path under the plugin directory, e.g. assets/css/frontend.css.
 * @return string
 */
function scsl_asset_version( string $relative ): string {
	$path = SCSL_PLUGIN_DIR . ltrim( $relative, '/' );

	if ( is_readable( $path ) ) {
		$mtime = filemtime( $path );

		if ( $mtime ) {
			return SCSL_VERSION . '.' . $mtime;
		}
	}

	return SCSL_VERSION;
}

if ( ! function_exists( 'scsl_do_gallery_shortcodes' ) ) {
	/**
	 * Run the [gallery] shortcodes in an editor field, and no other shortcode.
	 *
	 * The sermon's Article, Bible Study and More fields each have an Add Media
	 * button, and Add Media > Create Gallery inserts a [gallery]. The template
	 * prints those fields as HTML without running shortcodes, so the gallery
	 * came out as its own shortcode text. Running only [gallery] keeps the
	 * change to what that button makes: nothing else typed in square brackets
	 * starts behaving differently.
	 *
	 * A shortcode alone on a line ends up wrapped in a paragraph, and a
	 * gallery inside a <p> is invalid markup, so that wrapper comes off first,
	 * the same way WordPress does it for post content.
	 *
	 * @param string $html Field HTML.
	 * @return string
	 */
	function scsl_do_gallery_shortcodes( $html ) {
		$html = (string) $html;
		if ( false === strpos( $html, '[gallery' ) ) {
			return $html;
		}
		$html = shortcode_unautop( $html );
		return (string) preg_replace_callback( '/' . get_shortcode_regex( array( 'gallery' ) ) . '/', 'do_shortcode_tag', $html );
	}
}
