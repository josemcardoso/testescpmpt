<?php
namespace WPM\Campaign;

use WPM\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Campaign storage and per-campaign statistics.
 *
 * Status flow: draft -> scheduled -> sending <-> paused -> sent
 *                                         \-> cancelled
 */
final class CampaignRepo {

	public const STATUSES = array(
		'draft'     => 'Draft',
		'scheduled' => 'Scheduled',
		'sending'   => 'Sending',
		'paused'    => 'Paused',
		'sent'      => 'Sent',
		'cancelled' => 'Cancelled',
	);

	public const EDITABLE = array( 'draft', 'scheduled' );

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'campaigns' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function all( string $status = '' ): array {
		global $wpdb;
		$table = Schema::table( 'campaigns' );
		$cols  = 'id, name, subject, status, scheduled_at, started_at, finished_at, created_at, updated_at';
		if ( '' !== $status ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT $cols FROM $table WHERE status = %s ORDER BY id DESC", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return (array) $wpdb->get_results( "SELECT $cols FROM $table ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function save( int $id, array $fields ): int {
		global $wpdb;
		$allowed = array( 'name', 'subject', 'preheader', 'from_name', 'from_email', 'reply_to', 'template_id', 'content', 'text_content', 'segment', 'status', 'scheduled_at' );
		$data    = array_intersect_key( $fields, array_flip( $allowed ) );

		$data['updated_at'] = current_time( 'mysql', true );
		if ( $id && self::find( $id ) ) {
			$wpdb->update( Schema::table( 'campaigns' ), $data, array( 'id' => $id ) );
			return $id;
		}
		$data['created_at'] = $data['updated_at'];
		$data['created_by'] = get_current_user_id();
		$data['status']     = $data['status'] ?? 'draft';
		$wpdb->insert( Schema::table( 'campaigns' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Schema::table( 'campaigns' ), $data, array( 'id' => $id ) );
	}

	public static function duplicate( int $id ): int {
		$c = self::find( $id );
		if ( ! $c ) {
			return 0;
		}
		$copy           = $c;
		/* translators: %s: campaign name */
		$copy['name']   = sprintf( __( '%s (copy)', 'wp-mailer' ), $c['name'] );
		$copy['status'] = 'draft';
		unset( $copy['id'], $copy['scheduled_at'] );
		return self::save( 0, $copy );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		foreach ( array( 'sends', 'links', 'events' ) as $t ) {
			$wpdb->delete( Schema::table( $t ), array( 'campaign_id' => $id ) );
		}
		$wpdb->delete( Schema::table( 'campaigns' ), array( 'id' => $id ) );
	}

	/** Returns the stored link ID for a URL in a campaign, inserting it if new. */
	public static function link_id( int $campaign_id, string $url ): int {
		global $wpdb;
		$table = Schema::table( 'links' );
		$hash  = sha1( $url );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE campaign_id = %d AND url_hash = %s", $campaign_id, $hash ) ); // phpcs:ignore WordPress.DB
		if ( $id ) {
			return (int) $id;
		}
		$wpdb->insert(
			$table,
			array(
				'campaign_id' => $campaign_id,
				'url'         => $url,
				'url_hash'    => $hash,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Light per-campaign counters for the campaign list, in one query. */
	public static function summaries(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT campaign_id, COUNT(*) AS total, SUM(status IN (\'sent\',\'bounced\')) AS sent,
				SUM(opened_at IS NOT NULL) AS opened, SUM(clicked_at IS NOT NULL) AS clicked,
				SUM(bounce_type <> \'\') AS bounced, SUM(bounce_type = \'hard\') AS hard
			FROM ' . Schema::table( 'sends' ) . ' GROUP BY campaign_id', // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['campaign_id'] ] = array_map( 'intval', $r );
		}
		return $out;
	}

	public static function stats( int $id ): array {
		global $wpdb;
		$sends  = Schema::table( 'sends' );
		$events = Schema::table( 'events' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(status = 'queued') AS queued,
					SUM(status IN ('sent','bounced')) AS sent,
					SUM(status = 'failed') AS failed,
					SUM(opened_at IS NOT NULL) AS opened,
					SUM(human_opened_at IS NOT NULL) AS human_opened,
					SUM(open_count) AS opens_total,
					SUM(clicked_at IS NOT NULL) AS clicked,
					SUM(click_count) AS clicks_total,
					SUM(bounce_type = 'hard') AS hard_bounces,
					SUM(bounce_type = 'soft') AS soft_bounces,
					SUM(unsubscribed_at IS NOT NULL) AS unsubscribed
				FROM $sends WHERE campaign_id = %d", // phpcs:ignore WordPress.DB
				$id
			),
			ARRAY_A
		) ?: array();

		$s              = array_map( 'intval', $row );
		$s['bounced']   = ( $s['hard_bounces'] ?? 0 ) + ( $s['soft_bounces'] ?? 0 );
		$s['delivered'] = max( 0, ( $s['sent'] ?? 0 ) - ( $s['hard_bounces'] ?? 0 ) );
		$base           = max( 1, $s['delivered'] );
		$s['open_rate']        = round( 100 * ( $s['opened'] ?? 0 ) / $base, 1 );
		$s['human_open_rate']  = round( 100 * ( $s['human_opened'] ?? 0 ) / $base, 1 );
		$s['click_rate']       = round( 100 * ( $s['clicked'] ?? 0 ) / $base, 1 );
		$s['click_to_open']    = round( 100 * ( $s['clicked'] ?? 0 ) / max( 1, $s['opened'] ?? 0 ), 1 );
		$s['bounce_rate']      = round( 100 * $s['bounced'] / max( 1, $s['sent'] ?? 0 ), 1 );
		$s['unsubscribe_rate'] = round( 100 * ( $s['unsubscribed'] ?? 0 ) / $base, 1 );

		$s['links'] = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.url, COUNT(e.id) AS clicks, COUNT(DISTINCT e.send_id) AS unique_clicks
				FROM ' . Schema::table( 'links' ) . " l
				LEFT JOIN $events e ON e.link_id = l.id AND e.type = 'click'
				WHERE l.campaign_id = %d GROUP BY l.id, l.url ORDER BY unique_clicks DESC, l.id", // phpcs:ignore WordPress.DB
				$id
			),
			ARRAY_A
		);

		$s['timeline'] = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00') AS hour,
					SUM(type = 'open') AS opens, SUM(type = 'click') AS clicks
				FROM $events WHERE campaign_id = %d AND type IN ('open','click')
				GROUP BY hour ORDER BY hour LIMIT 168", // phpcs:ignore WordPress.DB
				$id
			),
			ARRAY_A
		);

		return $s;
	}

	/**
	 * Paged recipient list for reports.
	 *
	 * @param string $filter all|opened|not_opened|clicked|bounced|unsubscribed|failed
	 */
	public static function recipients( int $id, string $filter, string $search, int $per_page, int $page ): array {
		global $wpdb;
		$sends  = Schema::table( 'sends' );
		$where  = array( 'campaign_id = %d' );
		$params = array( $id );

		$filters = array(
			'opened'       => 'opened_at IS NOT NULL',
			'not_opened'   => "opened_at IS NULL AND status = 'sent'",
			'clicked'      => 'clicked_at IS NOT NULL',
			'bounced'      => "bounce_type <> ''",
			'unsubscribed' => 'unsubscribed_at IS NOT NULL',
			'failed'       => "status = 'failed'",
			'queued'       => "status = 'queued'",
		);
		if ( isset( $filters[ $filter ] ) ) {
			$where[] = $filters[ $filter ];
		}
		if ( '' !== $search ) {
			$where[]  = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql_where = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sends WHERE $sql_where", $params ) ); // phpcs:ignore WordPress.DB
		if ( $per_page <= 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $sends WHERE $sql_where ORDER BY id", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $sends WHERE $sql_where ORDER BY id LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return array( (array) $rows, $total );
	}
}
