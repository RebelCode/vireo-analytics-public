<?php
/**
 * What counts as a referrer.
 *
 * The site's own hosts, internal addresses and the host a stored referrer is
 * reported under. Used by the aggregator at flush time and by the reports,
 * never on the beacon fast path, so this file is loaded by the plugin and
 * not by endpoint.php: a site filtering vireo_own_hosts keeps the fast path.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hosts that are this site, not a source of traffic: the home and site
 * addresses, the connected site in context, and any headless front end
 * allowed to send beacons. Canonical (lowercase, no www.), cached per request.
 *
 * @return array<int,string>
 */
function vireo_own_hosts(): array {
	static $cache = [];
	$site = class_exists( '\\VireoAnalytics\\Sites' ) ? \VireoAnalytics\Sites::current() : 0;
	if ( isset( $cache[ $site ] ) ) {
		return $cache[ $site ];
	}
	$candidates = [];
	if ( function_exists( 'home_url' ) ) {
		$candidates[] = home_url();
	}
	if ( function_exists( 'site_url' ) ) {
		$candidates[] = site_url();
	}
	if ( class_exists( '\\VireoAnalytics\\Sites' ) && \VireoAnalytics\Sites::is_connected() ) {
		$row          = \VireoAnalytics\Sites::get( $site );
		$candidates[] = (string) ( $row['origin'] ?? '' );
	}
	if ( class_exists( '\\VireoAnalytics\\Headless' ) ) {
		$candidates = array_merge( $candidates, \VireoAnalytics\Headless::allowed_origins() );
	}
	/**
	 * Hosts never counted as referrers because they are this site.
	 *
	 * @param array<int,string> $hosts URLs or hosts.
	 */
	$candidates = (array) apply_filters( 'vireo_own_hosts', $candidates );

	$out = [];
	foreach ( $candidates as $candidate ) {
		$host = vireo_canonical_host( (string) $candidate );
		if ( '' !== $host && ! in_array( $host, $out, true ) ) {
			$out[] = $host;
		}
	}
	$cache[ $site ] = $out;
	return $out;
}

/**
 * A host that can never be a traffic source: the machine itself, a private
 * network, a bare IP, or a name that only resolves inside one. Pure.
 */
function vireo_is_internal_host( string $host ): bool {
	$host = strtolower( trim( $host, " \t\n\r\0\x0B[]" ) );
	if ( '' === $host ) {
		return true;
	}
	if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
		return true;
	}
	foreach ( [ '.local', '.internal', '.test', '.home', '.lan', '.intranet' ] as $suffix ) {
		if ( str_ends_with( $host, $suffix ) ) {
			return true;
		}
	}
	// Any bare IP, private or public: a referrer with no name is not a site
	// a reader came from.
	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return true;
	}
	return false;
}

/**
 * The host a referrer is stored and reported under, or '' when it is not a
 * referrer at all: empty, unparseable, this site itself, or an internal
 * address. Pure, given the own hosts.
 *
 * @param array<int,string> $own_hosts Canonical hosts from vireo_own_hosts().
 */
function vireo_referrer_host( string $raw, array $own_hosts = [] ): string {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return '';
	}
	// android-app://com.example/ stores the package; everything else needs a
	// scheme and a host.
	$parts = wp_parse_url( $raw );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}
	$host = vireo_canonical_host( (string) $parts['host'] );
	if ( '' === $host || vireo_is_internal_host( $host ) ) {
		return '';
	}
	foreach ( $own_hosts as $own ) {
		$own = vireo_canonical_host( (string) $own );
		if ( '' !== $own && ( $host === $own || str_ends_with( $host, '.' . $own ) ) ) {
			return '';
		}
	}
	return $host;
}
