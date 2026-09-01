<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Frontend
 */

namespace Seedcast\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-LD plumbing. The graphs themselves stay in the plugins, since an Event
 * and an Article with a VideoObject have nothing in common.
 *
 * breadcrumbs() takes the same array as Breadcrumb::render() on purpose: build
 * the trail and the structured data from one source and they can't disagree.
 */
final class Schema {

	/**
	 * Print a JSON-LD block.
	 *
	 * @param array $data Graph to emit.
	 * @return void
	 */
	public static function emit( array $data ): void {
		if ( empty( $data ) ) {
			return;
		}

		// JSON_HEX_TAG escapes '<' to '\u003C' so a value containing
		// '</script>' cannot terminate the script block. Combined with
		// JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE for readability
		// of URLs and non-ASCII text in the graph.
		$json = wp_json_encode(
			$data,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);
		if ( ! $json ) {
			return;
		}

		// Safe to echo unescaped: JSON_HEX_TAG guarantees no raw '<' or '>'
		// characters remain in the encoded output, so the string cannot
		// break out of the surrounding script block.
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $json contains only HEX-tag-encoded JSON, no raw HTML tokens.
	}

	/**
	 * A BreadcrumbList graph from the same crumbs the visible trail uses.
	 *
	 * @param array $crumbs Each: [ 'label' => string, 'url' => string ]. The
	 *                      last crumb is the current page and may omit 'url'.
	 *                      'name' is accepted as a synonym for 'label', because
	 *                      that is what existing plugin code already passes and
	 *                      quietly renaming it in every caller is how you end up
	 *                      shipping an empty BreadcrumbList nobody notices.
	 * @return array
	 */
	public static function breadcrumbs( array $crumbs ): array {
		$items = array();
		$position = 1;

		foreach ( $crumbs as $crumb ) {
			$label = (string) ( $crumb['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$item = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $label,
			);
			if ( ! empty( $crumb['url'] ) ) {
				$item['item'] = $crumb['url'];
			}
			$items[]  = $item;
			$position++;
		}

		if ( empty( $items ) ) {
			return array();
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

}
