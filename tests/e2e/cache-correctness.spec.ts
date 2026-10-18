/**
 * Cache-correctness acceptance tests against the local edge simulator.
 *
 * The simulator (`tests/debugging/edge-sim.mjs`) is a test double for the
 * parts of the VIP page cache that matter here: it keys stored copies on
 * the `X-Content-Gates-Vary` set each response reports plus the reader's
 * values for only those segments. See its README for the variances from
 * the real platform; nothing here is live VIP evidence.
 *
 * Criteria covered (spec/content-gating-api.md "Verification" table):
 * - "Readers who differ only in unconsulted segments share a copy":
 *   readers A {S1} and B {S1,S2} share one copy; C {S2,S3} and D {S2}
 *   share another (the spec's worked example).
 * - "Nested gates key on their own set": the non-subscriber and the
 *   subscriber renders of one URL report different sets, are stored under
 *   different keys, and a subscriber is never served the other copy.
 * - "Empty set means one copy": a page with no gates reports an empty set
 *   and has one stored copy for every reader.
 * - editor-ui.md Delivery bullet 4: a cached entitled response is never
 *   served to a non-entitled reader, cold and warm, alternating readers.
 * - The anonymous copy is storable: a plain GET carries no Set-Cookie.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { request, type APIRequestContext } from '@playwright/test';
import { spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import {
	createPost,
	createSegment,
	group,
	paragraph,
	pathOf,
	reader,
	readerGet,
	tokens,
	unique,
} from './helpers';

interface SimEntry {
	consulted: string[];
	variantKey: Record< string, string >;
	legacy: boolean;
	hitCount: number;
}

interface SimStats {
	hits: number;
	misses: number;
	passes: number;
	urls: Record< string, SimEntry[] >;
}

let sim: ChildProcess | undefined;
let simURL = '';
let simOutput = '';

/**
 * Pick a free TCP port on the loopback interface.
 */
function freePort(): Promise< number > {
	return new Promise( ( resolve, reject ) => {
		const server = createServer();
		server.unref();
		server.on( 'error', reject );
		server.listen( 0, '127.0.0.1', () => {
			const address = server.address();
			const port =
				address && typeof address === 'object' ? address.port : 0;
			server.close( () => resolve( port ) );
		} );
	} );
}

/**
 * Start the simulator in front of the tests site and wait until it listens.
 *
 * @param origin Origin base URL.
 */
