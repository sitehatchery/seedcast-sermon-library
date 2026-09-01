<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Frontend
 */
namespace Seedcast\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared audio player: a labeled card wrapping a native <audio controls>
 * element with an explicit MIME-typed <source>, so playback doesn't depend
 * on the [audio] shortcode or mediaelement.js - both of which have proven
 * unreliable for some hosting setups (notably .m4a files, which need an
 * explicit type="audio/mp4" hint in some browsers).
 *
 * Streaming links (Spotify, Apple Podcasts, SoundCloud) are skipped
 * entirely - those aren't playable via a bare <audio> tag and should be
 * surfaced as an outbound link instead, which callers handle separately.
 *
 * @package Seedcast\Core\Frontend
 */
class AudioPlayer {

	/**
	 * Render the player. Outputs nothing for streaming/podcast platform URLs.
	 *
	 * @param string $url   Direct audio file URL (mp3/ogg/wav/m4a/etc).
	 * @param string $label Card label, e.g. "Listen to this sermon".
	 */
	public static function render( string $url, string $label ): void {
		if ( '' === trim( $url ) || self::is_streaming_url( $url ) ) return;

		$mime = self::mime_for( $url );
		$id   = 'sc-audio-label-' . substr( md5( $url ), 0, 8 );
		?>
		<div class="sc-audio-player">
			<p class="sc-audio-player__label" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></p>
			<audio controls preload="metadata" aria-labelledby="<?php echo esc_attr( $id ); ?>" style="width:100%;">
				<source src="<?php echo esc_url( $url ); ?>" type="<?php echo esc_attr( $mime ); ?>" />
				<?php esc_html_e( 'Your browser does not support the audio element.', 'seedcast-sermon-library' ); ?>
			</audio>
		</div>
		<?php
	}

	private static function is_streaming_url( string $url ): bool {
		foreach ( [ 'spotify.com', 'podcasts.apple', 'soundcloud.com' ] as $host ) {
			if ( false !== strpos( $url, $host ) ) return true;
		}
		return false;
	}

	private static function mime_for( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: $url;
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'ogg':  return 'audio/ogg';
			case 'wav':  return 'audio/wav';
			case 'm4a':  return 'audio/mp4';
			case 'flac': return 'audio/flac';
			case 'mp3':
			default:     return 'audio/mpeg';
		}
	}
}
