<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** Notification e-mails, HTML with a plain-text alternative: a new report, and a report assigned to someone. */
final class Mailer {

	private const EXCERPT_LENGTH = 300;

	/** Per assignee, the reports assigned to them while batch_assignments() runs; null outside of it. @var array<int,int[]>|null */
	private static ?array $batch = null;

	public static function register_hooks(): void {
		add_action( 'bugspot_report_created', array( self::class, 'notify' ) );
		add_action( 'bugspot_report_assigned', array( self::class, 'on_assigned' ), 10, 3 );
	}

	public static function notify( int $report_id ): bool {
		$notifications = Settings::get()['notifications'];
		if ( empty( $notifications['enabled'] ) ) {
			return false;
		}
		$report = ( new Reports_Repository() )->find( $report_id );
		if ( null === $report ) {
			return false;
		}

		$recipients = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $notifications['recipients'] ?? '' ) ) ), 'is_email' ) );
		if ( ! $recipients ) {
			$recipients = array( (string) get_option( 'admin_email' ) );
		}

		/* translators: 1: site name, 2: report title, such as "Bug #12". */
		$subject = sprintf( __( '[%1$s] New report: %2$s', 'bug-spotter' ), Report_Format::site_name(), Report_Format::title( $report_id ) );

		$attachments = array();
		if ( ! empty( $notifications['attach_screenshot'] ) && is_string( $report['screenshot'] ?? null ) ) {
			$path = Screenshot_Store::path( $report['screenshot'] );
			if ( null !== $path && is_file( $path ) ) {
				$attachments[] = $path;
			}
		}
		$values = self::values( $report_id, $report );
		return self::send( $recipients, $subject, self::build_html( $values ), self::build_text( $values ), $attachments );
	}

	/** `bugspot_report_assigned` (Reports_Repository::update()): tells the new assignee, never the person who did it. */
	public static function on_assigned( int $report_id, int $assignee, int $by ): void {
		if ( $assignee === $by ) {
			return;
		}
		if ( null !== self::$batch ) {
			self::$batch[ $assignee ][] = $report_id;
			return;
		}
		self::notify_assignee( $assignee, array( $report_id ) );
	}

	/** Runs $fn (a bulk action); each person assigned reports during it then gets one e-mail for all of them. */
	public static function batch_assignments( callable $fn ): void {
		self::$batch = array();
		try {
			$fn();
			$batch = self::$batch;
		} finally {
			self::$batch = null;
		}
		foreach ( $batch as $assignee => $report_ids ) {
			self::notify_assignee( $assignee, $report_ids );
		}
	}

	/** One report: the full e-mail; several: their titles and links. @param int[] $report_ids */
	private static function notify_assignee( int $user_id, array $report_ids ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) || ! $report_ids ) {
			return false;
		}
		if ( 1 === count( $report_ids ) ) {
			$report = ( new Reports_Repository() )->find( $report_ids[0] );
			if ( null === $report ) {
				return false;
			}
			/* translators: 1: site name, 2: report title, such as "Bug #12". */
			$subject = sprintf( __( '[%1$s] Assigned to you: %2$s', 'bug-spotter' ), Report_Format::site_name(), Report_Format::title( $report_ids[0] ) );
			$values  = self::values( $report_ids[0], $report );
			return self::send( array( $user->user_email ), $subject, self::build_html( $values ), self::build_text( $values ), array() );
		}

		$count = count( $report_ids );
		/* translators: 1: site name, 2: number of reports. */
		$subject = sprintf( _n( '[%1$s] %2$d report assigned to you', '[%1$s] %2$d reports assigned to you', $count, 'bug-spotter' ), Report_Format::site_name(), $count );
		$intro   = __( 'These reports are now assigned to you:', 'bug-spotter' );
		$html    = '<p>' . esc_html( $intro ) . '</p><ul>';
		$text    = $intro . "\n";
		foreach ( $report_ids as $id ) {
			$html .= '<li><a href="' . esc_url( Report_Format::link( $id ) ) . '">' . esc_html( Report_Format::title( $id ) ) . '</a></li>';
			$text .= '- ' . Report_Format::title( $id ) . ': ' . Report_Format::link( $id ) . "\n";
		}
		return self::send( array( $user->user_email ), $subject, $html . '</ul>', $text, array() );
	}

	/**
	 * @param string[] $recipients
	 * @param string   $html        Body, escaped.
	 * @param string   $text        Plain-text alternative.
	 * @param string[] $attachments
	 */
	private static function send( array $recipients, string $subject, string $html, string $text, array $attachments ): bool {
		$alt = static function ( $phpmailer ) use ( $text ): void {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		};
		add_action( 'phpmailer_init', $alt );
		try {
			return (bool) wp_mail( $recipients, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ), $attachments );
		} finally {
			remove_action( 'phpmailer_init', $alt );
		}
	}

	/**
	 * The values shown in the e-mail, raw (each format escapes its own way).
	 *
	 * @param array<string,mixed> $report
	 * @return array{number:string,description:string,page:string,reporter:string,screenshot:string,link:string}
	 */
	private static function values( int $report_id, array $report ): array {
		return array(
			'number'      => Report_Format::title( $report_id ),
			'description' => Report_Format::excerpt( (string) $report['description'], self::EXCERPT_LENGTH ),
			'page'        => (string) $report['page_url'],
			'reporter'    => Report_Format::reporter_label( $report ),
			'screenshot'  => ! empty( $report['screenshot'] ) ? Screenshot_Store::capture_label( $report['context'] ) : '',
			'link'        => Report_Format::link( $report_id ),
		);
	}

	/** @return array<string,string> */
	private static function labels(): array {
		return array(
			'number'      => __( 'Report', 'bug-spotter' ),
			'description' => __( 'Description', 'bug-spotter' ),
			'page'        => __( 'Page', 'bug-spotter' ),
			'reporter'    => __( 'Reported by', 'bug-spotter' ),
			'screenshot'  => __( 'Screenshot', 'bug-spotter' ),
			'link'        => __( 'Open the report', 'bug-spotter' ),
		);
	}

	/** Plain-text alternative body. @param array<string,string> $v */
	private static function build_text( array $v ): string {
		$l = self::labels();
		return $l['number'] . ': ' . $v['number'] . "\n"
			. $l['description'] . ': ' . $v['description'] . "\n"
			. $l['page'] . ': ' . $v['page'] . "\n"
			. $l['reporter'] . ': ' . $v['reporter'] . "\n"
			. ( '' !== $v['screenshot'] ? $l['screenshot'] . ': ' . $v['screenshot'] . "\n" : '' )
			. $l['link'] . ': ' . $v['link'] . "\n";
	}

	/** HTML body. @param array<string,string> $v */
	private static function build_html( array $v ): string {
		$l = self::labels();
		return '<p><strong>' . esc_html( $l['number'] ) . ':</strong> ' . esc_html( $v['number'] ) . '</p>'
			. '<p><strong>' . esc_html( $l['description'] ) . ':</strong><br>' . nl2br( esc_html( $v['description'] ) ) . '</p>'
			. '<p><strong>' . esc_html( $l['page'] ) . ':</strong> <a href="' . esc_url( $v['page'] ) . '">' . esc_html( $v['page'] ) . '</a></p>'
			. '<p><strong>' . esc_html( $l['reporter'] ) . ':</strong> ' . esc_html( $v['reporter'] ) . '</p>'
			. ( '' !== $v['screenshot'] ? '<p><strong>' . esc_html( $l['screenshot'] ) . ':</strong> ' . esc_html( $v['screenshot'] ) . '</p>' : '' )
			. '<p><a href="' . esc_url( $v['link'] ) . '">' . esc_html( $l['link'] ) . '</a></p>';
	}
}