async function startSim( origin: string ): Promise< void > {
	const port = await freePort();
	simURL = `http://127.0.0.1:${ port }`;
	sim = spawn(
		process.execPath,
		[
			'tests/debugging/edge-sim.mjs',
			'--origin',
			origin,
			'--port',
			String( port ),
		],
		{ cwd: process.cwd(), stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	const child = sim;
	await new Promise< void >( ( resolve, reject ) => {
		const timer = setTimeout(
			() =>
				reject(
					new Error( `edge-sim did not start:\n${ simOutput }` )
				),
			15_000
		);
		const onData = ( chunk: Buffer ): void => {
			simOutput += chunk.toString();
			if ( simOutput.includes( 'listening' ) ) {
				clearTimeout( timer );
				resolve();
			}
		};
		child.stdout?.on( 'data', onData );
		child.stderr?.on( 'data', onData );
		child.on( 'exit', ( code ) => {
			clearTimeout( timer );
			reject(
				new Error( `edge-sim exited with ${ code }:\n${ simOutput }` )
			);
		} );
	} );
}

/**
 * Read the simulator's stats endpoint.
 */
async function stats(): Promise< SimStats > {
	const context = await request.newContext( { baseURL: simURL } );
	const response = await context.get( '/__edge-sim/stats' );
	const data = ( await response.json() ) as SimStats;
	await context.dispose();
	return data;
}

/**
 * GET a path through the simulator as a reader and summarize the result.
 *
 * @param context Reader context created with `reader( simURL, ... )`.
 * @param path    URL path on the origin.
 */
async function fetchViaSim(
	context: APIRequestContext,
	path: string
): Promise< {
	status: number;
	result: string;
	key: string;
	stored: string | undefined;
	setCookie: string | undefined;
	body: string;
} > {
	const response = await readerGet( context, path );
	const headers = response.headers();
	return {
		status: response.status(),
		result: headers[ 'x-edge-sim' ],
		key: headers[ 'x-edge-sim-key' ],
		stored: headers[ 'x-edge-sim-stored' ],
		setCookie: headers[ 'set-cookie' ],
		body: await response.text(),
	};
}

/**
 * Parse `a=1, b=none` into a map (same format as the plugin's key header).
 *
 * @param value Header value.
 */
function keyMap( value: string | undefined ): Record< string, string > {
	const out: Record< string, string > = {};
	for ( const token of tokens( value ) ) {
		const [ key, val ] = token.split( '=' );
		out[ key ] = val;
	}
	return out;
}

test.describe( 'cache correctness (edge simulator)', () => {
	test.beforeAll( async () => {
		const origin =
			( test.info().project.use.baseURL as string | undefined ) ??
			process.env.WP_BASE_URL ??
			'http://localhost:8889';
		await startSim( origin );
	} );

	test.afterAll( async () => {
		sim?.kill();
	} );

	test.beforeEach( async () => {
		const context = await request.newContext( { baseURL: simURL } );
		const response = await context.post( '/__edge-sim/reset' );
		expect( response.ok() ).toBe( true );
		await context.dispose();
	} );

	test( 'the anonymous copy is storable: MISS then HIT, no Set-Cookie', async ( {
		request: adminRequest,
		requestUtils,
	} ) => {
		const segment = await createSegment( adminRequest );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) ) +
				group( paragraph( SECRET ), segment.id )
		);
		const path = pathOf( post.link );

		const anon = await reader( simURL );
		const first = await fetchViaSim( anon, path );
		expect( first.status ).toBe( 200 );
		expect( first.result ).toBe( 'MISS' );
		expect( first.setCookie ).toBeUndefined();
		expect( first.stored ).toBe( 'yes' );
		expect( first.body ).not.toContain( SECRET );

		const second = await fetchViaSim( anon, path );
		expect( second.result ).toBe( 'HIT' );
		expect( second.key ).toBe( first.key );
		expect( second.body ).not.toContain( SECRET );
		await anon.dispose();
	} );

	test( 'worked example: readers who differ only in unconsulted segments share a copy', async ( {
		request: adminRequest,
		requestUtils,
	} ) => {
		const s1 = await createSegment( adminRequest, unique( 'S1' ) );
		const s2 = await createSegment( adminRequest, unique( 'S2' ) );
		const s3 = await createSegment( adminRequest, unique( 'S3' ) );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) ) +
				group( paragraph( SECRET ), s1.id )
		);
		const path = pathOf( post.link );

		const a = await reader( simURL, { [ s1.key ]: '1' } );
		const b = await reader( simURL, { [ s1.key ]: '1', [ s2.key ]: '1' } );
		const c = await reader( simURL, { [ s2.key ]: '1', [ s3.key ]: '1' } );
		const d = await reader( simURL, { [ s2.key ]: '1' } );

		const asA = await fetchViaSim( a, path );
		expect( asA.result ).toBe( 'MISS' );
		expect( asA.stored ).toBe( 'yes' );
		expect( keyMap( asA.key )[ s1.key ] ).toBe( '1' );
		expect( asA.body ).toContain( SECRET );

		const asB = await fetchViaSim( b, path );
		expect( asB.result ).toBe( 'HIT' );
		expect( asB.key ).toBe( asA.key );
		expect( asB.body ).toContain( SECRET );

		const asC = await fetchViaSim( c, path );
		expect( asC.result ).toBe( 'MISS' );
		expect( keyMap( asC.key ) ).toEqual( {
			crawler: 'none',
			[ s1.key ]: 'none',
		} );
		expect( asC.body ).not.toContain( SECRET );

		const asD = await fetchViaSim( d, path );
		expect( asD.result ).toBe( 'HIT' );
		expect( asD.key ).toBe( asC.key );
		expect( asD.body ).not.toContain( SECRET );

		const snapshot = await stats();
		const entries = snapshot.urls[ path ];
		expect( entries ).toHaveLength( 2 );
		// Both copies consulted S1 and nothing else from the registry. (The
		// `crawler` key is filtered: Crawler_Markup reads it on every singular
		// gated post, see the leak-surfaces finding.)
		expect(
			entries.map( ( entry ) =>
				entry.consulted.filter( ( key ) => key !== 'crawler' )
			)
		).toEqual( [ [ s1.key ], [ s1.key ] ] );
		expect( entries[ 1 ].consulted ).toContain( 'crawler' );
		expect( snapshot.hits ).toBe( 2 );
		expect( snapshot.misses ).toBe( 2 );
		for ( const context of [ a, b, c, d ] ) {
			await context.dispose();
		}
	} );

	test( 'a non-member is never served the member copy, cold or warm', async ( {
		request: adminRequest,
		requestUtils,
	} ) => {
		const segment = await createSegment( adminRequest );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) ) +
				group( paragraph( SECRET ), segment.id )
		);
		const path = pathOf( post.link );
		const member = await reader( simURL, { [ segment.key ]: '1' } );
		const anon = await reader( simURL );
		const other = await reader( simURL, { newsletter: '1' } );

		const cold = await fetchViaSim( member, path );
		expect( cold.result ).toBe( 'MISS' );
		expect( cold.body ).toContain( SECRET );

		const anonCold = await fetchViaSim( anon, path );
		expect( anonCold.result ).toBe( 'MISS' );
		expect( anonCold.key ).not.toBe( cold.key );
		expect( anonCold.body ).not.toContain( SECRET );

		// Alternate readers on a warm cache; every answer stays correct.
		for ( let round = 0; round < 3; round += 1 ) {
			const warmMember = await fetchViaSim( member, path );
			expect( warmMember.result, `member round ${ round }` ).toBe(
				'HIT'
			);
			expect( warmMember.body ).toContain( SECRET );

			const warmAnon = await fetchViaSim( anon, path );
			expect( warmAnon.result, `anon round ${ round }` ).toBe( 'HIT' );
			expect( warmAnon.body ).not.toContain( SECRET );

			// A reader in an unrelated segment shares the non-member copy.
			const warmOther = await fetchViaSim( other, path );
			expect( warmOther.result, `other round ${ round }` ).toBe( 'HIT' );
			expect( warmOther.key ).toBe( anonCold.key );
			expect( warmOther.body ).not.toContain( SECRET );
		}

		const snapshot = await stats();
		expect( snapshot.urls[ path ] ).toHaveLength( 2 );
		expect( snapshot.misses ).toBe( 2 );
		for ( const context of [ member, anon, other ] ) {
			await context.dispose();
		}
	} );

	test( 'an ungated URL has one copy for every reader', async ( {
		request: adminRequest,
		requestUtils,
	} ) => {
		const segment = await createSegment( adminRequest );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) + group( paragraph( 'ungated group' ) )
		);
		const path = pathOf( post.link );
		const anon = await reader( simURL );
		const member = await reader( simURL, { [ segment.key ]: '1' } );
		const other = await reader( simURL, {
			[ segment.key ]: '1',
			newsletter: '1',
		} );

		const first = await fetchViaSim( anon, path );
		expect( first.result ).toBe( 'MISS' );
		expect( first.stored ).toBe( 'yes' );
		expect( first.key ).toBe( '{}' );
		expect( first.body ).toContain( PUBLIC );

		for ( const context of [ member, other, anon ] ) {
			const warm = await fetchViaSim( context, path );
			expect( warm.result ).toBe( 'HIT' );
			expect( warm.key ).toBe( '{}' );
			expect( warm.body ).toContain( PUBLIC );
		}

		const snapshot = await stats();
		expect( snapshot.urls[ path ] ).toHaveLength( 1 );
		expect( snapshot.urls[ path ][ 0 ].consulted ).toEqual( [] );
		expect( snapshot.urls[ path ][ 0 ].legacy ).toBe( false );
		expect( snapshot.misses ).toBe( 1 );
		expect( snapshot.hits ).toBe( 3 );
		for ( const context of [ anon, member, other ] ) {
			await context.dispose();
		}
	} );

	test( 'nested gates produce a different consulted set per reader and never cross', async ( {
		request: adminRequest,
		requestUtils,
	} ) => {
		const outer = await createSegment( adminRequest, unique( 'Outer' ) );
		const inner = await createSegment( adminRequest, unique( 'Inner' ) );
		const OUTER = unique( 'OUTER-BYTES' );
		const INNER = unique( 'INNER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) ) +
				group(
					paragraph( OUTER ) + group( paragraph( INNER ), inner.id ),
					outer.id
				)
		);
		const path = pathOf( post.link );

		const none = await reader( simURL );
		const outerOnly = await reader( simURL, { [ outer.key ]: '1' } );
		const both = await reader( simURL, {
			[ outer.key ]: '1',
			[ inner.key ]: '1',
		} );
		const innerOnly = await reader( simURL, { [ inner.key ]: '1' } );

		const asNone = await fetchViaSim( none, path );
		expect( asNone.result ).toBe( 'MISS' );
		expect( asNone.body ).not.toContain( OUTER );

		const asOuter = await fetchViaSim( outerOnly, path );
		expect( asOuter.result ).toBe( 'MISS' );
		expect( asOuter.body ).toContain( OUTER );
		expect( asOuter.body ).not.toContain( INNER );

		const asBoth = await fetchViaSim( both, path );
		expect( asBoth.result ).toBe( 'MISS' );
		expect( asBoth.body ).toContain( INNER );

		// Inner-only matches the non-subscriber copy: the outer gate denied,
		// so the inner segment was never consulted for that copy.
		const asInnerOnly = await fetchViaSim( innerOnly, path );
		expect( asInnerOnly.result ).toBe( 'HIT' );
		expect( asInnerOnly.key ).toBe( asNone.key );
		expect( asInnerOnly.body ).not.toContain( OUTER );

		// Warm: each subscriber keeps getting their own copy.
		const warmOuter = await fetchViaSim( outerOnly, path );
		expect( warmOuter.result ).toBe( 'HIT' );
		expect( warmOuter.body ).toContain( OUTER );
		expect( warmOuter.body ).not.toContain( INNER );
		const warmBoth = await fetchViaSim( both, path );
		expect( warmBoth.result ).toBe( 'HIT' );
		expect( warmBoth.body ).toContain( INNER );

		const snapshot = await stats();
		const sets = snapshot.urls[ path ].map( ( entry ) =>
			entry.consulted.filter( ( key ) => key !== 'crawler' )
		);
		// The denied-outer copy never consulted the inner segment; the two
		// subscriber copies consulted both and differ only in their values.
		expect( sets ).toEqual( [
			[ outer.key ],
			[ outer.key, inner.key ].sort(),
			[ outer.key, inner.key ].sort(),
		] );
		expect( snapshot.urls[ path ][ 0 ].consulted ).toContain( 'crawler' );
		expect( snapshot.urls[ path ][ 1 ].variantKey[ inner.key ] ).toBe(
			'none'
		);
		expect( snapshot.urls[ path ][ 2 ].variantKey[ inner.key ] ).toBe(
			'1'
		);
		expect( snapshot.misses ).toBe( 3 );
		expect( snapshot.hits ).toBe( 3 );
		for ( const context of [ none, outerOnly, both, innerOnly ] ) {
			await context.dispose();
		}
	} );
} );
