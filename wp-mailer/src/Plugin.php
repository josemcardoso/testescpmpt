<?php
namespace WPM;

use WPM\Admin\Admin;
use WPM\Bounce\BounceProcessor;
use WPM\Install\Schema;
use WPM\Send\Queue;
use WPM\Track\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component into WordPress.
 */
final class Plugin {

	public const CRON_TICK    = 'wpm_tick';
	public const CRON_BOUNCES = 'wpm_check_bounces';
	public const CRON_DAILY   = 'wpm_daily_cleanup';

	public static function boot(): void {
		Schema::maybe_upgrade();

		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		self::ensure_cron();

		add_action( self::CRON_TICK, array( Queue::class, 'tick' ) );
		add_action( self::CRON_BOUNCES, array( BounceProcessor::class, 'run' ) );
		add_action( self::CRON_DAILY, array( Privacy::class, 'cleanup' ) );

		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_action( 'wp_mail_failed', array( Send\Mailer::class, 'capture_failure' ) );

		Privacy::register();

		if ( is_admin() ) {
			Admin::register();
		}
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['wpm_minute']      = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (WP Mailer)', 'wp-mailer' ),
		);
		$schedules['wpm_ten_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 10 minutes (WP Mailer)', 'wp-mailer' ),
		);
		return $schedules;
	}

	public static function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_TICK ) ) {
			wp_schedule_event( time() + 30, 'wpm_minute', self::CRON_TICK );
		}
		if ( ! wp_next_scheduled( self::CRON_BOUNCES ) ) {
			wp_schedule_event( time() + 120, 'wpm_ten_minutes', self::CRON_BOUNCES );
		}
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	public static function deactivate(): void {
		foreach ( array( self::CRON_TICK, self::CRON_BOUNCES, self::CRON_DAILY ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
