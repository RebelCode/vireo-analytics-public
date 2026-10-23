(function () {
	'use strict';

	const SVG_NS = 'http://www.w3.org/2000/svg';

	const { __, _n, sprintf } = window.wp.i18n;

	// ---------------------------------------------------------------------
	// Utilities
	// ---------------------------------------------------------------------

	function pickStride(count) {
		if (count <= 12) return 1;
		if (count <= 20) return 2;
		if (count <= 45) return 3;
		if (count <= 70) return 5;
		if (count <= 120) return 10;
		return Math.ceil(count / 12);
	}

	function fmtInt(n) {
		return Number(n).toLocaleString();
	}

	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	// Every admin-ajax write on this page has the same shape: an action, a nonce
	// and same-origin credentials. Values may be arrays, which become repeated
	// keys (order[]=a&order[]=b).
	function postAdminAjax(action, nonce, params) {
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce || '');
		Object.keys(params || {}).forEach(function (key) {
			const value = params[key];
			if (Array.isArray(value)) {
				value.forEach(function (v) { body.append(key, v); });
			} else {
				body.set(key, value);
			}
		});

		return fetch(window.vireoDash.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		}).then(function (resp) {
			if (!resp.ok) throw new Error('HTTP ' + resp.status);
			return resp.json();
		});
	}

	// Gridlines on round numbers (1/2/5 × 10^n) rather than at half the peak,
	// so the axis reads 0/200/400/600 instead of 0/335/670.
	function niceTicks(max, count) {
		count = count || 4;
		if (!(max > 0)) return [0, 1];
		const rawStep = max / count;
		const mag = Math.pow(10, Math.floor(Math.log10(rawStep)));
		const norm = rawStep / mag;
		// Pageviews are whole things, so a step below 1 gives fractional gridlines
		// that all round to the same label: a brand-new site with no data drew an
		// axis reading "1, 1, 0".
		const step = Math.max(1, (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag);
		const ticks = [];
		for (let v = 0; v < max + step * 0.5; v += step) ticks.push(v);
		if (ticks[ticks.length - 1] < max) ticks.push(ticks[ticks.length - 1] + step);
		return ticks;
	}

	function abbrev(n) {
		n = Number(n) || 0;
		// The reader's own decimal mark, as the PHP side uses the site's.
		const one = function (v) { return v.toLocaleString(undefined, { maximumFractionDigits: 1 }); };
		if (n >= 1000000) return one(Math.round(n / 100000) / 10) + 'M';
		if (n >= 10000) return Math.round(n / 1000) + 'k';
		if (n >= 1000) return one(Math.round(n / 100) / 10) + 'k';
		return Math.round(n).toLocaleString();
	}

	function fmtDateShort(iso) {
		// Hour-mode keys arrive as "YYYY-MM-DD HH:00" — render as "HH:00".
		const hm = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/.exec(iso || '');
		if (hm) return hm[4] + ':00';
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
		if (!m) return iso || '';
		const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
		return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
	}

	// "Sep 28, 2026" in the reader's own order: the notes list.
	function fmtDateMedium(iso) {
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
		if (!m) return iso || '';
		const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
		return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
	}

	function fmtDateLong(iso) {
		const hm = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/.exec(iso || '');
		if (hm) {
			const d = new Date(Number(hm[1]), Number(hm[2]) - 1, Number(hm[3]), Number(hm[4]));
			return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }) + ' · ' + hm[4] + ':00';
		}
		const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
		if (!m) return iso || '';
		const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
		return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
	}

	function parseJsonAttr(el, attr) {
		const raw = el.getAttribute(attr);
		if (!raw) return null;
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function tweenNumber(el, from, to, durationMs) {
		if (!el) return;
		durationMs = typeof durationMs === 'number' ? durationMs : 500;
		const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		if (reduce || durationMs <= 0) {
			el.textContent = abbrev(to);
			return;
		}
		const start = performance.now();
		const delta = to - from;
		function step(now) {
			const t = Math.min(1, (now - start) / durationMs);
			const eased = 1 - Math.pow(1 - t, 3);
			const v = Math.round(from + delta * eased);
			el.textContent = abbrev(v);
			if (t < 1) requestAnimationFrame(step);
		}
		requestAnimationFrame(step);
	}

	function runHeroTweens(root) {
		root.querySelectorAll('[data-va-tween]').forEach(function (el) {
			const target = parseInt(el.getAttribute('data-va-tween'), 10) || 0;
			if (target <= 0) return;
			tweenNumber(el, 0, target, 550);
		});
	}

	// ---------------------------------------------------------------------
	// VaChart — main daily bar chart.
	// ---------------------------------------------------------------------

	function VaChart(opts) {
		this.el = opts.el;
		this.data = Array.isArray(opts.data) ? opts.data : [];
		this.prior = Array.isArray(opts.prior) ? opts.prior : [];
		// Metric toggle: 'pv' | 'vis' | 'both'. Default 'both' for parity with
		// the existing behaviour.
		this.metric = (opts.metric === 'pv' || opts.metric === 'vis') ? opts.metric : 'both';
		// Date -> note. Answers "what happened here" on a spike nobody remembers.
		this.notes = (opts.notes && typeof opts.notes === 'object') ? opts.notes : {};
		// What the ghost line is: the prior period, or the same period last year.
		this.priorLabel = opts.priorLabel || __('Prior period', 'vireo-analytics');
		// The event screen reuses this chart for fires, not pageviews.
		this.events = this.el.getAttribute('data-va-unit') === 'events';
		this.tooltip = null;
		this.svg = null;
		this.render();
	}

	// "1 pageview" / "12 pageviews". Each count gets its own plural form, since
	// one _n() can't cover a sentence holding two numbers.
	function pageviewsText(count) {
		/* translators: %s: pageview count. */
		return sprintf(_n('%s pageview', '%s pageviews', count, 'vireo-analytics'), fmtInt(count));
	}
	function visitorsText(count) {
		/* translators: %s: visitor count. */
		return sprintf(_n('%s visitor', '%s visitors', count, 'vireo-analytics'), fmtInt(count));
	}

	// The first series' noun: pageviews on traffic charts, events on an event's.
	VaChart.prototype.countText = function (count) {
		if (!this.events) return pageviewsText(count);
		/* translators: %s: number of times an event was recorded. */
		return sprintf(_n('%s event', '%s events', count, 'vireo-analytics'), fmtInt(count));
	};

	// What a screen reader hears instead of an unlabelled <svg> full of rects.
	VaChart.prototype.summaryLabel = function () {
		const n = this.data.length;
		if (!n) return __('Daily traffic chart, no data.', 'vireo-analytics');
		const totPv = this.data.reduce((s, d) => s + (d.pageviews | 0), 0);
		const totVis = this.data.reduce((s, d) => s + (d.visitors | 0), 0);
		return sprintf(
			/* translators: 1: first date in the range, 2: last date, 3: pageview count with its noun, 4: visitor count with its noun. */
			__('Daily traffic, %1$s to %2$s. %3$s, %4$s. Use arrow keys to step through each day.', 'vireo-analytics'),
			fmtDateLong(this.data[0].date), fmtDateLong(this.data[n - 1].date), this.countText(totPv), visitorsText(totVis)
		);
	};

	// Per-bar label so keyboard and screen-reader users can read the values that
	// were previously only reachable by hovering a mouse.
	VaChart.prototype.pointLabel = function (idx) {
		const d = this.data[idx] || {};
		const p = this.prior[idx];
		let s = sprintf(
			/* translators: 1: date, 2: pageview count with its noun, 3: visitor count with its noun. */
			__('%1$s: %2$s, %3$s', 'vireo-analytics'),
			fmtDateLong(d.date), this.countText(d.pageviews | 0), visitorsText(d.visitors | 0)
		);
		if (p) {
			s += sprintf(
				/* translators: %s: pageview count in the prior period. Note the leading comma — this continues the previous sentence. */
				_n(', %s pageview in the prior period', ', %s pageviews in the prior period', p.pageviews | 0, 'vireo-analytics'),
				fmtInt(p.pageviews | 0)
			);
		}
		s += '.';
		const note = this.noteFor(d.date);
		if (note) {
			s += ' ' + sprintf(
				/* translators: %s: the note someone pinned to this day. */
				__('Note: %s', 'vireo-analytics'),
				note
			);
		}
		return s;
	};

	// Own properties only: a bare key lookup would happily return
	// Object.prototype.constructor for a date of "constructor".
	VaChart.prototype.noteFor = function (date) {
		return Object.prototype.hasOwnProperty.call(this.notes, date) ? String(this.notes[date] || '') : '';
	};

	VaChart.prototype.clearHover = function () {
		if (!this.svg) return;
		this.svg.querySelectorAll('.va-chart-bar.is-hover').forEach(function (el) {
			el.classList.remove('is-hover');
		});
	};

	// Move the keyboard cursor to a day: highlight it, show the same tooltip the
	// mouse gets, and say it out loud for a screen reader.
	VaChart.prototype.focusPoint = function (idx) {
		if (!this.svg || !this.data[idx]) return;
		this.clearHover();
		const pvEl = this.svg.querySelector('.va-chart-bar-pv[data-idx="' + idx + '"]');
		const visEl = this.svg.querySelector('.va-chart-bar-vis[data-idx="' + idx + '"]');
		if (pvEl) pvEl.classList.add('is-hover');
		if (visEl) visEl.classList.add('is-hover');
		if (pvEl) this.showTooltip(idx, pvEl);
		if (this.live) this.live.textContent = this.pointLabel(idx);
	};

	VaChart.prototype.setMetric = function (metric) {
		if (metric !== 'pv' && metric !== 'vis' && metric !== 'both') return;
		if (metric === this.metric) return;
		this.metric = metric;
		this.el.setAttribute('data-va-metric', metric);
		this.render();
	};

	VaChart.prototype.render = function () {
		if (!this.el || !this.data.length) return;

		const host = this.el;
		host.classList.add('va-chart-host-live');

		// Draw at the container's real width. This used to be a fixed 900-unit
		// viewBox with preserveAspectRatio="none", which meant the browser stretched
		// the whole chart horizontally to fill the card — on a wide screen that's a
		// ~1.4x distortion, and every axis label is SVG text, so the dates came out
		// visibly squashed sideways.
		const W = Math.max(240, Math.round(host.clientWidth || 900));
		// Height comes from the stylesheet, which drops it on phones. A fixed 280
		// against a 220px-tall box made the browser scale the whole drawing down
		// to fit, so a phone got a chart three quarters as wide with tiny labels.
		const H = parseInt(window.getComputedStyle(host).minHeight, 10) || 280;
		const padL = 36, padR = 12, padT = 14, padB = 28;
		const innerW = W - padL - padR;
		const innerH = H - padT - padB;

		const n = this.data.length;

		// Scale to whichever series is on screen. Keying the axis off pageviews in
		// visitors-only mode squashed the visitor bars against a ceiling that
		// belonged to a metric the user wasn't looking at.
		const showsPv = this.metric === 'pv' || this.metric === 'both';
		const peak = (rows) => rows.reduce(
			(m, d) => Math.max(m, showsPv ? (d.pageviews | 0) : (d.visitors | 0)),
			0
		);
		const maxCur = peak(this.data);
		const maxPrv = this.prior.length ? peak(this.prior) : 0;
		const max = Math.max(1, maxCur, maxPrv);

		// Round the axis to human numbers instead of halving whatever the peak
		// happened to be, and give a 90-day range more than three gridlines.
		const yTicks = niceTicks(max, 4);
		const axisMax = yTicks[yTicks.length - 1];

		const slot = innerW / n;
		const barW = Math.max(2, slot * 0.72);
		const hasPrev = this.prior.length > 0;

		const svg = document.createElementNS(SVG_NS, 'svg');
		svg.setAttribute('class', 'va-chart-svg');
		svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
		// The viewBox now matches the host's real pixel width, so the chart maps
		// 1:1 and nothing stretches. It used to be a fixed 900-wide box squashed
		// to fit, which fattened every bar on a wide monitor.
		svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
		svg.setAttribute('role', 'img');
		svg.setAttribute('aria-label', this.summaryLabel());

		const gridG = document.createElementNS(SVG_NS, 'g');
		gridG.setAttribute('class', 'va-chart-grid');
		yTicks.forEach(function (v) {
			const y = padT + innerH - (v / axisMax) * innerH;
			const line = document.createElementNS(SVG_NS, 'line');
			line.setAttribute('class', 'va-chart-gridline');
			line.setAttribute('x1', String(padL));
			line.setAttribute('x2', String(W - padR));
			line.setAttribute('y1', String(y));
			line.setAttribute('y2', String(y));
			gridG.appendChild(line);

			const label = document.createElementNS(SVG_NS, 'text');
			label.setAttribute('class', 'va-chart-axis-label');
			label.setAttribute('x', String(padL - 6));
			label.setAttribute('y', String(y + 3));
			label.setAttribute('text-anchor', 'end');
			label.textContent = abbrev(Math.round(v));
			gridG.appendChild(label);
		});
		svg.appendChild(gridG);

		// Prior period is reference data, so it gets a ghost line through the
		// level the metric reached each day, not a bar. Drawn as a filled rect of
		// the same width it sat behind the current bar and towered over it on
		// any day traffic fell, which handed the silhouette to the series nobody
		// is asking about. One continuous line, as the day-by-day shape of last
		// period is the point of overlaying it; a day with nothing breaks it.
		if (hasPrev) {
			const prvCount = this.prior.length;
			let d = '';
			let started = false;
			for (let i = 0; i < prvCount; i++) {
				const row = this.prior[i];
				const v = showsPv ? (row.pageviews | 0) : (row.visitors | 0);
				if (v <= 0) {
					started = false;
					continue;
				}
				const h = (v / axisMax) * innerH;
				const idx = Math.round((i / Math.max(1, prvCount - 1)) * (n - 1));
				const cx = padL + idx * slot + slot / 2;
				const y = padT + innerH - h;
				d += (started ? 'L' : 'M') + cx.toFixed(2) + ' ' + y.toFixed(2);
				started = true;
			}
			if ('' !== d) {
				const prvG = document.createElementNS(SVG_NS, 'g');
				prvG.setAttribute('class', 'va-chart-bars-prev');
				const path = document.createElementNS(SVG_NS, 'path');
				path.setAttribute('class', 'va-chart-prev-line');
				path.setAttribute('d', d);
				// The chart stretches to fit its host, so keep the hairline from
				// stretching with it.
				path.setAttribute('vector-effect', 'non-scaling-stroke');
				prvG.appendChild(path);
				svg.appendChild(prvG);
			}
		}

		const self = this;

		// A day with a note gets a pin over its bar, so the reason for a spike
		// is on the chart rather than waiting in a tooltip nobody hovers.
		// Drawn under the bars: the guide sits behind them, and the pin itself
		// lives in the top padding, clear of the hit areas.
		const notesG = document.createElementNS(SVG_NS, 'g');
		notesG.setAttribute('class', 'va-chart-notes');
		for (let i = 0; i < n; i++) {
			const note = this.noteFor(this.data[i].date);
			if (!note) continue;
			const cx = padL + i * slot + slot / 2;
			const pin = document.createElementNS(SVG_NS, 'g');
			pin.setAttribute('class', 'va-chart-note');
			pin.setAttribute('data-idx', String(i));
			pin.setAttribute('aria-label', sprintf(
				/* translators: 1: date, 2: the note pinned to it. */
				__('Note on %1$s: %2$s', 'vireo-analytics'),
				fmtDateLong(this.data[i].date), note
			));

			const guide = document.createElementNS(SVG_NS, 'line');
			guide.setAttribute('class', 'va-chart-note-line');
			guide.setAttribute('x1', String(cx));
			guide.setAttribute('x2', String(cx));
			guide.setAttribute('y1', String(padT - 2));
			guide.setAttribute('y2', String(padT + innerH));
			guide.setAttribute('vector-effect', 'non-scaling-stroke');

			const dot = document.createElementNS(SVG_NS, 'circle');
			dot.setAttribute('class', 'va-chart-note-dot');
			dot.setAttribute('cx', String(cx));
			dot.setAttribute('cy', String(padT - 6));
			dot.setAttribute('r', '4');

			pin.appendChild(guide);
			pin.appendChild(dot);
			notesG.appendChild(pin);

			dot.addEventListener('mouseenter', function (idx) {
				return function () {
					const pvEl = self.svg && self.svg.querySelector('.va-chart-bar-pv[data-idx="' + idx + '"]');
					if (pvEl) self.showTooltip(idx, pvEl);
				};
			}(i));
			dot.addEventListener('mouseleave', function () { self.hideTooltip(); });
		}
		if (notesG.childNodes.length) svg.appendChild(notesG);

		const barsG = document.createElementNS(SVG_NS, 'g');
		barsG.setAttribute('class', 'va-chart-bars');
		const showPv  = this.metric === 'pv'  || this.metric === 'both';
		const showVis = this.metric === 'vis' || this.metric === 'both';
		for (let i = 0; i < n; i++) {
			const pv = this.data[i].pageviews | 0;
			const vis = this.data[i].visitors | 0;
			const hPv  = pv  > 0 ? (pv  / axisMax) * innerH : 0;
			const hVis = vis > 0 ? (vis / axisMax) * innerH : 0;
			const x = padL + i * slot + (slot - barW) / 2;
			const yStart = padT + innerH;

			// Pageviews bar (lighter, rendered first so visitors overlays).
			const barPv = document.createElementNS(SVG_NS, 'rect');
			barPv.setAttribute('class', 'va-chart-bar va-chart-bar-pv' + (showPv ? '' : ' is-hidden'));
			barPv.setAttribute('x', String(x));
			barPv.setAttribute('y', String(yStart));
			barPv.setAttribute('width', String(barW));
			barPv.setAttribute('height', '0');
			barPv.setAttribute('rx', '2');
			barPv.setAttribute('data-idx', String(i));
			barPv.setAttribute('data-series', 'pv');

			// Visitors bar (darker, narrower, overlayed).
			const barVis = document.createElementNS(SVG_NS, 'rect');
			barVis.setAttribute('class', 'va-chart-bar va-chart-bar-vis' + (showVis ? '' : ' is-hidden'));
			barVis.setAttribute('x', String(x));
			barVis.setAttribute('y', String(yStart));
			barVis.setAttribute('width', String(barW));
			barVis.setAttribute('height', '0');
			barVis.setAttribute('rx', '2');
			barVis.setAttribute('data-idx', String(i));
			barVis.setAttribute('data-series', 'vis');

			const hit = document.createElementNS(SVG_NS, 'rect');
			hit.setAttribute('class', 'va-chart-bar-hit');
			hit.setAttribute('x', String(padL + i * slot));
			hit.setAttribute('y', String(padT));
			hit.setAttribute('width', String(slot));
			hit.setAttribute('height', String(innerH));
			hit.setAttribute('fill', 'transparent');
			hit.setAttribute('data-idx', String(i));

			barsG.appendChild(barPv);
			barsG.appendChild(barVis);
			barsG.appendChild(hit);

			hit.addEventListener('mouseenter', function (idx, pvEl, visEl) {
				return function () {
					self.showTooltip(idx, pvEl);
					pvEl.classList.add('is-hover');
					visEl.classList.add('is-hover');
				};
			}(i, barPv, barVis));
			hit.addEventListener('mouseleave', function (pvEl, visEl) {
				return function () {
					self.hideTooltip();
					pvEl.classList.remove('is-hover');
					visEl.classList.remove('is-hover');
				};
			}(barPv, barVis));

		}

		svg.appendChild(barsG);

		const axisG = document.createElementNS(SVG_NS, 'g');
		axisG.setAttribute('class', 'va-chart-axis-x');
		// Spacing follows the width as well as the day count: about 64px per date
		// keeps "Sep 14" clear of its neighbour on a phone and in longer
		// languages. A stride label too close to the last date is dropped so the
		// two endpoints never collide.
		const stride = Math.max(pickStride(n), Math.ceil(n / Math.max(2, Math.floor(innerW / 64))));
		for (let i = 0; i < n; i++) {
			const isEdge = i === 0 || i === n - 1;
			if (!isEdge && i % stride !== 0) continue;
			if (!isEdge && (n - 1 - i) * slot < 48) continue;
			const x = padL + i * slot + slot / 2;
			const label = document.createElementNS(SVG_NS, 'text');
			label.setAttribute('class', 'va-chart-axis-label');
			label.setAttribute('x', String(x));
			label.setAttribute('y', String(H - 8));
			label.setAttribute('text-anchor', i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle');
			label.textContent = fmtDateShort(this.data[i].date);
			axisG.appendChild(label);
		}
		svg.appendChild(axisG);

		host.textContent = '';
		host.appendChild(svg);

		const tip = document.createElement('div');
		tip.className = 'va-chart-tooltip';
		tip.setAttribute('role', 'tooltip');
		host.appendChild(tip);

		// Keyboard access hangs off the host, which is a real HTML element.
		// Putting tabindex on the SVG <rect>s does not work: in Chrome they never
		// enter the tab order and never fire focus events, so the chart looked
		// keyboard-accessible while being completely unreachable.
		const live = document.createElement('div');
		live.className = 'va-sr-only';
		live.setAttribute('aria-live', 'polite');
		host.appendChild(live);

		host.setAttribute('tabindex', '0');
		host.setAttribute('role', 'img');
		host.setAttribute('aria-label', this.summaryLabel());

		this.svg = svg;
		this.tooltip = tip;
		this.live = live;
		this.cursor = typeof this.cursor === 'number' ? Math.min(this.cursor, n - 1) : 0;

		if (!this._keysBound) {
			this._keysBound = true;

			host.addEventListener('keydown', function (ev) {
				const step = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
				const jump = ev.key === 'Home' ? 0 : ev.key === 'End' ? (self.data.length - 1) : null;
				if (!step && null === jump) return;
				ev.preventDefault();
				const last = self.data.length - 1;
				self.cursor = null !== jump ? jump : Math.min(last, Math.max(0, self.cursor + step));
				self.focusPoint(self.cursor);
			});

			host.addEventListener('focus', function () {
				self.focusPoint(self.cursor);
			});
			host.addEventListener('blur', function () {
				self.hideTooltip();
				self.clearHover();
			});
		}

		const bars = barsG.querySelectorAll('.va-chart-bar');
		const reduce = prefersReducedMotion();
		requestAnimationFrame(function () {
			bars.forEach(function (rect) {
				const idx    = parseInt(rect.getAttribute('data-idx'), 10) || 0;
				const series = rect.getAttribute('data-series') || 'pv';
				const hidden = rect.classList.contains('is-hidden');
				const d      = self.data[idx] || {};
				const v      = (series === 'vis' ? (d.visitors | 0) : (d.pageviews | 0));
				const h      = (!hidden && v > 0) ? (v / axisMax) * innerH : 0;
				// The hero number tween already respected this; the bars did not, so
				// "reduce motion" still got 30 bars sweeping up the screen.
				if (!reduce) {
					const delay = Math.min(300, n > 0 ? (idx / n) * 200 : 0);
					rect.style.transition = 'y 0.45s cubic-bezier(0.2,0.8,0.2,1) ' + delay + 'ms, height 0.45s cubic-bezier(0.2,0.8,0.2,1) ' + delay + 'ms';
				}
				rect.setAttribute('height', String(h));
				rect.setAttribute('y', String(padT + innerH - h));
			});
		});

		// The viewBox is pinned to the host width now, so a resized window (or a
		// collapsed admin menu) needs a re-render or the chart would keep the
		// geometry of the width it was born at.
		if (!this._ro && window.ResizeObserver) {
			let last = W;
			this._ro = new ResizeObserver(function () {
				const w = Math.max(320, Math.round(host.clientWidth || 900));
				if (Math.abs(w - last) < 8) return;
				last = w;
				clearTimeout(self._roT);
				self._roT = setTimeout(function () { self.render(); }, 120);
			});
			this._ro.observe(host);
		}
	};

	VaChart.prototype.showTooltip = function (idx, barEl) {
		if (!this.tooltip || !this.svg) return;
		const d = this.data[idx];
		if (!d) return;
		const prev = this.prior[idx];

		const parts = [
			'<div class="va-chart-tooltip-date">' + escapeHtml(fmtDateLong(d.date)) + '</div>',
			'<div class="va-chart-tooltip-row"><span class="va-dot pv"></span>' + escapeHtml(this.countText(d.pageviews | 0)) + '</div>',
			'<div class="va-chart-tooltip-row"><span class="va-dot uv"></span>' + escapeHtml(sprintf(
				/* translators: %s: visitor count. */
				_n('%s visitor', '%s visitors', d.visitors | 0, 'vireo-analytics'),
				fmtInt(d.visitors)
			)) + '</div>'
		];
		if (prev) {
			parts.push('<div class="va-chart-tooltip-row"><span class="va-dot prv"></span>' + escapeHtml(sprintf(
				/* translators: 1: the compared period, such as "Prior period", 2: its pageview count with its noun. */
				__('%1$s: %2$s', 'vireo-analytics'),
				this.priorLabel,
				this.countText(prev.pageviews | 0)
			)) + '</div>');
		}
		const note = this.noteFor(d.date);
		if (note) {
			parts.push('<div class="va-chart-tooltip-note">' + escapeHtml(String(note)) + '</div>');
		}

		this.tooltip.innerHTML = parts.join('');

		const barRect = barEl.getBoundingClientRect();
		const hostRect = this.svg.parentNode.getBoundingClientRect();
		const cx = barRect.left + barRect.width / 2 - hostRect.left;
		const cy = barRect.top - hostRect.top;

		this.tooltip.style.left = cx + 'px';
		this.tooltip.style.top = cy + 'px';
		this.tooltip.classList.add('is-visible');
	};

	VaChart.prototype.hideTooltip = function () {
		if (this.tooltip) this.tooltip.classList.remove('is-visible');
	};

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (ch) {
			switch (ch) {
				case '&': return '&amp;';
				case '<': return '&lt;';
				case '>': return '&gt;';
				case '"': return '&quot;';
				default:  return '&#39;';
			}
		});
	}

	// ---------------------------------------------------------------------
	// Hero sparkline
	// ---------------------------------------------------------------------

	function renderHeroSparkline(host) {
		const data = parseJsonAttr(host, 'data-va-series');
		if (!Array.isArray(data) || data.length < 2) return;

		const W = 240, H = 40, pad = 2;
		// `value` is a series of its own: time on page or bounce rate per day.
		const metric = host.getAttribute('data-va-spark-metric');
		const key = metric === 'visitors' || metric === 'value' ? metric : 'pageviews';
		const max = data.reduce(function (m, d) { return Math.max(m, +d[key] || 0); }, 0) || 1;
		const step = (W - 2 * pad) / Math.max(1, data.length - 1);

		const coords = data.map(function (d, i) {
			const x = pad + i * step;
			const y = pad + (H - 2 * pad) - ((+d[key] || 0) / max) * (H - 2 * pad);
			return [x.toFixed(2), y.toFixed(2)];
		});

		const line = coords.map(function (c) { return c[0] + ',' + c[1]; }).join(' ');
		let area = 'M ' + coords[0][0] + ' ' + (H - pad);
		coords.forEach(function (c) { area += ' L ' + c[0] + ' ' + c[1]; });
		area += ' L ' + coords[coords.length - 1][0] + ' ' + (H - pad) + ' Z';

		const svg = document.createElementNS(SVG_NS, 'svg');
		svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
		svg.setAttribute('preserveAspectRatio', 'none');
		svg.setAttribute('aria-hidden', 'true');

		const areaEl = document.createElementNS(SVG_NS, 'path');
		areaEl.setAttribute('class', 'va-spark-area');
		areaEl.setAttribute('d', area);
		svg.appendChild(areaEl);

		const lineEl = document.createElementNS(SVG_NS, 'polyline');
		lineEl.setAttribute('class', 'va-spark-line');
		lineEl.setAttribute('points', line);
		svg.appendChild(lineEl);

		// The average card draws its own level across the trend.
		if (host.hasAttribute('data-va-spark-average')) {
			const avg = data.reduce(function (s, d) { return s + (+d[key] || 0); }, 0) / data.length;
			const ay = (pad + (H - 2 * pad) - (avg / max) * (H - 2 * pad)).toFixed(2);
			const avgEl = document.createElementNS(SVG_NS, 'line');
			avgEl.setAttribute('class', 'va-spark-avg');
			avgEl.setAttribute('x1', String(pad));
			avgEl.setAttribute('x2', String(W - pad));
			avgEl.setAttribute('y1', ay);
			avgEl.setAttribute('y2', ay);
			avgEl.setAttribute('vector-effect', 'non-scaling-stroke');
			svg.appendChild(avgEl);
		}

		host.textContent = '';
		host.appendChild(svg);
	}

	// ---------------------------------------------------------------------
	// Custom-range panel toggle
	// ---------------------------------------------------------------------

	// Links acting as switches answer Space like a real switch would.
	function wireSwitchLinks(root) {
		root.querySelectorAll('[data-va-switch]').forEach(function (el) {
			el.addEventListener('keydown', function (ev) {
				if (ev.key !== ' ') return;
				ev.preventDefault();
				el.click();
			});
		});
	}

	// The phone range select: a preset navigates, Custom opens the date panel.
	function wireRangeSelect(root) {
		const select = root.querySelector('[data-va-range-select]');
		if (!select) return;
		select.addEventListener('change', function () {
			if (select.value === 'custom') {
				const trigger = root.querySelector('[data-va-custom]');
				if (trigger) trigger.click();
				return;
			}
			// Keep the open tab, which may have changed in place since load.
			const target = new URL(select.value, window.location.href);
			const tab = new URL(window.location.href).searchParams.get('tab');
			if (tab) target.searchParams.set('tab', tab); else target.searchParams.delete('tab');
			window.location.href = target.toString();
		});
	}

	// What changed shows five rows a side; the button reveals the rest.
	function wireDriversMore(root) {
		root.querySelectorAll('[data-va-drivers-more]').forEach(function (btn) {
			const group = btn.closest('[data-va-drivers-group]');
			if (!group) return;
			const more = btn.textContent.trim();
			btn.addEventListener('click', function () {
				const open = group.classList.toggle('is-expanded');
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
				btn.textContent = open ? __('Show fewer', 'vireo-analytics') : more;
			});
		});
	}

	function wireCustomPanel(root) {
		const trigger = root.querySelector('[data-va-custom]');
		const panel = root.querySelector('[data-va-custom-panel]');
		if (!trigger || !panel) return;
		trigger.addEventListener('click', function (ev) {
			ev.preventDefault();
			const opening = panel.hasAttribute('hidden');
			if (opening) {
				panel.removeAttribute('hidden');
				// Straight into the first date, so a keyboard user lands where
				// the panel appeared.
				const first = panel.querySelector('input[type="date"]');
				if (first) first.focus();
			} else {
				panel.setAttribute('hidden', '');
			}
			trigger.setAttribute('aria-expanded', opening ? 'true' : 'false');
		});
		// role="button" on a link: Space should press it too.
		trigger.addEventListener('keydown', function (ev) {
			if (ev.key === ' ') {
				ev.preventDefault();
				trigger.click();
			}
		});
	}

	// Filter-chip bar — auto-submit on change so users don't have to reach
	// for an Apply button. <noscript> keeps the fallback button visible when
	// JS is off.
	// The site selector posts to admin-post and comes back to the same range,
	// so it is a plain form submit; <noscript> keeps the Switch button for JS-off.
	function wireSiteSwitch(root) {
		const form = root.querySelector('[data-va-site-switch]');
		const select = form ? form.querySelector('[data-va-site-select]') : null;
		if (!form || !select) return;
		select.addEventListener('change', function () {
			form.submit();
		});
	}

	function wireFilterBar(root) {
		wireSiteSwitch(root);
		wireCompareMode(root);
		const bar = root.querySelector('[data-va-filter-bar]');
		if (!bar) return;
		bar.querySelectorAll('[data-va-filter]').forEach(function (sel) {
			sel.addEventListener('change', function () {
				// requestSubmit(), not submit(): form.submit() skips submit handlers
				// outright, so the in-place navigation would never see the change and
				// every filter would still cost a full page load.
				if (bar.requestSubmit) {
					bar.requestSubmit();
				} else {
					bar.submit();
				}
			});
		});
		wireSavedViews(root, bar);
	}

	// Previous period or same period last year: the options carry their URLs.
	function wireCompareMode(root) {
		const select = root.querySelector('[data-va-compare-mode]');
		if (!select) return;
		select.addEventListener('change', function () {
			if (select.value) navigate(select.value, true);
		});
	}

	// Saved views: pick one from the select, name and save the one on screen,
	// or forget it. The list and the buttons come back from the server with the
	// next render, so nothing here has to keep its own copy.
	function wireSavedViews(root, bar) {
		const box = bar.querySelector('[data-va-views]');
		if (!box) return;

		const status = box.querySelector('[data-va-view-status]');
		const say = function (text) { if (status) status.textContent = text; };

		const pick = box.querySelector('[data-va-view-select]');
		if (pick) {
			pick.addEventListener('change', function () {
				if (pick.value) navigate(pick.value, true);
			});
		}

		const save = box.querySelector('[data-va-view-save]');
		const form = box.querySelector('[data-va-view-form]');
		const name = box.querySelector('[data-va-view-name]');
		if (save && form && name) {
			save.addEventListener('click', function () {
				const open = form.hasAttribute('hidden');
				form.toggleAttribute('hidden', !open);
				save.setAttribute('aria-expanded', open ? 'true' : 'false');
				if (open) name.focus();
			});
			const close = function () {
				form.setAttribute('hidden', '');
				save.setAttribute('aria-expanded', 'false');
				save.focus();
			};
			const cancel = box.querySelector('[data-va-view-cancel]');
			if (cancel) cancel.addEventListener('click', close);
			name.addEventListener('keydown', function (ev) {
				if (ev.key === 'Escape') { ev.preventDefault(); close(); }
				if (ev.key === 'Enter') { ev.preventDefault(); submit(); }
			});
			const confirm = box.querySelector('[data-va-view-confirm]');
			if (confirm) confirm.addEventListener('click', submit);

			function submit() {
				const label = name.value.trim();
				if (!label) { name.focus(); return; }
				const params = readViewParams(box);
				const body = { name: label };
				Object.keys(params).forEach(function (key) { body['params[' + key + ']'] = params[key]; });
				say(__('Saving…', 'vireo-analytics'));
				postAdminAjax('vireo_save_view', window.vireoDash.savedViewsNonce, body)
					.then(function (res) {
						if (!res || !res.success || !res.data || !res.data.url) {
							throw new Error(res && res.data && res.data.message ? res.data.message : 'save failed');
						}
						say(__('View saved.', 'vireo-analytics'));
						navigate(res.data.url, true);
					})
					.catch(function (err) {
						say(err && err.message && err.message !== 'save failed' ? err.message : __('The view could not be saved.', 'vireo-analytics'));
					});
			}
		}

		const del = box.querySelector('[data-va-view-delete]');
		if (del) {
			del.addEventListener('click', function () {
				say(__('Forgetting…', 'vireo-analytics'));
				postAdminAjax('vireo_delete_view', window.vireoDash.savedViewsNonce, { id: del.getAttribute('data-va-view-delete') })
					.then(function () {
						say(__('View forgotten.', 'vireo-analytics'));
						navigate(window.location.href, false);
					})
					.catch(function () { say(__('The view could not be forgotten.', 'vireo-analytics')); });
			});
		}
	}

	// The view's params, printed by PHP as JSON so the save sends exactly what
	// the page shows, nonce and tab aside.
	function readViewParams(box) {
		const el = box.querySelector('[data-va-view-params]');
		if (!el) return {};
		try {
			const parsed = JSON.parse(el.textContent || '{}');
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (e) {
			return {};
		}
	}

	// ---------------------------------------------------------------------
	// In-place navigation
	//
	// Every period, filter and compare click used to be a full admin page load:
	// WordPress boots, the menu re-renders, the browser repaints everything, and
	// you wait a second to change a date range. The page already renders itself
	// correctly for any URL, so rather than build a parallel JSON API and a second
	// copy of the rendering in JS, we fetch that same URL and swap the dashboard
	// out of the response. PHP stays the only thing that knows how to draw a card.
	//
	// The links stay real links. If the fetch fails, or the browser is old, or JS
	// is off, the click just navigates.
	// ---------------------------------------------------------------------

	const PAGE_SLUG = 'vireo-analytics';
	let navToken = 0;

	function sameDashboard(url) {
		try {
			const u = new URL(url, window.location.origin);
			return u.pathname === window.location.pathname && u.searchParams.get('page') === PAGE_SLUG;
		} catch (e) {
			return false;
		}
	}

	function navigate(url, push) {
		if (!window.fetch || !window.history || !window.DOMParser
			|| !Element.prototype.replaceChildren || !sameDashboard(url)) {
			window.location.href = url;
			return;
		}

		const root = document.querySelector('.va-root');
		if (!root) {
			window.location.href = url;
			return;
		}

		const token = ++navToken;
		root.classList.add('is-loading');
		root.setAttribute('aria-busy', 'true');

		fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (resp) {
				if (!resp.ok) throw new Error('HTTP ' + resp.status);
				return resp.text();
			})
			.then(function (html) {
				// A newer click already started; drop this response.
				if (token !== navToken) return;

				const doc = new DOMParser().parseFromString(html, 'text/html');
				const next = doc.querySelector('.va-root');
				if (!next) throw new Error('no dashboard in response');

				// Adopt the parsed nodes rather than round-tripping through an HTML
				// string. DOMParser has already done the parsing, and moving nodes
				// skips a serialise/reparse of the whole dashboard.
				const adopted = document.importNode(next, true);
				root.replaceChildren.apply(root, Array.prototype.slice.call(adopted.childNodes));

				// Range and compare state live on the root as data attributes.
				Array.prototype.slice.call(next.attributes).forEach(function (attr) {
					if (attr.name.indexOf('data-') === 0) root.setAttribute(attr.name, attr.value);
				});

				if (push) window.history.pushState({ vaUrl: url }, '', url);

				root.classList.remove('is-loading');
				root.removeAttribute('aria-busy');

				init();
				// world-map.js hangs off this: its cards were just replaced.
				document.dispatchEvent(new CustomEvent('vireo:rendered'));
				window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
			})
			.catch(function () {
				if (token !== navToken) return;
				// Whatever went wrong, the plain page load still works.
				window.location.href = url;
			});
	}

	// Links that only change which slice of data is on screen: period presets,
	// the compare toggle, the day/hour switch, and the map's own view buttons are
	// handled elsewhere. Drill-downs (a post, a referrer) are left as real
	// navigations, since they are a different screen rather than a re-filter.
	function wireNavigation(root) {
		// The root element survives a swap; only its children are replaced. Binding
		// again on every render would stack a new handler each time.
		if (root.dataset.vaNavBound) return;
		root.dataset.vaNavBound = '1';

		root.addEventListener('click', function (ev) {
			// Let the user open things in a new tab/window as they expect.
			if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;

			const link = ev.target.closest('.va-preset-btn, .va-compare-toggle, .va-chart-mode-btn, .va-filter-tag, .va-filter-clear, .va-row-filter, .va-filter-note a');
			if (!link || link.tagName !== 'A') return;
			if (link.hasAttribute('data-va-custom')) return; // opens the custom-range panel

			const href = link.getAttribute('href');
			if (!href || href === '#' || !sameDashboard(href)) return;

			ev.preventDefault();
			navigate(href, true);
		});

		// Delegated: the filter bar is one of the nodes a swap replaces.
		root.addEventListener('submit', function (ev) {
			const bar = ev.target.closest ? ev.target.closest('[data-va-filter-bar]') : null;
			if (!bar) return;
			ev.preventDefault();
			const params = new URLSearchParams(new FormData(bar));
			navigate(bar.getAttribute('action') + '?' + params.toString(), true);
		});
	}

	// Back/forward should feel the same as clicking.
	if (window.history && window.history.pushState) {
		window.addEventListener('popstate', function () {
			if (document.querySelector('.va-root')) navigate(window.location.href, false);
		});
	}

	// Technology card: one card, three tabs. Browsers, OS and device types used to
	// be three near-identical cards eating a full row.
	function wireTechTabs(root) {
		root.querySelectorAll('[data-va-tech]').forEach(function (card) {
			const tabs = [].slice.call(card.querySelectorAll('[data-va-tech-tab]'));
			if (!tabs.length) return;

			function select(key, focus) {
				tabs.forEach(function (tab) {
					const active = tab.getAttribute('data-va-tech-tab') === key;
					tab.classList.toggle('is-active', active);
					tab.setAttribute('aria-selected', active ? 'true' : 'false');
					tab.setAttribute('tabindex', active ? '0' : '-1');
					if (active && focus) tab.focus();
				});
				card.querySelectorAll('[data-va-tech-panel]').forEach(function (panel) {
					panel.hidden = panel.getAttribute('data-va-tech-panel') !== key;
				});
			}

			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					select(tab.getAttribute('data-va-tech-tab'), false);
				});
			});

			// Arrow keys move between tabs, which is what a tablist is supposed to do.
			card.addEventListener('keydown', function (ev) {
				if (!ev.target.hasAttribute('data-va-tech-tab')) return;
				const step = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
				if (!step) return;
				ev.preventDefault();
				const i = tabs.indexOf(ev.target);
				const next = tabs[(i + step + tabs.length) % tabs.length];
				select(next.getAttribute('data-va-tech-tab'), true);
			});
		});
	}

	// ---------------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------------

	function init() {
		const root = document.querySelector('.va-root');
		if (!root) return;

		wireTechTabs(root);

		// A range or filter change swaps the tab bar out with everything else,
		// so the fresh one is wired here.
		if (window.vireoTabs) window.vireoTabs.wire(root);

		wireNavigation(root);
		wireCustomPanel(root);
		wireDriversMore(root);
		wireRangeSelect(root);
		wireSwitchLinks(root);
		wireFilterBar(root);
		runHeroTweens(root);

		root.querySelectorAll('[data-va-hero-spark]').forEach(renderHeroSparkline);

		const chartEl = root.querySelector('[data-va-chart]');
		if (chartEl) {
			const data = parseJsonAttr(chartEl, 'data-va-series') || [];
			const prev = parseJsonAttr(chartEl, 'data-va-series-prev') || [];
			const metric = chartEl.getAttribute('data-va-metric') || 'both';
			const notes = parseJsonAttr(chartEl, 'data-va-annotations') || {};
			const priorLabel = chartEl.getAttribute('data-va-prior-label') || '';
			// The template already printed an empty state when the range has no
			// pageviews. Drawing over it left a blank grid with a 0 to 1 axis.
			if (data.length && !chartEl.querySelector('.va-empty')) {
				const chart = new VaChart({ el: chartEl, data: data, prior: prev, metric: metric, notes: notes, priorLabel: priorLabel });

				// The chart is drawn at the container's width, so it has to be
				// redrawn when that width changes. Without this it would keep the
				// width it happened to be built at.
				let resizeTimer = null;
				let lastWidth = chartEl.clientWidth;
				window.addEventListener('resize', function () {
					if (chartEl.clientWidth === lastWidth) return;
					window.clearTimeout(resizeTimer);
					resizeTimer = window.setTimeout(function () {
						lastWidth = chartEl.clientWidth;
						chart.render();
					}, 150);
				});

				// Wire up in-page metric toggle. Clicking any button re-renders
				// the chart without a page reload; the URL is also updated so
				// bookmarking + sharing the page preserves the view.
				root.querySelectorAll('.va-chart-metric-btn').forEach(function (btn) {
					btn.addEventListener('click', function (ev) {
						ev.preventDefault();
						const m = btn.getAttribute('data-va-metric');
						if (!m || m === chart.metric) return;
						chart.setMetric(m);
						root.querySelectorAll('.va-chart-metric-btn').forEach(function (b) {
							const active = (b === btn);
							b.classList.toggle('is-active', active);
							b.setAttribute('aria-pressed', active ? 'true' : 'false');
						});
						try {
							const url = new URL(window.location.href);
							url.searchParams.set('chart_metric', m);
							window.history.replaceState({}, '', url.toString());
						} catch (e) { /* IE/legacy — leave URL as-is. */ }
					});
				});
			}
		}

		// A filter change replaces the grid element outright, so the fresh one
		// gets wired here while the old one is discarded.
		initSectionOrder();
	}

	// Dismiss the bot-filtering note and remember it per-user.
	// "Block this referrer" on a suspected-junk row: the host joins the
	// blocked list, its past rows leave the reports, and the page reloads.
	function initBlockReferrer() {
		document.addEventListener('click', function (ev) {
			const btn = ev.target.closest('[data-va-block-referrer]');
			if (!btn || !window.vireoDash || !window.vireoDash.blockReferrerNonce) return;
			const host = btn.getAttribute('data-va-block-referrer');
			if (!window.confirm(sprintf(
				/* translators: %s: a referrer host */
				__('Block %s? Its visits will be filtered like bots from now on and hidden from reports.', 'vireo-analytics'),
				host
			))) return;
			btn.disabled = true;
			postAdminAjax('vireo_block_referrer', window.vireoDash.blockReferrerNonce, { host: host })
				.then(function () { window.location.reload(); })
				.catch(function () { btn.disabled = false; });
		});
	}

	function initBotExplainer() {
		const box = document.querySelector('[data-va-bot-explainer]');
		if (!box) return;
		const btn = box.querySelector('[data-va-bot-explainer-dismiss]');
		if (!btn) return;
		btn.addEventListener('click', function () {
			box.remove();
			postAdminAjax('vireo_dismiss_bot_explainer', window.vireoDash.botExplainerNonce)
				.catch(function () { /* dismissal is best-effort; the box is already gone. */ });
		});
	}

	// The review request. Without JS its buttons post to admin-post and reload;
	// here they save in place. The review link still opens through admin-post in
	// a new tab, which records the choice before going on to wordpress.org.
	function initReviewPrompt() {
		const box = document.querySelector('[data-va-review]');
		if (!box) return;
		const form = box.querySelector('form');
		const nonce = form && form.querySelector('input[name="_wpnonce"]');

		function close() {
			box.remove();
			// Focus was on a button that no longer exists; put it somewhere sensible.
			const title = document.querySelector('.va-page-title');
			if (title) {
				title.setAttribute('tabindex', '-1');
				title.focus();
			}
		}

		const leave = box.querySelector('[data-va-review-leave]');
		if (leave) {
			leave.addEventListener('click', function () { setTimeout(close, 0); });
		}
		box.querySelectorAll('[data-va-review-choice]').forEach(function (btn) {
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				close();
				postAdminAjax('vireo_review_prompt', nonce ? nonce.value : '', { choice: btn.getAttribute('data-va-review-choice') })
					.catch(function () { /* best-effort, like the bot note; it can come back on the next load. */ });
			});
		});
	}

	// ---------------------------------------------------------------------
	// Section reordering
	//
	// The server renders sections in the user's saved order and ships the
	// handles with them, so a re-render after a filter change already comes
	// back correct — this only binds the interaction and POSTs the result.
	// ---------------------------------------------------------------------

	const SAVE_ACTION = 'vireo_save_section_order';
	const RESET_ACTION = 'vireo_reset_section_order';

	function sectionIds(grid) {
		return Array.prototype.slice.call(grid.querySelectorAll('.va-section'))
			.map(function (el) { return el.getAttribute('data-va-section'); })
			.filter(Boolean);
	}

	function postSectionOrder(action, order) {
		const params = {};
		if (order) params['order[]'] = order;
		// A failed save leaves the layout where the user put it; the next move
		// re-sends the whole order anyway, so there is nothing to recover.
		return postAdminAjax(action, window.vireoDash.sectionOrderNonce, params)
			.catch(function () { /* best-effort. */ });
	}

	// Key repeat on a held arrow fires ~30 moves a second. Move the DOM at once,
	// send the settled order once.
	let saveTimer = null;
	function queueSectionOrderSave(grid) {
		window.clearTimeout(saveTimer);
		saveTimer = window.setTimeout(function () {
			postSectionOrder(SAVE_ACTION, sectionIds(grid));
		}, 300);
		// The layout is now the reader's own, so offer the way back.
		const resetBtn = document.querySelector('[data-va-sections-reset]');
		if (resetBtn) resetBtn.hidden = false;
	}

	function announceSectionMove(section, position, total) {
		const live = document.getElementById('va-sections-live');
		if (!live) return;
		live.textContent = sprintf(
			/* translators: 1: section name, 2: new position, 3: total sections */
			__('%1$s moved to position %2$d of %3$d.', 'vireo-analytics'),
			section.getAttribute('aria-label') || '',
			position,
			total
		);
	}

	// Which section the pointer is over, and which side of its midpoint.
	// Third-width neighbours sit side by side, so they split horizontally while
	// full-width ones split vertically.
	function nearestSection(grid, x, y, placeholder) {
		const candidates = Array.prototype.slice.call(grid.children).filter(function (el) {
			return el !== placeholder && el.classList.contains('va-section');
		});
		for (let i = 0; i < candidates.length; i++) {
			const r = candidates[i].getBoundingClientRect();
			if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
				const before = candidates[i].dataset.vaWidth === 'full'
					? y < r.top + r.height / 2
					: x < r.left + r.width / 2;
				return { el: candidates[i], before: before };
			}
		}
		return null;
	}

	// A press only becomes a drag once the pointer has travelled this far, so
	// clicking the handle does nothing instead of lifting the card.
	const DRAG_THRESHOLD = 4;

	function startDrag(grid, section, handle, downEvent) {
		downEvent.preventDefault();

		const originX = downEvent.clientX;
		const originY = downEvent.clientY;
		const pointerId = downEvent.pointerId;

		let lifted = false;
		let placeholder = null;
		let frame = null;
		let pending = null;

		// Lifting moves the section to <body>, and moving a node out of the tree
		// releases any pointer capture it holds. Its pointer-events are also off
		// mid-drag. Listening on the handle therefore missed the pointerup, and
		// the card stayed pinned to the viewport. The window always hears it.
		function lift() {
			lifted = true;
			const rect = section.getBoundingClientRect();

			placeholder = document.createElement('div');
			placeholder.className = 'va-section-placeholder';
			placeholder.dataset.vaWidth = section.dataset.vaWidth;
			placeholder.style.minHeight = rect.height + 'px';

			// Pinned at its starting box and moved with a transform from here on:
			// a transform doesn't dirty layout, so the hit-test below never forces
			// a synchronous reflow of the whole page mid-drag.
			section.style.width = rect.width + 'px';
			section.style.height = rect.height + 'px';
			section.style.position = 'fixed';
			section.style.left = rect.left + 'px';
			section.style.top = rect.top + 'px';
			section.style.margin = '0';
			section.classList.add('is-va-dragging');

			grid.insertBefore(placeholder, section);
			document.body.appendChild(section);
		}

		function apply() {
			frame = null;
			const ev = pending;
			section.style.transform = 'translate3d(' + (ev.x - originX) + 'px,' + (ev.y - originY) + 'px,0)';

			const target = nearestSection(grid, ev.x, ev.y, placeholder);
			if (!target) return;
			const ref = target.before ? target.el : target.el.nextSibling;
			// Skip the DOM move when the placeholder is already there — otherwise
			// every move relays out the whole grid.
			if (placeholder.nextSibling !== ref) grid.insertBefore(placeholder, ref);
		}

		// One position update and one hit-test per frame, not per event.
		function onMove(ev) {
			if (ev.pointerId !== pointerId) return;
			if (!lifted) {
				if (Math.abs(ev.clientX - originX) < DRAG_THRESHOLD && Math.abs(ev.clientY - originY) < DRAG_THRESHOLD) return;
				lift();
			}
			pending = { x: ev.clientX, y: ev.clientY };
			if (frame === null) frame = requestAnimationFrame(apply);
		}

		function onUp(ev) {
			if (ev && ev.pointerId !== undefined && ev.pointerId !== pointerId) return;
			if (frame !== null) cancelAnimationFrame(frame);
			window.removeEventListener('pointermove', onMove);
			window.removeEventListener('pointerup', onUp);
			window.removeEventListener('pointercancel', onUp);
			window.removeEventListener('blur', onUp);

			if (!lifted) return;

			section.removeAttribute('style');
			section.classList.remove('is-va-dragging');
			grid.insertBefore(section, placeholder);
			placeholder.remove();
			handle.focus();

			const ids = sectionIds(grid);
			announceSectionMove(section, ids.indexOf(section.getAttribute('data-va-section')) + 1, ids.length);
			queueSectionOrderSave(grid);
		}

		window.addEventListener('pointermove', onMove);
		window.addEventListener('pointerup', onUp);
		window.addEventListener('pointercancel', onUp);
		// Alt-tabbing away mid-drag never delivers a pointerup.
		window.addEventListener('blur', onUp);
	}

	function initSectionOrder() {
		const grid = document.querySelector('[data-va-sections]');
		if (!grid || grid.classList.contains('is-va-sortable')) return;
		grid.classList.add('is-va-sortable');

		const resetBtn = document.querySelector('[data-va-sections-reset]');
		if (resetBtn) {
			// Only when there is something to reset. On the default order the
			// link sat under the cards doing nothing.
			resetBtn.hidden = !resetBtn.hasAttribute('data-va-sections-custom');
			resetBtn.addEventListener('click', function () {
				postSectionOrder(RESET_ACTION, null)
					.then(function () { window.location.reload(); });
			});
		}

		grid.querySelectorAll('[data-va-section-handle]').forEach(function (handle) {
			const section = handle.closest('.va-section');

			handle.addEventListener('pointerdown', function (ev) {
				if (ev.button !== 0) return;
				startDrag(grid, section, handle, ev);
			});

			handle.addEventListener('keydown', function (ev) {
				const back = (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp');
				const fwd = (ev.key === 'ArrowRight' || ev.key === 'ArrowDown');
				if (!back && !fwd) return;
				ev.preventDefault();

				const siblings = Array.prototype.slice.call(grid.querySelectorAll('.va-section'));
				const to = siblings.indexOf(section) + (back ? -1 : 1);
				if (to < 0 || to >= siblings.length) return;

				grid.insertBefore(section, back ? siblings[to] : siblings[to].nextSibling);

				// Moving the node drops focus in some browsers.
				handle.focus();

				announceSectionMove(section, to + 1, siblings.length);
				queueSectionOrderSave(grid);
			});
		});
	}

	// Delegated from the document, because a range change swaps the Overview's
	// nodes and the notice comes back with them until it has been dismissed.
	function initYearReview() {
		document.addEventListener('click', function (ev) {
			const dismiss = ev.target.closest('[data-va-yir-dismiss]');
			if (dismiss) {
				const notice = dismiss.closest('[data-va-yir-notice]');
				if (!notice) return;
				const year = notice.getAttribute('data-year');
				notice.remove();
				postAdminAjax('vireo_dismiss_year_review', window.vireoDash.yearReviewNonce, { year: year })
					.catch(function () { /* best-effort; it can come back on the next load. */ });
				return;
			}

			// Every report has a print button; the browser's print dialog is
			// also where "Save as PDF" lives, and print.css shapes the page.
			if (ev.target.closest('[data-va-yir-print], [data-va-print]')) {
				window.print();
				return;
			}

			const copy = ev.target.closest('[data-va-yir-copy]');
			if (!copy) return;
			const box = document.querySelector('[data-va-yir-text]');
			const status = document.querySelector('[data-va-yir-copied]');
			if (!box) return;

			function done(ok) {
				if (status) status.textContent = ok ? __('Copied', 'vireo-analytics') : __('Select the text and copy it yourself', 'vireo-analytics');
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(box.value).then(function () { done(true); }, function () { done(false); });
				return;
			}
			// Plain-http admin screens have no Clipboard API.
			box.removeAttribute('aria-hidden');
			box.select();
			let ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			box.setAttribute('aria-hidden', 'true');
			done(ok);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { init(); initBotExplainer(); initBlockReferrer(); initReviewPrompt(); initYearReview(); });
	} else {
		init();
		initBotExplainer();
		initBlockReferrer();
		initReviewPrompt();
		initYearReview();
	}

	window.VireoAnalytics = window.VireoAnalytics || {};
	window.VireoAnalytics.Chart = VaChart;
	window.VireoAnalytics.tweenNumber = tweenNumber;
	window.VireoAnalytics.abbrev = abbrev;

	/* Chart notes --------------------------------------------------------------
	 *
	 * Kept out of the chart renderer: these are a list beside it, not part of the
	 * drawing, and the tooltip picks the same text up from its own data.
	 */
	(function () {
		const ui = document.querySelector('[data-va-annotations-ui]');
		if (!ui) return;

		const nonce = ui.getAttribute('data-va-nonce') || '';
		const list = ui.querySelector('[data-va-annotations-list]');
		const form = ui.querySelector('[data-va-annotation-form]');
		const errorEl = ui.querySelector('[data-va-annotation-error]');
		const emptyEl = ui.querySelector('[data-va-annotations-empty]');

		function showError(message) {
			if (!errorEl) return;
			errorEl.textContent = message || '';
		}

		function bindRemove(button) {
			button.addEventListener('click', function () {
				const row = button.closest('[data-va-annotation]');
				if (!row) return;
				const id = row.getAttribute('data-va-annotation');

				button.disabled = true;
				postAdminAjax('vireo_annotation_delete', nonce, { id: id, site: window.vireoDash.site || 0 })
					.then(function (res) {
						if (!res || !res.success) {
							throw new Error((res && res.data && res.data.message) || '');
						}
						row.remove();
						if (emptyEl && list && !list.children.length) {
							emptyEl.hidden = false;
						}
					})
					.catch(function (e) {
						button.disabled = false;
						showError(e.message || '');
					});
			});
		}

		ui.querySelectorAll('[data-va-annotation-remove]').forEach(bindRemove);

		if (!form || !list) return;

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			showError('');

			const dateEl = form.querySelector('[name="date"]');
			const labelEl = form.querySelector('[name="label"]');
			const date = dateEl ? dateEl.value : '';
			const label = labelEl ? labelEl.value.trim() : '';
			if (!date || !label) return;

			const submit = form.querySelector('button[type="submit"]');
			if (submit) submit.disabled = true;

			postAdminAjax('vireo_annotation_add', nonce, { date: date, label: label, site: window.vireoDash.site || 0 })
				.then(function (res) {
					if (!res || !res.success || !res.data) {
						throw new Error((res && res.data && res.data.message) || '');
					}

					const li = document.createElement('li');
					li.setAttribute('data-va-annotation', String(res.data.id));

					const dateSpan = document.createElement('span');
					dateSpan.className = 'va-annotation-date';
					dateSpan.textContent = fmtDateMedium(res.data.date);

					const labelSpan = document.createElement('span');
					labelSpan.className = 'va-annotation-label';
					// textContent, not innerHTML: this string came back from the
					// server but it originated with a user, and there is no reason
					// for it to be markup.
					labelSpan.textContent = res.data.label;

					const remove = document.createElement('button');
					remove.type = 'button';
					remove.className = 'va-annotation-remove';
					// Same hook the server-rendered rows carry.
					remove.setAttribute('data-va-annotation-remove', '');
					remove.setAttribute('aria-label', sprintf(
						/* translators: %s: the date a note is pinned to. */
						__('Remove the note for %s', 'vireo-analytics'),
						fmtDateMedium(res.data.date)
					));
					remove.textContent = '×';

					li.appendChild(dateSpan);
					li.appendChild(labelSpan);
					li.appendChild(remove);
					list.appendChild(li);
					bindRemove(remove);

					if (emptyEl) emptyEl.hidden = true;
					if (labelEl) labelEl.value = '';
				})
				.catch(function (err) {
					showError(err.message || '');
				})
				.finally(function () {
					if (submit) submit.disabled = false;
				});
		});
	})();

	/* PageSpeed lab test -------------------------------------------------------
	 *
	 * Runs only on a click. The request stays open while Google tests the page,
	 * usually 20 to 40 seconds, and comes back with the rendered result. Its
	 * errors carry a message in the body, so the reply is read whatever the
	 * status, unlike postAdminAjax().
	 */
	document.querySelectorAll('[data-va-psi]').forEach(function (box) {
		const status = box.querySelector('[data-va-psi-status]');
		const noAnswer = __('No answer yet. If the test finishes, its result shows here when you reload the page in a minute.', 'vireo-analytics');
		let strategy = 'mobile';
		let running = false;

		function say(message) {
			if (status) status.textContent = message || '';
		}

		// "Tested by Google" and the lab pill only while the panel on screen
		// holds a result; otherwise the "Test ... with Google" heading.
		function syncHeading() {
			const cta = box.querySelector('[data-va-psi-heading="cta"]');
			const done = box.querySelector('[data-va-psi-heading="done"]');
			if (!cta || !done) return;
			const active = box.querySelector('[data-va-psi-panel="' + strategy + '"]');
			const shown = !!(active && active.querySelector('.va-psi-result'));
			cta.hidden = shown;
			done.hidden = !shown;
		}

		box.querySelectorAll('[data-va-psi-strategy]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				strategy = btn.getAttribute('data-va-psi-strategy') || 'mobile';
				box.querySelectorAll('[data-va-psi-strategy]').forEach(function (b) {
					b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
				});
				box.querySelectorAll('[data-va-psi-panel]').forEach(function (p) {
					p.hidden = p.getAttribute('data-va-psi-panel') !== strategy;
				});
				syncHeading();
				if (!running) say('');
			});
		});

		box.addEventListener('click', function (e) {
			const btn = e.target.closest('[data-va-psi-run]');
			if (!btn || running) return;

			const target = box.querySelector('[data-va-psi-panel="' + strategy + '"]');
			if (!target) return;

			running = true;
			box.setAttribute('aria-busy', 'true');
			box.querySelectorAll('[data-va-psi-run], [data-va-psi-strategy]').forEach(function (b) { b.disabled = true; });

			const started = Date.now();
			function tick() {
				const secs = Math.round((Date.now() - started) / 1000);
				/* translators: %d: seconds elapsed */
				say(sprintf(__('Testing on Google\'s servers, which takes up to a minute. %ds so far.', 'vireo-analytics'), secs));
			}
			tick();
			const timer = setInterval(tick, 1000);

			const body = new URLSearchParams();
			body.set('action', 'vireo_pagespeed_run');
			body.set('nonce', box.getAttribute('data-va-psi-nonce') || '');
			body.set('target', box.getAttribute('data-va-psi-target') || '');
			body.set('id', box.getAttribute('data-va-psi-id') || '0');
			body.set('strategy', strategy);
			if (btn.hasAttribute('data-va-psi-force')) body.set('force', '1');

			fetch(window.vireoDash.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			})
				.then(function (resp) {
					return resp.json().catch(function () { return null; });
				})
				.then(function (res) {
					if (res && res.success && res.data && typeof res.data.html === 'string') {
						// Escaped server-side by the same partial the page load uses.
						target.innerHTML = res.data.html;
						syncHeading();
						say(__('Test finished.', 'vireo-analytics'));
						return;
					}
					say((res && res.data && res.data.message) || noAnswer);
				})
				.catch(function () {
					say(noAnswer);
				})
				.finally(function () {
					clearInterval(timer);
					running = false;
					box.removeAttribute('aria-busy');
					box.querySelectorAll('[data-va-psi-run], [data-va-psi-strategy]').forEach(function (b) { b.disabled = false; });
				});
		});
	});

})();
