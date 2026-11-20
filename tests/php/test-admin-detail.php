<?php

use Bug_Spotter\Admin\Detail_Page;
use Bug_Spotter\Admin\Kanban;
use Bug_Spotter\Reports_Repository;

require_once __DIR__ . '/test-admin-list.php';

/** Captures the HTML printed by $fn. */
function bugspot_capture( callable $fn ): string {
	ob_start();
	try {
		$fn();
	} finally {
		$html = (string) ob_get_clean();
	}
	return $html;
}

bugspot_test(
	'admin-detail: opening_new_report_marks_it_seen',
	function () {
		$id = bugspot_admin_insert( 'detail-seen' );
		bugspot_capture( fn() => Detail_Page::render( $id ) );
		assert_same( 'seen', ( new Reports_Repository() )->find( $id )['status'] );
	}
);

bugspot_test(
	'admin-detail: opening_fixed_report_keeps_status',
	function () {
		$id   = bugspot_admin_insert( 'detail-fixed' );
		$repo = new Reports_Repository();
		$repo->update( $id, array( 'status' => 'fixed' ) );
		bugspot_capture( fn() => Detail_Page::render( $id ) );
		assert_same( 'fixed', $repo->find( $id )['status'] );
	}
);

bugspot_test(
	'admin-detail: detail_escapes_report_content',
	function () {
		$id   = bugspot_admin_insert(
			'<script>alert(1)</script>',
			array(
				'page_url' => 'javascript:alert(1)',
				'context'  => array(
					'element' => array( 'selector' => '<img src=x onerror=1>', 'tag' => 'a', 'role' => '', 'name' => '', 'text' => '', 'rect' => array( 'x' => 1, 'y' => 2, 'width' => 3, 'height' => 4 ) ),
					'console' => array( array( 'level' => 'error', 'message' => '<b>boom</b>', 'at' => '' ) ),
				),
			)
		);
		$html = bugspot_capture( fn() => Detail_Page::render( $id ) );
		assert_true( str_contains( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'description escaped' );
		assert_true( ! str_contains( $html, '<script>alert(1)' ), 'no raw script' );
		assert_true( ! str_contains( $html, '<img src=x' ), 'selector escaped' );
		assert_true( ! str_contains( $html, '<b>boom' ), 'console escaped' );
		assert_true( ! str_contains( $html, 'href="javascript:' ), 'non-http page url is not a link' );
	}
);

bugspot_test(
	'admin-detail: detail_never_shows_hashes',
	function () {
		$id   = bugspot_admin_insert( 'detail-hash', array( 'ip_hash' => 'IPHASHMARKER123', 'reporter_email' => 'visitor@example.test' ) );
		$html = bugspot_capture( fn() => Detail_Page::render( $id ) );
		assert_true( ! str_contains( $html, 'IPHASHMARKER123' ), 'ip hash leaked' );
		assert_true( ! str_contains( $html, 'reporter_email_hash' ), 'email hash key leaked' );
		assert_true( str_contains( $html, 'visitor@example.test' ), 'contact e-mail shown' );
	}
);

bugspot_test(
	'admin-detail: kanban_limits_cards_per_column',
	function () {
		$marker = 'kanbanmarker' . wp_generate_password( 8, false );
		for ( $i = 0; $i < 60; $i++ ) {
			bugspot_admin_insert( $marker . ' ' . $i );
		}
		$filters = array( 'status' => '', 'priority' => '', 'from' => '', 'to' => '', 'search' => $marker );

		$html = bugspot_capture( fn() => Kanban::render( $filters ) );
		assert_same( 50, substr_count( $html, 'class="bugspot-card"' ), 'cards shown' );
		assert_true( str_contains( $html, 'new_limit=100' ), 'show more link' );
		assert_true( str_contains( $html, '>60<' ), 'counter shows the real total' );

		$_GET['new_limit'] = '100';
		try {
			$html = bugspot_capture( fn() => Kanban::render( $filters ) );
		} finally {
			unset( $_GET['new_limit'] );
		}
		assert_same( 60, substr_count( $html, 'class="bugspot-card"' ), 'all cards after show more' );
		assert_true( ! str_contains( $html, 'new_limit=150' ), 'no more link when everything is shown' );
	}
);

bugspot_test(
	'admin-detail: render_after_update_redirect_keeps_new_status',
	function () {
		$id = bugspot_admin_insert( 'detail-keep-new' );
		$_GET['bugspot_notice'] = 'updated';
		try {
			bugspot_capture( fn() => Detail_Page::render( $id ) );
		} finally {
			unset( $_GET['bugspot_notice'] );
		}
		assert_same( 'new', ( new Reports_Repository() )->find( $id )['status'] );
	}
);

bugspot_test(
	'admin-detail: priority_label_matches_select_id',
	function () {
		$id   = bugspot_admin_insert( 'detail-label' );
		$html = bugspot_capture( fn() => Detail_Page::render( $id ) );
		assert_true( str_contains( $html, 'for="bugspot-priority-select"' ) && str_contains( $html, 'id="bugspot-priority-select"' ), 'label/select ids' );
	}
);

bugspot_test(
	'admin-detail: screenshot_box_shows_the_capture_method',
	function () {
		$name  = str_repeat( 'a', 32 ) . '.jpg';
		$cases = array(
			array( array( 'method' => 'tab', 'masked' => false ), 'Real capture', false ),
			array( array( 'method' => 'dom', 'masked' => true ), 'Page reconstruction (masked)', true ),
		);
		foreach ( $cases as list( $capture, $expected, $masked ) ) {
			$id   = bugspot_admin_insert( 'detail-capture', array( 'screenshot' => $name, 'context' => array( 'capture' => $capture ) ) );
			$html = bugspot_capture( fn() => Detail_Page::render( $id ) );
			assert_true( str_contains( $html, '>' . $expected . '<' ), 'label shown: ' . $expected );
			assert_same( $masked, str_contains( $html, '(masked)' ) );
		}
		$id   = bugspot_admin_insert( 'detail-capture-none', array( 'screenshot' => $name ) );
		$html = bugspot_capture( fn() => Detail_Page::render( $id ) );
		assert_true( ! str_contains( $html, 'Real capture' ) && ! str_contains( $html, 'Page reconstruction' ), 'no label without the key' );
	}
);
