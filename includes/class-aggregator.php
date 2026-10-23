<?php

namespace VireoAnalytics;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Per-minute batch job that drains the pageview buffer file and folds its
 * contents into the five daily rollup tables: site totals, per-post, referrer
 * hosts, country, and device.
 *
 * Flow:
 *   1. Rename buffer file to `.busy` so concurrent tracker writes create a
 *      fresh buffer.
 *   2. Read the `.busy` file line by line; each line is a serialized pageview
 *      record. Accumulate counts in memory, keyed by rollup.
 *   3. Bulk-upsert each rollup table. The string-keyed dimensions (paths,
 *      referrer hosts) go through Upserter first to turn strings into ids.
 *
 * Date bucketing uses the site's timezone (vireo_bucket_date), because the
 * dashboard's date pickers do. Bucketing in UTC while the ranges were built in
 * site time filed evening traffic under the next day for every site east of
 * UTC, so "today" was missing hours it should have had.
 */
final class Aggregator {

	/**
	 * Legacy marker line, skipped on read so an old buffer written by a build
	 * that emitted one still parses.
	 *
	 * Nothing writes it any more, and the comment here used to claim otherwise.
	 * It would not be a protection in any case: buffers are `.csv`, so a PHP
	 * guard line at the top stops nothing. What actually keeps a buffer out of
	 * the browser is `Salt_Manager::harden_directory()` (.htaccess on Apache,
	 * web.config on IIS, an index.php stub) plus a 128-bit random filename —
	 * and on nginx, where neither config file is read, that filename is the
	 * whole defence. Buffers hold raw IPs and live for about a minute.
	 */
	private const BUFFER_MARKER = '<?php exit; ?>';

	/** Passes at one snapshot before it is set aside instead of retried forever. */
	private const MAX_SNAPSHOT_ATTEMPTS = 5;

	/** Where a stalled aggregator is recorded so someone can actually see it. */
	public const STALL_OPTION = 'vireo_aggregator_stall';

	/** Live buffers drained in one tick. Bounded so a backlog cannot run the cron past its timeout. */
	private const MAX_BUFFERS_PER_RUN = 20;

	/**
	 * Rows per multi-row INSERT. A day with thousands of distinct paths in one
	 * statement can exceed the server's max_allowed_packet, and a snapshot that
	 * fails that way fails the same way on every retry until it is set aside.
	 */
	private const INSERT_BATCH = 500;


	private static ?Aggregator $instance = null;

	/** @var array<string, object{pageviews:int, visitors:int}> date => counts */
	private array $site_rollup = [];

	/** @var array<string, array<string, object{pageviews:int, visitors:int, post_id:int}>> date => path => counts */
	private array $post_rollup = [];

	/** @var array<string, array<string, object{pageviews:int, visitors:int}>> date => host => counts */
	private array $referrer_rollup = [];

	/** @var array<string, array<string, object{pageviews:int, visitors:int}>> date => country => counts */
	private array $geo_rollup = [];

	/** @var array<string, array<string, object{pageviews:int, visitors:int, browser:string, os:string, device_type:string}>> date => bucket_key => counts */
	private array $device_rollup = [];

	/** @var array<string, array<string, object{count:int, unique_count:int, name:string, value:string}>> date => event_key => counts */
	private array $event_rollup = [];

	/** @var array<string, array<int, object{completions:int, unique_completions:int, total_value:float}>> date => goal_id => counts */
	private array $goal_rollup = [];

	/** @var array<string, array<string, object{hits:int}>> date => category => counts */
	private array $bot_rollup = [];


	/** @var array<string, array<int, object{pageviews:int, visitors:int, hour:int}>> date => hour => counts */
	private array $hour_rollup = [];

	/** @var array<string, array<string, object{pageviews:int, visitors:int, post_id:int, host:string}>> date => "post_id|host" => counts */
	private array $post_referrer_rollup = [];

	/** @var array<string, array<string, object{samples:int, seconds:int}>> date => path => active time */
	private array $time_rollup = [];

	/** @var array<string, object{visitors:int, multi_page:int}> date => visitors for the bounce rate */
	private array $bounce_rollup = [];

	/**
	 * Within-run cache in front of the va_visitor_seen table, so repeat hits
	 * from the same visitor in one batch don't re-query. The table, not this
	 * array, decides whether a visitor is new — see first_sighting().
	 *
	 * @var array<string, bool>
	 */
	private array $seen_cache = [];

	/**
	 * Pageviews each visitor has contributed per day, overall and per path,
	 * keyed "date|dedupe_key". Seeded from va_visitor_load the first time a key
	 * is met in a run and written back on flush, so the cap holds across
	 * batches. Overlapping runs can each count a few hits past it; the cap is a
	 * ceiling on runaway clients, not an exact quota.
	 *
	 * @var array<string, int>
	 */
	private array $load = [];

	/** @var array<string, int> Increments made this run, written on flush. */
	private array $load_delta = [];

	/** @var array<int, bool> Post IDs already checked this run. */
	private array $post_is_public = [];

	/** Why the last flush failed, kept so the stall record can say. */
	private string $last_write_error = '';

