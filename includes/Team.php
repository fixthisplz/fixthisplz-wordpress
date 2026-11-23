<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/** The people who manage bugs (capability fixthisplz_manage): who a report can be assigned to, and how a user is named. */
final class Team {

	public static function register_hooks(): void {
		add_action( 'deleted_user', array( self::class, 'on_user_deleted' ) );
		// Multisite: WordPress switches to the site before this hook, so the site's own reports are unassigned.
		add_action( 'remove_user_from_blog', array( self::class, 'on_user_deleted' ) );
	}

	/** @return array<int,string> user ID => display name, sorted by name. */
	public static function members(): array {
		$users = get_users(
			array(
				'capability' => Installer::CAPABILITY,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		$members = array();
		foreach ( $users as $user ) {
			$members[ (int) $user->ID ] = (string) $user->display_name;
		}
		return $members;
	}

	public static function is_member( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, Installer::CAPABILITY );
	}

	/** Display name of a user, "Deleted user" when the account no longer exists. */
	public static function name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? (string) $user->display_name : __( 'Deleted user', 'fixthisplz' );
	}

	/** Up to two initials of a name ("Ada Lovelace" => "AL"). */
	public static function initials( string $name ): string {
		$words = preg_split( '/[\s\-_.]+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? array_slice( $words, 0, 2 ) : array();
		return mb_strtoupper( implode( '', array_map( static fn( string $w ): string => mb_substr( $w, 0, 1 ), $words ) ) );
	}

	/** The reports assigned to an account deleted (or removed from the site) become unassigned; its notes stay. */
	public static function on_user_deleted( int $user_id ): void {
		( new Reports_Repository() )->unassign_user( $user_id );
	}
}
