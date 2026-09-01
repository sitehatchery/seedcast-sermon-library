<?php
/**
 * Partial: Video Embed
 *
 * The URL parsing and the embed markup live in the shared library, so YouTube
 * privacy mode, the Vimeo do-not-track flag and the timestamp handling are
 * defined once for the whole suite rather than per template.
 *
 * Variables: $url (string), $type (string, unused, kept for callers),
 *            $start_time (string, optional).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( empty( $url ) ) return;

\Seedcast\Core\Frontend\VideoEmbed::render(
	(string) $url,
	[
		'start_time' => isset( $start_time ) ? (string) $start_time : '',
		'title'      => get_the_title(),
		'class'      => 'sc-video-wrap',
	]
);
