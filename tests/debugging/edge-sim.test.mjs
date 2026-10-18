/**
 * Self-checks for the edge simulator's pure functions.
 *
 * Run with `node --test 'tests/debugging/*.test.mjs'`. These cover the worked example in
 * spec/content-gating-api.md (readers A-E) and the legacy full-cookie mode.
 */

import assert from 'node:assert/strict';
/* eslint-disable-next-line vitest/no-import-node-test -- This suite runs under node --test; vitest is not a dependency. */
import { describe, it } from 'node:test';

import {
	computeVariantKey,
	consultedFromResponse,
	createStore,
	formatKey,
	matches,
	parseCacheControl,
	parseSegmentCookie,
} from './edge-sim.mjs';

const readers = {
	A: parseSegmentCookie( 'vc-v1__subscriber_--_1' ),
	B: parseSegmentCookie( 'vc-v1__newsletter_--_1---__subscriber_--_1' ),
	C: parseSegmentCookie( 'vc-v1__donor_--_1---__newsletter_--_1' ),
	D: parseSegmentCookie( 'vc-v1__newsletter_--_1' ),
	E: parseSegmentCookie( 'vc-v1__donor_--_1' ),
};

describe( 'parseSegmentCookie', () => {
	it( 'parses sorted groups into a membership map', () => {
		assert.deepEqual( readers.B, { newsletter: '1', subscriber: '1' } );
	} );

	it( 'returns an empty map for a missing or foreign cookie', () => {
		assert.deepEqual( parseSegmentCookie( undefined ), {} );
		assert.deepEqual( parseSegmentCookie( '' ), {} );
		assert.deepEqual( parseSegmentCookie( 'vc-v2__subscriber_--_1' ), {} );
	} );

	it( 'drops pairs with empty or invalid names and values', () => {
		assert.deepEqual(
			parseSegmentCookie(
				'vc-v1__subscriber_--_---__ok_--_1---__bad name_--_1---__x_--_a b'
			),
			{ ok: '1' }
		);
	} );

	it( 'accepts a URL-encoded value', () => {
		assert.deepEqual(
			parseSegmentCookie(
				encodeURIComponent( 'vc-v1__subscriber_--_1' )
			),
			{ subscriber: '1' }
		);
	} );
} );

describe( 'computeVariantKey (spec worked example)', () => {
	it( 'A: {subscriber} consulting [subscriber] -> {subscriber=1}', () => {
		assert.deepEqual( computeVariantKey( [ 'subscriber' ], readers.A ), {
			subscriber: '1',
		} );
	} );

	it( 'B: {subscriber,newsletter} consulting [subscriber] -> same as A', () => {
		assert.deepEqual(
			computeVariantKey( [ 'subscriber' ], readers.B ),
			computeVariantKey( [ 'subscriber' ], readers.A )
		);
	} );

	it( 'C: {newsletter,donor} consulting [subscriber,newsletter] -> {subscriber=none,newsletter=1}', () => {
		assert.deepEqual(
			computeVariantKey( [ 'subscriber', 'newsletter' ], readers.C ),
			{ newsletter: '1', subscriber: 'none' }
		);
	} );

	it( 'D: {newsletter} -> same key as C', () => {
		assert.deepEqual(
			computeVariantKey( [ 'subscriber', 'newsletter' ], readers.D ),
			computeVariantKey( [ 'subscriber', 'newsletter' ], readers.C )
		);
	} );

	it( 'E: consulting nothing -> {}', () => {
		assert.deepEqual( computeVariantKey( [], readers.E ), {} );
		assert.equal( formatKey( computeVariantKey( [], readers.E ) ), '{}' );
	} );

	it( 'formats keys sorted by group', () => {
		assert.equal(
			formatKey(
				computeVariantKey( [ 'subscriber', 'newsletter' ], readers.C )
			),
			'newsletter=1, subscriber=none'
		);
	} );
} );

describe( 'matches', () => {
	const entryA = {
		consulted: [ 'subscriber' ],
		variantKey: computeVariantKey( [ 'subscriber' ], readers.A ),
	};
	const entryC = {
		consulted: [ 'newsletter', 'subscriber' ],
		variantKey: computeVariantKey(
			[ 'newsletter', 'subscriber' ],
			readers.C
		),
	};
	const entryE = { consulted: [], variantKey: {} };

	it( 'B shares the copy stored for A', () => {
		assert.equal( matches( entryA, readers.B ), true );
	} );

	it( 'C and D do not get the subscriber copy', () => {
		assert.equal( matches( entryA, readers.C ), false );
		assert.equal( matches( entryA, readers.D ), false );
	} );

	it( 'D shares the copy stored for C; A and B do not', () => {
		assert.equal( matches( entryC, readers.D ), true );
		assert.equal( matches( entryC, readers.A ), false );
		assert.equal( matches( entryC, readers.B ), false );
	} );

	it( 'an entry that consulted nothing matches everyone', () => {
		for ( const membership of Object.values( readers ) ) {
			assert.equal( matches( entryE, membership ), true );
		}
		assert.equal( matches( entryE, {} ), true );
	} );

	it( 'legacy mode keys on everything in the cookie', () => {
		const legacy = {
			legacy: true,
			consulted: Object.keys( readers.C ).sort(),
			variantKey: computeVariantKey(
				Object.keys( readers.C ),
				readers.C
			),
		};
		assert.deepEqual( legacy.variantKey, { donor: '1', newsletter: '1' } );
		assert.equal( matches( legacy, readers.C ), true );
		// A subset of the cookie must not match.
		assert.equal( matches( legacy, readers.D ), false );
		// A superset of the cookie must not match either.
		assert.equal(
			matches( legacy, { ...readers.C, subscriber: '1' } ),
			false
		);
	} );
} );

