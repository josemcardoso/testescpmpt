<?php
namespace WPM\Template;

use WPM\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Saved HTML email templates, with a short revision history per template.
 */
final class TemplateRepo {

	public const MAX_REVISIONS = 20;

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'templates' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function all(): array {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT id, name, created_at, updated_at, CHAR_LENGTH(html) AS size FROM ' . Schema::table( 'templates' ) . ' ORDER BY name', ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Inserts or updates a template. Returns the template ID.
	 */
	public static function save( int $id, string $name, string $html, string $text = '' ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array(
			'name'         => '' === trim( $name ) ? __( 'Untitled template', 'wp-mailer' ) : $name,
			'html'         => $html,
			'text_version' => $text,
			'updated_at'   => $now,
		);

		$existing = $id ? self::find( $id ) : null;
		if ( $existing ) {
			if ( $existing['html'] !== $html ) {
				self::add_revision( $id, $existing['html'] );
			}
			$wpdb->update( Schema::table( 'templates' ), $data, array( 'id' => $id ) );
			return $id;
		}

		$data['created_at'] = $now;
		$data['created_by'] = get_current_user_id();
		$wpdb->insert( Schema::table( 'templates' ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function duplicate( int $id ): int {
		$t = self::find( $id );
		if ( ! $t ) {
			return 0;
		}
		/* translators: %s: template name */
		return self::save( 0, sprintf( __( '%s (copy)', 'wp-mailer' ), $t['name'] ), $t['html'], (string) $t['text_version'] );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'templates' ), array( 'id' => $id ) );
		$wpdb->delete( Schema::table( 'template_revisions' ), array( 'template_id' => $id ) );
	}

	private static function add_revision( int $template_id, string $html ): void {
		global $wpdb;
		$table = Schema::table( 'template_revisions' );
		$wpdb->insert(
			$table,
			array(
				'template_id' => $template_id,
				'html'        => $html,
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		$keep = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE template_id = %d ORDER BY id DESC LIMIT %d", $template_id, self::MAX_REVISIONS ) ); // phpcs:ignore WordPress.DB
		if ( $keep ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE template_id = %d AND id < %d", $template_id, min( $keep ) ) ); // phpcs:ignore WordPress.DB
		}
	}

	public static function revisions( int $template_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_by, created_at, CHAR_LENGTH(html) AS size FROM ' . Schema::table( 'template_revisions' ) . ' WHERE template_id = %d ORDER BY id DESC', $template_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Restores a revision; the current HTML becomes a revision itself. */
	public static function restore_revision( int $revision_id ): int {
		global $wpdb;
		$rev = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'template_revisions' ) . ' WHERE id = %d', $revision_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$t   = $rev ? self::find( (int) $rev['template_id'] ) : null;
		if ( ! $t ) {
			return 0;
		}
		return self::save( (int) $t['id'], $t['name'], $rev['html'], (string) $t['text_version'] );
	}

	/** Problems worth warning about when saving a template. */
	public static function lint( string $html ): array {
		$warnings = array();
		if ( ! str_contains( $html, '{{content}}' ) ) {
			$warnings[] = __( 'No {{content}} placeholder: campaign content will be added at the end of the body.', 'wp-mailer' );
		}
		if ( ! str_contains( $html, '{{unsubscribe_url}}' ) ) {
			$warnings[] = __( 'No {{unsubscribe_url}} link: an unsubscribe footer will be added automatically when sending.', 'wp-mailer' );
		}
		if ( preg_match( '/<(script|form|iframe)\b/i', $html ) ) {
			$warnings[] = __( 'Scripts, forms and iframes are stripped or blocked by most email clients.', 'wp-mailer' );
		}
		if ( preg_match( '/\s(src|href)\s*=\s*["\'](?!https?:|mailto:|tel:|#|\{\{)/i', $html ) ) {
			$warnings[] = __( 'Some images or links use relative URLs; email needs absolute https:// URLs.', 'wp-mailer' );
		}
		return $warnings;
	}

	/** Installs the starter templates shipped in /templates on first activation. */
	public static function seed_starters(): void {
		global $wpdb;
		if ( get_option( 'wpm_starters_seeded' ) ) {
			return;
		}
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'templates' ) ); // phpcs:ignore WordPress.DB
		if ( 0 === $count ) {
			$names = array(
				'newsletter.html'   => 'Newsletter (one column)',
				'announcement.html' => 'Announcement',
				'plain.html'        => 'Plain',
			);
			foreach ( $names as $file => $name ) {
				$path = WPM_DIR . 'templates/' . $file;
				if ( is_readable( $path ) ) {
					self::save( 0, $name, (string) file_get_contents( $path ) );
				}
			}
		}
		update_option( 'wpm_starters_seeded', 1, false );
	}
}
