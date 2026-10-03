<?php
namespace WPM\Admin;

use WPM\Install\Schema;
use WPM\Suppressions;

defined( 'ABSPATH' ) || exit;

final class SuppressionsPage {

	public static function register(): void {
		add_action( 'admin_post_wpm_add_suppression', array( self::class, 'add' ) );
		add_action( 'admin_post_wpm_remove_suppression', array( self::class, 'remove' ) );
	}

	public static function add(): void {
		Admin::guard( Schema::CAP_MANAGE, 'wpm_suppression' );
		$raw   = isset( $_POST['emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['emails'] ) ) : '';
		$added = 0;
		foreach ( preg_split( '/[\s,;]+/', $raw ) ?: array() as $email ) {
			if ( is_email( $email ) ) {
				Suppressions::add( $email, 'manual' );
				++$added;
			}
		}
		/* translators: %d: count */
		Admin::flash( sprintf( _n( '%d address added.', '%d addresses added.', $added, 'wp-mailer' ), $added ) );
		Admin::redirect( Admin::url( 'wpm-suppressions' ) );
	}

	public static function remove(): void {
		Admin::guard( Schema::CAP_MANAGE, 'wpm_suppression' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		Suppressions::remove( $email );
		/* translators: %s: email */
		Admin::flash( sprintf( __( '%s can receive emails again.', 'wp-mailer' ), esc_html( $email ) ) );
		Admin::redirect( Admin::url( 'wpm-suppressions' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable
		[ $rows, $total ] = Suppressions::page( $search, 50, $page );
		?>
		<div class="wrap wpm">
			<h1><?php esc_html_e( 'Suppression list', 'wp-mailer' ); ?></h1>
			<p><?php esc_html_e( 'These addresses are never emailed: hard bounces, repeated soft bounces, unsubscribes and manual additions.', 'wp-mailer' ); ?></p>

			<form method="get" class="wpm-search">
				<input type="hidden" name="page" value="wpm-suppressions">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email', 'wp-mailer' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'wp-mailer' ); ?></button>
				<span class="wpm-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</form>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Email', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'Reason', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'Details', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'Since', 'wp-mailer' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing here yet.', 'wp-mailer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['email'] ); ?></td>
						<td><?php echo esc_html( Suppressions::REASONS[ $r['reason'] ] ?? $r['reason'] ); ?></td>
						<td><?php echo esc_html( $r['note'] ); ?></td>
						<td><?php echo Admin::datetime( $r['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" data-confirm="<?php esc_attr_e( 'Allow emails to this address again?', 'wp-mailer' ); ?>">
								<?php wp_nonce_field( 'wpm_suppression' ); ?>
								<input type="hidden" name="action" value="wpm_remove_suppression">
								<input type="hidden" name="email" value="<?php echo esc_attr( $r['email'] ); ?>">
								<button class="button-link wpm-danger"><?php esc_html_e( 'Remove', 'wp-mailer' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php Admin::pagination( $total, 50, $page, array( 's' => $search ), 'wpm-suppressions' ); ?>

			<h2><?php esc_html_e( 'Add addresses', 'wp-mailer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>">
				<?php wp_nonce_field( 'wpm_suppression' ); ?>
				<input type="hidden" name="action" value="wpm_add_suppression">
				<textarea name="emails" rows="4" class="large-text" placeholder="one@example.com, two@example.com"></textarea>
				<?php submit_button( __( 'Add to suppression list', 'wp-mailer' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
