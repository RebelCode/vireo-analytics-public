<?php
/**
 * Popular posts and per-post view counts for the front end.
 *
 * Read-only over the daily rollups: nothing here records anything. Results are
 * cached per query in a transient for an hour, so a sidebar list costs one
 * grouped query an hour rather than one per page view, and the HTML a page
 * cache stores is built from the same cached rows.
 *
 * The cached value is post IDs and counts only. Titles, links and thumbnails
 * come from WordPress at render time, so a renamed post or a new featured
 * image shows up immediately; only changes to which posts qualify (publish,
 * unpublish, password, delete) bump the generation and drop the cache.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Popular_Posts {

	public const PERIODS = [ '7', '30', '90', 'all' ];

	private const DISPLAYS   = [ 'list', 'thumbnails' ];
	private const MAX_NUMBER = 20;
	private const GEN_OPTION = 'vireo_popular_gen';
	private const STYLE      = 'vireo-popular-posts';
	private const CACHE_TTL  = HOUR_IN_SECONDS;

	/** @var array<string,int> Request-level memo for view counts. */
	private static array $views_memo = [];

	public static function boot(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'widgets_init', [ self::class, 'register_widget' ] );
		add_filter( 'widget_types_to_hide_from_legacy_widget_block', [ self::class, 'hide_legacy_widget' ] );

		add_action( 'transition_post_status', [ self::class, 'on_status_change' ], 10, 3 );
		add_action( 'before_delete_post', [ self::class, 'on_delete' ] );
		add_action( 'vireo_reset_rollups', [ self::class, 'bump_generation' ] );
	}

	public static function register(): void {
		wp_register_style( self::STYLE, VIREO_URL . 'assets/css/popular-posts.css', [], VIREO_VERSION );

		register_block_type(
			VIREO_DIR . 'blocks/popular-posts',
			[ 'render_callback' => [ self::class, 'render_block' ] ]
		);
		register_block_type(
			VIREO_DIR . 'blocks/post-views',
			[ 'render_callback' => [ self::class, 'render_views_block' ] ]
		);
		wp_set_script_translations( 'vireo-popular-posts-editor-script', 'vireo-analytics', VIREO_DIR . 'languages' );
		wp_set_script_translations( 'vireo-post-views-editor-script', 'vireo-analytics', VIREO_DIR . 'languages' );

		add_shortcode( 'vireo_popular_posts', [ self::class, 'shortcode' ] );
		add_shortcode( 'vireo_views', [ self::class, 'views_shortcode' ] );
	}

	public static function register_widget(): void {
		register_widget( Popular_Posts_Widget::class );
	}

	/**
	 * The block covers the block widget editor, so the classic widget only
	 * clutters the legacy-widget picker there. It stays available to classic
	 * widget screens.
	 *
	 * @param string[] $ids
	 * @return string[]
	 */
	public static function hide_legacy_widget( $ids ): array {
		$ids = (array) $ids;
		$ids[] = Popular_Posts_Widget::ID_BASE;
		return $ids;
	}

	// ---------------------------------------------------------------------
	// Pure helpers
	// ---------------------------------------------------------------------

	/**
	 * One shape for block attributes, shortcode attributes and widget
	 * settings. Anything unrecognised falls back to the default.
	 *
	 * @param array<string,mixed> $raw
	 * @param string[]            $allowed_types Post types a visitor may see listed.
	 * @return array{number:int,period:string,post_types:string[],display:string,show_views:bool,exclude_current:bool}
	 */
	public static function normalize_atts( array $raw, array $allowed_types ): array {
		$number = isset( $raw['number'] ) && is_numeric( $raw['number'] ) ? (int) $raw['number'] : 5;
		$number = max( 1, min( self::MAX_NUMBER, $number ) );

		$period = isset( $raw['period'] ) ? (string) $raw['period'] : '30';
		if ( ! in_array( $period, self::PERIODS, true ) ) {
			$period = '30';
		}

		$types = $raw['post_types'] ?? [ 'post' ];
		if ( is_string( $types ) ) {
			$types = explode( ',', $types );
		}
		$types = array_map( 'trim', array_map( 'strval', (array) $types ) );
		$types = array_values( array_unique( array_intersect( $types, $allowed_types ) ) );
		sort( $types );
		if ( empty( $types ) ) {
			$types = [ 'post' ];
		}

		$display = isset( $raw['display'] ) ? (string) $raw['display'] : 'list';
		if ( ! in_array( $display, self::DISPLAYS, true ) ) {
			$display = 'list';
		}

		return [
			'number'          => $number,
			'period'          => $period,
			'post_types'      => $types,
			'display'         => $display,
			'show_views'      => self::to_bool( $raw['show_views'] ?? false ),
			'exclude_current' => self::to_bool( $raw['exclude_current'] ?? true ),
		];
	}

	/**
	 * Inclusive [start, end] in site days, matching the dashboard's last_N
	 * presets. Null for all time.
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function period_range( string $period, string $today ): ?array {
		if ( 'all' === $period || ! in_array( $period, self::PERIODS, true ) ) {
			return null;
		}
		$end   = new \DateTimeImmutable( $today . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		$start = $end->modify( '-' . ( (int) $period - 1 ) . ' days' );
		return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
	}

	/**
	 * Display options don't change the query, so they share a cache entry.
	 * Today's date is part of the key for dated periods, which rolls the
	 * window forward at midnight without any invalidation.
	 *
	 * @param array<string,mixed> $atts Normalised attributes.
	 */
	public static function cache_key( array $atts, string $today, int $gen ): string {
		$types = (array) $atts['post_types'];
		sort( $types );
		$day = 'all' === $atts['period'] ? '' : $today;
		return 'vireo_pop_' . md5( implode( '|', [ (int) $atts['number'], (string) $atts['period'], implode( ',', $types ), $day, $gen ] ) );
	}

	/**
	 * One spare row, so excluding the post being viewed never leaves the list
	 * one short.
	 */
	public static function fetch_limit( int $number ): int {
		return $number + 1;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows post_id + views, any order.
	 * @return array<int,array{post_id:int,views:int}>
	 */
	public static function shape( array $rows, int $exclude_id, int $limit ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$id = (int) ( $row['post_id'] ?? 0 );
			if ( $id <= 0 || $id === $exclude_id ) {
				continue;
			}
			$out[] = [
				'post_id' => $id,
				'views'   => (int) ( $row['views'] ?? 0 ),
			];
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return [ $b['views'], $a['post_id'] ] <=> [ $a['views'], $b['post_id'] ];
			}
		);
		return array_slice( $out, 0, max( 0, $limit ) );
	}

	/**
	 * @param mixed $value
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	// ---------------------------------------------------------------------
	// Data
	// ---------------------------------------------------------------------

	/**
	 * Post types a visitor can see, which is the only set the list may draw
	 * from. A shortcode attribute must not be able to list titles from a
	 * private post type.
	 *
	 * @return string[]
	 */
	public static function allowed_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Most-viewed published posts for normalised attributes, before excluding
	 * the current post.
	 *
	 * @param array<string,mixed> $atts
	 * @return array<int,array{post_id:int,views:int}>
	 */
	public static function top_posts( array $atts ): array {
		$key    = self::cache_key( $atts, vireo_bucket_date(), (int) get_option( self::GEN_OPTION, 0 ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$types = (array) $atts['post_types'];
		$range = self::period_range( (string) $atts['period'], vireo_bucket_date() );

		// The front page and the posts page are site furniture, not posts
		// anyone would want listed as popular reading.
		$skip = [ 0 ];
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$skip[] = (int) get_option( 'page_on_front' );
			$skip[] = (int) get_option( 'page_for_posts' );
		}

		$type_ph  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$skip_ph  = implode( ',', array_fill( 0, count( $skip ), '%d' ) );
		$date_sql = null === $range ? '' : 'AND s.date BETWEEN %s AND %s';
		$params   = array_merge(
			[ Sites::table( 'post_stats' ), $wpdb->posts ],
			null === $range ? [] : $range,
			$types,
			$skip,
			[ self::fetch_limit( (int) $atts['number'] ) ]
		);

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated above from fixed %s/%d lists
		$sql = $wpdb->prepare(
			"SELECT s.post_id, SUM(s.pageviews) AS views
			 FROM %i s
			 INNER JOIN %i p ON p.ID = s.post_id
			 WHERE p.post_status = 'publish'
			   AND p.post_password = ''
			   {$date_sql}
			   AND p.post_type IN ({$type_ph})
			   AND s.post_id NOT IN ({$skip_ph})
			 GROUP BY s.post_id
			 ORDER BY views DESC, s.post_id ASC
			 LIMIT %d",
			...$params
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- transient-cached above; $sql is prepared
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		$out  = self::shape( $rows, 0, self::fetch_limit( (int) $atts['number'] ) );

		set_transient( $key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Pageviews for one post over a period ('all', '7', '30', '90').
	 */
	public static function views_for( int $post_id, string $period ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}
		if ( ! in_array( $period, self::PERIODS, true ) ) {
			$period = 'all';
		}
		$range = self::period_range( $period, vireo_bucket_date() );
		$key   = 'vireo_views_' . md5( $post_id . '|' . $period . '|' . ( null === $range ? '' : $range[1] ) );
		if ( isset( self::$views_memo[ $key ] ) ) {
			return self::$views_memo[ $key ];
		}
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			self::$views_memo[ $key ] = (int) $cached;
			return (int) $cached;
		}

		global $wpdb;
		$table = Sites::table( 'post_stats' );
		if ( null === $range ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above
			$views = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(pageviews),0) FROM %i WHERE post_id = %d', $table, $post_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transient-cached above
			$views = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(pageviews),0) FROM %i WHERE post_id = %d AND date BETWEEN %s AND %s', $table, $post_id, $range[0], $range[1] ) );
		}

		$views = (int) $views;
		set_transient( $key, $views, self::CACHE_TTL );
		self::$views_memo[ $key ] = $views;
		return $views;
	}

	// ---------------------------------------------------------------------
	// Cache invalidation
	// ---------------------------------------------------------------------

	public static function bump_generation(): void {
		update_option( self::GEN_OPTION, (int) get_option( self::GEN_OPTION, 0 ) + 1, true );
	}

	/**
	 * Fires on every save, not just status changes. Only saves that touch a
	 * published post can change who qualifies (password, type, unpublish).
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public static function on_status_change( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		self::bump_generation();
	}

	/**
	 * A forced delete skips the trash and with it the status transition.
	 *
	 * @param int $post_id
	 */
	public static function on_delete( $post_id ): void {
		if ( 'publish' === get_post_status( (int) $post_id ) ) {
			self::bump_generation();
		}
	}

	// ---------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $atts Normalised attributes.
	 * @return string HTML, or '' when there is nothing to list.
	 */
	public static function render( array $atts, string $wrapper_attrs = '' ): string {
		$current = 0;
		if ( $atts['exclude_current'] ) {
			// The editor preview has no queried object; the block renderer sets
			// up the post being edited as the global post instead.
			$current = is_singular() ? (int) get_queried_object_id() : ( wp_is_serving_rest_request() ? (int) get_the_ID() : 0 );
		}
		$rows = self::shape( self::top_posts( $atts ), $current, (int) $atts['number'] + 1 );

		$ids = array_column( $rows, 'post_id' );
		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, true );
		}

		// The cached list may predate an unpublish; check again before showing.
		$items = [];
		foreach ( $rows as $row ) {
			$post = get_post( $row['post_id'] );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password
				|| ! in_array( $post->post_type, (array) $atts['post_types'], true ) ) {
				continue;
			}
			$items[] = [ $post, $row['views'] ];
			if ( count( $items ) >= (int) $atts['number'] ) {
				break;
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		$images = 'thumbnails' === $atts['display'] ? self::item_images( array_column( $items, 0 ) ) : [];

		wp_enqueue_style( self::STYLE );

		$classes = 'vireo-popular-posts vireo-popular-posts--' . $atts['display'];
		$html    = '' !== $wrapper_attrs
			? '<div ' . $wrapper_attrs . '>'
			: '<div class="' . esc_attr( $classes ) . '">';
		$html   .= '<ul class="vireo-popular-posts__list">';

		foreach ( $items as [ $post, $views ] ) {
			$html .= '<li class="vireo-popular-posts__item">';
			$html .= '<a class="vireo-popular-posts__link" href="' . esc_url( (string) get_permalink( $post ) ) . '">';
			if ( ! empty( $images ) ) {
				// A post with no image keeps an empty, unpainted slot so every
				// title starts in the same column.
				$img   = $images[ $post->ID ];
				$html .= '' !== $img
					? '<span class="vireo-popular-posts__thumb">' . $img . '</span>'
					: '<span class="vireo-popular-posts__thumb vireo-popular-posts__thumb--none" aria-hidden="true"></span>';
			}
			$html .= '<span class="vireo-popular-posts__title">' . esc_html( get_the_title( $post ) ) . '</span>';
			$html .= '</a>';
			if ( $atts['show_views'] ) {
				$html .= ' <span class="vireo-popular-posts__views">' . esc_html( self::views_label( (int) $views ) ) . '</span>';
			}
			$html .= '</li>';
		}

		$html .= '</ul></div>';
		return $html;
	}

	/**
	 * Thumbnail markup per post: the featured image, else the first image
	 * from the media library in the post's content. Empty when no post in the
	 * list has either, so the list drops the image column altogether.
	 *
	 * @param \WP_Post[] $posts
	 * @return array<int,string> post ID => <img> markup or ''
	 */
	private static function item_images( array $posts ): array {
		$ids = [];
		foreach ( $posts as $post ) {
			$id               = (int) get_post_thumbnail_id( $post );
			$ids[ $post->ID ] = $id > 0 ? $id : self::first_content_image_id( (string) $post->post_content );
		}
		$attachment_ids = array_values( array_filter( $ids ) );
		if ( empty( $attachment_ids ) ) {
			return [];
		}
		_prime_post_caches( $attachment_ids, false, true );

		$out = [];
		foreach ( $ids as $post_id => $id ) {
			// The title sits right next to it inside the same link, so the
			// image is decorative and takes an empty alt. The box around it
			// sets the size: some themes put inline width/height styles on
			// attachment images, which a class rule can't beat.
			$out[ $post_id ] = $id > 0
				? wp_get_attachment_image(
					$id,
					'thumbnail',
					false,
					[
						'class'   => 'vireo-popular-posts__img',
						'alt'     => '',
						'loading' => 'lazy',
					]
				)
				: '';
		}
		return array_filter( $out ) ? $out : [];
	}

	/**
	 * Attachment ID of the first media-library image in post content, from
	 * the wp-image-N class both editors add. Images hosted elsewhere are left
	 * alone: their size is unknown and they'd be hotlinked.
	 */
	public static function first_content_image_id( string $content ): int {
		if ( '' === $content || false === strpos( $content, 'wp-image-' ) ) {
			return 0;
		}
		return preg_match( '/<img\b[^>]*\bclass="[^"]*\bwp-image-(\d+)\b/i', $content, $m ) ? (int) $m[1] : 0;
	}

	public static function views_label( int $views ): string {
		return sprintf(
			/* translators: %s: number of pageviews */
			_n( '%s view', '%s views', $views, 'vireo-analytics' ),
			number_format_i18n( $views )
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public static function render_block( array $attributes ): string {
		$atts = self::normalize_atts(
			[
				'number'          => $attributes['number'] ?? 5,
				'period'          => $attributes['period'] ?? '30',
				'post_types'      => $attributes['postTypes'] ?? [ 'post' ],
				'display'         => $attributes['display'] ?? 'list',
				'show_views'      => $attributes['showViews'] ?? false,
				'exclude_current' => $attributes['excludeCurrent'] ?? true,
			],
			self::allowed_types()
		);

		$html = self::render(
			$atts,
			get_block_wrapper_attributes( [ 'class' => 'vireo-popular-posts vireo-popular-posts--' . $atts['display'] ] )
		);

		// An empty block on the front end renders nothing; in the editor it
		// needs to say why it is empty.
		if ( '' === $html && wp_is_serving_rest_request() ) {
			return '<p>' . esc_html__( 'No popular posts yet. They appear here once Vireo has recorded views for published posts in this period.', 'vireo-analytics' ) . '</p>';
		}
		return $html;
	}

	/**
	 * [vireo_popular_posts number="5" period="30" post_types="post" display="list" show_views="no" exclude_current="yes"]
	 *
	 * @param array<string,string>|string $atts
	 */
	public static function shortcode( $atts ): string {
		return self::render( self::normalize_atts( is_array( $atts ) ? $atts : [], self::allowed_types() ) );
	}

	/**
	 * [vireo_views post_id="123" period="all"]. Defaults to the current post.
	 *
	 * @param array<string,string>|string $atts
	 */
	public static function views_shortcode( $atts ): string {
		$atts    = shortcode_atts(
			[
				'post_id' => 0,
				'period'  => 'all',
			],
			is_array( $atts ) ? $atts : [],
			'vireo_views'
		);
		$post_id = absint( $atts['post_id'] );
		if ( 0 === $post_id ) {
			$post_id = (int) get_the_ID();
		}
		if ( ! self::can_show_views( $post_id ) ) {
			return '';
		}
		return '<span class="vireo-views">' . esc_html( self::views_label( self::views_for( $post_id, (string) $atts['period'] ) ) ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $attributes
	 * @param string              $content
	 * @param \WP_Block|null      $block
	 */
	public static function render_views_block( array $attributes, $content = '', $block = null ): string {
		$post_id = (int) ( $attributes['postId'] ?? 0 );
		if ( 0 === $post_id && $block instanceof \WP_Block && isset( $block->context['postId'] ) ) {
			$post_id = (int) $block->context['postId'];
		}
		if ( 0 === $post_id ) {
			$post_id = (int) get_the_ID();
		}
		// The editor preview also covers drafts the author is working on.
		$preview = wp_is_serving_rest_request() && $post_id > 0 && current_user_can( 'edit_post', $post_id );
		if ( ! $preview && ! self::can_show_views( $post_id ) ) {
			return '';
		}
		$label = self::views_label( self::views_for( $post_id, (string) ( $attributes['period'] ?? 'all' ) ) );
		return '<p ' . get_block_wrapper_attributes( [ 'class' => 'vireo-views' ] ) . '>' . esc_html( $label ) . '</p>';
	}

	/**
	 * Counts are public only for posts the visitor could read anyway.
	 */
	private static function can_show_views( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$post = get_post( $post_id );
		return $post instanceof \WP_Post && is_post_publicly_viewable( $post ) && ! post_password_required( $post );
	}
}
