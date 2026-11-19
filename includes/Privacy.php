<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** WordPress personal-data tools: exporter, eraser and suggested privacy-policy text. */
final class Privacy {

	public const GROUP_ID  = 'bug-spotter';
	public const PER_PAGE  = 50;

	public static function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_policy_content' ) );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters[ self::GROUP_ID ] = array(
			'exporter_friendly_name' => __( 'Bug Spotter reports', 'bug-spotter' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers[ self::GROUP_ID ] = array(
			'eraser_friendly_name' => __( 'Bug Spotter reports', 'bug-spotter' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/** @return array{data: array[], done: bool} */
	public static function export( string $email, int $page = 1 ): array {
		$repo  = new Reports_Repository();
		$ids   = self::ids_for_email( $email );
		$slice = array_slice( $ids, ( max( 1, $page ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$items = array();
		$stats = Statuses::all();

		foreach ( $slice as $id ) {
			$report = $repo->find( $id );
			if ( null === $report ) {
				continue;
			}
			$data = array(
				array( 'name' => __( 'Report number', 'bug-spotter' ), 'value' => '#' . $report['id'] ),
				array( 'name' => __( 'Date', 'bug-spotter' ), 'value' => wp_date( 'Y-m-d H:i', (int) strtotime( $report['created_at'] . ' UTC' ) ) ),
				array( 'name' => __( 'Page', 'bug-spotter' ), 'value' => $report['page_url'] ),
				array( 'name' => __( 'Description', 'bug-spotter' ), 'value' => $report['description'] ),
				array( 'name' => __( 'Status', 'bug-spotter' ), 'value' => $stats[ $report['status'] ] ?? $report['status'] ),
			);
			if ( is_string( $report['reporter_email'] ) && '' !== $report['reporter_email'] ) {
				$data[] = array( 'name' => __( 'Contact e-mail', 'bug-spotter' ), 'value' => $report['reporter_email'] );
			}
			$data[] = array(
				'name'  => __( 'Screenshot attached', 'bug-spotter' ),
				'value' => ! empty( $report['screenshot'] ) ? __( 'Yes', 'bug-spotter' ) : __( 'No', 'bug-spotter' ),
			);

			$items[] = array(
				'group_id'          => self::GROUP_ID,
				'group_label'       => __( 'Bug reports', 'bug-spotter' ),
				'group_description' => __( 'Bug reports submitted from this site.', 'bug-spotter' ),
				'item_id'           => 'bug-report-' . $report['id'],
				'data'              => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => ( max( 1, $page ) * self::PER_PAGE ) >= count( $ids ),
		);
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page ); // Deleted rows leave the lookup, so every pass simply takes the next batch.
		$repo    = new Reports_Repository();
		$ids     = self::ids_for_email( $email );
		$removed = 0;
		$failed  = 0;

		foreach ( array_slice( $ids, 0, self::PER_PAGE ) as $id ) {
			if ( $repo->delete( $id ) ) {
				++$removed;
			} else {
				++$failed;
			}
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $failed > 0,
			'messages'       => $failed > 0 ? array( __( 'Some bug reports could not be deleted.', 'bug-spotter' ) ) : array(),
			// No progress means the same batch would come back forever, so stop rather than loop.
			'done'           => count( $ids ) <= self::PER_PAGE || 0 === $removed,
		);
	}

	/** Reports left with this contact e-mail plus those of the WP account owning it, sorted by id. @return int[] */
	private static function ids_for_email( string $email ): array {
		$repo = new Reports_Repository();
		$ids  = $repo->ids_by_email( $email );
		$user = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$ids = array_merge( $ids, $repo->ids_by_user( (int) $user->ID ) );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	public static function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$paragraphs = array(
			__( 'Purpose: this site lets visitors and users report bugs they meet while browsing. Reports are used only to find and fix those problems.', 'bug-spotter' ),
			__( 'Data collected: the description you write, the address of the page, technical context (browser, screen size, language, errors and failed requests seen by the page), an optional screenshot, an optional contact e-mail address (stored encrypted), your account if you are logged in, and a keyed hash of your IP address used only against abuse. Your IP address itself is never stored. Values typed into the site\'s own forms and fields (including passwords) are never collected; only the description and optional contact e-mail typed into the bug report form are stored.', 'bug-spotter' ),
			__( 'Anti-bot check: when an anti-bot provider is enabled (Cloudflare Turnstile, Google reCAPTCHA or hCaptcha), that provider receives your IP address and browser data when you send a report, under its own privacy policy.', 'bug-spotter' ),
			__( 'Retention: closed reports are deleted automatically after the retention period set by the site administrators; otherwise reports are kept until they are deleted.', 'bug-spotter' ),
			__( 'Legal basis: legitimate interest in maintaining and improving the site.', 'bug-spotter' ),
			__( 'Recipients: only the site administrators and the people they authorise to manage bug reports.', 'bug-spotter' ),
		);
		wp_add_privacy_policy_content( 'Bug Spotter', wp_kses_post( wpautop( implode( "\n\n", $paragraphs ) ) ) );
	}
}
