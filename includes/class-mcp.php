<?php
/**
 * MCP server.
 *
 * Serves Vireo's abilities over MCP at `/wp-json/vireo-analytics/mcp`, so an
 * agent can be pointed at the site once and then ask about traffic in plain
 * language. WordPress core ships the Abilities API from 6.9 but no MCP
 * transport, so the `wordpress/mcp-adapter` package is bundled to provide one.
 *
 * Two things about booting the adapter matter:
 *
 *   - Instantiating it also spins up its own default site-wide MCP server at
 *     `/wp-json/mcp/mcp-adapter-default-server`, exposing every ability marked
 *     `mcp.public`, unless `mcp_adapter_create_default_server` is filtered
 *     false. A site owner who installs an analytics plugin has not asked for a
 *     general-purpose MCP endpoint over their whole site, so we turn that off,
 *     but only when no other plugin uses the adapter. Other plugins rely on
 *     that server: Rank Math serves its abilities through it and nothing else.
 *     That filter is global, so suppressing it while another plugin has the
 *     adapter takes their MCP endpoint away (0.12.4 did this to Rank Math).
 *   - Which plugins use the adapter isn't known until every one of them has
 *     loaded. Rank Math calls `McpAdapter::instance()` directly from its main
 *     file, which fires no action, so the only trace is the adapter's own
 *     callback on `rest_api_init`. We decide just before the adapter
 *     initialises, at priority 14 of the hook it initialises on, and leave
 *     everything alone if anyone else got there first. We use whichever copy
 *     of the adapter loaded first, too: ours is only required on
 *     `plugins_loaded`, after every plugin's main file has run.
 *   - Servers can only be created during `mcp_adapter_init`. Creating one
 *     outside that action is a hard error in the adapter.
 *
 * Pro used to run this server itself. It now detects this class, skips its own
 * boot, and adds its tool names through the `vireo_mcp_tools` filter, which is
 * applied at server-registration time (on `init`, after every plugin has
 * loaded). The server id, namespace and route are unchanged, so a client
 * configured against the Pro endpoint keeps working.
 *
 * This file is required at plugin-file load, not inside vireo_init(): Pro's
 * loader runs on `plugins_loaded` too, and the order plugins load in is not
 * ours to pick, so `class_exists( '\VireoAnalytics\Mcp' )` has to be true the
 * moment any plugin file has run.
 */

