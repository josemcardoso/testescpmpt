<?php
namespace WPM\Admin;

use WPM\Audience\SourceTable;
use WPM\Bounce\BounceProcessor;
use WPM\Send\Mailer;
use WPM\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public static function register(): void {
		add_action( 'admin_post_wpm_save_settings', array( self::class, 'save' ) );
		add_action( 'admin_post_wpm_test_imap', array( self::class, 'test_imap' ) );
		add_action( 'admin_post_wpm_check_bounces', array( self::class, 'check_bounces' ) );
		add_action( 'admin_post_wpm_test_smtp', array( self::class, 'test_smtp' ) );
		add_action( 'wp_ajax_wpm_table_columns', array( self::class, 'ajax_columns' ) );
	}

	public static function save(): void {
		Admin::guard( 'manage_options', 'wpm_settings' );
		$input = isset( $_POST['wpm'] ) && is_array( $_POST['wpm'] ) ? wp_unslash( $_POST['wpm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in Settings::save().
		Settings::save( $input );
		Admin::flash( __( 'Settings saved.', 'wp-mailer' ) );
		Admin::redirect( Admin::url( 'wpm-settings' ) );
	}

	public static function test_imap(): void {
		Admin::guard( 'manage_options', 'wpm_settings_tools' );
		try {
			Admin::flash( BounceProcessor::test_connection( Settings::all() ) );
		} catch ( \Throwable $e ) {
			Admin::flash( esc_html( $e->getMessage() ), 'error' );
		}
		Admin::redirect( Admin::url( 'wpm-settings' ) );
	}

	public static function check_bounces(): void {
		Admin::guard( 'manage_options', 'wpm_settings_tools' );
		BounceProcessor::run();
		$last = (array) get_option( 'wpm_bounce_last_run', array() );
		if ( ! empty( $last['error'] ) ) {
			Admin::flash( esc_html( $last['error'] ), 'error' );
		} else {
			/* translators: 1: checked, 2: hard, 3: soft, 4: unmatched */
			Admin::flash( sprintf( __( 'Bounce check done: %1$d messages read, %2$d hard and %3$d soft bounces recorded, %4$d unmatched.', 'wp-mailer' ), $last['checked'] ?? 0, $last['hard'] ?? 0, $last['soft'] ?? 0, $last['unmatched'] ?? 0 ) );
		}
		Admin::redirect( Admin::url( 'wpm-settings' ) );
	}

	public static function test_smtp(): void {
		Admin::guard( 'manage_options', 'wpm_settings_tools' );
		$to     = wp_get_current_user()->user_email;
		$result = Mailer::send( $to, __( 'WP Mailer SMTP test', 'wp-mailer' ), '<p>' . esc_html__( 'Your SMTP settings work.', 'wp-mailer' ) . '</p>', __( 'Your SMTP settings work.', 'wp-mailer' ) );
		if ( true === $result ) {
			/* translators: %s: email */
			Admin::flash( sprintf( __( 'Test email sent to %s.', 'wp-mailer' ), esc_html( $to ) ) );
		} else {
			Admin::flash( esc_html( $result->get_error_message() ), 'error' );
		}
		Admin::redirect( Admin::url( 'wpm-settings' ) );
	}

	public static function ajax_columns(): void {
		check_ajax_referer( 'wpm_ajax' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$table = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
		wp_send_json_success( SourceTable::columns_of( $table ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = Settings::all();
		$tables  = SourceTable::tables();
		$columns = $s['source_table'] ? SourceTable::columns_of( $s['source_table'] ) : array();
		$last    = (array) get_option( 'wpm_bounce_last_run', array() );

		$text = static function ( string $key, string $label, string $type = 'text', string $help = '' ) use ( $s ): void {
			$value = in_array( $key, Settings::SECRETS, true ) ? '' : (string) $s[ $key ];
			$ph    = in_array( $key, Settings::SECRETS, true ) && '' !== $s[ $key ] ? '••••••••' : '';
			printf(
				'<tr><th scope="row"><label for="wpm-%1$s">%2$s</label></th><td><input type="%3$s" id="wpm-%1$s" name="wpm[%1$s]" value="%4$s" placeholder="%5$s" class="regular-text" autocomplete="off">%6$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $value ),
				esc_attr( $ph ),
				$help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : ''
			);
		};
		$check = static function ( string $key, string $label, string $help = '' ) use ( $s ): void {
			printf(
				'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="wpm[%1$s]" value="1" %3$s> %4$s</label></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				checked( (int) $s[ $key ], 1, false ),
				wp_kses_post( $help )
			);
		};
		$select = static function ( string $key, string $label, array $options, string $help = '' ) use ( $s ): void {
			echo '<tr><th scope="row"><label for="wpm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="wpm-' . esc_attr( $key ) . '" name="wpm[' . esc_attr( $key ) . ']">';
			foreach ( $options as $value => $text ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $value ), selected( (string) $s[ $key ], (string) $value, false ), esc_html( $text ) );
			}
			echo '</select>' . ( $help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : '' ) . '</td></tr>';
		};
		$column_options = array( '' => '—' ) + array_combine( $columns, $columns );
		?>
		<div class="wrap wpm">
			<h1><?php esc_html_e( 'Mailing List Settings', 'wp-mailer' ); ?></h1>
			<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>">
				<?php wp_nonce_field( 'wpm_settings' ); ?>
				<input type="hidden" name="action" value="wpm_save_settings">

				<h2><?php esc_html_e( 'Audience', 'wp-mailer' ); ?></h2>
				<p><?php esc_html_e( 'Pick the table that holds your contacts. Every column becomes available as a merge tag, e.g. {{first_name}}, and as a filter when choosing a campaign audience.', 'wp-mailer' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$select( 'source_table', __( 'Contacts table', 'wp-mailer' ), array( '' => '—' ) + array_combine( $tables, $tables ) );
					$select( 'source_email_col', __( 'Email column', 'wp-mailer' ), $column_options );
					$select( 'source_id_col', __( 'ID column', 'wp-mailer' ), $column_options, __( 'Primary key, used to order and reference recipients.', 'wp-mailer' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Sender', 'wp-mailer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'from_name', __( 'Default from name', 'wp-mailer' ) );
					$text( 'from_email', __( 'Default from email', 'wp-mailer' ), 'email', __( 'Use an address on a domain with SPF, DKIM and DMARC set up.', 'wp-mailer' ) );
					$text( 'reply_to', __( 'Reply-To', 'wp-mailer' ), 'email' );
					?>
				</table>

				<h2><?php esc_html_e( 'SMTP', 'wp-mailer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$check( 'smtp_enabled', __( 'Use SMTP', 'wp-mailer' ), __( 'Send campaigns through the SMTP server below. Leave off to use the site\'s normal mail setup (or another SMTP plugin).', 'wp-mailer' ) );
					$text( 'smtp_host', __( 'Host', 'wp-mailer' ) );
					$text( 'smtp_port', __( 'Port', 'wp-mailer' ), 'number' );
					$select(
						'smtp_encryption',
						__( 'Encryption', 'wp-mailer' ),
						array(
							'tls'  => 'STARTTLS (587)',
							'ssl'  => 'SSL/TLS (465)',
							'none' => __( 'None', 'wp-mailer' ),
						)
					);
					$check( 'smtp_auth', __( 'Authentication', 'wp-mailer' ), __( 'Server requires username and password', 'wp-mailer' ) );
					$text( 'smtp_user', __( 'Username', 'wp-mailer' ) );
					$text( 'smtp_pass', __( 'Password', 'wp-mailer' ), 'password', __( 'Stored encrypted. Leave blank to keep the saved password.', 'wp-mailer' ) );
					$text( 'batch_size', __( 'Emails per minute', 'wp-mailer' ), 'number', __( 'Stay under your SMTP provider\'s hourly/daily limit (e.g. 50/min = 3,000/hour).', 'wp-mailer' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Bounces', 'wp-mailer' ); ?></h2>
				<p><?php echo wp_kses_post( __( 'Bounced emails come back to a mailbox. Each message is sent with a unique return path such as <code>bounces+123-abcd@yourdomain.com</code>, so the mailbox must accept <strong>plus-addressing</strong> (most do) or be a catch-all. The plugin reads it over IMAP every 10 minutes.', 'wp-mailer' ) ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$text( 'bounce_address', __( 'Bounce address', 'wp-mailer' ), 'email', __( 'Requires "Use SMTP" above: PHP\'s mail() cannot set a plus-addressed return path. Leave empty to keep the From address as return path (bounces then land in that mailbox and are matched by the X-WPM-Send header).', 'wp-mailer' ) );
					$check( 'imap_enabled', __( 'Process bounces', 'wp-mailer' ), __( 'Read the bounce mailbox over IMAP', 'wp-mailer' ) );
					$text( 'imap_host', __( 'IMAP host', 'wp-mailer' ) );
					$text( 'imap_port', __( 'IMAP port', 'wp-mailer' ), 'number' );
					$select(
						'imap_encryption',
						__( 'Encryption', 'wp-mailer' ),
						array(
							'ssl'  => 'SSL/TLS (993)',
							'tls'  => 'STARTTLS (143)',
							'none' => __( 'None', 'wp-mailer' ),
						)
					);
					$text( 'imap_user', __( 'IMAP username', 'wp-mailer' ) );
					$text( 'imap_pass', __( 'IMAP password', 'wp-mailer' ), 'password', __( 'Stored encrypted. Leave blank to keep the saved password.', 'wp-mailer' ) );
					$text( 'imap_folder', __( 'Folder to read', 'wp-mailer' ) );
					$text( 'imap_processed', __( 'Move processed to', 'wp-mailer' ), 'text', __( 'Folder for handled bounces (created automatically). Empty = only mark as read.', 'wp-mailer' ) );
					$text( 'soft_bounce_limit', __( 'Soft bounce limit', 'wp-mailer' ), 'number', __( 'Suppress an address after this many soft bounces (different campaigns, 90 days).', 'wp-mailer' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Tracking & privacy', 'wp-mailer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$check( 'track_opens', __( 'Track opens', 'wp-mailer' ), __( 'Adds a 1×1 tracking image', 'wp-mailer' ) );
					$check( 'track_clicks', __( 'Track clicks', 'wp-mailer' ), __( 'Rewrites links through your site. Add <code>data-notrack</code> to a link to skip it.', 'wp-mailer' ) );
					$check( 'inline_css', __( 'Inline CSS', 'wp-mailer' ), __( 'Copy &lt;style&gt; rules into style attributes when sending (recommended for Gmail/Outlook)', 'wp-mailer' ) );
					$text( 'retention_days', __( 'Keep user agents for (days)', 'wp-mailer' ), 'number', __( 'After this, user agents and IP hashes are removed from tracking events. 0 = keep.', 'wp-mailer' ) );
					$check( 'delete_on_uninstall', __( 'Uninstall', 'wp-mailer' ), __( 'Delete all campaigns, templates and stats when the plugin is deleted', 'wp-mailer' ) );
					?>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Tools', 'wp-mailer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" class="wpm-inline-forms">
				<?php wp_nonce_field( 'wpm_settings_tools' ); ?>
				<button class="button" name="action" value="wpm_test_smtp"><?php esc_html_e( 'Send SMTP test to me', 'wp-mailer' ); ?></button>
				<button class="button" name="action" value="wpm_test_imap"><?php esc_html_e( 'Test IMAP connection', 'wp-mailer' ); ?></button>
				<button class="button" name="action" value="wpm_check_bounces"><?php esc_html_e( 'Check bounces now', 'wp-mailer' ); ?></button>
			</form>
			<?php if ( ! empty( $last['time'] ) ) : ?>
				<p class="description">
					<?php
					/* translators: %s: date */
					printf( esc_html__( 'Last bounce check: %s.', 'wp-mailer' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) ) );
					if ( ! empty( $last['error'] ) ) {
						echo ' <span class="wpm-error">' . esc_html( $last['error'] ) . '</span>';
					}
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
