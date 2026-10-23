<?php
/**
 * Reads the facts an AI crawler would get from a page's raw HTML.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class AI_Scan_Page_Parser {

	private const ORG_TYPES = '/(Organization|Business|Restaurant|Store|Hotel|Dentist|Physician|Clinic|LegalService|Attorney|Corporation|Agency|Shop|Salon|Cafe|Bakery|Bar|Gym|School|Brand|Person)$/';

	/**
	 * @return array<string,mixed>
	 */
	public function parse( string $raw ): array {
		// Commented-out markup is inactive, so it must not count as metadata.
		$html  = $this->strip_comments( $raw );
		$metas = $this->tags( $html, 'meta' );

		$robots_meta = [];
		$og          = [];
		foreach ( $metas as $m ) {
			$name = strtolower( $m['name'] ?? '' );
			if ( in_array( $name, [ 'robots', 'googlebot' ], true ) && '' !== trim( $m['content'] ?? '' ) ) {
				$robots_meta[] = strtolower( trim( $m['content'] ) );
			}
			$prop = strtolower( $m['property'] ?? $name );
			if ( 0 === strpos( $prop, 'og:' ) && '' !== trim( $m['content'] ?? '' ) ) {
				$og[ $prop ] = trim( $m['content'] );
			}
		}

		$canonical = '';
		foreach ( $this->tags( $html, 'link' ) as $l ) {
			if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( $l['rel'] ?? '' ) ), true ) ) {
				$canonical = $l['href'] ?? '';
				break;
			}
		}

		$html_tag = $this->tags( $html, 'html' );

		$json_ld = [];
		$errors  = 0;
		if ( preg_match_all( '#<script\b[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script\s*>#is', $html, $blocks ) ) {
			foreach ( $blocks[1] as $raw ) {
				$decoded = json_decode( (string) preg_replace( '/^<!\[CDATA\[|\]\]>$/', '', trim( $raw ) ), true );
				if ( null === $decoded ) {
					++$errors;
					continue;
				}
				$json_ld[] = $decoded;
			}
		}

		$body = $this->strip_non_content( $html );
		$h1   = $this->headings( $body, 1 );
		$h2   = $this->headings( $body, 2 );

		$imgs     = $this->tags( $body, 'img' );
		$with_alt = count(
			array_filter(
				$imgs,
				static function ( array $img ): bool {
					return array_key_exists( 'alt', $img );
				}
			)
		);

		$text  = $this->text_of( $this->body_of( $body ) );
		$words = 0;
		foreach ( '' === $text ? [] : explode( ' ', $text ) as $w ) {
			// Pages that aren't valid UTF-8 make the /u match fail, so fall back to bytes.
			$is_word = preg_match( '/[\p{L}\p{N}]/u', $w );
			if ( 1 === $is_word || ( false === $is_word && preg_match( '/[a-z0-9\x80-\xff]/i', $w ) ) ) {
				++$words;
			}
		}

		preg_match_all( '#itemtype\s*=\s*["\']https?://schema\.org/([A-Za-z]+)#i', $html, $micro );

		return [
			'lang'           => $html_tag[0]['lang'] ?? '',
			'canonical'      => $canonical,
			'robots_meta'    => implode( ', ', $robots_meta ),
			'og'             => $og,
			'h1'             => $h1,
			'h2'             => $h2,
			'headings'       => array_merge( $h1, $h2, $this->headings( $body, 3 ) ),
			'images'         => count( $imgs ),
			'images_alt'     => $with_alt,
			'word_count'     => $words,
			'json_ld'        => $json_ld,
			'json_ld_errors' => $errors,
			'schema_types'   => $this->collect_types( $json_ld ),
			'microdata'      => array_values( array_unique( $micro[1] ) ),
			'tel_links'      => preg_match_all( '#href\s*=\s*["\']tel:#i', $html ),
			'mailto_links'   => preg_match_all( '#href\s*=\s*["\']mailto:#i', $html ),
			'app_shell'      => 1 === preg_match( '#<div\b[^>]*id\s*=\s*["\'](root|app|__next|__nuxt|svelte)["\'][^>]*>\s*</div>#i', $html ),
		];
	}

	/**
	 * @param array<int,mixed> $nodes
	 * @return array<string,mixed>|null
	 */
	public function find_org( array $nodes ): ?array {
		foreach ( $nodes as $node ) {
			$found = $this->find_org_in( $node, 0 );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	public static function is_org_type( string $type ): bool {
		return 1 === preg_match( self::ORG_TYPES, $type );
	}

	/**
	 * @param mixed $node
	 * @return array<string,mixed>|null
	 */
	private function find_org_in( $node, int $depth ): ?array {
		if ( $depth > 8 || ! is_array( $node ) ) {
			return null;
		}
		foreach ( $this->types_of( $node ) as $type ) {
			if ( self::is_org_type( $type ) ) {
				return $node;
			}
		}
		foreach ( $node as $child ) {
			$found = $this->find_org_in( $child, $depth + 1 );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param array<int,mixed> $nodes
	 * @return array<int,string>
	 */
	private function collect_types( array $nodes ): array {
		$out  = [];
		$walk = function ( $node, int $depth ) use ( &$walk, &$out ): void {
			if ( $depth > 8 || ! is_array( $node ) ) {
				return;
			}
			foreach ( $this->types_of( $node ) as $type ) {
				$out[ $type ] = true;
			}
			foreach ( $node as $child ) {
				$walk( $child, $depth + 1 );
			}
		};
		foreach ( $nodes as $node ) {
			$walk( $node, 0 );
		}
		return array_keys( $out );
	}

	/**
	 * @param array<mixed> $node
	 * @return array<int,string>
	 */
	private function types_of( array $node ): array {
		$types = [];
		foreach ( (array) ( $node['@type'] ?? [] ) as $t ) {
			if ( is_string( $t ) && '' !== $t ) {
				$types[] = (string) preg_replace( '#^https?://schema\.org/#', '', $t );
			}
		}
		return $types;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function tags( string $html, string $name ): array {
		// Quote-aware, so an attribute value containing ">" doesn't end the tag.
		if ( ! preg_match_all( '#<' . $name . '\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i', $html, $m ) ) {
			return [];
		}
		return array_map( [ $this, 'attrs' ], $m[0] );
	}

	/**
	 * @return array<string,string>
	 */
	private function attrs( string $tag ): array {
		$inner = (string) preg_replace( [ '#^<\s*[a-z0-9-]+#i', '#/?>$#' ], '', $tag );
		preg_match_all( '#([^\s=/"\'<>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#', $inner, $m, PREG_SET_ORDER );
		$attrs = [];
		foreach ( $m as $a ) {
			$value                        = $a[2] ?? '';
			$value                        = '' !== $value ? $value : ( $a[3] ?? '' );
			$value                        = '' !== $value ? $value : ( $a[4] ?? '' );
			$attrs[ strtolower( $a[1] ) ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $attrs;
	}

	private function strip_comments( string $html ): string {
		$out = preg_replace( '#<!--.*?-->#s', ' ', $html );
		return null === $out ? $html : $out;
	}

	private function strip_non_content( string $html ): string {
		$out = preg_replace(
			'#<(script|style|noscript|svg|template|iframe)\b.*?</\1\s*>#is',
			' ',
			$html
		);
		return null === $out ? $html : $out;
	}

	private function body_of( string $html ): string {
		$open = stripos( $html, '<body' );
		if ( false === $open ) {
			return $html;
		}
		$start = strpos( $html, '>', $open );
		$end   = strripos( $html, '</body' );
		if ( false === $start || false === $end || $end < $start ) {
			return substr( $html, $open );
		}
		return substr( $html, $start + 1, $end - $start - 1 );
	}

	/**
	 * @return array<int,string>
	 */
	private function headings( string $body, int $level ): array {
		preg_match_all( '#<h' . $level . '\b[^>]*>(.*?)</h' . $level . '\s*>#is', $body, $m );
		return array_values( array_filter( array_map( [ $this, 'text_of' ], $m[1] ), 'strlen' ) );
	}

	private function text_of( string $fragment ): string {
		$text = html_entity_decode( (string) preg_replace( '#<[^>]+>#', ' ', $fragment ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}
}
