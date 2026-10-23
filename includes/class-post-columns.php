<?php
/**
 * "Pageviews (30d)" column on the posts + pages list tables.
 *
 * Loads 30-day totals in a single batched query keyed on the visible posts,
 * so opening the list table stays cheap regardless of post count.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Post_Columns {

	private static ?self $instance = null;

	/** @var array<int,int>|null post_id => pageviews (30d) */
	private ?array $pageview_cache = null;

	/** @var array<int,int> post_id => approved comments (30d) */
	private array $comment_cache = [];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'manage_posts_columns', [ $this, 'add_column' ] );
		add_filter( 'manage_pages_columns', [ $this, 'add_column' ] );
		add_action( 'manage_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_action( 'manage_pages_custom_column', [ $this, 'render_column' ], 10, 2 );

		// Register the column as sortable for every public post type that uses
		// the posts list table. Hooking on `admin_init` lets us pick up custom
		// post types registered by themes/plugins too.
		add_action( 'admin_init', [ $this, 'register_sortable_columns' ] );

		// Translate `orderby=va_pageviews` into a SQL ORDER BY against the
		// 30-day totals cached in wp_la_post_stats.
		add_filter( 'posts_clauses', [ $this, 'apply_pageviews_order' ], 10, 2 );

		add_action( 'admin_enqueue_scripts', [ $this, 'column_styles' ] );
	}

	public function column_styles( $hook ): void {
		if ( 'edit.php' === $hook ) {
			wp_add_inline_style( 'list-tables', '.column-va_views .va-col-sub{display:block;color:#646970;font-size:12px;line-height:1.5}' );
		}
	}

	/**
	 * Register `va_views` as a sortable column on every list table that shows
	 * it. Hooked on admin_init so custom post types (which are registered
	 * after plugins_loaded) are covered too.
	 */
	public function register_sortable_columns(): void {
		foreach ( get_post_types( [ 'show_ui' => true ], 'names' ) as $pt ) {
			add_filter( "manage_edit-{$pt}_sortable_columns", [ $this, 'mark_sortable' ] );
		}
	}

	/**
	 * @param array<string,string|array{0:string,1:bool}> $sortable
	 * @return array<string,string|array{0:string,1:bool}>
	 */
	public function mark_sortable( $sortable ): array {
		$sortable = (array) $sortable;
		$sortable['va_views'] = 'va_pageviews';
		return $sortable;
	}

	/**
	 * When the user clicks the "Views (30d)" column header, join against
	 * va_post_stats and order by summed pageviews. Runs in admin posts list
	 * only, and only when `orderby=va_pageviews`.
	 *
	 * @param array<string,string> $clauses
	 * @param \WP_Query            $query
	 * @return array<string,string>
	 */
	public function apply_pageviews_order( $clauses, $query ): array {
		$clauses = (array) $clauses;
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return $clauses;
		}
		$orderby = $query->get( 'orderby' );
		if ( 'va_pageviews' !== $orderby ) {
			return $clauses;
		}

		global $wpdb;
		$order = strtoupper( (string) $query->get( 'order' ) );
		$order = 'ASC' === $order ? 'ASC' : 'DESC';

		$stats = Sites::table( 'post_stats' );
		$start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// LEFT JOIN so posts with zero pageviews still appear (sorted last in DESC).
		// COALESCE turns NULL totals into 0 for stable ordering.
		$join_sql = $wpdb->prepare(
			' LEFT JOIN (SELECT post_id, SUM(pageviews) AS va_total
			             FROM %i
			             WHERE date >= %s
			             GROUP BY post_id) va_totals ON va_totals.post_id = ' . $wpdb->posts . '.ID',
			$stats,
			$start
		);

		$clauses['join']    = (string) $clauses['join'] . $join_sql;
		$clauses['orderby'] = 'COALESCE(va_totals.va_total, 0) ' . $order . ', ' . $wpdb->posts . '.ID ' . $order;
		return $clauses;
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( $columns ): array {
		$columns = (array) $columns;
		$columns['va_views'] = __( 'Views (30d)', 'vireo-analytics' );
		return $columns;
	}

	public function render_column( $column, $post_id ): void {
		if ( 'va_views' !== $column ) {
			return;
		}
		$post_id = (int) $post_id;
		$views = $this->get_views( $post_id );
		echo esc_html( number_format_i18n( $views ) );

		// WordPress's own column counts comments for all time; this one is
		// the same 30 days as the views, so the two can be compared.
		$comments = $this->comment_cache[ $post_id ] ?? 0;
		if ( $comments > 0 ) {
			// Named as the same 30 days, so it isn't read as the all-time count.
			/* translators: %s: comment count with its noun, e.g. "3 comments" */
			$sub  = sprintf( __( '%s in 30 days', 'vireo-analytics' ), Comment_Stats::comments_label( $comments ) );
			$rate = Comment_Stats::rate( $comments, $views );
			if ( null !== $rate ) {
				$sub .= ' · ' . Comment_Stats::rate_label( $rate );
			}
			echo '<span class="va-col-sub">' . esc_html( $sub ) . '</span>';
		}
	}

	private function get_views( int $post_id ): int {
		if ( null === $this->pageview_cache ) {
			$this->load_cache();
		}
		return $this->pageview_cache[ $post_id ] ?? 0;
	}

	/**
	 * Batch-load 30-day pageviews for every post in the current query.
	 * Groups by post_id so posts that accumulate rows under multiple paths
	 * (e.g. post slug changed) still show a single total.
	 */
	private function load_cache(): void {
		global $wpdb, $wp_query;
		$this->pageview_cache = [];

		$post_ids = [];
		if ( ! empty( $wp_query->posts ) ) {
			$post_ids = wp_list_pluck( $wp_query->posts, 'ID' );
		}
		if ( empty( $post_ids ) ) {
			return;
		}

		$post_ids = array_map( 'intval', (array) $post_ids );
		$table    = Sites::table( 'post_stats' );
		$start    = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is %d-only, generated above; sniffer can't trace %i + spread
		$sql = $wpdb->prepare(
			"SELECT post_id, SUM(pageviews) AS total
			 FROM %i
			 WHERE date >= %s AND post_id IN ({$placeholders})
			 GROUP BY post_id",
			$table,
			$start,
			...$post_ids
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the output of $wpdb->prepare() above
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			$this->pageview_cache[ (int) $row->post_id ] = (int) $row->total;
		}

		$this->comment_cache = Comment_Stats::counts( $post_ids, $start, vireo_bucket_date() );
	}
}
