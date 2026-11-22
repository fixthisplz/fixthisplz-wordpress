<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

/** How a stored report is presented, the same way on every screen, in the e-mail and in the exports. */
final class Report_Format {

	/**
	 * Who reported: a WP user (name + profile link) or a visitor (+ decrypted contact e-mail when given).
	 *
	 * @return array{name:string,url:string,email:string,is_user:bool}
	 */
	public static function reporter( array $report ): array {
		$email = is_string( $report['reporter_email'] ?? null ) ? $report['reporter_email'] : '';
		$uid   = (int) ( $report['user_id'] ?? 0 );
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				return array( 'name' => $user->display_name, 'url' => (string) get_edit_user_link( $uid ), 'email' => '', 'is_user' => true );
			}
		}
		return array( 'name' => __( 'Visitor', 'bug-spotter' ), 'url' => '', 'email' => $email, 'is_user' => false );
	}

	/** One-line reporter: WP display name, or "Visitor (e-mail)" when a contact e-mail was given. */
	public static function reporter_label( array $report ): string {
		$who = self::reporter( $report );
		return '' !== $who['email'] ? $who['name'] . ' (' . $who['email'] . ')' : $who['name'];
	}

	/** The first $length characters of $text, with "…" when it was longer. */
	public static function excerpt( string $text, int $length ): string {
		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) . '…' : $text;
	}

	/**
	 * A status or priority pill tinted from the choice color (see .bugspot-pill in admin.css).
	 *
	 * @param class-string<Choice_List> $list Statuses::class or Priorities::class.
	 */
	public static function pill( string $list, string $key ): string {
		return sprintf(
			'<span class="bugspot-pill bugspot-%1$s" style="--bugspot-color:%2$s">%3$s</span>',
			esc_attr( $list::FIELD ),
			esc_attr( $list::color( $key ) ),
			esc_html( $list::label( $key ) )
		);
	}

	/** "{singular name} #12" (Management tab), raw: each output escapes it its own way. */
	public static function title( int $id ): string {
		/* translators: 1: singular name of a report (default "Bug"), 2: report number. */
		return sprintf( __( '%1$s #%2$d', 'bug-spotter' ), Settings::text( 'management.item_label' ), $id );
	}

	/** A stored UTC datetime in the site's date and time formats and timezone; '' when absent or invalid. */
	public static function date( mixed $utc ): string {
		$time = Reports_Repository::timestamp( $utc );
		return null !== $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}
}
