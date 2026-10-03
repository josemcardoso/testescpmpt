<?php
namespace WPM;

use WPM\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Addresses that must never be mailed again (hard bounces, unsubscribes, manual).
 */
final class Suppressions {

	public const REASONS = array(
		'hard_bounce' => 'Hard bounce',
		'soft_bounce' => 'Repeated soft bounces',
		'unsubscribe' => 'Unsubscribed',
		'complaint'   => 'Spam complaint',
		'manual'      => 'Added manually',
	);

	public static function add( string $email, string $reason, int $campaign_id = 0, string $note = '' ): void {
		global $wpdb;
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Schema::table( 'suppressions' ) . ' (email, reason, campaign_id, note, created_at) VALUES (%s, %s, %d, %s, %s)', // phpcs:ignore WordPress.DB
				$email,
				isset( self::REASONS[ $reason ] ) ? $reason : 'manual',
				$campaign_id,
				mb_substr( $note, 0, 255 ),
				current_time( 'mysql', true )
			)
		);
	}

	public static function remove( string $email ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'suppressions' ), array( 'email' => strtolower( trim( $email ) ) ) );
	}

	public static function is_suppressed( string $email ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Schema::table( 'suppressions' ) . ' WHERE email = %s', strtolower( trim( $email ) ) ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string,true> Lower-case email => true, for fast lookups. */
	public static function all_emails(): array {
		global $wpdb;
		$emails = (array) $wpdb->get_col( 'SELECT email FROM ' . Schema::table( 'suppressions' ) ); // phpcs:ignore WordPress.DB
		return array_fill_keys( array_map( 'strtolower', $emails ), true );
	}

	public static function page( string $search, int $per_page, int $page ): array {
		global $wpdb;
		$table  = Schema::table( 'suppressions' );
		$where  = '1=1';
		$params = array();
		if ( '' !== $search ) {
			$where    = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params ) : "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array( $rows, $total );
	}
}
