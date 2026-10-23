<?php

namespace VireoAnalytics\Importers;

use VireoAnalytics\Backup;
use VireoAnalytics\Sites;
use VireoAnalytics\Upserter;

defined( 'ABSPATH' ) || exit;

/**
 * Load a file written by Backup back into the report tables.
 *
 * Unlike the other importers this one is not additive. A row in the file
 * replaces the row with the same key here, so loading the same file twice,
 * or loading it after a run that stopped halfway, lands on the same numbers.
 * "Replace" empties the tables first, so the install ends up holding exactly
 * what the file holds; "merge" keeps whatever the file does not mention.
 *
 * The upload is copied into Vireo's data directory under the run token and
 * read a few lines per request, so no single request has to hold the file.
 */
final class Backup_Importer extends Importer_Base {

	private const PARAMS_OPTION = 'vireo_backup_import_params_';

	/** Lines per request. Each line holds up to Backup::ROWS_PER_LINE rows. */
	public const LINES_PER_CHUNK = 20;

	/** One line of the file. Longer than this is not a file Backup wrote. */
	private const MAX_LINE_BYTES = 8388608;

	/** A staged file nothing has touched for this long is dropped. */
	private const STALE_AFTER = 3600;

	public const MODES = [ 'merge', 'replace' ];

	/** @var array{first:string,last:string,days:int,pageviews:int} */
	private array $tally = [ 'first' => '', 'last' => '', 'days' => 0, 'pageviews' => 0 ];

	/** Lines naming a table this build does not know, so the result can say so. */
	private int $skipped = 0;

	public function slug(): string {
		return 'backup';
	}

	public function name(): string {
		return __( 'Vireo Analytics backup', 'vireo-analytics' );
	}

	/** It can always run; whether there is a file is a question for the run. */
	public function is_available(): bool {
		return true;
	}

	public function count_records(): int {
		return $this->get_saved_params()['lines'];
	}

	/** What this request wrote, from site_stats, which is what the history summary counts. */
	public function written(): array {
		return $this->tally;
	}

	// ---------------------------------------------------------------------
	// Staged state
	// ---------------------------------------------------------------------

	private function params_option(): string {
		return self::PARAMS_OPTION . $this->run;
	}

	/**
	 * @return array{file:string,mode:string,lines:int,rows:int,site:string,exported:string,from:string,to:string,tables:string[],date_start:string,date_end:string,created:int,touched:int}
	 */
	public function get_saved_params(): array {
		$raw = (array) get_option( $this->params_option(), [] );

		return [
			'file'       => (string) ( $raw['file'] ?? '' ),
			'mode'       => in_array( $raw['mode'] ?? '', self::MODES, true ) ? (string) $raw['mode'] : 'merge',
			'lines'      => (int) ( $raw['lines'] ?? 0 ),
			'rows'       => (int) ( $raw['rows'] ?? 0 ),
			'site'       => (string) ( $raw['site'] ?? '' ),
			'exported'   => (string) ( $raw['exported'] ?? '' ),
			'from'       => (string) ( $raw['from'] ?? '' ),
			'to'         => (string) ( $raw['to'] ?? '' ),
			'tables'     => array_map( 'strval', (array) ( $raw['tables'] ?? [] ) ),
			// Read by the controller as the range to file in the history.
			'date_start' => (string) ( $raw['from'] ?? '' ),
			'date_end'   => (string) ( $raw['to'] ?? '' ),
			'created'    => (int) ( $raw['created'] ?? 0 ),
			'touched'    => (int) ( $raw['touched'] ?? 0 ),
		];
	}

