<?php
namespace WPM\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Moves <style> rules into style="" attributes, because Gmail, Outlook and
 * many webmail clients ignore or strip <head> styles.
 *
 * Supported selectors: element, .class, #id, compounds (td.btn) and the
 * descendant / child combinators. Anything else (pseudo-classes, attribute
 * selectors, @media, @font-face) is kept in a <style> block so clients that
 * support it still apply it.
 */
final class CssInliner {

	private const L = 'WPMLBRACE';
	private const R = 'WPMRBRACE';

	public function inline( string $html ): string {
		if ( ! preg_match( '/<style\b/i', $html ) || ! class_exists( \DOMDocument::class ) ) {
			return $html;
		}

		// libxml percent-encodes "{" inside href/src, which would break merge tags.
		$html = str_replace( array( '{{', '}}' ), array( self::L, self::R ), $html );

		$doctype = preg_match( '/^\s*(<!DOCTYPE[^>]*>)/i', $html, $m ) ? $m[1] : '<!DOCTYPE html>';

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$css    = '';
		$styles = array();
		foreach ( $doc->getElementsByTagName( 'style' ) as $style ) {
			$styles[] = $style;
		}
		foreach ( $styles as $style ) {
			$media = strtolower( (string) $style->getAttribute( 'media' ) );
			if ( '' !== $media && 'all' !== $media && 'screen' !== $media ) {
				continue; // Leave print/other-media blocks alone.
			}
			if ( $style->hasAttribute( 'data-inline' ) && 'false' === $style->getAttribute( 'data-inline' ) ) {
				continue;
			}
			$css .= "\n" . $style->textContent;
			$style->parentNode->removeChild( $style );
		}

		[ $rules, $kept ] = $this->parse( $css );
		$this->apply( $doc, $rules );

		if ( '' !== trim( $kept ) ) {
			$head = $doc->getElementsByTagName( 'head' )->item( 0 );
			if ( $head ) {
				$node = $doc->createElement( 'style' );
				$node->setAttribute( 'type', 'text/css' );
				$node->appendChild( $doc->createTextNode( $kept ) );
				$head->appendChild( $node );
			}
		}

		$out = '';
		foreach ( $doc->childNodes as $child ) {
			if ( XML_PI_NODE === $child->nodeType || XML_DOCUMENT_TYPE_NODE === $child->nodeType ) {
				continue;
			}
			$out .= $doc->saveHTML( $child );
		}

		return str_replace( array( self::L, self::R ), array( '{{', '}}' ), $doctype . "\n" . $out );
	}

