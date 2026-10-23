(function () {
	'use strict';

	const SVG_URL_ATTR = 'data-va-map-src';
	const DATA_ATTR    = 'data-va-map-data';

	const { __ } = window.wp.i18n;

	// Five-stop scale: light -> dark brand green (matches --va-accent).
	// Rendered as a quintile lookup so one heavy country doesn't flatten
	// the rest. Countries with zero visitors stay at the base fill.
	const HEAT_STOPS = [
		'#e3f1ec',
		'#bfe0d4',
		'#86c3ad',
		'#4a9c82',
		'#205e4c'
	];
	const BASE_FILL = '#eef2f7';

	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	// Country-code → regional-indicator emoji. Mirrors the PHP helper
	// `va_country_flag_emoji()` so server- and client-rendered flags match.
	function countryFlagEmoji(code) {
		if (!code || typeof code !== 'string') return '';
		const up = code.trim().toUpperCase();
		if (!/^[A-Z]{2}$/.test(up)) return '';
		const offset = 0x1F1E6 - 'A'.charCodeAt(0);
		return String.fromCodePoint(up.charCodeAt(0) + offset) +
			String.fromCodePoint(up.charCodeAt(1) + offset);
	}

	function parseData(el) {
		const raw = el.getAttribute(DATA_ATTR);
		if (!raw) return [];
		try {
			const arr = JSON.parse(raw);
			return Array.isArray(arr) ? arr : [];
		} catch (e) {
			return [];
		}
	}

	function buildScale(rows) {
		// Quintile bucketing on visitors. Returns a function that maps a
		// visitor count to a fill colour.
		const values = rows.map(function (r) { return Number(r.visitors) || 0; }).filter(function (v) { return v > 0; });
		if (!values.length) return function () { return BASE_FILL; };

		const sorted = values.slice().sort(function (a, b) { return a - b; });
		const q = function (p) {
			const idx = Math.max(0, Math.min(sorted.length - 1, Math.floor(p * sorted.length)));
			return sorted[idx];
		};
		const s1 = q(0.20);
		const s2 = q(0.40);
		const s3 = q(0.60);
		const s4 = q(0.80);

		return function (v) {
			if (v <= 0) return BASE_FILL;
			if (v <= s1) return HEAT_STOPS[0];
			if (v <= s2) return HEAT_STOPS[1];
			if (v <= s3) return HEAT_STOPS[2];
			if (v <= s4) return HEAT_STOPS[3];
			return HEAT_STOPS[4];
		};
	}

	function fmtInt(n) {
		return Number(n).toLocaleString();
	}

	function init(host) {
		if (host.dataset.laMapInit) return;
		host.dataset.laMapInit = '1';

		const url = host.getAttribute(SVG_URL_ATTR);
		if (!url) return;

		const rows = parseData(host);
		const byCode = {};
		rows.forEach(function (r) {
			if (r && r.code) byCode[String(r.code).toUpperCase()] = r;
		});

		// Fetch inline — we vendor the SVG, so same-origin only.
		fetch(url, { credentials: 'omit' }).then(function (resp) {
			if (!resp.ok) throw new Error('map fetch failed');
			return resp.text();
		}).then(function (svgText) {
			// Parse the SVG as XML rather than injecting via innerHTML. The
			// file is vendored + same-origin, but DOMParser is still the
			// correct tool — no live <script> evaluation, proper SVG DOM.
			const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
			const svg = doc.documentElement;
			if (!svg || svg.nodeName.toLowerCase() !== 'svg') return;

			// Clear host and adopt the parsed <svg> node.
			while (host.firstChild) host.removeChild(host.firstChild);
			const imported = document.importNode(svg, true);
			imported.setAttribute('preserveAspectRatio', 'xMidYMid meet');
			imported.setAttribute('class', 'va-world-map-svg');
			imported.removeAttribute('width');
			imported.removeAttribute('height');
			imported.removeAttribute('style');
			host.appendChild(imported);

			const scale = buildScale(rows);

			const paths = imported.querySelectorAll('path[id]');
			paths.forEach(function (p) {
				const code = (p.getAttribute('id') || '').toUpperCase();
				const row  = byCode[code];
				const v    = row ? Number(row.visitors) || 0 : 0;

				p.setAttribute('fill', scale(v));
				p.setAttribute('stroke', '#ffffff');
				p.setAttribute('stroke-width', '0.5');
				p.setAttribute('class', 'va-country' + (row ? ' is-active' : ''));
				if (row) {
					p.setAttribute('data-code', code);
					p.setAttribute('data-name', String(row.name || code));
					p.setAttribute('data-visitors', String(v));
					p.setAttribute('data-pageviews', String(Number(row.pageviews) || 0));
					p.setAttribute('data-rank', String(row.rank || 0));
				}
			});

			wireTooltip(host, imported);
		}).catch(function () {
			// Fall through to the table view — the toggle stays usable.
			host.classList.add('va-world-map-failed');
		});
	}

	function wireTooltip(host, svg) {
		const tip = document.createElement('div');
		tip.className = 'va-world-tip';
		tip.setAttribute('role', 'tooltip');
		tip.hidden = true;
		host.appendChild(tip);

		// Pre-build tooltip structure once; update text nodes on each hover.
		const head     = document.createElement('span');
		head.className = 'va-world-tip-head';
		const flag     = document.createElement('span');
		flag.className = 'va-world-tip-flag';
		flag.setAttribute('aria-hidden', 'true');
		const nameEl   = document.createElement('span');
		nameEl.className = 'va-world-tip-name';
		const rankEl   = document.createElement('span');
		rankEl.className = 'va-world-tip-rank';
		head.appendChild(flag);
		head.appendChild(nameEl);
		head.appendChild(rankEl);

		function mkRow(label) {
			const row = document.createElement('span');
			row.className = 'va-world-tip-row';
			const lbl = document.createElement('span');
			lbl.textContent = label;
			const val = document.createElement('b');
			row.appendChild(lbl);
			row.appendChild(val);
			return { row: row, val: val };
		}
		const visRow = mkRow(__('Visitors', 'vireo-analytics'));
		const pvRow  = mkRow(__('Pageviews', 'vireo-analytics'));

		tip.appendChild(head);
		tip.appendChild(visRow.row);
		tip.appendChild(pvRow.row);

		function show(target, evt) {
			const code = target.getAttribute('data-code');
			if (!code) { hide(); return; }
			const name = target.getAttribute('data-name') || code;
			const vis  = Number(target.getAttribute('data-visitors') || 0);
			const pv   = Number(target.getAttribute('data-pageviews') || 0);
			const rank = Number(target.getAttribute('data-rank') || 0);

			flag.className = 'va-world-tip-flag va-flag-emoji';
			flag.textContent = countryFlagEmoji(code);
			nameEl.textContent = name;
			if (rank > 0) {
				rankEl.textContent = '#' + rank;
				rankEl.style.display = '';
			} else {
				rankEl.textContent = '';
				rankEl.style.display = 'none';
			}
			visRow.val.textContent = fmtInt(vis);
			pvRow.val.textContent  = fmtInt(pv);

			tip.hidden = false;
			positionTip(tip, host, evt);
		}

		function hide() {
			tip.hidden = true;
		}

		function positionTip(tip, host, evt) {
			const hostRect = host.getBoundingClientRect();
			const x = evt.clientX - hostRect.left;
			const y = evt.clientY - hostRect.top;
			const tipW = tip.offsetWidth;
			const tipH = tip.offsetHeight;
			let left = x + 14;
			let top = y + 14;
			if (left + tipW > host.clientWidth - 8) left = x - tipW - 14;
			if (top + tipH > host.clientHeight - 8) top = y - tipH - 14;
			tip.style.left = left + 'px';
			tip.style.top = top + 'px';
		}

		svg.addEventListener('mousemove', function (evt) {
			const t = evt.target.closest ? evt.target.closest('.va-country.is-active') : null;
			if (t) show(t, evt); else hide();
		});
		svg.addEventListener('mouseleave', hide);
	}

	function wireToggles(root) {
		root.querySelectorAll('[data-va-map-toggle]').forEach(function (group) {
			const map   = group.querySelector('[data-va-map]');
			const table = group.querySelector('[data-va-map-table]');
			const btns  = group.querySelectorAll('[data-va-view]');

			btns.forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					const view = btn.getAttribute('data-va-view');
					btns.forEach(function (b) {
						b.classList.toggle('is-active', b === btn);
						b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
					});
					// The list sits under the map in both views; "Table" drops
					// the map for anyone who only wants the numbers.
					if (view === 'table') {
						if (map)   map.hidden = true;
					} else {
						if (map)   { map.hidden = false; init(map); }
					}
					if (table) table.hidden = false;
				});
			});

			// The world SVG is ~112 KB. It used to be fetched on every dashboard
			// load, for a card that sits well below the fold and that plenty of
			// people never scroll to. Wait until it's actually about to be seen.
			if (map && !map.hidden) {
				if (window.IntersectionObserver) {
					const io = new IntersectionObserver(function (entries) {
						entries.forEach(function (entry) {
							if (!entry.isIntersecting) return;
							io.disconnect();
							init(map);
						});
					}, { rootMargin: '200px' });
					io.observe(map);
				} else {
					init(map);
				}
			}
		});
	}

	ready(function () {
		wireToggles(document);
	});

	// The dashboard swaps its cards in place when you change period or filters, so
	// the map node we wired up is gone and a fresh one has taken its place.
	document.addEventListener('vireo:rendered', function () {
		wireToggles(document);
	});
})();
