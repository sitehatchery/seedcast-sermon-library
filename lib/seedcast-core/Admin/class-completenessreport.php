<?php
/**
 * GENERATED FILE. DO NOT EDIT.
 * Source of truth lives in the seedcast-core repository.
 *
 * @package Seedcast\Core\Admin
 */

namespace Seedcast\Core\Admin;

use Seedcast\Core\Church;
use Seedcast\Core\Completeness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The completeness report screen, rendered by whichever plugin owns the
 * content being measured.
 *
 * Two numbers, deliberately given different weight. The archive score is the
 * headline, because that is the one a church can act on today. The weekly
 * history sits underneath as a record of how each week actually went. The
 * buckets and the per-item detail live behind the week, not on the front, so
 * the top of the screen stays answerable at a glance.
 */
final class CompletenessReport {

	/**
	 * Render the report for a source.
	 *
	 * @param string $source Plugin slug registered with Completeness.
	 * @return void
	 */
	public static function render( string $source ): void {
		$definition = Completeness::get_source( $source );
		if ( null === $definition ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'No completeness definition is registered for this content.', 'seedcast-sermon-library' )
				. '</p></div>';
			return;
		}

		$archive = Completeness::archive_score( $source );
		$summary = Completeness::weekly_summary( $source, 12 );
		$start   = Completeness::measurement_start( $source );

		/*
		 * The comprehensive tab only exists when something is switched off.
		 * With nothing excluded the two scores are identical, and showing both
		 * would read as a fault rather than as extra information.
		 */
		$show_comprehensive = Completeness::has_excluded_fields( $source );

		$requested = self::query_arg( 'seedcast_view', 'library' );

		$allowed = array( 'library', 'items' );
		if ( $show_comprehensive ) {
			$allowed[] = 'comprehensive';
		}

		$tab = in_array( $requested, $allowed, true ) ? $requested : 'library';

		self::render_tabs( $tab, $definition, $show_comprehensive );

		if ( 'comprehensive' === $tab ) {
			self::render_comprehensive( $source, $archive, $definition );
			return;
		}

		if ( 'items' === $tab ) {
			self::render_items( $source, $definition );
			return;
		}

