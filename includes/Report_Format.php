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

	/** "{singular name} #12" (Settings › Tracking › Team and menu), raw: each output escapes it its own way. */
	public static function title( int $id ): string {
		/* translators: 1: singular name of a report (default "Bug"), 2: report number. */
		return sprintf( __( '%1$s #%2$d', 'bug-spotter' ), Settings::text( 'management.item_label' ), $id );
	}

	/** Address of the report's detail screen in the administration, with extra query $args. */
	public static function link( int $id, array $args = array() ): string {
		return self::list_link( array_merge( array( 'report' => $id ), $args ) );
	}

	/** Address of the list of reports in the administration, with extra query $args (filters, view, notice). */
	public static function list_link( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => Admin\Menu::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** The site name as plain text (e-mail subjects, webhooks). */
	public static function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/** A stored UTC datetime in the site's date and time formats and timezone; '' when absent or invalid. */
	public static function date( mixed $utc ): string {
		$time = Reports_Repository::timestamp( $utc );
		return null !== $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}

	/** Author of an activity entry: 0 is the plugin itself ("System"), null an erased author ("Deleted user"). */
	public static function author( ?int $user_id ): string {
		return 0 === $user_id ? __( 'System', 'bug-spotter' ) : Team::name( (int) $user_id );
	}

	/** A history entry, such as "Status: New → Fixed", with the names of the settings (unknown keys shown as is). */
	public static function change( string $field, ?string $old, ?string $new ): string {
		$labels = array(
			'status'      => __( 'Status', 'bug-spotter' ),
			'priority'    => __( 'Priority', 'bug-spotter' ),
			'assigned_to' => __( 'Assigned to', 'bug-spotter' ),
		);
		/* translators: 1: changed field (Status, Priority or Assigned to), 2: old value, 3: new value. */
		return sprintf( __( '%1$s: %2$s → %3$s', 'bug-spotter' ), $labels[ $field ] ?? $field, self::change_value( $field, $old ), self::change_value( $field, $new ) );
	}

	private static function change_value( string $field, ?string $value ): string {
		if ( 'assigned_to' === $field ) {
			return null === $value ? __( 'Unassigned', 'bug-spotter' ) : Team::name( (int) $value );
		}
		return match ( $field ) {
			'status'   => Statuses::label( (string) $value ),
			'priority' => Priorities::label( (string) $value ),
			default    => (string) $value,
		};
	}
}
