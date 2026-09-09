<?php
namespace SeedcastSermonLibrary;

if ( ! defined( 'ABSPATH' ) ) exit;

class Autoloader {

	public static function register(): void {
		spl_autoload_register( [ new self(), 'load' ] );
	}

	/**
	 * Maps class base names to their actual filenames where the name
	 * cannot be derived mechanically (e.g. acronyms, compound words).
	 *
	 * @var array<string, string>
	 */
	private const FILE_MAP = [
		'MetaBoxes'             => 'class-metaboxes.php',
		'AdminColumns'          => 'class-admincolumns.php',
		'SettingsPage'          => 'class-settingspage.php',
		'ShortcodeGenerator'    => 'class-shortcodegenerator.php',
		'TopicsManager'         => 'class-topicsmanager.php',
		'SeriesEngineImporter'  => 'class-seriesengineimporter.php',
		'SermonManagerImporter' => 'class-sermonmanagerimporter.php',
		'ManifestImporter'      => 'class-manifestimporter.php',
		'FieldMap'              => 'class-fieldmap.php',
		'ProBridge'             => 'class-probridge.php',
		'PodcastScreen'         => 'class-podcastscreen.php',
		'AudioReclaim'          => 'class-audioreclaim.php',
		'ContentList'           => 'class-contentlist.php',
		'Revisions'             => 'class-revisions.php',
		'JsonImporter'          => 'class-jsonimporter.php',
		'Exporter'              => 'class-exporter.php',
		'PDFGenerator'          => 'class-pdfgenerator.php',
		'TemplateLoader'        => 'class-templateloader.php',
		'ViewCounter'           => 'class-viewcounter.php',
		'ImageFallback'         => 'class-imagefallback.php',
		'PodcastFeed'           => 'class-podcastfeed.php',
		'BulletinLibraryBridge' => 'class-bulletinlibrarybridge.php',
		'ScriptureParser'       => 'class-scriptureparser.php',
		'CardPassages'          => 'class-cardpassages.php',
		'ListLoader'            => 'class-listloader.php',
		'LegacyRedirects'       => 'class-legacyredirects.php',
		'LlmsIndex'             => 'class-llmsindex.php',
		'Faq'                   => 'class-faq.php',
		'ScriptureSummaries'    => 'class-scripturesummaries.php',
	];

	public function load( string $class ): void {
		// Only handle our namespace
		if ( strpos( $class, 'SeedcastSermonLibrary\\' ) !== 0 ) return;

		$relative = substr( $class, strlen( 'SeedcastSermonLibrary\\' ) );
		$parts    = explode( '\\', $relative );

		// Build the directory path from namespace segments (excluding class name)
		$dir  = SCSL_PLUGIN_DIR . 'includes/';
		if ( count( $parts ) > 1 ) {
			$dir .= implode( '/', array_slice( $parts, 0, -1 ) ) . '/';
		}

		$class_name = end( $parts );

		// Use explicit map for legacy filenames, otherwise derive via PascalCase → kebab-case
		if ( isset( self::FILE_MAP[ $class_name ] ) ) {
			$filename = self::FILE_MAP[ $class_name ];
		} else {
			$filename = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $class_name ) ) . '.php';
		}

		$file = $dir . $filename;

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
