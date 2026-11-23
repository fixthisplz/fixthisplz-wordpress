/* Bug Spotter settings screen: conditional fields, icon picker, SVG picker, live button preview and the alert
 * about unsaved changes (the anti-bot "Test" button: admin-captcha-test.js). No build step. Every user-visible string comes from bugspotSettings.i18n. */
( function ( $, apiFetch ) {
	'use strict';

	var config = window.bugspotSettings || {};
	var i18n = config.i18n || {};
	var $form = $( '.bugspot-settings-form' );
	if ( ! $form.length ) {
		return;
	}

	/* ---------------------------------------------------------------- conditional blocks */

	/** Current value of a field by name: checked radio, checkbox ("1"/"0", the hidden "0" is ignored), or value. */
	function fieldValue( name ) {
		var $fields = $form.find( '[name="' + name.replace( /(["\\])/g, '\\$1' ) + '"]' ).not( '[type="hidden"]' );
		if ( ! $fields.length ) {
			return '';
		}
		var type = ( $fields.attr( 'type' ) || '' ).toLowerCase();
		if ( 'radio' === type ) {
			return $fields.filter( ':checked' ).val() || '';
		}
		if ( 'checkbox' === type ) {
			return $fields.is( ':checked' ) ? '1' : '0';
		}
		return String( $fields.val() || '' );
	}

	function applyConditions() {
		$form.find( '[data-bugspot-show-if]' ).each( function () {
			var $el = $( this );
			var value = fieldValue( $el.attr( 'data-bugspot-show-if' ) );
			var expected = String( $el.attr( 'data-bugspot-value' ) );
			var visible = '!=' === $el.attr( 'data-bugspot-op' ) ? value !== expected : value === expected;
			$el.prop( 'hidden', ! visible );
		} );
	}

	/* ---------------------------------------------------------------- live preview */

	var $preview = $form.find( '.bugspot-preview' );
	var frame = $preview.find( '.bugspot-preview-frame' ).get( 0 );
	var previewTimer = null;
	var previewSeq = 0;

	/** Nested object of the posted bugspot_settings[button][...] fields, parsed like PHP parses $_POST. */
	function buttonFields() {
		var prefix = ( config.option || 'bugspot_settings' ) + '[button]';
		var out = {};
		$form.serializeArray().forEach( function ( field ) {
			if ( 0 !== field.name.indexOf( prefix ) ) {
				return;
			}
			var keys = field.name.slice( prefix.length ).match( /\[([^\]]*)\]/g ) || [];
			var node = out;
			keys = keys.map( function ( k ) {
				return k.slice( 1, -1 );
			} );
			keys.forEach( function ( key, index ) {
				if ( index === keys.length - 1 ) {
					node[ key ] = field.value; // Later fields win, like PHP (checkbox after its hidden "0").
				} else {
					node = node[ key ] = node[ key ] && 'object' === typeof node[ key ] ? node[ key ] : {};
				}
			} );
		} );
		return out;
	}

	function frameDocument() {
		return frame && frame.contentDocument;
	}

	function initFrame() {
		var doc = frameDocument();
		if ( ! doc ) {
			return;
		}
		doc.open();
		doc.write(
			'<!doctype html><html><head><meta charset="utf-8">' +
				'<link rel="stylesheet" href="' + encodeURI( config.dashiconsCss || '' ) + '">' +
				'<link rel="stylesheet" href="' + encodeURI( config.frontCss || '' ) + '">' +
				'<style>html,body{margin:0;height:100%;background:#fff;font:14px/1.5 system-ui,sans-serif;color:#50575e}' +
				'.fake{padding:24px;max-width:560px}.fake i{display:block;height:10px;margin:0 0 12px;background:#e0e0e0;border-radius:5px}' +
				'.fake i:first-child{height:18px;width:50%;background:#c3c4c7}</style>' +
				'<style id="bugspot-preview-css"></style></head><body>' +
				'<div class="fake" aria-hidden="true"><i></i><i></i><i style="width:90%"></i><i style="width:75%"></i><i></i><i style="width:60%"></i></div>' +
				'<button id="bugspot-button" type="button" tabindex="-1"></button></body></html>'
		);
		doc.close();
	}

	/** Same markup as the front-end loader (assets/js/loader.js createButton). */
	function renderButton( b ) {
		var doc = frameDocument();
		var el = doc && doc.getElementById( 'bugspot-button' );
		if ( ! el ) {
			return;
		}
		var label = b.label || i18n.defaultLabel || '';
		el.textContent = '';
		el.setAttribute( 'aria-label', label );
		el.title = b.tooltip || i18n.defaultLabel || '';
		var svgUrl = $form.find( '.bugspot-svg-thumb' ).attr( 'src' );
		if ( 'svg' === b.icon_type && svgUrl && '0' !== String( b.svg_attachment_id || '0' ) ) {
			var img = doc.createElement( 'img' );
			img.alt = '';
			img.src = svgUrl;
			el.appendChild( img );
		} else if ( b.dashicon ) {
			var icon = doc.createElement( 'span' );
			icon.className = 'dashicons ' + b.dashicon;
			icon.setAttribute( 'aria-hidden', 'true' );
			el.appendChild( icon );
		}
		if ( 'pill' === b.shape ) {
			var text = doc.createElement( 'span' );
			text.className = 'bugspot-label';
			text.textContent = label;
			el.appendChild( text );
		}
	}

	/** The CSS comes from the server (Button_Style::css), so the preview cannot drift from the front end. */
	function updatePreview() {
		var button = buttonFields();
		var seq = ++previewSeq;
		var $error = $preview.find( '.bugspot-preview-error' );
		renderButton( button );
		apiFetch( { path: config.previewPath, method: 'POST', data: { button: button } } )
			.then( function ( response ) {
				if ( seq !== previewSeq ) {
					return; // A newer request is on its way.
				}
				var doc = frameDocument();
				var style = doc && doc.getElementById( 'bugspot-preview-css' );
				if ( style ) {
					style.textContent = String( ( response && response.css ) || '' );
				}
				$error.prop( 'hidden', true ).text( '' );
			} )
			.catch( function ( error ) {
				if ( seq === previewSeq ) {
					$error.text( ( error && error.message ) || i18n.previewError || '' ).prop( 'hidden', false );
				}
			} );
	}

	function schedulePreview() {
		if ( ! $preview.length ) {
			return;
		}
		clearTimeout( previewTimer );
		previewTimer = setTimeout( updatePreview, 250 );
	}

	if ( $preview.length && frame ) {
		$preview.prop( 'hidden', false );
		initFrame();
		updatePreview();
		$preview.on( 'click', '[data-device]', function () {
			var device = $( this ).attr( 'data-device' );
			$preview.find( '[data-device]' ).attr( 'aria-pressed', 'false' );
			$( this ).attr( 'aria-pressed', 'true' );
			$preview.find( '.bugspot-preview-stage' ).toggleClass( 'is-mobile', 'mobile' === device );
		} );
	}

	/* ---------------------------------------------------------------- icon grid */

	$form.find( '.bugspot-icon-grid' ).each( function () {
		var $grid = $( this ).prop( 'hidden', false );
		var $target = $( '#' + $grid.attr( 'data-target' ) );
		$grid.on( 'click', '.bugspot-icon-choice', function () {
			$grid.find( '.bugspot-icon-choice' ).attr( 'aria-pressed', 'false' );
			$( this ).attr( 'aria-pressed', 'true' );
			$target.val( $( this ).attr( 'data-icon' ) ).trigger( 'change' );
		} );
	} );

	/* ---------------------------------------------------------------- SVG from the media library */

	var mediaFrame = null;
	var $svg = $form.find( '.bugspot-svg-picker' );
	var $svgId = $svg.find( 'input[type="hidden"]' );
	var $svgThumb = $svg.find( '.bugspot-svg-thumb' );
	var $svgRemove = $svg.find( '.bugspot-svg-remove' );

	$svg.on( 'click', '.bugspot-svg-choose', function () {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		if ( ! mediaFrame ) {
			mediaFrame = window.wp.media( {
				title: i18n.mediaTitle,
				button: { text: i18n.mediaButton },
				library: { type: 'image/svg+xml' },
				multiple: false,
			} );
			mediaFrame.on( 'select', function () {
				var attachment = mediaFrame.state().get( 'selection' ).first();
				var data = attachment ? attachment.toJSON() : null;
				if ( ! data || 'image/svg+xml' !== data.mime ) {
					window.alert( i18n.notSvg ); // eslint-disable-line no-alert
					return;
				}
				$svgThumb.attr( 'src', data.url ).prop( 'hidden', false );
				$svgRemove.prop( 'hidden', false );
				$svgId.val( String( data.id ) ).trigger( 'change' );
			} );
		}
		mediaFrame.open();
	} );

	$svg.on( 'click', '.bugspot-svg-remove', function () {
		$svgThumb.attr( 'src', '' ).prop( 'hidden', true );
		$svgRemove.prop( 'hidden', true );
		$svgId.val( '0' ).trigger( 'change' );
	} );

	/* ---------------------------------------------------------------- colors */

	if ( $.fn.wpColorPicker ) {
		$form.find( '.bugspot-color-field' ).wpColorPicker( {
			change: schedulePreview,
			clear: schedulePreview,
		} );
	}

	/* ---------------------------------------------------------------- wiring */

	$form.on( 'change input', ':input', function () {
		applyConditions();
		schedulePreview();
	} );
	applyConditions();

	/* ---------------------------------------------------------------- unsaved changes */

	/* The form as it would be posted, compared with the page load: also sees what fires no "change" (color picker,
	 * rows added, deleted or dragged by admin-lists.js), and ignores the controls that post nothing (preview
	 * devices, the anti-bot and webhook test buttons, the provider widget outside the form). Taken once every
	 * footer script has run. */
	var saved = null;
	var submitting = false;
	$( function () {
		saved = $form.serialize();
	} );
	$form.on( 'submit', function () {
		submitting = true;
	} );
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( submitting || null === saved || $form.serialize() === saved ) {
			return;
		}
		event.preventDefault();
		event.returnValue = ''; // Older browsers only show the prompt when it is set.
	} );
}( window.jQuery, window.wp && window.wp.apiFetch ) );
