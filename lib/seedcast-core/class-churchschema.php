<?php
/**
 * The church, described to search engines as a place.
 *
 * A church is three things at once and search engines want all three: an
 * organisation, a place with an address, and a church specifically. Saying only
 * one of them leaves the entity half described, which is why the type is a list
 * rather than a single word. Dropping Organization in particular would break
 * anything already pointing at this node as a publisher.
 *
 * Where an SEO plugin already publishes an organisation node, this adds to it
 * rather than publishing a second one. Two organisation nodes with different
 * identifiers on one page is a contradiction, and a search engine resolving it
 * will pick one, which may not be the one with the address in it.
 *
 * Deliberately no geo coordinates. A street address is geocoded perfectly well
 * on its own, and coordinates matter for places that have no address at all,
 * like a trailhead. Asking a church for latitude and longitude buys almost
 * nothing and is a field most would leave empty.
 *
 * Deliberately no opening hours. They mean the hours a place is open for
 * business, and a church that puts its Sunday service there is asserting
 * something untrue about the rest of the week.
 *
 * @package SeedcastCore
 */

namespace Seedcast\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

class ChurchSchema {

	/**
	 * The fragment this church is known by across the site.
	 *
	 * Fixed, and tied to the home address rather than whichever page happens
	 * to be showing, so everything referring to the church refers to the same
	 * thing.
	 */
	const FRAGMENT = '#church';

	public function init(): void {
		// Yoast and Rank Math each publish an organisation of their own. Added
		// to rather than duplicated.
		add_filter( 'wpseo_schema_organization', [ $this, 'extend_node' ], 20 );
		add_filter( 'rank_math/json_ld', [ $this, 'extend_rank_math' ], 20, 2 );

		// Nothing else is publishing one, so this does.
		add_action( 'wp_head', [ $this, 'maybe_print' ], 20 );
	}

	/**
	 * Whether there is enough here to describe a place at all.
	 *
	 * A name on its own is not a local entity, and publishing one without an
	 * address claims a presence that has not been given. Better to say nothing
	 * than to say something incomplete about where a church is.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		if ( '' === Church::name() ) return false;

		return '' !== Church::address() || Church::has_locality();
	}

	/**
	 * The church, as a search engine would want it.
	 *
	 * @return array
	 */
	public static function node(): array {
		$node = [
			'@type' => [ 'Organization', 'Church' ],
			'@id'   => home_url( '/' ) . self::FRAGMENT,
			'name'  => Church::name(),
			'url'   => home_url( '/' ),
		];

		$address = self::address();

		if ( $address ) $node['address'] = $address;

		$phone = Church::phone();

		if ( '' !== $phone ) $node['telephone'] = $phone;

		$email = Church::email();

		if ( '' !== $email ) $node['email'] = $email;

		$description = Church::description();

		if ( '' !== $description ) $node['description'] = $description;

		/*
		 * The same church, in the places it also is.
		 *
		 * These are how a search engine works out that a Facebook page, a
		 * YouTube channel and this website are one church rather than three
		 * things with similar names.
		 */
		$profiles = Church::profiles();

		if ( $profiles ) $node['sameAs'] = $profiles;

		$logo = self::logo();

		if ( '' !== $logo ) {
			$node['logo']  = $logo;
			$node['image'] = $logo;
		}

		/**
		 * The church node, before it is published.
		 *
		 * @param array $node The node.
		 */
		return (array) apply_filters( 'seedcast/schema/church', $node );
	}

	/**
	 * The address, as its own object.
	 *
	 * Left out entirely when there is nothing in it. An empty PostalAddress
	 * says a church has an address that is blank, which is worse than not
	 * mentioning one.
	 *
	 * @return array
	 */
	private static function address(): array {
		$address = array_filter( [
			'@type'           => 'PostalAddress',
			'streetAddress'   => Church::address(),
			'addressLocality' => Church::city(),
			'addressRegion'   => Church::state(),
			'postalCode'      => Church::postcode(),
			'addressCountry'  => Church::country(),
		] );

		// Only the type survived, so there is no address here.
		if ( count( $address ) < 2 ) return [];

		return $address;
	}

