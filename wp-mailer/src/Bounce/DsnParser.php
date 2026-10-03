<?php
namespace WPM\Bounce;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies a message found in the bounce mailbox.
 *
 * Uses RFC 3464 delivery-status fields when present (Status: 5.1.1 => hard),
 * and falls back to well-known phrases for servers that send free-text bounces.
 */
final class DsnParser {

	private const HARD_PHRASES = array(
		'user unknown',
		'unknown user',
		'no such user',
		'no such recipient',
		'mailbox unavailable',
		'mailbox not found',
		'mailbox does not exist',
		'address does not exist',
		'does not exist',
		'recipient address rejected',
		'invalid recipient',
		'invalid mailbox',
		'account has been disabled',
		'account is disabled',
		'recipient not found',
		'unrouteable address',
		'no mailbox here',
		'host or domain name not found',
		'domain not found',
		'address rejected',
	);

	private const SOFT_PHRASES = array(
		'mailbox full',
		'mailbox is full',
		'over quota',
		'quota exceeded',
		'exceeded storage',
		'insufficient storage',
		'temporarily',
		'try again later',
		'temporary failure',
		'message too large',
		'connection timed out',
		'delivery temporarily suspended',
	);

	/**
	 * @return array{
	 *   is_bounce: bool, is_auto_reply: bool, type: ?string, status: string,
	 *   diagnostic: string, recipient: string, envelope_to: string[], wpm_header: string,
	 *   message_id: string, subject: string
	 * }
	 */
	public static function parse( string $raw ): array {
		$raw                = str_replace( "\r\n", "\n", $raw );
		[ $head, $body ]    = array_pad( explode( "\n\n", $raw, 2 ), 2, '' );
		$headers            = self::headers( $head );
		$subject            = self::decode_header( $headers['subject'][0] ?? '' );
		$from               = strtolower( $headers['from'][0] ?? '' );
		$content_type       = strtolower( implode( ' ', $headers['content-type'] ?? array() ) );
		$decoded_body       = self::decode_body( $body );

		$result = array(
			'is_bounce'     => false,
			'is_auto_reply' => false,
			'type'          => null,
			'status'        => '',
			'diagnostic'    => '',
			'recipient'     => '',
			'envelope_to'   => array(),
			'wpm_header'    => '',
			'message_id'    => '',
			'subject'       => $subject,
		);

		foreach ( array( 'to', 'delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to', 'original-recipient' ) as $h ) {
			foreach ( $headers[ $h ] ?? array() as $v ) {
				$result['envelope_to'][] = $v;
			}
		}

		if ( preg_match( '/^X-WPM-Send:\s*([0-9]+-[a-f0-9]{10})/mi', $decoded_body, $m ) ) {
			$result['wpm_header'] = $m[1];
		}
		if ( preg_match( '/^Message-ID:\s*(<[^>]+>)/mi', $decoded_body, $m ) ) {
			$result['message_id'] = $m[1];
		}

		$is_report = str_contains( $content_type, 'multipart/report' ) && str_contains( $content_type, 'delivery-status' );
		$from_daemon = (bool) preg_match( '/mailer-daemon|postmaster|mail delivery (sub)?system/', $from );
		$bounce_subject = (bool) preg_match( '/undeliver|delivery status notification|failure notice|returned mail|delivery failure|mail delivery failed|could not be delivered|delivery has failed|non.?delivery/i', $subject );

		$auto_submitted = strtolower( $headers['auto-submitted'][0] ?? '' );
		$auto_reply     = ( '' !== $auto_submitted && 'no' !== $auto_submitted && ! $is_report && ! $from_daemon )
			|| isset( $headers['x-autoreply'] ) || isset( $headers['x-autorespond'] )
			|| preg_match( '/^(auto(matic)?[ -]?reply|out of (the )?office|abwesend|absence|vacation|ausente|fora do escrit)/i', $subject );

		if ( $auto_reply && ! $is_report && ! $bounce_subject ) {
			$result['is_auto_reply'] = true;
			return $result;
		}

		if ( ! $is_report && ! $from_daemon && ! $bounce_subject ) {
			return $result;
		}

		$result['is_bounce'] = true;

		if ( preg_match( '/^Final-Recipient:\s*[^;]*;\s*<?([^\s>]+)>?/mi', $decoded_body, $m ) ) {
			$result['recipient'] = strtolower( $m[1] );
		}
		if ( preg_match( '/^Diagnostic-Code:\s*[^;]*;\s*(.+(?:\n[ \t]+.+)*)/mi', $decoded_body, $m ) ) {
			$result['diagnostic'] = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
		}
		$action = preg_match( '/^Action:\s*(\w+)/mi', $decoded_body, $m ) ? strtolower( $m[1] ) : '';

		if ( preg_match( '/^Status:\s*([245])\.(\d{1,3})\.(\d{1,3})/mi', $decoded_body, $m ) ) {
			$result['status'] = "{$m[1]}.{$m[2]}.{$m[3]}";
			if ( 'delayed' === $action || 'delivered' === $action || 'relayed' === $action || '2' === $m[1] ) {
				$result['is_bounce'] = false; // Just a delay/success notice.
				return $result;
			}
			// 5.2.2 (mailbox full) and 5.2.x quota errors are usually temporary.
			$result['type'] = ( '5' === $m[1] && '2' !== $m[2] ) ? 'hard' : 'soft';
			return $result;
		}

		$text = strtolower( $decoded_body );
		if ( preg_match( '/\b([45])\.(\d{1,3})\.(\d{1,3})\b/', $text, $m ) ) {
			$result['status'] = "{$m[1]}.{$m[2]}.{$m[3]}";
		}

		foreach ( self::SOFT_PHRASES as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				$result['type']       = 'soft';
				$result['diagnostic'] = $result['diagnostic'] ?: $phrase;
				return $result;
			}
		}
		foreach ( self::HARD_PHRASES as $phrase ) {
			if ( str_contains( $text, $phrase ) ) {
				$result['type']       = 'hard';
				$result['diagnostic'] = $result['diagnostic'] ?: $phrase;
				return $result;
			}
		}
		if ( preg_match( '/\b55[0-4]\b/', $text ) || str_starts_with( $result['status'], '5.1' ) ) {
			$result['type'] = 'hard';
			return $result;
		}

