<?php
use WPM\Audience\Segment;

test( 'segment: empty means everyone', function () {
	eq( array( '1=1', array() ), Segment::to_sql( Segment::decode( '' ), array( 'email' ) ) );
} );

test( 'segment: operators produce placeholders, never raw values', function () {
	$seg = Segment::decode( json_encode( array(
		'match' => 'all',
		'rules' => array(
			array( 'column' => 'city', 'op' => 'eq', 'value' => "Lisbon' OR 1=1 --" ),
			array( 'column' => 'name', 'op' => 'contains', 'value' => '50%_off' ),
			array( 'column' => 'tier', 'op' => 'in', 'value' => 'gold, silver ,' ),
			array( 'column' => 'phone', 'op' => 'empty', 'value' => '' ),
		),
	) ) );
	[ $sql, $params ] = Segment::to_sql( $seg, array( 'city', 'name', 'tier', 'phone' ) );
	eq( "(`city` = %s AND `name` LIKE %s AND `tier` IN (%s,%s) AND (`phone` IS NULL OR `phone` = ''))", $sql );
	eq( array( "Lisbon' OR 1=1 --", '%50\\%\\_off%', 'gold', 'silver' ), $params );
} );

test( 'segment: any = OR', function () {
	$seg = Segment::decode( '{"match":"any","rules":[{"column":"a","op":"gt","value":"1"},{"column":"a","op":"lte","value":"9"}]}' );
	eq( array( '(`a` > %s OR `a` <= %s)', array( '1', '9' ) ), Segment::to_sql( $seg, array( 'a' ) ) );
} );

test( 'segment: unknown or malicious column is rejected', function () {
	$seg = Segment::decode( '{"rules":[{"column":"x` = 1; DROP TABLE wp_users; --","op":"eq","value":"1"}]}' );
	try {
		Segment::to_sql( $seg, array( 'email' ) );
		ok( false, 'should throw' );
	} catch ( InvalidArgumentException $e ) {
		ok( true );
	}
} );

test( 'segment: invalid operators are dropped on decode', function () {
	eq( array(), Segment::decode( '{"rules":[{"column":"a","op":"; DELETE","value":"1"}]}' )['rules'] );
} );
