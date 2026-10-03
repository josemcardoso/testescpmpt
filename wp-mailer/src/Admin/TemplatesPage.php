<?php
namespace WPM\Admin;

use WPM\Audience\SourceTable;
use WPM\Campaign\Renderer;
use WPM\Install\Schema;
use WPM\Send\Mailer;
use WPM\Send\Queue;
use WPM\Template\HtmlToText;
use WPM\Template\TemplateRepo;

defined( 'ABSPATH' ) || exit;

/**
 * Create, edit (CodeMirror + live preview), import/export and version HTML templates.
 */
final class TemplatesPage {

	public static function register(): void {
		add_action( 'admin_post_wpm_save_template', array( self::class, 'save' ) );
		add_action( 'admin_post_wpm_template_action', array( self::class, 'action' ) );
		add_action( 'admin_post_wpm_import_template', array( self::class, 'import' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function assets(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$page   = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		// phpcs:enable
		if ( 'wpm-templates' !== $page || ! in_array( $action, array( 'edit', 'new' ), true ) ) {
			return;
		}
		$editor = wp_enqueue_code_editor(
			array(
				'type'       => 'text/html',
				'codemirror' => array(
					'lineWrapping' => true,
					'indentUnit'   => 2,
					'tabSize'      => 2,
				),
			)
		);
		wp_enqueue_media();
		wp_enqueue_script( 'wpm-template-editor', WPM_URL . 'assets/template-editor.js', array( 'jquery' ), WPM_VERSION, true );
		wp_localize_script(
			'wpm-template-editor',
			'wpmTemplate',
			array(
				'editor'  => $editor ?: null,
				'sample'  => self::sample_data(),
				'content' => self::sample_content(),
			)
		);
	}

	/** First contact in the audience table, so previews show real merge values. */
	public static function sample_data(): array {
		$source = SourceTable::from_settings();
		$data   = $source ? $source->sample() : array();
		return $data ?: array(
			'email'      => 'alex@example.com',
			'first_name' => 'Alex',
			'last_name'  => 'Silva',
		);
	}

	public static function sample_content(): string {
		return '<h2 style="margin:0 0 12px">' . esc_html__( 'Your campaign content goes here', 'wp-mailer' ) . '</h2>'
			. '<p>' . esc_html__( 'This sample text shows where each campaign\'s content is inserted through the {{content}} placeholder.', 'wp-mailer' ) . '</p>'
			. '<p><a href="https://example.com">' . esc_html__( 'A sample link', 'wp-mailer' ) . '</a></p>';
	}

	public static function save(): void {
		Admin::guard( Schema::CAP_TEMPLATES, 'wpm_template' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- template HTML is stored raw by design; only users with wpm_manage_templates can edit it.
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$html = isset( $_POST['html'] ) ? (string) wp_unslash( $_POST['html'] ) : '';
		$text = isset( $_POST['text_version'] ) ? (string) wp_unslash( $_POST['text_version'] ) : '';
		$do   = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : 'save';
		// phpcs:enable

		$id = TemplateRepo::save( $id, $name, $html, $text );
		Admin::flash( __( 'Template saved.', 'wp-mailer' ) );
		foreach ( TemplateRepo::lint( $html ) as $warning ) {
			Admin::flash( esc_html( $warning ), 'warning' );
		}

		if ( 'test' === $do ) {
			self::send_test( $id );
		}
		Admin::redirect( Admin::url( 'wpm-templates', array( 'action' => 'edit', 'id' => $id ) ) );
	}

	private static function send_test( int $id ): void {
		$t    = TemplateRepo::find( $id );
		$to   = wp_get_current_user()->user_email;
		$data = self::sample_data();

		$renderer = Queue::renderer( array( 'track_clicks' => false ) );
		$html     = $renderer->personalize( $renderer->prepare( Renderer::layout( $t['html'], self::sample_content() ), static fn() => 0 ), 0, $data );
		/* translators: %s: template name */
		$result = Mailer::send( $to, sprintf( __( '[Test] %s', 'wp-mailer' ), $t['name'] ), $html, HtmlToText::convert( $html ) );
		if ( true === $result ) {
			/* translators: %s: email */
			Admin::flash( sprintf( __( 'Test email sent to %s.', 'wp-mailer' ), esc_html( $to ) ) );
		} else {
			Admin::flash( esc_html( $result->get_error_message() ), 'error' );
		}
	}

	/** Duplicate / delete / export / restore revision. */
	public static function action(): void {
		Admin::guard( Schema::CAP_TEMPLATES, 'wpm_template_action' );
		// phpcs:disable WordPress.Security.NonceVerification
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		// phpcs:enable

		switch ( $do ) {
			case 'duplicate':
				$new = TemplateRepo::duplicate( $id );
				Admin::flash( __( 'Template duplicated.', 'wp-mailer' ) );
				Admin::redirect( Admin::url( 'wpm-templates', array( 'action' => 'edit', 'id' => $new ) ) );
				break;
			case 'delete':
				TemplateRepo::delete( $id );
				Admin::flash( __( 'Template deleted.', 'wp-mailer' ) );
				break;
			case 'export':
				$t = TemplateRepo::find( $id );
				if ( $t ) {
					nocache_headers();
					header( 'Content-Type: text/html; charset=UTF-8' );
					header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $t['name'] ) . '.html"' );
					echo $t['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- file download.
					exit;
				}
				break;
			case 'restore':
				$template_id = TemplateRepo::restore_revision( $id );
				Admin::flash( __( 'Revision restored. The previous version was saved as a revision.', 'wp-mailer' ) );
				Admin::redirect( Admin::url( 'wpm-templates', array( 'action' => 'edit', 'id' => $template_id ) ) );
				break;
		}
		Admin::redirect( Admin::url( 'wpm-templates' ) );
	}

	public static function import(): void {
		Admin::guard( Schema::CAP_TEMPLATES, 'wpm_import_template' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$file = $_FILES['template_file'] ?? null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			Admin::flash( __( 'Upload failed.', 'wp-mailer' ), 'error' );
			Admin::redirect( Admin::url( 'wpm-templates' ) );
		}
		$name = sanitize_file_name( wp_unslash( $file['name'] ) );
		// phpcs:enable
		if ( ! preg_match( '/\.html?$/i', $name ) || (int) $file['size'] > 2 * MB_IN_BYTES ) {
			Admin::flash( __( 'Please upload an .html file smaller than 2 MB.', 'wp-mailer' ), 'error' );
			Admin::redirect( Admin::url( 'wpm-templates' ) );
		}
		$html = (string) file_get_contents( $file['tmp_name'] );
		if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
			$html = mb_convert_encoding( $html, 'UTF-8', 'ISO-8859-1' );
		}
		$id = TemplateRepo::save( 0, preg_replace( '/\.html?$/i', '', $name ), $html );
		Admin::flash( __( 'Template imported.', 'wp-mailer' ) );
		foreach ( TemplateRepo::lint( $html ) as $warning ) {
			Admin::flash( esc_html( $warning ), 'warning' );
		}
		Admin::redirect( Admin::url( 'wpm-templates', array( 'action' => 'edit', 'id' => $id ) ) );
	}

	public static function action_url( string $do, int $id ): string {
		return Admin::nonce_url(
			array(
				'action' => 'wpm_template_action',
				'do'     => $do,
				'id'     => $id,
			),
			'wpm_template_action'
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Schema::CAP_TEMPLATES ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $action, array( 'edit', 'new' ), true ) ) {
			self::render_editor( 'edit' === $action ? (int) ( $_GET['id'] ?? 0 ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		self::render_list();
	}

	private static function render_list(): void {
		$templates = TemplateRepo::all();
		?>
		<div class="wrap wpm">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Email templates', 'wp-mailer' ); ?></h1>
			<a href="<?php echo esc_url( Admin::url( 'wpm-templates', array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'wp-mailer' ); ?></a>
			<hr class="wp-header-end">

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'Size', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'Last modified', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'Actions', 'wp-mailer' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $templates ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No templates yet.', 'wp-mailer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $templates as $t ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( Admin::url( 'wpm-templates', array( 'action' => 'edit', 'id' => $t['id'] ) ) ); ?>"><?php echo esc_html( $t['name'] ); ?></a></strong></td>
						<td><?php echo esc_html( size_format( (int) $t['size'] ) ); ?></td>
						<td><?php echo Admin::datetime( $t['updated_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td class="wpm-actions">
							<a href="<?php echo esc_url( Admin::url( 'wpm-templates', array( 'action' => 'edit', 'id' => $t['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'wp-mailer' ); ?></a>
							<a href="<?php echo esc_url( self::action_url( 'duplicate', (int) $t['id'] ) ); ?>"><?php esc_html_e( 'Duplicate', 'wp-mailer' ); ?></a>
							<a href="<?php echo esc_url( self::action_url( 'export', (int) $t['id'] ) ); ?>"><?php esc_html_e( 'Export', 'wp-mailer' ); ?></a>
							<a href="<?php echo esc_url( self::action_url( 'delete', (int) $t['id'] ) ); ?>" class="wpm-danger" data-confirm="<?php esc_attr_e( 'Delete this template? Campaigns already sent keep their copy.', 'wp-mailer' ); ?>"><?php esc_html_e( 'Delete', 'wp-mailer' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Import a template', 'wp-mailer' ); ?></h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( Admin::post_url() ); ?>">
				<?php wp_nonce_field( 'wpm_import_template' ); ?>
				<input type="hidden" name="action" value="wpm_import_template">
				<input type="file" name="template_file" accept=".html,.htm,text/html" required>
				<button class="button"><?php esc_html_e( 'Import', 'wp-mailer' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function render_editor( int $id ): void {
		$t = $id ? TemplateRepo::find( $id ) : null;
		if ( $id && ! $t ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Template not found.', 'wp-mailer' ) . '</p></div>';
			return;
		}
		$html = $t['html'] ?? (string) file_get_contents( WPM_DIR . 'templates/newsletter.html' );

		$source  = SourceTable::from_settings();
		$columns = $source ? array_map( 'strtolower', $source->columns() ) : array_keys( self::sample_data() );
		$tags    = array_merge( array( 'content', 'unsubscribe_url', 'view_in_browser_url', 'preheader', 'date' ), array_diff( $columns, array( 'content', 'date' ) ) );
		?>
		<div class="wrap wpm wpm-template-editor">
			<h1 class="wp-heading-inline"><?php echo $t ? esc_html__( 'Edit template', 'wp-mailer' ) : esc_html__( 'New template', 'wp-mailer' ); ?></h1>
			<a href="<?php echo esc_url( Admin::url( 'wpm-templates' ) ); ?>" class="page-title-action"><?php esc_html_e( 'All templates', 'wp-mailer' ); ?></a>
			<hr class="wp-header-end">

			<form method="post" action="<?php echo esc_url( Admin::post_url() ); ?>" id="wpm-template-form">
				<?php wp_nonce_field( 'wpm_template' ); ?>
				<input type="hidden" name="action" value="wpm_save_template">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">

				<p><input type="text" name="name" class="wpm-title" value="<?php echo esc_attr( $t['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Template name', 'wp-mailer' ); ?>" required></p>

				<div class="wpm-toolbar">
					<label><?php esc_html_e( 'Insert', 'wp-mailer' ); ?>
						<select id="wpm-insert-tag">
							<option value=""><?php esc_html_e( 'merge tag…', 'wp-mailer' ); ?></option>
							<?php foreach ( $tags as $tag ) : ?>
								<option value="<?php echo esc_attr( '{{' . $tag . '}}' ); ?>"><?php echo esc_html( '{{' . $tag . '}}' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="button" id="wpm-insert-image"><?php esc_html_e( 'Insert image', 'wp-mailer' ); ?></button>
					<span class="wpm-spacer"></span>
					<button type="button" class="button wpm-device is-active" data-width="100%"><?php esc_html_e( 'Desktop', 'wp-mailer' ); ?></button>
					<button type="button" class="button wpm-device" data-width="375px"><?php esc_html_e( 'Mobile', 'wp-mailer' ); ?></button>
				</div>

				<div class="wpm-split">
					<div class="wpm-code">
						<textarea name="html" id="wpm-template-html" rows="30" class="large-text code"><?php echo esc_textarea( $html ); ?></textarea>
					</div>
					<div class="wpm-preview-wrap">
						<iframe id="wpm-preview" sandbox="" title="<?php esc_attr_e( 'Preview', 'wp-mailer' ); ?>"></iframe>
					</div>
				</div>

				<details class="wpm-details">
					<summary><?php esc_html_e( 'Plain-text version (optional)', 'wp-mailer' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Leave empty to generate it automatically from the HTML when sending.', 'wp-mailer' ); ?></p>
					<textarea name="text_version" rows="8" class="large-text code"><?php echo esc_textarea( $t['text_version'] ?? '' ); ?></textarea>
				</details>

				<p class="submit">
					<button class="button button-primary" name="do" value="save"><?php esc_html_e( 'Save template', 'wp-mailer' ); ?></button>
					<button class="button" name="do" value="test"><?php esc_html_e( 'Save & send test to me', 'wp-mailer' ); ?></button>
					<?php if ( $t ) : ?>
						<a class="button" href="<?php echo esc_url( self::action_url( 'duplicate', $id ) ); ?>"><?php esc_html_e( 'Duplicate', 'wp-mailer' ); ?></a>
						<a class="button" href="<?php echo esc_url( self::action_url( 'export', $id ) ); ?>"><?php esc_html_e( 'Export .html', 'wp-mailer' ); ?></a>
					<?php endif; ?>
				</p>
			</form>

			<?php if ( $t ) : ?>
				<?php $revisions = TemplateRepo::revisions( $id ); ?>
				<?php if ( $revisions ) : ?>
					<h2><?php esc_html_e( 'Revisions', 'wp-mailer' ); ?></h2>
					<table class="widefat striped wpm-narrow">
						<thead><tr><th><?php esc_html_e( 'Saved', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'By', 'wp-mailer' ); ?></th><th><?php esc_html_e( 'Size', 'wp-mailer' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $revisions as $r ) : ?>
							<?php $user = get_userdata( (int) $r['created_by'] ); ?>
							<tr>
								<td><?php echo Admin::datetime( $r['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
								<td><?php echo esc_html( size_format( (int) $r['size'] ) ); ?></td>
								<td><a href="<?php echo esc_url( self::action_url( 'restore', (int) $r['id'] ) ); ?>" data-confirm="<?php esc_attr_e( 'Restore this revision? Unsaved changes in the editor will be lost.', 'wp-mailer' ); ?>"><?php esc_html_e( 'Restore', 'wp-mailer' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>

			<div class="wpm-help">
				<h2><?php esc_html_e( 'Template tips', 'wp-mailer' ); ?></h2>
				<ul>
					<li><?php echo wp_kses_post( __( '<code>{{content}}</code> is where each campaign\'s content goes. One template can serve many campaigns.', 'wp-mailer' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Use any column of your contacts table as a merge tag, with an optional fallback: <code>{{first_name|there}}</code>.', 'wp-mailer' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Include <code>&lt;a href="{{unsubscribe_url}}"&gt;</code>. If missing, a footer link is added automatically.', 'wp-mailer' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Build layouts with tables, a max width around 600px, and absolute https:// image URLs. &lt;style&gt; rules are inlined automatically when sending.', 'wp-mailer' ) ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
