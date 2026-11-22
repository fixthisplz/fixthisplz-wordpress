/* Bug Spotter settings, "Statuses" and "Priorities" tabs: reorder rows by drag and drop, add a row from the <template>, delete a row
 * (asking where its reports go when it is used). The form posts the rows in document order. No build step. */
( function ( $ ) {
	'use strict';

	var $table = $( '.bugspot-list-table' );
	var $body = $table.children( 'tbody' );
	var $moves = $( '.bugspot-list-moves' );
	var template = $( '.bugspot-list-template' ).get( 0 );
	if ( ! $body.length || ! template ) {
		return;
	}
	var $add = $( '.bugspot-list-add' );
	var max = parseInt( $add.data( 'max' ), 10 ) || 20;
	var movesName = String( $table.data( 'moves' ) );
	var fallback = String( $table.data( 'fallback' ) );
	var added = 0;

	/* No row beyond the cap: the server would refuse it. */
	function updateAdd() {
		$add.prop( 'disabled', $body.children( '.bugspot-list-row' ).length >= max );
	}

	function initColor( $scope ) {
		if ( $.fn.wpColorPicker ) {
			$scope.find( '.bugspot-list-color' ).wpColorPicker();
		}
	}

	function rowName( $row ) {
		var $label = $row.find( '.bugspot-list-label' );
		return $.trim( $label.val() ) || $label.attr( 'placeholder' ) || '';
	}

	/* Saved choices still in the table: the only valid targets (a new one has no key until saved). */
	function targets() {
		return $body.children( '.bugspot-list-row' ).filter( function () {
			return '' !== String( $( this ).data( 'key' ) || '' );
		} );
	}

	/* Rebuilds every "move to" list from the remaining choices, keeping each choice while it remains. */
	function refreshMoves() {
		var $targets = targets();
		$moves.find( 'select' ).each( function () {
			var $select = $( this );
			var chosen = $select.val() || fallback;
			$select.empty();
			$targets.each( function () {
				var key = String( $( this ).data( 'key' ) );
				$select.append( $( '<option></option>' ).val( key ).text( rowName( $( this ) ) ) );
			} );
			$select.val( $select.find( 'option' ).filter( function () {
				return this.value === chosen;
			} ).length ? chosen : fallback );
		} );
	}

	function deleteRow( $row ) {
		var key = String( $row.data( 'key' ) || '' );
		var count = parseInt( $row.find( '.bugspot-list-count' ).data( 'count' ), 10 ) || 0;
		var name = rowName( $row );
		var moveLabel = $row.data( 'move-label' );
		$row.remove();
		if ( '' !== key && count > 0 ) {
			var id = 'bugspot-list-move-' + key;
			var $select = $( '<select></select>' ).attr( { id: id, name: movesName + '[' + key + ']' } );
			var $label = $( '<label></label>' ).attr( 'for', id ).text( name + ' — ' + moveLabel + ' ' );
			$moves.append( $( '<p></p>' ).append( $label, $select ) );
		}
		refreshMoves();
		updateAdd();
	}

	$body.sortable( {
		items: '> .bugspot-list-row',
		handle: '.bugspot-list-handle',
		axis: 'y',
		placeholder: 'bugspot-list-placeholder',
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
		$row.find( '.bugspot-list-label' ).trigger( 'focus' );
	} );

	$body.on( 'click', '.bugspot-list-delete', function () {
		deleteRow( $( this ).closest( '.bugspot-list-row' ) );
	} );

	/* A renamed choice shows its new name in the "move to" lists. */
	$body.on( 'input', '.bugspot-list-label', refreshMoves );
}( jQuery ) );
