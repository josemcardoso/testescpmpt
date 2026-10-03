<?php
namespace WPM\Track;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-signed tokens for tracking URLs and VERP bounce addresses.
 *
 * Tokens carry only numeric IDs; the signature stops anyone from forging
 * opens/clicks/unsubscribes for other recipients by editing the URL.
 */
final class Token {

	public const OPEN        = 'o';
	public const CLICK       = 'c';
	public const UNSUBSCRIBE = 'u';
	public const VIEW        = 'v';

	public function __construct( private string $key ) {}

	public static function instance(): self {
		return new self( (string) get_option( 'wpm_token_key', '' ) . ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '' ) );
	}

	public function make( string $type, int ...$ids ): string {
		$payload = $type . '.' . implode( '.', $ids );
		return $payload . '.' . $this->sign( $payload, 16 );
	}

	/**
	 * @return int[]|null The IDs, or null when the token is malformed or forged.
	 */
	public function parse( string $token, string $type ): ?array {
		$parts = explode( '.', $token );
		if ( count( $parts ) < 3 || $parts[0] !== $type ) {
			return null;
		}
		$sig     = array_pop( $parts );
		$payload = implode( '.', $parts );
		if ( ! hash_equals( $this->sign( $payload, 16 ), $sig ) ) {
			return null;
		}
		$ids = array_slice( $parts, 1 );
		foreach ( $ids as $id ) {
			if ( ! ctype_digit( $id ) ) {
				return null;
			}
		}
		return array_map( 'intval', $ids );
	}

	/**
	 * bounces@example.com + send 42 => bounces+42-1a2b3c4d5e@example.com
	 */
	public function verp( string $bounce_address, int $send_id ): string {
		[ $local, $domain ] = array_pad( explode( '@', $bounce_address, 2 ), 2, '' );
		return $local . '+' . $send_id . '-' . $this->sign( 'verp.' . $send_id, 10 ) . '@' . $domain;
	}

	/**
	 * Finds a VERP-encoded send ID inside any address string.
	 */
	public function parse_verp( string $address ): ?int {
		if ( ! preg_match_all( '/\+(\d+)-([a-f0-9]{10})@/i', $address, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		foreach ( $m as $match ) {
			if ( hash_equals( $this->sign( 'verp.' . $match[1], 10 ), strtolower( $match[2] ) ) ) {
				return (int) $match[1];
			}
		}
		return null;
	}

	/** Value of the X-WPM-Send header (fallback when VERP is stripped). */
	public function header_value( int $send_id ): string {
		return $send_id . '-' . $this->sign( 'verp.' . $send_id, 10 );
	}

	public function parse_header_value( string $value ): ?int {
		return $this->parse_verp( '+' . trim( $value ) . '@' );
	}

	private function sign( string $payload, int $length ): string {
		return substr( hash_hmac( 'sha256', $payload, $this->key ), 0, $length );
	}
}
