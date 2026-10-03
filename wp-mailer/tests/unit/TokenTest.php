<?php
use WPM\Track\Token;

test( 'token: round trip', function () {
	$t   = new Token( 'secret' );
	$tok = $t->make( Token::CLICK, 42, 7 );
	eq( array( 42, 7 ), $t->parse( $tok, Token::CLICK ) );
} );

test( 'token: rejects tampered ids, wrong type and other keys', function () {
	$t   = new Token( 'secret' );
	$tok = $t->make( Token::OPEN, 42 );
	eq( null, $t->parse( str_replace( '.42.', '.43.', $tok ), Token::OPEN ) );
	eq( null, $t->parse( $tok, Token::CLICK ) );
	eq( null, ( new Token( 'other' ) )->parse( $tok, Token::OPEN ) );
	eq( null, $t->parse( 'garbage', Token::OPEN ) );
} );

test( 'token: VERP address round trip and forgery', function () {
	$t    = new Token( 'secret' );
	$addr = $t->verp( 'bounces@example.com', 123 );
	ok( (bool) preg_match( '/^bounces\+123-[a-f0-9]{10}@example\.com$/', $addr ), $addr );
	eq( 123, $t->parse_verp( 'Delivered-To: <' . $addr . '>' ) );
	eq( null, $t->parse_verp( 'bounces+124-0000000000@example.com' ) );
	eq( 123, $t->parse_header_value( $t->header_value( 123 ) ) );
} );
