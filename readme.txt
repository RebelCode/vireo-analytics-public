=== Vireo Analytics – Cookieless WordPress Stats & Google Analytics Alternative ===
Contributors: RebelCode, jeangalea
Tags: analytics, statistics, cookieless-analytics, google-analytics-alternative, privacy
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.17.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Self-hosted WordPress analytics that imports your Google Analytics history, shows the searches behind each post, and filters out bots. No cookies.

== Description ==

Vireo Analytics is self-hosted, cookieless analytics for WordPress. As a Google Analytics alternative, it brings your history with you when you switch, shows the Google searches behind each post, and tells you what AI assistants take from your site against the readers they send back. Visitor data stays in your own database, with no consent banner.

= Who it's for =

Vireo is built for bloggers, publishers, shops and small businesses that want to know what people read and how they found it. It's also made for agencies looking after client sites: each client's old analytics history comes across on import, and clients can get read-only access to the dashboard without being administrators. If you run a WooCommerce or Easy Digital Downloads store, Vireo Pro traces each sale to the page and source that brought the buyer.

= Bring your history with you =

Switching analytics tools usually means your charts start again on the day you switch. Vireo imports your daily traffic, pages, referrers, countries and devices from Google Analytics 4, so the years you already have stay in front of you.

It also imports from Koko Analytics, Jetpack Stats, Statify, WP Statistics, Burst Statistics, Independent Analytics, SlimStat and Plausible (from Plausible's CSV export), bringing whatever each one kept: referrers per post, browsers and devices, missing pages, outbound clicks, downloads and custom events where the source recorded them. Imports run in batches you can watch, stop the day before Vireo's own data begins so no day is counted twice, and ask before importing a range you've already brought in. The [switching guides](https://vireowp.com/import/) show what comes across from each one.

= The searches behind each post =

Google stopped passing search terms to websites years ago, so most analytics plugins see a visit from Google and nothing more. Connect Search Console once and every post's stats screen lists the searches it appeared for, with clicks, impressions and average position. The Overview shows the landing page beside each search.

= What AI takes, and what it sends back =

ChatGPT, Claude, Gemini, Perplexity and Copilot are reported as their own traffic source. Next to the readers they send, Vireo counts the pages their crawlers fetched, so you can see the ratio for your own site. It works on the history you've already collected.

The AI visibility scan, on the same screen, fetches a page of your site the way an AI crawler does and lists what could be keeping the crawlers out: robots.txt rules, a firewall answering GPTBot, ClaudeBot or PerplexityBot with an error, text that only appears with JavaScript, a missing sitemap. Each failed check says how to fix it. The scan runs when you press the button, and its own requests aren't counted in your reports.

= Numbers you can check =

Over 800 known crawlers, AI trainers, SEO tools, uptime monitors and headless browsers are kept out of your numbers. Vireo reports how many it excluded and why, so a figure lower than your server logs makes sense. Automated browsers that claim to be Chrome are caught by the flags automation tools set on themselves, and prefetched pages nobody looked at aren't counted. The [bot directory](https://vireowp.com/bots/) lists every one Vireo knows.

= Headless WordPress: Astro, Next.js and others =

If your posts live in WordPress but visitors read them on an Astro, Next.js or other front end, Vireo can count that front end too. Enter its address in Settings, paste one script tag into the front end, and each pageview is matched to the WordPress post it shows, so per-post stats and Search Console searches keep working. It's free for one front end.

= Your other sites, in the same dashboard =

Run a static site, a docs site or a landing page next to your WordPress site? Add it under Settings and paste one tag into it. Its visits are counted on their own, without cookies, and a site selector on the Overview switches between your sites. One connected site is free.

= Everything else =

* An Overview that opens with what changed since the previous period, and the pages and sources behind it.
* A Popular posts block, shortcode and widget built on your real, bot-filtered view counts, plus a view counter for single posts.
* A Year in review for any year with data, including the history you imported from another tool, and weekly highlights on the Overview and in the weekly email.
* Top pages, referrers grouped by how people arrived, countries and devices, with filters for each.
* Average time on page and bounce rate, with each page's time in Top pages. Time counts only while the tab is in front of the reader.
* Core Web Vitals from your real visitors, graded at the 75th percentile the way Google grades them, with Google's PageSpeed lab test of any page one click away.
* Notes pinned to a date, so a spike still has its explanation weeks later.
* A weekly email digest, CSV export of any view, and a views column on the Posts screen.
* Custom events for clicks and form submissions, and 404 tracking to find broken links.
* Read-only dashboard access for editors or clients, without making them administrators.
* An MCP server, so Claude, Cursor or any other AI assistant can answer questions about your traffic from the real numbers. A REST API and WordPress Abilities for everything else.
* Multisite support, with one view across the network.
* Headless WordPress support: count visits to the Astro, Next.js or other front end that shows your WordPress content.
* Connected sites: count visits to your other sites, WordPress or not, from the same dashboard.
* A warning in Site Health if recording stops, so a broken setup doesn't look like a quiet week.
* Reports for WP RSS Aggregator and Spotlight sites: clicks through to aggregated articles per feed source, and what visitors do with Instagram feeds.
* Translated into German, Spanish, French, Italian, Japanese, Dutch, Portuguese (Portugal and Brazil) and Russian.

= Vireo Pro: see which pages and visitors make you money =

The free plugin is complete on its own. Vireo Pro is for sites that sell something or report to clients:

* Revenue from WooCommerce, Easy Digital Downloads, FluentCart and Freemius, traced to the page and source that brought each buyer.
* Goals and funnels, with each step's conversion on screen.
* Shareable read-only dashboards for clients, with an expiry date and a password.
* More connected sites, a live view, entry and exit pages, and a weekly email that explains what changed.

Pro starts at $60 for the first year on one site, with a 30-day money-back guarantee. [See Vireo Pro and pricing](https://vireowp.com/pricing/).

= Privacy =

Vireo sets no cookies and stores nothing on visitors' devices. Visitors are counted with a salted hash that changes every day, so nobody can be followed from one day to the next. Do Not Track and Global Privacy Control are honoured without a setting. Visitor data is never sent to RebelCode or anyone else; the only outside services are the ones you choose to connect, listed under External services below.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/vireo-analytics/`, or install via Plugins → Add New and search for "Vireo Analytics".
2. Activate the plugin through the Plugins menu.
3. Visit **Vireo Analytics → Overview** to see your dashboard. First pageviews will appear within a minute.

To bring your history across from Google Analytics or another plugin, go to **Vireo Analytics → Settings → Import data**. If another supported analytics plugin is installed, the Overview offers to import from it.

== Frequently Asked Questions ==

= Is there a paid version? =

Yes, Vireo Pro. Everything described above stays free. Pro adds revenue and conversion reports for stores, goals and funnels, client dashboards and more connected sites. If you stop paying, the free plugin keeps working with all your data. [Pricing is on vireowp.com](https://vireowp.com/pricing/).

= Is this GDPR-compliant? =

Yes. Vireo Analytics doesn't set cookies, doesn't use localStorage for tracking, and hashes visitor identifiers with a salt that rotates every 24 hours. No consent banner is required for basic analytics. Country-level geolocation is derived from the IP on the server; the raw IP is never written to the analytics tables, and the short-lived buffer file holding it is deleted as soon as the aggregator processes it, usually within a minute. You should still mention the plugin in your privacy policy.

= What exactly does the "Visitors" number mean? =

Within a single day it's a true unique count: one person is counted once no matter how many pages they read. Over a longer range it's the sum of the daily counts, so someone who visited on three days in the month counts three times.

That isn't laziness, it's the privacy model. A visitor is identified by a hash of their IP and browser, salted with a secret that is thrown away and regenerated every night. Tomorrow the same person produces a completely different hash, so there is nothing that can link them back to today. That's what makes cookie-free, consent-free tracking legitimate rather than a technicality. The cost is that "unique people this month" is a question the data genuinely cannot answer, and we'd rather say so than print a number that looks precise and isn't.

Pageviews are exact over any range.

= Does it honour Do Not Track and Global Privacy Control? =

Yes, and it's on by default. If a visitor's browser sends `DNT: 1` or `Sec-GPC: 1`, the tracker refuses to fire for them, and the collection endpoint refuses the pageview even if something sends one anyway. Those visits are not recorded anywhere. This costs you some traffic in the dashboard, which is the point: an opt-out you can switch off isn't an opt-out. If you have a specific reason to override it, the `vireo_honor_opt_out` filter can return false.

= Why are my numbers lower than another plugin's? =

Most analytics plugins count some automated traffic as visitors: uptime monitors (StatusCake, UptimeRobot, Pingdom), SEO crawlers (Ahrefs, Semrush, MJ12), AI crawlers (GPTBot, ClaudeBot, PerplexityBot) and testing tools. Vireo filters those out and shows you what it removed, by category, so you can check the difference yourself. If you'd rather count some of them, the `vireo_bot_ua_pattern` filter changes the pattern.

= Can I migrate from Jetpack Stats? =

Yes. Settings, then Import data, pulls your daily pageviews, top posts and referrers from the WordPress.com Stats API for whatever date range you choose. You paste in your WordPress.com API key and the site address the stats are recorded under; Jetpack doesn't need to be active or connected. Jetpack Stats has no visitor counts, so imported days show pageviews only.

= Can I migrate from Google Analytics? =

Yes. Settings, then Import data, brings your history across: daily pageviews and visitors, your pages, your referrers and which pages they sent readers to, countries, devices, outbound clicks, downloads, form submissions and your own custom events. It reads the Analytics Data API directly, so you need a Google service account key and your numeric property ID rather than the G- measurement ID. Sessions, conversions and the events GA4 collects on its own (page views, scrolls, session starts) do not come across.

= Can I migrate from Plausible Analytics? =

Yes. Export your site from Plausible (Site settings, then Imports & Exports), then upload the zip under Settings, then Import data. Daily pageviews and visitors, pages, referrers, countries, device types, goals and custom events come across, with outbound clicks, file downloads and 404s landing in the same reports as Vireo's own. No API key is needed, and the uploaded file is deleted as soon as it has been read. Browsers, operating systems, entry and exit pages and custom properties are not imported.

= Can I ask an AI assistant about my stats? =

Yes. Vireo runs an MCP server at /wp-json/vireo-analytics/mcp, so Claude Code, Claude Desktop, Cursor and other MCP clients can ask about traffic, what changed, which pages are growing, what people searched for, which AI assistants send readers, broken links and more. Open Settings, Search Console & API and copy the ready-made configuration for your client. The assistant signs in with an application password you create on your profile, sees only what that user can see in the dashboard, and can read, never change anything. Nothing is sent anywhere: your assistant connects to your site directly.

= Does Vireo share anything with MainWP? =

Only if the site is connected to a MainWP dashboard through MainWP Child. Then the dashboard can read the site's visitor and pageview totals, top pages and top referrers, over MainWP's own signed connection. Nothing about individual visitors is shared, and the dashboard already has administrator access to the site. To switch it off, add `add_filter( 'vireo_mainwp_enabled', '__return_false' );` to the site.

= Can I show my most popular posts? =

Yes. Add the Popular posts block anywhere blocks work, including block theme templates, or use the `[vireo_popular_posts]` shortcode or the Popular posts (Vireo) widget on classic themes. Choose how many posts, the period (last 7, 30 or 90 days, or all time), which post types, and whether to show featured images and view counts. It uses the same bot-filtered counts as your dashboard, lists only published posts that aren't password-protected, adds no tracking, and is cached so it stays fast. To show a single post's views, use the Post views block or `[vireo_views]`.

= Does it work with caching plugins? =

Yes. Tracking runs on every page load via a lightweight POST beacon, including cached pages. When you first activate the plugin, you may need to wait for your cache to regenerate before every page starts tracking. Once that happens, tracking is universal.

= My theme or landing page doesn't count visits. Why? =

The tracker loads in the footer through `wp_footer()`. Some themes, page builders and landing-page templates leave that call out, so the tracker never loads. Add `<?php do_action( 'vireo_print_tracking_script' ); ?>` just before `</body>` in that template. It's safe on pages that also call `wp_footer()`: the tracker only prints once.

= My site is headless. Can it count visits to my Astro or Next.js front end? =

Yes. Under Settings, then Tracking, enter the front end's address under "Headless front end". Pages on that site can then send pageviews to this WordPress site; anything from another address is refused. Paste the script tag shown under the setting into every page of the front end. It counts Astro view transitions on its own, and with `data-spa="history"` it counts page changes on other single-page front ends. Each pageview is matched to the WordPress post at the same path, so a front end that keeps the permalink structure needs nothing else, and Top pages, the per-post view counts and Search Console per post all work as they do for a theme. One front end per site.

Two things stay out of reach. Crawlers are counted when they fetch a page from WordPress, so the crawler and AI-bot numbers do not include requests made to the front end. And Core Web Vitals are not collected from the front end yet.

= How does it compare to Google Analytics? =

Google Analytics shows you more (segmentation, audiences, attribution modelling) but requires a cookie banner, sends your visitors' data to Google, and has become increasingly complex. Vireo Analytics answers "how many people visited what, from where, on what device", which is what most WordPress site owners actually look at. For site owners who don't need enterprise-grade attribution modelling, the tradeoff is usually worth it.

= Does it track logged-in admins? =

By default, no. There's a setting at **Vireo Analytics → Settings** to enable admin tracking if you want to see your own visits (useful for testing).

= How long is data kept? =

Raw visitor hashes and referrer rows are aggregated into daily rollups after 24 hours. Daily rollups are kept indefinitely unless you set a retention limit in **Settings → Retention**. The default retention is 730 days (two years).

= Will it slow down my site? =

The tracking beacon is fired asynchronously after the page is interactive, using the browser's `navigator.sendBeacon()` API where available. The pageview is recorded by a standalone endpoint that boots WordPress without loading plugins or your theme. Hosts that block direct PHP inside wp-content fall back to admin-ajax automatically. On a well-hosted site you will not notice a difference.

== External services ==

This plugin includes an optional importer for historical Jetpack Stats data. When (and only when) a site owner runs that importer from **Vireo Analytics → Settings → Import data**, the plugin calls the WordPress.com Stats API at `https://stats.wordpress.com/csv.php` to retrieve the site's existing pageview, referrer, and top-post history.

What is sent:

* The WordPress.com API key the site owner pastes into the import screen.
* The site address whose stats are being imported.
* A requested date range and table name (e.g. `views`, `referrers`, `topposts`).

What is not sent: no visitor data, no user data, no site content, no credentials beyond the API key the site owner entered.

When it is sent: only while the import is actively running, in response to a site owner clicking "Run Import". No background or recurring calls are made.

The Stats API is provided by Automattic Inc. (WordPress.com).

* Terms of Service: https://wordpress.com/tos/
* Privacy Policy: https://automattic.com/privacy/

The importer is entirely optional.

= Google Analytics (optional, importer) =

When a site owner runs the Google Analytics importer, the plugin signs a request with the service-account key they pasted and calls `https://oauth2.googleapis.com/token` for an access token, then `https://analyticsdata.googleapis.com` to read that property's history.

What is sent: the service account's own credentials and a date range. No visitor data, no site content, nothing about anyone reading the site.

When it is sent: only while an import is running, in response to a site owner starting one. The key can be removed from the same screen afterwards.

= Google Search Console (optional) =

When a site owner connects Search Console, the plugin authenticates the same way and calls `https://searchconsole.googleapis.com` to read the searches that led people to the site.

What is sent: the service account's own credentials, the property address the owner entered, and a date range.

When it is sent: once a day on a schedule, and whenever the owner presses the refresh control. A refresh fetches the last week straight away and then the rest of the requested history in the background, a fortnight at a time over a few minutes, so that one press can produce several calls in a row. This is the one recurring outbound call the plugin makes, and it only happens on sites that have connected Search Console. Disconnecting stops it and removes the schedule.

Both Google APIs are provided by Google LLC.

* Terms of Service: https://policies.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= Google PageSpeed Insights (optional) =

The Page speed tab and each post's stats screen have a button for Google's PageSpeed Insights lab test. It works one of two ways.

Without an API key (the default), the button is a link. It opens `https://pagespeed.web.dev/` in the admin's own browser, with the address of the page being tested in the link, and Google runs the test there. The plugin's server sends nothing and stores nothing.

With an API key the site owner adds under Settings, the plugin calls `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` from the server and shows the result on the dashboard.

What is sent: the address of the page being tested, which is always a page on this site or on a connected site, the device to test as (mobile or desktop), the admin's language, and, on the API path, the site owner's API key. No visitor data and nothing about anyone reading the site.

When it is sent: only when someone with access to the Vireo dashboard presses the button. Nothing runs on a schedule or on the front end. API results are kept for a day so the page can show them again without another test.

PageSpeed Insights is provided by Google LLC.

* Terms of Service: https://developers.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= DB-IP (optional, geolocation) =

Country-level geolocation needs a local database, and Pro's city-level detail needs a second one. When a site owner presses the download button, the plugin fetches the current monthly file from `https://download.db-ip.com`.

What is sent: nothing but the request for the file. No site data, no visitor data, no identifiers. Lookups afterwards happen entirely on your own server against the downloaded file; no address is ever sent anywhere.

When it is sent: only when the owner presses the button.

DB-IP Lite data is provided by DB-IP under CC BY 4.0.

* Website: https://db-ip.com/
* Privacy Policy: https://db-ip.com/legal/privacy

A site that imports nothing, connects nothing, adds no PageSpeed API key and downloads no geolocation database contacts none of these services from its own server.

It does make one recurring request to itself: once an hour the plugin posts to its own tracking endpoint to check the fast path is still reachable, because a host rule or a CDN change can break it silently. That request never leaves your server and carries no data about anyone.

None of these paths send visitor data anywhere. Every one of them pulls data in.

== Screenshots ==

1. The overview: what changed since the period before, this week's highlights, the headline numbers, what the bot filter kept out, and daily traffic.
2. One post's stats, with the Search Console searches that brought people to it.
3. AI assistants: how many requests their crawlers made, against how many readers they sent back.
4. Top pages, referrers grouped by how people arrived, and where in the world they were.
5. Importing history from Google Analytics 4, Plausible, Koko Analytics, Jetpack Stats and other plugins.
6. Settings, including which roles can read the dashboard without being administrators.
7. Year in review, including the history you imported from another tool.
8. A Popular posts block built on your own, bot-filtered view counts.
9. Connect an AI assistant: the site's MCP endpoint and ready-made configuration for Claude Code, Claude Desktop and Cursor, under Settings.

== Changelog ==

= 0.17.0 =
* New: a Pages tab that lists every page with traffic in the date range you pick, with pageviews, visitors and average time. Sort by any column, search by title or path, and page through the full list.
* New: a "View all pages" link on the Overview's Top pages card opens the Pages tab for the same range.

= 0.16.4 =
* Fixed: on sites that imported history, Vireo could take the first imported day as the day it started recording, so the Overview and dashboard widget still compared against the gap. It now works this out from what Vireo itself collected, and corrects a wrong date saved by 0.16.2 or 0.16.3.

= 0.16.3 =
* Changed: the dashboard widget uses the full width when there's room, with today's and the 7, 30 and 90 day figures in one row and a 30-day chart beside your top pages and referrers.
* Changed: the widget chart shows pageviews as bars and visitors as a line, with zero days visible and more date labels.
* Fixed: the widget no longer shows a drop for periods that start before Vireo began recording, and no longer shows an empty badge when there's nothing to compare.

= 0.16.2 =
* Fixed: after importing history from another analytics tool, the Overview no longer reports a drop for a period that starts before Vireo began recording. It says when Vireo started recording, and comparisons begin once a full period is in.

= 0.16.1 =
* Changed: the plugin's homepage link now points to vireowp.com, and the statistics tag replaces cookieless.

= 0.16.0 =
* Added: AI visibility scan, under Search & AI beside the AI assistants card. Press Run the scan and Vireo fetches a page of your site, your robots.txt, llms.txt and sitemap the way an AI crawler would, and requests the page as GPTBot, ClaudeBot and PerplexityBot. It scores crawler access, readable content, structured data and discovery files, and every failed check says what to change. The scan's own requests carry a one-time token and are left out of the crawler figures, the missing pages report and your pageviews.
* Changed: the readme now says who Vireo is for (bloggers, publishers, shops, small businesses and agencies) and backs its search tags in the description.

= 0.15.0 =
* Added: the email digest can go daily, weekly or monthly. Daily arrives each morning with the day before, monthly on the first with the month before; weekly keeps its chosen day. The email takes a logo, an accent colour and whether Vireo is named in its footer from the vireo_email_brand filter, which Vireo Pro's white-label settings fill in.
* Added: choose which roles are not counted. Settings, General lists every role on the site; tick editors, authors, shop managers or anyone else whose own browsing should stay out of the reports. Administrators stay ticked by default, as before.
* Added: form submissions that went through. Contact Form 7, WPForms, Gravity Forms, Fluent Forms and Elementor Pro forms record a form_success event from the plugin's own hook when a submission is accepted, carrying the form's id and title and nothing the visitor typed. Breakdance forms get a "Vireo Analytics" action to tick under Actions after submission. The optional form_submit auto-event leaves these forms alone, so an attempt that failed validation is no longer counted beside the one that sent.
* Added: Print / Save as PDF on the Overview and on each post, referrer and event view. The printed page drops the menus and controls, keeps the report, and says which site it is and when it was printed; "Save as PDF" in the print dialog is the PDF export.
* Added: backup and restore. Settings, Import & export downloads everything Vireo has recorded as one file, and Restore from a backup loads it on another install, or back onto this one. Merge replaces the days in the file and keeps the rest; Replace empties the tables first. Loading the same file twice gives the same numbers.
* Changed: the tracker and Core Web Vitals scripts are shipped minified, about a third of the size on the wire. The readable source ships beside them in assets/js/src.

= 0.14.0 =
* Added: compare with the same dates a year earlier, not only the previous period. Imported history makes the comparison available from the day you switch. The compared figure and its dates sit under each headline number and say when they come from an import, and the chart draws the compared period as a continuous line.
* Added: filter the Overview by a page or a referrer, from the funnel on any card row. Active filters show as chips such as "Page is Pricing". Every number on the page answers the same filter, and a combination the data cannot answer says so instead of showing a figure from the wrong table. Under any filter the hourly chart shows days.
* Added: saved views. Name a date range, comparison and filter set and come back to it from the Overview. Views are per user.
* Added: referrer spam. Hits from hosts on the bundled referrer-spam list are counted under Referrer spam in the bot panel and never reach the totals. A referrer whose visits show no engagement is tagged as suspected junk in the Referrers card with a one-click Block, and left out of What changed and the weekly highlights until you decide. Blocked referrers are listed under Settings.
* Added: automated browsers. A first visit of the day from a browser twelve or more major versions out of date, arriving with no outside referrer, is counted under Automated browsers (previously Headless browsers) in the bot panel and kept out of the totals. Readers on a slightly older browser, enterprise long-term releases and anyone browsing on from a second page are left alone.
* Fixed: a site's own address was listed among its referrers, often at the top, because every click from one page to another arrived with the site as the referrer. Your own hosts, connected sites, headless origins, localhost and bare IP addresses are never referrers now, and rows already stored that way are left out of the Referrers card, the channel totals, What changed, the widget and the year in review. Those visits count as Direct.
* Fixed: Today includes the hits still waiting to be folded in, so the dashboard widget, the Overview and the chart no longer show 0 for the first minutes after midnight or on sites that fold on a system cron. The today-versus-yesterday figure compares with yesterday up to the same hour, instead of scaling the whole of yesterday and reading a quiet morning as a collapse.
* Fixed: after an update that arrived while nobody was in wp-admin, visits folded by the scheduled run were stored before the update had created its new tables, so time-on-page reports from that window were dropped and bounce counting started a day later than it needed to. The update now completes on the first scheduled run as well.
* Fixed: Jetpack Stats imports reported the home page and archives as a page called "/?p=0", or dropped them. They are kept as one row named "Home page and archives (imported)". Live tracking's own home-page row is unchanged.
* Fixed: a visit whose country was never resolved is no longer ranked as "Unknown", which put it first on sites with imported history. When only part of the range has country data, the Countries card says "Country known for N% of visitors", and the year in review ranks countries over the days that have one and says from which date.
* Changed: when Time on page or Bounce rate has nothing to show, the card says why and from which day it counts, such as "starts counting Oct 16" or "no views timed in this range", instead of a dash with "no visitors counted yet".
= 0.13.0 =
* Added: average time on page and bounce rate on the Overview, compared with the previous period like the other headline numbers, and each page's average time in Top pages. Time on page counts only while the tab is in front of the reader, up to 30 minutes a view, averaged over the views that sent a time. Bounce rate is the share of a day's visitors who viewed one page, with visitors counted the same way as on the Visitors card. Time on page starts counting when you update, bounce rate the day after.
* Added: AI assistants and the REST API get both numbers with the traffic totals, and each page's time with the top pages. The CSV export has them too.
* Changed: the tracker sends one more small request when a reader leaves a page or switches away from it, with the seconds they spent there. It sets no cookies and stores nothing in the browser, and it respects Do Not Track, Global Privacy Control and your consent setup the same way pageviews do. Headless front ends and connected sites get it with the same script.
* Fixed: when the first two visits to a newly connected site arrived together, one of them got an error back and wasn't counted. Both are counted now.
* Fixed: Core Web Vitals history was kept forever, whatever the data retention setting said. It's trimmed with everything else now.

= 0.12.5 =
* Fixed: with Rank Math or another plugin that serves its tools through the MCP Adapter's shared server, activating Vireo made that plugin's MCP endpoint disappear, even if you never used Vireo's own. Vireo switched off the shared server whenever it started the adapter itself, and it couldn't see that Rank Math had started it already. It now leaves the shared server alone whenever any other plugin uses the adapter, and only turns it off on sites where nothing but Vireo uses the adapter.
* Fixed: a site that changed the beacon rate limiter's shard count, the size cap on unprocessed visits, or the list of bot signatures through their filters still used the fast tracking endpoint, where those filters never run, so the change had no effect. The plugin checked for a misspelled name and missed the other two. Setting any of them now keeps the site on the standard route, where they apply.

= 0.12.4 =
* Fixed: a MainWP dashboard asking this site for a report with a date range or any other argument got "The tool input must be a JSON object" back, because WordPress had escaped the quotes in the request before the plugin read it. Arguments from the dashboard are now read as sent.

= 0.12.3 =
* Fixed: with a language pack from translate.wordpress.org installed, the dashboard and settings showed English wherever the pack was still incomplete, because WordPress loads the pack instead of the full catalogue shipped with the plugin. The plugin now adds its own catalogue underneath the pack: the pack's wording stays wherever a translator approved one, and the bundled translation covers the rest.
* Fixed: on PHP 7.4, activating the plugin stopped with a type error, because WordPress passes the network flag as null and the hook demanded a boolean. Every PHP version the plugin supports can activate it again.

= 0.12.2 =
* Security: the text an AI assistant reads from Vireo (paths, referrers, search terms, event values, error messages) is now cleaned of invisible Unicode characters, direction overrides, line separators and look-alike brackets as well as the ASCII ones, so a crafted visit can't slip an instruction past the cleaning.
* Added: a MainWP dashboard running the Vireo Analytics for MainWP add-on can ask this site for any of its read-only reports over the connection MainWP already signs, so an assistant connected to the dashboard can read each child site by name. Only Vireo's own read-only reports are available, the connected user needs the Vireo capability, the site's `vireo_mainwp_enabled` opt-out still applies, and nothing about a child's own connected sites is passed up.

= 0.12.1 =
* Fixed: on phones, the three traffic cards each took a screen of their own before the chart. Each is now one compact band, and on tablets they sit three across.
* Fixed: the filter chips wrapped into two ragged rows on phones. Each now takes a full row.
* Fixed: the Mobile/Desktop switch on the Page speed tab sat across the card from the button it changes. It follows the heading now.
* Fixed: change chips said their sign twice, or not at all. The arrow is the sign, and it now shows on every chip.
* Fixed: on PHP 8.2 and later, every MCP call with arguments logged a deprecation notice from the bundled MCP adapter. The adapter is updated to a version that doesn't.

= 0.12.0 =
* Added: an MCP server, so Claude, Cursor and other AI assistants can answer questions about your traffic from your real numbers: what changed this week, which pages are growing, what people searched for, which assistants send readers, which links are broken. Open Settings, Search Console & API and copy the ready-made configuration for your client. The assistant signs in with an application password you create on your profile, sees only what that user can see in the dashboard, and can read but never change anything.
* Added: every dashboard view can now be asked for by an AI assistant, over the REST API or through WordPress abilities: what changed against the previous period, weekly highlights, hourly traffic, traffic by channel, one referrer in depth, AI assistant traffic, Search Console queries site-wide or for one page, one event in depth, missing pages, bot traffic, Core Web Vitals, chart notes and the year in review. Text that visitors can set, such as paths, referrers and search terms, is flattened before it reaches an assistant.
* Changed: the Overview opens with the traffic chart, and Issues & campaigns is now three tabs: Content, Campaigns and Issues. What changed shows five rows a side with a Show all link, and the card titles, comparison note and bot note take less room.
* Changed: on phones, the date range is one select instead of ten wrapping buttons, the report tabs scroll in one row, chart dates stay readable, nothing runs past the edge of the screen, and every control is a comfortable tap target.
* Changed: Settings is laid out as cards, the email day and hour share one row, the REST reference is folded away, and the import picker separates sources that can always run from plugins whose data wasn't found on this site. Importer screens lead back to Import & export and keep the Vireo menu open.
* Changed: Year in review marks the month in progress as "so far", lists the months as rows on phones, and tells a fresh site the review fills in on its own. The WordPress dashboard widget's chart keeps its labels readable on a phone and each day has a tooltip.
* Fixed: the AI assistants card divided crawler requests by readers over the whole range even when crawler counting had started part-way through it, which made the ratio look five times smaller than it was. All three numbers now cover the days crawlers were counted, and the card says since when. Readers sent counts visitors, not pageviews.
* Fixed: Today's average-daily card and the dashboard widget compared a day in progress against a whole one, so every morning opened on a red -59%. Both now weight the comparison by the time elapsed, like the Overview.
* Fixed: a German admin's page labels could turn up in an English admin's What changed list, and the other way round. Cached labels are now kept per language.
* Fixed: referrer detail listed the whole site's top pages under a referrer's name. It lists the posts that referrer sent people to.
* Fixed: admin notices landed between a page's title and its dates; the widget's single phone column cut off page counts; the Vireo menu closed on importer screens; muted text and delta chips didn't pass AA contrast.
* Fixed: on a site with WooCommerce, the first page load after activating it logged a "translation loading triggered too early" notice from Vireo's cron schedule label.
* Changed: for developers, abilities that answered with a list (daily stats, top pages, top referrers, countries, events) now answer with an object holding a `rows` list, which is what MCP clients require. The REST routes for those keep their original shape. Arguments an ability doesn't document are refused instead of ignored.

= 0.11.1 =
* Added: a Settings option to hide today's pageviews from the admin toolbar. It's on by default. Developers can decide per user with the `vireo_show_admin_bar` filter. Thanks to Paul Fleury for the suggestion.

= 0.11.0 =
* Added: test your homepage or any post with Google's PageSpeed Insights, from the Page speed tab and each post's stats screen, on mobile or desktop. Out of the box the button opens the test on the PageSpeed Insights website. Add your own free Google API key under Settings, Search Console & API and the test runs from the dashboard instead, showing the performance score, the lab timings and the five fixes Google expects to save the most, next to your real-visitor numbers. A test runs only when you press the button, and results are kept for a day.

= 0.10.3 =
* Fixed: notices from Vireo Analytics Pro were hidden on Vireo's own screens along with other plugins' notices, so Pro's Freemius revenue set-up card never appeared on the Settings page.
* Changed: the cache that remembers which country an address belongs to, and the fallback used to tell visitors apart, now key on a hash that cannot be reproduced without the site's own secret, instead of a plain MD5 of the address. The old cache rows are cleared on update and rebuild as visits arrive.

= 0.10.2 =
* Fixed: add-ons that wait for new data, such as the Pro spike alert, are now told when Vireo finishes writing it. They never were, so spike alerts never ran.

= 0.10.1 =
* Fixed: on sites using WP Rocket's JavaScript minification, the tracker could stay on an old copy after updates, so WP RSS Aggregator and Spotlight clicks were never counted.
* Fixed: clicks on the padding of Spotlight's "View on Instagram" button weren't counted.

= 0.10.0 =
* Added: connected sites. Count visits to the other sites you run from this dashboard, whether or not they use WordPress: a static site, a docs site, a landing page. Each one keeps its own numbers, and a site selector on the Overview switches between them. Add a site under Settings, Tracking and paste its tag; visits are accepted only from that site's address. Free includes one connected site. Pages on a connected site are listed by address, and per-post reports, Search Console, the weekly email, the REST API and imports stay with this WordPress site.

Older versions are in [changelog.txt](https://plugins.trac.wordpress.org/browser/vireo-analytics/trunk/changelog.txt), which also ships with the plugin.
