<?php
/**
 * Stands in for Collector in tests/test-ai-scan-self-hit.php: records the
 * category of every crawler the plugin asks it to append.
 */

namespace VireoAnalytics;

final class Collector {
	public static array $bots = [];

	public static function append_bot( array $bot ): bool {
		self::$bots[] = $bot['category'];
		return true;
	}
}
