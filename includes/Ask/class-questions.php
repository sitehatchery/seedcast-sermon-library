<?php
namespace SeedcastSermonLibrary\Ask;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the public asked, kept on the church's own database.
 *
 * Questions live in a table of their own rather than in the counters, because
 * they are free text from the public and the counters must stay free of
 * anything that could identify anybody.
 *
 * WHO ASKED IS A SALTED HASH, NEVER AN ADDRESS. The hash answers both things a
 * church actually wants to know, "is this the same person asking forty times"
 * and "stop them", without keeping an identifier. The salt is per site and
 * rotates monthly, with the previous one kept so a block survives a rotation.
 * The raw address is written down in one place only: on a block, the next time
 * the blocked visitor asks, which is the one case with a reason to keep it.
 *
 * Retention: ninety days for anything nobody has dealt with, then gone. A
 * promoted question is content by then and lives on the page it went to.
 */
class Questions {

	public const SCHEMA_VERSION = '1';
	public const SCHEMA_OPTION  = 'scsl_questions_schema';
	public const SALT_OPTION    = 'scsl_ask_salt';
	public const BLOCKS_OPTION  = 'scsl_ask_blocks';
	public const CRON_HOOK      = 'scsl_questions_prune';

	public const STATUS_NEW      = 'new';
	public const STATUS_PROMOTED = 'promoted';
	public const STATUS_IGNORED  = 'ignored';
	public const STATUS_BLOCKED  = 'blocked';

	/** Days an undealt-with question is kept. */
	public const KEEP_DAYS = 90;

	/** Days before the visitor salt rotates. */
	private const SALT_DAYS = 30;

