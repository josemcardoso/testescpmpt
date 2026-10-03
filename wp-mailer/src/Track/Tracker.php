<?php
namespace WPM\Track;

use WPM\Install\Schema;
use WPM\Suppressions;

defined( 'ABSPATH' ) || exit;

/**
 * Records opens, clicks and unsubscribes against a `sends` row.
 */
final class Tracker {

	public static function send( int $send_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'sends' ) . ' WHERE id = %d', $send_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/**
	 * Apple Mail Privacy Protection and security scanners fetch images without
	 * a human. We still count those opens but flag them, so reports can show
	 * both "all opens" and "likely human opens".
	 */
	public static function looks_automated( string $user_agent, ?string $sent_at ): bool {
		$ua = trim( $user_agent );
		if ( '' === $ua || 'Mozilla/5.0' === $ua ) {
			return true; // Apple MPP proxy sends a bare "Mozilla/5.0".
		}
		if ( preg_match( '/bot|crawler|spider|scanner|barracuda|mimecast|proofpoint|symantec|python|curl|wget|go-http|java\//i', $ua ) ) {
			return true;
		}
		return $sent_at && ( time() - strtotime( $sent_at . ' UTC' ) ) < 5;
	}

	public static function open( int $send_id ): void {
		global $wpdb;
		$send = self::send( $send_id );
		if ( ! $send ) {
			return;
		}
		$ua      = self::user_agent();
		$machine = self::looks_automated( $ua, $send['sent_at'] );
		$now     = current_time( 'mysql', true );

		self::event( $send, 'open', null, '', $machine );

		$sql = 'UPDATE ' . Schema::table( 'sends' ) . ' SET open_count = open_count + 1, opened_at = COALESCE(opened_at, %s)';
		if ( ! $machine ) {
			$sql .= ', human_opened_at = COALESCE(human_opened_at, %s)';
		}
		$sql .= ' WHERE id = %d';
		$wpdb->query( $machine ? $wpdb->prepare( $sql, $now, $send_id ) : $wpdb->prepare( $sql, $now, $now, $send_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array|null Stored URL + recipient merge data, or null if the link is unknown.
	 */
	public static function click( int $send_id, int $link_id ): ?array {
		global $wpdb;
		$send = self::send( $send_id );
		$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'links' ) . ' WHERE id = %d', $link_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $send || ! $link || (int) $link['campaign_id'] !== (int) $send['campaign_id'] ) {
			return null;
		}

		$ua      = self::user_agent();
		$machine = (bool) preg_match( '/bot|scanner|barracuda|mimecast|proofpoint|safelinks|python|curl|wget/i', $ua );
		$now     = current_time( 'mysql', true );

		self::event( $send, 'click', $link_id, '', $machine );

		if ( ! $machine ) {
			// A real click proves the email was opened, even if images were blocked.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Schema::table( 'sends' ) . ' SET click_count = click_count + 1, clicked_at = COALESCE(clicked_at, %s), opened_at = COALESCE(opened_at, %s), human_opened_at = COALESCE(human_opened_at, %s) WHERE id = %d', // phpcs:ignore WordPress.DB
					$now,
					$now,
					$now,
					$send_id
				)
			);
		}

		return array(
			'url'  => $link['url'],
			'data' => json_decode( (string) $send['merge_data'], true ) ?: array( 'email' => $send['email'] ),
		);
	}

	public static function unsubscribe( int $send_id ): bool {
		global $wpdb;
		$send = self::send( $send_id );
		if ( ! $send ) {
			return false;
		}
		if ( ! $send['unsubscribed_at'] ) {
			$wpdb->update( Schema::table( 'sends' ), array( 'unsubscribed_at' => current_time( 'mysql', true ) ), array( 'id' => $send_id ) );
			self::event( $send, 'unsubscribe' );
		}
		Suppressions::add( $send['email'], 'unsubscribe', (int) $send['campaign_id'] );
		do_action( 'wpm_unsubscribed', $send['email'], (int) $send['campaign_id'], $send );
		return true;
	}

	public static function event( array $send, string $type, ?int $link_id = null, string $detail = '', bool $machine = false ): void {
		global $wpdb;
		$from_recipient = in_array( $type, array( 'open', 'click', 'unsubscribe' ), true );
		$wpdb->insert(
			Schema::table( 'events' ),
			array(
				'send_id'     => (int) $send['id'],
				'campaign_id' => (int) $send['campaign_id'],
				'type'        => $type,
				'link_id'     => $link_id,
				'detail'      => mb_substr( $detail, 0, 255 ),
				'is_machine'  => $machine ? 1 : 0,
				'ip_hash'     => $from_recipient ? self::ip_hash() : '',
				'user_agent'  => $from_recipient ? mb_substr( self::user_agent(), 0, 255 ) : '',
				'created_at'  => current_time( 'mysql', true ),
			)
		);
	}

	private static function user_agent(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/** IPs are never stored in clear: a salted, truncated hash is enough to spot repeats. */
	private static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? '' : substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 16 );
	}
}
