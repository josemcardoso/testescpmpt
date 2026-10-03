<?php
namespace WPM;

use WPM\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single `wpm_settings` option.
 */
final class Settings {

	public const OPTION = 'wpm_settings';

	/** Fields stored encrypted at rest. */
	public const SECRETS = array( 'smtp_pass', 'imap_pass' );

	/** On/off fields: an unchecked box is absent from the form post. */
	public const CHECKBOXES = array( 'smtp_enabled', 'smtp_auth', 'imap_enabled', 'inline_css', 'track_opens', 'track_clicks', 'delete_on_uninstall' );

	public static function defaults(): array {
		return array(
			'from_name'           => get_bloginfo( 'name' ),
			'from_email'          => get_option( 'admin_email' ),
			'reply_to'            => '',
			'smtp_enabled'        => 0,
			'smtp_host'           => '',
			'smtp_port'           => 587,
			'smtp_encryption'     => 'tls',
			'smtp_auth'           => 1,
			'smtp_user'           => '',
			'smtp_pass'           => '',
			'bounce_address'      => '',
			'imap_enabled'        => 0,
			'imap_host'           => '',
			'imap_port'           => 993,
			'imap_encryption'     => 'ssl',
			'imap_user'           => '',
			'imap_pass'           => '',
			'imap_folder'         => 'INBOX',
			'imap_processed'      => 'WPM-Processed',
			'source_table'        => '',
			'source_id_col'       => '',
			'source_email_col'    => '',
			'batch_size'          => 50,
			'inline_css'          => 1,
			'track_opens'         => 1,
			'track_clicks'        => 1,
			'soft_bounce_limit'   => 3,
			'retention_days'      => 180,
			'delete_on_uninstall' => 0,
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		foreach ( self::SECRETS as $key ) {
			$all[ $key ] = Crypto::decrypt( (string) $all[ $key ] );
		}
		return $all;
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Saves sanitized values. Empty secret fields keep the stored secret.
	 */
	public static function save( array $input ): void {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$out     = array();

		foreach ( self::defaults() as $key => $default ) {
			if ( in_array( $key, self::CHECKBOXES, true ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
				continue;
			}

			$value = $input[ $key ] ?? ( $current[ $key ] ?? $default );

			if ( in_array( $key, self::SECRETS, true ) ) {
				$value       = (string) $value;
				$out[ $key ] = '' === $value ? ( $current[ $key ] ?? '' ) : Crypto::encrypt( $value );
				continue;
			}

			if ( is_int( $default ) ) {
				$out[ $key ] = max( 0, (int) $value );
			} elseif ( str_ends_with( $key, 'email' ) || 'reply_to' === $key || 'bounce_address' === $key ) {
				$out[ $key ] = sanitize_email( (string) $value );
			} else {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$out['batch_size'] = max( 1, min( 1000, $out['batch_size'] ) );
		update_option( self::OPTION, $out, false );
	}
}
