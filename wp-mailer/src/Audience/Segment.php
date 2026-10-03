<?php
namespace WPM\Audience;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a saved segment (JSON rules) into a safe SQL WHERE clause.
 *
 * Column names are only accepted if they exist in the source table, and
 * every value goes through a placeholder, so segments cannot inject SQL.
 *
 * Segment shape: {"match":"all|any","rules":[{"column":"city","op":"eq","value":"Lisbon"}]}
 */
final class Segment {

	public const OPERATORS = array(
		'eq'          => '=',
		'neq'         => '≠',
		'contains'    => 'contains',
		'not_contains' => 'does not contain',
		'starts'      => 'starts with',
		'in'          => 'is one of (comma separated)',
		'gt'          => '>',
		'gte'         => '≥',
		'lt'          => '<',
		'lte'         => '≤',
		'empty'       => 'is empty',
		'not_empty'   => 'is not empty',
	);

	public static function decode( ?string $json ): array {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$rules = array();
		foreach ( (array) ( $data['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['column'] ) || ! isset( self::OPERATORS[ $rule['op'] ?? '' ] ) ) {
				continue;
			}
			$rules[] = array(
				'column' => (string) $rule['column'],
				'op'     => (string) $rule['op'],
				'value'  => is_scalar( $rule['value'] ?? '' ) ? (string) ( $rule['value'] ?? '' ) : '',
			);
		}
		return array(
			'match' => ( $data['match'] ?? 'all' ) === 'any' ? 'any' : 'all',
			'rules' => $rules,
		);
	}

	/**
	 * @param array    $segment Decoded segment.
	 * @param string[] $columns Columns that exist in the source table.
	 * @return array{0:string,1:array} SQL fragment with %s placeholders, and its values.
	 */
	public static function to_sql( array $segment, array $columns ): array {
		$parts  = array();
		$params = array();

		foreach ( $segment['rules'] ?? array() as $rule ) {
			if ( ! in_array( $rule['column'], $columns, true ) ) {
				throw new \InvalidArgumentException( 'Unknown column: ' . $rule['column'] );
			}
			$col   = '`' . str_replace( '`', '', $rule['column'] ) . '`';
			$value = (string) $rule['value'];

			switch ( $rule['op'] ) {
				case 'eq':
				case 'neq':
				case 'gt':
				case 'gte':
				case 'lt':
				case 'lte':
					$sql      = array(
						'eq'  => '=',
						'neq' => '<>',
						'gt'  => '>',
						'gte' => '>=',
						'lt'  => '<',
						'lte' => '<=',
					)[ $rule['op'] ];
					$parts[]  = "$col $sql %s";
					$params[] = $value;
					break;
				case 'contains':
				case 'not_contains':
				case 'starts':
					$like     = self::esc_like( $value );
					$parts[]  = 'not_contains' === $rule['op'] ? "($col IS NULL OR $col NOT LIKE %s)" : "$col LIKE %s";
					$params[] = 'starts' === $rule['op'] ? $like . '%' : '%' . $like . '%';
					break;
				case 'in':
					$items = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
					if ( ! $items ) {
						$parts[] = '1=0';
						break;
					}
					$parts[] = "$col IN (" . implode( ',', array_fill( 0, count( $items ), '%s' ) ) . ')';
					$params  = array_merge( $params, $items );
					break;
				case 'empty':
					$parts[] = "($col IS NULL OR $col = '')";
					break;
				case 'not_empty':
					$parts[] = "($col IS NOT NULL AND $col <> '')";
					break;
			}
		}

		if ( ! $parts ) {
			return array( '1=1', array() );
		}
		$glue = 'any' === ( $segment['match'] ?? 'all' ) ? ' OR ' : ' AND ';
		return array( '(' . implode( $glue, $parts ) . ')', $params );
	}

	/** Same escaping as $wpdb->esc_like(). */
	private static function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
}