		self::render_header( $archive, $summary, $definition, $source );
		self::render_weeks( $source, $summary, $start, $archive );
		self::render_definition( $definition );
	}

	/**
	 * Read a query argument from the current screen URL.
	 *
	 * These are navigation state on a read-only report: which tab, which
	 * filter, which page. Nothing is written and nothing is acted on, so there
	 * is no form submission to verify and a nonce would be ceremony. Every
	 * value is checked against a known list by the caller before use.
	 *
	 * Reading them in one place keeps the sniff suppression to a single line
	 * rather than scattering it wherever a parameter happens to be read.
	 *
	 * @param string $key      Query argument name.
	 * @param string $fallback Value when absent.
	 * @return string
	 */
	private static function query_arg( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ $key ] ) ) {
			return $fallback;
		}

		/*
		 * Sanitised in the same expression it is read in. Doing it a line later
		 * is equally safe but static analysis cannot follow it, and a security
		 * warning that has to be explained away every time is worse than one
		 * line of directness.
		 *
		 * sanitize_key() returns an empty string for an array, so a crafted
		 * seedcast_filter[]= falls through to the fallback rather than reaching the
		 * comparison as an array.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = sanitize_key( wp_unslash( $_GET[ $key ] ) );

		return '' === $value ? $fallback : $value;
	}

	/**
	 * Base admin URL of the current screen.
	 *
	 * @return string
	 */
	private static function base_url(): string {
		return admin_url( 'admin.php?page=' . rawurlencode( self::query_arg( 'page' ) ) );
	}

	/**
	 * The drill-down: which items are short, and of what.
	 *
	 * Ordered worst first, because the screen exists to show what needs
	 * attention rather than to congratulate. The gap counts come before the
	 * list, since a pastor looking at a low score wants to know which single
	 * thing to fix, and one field missing across a hundred sermons is a more
	 * useful answer than a hundred rows.
	 *
	 * @param string $source     Plugin slug.
	 * @param array  $definition Registered definition.
	 * @return void
	 */
	private static function render_items( string $source, array $definition ): void {
		$breakdown = Completeness::item_breakdown( $source );
		$items     = $breakdown['items'];
		$gaps      = $breakdown['gaps'];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tier_keys = array();
		foreach ( Completeness::tiers() as $tier ) {
			$tier_keys[] = $tier['key'];
		}

		$allowed_filters = array_merge( array( 'all', 'incomplete', 'undated' ), $tier_keys );

		$filter = self::query_arg( 'seedcast_filter', 'all' );
		$filter = in_array( $filter, $allowed_filters, true ) ? $filter : 'all';

		$filtered = array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $filter, $tier_keys ) {
					if ( 'incomplete' === $filter ) {
						return 100 !== (int) $item['score'];
					}
					if ( 'undated' === $filter ) {
						return '' === (string) $item['date'];
					}
					if ( in_array( $filter, $tier_keys, true ) ) {
						return Completeness::tier( (int) $item['score'] )['key'] === $filter;
					}
					return true;
				}
			)
		);

		$per_page = 50;
		$paged    = max( 1, (int) self::query_arg( 'seedcast_paged', '1' ) );
		$pages    = max( 1, (int) ceil( count( $filtered ) / $per_page ) );
		$paged    = min( $paged, $pages );
		$page_of  = array_slice( $filtered, ( $paged - 1 ) * $per_page, $per_page );

		$tier_counts = Completeness::tier_counts( $source );

		$counts = array_merge(
			array(
				'all'     => count( $items ),
				'undated' => count( array_filter( $items, static function ( $i ) { return '' === (string) $i['date']; } ) ),
			),
			$tier_counts
		);

		/*
		 * One lookup for every stored week rather than one per row. Lets each
		 * row say whether the week it belongs to is a closed record, still
		 * open, or was never measured, which is the thing this list adds over
		 * the ordinary post list.
		 */
		$weeks = array();
		$start = Completeness::measurement_start( $source );
		if ( '' !== $start ) {
			foreach ( Completeness::get_weeks( $source, $start, Church::week_start_for( null ) ) as $row ) {
				$weeks[ (string) $row['week_start'] ] = $row;
			}
		}
		?>
		<div class="sc-completeness">
			<h2><?php esc_html_e( 'What is missing', 'seedcast-sermon-library' ); ?></h2>
			<p class="sc-completeness__lead">
				<?php esc_html_e( 'How many items are missing each field. The one at the top is where filling something in moves your score the most.', 'seedcast-sermon-library' ); ?>
			</p>

			<ul class="sc-gaps">
				<?php foreach ( $gaps as $label => $count ) : ?>
					<li class="sc-gap">
						<span class="sc-gap__label"><?php echo esc_html( (string) $label ); ?></span>
						<span class="sc-gap__count <?php echo 0 === (int) $count ? 'sc-gap__count--none' : ''; ?>">
							<?php
							if ( 0 === (int) $count ) {
								esc_html_e( 'all done', 'seedcast-sermon-library' );
							} else {
								printf(
									/* translators: %d: number of items missing this field. */
									esc_html( _n( '%d missing', '%d missing', (int) $count, 'seedcast-sermon-library' ) ),
									(int) $count
								);
							}
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php echo esc_html( (string) $definition['label'] ); ?></h2>
			<p class="sc-completeness__lead">
				<?php
				esc_html_e( 'Each row shows where that item stands today. The week tells you which service week it counted toward: weeks that have closed are a fixed record and no longer move, while the current week is still open. Anything without a date counts toward your library score but toward no week at all.', 'seedcast-sermon-library' );
				?>
			</p>

			<div class="sc-items__filters">
				<?php
				$labels = array();
				foreach ( Completeness::tiers() as $tier ) {
					$labels[ $tier['key'] ] = $tier['label'];
				}
				$labels['undated'] = __( 'No date', 'seedcast-sermon-library' );
				$labels['all']     = __( 'All', 'seedcast-sermon-library' );
				foreach ( $labels as $key => $label ) :
					$url = add_query_arg(
						array(
							'seedcast_view'   => 'items',
							'seedcast_filter' => $key,
						),
						self::base_url()
					);
					?>
					<a class="sc-pill <?php echo $filter === $key ? 'sc-pill--active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
						<?php echo esc_html( $label ); ?>
						<span>(<?php echo esc_html( (string) $counts[ $key ] ); ?>)</span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( empty( $page_of ) ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing here.', 'seedcast-sermon-library' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html( (string) $definition['label'] ); ?></th>
							<th style="width:190px;"><?php esc_html_e( 'Service week', 'seedcast-sermon-library' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Score', 'seedcast-sermon-library' ); ?></th>
							<th><?php esc_html_e( 'Missing', 'seedcast-sermon-library' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $page_of as $item ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $item['id'] ) ); ?>">
										<?php echo esc_html( (string) $item['title'] ); ?>
									</a>
								</td>
								<td>
									<?php self::render_week_cell( $item, $weeks, $start ); ?>
								</td>
								<td>
									<?php $tier = Completeness::tier( (int) $item['score'] ); ?>
									<span class="sc-item__score sc-item__score--<?php echo esc_attr( $tier['band'] ); ?>">
										<?php echo esc_html( (string) (int) $item['score'] ); ?>%
									</span>
									<br />
									<span class="sc-item__tier"><?php echo esc_html( $tier['label'] ); ?></span>
								</td>
								<td class="sc-item__missing">
									<?php
									echo empty( $item['missing'] )
										? esc_html__( 'Nothing', 'seedcast-sermon-library' )
										: esc_html( implode( ', ', array_map( 'strval', (array) $item['missing'] ) ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<p class="tablenav-pages" style="margin-top:1rem;">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										array(
											'seedcast_view'   => 'items',
											'seedcast_filter' => $filter,
											'seedcast_paged'  => '%#%',
										),
										self::base_url()
									),
									'format'    => '',
									'current'   => $paged,
									'total'     => $pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Tab navigation.
	 *
	 * @param string $current Active tab.
	 * @return void
	 */
	private static function render_tabs( string $current, array $definition, bool $show_comprehensive ): void {
		$base = self::base_url();
		?>
		<h2 class="nav-tab-wrapper sc-completeness__tabs">
			<a href="<?php echo esc_url( $base ); ?>"
				class="nav-tab <?php echo 'library' === $current ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Overview', 'seedcast-sermon-library' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'seedcast_view', 'items', $base ) ); ?>"
				class="nav-tab <?php echo 'items' === $current ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( (string) $definition['label'] ); ?>
			</a>
			<?php if ( $show_comprehensive ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'seedcast_view', 'comprehensive', $base ) ); ?>"
					class="nav-tab <?php echo 'comprehensive' === $current ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Comprehensive score', 'seedcast-sermon-library' ); ?>
				</a>
			<?php endif; ?>
		</h2>
		<?php
	}

	/**
	 * The comprehensive view: every field, regardless of what is switched on.
	 *
	 * Written as information rather than as a pitch. A church that has
	 * deliberately turned written content off opened this screen to look at
	 * its numbers, and would rightly resent being sold to on it. The gap
	 * between the two figures makes the case on its own.
	 *
	 * @param string $source     Plugin slug.
	 * @param array  $archive    Archive score.
	 * @param array  $definition Registered definition.
	 * @return void
	 */
	private static function render_comprehensive( string $source, array $archive, array $definition ): void {
		$configured = (int) $archive['overall'];
		$full       = (int) $archive['comprehensive'];
		$excluded   = Completeness::active_config( $source );
		?>
		<div class="sc-completeness">
			<p class="sc-completeness__explain">
				<?php
				esc_html_e( 'Your library score counts only the content you have chosen to use. The comprehensive score counts everything Sermon Library can track, whether you use it or not. It is the same measure on every church, which is what makes it worth comparing against.', 'seedcast-sermon-library' );
				?>
			</p>

			<div class="sc-completeness__compare">
				<div class="sc-score sc-score--<?php echo esc_attr( Completeness::band( $configured ) ); ?>">
					<span class="sc-score__value"><?php echo esc_html( (string) $configured ); ?></span>
					<span class="sc-score__label"><?php esc_html_e( 'Your library', 'seedcast-sermon-library' ); ?></span>
				</div>
				<div class="sc-score sc-score--<?php echo esc_attr( Completeness::band( $full ) ); ?>">
					<span class="sc-score__value"><?php echo esc_html( (string) $full ); ?></span>
					<span class="sc-score__label"><?php esc_html_e( 'Comprehensive', 'seedcast-sermon-library' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $excluded ) ) : ?>
				<h2><?php esc_html_e( 'Not currently in use', 'seedcast-sermon-library' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'These are counted in the comprehensive score but not in your library score. You can switch any of them on in settings.', 'seedcast-sermon-library' ); ?>
				</p>
				<ul class="sc-completeness__excluded">
					<?php foreach ( $excluded as $label ) : ?>
						<li><?php echo esc_html( (string) $label ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php
			/**
			 * Room for the plugin that owns the content to say where this
			 * content comes from. Kept as a hook so core carries no product
			 * copy of its own.
			 *
			 * @param string $source Plugin slug.
			 */
			do_action( 'seedcast/completeness/comprehensive_footer', $source );
			?>
		</div>
		<?php
	}

	/**
	 * The headline: current state across everything.
	 *
	 * @param array $archive    Archive score.
	 * @param array $summary    Weekly summary.
	 * @param array $definition Registered definition.
	 * @return void
	 */
	private static function render_header( array $archive, array $summary, array $definition, string $source = '' ): void {
		$gap = '' !== $source ? Completeness::completion_gap( $source ) : array( 'median' => null, 'count' => 0, 'pending' => 0 );
		$band = Completeness::band( (int) $archive['overall'] );
		?>
		<div class="sc-completeness">
			<div class="sc-completeness__hero">
				<div class="sc-score sc-score--<?php echo esc_attr( $band ); ?>">
					<span class="sc-score__value"><?php echo esc_html( (string) $archive['overall'] ); ?></span>
					<span class="sc-score__label"><?php esc_html_e( 'Library score', 'seedcast-sermon-library' ); ?></span>
				</div>

				<div class="sc-completeness__stats">
					<div class="sc-stat">
						<span class="sc-stat__value"><?php echo esc_html( (string) $summary['average'] ); ?></span>
						<span class="sc-stat__label"><?php esc_html_e( '12 week average', 'seedcast-sermon-library' ); ?></span>
					</div>
					<div class="sc-stat">
						<span class="sc-stat__value"><?php echo esc_html( (string) $summary['best'] ); ?></span>
						<span class="sc-stat__label"><?php esc_html_e( 'Best week', 'seedcast-sermon-library' ); ?></span>
					</div>
					<?php if ( null !== $gap['median'] ) : ?>
						<div class="sc-stat">
							<span class="sc-stat__value">
								<?php
								printf(
									/* translators: %d: number of days. */
									esc_html( _n( '%d day', '%d days', (int) $gap['median'], 'seedcast-sermon-library' ) ),
									(int) $gap['median']
								);
								?>
							</span>
							<span class="sc-stat__label"><?php esc_html_e( 'Typical time to finish', 'seedcast-sermon-library' ); ?></span>
						</div>
					<?php endif; ?>

					<div class="sc-stat">
						<a class="sc-stat__link" href="<?php echo esc_url( add_query_arg( array( 'seedcast_view' => 'items', 'seedcast_filter' => 'complete' ), self::base_url() ) ); ?>">
							<span class="sc-stat__value"><?php echo esc_html( (string) (int) $archive['complete'] ); ?></span>
							<span class="sc-stat__label"><?php esc_html_e( 'Complete', 'seedcast-sermon-library' ); ?></span>
						</a>
					</div>
				</div>
			</div>

			<p class="sc-completeness__explain">
				<?php
				printf(
					/* translators: 1: number of complete items, 2: total number of items. */
					esc_html__( 'The library score is the average across all your sermons, not a count of finished ones. %1$d of %2$d are at 100%% so far, and the score rises whenever you fill anything in, including in bulk.', 'seedcast-sermon-library' ),
					(int) $archive['complete'],
					(int) $archive['total']
				);
				?>
			</p>
			<p class="sc-completeness__explain">
				<?php
				esc_html_e( 'The weekly scores below are a separate record of how each week actually went, and do not change once the week has passed.', 'seedcast-sermon-library' );
				?>
			</p>

			<?php if ( null !== $gap['median'] ) : ?>
				<p class="sc-completeness__explain">
					<?php
					printf(
						/* translators: 1: number of days, 2: number of items counted. */
						esc_html( _n(
							'Measured across %2$d item finished since you started using this, half were complete within %1$d day of the date they were preached.',
							'Measured across %2$d items finished since you started using this, half were complete within %1$d days of the date they were preached.',
							(int) $gap['median'],
							'seedcast-sermon-library'
						) ),
						(int) $gap['median'],
						(int) $gap['count']
					);
					?>
				</p>
			<?php endif; ?>

			<div class="sc-completeness__buckets">
				<?php
				self::render_bar( __( 'Details', 'seedcast-sermon-library' ), (int) $archive['metadata'] );
				self::render_bar( __( 'Written content', 'seedcast-sermon-library' ), (int) $archive['content'] );
				?>
			</div>

			<?php if ( '' !== $source ) : ?>
				<?php self::render_tiers( $source ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * How the library is spread across the tiers.
	 *
	 * A single count of everything short of perfect reads as a wall, and a
	 * wall is not something anybody starts chipping at. Split into tiers the
	 * same library reads as a queue: a few nearly done, some part way, the
	 * rest waiting. It also moves visibly when work happens, which one number
	 * stuck near the total does not.
	 *
	 * @param string $source Plugin slug.
	 * @return void
	 */
	private static function render_tiers( string $source ): void {
		$counts = Completeness::tier_counts( $source );
		$total  = array_sum( $counts );

		if ( $total < 1 ) {
			return;
		}
		?>
		<div class="sc-tiers">
			<div class="sc-tiers__bar">
				<?php foreach ( Completeness::tiers() as $tier ) : ?>
					<?php
					$count = (int) $counts[ $tier['key'] ];
					if ( $count < 1 ) {
						continue;
					}
					$width = ( $count / $total ) * 100;
					?>
					<span class="sc-tiers__seg sc-tiers__seg--<?php echo esc_attr( $tier['band'] ); ?>"
						style="width:<?php echo esc_attr( (string) $width ); ?>%"
						title="<?php echo esc_attr( $tier['label'] . ': ' . $count ); ?>"></span>
				<?php endforeach; ?>
			</div>

			<ul class="sc-tiers__legend">
				<?php foreach ( Completeness::tiers() as $tier ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'seedcast_view' => 'items', 'seedcast_filter' => $tier['key'] ), self::base_url() ) ); ?>">
							<span class="sc-tiers__dot sc-tiers__dot--<?php echo esc_attr( $tier['band'] ); ?>"></span>
							<span class="sc-tiers__count"><?php echo esc_html( (string) (int) $counts[ $tier['key'] ] ); ?></span>
							<span class="sc-tiers__label"><?php echo esc_html( $tier['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * One labelled progress bar.
	 *
	 * @param string $label Bar label.
	 * @param int    $value Percentage.
	 * @return void
	 */
	private static function render_bar( string $label, int $value ): void {
		?>
		<div class="sc-bar">
			<div class="sc-bar__head">
				<span><?php echo esc_html( $label ); ?></span>
				<span><?php echo esc_html( (string) $value ); ?>%</span>
			</div>
			<div class="sc-bar__track">
				<div class="sc-bar__fill sc-bar__fill--<?php echo esc_attr( Completeness::band( $value ) ); ?>"
					style="width:<?php echo esc_attr( (string) max( 0, min( 100, $value ) ) ); ?>%"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * The weekly history.
	 *
	 * @param string $source  Plugin slug.
	 * @param array  $summary Weekly summary.
	 * @param string $start   Measurement start.
	 * @return void
	 */
	private static function render_weeks( string $source, array $summary, string $start, array $archive = array() ): void {
		?>
		<h2><?php esc_html_e( 'Week by week', 'seedcast-sermon-library' ); ?></h2>
		<?php
		/*
		 * The weekly scores genuinely do exclude things, unlike the library
		 * score, and saying which keeps the two sets of numbers from looking
		 * like they disagree. Stated here rather than at the top because this
		 * is the only place it is true.
		 */
		$excluded = array();

		if ( ! empty( $archive['undated'] ) ) {
			$excluded[] = sprintf(
				/* translators: %d: number of items with no date. */
				_n( '%d has no date', '%d have no date', (int) $archive['undated'], 'seedcast-sermon-library' ),
				(int) $archive['undated']
			);
		}

		if ( ! empty( $excluded ) ) :
			?>
			<p class="description sc-completeness__excluded-note sc-completeness__excluded-note--warn">
				<?php
				printf(
					/* translators: %s: reasons items are not counted weekly. */
					esc_html__( 'Not counted in any week: %s. Sermons posted before you started using this report are also outside the weekly scores.', 'seedcast-sermon-library' ),
					esc_html( implode( ', ', $excluded ) )
				);
				?>
				<a href="<?php echo esc_url( add_query_arg( array( 'seedcast_view' => 'items', 'seedcast_filter' => 'undated' ), self::base_url() ) ); ?>">
					<?php esc_html_e( 'Show them', 'seedcast-sermon-library' ); ?>
				</a>
			</p>
			<?php
		endif;
		?>
		<?php

		if ( empty( $summary['recent'] ) ) {
			?>
			<p class="description">
				<?php
				if ( '' !== $start ) {
					printf(
						/* translators: %s: date measurement began. */
						esc_html__( 'Measurement began the week of %s. The first score appears once that week has finished.', 'seedcast-sermon-library' ),
						esc_html( self::format_week( $start ) )
					);
				} else {
					esc_html_e( 'Nothing has been measured yet.', 'seedcast-sermon-library' );
				}
				?>
			</p>
			<?php
			return;
		}

		$weeks = array_reverse( $summary['recent'] );
		?>
		<div class="sc-weeks">
			<?php foreach ( $weeks as $week ) : ?>
				<?php
				$score   = (int) $week['score'];
				$missing = 'missing' === $week['state'];
				$band    = $missing ? 'poor' : Completeness::band( $score );
				$details = json_decode( (string) $week['details'], true );
				$details = is_array( $details ) ? $details : array();
				?>
				<details class="sc-week sc-week--<?php echo esc_attr( $band ); ?>">
					<summary class="sc-week__summary">
						<span class="sc-week__dot sc-week__dot--<?php echo esc_attr( $band ); ?>"></span>
						<span class="sc-week__date"><?php echo esc_html( self::format_week( (string) $week['week_start'] ) ); ?></span>
						<span class="sc-week__score">
							<?php echo $missing ? esc_html__( 'Nothing posted', 'seedcast-sermon-library' ) : esc_html( $score . '%' ); ?>
						</span>
						<?php if ( ! empty( $week['is_amended'] ) ) : ?>
							<span class="sc-week__flag" title="<?php esc_attr_e( 'A date change after this week closed caused it to be recalculated.', 'seedcast-sermon-library' ); ?>">
								<?php esc_html_e( 'amended', 'seedcast-sermon-library' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( empty( $week['is_locked'] ) ) : ?>
							<span class="sc-week__flag sc-week__flag--open"><?php esc_html_e( 'in progress', 'seedcast-sermon-library' ); ?></span>
						<?php endif; ?>
					</summary>

					<div class="sc-week__body">
						<?php if ( $missing ) : ?>
							<p class="description">
								<?php esc_html_e( 'No dated item fell in this week. A week with nothing posted counts as zero rather than being skipped.', 'seedcast-sermon-library' ); ?>
							</p>
						<?php else : ?>
							<p class="sc-week__buckets">
								<span><?php esc_html_e( 'Details', 'seedcast-sermon-library' ); ?>: <strong><?php echo esc_html( (string) (int) $week['metadata_score'] ); ?>%</strong></span>
								<span><?php esc_html_e( 'Written content', 'seedcast-sermon-library' ); ?>: <strong><?php echo esc_html( (string) (int) $week['content_score'] ); ?>%</strong></span>
							</p>
							<table class="widefat striped sc-week__items">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Item', 'seedcast-sermon-library' ); ?></th>
										<th><?php esc_html_e( 'Score', 'seedcast-sermon-library' ); ?></th>
										<th><?php esc_html_e( 'Missing', 'seedcast-sermon-library' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $details as $item ) : ?>
										<tr>
											<td>
												<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $item['id'] ) ); ?>">
													<?php echo esc_html( (string) $item['title'] ); ?>
												</a>
											</td>
											<td><?php echo esc_html( (string) (int) $item['overall'] ); ?>%</td>
											<td>
												<?php
												echo empty( $item['missing'] )
													? esc_html__( 'Nothing', 'seedcast-sermon-library' )
													: esc_html( implode( ', ', array_map( 'strval', (array) $item['missing'] ) ) );
												?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>

						<p class="description sc-week__version">
							<?php
							printf(
								/* translators: %s: definition version. */
								esc_html__( 'Scored against definition version %s.', 'seedcast-sermon-library' ),
								esc_html( (string) $week['definition_version'] )
							);
							?>
						</p>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * What the current definition actually requires.
	 *
	 * Rendered here rather than linked out, so a site running an older version
	 * always documents the rules it is actually applying.
	 *
	 * @param array $definition Registered definition.
	 * @return void
	 */
	private static function render_definition( array $definition ): void {
		?>
		<h2><?php esc_html_e( 'What counts as complete', 'seedcast-sermon-library' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: definition version. */
				esc_html__( 'Definition version %s. Weeks scored under an earlier version keep their original score and say so.', 'seedcast-sermon-library' ),
				esc_html( (string) $definition['version'] )
			);
			?>
		</p>
		<table class="widefat striped" style="max-width:640px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Field', 'seedcast-sermon-library' ); ?></th>
					<th><?php esc_html_e( 'Counts toward', 'seedcast-sermon-library' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $definition['fields'] as $field ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $field['label'] ?? $field['key'] ) ); ?></td>
						<td>
							<?php
							echo ( isset( $field['bucket'] ) && 'content' === $field['bucket'] )
								? esc_html__( 'Written content', 'seedcast-sermon-library' )
								: esc_html__( 'Details', 'seedcast-sermon-library' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The service week an item belongs to, and what that week is worth.
	 *
	 * The distinction this draws is the reason to read this list rather than
	 * the ordinary post list. A closed week is a fixed record that no longer
	 * moves however much is filled in afterwards; the open week is still
	 * being written; a week before measurement began was never judged at all.
	 * The score in the next column is always today's, never the locked one,
	 * and those two can differ for any closed week.
	 *
	 * @param array  $item  One item from item_breakdown().
	 * @param array  $weeks Stored weeks keyed by week start.
	 * @param string $start Measurement start.
	 * @return void
	 */
	private static function render_week_cell( array $item, array $weeks, string $start ): void {
		$date = (string) $item['date'];

		if ( '' === $date ) {
			?>
			<span class="sc-item__nodate"><?php esc_html_e( 'No date', 'seedcast-sermon-library' ); ?></span>
			<br />
			<span class="sc-item__weeknote"><?php esc_html_e( 'Counts toward no week', 'seedcast-sermon-library' ); ?></span>
			<?php
			return;
		}

		$week = (string) $item['week'];
		echo '<span class="sc-item__week">' . esc_html( self::format_week( $week ) ) . '</span><br />';

		if ( '' !== $start && $week < $start ) {
			?>
			<span class="sc-item__weeknote"><?php esc_html_e( 'Before measuring began', 'seedcast-sermon-library' ); ?></span>
			<?php
			return;
		}

		if ( ! isset( $weeks[ $week ] ) ) {
			?>
			<span class="sc-item__weeknote sc-item__weeknote--open"><?php esc_html_e( 'This week, still open', 'seedcast-sermon-library' ); ?></span>
			<?php
			return;
		}

		$row = $weeks[ $week ];

		if ( empty( $row['is_locked'] ) ) {
			?>
			<span class="sc-item__weeknote sc-item__weeknote--open"><?php esc_html_e( 'This week, still open', 'seedcast-sermon-library' ); ?></span>
			<?php
			return;
		}

		printf(
			'<span class="sc-item__weeknote">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: the locked score for that week. */
					__( 'Week recorded at %d%%', 'seedcast-sermon-library' ),
					(int) $row['score']
				)
			)
		);

		if ( ! empty( $row['is_amended'] ) ) {
			?>
			<span class="sc-week__flag"><?php esc_html_e( 'amended', 'seedcast-sermon-library' ); ?></span>
			<?php
		}
	}

	/**
	 * Format a week start for display, as a range.
	 *
	 * @param string $week_start Y-m-d.
	 * @return string
	 */
	private static function format_week( string $week_start ): string {
		$bounds = Church::week_bounds_for( $week_start );
		if ( '' === $bounds[0] ) {
			return $week_start;
		}

		$format = (string) get_option( 'date_format', 'M j, Y' );

		try {
			$from = new \DateTimeImmutable( $bounds[0], wp_timezone() );
			$to   = new \DateTimeImmutable( $bounds[1], wp_timezone() );
		} catch ( \Exception $e ) {
			return $week_start;
		}

		return wp_date( $format, $from->getTimestamp() ) . ' - ' . wp_date( $format, $to->getTimestamp() );
	}
}
