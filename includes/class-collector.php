<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Buffer writer. Appends one serialized pageview per line to a CSV-ish buffer
 * file in wp-content/uploads/vireo-analytics/. The Aggregator cron reads the
 * file, parses it, and flushes rollups into the DB.
 *
 * Format: one line per event, `serialize()`'d PHP array. We keep PHP's native
 * serialization (rather than CSV) because paths / referrers can contain commas,
 * quotes, and unicode; escaping CSV properly at 5ms budget isn't worth it when
 * `unserialize` with `allowed_classes => false` is already safe.
 *
 * Line shape for pageviews:
 *   [ 'p', ts, path, post_id, new_visitor, unique_pageview, referrer, ua, ip, visitor_hash ]
 *
 * `visitor_hash` was added after the initial scaffold (server-side uniqueness
 * rollout). Older buffer lines without it still parse — Aggregator falls back
 * to the legacy IP-keyed dedupe logic.
 *
 * Static helpers so this file is reusable from the tracker endpoint too.
 */
final class Collector {

	/**
	 * Longest active time one view can report, in seconds. Past half an hour
	 * the tab was left open, not read. Here rather than on the aggregator
	 * because the endpoint clamps with it and loads this class, not that one.
	 */
	public const MAX_VIEW_SECONDS = 1800;

	/** Bytes under the data tree, measured once per request. See dir_bytes(). */
	private static ?int $dir_bytes = null;

	/**
	 * Resolve the buffer directory (under wp-content/uploads). Shares the
	 * data dir with the aggregator buffer files; buffer files are
	 * disambiguated by the `buffer-` filename prefix.
	 */
	public static function upload_dir(): string {
		// The main data directory, or the site-{ID} directory inside it when a
		// connected site is in context. One directory per site is what keeps
		// one site's visits from ever reaching another site's tables.
		return Sites::data_dir();
	}

	/**
	 * Active buffer filename. Re-uses an existing non-busy buffer file in the
	 * directory if one exists (so concurrent writes keep appending to the same
	 * file), otherwise generates a fresh random name.
	 */
	public static function buffer_filename(): string {
		$dir = self::upload_dir();

		if ( is_dir( $dir ) ) {
			$filenames = scandir( $dir );
			if ( is_array( $filenames ) ) {
				foreach ( $filenames as $filename ) {
					// Match the live buffer shape exactly: buffer-<32 hex>.csv.
					//
					// This used to accept anything starting with `buffer-` that
					// did not end in `.busy`, which quietly adopted a failed
					// snapshot as the live buffer. A snapshot the aggregator
					// could not write is left as `buffer-….csv.<rand>.busy`,
					// the replay pass renames it to `….busy.replay`, and that
					// name satisfied both halves of the old test. New hits were
					// then appended to a file nothing would ever write, run()
					// renamed it to `.busy` again, replay renamed it to
					// `.replay` again, and the name grew a suffix per cycle
					// while the data never reached the database. Seen in the
					// wild at ten stacked suffixes and six megabytes, five
					// weeks after it started.
					if ( 1 === preg_match( '/^buffer-[0-9a-f]{32}\.csv$/', $filename ) ) {
						return "{$dir}/{$filename}";
					}
				}
			}
		}

		return sprintf( '%s/buffer-%s.csv', $dir, bin2hex( random_bytes( 16 ) ) );
	}

