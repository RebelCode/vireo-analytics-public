<?php

namespace VireoAnalytics\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Import history out of Google Analytics 4.
 *
 * The other importers read another WordPress plugin's tables. Nobody leaves
 * Statify under duress; they leave GA4. So this one talks to the Data API
 * directly and pulls the same shapes the rest of the plugin stores: daily
 * totals, pages, referrers, referrers per page, countries, devices, and events.
 *
 * Authentication is a service account rather than OAuth, which means no
 * redirect flow, no client secret round-trip, and nothing to re-authorise
 * every sixty days. The site owner creates a service account, downloads its
 * JSON key, and grants that account's email Viewer access on the GA4 property.
 * We sign a JWT with the key and exchange it for an access token.
 *
 * Imports are additive, like every other importer here: running the same range
 * twice adds the numbers twice. The UI warns about it; this class does not
 * silently dedupe, because a partial re-run after a failure is a legitimate
 * thing to want.
 */
final class GA4_Importer extends Importer_Base {

	private const TOKEN_URL      = 'https://oauth2.googleapis.com/token';
	private const DATA_URL       = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport';
	private const SCOPE          = 'https://www.googleapis.com/auth/analytics.readonly';
	private const PARAMS_OPTION  = 'vireo_ga4_import_params';
	private const CHUNK_SIZE_DAYS = 14;
	private const REMOTE_TIMEOUT  = 90;
	private const PAGE_SIZE       = 50000;
	private const DEFAULT_TOTAL   = 365;

	public function slug(): string {
		return 'ga4';
	}

	public function name(): string {
		return __( 'Google Analytics 4', 'vireo-analytics' );
	}

	/**
	 * Signing needs openssl. Hosts without it cannot mint the assertion, and
	 * there is no fallback worth offering: telling someone to paste an access
	 * token that expires in an hour is not an import path.
	 */
	public function is_available(): bool {
		return \VireoAnalytics\Google_Auth::available();
	}