	public function clear_params(): void {
		$file = $this->get_saved_params()['file'];
		if ( '' !== $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
		delete_option( $this->params_option() );
	}

	public function abort(): void {
		$this->clear_params();
	}

	public static function is_stale( array $saved, int $now ): bool {
		$last = max( (int) ( $saved['created'] ?? 0 ), (int) ( $saved['touched'] ?? 0 ) );
		return $last > 0 && $now - $last > self::STALE_AFTER;
	}

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

	/** One staged file at a time: a new upload drops any other run's. */
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

	// ---------------------------------------------------------------------
	// Upload
	// ---------------------------------------------------------------------

	/**
	 * Keep the upload for the run and check it is a backup.
	 *
	 * @param array{name:string,tmp_name:string,error:int,size:int} $file
	 * @return array<string,mixed>|\WP_Error The saved params, or why not.
	 */
	public function stage_upload( array $file, string $mode ) {
		$this->abort_others();

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error(
				'vireo_backup_upload',
				in_array( (int) $file['error'], [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true )
					? sprintf(
						/* translators: %s: maximum upload size, e.g. 64 MB */
						__( 'That file is larger than this server accepts (%s). Ask your host to raise the upload limit, or restore in smaller pieces.', 'vireo-analytics' ),
						size_format( (int) wp_max_upload_size() )
					)
					: __( 'The upload did not complete. Try again.', 'vireo-analytics' )
			);
		}

		$dir = function_exists( 'vireo_data_dir' ) ? vireo_data_dir() : '';
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'vireo_backup_dir', __( 'The uploads directory is not writable, so the file cannot be kept while it is read.', 'vireo-analytics' ) );
		}

		$dest = $dir . '/backup-import-' . $this->run . '.bin';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- the upload has to survive this request; copying it is the whole point
		if ( ! copy( $file['tmp_name'], $dest ) ) {
			return new \WP_Error( 'vireo_backup_copy', __( 'The file could not be saved for reading.', 'vireo-analytics' ) );
		}

		$inspected = self::inspect( $dest );
		if ( is_wp_error( $inspected ) ) {
			wp_delete_file( $dest );
			return $inspected;
		}

		$params = $inspected + [
			'file'    => $dest,
			'mode'    => in_array( $mode, self::MODES, true ) ? $mode : 'merge',
			'created' => time(),
			'touched' => time(),
		];
		update_option( $this->params_option(), $params, false );

