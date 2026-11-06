<?php
namespace SeedcastSermonLibrary\Admin;

use SeedcastSermonLibrary\Pro\ProBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How much of the library carries topics, and which sermons do not.
 *
 * Needed before anything leans on topics, the assistant most of all. A sermon
 * with no topics looks exactly like one the model declined to file, which is
 * correct on a page and misleading for retrieval: without a coverage number
 * nobody can tell whether filtering by topic sees the whole library or a third
 * of it. So this separates the two, and answers one question: can retrieval
 * trust topics yet.
 *
 * Separate from the completeness score, which stays about one sermon's own
 * fields. This is about the library.
 *
 * The list it shows can be handed straight to the engine's queue as one run,
 * topics only. A sermon the model declines is remembered and not asked again.
 */
class TopicCoverage {

	public const PAGE = 'seedcast-sermon-library-topic-coverage';

	private const CAP = 'edit_others_posts';

	/** At or above this share, topic filtering sees essentially the library. */
	private const TRUSTED = 90;

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 19 );
		add_action( 'admin_post_scsl_topics_backfill', [ $this, 'backfill' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Topic Coverage', 'seedcast-sermon-library' ),
			__( 'Topic Coverage', 'seedcast-sermon-library' ),
			self::CAP,
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/**
	 * The numbers, worked out in a few queries rather than a loop over every
	 * sermon.
	 *
	 * @return array{total:int, topics:int, declined:int, unfiled:int[], passages:int, described:int}
	 */
	public static function numbers(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map( 'intval', (array) $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'scsl_sermon' AND post_status = 'publish' ORDER BY post_date DESC"
		) );

		$with = static function ( string $taxonomy ) use ( $wpdb ): array {
			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = %s",
				$taxonomy
			) ) );
		};

		$topics   = array_flip( $with( 'scsl_topic' ) );
		$passages = array_flip( $with( 'scsl_scripture' ) );

		$declined = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
			ProBridge::META_TOPICS_DECLINED
		) ) ) );

		$described = array_flip( array_map( 'intval', (array) $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_scsl_content_description' AND meta_value <> ''"
		) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = [
			'total'     => count( $ids ),
			'topics'    => 0,
			'declined'  => 0,
			'unfiled'   => [],
			'passages'  => 0,
			'described' => 0,
		];

		foreach ( $ids as $id ) {
			if ( isset( $topics[ $id ] ) ) {
				$out['topics']++;
			} elseif ( isset( $declined[ $id ] ) ) {
				$out['declined']++;
			} else {
				$out['unfiled'][] = $id;
			}

			if ( isset( $passages[ $id ] ) ) {
				$out['passages']++;
			}

			if ( isset( $described[ $id ] ) ) {
				$out['described']++;
			}
		}

		return $out;
	}

	private static function percent( int $part, int $total ): int {
		return $total > 0 ? (int) round( 100 * $part / $total ) : 0;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$n = self::numbers();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Topic Coverage', 'seedcast-sermon-library' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count for a message.
		$queued = isset( $_GET['queued'] ) ? absint( $_GET['queued'] ) : -1;

		if ( $queued >= 0 ) {
			$url = (string) apply_filters( 'seedcast_pro_queue_url', '' );
			echo '<div class="notice notice-success is-dismissible"><p>';
			/* translators: %d: number of sermons. */
			echo esc_html( sprintf( _n( '%d sermon queued for topics. The numbers below move as it works through them.', '%d sermons queued for topics. The numbers below move as it works through them.', $queued, 'seedcast-sermon-library' ), $queued ) );

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count for a message.
			$asked   = isset( $_GET['asked'] ) ? absint( $_GET['asked'] ) : $queued;
			$skipped = max( 0, $asked - $queued );

			if ( $skipped ) {
				/* translators: %d: number of sermons. */
				echo ' ' . esc_html( sprintf( _n( '%d could not be: it has no transcript or recording to read.', '%d could not be: they have no transcript or recording to read.', $skipped, 'seedcast-sermon-library' ), $skipped ) );
			}

			if ( '' !== $url ) {
				echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Watch the queue', 'seedcast-sermon-library' ) . '</a>';
			}

			echo '</p></div>';
		}

		if ( ! $n['total'] ) {
			echo '<p>' . esc_html__( 'There are no published sermons yet.', 'seedcast-sermon-library' ) . '</p></div>';
			return;
		}

		$share = self::percent( $n['topics'], $n['total'] );

		echo '<div class="card" style="max-width:none;"><h2 style="margin-top:0;">';

		if ( $share >= self::TRUSTED ) {
			esc_html_e( 'Topics cover the library.', 'seedcast-sermon-library' );
		} else {
			/* translators: %d: percentage. */
			echo esc_html( sprintf( __( 'Filtering by topic sees %d%% of the library.', 'seedcast-sermon-library' ), $share ) );
		}

		echo '</h2><p>';

		if ( $share >= self::TRUSTED ) {
			esc_html_e( 'Anything that finds sermons by topic, including the assistant, is looking at nearly everything you have preached.', 'seedcast-sermon-library' );
		} else {
			esc_html_e( 'Sermons with no topics are invisible to anything that finds sermons by topic, the assistant included. Filing them is the quickest way to improve what it can find.', 'seedcast-sermon-library' );
		}

		echo '</p></div>';

		$rows = [
			[ __( 'Carry at least one topic', 'seedcast-sermon-library' ), $n['topics'] ],
			[ __( 'Checked, and none of your topics fit', 'seedcast-sermon-library' ), $n['declined'] ],
			[ __( 'Never filed', 'seedcast-sermon-library' ), count( $n['unfiled'] ) ],
			[ __( 'Carry at least one passage', 'seedcast-sermon-library' ), $n['passages'] ],
			[ __( 'Have a description', 'seedcast-sermon-library' ), $n['described'] ],
		];

		echo '<table class="widefat striped" style="max-width:40em;margin-top:1em;"><thead><tr><th>';
		/* translators: %s: number of sermons. */
		echo esc_html( sprintf( __( 'Of %s published sermons', 'seedcast-sermon-library' ), number_format_i18n( $n['total'] ) ) );
		echo '</th><th style="text-align:right;">' . esc_html__( 'Sermons', 'seedcast-sermon-library' ) . '</th><th style="text-align:right;">%</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row[0] ) . '</td><td style="text-align:right;">' . esc_html( number_format_i18n( (int) $row[1] ) ) . '</td><td style="text-align:right;">' . esc_html( (string) self::percent( (int) $row[1], $n['total'] ) ) . '%</td></tr>';
		}

		echo '</tbody></table>';

		if ( $n['declined'] ) {
			echo '<p class="description">' . esc_html__( 'Declined sermons are not asked again. If many are declined, the topic list may be missing a subject you preach on: add it under Sermons > Topics, then file those sermons by hand.', 'seedcast-sermon-library' ) . '</p>';
		}

		if ( $n['unfiled'] ) {
			echo '<h2 style="margin-top:2em;">' . esc_html__( 'Never filed', 'seedcast-sermon-library' ) . '</h2>';

			if ( has_filter( 'seedcast_pro_queue_terms' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="scsl_topics_backfill" />';
				wp_nonce_field( 'scsl_topics_backfill' );
				echo '<p>';
				submit_button(
					/* translators: %d: number of sermons. */
					sprintf( _n( 'File %d sermon under topics', 'File %d sermons under topics', count( $n['unfiled'] ), 'seedcast-sermon-library' ), count( $n['unfiled'] ) ),
					'primary',
					'submit',
					false
				);
				echo ' <span class="description">' . esc_html__( 'Topics only, chosen from your own list. Runs in the background a few at a time, within the daily transcript allowance.', 'seedcast-sermon-library' ) . '</span></p></form>';
			} else {
				echo '<p class="description">' . esc_html__( 'Filing these automatically needs the Seedcast AI Engine with a Sermon Library subscription. They can be filed by hand from each sermon.', 'seedcast-sermon-library' ) . '</p>';
			}

			echo '<ul>';

			foreach ( array_slice( $n['unfiled'], 0, 100 ) as $id ) {
				echo '<li><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a> <span class="description">' . esc_html( get_the_date( '', $id ) ) . '</span></li>';
			}

			echo '</ul>';

			if ( count( $n['unfiled'] ) > 100 ) {
				/* translators: %d: number of sermons. */
				echo '<p class="description">' . esc_html( sprintf( __( 'And %d more.', 'seedcast-sermon-library' ), count( $n['unfiled'] ) - 100 ) ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * Hand every never-filed sermon to the engine, topics only.
	 */
	public function backfill(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'seedcast-sermon-library' ) );
		}

		check_admin_referer( 'scsl_topics_backfill' );

		$ids = self::numbers()['unfiled'];

		/**
		 * Queue posts for one extra, as declared on the product. Answered by
		 * the AI Engine's Content Queue, with how many it actually queued:
		 * a sermon with no transcript or recording has nothing to read.
		 *
		 * @param int    $queued    Zero.
		 * @param int[]  $ids       Sermon ids.
		 * @param string $api_field The extra, as declared.
		 */
		$queued = (int) apply_filters( 'seedcast_pro_queue_terms', 0, $ids, 'topics' );

		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'queued' => $queued, 'asked' => count( $ids ) ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
