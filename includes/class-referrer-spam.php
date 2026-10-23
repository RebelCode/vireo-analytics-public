<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Referrers that are not readers.
 *
 * Three layers, each answering a different question:
 *
 *   - Known spam: the bundled Matomo list (data/referrer-spam.php) plus the
 *     hosts the admin blocked. Hits from these are kept out of the totals at
 *     fold time, counted under "Referrer spam" in the bot panel, and hidden
 *     from referrer reports retroactively.
 *   - Suspected junk: a host whose visits show no engagement at all, read
 *     from the rollups. Nothing is removed; the host is tagged in reports
 *     with a one-click block, and kept out of "What changed" and the weekly
 *     highlights, which exist to name real movement.
 *   - The site's own hosts: internal navigation, never a referrer.
 */
final class Referrer_Spam {

	public const OPTION = 'vireo_blocked_referrers';
	public const NONCE  = 'vireo_block_referrer';

	/** The bot-panel category the filtered hits are counted under. */
	public const CATEGORY = 'referrer_spam';

	/**
	 * What "no engagement" means, over the window the report is looking at.
	 * Conservative on purpose: a newsletter whose readers all land on one page
	 * still has a few who click on, and a real site that links to a post is
	 * credited with that post.
	 */
	private const JUNK_MIN_PAGEVIEWS = 30;
	private const JUNK_MIN_DAYS      = 2;
	/** Pageviews beyond one per visit, as a share of pageviews. */
	private const JUNK_MAX_EXTRA_SHARE = 0.03;
	/** Share of the host's pageviews that landed on a post. */
	private const JUNK_MAX_POST_SHARE = 0.25;
	/** A burst: first seen no earlier than this many days before the window ends. */
	private const JUNK_MAX_AGE_DAYS = 45;

	/** @var array<int,string>|null */
	private static ?array $listed = null;

