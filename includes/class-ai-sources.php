<?php
/**
 * AI assistant traffic.
 *
 * Two different questions get muddled together when people ask whether AI is
 * sending them business, and the answers point in opposite directions:
 *
 *   1. Are the AI crawlers reading my site? Measured by the bot filter, which
 *      records what it rejected under the `ai` category.
 *   2. Are the assistants sending me readers? Measured here, from referrers.
 *
 * The first is what the AI companies take. The second is what they give back.
 * Reporting one without the other is how a site ends up believing AI is a
 * traffic source when it is closer to a traffic sink.
 *
 * Classification happens at read time against the referrer table, so this works
 * on history collected before the feature existed. No new storage.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class AI_Sources {

	/**
	 * Referrer host patterns for assistants that pass a visitor through with a
	 * link. Anchored so `notchatgpt.example.com` cannot match.
	 *
	 * Kept as host patterns rather than full URLs because every one of these
	 * has moved domain at least once.
	 *
	 * @return array<string,string> label => host regex
	 */
	public static function sources(): array {
		$map = [
			'ChatGPT'    => '^(chat\.)?openai\.com$|^chatgpt\.com$|(^|\.)chatgpt\.[a-z.]+$',
			'Claude'     => '^claude\.ai$|^claude\.com$|(^|\.)anthropic\.com$',
			'Gemini'     => '^gemini\.google\.com$|^bard\.google\.com$|^aistudio\.google\.com$',
			'Perplexity' => '(^|\.)perplexity\.ai$',
			'Copilot'    => '^copilot\.microsoft\.com$|^(www\.)?bing\.com$/chat|^m365\.cloud\.microsoft$',
			'DeepSeek'   => '(^|\.)deepseek\.com$',
			'Grok'       => '(^|\.)grok\.com$|(^|\.)x\.ai$',
			'Mistral'    => '(^|\.)chat\.mistral\.ai$|^lechat\.mistral\.ai$',
			'Poe'        => '^poe\.com$',
			'You.com'    => '^you\.com$',
			'Phind'      => '^(www\.)?phind\.com$',
			'Meta AI'    => '^(www\.)?meta\.ai$',
		];

		return (array) apply_filters( 'vireo_ai_sources', $map );
	}

	/**
	 * Every assistant's host pattern as one regex, for the SQL source filter.
	 * Plain groups only, so MySQL 5.7's REGEXP reads it the same way PHP does.
	 * Empty when the `vireo_ai_sources` filter removes them all.
	 */
	public static function pattern(): string {
		return implode( '|', array_map( static fn( string $p ): string => '(' . $p . ')', array_values( self::sources() ) ) );
	}

	/**
	 * Which assistant a referrer host belongs to, or an empty string.
	 */
	public static function classify( string $host ): string {
		$host = strtolower( ltrim( trim( $host ), '.' ) );
		if ( '' === $host ) {
			return '';
		}

		foreach ( self::sources() as $label => $pattern ) {
			if ( preg_match( '~' . $pattern . '~i', $host ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Referrals per assistant for a date range, busiest first.
	 *
	 * @return array{total:int, by_source:array<int,array{source:string,pageviews:int,visitors:int}>}
	 */
	public static function referrals( string $start, string $end ): array {
		global $wpdb;

		$stats = Sites::table( 'referrer_stats' );
		$urls  = Sites::table( 'referrer_urls' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT u.url AS host, SUM(r.pageviews) AS pageviews, SUM(r.visitors) AS visitors
				 FROM %i r INNER JOIN %i u ON u.id = r.url_id
				 WHERE r.date BETWEEN %s AND %s
				 GROUP BY u.url',
				$stats,
				$urls,
				$start,
				$end
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [
				'total'     => 0,
				'by_source' => [],
			];
		}

		$agg = [];
		foreach ( $rows as $row ) {
			$label = self::classify( (string) ( $row['host'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			if ( ! isset( $agg[ $label ] ) ) {
				$agg[ $label ] = [
					'source'    => $label,
					'pageviews' => 0,
					'visitors'  => 0,
				];
			}
			$agg[ $label ]['pageviews'] += (int) $row['pageviews'];
			$agg[ $label ]['visitors']  += (int) $row['visitors'];
		}

		uasort(
			$agg,
			static function ( array $a, array $b ): int {
				return $b['visitors'] <=> $a['visitors'];
			}
		);

		// Visitors, not pageviews: every label this feeds talks about readers,
		// and one reader opening four pages is still one reader sent.
		$total = 0;
		foreach ( $agg as $row ) {
			$total += (int) $row['visitors'];
		}

		return [
			'total'     => $total,
			'by_source' => array_values( $agg ),
		];
	}

	/**
	 * How many requests AI crawlers made over the same range, from the bot
	 * filter's own record.
	 */
	public static function crawl_requests( string $start, string $end ): int {
		global $wpdb;

		$table = Sites::table( 'bot_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT IFNULL(SUM(hits),0) FROM %i WHERE category = %s AND date BETWEEN %s AND %s',
				$table,
				'ai',
				$start,
				$end
			)
		);
	}

	/**
	 * The first day in a range the bot filter has rows for, or null when it
	 * has none. Any category counts: a day the filter was running but saw no
	 * AI crawler is a real zero, while a day before it ran is not.
	 */
	public static function coverage_start( string $start, string $end ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- reporting read
		$first = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(date) FROM %i WHERE date BETWEEN %s AND %s',
				Sites::table( 'bot_stats' ),
				$start,
				$end
			)
		);

		return is_string( $first ) && '' !== $first ? $first : null;
	}

	/**
	 * What the crawlers took against what the assistants sent back, over the
	 * same days.
	 *
	 * Crawlers are only counted from the day Vireo's bot filter started
	 * running, while referrals go back as far as the referrer table does,
	 * imported history included. Dividing 27 days of crawling by 90 days of
	 * referrals made a site look five times more generous than it was, so both
	 * sides start on the first day with crawler data. `since` carries that day
	 * when it is later than the range's start, so the card can say so.
	 *
	 * @return array{start:string,end:string,since:string,crawled:int,referred:int,rate:?float}
	 */
	public static function exchange( string $start, string $end ): array {
		$from  = self::coverage_start( $start, $end );
		$since = null !== $from && strcmp( $from, $start ) > 0 ? $from : '';
		if ( '' !== $since ) {
			$start = $since;
		}

		$crawled  = null === $from ? 0 : self::crawl_requests( $start, $end );
		$referred = (int) self::referrals( $start, $end )['total'];

		return [
			'start'    => $start,
			'end'      => $end,
			'since'    => $since,
			'crawled'  => $crawled,
			'referred' => $referred,
			'rate'     => self::exchange_rate( $crawled, $referred ),
		];
	}

	/**
	 * Pages taken per visit returned.
	 *
	 * Null when there is nothing to divide, so the caller can stay quiet rather
	 * than print a ratio built on no data. Null also when no crawling was
	 * recorded at all, because "infinite return on zero crawling" is not a
	 * useful thing to tell someone.
	 */
	public static function exchange_rate( int $crawled, int $referred ): ?float {
		if ( $crawled < 1 ) {
			return null;
		}
		if ( $referred < 1 ) {
			return (float) $crawled;
		}
		return round( $crawled / $referred, 1 );
	}
}
