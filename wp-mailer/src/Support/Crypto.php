<?php
namespace WPM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts stored credentials (SMTP / IMAP passwords) with AES-256-GCM.
 *
 * The key comes from WPM_SECRET_KEY in wp-config.php when defined, otherwise
 * from the site's salts, so a database dump alone does not reveal passwords.
 */
final class Crypto {

	private const PREFIX = 'enc1:';

	private static function key(): string {
		$secret = defined( 'WPM_SECRET_KEY' ) ? WPM_SECRET_KEY : wp_salt( 'secure_auth' );
		return hash( 'sha256', 'wpm|' . $secret, true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $cipher ? $plain : self::PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}
}
