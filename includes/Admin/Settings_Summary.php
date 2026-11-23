<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;
use Bug_Spotter\Captcha\Factory;
use Bug_Spotter\Captcha\Siteverify_Provider;
use Bug_Spotter\Mailer;
use Bug_Spotter\Statuses;
use Bug_Spotter\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only summary at the top of the General tab: what the saved settings do now, one line per topic, each one
 * linking to its section. Every value comes from the helper that applies the rule (Mailer, Webhooks, Statuses…).
 */
final class Settings_Summary {

	/** Ids of the section headings the summary links to. */
	public const ACTIVATION = 'bugspot-section-activation';
	public const WHO        = 'bugspot-section-who';
	public const SPAM       = 'bugspot-section-spam-check';
	public const EMAIL      = 'bugspot-section-email';
	public const WEBHOOKS   = 'bugspot-section-webhooks';
	public const RETENTION  = 'bugspot-section-retention';

	/** @param array<string,mixed> $s Saved settings. */
	public static function render( array $s ): void {
		F::heading( __( 'Summary', 'bug-spotter' ), __( 'What the saved settings do now. Each line leads to its setting.', 'bug-spotter' ) );
		echo '<dl class="bugspot-summary">';
		foreach ( self::rows( $s ) as $row ) {
			printf(
				'<dt><a href="%1$s">%2$s</a></dt><dd%3$s>%4$s</dd>',
				// A line of this tab is a plain anchor: no reload (which would also ask about unsaved changes).
				esc_url( ( 'general' === $row['tab'] ? '' : Settings_Page::tab_url( $row['tab'] ) ) . '#' . $row['anchor'] ),
				esc_html( $row['label'] ),
				'' !== $row['class'] ? ' class="' . esc_attr( $row['class'] ) . '"' : '',
				esc_html( $row['value'] )
			);
		}
		echo '</dl>';
	}

	/**
	 * @param array<string,mixed> $s
	 * @return list<array{label:string,tab:string,anchor:string,value:string,class:string}> class: "" or "bugspot-summary-warning".
	 */
	public static function rows( array $s ): array {
		$enabled = ! empty( $s['enabled'] );
		return array(
			self::row( __( 'Report button', 'bug-spotter' ), 'general', self::ACTIVATION, $enabled ? array( _x( 'On', 'report button state', 'bug-spotter' ), false ) : array( __( 'Off: nobody sees the button.', 'bug-spotter' ), true ) ),
			self::row( __( 'Who can report', 'bug-spotter' ), 'general', self::WHO, array( self::reporters( $s['visibility'] ), false ) ),
			self::row( __( 'Spam protection', 'bug-spotter' ), 'spam', self::SPAM, self::spam( $s ) ),
			self::row( __( 'E-mail', 'bug-spotter' ), 'alerts', self::EMAIL, array( self::email( $s['notifications'] ), false ) ),
			self::row( __( 'Webhooks', 'bug-spotter' ), 'alerts', self::WEBHOOKS, array( self::webhooks(), false ) ),
			self::row( __( 'Retention', 'bug-spotter' ), 'privacy', self::RETENTION, self::retention( (int) $s['data']['retention_months'] ) ),
		);
	}

	/**
	 * @param array{0:string,1:bool} $value The text, and whether it is a warning.
	 * @return array{label:string,tab:string,anchor:string,value:string,class:string}
	 */
	private static function row( string $label, string $tab, string $anchor, array $value ): array {
		return array(
			'label'  => $label,
			'tab'    => $tab,
			'anchor' => $anchor,
			'value'  => $value[0],
			'class'  => $value[1] ? 'bugspot-summary-warning' : '',
		);
	}

	/** The checked roles that still exist, then guests: the rule of Visibility::user_allowed(). @param array<string,mixed> $v */
	private static function reporters( array $v ): string {
		$names = array_values( array_intersect_key( F::role_names(), array_flip( (array) $v['roles'] ) ) );
		if ( ! empty( $v['guests'] ) ) {
			$names[] = __( 'Guests (not logged in)', 'bug-spotter' );
		}
		return $names ? implode( wp_get_list_item_separator(), $names ) : __( 'Nobody', 'bug-spotter' );
	}

	/** @param array<string,mixed> $s @return array{0:string,1:bool} */
	private static function spam( array $s ): array {
		$labels   = Settings_Spam_Tab::provider_labels();
		$label    = $labels[ $s['captcha']['provider'] ] ?? $labels['none'];
		$provider = Factory::from_settings( $s['captcha'] );
		if ( $provider instanceof Siteverify_Provider && ! $provider->has_secret() ) {
			/* translators: %s: the spam check service, such as "Cloudflare Turnstile". */
			return array( sprintf( __( '%s: secret key missing, every report will fail.', 'bug-spotter' ), $label ), true );
		}
		if ( Settings_Page::guests_unprotected( $s ) ) {
			/* translators: %s: "None", the spam check service. */
			return array( sprintf( __( '%s: guests can send reports without any check.', 'bug-spotter' ), $label ), true );
		}
		return array( $label, false );
	}

	/** @param array<string,mixed> $n */
	private static function email( array $n ): string {
		if ( empty( $n['enabled'] ) ) {
			return _x( 'Off', 'e-mail notification state', 'bug-spotter' );
		}
		$recipients = Mailer::recipients( $n );
		if ( Mailer::uses_admin_email( $n ) ) {
			/* translators: %s: the site administration e-mail address. */
			return sprintf( __( 'Site administration address (%s)', 'bug-spotter' ), $recipients[0] );
		}
		$count = count( $recipients );
		/* translators: %d: number of e-mail addresses. */
		return sprintf( _n( '%d recipient', '%d recipients', $count, 'bug-spotter' ), $count );
	}

	private static function webhooks(): string {
		$count = count( Webhooks::active() );
		/* translators: %d: number of webhooks switched on. */
		return sprintf( _n( '%d active', '%d active', $count, 'bug-spotter' ), $count );
	}

	/**
	 * Retention::purge_closed_older_than() deletes nothing for 0 months, nor without any status marked Done.
	 *
	 * @return array{0:string,1:bool}
	 */
	private static function retention( int $months ): array {
		if ( $months <= 0 ) {
			return array( __( 'Without limit', 'bug-spotter' ), false );
		}
		/* translators: %d: number of months. */
		$value = sprintf( _n( '%d month', '%d months', $months, 'bug-spotter' ), $months );
		if ( Statuses::closed() ) {
			return array( $value, false );
		}
		/* translators: %s: a duration, such as "6 months". */
		return array( sprintf( __( '%s, but no status is marked Done: nothing is deleted.', 'bug-spotter' ), $value ), true );
	}
}
