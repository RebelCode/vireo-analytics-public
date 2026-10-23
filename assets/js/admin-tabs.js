/*
 * Vireo Analytics — tabs for the long admin screens (Settings, Overview).
 *
 * The server renders the active tab from `?tab=`, so a reload or a shared link
 * opens where it should, and the tab links are real links that still work with
 * JS off. This only saves the round trip: it swaps panels in place and keeps
 * the URL, the form referers and any `data-va-tab-carry` links pointing at the
 * tab on screen, so saving a form or changing the date range comes back to it.
 *
 * Panels name the tabs they belong to in `data-va-tab-panel`, space separated,
 * because one tab can be split around a form it does not own.
 *
 * Old deep links (`#vireo-gsc`, `#va-geo`) carry no tab. When the hash names an
 * element inside a hidden panel, that panel's tab is opened first.
 */
( function () {
	'use strict';

	function panelTabs( panel ) {
		return ( panel.getAttribute( 'data-va-tab-panel' ) || '' ).split( /\s+/ ).filter( Boolean );
	}

	function stripParams( url, nav ) {
		( nav.getAttribute( 'data-va-tabs-strip' ) || '' ).split( /\s+/ ).forEach( function ( key ) {
			if ( key ) url.searchParams.delete( key );
		} );
		return url;
	}

	function activate( nav, id, opts ) {
		opts = opts || {};
		var tabs = [].slice.call( nav.querySelectorAll( '[data-va-tab]' ) );
		var known = tabs.some( function ( t ) { return t.getAttribute( 'data-va-tab' ) === id; } );
		if ( ! known ) return false;

		var fallback = nav.getAttribute( 'data-va-tabs-default' ) || '';
		var scope = nav.closest( '[data-va-tabs-scope]' ) || document;

		tabs.forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-va-tab' ) === id;
			tab.classList.toggle( 'is-active', on );
			tab.classList.toggle( 'nav-tab-active', on );
			tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', on ? '0' : '-1' );
			if ( on && opts.focus ) tab.focus();
		} );

		scope.querySelectorAll( '[data-va-tab-panel]' ).forEach( function ( panel ) {
			panel.hidden = panelTabs( panel ).indexOf( id ) === -1;
		} );

		var url;
		try {
			url = new URL( window.location.href );
		} catch ( e ) {
			return true;
		}
		if ( id === fallback ) {
			url.searchParams.delete( 'tab' );
		} else {
			url.searchParams.set( 'tab', id );
		}
		if ( ! opts.keepHash ) url.hash = '';
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( window.history.state, '', url.toString() );
		}

		// Forms post back to wherever their referer field says. Left alone, a
		// save from the Email tab would land on whichever tab the page loaded on.
		var referer = stripParams( new URL( url.toString() ), nav );
		referer.hash = '';
		var refererValue = referer.pathname + referer.search;
		document.querySelectorAll( 'input[name="_wp_http_referer"]' ).forEach( function ( input ) {
			input.value = refererValue;
		} );

		scope.querySelectorAll( '[data-va-tab-carry]' ).forEach( function ( el ) {
			if ( 'INPUT' === el.tagName ) {
				el.value = id;
				el.disabled = id === fallback;
				return;
			}
			var href = el.getAttribute( 'href' );
			if ( ! href ) return;
			try {
				var u = new URL( href, window.location.href );
				if ( id === fallback ) {
					u.searchParams.delete( 'tab' );
				} else {
					u.searchParams.set( 'tab', id );
				}
				el.setAttribute( 'href', u.toString() );
			} catch ( e ) { /* leave the link as rendered */ }
		} );

		// Anything drawn at its container's width (the traffic chart) was drawn
		// at zero while hidden. It listens for resize already.
		window.dispatchEvent( new Event( 'resize' ) );
		nav.dispatchEvent( new CustomEvent( 'vireo:tab', { bubbles: true, detail: { tab: id } } ) );
		return true;
	}

	function openHashTarget( nav ) {
		var hash = window.location.hash;
		if ( ! hash || hash.length < 2 ) return;
		var target;
		try {
			target = document.getElementById( decodeURIComponent( hash.slice( 1 ) ) );
		} catch ( e ) {
			return;
		}
		if ( ! target ) return;
		var panel = target.closest( '[data-va-tab-panel]' );
		if ( ! panel || ! panel.hidden ) return;
		var first = panelTabs( panel )[ 0 ];
		if ( first && activate( nav, first, { keepHash: true } ) ) {
			target.scrollIntoView();
		}
	}

	function wire( root ) {
		( root || document ).querySelectorAll( '[data-va-tabs]' ).forEach( function ( nav ) {
			if ( nav.dataset.vaTabsBound ) return;
			nav.dataset.vaTabsBound = '1';

			var tabs = [].slice.call( nav.querySelectorAll( '[data-va-tab]' ) );

			nav.addEventListener( 'click', function ( ev ) {
				if ( ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey ) return;
				var tab = ev.target.closest( '[data-va-tab]' );
				if ( ! tab ) return;
				ev.preventDefault();
				activate( nav, tab.getAttribute( 'data-va-tab' ) );
			} );

			nav.addEventListener( 'keydown', function ( ev ) {
				var tab = ev.target.closest( '[data-va-tab]' );
				if ( ! tab ) return;
				// Tabs are links, which Space doesn't press; a tab should.
				if ( ' ' === ev.key ) {
					ev.preventDefault();
					activate( nav, tab.getAttribute( 'data-va-tab' ), { focus: true } );
					return;
				}
				var step = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
				if ( ! step ) return;
				ev.preventDefault();
				var next = tabs[ ( tabs.indexOf( tab ) + step + tabs.length ) % tabs.length ];
				activate( nav, next.getAttribute( 'data-va-tab' ), { focus: true } );
			} );

			// On a phone the bar scrolls sideways; start it at the open tab so a
			// page loaded on the last tab doesn't hide which one is showing.
			var current = nav.querySelector( '[data-va-tab][aria-selected="true"]' );
			if ( current && nav.scrollWidth > nav.clientWidth ) {
				nav.scrollLeft += current.getBoundingClientRect().left - nav.getBoundingClientRect().left - 12;
			}

			openHashTarget( nav );
		} );
	}

	window.addEventListener( 'hashchange', function () {
		document.querySelectorAll( '[data-va-tabs]' ).forEach( openHashTarget );
	} );

	window.vireoTabs = { wire: wire, activate: activate };

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { wire( document ); } );
	} else {
		wire( document );
	}
} )();
