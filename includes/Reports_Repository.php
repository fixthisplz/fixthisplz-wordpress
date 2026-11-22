<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, live data.

/** SQL access to the reports table. Dates are stored in UTC ("Y-m-d H:i:s"); every value goes through $wpdb->prepare(). */
final class Reports_Repository {

	public const MAX_PER_PAGE = 200;

	/** What list screens, exports and cards read: no `context` JSON, no hashes, no dates they never show. */
	private const LIST_COLUMNS = 'id, status, priority, description, page_url, user_id, screenshot, created_at';

	/**
	 * @param array $row Keys: description, page_url, context (array), user_id, reporter_email (plain), screenshot, ip_hash.
	 * @return int New id, or 0 when the insert fails.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$now   = self::now();
		$email = isset( $row['reporter_email'] ) ? strtolower( trim( (string) $row['reporter_email'] ) ) : '';
		$user  = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		$shot  = isset( $row['screenshot'] ) ? (string) $row['screenshot'] : '';
		$ip    = isset( $row['ip_hash'] ) ? (string) $row['ip_hash'] : '';

		$data = array(
			'status'              => 'new',
			'priority'            => 'normal',
			'description'         => (string) ( $row['description'] ?? '' ),
			'page_url'            => (string) ( $row['page_url'] ?? '' ),
			'context'             => wp_json_encode( $row['context'] ?? array() ),
			'user_id'             => $user > 0 ? $user : null,
			'reporter_email'      => '' !== $email ? Secret::encrypt( trim( (string) $row['reporter_email'] ) ) : null,
			'reporter_email_hash' => '' !== $email ? Secret::hmac( $email ) : null,
			'screenshot'          => '' !== $shot ? $shot : null,
			'ip_hash'             => '' !== $ip ? $ip : null,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		// wpdb::insert() writes NULL for null values; formats are matched by position.
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( false === $wpdb->insert( Installer::table(), $data, $formats ) ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::table(), $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Only `status` and `priority` can change; any invalid value rejects the whole update. A value unknown to the
	 * settings may be kept, never newly set.
	 *
	 * @param array|null $current The full row find() returned for $id (seen_at is read), when the caller already has it;
	 *                           never a light query() / find_many() row.
	 */
	public function update( int $id, array $fields, ?array $current = null ): bool {
		global $wpdb;

		$current ??= $this->find( $id );
		if ( null === $current ) {
			return false;
		}

		$data = array();
		if ( array_key_exists( 'status', $fields ) ) {
			$status = (string) $fields['status'];
			if ( $status !== $current['status'] && ! Statuses::is_valid( $status ) ) {
				return false;
			}
			$data['status'] = $status;
		}
		if ( array_key_exists( 'priority', $fields ) ) {
			$priority = (string) $fields['priority'];
			if ( $priority !== $current['priority'] && ! Priorities::is_valid( $priority ) ) {
				return false;
			}
			$data['priority'] = $priority;
		}
		if ( ! $data ) {
			return false;
		}

		$now = self::now();
		if ( isset( $data['status'] ) && $data['status'] !== $current['status'] ) {
			$data['status_changed_at'] = $now;
		}
		if ( isset( $data['status'] ) && 'new' !== $data['status'] && empty( $current['seen_at'] ) ) {
			$data['seen_at'] = $now;
		}
		$data['updated_at'] = $now;

		return false !== $wpdb->update( Installer::table(), $data, array( 'id' => $id ) );
	}

