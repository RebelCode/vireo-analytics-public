<?php
/**
 * Vireo Analytics — goal matcher.
 *
 * Rules live in the `vireo_goals` option as an array of rule rows, written
 * whenever the admin saves a goal (Pro). Empty array => no-op; this function
 * is cheap to call even with zero goals.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'vireo_match_goals' ) ) {
	/**
	 * Match $event against goal rules and append a goal-completion
	 * line per match. Called after the underlying hit (pageview/event/outbound)
	 * is already buffered.
	 *
	 * @param array{kind:string,ts:int,visitor_hash:string,path?:string,name?:string,host?:string} $event
	 */
	function vireo_match_goals( array $event ): void {
		static $rules_cache = null;

		if ( null === $rules_cache ) {
			$rules_cache = (array) get_option( 'vireo_goals', [] );
		}

		if ( empty( $rules_cache ) ) {
			return;
		}

		foreach ( $rules_cache as $rule ) {
			$rtype = (string) ( $rule['type'] ?? '' );
			$rpat  = (string) ( $rule['pattern'] ?? '' );
			$rval  = (float) ( $rule['value'] ?? 0 );
			$rid   = (int) ( $rule['id'] ?? 0 );

			if ( $rid <= 0 ) {
				continue;
			}

			$match = false;
			if ( 'page_visit' === $rtype && 'page' === $event['kind'] ) {
				$path  = (string) ( $event['path'] ?? '' );
				$match = '' !== $path && '' !== $rpat && vireo_goal_glob( $rpat, $path );
			} elseif ( 'event' === $rtype && 'event' === $event['kind'] ) {
				$name  = (string) ( $event['name'] ?? '' );
				$match = '' !== $name && $name === $rpat;
			} elseif ( 'outbound_click' === $rtype && 'outbound' === $event['kind'] ) {
				$host = (string) ( $event['host'] ?? '' );
				$path = (string) ( $event['path'] ?? '' );
				if ( '' !== $rpat && '' !== $host ) {
					$candidate = ( false !== strpos( $rpat, '/' ) ) ? $host . $path : $host;
					$match     = vireo_goal_glob( $rpat, $candidate );
				}
			}

			if ( $match ) {
				\VireoAnalytics\Collector::append_goal_completion(
					[
						'ts'           => (int) ( $event['ts'] ?? time() ),
						'goal_id'      => $rid,
						'visitor_hash' => (string) ( $event['visitor_hash'] ?? '' ),
						'value'        => $rval,
					]
				);
			}
		}
	}
}

if ( ! function_exists( 'vireo_goal_glob' ) ) {
	/**
	 * Glob match for goal patterns. `*` = any chars except `/`, `**` = any
	 * chars, `?` = single char. Anchored. Case-insensitive.
	 */
	function vireo_goal_glob( string $pattern, string $subject ): bool {
		if ( '' === $pattern ) {
			return false;
		}
		if ( $pattern === $subject ) {
			return true;
		}
		$regex    = '';
		$segments = preg_split( '/(\*\*|\*|\?)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( (array) $segments as $seg ) {
			if ( '**' === $seg ) {
				$regex .= '.*';
			} elseif ( '*' === $seg ) {
				$regex .= '[^/]*';
			} elseif ( '?' === $seg ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $seg, '~' );
			}
		}
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- malformed user pattern is recoverable; treat as no match
		return (bool) @preg_match( '~^' . $regex . '$~i', $subject );
	}
}