		$result['type'] = 'soft'; // Recognised as a bounce but unclassified: be conservative.
		return $result;
	}

	/** @return array<string,string[]> Lower-case header name => values (unfolded). */
	public static function headers( string $head ): array {
		$head = preg_replace( "/\n[ \t]+/", ' ', $head ) ?? $head;
		$out  = array();
		foreach ( explode( "\n", $head ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$out[ strtolower( trim( substr( $line, 0, $pos ) ) ) ][] = trim( substr( $line, $pos + 1 ) );
		}
		return $out;
	}

	/** Decodes base64 / quoted-printable MIME parts so phrases and headers can be matched. */
	private static function decode_body( string $body ): string {
		$out = $body;
		if ( preg_match_all( '/Content-Transfer-Encoding:\s*(base64|quoted-printable)[^\n]*\n(?:[^\n]+\n)*\n(.*?)(?=\n--|\z)/is', $body, $parts, PREG_SET_ORDER ) ) {
			foreach ( $parts as $p ) {
				$decoded = 'base64' === strtolower( $p[1] )
					? (string) base64_decode( preg_replace( '/\s+/', '', $p[2] ) ?? '', false )
					: quoted_printable_decode( $p[2] );
				$out .= "\n" . str_replace( "\r\n", "\n", $decoded );
			}
		}
		return $out;
	}

	private static function decode_header( string $value ): string {
		if ( function_exists( 'iconv_mime_decode' ) ) {
			$decoded = @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $decoded ) {
				return $decoded;
			}
		}
		return $value;
	}
}
