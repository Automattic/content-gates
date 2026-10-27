/**
 * The worker's side of the edge contract: the formats in
 * tests/fixtures/edge-contract.json, as the worker parses and emits them.
 * Origin's side is tests/unit/test-edge-contract.php.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { encodeRecord, parseNameList } from '../lib/declared.ts';
import { parseMeterHeader } from '../lib/meter.ts';
import {
	parseSegmentation,
	serializeSegmentation,
} from '../lib/segmentation.ts';
import {
	DECLARED_HEADER,
	EDGE_VERSION_HEADER,
	KEY_HEADER,
	METER_HEADER,
	SEGMENTATION_HEADER,
	SEGMENT_COOKIE,
	variesOnSegmentation,
} from '../lib/worker.ts';

interface Contract {
	headers: Record< string, string >;
	declared: Array< { groups: string[]; header: string; record: string } >;
	meter: {
		group: string;
		post_id: number;
		limit: number;
		period: number;
		header: string;
	};
	cookie: { name: string; groups: Record< string, string >; value: string };
}

const here = dirname( fileURLToPath( import.meta.url ) );
const contract: Contract = JSON.parse(
	readFileSync(
		join( here, '..', '..', 'tests', 'fixtures', 'edge-contract.json' ),
		'utf8'
	)
);

test( 'header names match the fixture (the host lowercases them)', () => {
	const names = contract.headers;
	assert.equal( DECLARED_HEADER, names.declared.toLowerCase() );
	assert.equal( METER_HEADER, names.meter.toLowerCase() );
	assert.equal( KEY_HEADER, names.key.toLowerCase() );
	assert.equal( EDGE_VERSION_HEADER, names.edge_version.toLowerCase() );
	assert.equal( SEGMENTATION_HEADER, names.segmentation.toLowerCase() );
	assert.equal( variesOnSegmentation( names.segmentation ), true );
} );

test( 'the declared header parses to the sorted groups and encodes to the record', () => {
	for ( const { groups, header, record } of contract.declared ) {
		const parsed = parseNameList( header );
		assert.deepEqual( parsed, [ ...groups ].sort() );
		assert.equal( encodeRecord( parsed ), record );
	}
} );

test( 'the meter header parses to its spec', () => {
	const spec = parseMeterHeader( contract.meter.header );
	assert.ok( spec );
	assert.equal( spec.group, contract.meter.group );
	assert.equal( spec.postId, String( contract.meter.post_id ) );
	assert.equal( spec.limit, contract.meter.limit );
	assert.equal( spec.period, contract.meter.period );
} );

test( 'the segmentation cookie parses to the groups and serializes back byte for byte', () => {
	assert.equal( SEGMENT_COOKIE, contract.cookie.name );
	const groups = parseSegmentation( contract.cookie.value );
	for ( const [ name, value ] of Object.entries( contract.cookie.groups ) ) {
		assert.equal( groups.get( name ), value );
	}
	assert.equal(
		groups.names.length,
		Object.keys( contract.cookie.groups ).length
	);
	assert.equal( serializeSegmentation( groups ), contract.cookie.value );
} );