	public function count_records(): int {
		$saved = $this->get_saved_params();
		if ( '' === $saved['date_start'] || '' === $saved['date_end'] ) {
			return self::DEFAULT_TOTAL;
		}

		$start = strtotime( $saved['date_start'] );
		$end   = strtotime( $saved['date_end'] );
		if ( false === $start || false === $end || $end < $start ) {
			return self::DEFAULT_TOTAL;
		}

		return (int) floor( ( $end - $start ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * @param array{key_json?:string, property_id?:string, date_start?:string, date_end?:string} $params
	 */
	public function save_params( array $params ): void {
		$existing = $this->get_saved_params();

		// An empty key means "keep the one already stored": the form never
		// renders the key back, so a user editing the date range would
		// otherwise wipe their credentials.
		$key = isset( $params['key_json'] ) ? trim( (string) $params['key_json'] ) : '';
		if ( '' === $key ) {
			$key = $existing['key_json'];
		}

		update_option(
			self::PARAMS_OPTION,
			[
				'key_json'    => $key,
				'property_id' => preg_replace( '/\D+/', '', (string) ( $params['property_id'] ?? $existing['property_id'] ) ),
				'date_start'  => (string) ( $params['date_start'] ?? $existing['date_start'] ),
				'date_end'    => (string) ( $params['date_end'] ?? $existing['date_end'] ),
			],
			false
		);
	}

	/**
	 * @return array{key_json:string, property_id:string, date_start:string, date_end:string}
	 */
	public function get_saved_params(): array {
		$raw = (array) get_option( self::PARAMS_OPTION, [] );

		return [
			'key_json'    => (string) ( $raw['key_json'] ?? '' ),
			'property_id' => (string) ( $raw['property_id'] ?? '' ),
			'date_start'  => (string) ( $raw['date_start'] ?? '' ),
			'date_end'    => (string) ( $raw['date_end'] ?? '' ),
		];
	}

	public function has_saved_key(): bool {
		return '' !== $this->get_saved_params()['key_json'];
	}

	public function clear_params(): void {
		$key = $this->get_saved_params()['key_json'];
		if ( '' !== $key ) {
			\VireoAnalytics\Google_Auth::forget( $key, [ self::SCOPE ] );
		}
		delete_option( self::PARAMS_OPTION );
	}

	/**
	 * The service account's email, so the UI can tell the user which account to
	 * grant access to. Never exposes the key itself.
	 */
	public function service_account_email(): string {
		return \VireoAnalytics\Google_Auth::account_email( $this->get_saved_params()['key_json'] );
	}

	/**
	 * Pull one date window. Walks backwards from the end of the range so the
	 * most recent, most useful data lands first.
	 *
	 * @param array<string,mixed> $params
	 * @return array{done:bool,processed:int,total:int,next_offset:int,label:string,error?:string}
	 */
	public function import_chunk( array $params, int $offset, int $limit ): array {
		$saved = $this->get_saved_params();

		if ( '' === $saved['key_json'] || '' === $saved['property_id'] ) {
			return $this->envelope_error( __( 'Add the service account key and the GA4 property ID first.', 'vireo-analytics' ) );
		}

		$range = $this->parse_range( $saved['date_start'], $saved['date_end'] );
		if ( null === $range ) {
			return $this->envelope_error( __( 'The date range is not valid.', 'vireo-analytics' ) );
		}

		$total = (int) floor( ( $range['end']->getTimestamp() - $range['start']->getTimestamp() ) / DAY_IN_SECONDS ) + 1;

		if ( $offset >= $total ) {
			return $this->envelope_done( $total );
		}

		$chunk_end   = $range['end']->modify( '-' . $offset . ' days' );
		$days        = min( self::CHUNK_SIZE_DAYS, $total - $offset );
		$chunk_start = $chunk_end->modify( '-' . ( $days - 1 ) . ' days' );

		$token = $this->access_token( $saved['key_json'] );
		if ( is_wp_error( $token ) ) {
			return $this->envelope_error( $token->get_error_message() );
		}

		$from = $chunk_start->format( 'Y-m-d' );
		$to   = $chunk_end->format( 'Y-m-d' );

		foreach ( [ 'site', 'pages', 'referrers', 'countries', 'devices', 'post_referrers', 'outbound', 'downloads', 'events' ] as $report ) {
			$result = $this->import_report( $report, $saved['property_id'], $token, $from, $to );
			if ( is_wp_error( $result ) ) {
				return $this->envelope_error( $result->get_error_message() );
			}
		}

		$next = $offset + $days;

		if ( $next >= $total ) {
			return $this->envelope_done( $total );
		}

		return [
			'done'        => false,
			'processed'   => $next,
			'total'       => $total,
			'next_offset' => $next,
			'label'       => sprintf(
				/* translators: 1: start date, 2: end date */
				__( 'Imported %1$s to %2$s', 'vireo-analytics' ),
				$from,
				$to
			),
		];
	}

	/**
	 * @return true|\WP_Error
	 */
	private function import_report( string $report, string $property, string $token, string $from, string $to ) {
		$shape = $this->report_shape( $report );

		$rows = $this->run_report( $property, $token, $from, $to, $shape['dimensions'], $shape['metrics'], $shape['filter'] ?? null );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		switch ( $report ) {
			case 'site':
				foreach ( $rows as $row ) {
					$date = $this->normalize_date( $row['dimensions'][0] ?? '' );
					if ( '' === $date ) {
						continue;
					}
					$this->upsert_site_stats( $date, (int) ( $row['metrics'][1] ?? 0 ), (int) ( $row['metrics'][0] ?? 0 ) );
				}
				break;

			case 'pages':
				$bulk = [];
				foreach ( $rows as $row ) {
					$date = $this->normalize_date( $row['dimensions'][0] ?? '' );
					$path = $this->normalize_path( (string) ( $row['dimensions'][1] ?? '' ) );
					if ( '' === $date || '' === $path ) {
						continue;
					}
					$bulk[] = [
						'date'      => $date,
						'post_id'   => (int) url_to_postid( home_url( $path ) ),
						'path'      => $path,
						'pageviews' => (int) ( $row['metrics'][0] ?? 0 ),
						'visitors'  => (int) ( $row['metrics'][1] ?? 0 ),
					];
				}
				$this->upsert_post_stats_bulk( $bulk );
				break;

			case 'referrers':
				$bulk = [];
				foreach ( $rows as $row ) {
					$date = $this->normalize_date( $row['dimensions'][0] ?? '' );
					$host = $this->normalize_host( (string) ( $row['dimensions'][1] ?? '' ) );
					if ( '' === $date || '' === $host ) {
						continue;
					}
					$bulk[] = [
						'date'      => $date,
						'host'      => $host,
						'pageviews' => (int) ( $row['metrics'][0] ?? 0 ),
						'visitors'  => (int) ( $row['metrics'][1] ?? 0 ),
					];
				}
				$this->upsert_referrer_stats_bulk( $bulk );
				break;

			case 'countries':
				foreach ( $rows as $row ) {
					$date = $this->normalize_date( $row['dimensions'][0] ?? '' );
					$code = strtoupper( (string) ( $row['dimensions'][1] ?? '' ) );
					// Must be two letters. Truncating first and checking the
					// length instead lets GA4's "(not set)" through as "(N".
					if ( '' === $date || 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
						continue;
					}
					$this->upsert_geo_stats( $date, $code, (int) ( $row['metrics'][1] ?? 0 ), (int) ( $row['metrics'][0] ?? 0 ) );
				}
				break;

			case 'devices':
				foreach ( $rows as $row ) {
					$date = $this->normalize_date( $row['dimensions'][0] ?? '' );
					if ( '' === $date ) {
						continue;
					}
					$this->upsert_device_stats(
						$date,
						\VireoAnalytics\Collector::clip( (string) ( $row['dimensions'][1] ?? '' ), 50 ),
						\VireoAnalytics\Collector::clip( (string) ( $row['dimensions'][2] ?? '' ), 50 ),
						$this->normalize_device( (string) ( $row['dimensions'][3] ?? '' ) ),
						(int) ( $row['metrics'][1] ?? 0 ),
						(int) ( $row['metrics'][0] ?? 0 )
					);
				}
				break;

			case 'post_referrers':
				$bulk     = [];
				$post_ids = [];
				foreach ( $rows as $row ) {
					$date = $this->normalize_date( $row['dimensions'][0] ?? '' );
					$path = $this->normalize_path( (string) ( $row['dimensions'][1] ?? '' ) );
					$host = $this->normalize_host( (string) ( $row['dimensions'][2] ?? '' ) );
					if ( '' === $date || '' === $path || '' === $host ) {
						continue;
					}
					if ( ! isset( $post_ids[ $path ] ) ) {
						$post_ids[ $path ] = (int) url_to_postid( home_url( $path ) );
					}
					$bulk[] = [
						'date'      => $date,
						'post_id'   => $post_ids[ $path ],
						'host'      => $host,
						'pageviews' => (int) ( $row['metrics'][0] ?? 0 ),
						'visitors'  => (int) ( $row['metrics'][1] ?? 0 ),
					];
				}
				$this->upsert_post_referrer_stats_bulk( self::merge_rows( $bulk, [ 'date', 'post_id', 'host' ], [ 'pageviews', 'visitors' ] ) );
				break;

			case 'outbound':
			case 'downloads':
			case 'events':
				$this->upsert_event_stats_bulk( self::event_rows( $report, $rows ) );
				break;
		}

		return true;
	}

	/**
	 * @return array{dimensions:string[],metrics:string[],filter?:array<string,mixed>}
	 */
	private function report_shape( string $report ): array {
		$metrics = [ 'screenPageViews', 'totalUsers' ];

		switch ( $report ) {
			case 'pages':
				return [
					'dimensions' => [ 'date', 'pagePath' ],
					'metrics'    => $metrics,
				];
			case 'referrers':
				return [
					'dimensions' => [ 'date', 'pageReferrer' ],
					'metrics'    => $metrics,
				];
			case 'countries':
				return [
					'dimensions' => [ 'date', 'countryId' ],
					'metrics'    => $metrics,
				];
			case 'devices':
				return [
					'dimensions' => [ 'date', 'browser', 'operatingSystem', 'deviceCategory' ],
					'metrics'    => $metrics,
				];
			case 'post_referrers':
				return [
					'dimensions' => [ 'date', 'pagePath', 'pageReferrer' ],
					'metrics'    => $metrics,
				];
			case 'outbound':
				return [
					'dimensions' => [ 'date', 'linkDomain' ],
					'metrics'    => [ 'eventCount', 'totalUsers' ],
					'filter'     => self::and_filter(
						[
							'eventName' => 'click',
							'outbound'  => 'true',
						]
					),
				];
			case 'downloads':
				return [
					'dimensions' => [ 'date', 'fileName' ],
					'metrics'    => [ 'eventCount', 'totalUsers' ],
					'filter'     => self::and_filter( [ 'eventName' => 'file_download' ] ),
				];
			case 'events':
				return [
					'dimensions' => [ 'date', 'eventName' ],
					'metrics'    => [ 'eventCount', 'totalUsers' ],
				];
			default:
				return [
					'dimensions' => [ 'date' ],
					'metrics'    => $metrics,
				];
		}
	}

	/**
	 * Run one report, following pagination until the reported row count is
	 * exhausted.
	 *
	 * @param string[] $dimensions
	 * @param string[] $metrics
	 * @return array<int,array{dimensions:string[],metrics:string[]}>|\WP_Error
	 */
	private function run_report( string $property, string $token, string $from, string $to, array $dimensions, array $metrics, ?array $filter = null ) {
		$collected = [];
		$offset    = 0;

		do {
			$body = [
				'dateRanges'     => [ [ 'startDate' => $from, 'endDate' => $to ] ],
				'dimensions'     => array_map( static fn( string $d ): array => [ 'name' => $d ], $dimensions ),
				'metrics'        => array_map( static fn( string $m ): array => [ 'name' => $m ], $metrics ),
				'limit'          => self::PAGE_SIZE,
				'offset'         => $offset,
				'keepEmptyRows'  => false,
			];
			if ( null !== $filter ) {
				$body['dimensionFilter'] = $filter;
			}

			$response = wp_remote_post(
				sprintf( self::DATA_URL, rawurlencode( $property ) ),
				[
					'timeout' => self::REMOTE_TIMEOUT,
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode( $body ),
				]
			);

			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'vireo_ga4_http', $response->get_error_message() );
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code ) {
				$message = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';
				if ( 403 === $code ) {
					// Two different 403s arrive here. PERMISSION_DENIED means the
					// service account cannot see the property, and Google's own
					// wording buries that under a link about property IDs, so we
					// say it plainly. The other one is SERVICE_DISABLED, raised
					// when the Data API is not enabled on the project, and its
					// message names the API and links the page that turns it on.
					// Overwriting that sent people to check property access when
					// the problem was a switch in a different console.
					$status = is_array( $decoded ) ? (string) ( $decoded['error']['status'] ?? '' ) : '';
					if ( 'PERMISSION_DENIED' === $status || '' === $message ) {
						$message = __( 'Google refused the request. Check the service account has Viewer access on this GA4 property.', 'vireo-analytics' );
					}
				}
				return new \WP_Error(
					'vireo_ga4_api',
					'' !== $message ? $message : sprintf(
						/* translators: %d: HTTP status code */
						__( 'Google Analytics returned an unexpected response (HTTP %d).', 'vireo-analytics' ),
						$code
					)
				);
			}

			if ( ! is_array( $decoded ) ) {
				return new \WP_Error( 'vireo_ga4_parse', __( 'Google Analytics returned a response we could not read.', 'vireo-analytics' ) );
			}

			$rows = (array) ( $decoded['rows'] ?? [] );
			foreach ( $rows as $row ) {
				$collected[] = [
					'dimensions' => array_column( (array) ( $row['dimensionValues'] ?? [] ), 'value' ),
					'metrics'    => array_column( (array) ( $row['metricValues'] ?? [] ), 'value' ),
				];
			}

			$row_count = (int) ( $decoded['rowCount'] ?? count( $collected ) );
			$offset   += self::PAGE_SIZE;
			$more      = count( $rows ) > 0 && $offset < $row_count;
		} while ( $more );

		return $collected;
	}

	/**
	 * Exchange the service account key for an access token.
	 *
	 * Signing lives in Google_Auth because Search Console needs exactly the
	 * same dance against a different scope.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token( string $key_json ) {
		return \VireoAnalytics\Google_Auth::access_token( $key_json, self::SCOPE );
	}

	/** GA4 returns dates as YYYYMMDD. */
	private function normalize_date( string $raw ): string {
		if ( 1 !== preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ) {
			return '';
		}
		return "{$m[1]}-{$m[2]}-{$m[3]}";
	}

	private function normalize_path( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = $raw;
		}
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}
		return \VireoAnalytics\Collector::clip( $path, 500 );
	}

	/**
	 * GA4's pageReferrer is a full URL. Vireo keys referrers on host, and a
	 * referral from this site to itself is not a referral.
	 */
	private function normalize_host( string $raw ): string {
		if ( '' === $raw || '(not set)' === $raw ) {
			return '';
		}

		$host = (string) wp_parse_url( $raw, PHP_URL_HOST );
		if ( '' === $host ) {
			return '';
		}

		$host = strtolower( preg_replace( '/^www\./', '', $host ) );
		$self = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

		return $host === $self ? '' : \VireoAnalytics\Collector::clip( $host, 190 );
	}

	private function normalize_device( string $raw ): string {
		switch ( strtolower( $raw ) ) {
			case 'mobile':
				return 'Mobile';
			case 'tablet':
				return 'Tablet';
			case 'desktop':
				return 'Desktop';
			default:
				return 'Unknown';
		}
	}

	/**
	 * @return array{start:\DateTimeImmutable,end:\DateTimeImmutable}|null
	 */
	private function parse_range( string $start, string $end ): ?array {
		try {
			$tz    = new \DateTimeZone( 'UTC' );
			$from  = new \DateTimeImmutable( '' !== $start ? $start : '-365 days', $tz );
			$until = new \DateTimeImmutable( '' !== $end ? $end : 'yesterday', $tz );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( $until < $from ) {
			return null;
		}

		return [
			'start' => $from,
			'end'   => $until,
		];
	}

	/** @return array{done:bool,processed:int,total:int,next_offset:int,label:string} */
	private function envelope_done( int $total ): array {
		return [
			'done'        => true,
			'processed'   => $total,
			'total'       => $total,
			'next_offset' => $total,
			'label'       => __( 'Import complete', 'vireo-analytics' ),
		];
	}

	/** @return array{done:bool,processed:int,total:int,next_offset:int,label:string,error:string} */
	private function envelope_error( string $message ): array {
		return [
			'done'        => true,
			'processed'   => 0,
			'total'       => 0,
			'next_offset' => 0,
			'label'       => __( 'Import stopped', 'vireo-analytics' ),
			'error'       => $message,
		];
	}

	/**
	 * Events GA4 records on its own, with or without enhanced measurement.
	 * Outbound clicks, downloads and form submissions are mapped to the
	 * tracker's own names below; the rest describe sessions, scrolling,
	 * video and site search rather than anything the site fired, and Vireo
	 * either counts them another way or has no report for them.
	 */
	private const AUTOMATIC_EVENTS = [ 'page_view', 'session_start', 'first_visit', 'user_engagement', 'scroll', 'click', 'file_download', 'form_start', 'view_search_results', 'video_start', 'video_progress', 'video_complete' ];

	/**
	 * @param array<string,string> $equals Dimension => exact value, all of which must match.
	 * @return array<string,mixed>
	 */
	private static function and_filter( array $equals ): array {
		$expressions = [];
		foreach ( $equals as $dimension => $value ) {
			$expressions[] = [
				'filter' => [
					'fieldName'    => $dimension,
					'stringFilter' => [
						'matchType' => 'EXACT',
						'value'     => $value,
					],
				],
			];
		}
		return [ 'andGroup' => [ 'expressions' => $expressions ] ];
	}

	/**
	 * Event rows from the outbound, downloads and events reports. Each row is
	 * [date, dimension] with metrics [eventCount, totalUsers].
	 *
	 * @param array<int,array{dimensions:string[],metrics:string[]}> $rows
	 * @return array<int, array{date:string,name:string,value:string,count:int,unique_count:int}>
	 */
	public static function event_rows( string $report, array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$raw  = (string) ( $row['dimensions'][0] ?? '' );
			$date = 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : '';
			$dim  = trim( (string) ( $row['dimensions'][1] ?? '' ) );
			if ( '' === $date || '' === $dim || '(not set)' === $dim ) {
				continue;
			}

			if ( 'outbound' === $report ) {
				$name  = 'outbound';
				$value = strtolower( $dim );
			} elseif ( 'downloads' === $report ) {
				$name  = 'download';
				$value = self::download_name( $dim );
			} elseif ( 'form_submit' === $dim ) {
				$name  = 'form_submit';
				$value = '';
			} elseif ( in_array( $dim, self::AUTOMATIC_EVENTS, true ) ) {
				continue;
			} else {
				$name  = $dim;
				$value = '';
			}

			$out[] = [
				'date'         => $date,
				'name'         => $name,
				'value'        => $value,
				'count'        => (int) ( $row['metrics'][0] ?? 0 ),
				'unique_count' => (int) ( $row['metrics'][1] ?? 0 ),
			];
		}
		return self::merge_rows( $out, [ 'date', 'name', 'value' ], [ 'count', 'unique_count' ] );
	}
}