declare( strict_types=1 );

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class Mcp {

	public const SERVER_ID = 'vireo-analytics';
	public const NAMESPACE = 'vireo-analytics';
	public const ROUTE     = 'mcp';

	/** Whether this class was the one to boot the adapter. */
	private static bool $we_booted_adapter = false;

	public static function boot(): void {
		if ( ! self::load_adapter() ) {
			return;
		}

		add_action( 'mcp_adapter_init', [ __CLASS__, 'register_server' ] );
		add_filter( 'mcp_adapter_tool_call_result', [ __CLASS__, 'wrap_list_result' ], 10, 5 );

		// The adapter initialises on rest_api_init at 15, or on init at 20
		// under WP-CLI. Decide one priority earlier, once every other plugin
		// has had its chance to boot it.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'init', [ __CLASS__, 'maybe_boot_adapter' ], 19 );
		} else {
			add_action( 'rest_api_init', [ __CLASS__, 'maybe_boot_adapter' ], 14 );
		}
	}

	/**
	 * Boot the adapter if no other plugin has, and only then suppress its
	 * default server.
	 */
	public static function maybe_boot_adapter(): void {
		if ( self::$we_booted_adapter || self::adapter_booted() ) {
			return;
		}

		self::$we_booted_adapter = true;
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );
		\WP\MCP\Plugin::instance();
	}

	/** Whether the server can run on this install (the adapter is loadable). */
	public static function available(): bool {
		return class_exists( '\WP\MCP\Core\McpAdapter' ) || is_file( self::autoload_path() );
	}

	/** The endpoint an MCP client connects to. */
	public static function endpoint_url(): string {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}

	public static function we_booted_adapter(): bool {
		return self::$we_booted_adapter;
	}

	private static function autoload_path(): string {
		return ( defined( 'VIREO_DIR' ) ? VIREO_DIR : dirname( __DIR__ ) . '/' ) . 'vendor/autoload.php';
	}

	/**
	 * Load the bundled adapter unless something already provides it. Sites
	 * running the standalone MCP Adapter plugin, or another plugin that bundles
	 * it, keep whichever copy loaded first; two autoloaders declaring the same
	 * classes would fatal.
	 */
	private static function load_adapter(): bool {
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return true;
		}

		$autoload = self::autoload_path();
		if ( ! is_file( $autoload ) ) {
			return false;
		}
		require_once $autoload;

		return class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	/**
	 * Has anything already instantiated the adapter? The adapter's plugin build
	 * fires `wp_mcp_init`, but a plugin calling `McpAdapter::instance()` itself
	 * fires nothing before the adapter initialises. What it does do is hook
	 * the adapter's init onto `rest_api_init` (or `init` under WP-CLI).
	 */
	private static function adapter_booted(): bool {
		if ( did_action( 'mcp_adapter_init' ) > 0 || did_action( 'wp_mcp_init' ) > 0 ) {
			return true;
		}

		global $wp_filter;
		foreach ( [ 'rest_api_init', 'init' ] as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( is_array( $function ) && $function[0] instanceof \WP\MCP\Core\McpAdapter ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Create the Vireo MCP server. Only abilities that actually registered are
	 * offered: the goals and revenue tools are Pro-gated, and advertising a
	 * tool that then refuses every call is worse than not listing it.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter
	 */
	public static function register_server( $adapter ): void {
		$tools = self::available_tools();
		if ( empty( $tools ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE,
			self::ROUTE,
			__( 'Vireo Analytics', 'vireo-analytics' ),
			self::description(),
			defined( 'VIREO_VERSION' ) ? VIREO_VERSION : '1.0.0',
			[ \WP\MCP\Transport\HttpTransport::class ],
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$tools,
			[],
			[],
			[ __CLASS__, 'transport_permission_check' ]
		);
	}

	/**
	 * What the server tells a client it can answer. Pro appends a sentence
	 * about revenue, campaigns and funnels.
	 */
	public static function description(): string {
		$description = __( 'Read-only site analytics: traffic, top content, where visitors came from, Google searches, AI assistant traffic, broken links, and what changed since the previous period.', 'vireo-analytics' );

		// Without Pro, say where the money questions are answered, so an agent
		// asked "which pages make me money?" can point there instead of
		// guessing from pageviews. With Pro active its own sentence takes this
		// place through the filter below, so the two never both appear.
		if ( ! apply_filters( 'vireo_pro_is_active', false ) ) {
			$description .= ' ' . __( 'Revenue, which pages and sources drive sales, campaigns, funnels and real-time visitors need Vireo Pro: https://vireowp.com/pricing/', 'vireo-analytics' );
		}

		/**
		 * Filter the server description an MCP client receives as its instructions.
		 *
		 * @param string $description
		 */
		return (string) apply_filters( 'vireo_mcp_server_description', $description );
	}

	/**
	 * The abilities the free plugin offers as MCP tools, before filtering.
	 *
	 * @return array<int,string>
	 */
	public static function free_tools(): array {
		$tools = [
			'vireo-analytics/get-totals',
			'vireo-analytics/get-daily-stats',
			'vireo-analytics/get-hourly-stats',
			'vireo-analytics/get-changes',
			'vireo-analytics/get-highlights',
			'vireo-analytics/get-top-pages',
			'vireo-analytics/get-top-referrers',
			'vireo-analytics/get-traffic-sources',
			'vireo-analytics/get-referrer-detail',
			'vireo-analytics/get-ai-referrals',
			'vireo-analytics/get-search-console',
			'vireo-analytics/get-countries',
			'vireo-analytics/get-devices',
			'vireo-analytics/get-events',
			'vireo-analytics/get-event-detail',
			'vireo-analytics/get-post-stats',
			'vireo-analytics/get-404s',
			'vireo-analytics/get-bot-stats',
			'vireo-analytics/get-vitals',
			'vireo-analytics/get-annotations',
			'vireo-analytics/get-year-review',
		];

		// Goals registers on every site so the REST route can 403 honestly, but
		// a tool that refuses every call has no place in the list.
		if ( apply_filters( 'vireo_pro_is_active', false ) ) {
			$tools[] = 'vireo-analytics/get-goals';
		}

		return $tools;
	}

	/**
	 * The abilities to expose as MCP tools, filtered to those actually
	 * registered on this site.
	 *
	 * @return array<int,string>
	 */
	public static function available_tools(): array {
		/**
		 * Filter the abilities Vireo exposes over MCP. Pro adds its revenue
		 * tools here.
		 *
		 * @param array<int,string> $candidates Ability names.
		 */
		$candidates = (array) apply_filters( 'vireo_mcp_tools', self::free_tools() );

		if ( ! function_exists( 'wp_get_ability' ) && ! function_exists( 'wp_has_ability' ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					$candidates,
					static function ( $name ): bool {
						return is_string( $name ) && self::ability_registered( $name );
					}
				)
			)
		);
	}

	/**
	 * wp_get_ability() raises a _doing_it_wrong notice for a name it does not
	 * know, on every MCP request. wp_has_ability() asks without the notice.
	 */
	private static function ability_registered( string $name ): bool {
		if ( function_exists( 'wp_has_ability' ) ) {
			return (bool) wp_has_ability( $name );
		}
		return null !== wp_get_ability( $name );
	}

	/**
	 * Hand MCP clients an object, never a bare list.
	 *
	 * The adapter copies whatever an ability returns into the tool result's
	 * `structuredContent`, and the MCP schema says that field is an object. A
	 * client built on the official SDK (Claude Code, Claude Desktop, Cursor)
	 * rejects the whole response when it is a list: "expected record, received
	 * array". Free's own abilities already return objects (Abilities::as_object),
	 * so this is for Pro's tools and anything else on the server, wrapped as
	 * `{ "rows": [...] }`. Only adapter 0.5.0 and later run this filter; a site
	 * where WooCommerce's bundled 0.3.0 loads first gets no help here, which is
	 * why the abilities do it themselves.
	 *
	 * @param mixed                       $result
	 * @param array<string,mixed>         $args
	 * @param string                      $tool_name
	 * @param \WP\MCP\Domain\Tools\McpTool $tool
	 * @param \WP\MCP\Core\McpServer      $server
	 * @return mixed
	 */
	public static function wrap_list_result( $result, $args = [], $tool_name = '', $tool = null, $server = null ) {
		if ( null !== $server && method_exists( $server, 'get_server_id' ) && self::SERVER_ID !== $server->get_server_id() ) {
			return $result;
		}
		if ( ! is_array( $result ) || ! self::is_list( $result ) ) {
			return $result;
		}
		return [ 'rows' => $result ];
	}

	/**
	 * array_is_list() for PHP 7.4: an empty array counts as a list, which is
	 * what an ability with nothing to report returns.
	 *
	 * @param array<mixed> $value
	 */
	private static function is_list( array $value ): bool {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Gate the transport itself, not just the individual tools.
	 *
	 * The adapter's default is `is_user_logged_in()`, which on a site with open
	 * registration or a membership plugin would hand every subscriber a live
	 * MCP endpoint onto the site's traffic. Each ability re-checks its own
	 * permission, so this is defence in depth rather than the only gate, but an
	 * endpoint that lists the tools at all is already telling the caller what
	 * the site measures.
	 */
	public static function transport_permission_check(): bool {
		return current_user_can( Installer::CAPABILITY );
	}
}
