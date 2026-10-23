/*
 * Vireo Analytics — Settings page bot-filter tester.
 *
 * Reads window.vireoSettingsPage (set via wp_add_inline_script before this
 * file loads) for ajaxUrl + nonce, then posts to the vireo_test_bot_filter
 * AJAX endpoint.
 */
( function () {
	'use strict';

	var __ = window.wp.i18n.__;

	// Copy buttons next to the MCP snippets. The clipboard API needs a secure
	// context, which wp-admin over plain http on a LAN is not, so fall back to
	// selecting the text and asking the browser to copy that.
	var copyButtons = document.querySelectorAll( '[data-va-copy]' );
	for ( var c = 0; c < copyButtons.length; c++ ) {
		( function ( btn ) {
			var target = document.querySelector( btn.getAttribute( 'data-va-copy' ) );
			if ( ! target ) {
				return;
			}
			var label = btn.textContent;
			var done = function () {
				btn.textContent = __( 'Copied', 'vireo-analytics' );
				window.setTimeout( function () {
					btn.textContent = label;
				}, 1600 );
			};
			btn.addEventListener( 'click', function () {
				var text = target.textContent;
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( done );
					return;
				}
				var area = document.createElement( 'textarea' );
				area.value = text;
				area.setAttribute( 'readonly', '' );
				area.style.position = 'fixed';
				area.style.left = '-9999px';
				document.body.appendChild( area );
				area.select();
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( e ) {}
				document.body.removeChild( area );
			} );
		} )( copyButtons[ c ] );
	}

	// Removing a connected site: the button only enables once the site's
	// name has been typed back. The server checks the same thing.
	var removeForms = document.querySelectorAll( '[data-va-confirm-remove]' );
	for ( var f = 0; f < removeForms.length; f++ ) {
		( function ( form ) {
			var open   = form.querySelector( '[data-va-confirm-open]' );
			var panel  = form.querySelector( '[data-va-confirm-panel]' );
			var name   = form.querySelector( '[data-va-confirm-name]' );
			var submit = form.querySelector( '[data-va-confirm-submit]' );
			var cancel = form.querySelector( '[data-va-confirm-cancel]' );
			if ( ! open || ! panel || ! name || ! submit ) {
				return;
			}
			open.addEventListener( 'click', function () {
				panel.hidden = false;
				open.hidden = true;
				name.focus();
			} );
			name.addEventListener( 'input', function () {
				submit.disabled = name.value.trim() !== name.getAttribute( 'data-va-confirm-name' );
			} );
			if ( cancel ) {
				cancel.addEventListener( 'click', function () {
					panel.hidden = true;
					open.hidden = false;
					name.value = '';
					submit.disabled = true;
				} );
			}
		} )( removeForms[ f ] );
	}

	// The weekday only applies to a weekly digest; the note under the
	// frequency select follows the choice.
	var frequency = document.querySelector( '[data-va-email-frequency]' );
	if ( frequency ) {
		var dayField = document.querySelector( '[data-va-email-day]' );
		var notes    = document.querySelectorAll( '[data-va-frequency-note]' );
		frequency.addEventListener( 'change', function () {
			if ( dayField ) {
				dayField.hidden = 'weekly' !== frequency.value;
			}
			for ( var n = 0; n < notes.length; n++ ) {
				notes[ n ].hidden = notes[ n ].getAttribute( 'data-va-frequency-note' ) !== frequency.value;
			}
		} );
	}

	var cfg = window.vireoSettingsPage || null;
	if ( ! cfg || ! cfg.ajaxUrl || ! cfg.nonce ) {
		return;
	}

	var btn     = document.getElementById( 'va-bot-test-run' );
	var ua      = document.getElementById( 'va-bot-test-ua' );
	var out     = document.getElementById( 'va-bot-test-result' );
	var verdict = document.getElementById( 'va-bot-test-verdict' );
	var reason  = document.getElementById( 'va-bot-test-reason' );

	if ( ! btn || ! ua || ! out ) {
		return;
	}

	function show( classes, verdictText, reasonText ) {
		out.className = 'va-bot-test-result ' + classes;
		verdict.textContent = verdictText || '';
		reason.textContent = reasonText || '';
		out.hidden = false;
	}

	btn.addEventListener( 'click', function () {
		var body = new URLSearchParams();
		body.set( 'action', 'vireo_test_bot_filter' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'ua', ua.value || '' );

		btn.disabled = true;
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				btn.disabled = false;
				if ( ! resp || ! resp.success || ! resp.data ) {
					show( 'va-bot-test-error', ( resp && resp.data && resp.data.message ) || __( 'Request failed.', 'vireo-analytics' ), '' );
					return;
				}
				var d = resp.data;
				show( d.is_bot ? 'va-bot-test-blocked' : 'va-bot-test-ok', d.verdict || '', d.reason || '' );
			} )
			.catch( function () {
				btn.disabled = false;
				show( 'va-bot-test-error', __( 'Network error — try again.', 'vireo-analytics' ), '' );
			} );
	} );
} )();
