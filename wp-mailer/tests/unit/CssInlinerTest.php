<?php
use WPM\Template\CssInliner;

test( 'css: inlines tag, class, id and descendant rules by specificity', function () {
	$html = '<html><head><style>
		p { color: red; font-size: 14px }
		.lead { color: blue }
		#hero p.lead { color: green }
		td > a { text-decoration: none }
	</style></head><body><div id="hero"><p class="lead x">A</p></div><p>B</p><table><tr><td><a href="#">L</a></td></tr></table></body></html>';
	$out = ( new CssInliner() )->inline( $html );
	has( '<p class="lead x" style="font-size: 14px; color: green;">A</p>', $out );
	has( '<p style="color: red; font-size: 14px;">B</p>', $out );
	has( 'style="text-decoration: none;"', $out );
	lacks( '<style', $out, 'nothing left to keep in <style>' );
} );

test( 'css: existing inline style wins unless !important', function () {
	$html = '<html><head><style>.a { color: red; margin: 0 !important }</style></head><body><p class="a" style="color: black; margin: 5px">x</p></body></html>';
	$out  = ( new CssInliner() )->inline( $html );
	has( 'style="margin: 0; color: black;"', $out );
} );

test( 'css: keeps @media, pseudo-classes and merge tags in URLs', function () {
	$html = '<html><head><style>a:hover { color: red } @media (max-width: 600px) { .c { width: 100% !important } } .c { width: 600px }</style></head>'
		. '<body><table class="c"><tr><td><a href="{{unsubscribe_url}}">u</a> <a href="https://x.test/?e={{email}}">e</a> {{first_name}}</td></tr></table></body></html>';
	$out  = ( new CssInliner() )->inline( $html );
	has( '@media (max-width: 600px)', $out );
	has( 'a:hover { color: red }', $out );
	has( 'class="c" style="width: 600px;"', $out );
	has( 'href="{{unsubscribe_url}}"', $out );
	has( 'href="https://x.test/?e={{email}}"', $out );
	has( '{{first_name}}', $out );
} );

test( 'css: html without <style> is returned untouched', function () {
	$html = '<p>Hello {{first_name}}</p>';
	eq( $html, ( new CssInliner() )->inline( $html ) );
} );

test( 'css: keeps UTF-8 text intact', function () {
	$out = ( new CssInliner() )->inline( '<html><head><style>p{color:red}</style></head><body><p>Olá, ação €</p></body></html>' );
	has( 'Olá, ação €', $out );
} );