		return $params;
	}

	/**
	 * Read the file once end to end: the header has to be ours, every line
	 * has to parse, and the end line has to be there.
	 *
	 * @return array{lines:int,rows:int,site:string,exported:string,from:string,to:string,tables:string[]}|\WP_Error
	 */
	public static function inspect( string $path ) {
		$handle = self::open( $path );
		if ( false === $handle ) {
			return new \WP_Error( 'vireo_backup_open', __( 'The file could not be opened.', 'vireo-analytics' ) );
		}

		$header = self::parse_line( (string) gzgets( $handle, self::MAX_LINE_BYTES ) );
		$check  = self::check_header( $header );
		if ( is_wp_error( $check ) ) {
			gzclose( $handle );
			return $check;
		}

		$lines = 0;
		$rows  = 0;
		$ended = false;
		while ( ! gzeof( $handle ) ) {
			$raw = gzgets( $handle, self::MAX_LINE_BYTES );
			if ( false === $raw || '' === trim( $raw ) ) {
				continue;
			}
			$line = self::parse_line( $raw );
			if ( null === $line ) {
				gzclose( $handle );
				return new \WP_Error( 'vireo_backup_line', __( 'The file is damaged: a line could not be read.', 'vireo-analytics' ) );
			}
			if ( ! empty( $line['end'] ) ) {
				$ended = true;
				break;
			}
			++$lines;
			$rows += count( (array) ( $line['r'] ?? [] ) );
		}
		gzclose( $handle );

		if ( ! $ended ) {
			return new \WP_Error( 'vireo_backup_truncated', __( 'The file ends early. It may not have finished downloading; export it again.', 'vireo-analytics' ) );
		}

		return [
			'lines'    => $lines,
			'rows'     => $rows,
			'site'     => (string) ( $header['site'] ?? '' ),
			'exported' => (string) ( $header['exported'] ?? '' ),
			'from'     => (string) ( $header['from'] ?? '' ),
			'to'       => (string) ( $header['to'] ?? '' ),
			'tables'   => array_map( 'strval', (array) ( $header['tables'] ?? [] ) ),
		];
	}

	/**
	 * @param array<string,mixed>|null $header
	 * @return true|\WP_Error
	 */
	public static function check_header( ?array $header ) {
		if ( null === $header || Backup::FORMAT !== (string) ( $header['format'] ?? '' ) ) {
			return new \WP_Error( 'vireo_backup_format', __( 'That is not a Vireo Analytics backup. Use the file from Settings, Import & export, Download backup.', 'vireo-analytics' ) );
		}
		if ( (int) ( $header['version'] ?? 0 ) > Backup::VERSION ) {
			return new \WP_Error( 'vireo_backup_version', __( 'This backup was made by a newer version of Vireo Analytics. Update the plugin here, then try again.', 'vireo-analytics' ) );
		}
		return true;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function parse_line( string $raw ): ?array {
		$line = json_decode( trim( $raw ), true );
		return is_array( $line ) ? $line : null;
	}

	/**
	 * gzopen reads plain files too, so one opener covers both.
	 *
	 * @return resource|false
	 */
	private static function open( string $path ) {
		if ( ! is_file( $path ) || ! function_exists( 'gzopen' ) ) {
			return false;
		}
		return gzopen( $path, 'rb' );
	}

	// ---------------------------------------------------------------------
	// Chunk loop
	// ---------------------------------------------------------------------

	/**
	 * $offset is how many data lines earlier chunks consumed.
	 */
	public function import_chunk( array $params, int $offset, int $limit ): array {
		$saved = $this->get_saved_params();
		if ( '' === $saved['file'] ) {
			return $this->envelope_error( __( 'Upload a backup file first.', 'vireo-analytics' ) );
		}

		$handle = self::open( $saved['file'] );
		if ( false === $handle ) {
			$this->abort();
			return $this->envelope_error( __( 'The uploaded file is no longer available. Upload it again.', 'vireo-analytics' ) );
		}

		update_option( $this->params_option(), [ 'touched' => time() ] + (array) get_option( $this->params_option(), [] ), false );

		if ( 0 === $offset && 'replace' === $saved['mode'] ) {
			$this->empty_tables();
		}

		// Skip the header and the lines already loaded.
		gzgets( $handle, self::MAX_LINE_BYTES );
		$seen = 0;
		while ( $seen < $offset && ! gzeof( $handle ) ) {
			$raw = gzgets( $handle, self::MAX_LINE_BYTES );
			if ( false !== $raw && '' !== trim( $raw ) ) {
				++$seen;
			}
		}

		$done      = false;
		$processed = 0;
		$written   = 0;
		$last      = '';
		while ( $processed < self::LINES_PER_CHUNK && ! gzeof( $handle ) ) {
			$raw = gzgets( $handle, self::MAX_LINE_BYTES );
			if ( false === $raw || '' === trim( $raw ) ) {
				continue;
			}
			$line = self::parse_line( $raw );
			if ( null === $line ) {
				gzclose( $handle );
				$this->abort();
				return $this->envelope_error( __( 'The file is damaged: a line could not be read.', 'vireo-analytics' ) );
			}
			if ( ! empty( $line['end'] ) ) {
				$done = true;
				break;
			}
			++$processed;
			$written += $this->write_rows( (string) ( $line['t'] ?? '' ), array_map( 'strval', (array) ( $line['c'] ?? [] ) ), (array) ( $line['r'] ?? [] ) );
			$last     = (string) ( $line['t'] ?? '' );
		}
		$done = $done || gzeof( $handle );
		gzclose( $handle );

		$next = $offset + $processed;

		if ( $done ) {
			$this->clear_params();
			return [
				'done'        => true,
				'processed'   => $written,
				'total'       => $saved['lines'],
				'next_offset' => $saved['lines'],
				'label'       => $this->skipped > 0
					? sprintf(
						/* translators: %d: number of parts of the file that were skipped */
						_n( 'Restored. %d part of the file was for a table this version does not have and was skipped.', 'Restored. %d parts of the file were for tables this version does not have and were skipped.', $this->skipped, 'vireo-analytics' ),
						$this->skipped
					)
					: __( 'Restored.', 'vireo-analytics' ),
			];
		}

		return [
			'done'        => false,
			'processed'   => $written,
			'total'       => $saved['lines'],
			'next_offset' => $next,
			'label'       => sprintf(
				/* translators: %s: name of the table being restored, e.g. post_stats */
				__( 'Restoring %s', 'vireo-analytics' ),
				self::table_label( $last )
			),
		];
	}

	/**
	 * What a table holds, in words, for the progress line.
	 */
	public static function table_label( string $short ): string {
		$labels = [
			'site_stats'          => __( 'daily totals', 'vireo-analytics' ),
			'bounce_stats'        => __( 'bounce rates', 'vireo-analytics' ),
			'hour_stats'          => __( 'hourly totals', 'vireo-analytics' ),
			'post_stats'          => __( 'pages', 'vireo-analytics' ),
			'page_time'           => __( 'time on page', 'vireo-analytics' ),
			'referrer_stats'      => __( 'referrers', 'vireo-analytics' ),
			'post_referrer_stats' => __( 'referrers per page', 'vireo-analytics' ),
			'geo_stats'           => __( 'countries', 'vireo-analytics' ),
			'device_stats'        => __( 'devices', 'vireo-analytics' ),
			'404_stats'           => __( 'missing pages', 'vireo-analytics' ),
			'event_stats'         => __( 'events', 'vireo-analytics' ),
			'bot_stats'           => __( 'bot traffic', 'vireo-analytics' ),
			'vitals_stats'        => __( 'Core Web Vitals', 'vireo-analytics' ),
			'annotations'         => __( 'chart notes', 'vireo-analytics' ),
			'gsc_stats'           => __( 'Search Console queries', 'vireo-analytics' ),
			'gsc_pages'           => __( 'Search Console pages', 'vireo-analytics' ),
		];
		return $labels[ $short ] ?? $short;
	}

	/**
	 * Delete every row of every backed-up table for the site in context. The
	 * dictionary tables stay: nothing is wrong with a path row nobody points
	 * at, and Pro reads them by id.
	 */
	private function empty_tables(): void {
		global $wpdb;
		foreach ( array_keys( Backup::tables() ) as $short ) {
			$table = Sites::table( $short );
			if ( ! Backup::table_exists( $table ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the person chose "replace"
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
			$this->assert_no_db_error();
		}
	}

	/**
	 * One line of the file into its table. Returns the rows written.
	 *
	 * @param string[]                 $columns Column names, in the order the rows' values come.
	 * @param array<int, array<int, mixed>> $rows
	 */
	public function write_rows( string $short, array $columns, array $rows ): int {
		$spec = Backup::tables()[ $short ] ?? null;
		if ( null === $spec || [] === $rows ) {
			if ( null === $spec ) {
				++$this->skipped;
			}
			return 0;
		}

		$table = Sites::table( $short );
		if ( ! Backup::table_exists( $table ) ) {
			++$this->skipped;
			return 0;
		}

		// Rows keyed by column name, missing columns at their defaults, so a
		// file from a build with fewer or more columns still loads.
		$assoc = [];
		foreach ( $rows as $row ) {
			$row = array_values( (array) $row );
			$r   = [];
			foreach ( $spec['columns'] as $i => $column ) {
				$at = array_search( $column, $columns, true );
				$v  = false === $at ? null : ( $row[ $at ] ?? null );
				if ( null === $v ) {
					$v = Backup::is_number_column( $column ) ? 0 : '';
				}
				$r[ $column ] = Backup::is_number_column( $column ) ? (int) $v : (string) $v;
			}
			if ( '' === $r['date'] || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $r['date'] ) ) {
				continue;
			}
			$assoc[] = $r;
		}
		if ( [] === $assoc ) {
			return 0;
		}

		if ( 'annotations' === $short ) {
			return $this->write_annotations( $table, $assoc );
		}

		// Text columns the table stores as ids.
		$ids = [];
		foreach ( $spec['lookup'] ?? [] as $column => [ $dict, $text ] ) {
			$values         = array_values( array_unique( array_column( $assoc, $column ) ) );
			$ids[ $column ] = ( new Upserter( $dict, $text ) )->upsert( $values );
		}

		$db_columns = [];
		foreach ( $spec['columns'] as $column ) {
			$db_columns[] = isset( $spec['lookup'][ $column ] ) ? $spec['lookup'][ $column ][2] : $column;
		}
		if ( '404_stats' === $short ) {
			$db_columns[] = 'entry_hash';
		}
		$key_columns = [];
		foreach ( $spec['keys'] as $key ) {
			$key_columns[] = isset( $spec['lookup'][ $key ] ) ? $spec['lookup'][ $key ][2] : $key;
		}
		if ( '404_stats' === $short ) {
			$key_columns = [ 'date', 'entry_hash' ];
		}
		$updates = [];
		foreach ( $db_columns as $column ) {
			if ( ! in_array( $column, $key_columns, true ) ) {
				$updates[] = "{$column} = VALUES({$column})";
			}
		}

		$written = 0;
		foreach ( array_chunk( $assoc, Backup::ROWS_PER_LINE ) as $batch ) {
			$values = [];
			$count  = 0;
			foreach ( $batch as $r ) {
				$tuple = [];
				$skip  = false;
				foreach ( $spec['columns'] as $column ) {
					if ( isset( $spec['lookup'][ $column ] ) ) {
						if ( ! isset( $ids[ $column ][ $r[ $column ] ] ) ) {
							$skip = true;
							break;
						}
						$tuple[] = (int) $ids[ $column ][ $r[ $column ] ];
						continue;
					}
					$tuple[] = $r[ $column ];
				}
				if ( $skip ) {
					continue;
				}
				if ( '404_stats' === $short ) {
					// The same key the 404 tracker and the other importers use.
					$tuple[] = md5( $r['path'] . "\0" . $r['referrer'] . "\0" . $r['internal_ref'] );
				}
				array_push( $values, ...$tuple );
				++$count;
			}
			if ( 0 === $count ) {
				continue;
			}

			$id_columns = array_column( $spec['lookup'] ?? [], 2 );
			$marks      = [];
			foreach ( $db_columns as $column ) {
				$marks[] = Backup::is_number_column( $column ) || in_array( $column, $id_columns, true ) ? '%d' : '%s';
			}
			$placeholders = rtrim( str_repeat( '(' . implode( ',', $marks ) . '),', $count ), ',' );
			$sql          = 'INSERT INTO %i (' . implode( ', ', $db_columns ) . ") VALUES {$placeholders}"
				. ( $updates ? ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ) : '' );

			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- bulk upsert; column names come from Backup::tables() and $placeholders from %s/%d markers only
			$wpdb->query( $wpdb->prepare( $sql, $table, ...$values ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$this->assert_no_db_error();
			$written += $count;
		}

		if ( 'site_stats' === $short ) {
			foreach ( $assoc as $r ) {
				if ( '' === $this->tally['first'] || $r['date'] < $this->tally['first'] ) {
					$this->tally['first'] = $r['date'];
				}
				if ( $r['date'] > $this->tally['last'] ) {
					$this->tally['last'] = $r['date'];
				}
				++$this->tally['days'];
				$this->tally['pageviews'] += (int) $r['pageviews'];
			}
		}

		return $written;
	}

	/**
	 * Notes have an auto-increment id and no unique key, so "the same note"
	 * is one on the same day with the same words. Those are left alone; the
	 * rest are added.
	 *
	 * @param array<int, array<string,mixed>> $rows
	 */
	private function write_annotations( string $table, array $rows ): int {
		global $wpdb;

		$dates = array_values( array_unique( array_column( $rows, 'date' ) ) );
		$in    = implode( ',', array_fill( 0, count( $dates ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is built from %s markers only
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT date, label FROM %i WHERE date IN ({$in})", $table, ...$dates ), ARRAY_A );
		$have     = [];
		foreach ( (array) $existing as $e ) {
			$have[ $e['date'] . "\0" . $e['label'] ] = true;
		}

		$written = 0;
		foreach ( $rows as $r ) {
			$k = $r['date'] . "\0" . $r['label'];
			if ( isset( $have[ $k ] ) || '' === trim( (string) $r['label'] ) ) {
				continue;
			}
			$have[ $k ] = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- import insert
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (date, label, created_by, created_at) VALUES (%s, %s, %d, %d)',
					$table,
					$r['date'],
					\VireoAnalytics\Collector::clip( sanitize_text_field( (string) $r['label'] ), 160 ),
					get_current_user_id(),
					(int) $r['created_at'] > 0 ? (int) $r['created_at'] : time()
				)
			);
			$this->assert_no_db_error();
			++$written;
		}
		return $written;
	}

	/**
	 * @return array{done:bool,processed:int,total:int,next_offset:int,label:string,error:string}
	 */
	private function envelope_error( string $message ): array {
		return [
			'done'        => true,
			'processed'   => 0,
			'total'       => 0,
			'next_offset' => 0,
			'label'       => '',
			'error'       => $message,
		];
	}
}
