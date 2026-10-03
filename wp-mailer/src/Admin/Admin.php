<?php
namespace WPM\Admin;

use WPM\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, assets, notices and shared helpers for the admin pages.
 */
final class Admin {

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );

		CampaignsPage::register();
		TemplatesPage::register();
		SettingsPage::register();
		SuppressionsPage::register();
	}

	public static function menu(): void {
		add_menu_page( __( 'Mailing List', 'wp-mailer' ), __( 'Mailing List', 'wp-mailer' ), Schema::CAP_MANAGE, 'wpm-campaigns', array( CampaignsPage::class, 'render' ), 'dashicons-email-alt', 26 );
		add_submenu_page( 'wpm-campaigns', __( 'Campaigns', 'wp-mailer' ), __( 'Campaigns', 'wp-mailer' ), Schema::CAP_MANAGE, 'wpm-campaigns', array( CampaignsPage::class, 'render' ) );
		add_submenu_page( 'wpm-campaigns', __( 'Templates', 'wp-mailer' ), __( 'Templates', 'wp-mailer' ), Schema::CAP_TEMPLATES, 'wpm-templates', array( TemplatesPage::class, 'render' ) );
		add_submenu_page( 'wpm-campaigns', __( 'Suppression list', 'wp-mailer' ), __( 'Suppression list', 'wp-mailer' ), Schema::CAP_MANAGE, 'wpm-suppressions', array( SuppressionsPage::class, 'render' ) );
		add_submenu_page( 'wpm-campaigns', __( 'Settings', 'wp-mailer' ), __( 'Settings', 'wp-mailer' ), 'manage_options', 'wpm-settings', array( SettingsPage::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! str_starts_with( $page, 'wpm-' ) ) {
			return;
		}
		wp_enqueue_style( 'wpm-admin', WPM_URL . 'assets/admin.css', array(), WPM_VERSION );
		wp_enqueue_script( 'wpm-admin', WPM_URL . 'assets/admin.js', array(), WPM_VERSION, true );
		wp_localize_script(
			'wpm-admin',
			'wpmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpm_ajax' ),
				'i18n'    => array(
					'confirmSend'   => __( 'Send this campaign now to all matching recipients?', 'wp-mailer' ),
					'confirmDelete' => __( 'Delete permanently?', 'wp-mailer' ),
					'loading'       => __( 'Loading…', 'wp-mailer' ),
					'recipients'    => __( 'deliverable recipients', 'wp-mailer' ),
				),
			)
		);
	}

	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	public static function post_url(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * admin-post.php URL with a nonce. Unlike wp_nonce_url() it is not HTML-escaped,
	 * so it works both in redirects and (through esc_url) in links.
	 */
	public static function nonce_url( array $args, string $action ): string {
		return add_query_arg( array_merge( $args, array( '_wpnonce' => wp_create_nonce( $action ) ) ), self::post_url() );
	}

	/** Verifies capability + nonce for an admin-post handler. */
	public static function guard( string $cap, string $action ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-mailer' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function flash( string $message, string $type = 'success' ): void {
		$key   = 'wpm_flash_' . get_current_user_id();
		$queue = (array) get_transient( $key );
		$queue[] = array( $type, $message );
		set_transient( $key, $queue, 120 );
	}

	public static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	public static function notices(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! str_starts_with( $page, 'wpm-' ) ) {
			return;
		}
		$key = 'wpm_flash_' . get_current_user_id();
		foreach ( array_filter( (array) get_transient( $key ) ) as [ $type, $message ] ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), wp_kses_post( $message ) );
		}
		delete_transient( $key );

		$last_error = get_option( 'wpm_last_error' );
		if ( $last_error && current_user_can( Schema::CAP_MANAGE ) ) {
			printf( '<div class="notice notice-error"><p>%s %s</p></div>', esc_html__( 'Scheduled campaign could not start:', 'wp-mailer' ), esc_html( $last_error ) );
			delete_option( 'wpm_last_error' );
		}

		if ( ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && 'wpm-campaigns' === $page ) {
			echo '<div class="notice notice-info"><p>' . wp_kses_post( __( '<strong>Tip:</strong> WP-Cron only runs when someone visits the site. For steady sending, add a real cron job that calls <code>wp-cron.php</code> every minute and set <code>DISABLE_WP_CRON</code> in wp-config.php. See the plugin README.', 'wp-mailer' ) ) . '</p></div>';
		}
	}

	public static function datetime( ?string $gmt ): string {
		if ( ! $gmt ) {
			return '—';
		}
		return esc_html( get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	public static function pagination( int $total, int $per_page, int $page, array $base_args, string $slug ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', self::url( $slug, $base_args ) ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}
}
