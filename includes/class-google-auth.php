<?php

namespace VireoAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Service-account authentication for Google APIs.
 *
 * Shared by the Analytics importer and Search Console, which want different
 * scopes against the same kind of key. A service account rather than OAuth
 * means no redirect flow, no client secret round-trip, and nothing to
 * re-authorise every sixty days: the site owner pastes a key once and grants
 * that account read access on whatever they want read.
 *
 * Tokens are cached per scope until shortly before they expire.
 */
final class Google_Auth {

	public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	private const TIMEOUT = 60;

	/** Signing needs openssl; there is no useful fallback. */
	public static function available(): bool {
		return function_exists( 'openssl_sign' ) && defined( 'OPENSSL_ALGO_SHA256' );
	}

	/**
	 * Does this look like a service account key, and whose is it?
	 *
	 * @return array{client_email:string, private_key:string}|\WP_Error
	 */
	public static function parse_key( string $key_json ) {
		$key = json_decode( $key_json, true );

		if ( ! is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return new \WP_Error(
				'vireo_google_key',
				__( 'That does not look like a service account key file. It should contain client_email and private_key.', 'vireo-analytics' )
			);
		}

		return [
			'client_email' => (string) $key['client_email'],
			'private_key'  => (string) $key['private_key'],
		];
	}

	/** The account address, for telling the user who to grant access to. */
	public static function account_email( string $key_json ): string {
		$key = self::parse_key( $key_json );

		return is_wp_error( $key ) ? '' : $key['client_email'];
	}

	/**
	 * Exchange the key for an access token on one scope.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token( string $key_json, string $scope ) {
		$cache_key = 'vireo_gtoken_' . md5( $scope . '|' . $key_json );

		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( ! self::available() ) {
			return new \WP_Error(
				'vireo_google_openssl',
				__( 'This server is missing the OpenSSL functions needed to authenticate with Google.', 'vireo-analytics' )
			);
		}

		$key = self::parse_key( $key_json );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$now    = time();
		$claims = [
			'iss'   => $key['client_email'],
			'scope' => $scope,
			'aud'   => self::TOKEN_URL,
			'exp'   => $now + 3600,
			'iat'   => $now,
		];

		$segments = [
			self::base64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) ),
			self::base64url( (string) wp_json_encode( $claims ) ),
		];

		$signature = '';
		if ( ! openssl_sign( implode( '.', $segments ), $signature, $key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error(
				'vireo_google_sign',
				__( 'Could not sign the request with that key. Check the key file was pasted whole.', 'vireo-analytics' )
			);
		}

		$segments[] = self::base64url( $signature );

		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => self::TIMEOUT,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => implode( '.', $segments ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'vireo_google_http', $response->get_error_message() );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$token   = is_array( $decoded ) ? (string) ( $decoded['access_token'] ?? '' ) : '';

		if ( '' === $token ) {
			$detail = is_array( $decoded ) ? (string) ( $decoded['error_description'] ?? $decoded['error'] ?? '' ) : '';

			return new \WP_Error(
				'vireo_google_token',
				'' !== $detail ? $detail : __( 'Google would not issue an access token for that key.', 'vireo-analytics' )
			);
		}

		$expires = (int) ( $decoded['expires_in'] ?? 3600 );
		set_transient( $cache_key, $token, max( 60, $expires - 120 ) );

		return $token;
	}

	/** Forget every cached token for a key, e.g. when it is replaced. */
	public static function forget( string $key_json, array $scopes ): void {
		foreach ( $scopes as $scope ) {
			delete_transient( 'vireo_gtoken_' . md5( $scope . '|' . $key_json ) );
		}
	}

	private static function base64url( string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding, not obfuscation
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
