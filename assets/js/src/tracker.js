/*
 * Vireo Analytics — front-end tracker beacon.
 *
 * Reads window.vireoTracker (set by wp_add_inline_script() before this file
 * loads) and fires one pageview beacon via navigator.sendBeacon() with a
 * fetch keepalive fallback. The visibilitychange + load pair covers
 * bfcache / prerender / DCL-missing visitors; a single-shot flag stops
 * double-firing.
 *
 * The `window.vireo` global is a queue stub that lets calling code do
 * `vireo('event', name, value)` before this script has booted. The stub
 * is replaced by a live function once the body runs; queued calls are
 * replayed against the live function.
 *
 * Auto-event listeners (downloads / forms / mailto / tel / outbound)
 * read window.vireoAutoEvents (also set via wp_add_inline_script() before
 * load). Each handler is gated by its corresponding flag.
 *
 * The endpoint action name (`vireo_collect`) is routed through admin-ajax;
 * the server hook registrations are keyed on it.
 *
 * Headless front ends (Astro, Next.js) load this same file from another
 * origin. There is no inline config from WordPress, so the script tag carries
 * `data-endpoint` instead, the path is read from location at send time, and
 * the post ID, when the page knows it, from `<meta name="vireo:post-id">`.
 * Client-side navigations count once each: `astro:page-load` after an Astro
 * view transition, and with `data-spa="history"` a pushState or popstate.
 *
 * A connected site (its own numbers, no post matching) uses the same tag with
 * `data-site` carrying the key WordPress issued for it; every beacon then
 * carries it as `site`. The endpoint only accepts it from that site's origin.
 */
