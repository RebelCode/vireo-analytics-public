/**
 * Core Web Vitals collection.
 *
 * Measures what Google actually ranks on, from the people actually visiting:
 * largest contentful paint, cumulative layout shift, and interaction to next
 * paint. Lab tools tell you what one run on one machine did; this tells you
 * what your readers got.
 *
 * No library. The three metrics come straight from PerformanceObserver, which
 * every browser that supports them exposes natively, and pulling in a bundle
 * to wrap three observers would cost more than it measures.
 *
 * One beacon per visit, sent when the page goes away, because none of these
 * values are final until then: LCP can be superseded, CLS accumulates, and the
 * worst interaction may be the last one.
 *
 * On a connected site there is no inline config from WordPress, so the script
 * tag carries `data-endpoint` and `data-site` instead, the same way the
 * tracker's does, and the path is read from location when the beacon goes.
 */
( function () {
	if ( typeof PerformanceObserver === 'undefined' ) {
		return;
	}

	var cfg = window.vireoVitals || null;
	var script = document.currentScript || null;
	if ( ! cfg && script && script.getAttribute( 'data-endpoint' ) && script.getAttribute( 'data-site' ) ) {
		cfg = {
			url: script.getAttribute( 'data-endpoint' ),
			site: script.getAttribute( 'data-site' )
		};
	}
	if ( ! cfg || ! cfg.url ) {
		return;
	}

	// Honour the same automation and opt-out checks the tracker makes. A
	// headless browser's timings are not a reader's timings.
	if (
		navigator.webdriver ||
		window._phantom ||
		window.__nightmare ||
		window.Cypress ||
		window.__playwright ||
		window.__puppeteer_evaluation_script__
	) {
		return;
	}
	if ( navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl ) {
		return;
	}

	var lcp = 0;
	var cls = 0;
	var inp = 0;
	var sent = false;

	function observe( type, handler, extra ) {
		try {
			var opts = { type: type, buffered: true };
			if ( extra ) {
				for ( var k in extra ) {
					opts[ k ] = extra[ k ];
				}
			}
			var po = new PerformanceObserver( function ( list ) {
				handler( list.getEntries() );
			} );
			po.observe( opts );
			return po;
		} catch ( e ) {
			return null;
		}
	}

	// LCP: the last reported candidate before the page is backgrounded wins.
	observe( 'largest-contentful-paint', function ( entries ) {
		var last = entries[ entries.length - 1 ];
		if ( last ) {
			lcp = last.startTime;
		}
	} );

	// CLS: the score is the worst one-second-gap, five-second-cap session
	// window, not the sum of every shift. Summing overstates it badly on long
	// pages, which would have us reporting a problem nobody has.
	var windowValue = 0;
	var windowFirst = 0;
	var windowLast = 0;
	observe( 'layout-shift', function ( entries ) {
		for ( var i = 0; i < entries.length; i++ ) {
			var entry = entries[ i ];
			if ( entry.hadRecentInput ) {
				continue;
			}
			if (
				windowValue &&
				entry.startTime - windowLast < 1000 &&
				entry.startTime - windowFirst < 5000
			) {
				windowValue += entry.value;
				windowLast = entry.startTime;
			} else {
				windowValue = entry.value;
				windowFirst = entry.startTime;
				windowLast = entry.startTime;
			}
			if ( windowValue > cls ) {
				cls = windowValue;
			}
		}
	} );

	// INP: the slowest interaction the visitor actually waited on.
	observe(
		'event',
		function ( entries ) {
			for ( var i = 0; i < entries.length; i++ ) {
				var d = entries[ i ].duration;
				if ( d > inp ) {
					inp = d;
				}
			}
		},
		{ durationThreshold: 40 }
	);

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

	function send() {
		if ( sent ) {
			return;
		}
		// Unlike the tracker there is nothing to queue: by the time this fires
		// the page is going away, and a banner answered after that is answered
		// too late to matter for this visit.
		if ( ! consentGranted() ) {
			return;
		}
		// Nothing measured means nothing worth a request.
		if ( ! lcp && ! cls && ! inp ) {
			return;
		}
		sent = true;

		var b = new URLSearchParams();
		b.set( 'action', 'vireo_collect' );
		b.set( 'kind', 'vitals' );
		b.set( 'path', cfg.path || location.pathname || '/' );
		if ( cfg.site ) {
			b.set( 'site', cfg.site );
		}
		b.set( 'lcp', String( Math.round( lcp ) ) );
		// Sent as thousandths so it survives as an integer.
		b.set( 'cls', String( Math.round( cls * 1000 ) ) );
		b.set( 'inp', String( Math.round( inp ) ) );

		var body = b.toString();
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

	// pagehide is the reliable one; a tab can be discarded without ever firing
	// unload. visibilitychange covers the phone being locked mid-read.
	window.addEventListener( 'pagehide', send, { capture: true } );
	document.addEventListener(
		'visibilitychange',
		function () {
			if ( document.visibilityState === 'hidden' ) {
				send();
			}
		},
		{ capture: true }
	);
} )();
