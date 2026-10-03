<?php
use WPM\Bounce\BounceProcessor;
use WPM\Bounce\DsnParser;
use WPM\Track\Token;

function wpm_fixture( string $name, int $send_id = 123, ?Token $t = null ): string {
	$t   = $t ?? new Token( 'k' );
	$raw = file_get_contents( WPM_DIR . 'tests/fixtures/bounces/' . $name );
	$verp_suffix = substr( $t->verp( 'x@y', $send_id ), 2, -2 ); // "123-abcdef0123"
	$b64 = chunk_split( base64_encode( "Delivery has failed to these recipients:\n\nlost@example.com\n550 5.1.10 RESOLVER.ADR.RecipientNotFound; Recipient not found by SMTP address lookup\n\nX-WPM-Send: " . $t->header_value( $send_id ) . "\n" ), 76, "\n" );
	return strtr( $raw, array( '{SEND}' => $verp_suffix, '{HEADER}' => $t->header_value( $send_id ), '{B64}' => $b64 ) );
}

test( 'dsn: postfix 5.1.1 is a hard bounce', function () {
	$p = DsnParser::parse( wpm_fixture( 'postfix-user-unknown.eml' ) );
	ok( $p['is_bounce'] );
	eq( 'hard', $p['type'] );
	eq( '5.1.1', $p['status'] );
	eq( 'nobody@gmail.com', $p['recipient'] );
	has( 'does not exist', $p['diagnostic'] );
} );

test( 'dsn: 5.2.2 mailbox full is soft', function () {
	$p = DsnParser::parse( wpm_fixture( 'mailbox-full.eml' ) );
	ok( $p['is_bounce'] );
	eq( 'soft', $p['type'] );
} );

test( 'dsn: free-text exim bounce is hard', function () {
	$p = DsnParser::parse( wpm_fixture( 'exim-freetext.eml' ) );
	ok( $p['is_bounce'] );
	eq( 'hard', $p['type'] );
} );

test( 'dsn: base64-encoded Exchange bounce is decoded', function () {
	$p = DsnParser::parse( wpm_fixture( 'base64-ms.eml' ) );
	ok( $p['is_bounce'] );
	eq( 'hard', $p['type'] );
	ok( '' !== $p['wpm_header'], 'X-WPM-Send found inside base64 part' );
} );

test( 'dsn: out-of-office, delay notices and human replies are not bounces', function () {
	$ooo = DsnParser::parse( wpm_fixture( 'out-of-office.eml' ) );
	ok( ! $ooo['is_bounce'] && $ooo['is_auto_reply'] );
	ok( ! DsnParser::parse( wpm_fixture( 'delayed.eml' ) )['is_bounce'] );
	$reply = DsnParser::parse( wpm_fixture( 'human-reply.eml' ) );
	ok( ! $reply['is_bounce'] && ! $reply['is_auto_reply'] );
} );

test( 'bounce: matched by VERP address', function () {
	$t = new Token( 'k' );
	eq( array( 'action' => 'matched', 'send_id' => 123, 'type' => 'hard' ), BounceProcessor::handle_message( wpm_fixture( 'postfix-user-unknown.eml', 123, $t ), $t ) );
} );

test( 'bounce: matched by X-WPM-Send header when VERP is absent', function () {
	$t = new Token( 'k' );
	eq( array( 'action' => 'matched', 'send_id' => 77, 'type' => 'soft' ), BounceProcessor::handle_message( wpm_fixture( 'mailbox-full.eml', 77, $t ), $t ) );
	eq( 55, BounceProcessor::handle_message( wpm_fixture( 'base64-ms.eml', 55, $t ), $t )['send_id'] );
} );

test( 'bounce: forged ids are not matched', function () {
	$raw = wpm_fixture( 'mailbox-full.eml', 77, new Token( 'attacker' ) );
	eq( array( 'action' => 'unmatched' ), BounceProcessor::handle_message( $raw, new Token( 'k' ) ) );
} );

test( 'bounce: auto replies and other mail are classified', function () {
	$t = new Token( 'k' );
	eq( 'auto_reply', BounceProcessor::handle_message( wpm_fixture( 'out-of-office.eml', 1, $t ), $t )['action'] );
	eq( 'ignored', BounceProcessor::handle_message( wpm_fixture( 'human-reply.eml', 1, $t ), $t )['action'] );
} );
