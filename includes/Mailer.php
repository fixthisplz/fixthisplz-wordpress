<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** New-bug notification e-mail (HTML with a plain-text alternative). */
final class Mailer {

	private const EXCERPT_LENGTH = 300;

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

		$blogname = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		/* translators: 1: site name, 2: report number */
		$subject = sprintf( __( '[%1$s] New bug #%2$d', 'bug-spotter' ), $blogname, $report_id );

		$values = self::values( $report_id, $report );
		$text   = self::build_text( $values );
		$html   = self::build_html( $values );

		$attachments = array();
		if ( ! empty( $notifications['attach_screenshot'] ) && is_string( $report['screenshot'] ?? null ) ) {
			$path = Screenshot_Store::path( $report['screenshot'] );
			if ( null !== $path && is_file( $path ) ) {
				$attachments[] = $path;
			}
		}

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
			'number'      => '#' . $report_id,
			'description' => Report_Format::excerpt( (string) $report['description'], self::EXCERPT_LENGTH ),
			'page'        => (string) $report['page_url'],
			'reporter'    => Report_Format::reporter_label( $report ),
			'screenshot'  => ! empty( $report['screenshot'] ) ? Screenshot_Store::capture_label( $report['context'] ) : '',
			'link'        => admin_url( 'admin.php?page=bug-spotter&report=' . $report_id ),
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
