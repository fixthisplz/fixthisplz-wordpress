<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * "Tracking › Statuses" and "Tracking › Priorities" views of the settings screen (spec 7.10, 7.11): one row per choice, in the order of
 * the settings, reordered by drag and drop (admin-lists.js). The same row() markup serves the saved choices and
 * the <template> of a new one. The post is turned into the stored list by List_Editor.
 */
final class Settings_List_Tab {

	/**
	 * @param class-string<\Bug_Spotter\Choice_List> $list
	 * @param array<string,mixed>                    $s
	 */
	public static function render( string $list, array $s ): void {
		$counts   = ( new Reports_Repository() )->count_by( $list, array() );
		$text     = self::texts( $list );
		$closable = Statuses::class === $list;

		F::heading( $text['title'], $text['intro'] );
		F::description( $text['help'] );
		printf(
			'<table class="widefat bugspot-list-table" data-moves="%1$s" data-fallback="%2$s"><thead><tr>',
			esc_attr( F::name( array( $list::FIELD . '_moves' ) ) ),
			esc_attr( $list::REQUIRED[0] )
		);
		echo '<th class="bugspot-list-handle"><span class="screen-reader-text">' . esc_html__( 'Order', 'bug-spotter' ) . '</span></th>';
		echo '<th>' . esc_html__( 'Name', 'bug-spotter' ) . '</th>';
		echo '<th>' . esc_html__( 'Color', 'bug-spotter' ) . '</th>';
		if ( $closable ) {
			echo '<th>' . esc_html_x( 'Done', 'status flag: the report is handled', 'bug-spotter' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Reports', 'bug-spotter' ) . '</th>';
		echo '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'bug-spotter' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		foreach ( $list::sanitize( $s[ $list::SETTING ] ) as $index => $entry ) {
			self::row( $list, $text, (string) $index, $entry, $counts[ $entry['key'] ] ?? 0 );
		}
		echo '</tbody></table>';

		echo '<p><button type="button" class="button bugspot-list-add" data-max="' . (int) $list::MAX . '">' . esc_html( $text['add'] ) . '</button></p>';
		echo '<div class="bugspot-list-moves"></div>';

		// A new row: "__i__" is replaced by a unique index in the browser; its key is made on save.
		echo '<template class="bugspot-list-template">';
		$new = array(
			'key'   => '',
			'label' => '',
			'color' => $list::FALLBACK_COLOR,
		);
		self::row( $list, $text, '__i__', $new + ( $closable ? array( 'closed' => false ) : array() ), 0 );
		echo '</template>';
	}

	/** @param class-string<\Bug_Spotter\Choice_List> $list @return array{title:string,intro:string,help:string,add:string,name:string,color:string} */
	private static function texts( string $list ): array {
		if ( Statuses::class === $list ) {
			return array(
				'title' => __( 'Statuses', 'bug-spotter' ),
				'intro' => __( 'The steps a report goes through, in the order of the Kanban columns.', 'bug-spotter' ),
				'help'  => __( 'Drag the rows to change the order of the Kanban columns and of the status lists. Leave the name of an original status empty to keep its default name. A status marked Done counts as finished: its reports can be deleted automatically by the retention (Privacy › Retention).', 'bug-spotter' ),
				'add'   => __( 'Add a status', 'bug-spotter' ),
				'name'  => __( 'Status name', 'bug-spotter' ),
				'color' => __( 'Status color', 'bug-spotter' ),
			);
		}
		return array(
			'title' => __( 'Priorities', 'bug-spotter' ),
			'intro' => __( 'How urgent a report is, from the lowest to the highest.', 'bug-spotter' ),
			'help'  => __( 'Drag the rows to order the priorities from the lowest to the highest: sorting the list by priority follows this order. The original priorities keep their default name while their name is left empty. "Normal", given to every new report, cannot be deleted.', 'bug-spotter' ),
			'add'   => __( 'Add a priority', 'bug-spotter' ),
			'name'  => __( 'Priority name', 'bug-spotter' ),
			'color' => __( 'Priority color', 'bug-spotter' ),
		);
	}

	/**
	 * @param class-string<\Bug_Spotter\Choice_List> $list
	 * @param array<string,string>                   $text  texts() of the list.
	 * @param array<string,mixed>                    $entry key, label, color (and closed for a status).
	 */
	private static function row( string $list, array $text, string $index, array $entry, int $count ): void {
		$key      = (string) $entry['key'];
		$required = in_array( $key, $list::REQUIRED, true );
		$path     = static fn( string $field ): array => array( $list::FIELD . '_rows', $index, $field );
		$label    = array(
			'class'       => 'regular-text bugspot-list-label',
			'maxlength'   => '40',
			'placeholder' => $list::default_label( $key ),
			'aria-label'  => $text['name'],
		);
		if ( '' === $key ) {
			$label['required'] = 'required';
		}

		printf(
			'<tr class="bugspot-list-row" data-key="%1$s" data-move-label="%2$s">',
			esc_attr( $key ),
			/* translators: %d: number of reports that use the deleted status or priority. */
			esc_attr( sprintf( _n( 'Move its %d report to:', 'Move its %d reports to:', $count, 'bug-spotter' ), $count ) )
		);
		echo '<td class="bugspot-list-handle" title="' . esc_attr__( 'Drag to reorder', 'bug-spotter' ) . '"><span class="dashicons dashicons-menu" aria-hidden="true"></span></td><td>';
		F::input( 'hidden', $path( 'key' ), $key );
		F::input( 'text', $path( 'label' ), (string) $entry['label'], $label );
		echo '</td><td>';
		F::input(
			'text',
			$path( 'color' ),
			(string) $entry['color'],
			array(
				'class'              => 'bugspot-list-color',
				'data-default-color' => (string) $entry['color'],
				'aria-label'         => $text['color'],
			)
		);
		echo '</td>';
		if ( array_key_exists( 'closed', $entry ) ) {
			echo '<td>';
			if ( $required ) {
				echo '<span class="bugspot-muted">' . esc_html__( 'Never', 'bug-spotter' ) . '</span>';
			} else {
				F::checkbox( $path( 'closed' ), (bool) $entry['closed'], _x( 'Done', 'status flag: the report is handled', 'bug-spotter' ) );
			}
			echo '</td>';
		}
		echo '<td class="bugspot-list-count" data-count="' . (int) $count . '">' . esc_html( number_format_i18n( $count ) ) . '</td><td>';
		if ( ! $required ) {
			echo '<button type="button" class="button-link button-link-delete bugspot-list-delete">' . esc_html__( 'Delete', 'bug-spotter' ) . '</button>';
		}
		echo '</td></tr>';
	}
}
