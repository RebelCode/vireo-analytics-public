<?php

namespace VireoAnalytics\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Import history from a Plausible Analytics CSV export.
 *
 * Plausible exports a site as a zip of CSVs (Site settings, then Imports &
 * Exports), one file per report, in the shape of its own imported_* tables.
 * Reading that file means there is no API key to store, and it works the same
 * for Plausible Cloud and self-hosted Community Edition.
 *
 * Column names follow Plausible's CSV importer (input_structures in
 * lib/plausible/imported/csv_importer.ex). Columns are looked up by name and
 * the report is recognised by its columns rather than its filename, so a
 * renamed file or a reordered export still parses.
 *
 * The upload is parsed once, in the request that receives it, and staged as
 * non-autoloaded options a batch at a time. The chunk loop writes one batch per
 * request and deletes it, so nothing from the upload outlives the import and no
 * single request has to write the whole history.
 */
final class Plausible_Importer extends Importer_Base {

	/** Both are suffixed with the run token: one staged upload per run. */
	private const PARAMS_OPTION = 'vireo_plausible_import_params_';
	private const STAGE_OPTION  = 'vireo_plausible_import_stage_';

	/** Rows per staged batch, which bounds both the option size and one chunk's writes. */
	private const BATCH_ROWS = 2000;

	/** Distinct rows held in memory while parsing. */
	private const MAX_ROWS = 250000;

	/** Lines read across every file, counted whether or not they are kept. */
	private const MAX_LINES = 3000000;

	/** Per file, compressed or not. Also refuses a zip entry claiming to be larger. */
	private const MAX_FILE_BYTES = 104857600;

	private const MAX_ZIP_ENTRIES = 50;

	/** A stage nothing has written to for this long is treated as abandoned. */
	private const STALE_AFTER = 3600;

	/** One CSV line. Longer ones are split by fgetcsv rather than read into memory whole. */
	private const MAX_LINE_BYTES = 65536;

	/**
	 * CSV parsed across every file, after decompression. The file and line
	 * caps don't bound this on their own: a zip of a few megabytes can inflate
	 * to millions of 64 KB lines. Filterable with vireo_plausible_max_bytes.
	 */
	private const MAX_BYTES = 1073741824;

	/** Plausible report => the Vireo rollup it fills. */
	private const MAPPED = [
		'visitors'      => 'site',
		'pages'         => 'pages',
		'sources'       => 'referrers',
		'locations'     => 'countries',
		'devices'       => 'devices',
		'custom_events' => 'events',
	];

	/**
	 * Columns each mapped report must carry. Plausible's own importer accepts
	 * files with columns missing and fills them with zero, and its test exports
	 * leave pageviews out of the session-based reports, so only the daily
	 * visitors report has to have it.
	 */
	private const REQUIRED = [
		'visitors'      => [ 'date', 'visitors', 'pageviews' ],
		'pages'         => [ 'date', 'page', 'visitors' ],
		'sources'       => [ 'date', 'source', 'visitors' ],
		'locations'     => [ 'date', 'country', 'visitors' ],
		'devices'       => [ 'date', 'device', 'visitors' ],
		'custom_events' => [ 'date', 'name', 'visitors', 'events' ],
	];

	/**
	 * A column only one report has => that report. Checked in order; the daily
	 * visitors report has no dimension column, so it is what is left over.
	 */
	private const SIGNATURES = [
		'entry_page'       => 'entry_pages',
		'exit_page'        => 'exit_pages',
		'browser'          => 'browsers',
		'operating_system' => 'operating_systems',
		'property'         => 'custom_props',
		'link_url'         => 'custom_events',
		'country'          => 'locations',
		'device'           => 'devices',
		'referrer'         => 'sources',
		'source'           => 'sources',
		'page'             => 'pages',
	];

	public function slug(): string {
		return 'plausible';
	}

	public function name(): string {
		return __( 'Plausible Analytics', 'vireo-analytics' );
	}

	/** The data arrives as an upload, so there is nothing on the site to look for. */
	public function is_available(): bool {
		return true;
	}

	public function count_records(): int {
		return $this->get_saved_params()['batches'];
	}

