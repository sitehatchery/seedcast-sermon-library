<?php
/**
 * Archive template: Speakers
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use SeedcastSermonLibrary\Frontend\TemplateLoader;

get_header();
?>
<div class="sc-wrap scsl-speakers-wrap">

	<header class="scsl-archive-header">
		<h1 class="scsl-archive-title"><?php esc_html_e( 'Speakers', 'seedcast-sermon-library' ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
	<div class="scsl-speaker-grid sc-grid-cols-3">
		<?php while ( have_posts() ) : the_post(); ?>
			<?php TemplateLoader::partial( 'speaker-card', [ 'post' => get_post() ] ); ?>
		<?php endwhile; ?>
	</div>
	<?php else : ?>
		<p class="scsl-no-content"><?php esc_html_e( 'No speakers found.', 'seedcast-sermon-library' ); ?></p>
	<?php endif; ?>

</div>

<?php get_footer(); ?>
