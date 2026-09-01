<?php
/**
 * Template: Single Speaker
 * Override: your-theme/seedcast-sermon-library/single/single-speaker.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\TemplateLoader;
use SeedcastSermonLibrary\Frontend\Shortcodes;

get_header();

while ( have_posts() ) :
	the_post();
	$scsl_post_id   = get_the_ID();
	$scsl_title     = get_post_meta( $scsl_post_id, '_scsl_speaker_title',    true );
	$scsl_email     = get_post_meta( $scsl_post_id, '_scsl_speaker_email',    true );
	$scsl_phone     = get_post_meta( $scsl_post_id, '_scsl_speaker_phone',    true );
	$scsl_website   = get_post_meta( $scsl_post_id, '_scsl_speaker_website',  true );
	$scsl_twitter   = get_post_meta( $scsl_post_id, '_scsl_speaker_twitter',  true );
	$scsl_facebook  = get_post_meta( $scsl_post_id, '_scsl_speaker_facebook', true );
	$scsl_instagram = get_post_meta( $scsl_post_id, '_scsl_speaker_instagram',true );
	$scsl_wp_user   = get_post_meta( $scsl_post_id, '_scsl_speaker_wp_user',  true );
	$scsl_per_page  = absint( get_option( 'scsl_sermons_per_page', 10 ) );

	// Get all sermons by this speaker
	$scsl_sermon_count_query = new \WP_Query( [
		'post_type'      => 'scsl_sermon',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [ [ 'key' => '_scsl_speaker_id', 'value' => $scsl_post_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	] );
	$scsl_sermon_count = $scsl_sermon_count_query->found_posts;
	wp_reset_postdata();

	// First page of sermons
	$scsl_page_raw = get_query_var( 'scsl_page', 1 );
	$scsl_paged = max( 1, absint( $scsl_page_raw ) );

	$scsl_sermons = new \WP_Query( [
		'post_type'      => 'scsl_sermon',
		'post_status'    => 'publish',
		'posts_per_page' => $scsl_per_page,
		'paged'          => $scsl_paged,
		'meta_key'       => '_scsl_recorded_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'orderby'        => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'order'          => 'DESC',
		'meta_query'     => [ [ 'key' => '_scsl_speaker_id', 'value' => $scsl_post_id ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	] );

	// Series this speaker has preached in.
	$scsl_series_ids = [];
	$scsl_all_sermon_posts = get_posts( [
		'post_type'   => 'scsl_sermon',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_key'    => '_scsl_speaker_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'  => $scsl_post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	] );
	// Prime the meta cache in one query. Without this the loop below fires a
	// separate query per sermon, which on a speaker with a few hundred of them
	// is the slowest thing on the page by a wide margin.
	if ( $scsl_all_sermon_posts ) {
		update_postmeta_cache( $scsl_all_sermon_posts );
	}
	foreach ( $scsl_all_sermon_posts as $scsl_sid ) {
		$scsl_ser = get_post_meta( $scsl_sid, '_scsl_series_id', true );
		if ( $scsl_ser && ! in_array( $scsl_ser, $scsl_series_ids, true ) ) {
			$scsl_series_ids[] = $scsl_ser;
		}
	}

	// Blog posts by linked WP user
	$scsl_blog_posts = [];
	if ( $scsl_wp_user ) {
		$scsl_blog_posts = get_posts( [
			'post_type'   => 'post',
			'post_status' => 'publish',
			'author'      => absint( $scsl_wp_user ),
			'numberposts' => 5,
		] );
	}

	wp_enqueue_script( 'scsl-sermon-list' );
	?>

<div class="sc-wrap">

	<!-- Breadcrumb -->
	<?php
	\Seedcast\Core\Frontend\Breadcrumb::render( [
		[ 'label' => __( 'Sermon Library', 'seedcast-sermon-library' ), 'url' => get_post_type_archive_link( 'scsl_series' ) ],
		[ 'label' => get_the_title() ],
	] );
	?>

	<!-- Speaker Profile -->
	<header class="scsl-speaker-profile">
		<?php if ( has_post_thumbnail() ) : ?>
		<div class="scsl-speaker-profile__photo">
			<?php the_post_thumbnail( 'scsl_speaker_card', [
				'alt'   => esc_attr( get_the_title() ),
				'class' => 'scsl-speaker-profile__img',
			] ); ?>
		</div>
		<?php endif; ?>

		<div class="scsl-speaker-profile__content">
			<h1 class="scsl-speaker-profile__name"><?php the_title(); ?></h1>

			<?php if ( $scsl_title ) : ?>
				<p class="scsl-speaker-profile__title"><?php echo esc_html( $scsl_title ); ?></p>
			<?php endif; ?>

			<?php if ( $scsl_sermon_count ) : ?>
			<div class="scsl-speaker-profile__stats">
				<span class="scsl-speaker-stat">
					<strong><?php echo esc_html( $scsl_sermon_count ); ?></strong>
					<?php echo esc_html( _n( 'sermon', 'sermons', $scsl_sermon_count, 'seedcast-sermon-library' ) ); ?>
				</span>
				<?php if ( $scsl_series_ids ) : ?>
				<span class="scsl-speaker-stat">
					<strong><?php echo esc_html( count( $scsl_series_ids ) ); ?></strong>
					<?php echo esc_html( _n( 'series', 'series', count( $scsl_series_ids ), 'seedcast-sermon-library' ) ); ?>
				</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( get_the_content() ) : ?>
			<div class="scsl-speaker-profile__bio">
				<?php the_content(); ?>
			</div>
			<?php endif; ?>

			<!-- Contact & Social -->
			<?php if ( $scsl_email || $scsl_phone || $scsl_website || $scsl_twitter || $scsl_facebook || $scsl_instagram ) : ?>
			<div class="scsl-speaker-profile__contact">
				<?php if ( $scsl_website ) : ?>
					<a href="<?php echo esc_url( $scsl_website ); ?>" class="scsl-speaker-contact-link" target="_blank" rel="noopener noreferrer">
						<span aria-hidden="true">🌐</span> <?php echo esc_html( preg_replace( '/^https?:\/\//', '', $scsl_website ) ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $scsl_email ) : ?>
					<a href="mailto:<?php echo esc_attr( $scsl_email ); ?>" class="scsl-speaker-contact-link">
						<span aria-hidden="true">✉</span> <?php echo esc_html( $scsl_email ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $scsl_phone ) : ?>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $scsl_phone ) ); ?>" class="scsl-speaker-contact-link">
						<span aria-hidden="true">📞</span> <?php echo esc_html( $scsl_phone ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $scsl_twitter ) : ?>
					<a href="https://twitter.com/<?php echo esc_attr( ltrim( $scsl_twitter, '@' ) ); ?>" class="scsl-speaker-contact-link" target="_blank" rel="noopener noreferrer">
						<span aria-hidden="true">𝕏</span> @<?php echo esc_html( ltrim( $scsl_twitter, '@' ) ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $scsl_facebook ) : ?>
					<a href="https://facebook.com/<?php echo esc_attr( ltrim( $scsl_facebook, '@' ) ); ?>" class="scsl-speaker-contact-link" target="_blank" rel="noopener noreferrer">
						<span aria-hidden="true">f</span> <?php echo esc_html( ltrim( $scsl_facebook, '@' ) ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $scsl_instagram ) : ?>
					<a href="https://instagram.com/<?php echo esc_attr( ltrim( $scsl_instagram, '@' ) ); ?>" class="scsl-speaker-contact-link" target="_blank" rel="noopener noreferrer">
						<span aria-hidden="true">📷</span> @<?php echo esc_html( ltrim( $scsl_instagram, '@' ) ); ?>
					</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Series this speaker has been in -->
			<?php if ( $scsl_series_ids ) : ?>
			<div class="scsl-speaker-profile__series">
				<h3 class="scsl-speaker-profile__section-title"><?php esc_html_e( 'Series', 'seedcast-sermon-library' ); ?></h3>
				<div class="scsl-speaker-series-list">
					<?php foreach ( $scsl_series_ids as $scsl_ser_id ) : ?>
						<a href="<?php echo esc_url( get_permalink( $scsl_ser_id ) ); ?>" class="scsl-speaker-series-tag">
							<?php echo esc_html( get_the_title( $scsl_ser_id ) ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</header>

	<!-- Sermons by this speaker -->
	<?php if ( $scsl_sermon_count > 0 ) : ?>
	<section class="scsl-episode-list" aria-label="<?php esc_attr_e( 'Sermons by this speaker', 'seedcast-sermon-library' ); ?>">
		<h2 class="sc-section-title"><?php esc_html_e( 'Sermons', 'seedcast-sermon-library' ); ?></h2>

		<div class="scsl-sermon-list-wrap"
			 data-speaker-id="<?php echo esc_attr( $scsl_post_id ); ?>"
			 data-series-id=""
			 data-per-page="<?php echo esc_attr( $scsl_per_page ); ?>"
			 data-topic=""
			 data-book=""
			 data-orderby="recorded_date"
			 data-order="DESC"
			 data-template="episode-card"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( 'scsl_list_nonce' ) ); ?>">

			<!-- Filter bar -->
			<?php
			// Get series this speaker appears in for filter
			// One query for all the series, rather than one per series.
			$scsl_speaker_series = $scsl_series_ids
				? get_posts( [
					'post_type'   => 'scsl_series',
					'post__in'    => array_map( 'absint', $scsl_series_ids ),
					'numberposts' => count( $scsl_series_ids ),
					'orderby'     => 'post__in',
				] )
				: [];

			// Same again for the topic terms attached to those sermons.
			if ( $scsl_all_sermon_posts ) {
				update_object_term_cache( $scsl_all_sermon_posts, 'scsl_sermon' );
			}
			$scsl_speaker_topics = [];
			foreach ( $scsl_all_sermon_posts as $scsl_sid ) {
				$scsl_s_topics = get_the_terms( $scsl_sid, 'scsl_topic' );
				if ( $scsl_s_topics && ! is_wp_error( $scsl_s_topics ) ) {
					foreach ( $scsl_s_topics as $scsl_st ) {
						$scsl_speaker_topics[ $scsl_st->term_id ] = $scsl_st;
					}
				}
			}
			?>
			<?php
			// Build speaker-scoped extra filters
			$scsl_speaker_extra = [];

			if ( count( $scsl_speaker_series ) > 1 ) {
				ob_start();
				echo '<select name="scsl_series_id" class="scsl-filter-select" data-filter="series_id" aria-label="' . esc_attr__( 'Series', 'seedcast-sermon-library' ) . '">';
				echo '<option value="">' . esc_html__( 'All series', 'seedcast-sermon-library' ) . '</option>';
				foreach ( $scsl_speaker_series as $scsl_sp_series ) {
					if ( ! $scsl_sp_series ) continue;
					echo '<option value="' . esc_attr( $scsl_sp_series->ID ) . '">' . esc_html( $scsl_sp_series->post_title ) . '</option>';
				}
				echo '</select>';
				$scsl_speaker_extra[] = ob_get_clean();
			}

			if ( $scsl_speaker_topics ) {
				ob_start();
				echo '<select name="scsl_topic" class="scsl-filter-select" data-filter="topic" aria-label="' . esc_attr__( 'Topic', 'seedcast-sermon-library' ) . '">';
				echo '<option value="">' . esc_html__( 'All topics', 'seedcast-sermon-library' ) . '</option>';
				foreach ( $scsl_speaker_topics as $scsl_st ) {
					echo '<option value="' . esc_attr( $scsl_st->slug ) . '">' . esc_html( $scsl_st->name ) . '</option>';
				}
				echo '</select>';
				$scsl_speaker_extra[] = ob_get_clean();
			}

			ob_start();
			echo '<select name="scsl_order" class="scsl-filter-select" data-filter="order" aria-label="' . esc_attr__( 'Sort order', 'seedcast-sermon-library' ) . '">';
			echo '<option value="DESC">' . esc_html__( 'Newest First', 'seedcast-sermon-library' ) . '</option>';
			echo '<option value="ASC">'  . esc_html__( 'Oldest First', 'seedcast-sermon-library' ) . '</option>';
			echo '</select>';
			$scsl_speaker_extra[] = ob_get_clean();

			$scsl_threshold = absint( get_option( 'scsl_filter_threshold', 0 ) );
			$scsl_count = isset( $scsl_sermons ) ? $scsl_sermons->found_posts : 999;
			if ( $scsl_threshold === 0 || $scsl_count > $scsl_threshold ) :
			( new Shortcodes() )->render_filter_bar( [
				'uid'           => 'scsl-speaker-' . $scsl_post_id,
				'lock_series'   => true,   // handled via extra_filters above
				'lock_speaker'  => true,   // we're on a speaker page
				'lock_topic'    => true,   // handled via extra_filters above
				'extra_filters' => $scsl_speaker_extra,
			] );
			endif;
			?>

			<!-- Results -->
			<div class="scsl-list-results scsl-list-results--cards">
				<?php while ( $scsl_sermons->have_posts() ) : $scsl_sermons->the_post(); ?>
					<?php TemplateLoader::partial( 'episode-card', [ 'post' => get_post() ] ); ?>
				<?php endwhile; wp_reset_postdata(); ?>
			</div>

			<!-- SEO Pagination -->
			<?php if ( $scsl_sermons->max_num_pages > 1 ) : ?>
			<div class="scsl-list-pagination scsl-list-pagination--seo">
				<nav class="sc-pagination sc-pagination--links" aria-label="<?php esc_attr_e( 'Pages', 'seedcast-sermon-library' ); ?>">
					<?php if ( $scsl_paged > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged - 1 ) ); ?>"
						   class="scsl-btn scsl-btn--ghost scsl-page-link" rel="prev">&larr; <?php esc_html_e( 'Prev', 'seedcast-sermon-library' ); ?></a>
					<?php endif; ?>
					<span class="scsl-page-info"><?php
					// translators: %1$d is the current page number, %2$d is the total number of pages
					printf( esc_html__( 'Page %1$d of %2$d', 'seedcast-sermon-library' ), esc_html( $scsl_paged ), esc_html( $scsl_sermons->max_num_pages ) ); ?></span>
					<?php if ( $scsl_paged < $scsl_sermons->max_num_pages ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'scsl_page', $scsl_paged + 1 ) ); ?>"
						   class="scsl-btn scsl-btn--ghost scsl-page-link" rel="next"><?php esc_html_e( 'Next', 'seedcast-sermon-library' ); ?> &rarr;</a>
					<?php endif; ?>
				</nav>
			</div>
			<div class="scsl-list-pagination scsl-list-pagination--ajax" style="display:none;"></div>
			<?php endif; ?>

			<div class="scsl-list-loading" style="display:none;"></div>
		</div>
	</section>
	<?php endif; ?>

	<!-- Blog posts by this speaker -->
	<?php if ( $scsl_blog_posts ) : ?>
	<section class="scsl-speaker-articles">
		<h2 class="sc-section-title"><?php esc_html_e( 'Articles', 'seedcast-sermon-library' ); ?></h2>
		<div class="scsl-speaker-article-grid">
			<?php foreach ( $scsl_blog_posts as $scsl_bp ) :
				$scsl_thumb_url = get_the_post_thumbnail_url( $scsl_bp->ID, 'medium' );
			?>
			<article class="scsl-speaker-article-card">
				<a class="scsl-speaker-article-card__img-wrap"
				   href="<?php echo esc_url( get_permalink( $scsl_bp->ID ) ); ?>"
				   tabindex="-1" aria-hidden="true">
					<?php if ( $scsl_thumb_url ) : ?>
						<img src="<?php echo esc_url( $scsl_thumb_url ); ?>"
							 alt=""
							 loading="lazy" />
					<?php else : ?>
						<div class="scsl-speaker-article-card__placeholder" aria-hidden="true"></div>
					<?php endif; ?>
				</a>
				<div class="scsl-speaker-article-card__body">
					<time class="scsl-speaker-article-card__date"
						  datetime="<?php echo esc_attr( $scsl_bp->post_date ); ?>">
						<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $scsl_bp->post_date ) ) ); ?>
					</time>
					<h3 class="scsl-speaker-article-card__title">
						<a href="<?php echo esc_url( get_permalink( $scsl_bp->ID ) ); ?>">
							<?php echo esc_html( $scsl_bp->post_title ); ?>
						</a>
					</h3>
					<?php
					$scsl_excerpt = $scsl_bp->post_excerpt
						?: wp_trim_words( wp_strip_all_tags( $scsl_bp->post_content ), 20 );
					if ( $scsl_excerpt ) : ?>
						<p class="scsl-speaker-article-card__excerpt">
							<?php echo esc_html( $scsl_excerpt ); ?>
						</p>
					<?php endif; ?>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

</div>

<?php endwhile;
get_footer();
