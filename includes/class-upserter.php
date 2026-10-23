<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Helper for our two "dictionary" tables (va_paths, va_referrer_urls) where we
 * store unique string values and reference them by numeric id everywhere else.
 *
 * Given a list of strings, guarantee each exists in the backing table and
 * return a { value => id } map so the caller can substitute ids into a
 * downstream INSERT.
 *
 * Two-query strategy: INSERT IGNORE the whole batch, then SELECT all rows that
 * match. The SELECT is required because INSERT IGNORE doesn't tell us which
 * rows were new vs. existing, and LAST_INSERT_ID() only covers one of them.
 */
final class Upserter {

	/** Values per statement, so a day with thousands of new paths stays under max_allowed_packet. */
	private const BATCH = 500;

	private string $full_table;
	private string $value_column;

	public function __construct( string $table, string $column ) {
		global $wpdb;
		$this->full_table   = Sites::table( $table );
		$this->value_column = $column;
	}

	/**
	 * @param string[] $values Strings to ensure exist in the table.
	 * @return array<string,int> Map keyed by value, whose int is the row id.
	 */
	public function upsert( array $values ): array {
		if ( [] === $values ) {
			return [];
		}

		// Drop duplicates up-front. Reduces wire size and sidesteps the unique
		// index complaining about repeated values in the same statement.
		$unique = array_values( array_unique( $values ) );

		$map = [];
		foreach ( array_chunk( $unique, self::BATCH ) as $batch ) {
			$count = count( $batch );
			$this->insert_ignore( $batch, $count );
			$map += $this->fetch_ids( $batch, $count );
		}
		return $map;
	}

	private function insert_ignore( array $values, int $count ): void {
		global $wpdb;
		// $row_placeholders is composed from literal placeholder tuples; %i for
		// table + column name completes the prepare() contract.
		$row_placeholders = implode( ',', array_fill( 0, $count, '(%s)' ) );
		$sql              = "INSERT IGNORE INTO %i (%i) VALUES {$row_placeholders}";

		// SQL string is composed from literal placeholders (`(%s)` + `%i`), safe
		// for $wpdb->prepare(). Disable the sniff across the multi-line call.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				array_merge( [ $this->full_table, $this->value_column ], $values )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * @return array<string,int>
	 */
	private function fetch_ids( array $values, int $count ): array {
		global $wpdb;
		// Same safety argument as insert_ignore: composed from literals.
		$in_placeholders = implode( ',', array_fill( 0, $count, '%s' ) );
		$sql             = "SELECT %i AS v, id FROM %i WHERE %i IN ({$in_placeholders})";

		// Same safety rationale as insert_ignore.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				array_merge( [ $this->value_column, $this->full_table, $this->value_column ], $values )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$map = [];
		foreach ( $rows as $row ) {
			$map[ (string) $row['v'] ] = (int) $row['id'];
		}
		return $map;
	}
}
