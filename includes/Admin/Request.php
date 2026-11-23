<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Installer;

defined( 'ABSPATH' ) || exit;

/** Shared checks of the plugin's admin requests. */
final class Request {

	/** Stops with a 403 unless the current user manages bugs. */
	public static function require_manager(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			self::deny();
		}
	}

	public static function deny(): void {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fixthisplz' ), 403 );
	}

	/** A posted ID (0 when missing); the caller verifies the nonce for that ID right after. */
	public static function posted_id( string $key ): int {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right after, by id.
	}
}
