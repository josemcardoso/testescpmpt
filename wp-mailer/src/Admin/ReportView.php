<?php
namespace WPM\Admin;

use WPM\Campaign\CampaignRepo;

defined( 'ABSPATH' ) || exit;

/**
 * Campaign report: delivery, opens, clicks per link, bounces, recipients.
 */
final class ReportView {

	private const FILTERS = array(
		'all'          => 'All',
		'opened'       => 'Opened',
		'not_opened'   => 'Not opened',
		'clicked'      => 'Clicked',
		'bounced'      => 'Bounced',
		'unsubscribed' => 'Unsubscribed',
		'failed'       => 'Failed',
		'queued'       => 'Queued',
	);

	public static function render( int $id ): void {
		$c = CampaignRepo::find( $id );
		if ( ! $c ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Campaign not found.', 'wp-mailer' ) . '</p></div>';
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable
		$filter = isset( self::FILTERS[ $filter ] ) ? $filter : 'all';

		$s                = CampaignRepo::stats( $id );
		[ $rows, $total ] = CampaignRepo::recipients( $id, $filter, $search, 50, $page );
		$base_args        = array(
			'action' => 'report',
			'id'     => $id,
		);
		$export_url       = Admin::nonce_url( array( 'action' => 'wpm_export_report', 'id' => $id ), 'wpm_export_report' );
		$progress         = $s['total'] ? round( 100 * ( $s['total'] - $s['queued'] ) / $s['total'] ) : 0;
		?>
		<div class="wrap wpm wpm-report" <?php echo 'sending' === $c['status'] ? 'data-autorefresh="30"' : ''; ?>>
			<h1 class="wp-heading-inline"><?php echo esc_html( $c['name'] ); ?></h1>
			<?php echo CampaignsPage::status_badge( $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<hr class="wp-header-end">

			<p class="wpm-meta">
				<strong><?php esc_html_e( 'Subject:', 'wp-mailer' ); ?></strong> <?php echo esc_html( $c['subject'] ); ?> ·
				<strong><?php esc_html_e( 'Started:', 'wp-mailer' ); ?></strong> <?php echo Admin::datetime( $c['started_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> ·
				<strong><?php esc_html_e( 'Finished:', 'wp-mailer' ); ?></strong> <?php echo Admin::datetime( $c['finished_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
			<p class="wpm-actions">
				<a class="button" href="<?php echo esc_url( CampaignsPage::preview_url( $id ) ); ?>" target="_blank"><?php esc_html_e( 'View email', 'wp-mailer' ); ?></a>
				<?php if ( 'sending' === $c['status'] ) : ?>
					<a class="button" href="<?php echo esc_url( CampaignsPage::action_url( 'pause', $id ) ); ?>"><?php esc_html_e( 'Pause', 'wp-mailer' ); ?></a>
				<?php elseif ( 'paused' === $c['status'] ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( CampaignsPage::action_url( 'resume', $id ) ); ?>"><?php esc_html_e( 'Resume', 'wp-mailer' ); ?></a>
				<?php endif; ?>
				<?php if ( in_array( $c['status'], array( 'sending', 'paused' ), true ) ) : ?>
					<a class="button wpm-danger" href="<?php echo esc_url( CampaignsPage::action_url( 'cancel', $id ) ); ?>" data-confirm="<?php esc_attr_e( 'Stop sending? Recipients not yet emailed will be skipped.', 'wp-mailer' ); ?>"><?php esc_html_e( 'Cancel', 'wp-mailer' ); ?></a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( CampaignsPage::action_url( 'duplicate', $id ) ); ?>"><?php esc_html_e( 'Duplicate', 'wp-mailer' ); ?></a>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'wp-mailer' ); ?></a>
			</p>

			<?php if ( in_array( $c['status'], array( 'sending', 'paused' ), true ) ) : ?>
				<div class="wpm-progress" role="progressbar" aria-valuenow="<?php echo (int) $progress; ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo (int) $progress; ?>%"></span></div>
				<p class="description">
					<?php
					/* translators: 1: processed, 2: total */
					printf( esc_html__( '%1$s of %2$s processed. This page refreshes every 30 seconds.', 'wp-mailer' ), esc_html( number_format_i18n( $s['total'] - $s['queued'] ) ), esc_html( number_format_i18n( $s['total'] ) ) );
					?>
				</p>
			<?php endif; ?>

			<div class="wpm-stats">
				<?php
				self::card( __( 'Sent', 'wp-mailer' ), number_format_i18n( $s['sent'] ), sprintf( /* translators: %s: count */ __( '%s delivered', 'wp-mailer' ), number_format_i18n( $s['delivered'] ) ) );
				self::card( __( 'Opened', 'wp-mailer' ), $s['open_rate'] . '%', sprintf( /* translators: 1: unique, 2: human rate */ __( '%1$s unique · %2$s%% excl. automated', 'wp-mailer' ), number_format_i18n( $s['opened'] ), $s['human_open_rate'] ) );
				self::card( __( 'Clicked', 'wp-mailer' ), $s['click_rate'] . '%', sprintf( /* translators: 1: unique, 2: click-to-open */ __( '%1$s unique · %2$s%% click-to-open', 'wp-mailer' ), number_format_i18n( $s['clicked'] ), $s['click_to_open'] ) );
				self::card( __( 'Bounced', 'wp-mailer' ), $s['bounce_rate'] . '%', sprintf( /* translators: 1: hard, 2: soft */ __( '%1$s hard · %2$s soft', 'wp-mailer' ), number_format_i18n( $s['hard_bounces'] ), number_format_i18n( $s['soft_bounces'] ) ) );
				self::card( __( 'Unsubscribed', 'wp-mailer' ), $s['unsubscribe_rate'] . '%', number_format_i18n( $s['unsubscribed'] ) );
				if ( $s['failed'] ) {
					self::card( __( 'Failed', 'wp-mailer' ), number_format_i18n( $s['failed'] ), __( 'SMTP errors', 'wp-mailer' ) );
				}
				?>
			</div>
			<p class="description"><?php esc_html_e( 'Open rates are estimates: Apple Mail pre-loads images (counted as automated) and some clients block images. Clicks also count as opens.', 'wp-mailer' ); ?></p>

			<?php if ( $s['timeline'] ) : ?>
				<?php $max = max( 1, ...array_map( static fn( $r ) => (int) $r['opens'] + (int) $r['clicks'], $s['timeline'] ) ); ?>
				<h2><?php esc_html_e( 'Activity (UTC, by hour)', 'wp-mailer' ); ?></h2>
				<div class="wpm-timeline" aria-label="<?php esc_attr_e( 'Opens and clicks per hour', 'wp-mailer' ); ?>">
					<?php foreach ( $s['timeline'] as $t ) : ?>
						<?php
						$o     = (int) $t['opens'];
						$k     = (int) $t['clicks'];
						$label = sprintf( '%s — %d opens, %d clicks', $t['hour'], $o, $k );
						?>
						<div class="wpm-bar" title="<?php echo esc_attr( $label ); ?>">
							<span class="wpm-bar-open" style="height:<?php echo esc_attr( (string) round( 100 * $o / $max, 1 ) ); ?>%"></span>
							<span class="wpm-bar-click" style="height:<?php echo esc_attr( (string) round( 100 * $k / $max, 1 ) ); ?>%"></span>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="wpm-legend"><span class="wpm-key-open"></span> <?php esc_html_e( 'Opens', 'wp-mailer' ); ?> <span class="wpm-key-click"></span> <?php esc_html_e( 'Clicks', 'wp-mailer' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Links', 'wp-mailer' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'URL', 'wp-mailer' ); ?></th><th class="num"><?php esc_html_e( 'Unique clicks', 'wp-mailer' ); ?></th><th class="num"><?php esc_html_e( 'Total clicks', 'wp-mailer' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $s['links'] ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No tracked links in this campaign.', 'wp-mailer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $s['links'] as $l ) : ?>
					<tr>
						<td class="wpm-url"><a href="<?php echo esc_url( $l['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $l['url'] ); ?></a></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $l['unique_clicks'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $l['clicks'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recipients', 'wp-mailer' ); ?></h2>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( self::FILTERS as $key => $label ) {
					$links[] = sprintf(
						'<li><a href="%s" class="%s">%s</a></li>',
						esc_url( Admin::url( 'wpm-campaigns', array_merge( $base_args, array( 'filter' => $key ) ) ) ),
						$key === $filter ? 'current' : '',
						esc_html( $label )
					);
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</ul>
			<form method="get" class="wpm-search">
				<input type="hidden" name="page" value="wpm-campaigns">
				<input type="hidden" name="action" value="report">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email', 'wp-mailer' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'wp-mailer' ); ?></button>
				<span class="wpm-count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
			</form>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Email', 'wp-mailer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-mailer' ); ?></th>
					<th><?php esc_html_e( 'Sent', 'wp-mailer' ); ?></th>
					<th><?php esc_html_e( 'First open', 'wp-mailer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Opens', 'wp-mailer' ); ?></th>
					<th class="num"><?php esc_html_e( 'Clicks', 'wp-mailer' ); ?></th>
					<th><?php esc_html_e( 'Details', 'wp-mailer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No recipients match.', 'wp-mailer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['email'] ); ?></td>
						<td>
							<?php
							$label = $r['status'];
							if ( $r['bounce_type'] ) {
								$label = $r['bounce_type'] . ' bounce';
							} elseif ( $r['unsubscribed_at'] ) {
								$label = 'unsubscribed';
							}
							echo '<span class="wpm-badge wpm-badge-' . esc_attr( str_replace( ' ', '-', $label ) ) . '">' . esc_html( $label ) . '</span>';
							?>
						</td>
						<td><?php echo Admin::datetime( $r['sent_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo Admin::datetime( $r['opened_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo $r['opened_at'] && ! $r['human_opened_at'] ? ' <span class="wpm-muted" title="' . esc_attr__( 'Likely an automated open (privacy proxy or scanner)', 'wp-mailer' ) . '">(auto)</span>' : ''; ?></td>
						<td class="num"><?php echo (int) $r['open_count']; ?></td>
						<td class="num"><?php echo (int) $r['click_count']; ?></td>
						<td class="wpm-muted"><?php echo esc_html( (string) $r['error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php Admin::pagination( $total, 50, $page, array_merge( $base_args, array( 'filter' => $filter, 's' => $search ) ), 'wpm-campaigns' ); ?>
		</div>
		<?php
	}

	private static function card( string $label, string $value, string $sub ): void {
		printf(
			'<div class="wpm-stat"><span class="wpm-stat-label">%s</span><span class="wpm-stat-value">%s</span><span class="wpm-stat-sub">%s</span></div>',
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $sub )
		);
	}
}
