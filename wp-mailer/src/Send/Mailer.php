<?php
namespace WPM\Send;

use WPM\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one message through wp_mail(), configuring PHPMailer for SMTP,
 * the VERP return path, the text alternative and list headers.
 */
final class Mailer {

	private static ?string $last_error = null;
	private static bool $keep_alive    = false;

	/** Hooked to wp_mail_failed so the error reaches the caller. */
	public static function capture_failure( $error ): void {
		if ( $error instanceof \WP_Error ) {
			self::$last_error = $error->get_error_message();
		}
	}

	/** Keep the SMTP connection open across a batch. */
	public static function begin_batch(): void {
		self::$keep_alive = true;
	}

	public static function end_batch(): void {
		global $phpmailer;
		self::$keep_alive = false;
		if ( is_object( $phpmailer ) && method_exists( $phpmailer, 'smtpClose' ) ) {
			$phpmailer->smtpClose();
		}
	}

	/**
	 * @param array $o from_name, from_email, reply_to, return_path, headers (name => value).
	 * @return true|\WP_Error
	 */
	public static function send( string $to, string $subject, string $html, string $text, array $o = array() ) {
		$s = Settings::all();

		$from_email = ( $o['from_email'] ?? '' ) ?: $s['from_email'];
		$from_name  = ( $o['from_name'] ?? '' ) ?: $s['from_name'];
		$reply_to   = ( $o['reply_to'] ?? '' ) ?: $s['reply_to'];

		$headers   = array();
		$headers[] = 'From: ' . self::address( $from_email, $from_name );
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		foreach ( (array) ( $o['headers'] ?? array() ) as $name => $value ) {
			$headers[] = $name . ': ' . str_replace( array( "\r", "\n" ), '', (string) $value );
		}

		$return_path = (string) ( $o['return_path'] ?? '' );

		$configure = static function ( $phpmailer ) use ( $s, $text, $return_path ): void {
			if ( $s['smtp_enabled'] && $s['smtp_host'] ) {
				$phpmailer->isSMTP();
				$phpmailer->Host       = $s['smtp_host'];
				$phpmailer->Port       = (int) $s['smtp_port'];
				$phpmailer->SMTPSecure = in_array( $s['smtp_encryption'], array( 'ssl', 'tls' ), true ) ? $s['smtp_encryption'] : '';
				$phpmailer->SMTPAutoTLS = 'none' !== $s['smtp_encryption'];
				$phpmailer->SMTPAuth   = (bool) $s['smtp_auth'];
				$phpmailer->Username   = $s['smtp_user'];
				$phpmailer->Password   = $s['smtp_pass'];
				$phpmailer->Timeout    = 20;
			}
			$phpmailer->SMTPKeepAlive = self::$keep_alive;
			$phpmailer->AltBody       = $text;
			if ( '' !== $return_path ) {
				$phpmailer->Sender = $return_path;
			}
		};

		self::$last_error = null;
		add_action( 'phpmailer_init', $configure, 999 );
		$ok = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'phpmailer_init', $configure, 999 );

		if ( $ok ) {
			return true;
		}
		return new \WP_Error( 'wpm_send_failed', self::$last_error ?: __( 'wp_mail() returned false.', 'wp-mailer' ) );
	}

	/** True when an SMTP error means the server refused this recipient permanently. */
	public static function is_permanent_rejection( string $error ): bool {
		return (bool) preg_match( '/recipients failed|\b55[0-4]\b|\b5\.1\.[0-9]\b|user unknown|no such user|does not exist/i', $error );
	}

	private static function address( string $email, string $name ): string {
		$name = trim( str_replace( array( '"', "\r", "\n" ), '', $name ) );
		return '' === $name ? $email : '"' . $name . '" <' . $email . '>';
	}
}
