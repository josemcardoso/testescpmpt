<?php
namespace WPM\Bounce;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal IMAP4rev1 client (RFC 3501) over PHP streams.
 *
 * Written in-house because the PHP imap extension was removed from core in
 * PHP 8.4 and is missing on many hosts. It only implements what the bounce
 * poller needs: login, select, search, fetch, flag, move.
 */
final class ImapClient {

	/** @var resource|null */
	private $stream;
	private int $seq = 0;

	/**
	 * @param resource|null $stream Pre-opened stream (used by tests).
	 */
	public function __construct( $stream = null ) {
		$this->stream = $stream;
	}

	/**
	 * @param string $encryption ssl (port 993), tls (STARTTLS on 143) or none.
	 */
	public function connect( string $host, int $port, string $encryption = 'ssl', int $timeout = 20 ): void {
		$transport = 'ssl' === $encryption ? 'ssl' : 'tcp';
		$context   = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
					'peer_name'        => $host,
				),
			)
		);
		$stream = @stream_socket_client( "$transport://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $stream ) {
			throw new \RuntimeException( "IMAP connection to $host:$port failed: $errstr ($errno)" );
		}
		stream_set_timeout( $stream, $timeout );
		$this->stream = $stream;

		$greeting = $this->read_line();
		if ( ! preg_match( '/^\* (OK|PREAUTH)/i', $greeting ) ) {
			throw new \RuntimeException( 'Unexpected IMAP greeting: ' . trim( $greeting ) );
		}

		if ( 'tls' === $encryption ) {
			$this->expect_ok( 'STARTTLS' );
			if ( ! stream_socket_enable_crypto( $stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				throw new \RuntimeException( 'STARTTLS negotiation failed' );
			}
		}
	}

	public function login( string $user, string $pass ): void {
		$this->expect_ok( 'LOGIN ' . self::quote( $user ) . ' ' . self::quote( $pass ), 'IMAP login failed' );
	}

	/** @return int Number of messages in the mailbox. */
	public function select( string $mailbox ): int {
		$res = $this->expect_ok( 'SELECT ' . self::quote( $mailbox ), 'Cannot open mailbox ' . $mailbox );
		foreach ( $res['lines'] as $line ) {
			if ( preg_match( '/^\* (\d+) EXISTS/i', $line, $m ) ) {
				return (int) $m[1];
			}
		}
		return 0;
	}

	/** @return int[] UIDs of unseen messages. */
	public function search_unseen(): array {
		$res  = $this->expect_ok( 'UID SEARCH UNSEEN' );
		$uids = array();
		foreach ( $res['lines'] as $line ) {
			if ( preg_match( '/^\* SEARCH\s*(.*)$/i', trim( $line ), $m ) && '' !== $m[1] ) {
				$uids = array_merge( $uids, array_map( 'intval', preg_split( '/\s+/', $m[1] ) ) );
			}
		}
		return $uids;
	}

	/** Full RFC 822 source, without setting \Seen. */
	public function fetch( int $uid ): string {
		$res = $this->expect_ok( "UID FETCH $uid BODY.PEEK[]" );
		return $res['literals'][0] ?? '';
	}

	public function mark_seen( int $uid ): void {
		$this->expect_ok( "UID STORE $uid +FLAGS.SILENT (\\Seen)" );
	}

	public function create_mailbox( string $mailbox ): void {
		$this->command( 'CREATE ' . self::quote( $mailbox ) ); // Fails harmlessly if it exists.
	}

	/** Moves a message, falling back to COPY + delete on servers without MOVE. */
	public function move( int $uid, string $mailbox ): void {
		$res = $this->command( "UID MOVE $uid " . self::quote( $mailbox ) );
		if ( $res['ok'] ) {
			return;
		}
		$this->expect_ok( "UID COPY $uid " . self::quote( $mailbox ) );
		$this->expect_ok( "UID STORE $uid +FLAGS.SILENT (\\Deleted)" );
		$this->command( "UID EXPUNGE $uid" );
	}

	public function logout(): void {
		if ( $this->stream ) {
			try {
				$this->command( 'LOGOUT' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Connection already gone.
			}
			fclose( $this->stream );
			$this->stream = null;
		}
	}

	/**
	 * @return array{ok:bool,lines:string[],literals:string[],status:string}
	 */
	public function command( string $command ): array {
		if ( ! $this->stream ) {
			throw new \RuntimeException( 'Not connected' );
		}
		$tag = 'W' . ( ++$this->seq );
		fwrite( $this->stream, "$tag $command\r\n" );

		$lines    = array();
		$literals = array();
		while ( true ) {
			$line = $this->read_line();
			while ( preg_match( '/\{(\d+)\}\r?\n$/', $line, $m ) ) {
				$literals[] = $this->read_bytes( (int) $m[1] );
				$line       = $this->read_line(); // Rest of the response after the literal.
			}
			if ( str_starts_with( $line, $tag . ' ' ) ) {
				$status = trim( substr( $line, strlen( $tag ) + 1 ) );
				return array(
					'ok'       => (bool) preg_match( '/^OK\b/i', $status ),
					'lines'    => $lines,
					'literals' => $literals,
					'status'   => $status,
				);
			}
			$lines[] = $line;
		}
	}

	private function expect_ok( string $command, string $error = '' ): array {
		$res = $this->command( $command );
		if ( ! $res['ok'] ) {
			$verb = strtok( $command, ' ' );
			throw new \RuntimeException( ( $error ?: "IMAP $verb failed" ) . ': ' . $res['status'] );
		}
		return $res;
	}

	private function read_line(): string {
		$line = fgets( $this->stream );
		if ( false === $line ) {
			$meta = stream_get_meta_data( $this->stream );
			throw new \RuntimeException( ! empty( $meta['timed_out'] ) ? 'IMAP server timed out' : 'IMAP connection closed' );
		}
		return $line;
	}

	private function read_bytes( int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$chunk = fread( $this->stream, $length - strlen( $data ) );
			if ( false === $chunk || '' === $chunk ) {
				$meta = stream_get_meta_data( $this->stream );
				if ( feof( $this->stream ) || ! empty( $meta['timed_out'] ) ) {
					throw new \RuntimeException( 'IMAP connection lost mid-message' );
				}
				continue;
			}
			$data .= $chunk;
		}
		return $data;
	}

	public static function quote( string $value ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}
}
