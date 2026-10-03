<?php
namespace WPM\Admin;

use WPM\Audience\Segment;
use WPM\Audience\SourceTable;
use WPM\Campaign\CampaignRepo;
use WPM\Campaign\Renderer;
use WPM\Install\Schema;
use WPM\Send\Mailer;
use WPM\Send\Queue;
use WPM\Settings;
use WPM\Template\HtmlToText;
use WPM\Template\TemplateRepo;

defined( 'ABSPATH' ) || exit;

/**
 * Campaign list, editor (audience, template, content), sending and reports.
 */
final class CampaignsPage {

	public static function register(): void {
		add_action( 'admin_post_wpm_save_campaign', array( self::class, 'save' ) );
		add_action( 'admin_post_wpm_campaign_action', array( self::class, 'action' ) );
		add_action( 'admin_post_wpm_campaign_preview', array( self::class, 'preview' ) );
		add_action( 'admin_post_wpm_export_report', array( self::class, 'export' ) );
		add_action( 'wp_ajax_wpm_segment_preview', array( self::class, 'ajax_segment' ) );
	}

	/* ---------------------------------------------------------------- Handlers */

	public static function save(): void {
		Admin::guard( Schema::CAP_MANAGE, 'wpm_campaign' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : 'save';

		$existing = $id ? CampaignRepo::find( $id ) : null;
		if ( $existing && ! in_array( $existing['status'], CampaignRepo::EDITABLE, true ) ) {
			Admin::flash( __( 'This campaign has already been sent and can no longer be edited.', 'wp-mailer' ), 'error' );
			Admin::redirect( self::report_url( $id ) );
		}

		$segment = Segment::decode( isset( $_POST['segment'] ) ? wp_unslash( $_POST['segment'] ) : '' );
		$fields  = array(
			'name'         => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'subject'      => sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
			'preheader'    => sanitize_text_field( wp_unslash( $_POST['preheader'] ?? '' ) ),
			'from_name'    => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
			'from_email'   => sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) ),
			'reply_to'     => sanitize_email( wp_unslash( $_POST['reply_to'] ?? '' ) ),
			'template_id'  => (int) ( $_POST['template_id'] ?? 0 ),
			'content'      => current_user_can( 'unfiltered_html' ) ? (string) wp_unslash( $_POST['content'] ?? '' ) : wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) ),
			'text_content' => sanitize_textarea_field( wp_unslash( $_POST['text_content'] ?? '' ) ),
			'segment'      => wp_json_encode( $segment ),
		);
		// phpcs:enable
		if ( '' === $fields['name'] ) {
			$fields['name'] = '' !== $fields['subject'] ? $fields['subject'] : __( 'Untitled campaign', 'wp-mailer' );
		}

		$id = CampaignRepo::save( $id, $fields );

		switch ( $do ) {
			case 'test':
				self::send_test( $id );
				break;

			case 'preview':
				Admin::redirect( self::preview_url( $id ) );
				break;

			case 'send':
				CampaignRepo::update(
					$id,
					array(
						'status'       => 'draft',
						'scheduled_at' => null,
					)
				);
				$result = Queue::start( $id );
				if ( is_wp_error( $result ) ) {
					Admin::flash( esc_html( $result->get_error_message() ), 'error' );
					break;
				}
				/* translators: %s: number of recipients */
				Admin::flash( sprintf( __( 'Sending started to %s recipients.', 'wp-mailer' ), number_format_i18n( $result ) ) );
				Admin::redirect( self::report_url( $id ) );
				break;

			case 'schedule':
				$local = sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$gmt   = $local ? get_gmt_from_date( str_replace( 'T', ' ', $local ) . ':00' ) : '';
				if ( ! $gmt || strtotime( $gmt . ' UTC' ) <= time() ) {
					Admin::flash( __( 'Pick a date and time in the future.', 'wp-mailer' ), 'error' );
					break;
				}
				if ( ! SourceTable::from_settings() ) {
					Admin::flash( __( 'Configure the audience table in Settings first.', 'wp-mailer' ), 'error' );
					break;
				}
				CampaignRepo::update(
					$id,
					array(
						'status'       => 'scheduled',
						'scheduled_at' => $gmt,
					)
				);
				Admin::flash( __( 'Campaign scheduled.', 'wp-mailer' ) );
				Admin::redirect( Admin::url( 'wpm-campaigns' ) );
				break;

			default:
				Admin::flash( __( 'Campaign saved.', 'wp-mailer' ) );
		}

		Admin::redirect( Admin::url( 'wpm-campaigns', array( 'action' => 'edit', 'id' => $id ) ) );
	}

	private static function send_test( int $id ): void {
		$c   = CampaignRepo::find( $id );
		$raw = sanitize_text_field( wp_unslash( $_POST['test_emails'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
		$to  = array_slice( array_filter( preg_split( '/[\s,;]+/', $raw ) ?: array(), 'is_email' ), 0, 5 );
		if ( ! $to ) {
			$to = array( wp_get_current_user()->user_email );
		}
		update_user_meta( get_current_user_id(), 'wpm_test_emails', implode( ', ', $to ) );

		$data     = self::sample_for( $c );
		$renderer = Queue::renderer( array( 'track_clicks' => false ) );
		$html     = $renderer->personalize( $renderer->prepare( Queue::layout( $c ), static fn() => 0 ), 0, $data );
		$text     = '' !== trim( (string) $c['text_content'] ) ? Renderer::merge( (string) $c['text_content'], $data, 'text' ) : HtmlToText::convert( $html );
		$subject  = '[Test] ' . Renderer::merge( (string) $c['subject'], $data, 'text' );

		foreach ( $to as $email ) {
			$result = Mailer::send(
				$email,
				$subject,
				$html,
				$text,
				array(
					'from_name'  => $c['from_name'],
					'from_email' => $c['from_email'],
					'reply_to'   => $c['reply_to'],
				)
			);
			if ( true !== $result ) {
				Admin::flash( esc_html( $email . ': ' . $result->get_error_message() ), 'error' );
				return;
			}
		}
		/* translators: %s: email list */
		Admin::flash( sprintf( __( 'Test sent to %s (tracking is disabled in tests).', 'wp-mailer' ), esc_html( implode( ', ', $to ) ) ) );
	}

	/** First recipient of the campaign's audience, used for previews and tests. */
	private static function sample_for( array $c ): array {
		$source = SourceTable::from_settings();
		if ( $source ) {
			try {
				$rows = $source->rows( Segment::decode( $c['segment'] ), 1 );
				if ( $rows ) {
					return $source->to_recipient( $rows[0] )['data'] ?? array();
				}
			} catch ( \InvalidArgumentException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Fall through to generic sample data.
			}
		}
		return TemplatesPage::sample_data();
	}

	public static function preview(): void {
		Admin::guard( Schema::CAP_MANAGE, 'wpm_campaign_preview' );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$c  = CampaignRepo::find( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Campaign not found.', 'wp-mailer' ) );
		}
		$data = self::sample_for( $c );
		if ( '' !== (string) $c['html_snapshot'] ) {
			// Sent campaigns: show exactly what went out.
			$html = Queue::renderer()->personalize( (string) $c['html_snapshot'], 0, $data );
		} else {
			$renderer = Queue::renderer( array( 'track_clicks' => false ) );
			$html     = $renderer->personalize( $renderer->prepare( Queue::layout( $c ), static fn() => 0 ), 0, $data );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- admin preview of trusted template HTML.
		exit;
	}

	public static function action(): void {
		Admin::guard( Schema::CAP_MANAGE, 'wpm_campaign_action' );
		// phpcs:disable WordPress.Security.NonceVerification
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		// phpcs:enable
		switch ( $do ) {
			case 'pause':
				Queue::pause( $id );
				Admin::flash( __( 'Sending paused.', 'wp-mailer' ) );
				Admin::redirect( self::report_url( $id ) );
				break;
			case 'resume':
				Queue::resume( $id );
				Admin::flash( __( 'Sending resumed.', 'wp-mailer' ) );
				Admin::redirect( self::report_url( $id ) );
				break;
			case 'cancel':
				Queue::cancel( $id );
				Admin::flash( __( 'Campaign cancelled.', 'wp-mailer' ) );
				break;
			case 'duplicate':
				$new = CampaignRepo::duplicate( $id );
				Admin::flash( __( 'Campaign duplicated.', 'wp-mailer' ) );
				Admin::redirect( Admin::url( 'wpm-campaigns', array( 'action' => 'edit', 'id' => $new ) ) );
				break;
			case 'delete':
				$c = CampaignRepo::find( $id );
				if ( $c && in_array( $c['status'], array( 'sending' ), true ) ) {
					Admin::flash( __( 'Pause or cancel the campaign before deleting it.', 'wp-mailer' ), 'error' );
					break;
				}
				CampaignRepo::delete( $id );
				Admin::flash( __( 'Campaign deleted.', 'wp-mailer' ) );
				break;
		}
		Admin::redirect( Admin::url( 'wpm-campaigns' ) );
	}

	public static function export(): void {
		Admin::guard( Schema::CAP_MANAGE, 'wpm_export_report' );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$c  = CampaignRepo::find( $id );
		if ( ! $c ) {
			wp_die( esc_html__( 'Campaign not found.', 'wp-mailer' ) );
		}
		[ $rows ] = CampaignRepo::recipients( $id, 'all', '', 0, 1 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'campaign-' . $id . '-' . $c['name'] ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel needs the BOM to read UTF-8.
		$cols = array( 'email', 'recipient_ref', 'status', 'sent_at', 'opened_at', 'human_opened_at', 'open_count', 'clicked_at', 'click_count', 'bounce_type', 'bounced_at', 'unsubscribed_at', 'error' );
		fputcsv( $out, $cols );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $cols as $col ) {
				$value = (string) ( $r[ $col ] ?? '' );
				// Neutralise spreadsheet formula injection.
				$line[] = preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
			}
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	public static function ajax_segment(): void {
		check_ajax_referer( 'wpm_ajax' );
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			wp_send_json_error( null, 403 );
		}
		$source = SourceTable::from_settings();
		if ( ! $source ) {
			wp_send_json_error( array( 'message' => __( 'Configure the audience table in Settings first.', 'wp-mailer' ) ) );
		}
		$segment = Segment::decode( isset( $_POST['segment'] ) ? wp_unslash( $_POST['segment'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		try {
			$sample = array();
			foreach ( $source->rows( $segment, 10 ) as $row ) {
				$sample[] = array_map( static fn( $v ) => is_scalar( $v ) ? mb_substr( (string) $v, 0, 80 ) : '', $row );
			}
			wp_send_json_success(
				array(
					'matching'    => $source->count( $segment ),
					'deliverable' => $source->deliverable_count( $segment ),
					'sample'      => $sample,
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/* ---------------------------------------------------------------- URLs */

	public static function report_url( int $id ): string {
		return Admin::url(
			'wpm-campaigns',
			array(
				'action' => 'report',
				'id'     => $id,
			)
		);
	}

	public static function preview_url( int $id ): string {
		return Admin::nonce_url( array( 'action' => 'wpm_campaign_preview', 'id' => $id ), 'wpm_campaign_preview' );
	}

	public static function action_url( string $do, int $id ): string {
		return Admin::nonce_url( array( 'action' => 'wpm_campaign_action', 'do' => $do, 'id' => $id ), 'wpm_campaign_action' );
	}

	/* ---------------------------------------------------------------- Views */

	public static function render(): void {
		if ( ! current_user_can( Schema::CAP_MANAGE ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		// phpcs:enable
		if ( 'report' === $action ) {
			ReportView::render( $id );
			return;
		}
		if ( in_array( $action, array( 'edit', 'new' ), true ) ) {
			$c = $id ? CampaignRepo::find( $id ) : null;
			if ( $c && ! in_array( $c['status'], CampaignRepo::EDITABLE, true ) ) {
				ReportView::render( $id );
				return;
			}
			self::render_editor( $c );
			return;
		}
		self::render_list();
	}

	public static function status_badge( string $status ): string {
		return '<span class="wpm-badge wpm-badge-' . esc_attr( $status ) . '">' . esc_html( CampaignRepo::STATUSES[ $status ] ?? $status ) . '</span>';
	}

	private static function render_list(): void {
		$campaigns = CampaignRepo::all();
		$summaries = CampaignRepo::summaries();
		?>
		<div class="wrap wpm">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Campaigns', 'wp-mailer' ); ?></h1>
			<a href="<?php echo esc_url( Admin::url( 'wpm-campaigns', array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'New campaign', 'wp-mailer' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( ! SourceTable::from_settings() && current_user_can( 'manage_options' ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings link */
						wp_kses_post( __( 'Choose the table that holds your contacts in <a href="%s">Settings</a> before sending.', 'wp-mailer' ) ),
						esc_url( Admin::url( 'wpm-settings' ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<table class="widefat striped wpm-campaigns">
				<thead><tr>
					<th><?php esc_html_e( 'Campaign', 'wp-mailer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-mailer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Sent', 'wp-mailer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Opened', 'wp-mailer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Clicked', 'wp-mailer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Bounced', 'wp-mailer' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wp-mailer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $campaigns ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No campaigns yet. Create your first one!', 'wp-mailer' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $campaigns as $c ) :
					$id       = (int) $c['id'];
					$sum      = $summaries[ $id ] ?? array( 'total' => 0, 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0, 'hard' => 0 );
					$base     = max( 1, $sum['sent'] - $sum['hard'] );
					$editable = in_array( $c['status'], CampaignRepo::EDITABLE, true );
					$main_url = $editable ? Admin::url( 'wpm-campaigns', array( 'action' => 'edit', 'id' => $id ) ) : self::report_url( $id );
					$date     = $c['finished_at'] ?: ( $c['started_at'] ?: ( $c['scheduled_at'] ?: $c['updated_at'] ) );
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $main_url ); ?>"><?php echo esc_html( $c['name'] ); ?></a></strong>
							<div class="wpm-sub"><?php echo esc_html( $c['subject'] ); ?></div>
							<div class="row-actions">
								<?php if ( $editable ) : ?>
									<a href="<?php echo esc_url( $main_url ); ?>"><?php esc_html_e( 'Edit', 'wp-mailer' ); ?></a> |
								<?php else : ?>
									<a href="<?php echo esc_url( self::report_url( $id ) ); ?>"><?php esc_html_e( 'Report', 'wp-mailer' ); ?></a> |
								<?php endif; ?>
								<a href="<?php echo esc_url( self::preview_url( $id ) ); ?>" target="_blank"><?php esc_html_e( 'Preview', 'wp-mailer' ); ?></a> |
								<a href="<?php echo esc_url( self::action_url( 'duplicate', $id ) ); ?>"><?php esc_html_e( 'Duplicate', 'wp-mailer' ); ?></a>
								<?php if ( 'scheduled' === $c['status'] ) : ?>
									| <a href="<?php echo esc_url( self::action_url( 'cancel', $id ) ); ?>"><?php esc_html_e( 'Unschedule', 'wp-mailer' ); ?></a>
								<?php endif; ?>
								<?php if ( 'sending' !== $c['status'] ) : ?>
									| <a class="wpm-danger" href="<?php echo esc_url( self::action_url( 'delete', $id ) ); ?>" data-confirm="<?php esc_attr_e( 'Delete this campaign and all its statistics?', 'wp-mailer' ); ?>"><?php esc_html_e( 'Delete', 'wp-mailer' ); ?></a>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo self::status_badge( $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td class="num"><?php echo esc_html( $sum['total'] ? number_format_i18n( $sum['sent'] ) . ' / ' . number_format_i18n( $sum['total'] ) : '—' ); ?></td>
						<td class="num"><?php echo esc_html( $sum['sent'] ? round( 100 * $sum['opened'] / $base, 1 ) . '%' : '—' ); ?></td>
						<td class="num"><?php echo esc_html( $sum['sent'] ? round( 100 * $sum['clicked'] / $base, 1 ) . '%' : '—' ); ?></td>
						<td class="num"><?php echo esc_html( $sum['sent'] ? number_format_i18n( $sum['bounced'] ) : '—' ); ?></td>
						<td><?php echo Admin::datetime( $date ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_editor( ?array $c ): void {
		$s         = Settings::all();
		$source    = SourceTable::from_settings();
		$columns   = $source ? $source->columns() : array();
		$templates = TemplateRepo::all();
		$segment   = Segment::decode( $c['segment'] ?? '' );
		$id        = (int) ( $c['id'] ?? 0 );
		$tests     = (string) get_user_meta( get_current_user_id(), 'wpm_test_emails', true ) ?: wp_get_current_user()->user_email;
		$scheduled = ! empty( $c['scheduled_at'] ) ? get_date_from_gmt( $c['scheduled_at'], 'Y-m-d\TH:i' ) : '';
		?>
		<div class="wrap wpm wpm-campaign-editor">
			<h1 class="wp-heading-inline"><?php echo $c ? esc_html__( 'Edit campaign', 'wp-mailer' ) : esc_html__( 'New campaign', 'wp-mailer' ); ?></h1>
			<?php if ( $c ) : ?>
				<?php echo self::status_badge( $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<hr class="wp-header-end">

			<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" id="wpm-campaign-form">
				<?php wp_nonce_field( 'wpm_campaign' ); ?>
				<input type="hidden" name="action" value="wpm_save_campaign">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">

				<div class="wpm-card">
					<h2><?php esc_html_e( '1. Message', 'wp-mailer' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><label for="wpm-name"><?php esc_html_e( 'Internal name', 'wp-mailer' ); ?></label></th><td><input id="wpm-name" name="name" class="regular-text" value="<?php echo esc_attr( $c['name'] ?? '' ); ?>"></td></tr>
						<tr><th><label for="wpm-subject"><?php esc_html_e( 'Subject', 'wp-mailer' ); ?></label></th><td><input id="wpm-subject" name="subject" class="large-text" required value="<?php echo esc_attr( $c['subject'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Hi {{first_name|there}}, our October news', 'wp-mailer' ); ?>"></td></tr>
						<tr><th><label for="wpm-preheader"><?php esc_html_e( 'Preheader', 'wp-mailer' ); ?></label></th><td><input id="wpm-preheader" name="preheader" class="large-text" value="<?php echo esc_attr( $c['preheader'] ?? '' ); ?>"><p class="description"><?php esc_html_e( 'Preview text shown after the subject in most inboxes.', 'wp-mailer' ); ?></p></td></tr>
						<tr><th><label for="wpm-from-name"><?php esc_html_e( 'From', 'wp-mailer' ); ?></label></th><td>
							<input id="wpm-from-name" name="from_name" value="<?php echo esc_attr( $c['from_name'] ?? $s['from_name'] ); ?>" placeholder="<?php esc_attr_e( 'Name', 'wp-mailer' ); ?>">
							<input name="from_email" type="email" value="<?php echo esc_attr( $c['from_email'] ?? $s['from_email'] ); ?>" placeholder="<?php esc_attr_e( 'Email', 'wp-mailer' ); ?>">
						</td></tr>
						<tr><th><label for="wpm-reply"><?php esc_html_e( 'Reply-To', 'wp-mailer' ); ?></label></th><td><input id="wpm-reply" name="reply_to" type="email" value="<?php echo esc_attr( $c['reply_to'] ?? $s['reply_to'] ); ?>"></td></tr>
					</table>
				</div>

				<div class="wpm-card">
					<h2><?php esc_html_e( '2. Audience', 'wp-mailer' ); ?></h2>
					<?php if ( ! $source ) : ?>
						<p class="wpm-error"><?php esc_html_e( 'No contacts table configured yet. Go to Mailing List → Settings.', 'wp-mailer' ); ?></p>
					<?php else : ?>
						<p class="description">
							<?php
							/* translators: %s: table name */
							printf( esc_html__( 'Recipients come from %s. Leave the rules empty to send to everyone. Suppressed and invalid addresses are always skipped.', 'wp-mailer' ), '<code>' . esc_html( $s['source_table'] ) . '</code>' );
							?>
						</p>
						<div id="wpm-segment"
							data-columns="<?php echo esc_attr( wp_json_encode( $columns ) ); ?>"
							data-operators="<?php echo esc_attr( wp_json_encode( Segment::OPERATORS ) ); ?>"
							data-segment="<?php echo esc_attr( wp_json_encode( $segment ) ); ?>"></div>
						<input type="hidden" name="segment" id="wpm-segment-input" value="<?php echo esc_attr( wp_json_encode( $segment ) ); ?>">
						<p><button type="button" class="button" id="wpm-segment-count"><?php esc_html_e( 'Count recipients', 'wp-mailer' ); ?></button> <span id="wpm-segment-result"></span></p>
						<div id="wpm-segment-sample"></div>
					<?php endif; ?>
				</div>

				<div class="wpm-card">
					<h2><?php esc_html_e( '3. Design & content', 'wp-mailer' ); ?></h2>
					<p>
						<label for="wpm-template"><strong><?php esc_html_e( 'Template', 'wp-mailer' ); ?></strong></label>
						<select id="wpm-template" name="template_id">
							<option value="0"><?php esc_html_e( 'No template (content only)', 'wp-mailer' ); ?></option>
							<?php foreach ( $templates as $t ) : ?>
								<option value="<?php echo (int) $t['id']; ?>" <?php selected( (int) ( $c['template_id'] ?? 0 ), (int) $t['id'] ); ?>><?php echo esc_html( $t['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( current_user_can( Schema::CAP_TEMPLATES ) ) : ?>
							<a href="<?php echo esc_url( Admin::url( 'wpm-templates' ) ); ?>" target="_blank"><?php esc_html_e( 'Manage templates', 'wp-mailer' ); ?></a>
						<?php endif; ?>
					</p>
					<?php
					wp_editor(
						(string) ( $c['content'] ?? '' ),
						'wpm_content',
						array(
							'textarea_name' => 'content',
							'textarea_rows' => 18,
							'media_buttons' => true,
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'Merge tags:', 'wp-mailer' ); ?>
						<?php foreach ( array_merge( array( 'email' ), array_map( 'strtolower', $columns ) ) as $col ) : ?>
							<code>{{<?php echo esc_html( $col ); ?>}}</code>
						<?php endforeach; ?>
						<?php esc_html_e( '— add a fallback with {{first_name|friend}}.', 'wp-mailer' ); ?>
					</p>
					<details class="wpm-details">
						<summary><?php esc_html_e( 'Plain-text version (optional)', 'wp-mailer' ); ?></summary>
						<p class="description"><?php esc_html_e( 'Generated automatically from the HTML when left empty. Use {{unsubscribe_url}} for the unsubscribe link.', 'wp-mailer' ); ?></p>
						<textarea name="text_content" rows="8" class="large-text code"><?php echo esc_textarea( $c['text_content'] ?? '' ); ?></textarea>
					</details>
				</div>

				<div class="wpm-card">
					<h2><?php esc_html_e( '4. Test & send', 'wp-mailer' ); ?></h2>
					<p>
						<label><?php esc_html_e( 'Send a test to', 'wp-mailer' ); ?> <input name="test_emails" class="regular-text" value="<?php echo esc_attr( $tests ); ?>"></label>
						<button class="button" name="do" value="test"><?php esc_html_e( 'Save & send test', 'wp-mailer' ); ?></button>
						<button class="button" name="do" value="preview" formtarget="_blank"><?php esc_html_e( 'Save & preview', 'wp-mailer' ); ?></button>
					</p>
					<p class="wpm-send-row">
						<button class="button button-secondary" name="do" value="save"><?php esc_html_e( 'Save draft', 'wp-mailer' ); ?></button>
						<span class="wpm-spacer"></span>
						<label><?php esc_html_e( 'Schedule for', 'wp-mailer' ); ?> <input type="datetime-local" name="scheduled_at" value="<?php echo esc_attr( $scheduled ); ?>"></label>
						<button class="button" name="do" value="schedule"><?php esc_html_e( 'Schedule', 'wp-mailer' ); ?></button>
						<button class="button button-primary" name="do" value="send" data-confirm="<?php esc_attr_e( 'Send this campaign now to all matching recipients?', 'wp-mailer' ); ?>"><?php esc_html_e( 'Send now', 'wp-mailer' ); ?></button>
					</p>
					<?php if ( $c && 'scheduled' === $c['status'] ) : ?>
						<p class="description">
							<?php
							/* translators: %s: date */
							printf( esc_html__( 'Scheduled for %s. Saving keeps the schedule; use Unschedule in the campaign list to cancel it.', 'wp-mailer' ), Admin::datetime( $c['scheduled_at'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							?>
						</p>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}
}