	/**
	 * Core Web Vitals histograms: date => metric => bucket => samples.
	 *
	 * Buckets rather than a running total, because the number that matters for
	 * these is the 75th percentile and an average cannot be recovered into one.
	 * A handful of very slow loads is exactly what a mean hides and a
	 * percentile exposes.
	 *
	 * @var array<string, array<string, array<int,int>>>
	 */
	private array $vitals_rollup = [];

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'vireo_flush_buffer', [ $this, 'run' ] );
		add_action( 'vireo_purge_hour_stats', [ $this, 'purge_hour_stats' ] );
		add_action( 'admin_notices', [ __CLASS__, 'stall_notice' ] );
	}

	/**
	 * Daily job. Drops hourly rows older than the retention window (default 7
	 * days). Daily rollups remain the long-term store; the hourly chart is
	 * only meaningful for recent ranges.
	 */
	public function purge_hour_stats(): void {
		$this->purge_hour_stats_site();
		foreach ( self::site_ids() as $site_id ) {
			Sites::switch_to( $site_id );
			try {
				$this->purge_hour_stats_site();
			} finally {
				Sites::restore();
			}
		}
	}

	private function purge_hour_stats_site(): void {
		global $wpdb;
		$table = Sites::table( 'hour_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$days   = (int) apply_filters( 'vireo_hour_stats_retention_days', 7 );
		$days   = max( 1, min( 90, $days ) );
		$cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance delete
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE date < %s', $table, $cutoff ) );
	}

	/**
	 * Cron entry point. Hooked to `vireo_flush_buffer` (every minute).
	 */
	public function run(): void {
		update_option( 'vireo_last_aggregation_at', time(), false );

		// The main site, then each connected site in its own context. Every
		// table name a writer uses comes from Sites::table() and every buffer
		// path from Collector::upload_dir(), both of which follow the context,
		// so a site's records can only ever land in that site's tables.
		$flushed = $this->run_site();

		foreach ( self::site_ids() as $site_id ) {
			Sites::switch_to( $site_id );
			try {
				$this->run_site();
			} finally {
				Sites::restore();
			}
		}

		// A site removed while a beacon was still writing can leave its
		// directory behind, holding raw IPs that nothing would ever drain.
		Sites::remove_orphan_dirs();

		// Once per run and only for this site's own data: listeners such as the
		// Pro spike detector read the main tables directly.
		if ( $flushed ) {
			do_action( 'vireo_after_flush' );
		}
	}

	/**
	 * Every connected site's id, in the order they were added. Separate so a
	 * test can drive the per-site loop without the option.
	 *
	 * @return array<int,int>
	 */
	public static function site_ids(): array {
		return array_keys( Sites::all() );
	}

	/**
	 * Drain the buffers of the site in context.
	 */
	private function run_site(): bool {
		// Anything a previous run left behind: a cron that died between taking a
		// snapshot and writing it to the DB. Those hits are still on disk (with
		// IP and UA in them), so replay them before doing anything else rather
		// than leaving them to rot in uploads.
		$this->replay_abandoned_snapshots();

		// Usually one file. Two requests that both find no live buffer will each
		// mint one, and draining only the first lets a burst create them faster
		// than they are drained, with each new file carrying its own size
		// allowance.
		$live_buffers = Collector::live_buffers();
		if ( empty( $live_buffers ) ) {
			return false;
		}

		$drained = 0;
		$ok      = false;

		foreach ( $live_buffers as $live_buffer ) {
			if ( $drained >= self::MAX_BUFFERS_PER_RUN ) {
				// The rest keep until the next tick rather than running this one
				// past the cron timeout and leaving snapshots half-written.
				break;
			}

			if ( ! is_file( $live_buffer ) ) {
				continue;
			}

			// Swap the live buffer out of the way so new pageviews land in a fresh
			// file while we process this snapshot. rename() is atomic on POSIX (unlike
			// WP_Filesystem::move()), so concurrent writers can't interleave with our
			// read. The name is unique per run so two overlapping crons can never
			// fight over the same snapshot.
			$snapshot = self::snapshot_name( $live_buffer, 1 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,Generic.PHP.NoSilencedErrors.Discouraged -- benign on race; we just skip this tick
			if ( ! @rename( $live_buffer, $snapshot ) ) {
				self::record_stall( 'could not rename the live buffer for processing', $live_buffer );
				continue;
			}

			$drained++;

			if ( $this->process_snapshot( $snapshot ) ) {
				$ok = true;
			}
		}

		if ( $ok ) {
			self::clear_stall();
		}

		return $ok;
	}

	/**
	 * Rescue buffers the old retry naming stranded on disk.
	 *
	 * Before this release a failed snapshot was renamed by appending a suffix,
	 * so a file that kept failing grew a suffix per attempt until it passed the
	 * 255-byte filename limit and could not be renamed at all. Those files are
	 * still full of real visits, and nothing in the current code will ever look
	 * at them: they don't match the live-buffer shape and they don't end in
	 * .busy. Rename each to a fresh snapshot so the replay path picks it up.
	 *
	 * Runs once, on upgrade.
	 */
	public static function rescue_stranded_buffers(): int {
		$dir = vireo_data_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$names = scandir( $dir );
		if ( ! is_array( $names ) ) {
			return 0;
		}

		$rescued = 0;
		$settled = time() - ( 10 * MINUTE_IN_SECONDS );

		foreach ( $names as $name ) {
			// A buffer with suffixes on it, but not one the current code made.
			if ( 1 !== preg_match( '/^buffer-[0-9a-f]{32}\.csv\..+/', $name ) ) {
				continue;
			}
			if ( 1 === preg_match( '/\.r\d{1,2}\.(busy|failed)$/', $name ) ) {
				continue;
			}

			$path = $dir . '/' . $name;
			if ( ! is_file( $path ) ) {
				continue;
			}
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing file is a race, not an error
			if ( (int) @filemtime( $path ) > $settled ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,Generic.PHP.NoSilencedErrors.Discouraged -- losing the race is expected
			if ( @rename( $path, self::snapshot_name( $path, 1 ) ) ) {
				$rescued++;
			}
		}

		return $rescued;
	}

	/**
	 * Name for a snapshot of a buffer file, at a given attempt number.
	 *
	 * Always derived from the base `buffer-<hex>.csv`, never by appending to
	 * the name we were handed. The old code built each name from the previous
	 * one, so every failed cycle grew it by a suffix. After ten cycles the name
	 * reached the kernel's 255-byte ceiling, rename() started returning
	 * ENAMETOOLONG, and run() bailed out on that silently once a minute while
	 * hits kept appending to a file nothing would ever drain. This shape is a
	 * fixed 59 bytes no matter how many times a snapshot is retried.
	 */
	private static function snapshot_name( string $path, int $attempt ): string {
		$base = basename( $path );
		if ( preg_match( '/^(buffer-[0-9a-f]{32}\.csv)/', $base, $m ) ) {
			$base = $m[1];
		}

		return sprintf(
			'%s/%s.%s.r%d.busy',
			dirname( $path ),
			$base,
			bin2hex( random_bytes( 4 ) ),
			max( 1, min( 99, $attempt ) )
		);
	}

	/**
	 * Attempt number encoded in a snapshot name, 0 if there isn't one.
	 */
	private static function snapshot_attempt( string $path ): int {
		return preg_match( '/\.r(\d{1,2})\.busy$/', basename( $path ), $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Drain one snapshot into the rollup tables.
	 *
	 * The snapshot is deleted only once the database has taken the writes. It
	 * used to be deleted as soon as it had been read, which meant any failure in
	 * flush_to_db() — a deadlock, a timeout, a dropped connection — silently
	 * threw away that minute of traffic with no way to replay it. On failure we
	 * leave the file alone; the next run picks it up.
	 */
	private function process_snapshot( string $snapshot ): bool {
		// Start clean. State is reset after every flush, but a snapshot that
		// could not be opened leaves the previous rollups in place, and the
		// per-site loop must never carry one site's counts into the next.
		$this->reset_state();

		// Streaming line-by-line read of a multi-MB buffer. file_get_contents()
		// would force the whole file into memory; WP_Filesystem has no stream
		// API. Raw fopen/fgets is the correct tool here.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $snapshot, 'r' );
		if ( $handle === false ) {
			self::record_stall( 'could not open a buffer snapshot for reading', $snapshot );
			// Unreadable rather than un-writable: retrying won't help, and leaving
			// it would keep raw IPs on disk forever.
			wp_delete_file( $snapshot );
			return false;
		}

		try {
			$this->drain_buffer( $handle );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}

		$written = $this->flush_to_db();
		$this->reset_state();

		if ( $written ) {
			wp_delete_file( $snapshot );
			return true;
		}

		self::record_stall(
			'a buffer snapshot could not be written to the database',
			$snapshot,
			$this->last_write_error
		);
		$this->last_write_error = '';

		return false;
	}

	/**
	 * Note that aggregation is not getting through, and keep noting it.
	 *
	 * Every failure path here used to be gated behind WP_DEBUG, which is off on
	 * every production site, so a site could stop recording entirely and the
	 * only symptom was a dashboard that quietly stopped moving. The counter
	 * survives between runs so the admin notice can distinguish one bad tick
	 * from a fortnight of them.
	 */
	private static function record_stall( string $reason, string $path = '', string $detail = '' ): void {
		$stall = (array) get_option( self::STALL_OPTION, [] );
		$count = (int) ( $stall['count'] ?? 0 ) + 1;

		update_option(
			self::STALL_OPTION,
			[
				'reason' => $reason,
				'detail' => substr( $detail, 0, 500 ),
				'file'   => '' === $path ? '' : basename( $path ),
				'since'  => (int) ( $stall['since'] ?? time() ),
				'last'   => time(),
				'count'  => $count,
			],
			false
		);

		// Loud on the first failure and then once an hour, rather than once a
		// minute for five weeks.
		if ( 1 === $count || 0 === $count % 60 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[vireo-analytics] %s (failure %d): %s %s', $reason, $count, basename( $path ), $detail ) );
		}
	}

	private static function clear_stall(): void {
		if ( [] !== (array) get_option( self::STALL_OPTION, [] ) ) {
			delete_option( self::STALL_OPTION );
		}
	}

	/**
	 * Tell someone with the keys that the numbers have stopped moving. Held
	 * back until a handful of consecutive failures so a single deadlock or a
	 * restart mid-write doesn't nag.
	 */
	public static function stall_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stall = (array) get_option( self::STALL_OPTION, [] );
		$count = (int) ( $stall['count'] ?? 0 );
		if ( $count < 5 ) {
			return;
		}

		$since = (int) ( $stall['since'] ?? time() );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Vireo Analytics is not recording.', 'vireo-analytics' ),
			esc_html(
				sprintf(
					/* translators: 1: reason, 2: failure count, 3: human-readable duration */
					__( '%1$s. %2$d attempts have failed over the last %3$s. Visits are still being written to disk and will be counted once this clears.', 'vireo-analytics' ),
					$stall['reason'] ?? __( 'Aggregation is failing', 'vireo-analytics' ),
					$count,
					human_time_diff( $since )
				)
			)
		);
	}

	/**
	 * Replay snapshots a previous run took but never managed to write.
	 *
	 * Claimed with an atomic rename so two overlapping crons can't both replay
	 * the same file and double-count it. Only files older than a couple of
	 * minutes are touched, so a snapshot being processed right now by a slow
	 * concurrent run is left alone.
	 */
	private function replay_abandoned_snapshots(): void {
		$dir = Collector::upload_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$stale_before = time() - ( 5 * MINUTE_IN_SECONDS );

		foreach ( (array) glob( $dir . '/*.busy' ) as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- missing file is a race, not an error
			$mtime = (int) @filemtime( $path );
			if ( $mtime > $stale_before ) {
				continue;
			}

			$attempt = self::snapshot_attempt( $path ) + 1;

			// A snapshot the database keeps refusing is not going to start
			// working on the twentieth pass, and re-reading several megabytes
			// every five minutes forever is its own problem. Set it aside and
			// say so; the records stay on disk for a manual replay.
			if ( $attempt > self::MAX_SNAPSHOT_ATTEMPTS ) {
				$parked = preg_replace( '/\.busy$/', '.failed', $path );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,Generic.PHP.NoSilencedErrors.Discouraged -- losing the race is expected
				if ( is_string( $parked ) && @rename( $path, $parked ) ) {
					self::record_stall( 'gave up on a buffer snapshot after repeated write failures', $parked );
				}
				continue;
			}

			// Claim it. Whoever wins the rename owns the replay. The fresh
			// random segment is the claim, so the name stays a fixed length
			// however many times this snapshot has been round the loop.
			$claimed = self::snapshot_name( $path, $attempt );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,Generic.PHP.NoSilencedErrors.Discouraged -- losing the race is expected
			if ( ! @rename( $path, $claimed ) ) {
				continue;
			}

			if ( $this->process_snapshot( $claimed ) ) {
				self::clear_stall();
			}
		}
	}

	/**
	 * Read every record from the open buffer file, folding counts into the
	 * in-memory rollups. Malformed lines are logged and skipped — a single
	 * bad write shouldn't abort the whole batch.
	 *
	 * @param resource $handle
	 */
	private function drain_buffer( $handle ): void {
		for ( $line = fgets( $handle ); $line !== false; $line = fgets( $handle ) ) {
			$line = trim( $line );
			if ( $line === '' || $line === self::BUFFER_MARKER ) {
				continue;
			}

			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- malformed line is recoverable; skip and move on
			$record = @unserialize( $line, [ 'allowed_classes' => false ] );
			if ( ! is_array( $record ) || $record === [] ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[vireo-analytics] skipping unparseable buffer line' );
				}
				continue;
			}

			// Buffers written by earlier versions can hold text that was cut
			// mid-character by the byte-offset truncation the collector used
			// to do. MySQL refuses invalid UTF-8 outright, and a refused row
			// fails the batch, so scrub it here rather than trust the disk.
			// The check is a cheap match; only the rare bad value pays for the
			// conversion.
			foreach ( $record as $i => $value ) {
				if ( is_string( $value ) && '' !== $value && 1 !== preg_match( '//u', $value ) ) {
					$record[ $i ] = function_exists( 'mb_convert_encoding' )
						? mb_convert_encoding( $value, 'UTF-8', 'UTF-8' )
						: (string) preg_replace( '/[^\x00-\x7F]/', '', $value );
				}
			}

			// Record shape: [ type, ...payload ]. 'p' = pageview, 'e' = event.
			// The leading type byte leaves room for future kinds without
			// breaking existing buffers.
			$type    = array_shift( $record );
			if ( $type === 'p' ) {
				$this->fold_pageview( $record );
			} elseif ( $type === 'e' ) {
				$this->fold_event( $record );
			} elseif ( $type === 'g' ) {
				$this->fold_goal( $record );
			} elseif ( $type === 'b' ) {
				$this->fold_bot( $record );
			} elseif ( $type === 'v' ) {
				$this->fold_vitals( $record );
			} elseif ( $type === 't' ) {
				$this->fold_time( $record );
			}
		}
	}

	/**
	 * Bucket one Core Web Vitals sample.
	 *
	 * Widths are chosen around the thresholds Google grades on, so the
	 * good/poor boundary always falls on a bucket edge rather than inside one:
	 * LCP at 2.5s and 4s, INP at 200ms and 500ms, CLS at 0.1 and 0.25.
	 *
	 * @param array<int, mixed> $payload [ts, path, lcp, cls, inp]
	 */
	private function fold_vitals( array $payload ): void {
		$ts = (int) ( $payload[0] ?? 0 );
		if ( $ts <= 0 ) {
			return;
		}

		$date = vireo_bucket_date( $ts );
		$lcp  = (int) ( $payload[2] ?? 0 );
		$cls  = (int) ( $payload[3] ?? 0 );
		$inp  = (int) ( $payload[4] ?? 0 );

		foreach ( [ 'lcp' => $lcp, 'cls' => $cls, 'inp' => $inp ] as $metric => $value ) {
			if ( $value <= 0 ) {
				continue;
			}
			$bucket = self::vitals_bucket( $metric, $value );
			if ( ! isset( $this->vitals_rollup[ $date ][ $metric ][ $bucket ] ) ) {
				$this->vitals_rollup[ $date ][ $metric ][ $bucket ] = 0;
			}
			$this->vitals_rollup[ $date ][ $metric ][ $bucket ]++;
		}

		// Add-ons can break these down per page; free keeps the site-wide
		// number. Same arrangement as every other rollup, including the main
		// site only: see fold_pageview().
		if ( ! Sites::is_connected() && has_action( 'vireo_fold_vitals' ) ) {
			do_action(
				'vireo_fold_vitals',
				[
					'date' => $date,
					'path' => (string) ( $payload[1] ?? '' ),
					'lcp'  => $lcp,
					'cls'  => $cls,
					'inp'  => $inp,
					'site' => (string) ( $payload[5] ?? '' ),
				]
			);
		}
	}

	/** Bucket index for a raw metric value. Widths are in VITALS_BUCKETS. */
	public static function vitals_bucket( string $metric, int $value ): int {
		$widths = [
			'lcp' => 100,
			'inp' => 25,
			'cls' => 10,
		];
		$caps   = [
			'lcp' => 100,
			'inp' => 80,
			'cls' => 100,
		];

		$width = $widths[ $metric ] ?? 100;
		$cap   = $caps[ $metric ] ?? 100;

		return (int) max( 0, min( $cap, intdiv( $value, $width ) ) );
	}

	/** The raw value at the top of a bucket, used when reading percentiles back. */
	public static function vitals_bucket_ceiling( string $metric, int $bucket ): int {
		$widths = [
			'lcp' => 100,
			'inp' => 25,
			'cls' => 10,
		];

		return ( $bucket + 1 ) * ( $widths[ $metric ] ?? 100 );
	}

	/**
	 * Is this table actually there?
	 *
	 * The readers all probe before querying; the writers did not, and that
	 * asymmetry is dangerous now that every rollup shares one transaction. A
	 * table that has not been created yet, on a site whose schema migration
	 * has not run, makes its writer set an error, and the error rolls back the
	 * pageviews written beside it. A missing side table should cost its own
	 * rows and nothing else.
	 *
	 * Cached per request: one SHOW TABLES for the life of the process.
	 */
	public static function table_ready( string $table ): bool {
		global $wpdb;

		// Only a positive is remembered. Caching "absent" would mean a process
		// that probed before the migration ran kept skipping the table for the
		// rest of its life, which is exactly what happens when a migration and
		// a flush share a request. A table is almost never missing, so the
		// re-probe costs one query per flush in the rare case and none in the
		// normal one.
		static $present = [];

		if ( isset( $present[ $table ] ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		if ( $exists ) {
			$present[ $table ] = true;
		}

		return $exists;
	}

	private function write_vitals_rollup(): void {
		global $wpdb;
		if ( $this->vitals_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'vitals_stats' );

		if ( ! self::table_ready( $table ) ) {
			// Nothing to do and nothing to complain about on this tick. The
			// schema migration creates it; until then these samples are
			// dropped rather than allowed to fail the whole flush.
			$this->vitals_rollup = [];
			return;
		}

		foreach ( $this->vitals_rollup as $date => $by_metric ) {
			foreach ( $by_metric as $metric => $buckets ) {
				foreach ( $buckets as $bucket => $samples ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator write
					$wpdb->query(
						$wpdb->prepare(
							'INSERT INTO %i (date, metric, bucket, samples) VALUES (%s, %s, %d, %d)
							ON DUPLICATE KEY UPDATE samples = samples + VALUES(samples)',
							$table,
							$date,
							$metric,
							$bucket,
							$samples
						)
					);
				}
			}
		}
	}

	/**
	 * Apply one time-on-page report.
	 *
	 * The browser reports active seconds when the page is hidden or left, as
	 * the seconds added since its previous report, and marks the first report
	 * for a view. The average divides by those first reports, so a view that
	 * never reported is left out rather than counted as zero.
	 *
	 * A report only counts against a path this visitor has a counted pageview
	 * on today: one timed view per counted view, and half an hour for each
	 * (Collector::MAX_VIEW_SECONDS). The beacon is anonymous, so this is what stops a forged or
	 * replayed one from adding views nobody made or hours nobody spent. The
	 * allowance lives in va_visitor_load beside the pageview cap and is as
	 * exact as that is.
	 *
	 * @param array<int, mixed> $payload [ts, path, seconds, first, visitor_hash, site]
	 */
	private function fold_time( array $payload ): void {
		$ts      = (int) ( $payload[0] ?? 0 );
		$seconds = max( 0, min( Collector::MAX_VIEW_SECONDS, (int) ( $payload[2] ?? 0 ) ) );
		$first   = ! empty( $payload[3] );
		$hash    = (string) ( $payload[4] ?? '' );

		if ( $ts <= 0 || '' === $hash || ! self::table_ready( Sites::table( 'visitor_load' ) ) ) {
			return;
		}

		$date      = vireo_bucket_date( $ts );
		$path      = self::canonicalize_path( (string) ( $payload[1] ?? '' ) );
		$views     = $this->load_count( $date, md5( 'load|' . $path . '|' . $hash ) );
		$timed_key = md5( 'time|' . $path . '|' . $hash );
		$spent_key = md5( 'secs|' . $path . '|' . $hash );
		$timed     = $this->load_count( $date, $timed_key );

		if ( $first ) {
			if ( $timed >= $views ) {
				return;
			}
			$timed++;
			$this->add_load( $date, $timed_key, 1 );
		} elseif ( $timed < 1 ) {
			return;
		}

		$seconds = max( 0, min( $seconds, $timed * Collector::MAX_VIEW_SECONDS - $this->load_count( $date, $spent_key ) ) );
		if ( ! $first && $seconds < 1 ) {
			return;
		}
		if ( $seconds > 0 ) {
			$this->add_load( $date, $spent_key, $seconds );
		}

		$bucket = $this->time_rollup[ $date ][ $path ] ??= (object) [
			'samples' => 0,
			'seconds' => 0,
		];
		$bucket->samples += $first ? 1 : 0;
		$bucket->seconds += $seconds;
	}

	/**
	 * Apply a single goal-completion record to the goal rollup.
	 *
	 * @param array<int, mixed> $payload [ts, visitor_hash, goal_id, value]
	 */
	private function fold_goal( array $payload ): void {
		if ( count( $payload ) < 3 ) {
			return;
		}

		$ts           = (int) ( $payload[0] ?? 0 );
		$visitor_hash = (string) ( $payload[1] ?? '' );
		$goal_id      = (int) ( $payload[2] ?? 0 );
		$value        = isset( $payload[3] ) ? (float) $payload[3] : 0.0;

		// Goals belong to the main site and the beacon never matches them for a
		// connected one; a stray record is dropped rather than written to Pro's
		// table under the wrong site.
		if ( $goal_id <= 0 || Sites::is_connected() ) {
			return;
		}

		$date = vireo_bucket_date( $ts );

		$bucket = $this->goal_rollup[ $date ][ $goal_id ] ??= (object) [
			'completions'        => 0,
			'unique_completions' => 0,
			'total_value'        => 0.0,
		];
		$bucket->completions++;
		$bucket->total_value += $value;

		if ( '' !== $visitor_hash ) {
			if ( $this->first_sighting( 'goal', $date, (string) $goal_id, $visitor_hash ) ) {
				$bucket->unique_completions++;
			}
		}

		/**
		 * Fires for each goal completion as it is folded into the daily rollup.
		 *
		 * This is the only point where a completion still carries which visitor
		 * it belonged to and when. The rollup that follows keeps counts per day
		 * and throws the rest away, which is right for the dashboard but leaves
		 * nothing able to answer "did the same person do A and then B" — so
		 * anything wanting sequence, funnels included, has to listen here.
		 *
		 * The hash is the rotating salted visitor id, not an identity: it is
		 * only comparable to other hashes from the same salt window.
		 *
		 * @since 0.4.2
		 *
		 * @param int    $goal_id      Goal that completed.
		 * @param string $visitor_hash Salted visitor hash, may be ''.
		 * @param int    $ts           Unix timestamp of the completion.
		 * @param float  $value        Value attached to the goal.
		 * @param string $date         Site-local bucket date, Y-m-d.
		 */
		do_action( 'vireo_goal_completed', $goal_id, $visitor_hash, $ts, $value, $date );
	}

	/**
	 * Apply a single event record to the event rollup.
	 *
	 * @param array<int, mixed> $payload [ts, visitor_hash, name, value]
	 */
	private function fold_event( array $payload ): void {
		if ( count( $payload ) < 3 ) {
			return;
		}

		$ts           = (int) ( $payload[0] ?? 0 );
		$visitor_hash = (string) ( $payload[1] ?? '' );
		$name         = (string) ( $payload[2] ?? '' );
		$value        = isset( $payload[3] ) ? (string) $payload[3] : '';

		if ( '' === $name ) {
			return;
		}

		$date = vireo_bucket_date( $ts );
		$key  = $name . '|' . $value;

		$bucket = $this->event_rollup[ $date ][ $key ] ??= (object) [
			'count'        => 0,
			'unique_count' => 0,
			'name'         => $name,
			'value'        => $value,
		];
		$bucket->count++;

		if ( '' !== $visitor_hash ) {
			if ( $this->first_sighting( 'event', $date, $key, $visitor_hash ) ) {
				$bucket->unique_count++;
			}
		}
	}

	/**
	 * Apply a single pageview record to every relevant rollup.
	 *
	 * @param array<int, mixed> $payload [ts, path, post_id, new_visitor, unique_pageview, referrer, ua?, ip?, visitor_hash?]
	 */
	private function fold_pageview( array $payload ): void {
		if ( count( $payload ) < 6 ) {
			return;
		}

		$ts              = (int) ( $payload[0] ?? 0 );
		$raw_path        = (string) ( $payload[1] ?? '' );
		$post_id         = (int) ( $payload[2] ?? 0 );
		$new_visitor     = (bool) ( $payload[3] ?? false );
		$unique_pageview = (bool) ( $payload[4] ?? false );
		$raw_referrer    = (string) ( $payload[5] ?? '' );
		$ua              = isset( $payload[6] ) ? (string) $payload[6] : '';
		$ip              = isset( $payload[7] ) ? (string) $payload[7] : '';
		$visitor_hash    = isset( $payload[8] ) ? (string) $payload[8] : '';
		// Country the host resolved at request time. Absent on records written
		// before this field existed, and on hosts that don't provide one.
		$country         = isset( $payload[9] ) ? (string) $payload[9] : '';
		// Origin of the headless front end that sent the hit, '' for this
		// site's own pages. Absent on records written before it existed.
		$site            = isset( $payload[10] ) ? (string) $payload[10] : '';

		$date          = vireo_bucket_date( $ts );
		$path          = self::canonicalize_path( $raw_path );
		$referrer_host = self::canonicalize_referrer( $raw_referrer );
		// A referrer that is this site: internal navigation, which the tracker
		// drops before sending, so one that still arrives on a landing
		// pageview is a scraper following links. See Automation.
		$own_referrer = '' === $referrer_host && '' !== $raw_referrer && vireo_referrer_host( $raw_referrer, [] ) !== '' && '' === vireo_referrer_host( $raw_referrer, vireo_own_hosts() );

		if ( Sites::is_connected() ) {
			// A connected site is not this WordPress site: its pages are paths,
			// never posts, whatever the beacon claimed.
			$post_id = 0;
		} elseif ( '' !== $site ) {
			// A front-end page knows its own path and maybe its post ID, and
			// the path is what WordPress can vouch for. Resolved here, where the
			// rewrite rules are loaded, rather than at the beacon.
			$post_id = Headless::resolve_post_id( $path, $post_id, $site );
		} elseif ( $post_id > 0 && ! $this->post_is_public( $post_id ) ) {
			// The post ID comes from the visitor's browser, so a client can send
			// any number. One that matches no post would be listed as its own
			// page, and one that matches a draft, a private or scheduled post or
			// a reusable block would put that post's title on the dashboard, in
			// the weekly email and in the MainWP payload. Keep the path and drop
			// the ID instead.
			$post_id = 0;
		}

		// Countries the owner has marked as junk. Filtered here rather than at
		// the beacon because the country is only certain once it is known: the
		// host supplies one on a CDN, and otherwise it takes an IP lookup that
		// belongs on this side, not on the request the reader is waiting for.
		// The lookup only happens when there is a list to check against, so a
		// site that excludes nothing pays nothing.
		$excluded_countries = self::excluded_countries();
		if ( ! empty( $excluded_countries ) ) {
			$resolved = '' !== $country ? $country : Geo_Lookup::lookup( $ip );
			if ( '' !== $resolved && in_array( strtoupper( $resolved ), $excluded_countries, true ) ) {
				return;
			}
			// Keep what the lookup cost us, so apply_geo_totals does not repeat it.
			$country = '' !== $country ? $country : $resolved;
		}

		// One visitor viewing the same archive page 89 times in a day is a client
		// stuck in a loop or a crawler that runs scripts, not a reader. Past the
		// cap the hit is reported as filtered traffic instead of counted.
		if ( '' !== $visitor_hash && $this->over_daily_cap( $date, $path, $visitor_hash ) ) {
			$bucket = $this->bot_rollup[ $date ]['repeat'] ??= (object) [ 'hits' => 0 ];
			$bucket->hits++;
			return;
		}

		// A referrer on the spam list, or one the owner blocked, is not a
		// reader. Counted with the bots so the panel can say so, never in the
		// totals, the same as a crawler that announced itself.
		if ( '' !== $referrer_host && class_exists( Referrer_Spam::class ) && Referrer_Spam::is_spam( $referrer_host ) ) {
			$bucket = $this->bot_rollup[ $date ][ Referrer_Spam::CATEGORY ] ??= (object) [ 'hits' => 0 ];
			$bucket->hits++;
			return;
		}

		// A browser a year out of date, landing with no outside referrer, is
		// a scraper driving a real engine. Counted with the bots, never as a
		// reader; see Automation for why one of those signals is not enough.
		if ( '' !== $ua && '' !== $visitor_hash && class_exists( Automation::class )
			&& Automation::looks_automated( $ua, ! $this->seen_today( $date, $visitor_hash ), '' === $raw_referrer, $own_referrer ) ) {
			$bucket = $this->bot_rollup[ $date ]['automation'] ??= (object) [ 'hits' => 0 ];
			$bucket->hits++;
			return;
		}

		$this->apply_site_totals( $date, $visitor_hash, $new_visitor );
		$this->apply_post_totals( $date, $path, $post_id, $visitor_hash, $unique_pageview );
		$this->apply_hour_totals( $date, vireo_bucket_hour( $ts ), $visitor_hash, $new_visitor );

		if ( $referrer_host !== '' ) {
			$this->apply_referrer_totals( $date, $referrer_host, $visitor_hash, $new_visitor );
			if ( $post_id > 0 ) {
				$this->apply_post_referrer_totals( $date, $post_id, $referrer_host, $visitor_hash, $new_visitor );
			}
		}

		if ( $ua !== '' ) {
			$this->apply_device_totals( $date, $ua, $visitor_hash, $ip );
		}

		if ( '' !== $country || ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) ) {
			$this->apply_geo_totals( $date, $ip, $visitor_hash, $country );
		}

		// Extension point for add-ons that keep their own rollups. Guarded by
		// has_action() so a site without any listener does not pay to build the
		// array once per buffered hit. Listeners accumulate in memory here and
		// write on `vireo_flush_rollups`, which runs inside our transaction.
		//
		// Not for a connected site. Add-on rollups live in tables that have one
		// copy, the main site's, and a listener that has never heard of
		// connected sites would fold another site's visits into them.
		if ( ! Sites::is_connected() && has_action( 'vireo_fold_pageview' ) ) {
			do_action(
				'vireo_fold_pageview',
				[
					'date'         => $date,
					'ts'           => $ts,
					'path'         => $path,
					'post_id'      => $post_id,
					'ip'           => $ip,
					'country'      => $country,
					'referrer'     => $referrer_host,
					'ua'           => $ua,
					'visitor_hash' => $visitor_hash,
					'new_visitor'  => $new_visitor,
					'site'         => $site,
				]
			);
		}
	}

	/**
	 * Count a visitor once per (scope, date, key), for add-ons keeping their
	 * own rollups. Shares va_visitor_seen with the built-in rollups, so an
	 * add-on's unique counts behave the same way ours do across flushes and
	 * overlapping cron runs. Use a scope nobody else uses.
	 */
	public function count_visitor_once( string $scope, string $date, string $scope_key, string $visitor_hash ): bool {
		return $this->first_sighting( $scope, $date, $scope_key, $visitor_hash );
	}

	// -----------------------------------------------------------------------
	//  Per-rollup fold helpers
	//
	//  Each helper owns one rollup and understands how to:
	//    * increment the pageview counter unconditionally
	//    * increment the visitor counter conditionally, using either the
	//      server-side visitor_hash (preferred) or a client-provided flag as
	//      a fallback for legacy buffer lines without a hash.
	// -----------------------------------------------------------------------

	/**
	 * Whether a post is one a visitor could have seen: published (a password
	 * is fine) and of a type with a public URL. Anything else is not a page
	 * someone viewed, whatever the beacon claims.
	 */
	private function post_is_public( int $post_id ): bool {
		if ( ! isset( $this->post_is_public[ $post_id ] ) ) {
			$this->post_is_public[ $post_id ] = is_post_publicly_viewable( $post_id );
		}

		return $this->post_is_public[ $post_id ];
	}

	/**
	 * Count this pageview against the visitor's daily allowance, and say whether
	 * it is past the cap: too many views of one path, or too many in total.
	 */
	private function over_daily_cap( string $date, string $path, string $visitor_hash ): bool {
		/**
		 * Most pageviews one visitor can add to the counts in a day.
		 *
		 * @param int $cap Default 50. Zero or less disables the cap.
		 */
		$day_cap = (int) apply_filters( 'vireo_visitor_daily_cap', 50 );
		/**
		 * Most views of a single path one visitor can add in a day.
		 *
		 * @param int $cap Default 5. Zero or less disables the cap.
		 */
		$path_cap = (int) apply_filters( 'vireo_visitor_path_cap', 5 );

		// Until the schema upgrade has created the table (it runs on the next
		// admin or CLI request), count everything rather than fail the flush.
		if ( ! self::table_ready( Sites::table( 'visitor_load' ) ) ) {
			return false;
		}

		$day_key  = md5( 'load|' . $visitor_hash );
		$path_key = md5( 'load|' . $path . '|' . $visitor_hash );

		$day  = $this->load_count( $date, $day_key );
		$seen = $this->load_count( $date, $path_key );

		if ( ( $day_cap > 0 && $day >= $day_cap ) || ( $path_cap > 0 && $seen >= $path_cap ) ) {
			return true;
		}

		foreach ( [ $day_key, $path_key ] as $key ) {
			$this->add_load( $date, $key, 1 );
		}

		return false;
	}

	private function load_count( string $date, string $key ): int {
		global $wpdb;

		$cache_key = $date . '|' . $key;
		if ( ! isset( $this->load[ $cache_key ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- counter state, seeded once per key per run
			$this->load[ $cache_key ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT hits FROM %i WHERE date = %s AND dedupe_key = %s',
					Sites::table( 'visitor_load' ),
					$date,
					$key
				)
			);
		}

		return $this->load[ $cache_key ];
	}

	/** Raise a counter already read through load_count(), and queue its write. */
	private function add_load( string $date, string $key, int $increment ): void {
		$this->load[ $date . '|' . $key ]       = ( $this->load[ $date . '|' . $key ] ?? 0 ) + $increment;
		$this->load_delta[ $date . '|' . $key ] = ( $this->load_delta[ $date . '|' . $key ] ?? 0 ) + $increment;
	}

	private function write_visitor_load(): void {
		global $wpdb;

		if ( [] === $this->load_delta ) {
			return;
		}

		foreach ( $this->load_delta as $cache_key => $increment ) {
			[ $date, $key ] = explode( '|', $cache_key, 2 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (date, dedupe_key, hits) VALUES (%s, %s, %d)
					 ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)',
					Sites::table( 'visitor_load' ),
					$date,
					$key,
					(int) $increment
				)
			);
		}
	}

	/**
	 * Has this visitor already been counted for this date and dimension?
	 *
	 * The buffer is flushed in batches, so a per-run set can't answer this: a
	 * visitor whose pageviews land in different flushes would be counted as new
	 * each time. We record every counted (date, scope, key, visitor) in
	 * va_visitor_seen and let the primary key arbitrate.
	 *
	 * INSERT IGNORE rather than SELECT-then-INSERT because cron runs can
	 * overlap and nothing here holds a lock: rows_affected === 1 means this
	 * process won the insert and owns the increment. A concurrent run that
	 * loses gets 0 and correctly declines to count the visitor twice.
	 */
	/**
	 * Whether this visitor has already been counted today, without counting
	 * them: the key first_sighting() writes, read instead of inserted.
	 */
	private function seen_today( string $date, string $visitor_hash ): bool {
		global $wpdb;

		if ( isset( $this->seen_cache[ 'site|' . $date . '||' . $visitor_hash ] ) ) {
			return true;
		}
		if ( ! self::table_ready( Sites::table( 'visitor_seen' ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- counter state, no cache layer applies
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE date = %s AND dedupe_key = %s',
				Sites::table( 'visitor_seen' ),
				$date,
				md5( 'site||' . $visitor_hash )
			)
		);
	}

	private function first_sighting( string $scope, string $date, string $scope_key, string $visitor_hash ): bool {
		global $wpdb;

		$cache_key = $scope . '|' . $date . '|' . $scope_key . '|' . $visitor_hash;
		if ( isset( $this->seen_cache[ $cache_key ] ) ) {
			return false;
		}
		$this->seen_cache[ $cache_key ] = true;

		$dedupe_key = md5( $scope . '|' . $scope_key . '|' . $visitor_hash );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- counter state, no cache layer applies
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (date, dedupe_key) VALUES (%s, %s)',
				Sites::table( 'visitor_seen' ),
				$date,
				$dedupe_key
			)
		);

		return 1 === (int) $wpdb->rows_affected;
	}

	private function apply_site_totals( string $date, string $visitor_hash, bool $new_visitor ): void {
		$bucket = $this->site_rollup[ $date ] ??= (object) [
			'pageviews' => 0,
			'visitors'  => 0,
		];
		$bucket->pageviews++;

		if ( $visitor_hash !== '' ) {
			$first = $this->first_sighting( 'site', $date, '', $visitor_hash );
			if ( $first ) {
				$bucket->visitors++;
			}
			$this->apply_bounce_totals( $date, $visitor_hash, $first );
			return;
		}

		// Pre-hash buffer entries — defer to whatever the client reported.
		$bucket->visitors += (int) $new_visitor;
	}

	/**
	 * Count the visitor towards the bounce rate: everyone once, and once more
	 * on their second pageview of the day. A bounce is a visitor who never gets
	 * the second count, so the rate is (visitors - multi_page) / visitors.
	 *
	 * Both counts are first sightings in va_visitor_seen, the same arbiter the
	 * visitor total uses, so a visitor whose pages land in different flushes or
	 * in overlapping runs is still counted once each way. Two counters that only
	 * go up, because a running "one page only" figure would have to come down
	 * again, and the columns are unsigned.
	 */
	private function apply_bounce_totals( string $date, string $visitor_hash, bool $first ): void {
		if ( ! self::bounce_counted( $date ) ) {
			return;
		}

		$bucket = $this->bounce_rollup[ $date ] ??= (object) [
			'visitors'   => 0,
			'multi_page' => 0,
		];

		if ( $first ) {
			$bucket->visitors++;
		} elseif ( $this->first_sighting( 'multi', $date, '', $visitor_hash ) ) {
			$bucket->multi_page++;
		}
	}

	/**
	 * Whether bounce counting covers a date.
	 *
	 * Not the day of the update that added it: visitors who arrived earlier
	 * that day were already marked as seen, so their next page would count as
	 * a second page for someone missing from the total. The installer sets the
	 * option to the day after an upgrade, and to the install day on a new site,
	 * where nobody has been seen yet.
	 */
	public static function bounce_counted( string $date ): bool {
		$since = (string) get_option( Installer::BOUNCE_SINCE_OPTION, '' );
		return '' !== $since && $date >= $since;
	}

	private function apply_post_totals( string $date, string $path, int $post_id, string $visitor_hash, bool $unique_pageview ): void {
		$bucket = $this->post_rollup[ $date ][ $path ] ??= (object) [
			'pageviews' => 0,
			'visitors'  => 0,
			'post_id'   => $post_id,
		];
		$bucket->pageviews++;

		if ( $visitor_hash !== '' ) {
			if ( $this->first_sighting( 'post', $date, $path, $visitor_hash ) ) {
				$bucket->visitors++;
			}
			return;
		}

		$bucket->visitors += (int) $unique_pageview;
	}

	private function apply_referrer_totals( string $date, string $host, string $visitor_hash, bool $new_visitor ): void {
		$bucket = $this->referrer_rollup[ $date ][ $host ] ??= (object) [
			'pageviews' => 0,
			'visitors'  => 0,
		];
		$bucket->pageviews++;

		if ( $visitor_hash !== '' ) {
			if ( $this->first_sighting( 'ref', $date, $host, $visitor_hash ) ) {
				$bucket->visitors++;
			}
			return;
		}

		$bucket->visitors += (int) $new_visitor;
	}

	private function apply_hour_totals( string $date, int $hour, string $visitor_hash, bool $new_visitor ): void {
		$hour = max( 0, min( 23, $hour ) );

		$bucket = $this->hour_rollup[ $date ][ $hour ] ??= (object) [
			'pageviews' => 0,
			'visitors'  => 0,
			'hour'      => $hour,
		];
		$bucket->pageviews++;

		if ( $visitor_hash !== '' ) {
			if ( $this->first_sighting( 'hour', $date, (string) $hour, $visitor_hash ) ) {
				$bucket->visitors++;
			}
			return;
		}

		$bucket->visitors += (int) $new_visitor;
	}

	private function apply_post_referrer_totals( string $date, int $post_id, string $host, string $visitor_hash, bool $new_visitor ): void {
		$key = $post_id . '|' . $host;

		$bucket = $this->post_referrer_rollup[ $date ][ $key ] ??= (object) [
			'pageviews' => 0,
			'visitors'  => 0,
			'post_id'   => $post_id,
			'host'      => $host,
		];
		$bucket->pageviews++;

		if ( $visitor_hash !== '' ) {
			if ( $this->first_sighting( 'postref', $date, $key, $visitor_hash ) ) {
				$bucket->visitors++;
			}
			return;
		}

		$bucket->visitors += (int) $new_visitor;
	}

	private function apply_device_totals( string $date, string $ua, string $visitor_hash, string $ip ): void {
		$parsed = UA_Parser::parse( $ua );
		$key    = $parsed['browser'] . '|' . $parsed['os'] . '|' . $parsed['device_type'];

		$bucket = $this->device_rollup[ $date ][ $key ] ??= (object) [
			'pageviews'   => 0,
			'visitors'    => 0,
			'browser'     => $parsed['browser'],
			'os'          => $parsed['os'],
			'device_type' => $parsed['device_type'],
		];
		$bucket->pageviews++;

		// For device dedupe we prefer the visitor hash, but fall back to an
		// IP-derived token — the pre-hash buffer lines didn't carry a hash
		// at all, and an empty ip would just mean "can't dedupe".
		$token = $this->dedupe_token( $visitor_hash, $ip );
		if ( $token === '' ) {
			return;
		}

		if ( $this->first_sighting( 'device', $date, $key, $token ) ) {
			$bucket->visitors++;
		}
	}

	/**
	 * Upper-case country codes the owner treats as junk traffic. Read once per
	 * run; the aggregator processes a whole buffer in one pass.
	 *
	 * @return array<int,string>
	 */
	private static function excluded_countries(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$rules = function_exists( 'vireo_get_exclusions' ) ? vireo_get_exclusions() : [];
		$cache = isset( $rules['countries'] ) ? (array) $rules['countries'] : [];
		return $cache;
	}

	private function apply_geo_totals( string $date, string $ip, string $visitor_hash, string $country = '' ): void {
		// Trust the host's answer when it gave one; only reach for the MaxMind
		// database when it didn't.
		if ( '' === $country ) {
			$country = Geo_Lookup::lookup( $ip );
		}

		$bucket = $this->geo_rollup[ $date ][ $country ] ??= (object) [
			'pageviews' => 0,
			'visitors'  => 0,
		];
		$bucket->pageviews++;

		// Geo always has an IP by construction, so the fallback token here
		// is always available.
		$token    = $this->dedupe_token( $visitor_hash, $ip );
		if ( $this->first_sighting( 'geo', $date, $country, $token ) ) {
			$bucket->visitors++;
		}
	}

	/**
	 * Pick a dedupe token, preferring the server-side visitor hash. Falls
	 * back to a hash of the IP keyed with the site's secret (see
	 * Salt_Manager::hash_ip). Returns '' if neither is available.
	 */
	private function dedupe_token( string $visitor_hash, string $ip ): string {
		if ( $visitor_hash !== '' ) {
			return $visitor_hash;
		}
		return Salt_Manager::hash_ip( $ip );
	}

	// -----------------------------------------------------------------------
	//  DB write phase
	// -----------------------------------------------------------------------

	/**
	 * Write every rollup. Returns false if the database rejected anything, so
	 * the caller keeps the buffer snapshot instead of destroying traffic we
	 * failed to record.
	 */
	private function flush_to_db(): bool {
		global $wpdb;

		$ok = true;

		// All ten writers move together. They used to run bare, so a snapshot
		// that failed on one rollup had already written the other nine — and
		// since a failed flush keeps the snapshot for replay, every retry
		// counted those nine again. A live site ended up with a day inflated by
		// four extra passes before the snapshot was set aside. Either the whole
		// snapshot lands or none of it does.
		$wpdb->query( 'START TRANSACTION' );

		// Checked per writer, not once at the end, and read from wpdb's error log
		// rather than $wpdb->last_error. Every query resets last_error, and a
		// writer runs several (an upsert and an insert per day, in batches), so
		// an early failure followed by a later success read as a clean write and
		// the snapshot was deleted with rows missing from the database.
		foreach (
			[
				'write_site_rollup',
				'write_post_rollup',
				'write_referrer_rollup',
				'write_geo_rollup',
				'write_device_rollup',
				'write_event_rollup',
				'write_goal_rollup',
				'write_hour_rollup',
				'write_post_referrer_rollup',
				'write_bot_rollup',
				'write_vitals_rollup',
				'write_time_rollup',
				'write_bounce_rollup',
				'write_visitor_load',
			] as $writer
		) {
			$mark = self::error_mark();
			$this->{$writer}();

			$error = self::error_since( $mark );
			if ( '' !== $error ) {
				$ok = false;
				// Keep the first one. It is the reason aggregation stopped, and
				// without it a stalled site gives you nothing to work from.
				if ( '' === $this->last_write_error ) {
					$this->last_write_error = $writer . ': ' . $error;
				}
			}
		}

		// Add-on rollups join the same transaction, and the same error check.
		// If they wrote outside it, a failure in Pro would leave half a flush
		// committed and the snapshot would be replayed over the top of it.
		// Nothing was folded for a connected site, so nothing is flushed.
		if ( ! Sites::is_connected() && has_action( 'vireo_flush_rollups' ) ) {
			$mark = self::error_mark();
			do_action( 'vireo_flush_rollups' );

			$error = self::error_since( $mark );
			if ( '' !== $error ) {
				$ok = false;
				if ( '' === $this->last_write_error ) {
					$this->last_write_error = 'vireo_flush_rollups: ' . $error;
				}
			}
		}

		if ( $ok ) {
			$wpdb->query( 'COMMIT' );
			// A site with nothing live on record until now starts counting here,
			// so the option can't stay empty once hits are landing.
			if ( class_exists( Installer::class ) ) {
				Installer::backfill_tracking_since( true );
			}
		} else {
			$wpdb->query( 'ROLLBACK' );
		}

		return $ok;
	}

	/** Position in wpdb's error log, to compare against after a writer runs. */
	private static function error_mark(): int {
		global $EZSQL_ERROR;
		return is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
	}

	/**
	 * The first database error logged since $mark, or ''. wpdb::print_error()
	 * appends every failed query to $EZSQL_ERROR, suppressed or not. A db.php
	 * drop-in that skips the log still sets last_error, so that is the fallback.
	 */
	private static function error_since( int $mark ): string {
		global $EZSQL_ERROR, $wpdb;
		if ( is_array( $EZSQL_ERROR ) && count( $EZSQL_ERROR ) > $mark ) {
			$entry = array_values( array_slice( $EZSQL_ERROR, $mark, 1 ) )[0];
			$error = (string) ( $entry['error_str'] ?? '' );
			return '' !== $error ? $error : 'database error';
		}
		return (string) $wpdb->last_error;
	}

	/**
	 * Upsert $rows in batches of INSERT_BATCH, all inside the flush transaction,
	 * so a failed batch still rolls back the whole snapshot.
	 *
	 * @param string                             $table   Full table name.
	 * @param string                             $columns Column list for the INSERT.
	 * @param string                             $tuple   Placeholder tuple for one row.
	 * @param array<int, array<int, int|string>> $rows    One bind list per row, matching $tuple.
	 */
	private static function insert_batched( string $table, string $columns, string $tuple, array $rows ): void {
		global $wpdb;
		foreach ( array_chunk( $rows, self::INSERT_BATCH ) as $batch ) {
			// $values_sql is composed from literal placeholder tuples — safe for
			// $wpdb->prepare() even though the sniffer can't prove it.
			$values_sql = implode( ',', array_fill( 0, count( $batch ), $tuple ) );
			$sql        = "INSERT INTO %i ({$columns}) VALUES {$values_sql}
				ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- safe; SQL composed from literal placeholders
			$wpdb->query( $wpdb->prepare( $sql, array_merge( [ $table ], ...$batch ) ) );
		}
	}

	private function reset_state(): void {
		$this->seen_cache = [];
		$this->site_rollup          = [];
		$this->post_rollup          = [];
		$this->referrer_rollup      = [];
		$this->geo_rollup           = [];
		$this->device_rollup        = [];
		$this->event_rollup         = [];
		$this->goal_rollup          = [];
		$this->hour_rollup          = [];
		$this->post_referrer_rollup = [];
		$this->vitals_rollup        = [];
		$this->time_rollup          = [];
		$this->bounce_rollup        = [];
		$this->load                 = [];
		$this->load_delta           = [];
		$this->post_is_public       = [];

		do_action( 'vireo_reset_rollups' );
	}

	private function write_site_rollup(): void {
		global $wpdb;
		if ( $this->site_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'site_stats' );

		foreach ( $this->site_rollup as $date => $bucket ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (date, pageviews, visitors) VALUES (%s, %d, %d)
					ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)',
					$table,
					$date,
					$bucket->pageviews,
					$bucket->visitors
				)
			);
		}
	}

	private function write_post_rollup(): void {
		global $wpdb;
		if ( $this->post_rollup === [] ) {
			return;
		}

		$upserter = new Upserter( 'paths', 'path' );
		$table    = Sites::table( 'post_stats' );

		foreach ( $this->post_rollup as $date => $by_path ) {
			$path_ids = $upserter->upsert( array_keys( $by_path ) );

			$rows = [];
			foreach ( $by_path as $path => $bucket ) {
				if ( ! isset( $path_ids[ $path ] ) ) {
					continue;
				}
				$rows[] = [ $date, (int) $bucket->post_id, (int) $path_ids[ $path ], (int) $bucket->pageviews, (int) $bucket->visitors ];
			}
			self::insert_batched( $table, 'date, post_id, path_id, pageviews, visitors', '(%s,%d,%d,%d,%d)', $rows );
		}
	}

	private function write_time_rollup(): void {
		global $wpdb;
		if ( $this->time_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'page_time' );
		if ( ! self::table_ready( $table ) ) {
			// Until the upgrade creates it, drop these rather than fail the
			// pageviews flushed beside them, as with the vitals table.
			$this->time_rollup = [];
			return;
		}

		$upserter = new Upserter( 'paths', 'path' );

		foreach ( $this->time_rollup as $date => $by_path ) {
			$path_ids = $upserter->upsert( array_keys( $by_path ) );
			foreach ( $by_path as $path => $bucket ) {
				if ( ! isset( $path_ids[ $path ] ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (date, path_id, samples, seconds) VALUES (%s, %d, %d, %d)
						ON DUPLICATE KEY UPDATE samples = samples + VALUES(samples), seconds = seconds + VALUES(seconds)',
						$table,
						$date,
						(int) $path_ids[ $path ],
						(int) $bucket->samples,
						(int) $bucket->seconds
					)
				);
			}
		}
	}

	private function write_bounce_rollup(): void {
		global $wpdb;
		if ( $this->bounce_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'bounce_stats' );
		if ( ! self::table_ready( $table ) ) {
			$this->bounce_rollup = [];
			return;
		}

		foreach ( $this->bounce_rollup as $date => $bucket ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (date, visitors, multi_page) VALUES (%s, %d, %d)
					ON DUPLICATE KEY UPDATE visitors = visitors + VALUES(visitors), multi_page = multi_page + VALUES(multi_page)',
					$table,
					$date,
					(int) $bucket->visitors,
					(int) $bucket->multi_page
				)
			);
		}
	}

	private function write_referrer_rollup(): void {
		global $wpdb;
		if ( $this->referrer_rollup === [] ) {
			return;
		}

		$upserter = new Upserter( 'referrer_urls', 'url' );
		$table    = Sites::table( 'referrer_stats' );

		foreach ( $this->referrer_rollup as $date => $by_host ) {
			$url_ids = $upserter->upsert( array_keys( $by_host ) );

			$rows = [];
			foreach ( $by_host as $host => $bucket ) {
				if ( ! isset( $url_ids[ $host ] ) ) {
					continue;
				}
				$rows[] = [ $date, (int) $url_ids[ $host ], (int) $bucket->pageviews, (int) $bucket->visitors ];
			}
			self::insert_batched( $table, 'date, url_id, pageviews, visitors', '(%s,%d,%d,%d)', $rows );
		}
	}

	private function write_geo_rollup(): void {
		global $wpdb;
		if ( $this->geo_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'geo_stats' );

		foreach ( $this->geo_rollup as $date => $by_country ) {
			foreach ( $by_country as $country => $bucket ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (date, country_code, pageviews, visitors) VALUES (%s, %s, %d, %d)
						ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)',
						$table,
						$date,
						$country,
						$bucket->pageviews,
						$bucket->visitors
					)
				);
			}
		}
	}

	/**
	 * Apply a single filtered-bot record to the bot rollups.
	 *
	 * @param array<int, mixed> $payload [ts, category, ua]
	 */
	private function fold_bot( array $payload ): void {
		if ( count( $payload ) < 2 ) {
			return;
		}

		$ts       = (int) ( $payload[0] ?? 0 );
		$category = (string) ( $payload[1] ?? 'other' );

		if ( '' === $category ) {
			$category = 'other';
		}

		$date = vireo_bucket_date( $ts );

		$bucket = $this->bot_rollup[ $date ][ $category ] ??= (object) [ 'hits' => 0 ];
		$bucket->hits++;
	}

	private function write_bot_rollup(): void {
		global $wpdb;

		if ( $this->bot_rollup !== [] ) {
			$table = Sites::table( 'bot_stats' );
			foreach ( $this->bot_rollup as $date => $by_category ) {
				foreach ( $by_category as $category => $bucket ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
					$wpdb->query(
						$wpdb->prepare(
							'INSERT INTO %i (date, category, hits) VALUES (%s, %s, %d)
							ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)',
							$table,
							$date,
							$category,
							$bucket->hits
						)
					);
				}
			}
		}

	}

	private function write_event_rollup(): void {
		global $wpdb;
		if ( $this->event_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'event_stats' );

		foreach ( $this->event_rollup as $date => $by_key ) {
			foreach ( $by_key as $bucket ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (date, event_name, event_value, count, unique_count) VALUES (%s, %s, %s, %d, %d)
						ON DUPLICATE KEY UPDATE count = count + VALUES(count), unique_count = unique_count + VALUES(unique_count)',
						$table,
						$date,
						$bucket->name,
						$bucket->value,
						$bucket->count,
						$bucket->unique_count
					)
				);
			}
		}
	}

	private function write_goal_rollup(): void {
		global $wpdb;
		if ( $this->goal_rollup === [] ) {
			return;
		}

		// Goal_completions table is owned by the Pro Goals feature; if Pro
		// isn't active the table doesn't exist and the rollup is moot. Guard
		// rather than fatal so a license lapse doesn't break aggregation.
		$table = Sites::table( 'goal_completions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		foreach ( $this->goal_rollup as $date => $by_goal ) {
			foreach ( $by_goal as $goal_id => $bucket ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (date, goal_id, completions, unique_completions, total_value)
						VALUES (%s, %d, %d, %d, %f)
						ON DUPLICATE KEY UPDATE
							completions = completions + VALUES(completions),
							unique_completions = unique_completions + VALUES(unique_completions),
							total_value = total_value + VALUES(total_value)',
						$table,
						$date,
						(int) $goal_id,
						(int) $bucket->completions,
						(int) $bucket->unique_completions,
						(float) $bucket->total_value
					)
				);
			}
		}
	}

	private function write_hour_rollup(): void {
		global $wpdb;
		if ( $this->hour_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'hour_stats' );
		// Older installs that haven't run the migration yet won't have this
		// table. Skip silently rather than error-log-spam — the upgrade path
		// creates it on next admin load.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		foreach ( $this->hour_rollup as $date => $by_hour ) {
			foreach ( $by_hour as $bucket ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (date, hour, pageviews, visitors) VALUES (%s, %d, %d, %d)
						ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)',
						$table,
						$date,
						(int) $bucket->hour,
						(int) $bucket->pageviews,
						(int) $bucket->visitors
					)
				);
			}
		}
	}

	private function write_post_referrer_rollup(): void {
		global $wpdb;
		if ( $this->post_referrer_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'post_referrer_stats' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$upserter = new Upserter( 'referrer_urls', 'url' );

		foreach ( $this->post_referrer_rollup as $date => $by_key ) {
			$hosts = [];
			foreach ( $by_key as $bucket ) {
				$hosts[ $bucket->host ] = true;
			}
			$url_ids = $upserter->upsert( array_keys( $hosts ) );

			$rows = [];
			foreach ( $by_key as $bucket ) {
				if ( ! isset( $url_ids[ $bucket->host ] ) ) {
					continue;
				}
				$rows[] = [ $date, (int) $bucket->post_id, (int) $url_ids[ $bucket->host ], (int) $bucket->pageviews, (int) $bucket->visitors ];
			}
			self::insert_batched( $table, 'date, post_id, url_id, pageviews, visitors', '(%s,%d,%d,%d,%d)', $rows );
		}
	}

	private function write_device_rollup(): void {
		global $wpdb;
		if ( $this->device_rollup === [] ) {
			return;
		}

		$table = Sites::table( 'device_stats' );

		foreach ( $this->device_rollup as $date => $by_key ) {
			foreach ( $by_key as $bucket ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregator writes, cache not applicable
				$wpdb->query(
					$wpdb->prepare(
						'INSERT INTO %i (date, browser, os, device_type, pageviews, visitors) VALUES (%s, %s, %s, %s, %d, %d)
						ON DUPLICATE KEY UPDATE pageviews = pageviews + VALUES(pageviews), visitors = visitors + VALUES(visitors)',
						$table,
						$date,
						$bucket->browser,
						$bucket->os,
						$bucket->device_type,
						$bucket->pageviews,
						$bucket->visitors
					)
				);
			}
		}
	}

	// -----------------------------------------------------------------------
	//  Normalization helpers
	// -----------------------------------------------------------------------

	/**
	 * Trim a raw request path down to something suitable for the `paths`
	 * table:
	 *   - strip the fragment (`#…`)
	 *   - strip query params except a short allowlist (p, cat, page_id, …)
	 *   - strip the WP subdirectory prefix if any
	 *   - fold `/amp/` suffix to the canonical URL
	 *
	 * The query-var allowlist is filterable via `vireo_allowed_query_vars` for
	 * sites that use custom rewrite params they care to preserve.
	 */
	private static function canonicalize_path( string $raw ): string {
		$path = $raw;

		$fragment_pos = strpos( $path, '#' );
		if ( $fragment_pos !== false ) {
			$path = substr( $path, 0, $fragment_pos );
		}

		$qpos = strpos( $path, '?' );
		if ( $qpos !== false ) {
			$query_string = substr( $path, $qpos + 1 );
			$path         = substr( $path, 0, $qpos + 1 );

			$parsed = [];
			parse_str( $query_string, $parsed );

			$allowed_keys = (array) apply_filters(
				'vireo_allowed_query_vars',
				[ 'page_id', 'p', 'tag', 'cat', 'product', 'attachment_id' ]
			);
			$kept         = array_intersect_key( $parsed, array_flip( $allowed_keys ) );

			$path  = rtrim( $path . http_build_query( $kept ), '?' );
		}

		$home_prefix = wp_parse_url( home_url( '' ), PHP_URL_PATH );
		if ( $home_prefix && $home_prefix !== '/' && str_starts_with( $path, $home_prefix ) ) {
			$path = substr( $path, strlen( $home_prefix ) );
		}

		if ( str_ends_with( $path, '/amp/' ) ) {
			$path = substr( $path, 0, -4 );
		}

		return $path;
	}

	/**
	 * Turn a referrer URL into a bare host (lowercase, sans www). Returns ''
	 * for empty input or anything that won't parse.
	 */
	private static function canonicalize_referrer( string $raw ): string {
		// The site's own hosts and internal addresses come back as '': a click
		// from one of our pages to another is not a referral, and until now
		// only the tracker on a headless front end dropped its own host. On a
		// WordPress site every internal click was stored under the site's own
		// host, which then topped its own referrers list.
		if ( function_exists( 'vireo_referrer_host' ) ) {
			return vireo_referrer_host( $raw, function_exists( 'vireo_own_hosts' ) ? vireo_own_hosts() : [] );
		}

		// Without va-filters.php loaded (the zero-dependency runners), the
		// plain host, as before.
		if ( '' === $raw ) {
			return '';
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
