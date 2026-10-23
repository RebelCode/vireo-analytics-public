<?php
/**
 * Minimal pure-PHP MMDB (MaxMind DB binary file format) reader.
 *
 * Hand-rolled, dependency-free implementation that decodes a
 * GeoLite2-Country lookup. Supports IPv4 + IPv6 tree walking, 28-bit and
 * 32-bit record sizes, and the data-section types used by GeoLite2 (map,
 * string, uint16/32/64, int32, double, float, bytes, bool, array, pointer).
 *
 * Format reference: https://maxmind.github.io/MaxMind-DB/
 *
 * Note: this is *not* the official `maxmind/MaxMind-DB-Reader-PHP` library —
 * it lives under `lib/mmdb-reader/` to make that distinction obvious.
 *
 * Sniffer notes for this file:
 *   - fopen()/fread()/fclose() are required: the file is binary and must
 *     be streamed; WP_Filesystem has no binary-stream API.
 *   - Exception messages contain raw values from the MMDB header for
 *     diagnostic purposes. They are never echoed to a browser — the
 *     caller catches Throwable and logs only when WP_DEBUG is on.
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

namespace VireoAnalytics\MMDB_Reader;

defined( 'ABSPATH' ) || exit;

class Reader {

	const METADATA_MARKER            = "\xAB\xCD\xEFMaxMind.com";
	const DATA_SECTION_SEPARATOR_SIZE = 16;

	/** @var resource */
	private $handle;
	/** @var int */
	private $file_size;
	/** @var array */
	private $metadata;
	/** @var int */
	private $record_size;
	/** @var int */
	private $node_byte_size;
	/** @var int */
	private $node_count;
	/** @var int */
	private $search_tree_size;
	/** @var int */
	private $data_section_start;
	/** @var int */
	private $ipv4_start = 0;

	/**
	 * @param string $filename Path to the .mmdb file.
	 * @throws InvalidDatabaseException
	 */
	public function __construct( $filename ) {
		if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
			throw new InvalidDatabaseException( 'MMDB file not found or unreadable: ' . $filename );
		}

		$handle = fopen( $filename, 'rb' );
		if ( false === $handle ) {
			throw new InvalidDatabaseException( 'Could not open MMDB file: ' . $filename );
		}
		$this->handle    = $handle;
		$this->file_size = (int) filesize( $filename );

		$this->metadata = $this->read_metadata();

		$this->record_size        = (int) $this->metadata['record_size'];
		$this->node_count         = (int) $this->metadata['node_count'];
		$this->node_byte_size     = ( $this->record_size * 2 ) / 8;
		$this->search_tree_size   = $this->node_count * $this->node_byte_size;
		$this->data_section_start = $this->search_tree_size + self::DATA_SECTION_SEPARATOR_SIZE;

		if ( 6 === (int) $this->metadata['ip_version'] ) {
			$this->ipv4_start = $this->find_ipv4_start();
		}
	}

	public function close() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	public function __destruct() {
		$this->close();
	}

	public function metadata() {
		return $this->metadata;
	}

	/**
	 * Look up an IP address. Returns decoded data (array for map types) or null if not found.
	 *
	 * @param string $ip
	 * @return array|null
	 */
	public function get( $ip ) {
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}

		$is_v4 = ( 4 === strlen( $packed ) );
		if ( 6 === (int) $this->metadata['ip_version'] && $is_v4 ) {
			$bits = 32;
			$node = $this->ipv4_start;
		} else {
			$bits = $is_v4 ? 32 : 128;
			$node = 0;
		}

		for ( $i = 0; $i < $bits; $i++ ) {
			if ( $node >= $this->node_count ) {
				break;
			}

			$byte_idx = intdiv( $i, 8 );
			$bit_idx  = 7 - ( $i % 8 );
			$bit      = ( ord( $packed[ $byte_idx ] ) >> $bit_idx ) & 1;

			$node = $this->read_node( $node, $bit );
		}

		if ( $node === $this->node_count ) {
			return null; // Empty record.
		}

		if ( $node > $this->node_count ) {
			// Data section offset as defined by the spec:
			//   absolute_offset = (node - node_count) + search_tree_size
			// which already accounts for the 16-byte separator sitting between
			// the tree and the data section.
			$data_offset = ( $node - $this->node_count ) + $this->search_tree_size;
			list( $value, ) = $this->decode( $data_offset );
			return $value;
		}

		return null; // Should not happen for a well-formed DB.
	}

	private function read_node( $node_number, $index ) {
		$base_offset = $node_number * $this->node_byte_size;

		switch ( $this->record_size ) {
			case 24:
				$bytes = $this->read( $base_offset + $index * 3, 3 );
				return $this->bytes_to_int( $bytes );

			case 28:
				$middle = ord( $this->read( $base_offset + 3, 1 ) );
				if ( 0 === $index ) {
					$nibble = ( $middle >> 4 ) & 0x0F;
					$rest   = $this->read( $base_offset, 3 );
				} else {
					$nibble = $middle & 0x0F;
					$rest   = $this->read( $base_offset + 4, 3 );
				}
				return ( $nibble << 24 ) | $this->bytes_to_int( $rest );

			case 32:
				$bytes = $this->read( $base_offset + $index * 4, 4 );
				return $this->bytes_to_int( $bytes );

			default:
				throw new InvalidDatabaseException( 'Unsupported MMDB record size: ' . $this->record_size );
		}
	}

	/**
	 * For an IPv6 DB, walk 96 zero bits to find the v4-in-v6 subtree root (::ffff:0:0/96).
	 */
	private function find_ipv4_start() {
		$node = 0;
		for ( $i = 0; $i < 96; $i++ ) {
			if ( $node >= $this->node_count ) {
				break;
			}
			$node = $this->read_node( $node, 0 );
		}
		return $node;
	}

	private function read_metadata() {
		// Metadata lives at the end of the file, preceded by the marker.
		// Spec allows up to 128KB of metadata.
		$search_from = max( 0, $this->file_size - ( 128 * 1024 ) );
		if ( fseek( $this->handle, $search_from ) !== 0 ) {
			throw new InvalidDatabaseException( 'MMDB seek failed while reading metadata.' );
		}
		$tail = fread( $this->handle, $this->file_size - $search_from );
		if ( false === $tail ) {
			throw new InvalidDatabaseException( 'MMDB read failed while reading metadata.' );
		}

		$pos = strrpos( $tail, self::METADATA_MARKER );
		if ( false === $pos ) {
			throw new InvalidDatabaseException( 'MMDB metadata marker not found — not a valid MaxMind DB file.' );
		}

		$metadata_abs_offset = $search_from + $pos + strlen( self::METADATA_MARKER );

		// The metadata section is a self-contained map that uses the same
		// encoding as the data section. Decode directly from its absolute
		// offset — pointers inside metadata are relative to metadata_start.
		$meta_decoder = new Reader_Metadata_Decoder( $this->handle, $metadata_abs_offset );
		$meta         = $meta_decoder->decode_root();

		if ( ! is_array( $meta ) ) {
			throw new InvalidDatabaseException( 'MMDB metadata is not a map.' );
		}

		$required = array( 'binary_format_major_version', 'node_count', 'record_size', 'ip_version' );
		foreach ( $required as $k ) {
			if ( ! isset( $meta[ $k ] ) ) {
				throw new InvalidDatabaseException( 'MMDB metadata missing required field: ' . $k );
			}
		}
		if ( 2 !== (int) $meta['binary_format_major_version'] ) {
			throw new InvalidDatabaseException( 'Unsupported MMDB binary format version: ' . $meta['binary_format_major_version'] );
		}

		return $meta;
	}

	// ---------------------------------------------------------------------
	// Data-section decoder (used after lookup).
	// ---------------------------------------------------------------------

	/**
	 * Decode a value from the data section at absolute file offset $offset.
	 *
	 * @return array [value, next_offset]
	 */
	private function decode( $offset ) {
		$ctrl   = ord( $this->read( $offset, 1 ) );
		$type   = $ctrl >> 5;
		$offset += 1;

		if ( 0 === $type ) {
			$type    = ord( $this->read( $offset, 1 ) ) + 7;
			$offset += 1;
		}

		if ( 1 === $type ) {
			return $this->decode_pointer( $ctrl, $offset );
		}

		$size = $ctrl & 0x1F;
		if ( $size >= 29 ) {
			if ( 29 === $size ) {
				$size    = 29 + ord( $this->read( $offset, 1 ) );
				$offset += 1;
			} elseif ( 30 === $size ) {
				$size    = 285 + $this->bytes_to_int( $this->read( $offset, 2 ) );
				$offset += 2;
			} else {
				$size    = 65821 + $this->bytes_to_int( $this->read( $offset, 3 ) );
				$offset += 3;
			}
		}

		return $this->decode_by_type( $type, $size, $offset );
	}

	private $pointer_depth = 0;

	// Bounds pointer following so a malformed file cannot exhaust the stack by
	// chaining pointers in a loop. Every level increments this, so a cycle is
	// caught however it is constructed.
	//
	// This was 1, on the reading that the spec forbids nested pointers. It
	// forbids a pointer whose target is itself a pointer; it says nothing
	// about a pointer to a map whose values are pointers, which is how City
	// databases deduplicate repeated place names and happens several levels
	// deep on ordinary lookups. Country databases are shallow enough that the
	// old limit never fired, so this only surfaced when city data arrived.
	const MAX_POINTER_DEPTH = 16;

	private function decode_pointer( $ctrl, $offset ) {
		if ( $this->pointer_depth >= self::MAX_POINTER_DEPTH ) {
			throw new InvalidDatabaseException( 'MMDB pointer cycle or nesting too deep' );
		}

		$size = ( $ctrl >> 3 ) & 0x3;
		$b1   = $ctrl & 0x7;

		switch ( $size ) {
			case 0:
				$pointer = ( $b1 << 8 ) | ord( $this->read( $offset, 1 ) );
				$offset += 1;
				break;
			case 1:
				$pointer = ( $b1 << 16 ) | $this->bytes_to_int( $this->read( $offset, 2 ) );
				$pointer += 2048;
				$offset  += 2;
				break;
			case 2:
				$pointer = ( $b1 << 24 ) | $this->bytes_to_int( $this->read( $offset, 3 ) );
				$pointer += 526336;
				$offset  += 3;
				break;
			default: // 3
				$pointer = $this->bytes_to_int( $this->read( $offset, 4 ) );
				$offset += 4;
				break;
		}

		$this->pointer_depth++;
		try {
			$absolute       = $this->data_section_start + $pointer;
			list( $value, ) = $this->decode( $absolute );
		} finally {
			$this->pointer_depth--;
		}
		return array( $value, $offset );
	}

	private function decode_by_type( $type, $size, $offset ) {
		switch ( $type ) {
			case 2: // utf8_string
				$value   = $size > 0 ? $this->read( $offset, $size ) : '';
				$offset += $size;
				return array( $value, $offset );

			case 3: // double, big-endian 8 bytes
				$bytes   = $this->read( $offset, 8 );
				$offset += 8;
				return array( self::unpack_double_be( $bytes ), $offset );

			case 4: // bytes
				$value   = $size > 0 ? $this->read( $offset, $size ) : '';
				$offset += $size;
				return array( $value, $offset );

			case 5: // uint16
			case 6: // uint32
			case 9: // uint64
			case 10: // uint128
				$value   = $size > 0 ? $this->bytes_to_int( $this->read( $offset, $size ) ) : 0;
				$offset += $size;
				return array( $value, $offset );

			case 7: // map
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $k, $offset ) = $this->decode( $offset );
					list( $v, $offset ) = $this->decode( $offset );
					$map[ (string) $k ] = $v;
				}
				return array( $map, $offset );

			case 8: // int32
				$value   = $size > 0 ? $this->bytes_to_signed_int( $this->read( $offset, $size ) ) : 0;
				$offset += $size;
				return array( $value, $offset );

			case 11: // array
				$arr = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $v, $offset ) = $this->decode( $offset );
					$arr[] = $v;
				}
				return array( $arr, $offset );

			case 12: // data cache container — not expected at read time
			case 13: // end marker
				return array( null, $offset );

			case 14: // boolean — size encodes the value
				return array( $size > 0, $offset );

			case 15: // float, big-endian 4 bytes
				$bytes   = $this->read( $offset, 4 );
				$offset += 4;
				return array( self::unpack_float_be( $bytes ), $offset );

			default:
				throw new InvalidDatabaseException( 'Unknown MMDB data type: ' . $type );
		}
	}

	// ---------------------------------------------------------------------
	// Low-level helpers.
	// ---------------------------------------------------------------------

	/**
	 * Read $length bytes at absolute offset $offset.
	 */
	public function read( $offset, $length ) {
		if ( $length <= 0 ) {
			return '';
		}
		if ( fseek( $this->handle, $offset ) !== 0 ) {
			throw new InvalidDatabaseException( 'MMDB seek failed at offset ' . $offset );
		}
		$buf = fread( $this->handle, $length );
		if ( false === $buf || strlen( $buf ) !== $length ) {
			throw new InvalidDatabaseException( 'MMDB short read at offset ' . $offset );
		}
		return $buf;
	}

	public function bytes_to_int( $bytes ) {
		$n = 0;
		$l = strlen( $bytes );
		for ( $i = 0; $i < $l; $i++ ) {
			$n = ( $n << 8 ) | ord( $bytes[ $i ] );
		}
		return $n;
	}

	public function bytes_to_signed_int( $bytes ) {
		$n = $this->bytes_to_int( $bytes );
		$l = strlen( $bytes );
		if ( $l > 0 && ( ord( $bytes[0] ) & 0x80 ) ) {
			$n -= 1 << ( 8 * $l );
		}
		return $n;
	}

	private static function unpack_double_be( $bytes ) {
		// PHP 7.0.15+/7.1 supports the 'E' format spec for big-endian doubles.
		$r = @unpack( 'E', $bytes );
		if ( is_array( $r ) && isset( $r[1] ) ) {
			return $r[1];
		}
		$r = unpack( 'd', strrev( $bytes ) );
		return $r[1];
	}

	private static function unpack_float_be( $bytes ) {
		$r = @unpack( 'G', $bytes );
		if ( is_array( $r ) && isset( $r[1] ) ) {
			return $r[1];
		}
		$r = unpack( 'f', strrev( $bytes ) );
		return $r[1];
	}

	public function data_section_start() {
		return $this->data_section_start;
	}
}

