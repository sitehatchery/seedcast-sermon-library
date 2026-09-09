<?php
/**
 * Template: Single Sermon
 * Override: your-theme/seedcast-sermon-library/single/single-sermon.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\Faq;
use SeedcastSermonLibrary\Frontend\TemplateLoader;
use SeedcastSermonLibrary\PDF\PDFGenerator;

get_header();
while ( have_posts() ) :
	the_post();
	$scsl_post_id      = get_the_ID();
	$scsl_video_url    = get_post_meta( $scsl_post_id, '_scsl_video_url',           true );
	$scsl_audio_url    = get_post_meta( $scsl_post_id, '_scsl_audio_url',           true );
	$scsl_short_url    = get_post_meta( $scsl_post_id, '_scsl_short_url',           true );
	$scsl_speaker_id   = get_post_meta( $scsl_post_id, '_scsl_speaker_id',          true );
	$scsl_series_id    = get_post_meta( $scsl_post_id, '_scsl_series_id',           true );
	$scsl_rec_date     = get_post_meta( $scsl_post_id, '_scsl_recorded_date',       true );
	$scsl_transcript   = get_post_meta( $scsl_post_id, '_scsl_transcript_clean',    true );
	$scsl_article      = get_post_meta( $scsl_post_id, '_scsl_article_body',        true );
	$scsl_bible_study  = get_post_meta( $scsl_post_id, '_scsl_bible_study',         true );
	$scsl_description  = get_post_meta( $scsl_post_id, '_scsl_content_description', true );
	$scsl_resources    = get_post_meta( $scsl_post_id, '_scsl_resources',           true );
	$scsl_notes_intro  = get_post_meta( $scsl_post_id, '_scsl_notes_intro',         true );
	$scsl_notes_files  = get_post_meta( $scsl_post_id, '_scsl_notes_files',         true );
	if ( ! is_array( $scsl_notes_files ) ) $scsl_notes_files = [];
	$scsl_more_label   = get_post_meta( $scsl_post_id, '_scsl_more_label', true )
					?: get_option( 'scsl_tab_more_label', __( 'More', 'seedcast-sermon-library' ) );
	$scsl_has_notes    = $scsl_notes_intro || ! empty( $scsl_notes_files );
	$scsl_sermon_title = get_the_title( $scsl_post_id );

	// Autoplay detection

	// External links
	$scsl_ext_raw    = get_post_meta( $scsl_post_id, '_scsl_external_links', true );
	$scsl_ext_links  = $scsl_ext_raw ? json_decode( $scsl_ext_raw, true ) : [];
	if ( ! is_array( $scsl_ext_links ) ) $scsl_ext_links = [];

	// Is there anything above the share row? Drives whether the divider is
	// drawn at all. A link with no URL is skipped when rendering, so it does
	// not count here either.
	$scsl_has_actions = (bool) $scsl_short_url;
	foreach ( $scsl_ext_links as $scsl_link ) {
		if ( ! empty( $scsl_link['url'] ) ) {
			$scsl_has_actions = true;
			break;
		}
	}

	$scsl_platform_icons = [
		'spotify'    => '🎵',
		'apple'      => '🎧',
		'youtube'    => '▶',
		'vimeo'      => '▶',
		'sermon'     => '🎧',
		'soundcloud' => '☁',
		'other'      => '🔗',
	];

	// Share URLs
	$scsl_share_url   = rawurlencode( get_permalink( $scsl_post_id ) );
	$scsl_share_title = rawurlencode( $scsl_sermon_title );

	// Featured image
	$scsl_thumbnail_id  = get_post_thumbnail_id( $scsl_post_id );
	$scsl_thumbnail_url = $scsl_thumbnail_id ? wp_get_attachment_image_url( $scsl_thumbnail_id, 'large' ) : '';
	?>

<div class="sc-wrap">
<article class="scsl-sermon-single" id="sermon-<?php echo esc_attr( $scsl_post_id ); ?>"
		 aria-labelledby="scsl-sermon-title">

	<!-- Breadcrumb -->
	<?php
	$scsl_crumbs = [
		[ 'label' => __( 'Sermon Library', 'seedcast-sermon-library' ), 'url' => get_post_type_archive_link( 'scsl_series' ) ],
	];
	if ( $scsl_series_id ) {
		$scsl_crumbs[] = [ 'label' => get_the_title( $scsl_series_id ), 'url' => get_permalink( $scsl_series_id ) ];
	}
	$scsl_crumbs[] = [ 'label' => $scsl_sermon_title ];
	\Seedcast\Core\Frontend\Breadcrumb::render( $scsl_crumbs );
	?>

	<!-- Video -->
	<?php if ( $scsl_video_url ) : ?>
	<div class="scsl-media-wrap">
		<?php TemplateLoader::partial( 'video-embed', [
			'url'        => $scsl_video_url,
			'type'       => 'youtube',
			'start_time' => get_post_meta( $scsl_post_id, '_scsl_video_start', true ),
		] ); ?>
	</div>
	<?php endif; ?>

	<!-- Audio -->
	<?php if ( $scsl_audio_url ) :
		// Detect MIME type for self-hosted files
		$scsl_audio_mime = 'audio/mpeg'; // default for mp3
		if ( strpos( $scsl_audio_url, '.ogg' ) !== false ) $scsl_audio_mime = 'audio/ogg';
		if ( strpos( $scsl_audio_url, '.wav' ) !== false ) $scsl_audio_mime = 'audio/wav';
		if ( strpos( $scsl_audio_url, '.m4a' ) !== false ) $scsl_audio_mime = 'audio/mp4';
		// For podcast/streaming URLs don't show audio player: they're handled as external links
		$scsl_is_streaming = (
			strpos( $scsl_audio_url, 'spotify.com' ) !== false ||
			strpos( $scsl_audio_url, 'podcasts.apple' ) !== false ||
			strpos( $scsl_audio_url, 'soundcloud.com' ) !== false
		);
	?>
	<?php if ( ! $scsl_is_streaming ) : ?>
	<div class="sc-audio-player">
		<p class="sc-audio-player__label" id="scsl-audio-label"><?php esc_html_e( 'Listen to this sermon', 'seedcast-sermon-library' ); ?></p>
		<audio controls preload="metadata"
			   aria-labelledby="scsl-audio-label"
			   <?php if ( get_query_var( 'scsl_play' ) === 'audio' ) echo 'autoplay'; ?>
			   style="width:100%;">
			<source src="<?php echo esc_url( $scsl_audio_url ); ?>" type="<?php echo esc_attr( $scsl_audio_mime ); ?>" />
			<?php esc_html_e( 'Your browser does not support the audio element.', 'seedcast-sermon-library' ); ?>
		</audio>
	</div>
	<?php endif; ?>
	<?php endif; ?>

	<!-- Main layout: left content + right sidebar -->
	<div class="scsl-sermon-layout">
		<div class="scsl-sermon-layout__main">

			<!-- Header: title, meta, description only -->
			<header class="scsl-sermon-header">
				<h1 class="scsl-sermon-title" id="scsl-sermon-title">
					<?php the_title(); ?>
					<button type="button"
							class="scsl-title-copy-btn scsl-share-btn--copy"
							data-url="<?php echo esc_attr( get_permalink( $scsl_post_id ) ); ?>"
							aria-label="<?php esc_attr_e( 'Copy link to this sermon', 'seedcast-sermon-library' ); ?>"
							title="<?php esc_attr_e( 'Copy link', 'seedcast-sermon-library' ); ?>">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
							<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
						</svg>
						<span class="scsl-sr-only"><?php esc_html_e( 'Copy link', 'seedcast-sermon-library' ); ?></span>
					</button><span class="scsl-copy-feedback" aria-live="polite"></span>
				</h1>

				<div class="scsl-sermon-meta">
					<?php if ( $scsl_speaker_id ) : ?>
						<span class="scsl-meta-speaker">
							<?php
							$scsl_avatar_id  = (int) get_post_meta( $scsl_speaker_id, '_scsl_speaker_photo_id', true );
							$scsl_avatar_url = $scsl_avatar_id
								? wp_get_attachment_image_url( $scsl_avatar_id, 'thumbnail' )
								: get_the_post_thumbnail_url( $scsl_speaker_id, 'thumbnail' );
							if ( $scsl_avatar_url ) : ?>
								<img src="<?php echo esc_url( $scsl_avatar_url ); ?>"
									 alt="<?php echo esc_attr( get_the_title( $scsl_speaker_id ) ); ?>"
									 class="scsl-speaker-avatar" width="32" height="32" />
							<?php endif; ?>
							<a href="<?php echo esc_url( get_permalink( $scsl_speaker_id ) ); ?>">
								<?php echo esc_html( get_the_title( $scsl_speaker_id ) ); ?>
							</a>
						</span>
					<?php endif; ?>
					<?php if ( $scsl_rec_date ) : ?>
						<time class="scsl-meta-date" datetime="<?php echo esc_attr( $scsl_rec_date ); ?>">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $scsl_rec_date ) ) ); ?>
						</time>
					<?php endif; ?>
					<?php if ( $scsl_series_id ) : ?>
						<span class="scsl-meta-series">
							<a href="<?php echo esc_url( get_permalink( $scsl_series_id ) ); ?>">
								<?php echo esc_html( get_the_title( $scsl_series_id ) ); ?>
							</a>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( $scsl_description && \SeedcastSermonLibrary\Import\FieldMap::uses( '_scsl_content_description' ) ) : ?>
					<p class="scsl-sermon-description"><?php echo esc_html( $scsl_description ); ?></p>
				<?php endif; ?>
			</header>

			<!-- Tabbed content -->
			<?php
			$scsl_tabs = [];

			// A section this church has said it does not use stays off the
			// page even if something was written into it earlier.
			$scsl_uses = static function ( $key ) {
				return \SeedcastSermonLibrary\Import\FieldMap::uses( $key );
			};

			if ( $scsl_article && $scsl_uses( '_scsl_article_body' ) )        $scsl_tabs['article']    = __( 'Article',     'seedcast-sermon-library' );
			if ( $scsl_bible_study && $scsl_uses( '_scsl_bible_study' ) )     $scsl_tabs['bible-study'] = __( 'Bible Study', 'seedcast-sermon-library' );
			if ( Faq::has( $scsl_post_id ) && $scsl_uses( Faq::META ) )       $scsl_tabs['questions']   = __( 'Questions',   'seedcast-sermon-library' );
			if ( $scsl_transcript && $scsl_uses( '_scsl_transcript_clean' ) ) $scsl_tabs['transcript']  = __( 'Transcript',  'seedcast-sermon-library' );
			if ( $scsl_resources && $scsl_uses( '_scsl_resources' ) )         $scsl_tabs['more']        = $scsl_more_label;
			if ( $scsl_has_notes )   $scsl_tabs['notes']       = __( 'Sermon Notes', 'seedcast-sermon-library' );
			?>
			<?php if ( $scsl_tabs ) : ?>
			<div class="scsl-content-tabs">

				<?php $scsl_is_single_tab = ( count( $scsl_tabs ) === 1 ); ?>

				<?php if ( ! $scsl_is_single_tab ) : ?>
				<nav class="scsl-tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Sermon content', 'seedcast-sermon-library' ); ?>">
					<?php $scsl_first = true; foreach ( $scsl_tabs as $scsl_tab_id => $scsl_tab_label ) : ?>
						<button class="scsl-tab-btn <?php echo esc_attr( $scsl_first ? 'is-active' : '' ); ?>"
								role="tab"
								aria-selected="<?php echo esc_attr( $scsl_first ? 'true' : 'false' ); ?>"
								aria-controls="scsl-panel-<?php echo esc_attr( $scsl_tab_id ); ?>"
								id="scsl-tab-<?php echo esc_attr( $scsl_tab_id ); ?>"
								data-tab="<?php echo esc_attr( $scsl_tab_id ); ?>"
								tabindex="<?php echo esc_attr( $scsl_first ? '0' : '-1' ); ?>">
							<?php echo esc_html( $scsl_tab_label ); ?>
						</button>
					<?php $scsl_first = false; endforeach; ?>
					<?php
					/*
					 * A way back up, and a reminder of where this is.
					 *
					 * Arriving from an article scrolls straight to the tabs,
					 * and what fills the screen then is a heading and a body
					 * with no sign it belongs to a sermon. A link rather than
					 * a button on purpose: it is a way back, not another
					 * thing to press.
					 */
					?>
					<a class="scsl-tabs-nav__up" href="#sermon-<?php echo esc_attr( $scsl_post_id ); ?>">
						<?php esc_html_e( 'Sermon', 'seedcast-sermon-library' ); ?>
						<span aria-hidden="true">&uarr;</span>
					</a>
				</nav>
				<?php endif; ?>

				<?php $scsl_first = true; foreach ( $scsl_tabs as $scsl_tab_id => $scsl_tab_label ) : ?>
				<div id="scsl-panel-<?php echo esc_attr( $scsl_tab_id ); ?>"
					 class="sc-tab-panel <?php echo esc_attr( $scsl_first ? 'is-active' : '' ); ?>"
					 role="<?php echo esc_attr( $scsl_is_single_tab ? 'region' : 'tabpanel' ); ?>"
					 <?php if ( ! $scsl_is_single_tab ) : ?>aria-labelledby="scsl-tab-<?php echo esc_attr( $scsl_tab_id ); ?>"<?php endif; ?>
					 aria-hidden="<?php echo esc_attr( ( ! $scsl_first && ! $scsl_is_single_tab ) ? 'true' : 'false' ); ?>">
					<?php
					$scsl_heading = $scsl_tab_label;

					if ( 'article' === $scsl_tab_id ) {
						$scsl_article_title = trim( (string) get_post_meta( $scsl_post_id, '_scsl_article_title', true ) );

						if ( '' !== $scsl_article_title ) {
							$scsl_heading = sprintf(
								/* translators: 1: the word Article, 2: the article's own title. */
								_x( '%1$s: %2$s', 'article section heading', 'seedcast-sermon-library' ),
								$scsl_tab_label,
								$scsl_article_title
							);
						}
					}
					?>
					<h2 class="sc-content-section-heading"><?php echo esc_html( $scsl_heading ); ?></h2>
					<?php
					switch ( $scsl_tab_id ) {
						case 'article':
							if ( $scsl_thumbnail_url ) {
								echo '<div class="scsl-article-featured-image">';
								echo '<img src="' . esc_url( $scsl_thumbnail_url ) . '" alt="' . esc_attr( $scsl_sermon_title ) . '" />';
								echo '</div>';
							}
							echo '<div class="scsl-article-content">' . wp_kses_post( scsl_demote_headings( $scsl_article ) ) . '</div>';
							echo wp_kses_post( '<div class="scsl-pdf-row">' . PDFGenerator::download_button( $scsl_post_id, 'article', '⬇ ' . __( 'Download Article PDF', 'seedcast-sermon-library' ) ) . '</div>' );
							break;
						case 'bible-study':
							echo '<div class="scsl-study-content">' . wp_kses_post( scsl_demote_headings( $scsl_bible_study ) ) . '</div>';
							echo '<div class="scsl-pdf-row">' . PDFGenerator::download_button( $scsl_post_id, 'bible_study', '⬇ ' . __( 'Download Study PDF', 'seedcast-sermon-library' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDFGenerator::download_button() returns escaped HTML
							break;
						case 'questions':
							/*
							 * Plain headings and paragraphs. The schema graph
							 * describes these questions, and structured data may
							 * only describe what a visitor can actually read.
							 */
							echo Faq::render( $scsl_post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by Faq::render().
							break;
						case 'transcript':
							echo wp_kses_post( '<div class="scsl-transcript">' . wpautop( wp_kses_post( $scsl_transcript ) ) . '</div>' );
							echo '<div class="scsl-pdf-row">' . PDFGenerator::download_button( $scsl_post_id, 'transcript', '⬇ ' . __( 'Download Transcript PDF', 'seedcast-sermon-library' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDFGenerator::download_button() returns escaped HTML
							break;
						case 'notes':
							echo '<div class="scsl-notes-content">';
							if ( $scsl_notes_intro ) {
								echo '<p class="scsl-notes-intro">' . esc_html( $scsl_notes_intro ) . '</p>';
							}
							if ( $scsl_notes_files ) {
								echo '<ul class="scsl-notes-files">';
								foreach ( $scsl_notes_files as $scsl_file ) {
									if ( empty( $scsl_file['url'] ) ) continue;
									$scsl_label = ! empty( $scsl_file['label'] ) ? $scsl_file['label'] : basename( $scsl_file['url'] );
									$scsl_ext   = strtoupper( pathinfo( $scsl_file['url'], PATHINFO_EXTENSION ) );
									echo '<li class="scsl-notes-file">';
									$scsl_is_file = (bool) preg_match( '/\.(?:pdf|doc|docx|ppt|pptx|xls|xlsx|zip)$/i', $scsl_file['url'] );
									echo '<a href="' . esc_url( $scsl_file['url'] ) . '" class="scsl-notes-download"'
										. ( $scsl_is_file ? ' download' : '' )
										. ' target="_blank" rel="noopener">';
									echo '<span class="scsl-notes-download__icon" aria-hidden="true">⬇</span>';
									echo '<span class="scsl-notes-download__label">' . esc_html( $scsl_label ) . '</span>';
									if ( $scsl_ext ) echo '<span class="scsl-notes-download__ext">' . esc_html( $scsl_ext ) . '</span>';
									echo '</a></li>';
								}
								echo '</ul>';
							}
							echo '</div>';
							break;
						case 'more':
							echo wp_kses_post( '<div class="scsl-resources-content">' . wpautop( wp_kses_post( $scsl_resources ) ) . '</div>' );
							break;
					}
					?>
				</div>
				<?php $scsl_first = false; endforeach; ?>

			</div><!-- /.scsl-content-tabs -->
			<?php endif; ?>

		</div><!-- /.scsl-sermon-layout__main -->

		<!-- RIGHT SIDEBAR: scripture + actions stacked -->
		<div class="scsl-sermon-layout__sidebar">

			<!-- Action buttons: Watch Short Clip, Platform Links, Social Share -->
			<div class="scsl-sermon-actions">

				<!-- Watch Short Clip -->
				<?php if ( $scsl_short_url ) : ?>
				<a href="<?php echo esc_url( $scsl_short_url ); ?>"
				   class="scsl-btn--platform scsl-platform--short"
				   target="_blank" rel="noopener noreferrer"
				   aria-label="<?php esc_attr_e( 'Watch Short Clip, opens in a new tab', 'seedcast-sermon-library' ); ?>">
					<span class="scsl-platform-icon" aria-hidden="true">▶</span>
					<span class="scsl-platform-label"><?php esc_html_e( 'Watch Short Clip', 'seedcast-sermon-library' ); ?></span>
				</a>
				<?php endif; ?>

				<!-- Platform links (Spotify, YouTube, etc.) -->
				<?php foreach ( $scsl_ext_links as $link ) :
					if ( empty( $link['url'] ) ) continue;
					$scsl_platform = $link['platform'] ?? 'other';
					$scsl_icon     = $scsl_platform_icons[ $scsl_platform ] ?? '🔗';
					$scsl_label    = $link['label'] ?: ucfirst( $scsl_platform );
				?>
				<a href="<?php echo esc_url( $link['url'] ); ?>"
				   class="scsl-btn--platform scsl-platform--<?php echo esc_attr( $scsl_platform ); ?>"
				   target="_blank" rel="noopener noreferrer"
				   aria-label="<?php echo esc_attr( $scsl_label ) . ': ' . esc_attr__( 'opens in a new tab', 'seedcast-sermon-library' ); ?>">
					<span class="scsl-platform-icon" aria-hidden="true"><?php echo esc_html( $scsl_icon ); ?></span>
					<span class="scsl-platform-label"><?php echo esc_html( $scsl_label ); ?></span>
				</a>
				<?php endforeach; ?>

				<!-- Divider before social, only when something sits above it -->
				<?php if ( $scsl_has_actions ) : ?>
					<hr class="scsl-sermon-actions__divider" />
				<?php endif; ?>

				<!-- Social share label -->
				<p class="scsl-sermon-actions__label"><?php esc_html_e( 'Share', 'seedcast-sermon-library' ); ?></p>

				<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo esc_attr( rawurlencode( get_permalink( $scsl_post_id ) ) ); ?>"
				   class="scsl-btn--platform scsl-platform--share-facebook"
				   target="_blank" rel="noopener noreferrer"
				   aria-label="<?php esc_attr_e( 'Share on Facebook, opens in a new tab', 'seedcast-sermon-library' ); ?>">
					<span class="scsl-platform-icon" aria-hidden="true">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
					</span>
					<span class="scsl-platform-label">Facebook</span>
				</a>

				<a href="https://twitter.com/intent/tweet?url=<?php echo esc_attr( rawurlencode( get_permalink( $scsl_post_id ) ) ); ?>&text=<?php echo rawurlencode( $scsl_sermon_title ); ?>"
				   class="scsl-btn--platform scsl-platform--share-twitter"
				   target="_blank" rel="noopener noreferrer"
				   aria-label="<?php esc_attr_e( 'Share on X, opens in a new tab', 'seedcast-sermon-library' ); ?>">
					<span class="scsl-platform-icon" aria-hidden="true">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
					</span>
					<span class="scsl-platform-label"><?php esc_html_e( 'Share on X', 'seedcast-sermon-library' ); ?></span>
				</a>

				<a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo esc_attr( rawurlencode( get_permalink( $scsl_post_id ) ) ); ?>"
				   class="scsl-btn--platform scsl-platform--share-linkedin"
				   target="_blank" rel="noopener noreferrer"
				   aria-label="<?php esc_attr_e( 'Share on LinkedIn, opens in a new tab', 'seedcast-sermon-library' ); ?>">
					<span class="scsl-platform-icon" aria-hidden="true">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
					</span>
					<span class="scsl-platform-label">LinkedIn</span>
				</a>

			</div><!-- /.scsl-sermon-actions -->

			<!-- Scripture References -->
			<?php TemplateLoader::partial( 'scripture-panel', [ 'scsl_post_id' => $scsl_post_id ] ); ?>

			<?php
			/**
			 * The end of the sermon sidebar, after the scripture panel.
			 *
			 * For another Seedcast plugin that wants a place on the page
			 * without editing this template. Visitor Card uses it to put its
			 * card here rather than at the foot of the content, where somebody
			 * who has just finished listening would have to scroll past the
			 * series and the transcript to find it.
			 *
			 * @param int $scsl_post_id Sermon being displayed.
			 */
			do_action( 'scsl_sermon_sidebar_end', $scsl_post_id );
			?>

		</div><!-- /.scsl-sermon-layout__sidebar -->

	</div><!-- /.scsl-sermon-layout -->

	<?php
	/*
	 * A sentence naming who preached, at which church, in which town.
	 *
	 * Worth having for a reader who arrived from a search and has no idea
	 * whose church this is or where it is. Worth having for search engines for
	 * the same reason: a named person, a named church and a named place in
	 * ordinary prose is what actually gets read as a signal about location,
	 * and the schema markup elsewhere on the page says it only to machines.
	 *
	 * Built entirely from what is already filled in. Every clause disappears
	 * when its parts are missing, and nothing prints at all when the church
	 * details are empty, so a site that has not set them up sees no change.
	 */
	if ( class_exists( '\\Seedcast\\Core\\Blurb' ) ) :
		$scsl_blurb_date = '';
		if ( $scsl_rec_date ) {
			$scsl_blurb_date = wp_date(
				(string) get_option( 'date_format', 'F j, Y' ),
				(int) strtotime( (string) $scsl_rec_date )
			);
		}

		$scsl_blurb = \Seedcast\Core\Blurb::sermon( [
			'speaker' => $scsl_speaker_id ? get_the_title( $scsl_speaker_id ) : '',
			'date'    => $scsl_blurb_date,
			'series'  => $scsl_series_id ? get_the_title( $scsl_series_id ) : '',
			'subject' => __( 'this message', 'seedcast-sermon-library' ),
		] );

		if ( '' !== $scsl_blurb ) :
			?>
			<p class="scsl-sermon-blurb"><?php echo esc_html( $scsl_blurb ); ?></p>
			<?php
		endif;
	endif;
	?>

	<?php if ( $scsl_series_id ) :
		TemplateLoader::partial( 'related-sermons', [ 'series_id' => $scsl_series_id, 'scsl_exclude' => $scsl_post_id ] );
	endif; ?>

</article>
</div>

<?php endwhile;
get_footer();
