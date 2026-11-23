<?php

namespace FixThisPlz;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the visitor IP. A proxy header is trusted only when the admin configured it.
 *
 * X-Forwarded-For: the RIGHTMOST valid entry is used. A proxy appends the address it saw to the end of the
 * list, while everything to its left is client-supplied and can be forged to dodge the per-IP rate limiter.
 * One trusted hop is assumed, so this setting must only be enabled behind a proxy that sets/appends the
 * header (and never when the site is directly reachable). CF-Connecting-IP is a single value set by Cloudflare.
 */
final class Client_Ip {

	private const HEADERS = array(
		'x-forwarded-for'  => 'HTTP_X_FORWARDED_FOR',
		'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP',
	);

	public static function get( string $proxy_header = '' ): string {
		$key = self::HEADERS[ $proxy_header ] ?? null;
		if ( null !== $key && isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
			foreach ( array_reverse( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) ) as $candidate ) {
				$ip = self::valid( $candidate );
				if ( null !== $ip ) {
					return $ip;
				}
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? self::valid( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : null;
		return $remote ?? '0.0.0.0';
	}

	private static function valid( string $candidate ): ?string {
		$candidate = trim( $candidate );
		return false === filter_var( $candidate, FILTER_VALIDATE_IP ) ? null : $candidate;
	}
}
