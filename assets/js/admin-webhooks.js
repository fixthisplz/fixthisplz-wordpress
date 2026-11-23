/* FixThisPlz settings, Alerts › Webhooks: the "Send a test" button of each saved webhook. No build step.
 * Every user-visible string comes from fixThisPlzSettings.i18n or from the server answer. */
( function ( $, apiFetch ) {
	'use strict';

	var config = window.fixThisPlzSettings || {};
	var i18n = config.i18n || {};

	function showResult( $result, type, message ) {
		var $notice = $( '<div class="notice inline"></div>' ).addClass( 'notice-' + type );
		$result.empty().append( $notice.append( $( '<p></p>' ).text( message ) ) );
	}

	$( '.fixthisplz-webhook-test' ).prop( 'hidden', false ).on( 'click', function () {
		var $button = $( this ).prop( 'disabled', true );
		var $result = $button.closest( 'td' ).find( '.fixthisplz-webhook-result' );
		showResult( $result, 'info', i18n.hookSending || '' );
		apiFetch( { path: config.webhookPath, method: 'POST', data: { index: Number( $button.attr( 'data-index' ) ) } } )
			.then( function ( response ) {
				showResult( $result, response && response.ok ? 'success' : 'error', String( ( response && response.message ) || '' ) );
			} )
			.catch( function ( error ) {
				var detail = error && error.message ? ' ' + error.message : '';
				showResult( $result, 'error', ( i18n.hookFailed || '' ) + detail );
			} )
			.then( function () {
				$button.prop( 'disabled', false );
			} );
	} );
}( window.jQuery, window.wp && window.wp.apiFetch ) );