	/** `new` -> `seen` only; atomic so two simultaneous opens cannot both transition. */
	public function mark_seen( int $id ): bool {
		global $wpdb;
		$now   = self::now();
		$rows  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'seen', seen_at = COALESCE(seen_at, %s), status_changed_at = %s, updated_at = %s WHERE id = %d AND status = 'new'",
				Installer::table(),
				$now,
				$now,
				$now,
				$id
			)
		);
		return is_int( $rows ) && $rows > 0;
	}

	/** Moves every report of a deleted choice $from of $list to $to. @param class-string<Choice_List> $list */
	public function reassign( string $list, string $from, string $to ): void {
		global $wpdb;
		$now  = self::now();
		$data = array( $list::FIELD => $to, 'updated_at' => $now );
		if ( Statuses::class === $list ) {
			$data['status_changed_at'] = $now;
		}
		$wpdb->update( Installer::table(), $data, array( $list::FIELD => $from ) );
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$report = $this->find( $id );
		if ( null === $report ) {
			return false;
		}
		if ( false === $wpdb->delete( Installer::table(), array( 'id' => $id ), array( '%d' ) ) ) {
			return false;
		}
		self::delete_screenshot( $report['screenshot'] );
		return true;
	}

	/**
	 * Light rows (LIST_COLUMNS, plus the decrypted reporter_email unless `with_email` is false); find() has the rest.
	 *
	 * @param array $args status, priority, from, to (Y-m-d, site timezone), search, orderby (created_at, or priority in
	 *                    the order of the settings), order (DESC|ASC), per_page (1-200, default 20), page (>= 1), with_email (default true).
	 * @return array{items: array[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$table   = Installer::table();
		$where   = Report_Query::where( $args );
		$columns = self::LIST_COLUMNS . ( false === ( $args['with_email'] ?? true ) ? '' : ', reporter_email' );

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$dir      = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$primary  = 'priority' === ( $args['orderby'] ?? '' ) ? Report_Query::priority_order( $dir ) : "created_at {$dir}";

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from prepare() fragments.
		$rows  = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- see the prepare() below.
			$wpdb->prepare(
				"SELECT {$columns} FROM %i {$where} ORDER BY {$primary}, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $columns comes from a fixed list, $primary and $where from fixed text or prepare() fragments.
				$table,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Light rows (LIST_COLUMNS + decrypted reporter_email) of the given reports, by id; missing ids are skipped.
	 *
	 * @param int[] $ids
	 * @return array[]
	 */
	public function find_many( array $ids ): array {
		global $wpdb;
		$ids = array_map( 'intval', $ids );
		if ( ! $ids ) {
			return array();
		}
		$table   = Installer::table();
		$columns = self::LIST_COLUMNS . ', reporter_email';
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM %i WHERE id IN ({$in}) ORDER BY id", array_merge( array( $table ), $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $columns is a fixed list, $in a list of %d placeholders.
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/** @return array<string,int> count_by() of the statuses. */
	public function count_by_status( array $args ): array {
		return $this->count_by( Statuses::class, $args );
	}

	/**
	 * @param class-string<Choice_List> $list
	 * @return array<string,int> One entry per key of $list in order (0 when empty), then stored keys unknown to the
	 *                           settings; the filter on that same column is ignored.
	 */
	public function count_by( string $list, array $args ): array {
		global $wpdb;
		$where = Report_Query::where( array_diff_key( $args, array( $list::FIELD => true ) ) );

		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT %i AS k, COUNT(*) AS n FROM %i {$where} GROUP BY k", $list::FIELD, Installer::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from prepare() fragments.
		$counts = array_fill_keys( array_keys( $list::all() ), 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ $row['k'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/** @return int[] */
	public function ids_by_email( string $email ): array {
		global $wpdb;
		$hash = Secret::hmac( strtolower( trim( $email ) ) );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE reporter_email_hash = %s ORDER BY id', Installer::table(), $hash ) ) );
	}

	/** @return int[] */
	public function ids_by_user( int $user_id ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d ORDER BY id', Installer::table(), $user_id ) ) );
	}

	/** Deletes closed reports whose last status change is older than $months months (and their screenshots). */
	public function purge_closed_older_than( int $months ): int {
		global $wpdb;
		$closed = Statuses::closed();
		if ( $months <= 0 || ! $closed ) {
			return 0;
		}
		$table  = Installer::table();
		$cutoff = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );
		$in     = implode( ',', array_fill( 0, count( $closed ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, screenshot FROM %i WHERE status IN ({$in}) AND status_changed_at < %s", array_merge( array( $table ), $closed, array( $cutoff ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %s placeholders, the values are passed as one array.
			ARRAY_A
		);
		$deleted = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) ) ) {
				++$deleted;
				self::delete_screenshot( $row['screenshot'] );
			}
		}
		return $deleted;
	}

	/** Unix timestamp of a stored UTC "Y-m-d H:i:s" datetime; null when absent or invalid. */
	public static function timestamp( mixed $utc ): ?int {
		if ( ! is_string( $utc ) || '' === $utc ) {
			return null;
		}
		$time = date_create_immutable( $utc . ' UTC' );
		return $time ? $time->getTimestamp() : null;
	}

	/** Types the columns that were selected; `context` and `reporter_email` are decoded only when present. */
	private static function hydrate( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['user_id'] = null !== $row['user_id'] ? (int) $row['user_id'] : null;
		if ( array_key_exists( 'context', $row ) ) {
			$context        = is_string( $row['context'] ) ? json_decode( $row['context'], true ) : null;
			$row['context'] = is_array( $context ) ? $context : array();
		}
		if ( array_key_exists( 'reporter_email', $row ) ) {
			$row['reporter_email'] = ( null !== $row['reporter_email'] && '' !== $row['reporter_email'] ) ? Secret::decrypt( $row['reporter_email'] ) : null;
		}
		return $row;
	}

	private static function delete_screenshot( mixed $name ): void {
		if ( is_string( $name ) && '' !== $name ) {
			Screenshot_Store::delete( $name );
		}
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
