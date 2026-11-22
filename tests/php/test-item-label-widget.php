<?php

use Bug_Spotter\Admin\Dashboard_Widget;
use Bug_Spotter\Admin\Detail_Page;
use Bug_Spotter\Mailer;
use Bug_Spotter\Report_Format;
use Bug_Spotter\Settings;
use Bug_Spotter\Settings_Sanitizer;

// bugspot_admin_insert(), bugspot_capture(), also when run alone.
require_once __DIR__ . '/test-admin-list.php';
require_once __DIR__ . '/test-admin-detail.php';

bugspot_test(
	'item-label: the_singular_name_titles_the_detail_page_and_the_email_escaped',
	function () {
		with_settings_option(
			function () {
				assert_same( 'Bug #12', Report_Format::title( 12 ), 'default name' );

				$saved = Settings_Sanitizer::sanitize( array( 'management' => array( 'item_label' => ' <b>Ticket</b> & "co" ' . str_repeat( 'x', 50 ) ) ) );
				assert_same( 40, mb_strlen( $saved['management']['item_label'] ), 'at most 40 characters' );
				update_option( Settings::OPTION, array( 'management' => array( 'item_label' => 'Ticket & "co"' ) ) );
				$id = bugspot_admin_insert( 'item-label' );
				assert_same( 'Ticket & "co" #' . $id, Report_Format::title( $id ) );

				$html = bugspot_capture( fn() => Detail_Page::render( $id ) );
				assert_true( str_contains( $html, '<h1 class="wp-heading-inline">Ticket &amp; &quot;co&quot; #' . $id . '</h1>' ), 'escaped detail title' );

				$sent = array();
				$mail = static function ( $short, $atts ) use ( &$sent ) {
					$sent[] = $atts;
					return true;
				};
				add_filter( 'pre_wp_mail', $mail, 10, 2 );
				try {
					assert_true( Mailer::notify( $id ) );
				} finally {
					remove_filter( 'pre_wp_mail', $mail, 10 );
				}
				assert_true( str_ends_with( $sent[0]['subject'], '] New report: Ticket & "co" #' . $id ), 'subject: ' . $sent[0]['subject'] );
				assert_true( str_contains( $sent[0]['message'], '</strong> Ticket &amp; &quot;co&quot; #' . $id . '</p>' ), 'escaped in the HTML body' );
			}
		);
	}
);

bugspot_test(
	'dashboard-widget: only_bug_managers_get_it',
	function () {
		global $wp_meta_boxes;
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		$user  = get_current_user_id();
		$saved = $wp_meta_boxes;
		set_current_screen( 'dashboard' );
		try {
			$subscriber = wp_insert_user( array( 'user_login' => 'bugspot_widget_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
			wp_set_current_user( $subscriber );
			Dashboard_Widget::register();
			assert_true( ! isset( $wp_meta_boxes['dashboard']['normal']['core'][ Dashboard_Widget::ID ] ), 'no widget without bugspot_manage' );

			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			wp_set_current_user( (int) $admins[0] );
			Dashboard_Widget::register();
			assert_same( esc_html( Settings::text( 'management.menu_label' ) ), $wp_meta_boxes['dashboard']['normal']['core'][ Dashboard_Widget::ID ]['title'] );
		} finally {
			$wp_meta_boxes = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			wp_set_current_user( $user );
			$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}
);

bugspot_test(
	'dashboard-widget: counts_the_open_statuses_and_links_the_5_latest_reports',
	function () {
		with_settings_option(
			function () {
				update_option( Settings::OPTION, array( 'management' => array( 'item_label' => 'Ticket', 'list_label' => 'Every <ticket>' ) ) );
				$before = ( new Bug_Spotter\Reports_Repository() )->count_by_status( array() );
				$ids    = array();
				for ( $i = 1; $i <= 6; $i++ ) {
					$ids[] = bugspot_admin_insert( 'widget report ' . $i . ' <script>' );
				}
				$html = bugspot_capture( array( Dashboard_Widget::class, 'render' ) );

				assert_true( str_contains( $html, 'status=new' ) && str_contains( $html, 'status=seen' ), 'open statuses listed' );
				assert_true( ! str_contains( $html, 'status=fixed' ) && ! str_contains( $html, 'status=wont_fix' ), 'closed statuses left out' );
				assert_true( str_contains( $html, '<span class="bugspot-count">' . number_format_i18n( $before['new'] + 6 ) . '</span>' ), 'count of new' );
				foreach ( array_slice( array_reverse( $ids ), 0, 5 ) as $id ) {
					assert_true( str_contains( $html, esc_url( Detail_Page::url( $id ) ) . '">Ticket #' . $id . '</a>' ), 'latest #' . $id );
				}
				assert_true( ! str_contains( $html, 'Ticket #' . $ids[0] . '<' ), 'only 5 reports' );
				assert_true( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ), 'excerpt escaped' );
				assert_true( str_contains( $html, '>Every &lt;ticket&gt;</a></p>' ), 'link to the list, with its name' );
			}
		);
	}
);
