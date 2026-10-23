// Visual QA for the Overview: every filter and compare state at 390/768/1440,
// light and dark, with in-page layout assertions and full-page screenshots.
//
//   node tests/visual/dashboard-qa.mjs [outDir]
//
// Needs a running site (VA_URL, default http://localhost:8980) with the
// admin/password user and the rows from tests/wp/seed-dashboard.php, a Chrome
// at VA_CHROME, and playwright-core: either installed next to this file or
// named by PLAYWRIGHT_CORE (a path to its index.mjs). Exits non-zero when a
// render fails an assertion or the known-bad control passes.
import fs from 'node:fs';
import path from 'node:path';

const { chromium } = await import(process.env.PLAYWRIGHT_CORE ?? 'playwright-core');

const BASE = process.env.VA_URL ?? 'http://localhost:8980';
const OUT = process.argv[2] ?? 'visual-qa-out';
const WIDTHS = [390, 768, 1440];
const SCHEMES = ['light', 'dark'];
const CHROME = process.env.VA_CHROME ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

fs.mkdirSync(OUT, { recursive: true });

// Runs inside the page. Returns a list of problems.
function audit() {
  const problems = [];
  const vis = (el) => {
    const s = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const inClosedDetails = el.closest('details:not([open])') && !el.closest('summary') && !el.matches('details');
    return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0 && !inClosedDetails && !el.closest('[hidden]');
  };
  const name = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
  const root = document.querySelector('.va-root');
  if (!root) { problems.push('no .va-root rendered'); return problems; }

  if (document.documentElement.scrollWidth > innerWidth + 1) problems.push(`horizontal scroll: page is ${document.documentElement.scrollWidth}px wide in a ${innerWidth}px viewport`);
  if (/Fatal error|Warning: |Notice: |Deprecated: /.test(document.body.innerText)) problems.push('PHP notice text on the page');

  for (const el of root.querySelectorAll('h1,h2,h3,p,li,a,button,span,label,select,div.va-hero-num,div.va-hero-sub,div.va-hero-prior')) {
    if (!vis(el) || el.closest('.screen-reader-text')) continue;
    const s = getComputedStyle(el);
    if (el.scrollWidth > el.clientWidth + 2 && s.overflowX !== 'visible' && s.textOverflow !== 'ellipsis' && !el.matches('select') && s.webkitLineClamp === 'none') problems.push(`clipped text in ${name(el)}: "${el.textContent.trim().slice(0, 40)}"`);
    const r = el.getBoundingClientRect();
    if (r.right > innerWidth + 1 || r.left < -1) {
      // A row that scrolls on purpose (the report tabs under 640px, which Pro
      // extends past one phone width) is not a layout fault, as long as the
      // scroller itself fits and the page does not scroll sideways.
      let scroller = null;
      for (let p = el.parentElement; p && p !== root && !scroller; p = p.parentElement) {
        if (/auto|scroll/.test(getComputedStyle(p).overflowX)) scroller = p;
      }
      const sr = scroller?.getBoundingClientRect();
      if (!(sr && sr.left >= -1 && sr.right <= innerWidth + 1)) problems.push(`${name(el)} sticks out of the viewport (${Math.round(r.left)}..${Math.round(r.right)}): "${el.textContent.trim().slice(0, 40)}"`);
    }
  }

  // Cards share the grid; none may overlap a sibling.
  const cards = [...root.querySelectorAll('.va-card')].filter(vis);
  for (let i = 0; i < cards.length; i++) for (let j = i + 1; j < cards.length; j++) {
    if (cards[i].contains(cards[j]) || cards[j].contains(cards[i])) continue;
    const a = cards[i].getBoundingClientRect(), b = cards[j].getBoundingClientRect();
    const ox = Math.min(a.right, b.right) - Math.max(a.left, b.left), oy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
    if (ox > 2 && oy > 2) problems.push(`${name(cards[i])} overlaps ${name(cards[j])} by ${Math.round(ox)}x${Math.round(oy)}`);
  }

  // Tap targets on the controls.
  for (const el of root.querySelectorAll('.va-row-filter, .va-views-btn, .va-filter-tag, .va-preset-btn, .va-compare-toggle')) {
    if (!vis(el)) continue;
    const r = el.getBoundingClientRect();
    const after = getComputedStyle(el, '::after');
    const extended = after.content !== 'none' && after.position === 'absolute';
    if (r.height < 24 && !extended) problems.push(`small tap target ${name(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
  }

  // The feature under test: prior lines carry dates; chips name the filter.
  for (const el of root.querySelectorAll('.va-hero-prior')) {
    if (!vis(el)) continue;
    if (!el.querySelector('.va-hero-prior-dates')?.textContent.trim()) problems.push('a prior line has no dates');
  }

  // The world map draws lazily, once scrolled near; by the time the audit runs
  // it has been scrolled to, so a visible map must hold shaded countries.
  for (const map of root.querySelectorAll('[data-va-map]')) {
    if (!vis(map)) continue;
    const rows = JSON.parse(map.getAttribute('data-va-map-data') || '[]').filter((r) => Number(r.visitors) > 0);
    const shaded = [...map.querySelectorAll('svg path[id]')].filter((p) => { const f = (p.getAttribute('fill') || '').toLowerCase(); return f && f !== '#eef2f7'; });
    if (!map.querySelector('svg')) problems.push('map has no SVG drawn');
    else if (rows.length && !shaded.length) problems.push(`map has ${rows.length} countries with visitors but no shaded region`);
    else if (shaded.length > rows.length) problems.push(`map shades ${shaded.length} regions for ${rows.length} countries`);
  }
  return problems;
}

async function login(page) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
}

async function nonce(page) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=vireo-analytics`);
  return page.evaluate(() => new URL(document.querySelector('.va-preset-btn').href).searchParams.get('_vrn'));
}

const states = (n) => ({
  'default':            `period=last_30&compare=1`,
  'year':               `period=last_30&compare=year`,
  'year-90':            `period=last_90&compare=year`,
  'source-search':      `period=last_30&compare=1&source=search`,
  'referrer-google':    `period=last_30&compare=year&ref=google.com`,
  'page-pricing':       `period=last_30&compare=1&path=%2Fpricing%2F`,
  'page-x-source':      `period=last_30&compare=1&path=%2Fpricing%2F&source=search`,
  'page-x-direct':      `period=last_30&compare=1&path=%2Fpricing%2F&source=direct`,
  'device-mobile':      `period=last_30&compare=1&device=mobile`,
  'country-es':         `period=last_30&compare=1&country=ES`,
  'unsupported':        `period=last_30&compare=1&device=mobile&country=ES`,
  'bare-page-x-source': `period=last_30&compare=1&path=%2Fcategory%2Fnews%2F&source=search`,
  'no-compare':         `period=last_30&compare=0&source=ai`,
});

// Scroll the map into view so its IntersectionObserver fires, then wait for
// the fetched SVG to be shaded. A full-page screenshot alone never scrolls,
// which left an empty grey box in the picture where a reader sees a map.
async function settleMap(page) {
  const map = page.locator('[data-va-map]:visible').first();
  if (!(await map.count())) { await page.waitForTimeout(300); return; }
  await map.scrollIntoViewIfNeeded();
  await page.waitForFunction(() => {
    const m = document.querySelector('[data-va-map]:not([hidden])');
    return m && m.querySelector('svg path[id][fill]');
  }, null, { timeout: 10000 }).catch(() => {});
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(300);
}

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const report = [];
const context0 = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page0 = await context0.newPage();
await login(page0);
const vrn = await nonce(page0);
const cookies = await context0.cookies();
await context0.close();

for (const scheme of SCHEMES) {
  for (const width of WIDTHS) {
    const context = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, colorScheme: scheme, reducedMotion: 'reduce', deviceScaleFactor: 1 });
    await context.addCookies(cookies);
    const page = await context.newPage();
    await page.addStyleTag !== undefined;
    for (const [state, qs] of Object.entries(states(vrn))) {
      const url = `${BASE}/wp-admin/admin.php?page=vireo-analytics&_vrn=${vrn}&${qs}`;
      await page.goto(url, { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: '*{animation:none!important;transition:none!important;scroll-behavior:auto!important}' });
      await settleMap(page);
      const problems = await page.evaluate(audit);
      const key = `${state}-${width}-${scheme}`;
      await page.screenshot({ path: path.join(OUT, `${key}.png`), fullPage: true });
      report.push({ key, problems });
    }
    await page.close();
    await context.close();
  }
}

