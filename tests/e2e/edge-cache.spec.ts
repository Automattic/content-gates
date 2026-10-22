import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { request, type APIRequestContext } from '@playwright/test';
import { startEdgeSim } from '../../edge/sim/server.ts';
import { SEED, serialize, setCookies } from './helpers';

/**
 * The edge simulator in front of the tests site: declared-set narrowing,
 * crawler classification, and metering, including on cache hits.
 *
 * The simulator is a stand-in for WPVIP; docs/edge-worker.md lists where it
 * can differ. Everything here still has to be proven on a VIP application.
 */
test.describe( 'edge simulator', () => {
	let edge: { url: string; close: () => Promise< void > };

	test.beforeAll( async ( { baseURL } ) => {
		edge = await startEdgeSim( {
			origin: baseURL!,
			port: 0,
			secret: 'e2e-secret',
		} );
	} );

	test.afterAll( async () => {
		await edge.close();
	} );

	test.beforeEach( async () => {
		const ctx = await request.newContext( { baseURL: edge.url } );
		await ctx.post( '/__edge/reset' );
		await ctx.dispose();
	} );

	/**
	 * Request context through the edge with optional reader groups and UA.
	 *
	 * @param groups    Reader groups.
	 * @param userAgent User agent.
	 */
	async function via(
		groups: Record< string, string > = {},
		userAgent = 'Mozilla/5.0 (e2e)'
	): Promise< APIRequestContext > {
		const headers: Record< string, string > = { 'User-Agent': userAgent };
		if ( Object.keys( groups ).length > 0 ) {
			headers.Cookie = `vip-go-seg=${ serialize( groups ) }`;
		}
		return request.newContext( {
			baseURL: edge.url,
			maxRedirects: 0,
			extraHTTPHeaders: headers,
		} );
	}

	test( 'readers that differ only in undeclared segments share one copy', async () => {
		const anon = await via();
		const first = await anon.get( SEED.gatedArticle );
		expect( first.headers()[ 'x-edge-sim' ] ).toBe( 'MISS' );
		expect( await first.text() ).not.toContain( SEED.subscriberOnly );

		const second = await anon.get( SEED.gatedArticle );
		expect( second.headers()[ 'x-edge-sim' ] ).toBe( 'HIT' );
		await anon.dispose();

		// A newsletter reader is not in the declared set of this URL: same copy.
		const newsletter = await via( { newsletter: '1' } );
		const shared = await newsletter.get( SEED.gatedArticle );
		expect( shared.headers()[ 'x-edge-sim' ] ).toBe( 'HIT' );
		expect( shared.headers()[ 'x-edge-sim-key' ] ).toBe( '-' );
		await newsletter.dispose();

		// A subscriber gets a separate copy, keyed on subscriber only.
		const subscriber = await via( { subscriber: '1', newsletter: '1' } );
		const own = await subscriber.get( SEED.gatedArticle );
		expect( own.headers()[ 'x-edge-sim' ] ).toBe( 'MISS' );
		expect( own.headers()[ 'x-edge-sim-key' ] ).toBe(
			'vc-v1__subscriber_--_1'
		);
		expect( await own.text() ).toContain( SEED.subscriberOnly );
		const again = await subscriber.get( SEED.gatedArticle );
		expect( again.headers()[ 'x-edge-sim' ] ).toBe( 'HIT' );
		expect( await again.text() ).toContain( SEED.subscriberOnly );
		await subscriber.dispose();

		// The anonymous copy is still the anonymous copy.
		const anon2 = await via();
		const check = await anon2.get( SEED.gatedArticle );
		expect( check.headers()[ 'x-edge-sim' ] ).toBe( 'HIT' );
		expect( await check.text() ).not.toContain( SEED.subscriberOnly );
		await anon2.dispose();
	} );

	test( 'an ungated URL has one copy for everyone', async () => {
		const subscriber = await via( { subscriber: '1', donor: '1' } );
		const first = await subscriber.get( SEED.publicPost );
		expect( first.headers()[ 'x-edge-sim' ] ).toBe( 'MISS' );
		await subscriber.dispose();

		// First request had no record, so it was keyed on the full cookie
		// value; the record is now empty and every later reader narrows to ''.
		const anon = await via();
		const miss = await anon.get( SEED.publicPost );
		expect( miss.headers()[ 'x-edge-sim-key' ] ).toBe( '-' );
		await anon.dispose();

		const donor = await via( { donor: '1' } );
		const hit = await donor.get( SEED.publicPost );
		expect( hit.headers()[ 'x-edge-sim' ] ).toBe( 'HIT' );
		await donor.dispose();
	} );

	test( 'a search crawler gets the crawler copy with structured data and no cookie', async () => {
		const bot = await via( {}, 'Mozilla/5.0 (compatible; Googlebot/2.1)' );
		const response = await bot.get( SEED.gatedArticle );
		expect( response.headers()[ 'x-edge-sim-key' ] ).toBe(
			'vc-v1__crawlers-search_--_1'
		);
		const body = await response.text();
		expect( body ).toContain( SEED.subscriberOnly );
		expect( body ).toContain( 'id="content-gates-paywall"' );
		expect( setCookies( response.headersArray() ) ).toEqual( [] );
		await bot.dispose();

		// A browser claiming a crawler value in its cookie gains nothing.
		const forged = await via( { 'crawlers-search': '1' } );
		const forgedResponse = await forged.get( SEED.gatedArticle );
		expect( forgedResponse.headers()[ 'x-edge-sim-key' ] ).toBe( '-' );
		expect( await forgedResponse.text() ).not.toContain(
			SEED.subscriberOnly
		);
		await forged.dispose();
	} );

	test( 'the meter advances on hits and walls after the limit', async () => {
		// First visit: no cookie, gate shown, fresh record written with state open.
		const first = await request.newContext( {
			baseURL: edge.url,
			maxRedirects: 0,
		} );
		const visit0 = await first.get( SEED.meteredArticle );
		expect( await visit0.text() ).not.toContain( SEED.meteredBody );
		const cookies0 = setCookies( visit0.headersArray() );
		expect( cookies0.join( '\n' ) ).toContain( 'vip-go-meter=' );
		expect( cookies0.join( '\n' ) ).toContain( 'metered-articles_--_open' );

		// Later visits reuse the cookie jar. Limit 3: three distinct articles
		// are delivered (states open, last, wall after each), then the wall.
		const urls = [
			SEED.meteredArticle,
			SEED.meteredArticle2,
			SEED.meteredArticle3,
			SEED.meteredArticle,
		];
		const texts: string[] = [];
		const states: string[] = [];
		const hits: string[] = [];
		for ( const url of urls ) {
			const response = await first.get( url );
			texts.push( await response.text() );
			hits.push( response.headers()[ 'x-edge-sim' ] );
			const seg = setCookies( response.headersArray() ).find( ( c ) =>
				c.startsWith( 'vip-go-seg=' )
			);
			states.push(
				seg?.match( /metered-articles_--_(\w+)/ )?.[ 1 ] ?? 'none'
			);
		}
		expect( texts[ 0 ] ).toContain( SEED.meteredBody );
		expect( texts[ 1 ] ).toContain( 'METERED-BODY-2' );
		expect( texts[ 2 ] ).toContain( 'METERED-BODY-3' );
		expect( texts[ 3 ] ).not.toContain( SEED.meteredBody );
		expect( texts[ 3 ] ).toContain( SEED.meteredWall );
		expect( states ).toEqual( [ 'open', 'last', 'wall', 'wall' ] );

		// The first article was stored under the open key, so the second
		// reader with the same state hits it, and the meter still advances.
		const second = await request.newContext( {
			baseURL: edge.url,
			maxRedirects: 0,
		} );
		await second.get( SEED.meteredArticle );
		const hit = await second.get( SEED.meteredArticle );
		expect( hit.headers()[ 'x-edge-sim' ] ).toBe( 'HIT' );
		expect( setCookies( hit.headersArray() ).join( '\n' ) ).toContain(
			'metered-articles_--_open'
		);
		await second.dispose();
		await first.dispose();
	} );
} );
