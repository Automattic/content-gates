import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
	parseHttpDate,
	parseNonNegativeInt,
	responseTime,
} from '../lib/httpdate.ts';

test( 'parses IMF-fixdate to epoch seconds', () => {
	assert.equal( parseHttpDate( 'Sun, 06 Nov 1994 08:49:37 GMT' ), 784111777 );
	assert.equal( parseHttpDate( 'Thu, 01 Jan 1970 00:00:00 GMT' ), 0 );
	assert.equal(
		parseHttpDate( 'Thu, 22 Oct 2026 12:00:00 GMT' ),
		1792670400
	);
	assert.equal(
		parseHttpDate( '29 Feb 2024 23:59:59 GMT' ),
		Date.UTC( 2024, 1, 29, 23, 59, 59 ) / 1000,
		'weekday is optional; leap day handled'
	);
} );

test( 'agrees with the JavaScript Date parser across many dates', () => {
	for ( let i = 0; i < 400; i++ ) {
		const seconds = 946684800 + i * 7919 * 1000 + ( i % 7 ) * 61;
		const text = new Date( seconds * 1000 ).toUTCString();
		assert.equal( parseHttpDate( text ), seconds, text );
	}
} );

test( 'rejects malformed dates', () => {
	assert.equal( parseHttpDate( null ), -1 );
	assert.equal( parseHttpDate( '' ), -1 );
	assert.equal( parseHttpDate( 'yesterday' ), -1 );
	assert.equal( parseHttpDate( 'Sun, 06 Nov 1994 08:49:37 PST' ), -1 );
	assert.equal( parseHttpDate( 'Sun, 06 Foo 1994 08:49:37 GMT' ), -1 );
	assert.equal( parseHttpDate( 'Sunday, 06-Nov-94 08:49:37 GMT' ), -1 );
} );

test( 'responseTime adds Age when present', () => {
	assert.equal(
		responseTime( 'Sun, 06 Nov 1994 08:49:37 GMT', '23' ),
		784111800
	);
	assert.equal(
		responseTime( 'Sun, 06 Nov 1994 08:49:37 GMT', 'x' ),
		784111777
	);
	assert.equal(
		responseTime( 'Sun, 06 Nov 1994 08:49:37 GMT', null ),
		784111777
	);
	assert.equal( responseTime( null, '5' ), -1 );
} );

test( 'parseNonNegativeInt', () => {
	assert.equal( parseNonNegativeInt( '42' ), 42 );
	assert.equal( parseNonNegativeInt( ' 7 ' ), 7 );
	assert.equal( parseNonNegativeInt( '-1' ), -1 );
	assert.equal( parseNonNegativeInt( '4.2' ), -1 );
	assert.equal( parseNonNegativeInt( '' ), -1 );
} );
