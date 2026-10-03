<?php
namespace WPM\Send;

use WPM\Audience\Segment;
use WPM\Audience\SourceTable;
use WPM\Bounce\BounceProcessor;
use WPM\Campaign\CampaignRepo;
use WPM\Campaign\Renderer;
use WPM\Install\Schema;
use WPM\Settings;
use WPM\Suppressions;
use WPM\Template\HtmlToText;
use WPM\Template\TemplateRepo;
use WPM\Track\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Freezes a campaign's audience into `sends` rows, then drips them out in
 * rate-limited batches from a once-a-minute cron tick.
 */
final class Queue {

	private const LOCK = 'wpm_tick_lock';

	public static function renderer( array $overrides = array() ): Renderer {
		$s = Settings::all();
		return new Renderer(
			Token::instance(),
			rest_url( 'wpm/v1' ),
			array_merge(
				array(
					'inline_css'   => (bool) $s['inline_css'],
					'track_opens'  => (bool) $s['track_opens'],
					'track_clicks' => (bool) $s['track_clicks'],
				),
				$overrides
			)
		);
	}

	/** Builds the campaign's HTML layout (template + content + preheader + unsubscribe). */
	public static function layout( array $campaign ): string {
		$template = TemplateRepo::find( (int) $campaign['template_id'] );
		return Renderer::layout( $template['html'] ?? '', (string) $campaign['content'], (string) $campaign['preheader'], __( 'Unsubscribe', 'wp-mailer' ) );
	}