	/**
	 * The site logo, if the theme has one.
	 *
	 * @return string
	 */
	private static function logo(): string {
		$id = (int) get_theme_mod( 'custom_logo' );

		if ( ! $id ) return '';

		$src = wp_get_attachment_image_src( $id, 'full' );

		return is_array( $src ) && ! empty( $src[0] ) ? (string) $src[0] : '';
	}

	/**
	 * Add the church to the organisation Yoast is already publishing.
	 *
	 * Yoast's own guidance is that a local entity carries Organization, Place
	 * and its particular kind together, so its type is extended rather than
	 * replaced: a node that stopped being an Organization would break every
	 * reference already pointing at it as a publisher.
	 *
	 * @param array $node Yoast's organisation node.
	 * @return array
	 */
	public function extend_node( $node ) {
		if ( ! is_array( $node ) || ! self::ready() ) return $node;

		$node['@type'] = self::merge_types( $node['@type'] ?? 'Organization' );

		$address = self::address();

		// Not overwritten. A church that has filled Yoast's address in has
		// said where it is once already, and replacing it would quietly
		// discard the more considered answer.
		if ( $address && empty( $node['address'] ) ) {
			$node['address'] = $address;
		}

		foreach ( [ 'telephone' => Church::phone(), 'email' => Church::email(), 'description' => Church::description() ] as $key => $value ) {
			if ( '' !== $value && empty( $node[ $key ] ) ) $node[ $key ] = $value;
		}

		/*
		 * Profiles are added to, not replaced.
		 *
		 * Yoast collects social addresses of its own, and a church has often
		 * given it one already. Overwriting would drop whatever it had and
		 * replace it with whatever was typed here, which is a worse answer
		 * than both together.
		 */
		$profiles = Church::profiles();

		if ( $profiles ) {
			$existing = array_filter( array_map( 'strval', (array) ( $node['sameAs'] ?? [] ) ) );

			$node['sameAs'] = array_values( array_unique( array_merge( $existing, $profiles ) ) );
		}

		return $node;
	}

	/**
	 * The same, for Rank Math.
	 *
	 * @param array $data    The graph, keyed by piece.
	 * @param mixed $jsonld  Rank Math's builder.
	 * @return array
	 */
	public function extend_rank_math( $data, $jsonld ) {
		if ( ! is_array( $data ) || ! self::ready() ) return $data;

		foreach ( $data as $key => $piece ) {
			if ( ! is_array( $piece ) ) continue;

			$types = (array) ( $piece['@type'] ?? [] );

			if ( ! in_array( 'Organization', $types, true ) ) continue;

			$data[ $key ] = $this->extend_node( $piece );

			// One organisation is the church. A second would be somebody else.
			break;
		}

		return $data;
	}

	/**
	 * Publish the church where nothing else is publishing one.
	 *
	 * @return void
	 */
	public function maybe_print(): void {
		if ( ! self::ready() ) return;

		// Somebody else's organisation is already on the page and has been
		// added to above. A second one here would contradict it.
		if ( self::seo_plugin_active() ) return;

		/*
		 * Schema::emit() rather than a printf() of our own: it encodes with
		 * JSON_HEX_TAG, so a church name or description containing '</script>'
		 * is escaped to '</script>' and cannot close the block early.
		 * These values come from a settings screen, but "only an admin can set
		 * it" is not the same as safe, and the other JSON-LD in the library
		 * already goes out through there.
		 */
		Frontend\Schema::emit(
			[
				'@context' => 'https://schema.org',
				'@graph'   => [ self::node() ],
			]
		);
	}

	/**
	 * Whether something else is describing this site's organisation.
	 *
	 * @return bool
	 */
	private static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'SEOPress' );
	}

	/**
	 * The three types, without repeating any the node already has.
	 *
	 * @param string|array $existing What the node says it is.
	 * @return array
	 */
	private static function merge_types( $existing ): array {
		$types = array_values( array_unique( array_merge(
			array_map( 'strval', (array) $existing ),
			[ 'Organization', 'Church' ]
		) ) );

		return $types;
	}
}
