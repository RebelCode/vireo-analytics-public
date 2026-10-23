<?php
/**
 * Jetpack Stats legacy CSV importer.
 *
 * Pulls historical pageview + referrer data from stats.wordpress.com using the
 * long-standing csv.php endpoint. The user supplies a WordPress.com API key
 * (often copied from Jetpack → Security → Akismet), a blog URI, and a date
 * range. We walk that range backwards in 30-day chunks so the user sees
 * progress from "now" to "then", and so each HTTP call fits comfortably under
 * the endpoint's timeout.
 *
 * The driver is the admin-side AJAX loop in Import_Controller — this class
 * just owns one chunk at a time and reports back where the loop should
 * resume.
 *
 * Endpoint: https://stats.wordpress.com/csv.php
 *   query: api_key, blog_uri, end=YYYY-MM-DD, days=N, table=postviews|referrers,
 *          format=json, limit=-1
 */

namespace VireoAnalytics\Importers;

use DateTimeImmutable;
use Exception;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Jetpack_Importer extends Importer_Base {

	private const API_URL          = 'https://stats.wordpress.com/csv.php';
	private const CHUNK_SIZE_DAYS  = 30;
	private const REMOTE_TIMEOUT   = 90;
	private const PARAMS_OPTION    = 'vireo_jetpack_import_params';
	private const DEFAULT_TOTAL    = 365;

	public function slug(): string {
		return 'jetpack';
	}

	public function name(): string {
		return __( 'Jetpack Stats', 'vireo-analytics' );
	}

	/**
	 * Availability is loose on purpose. The wpcom CSV endpoint only needs an
	 * API key, and plenty of users have one from Akismet without having
	 * Jetpack itself "connected". So if Jetpack-the-plugin exists in any form
	 * we enable the importer and let the credentials succeed or fail at
	 * import time.
	 */
	public function is_available(): bool {
		return class_exists( 'Jetpack' )
			|| defined( 'JETPACK__VERSION' )
			|| class_exists( '\\Automattic\\Jetpack\\Stats\\Options' );
	}

	/**
	 * Total unit of work = number of calendar days in the user's date range.
	 * When the user hasn't submitted a range yet we return a plausible
	 * placeholder so the UI's progress bar has something to render against.
	 */
	public function count_records(): int {
		$saved = $this->get_saved_params();
		if ( $saved['date_start'] === '' || $saved['date_end'] === '' ) {
			return self::DEFAULT_TOTAL;
		}

		try {
			$from = new DateTimeImmutable( $saved['date_start'] );
			$to   = new DateTimeImmutable( $saved['date_end'] );
		} catch ( Exception $e ) {
			return 0;
		}

		if ( $to < $from ) {
			return 0;
		}
		return $from->diff( $to )->days + 1;
	}

	/** How long a stashed API key may live if an import is abandoned. */
	private const PARAMS_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Stash user-supplied params server-side so the AJAX loop doesn't have to
	 * round-trip the API key on every tick.
	 *
	 * A transient, not an option: this holds a live WordPress.com API key, and an
	 * import that fails or is simply abandoned used to leave it sitting in
	 * wp_options indefinitely. Now it expires on its own even if nothing cleans
	 * up after it.
	 *
	 * @param array{api_key:string, blog_uri:string, date_start:string, date_end:string} $params
	 */
	public function save_params( array $params ): void {
		set_transient( self::PARAMS_OPTION, $params, self::PARAMS_TTL );
	}

	/**
	 * @return array{api_key:string, blog_uri:string, date_start:string, date_end:string}
	 */
	public function get_saved_params(): array {
		$raw = get_transient( self::PARAMS_OPTION );

		// Older versions stored this as a permanent option. Migrate it out of
		// wp_options on first read so the key stops living there forever.
		if ( ! is_array( $raw ) ) {
			$legacy = get_option( self::PARAMS_OPTION, [] );
			delete_option( self::PARAMS_OPTION );
			$raw = is_array( $legacy ) ? $legacy : [];
		}

		return [
			'api_key'    => (string) ( $raw['api_key']    ?? '' ),
			'blog_uri'   => (string) ( $raw['blog_uri']   ?? '' ),
			'date_start' => (string) ( $raw['date_start'] ?? '' ),
			'date_end'   => (string) ( $raw['date_end']   ?? '' ),
		];
	}

	/** Does a usable key exist without handing it to the caller? */
	public function has_saved_key(): bool {
		return '' !== $this->get_saved_params()['api_key'];
	}

	public function clear_params(): void {
		delete_transient( self::PARAMS_OPTION );
		delete_option( self::PARAMS_OPTION );
	}

	/**
	 * Import one chunk. Called by the AJAX driver with the current offset
	 * (= days processed so far) and an advisory limit we ignore in favor of
	 * our fixed CHUNK_SIZE_DAYS.
	 *
	 * Returns a status envelope the JS driver uses to update the progress UI
	 * and decide whether to schedule the next tick.
	 *
	 * @param array $params   Echoed back from the driver; we prefer the server copy.
	 * @param int   $offset   Days already consumed from the end of the range.
	 * @param int   $limit    Unused — we size chunks by date, not row count.
	 *
	 * @return array{done:bool, processed:int, total:int, next_offset:int, label:string}
	 */
	public function import_chunk( array $params, int $offset, int $limit ): array {
		$saved = $this->get_saved_params();
		$this->require_params_present( $saved );

		$range = $this->parse_range( $saved['date_start'], $saved['date_end'] );
		[ $range_start, $range_end ] = $range;
		$total_days = $range_start->diff( $range_end )->days + 1;

		// Walk backwards: each chunk ends "offset" days before range_end.
		$chunk_end = $range_end->modify( '-' . $offset . ' days' );

		// If offset has already stepped past the start of the range, signal
		// completion immediately. This only happens when the driver picks up
		// a stale offset after the import finished in a previous session.
		if ( $chunk_end < $range_start ) {
			$this->clear_params();
			return $this->envelope_done( $total_days );
		}

		$chunk_days  = $this->chunk_days_for( $chunk_end, $range_start );
		$chunk_start = $chunk_end->modify( '-' . ( $chunk_days - 1 ) . ' days' );

		// Long-running import; chunked at the caller level

		// Each chunk fetches both tables. We don't roll referrer views into
		// site_stats because postviews already accounts for all pageviews for
		// the day — counting referrers on top would double the total.
		$rows_written  = $this->ingest_postviews( $saved, $chunk_end, $chunk_days );
		$rows_written += $this->ingest_referrers( $saved, $chunk_end, $chunk_days );

		$is_done = $chunk_start <= $range_start;
		if ( $is_done ) {
			$this->clear_params();
		}

		return [
			'done'        => $is_done,
			'processed'   => $rows_written,
			'total'       => $total_days,
			'next_offset' => $is_done ? $total_days : $offset + $chunk_days,
			'label'       => sprintf(
				/* translators: 1: chunk start date, 2: chunk end date */
				__( 'Importing %1$s — %2$s', 'vireo-analytics' ),
				$chunk_start->format( 'Y-m-d' ),
				$chunk_end->format( 'Y-m-d' )
			),
		];
	}

	// -------------------------------------------------------------------------
	//  Chunk helpers
	// -------------------------------------------------------------------------

	private function require_params_present( array $saved ): void {
		if ( $saved['api_key'] === '' || $saved['blog_uri'] === ''
			|| $saved['date_start'] === '' || $saved['date_end'] === '' ) {
			throw new RuntimeException( esc_html__( 'Missing import parameters. Please submit the form again.', 'vireo-analytics' ) );
		}
	}

	/**
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	private function parse_range( string $start, string $end ): array {
		try {
			$from = new DateTimeImmutable( $start );
			$to   = new DateTimeImmutable( $end );
		} catch ( Exception $e ) {
			throw new RuntimeException( esc_html__( 'Invalid date range — please use YYYY-MM-DD.', 'vireo-analytics' ) );
		}

		if ( $to < $from ) {
			throw new RuntimeException( esc_html__( 'End date must be on or after start date.', 'vireo-analytics' ) );
		}

		return [ $from, $to ];
	}

	/**
	 * Clamp the chunk size so it never extends past the user-supplied start
	 * date, and never goes below 1 day (a zero-day window is meaningless).
	 */
	private function chunk_days_for( DateTimeImmutable $chunk_end, DateTimeImmutable $range_start ): int {
		$remaining = $range_start->diff( $chunk_end )->days + 1;
		if ( $remaining < self::CHUNK_SIZE_DAYS ) {
			return max( 1, $remaining );
		}
		return self::CHUNK_SIZE_DAYS;
	}

	private function envelope_done( int $total ): array {
		return [
			'done'        => true,
			'processed'   => 0,
			'total'       => $total,
			'next_offset' => $total,
			'label'       => __( 'Complete', 'vireo-analytics' ),
		];
	}

	// -------------------------------------------------------------------------
	//  Ingestion per table
	// -------------------------------------------------------------------------

	/**
	 * Fetch postviews for the given window and upsert them into post_stats +
	 * a site_stats rollup. Returns number of post_stats rows written.
	 */
	private function ingest_postviews( array $saved, DateTimeImmutable $end, int $days ): int {
		$response = $this->call_jetpack( $saved, 'postviews', $end, $days );
		if ( $response === [] ) {
			return 0;
		}

		$mapped = self::postview_rows( $response, fn( int $post_id, $pv ): string => $this->path_for_post( $post_id, $pv ) );
		$this->upsert_post_stats_bulk( $mapped['posts'] );
		foreach ( $mapped['site'] as $date => $total ) {
			$this->upsert_site_stats( (string) $date, 0, $total );
		}

		return count( $mapped['posts'] );
	}

	/**
	 * Post rows and daily totals from a postviews response.
	 *
	 * The csv.php API returns view counts and nothing else, so visitors are 0. They
	 * used to be filled with the view count, which put a number on the
	 * visitors chart that Jetpack never recorded.
	 *
	 * @param array<int, object>                       $response
	 * @param callable(int, object): string            $path_for Resolves a post view to its path.
	 * @return array{posts: array<int, array{date:string,post_id:int,path:string,visitors:int,pageviews:int}>, site: array<string,int>}
	 */
	public static function postview_rows( array $response, callable $path_for ): array {
		$posts = [];
		$site  = [];
		foreach ( $response as $day ) {
			$date      = isset( $day->date ) ? (string) $day->date : '';
			$postviews = $day->postviews ?? null;
			if ( $date === '' || ! is_array( $postviews ) || $postviews === [] ) {
				continue;
			}

			foreach ( $postviews as $pv ) {
				$post_id = (int) ( $pv->post_id ?? 0 );
				$views   = (int) ( $pv->views ?? 0 );
				if ( $views <= 0 ) {
					continue;
				}

				$path = $path_for( $post_id, $pv );
				if ( $path === '' ) {
					continue;
				}

				$posts[]       = [
					'date'      => $date,
					'post_id'   => $post_id,
					'path'      => $path,
					'visitors'  => 0,
					'pageviews' => $views,
				];
				$site[ $date ] = ( $site[ $date ] ?? 0 ) + $views;
			}
		}

		return [
			'posts' => $posts,
			'site'  => $site,
		];
	}

	/**
	 * Fetch referrers for the given window and upsert them. No rollup into
	 * site_stats — postviews already counted those pageviews.
	 */
	private function ingest_referrers( array $saved, DateTimeImmutable $end, int $days ): int {
		$response = $this->call_jetpack( $saved, 'referrers', $end, $days );
		if ( $response === [] ) {
			return 0;
		}

		$rows = self::referrer_rows( $response );
		$this->upsert_referrer_stats_bulk( $rows );
		return count( $rows );
	}

	/**
	 * @param array<int, object> $response
	 * @return array<int, array{date:string,host:string,visitors:int,pageviews:int}>
	 */
	public static function referrer_rows( array $response ): array {
		$rows = [];
		foreach ( $response as $day ) {
			$date      = isset( $day->date ) ? (string) $day->date : '';
			$referrers = $day->referrers ?? null;
			if ( $date === '' || ! is_array( $referrers ) || $referrers === [] ) {
				continue;
			}

			foreach ( $referrers as $ref ) {
				$host  = self::host_of_referrer( $ref );
				$views = (int) ( $ref->views ?? ( $ref->total ?? 0 ) );
				if ( $host === '' || $views <= 0 ) {
					continue;
				}

				$rows[] = [
					'date'      => $date,
					'host'      => $host,
					'visitors'  => 0,
					'pageviews' => $views,
				];
			}
		}
		return $rows;
	}

	// -------------------------------------------------------------------------
	//  HTTP
	// -------------------------------------------------------------------------

	/**
	 * Make a request to the legacy csv.php endpoint and decode the JSON body.
	 * Converts the zoo of possible failure modes (transport error, 4xx, 5xx,
	 * non-JSON body) into a single set of user-friendly exceptions the
	 * driver can surface verbatim.
	 *
	 * @return array<int, object> Decoded top-level list; empty array for "no data".
	 */
	private function call_jetpack( array $saved, string $table, DateTimeImmutable $end, int $days ): array {
		$url = add_query_arg(
			[
				'api_key'  => $saved['api_key'],
				'blog_uri' => $saved['blog_uri'],
				'end'      => $end->format( 'Y-m-d' ),
				'table'    => $table,
				'format'   => 'json',
				'days'     => $days,
				'limit'    => -1,
			],
			self::API_URL
		);

		$result = wp_remote_post(
			$url,
			[
				'timeout' => self::REMOTE_TIMEOUT,
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: HTTP error detail returned by WP_Error. */
						__( 'Could not reach Jetpack Stats API: %s', 'vireo-analytics' ),
						$result->get_error_message()
					)
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $result );
		$body   = (string) wp_remote_retrieve_body( $result );

		$this->raise_for_http_status( $status );

		$trimmed = trim( $body );
		if ( $trimmed === '' || $trimmed === 'null' ) {
			return [];
		}

		$decoded = json_decode( $body );
		if ( $decoded === null ) {
			throw new RuntimeException( esc_html__( 'Unexpected (non-JSON) response from Jetpack Stats API.', 'vireo-analytics' ) );
		}

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Map HTTP status codes from the Jetpack API to human-readable errors.
	 * No-op on 2xx.
	 */
	private function raise_for_http_status( int $status ): void {
		if ( $status < 400 ) {
			return;
		}

		if ( $status === 401 || $status === 403 ) {
			throw new RuntimeException( esc_html__( 'Invalid or unauthorized API key. Copy your WordPress.com API key from Jetpack → Settings → Security → Akismet.', 'vireo-analytics' ) );
		}

		if ( $status === 429 ) {
			throw new RuntimeException( esc_html__( 'Jetpack Stats API is rate-limiting — please wait a minute and try again.', 'vireo-analytics' ) );
		}

		if ( $status >= 500 ) {
			throw new RuntimeException( esc_html__( 'Jetpack Stats API is unavailable right now. Please retry in a few minutes.', 'vireo-analytics' ) );
		}

		throw new RuntimeException(
			esc_html(
				sprintf(
					/* translators: %d: HTTP status code returned by the Jetpack API. */
					__( 'Jetpack Stats API returned an error (HTTP %d). Check your API key and blog URI.', 'vireo-analytics' ),
					$status
				)
			)
		);
	}

	// -------------------------------------------------------------------------
	//  Field extraction
	// -------------------------------------------------------------------------

	/**
	 * Resolve a canonical URL path for a post view. Priority:
	 *   1. post_permalink field returned by newer Jetpack responses
	 *   2. local get_permalink() for the post_id (best for imports on the
	 *      same site the data was collected for)
	 *   3. `/?p=ID` as a last resort so the row isn't dropped
	 *
	 * @param object $pv
	 */
	private function path_for_post( int $post_id, $pv ): string {
		$permalink = isset( $pv->post_permalink ) && is_string( $pv->post_permalink ) ? $pv->post_permalink : null;
		$local     = null;
		if ( null === $permalink && $post_id > 0 ) {
			$local = get_permalink( $post_id );
			$local = is_string( $local ) ? $local : null;
		}
		return self::path_for( $post_id, $permalink, $local );
	}

	/**
	 * The path Jetpack reports for "Home page / Archives": its post ID 0 is
	 * the front page and every archive as one bucket. It is stored under this
	 * fixed path, the one older imports already wrote, rather than under "/":
	 * the live tracker's "/" rows are the home page alone, so merging them
	 * would overstate the home page and lose the archives. Dashboard labels
	 * the path as what it is.
	 */
	public const HOME_ARCHIVES_PATH = '/?p=0';

	/**
	 * Pure: the stored path for a post view, given what Jetpack sent and what
	 * the local site knows. Post 0 used to fall through to '' and be dropped,
	 * which lost the home page and archive views from every import.
	 */
	public static function path_for( int $post_id, ?string $permalink, ?string $local ): string {
		if ( 0 === $post_id ) {
			return self::HOME_ARCHIVES_PATH;
		}
		if ( $post_id < 0 ) {
			return '';
		}
		foreach ( [ $permalink, $local ] as $candidate ) {
			if ( null === $candidate || '' === $candidate ) {
				continue;
			}
			$path = wp_parse_url( $candidate, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				return $path;
			}
		}
		return '/?p=' . $post_id;
	}

	/**
	 * Teasing a host out of a referrer entry. The Jetpack payload has changed
	 * shape across versions — newer responses put the URL in `url`, older
	 * ones use `value` or `group` or a mix. Try each in order and return the
	 * first candidate that resolves to a real host.
	 *
	 * @param mixed $ref
	 */
	private static function host_of_referrer( $ref ): string {
		if ( ! is_object( $ref ) ) {
			return '';
		}

		static $fields = [ 'url', 'value', 'group', 'name', 'referrer', 'domain' ];

		foreach ( $fields as $field ) {
			if ( ! isset( $ref->{$field} ) || ! is_string( $ref->{$field} ) ) {
				continue;
			}
			$host = self::as_bare_host( $ref->{$field} );
			if ( $host !== '' ) {
				return $host;
			}
		}

		return '';
	}

	/**
	 * Reduce an arbitrary referrer string (URL, bare host, or grouped label
	 * like "Search Engines") to a lowercase host with the `www.` prefix
	 * stripped. Returns '' for anything that doesn't look host-like.
	 */
	private static function as_bare_host( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		// Grouped labels have neither a dot nor a slash — reject early.
		if ( strpos( $value, '.' ) === false && strpos( $value, '/' ) === false ) {
			return '';
		}

		// parse_url is forgiving with schemeless input but occasionally
		// surprising; prefixing http:// normalizes behaviour.
		if ( strpos( $value, '://' ) === false ) {
			$value = 'http://' . $value;
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) {
			return '';
		}

		$host = strtolower( $host );
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}
}
