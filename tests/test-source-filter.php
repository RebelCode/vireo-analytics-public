<?php
/**
 * Zero-dependency assertion runner for the source filter patterns.
 * Run: php tests/test-source-filter.php
 *
 * The REGEXP patterns reach MySQL as bound arguments, so the regex engine sees
 * exactly the argument's characters. This test parses each bucket's actual SQL
 * fragment and evaluates it per host, with preg_match() standing in for
 * REGEXP, and it must put every host in the bucket the PHP classifier does. A
 * pattern escaped one level too deep (a literal backslash before the dot)
 * matches nothing and fails here.
 */

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { return $value; }
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../includes/va-filters.php';
require __DIR__ . '/../includes/class-filters.php';
require __DIR__ . '/../includes/class-ai-sources.php';

use VireoAnalytics\Filters;

$fail = 0;
function ok( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $msg\n";
	} else {
		echo "FAIL: $msg\n";
		$fail++;
	}
}

// Whether MySQL would select $host for a bucket, found by parsing the
// fragment source_where() builds and evaluating it against its bound arguments.
// The grammar is the little source_where() emits: parentheses, AND, OR, 0=1,
// and `u.url [NOT] REGEXP %s` / `u.url [NOT] IN (%s, ...)`.
function sql_selects( string $bucket, string $host ): bool {
	$f         = new Filters();
	$f->source = $bucket;
	[ $sql, $args ] = $f->source_where( 'u.url' );
	if ( '' === $sql ) {
		return true; // No fragment means no filter: every row comes back.
	}
	return sql_compile( $sql, $args )( $host );
}

// The bucket the dashboard's group-total query files $host under, by
// evaluating the CASE expression it groups by.
function sql_case_bucket( string $host ): string {
	[ $sql, $args ] = Filters::bucket_case( 'u.url' );
	return sql_compile( $sql, $args )( $host );
}

// Parses either a source_where() fragment (`AND <expr>`) or the bucket CASE
// (`CASE WHEN <expr> THEN %s ... ELSE %s END`) into a closure over a host.
function sql_compile( string $sql, array $args ): callable {
	preg_match_all( '/\(|\)|,|%s|0=1|u\.url|NOT|IN|REGEXP|AND|OR|CASE|WHEN|THEN|ELSE|END/', $sql, $m );
	$tokens = $m[0];
	$pos    = 0;
	$arg    = 0;

	$peek = static function () use ( &$tokens, &$pos ) { return $tokens[ $pos ] ?? null; };
	$take = static function ( ?string $want = null ) use ( &$tokens, &$pos ) {
		$t = $tokens[ $pos++ ] ?? null;
		if ( null !== $want && $t !== $want ) {
			throw new RuntimeException( "expected $want, got $t" );
		}
		return $t;
	};

	// Builds closures so every placeholder is consumed in textual order, however
	// the evaluation later short-circuits.
	$expr = null;
	$factor = static function () use ( &$expr, $peek, $take, &$args, &$arg ) {
		if ( '(' === $peek() ) {
			$take( '(' );
			$e = $expr();
			$take( ')' );
			return $e;
		}
		if ( '0=1' === $peek() ) {
			$take();
			return static fn( string $h ): bool => false;
		}
		$take( 'u.url' );
		$not = false;
		if ( 'NOT' === $peek() ) {
			$take();
			$not = true;
		}
		if ( 'REGEXP' === $peek() ) {
			$take();
			$take( '%s' );
			$re = (string) $args[ $arg++ ];
			return static fn( string $h ): bool => $not !== ( 1 === preg_match( '~' . $re . '~i', $h ) );
		}
		$take( 'IN' );
		$take( '(' );
		$list = [];
		do {
			$take( '%s' );
			$list[] = strtolower( (string) $args[ $arg++ ] );
		} while ( ',' === $peek() && $take( ',' ) );
		$take( ')' );
		return static fn( string $h ): bool => $not !== in_array( strtolower( $h ), $list, true );
	};
	$term = static function () use ( $factor, $peek, $take ) {
		$parts = [ $factor() ];
		while ( 'AND' === $peek() ) {
			$take();
			$parts[] = $factor();
		}
		return static fn( string $h ): bool => array_reduce( $parts, static fn( $c, $p ) => $c && $p( $h ), true );
	};
	$expr = static function () use ( $term, $peek, $take ) {
		$parts = [ $term() ];
		while ( 'OR' === $peek() ) {
			$take();
			$parts[] = $term();
		}
		return static fn( string $h ): bool => array_reduce( $parts, static fn( $c, $p ) => $c || $p( $h ), false );
	};

	if ( 'CASE' === $peek() ) {
		$take();
		$arms = [];
		while ( 'WHEN' === $peek() ) {
			$take();
			$cond = $expr();
			$take( 'THEN' );
			$take( '%s' );
			$arms[] = [ $cond, (string) $args[ $arg++ ] ];
		}
		$take( 'ELSE' );
		$take( '%s' );
		$else = (string) $args[ $arg++ ];
		$take( 'END' );
		$eval = static function ( string $h ) use ( $arms, $else ): string {
			foreach ( $arms as [ $cond, $label ] ) {
				if ( $cond( $h ) ) {
					return $label;
				}
			}
			return $else;
		};
	} else {
		$take( 'AND' );
		$eval = $expr();
	}
	if ( null !== $peek() || count( $args ) !== $arg ) {
		throw new RuntimeException( 'fragment not fully parsed' );
	}
	return $eval;
}