/**
 * Metadata decoder — decodes from a base offset using a mini-copy of the
 * decoder logic. Metadata pointers are relative to the metadata section start,
 * not the data section start, which is why we can't reuse Reader::decode().
 */
class Reader_Metadata_Decoder {

	/** @var resource */
	private $handle;
	/** @var int */
	private $base;

	public function __construct( $handle, $base_offset ) {
		$this->handle = $handle;
		$this->base   = $base_offset;
	}

	public function decode_root() {
		list( $value, ) = $this->decode( $this->base );
		return $value;
	}

	private function decode( $offset ) {
		$ctrl    = ord( $this->read( $offset, 1 ) );
		$type    = $ctrl >> 5;
		$offset += 1;

		if ( 0 === $type ) {
			$type    = ord( $this->read( $offset, 1 ) ) + 7;
			$offset += 1;
		}

		if ( 1 === $type ) {
			return $this->decode_pointer( $ctrl, $offset );
		}

		$size = $ctrl & 0x1F;
		if ( $size >= 29 ) {
			if ( 29 === $size ) {
				$size    = 29 + ord( $this->read( $offset, 1 ) );
				$offset += 1;
			} elseif ( 30 === $size ) {
				$size    = 285 + $this->bytes_to_int( $this->read( $offset, 2 ) );
				$offset += 2;
			} else {
				$size    = 65821 + $this->bytes_to_int( $this->read( $offset, 3 ) );
				$offset += 3;
			}
		}

		return $this->decode_by_type( $type, $size, $offset );
	}

