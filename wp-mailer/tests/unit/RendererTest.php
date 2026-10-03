<?php
use WPM\Campaign\Renderer;
use WPM\Track\Token;

function wpm_renderer( array $opts = array() ): Renderer {
	return new Renderer( new Token( 'k' ), 'https://site.test/wp-json/wpm/v1/', array_merge( array( 'inline_css' => false ), $opts ) );
}

test( 'render: layout injects content and preheader', function () {
	$html = Renderer::layout( '<html><body><h1>T</h1>{{content}}<a href="{{unsubscribe_url}}">u</a></body></html>', '<p>Body</p>', 'Peek' );
	has( '<h1>T</h1><p>Body</p>', $html );
	has( 'Peek', $html );
	ok( substr_count( $html, '{{unsubscribe_url}}' ) === 1, 'no extra footer when link exists' );
} );

test( 'render: layout adds unsubscribe footer and content when placeholders missing', function () {
	$html = Renderer::layout( '<html><body><h1>T</h1></body></html>', '<p>Body</p>' );
	has( '<p>Body</p>', $html );
	has( 'href="{{unsubscribe_url}}"', $html );
	ok( strpos( $html, '<p>Body</p>' ) < strpos( $html, '</body>' ) );
} );

test( 'render: prepare rewrites only trackable links', function () {
	$seen = array();
	$html = '<body><a href="https://a.test/x?y=1&amp;z=2">A</a> <a class="b" href=\'http://b.test\'>B</a> <a href="mailto:x@y.z">M</a>'
		. ' <a href="{{unsubscribe_url}}">U</a> <a data-notrack href="https://c.test">C</a> <a href="#top">T</a> <a href="https://a.test/x?y=1&amp;z=2">A2</a></body>';
	$out  = wpm_renderer()->prepare(
		$html,
		function ( $url ) use ( &$seen ) {
			$seen[ $url ] = $seen[ $url ] ?? count( $seen ) + 1;
			return $seen[ $url ];
		}
	);
	eq( array( 'https://a.test/x?y=1&z=2' => 1, 'http://b.test' => 2 ), $seen );
	has( '<a href="{{wpm_link:1}}">A</a>', $out );
	has( "<a class=\"b\" href='{{wpm_link:2}}'>B</a>", $out );
	has( 'href="mailto:x@y.z"', $out );
	has( 'href="{{unsubscribe_url}}"', $out );
	has( 'href="https://c.test"', $out );
	has( '<a href="{{wpm_link:1}}">A2</a>', $out );
} );

test( 'render: personalize fills merge tags (escaped), tracking URLs and pixel', function () {
	$r    = wpm_renderer();
	$out  = $r->personalize( '<html><body>Hi {{ first_name | friend }} {{last|}}! <a href="{{wpm_link:5}}">x</a> <a href="{{unsubscribe_url}}">u</a></body></html>', 9, array( 'first_name' => '<b>Ana</b>' ) );
	has( 'Hi &lt;b&gt;Ana&lt;/b&gt; !', $out );
	$t = new Token( 'k' );
	has( 'href="https://site.test/wp-json/wpm/v1/c/' . $t->make( 'c', 9, 5 ) . '"', $out );
	has( 'href="https://site.test/wp-json/wpm/v1/u/' . $t->make( 'u', 9 ) . '"', $out );
	has( '<img src="https://site.test/wp-json/wpm/v1/o/' . $t->make( 'o', 9 ) . '"', $out );
	ok( strpos( $out, '<img' ) < strpos( $out, '</body>' ) );
} );

test( 'render: fallback used for missing values; preview mode has no tracking', function () {
	$out = wpm_renderer()->personalize( '<body>{{first_name|there}} <a href="{{unsubscribe_url}}">u</a></body>', 0, array() );
	has( 'there', $out );
	has( 'href="#"', $out );
	lacks( '<img', $out );
} );

test( 'render: tracking can be switched off', function () {
	$r   = wpm_renderer( array( 'track_opens' => false, 'track_clicks' => false ) );
	$out = $r->personalize( $r->prepare( '<body><a href="https://a.test">a</a></body>', fn() => 1 ), 3, array() );
	has( 'href="https://a.test"', $out );
	lacks( '<img', $out );
} );

test( 'render: link targets url-encode merge values', function () {
	eq( 'https://a.test/?e=a%2Bb%40x.com&n=Jo%C3%A3o', Renderer::link_target( 'https://a.test/?e={{email}}&n={{name}}', array( 'email' => 'a+b@x.com', 'name' => 'João' ) ) );
} );

test( 'render: subject merge in text mode is not html-escaped', function () {
	eq( 'News for Tom & Jerry', Renderer::merge( 'News for {{name}}', array( 'name' => 'Tom & Jerry' ), 'text' ) );
} );

test( 'render: full pipeline with CSS inlining keeps tracking placeholders', function () {
	$r   = wpm_renderer( array( 'inline_css' => true ) );
	$html = Renderer::layout( file_get_contents( WPM_DIR . 'templates/newsletter.html' ), '<p>Hello <a href="https://shop.test/?u={{email}}">shop</a></p>', 'Pre' );
	$prep = $r->prepare( $html, fn( $url ) => 'https://shop.test/?u={{email}}' === $url ? 11 : 12 );
	has( '{{wpm_link:11}}', $prep );
	has( 'href="{{unsubscribe_url}}"', $prep );
	has( 'style="', $prep );
	has( '@media only screen', $prep );
	$out = $r->personalize( $prep, 4, array( 'email' => 'z@z.z', 'first_name' => 'Zoe' ) );
	has( 'Hi Zoe,', $out );
	lacks( '{{', $out );
} );