( function () {
	'use strict';

	var cfg = window.vireoTracker || null;
	var script = document.currentScript || null;
	if ( ! cfg && script && script.getAttribute( 'data-endpoint' ) ) {
		cfg = {
			url: script.getAttribute( 'data-endpoint' ),
			spa: script.getAttribute( 'data-spa' ) || ''
		};
		if ( script.getAttribute( 'data-post-id' ) ) {
			cfg.postId = script.getAttribute( 'data-post-id' );
		}
		if ( script.getAttribute( 'data-site' ) ) {
			cfg.site = script.getAttribute( 'data-site' );
		}
	}
	if ( ! cfg || ! cfg.url ) {
		return;
	}

	// No path from the server means the page itself has to say where it is,
	// and that can change under a client-side router.
	var dynamic = ! cfg.path;

	// Do Not Track / Global Privacy Control. The server declines to emit this
	// script for opted-out visitors, but a page cached for someone else still
	// carries it, so check here too and never send the beacon. The endpoint
	// rejects it regardless; this just avoids the pointless request.
	if (
		navigator.globalPrivacyControl === true ||
		navigator.doNotTrack === '1' ||
		window.doNotTrack === '1' ||
		navigator.msDoNotTrack === '1'
	) {
		return;
	}

	// Browser automation. The server-side user-agent filter only catches bots
	// that declare themselves, and a headless browser driving a real engine can
	// send whatever user-agent it likes. These flags are set by the automation
	// frameworks, so they survive a spoofed user-agent.
	if (
		navigator.webdriver === true ||
		window._phantom ||
		window.__nightmare ||
		window.Cypress ||
		window.__playwright ||
		window.__puppeteer_evaluation_script__
	) {
		return;
	}

	// --- consent ---------------------------------------------------------
	//
	// Only engaged when the site has asked for it; cfg.consent is absent
	// otherwise and none of this runs. Anything measured before consent
	// arrives is held rather than dropped, so granting it mid-visit does not
	// lose the pageview that prompted the banner in the first place.
	var pending = [];

	function consentGranted() {
		if ( ! cfg.consent ) {
			return true;
		}
		try {
			return typeof window.wp_has_consent === 'function' && window.wp_has_consent( cfg.consent );
		} catch ( e ) {
			return false;
		}
	}

	if ( cfg.consent ) {
		document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
			var detail = e && e.detail ? e.detail : {};
			if ( detail[ cfg.consent ] !== 'allow' || ! consentGranted() ) {
				return;
			}
			var held = pending;
			pending = [];
			for ( var i = 0; i < held.length; i++ ) {
				rawSend( held[ i ] );
			}
		} );
	}

	function send( body ) {
		if ( ! consentGranted() ) {
			// Cap it. A visitor who never answers the banner should not
			// accumulate an unbounded queue over a long session.
			if ( pending.length < 20 ) {
				pending.push( body );
			}
			return;
		}
		rawSend( body );
	}

	function rawSend( body ) {
		try {
			if ( navigator.sendBeacon ) {
				var blob = new Blob( [ body ], { type: 'application/x-www-form-urlencoded' } );
				if ( navigator.sendBeacon( cfg.url, blob ) ) {
					return;
				}
			}
		} catch ( e ) {}
		try {
			fetch( cfg.url, {
				method: 'POST',
				credentials: 'omit',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body,
				keepalive: true
			} );
		} catch ( e ) {}
	}

	function currentPath() {
		return dynamic ? ( location.pathname || '/' ) : cfg.path;
	}

	// The post the front-end page says it is. Read at send time because a
	// client-side navigation swaps the <head> along with the page.
	function currentPostId() {
		if ( ! dynamic ) {
			return cfg.postId || '';
		}
		var meta = document.querySelector( 'meta[name="vireo:post-id"]' );
		if ( meta && meta.getAttribute( 'content' ) ) {
			return meta.getAttribute( 'content' );
		}
		return cfg.postId || '';
	}

	// A click from one page of this site to another is not a referral. The
	// server drops its own hosts too, but a referrer that never leaves the
	// browser is one less thing to store, and a headless front end's host is
	// one the server may not know. www. is ignored on both sides, so a site
	// served with and without it still counts its own clicks as internal.
	function sameSite( ref ) {
		try {
			var strip = function ( h ) { return String( h || '' ).toLowerCase().replace( /^www\./, '' ); };
			return strip( new URL( ref ).hostname ) === strip( location.hostname );
		} catch ( e ) {
			return false;
		}
	}

	function currentReferrer( navigated ) {
		if ( navigated ) {
			return '';
		}
		var ref = document.referrer || '';
		if ( ref && sameSite( ref ) ) {
			return '';
		}
		return ref;
	}

	var lastPath = null;
	var lastPostId = null;

	// --- time on page ----------------------------------------------------
	//
	// Active time only: the clock runs while the tab is visible and stops
	// while it is hidden, so a page left in a background tab overnight adds
	// nothing. Reported when the page is hidden or left, as the seconds added
	// since the last report, so a reader who switches away and comes back is
	// still counted in full. `n=1` marks a view's first report, which is what
	// the average divides by. Half an hour at most per view.
	var timePath = null;
	var activeMs = 0;
	var since = 0;
	var reported = 0;

	function accrue() {
		var now = Date.now();
		if ( since ) {
			activeMs += now - since;
		}
		since = 'visible' === document.visibilityState ? now : 0;
	}

	function reportTime() {
		accrue();
		var secs = Math.min( 1800, Math.round( activeMs / 1000 ) );
		if ( ! timePath || secs <= reported ) {
			return;
		}
		var b = new URLSearchParams();
		b.set( 'action', 'vireo_collect' );
		b.set( 'kind', 'time' );
		b.set( 'path', timePath );
		b.set( 'seconds', String( secs - reported ) );
		b.set( 'n', reported ? '0' : '1' );
		if ( cfg.site ) {
			b.set( 'site', cfg.site );
		}
		reported = secs;
		send( b.toString() );
	}

	// A new view, after a client-side navigation as much as on load: the
	// previous page's time is reported and the clock starts again.
	function startTime( path ) {
		reportTime();
		timePath = path;
		activeMs = 0;
		reported = 0;
		since = 0;
		accrue();
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			reportTime();
		} else {
			accrue();
		}
	} );
	window.addEventListener( 'pagehide', reportTime );
	// Back from the back-forward cache: the clock was stopped when it left.
	window.addEventListener( 'pageshow', accrue );

	function pageview( navigated ) {
		var path = currentPath();
		startTime( path );
		var declared = currentPostId();
		var postId = declared;
		// After a client-side navigation a post ID equal to the previous page's
		// is more likely a <head> the router has not swapped yet than two pages
		// for one post. Leave it out; the server resolves the path on its own.
		if ( navigated && postId && postId === lastPostId ) {
			postId = '';
		}
		lastPath = path;
		lastPostId = declared;

		var b = new URLSearchParams();
		b.set( 'action', 'vireo_collect' );
		b.set( 'kind', 'pageview' );
		b.set( 'path', path );
		if ( postId && ! cfg.site ) {
			b.set( 'post_id', postId );
		}
		if ( cfg.site ) {
			b.set( 'site', cfg.site );
		}
		b.set( 'referrer', currentReferrer( navigated ) );
		send( b.toString() );
	}

	// One pageview per address. Both the Astro event and a history change can
	// announce the same navigation, and the initial load announces itself
	// twice (load, then astro:page-load).
	function navigatedTo() {
		if ( ! dynamic ) {
			return;
		}
		if ( null === lastPath ) {
			// Nothing counted yet, so this is the first load announcing itself.
			firePv();
			return;
		}
		if ( ( location.pathname || '/' ) === lastPath ) {
			return;
		}
		pageview( true );
	}

	function event( name, value ) {
		if ( ! name ) {
			return;
		}
		var b = new URLSearchParams();
		b.set( 'action', 'vireo_collect' );
		b.set( 'kind', 'event' );
		b.set( 'name', String( name ) );
		if ( value !== undefined && value !== null ) {
			b.set( 'value', String( value ) );
		}
		if ( cfg.site ) {
			b.set( 'site', cfg.site );
		}
		send( b.toString() );
	}

	// Replace the queue stub with the live dispatcher and drain any queued
	// calls placed before this script loaded.
	var live = function ( kind, a, b ) {
		if ( 'event' === kind ) {
			event( a, b );
		} else if ( 'pageview' === kind ) {
			// A front end with its own router calls this after it has
			// rendered the new page; counted as a navigation.
			pageview( dynamic && null !== lastPath );
		}
	};
	var queue = ( window.vireo && window.vireo.q ) || [];
	window.vireo = live;
	for ( var i = 0; i < queue.length; i++ ) {
		try {
			live.apply( null, queue[ i ] );
		} catch ( e ) {}
	}

	// Fire the pageview on visibility (so bfcache / prerender pages count too)
	// and again on load. Single-shot flag prevents double-firing.
	var fired = false;
	function firePv() {
		if ( fired ) {
			return;
		}
		fired = true;
		pageview();
	}

	if ( 'visible' === document.visibilityState ) {
		firePv();
	} else {
		document.addEventListener( 'visibilitychange', function () {
			if ( 'visible' === document.visibilityState ) {
				firePv();
			}
		}, { once: false } );
	}
	window.addEventListener( 'load', firePv, { once: true } );

	if ( dynamic ) {
		// Astro's <ClientRouter /> fires this after every swap, and once on the
		// initial load, which navigatedTo() recognises as already counted.
		document.addEventListener( 'astro:page-load', navigatedTo );

		if ( 'history' === cfg.spa ) {
			// Routers that drive history directly (Next.js, SvelteKit, a hand
			// rolled one). pushState runs before the new page renders, so the
			// read of the path and <head> waits a tick; popstate covers back
			// and forward.
			var pushState = history.pushState;
			history.pushState = function () {
				var result = pushState.apply( this, arguments );
				setTimeout( navigatedTo, 50 );
				return result;
			};
			window.addEventListener( 'popstate', function () {
				setTimeout( navigatedTo, 50 );
			} );
		}
	}

	// ---- Auto-event listeners ----
	var flags = window.vireoAutoEvents || null;
	if ( ! flags ) {
		return;
	}

	function fire( name, value ) {
		try {
			window.vireo( 'event', name, value );
		} catch ( e ) {}
	}

	var host = location.host;
	var dlRe = flags.exts ? new RegExp( '\\.(' + flags.exts + ')(\\?|#|$)', 'i' ) : null;

	if ( flags.dl || flags.ob || flags.ml || flags.tl ) {
		document.addEventListener( 'click', function ( e ) {
			var a = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
			if ( ! a ) {
				return;
			}
			var href = a.getAttribute( 'href' ) || '';
			if ( ! href || '#' === href.charAt( 0 ) ) {
				return;
			}

			if ( flags.ml && 0 === href.toLowerCase().indexOf( 'mailto:' ) ) {
				// Mask local-part so we don't log raw PII.
				var em = href.slice( 7 ).split( '?' )[ 0 ];
				var at = em.indexOf( '@' );
				fire( 'mailto_click', at > 0 ? em.slice( at ) : em );
				return;
			}
			if ( flags.tl && 0 === href.toLowerCase().indexOf( 'tel:' ) ) {
				fire( 'tel_click', href.slice( 4 ) );
				return;
			}

			var url;
			try {
				url = new URL( href, location.href );
			} catch ( er ) {
				return;
			}
			var external = url.host && url.host !== host;

			if ( flags.dl && dlRe && dlRe.test( url.pathname || '' ) ) {
				var name = ( url.pathname || '' ).split( '/' ).pop() || url.pathname;
				fire( 'download', name );
				return;
			}
			if ( flags.ob && external ) {
				fire( 'outbound', url.host );
			}
		}, true );
	}

	// WP RSS Aggregator and Spotlight. Their content sends readers away from
	// the page (an aggregated item to its original article, an Instagram tile
	// to the post), so the click is the only trace it leaves here.
	if ( flags.wr || flags.sl ) {
		var page = ( location.pathname || '/' ).slice( 0, 100 );
		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( ! t || ! t.closest ) {
				return;
			}

			if ( flags.wr ) {
				var item = t.closest( '.wpra-item, .feed-item' );
				var link = item ? t.closest( 'a[href]' ) : null;
				if ( link ) {
					var to;
					try {
						to = new URL( link.getAttribute( 'href' ) || '', location.href );
					} catch ( er ) {
						return;
					}
					if ( to.host && to.host !== host ) {
						// The source's name as WP RSS Aggregator printed it: the
						// link inside .feed-source, or its text minus the prefix.
						var src = item.querySelector( '.feed-source' );
						var name = '';
						if ( src ) {
							var srcLink = src.querySelector( 'a' );
							name = ( srcLink ? srcLink.textContent : src.textContent.replace( /^[^:]{0,30}:\s*/, '' ) ) || '';
						}
						fire( 'wpra_click', ( name.trim() || to.host ).slice( 0, 100 ) );
					}
					return;
				}
			}

			if ( flags.sl ) {
				// The popup is rendered outside the feed, at the end of <body>.
				// Its link to Instagram is a plain button whose icon and label
				// carry the class, so a click on its padding lands on the button.
				var btn = t.closest( 'a, button' );
				if ( t.closest( '[class*="MediaPopupBox__cta"]' ) || ( btn && btn.querySelector( '[class*="MediaPopupBox__cta"]' ) ) ) {
					fire( 'spotlight_popup_link', page );
					return;
				}
				if ( ! t.closest( '.spotlight-instagram-feed, .spotlight-instagram-app' ) ) {
					return;
				}
				if ( t.closest( '.FollowButton' ) ) {
					fire( 'spotlight_follow', page );
				} else if ( t.closest( '.LoadMoreButton' ) ) {
					fire( 'spotlight_load_more', page );
				} else if ( t.closest( '.MediaTile__root' ) ) {
					fire( 'spotlight_post_click', page );
				}
			}
		}, true );
	}

	if ( flags.fm ) {
		// Forms a plugin on the server reports as `form_success` once they
		// have been accepted; counting their submit event too would add every
		// attempt that failed validation beside the one that went through.
		var served = ( flags.fs && flags.fs.length ) ? flags.fs.join( ',' ) : '';
		document.addEventListener( 'submit', function ( e ) {
			var form = e.target;
			if ( ! form || 'FORM' !== form.tagName ) {
				return;
			}
			if ( served && form.matches && form.matches( served ) ) {
				return;
			}
			var id = form.id || form.getAttribute( 'name' ) || form.getAttribute( 'action' ) || 'form';
			fire( 'form_submit', String( id ).slice( 0, 100 ) );
		}, true );
	}
} )();
