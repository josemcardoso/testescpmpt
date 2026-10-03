<?php
namespace WPM\Track;

use WPM\Campaign\CampaignRepo;
use WPM\Campaign\Renderer;
use WPM\Send\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Public tracking endpoints:
 *   GET  /wp-json/wpm/v1/o/{token}  open pixel
 *   GET  /wp-json/wpm/v1/c/{token}  click redirect
 *   GET  /wp-json/wpm/v1/u/{token}  unsubscribe confirmation page
 *   POST /wp-json/wpm/v1/u/{token}  unsubscribe (also RFC 8058 one-click)
 *   GET  /wp-json/wpm/v1/v/{token}  view in browser
 */
final class Rest {

	private const TOKEN = '(?P<token>[a-z](?:\.\d+)+\.[a-f0-9]{16})';

	public static function register_routes(): void {
		$public = '__return_true';
		register_rest_route( 'wpm/v1', '/o/' . self::TOKEN, array( 'methods' => 'GET', 'callback' => array( self::class, 'open' ), 'permission_callback' => $public ) );
		register_rest_route( 'wpm/v1', '/c/' . self::TOKEN, array( 'methods' => 'GET', 'callback' => array( self::class, 'click' ), 'permission_callback' => $public ) );
		register_rest_route( 'wpm/v1', '/u/' . self::TOKEN, array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( self::class, 'unsubscribe' ), 'permission_callback' => $public ) );
		register_rest_route( 'wpm/v1', '/v/' . self::TOKEN, array( 'methods' => 'GET', 'callback' => array( self::class, 'view' ), 'permission_callback' => $public ) );
	}

	public static function open( \WP_REST_Request $request ): void {
		$ids = Token::instance()->parse( (string) $request['token'], Token::OPEN );
		if ( $ids ) {
			Tracker::open( $ids[0] );
		}
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function click( \WP_REST_Request $request ): void {
		$ids    = Token::instance()->parse( (string) $request['token'], Token::CLICK );
		$result = $ids && count( $ids ) === 2 ? Tracker::click( $ids[0], $ids[1] ) : null;

		// Only ever redirect to a URL stored for this campaign: never an open redirect.
		$target = $result ? Renderer::link_target( $result['url'], $result['data'] ) : home_url( '/' );
		if ( ! preg_match( '#^https?://#i', $target ) ) {
			$target = home_url( '/' );
		}
		nocache_headers();
		wp_redirect( $target, 302, 'WP Mailer' ); // phpcs:ignore WordPress.Security.SafeRedirect -- destination is a stored campaign link.
		exit;
	}

	public static function unsubscribe( \WP_REST_Request $request ): void {
		$ids = Token::instance()->parse( (string) $request['token'], Token::UNSUBSCRIBE );
		if ( ! $ids ) {
			self::page( __( 'Invalid link', 'wp-mailer' ), '<p>' . esc_html__( 'This unsubscribe link is not valid.', 'wp-mailer' ) . '</p>', 404 );
		}
		$send = Tracker::send( $ids[0] );
		if ( ! $send ) {
			self::page( __( 'Invalid link', 'wp-mailer' ), '<p>' . esc_html__( 'This unsubscribe link has expired.', 'wp-mailer' ) . '</p>', 404 );
		}

		if ( 'POST' === $request->get_method() ) {
			Tracker::unsubscribe( $ids[0] );
			// One-click unsubscribe from Gmail/Yahoo: they only need a 2xx.
			if ( 'One-Click' === (string) $request->get_param( 'List-Unsubscribe' ) ) {
				status_header( 200 );
				exit;
			}
			self::page(
				__( 'Unsubscribed', 'wp-mailer' ),
				'<p>' . sprintf(
					/* translators: %s: email address */
					esc_html__( '%s has been removed from our mailing list.', 'wp-mailer' ),
					'<strong>' . esc_html( $send['email'] ) . '</strong>'
				) . '</p>'
			);
		}

		// GET asks for confirmation: link scanners pre-fetch URLs and must not unsubscribe people.
		$form = '<p>' . sprintf(
			/* translators: %s: email address */
			esc_html__( 'Unsubscribe %s from our mailing list?', 'wp-mailer' ),
			'<strong>' . esc_html( $send['email'] ) . '</strong>'
		) . '</p><form method="post"><button type="submit" class="button">' . esc_html__( 'Unsubscribe', 'wp-mailer' ) . '</button></form>';
		self::page( __( 'Unsubscribe', 'wp-mailer' ), $form );
	}

	public static function view( \WP_REST_Request $request ): void {
		$ids  = Token::instance()->parse( (string) $request['token'], Token::VIEW );
		$send = $ids ? Tracker::send( $ids[0] ) : null;
		$c    = $send ? CampaignRepo::find( (int) $send['campaign_id'] ) : null;
		if ( ! $c || '' === (string) $c['html_snapshot'] ) {
			self::page( __( 'Not found', 'wp-mailer' ), '<p>' . esc_html__( 'This email is no longer available.', 'wp-mailer' ) . '</p>', 404 );
		}
		$data = json_decode( (string) $send['merge_data'], true ) ?: array( 'email' => $send['email'] );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo Queue::renderer( array( 'track_opens' => false ) )->personalize( (string) $c['html_snapshot'], (int) $send['id'], $data ); // phpcs:ignore WordPress.Security.EscapeOutput -- trusted template HTML.
		exit;
	}

	private static function page( string $title, string $body, int $status = 200 ): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		wp_die( $body, esc_html( $title ) . ' — ' . esc_html( get_bloginfo( 'name' ) ), array( 'response' => $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
