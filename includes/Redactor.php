<?php

namespace Bug_Spotter;

defined( 'ABSPATH' ) || exit;

// Server-side redaction (spec 5.2). The server never trusts the browser: everything stored goes through here.
//
// These rules are mirrored 1:1 in JavaScript (Task 10). Keep them simple and deterministic.
// (Line comments on purpose: the regexes below contain the two-character sequence that ends a block comment.)
//
// text() applies, in this exact order:
//  1. Invalid UTF-8 is replaced (mb_scrub, bad bytes become "?").
//  2. Bearer:   /\bBearer\s+\S+/i                                      => "Bearer [jeton]"
//  3. JWT:      /\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*/   => "[jeton]"
//  4. E-mail:   /[\w.+%-]{1,64}@[\w-]{1,63}(?:\.[\w-]{1,63}){1,20}/u   => "[email]"
//     (every repetition is bounded on purpose: unbounded labels make the regex engine hit its
//     backtrack/JIT limit on hostile input such as "a@" . "a." x 25000; the JS mirror copies these bounds.)
//  5. Long run: /[A-Za-z0-9_-]{32,}/                                    => "[jeton]"
//  6. Truncation to $max characters (mb_substr). Truncating last means a token can never be cut into
//     a visible fragment, and the cut never splits a UTF-8 character.
//
// url() applies: control characters and whitespace removed; parse; scheme and host are required (else '');
// user:pass@ and #fragment dropped; the path and each query parameter NAME are percent-decoded
// (rawurldecode) and stripped of control characters again, so "jean%40ex.fr" is seen as an e-mail
// (the decoded, readable form is what is kept, not re-encoded); each query value is replaced by U+2026;
// the rebuilt URL then goes through text() (rules 1-6) so e-mails and tokens in the path are masked too.
final class Redactor {

	private const VALUE_MASK = "\u{2026}";

	public static function text( string $s, int $max ): string {
		$s = mb_scrub( $s, 'UTF-8' );

		$steps = array(
			'/\bBearer\s+\S+/i'                                       => 'Bearer [jeton]',
			'/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*/'   => '[jeton]',
			'/[\w.+%-]{1,64}@[\w-]{1,63}(?:\.[\w-]{1,63}){1,20}/u'                        => '[email]',
			'/[A-Za-z0-9_-]{32,}/'                                    => '[jeton]',
		);
		foreach ( $steps as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $s );
			if ( null === $result ) {
				return ''; // Regex failure (backtrack limit): fail closed rather than store unredacted text.
			}
			$s = $result;
		}

		return mb_substr( $s, 0, max( 0, $max ), 'UTF-8' );
	}

	public static function url( string $url, int $max ): string {
		$url = preg_replace( '/[\x00-\x20\x7f]+/', '', mb_scrub( $url, 'UTF-8' ) );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$built = strtolower( $parts['scheme'] ) . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$built .= ':' . $parts['port'];
		}
		$built .= self::decode( $parts['path'] ?? '' );

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$pairs = array();
			foreach ( explode( '&', $parts['query'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$name    = self::decode( explode( '=', $pair, 2 )[0] );
				$pairs[] = $name . '=' . self::VALUE_MASK;
			}
			if ( $pairs ) {
				$built .= '?' . implode( '&', $pairs );
			}
		}

		return self::text( $built, $max );
	}

	/** Percent-decodes, then drops control characters and whitespace again (a decoded %00 or %20 must not survive). */
	private static function decode( string $part ): string {
		return (string) preg_replace( '/[\x00-\x20\x7f]+/', '', rawurldecode( $part ) );
	}
}