	/**
	 * The bundled list, lowercase registrable domains.
	 *
	 * @return array<int,string>
	 */
	public static function listed(): array {
		if ( null !== self::$listed ) {
			return self::$listed;
		}
		$file = VIREO_DIR . 'data/referrer-spam.php';
		$data = is_readable( $file ) ? require $file : [];
		$data = is_array( $data ) ? $data : [];
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Hosts treated as referrer spam, on top of the admin's own list.
			 *
			 * @param array<int,string> $hosts Lowercase registrable domains; subdomains match.
			 */
			$data = (array) apply_filters( 'vireo_referrer_spam_hosts', $data );
		}
		self::$listed = array_values( array_unique( array_map( 'strval', $data ) ) );
		return self::$listed;
	}

	/**
	 * Whether a host is one of the given domains or a subdomain of one. Pure,
	 * so the match rule is testable on its own.
	 *
	 * @param array<int,string> $domains
	 */
	public static function host_matches( string $host, array $domains ): bool {
		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return false;
		}
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		foreach ( $domains as $domain ) {
			$domain = strtolower( (string) $domain );
			if ( '' === $domain ) {
				continue;
			}
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_listed( string $host ): bool {
		return self::host_matches( $host, self::listed() );
	}

	/**
	 * Hosts the admin blocked, canonical and lowercase.
	 *
	 * @return array<int,string>
	 */
	public static function blocked(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$out = [];
		foreach ( $stored as $host ) {
			$host = vireo_canonical_host( (string) $host );
			if ( '' !== $host && ! in_array( $host, $out, true ) ) {
				$out[] = $host;
			}
		}
		return $out;
	}

	public static function is_blocked( string $host ): bool {
		return self::host_matches( $host, self::blocked() );
	}

	/** Listed or blocked: the hits are filtered and the host is hidden. */
	public static function is_spam( string $host ): bool {
		return self::is_blocked( $host ) || self::is_listed( $host );
	}

	/** @return bool Whether the list changed. */
	public static function block( string $host ): bool {
		$host = vireo_canonical_host( $host );
		if ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return false;
		}
		$list = self::blocked();
		if ( in_array( $host, $list, true ) ) {
			return false;
		}
		$list[] = $host;
		update_option( self::OPTION, $list, false );
		self::forget_reports();
		return true;
	}

	/** @return bool Whether the list changed. */
	public static function unblock( string $host ): bool {
		$host = vireo_canonical_host( $host );
		$list = self::blocked();
		$kept = array_values( array_filter( $list, static fn( string $h ): bool => $h !== $host ) );
		if ( count( $kept ) === count( $list ) ) {
			return false;
		}
		update_option( self::OPTION, $kept, false );
		self::forget_reports();
		return true;
	}

	/**
	 * Drop rows whose host is spam or the site's own. For the report readers:
	 * the bundled list is too long for a SQL NOT IN, and the rows are already
	 * in memory.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param string                         $key  The key holding the host.
	 * @return array<int,array<string,mixed>>
	 */
	public static function strip( array $rows, string $key = 'host' ): array {
		$own = function_exists( 'vireo_own_hosts' ) ? vireo_own_hosts() : [];
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $key, $own ): bool {
					$host = is_array( $row ) ? (string) ( $row[ $key ] ?? '' ) : (string) ( $row->$key ?? '' );
					$canon = vireo_canonical_host( $host );
					if ( '' !== $canon && in_array( $canon, $own, true ) ) {
						return false;
					}
					return ! self::is_spam( $host );
				}
			)
		);
	}

	/**
	 * The engagement a referrer's visits showed over a window, summarised
	 * from the rollups, for self::looks_like_junk().
	 *
	 * @param array<int,string> $hosts Stored hosts (u.url values).
	 * @return array<string,array{pageviews:int,visitors:int,days:int,post_pageviews:int,first_seen:string}>
	 */
	public static function profiles( array $hosts, string $start, string $end ): array {
		global $wpdb;

		$hosts = array_values( array_unique( array_filter( array_map( 'strval', $hosts ) ) ) );
		if ( [] === $hosts ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $hosts ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a counted array; callers cache
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.url AS host,
					SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.pageviews ELSE 0 END) AS pageviews,
					SUM(CASE WHEN s.date BETWEEN %s AND %s THEN s.visitors ELSE 0 END) AS visitors,
					SUM(CASE WHEN s.date BETWEEN %s AND %s AND s.pageviews > 0 THEN 1 ELSE 0 END) AS days,
					MIN(s.date) AS first_seen
				 FROM %i s JOIN %i u ON u.id = s.url_id
				 WHERE u.url IN ($placeholders)
				 GROUP BY u.url",
				array_merge( [ $start, $end, $start, $end, $start, $end, Sites::table( 'referrer_stats' ), Sites::table( 'referrer_urls' ) ], $hosts )
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a counted array; callers cache
		$posts = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.url AS host, SUM(s.pageviews) AS pageviews
				 FROM %i s JOIN %i u ON u.id = s.url_id
				 WHERE s.date BETWEEN %s AND %s AND s.post_id > 0 AND u.url IN ($placeholders)
				 GROUP BY u.url",
				array_merge( [ Sites::table( 'post_referrer_stats' ), Sites::table( 'referrer_urls' ), $start, $end ], $hosts )
			),
			ARRAY_A
		);
		$post_pv = [];
		foreach ( $posts as $row ) {
			$post_pv[ (string) $row['host'] ] = (int) $row['pageviews'];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$host         = (string) $row['host'];
			$out[ $host ] = [
				'pageviews'      => (int) $row['pageviews'],
				'visitors'       => (int) $row['visitors'],
				'days'           => (int) $row['days'],
				'post_pageviews' => (int) ( $post_pv[ $host ] ?? 0 ),
				'first_seen'     => (string) $row['first_seen'],
			];
		}
		return $out;
	}

	/**
	 * Whether a referrer's visits look like junk: many of them, over more than
	 * a day, every one a single pageview, almost none on a post, from a host
	 * that only just appeared. Pure, so the rule can be tested against shapes.
	 *
	 * A compromised site loading pages in hidden frames, or a spoofed
	 * referrer, produces exactly this: pageviews equal to visitors on every
	 * day and landings on paths that are not posts. A newsletter or a forum
	 * thread that links to one post does not: its readers land on the post,
	 * and some of them click on.
	 *
	 * @param array{pageviews:int,visitors:int,days:int,post_pageviews:int,first_seen:string} $profile
	 * @param string                                                                          $end The window's last day.
	 */
	public static function looks_like_junk( array $profile, string $end ): bool {
		$pv = (int) ( $profile['pageviews'] ?? 0 );
		if ( $pv < self::JUNK_MIN_PAGEVIEWS || (int) ( $profile['days'] ?? 0 ) < self::JUNK_MIN_DAYS ) {
			return false;
		}

		$extra = $pv - (int) ( $profile['visitors'] ?? 0 );
		if ( $extra < 0 || $extra > $pv * self::JUNK_MAX_EXTRA_SHARE ) {
			return false;
		}

		if ( (int) ( $profile['post_pageviews'] ?? 0 ) > $pv * self::JUNK_MAX_POST_SHARE ) {
			return false;
		}

		$first = (string) ( $profile['first_seen'] ?? '' );
		if ( '' !== $first && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $first ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			$age = ( strtotime( $end . ' UTC' ) - strtotime( $first . ' UTC' ) ) / DAY_IN_SECONDS;
			if ( $age > self::JUNK_MAX_AGE_DAYS ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Which of these hosts look like junk over the window. Cached briefly:
	 * the referrer card, the drivers and the highlights all ask.
	 *
	 * @param array<int,string> $hosts Stored hosts.
	 * @return array<int,string> The suspects, as given.
	 */
	public static function suspects( array $hosts, string $start, string $end ): array {
		$hosts = array_values( array_unique( array_filter( array_map( 'strval', $hosts ) ) ) );
		if ( [] === $hosts ) {
			return [];
		}
		sort( $hosts );
		$key   = 'vireo_junk_' . md5( Sites::current() . '|' . $start . '|' . $end . '|' . implode( ',', $hosts ) );
		$found = get_transient( $key );
		if ( is_array( $found ) ) {
			return $found;
		}

		$out = [];
		foreach ( self::profiles( $hosts, $start, $end ) as $host => $profile ) {
			if ( self::looks_like_junk( $profile, $end ) ) {
				$out[] = $host;
			}
		}
		set_transient( $key, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** Reports cache for 30 s to an hour; a block should show at once. */
	private static function forget_reports(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- clearing our own transients
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vireo_%' OR option_name LIKE '_transient_timeout_vireo_%'" );
		wp_cache_flush();
	}
}
