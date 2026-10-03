<?php
namespace WPM\Bounce;

use WPM\Install\Schema;
use WPM\Settings;
use WPM\Suppressions;
use WPM\Track\Token;
use WPM\Track\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the bounce mailbox over IMAP, matches each bounce to the exact send
 * (via the VERP return path or the X-WPM-Send header) and suppresses
 * addresses that hard-bounce or keep soft-bouncing.
 */
final class BounceProcessor {

	private const MAX_PER_RUN = 200;
	private const LOCK        = 'wpm_bounce_lock';

	/** Cron entry point. */
	public static function run(): void {
		$s = Settings::all();
		if ( ! $s['imap_enabled'] || ! $s['imap_host'] ) {
			return;
		}
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			if ( (int) get_option( self::LOCK ) > time() - 900 ) {
				return;
			}
			update_option( self::LOCK, time(), false );
		}
		try {
			$stats = self::poll( $s );
			update_option( 'wpm_bounce_last_run', array_merge( $stats, array( 'time' => time(), 'error' => '' ) ), false );
		} catch ( \Throwable $e ) {
			update_option(
				'wpm_bounce_last_run',
				array(
					'time'  => time(),
					'error' => $e->getMessage(),
				),
				false
			);
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** Connects and logs in only; used by the "Test connection" button. */
	public static function test_connection( array $s ): string {
		$client = new ImapClient();
		$client->connect( $s['imap_host'], (int) $s['imap_port'], $s['imap_encryption'] );
		$client->login( $s['imap_user'], $s['imap_pass'] );
		$count = $client->select( $s['imap_folder'] ?: 'INBOX' );
		$unseen = count( $client->search_unseen() );
		$client->logout();
		/* translators: 1: folder, 2: total messages, 3: unread messages */
		return sprintf( __( 'Connected. %1$s has %2$d messages (%3$d unread).', 'wp-mailer' ), $s['imap_folder'] ?: 'INBOX', $count, $unseen );
	}

	public static function poll( array $s, ?ImapClient $client = null ): array {
		$client = $client ?? new ImapClient();
		$client->connect( $s['imap_host'], (int) $s['imap_port'], $s['imap_encryption'] );
		$client->login( $s['imap_user'], $s['imap_pass'] );
		$client->select( $s['imap_folder'] ?: 'INBOX' );

		$processed_folder = trim( (string) $s['imap_processed'] );
		if ( '' !== $processed_folder ) {
			$client->create_mailbox( $processed_folder );
		}

		$stats = array(
			'checked'    => 0,
			'hard'       => 0,
			'soft'       => 0,
			'unmatched'  => 0,
			'auto_reply' => 0,
			'ignored'    => 0,
		);

		foreach ( array_slice( $client->search_unseen(), 0, self::MAX_PER_RUN ) as $uid ) {
			++$stats['checked'];
			$result = self::handle_message( $client->fetch( $uid ) );
			$action = $result['action'];

			if ( 'matched' === $action ) {
				++$stats[ $result['type'] ];
			} else {
				++$stats[ $action ];
			}

			// Unmatched bounces and unrelated mail stay in the inbox (marked read) for a human to look at.
			if ( '' !== $processed_folder && in_array( $action, array( 'matched', 'auto_reply' ), true ) ) {
				$client->move( $uid, $processed_folder );
			} else {
				$client->mark_seen( $uid );
			}
		}

		$client->logout();
		return $stats;
	}

	/**
	 * @return array{action:string,send_id?:int,type?:string}
	 */
	public static function handle_message( string $raw, ?Token $token = null ): array {
		$token = $token ?? Token::instance();
		$p     = DsnParser::parse( $raw );

		if ( ! $p['is_bounce'] ) {
			return array( 'action' => $p['is_auto_reply'] ? 'auto_reply' : 'ignored' );
		}

		$send_id = null;
		foreach ( $p['envelope_to'] as $address ) {
			$send_id = $token->parse_verp( $address );
			if ( $send_id ) {
				break;
			}
		}
		if ( ! $send_id && '' !== $p['wpm_header'] ) {
			$send_id = $token->parse_header_value( $p['wpm_header'] );
		}
		if ( ! $send_id ) {
			// Last resort: a signed VERP address quoted anywhere in the bounce.
			$send_id = $token->parse_verp( $raw );
		}
		if ( ! $send_id ) {
			return array( 'action' => 'unmatched' );
		}

		$type = $p['type'] ?? 'soft';
		self::record( $send_id, $type, trim( $p['status'] . ' ' . $p['diagnostic'] ) );

		return array(
			'action'  => 'matched',
			'send_id' => $send_id,
			'type'    => $type,
		);
	}

	public static function record( int $send_id, string $type, string $detail ): void {
		global $wpdb;
		$send = Tracker::send( $send_id );
		if ( ! $send || 'hard' === $send['bounce_type'] ) {
			return;
		}
		$type = 'hard' === $type ? 'hard' : 'soft';

		$wpdb->update(
			Schema::table( 'sends' ),
			array(
				'status'      => 'bounced',
				'bounce_type' => $type,
				'bounced_at'  => current_time( 'mysql', true ),
				'error'       => mb_substr( $detail, 0, 1000 ),
			),
			array( 'id' => $send_id )
		);
		Tracker::event( $send, 'bounce', null, $type . ( '' !== $detail ? ': ' . $detail : '' ) );

		if ( 'hard' === $type ) {
			Suppressions::add( $send['email'], 'hard_bounce', (int) $send['campaign_id'], $detail );
			return;
		}

		// Suppress after N soft bounces on different campaigns within 90 days.
		$limit = max( 1, (int) Settings::get( 'soft_bounce_limit' ) );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT campaign_id) FROM ' . Schema::table( 'sends' ) . " WHERE email = %s AND bounce_type = 'soft' AND bounced_at > %s", // phpcs:ignore WordPress.DB
				$send['email'],
				gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS )
			)
		);
		if ( $count >= $limit ) {
			/* translators: %d: number of soft bounces */
			Suppressions::add( $send['email'], 'soft_bounce', (int) $send['campaign_id'], sprintf( __( '%d soft bounces in 90 days', 'wp-mailer' ), $count ) );
		}
	}
}
