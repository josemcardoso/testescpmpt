<?php
use WPM\Bounce\ImapClient;

/** Scripted IMAP server: reads come from a fixed transcript, writes are captured. */
final class Fake_Imap_Stream {
	public static string $responses = '';
	public static string $written   = '';
	private int $pos                = 0;
	public $context;

	public function stream_open( $path, $mode, $options, &$opened ) {
		return true;
	}
	public function stream_read( $count ) {
		// Hand out one line at a time: PHP drops buffered read data on write.
		$nl         = strpos( self::$responses, "\n", $this->pos );
		$len        = false === $nl ? $count : min( $count, $nl - $this->pos + 1 );
		$chunk      = substr( self::$responses, $this->pos, $len );
		$this->pos += strlen( $chunk );
		return $chunk;
	}
	public function stream_write( $data ) {
		self::$written .= $data;
		return strlen( $data );
	}
	public function stream_eof() {
		return $this->pos >= strlen( self::$responses );
	}
	public function stream_set_option( $option, $arg1, $arg2 ) {
		return false;
	}
	public function stream_close() {}
}
stream_wrapper_register( 'fakeimap', Fake_Imap_Stream::class );

test( 'imap: login, select, search, fetch literal, move', function () {
	$msg = "Subject: Hi\r\nX-Test: {5}\r\n\r\nBody with ) and {3}\r\n";
	Fake_Imap_Stream::$responses = implode(
		'',
		array(
			"W1 OK LOGIN completed\r\n",
			"* 3 EXISTS\r\n* 0 RECENT\r\nW2 OK [READ-WRITE] SELECT completed\r\n",
			"* SEARCH 4 9\r\nW3 OK SEARCH completed\r\n",
			'* 1 FETCH (UID 4 BODY[] {' . strlen( $msg ) . "}\r\n" . $msg . ")\r\nW4 OK FETCH completed\r\n",
			"W5 BAD unknown command MOVE\r\n",
			"W6 OK COPY completed\r\n",
			"W7 OK STORE completed\r\n",
			"W8 OK EXPUNGE completed\r\n",
		)
	);
	Fake_Imap_Stream::$written = '';

	$c = new ImapClient( fopen( 'fakeimap://server', 'r+' ) );
	$c->login( 'user@x.com', 'pa"ss\\word' );
	eq( 3, $c->select( 'INBOX' ) );
	eq( array( 4, 9 ), $c->search_unseen() );
	eq( $msg, $c->fetch( 4 ) );
	$c->move( 4, 'Done' );

	has( 'W1 LOGIN "user@x.com" "pa\\"ss\\\\word"', Fake_Imap_Stream::$written );
	has( 'W4 UID FETCH 4 BODY.PEEK[]', Fake_Imap_Stream::$written );
	has( 'W6 UID COPY 4 "Done"', Fake_Imap_Stream::$written );
	has( 'W7 UID STORE 4 +FLAGS.SILENT (\\Deleted)', Fake_Imap_Stream::$written );
} );

test( 'imap: failed login throws', function () {
	Fake_Imap_Stream::$responses = "W1 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n";
	$c = new ImapClient( fopen( 'fakeimap://server', 'r+' ) );
	try {
		$c->login( 'a', 'b' );
		ok( false, 'should throw' );
	} catch ( RuntimeException $e ) {
		has( 'Invalid credentials', $e->getMessage() );
	}
} );