	/**
	 * @return array{0:array,1:string} Inlinable rules and the CSS that must stay in <style>.
	 */
	public function parse( string $css ): array {
		$css  = preg_replace( '#/\*.*?\*/#s', '', $css ) ?? '';
		$kept = '';

		// Pull out at-rules (with or without a block).
		$plain = '';
		$len   = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '@' !== $css[ $i ] ) {
				$plain .= $css[ $i ];
				continue;
			}
			$brace = strpos( $css, '{', $i );
			$semi  = strpos( $css, ';', $i );
			if ( false !== $semi && ( false === $brace || $semi < $brace ) ) {
				$kept .= substr( $css, $i, $semi - $i + 1 ) . "\n";
				$i     = $semi;
				continue;
			}
			if ( false === $brace ) {
				break;
			}
			$depth = 0;
			for ( $j = $brace; $j < $len; $j++ ) {
				if ( '{' === $css[ $j ] ) {
					++$depth;
				} elseif ( '}' === $css[ $j ] && 0 === --$depth ) {
					break;
				}
			}
			$kept .= substr( $css, $i, $j - $i + 1 ) . "\n";
			$i     = $j;
		}

		$rules = array();
		$order = 0;
		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $plain, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$decls = trim( $m[2] );
				if ( '' === $decls ) {
					continue;
				}
				foreach ( explode( ',', $m[1] ) as $selector ) {
					$selector = trim( $selector );
					if ( '' === $selector ) {
						continue;
					}
					$xpath = $this->to_xpath( $selector );
					if ( null === $xpath ) {
						$kept .= $selector . ' { ' . $decls . " }\n";
						continue;
					}
					$rules[] = array(
						'xpath'       => $xpath,
						'specificity' => $this->specificity( $selector ),
						'order'       => $order++,
						'decls'       => $this->declarations( $decls ),
					);
				}
			}
		}

		usort(
			$rules,
			static fn( $a, $b ) => array( $a['specificity'], $a['order'] ) <=> array( $b['specificity'], $b['order'] )
		);

		return array( $rules, $kept );
	}

	public function to_xpath( string $selector ): ?string {
		if ( preg_match( '/[:\[\]+~*]/', $selector ) ) {
			return null;
		}
		$selector = preg_replace( '/\s*>\s*/', ' > ', trim( $selector ) ) ?? '';
		$tokens   = preg_split( '/\s+/', $selector ) ?: array();
		$xpath    = '';
		$axis     = '//';
		foreach ( $tokens as $token ) {
			if ( '>' === $token ) {
				$axis = '/';
				continue;
			}
			if ( ! preg_match( '/^([a-z][a-z0-9-]*)?((?:[.#][a-z0-9_-]+)*)$/i', $token, $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
				return null;
			}
			$step = '' !== $m[1] ? strtolower( $m[1] ) : '*';
			preg_match_all( '/([.#])([a-z0-9_-]+)/i', $m[2], $parts, PREG_SET_ORDER );
			foreach ( $parts as $p ) {
				$step .= '.' === $p[1]
					? "[contains(concat(' ', normalize-space(@class), ' '), ' {$p[2]} ')]"
					: "[@id='{$p[2]}']";
			}
			$xpath .= $axis . $step;
			$axis   = '//';
		}
		return '' === $xpath ? null : $xpath;
	}

	private function specificity( string $selector ): int {
		$ids     = preg_match_all( '/#[a-z0-9_-]+/i', $selector );
		$classes = preg_match_all( '/\.[a-z0-9_-]+/i', $selector );
		$tags    = preg_match_all( '/(?:^|[\s>])[a-z][a-z0-9-]*/i', $selector );
		return $ids * 10000 + $classes * 100 + $tags;
	}

	/** @return array<string,string> property => value, in source order. */
	private function declarations( string $block ): array {
		$out = array();
		foreach ( preg_split( '/;(?![^(]*\))/', $block ) ?: array() as $decl ) {
			$pos = strpos( $decl, ':' );
			if ( false === $pos ) {
				continue;
			}
			$prop  = strtolower( trim( substr( $decl, 0, $pos ) ) );
			$value = trim( substr( $decl, $pos + 1 ) );
			if ( '' !== $prop && '' !== $value ) {
				$out[ $prop ] = $value;
			}
		}
		return $out;
	}

	private function apply( \DOMDocument $doc, array $rules ): void {
		if ( ! $rules ) {
			return;
		}
		$xpath    = new \DOMXPath( $doc );
		$computed = new \SplObjectStorage();

		foreach ( $rules as $rule ) {
			$nodes = @$xpath->query( $rule['xpath'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $nodes ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$current = $computed->contains( $node ) ? $computed[ $node ] : array();
				foreach ( $rule['decls'] as $prop => $value ) {
					// A non-!important value never overrides an !important one.
					if ( isset( $current[ $prop ] ) && str_contains( $current[ $prop ], '!important' ) && ! str_contains( $value, '!important' ) ) {
						continue;
					}
					unset( $current[ $prop ] );
					$current[ $prop ] = $value;
				}
				$computed[ $node ] = $current;
			}
		}

		foreach ( $computed as $node ) {
			$props  = $computed[ $node ];
			$inline = $this->declarations( (string) $node->getAttribute( 'style' ) );
			foreach ( $inline as $prop => $value ) {
				if ( isset( $props[ $prop ] ) && str_contains( $props[ $prop ], '!important' ) ) {
					continue;
				}
				unset( $props[ $prop ] );
				$props[ $prop ] = $value;
			}
			$style = array();
			foreach ( $props as $prop => $value ) {
				$style[] = $prop . ': ' . trim( str_replace( '!important', '', $value ) );
			}
			$node->setAttribute( 'style', implode( '; ', $style ) . ';' );
		}
	}
}
