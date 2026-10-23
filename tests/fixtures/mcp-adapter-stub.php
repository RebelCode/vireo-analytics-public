<?php
/**
 * The two adapter classes class-mcp.php touches, reduced to what the test
 * needs to observe: whether the plugin was booted, and what create_server
 * was handed.
 */

namespace WP\MCP\Core {
	class McpAdapter {
		public array $servers = [];
		public function create_server( ...$args ) {
			$this->servers[] = $args;
			return true;
		}
	}
}

namespace WP\MCP {
	class Plugin {
		public static int $booted = 0;
		public static function instance() {
			self::$booted++;
			return new self();
		}
	}
}
