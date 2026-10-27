/**
 * Reading the Group attributes the way the server does.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readMode, readSegments } from '../../src/attributes.ts';

test( 'readSegments keeps non-empty strings once, in stored order', () => {
	assert.deepEqual(
		readSegments( {
			contentGatesSegments: [ 'b', '', 'a', 3, 'b', null ],
		} ),
		[ 'b', 'a' ]
	);
	assert.deepEqual( readSegments( {} ), [] );
	assert.deepEqual( readSegments( { contentGatesSegments: 'b' } ), [] );
} );

test( 'readMode is not-in only when stored exactly so', () => {
	assert.equal( readMode( { contentGatesMode: 'not-in' } ), 'not-in' );
	assert.equal( readMode( { contentGatesMode: 'anything' } ), 'in' );
	assert.equal( readMode( {} ), 'in' );
} );
