<?php
namespace WPM\Audience;

use WPM\Settings;
use WPM\Suppressions;

defined( 'ABSPATH' ) || exit;

/**
 * Reads recipients from the configured MySQL table (e.g. another plugin's table).
 *
 * The table and column names come from settings and are always checked against
 * SHOW TABLES / SHOW COLUMNS before being placed in a query.
 */
final class SourceTable {

	public function __construct(
		private string $table,
		private string $id_col,
		private string $email_col
	) {}

	public static function from_settings(): ?self {
		$s = Settings::all();
		if ( ! $s['source_table'] || ! $s['source_email_col'] ) {
			return null;
		}
		if ( ! in_array( $s['source_table'], self::tables(), true ) ) {
			return null;
		}
		$columns = self::columns_of( $s['source_table'] );
		if ( ! in_array( $s['source_email_col'], $columns, true ) ) {
			return null;
		}
		$id = in_array( $s['source_id_col'], $columns, true ) ? $s['source_id_col'] : $s['source_email_col'];
		return new self( $s['source_table'], $id, $s['source_email_col'] );
	}

	/** @return string[] */
	public static function tables(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( 'SHOW TABLES' ) );
	}

	/** @return string[] */
	public static function columns_of( string $table ): array {
		global $wpdb;
		if ( ! in_array( $table, self::tables(), true ) ) {
			return array();
		}
		return array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . str_replace( '`', '', $table ) . '`' ) );
	}

	public function columns(): array {
		return self::columns_of( $this->table );
	}

	public function email_column(): string {
		return $this->email_col;
	}

	public function id_column(): string {
		return $this->id_col;
	}

	private function where( array $segment ): string {
		global $wpdb;
		[ $sql, $params ] = Segment::to_sql( $segment, $this->columns() );
		$email            = '`' . str_replace( '`', '', $this->email_col ) . '`';
		$sql             .= " AND $email IS NOT NULL AND $email <> ''";
		return $params ? $wpdb->prepare( $sql, $params ) : $sql; // phpcs:ignore WordPress.DB
	}

	public function count( array $segment ): int {
		global $wpdb;
		$table = '`' . str_replace( '`', '', $this->table ) . '`';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE " . $this->where( $segment ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array<int,array<string,mixed>> Raw rows.
	 */
	public function rows( array $segment, int $limit, int $offset = 0 ): array {
		global $wpdb;
		$table = '`' . str_replace( '`', '', $this->table ) . '`';
		$order = '`' . str_replace( '`', '', $this->id_col ) . '`';
		$sql   = "SELECT * FROM $table WHERE " . $this->where( $segment ) . " ORDER BY $order LIMIT %d OFFSET %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Converts a raw row into a recipient: email, id and merge data (lower-case column keys).
	 */
	public function to_recipient( array $row ): ?array {
		$email = strtolower( trim( (string) ( $row[ $this->email_col ] ?? '' ) ) );
		if ( ! is_email( $email ) ) {
			return null;
		}
		$data = array();
		foreach ( $row as $key => $value ) {
			$data[ strtolower( (string) $key ) ] = is_scalar( $value ) ? (string) $value : '';
		}
		$data['email'] = $email;
		return array(
			'email' => $email,
			'ref'   => (string) ( $row[ $this->id_col ] ?? $email ),
			'data'  => $data,
		);
	}

	/**
	 * Walks every matching, valid, non-suppressed, de-duplicated recipient in pages.
	 *
	 * @param callable $callback Receives an array of recipients per page.
	 */
	public function each_recipient( array $segment, callable $callback, int $page_size = 500 ): void {
		$skip   = Suppressions::all_emails(); // Grows with each address seen, so duplicates are skipped too.
		$offset = 0;
		do {
			$rows   = $this->rows( $segment, $page_size, $offset );
			$offset += $page_size;
			$batch  = array();
			foreach ( $rows as $row ) {
				$r = $this->to_recipient( $row );
				if ( $r && ! isset( $skip[ $r['email'] ] ) ) {
					$skip[ $r['email'] ] = true;
					$batch[]             = $r;
				}
			}
			if ( $batch ) {
				$callback( $batch );
			}
		} while ( count( $rows ) === $page_size );
	}

	/** Recipient count after removing invalid and suppressed addresses (exact, used for previews). */
	public function deliverable_count( array $segment ): int {
		$n = 0;
		$this->each_recipient(
			$segment,
			static function ( array $batch ) use ( &$n ) {
				$n += count( $batch );
			}
		);
		return $n;
	}

	public function sample( array $segment = array() ): array {
		$rows = $this->rows( $segment ?: array( 'rules' => array() ), 1 );
		return $rows ? ( $this->to_recipient( $rows[0] )['data'] ?? array() ) : array();
	}
}