describe( 'consultedFromResponse', () => {
	it( 'reads and sorts X-Content-Gates-Vary', () => {
		const headers = new Headers( {
			'x-content-gates-vary': 'subscriber, newsletter ,subscriber',
		} );
		assert.deepEqual( consultedFromResponse( headers, readers.B ), {
			consulted: [ 'newsletter', 'subscriber' ],
			legacy: false,
		} );
	} );

	it( 'falls back to the whole cookie on a legacy Vary', () => {
		const headers = new Headers( {
			vary: 'Accept-Encoding, X-VIP-Go-Segmentation',
		} );
		assert.deepEqual( consultedFromResponse( headers, readers.C ), {
			consulted: [ 'donor', 'newsletter' ],
			legacy: true,
		} );
	} );

	it( 'lets X-Content-Gates-Vary win over a legacy Vary', () => {
		const headers = new Headers( {
			vary: 'X-VIP-Go-Segmentation',
			'x-content-gates-vary': 'subscriber',
		} );
		assert.deepEqual( consultedFromResponse( headers, readers.C ), {
			consulted: [ 'subscriber' ],
			legacy: false,
		} );
	} );

	it( 'consults nothing when neither header is present', () => {
		assert.deepEqual( consultedFromResponse( new Headers(), readers.C ), {
			consulted: [],
			legacy: false,
		} );
	} );
} );

describe( 'parseCacheControl', () => {
	it( 'treats no-store, no-cache, private, and max-age=0 as uncacheable', () => {
		for ( const value of [
			'no-store',
			'no-cache',
			'private',
			'max-age=0',
			'public, max-age=0',
		] ) {
			assert.equal( parseCacheControl( value ).cacheable, false, value );
		}
	} );

	it( 'reads max-age', () => {
		assert.deepEqual( parseCacheControl( 'public, max-age=60' ), {
			cacheable: true,
			maxAge: 60,
		} );
		assert.deepEqual( parseCacheControl( undefined ), {
			cacheable: true,
			maxAge: null,
		} );
	} );
} );

describe( 'createStore', () => {
	/**
	 * Build a store entry for a reader and consulted set.
	 *
	 * @param {string[]}              consulted  Consulted set.
	 * @param {Object<string,string>} membership Reader membership.
	 * @param {string}                body       Body marker.
	 * @return {Object} Entry.
	 */
	function entry( consulted, membership, body ) {
		return {
			consulted: [ ...consulted ].sort(),
			variantKey: computeVariantKey( consulted, membership ),
			status: 200,
			headers: {},
			body: Buffer.from( body ),
		};
	}

	it( 'serves the worked example with two copies of /article-1', () => {
		const store = createStore();
		assert.equal( store.lookup( '/article-1', readers.A ), null );
		store.put( '/article-1', entry( [ 'subscriber' ], readers.A, 'full' ) );
		assert.equal(
			store.lookup( '/article-1', readers.B ).body.toString(),
			'full'
		);
		assert.equal( store.lookup( '/article-1', readers.C ), null );
		store.put(
			'/article-1',
			entry( [ 'subscriber', 'newsletter' ], readers.C, 'teaser' )
		);
		assert.equal(
			store.lookup( '/article-1', readers.D ).body.toString(),
			'teaser'
		);
		assert.equal(
			store.lookup( '/article-1', readers.A ).body.toString(),
			'full'
		);
		assert.deepEqual( Object.keys( store.stats().urls ), [ '/article-1' ] );
		assert.equal( store.stats().urls[ '/article-1' ].length, 2 );
	} );

	it( 'keys an empty consulted set as one copy for everyone', () => {
		const store = createStore();
		store.put( '/about', entry( [], readers.E, 'about' ) );
		assert.equal( store.lookup( '/about', {} ).body.toString(), 'about' );
		assert.equal(
			store.lookup( '/about', readers.A ).body.toString(),
			'about'
		);
		assert.equal( store.stats().urls[ '/about' ][ 0 ].hitCount, 2 );
	} );

	it( 'replaces an entry with the same key instead of duplicating it', () => {
		const store = createStore();
		store.put( '/article-1', entry( [ 'subscriber' ], readers.A, 'v1' ) );
		store.put( '/article-1', entry( [ 'subscriber' ], readers.A, 'v2' ) );
		assert.equal( store.stats().urls[ '/article-1' ].length, 1 );
		assert.equal(
			store.lookup( '/article-1', readers.A ).body.toString(),
			'v2'
		);
	} );

	it( 'expires entries after maxAge', () => {
		let clock = 1_000_000;
		const store = createStore( { now: () => clock } );
		store.put( '/x', { ...entry( [], {}, 'x' ), maxAge: 10 } );
		clock += 9_000;
		assert.notEqual( store.lookup( '/x', {} ), null );
		clock += 2_000;
		assert.equal( store.lookup( '/x', {} ), null );
		assert.deepEqual( store.stats().urls, {} );
	} );

	it( 'purges one URL or everything and resets counters', () => {
		const store = createStore();
		store.put( '/a', entry( [], {}, 'a' ) );
		store.put( '/b', entry( [], {}, 'b' ) );
		store.put( '/b', entry( [ 'subscriber' ], readers.A, 'b2' ) );
		assert.equal( store.purge( '/b' ), 2 );
		assert.equal( store.purge( '/missing' ), 0 );
		assert.equal( store.purge(), 1 );
		store.counters.hits = 3;
		store.reset();
		assert.deepEqual( store.stats(), {
			hits: 0,
			misses: 0,
			passes: 0,
			urls: {},
		} );
	} );
} );
