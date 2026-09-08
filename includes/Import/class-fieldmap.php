<?php
/**
 * Field map.
 *
 * Where a generated field lands on a sermon, in one place.
 *
 * Two things import generated content: the manifest zip importer on the Edit
 * Sermon screen, which is part of this free plugin, and the AI Engine, which
 * pulls the same content from api.seedcast.ai as JSON. Both read this map, so
 * a zip dropped by hand and a job fetched from the API produce the same sermon
 * and cannot drift apart.
 *
 * This plugin owns the meta keys, so it owns the map. Pro receives a copy
 * through the product declaration and never hardcodes an _scsl_ key.
 *
 * @package SeedcastSermonLibrary\Import
 */

namespace SeedcastSermonLibrary\Import;

if ( ! defined( 'ABSPATH' ) ) exit;

class FieldMap {

	/**
	 * Generated field key to sermon meta key.
	 *
	 * Fields not listed are either handled specially (scripture, which becomes
	 * a passage array) or appended into the resources field, so a new field
	 * appearing upstream is ignored rather than written somewhere unexpected.
	 *
	 * @return array<string, string>
	 */
	public static function api_to_meta(): array {
		// Order matters: this is the order the fields are offered in, and the
		// transcript belongs at the end. It is the longest, the least often
		// wanted, and the one most likely to replace something.
		return [
			'summary'            => '_scsl_content_description',
			'article'            => '_scsl_article_body',
			'study_guide'        => '_scsl_bible_study',
			'cleaned_transcript' => '_scsl_transcript_clean',
		];
	}

	/**
	 * Fields stored as plain text rather than HTML.
	 *
	 * @return string[]
	 */
	public static function plain_text_meta(): array {
		return [ '_scsl_content_description', '_scsl_transcript_clean' ];
	}

	/**
	 * Fields concatenated into the resources field, each under its own heading.
	 *
	 * @return string[]
	 */
	public static function appended_fields(): array {
		return [ 'outline', 'study_notes', 'faqs', 'trivia' ];
	}

	/**
	 * Fields offering a regenerate control on the Edit Sermon screen.
	 *
	 * Only whole-field replacements. The resources field is a concatenation of
	 * four generated fields under their own headings, so rewriting one of them
	 * cannot be written back without rebuilding the whole field, and that is
	 * not something to hide behind a single button.
	 *
	 * @return array<string, string> Meta key to human label.
	 */
	public static function regenerable(): array {
		return [
			'_scsl_content_description' => __( 'Description', 'seedcast-sermon-library' ),
			'_scsl_article_body'        => __( 'Article', 'seedcast-sermon-library' ),
			'_scsl_bible_study'         => __( 'Bible Study', 'seedcast-sermon-library' ),

			/*
			 * Named for the work, not for the field.
			 *
			 * This list is what somebody is asking to have written, and what
			 * happens here is a tidying: the words are already on the sermon,
			 * verbatim, and this rewrites them into something readable. Calling
			 * it "Transcript" made it look like the choice between having a
			 * transcript and not having one, when the transcript is there
			 * either way.
			 *
			 * The field itself stays "Transcript" in sections() and on the tab,
			 * because it holds whichever version the sermon has. Only the act
			 * of cleaning is optional, and only that is named here.
			 */
			'_scsl_transcript_clean'    => __( 'Cleaned Transcript', 'seedcast-sermon-library' ),
		];
	}

	/**
	 * Every field a generation touches, labelled as the Content tabs label it.
	 *
	 * Somebody reading "Study guide" in one place and "Bible Study" in another
	 * has to work out that they are the same thing. These are the names on the
	 * tabs, because that is where the content is read and edited.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return self::regenerable() + [
			'_scsl_article_title' => __( 'Article Title', 'seedcast-sermon-library' ),
			// Renameable per site, so it is read rather than assumed.
			'_scsl_resources'     => (string) get_option( 'scsl_tab_more_label', __( 'More', 'seedcast-sermon-library' ) ),
			'post_title'          => __( 'Sermon title', 'seedcast-sermon-library' ),
		];
	}

	/**
	 * The written content sections a sermon can have.
	 *
	 * Sections, not fields: this is the list a church chooses from when saying
	 * which kinds of content it actually uses. Uploaded files are not here,
	 * because nothing writes those and nobody would call them missing.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return [
			'_scsl_content_description' => __( 'Description', 'seedcast-sermon-library' ),
			'_scsl_article_body'        => __( 'Article', 'seedcast-sermon-library' ),
			'_scsl_bible_study'         => __( 'Bible Study', 'seedcast-sermon-library' ),
			'_scsl_transcript_clean'    => __( 'Transcript', 'seedcast-sermon-library' ),
			'_scsl_resources'           => (string) get_option( 'scsl_tab_more_label', __( 'More', 'seedcast-sermon-library' ) ),
			'_scsl_other_passages'      => __( 'Scripture references', 'seedcast-sermon-library' ),
		];
	}

	/**
	 * The sections this church has said it uses.
	 *
	 * Everything, until somebody says otherwise. A site that has never opened
	 * the setting should behave exactly as it did before it existed.
	 *
	 * @return array<string, string>
	 */
	public static function in_use(): array {
		$stored = get_option( 'scsl_sections_in_use', null );

		// Never asked, so everything.
		if ( ! is_array( $stored ) ) return self::sections();

		$stored = array_map( 'strval', $stored );

		// Which sections existed when that choice was made. A section added
		// since could not have been ticked, and treating it as unwanted would
		// switch off new content nobody had been offered.
		$known = get_option( 'scsl_sections_known', null );
		$known = is_array( $known ) ? array_map( 'strval', $known ) : $stored;

		$out = [];

		foreach ( self::sections() as $key => $label ) {
			if ( in_array( $key, $stored, true ) || ! in_array( $key, $known, true ) ) {
				$out[ $key ] = $label;
			}
		}

		return $out;
	}

	/**
	 * Whether one section is in use.
	 *
	 * @param string $meta_key Section meta key.
	 * @return bool
	 */
	public static function uses( string $meta_key ): bool {
		return array_key_exists( $meta_key, self::in_use() );
	}
}
