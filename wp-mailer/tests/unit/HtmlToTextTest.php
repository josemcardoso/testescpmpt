<?php
use WPM\Template\HtmlToText;

test( 'text: converts links, headings, lists and strips hidden preheader', function () {
	$html = '<html><head><style>p{}</style></head><body><div style="display:none;max-height:0">Preview</div>'
		. '<h1>Title</h1><p>Hello &amp; welcome<br>line two</p><ul><li>One</li><li>Two</li></ul>'
		. '<p><a href="https://example.com/a">Read more</a> <a href="https://example.com">https://example.com</a></p></body></html>';
	$text = HtmlToText::convert( $html );
	lacks( 'Preview', $text );
	has( "Title\n\nHello & welcome\nline two", $text );
	has( '- One', $text );
	has( 'Read more (https://example.com/a)', $text );
	lacks( 'https://example.com (https://example.com)', $text );
} );
