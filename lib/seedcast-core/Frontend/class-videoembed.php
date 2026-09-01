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
 * YouTube and Vimeo URLs to embeds.
 *
 * People paste whatever the share button handed them: watch URLs, short URLs,
 * embed URLs, sometimes with a timestamp on the end. All of those work.
 *
 * YouTube goes through youtube-nocookie.com, Vimeo gets dnt=1, related videos
 * are off. Don't change those without a reason.
 */
final class VideoEmbed {

	/**
	 * Detect the platform for a URL.
	 *
	 * @param string $url Video URL.
	 * @return string 'youtube', 'vimeo', 'file', or '' when unrecognised.
	 */
	public static function platform( string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}
		if ( preg_match( '/(?:youtube\.com|youtu\.be|youtube-nocookie\.com)/i', $url ) ) {
			return 'youtube';
		}
		if ( preg_match( '/vimeo\.com/i', $url ) ) {
			return 'vimeo';
		}
		if ( preg_match( '/\.(?:mp4|webm|ogv|mov|m4v)(?:\?|#|$)/i', $url ) ) {
			return 'file';
		}
		return '';
	}

	/**
	 * The YouTube video ID from any of its URL shapes.
	 *
	 * @param string $url Video URL.
	 * @return string Empty when not a YouTube URL.
	 */
	public static function youtube_id( string $url ): string {
		$patterns = array(
			'~(?:youtube\.com|youtube-nocookie\.com)/(?:watch\?(?:.*&)?v=|embed/|v/|shorts/)([A-Za-z0-9_-]{11})~',
			'~youtu\.be/([A-Za-z0-9_-]{11})~',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $url, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/**
	 * The Vimeo video ID.
	 *
	 * @param string $url Video URL.
	 * @return string Empty when not a Vimeo URL.
	 */
	public static function vimeo_id( string $url ): string {
		if ( preg_match( '~vimeo\.com/(?:video/|channels/[A-Za-z0-9_-]+/|groups/[A-Za-z0-9_-]+/videos/)?(\d+)~', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Seconds from a timestamp written as seconds, mm:ss, or hh:mm:ss.
	 *
	 * @param string $timestamp Timestamp as typed by a person.
	 * @return int Seconds, or 0.
	 */
	public static function seconds( string $timestamp ): int {
		$timestamp = trim( $timestamp );
		if ( '' === $timestamp ) {
			return 0;
		}
		$parts = array_map( 'absint', explode( ':', $timestamp ) );
		$count = count( $parts );

		if ( 3 === $count ) {
			return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
		}
		if ( 2 === $count ) {
			return $parts[0] * 60 + $parts[1];
		}
		return $parts[0];
	}

	/**
	 * The embeddable URL for a pasted video URL.
	 *
	 * @param string $url        Video URL as pasted.
	 * @param string $start_time Optional start timestamp.
	 * @return string Empty when the URL cannot be embedded.
	 */
	public static function embed_url( string $url, string $start_time = '' ): string {
		$start = self::seconds( $start_time );

		switch ( self::platform( $url ) ) {
			case 'youtube':
				$id = self::youtube_id( $url );
				if ( '' === $id ) {
					return '';
				}
				$params = 'rel=0&modestbranding=1';
				if ( $start ) {
					$params .= '&start=' . $start;
				}
				return 'https://www.youtube-nocookie.com/embed/' . $id . '?' . $params;

			case 'vimeo':
				$id = self::vimeo_id( $url );
				if ( '' === $id ) {
					return '';
				}
				$embed = 'https://player.vimeo.com/video/' . $id . '?dnt=1';
				if ( $start ) {
					$embed .= '#t=' . $start . 's';
				}
				return $embed;
		}

		return '';
	}

	/**
	 * Print a responsive 16:9 embed, or a native player for a hosted file.
	 *
	 * @param string $url        Video URL.
	 * @param array  $args       { @type string $start_time. @type string $title. @type string $class. }
	 * @return void
	 */
	public static function render( string $url, array $args = array() ): void {
		$args = wp_parse_args(
			$args,
			array(
				'start_time' => '',
				'title'      => __( 'Video', 'seedcast-sermon-library' ),
				'class'      => '',
			)
		);

		$platform = self::platform( $url );
		$class    = trim( 'sc-video-wrap ' . (string) $args['class'] );

		if ( 'file' === $platform ) {
			printf(
				'<div class="%s"><video src="%s" controls preload="metadata" class="sc-video"></video></div>',
				esc_attr( $class ),
				esc_url( $url )
			);
			return;
		}

		$embed = self::embed_url( $url, (string) $args['start_time'] );
		if ( '' === $embed ) {
			return;
		}

		printf(
			'<div class="%s"><iframe src="%s" title="%s" loading="lazy" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>',
			esc_attr( $class ),
			esc_url( $embed ),
			esc_attr( $args['title'] )
		);
	}
}
