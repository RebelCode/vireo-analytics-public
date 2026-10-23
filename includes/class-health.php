<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question on demand: is this site actually recording right now?
 *
 * Analytics fails quietly by nature. A plugin that stops counting looks exactly
 * like a plugin on a quiet site, and the only symptom is a chart that flattens
 * weeks later. Three sites running this plugin stopped recording for between
 * eleven and fifty days without anything saying so, for two unrelated reasons,
 * and in every case the evidence was sitting in an option or a filename the
 * whole time.
 *
 * So the checks here are deliberately the boring ones: is the cron event there,
 * did the last flush happen recently, is anything stuck on disk, can we write
 * at all. Each returns a severity and a sentence a human can act on.
 */
final class Health {

	/** A flush is scheduled every minute, so this much silence is a fault. */
	private const STALE_AGGREGATION = 900;

	/** Buffered data older than this has missed too many flushes. */
	private const STALE_BUFFER = 1800;

	public static function boot(): void {
		add_filter( 'site_status_tests', [ __CLASS__, 'register_test' ] );
		add_filter( 'debug_information', [ __CLASS__, 'register_debug_information' ] );
	}

	/**
	 * Every check, worst first.
	 *
	 * @return array<int,array{id:string,severity:string,label:string,detail:string}>
	 */
	public static function report(): array {
		$checks = array_merge(
			self::check_cron(),
			self::check_aggregation(),
			self::check_stall(),
			self::check_buffer(),
			self::check_storage(),
			self::check_data()
		);

		$rank = [ 'critical' => 0, 'warning' => 1, 'good' => 2 ];
		usort(
			$checks,
			static fn( array $a, array $b ): int => ( $rank[ $a['severity'] ] ?? 3 ) <=> ( $rank[ $b['severity'] ] ?? 3 )
		);

		return $checks;
	}

	/** The worst severity across all checks. */
	public static function worst_severity(): string {
		foreach ( self::report() as $check ) {
			return $check['severity'];
		}
		return 'good';
	}

	/**
	 * The event that drains the buffer into the database. It used to be
	 * scheduled once at activation and never checked again, which is how two
	 * sites went silent; the installer now restores it, so finding it missing
	 * here means something is removing it faster than we can put it back.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function check_cron(): array {
		$next = wp_next_scheduled( 'vireo_flush_buffer' );

		if ( ! $next ) {
			return [ self::check(
				'cron',
				'critical',
				__( 'The job that records visits is not scheduled', 'vireo-analytics' ),
				__( 'Visits are being written to disk but nothing is moving them into your reports. Deactivating and reactivating the plugin will restore it.', 'vireo-analytics' )
			) ];
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return [ self::check(
				'cron',
				'good',
				__( 'The recording job is scheduled', 'vireo-analytics' ),
				__( 'WordPress cron is disabled on this site, which is normal on managed hosting: the host runs it externally instead. Recording depends on that continuing to run.', 'vireo-analytics' )
			) ];
		}

		return [ self::check( 'cron', 'good', __( 'The recording job is scheduled', 'vireo-analytics' ), '' ) ];
	}

	/** @return array<int,array<string,string>> */
	private static function check_aggregation(): array {
		$last = (int) get_option( 'vireo_last_aggregation_at', 0 );

		if ( $last <= 0 ) {
			return [ self::check(
				'aggregation',
				'warning',
				__( 'Visits have not been recorded yet', 'vireo-analytics' ),
				__( 'This is expected on a new install. If it persists for more than a few minutes, the recording job is not running.', 'vireo-analytics' )
			) ];
		}

		$age = max( 0, time() - $last );

		if ( $age > self::STALE_AGGREGATION ) {
			return [ self::check(
				'aggregation',
				'critical',
				__( 'Visits stopped being recorded', 'vireo-analytics' ),
				sprintf(
					/* translators: %s: human-readable duration, e.g. "3 hours" */
					__( 'The last successful run was %s ago, and it should run every minute. Anything since then is still on disk and will be counted once this clears.', 'vireo-analytics' ),
					human_time_diff( $last )
				)
			) ];
		}

		return [ self::check(
			'aggregation',
			'good',
			__( 'Visits are being recorded', 'vireo-analytics' ),
			sprintf(
				/* translators: %s: human-readable duration, e.g. "40 seconds" */
				__( 'Last run %s ago.', 'vireo-analytics' ),
				human_time_diff( $last )
			)
		) ];
	}