	public function can_read_zip(): bool {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * @return array{batches:int, rows:int, date_start:string, date_end:string, imported:string[], skipped:string[], attempted_through:string, created:int, touched:int, own_from:string}
	 */
	public function get_saved_params(): array {
		$raw = (array) get_option( $this->params_option(), [] );

		return [
			'batches'           => (int) ( $raw['batches'] ?? 0 ),
			'rows'              => (int) ( $raw['rows'] ?? 0 ),
			'date_start'        => (string) ( $raw['date_start'] ?? '' ),
			'date_end'          => (string) ( $raw['date_end'] ?? '' ),
			'imported'          => array_map( 'strval', (array) ( $raw['imported'] ?? [] ) ),
			'skipped'           => array_map( 'strval', (array) ( $raw['skipped'] ?? [] ) ),
			'attempted_through' => (string) ( $raw['attempted_through'] ?? '' ),
			'own_from'          => (string) ( $raw['own_from'] ?? '' ),
			'created'           => (int) ( $raw['created'] ?? 0 ),
			'touched'           => (int) ( $raw['touched'] ?? 0 ),
		];
	}

	/**
	 * @param array{batches:int, created:int, touched:int} $saved
	 */
	public static function is_stale( array $saved, int $now ): bool {
		return $saved['batches'] > 0 && max( $saved['created'], $saved['touched'] ) < $now - self::STALE_AFTER;
	}

	/**
	 * Abort every stage nobody is running any more.
	 *
	 * This runs on a plain page load, so it must not touch a run that may still
	 * be going: a link or an image pointing at the screen would otherwise
	 * cancel someone's import. Only a stage idle for an hour qualifies.
	 */
	public function abort_stale(): void {
		$current = $this->run;
		foreach ( $this->staged_runs() as $run ) {
			$this->run = $run;
			if ( self::is_stale( $this->get_saved_params(), time() ) ) {
				$this->abort();
			}
		}
		$this->run = $current;
	}

	/**
	 * Abort every staged run but this one. Called for a new upload, which is a
	 * nonce-checked POST, so an unfinished run's written days are filed in the
	 * history before the new upload is checked against it.
	 */
	public function abort_others(): void {
		$current = $this->run;
		foreach ( $this->staged_runs() as $run ) {
			if ( $run === $current ) {
				continue;
			}
			$this->run = $run;
			$this->abort();
		}
		$this->run = $current;
	}

	/** @return string[] */
	private function staged_runs(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- option names by prefix; no API for that
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::PARAMS_OPTION ) . '%'
			)
		);

		$runs = [];
		foreach ( (array) $names as $name ) {
			$run = self::valid_run( substr( (string) $name, strlen( self::PARAMS_OPTION ) ) );
			if ( '' !== $run ) {
				$runs[] = $run;
			}
		}
		return $runs;
	}

	private function params_option(): string {
		return self::PARAMS_OPTION . $this->run;
	}

	private function stage_option( int $batch ): string {
		return self::STAGE_OPTION . $this->run . '_' . $batch;
	}

	public function clear_params(): void {
		$batches = $this->get_saved_params()['batches'];
		for ( $i = 0; $i < $batches; $i++ ) {
			delete_option( $this->stage_option( $i ) );
		}
		delete_option( $this->params_option() );
	}

	/**
	 * Give up on a staged import that will not be finished.
	 *
	 * Batches already written stay written, so the dates they covered go in the
	 * import history. Without that, uploading the same export again would add
	 * those days a second time with no warning.
	 */
	public function abort(): void {
		$saved = $this->get_saved_params();
		if ( '' !== $saved['attempted_through'] ) {
			\VireoAnalytics\Import_History::record( $this->slug(), $saved['date_start'], $saved['attempted_through'], [ 'own_from' => $saved['own_from'] ] );
		}
		$this->clear_params();
	}

	/**
	 * Parse uploaded files and stage them for the chunk loop.
	 *
	 * @param array<int, array{name:string, tmp_name:string, error:int, size:int}> $files Already checked by is_uploaded_file().
	 * @return array{batches:int, rows:int, date_start:string, date_end:string, imported:string[], skipped:string[]}|\WP_Error
	 */
	public function stage_uploads( array $files, string $from, string $to ) {
		$this->abort_others();

		wp_raise_memory_limit( 'admin' );

		$acc              = self::new_accumulator( $from, $to, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$acc['max_bytes'] = (int) apply_filters( 'vireo_plausible_max_bytes', self::MAX_BYTES );

		try {
			foreach ( $files as $file ) {
				$this->read_upload( $file, $acc );
			}
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'vireo_plausible_read', $e->getMessage() );
		} finally {
			// PHP removes these at the end of the request anyway. Removing them
			// now means a fatal later in the request cannot leave one behind.
			foreach ( $files as $file ) {
				if ( is_file( $file['tmp_name'] ) ) {
					wp_delete_file( $file['tmp_name'] );
				}
			}
		}

		$imported = array_values( array_intersect( array_keys( self::MAPPED ), $acc['reports'] ) );
		$skipped  = array_values( array_diff( $acc['reports'], $imported ) );

		if ( empty( $imported ) ) {
			return new \WP_Error( 'vireo_plausible_empty', __( 'No Plausible reports were found in that upload. Upload the zip file Plausible emailed you, or the CSV files inside it.', 'vireo-analytics' ) );
		}

		$batches = self::batches( $acc['data'] );
		if ( empty( $batches ) ) {
			return new \WP_Error( 'vireo_plausible_range', __( 'The export has no traffic in the dates you chose.', 'vireo-analytics' ) );
		}

		foreach ( $batches as $i => $batch ) {
			update_option( $this->stage_option( $i ), $batch, false );
		}

		$summary = [
			'batches'    => count( $batches ),
			'rows'       => $acc['rows'],
			'date_start' => $batches[0]['from'],
			'date_end'   => $batches[ count( $batches ) - 1 ]['to'],
			'imported'   => $imported,
			'skipped'    => $skipped,
		];

		update_option(
			$this->params_option(),
			$summary + [
				'attempted_through' => '',
				'created'           => time(),
				// Taken before anything is written. Asked again later, the
				// days this run has written but not yet filed in the history
				// would look like Vireo's own.
				'own_from'          => (string) \VireoAnalytics\Import_History::own_data_start(),
			],
			false
		);

		return $summary;
	}

	/**
	 * Write one staged batch.
	 *
	 * @param array<string,mixed> $params
	 * @return array{done:bool,processed:int,total:int,next_offset:int,label:string,error?:string}
	 */
	public function import_chunk( array $params, int $offset, int $limit ): array {
		$saved = $this->get_saved_params();
		$total = $saved['batches'];

		if ( 0 === $total ) {
			return $this->envelope_error( __( 'Upload a Plausible export first.', 'vireo-analytics' ) );
		}

		if ( $offset >= $total ) {
			$this->clear_params();
			return $this->envelope_done( $total, 0 );
		}

		$batch = get_option( $this->stage_option( $offset ) );
		if ( ! is_array( $batch ) || ! isset( $batch['data'], $batch['from'], $batch['to'] ) ) {
			$this->abort();
			return $this->envelope_error( __( 'The uploaded export is no longer available. Upload it again; dates already imported are listed on this screen.', 'vireo-analytics' ) );
		}

		// Recorded before writing, so a batch that fails halfway still counts as
		// imported if the run is abandoned. Over-warning beats double counting.
		update_option(
			$this->params_option(),
			[
				'attempted_through' => (string) $batch['to'],
				'touched'           => time(),
			] + (array) get_option( $this->params_option(), [] ),
			false
		);

		$written = $this->write_batch( (array) $batch['data'] );
		delete_option( $this->stage_option( $offset ) );

		$next = $offset + 1;
		if ( $next >= $total ) {
			$this->clear_params();
			return $this->envelope_done( $total, $written );
		}

		return [
			'done'        => false,
			'processed'   => $written,
			'total'       => $total,
			'next_offset' => $next,
			'label'       => sprintf(
				/* translators: 1: start date, 2: end date */
				__( 'Imported %1$s to %2$s', 'vireo-analytics' ),
				(string) $batch['from'],
				(string) $batch['to']
			),
		];
	}

	/**
	 * @param array<string, array<string, array<string, array{0:int,1:int}>>> $data Keyed kind, date, key; values are [pageviews, visitors].
	 */
	private function write_batch( array $data ): int {
		$written = 0;

		foreach ( (array) ( $data['site'] ?? [] ) as $date => $keys ) {
			foreach ( $keys as $counts ) {
				$this->upsert_site_stats( (string) $date, (int) $counts[1], (int) $counts[0] );
				++$written;
			}
		}

		$post_ids = [];
		$bulk     = [];
		foreach ( (array) ( $data['pages'] ?? [] ) as $date => $paths ) {
			foreach ( $paths as $path => $counts ) {
				$path = (string) $path;
				if ( ! isset( $post_ids[ $path ] ) ) {
					$post_ids[ $path ] = (int) url_to_postid( home_url( $path ) );
				}
				$bulk[] = [
					'date'      => (string) $date,
					'post_id'   => $post_ids[ $path ],
					'path'      => $path,
					'pageviews' => (int) $counts[0],
					'visitors'  => (int) $counts[1],
				];
			}
		}
		$this->upsert_post_stats_bulk( $bulk );
		$written += count( $bulk );

		$bulk = [];
		foreach ( (array) ( $data['referrers'] ?? [] ) as $date => $hosts ) {
			foreach ( $hosts as $host => $counts ) {
				$bulk[] = [
					'date'      => (string) $date,
					'host'      => (string) $host,
					'pageviews' => (int) $counts[0],
					'visitors'  => (int) $counts[1],
				];
			}
		}
		$this->upsert_referrer_stats_bulk( $bulk );
		$written += count( $bulk );

		foreach ( (array) ( $data['countries'] ?? [] ) as $date => $codes ) {
			foreach ( $codes as $code => $counts ) {
				$this->upsert_geo_stats( (string) $date, (string) $code, (int) $counts[1], (int) $counts[0] );
				++$written;
			}
		}

		// Plausible reports device, browser and OS as three separate breakdowns,
		// and Vireo stores them as one combination. Pairing them up would mean
		// inventing the combinations, so only the device type comes across and
		// browser and OS are left blank for these days.
		foreach ( (array) ( $data['devices'] ?? [] ) as $date => $types ) {
			foreach ( $types as $type => $counts ) {
				$this->upsert_device_stats( (string) $date, '', '', (string) $type, (int) $counts[1], (int) $counts[0] );
				++$written;
			}
		}

		$bulk = [];
		foreach ( (array) ( $data['events'] ?? [] ) as $date => $keys ) {
			foreach ( $keys as $key => $counts ) {
				[ $name, $value ] = (array) json_decode( (string) $key, true ) + [ '', '' ];
				$bulk[]           = [
					'date'         => (string) $date,
					'name'         => $name,
					'value'        => $value,
					'count'        => (int) $counts[0],
					'unique_count' => (int) $counts[1],
				];
			}
		}
		$this->upsert_event_stats_bulk( $bulk );
		$written += count( $bulk );

		$bulk = [];
		foreach ( (array) ( $data['missing'] ?? [] ) as $date => $paths ) {
			foreach ( $paths as $path => $counts ) {
				$bulk[] = [
					'date'         => (string) $date,
					'path'         => (string) $path,
					'referrer'     => '',
					'internal_ref' => '',
					'hits'         => (int) $counts[0],
				];
			}
		}
		$this->upsert_404_stats_bulk( $bulk );
		$written += count( $bulk );

		return $written;
	}

	/**
	 * @param array{name:string, tmp_name:string, error:int, size:int} $file
	 * @param array<string,mixed>                                      $acc
	 */
	private function read_upload( array $file, array &$acc ): void {
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			self::fail( __( 'The file did not upload. It may be larger than this server accepts.', 'vireo-analytics' ) );
		}

		if ( $file['size'] > self::MAX_FILE_BYTES || filesize( $file['tmp_name'] ) > self::MAX_FILE_BYTES ) {
			self::fail( __( 'That file is too large. Export a shorter date range from Plausible and import it in parts.', 'vireo-analytics' ) );
		}

		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( 'zip' === $extension ) {
			$this->read_zip( $file['tmp_name'], $acc );
			return;
		}

		if ( 'csv' !== $extension ) {
			self::fail( __( 'Upload the .zip file Plausible gave you, or the .csv files inside it.', 'vireo-analytics' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading PHP's own upload temp file line by line; WP_Filesystem has no streaming read
		$handle = fopen( $file['tmp_name'], 'rb' );
		if ( false === $handle ) {
			self::fail( __( 'The uploaded file could not be read.', 'vireo-analytics' ) );
		}
		try {
			self::read_csv( $handle, $acc );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}
	}

	/**
	 * Entries are streamed straight out of the archive and never extracted, so
	 * a name like ../../wp-config.php inside it has no path to escape through.
	 *
	 * @param string              $path
	 * @param array<string,mixed> $acc
	 */
	private function read_zip( string $path, array &$acc ): void {
		if ( ! $this->can_read_zip() ) {
			self::fail( __( 'This server cannot open zip files. Unzip the export on your computer and upload the CSV files instead.', 'vireo-analytics' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			self::fail( __( 'That zip file could not be opened.', 'vireo-analytics' ) );
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's own property
		try {
			if ( $zip->numFiles > self::MAX_ZIP_ENTRIES ) {
				self::fail( __( 'That zip holds more files than a Plausible export does.', 'vireo-analytics' ) );
			}

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) ) {
					continue;
				}
				$name = (string) $stat['name'];
				if ( '/' === substr( $name, -1 ) || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
					continue;
				}
				if ( (int) $stat['size'] > self::MAX_FILE_BYTES ) {
					self::fail( __( 'That file is too large. Export a shorter date range from Plausible and import it in parts.', 'vireo-analytics' ) );
				}

				$stream = $zip->getStream( $name );
				if ( false === $stream ) {
					self::fail( __( 'A file inside the zip could not be read.', 'vireo-analytics' ) );
				}
				try {
					self::read_csv( $stream, $acc );
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $stream );
				}
			}
		} finally {
			$zip->close();
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * @return array{data:array<string,array>, reports:string[], rows:int, lines:int, from:string, to:string, self_host:string}
	 */
	public static function new_accumulator( string $from, string $to, string $self_host ): array {
		return [
			'data'      => [],
			'reports'   => [],
			'rows'      => 0,
			'lines'     => 0,
			'bytes'     => 0,
			'max_bytes' => self::MAX_BYTES,
			'from'      => $from,
			'to'        => $to,
			'self_host' => self::strip_www( strtolower( $self_host ) ),
		];
	}

	/**
	 * Read one Plausible CSV into the accumulator, summing rows that land on
	 * the same day and key. Returns the report it recognised, or '' when the
	 * header is not one of Plausible's.
	 *
	 * @param resource            $handle
	 * @param array<string,mixed> $acc
	 */
	public static function read_csv( $handle, array &$acc ): string {
		$header = fgetcsv( $handle, self::MAX_LINE_BYTES, ',', '"', '' );
		if ( ! is_array( $header ) ) {
			return '';
		}

		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header    = array_map( static fn( $col ): string => strtolower( trim( (string) $col ) ), $header );

		$report = self::detect_report( $header );
		if ( '' === $report ) {
			return '';
		}

		$cols = array_flip( $header );

		// A sources file can name each source ("Google") without the address it
		// came from. Vireo keys referrers on host, so there is nothing to store,
		// and the screen should say so rather than list referrers as imported.
		if ( 'sources' === $report && ! isset( $cols['referrer'] ) ) {
			$report = 'sources_by_name';
		}

		if ( ! in_array( $report, $acc['reports'], true ) ) {
			$acc['reports'][] = $report;
		}

		if ( ! isset( self::MAPPED[ $report ] ) ) {
			return $report;
		}

		foreach ( self::REQUIRED[ $report ] as $required ) {
			if ( ! isset( $cols[ $required ] ) ) {
				self::fail(
					sprintf(
						/* translators: 1: Plausible report name, 2: column name */
						__( 'The %1$s file is missing its %2$s column.', 'vireo-analytics' ),
						$report,
						$required
					)
				);
			}
		}

		$kind      = self::MAPPED[ $report ];
		$dimension = [
			'visitors'      => '',
			'pages'         => 'page',
			'sources'       => 'referrer',
			'locations'     => 'country',
			'devices'       => 'device',
			'custom_events' => 'name',
		][ $report ];

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $row = fgetcsv( $handle, self::MAX_LINE_BYTES, ',', '"', '' ) ) ) {
			// Field lengths plus a separator each: a slight undercount of the
			// raw line (quotes aren't seen), which is fine for a ceiling.
			$acc['bytes'] += count( $row ) + array_sum( array_map( 'strlen', array_map( 'strval', $row ) ) );
			if ( ++$acc['lines'] > self::MAX_LINES || $acc['bytes'] > $acc['max_bytes'] ) {
				self::fail( __( 'That export is too large to import in one go. Choose a shorter date range and import it in parts.', 'vireo-analytics' ) );
			}

			$date = self::normalize_date( (string) ( $row[ $cols['date'] ] ?? '' ) );
			if ( '' === $date
				|| ( '' !== $acc['from'] && $date < $acc['from'] )
				|| ( '' !== $acc['to'] && $date > $acc['to'] ) ) {
				continue;
			}

			// An event row's count is its `events` column, held where the other
			// reports hold pageviews.
			$count     = 'events' === $kind ? 'events' : 'pageviews';
			$pageviews = isset( $cols[ $count ] ) ? max( 0, (int) ( $row[ $cols[ $count ] ] ?? 0 ) ) : 0;
			$visitors  = max( 0, (int) ( $row[ $cols['visitors'] ] ?? 0 ) );
			if ( 0 === $pageviews && 0 === $visitors ) {
				continue;
			}

			$raw = '' === $dimension ? '' : (string) ( $row[ $cols[ $dimension ] ] ?? '' );
			if ( 'events' === $kind ) {
				$event = self::event_key(
					$raw,
					(string) ( isset( $cols['link_url'] ) ? ( $row[ $cols['link_url'] ] ?? '' ) : '' ),
					(string) ( isset( $cols['path'] ) ? ( $row[ $cols['path'] ] ?? '' ) : '' ),
					$acc['self_host']
				);
				if ( null === $event ) {
					continue;
				}
				[ $row_kind, $key ] = $event;
			} else {
				$row_kind = $kind;
				$key      = self::normalize_key( $kind, $raw, $acc['self_host'] );
			}
			if ( null === $key ) {
				continue;
			}

			if ( isset( $acc['data'][ $row_kind ][ $date ][ $key ] ) ) {
				$acc['data'][ $row_kind ][ $date ][ $key ][0] += $pageviews;
				$acc['data'][ $row_kind ][ $date ][ $key ][1] += $visitors;
				continue;
			}

			if ( ++$acc['rows'] > self::MAX_ROWS ) {
				self::fail( __( 'That export is too large to import in one go. Choose a shorter date range and import it in parts.', 'vireo-analytics' ) );
			}
			$acc['data'][ $row_kind ][ $date ][ $key ] = [ $pageviews, $visitors ];
		}

		return $report;
	}

	/**
	 * @param string[] $header Lowercased column names.
	 */
	public static function detect_report( array $header ): string {
		if ( ! in_array( 'date', $header, true ) ) {
			return '';
		}

		foreach ( self::SIGNATURES as $column => $report ) {
			if ( in_array( $column, $header, true ) ) {
				return $report;
			}
		}

		return in_array( 'visitors', $header, true ) && in_array( 'pageviews', $header, true ) ? 'visitors' : '';
	}

	/**
	 * Split parsed data into date-contiguous batches of about BATCH_ROWS rows.
	 * A day is never split, so one very busy day can make a batch larger.
	 *
	 * @param array<string, array<string, array<string, array{0:int,1:int}>>> $data
	 * @return array<int, array{from:string, to:string, data:array}>
	 */
	public static function batches( array $data ): array {
		$per_day = [];
		foreach ( $data as $kind => $days ) {
			foreach ( $days as $date => $keys ) {
				$per_day[ (string) $date ] = ( $per_day[ (string) $date ] ?? 0 ) + count( $keys );
			}
		}
		ksort( $per_day, SORT_STRING );

		$batches = [];
		$current = null;
		foreach ( $per_day as $date => $count ) {
			if ( null !== $current && $current['rows'] + $count > self::BATCH_ROWS ) {
				$batches[] = $current;
				$current   = null;
			}
			if ( null === $current ) {
				$current = [
					'from' => $date,
					'to'   => $date,
					'rows' => 0,
					'data' => [],
				];
			}
			foreach ( $data as $kind => $days ) {
				if ( isset( $days[ $date ] ) ) {
					$current['data'][ $kind ][ $date ] = $days[ $date ];
				}
			}
			$current['to']    = $date;
			$current['rows'] += $count;
		}
		if ( null !== $current ) {
			$batches[] = $current;
		}

		return array_map(
			static fn( array $b ): array => [
				'from' => $b['from'],
				'to'   => $b['to'],
				'data' => $b['data'],
			],
			$batches
		);
	}

	/**
	 * The key a row is stored under, or null to drop it.
	 */
	private static function normalize_key( string $kind, string $raw, string $self_host ): ?string {
		switch ( $kind ) {
			case 'site':
				return '';

			case 'pages':
				$path = preg_replace( '/[?#].*$/s', '', trim( $raw ) );
				if ( '' === $path ) {
					return null;
				}
				if ( '/' !== $path[0] ) {
					$path = '/' . $path;
				}
				return \VireoAnalytics\Collector::clip( $path, 500 );

			case 'referrers':
				// Plausible stores the referrer without its scheme
				// ("news.ycombinator.com/item"). Sessions with no referrer are
				// direct or tagged only by utm_source; Vireo counts direct as
				// what is left after referrers, so they are not stored here.
				$host = self::host_of( $raw );
				return ( '' === $host || $host === $self_host ) ? null : \VireoAnalytics\Collector::clip( $host, 190 );

			case 'countries':
				$code = strtoupper( trim( $raw ) );
				return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;

			case 'devices':
				switch ( strtolower( trim( $raw ) ) ) {
					case 'desktop':
					case 'laptop':
						return 'Desktop';
					case 'mobile':
						return 'Mobile';
					case 'tablet':
						return 'Tablet';
					default:
						return '';
				}
		}

		return null;
	}

	/**
	 * Where a Plausible event goes, as [kind, key], or null to drop it.
	 *
	 * Plausible's own events become the tracker's: an outbound click is
	 * `outbound` by host, a file download is `download` by file name, and a
	 * form submission is `form_submit`. Its 404 event, which carries the
	 * missing path, goes to missing pages. Engagement is how long a page was
	 * read, not an event anyone fired, so it is left out. Every other name is
	 * a goal or custom event of the site's own and keeps its name.
	 *
	 * Visitors are summed where several of Plausible's rows land on one key,
	 * such as two links to the same host, so a reader who clicked both is
	 * counted twice there.
	 *
	 * @return array{0:string, 1:string}|null
	 */
	public static function event_key( string $name, string $link_url, string $path, string $self_host ): ?array {
		$name = trim( $name );
		switch ( $name ) {
			case '':
			case 'pageview':
			case 'engagement':
				return null;
			case 'Outbound Link: Click':
				$host = self::outbound_host( $link_url, $self_host );
				return '' === $host ? null : [ 'events', self::pair( 'outbound', $host ) ];
			case 'File Download':
				$file = self::download_name( $link_url );
				return '' === $file ? null : [ 'events', self::pair( 'download', $file ) ];
			case 'Form: Submission':
			case 'WP Form Completions':
				return [ 'events', self::pair( 'form_submit', '' ) ];
			case '404':
				$path = preg_replace( '/[?#].*$/s', '', trim( $path ) );
				if ( '' === $path ) {
					return null;
				}
				return [ 'missing', '/' === $path[0] ? $path : '/' . $path ];
		}
		return [ 'events', self::pair( \VireoAnalytics\Collector::clip( $name, 100 ), '' ) ];
	}

	/** An event's name and value as one key. Staged as JSON, so no separator can collide with a name. */
	private static function pair( string $name, string $value ): string {
		return (string) wp_json_encode( [ $name, $value ] );
	}

	private static function host_of( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( false === strpos( $raw, '://' ) ) {
			$raw = 'http://' . $raw;
		}
		$host = wp_parse_url( $raw, PHP_URL_HOST );
		return is_string( $host ) ? self::strip_www( strtolower( $host ) ) : '';
	}

	private static function strip_www( string $host ): string {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	private static function normalize_date( string $raw ): string {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $raw ), $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : '';
	}

	/**
	 * Messages are escaped where they are shown. Escaping here as well would
	 * print entities for any translation with an apostrophe in it.
	 *
	 * @throws \RuntimeException Always.
	 */
	private static function fail( string $message ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped on output
		throw new \RuntimeException( $message );
	}

	/** @return array{done:bool,processed:int,total:int,next_offset:int,label:string} */
	private function envelope_done( int $total, int $written ): array {
		return [
			'done'        => true,
			'processed'   => $written,
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
}
