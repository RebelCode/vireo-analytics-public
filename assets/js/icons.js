/**
 * Inline SVG icon set for the Live Analytics dashboard.
 * All icons are 16x16 with currentColor strokes so CSS can recolor them.
 * The "hot" flame is the one intentional exception — it gets its own fill/stroke.
 *
 * Exposed globally as window.vireoIcons so the live dashboard script can consume
 * the strings via innerHTML on static SVG markup (never user content).
 */
(function () {
	'use strict';

	const S = 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none"';
	const W = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16"';

	window.vireoIcons = {
		// Source categories
		search: `<svg ${W} aria-hidden="true"><circle cx="7" cy="7" r="4.25" ${S}/><path d="M10.25 10.25 13 13" ${S}/></svg>`,
		social: `<svg ${W} aria-hidden="true"><path d="M13.25 9.25a2 2 0 0 1-2 2H6.5L3.75 13.5V4.75a2 2 0 0 1 2-2h5.5a2 2 0 0 1 2 2v4.5Z" ${S}/></svg>`,
		direct: `<svg ${W} aria-hidden="true"><circle cx="8" cy="8" r="5.25" ${S}/><path d="M2.75 8h10.5M8 2.75c1.6 1.75 2.5 3.85 2.5 5.25S9.6 11.5 8 13.25C6.4 11.5 5.5 9.4 5.5 8s.9-3.5 2.5-5.25Z" ${S}/></svg>`,
		referral: `<svg ${W} aria-hidden="true"><path d="M6.75 9.25a2.5 2.5 0 0 0 3.54 0l2-2a2.5 2.5 0 0 0-3.54-3.54l-.5.5" ${S}/><path d="M9.25 6.75a2.5 2.5 0 0 0-3.54 0l-2 2a2.5 2.5 0 0 0 3.54 3.54l.5-.5" ${S}/></svg>`,

		// Devices
		mobile: `<svg ${W} aria-hidden="true"><rect x="4.75" y="1.75" width="6.5" height="12.5" rx="1.5" ${S}/><path d="M7 12.5h2" ${S}/></svg>`,
		desktop: `<svg ${W} aria-hidden="true"><rect x="1.75" y="2.75" width="12.5" height="9" rx="1.25" ${S}/><path d="M5.5 14.25h5M8 11.75v2.5" ${S}/></svg>`,
		tablet: `<svg ${W} aria-hidden="true"><rect x="2.75" y="1.75" width="10.5" height="12.5" rx="1.5" ${S}/><path d="M7 12.25h2" ${S}/></svg>`,

		// Status & nav
		arrowUp: `<svg ${W} aria-hidden="true"><path d="M8 12.5V3.75M4 7.75 8 3.75l4 4" ${S}/></svg>`,
		arrowDown: `<svg ${W} aria-hidden="true"><path d="M8 3.5v8.75M4 8.25l4 4 4-4" ${S}/></svg>`,
		externalLink: `<svg ${W} aria-hidden="true"><path d="M9.25 2.75h4v4M13.25 2.75 7.5 8.5M11.25 8.75v3.5a1.5 1.5 0 0 1-1.5 1.5h-6a1.5 1.5 0 0 1-1.5-1.5v-6a1.5 1.5 0 0 1 1.5-1.5h3.5" ${S}/></svg>`,
		clock: `<svg ${W} aria-hidden="true"><circle cx="8" cy="8" r="5.25" ${S}/><path d="M8 5.25V8l1.75 1.5" ${S}/></svg>`,
		eye: `<svg ${W} aria-hidden="true"><path d="M1.75 8S4 3.75 8 3.75 14.25 8 14.25 8 12 12.25 8 12.25 1.75 8 1.75 8Z" ${S}/><circle cx="8" cy="8" r="1.75" ${S}/></svg>`,
		layers: `<svg ${W} aria-hidden="true"><path d="M8 2.25 2.25 5 8 7.75 13.75 5 8 2.25Z" ${S}/><path d="M2.25 8 8 10.75 13.75 8M2.25 11 8 13.75 13.75 11" ${S}/></svg>`,

		// Status: idle (active dot is CSS). A hollow ring.
		idle: `<svg ${W} aria-hidden="true"><circle cx="8" cy="8" r="3.25" ${S}/></svg>`,

		// Hot flame — two-tone: orange fill, darker orange stroke
		flame: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
			<path d="M8 1.75s-3.25 3-3.25 6.25c0 2 1.5 4 3.25 4s3.25-2 3.25-4c0-1.25-.5-2.25-1-3 .1 1-.5 1.75-1.25 1.75C8 6.75 9 5 8 1.75Z" fill="#fdba74" stroke="#ea580c" stroke-width="1.25" stroke-linejoin="round"/>
			<path d="M9 13.75c0 .5-.45 1-1 1s-1-.5-1-1 .4-1.25 1-1.75c.6.5 1 1.25 1 1.75Z" fill="#fdba74" stroke="#ea580c" stroke-width="1.25" stroke-linejoin="round"/>
		</svg>`,

		// Empty-state illustrations — larger, 64x64, single-stroke line drawings.
		emptyEye: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
			<path d="M6 32s10-16 26-16 26 16 26 16-10 16-26 16S6 32 6 32Z" opacity="0.45"/>
			<circle cx="32" cy="32" r="8"/>
			<circle cx="32" cy="32" r="2.5" fill="currentColor"/>
		</svg>`,
		emptyRadar: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
			<circle cx="32" cy="32" r="22" opacity="0.35"/>
			<circle cx="32" cy="32" r="14" opacity="0.55"/>
			<circle cx="32" cy="32" r="6"/>
			<path d="M32 32 48 18" />
			<circle cx="48" cy="18" r="2" fill="currentColor"/>
		</svg>`,
		emptyPulse: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
			<path d="M4 32h12l4-10 8 20 6-14 4 8h22" opacity="0.75"/>
			<circle cx="58" cy="36" r="2.5" fill="currentColor"/>
		</svg>`,
	};
})();
