/* Bug Spotter settings, "Statuses" tab: reorder rows by drag and drop, add a row from the <template>, delete a row
 * (asking where its reports go when it is used). The form posts the rows in document order. No build step. */
( function ( $ ) {
	'use strict';

	var $table = $( '.bugspot-status-table' );
	var $body = $table.children( 'tbody' );
	var $moves = $( '.bugspot-status-moves' );
	var template = $( '.bugspot-status-template' ).get( 0 );
	if ( ! $body.length || ! template ) {
		return;
	}
	var $add = $( '.bugspot-status-add' );
	var max = parseInt( $add.data( 'max' ), 10 ) || 20;
	var added = 0;

	/* No row beyond the cap: the server would refuse it. */
	function updateAdd() {
		$add.prop( 'disabled', $body.children( '.bugspot-status-row' ).length >= max );
	}

	function initColor( $scope ) {
		if ( $.fn.wpColorPicker ) {
			$scope.find( '.bugspot-status-color' ).wpColorPicker();
		}
	}

	function rowName( $row ) {
		var $label = $row.find( '.bugspot-status-label' );
		return $.trim( $label.val() ) || $label.attr( 'placeholder' ) || '';
	}

	/* Saved statuses still in the table: the only valid targets (a new one has no key until saved). */
	function targets() {
		return $body.children( '.bugspot-status-row' ).filter( function () {
			return '' !== String( $( this ).data( 'key' ) || '' );
		} );
	}

	/* Rebuilds every "move to" list from the remaining statuses, keeping each choice while it remains. */
	function refreshMoves() {
		var $targets = targets();
		$moves.find( 'select' ).each( function () {
			var $select = $( this );
			var chosen = $select.val() || 'new';
			$select.empty();
			$targets.each( function () {
				var key = String( $( this ).data( 'key' ) );
				$select.append( $( '<option></option>' ).val( key ).text( rowName( $( this ) ) ) );
			} );
			$select.val( $select.find( 'option' ).filter( function () {
				return this.value === chosen;
			} ).length ? chosen : 'new' );
		} );
	}

	function deleteRow( $row ) {
		var key = String( $row.data( 'key' ) || '' );
		var count = parseInt( $row.find( '.bugspot-status-count' ).data( 'count' ), 10 ) || 0;
		var name = rowName( $row );
		var moveLabel = $row.data( 'move-label' );
		$row.remove();
		if ( '' !== key && count > 0 ) {
			var id = 'bugspot-status-move-' + key;
			var $select = $( '<select></select>' ).attr( { id: id, name: 'bugspot_settings[status_moves][' + key + ']' } );
			var $label = $( '<label></label>' ).attr( 'for', id ).text( name + ' — ' + moveLabel + ' ' );
			$moves.append( $( '<p></p>' ).append( $label, $select ) );
		}
		refreshMoves();
		updateAdd();
	}

	$body.sortable( {
		items: '> .bugspot-status-row',
		handle: '.bugspot-status-handle',
		axis: 'y',
		placeholder: 'bugspot-status-placeholder',
		forcePlaceholderSize: true
	} );

	initColor( $body );

	updateAdd();

	$add.on( 'click', function () {
		added += 1;
		var html = template.innerHTML.replace( /__i__/g, 'n' + added );
		var $row = $( $.parseHTML( $.trim( html ) ) ).filter( 'tr' );
		$body.append( $row );
		initColor( $row );
		updateAdd();
		$row.find( '.bugspot-status-label' ).trigger( 'focus' );
	} );

	$body.on( 'click', '.bugspot-status-delete', function () {
		deleteRow( $( this ).closest( '.bugspot-status-row' ) );
	} );

	/* A renamed status shows its new name in the "move to" lists. */
	$body.on( 'input', '.bugspot-status-label', refreshMoves );
}( jQuery ) );
