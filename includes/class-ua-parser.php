<?php
/**
 * Lightweight User-Agent parser. No external libraries.
 */

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

final class UA_Parser {

	/**
	 * @return array{browser:string, os:string, device_type:string}
	 */
	public static function parse( string $ua ): array {
		return [
			'browser'     => self::detect_browser( $ua ),
			'os'          => self::detect_os( $ua ),
			'device_type' => self::detect_device_type( $ua ),
		];
	}

	/**
	 * Order matters — check specific browsers before generic ones.
	 * (Edge contains "Chrome", Opera contains "Chrome", etc.)
	 */
	private static function detect_browser( string $ua ): string {
		if ( preg_match( '/Edg(e|A|iOS)?\//', $ua ) ) {
			return 'Edge';
		}
		if ( preg_match( '/OPR\/|Opera/', $ua ) ) {
			return 'Opera';
		}
		if ( preg_match( '/SamsungBrowser\//', $ua ) ) {
			return 'Samsung Internet';
		}
		if ( preg_match( '/Firefox\//', $ua ) && ! preg_match( '/Seamonkey\//', $ua ) ) {
			return 'Firefox';
		}
		if ( preg_match( '/Chrome\//', $ua ) && ! preg_match( '/Chromium\//', $ua ) ) {
			return 'Chrome';
		}
		if ( preg_match( '/Safari\//', $ua ) && ! preg_match( '/Chrome\/|Chromium\//', $ua ) ) {
			return 'Safari';
		}
		if ( preg_match( '/MSIE|Trident\//', $ua ) ) {
			return 'IE';
		}
		return 'Other';
	}

	private static function detect_os( string $ua ): string {
		// iOS before macOS — iPad may contain Macintosh in some UAs.
		if ( preg_match( '/iPhone|iPad|iPod/', $ua ) ) {
			return 'iOS';
		}
		if ( preg_match( '/Android/', $ua ) ) {
			return 'Android';
		}
		if ( preg_match( '/CrOS/', $ua ) ) {
			return 'Chrome OS';
		}
		if ( preg_match( '/Windows NT/', $ua ) ) {
			return 'Windows';
		}
		if ( preg_match( '/Macintosh|Mac OS X/', $ua ) ) {
			return 'macOS';
		}
		// Linux last — Android and Chrome OS both report Linux.
		if ( preg_match( '/Linux/', $ua ) ) {
			return 'Linux';
		}
		return 'Other';
	}

	private static function detect_device_type( string $ua ): string {
		if ( preg_match( '/iPad/', $ua ) ) {
			return 'Tablet';
		}
		if ( preg_match( '/Android/', $ua ) && ! preg_match( '/Mobile/', $ua ) ) {
			return 'Tablet';
		}
		if ( preg_match( '/Kindle|Silk\//', $ua ) ) {
			return 'Tablet';
		}
		if ( preg_match( '/Mobile|iPhone|iPod|Android.*Mobile|Opera Mini|IEMobile|wpdesktop/', $ua ) ) {
			return 'Mobile';
		}
		return 'Desktop';
	}
}
