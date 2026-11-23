<?php

namespace FixThisPlz\Admin;

use FixThisPlz\Activity;
use FixThisPlz\Report_Format;
use FixThisPlz\Reports_Repository;

defined( 'ABSPATH' ) || exit;

/** "Notes and history" box of the detail screen: one timeline, newest first, and the forms to add or delete a note. */
final class Activity_Box {

	public const ADD_ACTION    = 'fixthisplz_add_note';
	public const DELETE_ACTION = 'fixthisplz_delete_note';

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ADD_ACTION, array( self::class, 'handle_add' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( self::class, 'handle_delete' ) );
	}

	public static function handle_add(): void {
		$id = Request::posted_id( 'report' );
		check_admin_referer( self::ADD_ACTION . '_' . $id );
		Request::require_manager();

		$note = isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$ok   = null !== ( new Reports_Repository() )->find( $id ) && Activity::add_note( $id, get_current_user_id(), $note ) > 0;
		wp_safe_redirect( Report_Format::link( $id, array( 'fixthisplz_notice' => $ok ? 'note_added' : 'note_failed' ) ) );
		exit;
	}

	public static function handle_delete(): void {
		$id = Request::posted_id( 'note' );
		check_admin_referer( self::DELETE_ACTION . '_' . $id );
		Request::require_manager();

		$note = Activity::find( $id );
		if ( null === $note || ! Activity::can_delete( $note, get_current_user_id() ) ) {
			Request::deny();
		}
		Activity::delete_note( $id );
		wp_safe_redirect( Report_Format::link( $note['report_id'], array( 'fixthisplz_notice' => 'note_deleted' ) ) );
		exit;
	}

	public static function render( int $report_id ): void {
		echo '<form method="post" class="fixthisplz-note-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADD_ACTION ) . '">';
		echo '<input type="hidden" name="report" value="' . esc_attr( (string) $report_id ) . '">';
		wp_nonce_field( self::ADD_ACTION . '_' . $report_id );
		echo '<label for="fixthisplz-note" class="screen-reader-text">' . esc_html__( 'New note', 'fixthisplz' ) . '</label>';
		echo '<textarea id="fixthisplz-note" name="note" rows="3" class="large-text" required placeholder="' . esc_attr__( 'Internal note, only visible to the team', 'fixthisplz' ) . '"></textarea>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Add note', 'fixthisplz' ) . '</button></p></form>';

		$entries = Activity::for_report( $report_id );
		if ( ! $entries ) {
			echo '<p class="fixthisplz-muted">' . esc_html__( 'No notes or changes yet.', 'fixthisplz' ) . '</p>';
			return;
		}
		// The authors and assignees the timeline names, loaded in one query.
		$people = array();
		foreach ( $entries as $entry ) {
			$people[] = (int) $entry['user_id'];
			if ( 'assigned_to' === $entry['field'] ) {
				array_push( $people, (int) $entry['old_value'], (int) $entry['new_value'] );
			}
		}
		cache_users( array_filter( $people ) );
		$user = get_current_user_id();
		echo '<ol class="fixthisplz-timeline">';
		foreach ( $entries as $entry ) {
			$note = Activity::NOTE === $entry['type'];
			echo '<li class="fixthisplz-entry fixthisplz-entry-' . ( $note ? 'note' : 'change' ) . '">';
			echo '<p class="fixthisplz-entry-head"><strong>' . esc_html( Report_Format::author( $entry['user_id'] ) ) . '</strong> <span class="fixthisplz-muted">' . esc_html( Report_Format::date( $entry['created_at'] ) ) . '</span></p>';
			if ( $note ) {
				echo '<div class="fixthisplz-note-text">' . nl2br( esc_html( (string) $entry['content'] ) ) . '</div>';
				if ( Activity::can_delete( $entry, $user ) ) {
					self::render_delete_form( $entry['id'] );
				}
			} else {
				echo '<p>' . esc_html( Report_Format::change( (string) $entry['field'], $entry['old_value'], $entry['new_value'] ) ) . '</p>';
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	private static function render_delete_form( int $note_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . esc_attr__( 'Delete this note?', 'fixthisplz' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE_ACTION ) . '">';
		echo '<input type="hidden" name="note" value="' . esc_attr( (string) $note_id ) . '">';
		wp_nonce_field( self::DELETE_ACTION . '_' . $note_id );
		echo '<button type="submit" class="button-link button-link-delete">' . esc_html__( 'Delete note', 'fixthisplz' ) . '</button></form>';
	}
}
