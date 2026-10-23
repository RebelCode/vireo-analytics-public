/*
 * Vireo Analytics — importer progress driver.
 *
 * Loops over the shared vireo_import_chunk AJAX endpoint until { done: true }.
 * Updates a progress bar + label element and handles cancel / errors.
 *
 * Two flows:
 *   1. Koko: autoStart=false, click "Start import" → begin loop immediately.
 *   2. Jetpack: autoStart=true when the page loads with a verified ?run=
 *      (server has already saved params). Form submit goes to admin-post.php.
 *
 * All DOM writes go through textContent / createElement — no innerHTML.
 * The security review hook will reject innerHTML.
 */
( function () {
	'use strict';

	function pickConfig() {
		if ( ! window.vireoImportConfig ) return null;
		// Pick the config whose form element actually exists on this page.
		var keys = Object.keys( window.vireoImportConfig );
		for ( var i = 0; i < keys.length; i++ ) {
			var cfg = window.vireoImportConfig[ keys[ i ] ];
			if ( cfg && cfg.formId && document.getElementById( cfg.formId ) ) {
				return cfg;
			}
		}
		return null;
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	function setProgress( cfg, processed, total, label ) {
		var pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 0;
		var fill = byId( cfg.progressFillId );
		var pctEl = byId( cfg.progressPctId );
		var labelEl = byId( cfg.progressLabelId );
		var bar = byId( cfg.progressBarId );
		if ( fill ) fill.style.width = pct + '%';
		if ( pctEl ) pctEl.textContent = pct + '%';
		if ( labelEl ) labelEl.textContent = label || '';
		// A width alone tells a screen reader nothing; aria-valuenow is what it
		// reads, so it has to move with the fill.
		if ( bar ) {
			bar.setAttribute( 'aria-valuenow', String( pct ) );
			bar.setAttribute( 'aria-valuetext', pct + '% ' + ( label || '' ) );
		}
	}

	function showResult( cfg, kind, message ) {
		var el = byId( cfg.resultId );
		if ( ! el ) return;
		el.hidden = false;
		// Clear existing children (safer than innerHTML = '').
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		el.className = 'va-import-result va-import-result--' + kind;
		// An error has to interrupt; a success can wait for a pause.
		el.setAttribute( 'role', 'error' === kind ? 'alert' : 'status' );
		el.setAttribute( 'aria-live', 'error' === kind ? 'assertive' : 'polite' );
		var p = document.createElement( 'p' );
		p.textContent = message;
		el.appendChild( p );
		// Send focus to the outcome so a keyboard or screen-reader user lands on
		// it rather than back at the top of the form.
		if ( 'function' === typeof el.focus ) {
			el.setAttribute( 'tabindex', '-1' );
			el.focus();
		}
	}

	function showFooter( cfg ) {
		if ( ! cfg.footerId ) return;
		var el = byId( cfg.footerId );
		if ( el ) el.hidden = false;
	}

	// Once a run finishes, the warning and the start button describe a step
	// that is over. Leaving them up read as "it didn't work, try again".
	function hideBefore() {
		var els = document.querySelectorAll( '[data-va-import-before]' );
		for ( var i = 0; i < els.length; i++ ) {
			els[ i ].hidden = true;
		}
	}

	// A source that has already been imported only offers a second run behind
	// a checkbox, and the button stays off until it is ticked.
	function confirmBox( cfg ) {
		var form = byId( cfg.formId );
		return form ? form.querySelector( '[data-va-import-confirm]' ) : null;
	}

	function setRunning( cfg, isRunning ) {
		var start = byId( cfg.startBtnId );
		var cancel = byId( cfg.cancelBtnId );
		var progress = byId( cfg.progressId );
		var confirm = confirmBox( cfg );
		if ( start ) start.disabled = isRunning || ( confirm && ! confirm.checked );
		if ( cancel ) {
			cancel.hidden = ! isRunning;
			cancel.disabled = false;
		}
		if ( progress ) progress.hidden = ! isRunning;
	}

	function collectFormParams( cfg ) {
		var form = byId( cfg.formId );
		var params = {};
		if ( ! form ) return params;
		// Only pick up checkbox flags here — credentials for Jetpack are
		// stored server-side via the admin-post flow.
		var checkboxes = form.querySelectorAll( 'input[type="checkbox"]' );
		for ( var i = 0; i < checkboxes.length; i++ ) {
			var cb = checkboxes[ i ];
			if ( cb.name ) {
				params[ cb.name ] = cb.checked ? 1 : 0;
			}
		}
		return params;
	}

	/**
	 * Issue a single POST to the chunk endpoint.
	 */
	function requestChunk( cfg, offset, params, signal ) {
		var body = new URLSearchParams();
		body.append( 'action', 'vireo_import_chunk' );
		body.append( 'nonce', window.vireoImport.nonce );
		body.append( 'importer', cfg.slug );
		// Per-run state on the server is keyed by this, so a second tab or an
		// old one can't replay into or reset someone else's run.
		body.append( 'run', cfg.run || '' );
		body.append( 'offset', String( offset ) );
		body.append( 'params', JSON.stringify( params || {} ) );

		return fetch( window.vireoImport.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
			signal: signal
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				return { ok: res.ok, json: json };
			} );
		} );
	}

	function runLoop( cfg ) {
		setRunning( cfg, true );
		setProgress( cfg, 0, 1, window.vireoImport.i18n.starting );

		var params = collectFormParams( cfg );
		var offset = 0;
		var cancelled = false;
		var abortCtl = typeof AbortController !== 'undefined' ? new AbortController() : null;

		var cancelBtn = byId( cfg.cancelBtnId );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				cancelled = true;
				if ( abortCtl ) abortCtl.abort();
			}, { once: true } );
		}

		function step() {
			if ( cancelled ) {
				setRunning( cfg, false );
				showResult( cfg, 'warn', window.vireoImport.i18n.cancelled );
				return;
			}

			requestChunk( cfg, offset, params, abortCtl ? abortCtl.signal : undefined )
				.then( function ( res ) {
					if ( ! res.json || ! res.json.success ) {
						var msg = res.json && res.json.data && res.json.data.message
							? res.json.data.message
							: window.vireoImport.i18n.errorBox;
						throw new Error( msg );
					}

					var data = res.json.data;

					// The server sends a failed chunk as an error envelope, but
					// an older build shipped it as a success carrying an error
					// key, which this loop read as a finished import. Belt and
					// braces: a payload with an error is never a completion.
					if ( data && data.error ) {
						throw new Error( data.error );
					}

					setProgress( cfg, data.next_offset || 0, data.total || 1, data.label || '' );

					if ( data.done ) {
						setRunning( cfg, false );
						setProgress( cfg, data.total || 1, data.total || 1, data.label || '' );
						// `processed` is a running offset for most importers, so
						// adding it up overstated the rows. The server says what
						// was actually written.
						hideBefore();
						showResult( cfg, 'ok', data.summary
							? window.vireoImport.i18n.complete + ' ' + data.summary
							: window.vireoImport.i18n.complete
						);
						showFooter( cfg );
						return;
					}

					offset = data.next_offset || ( offset + 1 );
					// Yield to the browser before the next tick — keeps the UI
					// responsive and stops us from stacking requests.
					setTimeout( step, 0 );
				} )
				.catch( function ( err ) {
					setRunning( cfg, false );
					if ( cancelled ) {
						showResult( cfg, 'warn', window.vireoImport.i18n.cancelled );
					} else {
						showResult( cfg, 'error', window.vireoImport.i18n.errorBox + ' ' + err.message );
					}
				} );
		}

		step();
	}

	function init() {
		var cfg = pickConfig();
		if ( ! cfg ) return;

		var form = byId( cfg.formId );
		var startBtn = byId( cfg.startBtnId );
		var confirm = confirmBox( cfg );

		if ( confirm && startBtn ) {
			confirm.addEventListener( 'change', function () {
				startBtn.disabled = ! confirm.checked;
			} );
		}

		if ( cfg.serverSubmit ) {
			// Jetpack: form posts to admin-post.php. No JS override needed.
			// After redirect with autoStart=true, kick off the loop.
			if ( cfg.autoStart ) {
				runLoop( cfg );
			}
			return;
		}

		// Koko: hijack the submit.
		if ( form ) {
			form.addEventListener( 'submit', function ( ev ) {
				ev.preventDefault();
				runLoop( cfg );
			} );
		} else if ( startBtn ) {
			startBtn.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				runLoop( cfg );
			} );
		}
	}

	// Restoring a backup: the Replace warning and its confirmation only show
	// once Replace is picked. The server refuses Replace without the tick.
	function wireBackupModes() {
		var modes = document.querySelectorAll( '[data-va-backup-mode]' );
		var warning = document.querySelector( '[data-va-backup-replace]' );
		if ( ! modes.length || ! warning ) return;
		var update = function () {
			var replace = false;
			for ( var i = 0; i < modes.length; i++ ) {
				if ( modes[ i ].checked && 'replace' === modes[ i ].value ) replace = true;
			}
			warning.hidden = ! replace;
		};
		for ( var j = 0; j < modes.length; j++ ) {
			modes[ j ].addEventListener( 'change', update );
		}
		update();
	}

	// Onboarding banner dismissal on the Overview page — a tiny helper that
	// lives in the same JS file so we don't need a separate asset.
	function wireOnboardingDismiss() {
		if ( ! window.vireoImport || ! window.vireoImport.ajaxUrl ) return;
		var banners = document.querySelectorAll( '[data-va-onboarding]' );
		if ( ! banners.length ) return;

		var dismissButtons = document.querySelectorAll( '[data-va-onboarding-dismiss]' );
		for ( var i = 0; i < dismissButtons.length; i++ ) {
			dismissButtons[ i ].addEventListener( 'click', function () {
				var body = new URLSearchParams();
				body.append( 'action', 'vireo_import_dismiss_onboarding' );
				body.append( 'nonce', window.vireoImport.nonce );
				fetch( window.vireoImport.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				} );
				for ( var j = 0; j < banners.length; j++ ) {
					banners[ j ].style.display = 'none';
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
			wireOnboardingDismiss();
			wireBackupModes();
		} );
	} else {
		init();
		wireOnboardingDismiss();
		wireBackupModes();
	}
} )();