// Control: the audit must fail on a known-bad page.
{
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await context.addCookies(cookies);
  const page = await context.newPage();
  await page.goto(`${BASE}/wp-admin/admin.php?page=vireo-analytics&_vrn=${vrn}&period=last_30`, { waitUntil: 'networkidle' });
  await settleMap(page);
  // A <p>, so the per-element "sticks out" check fires as well as the page
  // scroll one; it sits outside any scroll container.
  await page.evaluate(() => { const d = document.createElement('p'); d.style.width = '900px'; d.style.height = '10px'; d.textContent = 'x'; document.querySelector('.va-root').appendChild(d); });
  const bad = (await page.evaluate(audit)).filter((p) => /horizontal scroll|sticks out/.test(p));
  const bothSeen = bad.some((p) => p.startsWith('horizontal scroll')) && bad.some((p) => p.includes('sticks out'));
  report.push({ key: 'CONTROL-known-bad: wide element (must list both problems)', problems: bothSeen ? bad : [] });
  // And a map whose countries were never shaded.
  await page.evaluate(() => { document.querySelectorAll('[data-va-map] svg path[id]').forEach((p) => p.setAttribute('fill', '#eef2f7')); });
  const unshaded = await page.evaluate(audit);
  report.push({ key: 'CONTROL-known-bad: unshaded map (must list problems)', problems: unshaded.filter((p) => p.startsWith('map ')) });
  await context.close();
}
await browser.close();

let failing = 0;
for (const r of report) {
  const isControl = r.key.startsWith('CONTROL');
  const ok = isControl ? r.problems.length > 0 : r.problems.length === 0;
  if (!ok) failing++;
  console.log(`${ok ? 'PASS' : 'FAIL'} ${r.key}${r.problems.length ? '\n  - ' + r.problems.slice(0, 12).join('\n  - ') : ''}`);
}
console.log(`\n${report.length} renders audited, ${failing} failing, screenshots in ${OUT}`);
process.exit(failing ? 1 : 0);
