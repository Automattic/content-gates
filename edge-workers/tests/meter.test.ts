import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
	MeterRecord,
	advanceMeter,
	findRecord,
	formatInt,
	parseMeterCookie,
	parseMeterHeader,
	serializeMeterCookie,
	stateFor,
} from '../lib/meter.ts';
import { DAY, NOW, nodeCrypto } from './helpers.ts';

const SECRET = 'test-secret';

/**
 * A meter spec for the tests.
 *
 * @param limit  Articles per period.
 * @param postId Post being viewed.
 * @return The parsed spec.
 */
function spec( limit: number, postId = '123' ) {
	const parsed = parseMeterHeader(
		'metered-articles; id=' +
			postId +
			'; limit=' +
			limit +
			'; period=' +
			30 * DAY
	);
	assert.ok( parsed );
	return parsed;
}

test( 'parseMeterHeader reads the plugin format', () => {
	const parsed = parseMeterHeader(
		'metered-articles; id=123; limit=5; period=2592000'
	);
	assert.ok( parsed );
	assert.equal( parsed.group, 'metered-articles' );
	assert.equal( parsed.postId, '123' );
	assert.equal( parsed.limit, 5 );
	assert.equal( parsed.period, 2592000 );
} );

test( 'parseMeterHeader rejects malformed headers', () => {
	assert.equal( parseMeterHeader( '' ), null );
	assert.equal(
		parseMeterHeader( 'bad name; id=1; limit=1; period=1' ),
		null
	);
	assert.equal(
		parseMeterHeader( 'm; limit=1; period=1' ),
		null,
		'id required'
	);
	assert.equal(
		parseMeterHeader( 'm; id=1; period=1' ),
		null,
		'limit required'
	);
	assert.equal(
		parseMeterHeader( 'm; id=1; limit=1' ),
		null,
		'period required'
	);
	assert.equal( parseMeterHeader( 'm; id=1; limit=1; period=0' ), null );
} );

test( 'meter cookie round-trips through sign and verify', () => {
	const record = new MeterRecord();
	record.group = 'metered-articles';
	record.start = NOW;
	record.count = 2;
	record.seen = [ 'a1b2c3d4', 'deadbeef' ];
	const cookie = serializeMeterCookie( [ record ], SECRET, nodeCrypto );
	assert.match(
		cookie,
		/^v1\.metered-articles:1792670400:2:a1b2c3d4_deadbeef\.[0-9a-f]{32}$/
	);
	const parsed = parseMeterCookie( cookie, SECRET, nodeCrypto );
	assert.equal( parsed.length, 1 );
	assert.equal( parsed[ 0 ].start, NOW );
	assert.equal( parsed[ 0 ].count, 2 );
	assert.deepEqual( parsed[ 0 ].seen, [ 'a1b2c3d4', 'deadbeef' ] );
} );

test( 'a forged or edited signature is treated as absent', () => {
	const record = new MeterRecord();
	record.group = 'metered-articles';
	record.start = NOW;
	record.count = 4;
	const cookie = serializeMeterCookie( [ record ], SECRET, nodeCrypto );
	const edited = cookie.replace( ':4:', ':0:' );
	assert.deepEqual( parseMeterCookie( edited, SECRET, nodeCrypto ), [] );
	assert.deepEqual(
		parseMeterCookie( cookie, 'other-secret', nodeCrypto ),
		[]
	);
	assert.deepEqual( parseMeterCookie( 'v1..', SECRET, nodeCrypto ), [] );
	assert.deepEqual( parseMeterCookie( 'garbage', SECRET, nodeCrypto ), [] );
	assert.deepEqual( parseMeterCookie( null, SECRET, nodeCrypto ), [] );
} );

test( 'first visit: fresh record, state open, nothing counted', () => {
	const records: MeterRecord[] = [];
	const state = advanceMeter( records, spec( 5 ), null, NOW, nodeCrypto );
	assert.equal( state, 'open' );
	assert.equal( records.length, 1 );
	assert.equal( records[ 0 ].count, 0 );
	assert.deepEqual( records[ 0 ].seen, [] );
	assert.equal( records[ 0 ].start, NOW );
} );