	/** @return array<int,array<string,string>> */
	private static function check_stall(): array {
		$stall = (array) get_option( Aggregator::STALL_OPTION, [] );
		$count = (int) ( $stall['count'] ?? 0 );

		if ( $count < 1 ) {
			return [];
		}

		return [ self::check(
			'stall',
			$count >= 5 ? 'critical' : 'warning',
			__( 'The database is refusing writes', 'vireo-analytics' ),
			sprintf(
				/* translators: 1: reason, 2: number of attempts, 3: database error text */
				__( '%1$s. %2$d attempts have failed. The database reported: %3$s', 'vireo-analytics' ),
				(string) ( $stall['reason'] ?? __( 'Aggregation is failing', 'vireo-analytics' ) ),
				$count,
				(string) ( $stall['detail'] ?? __( 'no detail recorded', 'vireo-analytics' ) )
			)
		) ];
	}

	/**
	 * What is sitting in the data directory. A backlog means hits are arriving
	 * but not landing; a parked file means the aggregator gave up on one.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function check_buffer(): array {
		$dir = vireo_data_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return [];
		}

		$out     = [];
		$parked  = (array) glob( $dir . '/*.failed' );
		$pending = array_merge( (array) glob( $dir . '/buffer-*.csv' ), (array) glob( $dir . '/*.busy' ) );

		if ( [] !== $parked ) {
			$out[] = self::check(
				'parked',
				'warning',
				__( 'Some visits could not be saved', 'vireo-analytics' ),
				sprintf(
					/* translators: %d: number of files */
					_n(
						'%d batch was set aside after repeated database errors. The visits are still on disk and can be replayed once the cause is fixed.',
						'%d batches were set aside after repeated database errors. The visits are still on disk and can be replayed once the cause is fixed.',
						count( $parked ),
						'vireo-analytics'
					),
					count( $parked )
				)
			);
		}

		$bytes  = 0;
		$oldest = 0;
		foreach ( $pending as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$bytes = $bytes + (int) filesize( $path );
			$mtime = (int) filemtime( $path );
			if ( $mtime > 0 && ( 0 === $oldest || $mtime < $oldest ) ) {
				$oldest = $mtime;
			}
		}

		if ( $oldest > 0 && ( time() - $oldest ) > self::STALE_BUFFER ) {
			$out[] = self::check(
				'backlog',
				'critical',
				__( 'Visits are piling up without being saved', 'vireo-analytics' ),
				sprintf(
					/* translators: 1: size, 2: human-readable duration */
					__( '%1$s is waiting on disk, the oldest of it from %2$s ago. Visits are being captured but are not reaching your reports.', 'vireo-analytics' ),
					size_format( $bytes ),
					human_time_diff( $oldest )
				)
			);
		}

		return $out;
	}

	/** @return array<int,array<string,string>> */
	private static function check_storage(): array {
		$dir = vireo_data_dir();

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return [ self::check(
				'storage',
				'critical',
				__( 'The data folder is missing', 'vireo-analytics' ),
				__( 'Visits cannot be captured at all until it can be created inside wp-content/uploads.', 'vireo-analytics' )
			) ];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_writable( $dir ) ) {
			return [ self::check(
				'storage',
				'critical',
				__( 'The data folder is not writable', 'vireo-analytics' ),
				__( 'Visits are being discarded as they arrive. Check the folder permissions inside wp-content/uploads.', 'vireo-analytics' )
			) ];
		}

		return [ self::check( 'storage', 'good', __( 'The data folder is writable', 'vireo-analytics' ), '' ) ];
	}

	/**
	 * Has anything actually been written lately? The checks above can all pass
	 * on a site whose reports are nonetheless empty.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function check_data(): array {
		global $wpdb;

		$table = Sites::table( 'site_stats' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe, uncacheable
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [ self::check(
				'tables',
				'critical',
				__( 'The reporting tables are missing', 'vireo-analytics' ),
				__( 'Deactivating and reactivating the plugin will create them.', 'vireo-analytics' )
			) ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- status probe
		$latest = (string) $wpdb->get_var( "SELECT MAX(date) FROM {$table}" );

		if ( '' === $latest ) {
			return [ self::check(
				'data',
				'warning',
				__( 'No visits recorded yet', 'vireo-analytics' ),
				__( 'Expected on a new install, or on a site with no traffic since the plugin was activated.', 'vireo-analytics' )
			) ];
		}

		$days = (int) floor( ( time() - (int) strtotime( $latest . ' 00:00:00' ) ) / DAY_IN_SECONDS );

		if ( $days >= 2 ) {
			return [ self::check(
				'data',
				'warning',
				__( 'Nothing has been recorded for a while', 'vireo-analytics' ),
				sprintf(
					/* translators: %s: date, e.g. "2026-08-07" */
					__( 'The most recent day with any data is %s. On a site with regular traffic this means recording has stopped.', 'vireo-analytics' ),
					$latest
				)
			) ];
		}

		return [ self::check(
			'data',
			'good',
			__( 'Reports are up to date', 'vireo-analytics' ),
			sprintf(
				/* translators: %s: date */
				__( 'Most recent data: %s.', 'vireo-analytics' ),
				$latest
			)
		) ];
	}

	/** @return array<string,string> */
	private static function check( string $id, string $severity, string $label, string $detail ): array {
		return [
			'id'       => $id,
			'severity' => $severity,
			'label'    => $label,
			'detail'   => $detail,
		];
	}

	/**
	 * @param array<string,mixed> $tests
	 * @return array<string,mixed>
	 */
	public static function register_test( $tests ): array {
		$tests = (array) $tests;
		$tests['direct']['vireo_analytics_recording'] = [
			'label' => __( 'Vireo Analytics is recording visits', 'vireo-analytics' ),
			'test'  => [ __CLASS__, 'run_test' ],
		];

		return $tests;
	}

	/** @return array<string,mixed> */
	public static function run_test(): array {
		$checks = self::report();
		// report() globs the data directory and queries the database, so take
		// the worst severity from what we already have rather than building the
		// whole thing a second time.
		$worst  = $checks[0]['severity'] ?? 'good';

		$failures = array_values(
			array_filter( $checks, static fn( array $c ): bool => 'good' !== $c['severity'] )
		);

		if ( [] === $failures ) {
			return [
				'label'       => __( 'Vireo Analytics is recording visits', 'vireo-analytics' ),
				'status'      => 'good',
				'badge'       => [
					'label' => __( 'Analytics', 'vireo-analytics' ),
					'color' => 'blue',
				],
				'description' => '<p>' . esc_html__( 'The recording job is scheduled, ran recently, and your reports are up to date.', 'vireo-analytics' ) . '</p>',
				'test'        => 'vireo_analytics_recording',
			];
		}

		$body = '';
		foreach ( $failures as $failure ) {
			$body .= '<p><strong>' . esc_html( $failure['label'] ) . '</strong><br>' . esc_html( $failure['detail'] ) . '</p>';
		}

		return [
			'label'       => 'critical' === $worst
				? __( 'Vireo Analytics has stopped recording visits', 'vireo-analytics' )
				: __( 'Vireo Analytics is recording, with something worth checking', 'vireo-analytics' ),
			'status'      => 'critical' === $worst ? 'critical' : 'recommended',
			'badge'       => [
				'label' => __( 'Analytics', 'vireo-analytics' ),
				'color' => 'red',
			],
			'description' => $body,
			'test'        => 'vireo_analytics_recording',
		];
	}

	/**
	 * @param array<string,mixed> $info
	 * @return array<string,mixed>
	 */
	public static function register_debug_information( $info ): array {
		$info = (array) $info;
		$fields = [];

		foreach ( self::report() as $check ) {
			// Site Health output gets pasted into public support threads, so
			// the stall detail is summarised here rather than reproduced: it
			// carries the raw database error, which includes the table prefix
			// and whatever values were in the failing row.
			$detail = 'stall' === $check['id'] ? '' : $check['detail'];

			$fields[ 'vireo_' . $check['id'] ] = [
				'label' => $check['label'],
				'value' => '' === $detail ? $check['severity'] : $check['severity'] . ' — ' . $detail,
			];
		}

		$info['vireo-analytics'] = [
			'label'       => __( 'Vireo Analytics', 'vireo-analytics' ),
			'description' => __( 'Whether visits are currently being captured and written to the database.', 'vireo-analytics' ),
			'fields'      => $fields,
		];

		return $info;
	}
}
