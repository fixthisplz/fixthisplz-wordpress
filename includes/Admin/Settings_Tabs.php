<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;

defined( 'ABSPATH' ) || exit;

/** Renderers of the settings tabs other than "Button" (see Settings_Button_Tab). One method per tab. */
final class Settings_Tabs {

	/** @param array<string,mixed> $s */
	public static function visibility( array $s ): void {
		$v = $s['visibility'];
		F::table_open();

		F::row_open( __( 'Bug reporting', 'bug-spotter' ) );
		F::checkbox( array( 'enabled' ), (bool) $s['enabled'], __( 'Show the bug report button', 'bug-spotter' ) );
		F::row_close();

		F::row_open( __( 'Who sees the button', 'bug-spotter' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Who sees the button', 'bug-spotter' ) . '</legend>';
		F::list_marker( array( 'visibility', 'roles' ) );
		foreach ( self::role_names() as $role => $label ) {
			F::checkbox_item( array( 'visibility', 'roles' ), $role, in_array( $role, $v['roles'], true ), $label );
		}
		F::checkbox( array( 'visibility', 'guests' ), (bool) $v['guests'], __( 'Guests (not logged in)', 'bug-spotter' ) );
		echo '</fieldset>';
		F::row_close();

		$id    = F::id( array( 'visibility', 'excluded_pages' ) );
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'menu_order,post_title',
			)
		);
		$known = array();
		F::row_open( __( 'Excluded pages', 'bug-spotter' ), $id );
		F::list_marker( array( 'visibility', 'excluded_pages' ) );
		echo '<select multiple size="8" class="bugspot-wide" id="' . esc_attr( $id ) . '" name="' . esc_attr( F::name( array( 'visibility', 'excluded_pages' ) ) ) . '[]" aria-describedby="' . esc_attr( $id ) . '-desc">';
		foreach ( is_array( $pages ) ? $pages : array() as $page ) {
			$known[] = (int) $page->ID;
			F::option( (string) $page->ID, self::page_label( (int) $page->ID ), in_array( (int) $page->ID, $v['excluded_pages'], true ) );
		}
		// Saved pages that are no longer published stay selected rather than being dropped silently.
		foreach ( array_diff( $v['excluded_pages'], $known ) as $page_id ) {
			F::option( (string) $page_id, self::page_label( (int) $page_id ), true );
		}
		echo '</select>';
		F::description( __( 'Hold Ctrl (or Cmd) to select several pages, or to unselect one.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'visibility', 'excluded_url_prefixes' ) );
		F::row_open( __( 'Excluded URL prefixes', 'bug-spotter' ), $id );
		F::textarea( array( 'visibility', 'excluded_url_prefixes' ), implode( "\n", $v['excluded_url_prefixes'] ), 4, '/checkout/', true  );
		F::description( __( 'One path per line, for example /checkout/ or /my-account/. The button is hidden on every URL that starts with one of them.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Mobile', 'bug-spotter' ) );
		F::checkbox( array( 'visibility', 'hide_on_mobile' ), (bool) $v['hide_on_mobile'], __( 'Hide the button on small screens (782 px wide or less)', 'bug-spotter' ) );
		F::row_close();

		F::row_open( __( 'Administration', 'bug-spotter' ) );
		F::checkbox( array( 'visibility', 'show_in_admin' ), (bool) $v['show_in_admin'], __( 'Also show the button in the WordPress administration', 'bug-spotter' ) );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $s */
	public static function dialog( array $s ): void {
		$g = $s['dialog'];
		F::table_open();

		$id = F::id( array( 'dialog', 'title' ) );
		F::row_open( __( 'Title', 'bug-spotter' ), $id );
		F::input( 'text', array( 'dialog', 'title' ), $g['title'], array( 'placeholder' => __( 'Report a bug', 'bug-spotter' ), 'class' => 'regular-text' ) );
		F::row_close();

		$id = F::id( array( 'dialog', 'instructions' ) );
		F::row_open( __( 'Instructions', 'bug-spotter' ), $id );
		F::textarea( array( 'dialog', 'instructions' ), $g['instructions'], 3, __( 'Describe what went wrong and what you expected to happen.', 'bug-spotter' ) );
		F::row_close();

		$id = F::id( array( 'dialog', 'privacy_text' ) );
		F::row_open( __( 'Privacy notice', 'bug-spotter' ), $id );
		F::textarea( array( 'dialog', 'privacy_text' ), $g['privacy_text'], 3, __( 'Your report includes technical details about this page and your browser.', 'bug-spotter' ) );
		F::row_close();

		$id      = F::id( array( 'dialog', 'privacy_url' ) );
		$privacy = get_privacy_policy_url();
		F::row_open( __( 'Privacy policy link', 'bug-spotter' ), $id );
		F::input( 'url', array( 'dialog', 'privacy_url' ), $g['privacy_url'], array( 'placeholder' => '' !== $privacy ? $privacy : 'https://', 'class' => 'regular-text code' ), true  );
		F::description( __( 'Leave empty to use the privacy policy page set in Settings › Privacy.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Screenshot', 'bug-spotter' ) );
		F::checkbox( array( 'dialog', 'allow_screenshot' ), (bool) $g['allow_screenshot'], __( 'Let reporters attach a screenshot of the page', 'bug-spotter' ) );
		F::row_close();

		$only_with_screenshot = F::show_if( array( 'dialog', 'allow_screenshot' ), '=', '1' );

		F::row_open( __( 'Capture method', 'bug-spotter' ), '', true, $only_with_screenshot );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Capture method', 'bug-spotter' ) . '</legend>';
		F::radio( array( 'dialog', 'capture_method' ), 'tab', $g['capture_method'], __( 'Real capture of the tab (recommended) — exact image, the browser asks the visitor for permission', 'bug-spotter' ) );
		F::radio( array( 'dialog', 'capture_method' ), 'dom', $g['capture_method'], __( 'Page reconstruction — no permission prompt, may differ slightly from the screen', 'bug-spotter' ) );
		F::description( __( 'On phones and in browsers without screen sharing, the reconstruction is always used.', 'bug-spotter' ) );
		echo '</fieldset>';
		F::row_close();

		F::row_open( __( 'Masking', 'bug-spotter' ), '', true, $only_with_screenshot );
		F::checkbox( array( 'dialog', 'mask_capture' ), (bool) $g['mask_capture'], __( 'Hide typed fields and sensitive areas in the screenshot', 'bug-spotter' ) );
		F::row_close();

		F::row_open( __( 'Contact e-mail', 'bug-spotter' ) );
		F::checkbox( array( 'dialog', 'ask_email_guests' ), (bool) $g['ask_email_guests'], __( 'Ask guests for an optional contact e-mail address', 'bug-spotter' ) );
		F::row_close();

		F::row_open( __( 'Download formats', 'bug-spotter' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Download formats', 'bug-spotter' ) . '</legend>';
		F::list_marker( array( 'dialog', 'formats' ) );
		$formats = array(
			'md'   => __( 'Markdown (.md)', 'bug-spotter' ),
			'json' => __( 'JSON (.json)', 'bug-spotter' ),
			'txt'  => __( 'Plain text (.txt)', 'bug-spotter' ),
		);
		foreach ( $formats as $format => $label ) {
			F::checkbox_item( array( 'dialog', 'formats' ), $format, in_array( $format, $g['formats'], true ), $label );
		}
		F::description( __( 'Formats offered to download a copy of the report. If none is checked, all of them are offered.', 'bug-spotter' ) );
		echo '</fieldset>';
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $s */
	public static function captcha( array $s ): void {
		$c        = $s['captcha'];
		$provider = array( 'captcha', 'provider' );
		F::table_open();

		F::select_row(
			__( 'Provider', 'bug-spotter' ),
			$provider,
			$c['provider'],
			array(
				'none'         => __( 'None', 'bug-spotter' ),
				'turnstile'    => __( 'Cloudflare Turnstile', 'bug-spotter' ),
				'recaptcha_v2' => __( 'Google reCAPTCHA v2 (checkbox)', 'bug-spotter' ),
				'recaptcha_v3' => __( 'Google reCAPTCHA v3 (score)', 'bug-spotter' ),
				'hcaptcha'     => __( 'hCaptcha', 'bug-spotter' ),
			)
		);

		$keys = F::show_if( $provider, '!=', 'none' );

		$id = F::id( array( 'captcha', 'site_key' ) );
		F::row_open( __( 'Site key', 'bug-spotter' ), $id, false, $keys );
		F::input( 'text', array( 'captcha', 'site_key' ), $c['site_key'], array( 'class' => 'regular-text code', 'autocomplete' => 'off', 'spellcheck' => 'false' ) );
		F::row_close();

		$id = F::id( array( 'captcha', 'secret_key' ) );
		F::row_open( __( 'Secret key', 'bug-spotter' ), $id, false, $keys );
		// The secret is never printed back into the page: blank keeps the saved value.
		F::input(
			'password',
			array( 'captcha', 'secret_key' ),
			'',
			array(
				'class'        => 'regular-text code',
				'autocomplete' => 'new-password',
				'spellcheck'   => 'false',
				'placeholder'  => '' !== $c['secret_key'] ? __( 'Saved — leave blank to keep it', 'bug-spotter' ) : '',
			),
			true
		);
		F::description( __( 'Kept on the server only, never sent to visitors.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'captcha', 'v3_threshold' ) );
		F::row_open( __( 'Minimum score', 'bug-spotter' ), $id, false, F::show_if( $provider, '=', 'recaptcha_v3' ) );
		F::input( 'number', array( 'captcha', 'v3_threshold' ), (string) $c['v3_threshold'], array( 'min' => '0', 'max' => '1', 'step' => '0.1', 'class' => 'small-text' ), true  );
		F::description( __( 'Between 0 (accept everything) and 1 (strictest). Default: 0.5.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Test the keys', 'bug-spotter' ), '', false, $keys );
		echo '<p><button type="button" class="button bugspot-captcha-test" hidden>' . esc_html__( 'Test', 'bug-spotter' ) . '</button></p>';
		echo '<div class="bugspot-captcha-result" role="status" aria-live="polite"></div>';
		F::description( __( 'Uses the provider and keys typed above, even before saving. A blank secret key tests the saved one. Requires JavaScript.', 'bug-spotter' ) );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $s */
	public static function notifications( array $s ): void {
		$n = $s['notifications'];
		F::table_open();

		F::row_open( __( 'E-mail notification', 'bug-spotter' ) );
		F::checkbox( array( 'notifications', 'enabled' ), (bool) $n['enabled'], __( 'Send an e-mail for every new report', 'bug-spotter' ) );
		F::row_close();

		$id    = F::id( array( 'notifications', 'recipients' ) );
		$admin = (string) get_option( 'admin_email' );
		F::row_open( __( 'Recipients', 'bug-spotter' ), $id );
		F::input( 'text', array( 'notifications', 'recipients' ), $n['recipients'], array( 'class' => 'large-text', 'placeholder' => $admin ), true  );
		/* translators: %s: the site administration e-mail address. */
		F::description( sprintf( __( 'Several addresses separated by commas. Empty: %s (site administration address).', 'bug-spotter' ), $admin ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Screenshot', 'bug-spotter' ) );
		F::checkbox( array( 'notifications', 'attach_screenshot' ), (bool) $n['attach_screenshot'], __( 'Attach the screenshot to the e-mail', 'bug-spotter' ) );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $s */
	public static function protection( array $s ): void {
		$p = $s['protection'];
		F::table_open();

		$id = F::id( array( 'protection', 'per_hour' ) );
		F::row_open( __( 'Reports per hour', 'bug-spotter' ), $id );
		F::input( 'number', array( 'protection', 'per_hour' ), (string) $p['per_hour'], array( 'min' => '1', 'max' => '1000', 'class' => 'small-text' ), true  );
		F::description( __( 'Maximum per IP address.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'protection', 'per_day' ) );
		F::row_open( __( 'Reports per day', 'bug-spotter' ), $id );
		F::input( 'number', array( 'protection', 'per_day' ), (string) $p['per_day'], array( 'min' => '1', 'max' => '10000', 'class' => 'small-text' ), true  );
		F::description( __( 'Maximum per IP address. A hidden honeypot field also stops basic bots; it is always active.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		$id = F::id( array( 'protection', 'proxy_header' ) );
		F::row_open( __( 'Trusted proxy header (advanced)', 'bug-spotter' ), $id );
		F::select(
			array( 'protection', 'proxy_header' ),
			$p['proxy_header'],
			array(
				''                 => __( 'None: use the connection address (recommended)', 'bug-spotter' ),
				'x-forwarded-for'  => 'X-Forwarded-For',
				'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)',
			)
		);
		echo '<div class="notice notice-warning inline" id="' . esc_attr( $id ) . '-desc"><p>' . esc_html__( 'Only choose a header if every request reaches this site through a proxy or CDN that sets it. Otherwise anyone can forge it and get around the per-IP limits.', 'bug-spotter' ) . '</p></div>';
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $s */
	public static function context( array $s ): void {
		$x = $s['context'];
		F::table_open();

		F::row_open( __( 'Console errors', 'bug-spotter' ) );
		F::checkbox( array( 'context', 'console' ), (bool) $x['console'], __( 'Attach recent browser console errors and warnings', 'bug-spotter' ) );
		F::row_close();

		F::row_open( __( 'Failed requests', 'bug-spotter' ) );
		F::checkbox( array( 'context', 'network' ), (bool) $x['network'], __( 'Attach recent failed network requests', 'bug-spotter' ) );
		F::row_close();

		$id = F::id( array( 'context', 'sensitive_selectors' ) );
		F::row_open( __( 'Extra sensitive elements', 'bug-spotter' ), $id );
		F::textarea( array( 'context', 'sensitive_selectors' ), implode( "\n", $x['sensitive_selectors'] ), 5, '.account-balance', true  );
		F::description( __( 'One CSS selector per line. Matching elements are masked in screenshots and their text is never collected, in addition to form fields and the built-in list.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $s */
	public static function data( array $s ): void {
		$t = $s['data'];
		F::table_open();

		$id = F::id( array( 'data', 'retention_months' ) );
		F::row_open( __( 'Automatic deletion', 'bug-spotter' ), $id );
		F::input( 'number', array( 'data', 'retention_months' ), (string) $t['retention_months'], array( 'min' => '0', 'max' => '120', 'class' => 'small-text' ), true  );
		echo ' ' . esc_html__( 'months', 'bug-spotter' );
		F::description( __( 'Reports marked Fixed, Won’t fix or Duplicate are deleted (with their screenshot) this many months after their last status change. 0 = never.', 'bug-spotter' ), $id . '-desc' );
		F::row_close();

		F::row_open( __( 'Uninstall', 'bug-spotter' ) );
		F::checkbox( array( 'data', 'delete_on_uninstall' ), (bool) $t['delete_on_uninstall'], __( 'Delete all reports, screenshots and settings when the plugin is deleted', 'bug-spotter' ) );
		F::row_close();

		F::table_close();
	}

	/** @param array<string,mixed> $s */
	public static function management( array $s ): void {
		$m = $s['management'];
		F::table_open();

		F::row_open( __( 'Who can manage bugs', 'bug-spotter' ), '', true );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Who can manage bugs', 'bug-spotter' ) . '</legend>';
		F::list_marker( array( 'management', 'roles' ) );
		foreach ( self::role_names() as $role => $label ) {
			// Administrators always keep access (the sanitizer enforces it): shown checked and locked.
			F::checkbox_item( array( 'management', 'roles' ), $role, 'administrator' === $role || in_array( $role, $m['roles'], true ), $label, 'administrator' === $role );
		}
		F::description( __( 'These roles can see the reports, change their status and export them. Only administrators can change these settings.', 'bug-spotter' ) );
		echo '</fieldset>';
		F::row_close();

		F::row_open( __( 'Menu badge', 'bug-spotter' ) );
		F::checkbox( array( 'management', 'menu_badge' ), (bool) $m['menu_badge'], __( 'Show the number of new reports next to the Bugs menu', 'bug-spotter' ) );
		F::row_close();

		F::table_close();
	}

	/** @return array<string,string> Role slug => translated name. */
	private static function role_names(): array {
		$out = array();
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$out[ $role ] = translate_user_role( $name );
		}
		return $out;
	}

	private static function page_label( int $page_id ): string {
		$title = get_the_title( $page_id );
		/* translators: %d: page ID. */
		return '' !== $title ? $title : sprintf( __( 'Page #%d (no title)', 'bug-spotter' ), $page_id );
	}
}
