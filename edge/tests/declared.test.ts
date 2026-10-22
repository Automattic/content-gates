import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
	decodeRecord,
	encodeRecord,
	isCovered,
	joinNames,
	parseNameList,
	sameNames,
} from '../assembly/declared.ts';

test( 'parseNameList sorts, de-duplicates, and drops invalid names', () => {
	assert.deepEqual( parseNameList( 'subscriber, donor, subscriber' ), [
		'donor',
		'subscriber',
	] );
	assert.deepEqual( parseNameList( '' ), [] );
	assert.deepEqual( parseNameList( ' , bad name, ok ' ), [ 'ok' ] );
} );

test( 'joinNames matches the declared header format', () => {
	assert.equal( joinNames( [ 'subscriber', 'donor' ] ), 'donor, subscriber' );
	assert.equal( joinNames( [] ), '' );
} );

test( 'record encoding round-trips, including the empty set', () => {
	assert.equal(
		encodeRecord( [ 'subscriber', 'donor' ] ),
		'v1:donor,subscriber'
	);
	assert.deepEqual( decodeRecord( 'v1:donor,subscriber' ), [
		'donor',
		'subscriber',
	] );
	assert.equal( encodeRecord( [] ), 'v1:' );
	assert.deepEqual( decodeRecord( 'v1:' ), [] );
} );

test( 'absent or foreign records decode to null', () => {
	assert.equal( decodeRecord( null ), null );
	assert.equal( decodeRecord( '' ), null );
	assert.equal( decodeRecord( 'v2:x' ), null );
	assert.equal( decodeRecord( 'donor,subscriber' ), null );
} );

test( 'coverage: a full lookup covers everything', () => {
	assert.equal( isCovered( [ 'donor', 'subscriber' ], true, [] ), true );
} );

test( 'coverage: a narrowed lookup must include every declared group', () => {
	assert.equal(
		isCovered( [ 'subscriber' ], false, [ 'subscriber' ] ),
		true
	);
	assert.equal(
		isCovered( [ 'subscriber' ], false, [ 'donor', 'subscriber' ] ),
		true,
		'a too-wide record costs variants, not correctness'
	);
	assert.equal(
		isCovered( [ 'newsletter', 'subscriber' ], false, [ 'subscriber' ] ),
		false,
		'a declared group narrowed away fails'
	);
	assert.equal( isCovered( [], false, [] ), true );
	assert.equal( isCovered( [ 'subscriber' ], false, [] ), false );
} );

test( 'sameNames compares as sets', () => {
	assert.equal( sameNames( [ 'a', 'b' ], [ 'b', 'a' ] ), true );
	assert.equal( sameNames( [ 'a' ], [ 'a', 'b' ] ), false );
} );