	/**
	 * Snapshots HTML + audience and switches the campaign to "sending".
	 *
	 * @return int|\WP_Error Number of queued recipients.
	 */
	public static function start( int $campaign_id ) {
		global $wpdb;
		$c = CampaignRepo::find( $campaign_id );
		if ( ! $c || ! in_array( $c['status'], CampaignRepo::EDITABLE, true ) ) {
			return new \WP_Error( 'wpm_state', __( 'This campaign cannot be sent in its current state.', 'wp-mailer' ) );
		}
		$source = SourceTable::from_settings();
		if ( ! $source ) {
			return new \WP_Error( 'wpm_source', __( 'Configure the audience table in Settings first.', 'wp-mailer' ) );
		}
		if ( '' === trim( (string) $c['subject'] ) ) {
			return new \WP_Error( 'wpm_subject', __( 'The campaign has no subject.', 'wp-mailer' ) );
		}

		// Snapshot the rendered layout so later template edits never change this campaign.
		$prepared = self::renderer()->prepare(
			self::layout( $c ),
			static fn( string $url ): int => CampaignRepo::link_id( $campaign_id, $url )
		);

		$sends  = Schema::table( 'sends' );
		$queued = 0;
		try {
			$source->each_recipient(
				Segment::decode( $c['segment'] ),
				static function ( array $batch ) use ( $wpdb, $sends, $campaign_id, &$queued ): void {
					$values = array();
					$params = array();
					foreach ( $batch as $r ) {
						$values[] = '(%d, %s, %s, %s, %s)';
						array_push( $params, $campaign_id, mb_substr( $r['ref'], 0, 191 ), $r['email'], wp_json_encode( $r['data'] ), 'queued' );
					}
					// INSERT IGNORE + UNIQUE(campaign_id, email) de-duplicates addresses.
					$queued += (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $sends (campaign_id, recipient_ref, email, merge_data, status) VALUES " . implode( ',', $values ), $params ) ); // phpcs:ignore WordPress.DB
				}
			);
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wpm_segment', $e->getMessage() );
		}

		if ( 0 === $queued ) {
			return new \WP_Error( 'wpm_empty', __( 'No deliverable recipients match this audience.', 'wp-mailer' ) );
		}

		CampaignRepo::update(
			$campaign_id,
			array(
				'html_snapshot' => $prepared,
				'status'        => 'sending',
				'started_at'    => current_time( 'mysql', true ),
			)
		);

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return $queued;
	}

	public static function pause( int $id ): void {
		$c = CampaignRepo::find( $id );
		if ( $c && 'sending' === $c['status'] ) {
			CampaignRepo::update( $id, array( 'status' => 'paused' ) );
		}
	}

	public static function resume( int $id ): void {
		$c = CampaignRepo::find( $id );
		if ( $c && 'paused' === $c['status'] ) {
			CampaignRepo::update( $id, array( 'status' => 'sending' ) );
		}
	}

	public static function cancel( int $id ): void {
		global $wpdb;
		$c = CampaignRepo::find( $id );
		if ( ! $c ) {
			return;
		}
		if ( 'scheduled' === $c['status'] ) {
			CampaignRepo::update(
				$id,
				array(
					'status'       => 'draft',
					'scheduled_at' => null,
				)
			);
			return;
		}
		if ( in_array( $c['status'], array( 'sending', 'paused' ), true ) ) {
			$wpdb->update(
				Schema::table( 'sends' ),
				array(
					'status' => 'skipped',
					'error'  => 'Campaign cancelled',
				),
				array(
					'campaign_id' => $id,
					'status'      => 'queued',
				)
			);
			CampaignRepo::update(
				$id,
				array(
					'status'      => 'cancelled',
					'finished_at' => current_time( 'mysql', true ),
				)
			);
		}
	}

	/** Cron entry point: start due campaigns, then send one rate-limited batch. */
	public static function tick(): void {
		if ( ! self::lock() ) {
			return;
		}
		try {
			$now = current_time( 'mysql', true );
			foreach ( CampaignRepo::all( 'scheduled' ) as $c ) {
				if ( $c['scheduled_at'] && $c['scheduled_at'] <= $now ) {
					$result = self::start( (int) $c['id'] );
					if ( is_wp_error( $result ) ) {
						CampaignRepo::update( (int) $c['id'], array( 'status' => 'draft' ) );
						update_option( 'wpm_last_error', $c['name'] . ': ' . $result->get_error_message(), false );
					}
				}
			}

			$budget   = (int) Settings::get( 'batch_size' );
			$limit    = (int) ini_get( 'max_execution_time' );
			$deadline = time() + ( $limit > 0 ? max( 10, min( 50, $limit - 10 ) ) : 50 );

			foreach ( array_reverse( CampaignRepo::all( 'sending' ) ) as $c ) {
				$budget -= self::process( (int) $c['id'], $budget, $deadline );
				if ( $budget <= 0 || time() >= $deadline ) {
					break;
				}
			}
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** @return int Messages attempted. */
	private static function process( int $campaign_id, int $limit, int $deadline ): int {
		global $wpdb;
		$c     = CampaignRepo::find( $campaign_id );
		$sends = Schema::table( 'sends' );
		if ( ! $c || $limit <= 0 ) {
			return 0;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, merge_data FROM $sends WHERE campaign_id = %d AND status = 'queued' ORDER BY id LIMIT %d", $campaign_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			self::finish( $campaign_id );
			return 0;
		}

		$s        = Settings::all();
		$token    = Token::instance();
		$renderer = self::renderer();
		$done     = 0;

		Mailer::begin_batch();
		foreach ( $rows as $row ) {
			if ( time() >= $deadline ) {
				break;
			}
			++$done;
			$send_id = (int) $row['id'];

			if ( Suppressions::is_suppressed( $row['email'] ) ) {
				$wpdb->update( $sends, array( 'status' => 'skipped', 'error' => 'Suppressed' ), array( 'id' => $send_id ) );
				continue;
			}

			$data  = json_decode( (string) $row['merge_data'], true ) ?: array( 'email' => $row['email'] );
			$html  = $renderer->personalize( (string) $c['html_snapshot'], $send_id, $data );
			$text  = '' !== trim( (string) $c['text_content'] )
				? Renderer::merge( strtr( (string) $c['text_content'], array( '{{unsubscribe_url}}' => $renderer->url( Token::UNSUBSCRIBE, $send_id ) ) ), $data, 'text' )
				: HtmlToText::convert( $html );
			$unsub = $renderer->url( Token::UNSUBSCRIBE, $send_id );

			$result = Mailer::send(
				$row['email'],
				Renderer::merge( (string) $c['subject'], $data, 'text' ),
				$html,
				$text,
				array(
					'from_name'   => $c['from_name'],
					'from_email'  => $c['from_email'],
					'reply_to'    => $c['reply_to'],
					'return_path' => $s['bounce_address'] ? $token->verp( $s['bounce_address'], $send_id ) : '',
					'headers'     => array(
						'List-Unsubscribe'      => '<' . $unsub . '>',
						'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
						'X-WPM-Send'            => $token->header_value( $send_id ),
					),
				)
			);

			if ( true === $result ) {
				$wpdb->update( $sends, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ), array( 'id' => $send_id ) );
				continue;
			}

			$error = $result->get_error_message();
			$wpdb->update( $sends, array( 'status' => 'failed', 'error' => mb_substr( $error, 0, 1000 ) ), array( 'id' => $send_id ) );
			if ( Mailer::is_permanent_rejection( $error ) ) {
				BounceProcessor::record( $send_id, 'hard', mb_substr( $error, 0, 255 ) );
			}
		}
		Mailer::end_batch();

		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sends WHERE campaign_id = %d AND status = 'queued'", $campaign_id ) ); // phpcs:ignore WordPress.DB
		if ( 0 === $left ) {
			self::finish( $campaign_id );
		}
		return $done;
	}

	private static function finish( int $campaign_id ): void {
		CampaignRepo::update(
			$campaign_id,
			array(
				'status'      => 'sent',
				'finished_at' => current_time( 'mysql', true ),
			)
		);
	}

	private static function lock(): bool {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		// A crashed run leaves a stale lock; take it over after 10 minutes.
		if ( (int) get_option( self::LOCK ) < time() - 600 ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', false );
		}
		return false;
	}
}
