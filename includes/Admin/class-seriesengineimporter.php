<?php
/**
 * Series Engine CSV importer, with flexible series mapping.
 *
 * Renders as part of the unified Import/Export page.
 * Three-step flow:
 *  1. Upload CSV → parse and preview
 *  2. Map each SE series to a SL series (create new / use existing / skip)
 *  3. Run import with mapping applied
 *
 * @package SeedcastSermonLibrary\Admin
 */

namespace SeedcastSermonLibrary\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles the Import/Export admin page and Series Engine import AJAX.
 */
class SeriesEngineImporter {

	public function init(): void {
		add_action( 'admin_menu',                      [ $this, 'add_page'        ] );
		add_action( 'admin_enqueue_scripts',           [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_scsl_import_parse',         [ $this, 'ajax_parse' ] );
		add_action( 'wp_ajax_scsl_import_run',           [ $this, 'ajax_run'   ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'seedcast-sermon-library',
			__( 'Import / Export', 'seedcast-sermon-library' ),
			__( 'Import / Export', 'seedcast-sermon-library' ),
			'manage_options',
			'seedcast-sermon-library-import',
			[ $this, 'render' ]
		);
	}

	// ── Admin Page ────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		// Instantiate exporter for its render method
		$exporter = new Exporter();

		// Show JSON import results if redirected back after a successful import.
		// Integer counts passed through our own wp_safe_redirect, so no nonce is needed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['json_import'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: topics created 2: speakers created 3: series created 4: sermons created 5: updated 6: skipped */
				esc_html__( 'Import complete. Topics: %1$d created. Speakers: %2$d created. Series: %3$d created. Sermons: %4$d created, %5$d updated, %6$d skipped.', 'seedcast-sermon-library' ),
				absint( $_GET['topics_created']   ?? 0 ),
				absint( $_GET['speakers_created'] ?? 0 ),
				absint( $_GET['series_created']   ?? 0 ),
				absint( $_GET['sermons_created']  ?? 0 ),
				absint( $_GET['sermons_updated']  ?? 0 ),
				absint( $_GET['sermons_skipped']  ?? 0 )
			);
			echo '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Get existing series for mapping dropdowns
		$existing_series = get_posts( [
			'post_type'      => 'scsl_series',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => 'publish',
		] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export', 'seedcast-sermon-library' ); ?></h1>

			<?php
			// Tab switcher
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch, no data mutation
			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'import';
			$tab_url    = admin_url( 'admin.php?page=seedcast-sermon-library-import&tab=' );
			?>
			<nav class="nav-tab-wrapper" style="margin-bottom:1.5rem;">
				<a href="<?php echo esc_url( $tab_url . 'import' ); ?>"
				   class="nav-tab <?php echo esc_attr( $active_tab === 'import' ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Import', 'seedcast-sermon-library' ); ?>
				</a>
				<a href="<?php echo esc_url( $tab_url . 'export' ); ?>"
				   class="nav-tab <?php echo esc_attr( $active_tab === 'export' ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Export', 'seedcast-sermon-library' ); ?>
				</a>
			</nav>

			<?php if ( $active_tab === 'export' ) : ?>
				<?php $exporter->render(); ?>
				</div><!-- .wrap -->
				<?php
				return;
			endif;
			?>

			<!-- ── IMPORT TAB ─────────────────────────────────────────────── -->

			<!-- Import source selector -->
			<div class="scsl-settings-card" style="max-width:640px;margin-bottom:1.5rem;">
				<label style="font-weight:600;display:block;margin-bottom:.5rem;">
					<?php esc_html_e( 'Import From', 'seedcast-sermon-library' ); ?>
				</label>
				<select id="scsl-import-source" style="max-width:320px;">
					<option value="sermon-library" selected><?php esc_html_e( 'Sermon Library (JSON)', 'seedcast-sermon-library' ); ?></option>
					<option value="series-engine"><?php esc_html_e( 'Series Engine (CSV)', 'seedcast-sermon-library' ); ?></option>
					<option value="sermon-manager"<?php echo SermonManagerImporter::source_present() ? '' : ' disabled'; ?>>
						<?php
						echo SermonManagerImporter::source_present()
							? esc_html__( 'Sermon Manager / Mattytap Sermons (this site)', 'seedcast-sermon-library' )
							: esc_html__( 'Sermon Manager / Mattytap Sermons (nothing found on this site)', 'seedcast-sermon-library' );
						?>
					</option>
				</select>
				<p class="description" style="margin-top:.5rem;">
					<?php esc_html_e( 'Choose the platform you are importing data from.', 'seedcast-sermon-library' ); ?>
				</p>
			</div>

			<!-- Sermon Library JSON import panel -->
			<div id="scsl-source-sermon-library" class="scsl-import-source-panel">
				<div class="scsl-settings-card" style="max-width:640px;">
					<?php ( new JsonImporter() )->render_panel(); ?>
				</div>
			</div>


			<!-- Sermon Manager / Mattytap import panel -->
			<div id="scsl-source-sermon-manager" class="scsl-import-source-panel" style="display:none;">
				<div class="scsl-settings-card" style="max-width:820px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Import from Sermon Manager', 'seedcast-sermon-library' ); ?></h2>
					<p>
						<?php esc_html_e( 'Reads sermons already on this site from Sermon Manager, Sermon Works or Mattytap Sermons. There is no file to export or upload. Nothing in the original plugin is changed, so it keeps working and you can run this again if you need to.', 'seedcast-sermon-library' ); ?>
					</p>

					<button type="button" class="button button-primary" id="scsl-wpfc-scan">
						<?php esc_html_e( 'Scan this site', 'seedcast-sermon-library' ); ?>
					</button>

					<div id="scsl-wpfc-report" style="display:none;margin-top:1.25rem;"></div>

					<div id="scsl-wpfc-run-wrap" style="display:none;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid #dcdcde;">
						<p style="margin-top:0;">
							<label style="font-weight:600;display:block;margin-bottom:.35rem;">
								<?php esc_html_e( 'Publish state', 'seedcast-sermon-library' ); ?>
							</label>
							<label style="margin-right:1.25rem;">
								<input type="radio" name="scsl-wpfc-status" value="keep" checked />
								<?php esc_html_e( 'Match the original', 'seedcast-sermon-library' ); ?>
							</label>
							<label>
								<input type="radio" name="scsl-wpfc-status" value="draft" />
								<?php esc_html_e( 'Import everything as drafts', 'seedcast-sermon-library' ); ?>
							</label>
							<span class="description" style="display:block;margin-top:.35rem;">
								<?php esc_html_e( 'Drafts let you check the result before anything is public. Speakers and series come across with a name and description but no photo or artwork yet, so drafting is worth considering even if your sermons were already live.', 'seedcast-sermon-library' ); ?>
							</span>
						</p>
						<button type="button" class="button button-primary" id="scsl-wpfc-run">
							<?php esc_html_e( 'Import now', 'seedcast-sermon-library' ); ?>
						</button>
						<span id="scsl-wpfc-progress" style="margin-left:.75rem;"></span>
						<div id="scsl-wpfc-log" style="display:none;margin-top:1rem;max-height:280px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:.75rem;font-size:12px;font-family:monospace;"></div>
					</div>
				</div>
			</div>

			<!-- Series Engine CSV import panel -->
			<div id="scsl-source-series-engine" class="scsl-import-source-panel" style="display:none;">

				<!-- Step 1: Upload CSV -->
				<div class="scsl-import-panel" id="scsl-step-1">
					<div class="scsl-settings-card" style="max-width:640px;">
						<h2 style="margin-top:0;"><?php esc_html_e( 'Step 1: Upload your CSV', 'seedcast-sermon-library' ); ?></h2>
						<p class="description" style="margin-bottom:1rem;">
							<?php esc_html_e( 'Export your sermons from Series Engine, then upload the CSV here. Nothing is written until you have reviewed the series mapping on the next step.', 'seedcast-sermon-library' ); ?>
						</p>

						<input type="file" id="scsl_import_file" accept=".csv,text/csv" />

						<p style="margin-top:1rem;">
							<button type="button" class="button button-primary" id="scsl-parse-btn" disabled>
								<?php esc_html_e( 'Parse CSV', 'seedcast-sermon-library' ); ?>
							</button>
						</p>

						<div id="scsl-parse-progress" style="display:none;margin-top:1rem;">
							<span class="scsl-spinner"></span>
							<?php esc_html_e( 'Parsing…', 'seedcast-sermon-library' ); ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Step 2: Map Series -->
			<div class="scsl-import-panel" id="scsl-step-2" style="display:none;">
				<div class="scsl-settings-card">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Step 2: Map Series', 'seedcast-sermon-library' ); ?></h2>
					<p><?php esc_html_e( 'For each series found in your CSV, choose what to do in SermonLibrary.', 'seedcast-sermon-library' ); ?></p>

					<div id="scsl-series-mapping-wrap">
						<!-- Populated by JS after parse -->
					</div>

					<div id="scsl-parse-summary" style="margin:1rem 0;padding:.75rem 1rem;background:#f0f6fc;border:1px solid #c3d4e4;border-radius:4px;font-size:13px;"></div>

					<div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid #dcdcde;">
						<h3 style="margin-top:0;"><?php esc_html_e( 'Import Options', 'seedcast-sermon-library' ); ?></h3>
						<p>
							<label>
								<input type="checkbox" id="scscsl_dry_run" checked />
								<strong><?php esc_html_e( 'Dry run', 'seedcast-sermon-library' ); ?></strong>
								<?php esc_html_e( '(preview only, nothing will be saved)', 'seedcast-sermon-library' ); ?>
							</label>
						</p>
						<p>
							<label style="font-weight:600;"><?php esc_html_e( 'If a sermon already exists:', 'seedcast-sermon-library' ); ?></label><br>
							<label><input type="radio" name="scsl_duplicate" value="skip" checked /> <?php esc_html_e( 'Skip (recommended)', 'seedcast-sermon-library' ); ?></label>&nbsp;&nbsp;
							<label><input type="radio" name="scsl_duplicate" value="overwrite" /> <?php esc_html_e( 'Overwrite meta fields', 'seedcast-sermon-library' ); ?></label>&nbsp;&nbsp;
							<label><input type="radio" name="scsl_duplicate" value="create" /> <?php esc_html_e( 'Create anyway', 'seedcast-sermon-library' ); ?></label>
						</p>
					</div>

					<div style="margin-top:1rem;">
						<button type="button" id="scsl-back-btn" class="button">
							← <?php esc_html_e( 'Back', 'seedcast-sermon-library' ); ?>
						</button>
						<button type="button" id="scsl-run-btn" class="button button-primary" style="margin-left:.5rem;">
							<?php esc_html_e( 'Run Import →', 'seedcast-sermon-library' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Step 3: Results -->
			<div class="scsl-import-panel" id="scsl-step-3" style="display:none;">
				<div class="scsl-settings-card">
					<h2 style="margin-top:0;" id="scsl-results-title"><?php esc_html_e( 'Step 3: Results', 'seedcast-sermon-library' ); ?></h2>

					<div id="scsl-results-summary" style="padding:.85rem 1rem;border-radius:4px;margin-bottom:1rem;font-weight:600;font-size:14px;"></div>

					<div id="scsl-results-counts" style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1.5rem;"></div>

					<details open>
						<summary style="cursor:pointer;font-weight:600;margin-bottom:.5rem;">
							<?php esc_html_e( 'Import Log', 'seedcast-sermon-library' ); ?>
						</summary>
						<div id="scsl-import-log" style="font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;border-radius:4px;padding:1rem;max-height:450px;overflow-y:auto;white-space:pre-wrap;line-height:1.6;"></div>
					</details>

					<div style="margin-top:1.5rem;">
						<button type="button" id="scsl-reset-btn" class="button">
							<?php esc_html_e( '← Start Over', 'seedcast-sermon-library' ); ?>
						</button>
						<?php if ( $existing_series ) : ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=scsl_sermon' ) ); ?>"
						   class="button button-primary" style="margin-left:.5rem;">
							<?php esc_html_e( 'View Sermons →', 'seedcast-sermon-library' ); ?>
						</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		</div><!-- .wrap -->

		<?php
		wp_localize_script( 'scsl-admin', 'scslImportData', [
			'nonce'          => wp_create_nonce( 'scsl_import_nonce' ),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'existingSeries' => array_map( function( $p ) {
				return [
					'id'    => $p->ID,
					'title' => html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ),
				];
			}, $existing_series ),
		] );
		?>

		<?php
		// Localize the JSON import label for use by the enqueued switcher script
		wp_localize_script( 'scsl-admin', 'scslImportI18n', [
			'jsonComingSoon' => __( 'JSON import is coming soon. Use the Export and Import JSON feature for now.', 'seedcast-sermon-library' ),
			'noSeriesFound'  => __( 'No series found in the CSV.', 'seedcast-sermon-library' ),
			'seSeriesCol'    => __( 'Series Engine Series', 'seedcast-sermon-library' ),
			'sermonsCol'     => __( 'Sermons', 'seedcast-sermon-library' ),
			'importAs'       => __( 'Import as', 'seedcast-sermon-library' ),
			'createNew'      => __( 'Create new:', 'seedcast-sermon-library' ),
			'useExisting'    => __( 'Use existing:', 'seedcast-sermon-library' ),
			'skipSeries'     => __( 'Skip this series', 'seedcast-sermon-library' ),
			'foundInCsv'     => __( 'found in the CSV.', 'seedcast-sermon-library' ),
			'importing'      => __( 'Importing…', 'seedcast-sermon-library' ),
			'dryRunTitle'    => __( 'Step 3: Dry run results', 'seedcast-sermon-library' ),
			'importTitle'    => __( 'Step 3: Import results', 'seedcast-sermon-library' ),
			'running'        => __( 'Running…', 'seedcast-sermon-library' ),
			'requestFailed'  => __( 'Request failed.', 'seedcast-sermon-library' ),
			'runImport'      => __( 'Run import', 'seedcast-sermon-library' ),
		] );
		?>

		<?php
	}



	// ── AJAX: Parse CSV ───────────────────────────────────────────────────

	public function ajax_parse(): void {
		check_ajax_referer( 'scsl_import_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ] );

		$csv = isset( $_POST['csv_data'] ) ? sanitize_textarea_field( wp_unslash( $_POST['csv_data'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_ajax_referer() above
		if ( ! $csv ) wp_send_json_error( [ 'message' => 'No CSV data.' ] );

		$data = ( new SeriesEngineRunner() )->parse_csv( $csv );

		// Build series sermon counts
		$sermon_counts = [];
		foreach ( $data['smm'] as $row ) {
			$msg_id    = $row[1] ?? 0;
			$series_id = $row[2] ?? 0;
			if ( ! isset( $sermon_counts[ $series_id ] ) ) $sermon_counts[ $series_id ] = 0;
			$sermon_counts[ $series_id ]++;
		}

		// Format series for JS
		$series_out = array_map( function( $s ) {
			return [ 'id' => $s[0], 'title' => $s[1] ?? 'Untitled' ];
		}, $data['series'] );

		wp_send_json_success( [
			'series'               => $series_out,
			'series_sermon_counts' => $sermon_counts,
			'totals'               => [
				'messages' => count( $data['message'] ),
				'speakers' => count( $data['speaker'] ),
				'series'   => count( $data['series'] ),
				'topics'   => count( $data['topic'] ),
			],
		] );
	}

	// ── AJAX: Run Import ──────────────────────────────────────────────────

	public function ajax_run(): void {
		check_ajax_referer( 'scsl_import_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ] );

		$csv        = isset( $_POST['csv_data'] )   ? sanitize_textarea_field( wp_unslash( $_POST['csv_data'] ) ) : '';
		$raw_map    = isset( $_POST['series_map'] ) ? json_decode( wp_unslash( $_POST['series_map'] ), true ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- json_decode result sanitized key-by-key below
		$series_map = [];
		if ( is_array( $raw_map ) ) {
			foreach ( $raw_map as $k => $v ) {
				$series_map[ sanitize_text_field( $k ) ] = sanitize_text_field( $v );
			}
		}
		$dry_run    = isset( $_POST['dry_run'] ) && sanitize_text_field( wp_unslash( $_POST['dry_run'] ) ) === '1';
		$duplicate  = isset( $_POST['duplicate'] ) ? sanitize_key( wp_unslash( $_POST['duplicate'] ) ) : 'skip';

		if ( ! $csv ) wp_send_json_error( [ 'message' => 'No CSV data.' ] );

		$runner = new SeriesEngineRunner();
		$result = $runner->run_import( $csv, $series_map, $dry_run, $duplicate );
		wp_send_json_success( $result );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'seedcast-sermon-library-import' ) === false ) return;
		wp_enqueue_style(  'scsl-admin', SCSL_PLUGIN_URL . 'assets/css/admin.css', [], SCSL_VERSION );
		wp_enqueue_script( 'scsl-admin', SCSL_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], SCSL_VERSION, true );
		wp_add_inline_style(  'scsl-admin', <<<'CSS'
/* Import Steps */
.scsl-import-steps {
	display: flex;
	gap: 0;
	margin: 1.5rem 0;
	max-width: 600px;
}
.scsl-import-step {
	flex: 1;
	display: flex;
	align-items: center;
	gap: .5rem;
	padding: .65rem 1rem;
	background: #f6f7f7;
	border: 1px solid #dcdcde;
	border-right: none;
	font-size: 13px;
	color: #646970;
	font-weight: 500;
}
.scsl-import-step:last-child { border-right: 1px solid #dcdcde; border-radius: 0 4px 4px 0; }
.scsl-import-step:first-child { border-radius: 4px 0 0 4px; }
.scsl-import-step.is-active { background: #2271b1; border-color: #2271b1; color: #fff; }
.scsl-import-step.is-done   { background: #00a32a; border-color: #00a32a; color: #fff; }
.scsl-step-num {
	display: inline-flex; align-items: center; justify-content: center;
	width: 22px; height: 22px; border-radius: 50%;
	background: rgba(255,255,255,.25); font-size: 12px; font-weight: 700;
	flex-shrink: 0;
}
.scsl-import-step:not(.is-active):not(.is-done) .scsl-step-num {
	background: #dcdcde; color: #646970;
}

/* Series mapping table */
.scsl-series-map-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.scsl-series-map-table th {
	text-align: left; padding: .5rem .75rem;
	background: #f6f7f7; border-bottom: 2px solid #dcdcde;
	font-weight: 600; color: #1d2327;
}
.scsl-series-map-table td {
	padding: .6rem .75rem;
	border-bottom: 1px solid #f0f0f1;
	vertical-align: middle;
}
.scsl-series-map-table tr:last-child td { border-bottom: none; }
.scsl-series-map-table select { min-width: 220px; }
.scsl-sermon-count {
	display: inline-block; padding: .15rem .5rem;
	background: #e0e7ff; border-radius: 999px;
	font-size: 11px; font-weight: 700; color: #3730a3;
}

/* Import info box */
.scsl-import-info {
	background: #f0f6fc; border: 1px solid #c3d4e4;
	border-radius: 4px; padding: .75rem 1rem; margin: 1rem 0; font-size: 13px;
}
.scsl-import-info ul { margin: .35rem 0 0 1rem; }
.scsl-import-info li { margin-bottom: .25rem; }

/* Count badges */
.scsl-count-badge {
	display: flex; flex-direction: column; align-items: center;
	padding: .75rem 1.25rem; background: #f6f7f7;
	border: 1px solid #dcdcde; border-radius: 4px; min-width: 90px;
	text-align: center;
}
.scsl-count-badge .num { font-size: 1.75rem; font-weight: 800; color: #2271b1; line-height: 1; }
.scsl-count-badge .lbl { font-size: 11px; color: #646970; margin-top: .25rem; text-transform: uppercase; letter-spacing: .04em; }
.scsl-count-badge.is-warn .num { color: #d63638; }
.scsl-count-badge.is-ok .num   { color: #00a32a; }

/* Spinner */
.scsl-spinner {
	display: inline-block; width: 16px; height: 16px;
	border: 2px solid #dcdcde; border-top-color: #2271b1;
	border-radius: 50%; animation: scsl-spin .6s linear infinite;
	vertical-align: middle; margin-right: .35rem;
}
@keyframes scsl-spin { to { transform: rotate(360deg); } }

/* Log colors */
.scsl-log-ok    { color: #4ade80; }
.scsl-log-warn  { color: #facc15; }
.scsl-log-skip  { color: #94a3b8; }
.scsl-log-head  { color: #818cf8; font-weight: bold; }
CSS
		);
		wp_add_inline_script( 'scsl-admin', <<<'JS'
var slImportI18n = jQuery.extend( {
	noSeriesFound: 'No series found in the CSV.',
	seSeriesCol:   'Series Engine Series',
	sermonsCol:    'Sermons',
	importAs:      'Import as',
	createNew:     'Create new:',
	useExisting:   'Use existing:',
	skipSeries:    'Skip this series',
	foundInCsv:    'found in the CSV.',
	importing:     'Importing…',
	dryRunTitle:   'Step 3: Dry run results',
	importTitle:   'Step 3: Import results',
	running:       'Running…',
	requestFailed: 'Request failed.',
	runImport:     'Run import',
}, window.scslImportI18n || {} );
jQuery( function( $ ) {

	var parsedData = null;

	// ── Step navigation ─────────────────────────────────────────
	function goToStep( n ) {
for ( var i = 1; i <= 3; i++ ) {
	$( '#scsl-step-' + i ).hide();
	var $ind = $( '#step-indicator-' + i );
	$ind.removeClass( 'is-active is-done' );
	if ( i < n ) $ind.addClass( 'is-done' );
}
$( '#scsl-step-' + n ).show();
$( '#step-indicator-' + n ).addClass( 'is-active' );
	}

	// ── Step 1: File upload ──────────────────────────────────────
	var csvData = null;

	$( '#scsl_import_file' ).on( 'change', function() {
var file = this.files[0];
if ( ! file ) return;
var reader = new FileReader();
reader.onload = function( e ) {
	csvData = e.target.result;
	$( '#scsl-parse-btn' ).prop( 'disabled', false );
};
reader.readAsText( file );
	} );

	$( '#scsl-parse-btn' ).on( 'click', function() {
if ( ! csvData ) return;
$( '#scsl-parse-progress' ).show();
$( '#scsl-parse-btn' ).prop( 'disabled', true );

$.post( scslImportData.ajaxUrl, {
	action:   'scsl_import_parse',
	nonce:    scslImportData.nonce,
	csv_data: csvData,
} )
.done( function( res ) {
	if ( res.success ) {
parsedData = res.data;
buildSeriesMapping( res.data );
goToStep( 2 );
	} else {
alert( 'Parse error: ' + ( res.data.message || 'Unknown' ) );
	}
} )
.always( function() {
	$( '#scsl-parse-progress' ).hide();
	$( '#scsl-parse-btn' ).prop( 'disabled', false );
} );
	} );

	// ── Step 2: Series mapping ───────────────────────────────────
	function buildSeriesMapping( data ) {
var $wrap = $( '#scsl-series-mapping-wrap' );
$wrap.empty();

if ( ! data.series || data.series.length === 0 ) {
	$wrap.html( $( '<p>' ).text( slImportI18n.noSeriesFound ) );
	return;
}

var $table = $( '<table class="scsl-series-map-table"><thead><tr></tr></thead><tbody></tbody></table>' );
$table.find( 'thead tr' )
	.append( $( '<th>' ).text( slImportI18n.seSeriesCol ) )
	.append( $( '<th>' ).text( slImportI18n.sermonsCol ) )
	.append( $( '<th>' ).text( slImportI18n.importAs ) );

$.each( data.series, function( i, s ) {
	var count = data.series_sermon_counts[ s.id ] || 0;

	// Build dropdown
	var $select = $( '<select>' )
.attr( 'name', 'series_map[' + s.id + ']' )
.attr( 'data-se-id', s.id )
.attr( 'data-se-title', s.title );

	// Create new option
	$select.append( $( '<option>' ).val( 'new:' + s.title ).text(
slImportI18n.createNew + ' "' + s.title + '"'
	) );

	// Existing series options
	if ( scslImportData.existingSeries.length ) {
var $group = $( '<optgroup>' ).attr( 'label', slImportI18n.useExisting );
$.each( scslImportData.existingSeries, function( j, ex ) {
	$group.append( $( '<option>' ).val( 'existing:' + ex.id ).text( ex.title ) );
} );
$select.append( $group );
	}

	// Skip option
	$select.append( $( '<option>' ).val( 'skip' ).text( slImportI18n.skipSeries ) );

	var $row = $( '<tr>' )
.append( $( '<td>' ).text( s.title ) )
.append( $( '<td>' ).append( $( '<span class="scsl-sermon-count">' ).text( count + ' ' + slImportI18n.sermonsCol.toLowerCase() ) ) )
.append( $( '<td>' ).append( $select ) );

	$table.find( 'tbody' ).append( $row );
} );

$wrap.append( $table );

// Summary
$( '#scsl-parse-summary' ).html(
	'<strong>' + data.totals.messages + '</strong> sermons, ' +
	'<strong>' + data.totals.speakers + '</strong> speakers, ' +
	'<strong>' + data.totals.series + '</strong> series, ' +
	'<strong>' + data.totals.topics + '</strong> topics ' +
	slImportI18n.foundInCsv
);
	}

	$( '#scsl-back-btn' ).on( 'click', function() { goToStep( 1 ); } );
	$( '#scsl-reset-btn' ).on( 'click', function() {
parsedData = null; csvData = null;
$( '#scsl_import_file' ).val( '' );
$( '#scsl-parse-btn' ).prop( 'disabled', true );
goToStep( 1 );
	} );

	// ── Step 3: Run import ───────────────────────────────────────
	$( '#scsl-run-btn' ).on( 'click', function() {
if ( ! parsedData || ! csvData ) return;

// Collect series mapping
var seriesMapping = {};
$( '[data-se-id]' ).each( function() {
	seriesMapping[ $( this ).data( 'se-id' ) ] = $( this ).val();
} );

var dryRun    = $( '#scsl_dry_run' ).is( ':checked' ) ? '1' : '0';
var duplicate = $( '[name="scsl_duplicate"]:checked' ).val();

$( '#scsl-run-btn' ).prop( 'disabled', true ).text( slImportI18n.importing );

goToStep( 3 );
$( '#scsl-results-title' ).text( dryRun === '1'
	? slImportI18n.dryRunTitle
	: slImportI18n.importTitle
);
$( '#scsl-results-summary' ).css( { background: '#f0f6fc', border: '1px solid #c3d4e4', color: '#1d2327' } )
	.html( '<span class="scsl-spinner"></span> slImportI18n.running' );
$( '#scsl-results-counts' ).empty();
$( '#scsl-import-log' ).empty();

$.post( scslImportData.ajaxUrl, {
	action:        'scsl_import_run',
	nonce:         scslImportData.nonce,
	csv_data:      csvData,
	series_map:    JSON.stringify( seriesMapping ),
	dry_run:       dryRun,
	duplicate:     duplicate,
} )
.done( function( res ) {
	if ( res.success ) {
var d = res.data;
var isDry = dryRun === '1';

$( '#scsl-results-summary' )
	.css( { background: isDry ? '#fff3cd' : '#d1e7dd', border: isDry ? '1px solid #ffc107' : '1px solid #0f5132', color: isDry ? '#664d03' : '#0a3622' } )
	.text( d.summary );

// Count badges
var c = d.counts;
var badgesHtml = '';
var badgeDefs = [
	{ key: 'sermons',  label: 'Sermons',  cls: 'is-ok' },
	{ key: 'speakers', label: 'Speakers', cls: '' },
	{ key: 'series',   label: 'Series',   cls: '' },
	{ key: 'topics',   label: 'Topics',   cls: '' },
	{ key: 'skipped',  label: 'Skipped',  cls: c.skipped > 0 ? 'is-warn' : '' },
];
$.each( badgeDefs, function( i, b ) {
	badgesHtml += '<div class="scsl-count-badge ' + b.cls + '"><span class="num">' + ( c[ b.key ] || 0 ) + '</span><span class="lbl">' + b.label + '</span></div>';
} );
$( '#scsl-results-counts' ).html( badgesHtml );

// Colorize log
var colored = d.log
	.replace( /^(── .+)$/gm, '<span class="scsl-log-head">$1</span>' )
	.replace( /^(\[DRY RUN\].+Created.+)$/gm, '<span class="scsl-log-ok">$1</span>' )
	.replace( /^(\s*→ Created.+)$/gm, '<span class="scsl-log-ok">$1</span>' )
	.replace( /^(\s*→ Skipped.+)$/gm, '<span class="scsl-log-skip">$1</span>' )
	.replace( /^(\s*→ ERROR.+)$/gm, '<span class="scsl-log-warn">$1</span>' )
	.replace( /^(══.+══)$/gm, '<span class="scsl-log-head">$1</span>' );
$( '#scsl-import-log' ).html( colored );
	} else {
$( '#scsl-results-summary' )
	.css( { background: '#f8d7da', border: '1px solid #f5c2c7', color: '#58151c' } )
	.text( 'Error: ' + ( res.data.message || 'Unknown error' ) );
	}
} )
.fail( function() {
	$( '#scsl-results-summary' ).text( slImportI18n.requestFailed );
} )
.always( function() {
	$( '#scsl-run-btn' ).prop( 'disabled', false ).text( slImportI18n.runImport );
} );
	} );

} );
// Source switcher
( function() {
	var src = document.getElementById( 'scsl-import-source' );
	if ( ! src ) return;
	function switchSource() {
		document.querySelectorAll( '.scsl-import-source-panel' ).forEach( function( el ) {
			el.style.display = 'none';
		} );
		var panel = document.getElementById( 'scsl-source-' + src.value );
		if ( panel ) panel.style.display = '';
	}
	src.addEventListener( 'change', switchSource );
	switchSource();
	var jsonFile = document.getElementById( 'scsl_json_import_file' );
	var jsonBtn  = document.getElementById( 'scsl-json-import-btn' );
	if ( jsonFile && jsonBtn ) {
		jsonFile.addEventListener( 'change', function() { jsonBtn.disabled = ! this.files.length; } );
		jsonBtn.addEventListener( 'click', function() {
			var log = document.getElementById( 'scsl-json-import-log' );
			log.style.display = 'block';
			log.textContent = ( window.scslImportI18n && scslImportI18n.jsonComingSoon ) ? scslImportI18n.jsonComingSoon : '';
		} );
	}
} )();
JS
		);
	}
}
