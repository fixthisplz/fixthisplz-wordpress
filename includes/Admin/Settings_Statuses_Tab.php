<?php

namespace Bug_Spotter\Admin;

use Bug_Spotter\Admin\Settings_Fields as F;
use Bug_Spotter\Reports_Repository;
use Bug_Spotter\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * "Statuses" tab of the settings screen (spec 7.10): one row per status, in Kanban order, reordered by drag and
 * drop (admin-statuses.js). The same row() markup serves the saved statuses and the <template> of a new one.
 * The post is turned into the stored list by Status_Editor.
 */
final class Settings_Statuses_Tab {

	/** @param array<string,mixed> $s */
	public static function render( array $s ): void {
		$counts = ( new Reports_Repository() )->count_by_status( array() );

		F::description( __( 'Drag the rows to change the order of the Kanban columns and of the status lists. The original statuses keep their default name while their name is left empty. Reports with a "Closed" status are the ones the retention deletes (Data and privacy tab).', 'bug-spotter' ) );
		echo '<table class="widefat bugspot-status-table"><thead><tr>';
		echo '<th class="bugspot-status-handle"><span class="screen-reader-text">' . esc_html__( 'Order', 'bug-spotter' ) . '</span></th>';
		echo '<th>' . esc_html__( 'Name', 'bug-spotter' ) . '</th>';
		echo '<th>' . esc_html__( 'Color', 'bug-spotter' ) . '</th>';
		echo '<th>' . esc_html__( 'Closed', 'bug-spotter' ) . '</th>';
		echo '<th>' . esc_html__( 'Reports', 'bug-spotter' ) . '</th>';
		echo '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'bug-spotter' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		foreach ( Statuses::sanitize( $s['statuses'] ) as $index => $status ) {
			self::row( (string) $index, $status, $counts[ $status['key'] ] ?? 0 );
		}
		echo '</tbody></table>';

		echo '<p><button type="button" class="button bugspot-status-add" data-max="' . (int) Statuses::MAX . '">' . esc_html__( 'Add a status', 'bug-spotter' ) . '</button></p>';
		echo '<div class="bugspot-status-moves"></div>';

		// A new row: "__i__" is replaced by a unique index in the browser; its key is made on save.
		echo '<template class="bugspot-status-template">';
		self::row(
			'__i__',
			array(
				'key'    => '',
				'label'  => '',
				'color'  => Statuses::FALLBACK_COLOR,
				'closed' => false,
			),
			0
		);
		echo '</template>';
	}

	/** @param array{key:string,label:string,color:string,closed:bool} $status */
	private static function row( string $index, array $status, int $count ): void {
		$key      = $status['key'];
		$required = in_array( $key, Statuses::REQUIRED, true );
		$path     = static fn( string $field ): array => array( 'status_rows', $index, $field );
		$label    = array(
			'class'       => 'regular-text bugspot-status-label',
			'maxlength'   => '40',
			'placeholder' => Statuses::default_label( $key ),
			'aria-label'  => __( 'Status name', 'bug-spotter' ),
		);
		if ( '' === $key ) {
			$label['required'] = 'required';
		}

		printf(
			'<tr class="bugspot-status-row" data-key="%1$s" data-move-label="%2$s">',
			esc_attr( $key ),
			/* translators: %d: number of reports that use the deleted status. */
			esc_attr( sprintf( _n( 'Move its %d report to:', 'Move its %d reports to:', $count, 'bug-spotter' ), $count ) )
		);
		echo '<td class="bugspot-status-handle" title="' . esc_attr__( 'Drag to reorder', 'bug-spotter' ) . '"><span class="dashicons dashicons-menu" aria-hidden="true"></span></td><td>';
		F::input( 'hidden', $path( 'key' ), $key );
		F::input( 'text', $path( 'label' ), $status['label'], $label );
		echo '</td><td>';
		F::input(
			'text',
			$path( 'color' ),
			$status['color'],
			array(
				'class'              => 'bugspot-status-color',
				'data-default-color' => $status['color'],
				'aria-label'         => __( 'Status color', 'bug-spotter' ),
			)
		);
		echo '</td><td>';
		if ( $required ) {
			echo '<span class="bugspot-muted">' . esc_html__( 'Never', 'bug-spotter' ) . '</span>';
		} else {
			F::checkbox( $path( 'closed' ), $status['closed'], __( 'Closed', 'bug-spotter' ) );
		}
		echo '</td><td class="bugspot-status-count" data-count="' . (int) $count . '">' . esc_html( number_format_i18n( $count ) ) . '</td><td>';
		if ( ! $required ) {
			echo '<button type="button" class="button-link button-link-delete bugspot-status-delete">' . esc_html__( 'Delete', 'bug-spotter' ) . '</button>';
		}
		echo '</td></tr>';
	}
}