	/**
	 * Words that do not make one question different from another.
	 */
	private const STOPWORDS = [
		'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'for', 'from',
		'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'of', 'on', 'or', 'our', 'please', 'say',
		'says', 'so', 'tell', 'that', 'the', 'their', 'there', 'this', 'to', 'us', 'was', 'we', 'what', 'whats',
		'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your', 'about', 'any', 'have',
		'has', 'should', 'church', 'know', 'want',
	];

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'prune' ] );
		add_action( 'init', [ __CLASS__, 'schedule' ], 20 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scsl_questions';
	}

	/**
	 * Create or update the table. Created on first use, so a site that never
	 * offers the assistant never carries an empty table.
	 */
	public static function ensure_table( bool $force = false ): void {
		if ( ! $force && get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			question    VARCHAR(300)    NOT NULL,
			group_key   CHAR(40)        NOT NULL,
			from_type   VARCHAR(16)     NOT NULL DEFAULT '',
			from_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			from_url    VARCHAR(500)    NOT NULL DEFAULT '',
			answer      TEXT            NULL,
			cited       LONGTEXT        NULL,
			outcome     VARCHAR(16)     NOT NULL DEFAULT '',
			status      VARCHAR(16)     NOT NULL DEFAULT 'new',
			visitor     CHAR(64)        NOT NULL DEFAULT '',
			promoted_to VARCHAR(191)    NOT NULL DEFAULT '',
			asked_at    DATETIME        NOT NULL,
			handled_at  DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY group_status (group_key, status),
			KEY status_asked (status, asked_at),
			KEY visitor (visitor)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Record one question and what it got.
	 *
	 * A question in a group the church has already ignored is recorded as
	 * ignored, so the decision sticks across later repeats and the church is
	 * not asked twice about something it has decided about.
	 *
	 * @param string $question The question as asked.
	 * @param array  $from     { type, id, url } of the page it was asked from.
	 * @param array  $result   The answer: status, answer, cited.
	 * @param string $visitor  Visitor hash.
	 * @return int Row id, or 0.
	 */
	public static function record( string $question, array $from, array $result, string $visitor ): int {
		self::ensure_table();

		global $wpdb;
		$group = self::group_key( $question );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ignored = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE group_key = %s AND status = %s', $group, self::STATUS_IGNORED ) );

		$cited = [];

		foreach ( (array) ( $result['cited'] ?? [] ) as $one ) {
			if ( is_array( $one ) ) {
				$cited[] = [
					'ref'    => (string) ( $one['ref'] ?? '' ),
					'type'   => (string) ( $one['type'] ?? '' ),
					'title'  => (string) ( $one['title'] ?? '' ),
					'url'    => (string) ( $one['url'] ?? '' ),
					'reason' => (string) ( $one['reason'] ?? '' ),
				];
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			self::table(),
			[
				'question'  => mb_substr( $question, 0, 300 ),
				'group_key' => $group,
				'from_type' => substr( sanitize_key( (string) ( $from['type'] ?? '' ) ), 0, 16 ),
				'from_id'   => absint( $from['id'] ?? 0 ),
				'from_url'  => substr( esc_url_raw( (string) ( $from['url'] ?? '' ) ), 0, 500 ),
				'answer'    => (string) ( $result['answer'] ?? '' ),
				'cited'     => wp_json_encode( $cited ),
				'outcome'   => sanitize_key( (string) ( $result['status'] ?? '' ) ),
				'status'    => $ignored ? self::STATUS_IGNORED : self::STATUS_NEW,
				'visitor'   => $visitor,
				'asked_at'  => current_time( 'mysql' ),
			]
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The grouping key: forty phrasings of the same question are one row.
	 *
	 * Lowercased, punctuation and filler words dropped, a crude stem, and the
	 * words sorted, so "What time is church on Sunday?" and "sunday service
	 * times" land together. Crude on purpose: it only decides what is shown
	 * as one row, and a church can still see every phrasing inside it.
	 */
	public static function group_key( string $question ): string {
		$words = self::words( $question );

		if ( ! $words ) {
			$words = [ mb_strtolower( trim( $question ) ) ];
		}

		$words = array_unique( $words );
		sort( $words );

		return sha1( implode( ' ', $words ) );
	}

	/**
	 * The words of a question that carry meaning, lightly stemmed.
	 *
	 * @return string[]
	 */
	public static function words( string $text ): array {
		$text  = mb_strtolower( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$text  = (string) preg_replace( "/[^\\p{L}\\p{N}\\s]+/u", ' ', str_replace( [ "'", '’' ], '', $text ) );
		$words = preg_split( '/\s+/u', trim( $text ) );
		$out   = [];

		foreach ( (array) $words as $word ) {
			// Numbers stay whatever their length: "Romans 8" and "Romans 12"
			// are different questions.
			if ( '' === $word || ( mb_strlen( $word ) < 2 && ! ctype_digit( $word ) ) || in_array( $word, self::STOPWORDS, true ) ) {
				continue;
			}

			$out[] = self::stem( $word );
		}

		return $out;
	}

	/**
	 * Enough stemming that "times" finds "time" and "forgiving" finds
	 * "forgive", and no more.
	 */
	public static function stem( string $word ): string {
		if ( mb_strlen( $word ) <= 3 ) {
			return $word;
		}

		foreach ( [ 'ings' => '', 'ing' => '', 'ness' => '', 'ies' => 'y', 'es' => '', 's' => '', 'ed' => '' ] as $suffix => $swap ) {
			$len = strlen( $suffix );

			if ( substr( $word, -$len ) === $suffix && mb_strlen( $word ) - $len >= 3 ) {
				$word = substr( $word, 0, -$len ) . $swap;
				break;
			}
		}

		// The same either way, so "time" and "times" meet at "tim".
		return mb_strlen( $word ) > 3 ? rtrim( $word, 'e' ) : $word;
	}

	/* ---------------------------------------------------------------------
	 * Who asked
	 * ------------------------------------------------------------------ */

	/**
	 * The visitor's hash under the current salt.
	 */
	public static function visitor(): string {
		return self::hash_with( self::address(), self::salts()['current'] );
	}

	/**
	 * Every hash this visitor could have been recorded under: the current
	 * salt and the one before it.
	 *
	 * @return string[]
	 */
	public static function visitor_hashes(): array {
		$salts   = self::salts();
		$address = self::address();
		$out     = [ self::hash_with( $address, $salts['current'] ) ];

		if ( '' !== $salts['previous'] ) {
			$out[] = self::hash_with( $address, $salts['previous'] );
		}

		return $out;
	}

	private static function hash_with( string $address, string $salt ): string {
		return hash_hmac( 'sha256', $address, $salt );
	}

	/**
	 * The requesting address. Read here and passed nowhere: it goes into the
	 * hash and, only for a blocked visitor, onto the block.
	 */
	private static function address(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * The current and previous salts, rotating the current one when it is a
	 * month old.
	 *
	 * @return array{current:string, previous:string, since:int}
	 */
	private static function salts(): array {
		$salts = get_option( self::SALT_OPTION );

		if ( ! is_array( $salts ) || empty( $salts['current'] ) ) {
			$salts = [
				'current'  => wp_generate_password( 64, true, true ),
				'previous' => '',
				'since'    => time(),
			];
			update_option( self::SALT_OPTION, $salts, false );
		} elseif ( time() - (int) ( $salts['since'] ?? 0 ) > self::SALT_DAYS * DAY_IN_SECONDS ) {
			$salts = [
				'current'  => wp_generate_password( 64, true, true ),
				'previous' => (string) $salts['current'],
				'since'    => time(),
			];
			update_option( self::SALT_OPTION, $salts, false );
		}

		return [
			'current'  => (string) $salts['current'],
			'previous' => (string) ( $salts['previous'] ?? '' ),
			'since'    => (int) ( $salts['since'] ?? 0 ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Blocking
	 * ------------------------------------------------------------------ */

	/**
	 * Every block, keyed by an id of its own.
	 *
	 * @return array<string, array{hash:string, address:string, question:string, at:string}>
	 */
	public static function blocks(): array {
		$blocks = get_option( self::BLOCKS_OPTION, [] );
		return is_array( $blocks ) ? $blocks : [];
	}

	/**
	 * Whether the current visitor is blocked.
	 *
	 * A block is found by the hash it was made from, under either salt, or by
	 * the address it captured. Found by hash, it captures the address now, so
	 * it still holds after the salt has rotated twice.
	 */
	public static function is_blocked(): bool {
		$blocks = self::blocks();

		if ( ! $blocks ) {
			return false;
		}

		$hashes  = self::visitor_hashes();
		$address = self::address();

		foreach ( $blocks as $id => $block ) {
			$by_address = '' !== $address && '' !== ( $block['address'] ?? '' ) && hash_equals( (string) $block['address'], $address );
			$by_hash    = in_array( (string) ( $block['hash'] ?? '' ), $hashes, true );

			if ( $by_hash && '' === ( $block['address'] ?? '' ) && '' !== $address ) {
				$blocks[ $id ]['address'] = $address;
				update_option( self::BLOCKS_OPTION, $blocks, false );
			}

			if ( $by_address || $by_hash ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Block whoever asked one question.
	 */
	public static function block( int $id ): bool {
		$row = self::get( $id );

		if ( ! $row || '' === $row['visitor'] ) {
			return false;
		}

		$blocks = self::blocks();

		foreach ( $blocks as $block ) {
			if ( ( $block['hash'] ?? '' ) === $row['visitor'] ) {
				return true;
			}
		}

		$blocks[ wp_generate_password( 12, false ) ] = [
			'hash'     => $row['visitor'],
			'address'  => '',
			'question' => $row['question'],
			'at'       => current_time( 'mysql' ),
		];
		update_option( self::BLOCKS_OPTION, $blocks, false );

		self::set_status_where( 'visitor', $row['visitor'], self::STATUS_BLOCKED );

		return true;
	}

	/**
	 * Lift a block. Their questions go back on the list.
	 */
	public static function unblock( string $block_id ): void {
		$blocks = self::blocks();

		if ( ! isset( $blocks[ $block_id ] ) ) {
			return;
		}

		$hash = (string) ( $blocks[ $block_id ]['hash'] ?? '' );
		unset( $blocks[ $block_id ] );
		update_option( self::BLOCKS_OPTION, $blocks, false );

		if ( '' !== $hash ) {
			self::set_status_where( 'visitor', $hash, self::STATUS_NEW, self::STATUS_BLOCKED );
		}
	}

	/* ---------------------------------------------------------------------
	 * Dispositions
	 * ------------------------------------------------------------------ */

	public static function ignore_group( string $group ): void {
		self::set_status_where( 'group_key', $group, self::STATUS_IGNORED, self::STATUS_NEW );
	}

	public static function unignore_group( string $group ): void {
		self::set_status_where( 'group_key', $group, self::STATUS_NEW, self::STATUS_IGNORED );
	}

	public static function promote_group( string $group, string $promoted_to ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			[
				'status'      => self::STATUS_PROMOTED,
				'promoted_to' => substr( $promoted_to, 0, 191 ),
				'handled_at'  => current_time( 'mysql' ),
			],
			[
				'group_key' => $group,
				'status'    => self::STATUS_NEW,
			]
		);
	}

	private static function set_status_where( string $column, string $value, string $status, string $only_from = '' ): void {
		global $wpdb;

		$where = [ $column => $value ];

		if ( '' !== $only_from ) {
			$where['status'] = $only_from;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			[
				'status'     => $status,
				'handled_at' => self::STATUS_NEW === $status ? null : current_time( 'mysql' ),
			],
			$where
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	public static function get( int $id ): ?array {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			return null;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Questions grouped, in the order a church should read them: asked more
	 * than once first, then the ones that found nothing, then the rest, most
	 * recent first within each.
	 *
	 * @param string $status Which disposition to list.
	 * @return array<int, array{key:string, count:int, visitors:int, latest:array, rows:array, nothing:bool}>
	 */
	public static function groups( string $status = self::STATUS_NEW, int $limit = 500 ): array {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			return [];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY asked_at DESC LIMIT %d', $status, $limit ), ARRAY_A );

		$groups = [];

		foreach ( (array) $rows as $row ) {
			$row = self::shape( $row );
			$key = $row['group_key'];

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'key'      => $key,
					'count'    => 0,
					'visitors' => [],
					'latest'   => $row,
					'rows'     => [],
					'nothing'  => false,
				];
			}

			$groups[ $key ]['count']++;
			$groups[ $key ]['rows'][]                    = $row;
			$groups[ $key ]['visitors'][ $row['visitor'] ] = true;

			if ( 'nothing' === $row['outcome'] ) {
				$groups[ $key ]['nothing'] = true;
			}
		}

		foreach ( $groups as $key => $group ) {
			$groups[ $key ]['visitors'] = count( $group['visitors'] );
		}

		$groups = array_values( $groups );

		usort(
			$groups,
			static function ( array $a, array $b ): int {
				$a_rank = $a['visitors'] > 1 ? 0 : ( $a['nothing'] ? 1 : 2 );
				$b_rank = $b['visitors'] > 1 ? 0 : ( $b['nothing'] ? 1 : 2 );

				if ( $a_rank !== $b_rank ) {
					return $a_rank <=> $b_rank;
				}

				if ( $a['count'] !== $b['count'] ) {
					return $b['count'] <=> $a['count'];
				}

				return strcmp( $b['latest']['asked_at'], $a['latest']['asked_at'] );
			}
		);

		return $groups;
	}

	private static function shape( array $row ): array {
		$cited = json_decode( (string) ( $row['cited'] ?? '' ), true );

		return [
			'id'          => (int) $row['id'],
			'question'    => (string) $row['question'],
			'group_key'   => (string) $row['group_key'],
			'from_type'   => (string) $row['from_type'],
			'from_id'     => (int) $row['from_id'],
			'from_url'    => (string) $row['from_url'],
			'answer'      => (string) $row['answer'],
			'cited'       => is_array( $cited ) ? $cited : [],
			'outcome'     => (string) $row['outcome'],
			'status'      => (string) $row['status'],
			'visitor'     => (string) $row['visitor'],
			'promoted_to' => (string) $row['promoted_to'],
			'asked_at'    => (string) $row['asked_at'],
		];
	}

	/**
	 * Delete what nobody dealt with in ninety days.
	 */
	public static function prune(): void {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::KEEP_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE status = %s AND asked_at < %s', self::STATUS_NEW, $cutoff ) );
	}
}
