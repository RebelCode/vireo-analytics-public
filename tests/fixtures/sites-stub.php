<?php
/**
 * Sites::table() without WordPress, for the zero-dependency filter tests:
 * the table prefix is all they look at.
 */

namespace VireoAnalytics;

final class Sites {
	public static function table( string $name, int $site = 0 ): string {
		return 'wp_va_' . $name;
	}
}
