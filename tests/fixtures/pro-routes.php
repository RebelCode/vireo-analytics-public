<?php
/**
 * Every route the Pro plugin registers under vireo-analytics/v1, as of Pro
 * 1.1.0 (d0ff814). Free must never register one of these paths: both plugins
 * share the namespace, and WordPress keeps one handler per path, so the
 * second registration shadows the first and a Pro REST user silently gets
 * free's answer, or the other way round.
 *
 * Update this list when Pro adds a route (grep Pro for register_rest_route
 * and register_ability_route, and the 'route' keys in class-abilities-pro.php).
 */

return [
	// pro/class-revenue.php
	'/stats/revenue',
	'/stats/sources',
	'/stats/products',
	// pro/class-abilities-revenue.php
	'/stats/landing-pages',
	'/stats/store-funnel',
	// pro/class-abilities-pro.php
	'/stats/realtime',
	'/stats/campaigns',
	'/stats/site-searches',
	'/stats/outbound',
	'/stats/downloads',
	'/stats/engagement',
	'/stats/entry-exit',
	'/stats/page-paths',
	'/stats/authors',
	'/stats/post-types',
	'/stats/taxonomy-terms',
	'/stats/page-vitals',
	'/stats/cities',
	'/stats/funnels',
	// pro/class-store-freemius.php
	'/freemius/attribute',
	'/freemius/webhook',
];
