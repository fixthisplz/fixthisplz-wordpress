<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** SQL access to the reports table. Dates are stored in UTC ("Y-m-d H:i:s"); every value goes through $wpdb->prepare(). */
final class Reports_Repository {

	private const MAX_PER_PAGE = 200;

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
		$table = Installer::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/** Only `status` and `priority` can change; any invalid value rejects the whole update. */
	public function update( int $id, array $fields ): bool {
		global $wpdb;

		$current = $this->find( $id );
		if ( null === $current ) {
			return false;
		}

		$data = array();
		if ( array_key_exists( 'status', $fields ) ) {
			$status = (string) $fields['status'];
			if ( ! Statuses::is_valid( $status ) ) {
				return false;
			}
			$data['status'] = $status;
		}
		if ( array_key_exists( 'priority', $fields ) ) {
			$priority = (string) $fields['priority'];
			if ( ! Statuses::is_valid_priority( $priority ) ) {
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
		$table = Installer::table();
		$now   = self::now();
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'seen', seen_at = COALESCE(seen_at, %s), status_changed_at = %s, updated_at = %s WHERE id = %d AND status = 'new'",
				$now,
				$now,
				$now,
				$id
			)
		);
		return is_int( $rows ) && $rows > 0;
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
	 * @param array $args status, priority, from, to (Y-m-d, site timezone), search, orderby (created_at|priority),
	 *                    order (DESC|ASC), per_page (1-200, default 20), page (>= 1).
	 * @return array{items: array[], total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;
		$table = Installer::table();
		$where = $this->where( $args, true );

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$dir      = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$primary  = 'priority' === ( $args['orderby'] ?? '' )
			? "FIELD(priority, 'low', 'normal', 'high', 'critical') {$dir}"
			: "created_at {$dir}";

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepare() fragments.
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY {$primary}, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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

	/** @return array<string,int> One entry per status key (0 when empty); the `status` filter is ignored. */
	public function count_by_status( array $args ): array {
		global $wpdb;
		$table = Installer::table();
		$where = $this->where( $args, false );

		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} {$where} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array_fill_keys( array_keys( Statuses::all() ), 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/** @return int[] */
	public function ids_by_email( string $email ): array {
		global $wpdb;
		$table = Installer::table();
		$hash  = Secret::hmac( strtolower( trim( $email ) ) );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE reporter_email_hash = %s ORDER BY id", $hash ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return int[] */
	public function ids_by_user( int $user_id ): array {
		global $wpdb;
		$table = Installer::table();
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d ORDER BY id", $user_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Deletes closed reports whose last status change is older than $months months (and their screenshots). */
	public function purge_closed_older_than( int $months ): int {
		global $wpdb;
		if ( $months <= 0 ) {
			return 0;
		}
		$table  = Installer::table();
		$cutoff = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );
		$in     = implode( ',', array_fill( 0, count( Statuses::CLOSED ), '%s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->prepare( "SELECT id, screenshot FROM {$table} WHERE status IN ({$in}) AND status_changed_at < %s", array_merge( Statuses::CLOSED, array( $cutoff ) ) ),
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

	/** Builds a WHERE clause (possibly empty) from prepared fragments only. */
	private function where( array $args, bool $with_status ): string {
		global $wpdb;
		$clauses = array();

		if ( $with_status && ! empty( $args['status'] ) ) {
			$clauses[] = $wpdb->prepare( 'status = %s', (string) $args['status'] );
		}
		if ( ! empty( $args['priority'] ) ) {
			$clauses[] = $wpdb->prepare( 'priority = %s', (string) $args['priority'] );
		}
		$from = self::local_day_bound( $args['from'] ?? '', '00:00:00' );
		if ( null !== $from ) {
			$clauses[] = $wpdb->prepare( 'created_at >= %s', $from );
		}
		$to = self::local_day_bound( $args['to'] ?? '', '23:59:59' );
		if ( null !== $to ) {
			$clauses[] = $wpdb->prepare( 'created_at <= %s', $to );
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = $wpdb->prepare( '(description LIKE %s OR page_url LIKE %s)', $like, $like );
		}

		return $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
	}

	/** A site-timezone "Y-m-d" day at $time, converted to a UTC datetime string; null when the date is invalid. */
	private static function local_day_bound( mixed $date, string $time ): ?string {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . $time, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}
		return $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function hydrate( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['user_id'] = null !== $row['user_id'] ? (int) $row['user_id'] : null;
		$context        = is_string( $row['context'] ?? null ) ? json_decode( $row['context'], true ) : null;
		$row['context'] = is_array( $context ) ? $context : array();
		$row['reporter_email'] = ( isset( $row['reporter_email'] ) && '' !== $row['reporter_email'] ) ? Secret::decrypt( $row['reporter_email'] ) : null;
		return $row;
	}

	private static function delete_screenshot( mixed $name ): void {
		if ( is_string( $name ) && '' !== $name && class_exists( 'Bug_Spotter\Screenshot_Store' ) ) {
			Screenshot_Store::delete( $name );
		}
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
