<?php
/**
 * Everything the reports are built from, as one file.
 *
 * The CSV export answers "give me the pages for March". This answers "give me
 * all of it", so a site that moves hosts, is rebuilt from scratch, or wants a
 * copy somewhere safe keeps its history. Backup_Importer reads the file back
 * on the same install or another one.
 *
 * The file is JSON Lines, gzipped when the server can: a header line that says
 * what the file is and where it came from, then one line per batch of rows of
 * one table, then an end line so a cut-off upload is noticed. Rows that point
 * into the dictionary tables (paths, referrer hosts) carry the text, not the
 * id, since ids differ between installs. Working state (visitor hashes, the ip
 * cache) is not history and is left out.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Backup {

	public const FORMAT  = 'vireo-analytics-backup';
	public const VERSION = 1;
	public const ACTION  = 'vireo_export_backup';

	/** Rows per line in the file. Also the size of one INSERT on import. */
	public const ROWS_PER_LINE = 500;

	/** Rows read from a table per query while exporting. */
	private const PAGE = 5000;

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_export' ] );
	}

	public static function url(): string {
		return wp_nonce_url( add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * The tables in a backup.
	 *
	 * `columns` is the order the row's values are written in. `keys` are the
	 * columns that identify a row; on import the other columns are replaced,
	 * which is what makes loading a file twice give the same numbers. `lookup`
	 * names a text column that the table stores as an id into a dictionary
	 * table, as [ dictionary, its text column, the id column here ].
	 *
	 * @return array<string, array{columns: string[], keys: string[], lookup?: array<string, array{0:string,1:string,2:string}>}>
	 */
	public static function tables(): array {
		$path = [ 'path' => [ 'paths', 'path', 'path_id' ] ];
		$url  = [ 'url' => [ 'referrer_urls', 'url', 'url_id' ] ];

		return [
			'site_stats'          => [
				'columns' => [ 'date', 'pageviews', 'visitors' ],
				'keys'    => [ 'date' ],
			],
			'bounce_stats'        => [
				'columns' => [ 'date', 'visitors', 'multi_page' ],
				'keys'    => [ 'date' ],
			],
			'hour_stats'          => [
				'columns' => [ 'date', 'hour', 'pageviews', 'visitors' ],
				'keys'    => [ 'date', 'hour' ],
			],
			'post_stats'          => [
				'columns' => [ 'date', 'post_id', 'path', 'pageviews', 'visitors' ],
				'keys'    => [ 'date', 'post_id', 'path' ],
				'lookup'  => $path,
			],
			'page_time'           => [
				'columns' => [ 'date', 'path', 'samples', 'seconds' ],
				'keys'    => [ 'date', 'path' ],
				'lookup'  => $path,
			],
			'referrer_stats'      => [
				'columns' => [ 'date', 'url', 'pageviews', 'visitors' ],
				'keys'    => [ 'date', 'url' ],
				'lookup'  => $url,
			],
			'post_referrer_stats' => [
				'columns' => [ 'date', 'post_id', 'url', 'pageviews', 'visitors' ],
				'keys'    => [ 'date', 'post_id', 'url' ],
				'lookup'  => $url,
			],
			'geo_stats'           => [
				'columns' => [ 'date', 'country_code', 'pageviews', 'visitors' ],
				'keys'    => [ 'date', 'country_code' ],
			],
			'device_stats'        => [
				'columns' => [ 'date', 'browser', 'os', 'device_type', 'pageviews', 'visitors' ],
				'keys'    => [ 'date', 'browser', 'os', 'device_type' ],
			],
			'404_stats'           => [
				'columns' => [ 'date', 'path', 'referrer', 'internal_ref', 'hits' ],
				'keys'    => [ 'date', 'path', 'referrer', 'internal_ref' ],
			],
			'event_stats'         => [
				'columns' => [ 'date', 'event_name', 'event_value', 'count', 'unique_count' ],
				'keys'    => [ 'date', 'event_name', 'event_value' ],
			],
			'bot_stats'           => [
				'columns' => [ 'date', 'category', 'hits' ],
				'keys'    => [ 'date', 'category' ],
			],
			'vitals_stats'        => [
				'columns' => [ 'date', 'metric', 'bucket', 'samples' ],
				'keys'    => [ 'date', 'metric', 'bucket' ],
			],
			'annotations'         => [
				'columns' => [ 'date', 'label', 'created_at' ],
				'keys'    => [ 'date', 'label' ],
			],
			'gsc_stats'           => [
				'columns' => [ 'date', 'query_hash', 'query', 'clicks', 'impressions', 'position_x10' ],
				'keys'    => [ 'date', 'query_hash' ],
			],
			'gsc_pages'           => [
				'columns' => [ 'date', 'page_hash', 'query_hash', 'page', 'query', 'clicks', 'impressions', 'position_x10' ],
				'keys'    => [ 'date', 'page_hash', 'query_hash' ],
			],
		];
	}

	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'vireo-analytics' ), 403 );
		}
		check_admin_referer( self::ACTION );

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hosts that forbid it throw a warning, and the export then runs within the normal limit.
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Streamed through zlib when the server has it, which is nearly always.
		// A year of a busy site is tens of megabytes plain and a tenth of that
		// compressed, and the import has to fit under the upload limit.
		$gz   = function_exists( 'deflate_init' ) ? deflate_init( ZLIB_ENCODING_GZIP ) : false;
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name = self::filename( $host, gmdate( 'Y-m-d' ), false !== $gz );

		nocache_headers();
		header( 'Content-Type: ' . ( false !== $gz ? 'application/gzip' : 'application/x-ndjson; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the response
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( esc_html__( 'Failed to open output stream.', 'vireo-analytics' ), 500 );
		}

		$emit = static function ( string $line ) use ( $out, $gz ): void {
			fwrite( $out, false !== $gz ? deflate_add( $gz, $line, ZLIB_NO_FLUSH ) : $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		};

		self::write_all( $emit );

		if ( false !== $gz ) {
			fwrite( $out, deflate_add( $gz, '', ZLIB_FINISH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function filename( string $host, string $date, bool $gz ): string {
		$host = sanitize_file_name( '' === $host ? 'site' : $host );
		return 'vireo-analytics-backup-' . $host . '-' . $date . ( $gz ? '.jsonl.gz' : '.jsonl' );
	}

	/**
	 * The whole file, one line at a time, to $emit.
	 *
	 * Reads the site in context, which for this screen is the main site.
	 * Tables an older install has not created yet are left out and listed as
	 * absent in the header, so the importer knows the difference between
	 * "none" and "not in this file".
	 *
	 * @param callable(string):void $emit
	 */
	public static function write_all( callable $emit ): void {
		$present = [];
		foreach ( array_keys( self::tables() ) as $short ) {
			if ( self::table_exists( Sites::table( $short ) ) ) {
				$present[] = $short;
			}
		}

		$emit( self::line( self::header( $present ) ) );

		$rows_total = 0;
		foreach ( $present as $short ) {
			$spec = self::tables()[ $short ];
			foreach ( self::read_pages( $short, $spec ) as $rows ) {
				foreach ( array_chunk( $rows, self::ROWS_PER_LINE ) as $chunk ) {
					$rows_total += count( $chunk );
					$emit(
						self::line(
							[
								't' => $short,
								'c' => $spec['columns'],
								'r' => $chunk,
							]
						)
					);
				}
			}
		}

		$emit( self::line( [ 'end' => true, 'rows' => $rows_total ] ) );
	}

	/**
	 * @param string[] $present Tables found on this install.
	 * @return array<string,mixed>
	 */
	public static function header( array $present ): array {
		global $wpdb;

		$from = '';
		$to   = '';
		if ( in_array( 'site_stats', $present, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one read per export
			$bounds = $wpdb->get_row( $wpdb->prepare( 'SELECT MIN(date) AS first, MAX(date) AS last FROM %i', Sites::table( 'site_stats' ) ), ARRAY_A );
			$from   = (string) ( $bounds['first'] ?? '' );
			$to     = (string) ( $bounds['last'] ?? '' );
		}

		return [
			'format'   => self::FORMAT,
			'version'  => self::VERSION,
			'site'     => home_url(),
			'exported' => gmdate( 'c' ),
			'plugin'   => defined( 'VIREO_VERSION' ) ? VIREO_VERSION : '',
			'db'       => (string) get_option( 'vireo_db_version', '' ),
			'from'     => $from,
			'to'       => $to,
			'tables'   => $present,
		];
	}

	/**
	 * One JSON object per line. Slashes and unicode are left as they are so a
	 * path like /café/ reads back byte for byte.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function line( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/**
	 * Rows of one table in the file's column order, a page at a time.
	 *
	 * @param array{columns: string[], keys: string[], lookup?: array<string, array{0:string,1:string,2:string}>} $spec
	 * @return \Generator<int, array<int, array<int, mixed>>>
	 */
	private static function read_pages( string $short, array $spec ): \Generator {
		global $wpdb;

		$lookup = $spec['lookup'] ?? [];

		// Every identifier goes in as a %i placeholder; $args holds them in the
		// order they appear in the statement.
		$selects = [];
		$joins   = [];
		$order   = [];
		$select_args = [];
		$join_args   = [];
		$order_args  = [];
		$n           = 0;

		foreach ( $spec['columns'] as $column ) {
			if ( isset( $lookup[ $column ] ) ) {
				++$n;
				[ $dict, $text, $id ] = $lookup[ $column ];
				$selects[]            = "d{$n}.%i AS %i";
				array_push( $select_args, $text, $column );
				$joins[] = "INNER JOIN %i d{$n} ON d{$n}.id = s.%i";
				array_push( $join_args, Sites::table( $dict ), $id );
				continue;
			}
			$selects[]     = 's.%i';
			$select_args[] = $column;
		}
		foreach ( $spec['keys'] as $key ) {
			$order[]      = 's.%i';
			$order_args[] = isset( $lookup[ $key ] ) ? $lookup[ $key ][2] : $key;
		}

		$sql  = 'SELECT ' . implode( ', ', $selects ) . ' FROM %i s ' . implode( ' ', $joins )
			. ' ORDER BY ' . implode( ', ', $order ) . ' LIMIT %d OFFSET %d';
		$args = array_merge( $select_args, [ Sites::table( $short ) ], $join_args, $order_args );

		for ( $offset = 0; ; $offset += self::PAGE ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql is built above from %i/%d markers only and prepared here
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $args, [ self::PAGE, $offset ] ) ), ARRAY_N );
			$rows = is_array( $rows ) ? $rows : [];
			if ( [] === $rows ) {
				return;
			}
			yield array_map( [ self::class, 'typed_row' ], $rows, array_fill( 0, count( $rows ), $spec['columns'] ) );
			if ( count( $rows ) < self::PAGE ) {
				return;
			}
		}
	}

	/**
	 * Numbers as numbers in the file; everything else stays a string.
	 *
	 * @param array<int, mixed> $row
	 * @param string[]          $columns
	 * @return array<int, mixed>
	 */
	public static function typed_row( array $row, array $columns ): array {
		$out = [];
		foreach ( array_values( $row ) as $i => $value ) {
			$out[] = self::is_number_column( (string) ( $columns[ $i ] ?? '' ) ) ? (int) $value : (string) $value;
		}
		return $out;
	}

	public static function is_number_column( string $column ): bool {
		return in_array( $column, [ 'pageviews', 'visitors', 'multi_page', 'hour', 'post_id', 'samples', 'seconds', 'hits', 'count', 'unique_count', 'bucket', 'created_at', 'clicks', 'impressions', 'position_x10' ], true );
	}

	public static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
