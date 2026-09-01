<?php
namespace SeedcastSermonLibrary\PDF;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Generates downloadable PDFs for sermon assets.
 * Uses a REST API endpoint for clean URLs instead of admin-ajax.php.
 * Falls back to mPDF if available, otherwise serves a print-ready HTML page.
 */
class PDFGenerator {

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route(): void {
		register_rest_route( 'sermon-library/v1', '/pdf/(?P<post_id>\d+)/(?P<asset>[a-z_]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_download' ],
			'permission_callback' => '__return_true', // Public: sermon content is public
			'args'                => [
				'post_id' => [
					'required'          => true,
					'validate_callback' => function( $v ) { return is_numeric( $v ) && $v > 0; },
					'sanitize_callback' => 'absint',
				],
				'asset' => [
					'required'          => true,
					'validate_callback' => function( $v ) {
						return in_array( $v, [ 'article', 'bible_study', 'transcript' ], true );
					},
				],
			],
		] );
	}

	public function handle_download( \WP_REST_Request $request ): void {
		$post_id = $request->get_param( 'post_id' );
		$asset   = $request->get_param( 'asset' );

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== 'scsl_sermon' ) {
			wp_die( esc_html__( 'Content not found.', 'seedcast-sermon-library' ), '', [ 'response' => 404 ] );
		}

		[ $title, $content ] = $this->get_asset_content( $post_id, $asset );

		if ( ! $content ) {
			wp_die( esc_html__( 'No content available for this asset.', 'seedcast-sermon-library' ), '', [ 'response' => 404 ] );
		}

		$filename = sanitize_file_name( $post->post_title . '-' . str_replace( '_', '-', $asset ) . '.pdf' );

		// Try mPDF first
		$composer_autoload = SCSL_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $composer_autoload ) ) {
			require_once $composer_autoload;
			if ( class_exists( '\Mpdf\Mpdf' ) ) {
				$this->generate_mpdf( $title, $content, $filename );
				return;
			}
		}

		$this->generate_html_print( $title, $content, $filename );
	}

	private function get_asset_content( int $post_id, string $asset ): array {
		$post       = get_post( $post_id );
		$series_id  = get_post_meta( $post_id, '_scsl_series_id',  true );
		$speaker_id = get_post_meta( $post_id, '_scsl_speaker_id', true );

		$header = '<p style="color:#666;font-size:12px;">';
		if ( $series_id )  $header .= esc_html( get_the_title( $series_id ) ) . ': ';
		if ( $speaker_id ) $header .= esc_html( get_the_title( $speaker_id ) );
		$header .= '</p>';

		switch ( $asset ) {
			case 'article':
				$title   = get_post_meta( $post_id, '_scsl_article_title', true ) ?: $post->post_title;
				$content = $header . wp_kses_post( get_post_meta( $post_id, '_scsl_article_body', true ) );
				break;
			case 'bible_study':
				$title   = $post->post_title . ': ' . __( 'Bible Study', 'seedcast-sermon-library' );
				$content = $header . wp_kses_post( get_post_meta( $post_id, '_scsl_bible_study', true ) );
				break;
			case 'transcript':
				$title   = $post->post_title . ': ' . __( 'Transcript', 'seedcast-sermon-library' );
				$content = $header . wpautop( esc_html( get_post_meta( $post_id, '_scsl_transcript_clean', true ) ) );
				break;
			default:
				return [ '', '' ];
		}

		return [ $title, $content ];
	}

	private function generate_mpdf( string $title, string $content, string $filename ): void {
		try {
			$mpdf = new \Mpdf\Mpdf( [
				'margin_top' => 20, 'margin_bottom' => 20,
				'margin_left' => 20, 'margin_right' => 20,
				'default_font' => 'helvetica',
			] );
			$site_name = get_bloginfo( 'name' );
			$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
			$html .= '<style>body{font-family:helvetica,sans-serif;font-size:13px;line-height:1.7;color:#1f2937;}h1{font-size:22px;color:#1a1a2e;}h2{font-size:16px;color:#1a1a2e;margin-top:24px;}p{margin:0 0 12px;}.site-name{font-size:11px;color:#9ca3af;margin-bottom:24px;}.divider{border:none;border-top:1px solid #e5e7eb;margin:16px 0;}</style>';
			$html .= '</head><body>';
			$html .= '<div class="site-name">' . esc_html( $site_name ) . '</div>';
			$html .= '<h1>' . esc_html( $title ) . '</h1><hr class="divider">';
			$html .= $content;
			$html .= '</body></html>';
			$mpdf->SetTitle( $title );
			$mpdf->SetAuthor( $site_name );
			$mpdf->WriteHTML( $html );
			$mpdf->Output( $filename, 'D' );
			exit;
		} catch ( \Exception $e ) {
			$this->generate_html_print( $title, $content, $filename );
		}
	}

	private function generate_html_print( string $title, string $content, string $filename ): void {
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
		<!DOCTYPE html><html>
		<head>
			<meta charset="UTF-8">
			<title><?php echo esc_html( $title ); ?></title>
			<style>body{font-family:Georgia,serif;font-size:14px;line-height:1.8;color:#1f2937;max-width:720px;margin:40px auto;padding:0 20px;}h1{font-size:24px;color:#1a1a2e;}h2{font-size:18px;color:#1a1a2e;margin-top:2em;}h3{font-size:15px;}.site{font-size:12px;color:#9ca3af;margin-bottom:2em;}hr{border:none;border-top:1px solid #e5e7eb;margin:1.5em 0;}@media print{.no-print{display:none;}}</style>
		</head>
		<body>
			<div class="no-print" style="background:#f3f4f6;padding:12px 16px;margin-bottom:24px;border-radius:6px;font-family:sans-serif;font-size:13px;">
				<?php esc_html_e( "Use your browser's Print function (Ctrl+P / Cmd+P) and select Save as PDF.", 'seedcast-sermon-library' ); ?>
				<button onclick="window.print()" style="margin-left:12px;padding:6px 14px;background:#4f46e5;color:#fff;border:none;border-radius:4px;cursor:pointer;"><?php esc_html_e( 'Print / Save PDF', 'seedcast-sermon-library' ); ?></button>
			</div>
			<div class="site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
			<h1><?php echo esc_html( $title ); ?></h1>
			<hr>
			<?php echo wp_kses_post( $content ); ?>
		</body></html>
		<?php
		exit;
	}

	/**
	 * Generate a clean REST API download button.
	 * URL: /wp-json/sermon-library/v1/pdf/{post_id}/{asset}
	 */
	public static function download_button( int $post_id, string $asset, string $label ): string {
		$url = rest_url( "sermon-library/v1/pdf/{$post_id}/{$asset}" );
		return sprintf(
			'<a href="%s" class="scsl-pdf-btn" target="_blank" rel="noopener" title="%s">%s</a>',
			esc_url( $url ),
			esc_attr__( 'Download PDF', 'seedcast-sermon-library' ),
			esc_html( $label )
		);
	}
}
