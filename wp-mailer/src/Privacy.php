<?php
namespace WPM;

use WPM\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * GDPR helpers: personal-data export/erase (Tools → Export/Erase Personal Data),
 * suggested privacy-policy text and retention cleanup of tracking details.
 */
final class Privacy {

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
	}

	public static function add_exporter( array $exporters ): array {
		$exporters['wp-mailer'] = array(
			'exporter_friendly_name' => __( 'WP Mailer', 'wp-mailer' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function add_eraser( array $erasers ): array {
		$erasers['wp-mailer'] = array(
			'eraser_friendly_name' => __( 'WP Mailer', 'wp-mailer' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, c.subject FROM ' . Schema::table( 'sends' ) . ' s LEFT JOIN ' . Schema::table( 'campaigns' ) . ' c ON c.id = s.campaign_id WHERE s.email = %s ORDER BY s.id LIMIT 100 OFFSET %d', // phpcs:ignore WordPress.DB
				strtolower( $email ),
				( $page - 1 ) * 100
			),
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $r ) {
			$items[] = array(
				'group_id'    => 'wpm-emails',
				'group_label' => __( 'Emails sent', 'wp-mailer' ),
				'item_id'     => 'wpm-send-' . $r['id'],
				'data'        => array(
					array( 'name' => __( 'Subject', 'wp-mailer' ), 'value' => (string) $r['subject'] ),
					array( 'name' => __( 'Sent', 'wp-mailer' ), 'value' => (string) $r['sent_at'] ),
					array( 'name' => __( 'First opened', 'wp-mailer' ), 'value' => (string) $r['opened_at'] ),
					array( 'name' => __( 'Opens', 'wp-mailer' ), 'value' => (string) $r['open_count'] ),
					array( 'name' => __( 'Clicks', 'wp-mailer' ), 'value' => (string) $r['click_count'] ),
					array( 'name' => __( 'Bounce', 'wp-mailer' ), 'value' => (string) $r['bounce_type'] ),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( (array) $rows ) < 100,
		);
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$email = strtolower( $email );
		$sends = Schema::table( 'sends' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $sends WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			$wpdb->delete( Schema::table( 'events' ), array( 'send_id' => (int) $id ) );
			$wpdb->update(
				$sends,
				array(
					'email'      => 'erased-' . $id . '@invalid',
					'merge_data' => null,
					'recipient_ref' => '',
				),
				array( 'id' => (int) $id )
			);
		}
		$messages = array();
		$retained = Suppressions::is_suppressed( $email );
		if ( $retained ) {
			$messages[] = __( 'The address was kept on the suppression list so it is never emailed again.', 'wp-mailer' );
		}
		return array(
			'items_removed'  => count( $ids ) > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'WP Mailer', 'wp-mailer' ),
			'<p>' . esc_html__( 'Our newsletters contain a small tracking image and tracked links so we can tell whether an email was opened and which links were clicked. We store the time of each open and click, your browser user agent and a one-way hash of your IP address. Every email includes a link to unsubscribe.', 'wp-mailer' ) . '</p>'
		);
	}

	/** Daily: strip user agents and IP hashes from events older than the retention period. */
	public static function cleanup(): void {
		global $wpdb;
		$days = (int) Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'events' ) . " SET user_agent = '', ip_hash = '' WHERE created_at < %s AND (user_agent <> '' OR ip_hash <> '')", // phpcs:ignore WordPress.DB
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}
}