	/**
	 * Every file currently matching the live-buffer shape, oldest first.
	 *
	 * Normally there is one. There can be more: buffer_filename() reuses an
	 * existing live buffer when it finds one, but two requests that both find
	 * none will each mint a name, and both are then live. The aggregator drains
	 * all of them rather than the first, or a burst can create buffers faster
	 * than one-per-minute drains them.
	 *
	 * @return array<int,string> Absolute paths.
	 */
	public static function live_buffers(): array {
		$dir = self::upload_dir();
		if ( ! is_dir( $dir ) ) {
			return [];
		}

		$filenames = scandir( $dir );
		if ( ! is_array( $filenames ) ) {
			return [];
		}

		$out = [];
		foreach ( $filenames as $filename ) {
			if ( 1 === preg_match( '/^buffer-[0-9a-f]{32}\.csv$/', $filename ) ) {
				$out[] = "{$dir}/{$filename}";
			}
		}

		usort(
			$out,
			static function ( string $a, string $b ): int {
				return ( @filemtime( $a ) ?: 0 ) <=> ( @filemtime( $b ) ?: 0 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a vanished file sorts first and is skipped later
			}
		);

		return $out;
	}

	/**
	 * Bytes currently held by every buffer file under the data directory: the
	 * main site's and every connected site's `site-{ID}` directory together.
	 *
	 * The per-file cap alone does not bound disk use: each new live buffer
	 * carries its own allowance, and snapshots and parked `.failed` files sit
	 * alongside them. One total for the whole tree, so adding sites does not
	 * multiply the ceiling. Measured once per request.
	 */
	public static function dir_bytes(): int {
		if ( null !== self::$dir_bytes ) {
			return self::$dir_bytes;
		}

		$base = Sites::data_dir( Sites::MAIN );
		if ( '' === $base || ! is_dir( $base ) ) {
			self::$dir_bytes = 0;
			return 0;
		}

		$total = self::buffer_bytes_in( $base );
		foreach ( (array) scandir( $base ) as $entry ) {
			if ( 1 === preg_match( '/^site-\d+$/', (string) $entry ) ) {
				$total += self::buffer_bytes_in( $base . '/' . $entry );
			}
		}

		self::$dir_bytes = $total;
		return $total;
	}

	private static function buffer_bytes_in( string $dir ): int {
		$filenames = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( ! is_array( $filenames ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $filenames as $filename ) {
			if ( 0 !== strpos( $filename, 'buffer-' ) ) {
				continue;
			}
			$total += (int) @filesize( "{$dir}/{$filename}" ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a file removed mid-scan counts as zero
		}
		return $total;
	}

	/**
	 * Ceiling for everything the data directory tree holds, in bytes, across
	 * the main site and every connected site.
	 *
	 * Reached only when the aggregator has stopped draining, so the Site Health
	 * backlog check is the thing that explains it.
	 */
	public static function dir_byte_ceiling(): int {
		return (int) apply_filters( 'vireo_buffer_dir_max_bytes', 250 * 1024 * 1024 );
	}

	/**
	 * A request path as it should be stored and compared: percent-decoded,
	 * control characters removed, then sanitize_text_field().
	 *
	 * Decoding has to come first. sanitize_text_field() removes percent-encoded
	 * octets, so run on an encoded path it turns /caf%C3%A9/ into /caf/, and
	 * the 404 tracker and the importers then file the same page under two
	 * different keys. Both call this so they agree.
	 */
	public static function decode_path( string $raw ): string {
		$path = rawurldecode( $raw );
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		return sanitize_text_field( $path );
	}

	/**
	 * Trim text to a byte budget without leaving a broken character behind.
	 *
	 * This used to be a plain substr(), which cuts at a byte offset and will
	 * happily split a multi-byte character in half. The result is not valid
	 * UTF-8, MySQL rejects the row outright, and because the aggregator treats
	 * any failed write as a failed batch the whole snapshot is retried forever.
	 * That is how five weeks of traffic was lost on a live site: search-spam
	 * bots submitting emoji, landing exactly on the 100-byte boundary.
	 *
	 * Anything still malformed after the cut is dropped rather than passed on,
	 * because the alternative is one bad byte costing every record behind it.
	 * The importers cut paths and hosts with it for the same reason.
	 */
	public static function clip( string $value, int $bytes ): string {
		if ( '' === $value ) {
			return '';
		}

		$value = function_exists( 'mb_strcut' )
			? mb_strcut( $value, 0, $bytes, 'UTF-8' )
			: substr( $value, 0, $bytes );

		if ( 1 !== preg_match( '//u', $value ) ) {
			$value = function_exists( 'mb_convert_encoding' )
				? mb_convert_encoding( $value, 'UTF-8', 'UTF-8' )
				: (string) preg_replace( '/[^\x00-\x7F]/', '', $value );
		}

		return $value;
	}

	/**
	 * Append a pageview record to the buffer. Returns true on success.
	 *
	 * `visitor_hash` is the HMAC output from Salt_Manager::hash_with_salt().
	 * When present it drives server-side uniqueness in the aggregator;
	 * `new_visitor` / `unique_pageview` booleans become hints only.
	 *
	 * @param array{
	 *     ts: int,
	 *     path: string,
	 *     post_id: int,
	 *     new_visitor: bool,
	 *     unique_pageview: bool,
	 *     referrer: string,
	 *     ua: string,
	 *     ip: string,
	 *     visitor_hash?: string,
	 * } $pageview
	 */
	/**
	 * Append a custom event to the buffer. Same write-path as pageviews so the
	 * aggregator processes both in a single cron tick.
	 *
	 * Line shape:
	 *   [ 'e', ts, visitor_hash, name, value ]
	 *
	 * @param array{
	 *     ts?: int,
	 *     name: string,
	 *     value?: string,
	 *     visitor_hash?: string,
	 * } $event
	 */
	public static function append_event( array $event ): bool {
		$name = (string) ( $event['name'] ?? '' );
		if ( '' === $name ) {
			return false;
		}

		$line = [
			'e',
			(int) ( $event['ts'] ?? time() ),
			(string) ( $event['visitor_hash'] ?? '' ),
			self::clip( $name, 100 ),
			self::clip( (string) ( $event['value'] ?? '' ), 100 ),
			// Origin of the headless front end that sent it, '' for this site.
			// Trailing, like every field added since the first shape.
			self::clip( (string) ( $event['site'] ?? '' ), 300 ),
		];

		return self::append_line( $line );
	}

	public static function append_pageview( array $pageview ): bool {
		$line = [
			'p',
			(int) ( $pageview['ts'] ?? time() ),
			self::clip( (string) ( $pageview['path'] ?? '' ), 500 ),
			(int) ( $pageview['post_id'] ?? 0 ),
			! empty( $pageview['new_visitor'] ) ? 1 : 0,
			! empty( $pageview['unique_pageview'] ) ? 1 : 0,
			self::clip( (string) ( $pageview['referrer'] ?? '' ), 500 ),
			self::clip( (string) ( $pageview['ua'] ?? '' ), 500 ),
			(string) ( $pageview['ip'] ?? '' ),
			(string) ( $pageview['visitor_hash'] ?? '' ),
			// Country from the host, when the host knows it. Only meaningful at
			// request time, so it has to travel with the record; the aggregator
			// runs later on cron and can't ask again. Appended last so older
			// buffer files still parse.
			(string) ( $pageview['country'] ?? '' ),
			// Origin of the headless front end that sent it, '' for this site's
			// own pages. Free has at most one; the value is per hit so an add-on
			// can keep several front ends apart without a schema change.
			self::clip( (string) ( $pageview['site'] ?? '' ), 300 ),
		];

		return self::append_line( $line );
	}

	/**
	 * Append one Core Web Vitals sample.
	 *
	 * Line shape:
	 *   [ 'v', ts, path, lcp_ms, cls_thousandths, inp_ms, site ]
	 *
	 * CLS travels as thousandths so the whole record stays integers; a float
	 * in a serialized line is the kind of thing that reads back differently on
	 * a different locale.
	 *
	 * @param array{ts?:int, path:string, lcp?:int, cls?:int, inp?:int} $vitals
	 */
	public static function append_vitals( array $vitals ): bool {
		$path = self::clip( (string) ( $vitals['path'] ?? '' ), 500 );
		if ( '' === $path ) {
			return false;
		}

		return self::append_line(
			[
				'v',
				(int) ( $vitals['ts'] ?? time() ),
				$path,
				(int) ( $vitals['lcp'] ?? 0 ),
				(int) ( $vitals['cls'] ?? 0 ),
				(int) ( $vitals['inp'] ?? 0 ),
				self::clip( (string) ( $vitals['site'] ?? '' ), 300 ),
			]
		);
	}

	/**
	 * Append one time-on-page report.
	 *
	 * Line shape:
	 *   [ 't', ts, path, seconds, first, visitor_hash, site ]
	 *
	 * `seconds` is the active time added since the view's previous report and
	 * `first` is 1 on its first. The visitor hash is what lets the aggregator
	 * match the report to a pageview it counted; see Aggregator::fold_time().
	 *
	 * @param array{ts?:int, path:string, seconds?:int, first?:bool, visitor_hash?:string, site?:string} $time
	 */
	public static function append_time( array $time ): bool {
		$path = self::clip( (string) ( $time['path'] ?? '' ), 500 );
		if ( '' === $path ) {
			return false;
		}

		return self::append_line(
			[
				't',
				(int) ( $time['ts'] ?? time() ),
				$path,
				(int) ( $time['seconds'] ?? 0 ),
				! empty( $time['first'] ) ? 1 : 0,
				(string) ( $time['visitor_hash'] ?? '' ),
				self::clip( (string) ( $time['site'] ?? '' ), 300 ),
			]
		);
	}

	/**
	 * Append a filtered-bot record. One line per rejected request.
	 *
	 * Only the category is kept. The user agent is attacker-controlled text
	 * that nothing displays, so storing it would grow the table without bound
	 * for no benefit.
	 *
	 * Line shape:
	 *   [ 'b', ts, category ]
	 *
	 * @param array{ts?: int, category?: string} $bot
	 */
	public static function append_bot( array $bot ): bool {
		return self::append_line(
			[
				'b',
				(int) ( $bot['ts'] ?? time() ),
				self::clip( (string) ( $bot['category'] ?? 'other' ), 32 ),
			]
		);
	}

	/**
	 * Append a goal completion record to the buffer. One line per (goal_id,
	 * visitor_hash, timestamp). The aggregator dedupes on (date, goal_id,
	 * visitor_hash) to derive `unique_completions`; total `completions` is
	 * the raw line count.
	 *
	 * Line shape:
	 *   [ 'g', ts, visitor_hash, goal_id, value ]
	 *
	 * @param array{
	 *     ts?: int,
	 *     goal_id: int,
	 *     visitor_hash?: string,
	 *     value?: float,
	 * } $completion
	 */
	public static function append_goal_completion( array $completion ): bool {
		$goal_id = (int) ( $completion['goal_id'] ?? 0 );
		if ( $goal_id <= 0 ) {
			return false;
		}

		$line = [
			'g',
			(int) ( $completion['ts'] ?? time() ),
			(string) ( $completion['visitor_hash'] ?? '' ),
			$goal_id,
			(float) ( $completion['value'] ?? 0 ),
		];

		return self::append_line( $line );
	}

	/**
	 * Append a raw serialized line to the active buffer file. Creates the
	 * directory if needed. Uses `LOCK_EX` so concurrent writers don't
	 * interleave lines.
	 */
	public static function append_line( array $record ): bool {
		$filename  = self::buffer_filename();
		$directory = dirname( $filename );

		if ( ! self::ensure_dir( $directory ) ) {
			return false;
		}

		// Hard cap on buffer file size: prevents an attacker from filling disk
		// by flooding the endpoint. The aggregator flushes every minute so a
		// legitimate buffer never approaches this limit.
		if ( is_file( $filename ) && filesize( $filename ) > 50 * 1024 * 1024 ) {
			return false;
		}

		// The per-file cap does not bound the directory. A burst arriving while
		// no live buffer exists makes several of them, each with its own
		// allowance, and failed snapshots park here too. Stop writing once the
		// directory as a whole is too big, whatever the shape of the files in
		// it.
		if ( self::dir_bytes() > self::dir_byte_ceiling() ) {
			return false;
		}

		$content = serialize( $record ) . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- LOCK_EX append; WP_Filesystem has no equivalent
		return false !== file_put_contents( $filename, $content, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Make sure the buffer directory exists, hardening it when this request
	 * created it: buffer files hold raw IPs and user agents and must never be
	 * reachable from a browser.
	 *
	 * Two beacons can find the directory missing at the same moment, which is
	 * what a connected site's first visits look like. Both call mkdir(), and
	 * the one that loses gets false back for a directory that now exists. That
	 * used to answer the beacon with a 500 and lose the visit, so a failure is
	 * only a failure if the directory still isn't there.
	 */
	private static function ensure_dir( string $directory ): bool {
		if ( is_dir( $directory ) ) {
			return true;
		}

		if ( wp_mkdir_p( $directory ) ) {
			Salt_Manager::harden_directory( $directory );
			return true;
		}

		clearstatcache( true, $directory );
		return is_dir( $directory );
	}

	/**
	 * Test that we can write to the buffer directory. Used by the endpoint
	 * installer's verification step — creates the directory if needed and
	 * returns true if it's writable.
	 */
	public static function test_writable(): bool {
		$filename  = self::buffer_filename();
		$directory = dirname( $filename );

		if ( is_file( $filename ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return is_writable( $filename );
		}

		if ( ! self::ensure_dir( $directory ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		return is_writable( $directory );
	}
}