	private function decode_pointer( $ctrl, $offset ) {
		$size = ( $ctrl >> 3 ) & 0x3;
		$b1   = $ctrl & 0x7;
		switch ( $size ) {
			case 0:
				$pointer = ( $b1 << 8 ) | ord( $this->read( $offset, 1 ) );
				$offset += 1;
				break;
			case 1:
				$pointer = ( $b1 << 16 ) | $this->bytes_to_int( $this->read( $offset, 2 ) );
				$pointer += 2048;
				$offset  += 2;
				break;
			case 2:
				$pointer = ( $b1 << 24 ) | $this->bytes_to_int( $this->read( $offset, 3 ) );
				$pointer += 526336;
				$offset  += 3;
				break;
			default:
				$pointer = $this->bytes_to_int( $this->read( $offset, 4 ) );
				$offset += 4;
				break;
		}
		list( $value, ) = $this->decode( $this->base + $pointer );
		return array( $value, $offset );
	}

	private function decode_by_type( $type, $size, $offset ) {
		switch ( $type ) {
			case 2:
				$value   = $size > 0 ? $this->read( $offset, $size ) : '';
				$offset += $size;
				return array( $value, $offset );
			case 4:
				$value   = $size > 0 ? $this->read( $offset, $size ) : '';
				$offset += $size;
				return array( $value, $offset );
			case 5:
			case 6:
			case 9:
			case 10:
				$value   = $size > 0 ? $this->bytes_to_int( $this->read( $offset, $size ) ) : 0;
				$offset += $size;
				return array( $value, $offset );
			case 7:
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $k, $offset ) = $this->decode( $offset );
					list( $v, $offset ) = $this->decode( $offset );
					$map[ (string) $k ] = $v;
				}
				return array( $map, $offset );
			case 8:
				$n = 0;
				$r = $size > 0 ? $this->read( $offset, $size ) : '';
				$l = strlen( $r );
				for ( $i = 0; $i < $l; $i++ ) {
					$n = ( $n << 8 ) | ord( $r[ $i ] );
				}
				if ( $l > 0 && ( ord( $r[0] ) & 0x80 ) ) {
					$n -= 1 << ( 8 * $l );
				}
				$offset += $size;
				return array( $n, $offset );
			case 11:
				$arr = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $v, $offset ) = $this->decode( $offset );
					$arr[] = $v;
				}
				return array( $arr, $offset );
			case 14:
				return array( $size > 0, $offset );
			default:
				// Types we don't expect in metadata (doubles/floats) — return 0.
				$offset += $size;
				return array( 0, $offset );
		}
	}

	private function read( $offset, $length ) {
		if ( $length <= 0 ) {
			return '';
		}
		if ( fseek( $this->handle, $offset ) !== 0 ) {
			throw new InvalidDatabaseException( 'MMDB metadata seek failed at offset ' . $offset );
		}
		$buf = fread( $this->handle, $length );
		if ( false === $buf || strlen( $buf ) !== $length ) {
			throw new InvalidDatabaseException( 'MMDB metadata short read at offset ' . $offset );
		}
		return $buf;
	}

	private function bytes_to_int( $bytes ) {
		$n = 0;
		$l = strlen( $bytes );
		for ( $i = 0; $i < $l; $i++ ) {
			$n = ( $n << 8 ) | ord( $bytes[ $i ] );
		}
		return $n;
	}
}

class InvalidDatabaseException extends \RuntimeException {}
