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

		$excerpt = mb_substr( (string) $report['description'], 0, self::EXCERPT_LENGTH );
		if ( mb_strlen( (string) $report['description'] ) > self::EXCERPT_LENGTH ) {
			$excerpt .= '…';
		}
		$reporter = self::reporter( $report );
		$link     = admin_url( 'admin.php?page=bug-spotter&report=' . $report_id );
		$page     = (string) $report['page_url'];

		$labels = array(
			'number'      => __( 'Report', 'bug-spotter' ),
			'description' => __( 'Description', 'bug-spotter' ),
			'page'        => __( 'Page', 'bug-spotter' ),
			'reporter'    => __( 'Reported by', 'bug-spotter' ),
			'link'        => __( 'Open the report', 'bug-spotter' ),
		);

		$text = $labels['number'] . ': #' . $report_id . "\n"
			. $labels['description'] . ': ' . $excerpt . "\n"
			. $labels['page'] . ': ' . $page . "\n"
			. $labels['reporter'] . ': ' . $reporter . "\n"
			. $labels['link'] . ': ' . $link . "\n";

		$html = '<p><strong>' . esc_html( $labels['number'] ) . ':</strong> #' . (int) $report_id . '</p>'
			. '<p><strong>' . esc_html( $labels['description'] ) . ':</strong><br>' . nl2br( esc_html( $excerpt ) ) . '</p>'
			. '<p><strong>' . esc_html( $labels['page'] ) . ':</strong> <a href="' . esc_url( $page ) . '">' . esc_html( $page ) . '</a></p>'
			. '<p><strong>' . esc_html( $labels['reporter'] ) . ':</strong> ' . esc_html( $reporter ) . '</p>'
			. '<p><a href="' . esc_url( $link ) . '">' . esc_html( $labels['link'] ) . '</a></p>';

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

	/** Reporter label: WP display name, or "Visitor" with the contact e-mail when one was given. */
	private static function reporter( array $report ): string {
		if ( ! empty( $report['user_id'] ) ) {
			$user = get_userdata( (int) $report['user_id'] );
			if ( $user ) {
				return $user->display_name;
			}
		}
		$label = __( 'Visitor', 'bug-spotter' );
		if ( is_string( $report['reporter_email'] ?? null ) && '' !== $report['reporter_email'] ) {
			$label .= ' (' . $report['reporter_email'] . ')';
		}
		return $label;
	}
}