test( 'first visit with a limit of one lands on last', () => {
	assert.equal(
		advanceMeter( [], spec( 1 ), null, NOW, nodeCrypto ),
		'last'
	);
} );

test( 'advances open -> last -> wall and stays at wall', () => {
	const records: MeterRecord[] = [];
	assert.equal(
		advanceMeter( records, spec( 3, '1' ), null, NOW, nodeCrypto ),
		'open'
	);
	assert.equal(
		advanceMeter( records, spec( 3, '1' ), 'open', NOW, nodeCrypto ),
		'open'
	);
	assert.equal(
		advanceMeter( records, spec( 3, '2' ), 'open', NOW, nodeCrypto ),
		'last'
	);
	assert.equal(
		advanceMeter( records, spec( 3, '3' ), 'last', NOW, nodeCrypto ),
		'wall'
	);
	assert.equal(
		advanceMeter( records, spec( 3, '4' ), 'wall', NOW, nodeCrypto ),
		'wall'
	);
	assert.equal( records[ 0 ].count, 3 );
	assert.equal( records[ 0 ].seen.length, 3 );
} );

test( 'a repeat view of the same post counts once', () => {
	const records: MeterRecord[] = [];
	advanceMeter( records, spec( 5, '7' ), null, NOW, nodeCrypto );
	advanceMeter( records, spec( 5, '7' ), 'open', NOW, nodeCrypto );
	advanceMeter( records, spec( 5, '7' ), 'open', NOW + 60, nodeCrypto );
	assert.equal( records[ 0 ].count, 1 );
} );

test( 'the period elapsing resets count, seen list, and start', () => {
	const records: MeterRecord[] = [];
	const meter = spec( 2, '9' );
	advanceMeter( records, meter, null, NOW, nodeCrypto );
	advanceMeter( records, meter, 'open', NOW, nodeCrypto );
	assert.equal(
		advanceMeter( records, spec( 2, '10' ), 'last', NOW + DAY, nodeCrypto ),
		'wall'
	);
	const later = NOW + 30 * DAY;
	assert.equal(
		advanceMeter( records, spec( 2, '11' ), 'wall', later, nodeCrypto ),
		'open'
	);
	assert.equal( records[ 0 ].count, 0 );
	assert.deepEqual( records[ 0 ].seen, [] );
	assert.equal( records[ 0 ].start, later );
} );

test( 'a wall state with a missing cookie is not counted', () => {
	const records: MeterRecord[] = [];
	assert.equal(
		advanceMeter( records, spec( 5 ), 'wall', NOW, nodeCrypto ),
		'open'
	);
	assert.equal( records[ 0 ].count, 0 );
} );

test( 'records for other meters survive untouched', () => {
	const videos = new MeterRecord();
	videos.group = 'metered-videos';
	videos.start = NOW - DAY;
	videos.count = 1;
	videos.seen = [ '01234567' ];
	const records = [ videos ];
	advanceMeter( records, spec( 5 ), 'open', NOW, nodeCrypto );
	const cookie = serializeMeterCookie( records, SECRET, nodeCrypto );
	const parsed = parseMeterCookie( cookie, SECRET, nodeCrypto );
	assert.equal( parsed.length, 2 );
	const kept = findRecord( parsed, 'metered-videos' );
	assert.ok( kept );
	assert.equal( kept.count, 1 );
	assert.deepEqual( kept.seen, [ '01234567' ] );
} );

test( 'stateFor and formatInt', () => {
	const record = new MeterRecord();
	record.count = 0;
	assert.equal( stateFor( record, spec( 0 ) ), 'wall' );
	assert.equal( formatInt( 0 ), '0' );
	assert.equal( formatInt( 1792670400 ), '1792670400' );
	assert.equal( formatInt( 10 ), '10' );
} );
