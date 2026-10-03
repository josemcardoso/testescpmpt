<?php
namespace WPM\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the text/plain alternative of an email from its HTML.
 * A text part improves deliverability and is what some clients show.
 */
final class HtmlToText {

	public static function convert( string $html ): string {
		$text = preg_replace( '#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html ) ?? '';
		$text = preg_replace( '#<!--.*?-->#s', '', $text ) ?? '';

		// Hidden preheader spans should not show in the text part.
		$text = preg_replace( '#<(span|div)\b[^>]*display\s*:\s*none[^>]*>.*?</\1>#is', '', $text ) ?? '';

		// Links: "label (url)", or just the url when the label is the url.
		$text = preg_replace_callback(
			'#<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$url   = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$label = trim( html_entity_decode( strip_tags( $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' === $label || $label === $url || str_starts_with( $url, '#' ) ) {
					return '' === $label ? $url : $label;
				}
				return $label . ' (' . $url . ')';
			},
			$text
		) ?? '';

		$text = preg_replace( '#<img\b[^>]*\balt\s*=\s*(["\'])(.+?)\1[^>]*>#is', '[$2]', $text ) ?? '';
		$text = preg_replace( '#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', "\n\n$1\n\n", $text ) ?? '';
		$text = preg_replace( '#<li\b[^>]*>#i', "\n- ", $text ) ?? '';
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text ) ?? '';
		$text = preg_replace( '#</(p|div|tr|table|ul|ol|blockquote)>#i', "\n\n", $text ) ?? '';
		$text = preg_replace( '#</t[dh]>#i', ' ', $text ) ?? '';
		$text = preg_replace( '#<hr\b[^>]*>#i', "\n----------\n", $text ) ?? '';

		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text ) ?? '';
		$text = preg_replace( '/ *\n */', "\n", $text ) ?? '';
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? '';

		return wordwrap( trim( $text ), 78, "\n", false );
	}
}