$expected = [
	'google.com'                              => 'search',
	'google.co.uk'                            => 'search',
	'bing.com'                                => 'search',
	'duckduckgo.com'                          => 'search',
	'search.yahoo.com'                        => 'search',
	'search.brave.com'                        => 'search',
	'ecosia.org'                              => 'search',
	'facebook.com'                            => 'social',
	'm.facebook.com'                          => 'social',
	'fb.com'                                  => 'social',
	'instagram.com'                           => 'social',
	'linkedin.com'                            => 'social',
	'lnkd.in'                                 => 'social',
	'reddit.com'                              => 'social',
	'old.reddit.com'                          => 'social',
	'news.ycombinator.com'                    => 'social',
	'producthunt.com'                         => 'social',
	'x.com'                                   => 'social',
	'twitter.com'                             => 'social',
	't.co'                                    => 'social',
	'youtube.com'                             => 'social',
	'youtu.be'                                => 'social',
	'bsky.app'                                => 'social',
	'threads.net'                             => 'social',
	'tiktok.com'                              => 'social',
	'pinterest.com'                           => 'social',
	'mastodon.social'                         => 'social',
	'mastodonapp.uk'                          => 'social',
	'slack.com'                               => 'social',
	'discord.com'                             => 'social',
	'com.google.android.googlequicksearchbox' => 'search',
	'com.google.android.gm'                   => 'referral',
	'com.linkedin.android'                    => 'social',
	'chatgpt.com'                             => 'ai',
	'chat.openai.com'                         => 'ai',
	'perplexity.ai'                           => 'ai',
	'www.perplexity.ai'                       => 'ai',
	'claude.ai'                               => 'ai',
	'gemini.google.com'                       => 'ai',
	'copilot.microsoft.com'                   => 'ai',
	'chat.deepseek.com'                       => 'ai',
	'grok.com'                                => 'ai',
	'x.ai'                                    => 'ai',
	'poe.com'                                 => 'ai',
	'example.com'                             => 'referral',
	'wordpress.org'                           => 'referral',
	'myblog.example'                          => 'referral',
	'googleblog.example'                      => 'referral',
];

$all_args = [];
foreach ( [ 'ai', 'search', 'social', 'referral' ] as $bucket ) {
	$f         = new Filters();
	$f->source = $bucket;
	$all_args  = array_merge( $all_args, $f->source_where( 'u.url' )[1] );
}
ok( [] === array_filter( $all_args, static fn( $a ): bool => false !== strpos( (string) $a, '\\\\' ) ), 'no bound pattern carries a doubled backslash' );

// One host, one bucket, everywhere: the referrer card (vireo_classify_source),
// the PHP filter classifier, and the SQL filter.
foreach ( $expected as $host => $bucket ) {
	$card   = vireo_classify_source( $host )['type'];
	$filter = Filters::classify_source( $host );
	$sql    = array_values( array_filter( [ 'ai', 'search', 'social', 'referral' ], static fn( string $b ): bool => sql_selects( $b, $host ) ) );

	ok( $bucket === $card, "card puts $host in $bucket, got $card" );
	ok( $bucket === $filter, "filter classifier puts $host in $bucket, got $filter" );
	ok( [ $bucket ] === $sql, "SQL selects $host under $bucket only, got " . ( $sql ? implode( '+', $sql ) : 'none' ) );

	// The group-total query must file every host where its filter does, or a
	// header would disagree with the rows its filter shows.
	$case = method_exists( Filters::class, 'bucket_case' ) ? sql_case_bucket( $host ) : 'no bucket_case()';
	ok( $bucket === $case, "group totals file $host under $bucket, got $case" );
}

echo $fail ? "\n$fail failure(s)\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
