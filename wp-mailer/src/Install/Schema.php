<?php
namespace WPM\Install;

use WPM\Template\TemplateRepo;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 */
final class Schema {

	public const CAP_MANAGE    = 'wpm_manage';
	public const CAP_TEMPLATES = 'wpm_manage_templates';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wpm_' . $name;
	}

	public static function activate(): void {
		self::install();

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
			$admin->add_cap( self::CAP_TEMPLATES );
		}

		if ( ! get_option( 'wpm_token_key' ) ) {
			add_option( 'wpm_token_key', bin2hex( random_bytes( 32 ) ), '', false );
		}

		TemplateRepo::seed_starters();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'wpm_db_version' ) !== WPM_DB_VERSION ) {
			self::activate();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$campaigns    = self::table( 'campaigns' );
		$sends        = self::table( 'sends' );
		$links        = self::table( 'links' );
		$events       = self::table( 'events' );
		$suppressions = self::table( 'suppressions' );
		$templates    = self::table( 'templates' );
		$revisions    = self::table( 'template_revisions' );

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE $campaigns (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  preheader varchar(255) NOT NULL DEFAULT '',
  from_name varchar(255) NOT NULL DEFAULT '',
  from_email varchar(191) NOT NULL DEFAULT '',
  reply_to varchar(191) NOT NULL DEFAULT '',
  template_id bigint(20) unsigned NOT NULL DEFAULT 0,
  content longtext NULL,
  text_content longtext NULL,
  segment longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  html_snapshot longtext NULL,
  scheduled_at datetime NULL,
  started_at datetime NULL,
  finished_at datetime NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;
CREATE TABLE $sends (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  recipient_ref varchar(191) NOT NULL DEFAULT '',
  email varchar(191) NOT NULL,
  merge_data longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  error text NULL,
  sent_at datetime NULL,
  opened_at datetime NULL,
  human_opened_at datetime NULL,
  open_count int(10) unsigned NOT NULL DEFAULT 0,
  clicked_at datetime NULL,
  click_count int(10) unsigned NOT NULL DEFAULT 0,
  bounced_at datetime NULL,
  bounce_type varchar(10) NOT NULL DEFAULT '',
  unsubscribed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_email (campaign_id,email),
  KEY campaign_status (campaign_id,status),
  KEY email (email)
) $charset;
CREATE TABLE $links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  url text NOT NULL,
  url_hash char(40) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_url (campaign_id,url_hash)
) $charset;
CREATE TABLE $events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  send_id bigint(20) unsigned NOT NULL,
  campaign_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  link_id bigint(20) unsigned NULL,
  detail varchar(255) NOT NULL DEFAULT '',
  is_machine tinyint(1) NOT NULL DEFAULT 0,
  ip_hash char(16) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY send_type (send_id,type),
  KEY campaign_type (campaign_id,type),
  KEY created_at (created_at)
) $charset;
CREATE TABLE $suppressions (
  email varchar(191) NOT NULL,
  reason varchar(20) NOT NULL,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (email)
) $charset;
CREATE TABLE $templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  html longtext NOT NULL,
  text_version longtext NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset;
CREATE TABLE $revisions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  template_id bigint(20) unsigned NOT NULL,
  html longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY template_id (template_id)
) $charset;"
		);

		update_option( 'wpm_db_version', WPM_DB_VERSION );
	}

	public static function drop_all(): void {
		global $wpdb;
		foreach ( array( 'campaigns', 'sends', 'links', 'events', 'suppressions', 'templates', 'template_revisions' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB
		}
	}
}
