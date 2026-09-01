<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Admin
 */
namespace Seedcast\Core\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared media picker field for URL-based media (video / audio / image).
 *
 * Renders a URL input paired with an "Upload / Select" button that opens the
 * WordPress media library and a "Clear" button, plus an inline preview for
 * self-hosted audio/video. The value stored is always the file URL, so it
 * works equally for pasted embed links (YouTube, Vimeo, …) and uploads.
 *
 * The behaviour is powered by the shared handlers in Seedcast's admin.js
 * (.sc-media-select / .sc-media-clear), so any suite plugin can render one of
 * these fields without shipping its own uploader JS. The calling screen is
 * responsible for wp_enqueue_media() and for enqueuing the seedcast-core-admin
 * script (child admin scripts should declare it as a dependency).
 *
 * @package Seedcast\Core\Admin
 */
class MediaField {

	/**
	 * Render a media field.
	 *
	 * @param array $args {
	 *   @type string $id          Input id AND name (unless 'name' given). Required.
	 *   @type string $name        Field name. Defaults to 'id'.
	 *   @type string $value       Current URL value.
	 *   @type string $type        '', 'video', 'audio', or 'image' - filters the
	 *                             media library and sets picker labels.
	 *   @type string $placeholder Input placeholder.
	 *   @type string $description  Help text shown under the field.
	 *   @type bool   $preview      Show inline audio/video preview for self-hosted
	 *                             files. Default true.
	 * }
	 */
	public static function render( array $args ): void {
		$args = wp_parse_args( $args, [
			'id'           => '',
			'name'         => '',
			'value'        => '',
			'type'         => '',
			'placeholder'  => '',
			'description'  => '',
			'preview'      => true,
			'select_label' => __( '↑ Upload / Select', 'seedcast-sermon-library' ),
			'clear_label'  => __( 'Clear', 'seedcast-sermon-library' ),
		] );

		if ( '' === $args['id'] ) return;

		$id    = $args['id'];
		$name  = $args['name'] !== '' ? $args['name'] : $id;
		$value = (string) $args['value'];
		$type  = (string) $args['type'];

		$titles = [
			'video' => __( 'Select or Upload Video', 'seedcast-sermon-library' ),
			'audio' => __( 'Select or Upload Audio', 'seedcast-sermon-library' ),
			'image' => __( 'Select or Upload Image', 'seedcast-sermon-library' ),
		];
		$buttons = [
			'video' => __( 'Use this video', 'seedcast-sermon-library' ),
			'audio' => __( 'Use this audio', 'seedcast-sermon-library' ),
			'image' => __( 'Use this image', 'seedcast-sermon-library' ),
		];
		$picker_title  = $titles[ $type ]  ?? __( 'Select or Upload File', 'seedcast-sermon-library' );
		$picker_button = $buttons[ $type ] ?? __( 'Use this file', 'seedcast-sermon-library' );
		?>
		<div class="sc-media-field">
			<input type="url" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
			       value="<?php echo esc_attr( $value ); ?>" class="large-text sc-media-url"
			       placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>" />
			<button type="button" class="button sc-media-select"
			        data-target="<?php echo esc_attr( $id ); ?>"
			        data-title="<?php echo esc_attr( $picker_title ); ?>"
			        data-type="<?php echo esc_attr( $type ); ?>"
			        data-button="<?php echo esc_attr( $picker_button ); ?>">
				<?php echo esc_html( $args['select_label'] ); ?>
			</button>
			<button type="button" class="button sc-media-clear" data-target="<?php echo esc_attr( $id ); ?>"<?php echo $value ? '' : ' hidden'; ?>>
				<?php echo esc_html( $args['clear_label'] ); ?>
			</button>
		</div>
		<?php
		if ( $args['preview'] && $value ) {
			self::render_preview( $value, $type );
		}
		if ( $args['description'] !== '' ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Inline preview for a self-hosted file. Embed URLs (YouTube, Vimeo,
	 * Spotify, …) are skipped - they aren't playable via a bare <audio>/<video>.
	 */
	private static function render_preview( string $url, string $type ): void {
		$embed_hosts = [ 'youtube', 'youtu.be', 'vimeo', 'spotify', 'soundcloud' ];
		foreach ( $embed_hosts as $host ) {
			if ( false !== strpos( $url, $host ) ) return;
		}
		if ( 'audio' === $type ) {
			printf( '<audio src="%s" controls preload="none" class="sc-media-preview"></audio>', esc_url( $url ) );
		} elseif ( 'video' === $type ) {
			printf( '<video src="%s" controls class="sc-media-preview sc-media-preview--video"></video>', esc_url( $url ) );
		}
	}
}
