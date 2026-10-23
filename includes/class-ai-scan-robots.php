<?php
/**
 * robots.txt parsing and matching for the AI visibility scan.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class AI_Scan_Robots {

	private const MAX_RULE_LENGTH = 512;
	private const MAX_RULES       = 2000;
	private const MAX_PATH_LENGTH = 2048;
	private const STEP_BUDGET     = 2000000;

	/** @var array<int,array{agents:array<int,string>,rules:array<int,array{allow:bool,path:string}>}> */
	private array $groups = [];

	/** @var array<int,string> */
	private array $sitemaps = [];

	private bool $truncated = false;

	public function __construct( string $text ) {
		$current        = null;
		$last_was_agent = false;
		$rule_count     = 0;

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $raw ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $raw ) );
			$idx  = strpos( $line, ':' );
			if ( '' === $line || false === $idx ) {
				continue;
			}
			$key   = strtolower( trim( substr( $line, 0, $idx ) ) );
			$value = trim( substr( $line, $idx + 1 ) );

			if ( 'user-agent' === $key ) {
				if ( null === $current || ! $last_was_agent ) {
					$this->groups[] = [
						'agents' => [],
						'rules'  => [],
					];
					$current        = count( $this->groups ) - 1;
				}
				$this->groups[ $current ]['agents'][] = strtolower( $value );
				$last_was_agent                       = true;
			} elseif ( 'allow' === $key || 'disallow' === $key ) {
				$last_was_agent = false;
				// An empty Disallow allows everything, so it adds no rule.
				if ( null === $current || ( 'disallow' === $key && '' === $value ) ) {
					continue;
				}
				if ( $rule_count >= self::MAX_RULES || strlen( $value ) > self::MAX_RULE_LENGTH ) {
					$this->truncated = true;
					continue;
				}
				$this->groups[ $current ]['rules'][] = [
					'allow' => 'allow' === $key,
					'path'  => self::normalize_path( $value ),
				];
				++$rule_count;
			} elseif ( 'sitemap' === $key ) {
				if ( '' !== $value ) {
					$this->sitemaps[] = $value;
				}
			} else {
				$last_was_agent = false;
			}
		}
	}

	/**
	 * @return array<int,string>
	 */
	public function sitemaps(): array {
		return $this->sitemaps;
	}

	/**
	 * True when rules were skipped for being too long or too many, so verdicts
	 * can't be trusted.
	 */
	public function is_truncated(): bool {
		return $this->truncated;
	}

	/**
	 * Verdicts for several crawlers under one shared step budget.
	 *
	 * @param array<int,string> $agents
	 * @return array<string,bool>|null Null when the file is truncated or too complex to evaluate.
	 */
	public function verdicts( array $agents, string $path ): ?array {
		if ( $this->truncated ) {
			return null;
		}
		$budget = self::STEP_BUDGET;
		$out    = [];
		try {
			foreach ( $agents as $agent ) {
				$out[ $agent ] = $this->is_allowed( $agent, $path, $budget );
			}
		} catch ( AI_Scan_Robots_Too_Complex $e ) {
			return null;
		}
		return $out;
	}

	/**
	 * Google-style matching: groups naming the crawler win over "*", and the
	 * longest matching pattern wins, with Allow winning a tie. $path includes
	 * the query string.
	 */
	public function is_allowed( string $agent, string $path, ?int &$budget = null ): bool {
		if ( null === $budget ) {
			$budget = self::STEP_BUDGET;
		}
		$path     = self::normalize_path( substr( $path, 0, self::MAX_PATH_LENGTH ) );
		$token    = strtolower( $agent );
		$specific = [];
		$wildcard = [];
		foreach ( $this->groups as $group ) {
			if ( in_array( $token, $group['agents'], true ) && '*' !== $token ) {
				$specific[] = $group;
			}
			if ( in_array( '*', $group['agents'], true ) ) {
				$wildcard[] = $group;
			}
		}

		$best = null;
		foreach ( ( $specific ? $specific : $wildcard ) as $group ) {
			foreach ( $group['rules'] as $rule ) {
				if ( ! self::pattern_matches( $rule['path'], $path, $budget ) ) {
					continue;
				}
				// A trailing "*" matches anything, so it adds no specificity.
				$len = strlen( rtrim( $rule['path'], '*' ) );
				if ( null === $best || $len > $best['len'] || ( $len === $best['len'] && $rule['allow'] ) ) {
					$best = [
						'len'   => $len,
						'allow' => $rule['allow'],
					];
				}
			}
		}

		return null === $best || $best['allow'];
	}

	/**
	 * Decodes percent-escapes of unreserved characters so "/%7Efoo" and "/~foo"
	 * compare equal; other escapes are uppercased.
	 */
	public static function normalize_path( string $path ): string {
		return (string) preg_replace_callback(
			'/%([0-9a-f]{2})/i',
			static function ( array $m ): string {
				$c = chr( (int) hexdec( $m[1] ) );
				return preg_match( '/[A-Za-z0-9\-._~]/', $c ) ? $c : strtoupper( $m[0] );
			},
			$path
		);
	}

	/**
	 * Wildcard match ("*" is any run, a trailing "$" anchors the end) that only
	 * backtracks to the most recent star, so hostile patterns stay linear and
	 * can't hit the PCRE backtrack limit. Each step spends one unit of $budget.
	 *
	 * @throws AI_Scan_Robots_Too_Complex When the budget runs out.
	 */
	public static function pattern_matches( string $pattern, string $path, int &$budget ): bool {
		$anchored = '' !== $pattern && '$' === substr( $pattern, -1 );
		$pat      = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$pat_len  = strlen( $pat );
		$path_len = strlen( $path );
		$p        = 0;
		$s        = 0;
		$star     = -1;
		$mark     = 0;
		while ( $s < $path_len ) {
			if ( --$budget < 0 ) {
				throw new AI_Scan_Robots_Too_Complex( 'robots.txt is too complex to evaluate' );
			}
			if ( $p < $pat_len && '*' === $pat[ $p ] ) {
				$star = $p++;
				$mark = $s;
			} elseif ( $p < $pat_len && $pat[ $p ] === $path[ $s ] ) {
				++$p;
				++$s;
			} elseif ( $p === $pat_len && ! $anchored ) {
				return true;
			} elseif ( -1 !== $star ) {
				$p = $star + 1;
				$s = ++$mark;
			} else {
				return false;
			}
		}
		while ( $p < $pat_len && '*' === $pat[ $p ] ) {
			++$p;
		}
		return $p === $pat_len;
	}
}
