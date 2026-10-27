/**
 * The Segments panel's pure row builder, under Node's test runner.
 *
 * `src/options.ts` imports only `@wordpress/i18n`, which is a real npm
 * package here, so no editor runtime is needed.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buildOptions, toggleKey } from '../../src/options.ts';
import type { Segment } from '../../src/segments.ts';

function segment( overrides: Partial< Segment > & { key: string } ): Segment {
	return {
		id: 1,
		name: overrides.key,
		active: true,
		type: 'boolean',
		crawler: false,
		...overrides,
	};
}

test( 'rows sort by type, then key, like the settings page', () => {
	const rows = buildOptions(
		[
			segment( { key: 'metered-articles', type: 'meter' } ),
			segment( { key: 'subscriber' } ),
			segment( { key: 'crawlers-search', crawler: true } ),
			segment( { key: 'donor' } ),
		],
		[]
	);
	assert.deepEqual(
		rows.map( ( row ) => row.key ),
		[ 'donor', 'subscriber', 'crawlers-search', 'metered-articles' ]
	);
	assert.equal( rows[ 2 ].crawler, true );
} );

test( 'an inactive segment appears only while assigned, labeled as such', () => {
	const segments = [
		segment( { key: 'old', name: 'Old', active: false } ),
		segment( { key: 'subscriber', name: 'Subscriber' } ),
	];
	assert.deepEqual(
		buildOptions( segments, [] ).map( ( row ) => row.key ),
		[ 'subscriber' ]
	);
	const assigned = buildOptions( segments, [ 'old' ] );
	assert.equal( assigned[ 0 ].key, 'old' );
	assert.equal( assigned[ 0 ].label, 'Old (inactive)' );
	assert.equal( assigned[ 0 ].checked, true );
	assert.equal( assigned[ 0 ].inactive, true );
} );

test( 'an assigned key the site does not know is shown last, as not found', () => {
	const rows = buildOptions(
		[ segment( { key: 'subscriber' } ) ],
		[ 'ghost', 'subscriber' ]
	);
	assert.deepEqual(
		rows.map( ( row ) => [ row.key, row.label, row.checked ] ),
		[
			[ 'subscriber', 'subscriber', true ],
			[ 'ghost', 'ghost (not found)', true ],
		]
	);
} );

test( 'toggleKey adds at the end and removes in place', () => {
	assert.deepEqual( toggleKey( [ 'a', 'b' ], 'c', true ), [ 'a', 'b', 'c' ] );
	assert.deepEqual( toggleKey( [ 'a', 'b' ], 'a', true ), [ 'b', 'a' ] );
	assert.deepEqual( toggleKey( [ 'a', 'b' ], 'a', false ), [ 'b' ] );
	assert.deepEqual( toggleKey( [], 'a', false ), [] );
} );
