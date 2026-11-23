/* Bug Spotter settings, Spam protection tab: the anti-bot "Test" button. It mounts the widget of the provider chosen
 * in the form (assets/js/lib/captcha.js, imported on click) and checks the typed keys with the server. No build step.
 * Every user-visible string comes from bugspotSettings.i18n or from the server answer. */
( function ( $, apiFetch ) {
	'use strict';

	var config = window.bugspotSettings || {};
	var i18n = config.i18n || {};
	var $form = $( '.bugspot-settings-form' );
	if ( ! $form.length ) {
		return;
	}

	var $test = $form.find( '.bugspot-captcha-test' );
	var $widget = $( '.bugspot-captcha-widget' ).first();
	var $result = $form.find( '.bugspot-captcha-result' );
	var mounted = null;
	var testRun = 0;
	var TOKEN_WAIT_MS = 120000;

	function showResult( type, message ) {
		$result.empty();
		if ( ! message ) {
			return;
		}
		var $notice = $( '<div class="notice inline"></div>' ).addClass( 'notice-' + type );
		$notice.append( $( '<p></p>' ).text( message ) );
		$result.append( $notice );
	}

	function captchaField( key ) {
		return $form.find( '[name="' + ( config.option || 'bugspot_settings' ) + '[captcha][' + key + ']"]' ).not( '[type="hidden"]' );
	}

	function stopWidget() {
		if ( mounted ) {
			mounted.destroy();
			mounted = null;
		}
		$widget.empty();
	}

	/** Interactive widgets give a token only once solved: poll until then. */
	function waitForToken( handle, run ) {
		var started = Date.now();
		return new Promise( function ( resolve, reject ) {
			( function poll() {
				if ( run !== testRun ) {
					reject( null );
					return;
				}
				handle.getToken().then( function ( token ) {
					if ( token ) {
						resolve( token );
					} else if ( Date.now() - started > TOKEN_WAIT_MS ) {
						reject( new Error( i18n.testTimeout ) );
					} else {
						setTimeout( poll, 500 );
					}
				}, function () {
					reject( new Error( i18n.testNoWidget ) );
				} );
			} )();
		} );
	}

	$test.prop( 'hidden', false ).on( 'click', function () {
		var provider = String( captchaField( 'provider' ).val() || '' );
		var siteKey = String( captchaField( 'site_key' ).val() || '' ).trim();
		var run = ++testRun;
		stopWidget();
		if ( ! siteKey ) {
			showResult( 'error', i18n.testNeedKey );
			return;
		}
		var scriptUrl = String( ( config.scriptUrls || {} )[ provider ] || '' ).replace( encodeURIComponent( '{siteKey}' ), encodeURIComponent( siteKey ) ).replace( '{siteKey}', encodeURIComponent( siteKey ) );
		$test.prop( 'disabled', true );
		showResult( 'info', i18n.testLoading );

		import( config.captchaUrl )
			.then( function ( module ) {
				return module.mountCaptcha( $widget.get( 0 ), { provider: provider, siteKey: siteKey, scriptUrl: scriptUrl } );
			} )
			.then( function ( handle ) {
				if ( run !== testRun ) {
					handle.destroy();
					return null;
				}
				mounted = handle;
				if ( 'recaptcha_v3' !== provider ) {
					showResult( 'info', i18n.testSolve );
				}
				return waitForToken( handle, run );
			} )
			.then( function ( token ) {
				if ( null === token || run !== testRun ) {
					return null;
				}
				showResult( 'info', i18n.testChecking );
				return apiFetch( {
					path: config.testPath,
					method: 'POST',
					data: {
						provider: provider,
						site_key: siteKey,
						secret_key: String( captchaField( 'secret_key' ).val() || '' ),
						v3_threshold: String( captchaField( 'v3_threshold' ).val() || '' ),
						token: token,
					},
				} ).then( function ( response ) {
					showResult( 'success', ( response && response.message ) || i18n.testValid );
				} );
			} )
			.catch( function ( error ) {
				if ( run !== testRun || null === error ) {
					return;
				}
				var detail = error && error.message ? error.message : '';
				showResult( 'error', detail ? i18n.testFailed + ' ' + detail : i18n.testFailed );
			} )
			.then( function () {
				if ( run === testRun ) {
					$test.prop( 'disabled', false );
					stopWidget();
				}
			} );
	} );
}( window.jQuery, window.wp && window.wp.apiFetch ) );
