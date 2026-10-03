<?php
namespace WPM\Campaign;

use WPM\Template\CssInliner;
use WPM\Track\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Turns template + campaign content into the final, per-recipient email.
 *
 * Two stages, so the expensive work happens once per campaign:
 *  1. prepare():     template layout -> CSS inlined -> links swapped for {{wpm_link:N}}
 *  2. personalize(): merge tags, tracked link URLs, unsubscribe URL, open pixel
 */
final class Renderer {

	private const MERGE_TAG = '/\{\{\s*([a-zA-Z0-9_]+)\s*(?:\|([^{}]*))?\}\}/';
	private const SYSTEM    = array( 'content', 'unsubscribe_url', 'view_in_browser_url', 'preheader' );

	private array $opts;

	public function __construct(
		private Token $token,
		private string $endpoint,
		array $opts = array()
	) {
		$this->endpoint = rtrim( $endpoint, '/' );
		$this->opts     = array_merge(
			array(
				'inline_css'   => true,
				'track_opens'  => true,
				'track_clicks' => true,
			),
			$opts
		);
	}

	/**
	 * Places campaign content into a template and guarantees an unsubscribe link.
	 */
	public static function layout( string $template, string $content, string $preheader = '', string $unsubscribe_label = 'Unsubscribe' ): string {
		if ( '' === trim( $template ) ) {
			$template = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body>{{content}}</body></html>';
		}

		$html = str_contains( $template, '{{content}}' )
			? str_replace( '{{content}}', $content, $template )
			: self::before_body_end( $template, $content );

		$hidden = '' === $preheader ? '' : '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:transparent;opacity:0">' . htmlspecialchars( $preheader, ENT_QUOTES, 'UTF-8' ) . str_repeat( '&#847;&zwnj;&nbsp;', 30 ) . '</div>';
		if ( str_contains( $html, '{{preheader}}' ) ) {
			$html = str_replace( '{{preheader}}', $hidden, $html );
		} elseif ( '' !== $hidden ) {
			$html = preg_match( '/<body\b[^>]*>/i', $html ) ? preg_replace( '/(<body\b[^>]*>)/i', '$1' . $hidden, $html, 1 ) : $hidden . $html;
		}

		if ( ! str_contains( $html, '{{unsubscribe_url}}' ) ) {
			$footer = '<p style="font-family:Arial,sans-serif;font-size:12px;color:#888888;text-align:center;margin:24px 0">'
				. '<a href="{{unsubscribe_url}}" style="color:#888888">' . htmlspecialchars( $unsubscribe_label, ENT_QUOTES, 'UTF-8' ) . '</a></p>';
			$html   = self::before_body_end( $html, $footer );
		}

		return $html;
	}

	/**
	 * @param callable $link_id fn( string $url ): int — stores the URL and returns its ID.
	 */
	public function prepare( string $html, callable $link_id ): string {
		if ( $this->opts['inline_css'] ) {
			$html = ( new CssInliner() )->inline( $html );
		}
		if ( ! $this->opts['track_clicks'] ) {
			return $html;
		}

		return preg_replace_callback(
			'/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/is',
			static function ( array $m ) use ( $link_id ): string {
				$url = trim( html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if (
					! preg_match( '#^https?://#i', $url )
					|| str_contains( $url, '{{unsubscribe_url}}' )
					|| str_contains( $url, '{{view_in_browser_url}}' )
					|| preg_match( '/\bdata-notrack\b/i', $m[1] )
				) {
					return $m[0];
				}
				return $m[1] . $m[2] . '{{wpm_link:' . (int) $link_id( $url ) . '}}' . $m[2];
			},
			$html
		) ?? $html;
	}

	/**
	 * @param int   $send_id 0 for previews and test sends (no tracking).
	 * @param array $data    Merge data (lower-case keys).
	 */
	public function personalize( string $prepared, int $send_id, array $data ): string {
		$tracking = $send_id > 0;

		$html = preg_replace_callback(
			'/\{\{wpm_link:(\d+)\}\}/',
			fn( array $m ) => $tracking ? $this->url( Token::CLICK, $send_id, (int) $m[1] ) : '#',
			$prepared
		) ?? $prepared;

		$html = strtr(
			$html,
			array(
				'{{unsubscribe_url}}'     => $tracking ? $this->url( Token::UNSUBSCRIBE, $send_id ) : '#',
				'{{view_in_browser_url}}' => $tracking ? $this->url( Token::VIEW, $send_id ) : '#',
			)
		);

		$html = self::merge( $html, $data, 'html' );

		if ( $tracking && $this->opts['track_opens'] ) {
			$pixel = '<img src="' . $this->url( Token::OPEN, $send_id ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;margin:0;padding:0" />';
			$html  = self::before_body_end( $html, $pixel );
		}

		return $html;
	}

	/**
	 * Replaces {{tag}} / {{tag|default}} with recipient data.
	 *
	 * @param string $mode html (escaped), url (url-encoded) or text (raw).
	 */
	public static function merge( string $text, array $data, string $mode = 'html' ): string {
		if ( ! isset( $data['date'] ) ) {
			$data['date'] = function_exists( 'wp_date' ) ? (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ) ) : gmdate( 'Y-m-d' );
		}
		return preg_replace_callback(
			self::MERGE_TAG,
			static function ( array $m ) use ( $data, $mode ): string {
				$key = strtolower( $m[1] );
				if ( in_array( $key, self::SYSTEM, true ) ) {
					return $m[0];
				}
				$value = (string) ( $data[ $key ] ?? '' );
				if ( '' === trim( $value ) ) {
					$value = trim( $m[2] ?? '' );
				}
				return match ( $mode ) {
					'url'   => rawurlencode( $value ),
					'text'  => $value,
					default => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ),
				};
			},
			$text
		) ?? $text;
	}

	/** Final destination of a tracked link (merge tags in URLs are filled per recipient). */
	public static function link_target( string $stored_url, array $data ): string {
		return self::merge( $stored_url, $data, 'url' );
	}

	public function url( string $type, int ...$ids ): string {
		return $this->endpoint . '/' . $type . '/' . $this->token->make( $type, ...$ids );
	}

	private static function before_body_end( string $html, string $insert ): string {
		$pos = strripos( $html, '</body>' );
		return false === $pos ? $html . $insert : substr_replace( $html, $insert, $pos, 0 );
	}
}
