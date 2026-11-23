/* FixThisPlz: drag cards between status columns; each drop PATCHes the report. No build step. */
( function ( $, apiFetch ) {
	'use strict';

	var config = window.fixThisPlzKanban || {};
	var $wrap = $( '.fixthisplz-board-wrap' );
	var $board = $( '.fixthisplz-board' );
	var $lists = $board.find( '.fixthisplz-cards' );
	if ( ! $lists.length ) {
		return;
	}

	function bump( status, delta ) {
		var $count = $board.find( '.fixthisplz-count[data-status="' + status + '"]' );
		$count.text( Math.max( 0, ( parseInt( $count.text(), 10 ) || 0 ) + delta ) );
	}

	function showError( message ) {
		$wrap.children( '.fixthisplz-error' ).remove();
		var $notice = $( '<div class="notice notice-error is-dismissible fixthisplz-error" role="alert"></div>' );
		$notice.append( $( '<p></p>' ).text( message ) );
		var $button = $( '<button type="button" class="notice-dismiss"></button>' );
		$button.append( $( '<span class="screen-reader-text"></span>' ).text( config.dismiss || 'Dismiss this notice.' ) );
		$button.on( 'click', function () {
			$notice.remove();
		} );
		$notice.append( $button );
		$wrap.prepend( $notice );
	}

	/* The drag has already ended when the request fails, so sortable('cancel') no longer applies:
	   put the card back where it was picked up. */
	function revert( $item, origin ) {
		var $siblings = origin.$list.children( '.fixthisplz-card' ).not( $item );
		if ( origin.index < $siblings.length ) {
			$item.insertBefore( $siblings.eq( origin.index ) );
		} else {
			origin.$list.append( $item );
		}
	}

	$lists.sortable( {
		connectWith: '.fixthisplz-cards',
		items: '> .fixthisplz-card',
		placeholder: 'fixthisplz-card fixthisplz-placeholder',
		forcePlaceholderSize: true,
		tolerance: 'pointer',
		start: function ( event, ui ) {
			var $list = ui.item.parent();
			ui.item.data( 'fixThisPlzOrigin', {
				$list: $list,
				index: $list.children( '.fixthisplz-card' ).not( ui.placeholder ).index( ui.item )
			} );
		},
		stop: function ( event, ui ) {
			var $item = ui.item;
			var origin = $item.data( 'fixThisPlzOrigin' );
			var from = $item.attr( 'data-status' );
			var to = $item.closest( '.fixthisplz-cards' ).attr( 'data-status' );
			if ( ! origin || ! to ) {
				return;
			}
			if ( from === to ) {
				// Same column: no status change, so put the card back where it was.
				$( this ).sortable( 'cancel' );
				return;
			}

			$board.addClass( 'is-busy' );
			$lists.sortable( 'disable' );
			$wrap.children( '.fixthisplz-error' ).remove();

			apiFetch( {
				path: config.path + $item.attr( 'data-id' ),
				method: 'PATCH',
				data: { status: to }
			} ).then( function () {
				$item.attr( 'data-status', to );
				bump( from, -1 );
				bump( to, 1 );
			} ).catch( function ( error ) {
				revert( $item, origin );
				showError( ( error && error.message ) || config.genericError || 'The report could not be moved.' );
			} ).then( function () {
				$lists.sortable( 'enable' );
				$board.removeClass( 'is-busy' );
			} );
		}
	} );
}( window.jQuery, window.wp && window.wp.apiFetch ) );
