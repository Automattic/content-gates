import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	anonymous,
	reader,
	HEADERS,
	SEED,
	segFromSetCookie,
	setCookies,
} from './helpers';

/**
 * Origin behavior against the seeded content: zero gated bytes for readers
 * outside the segment, full content inside it, on page, feed, and REST.
 * Membership is set through the local dev provider, which stands in for a
 * real entitlement provider.
 */
test.describe( 'gating at origin', () => {
	test( 'anonymous page render carries no gated bytes and declares the set', async ( {
		baseURL,
	} ) => {
		const ctx = await anonymous( baseURL! );
		const response = await ctx.get( SEED.gatedArticle );
		expect( response.status() ).toBe( 200 );
		const body = await response.text();

		expect( body ).not.toContain( SEED.subscriberOnly );
		expect( body ).toContain( SEED.subscribePrompt );
		expect( body ).not.toContain( 'content-gates-gated' );
		expect( body ).not.toContain( 'content-gates-paywall' );

		const headers = response.headers();
		expect( headers[ HEADERS.declared ] ).toBe(
			'crawlers-ai, crawlers-search, subscriber'
		);
		expect( headers[ HEADERS.vary ] ).toContain( 'X-VIP-Go-Segmentation' );
		await ctx.dispose();
	} );

	test( 'an ungated URL declares nothing', async ( { baseURL } ) => {
		const ctx = await anonymous( baseURL! );
		const response = await ctx.get( SEED.publicPost );
		expect( response.headers()[ HEADERS.declared ] ).toBe( '' );
		await ctx.dispose();
	} );

	test( 'a subscriber cookie unlocks the Group and hides the prompt', async ( {
		baseURL,
	} ) => {
		const ctx = await reader( baseURL!, { subscriber: '1' } );
		const body = await ( await ctx.get( SEED.gatedArticle ) ).text();
		expect( body ).toContain( SEED.subscriberOnly );
		expect( body ).not.toContain( SEED.subscribePrompt );
		await ctx.dispose();
	} );

	test( 'the REST membership route writes the cookie before the JSON', async ( {
		baseURL,
	} ) => {
		const ctx = await anonymous( baseURL! );
		const join = await ctx.post(
			'/wp-json/content-gates-dev/v1/membership',
			{
				data: { segment: 'subscriber', action: 'assign' },
			}
		);
		expect( join.status() ).toBe( 200 );
		expect( ( await join.json() ).result ).toBe( 'ok' );
		const cookie = segFromSetCookie( setCookies( join.headersArray() ) );
		expect( cookie ).toBe( 'vc-v1__subscriber_--_1' );

		const page = await ctx.get( SEED.gatedArticle );
		expect( await page.text() ).toContain( SEED.subscriberOnly );

		const leave = await ctx.post(
			'/wp-json/content-gates-dev/v1/membership',
			{ data: { segment: 'subscriber', action: 'remove' } }
		);
		expect( leave.status() ).toBe( 200 );
		await ctx.dispose();
	} );

	test( 'an unknown segment is refused', async ( { baseURL } ) => {
		const ctx = await anonymous( baseURL! );
		const join = await ctx.post(
			'/wp-json/content-gates-dev/v1/membership',
			{
				data: { segment: 'ghost' },
			}
		);
		expect( join.status() ).toBe( 400 );
		expect( ( await join.json() ).result ).toBe(
			'content_gates_invalid_segment'
		);
		await ctx.dispose();
	} );

	test( 'feeds, REST, embeds, and search withhold the Group for anonymous readers', async ( {
		baseURL,
	} ) => {
		const ctx = await anonymous( baseURL! );

		const feed = await ctx.get( '/feed/' );
		expect( feed.status() ).toBe( 200 );
		expect( await feed.text() ).not.toContain( SEED.subscriberOnly );
		expect( feed.headers()[ HEADERS.declared ] ).toContain( 'subscriber' );

		const rest = await ctx.get( '/wp-json/wp/v2/posts?slug=gated-article' );
		expect( rest.status() ).toBe( 200 );
		expect( await rest.text() ).not.toContain( SEED.subscriberOnly );
		expect( rest.headers()[ HEADERS.declared ] ).toBe(
			'crawlers-ai, crawlers-search, subscriber'
		);
		expect( rest.headers()[ HEADERS.vary ] ).toContain(
			'X-VIP-Go-Segmentation'
		);

		const embed = await ctx.get( '/gated-article/embed/' );
		expect( await embed.text() ).not.toContain( SEED.subscriberOnly );

		// Search echoes the query in the title; assert on the gated sentence.
		const search = await ctx.get( '/?s=SUBSCRIBER-ONLY' );
		expect( await search.text() ).not.toContain( 'the full article body' );
		await ctx.dispose();
	} );

	test( 'feeds and REST include the Group for subscribers', async ( {
		baseURL,
	} ) => {
		const ctx = await reader( baseURL!, { subscriber: '1' } );
		expect( await ( await ctx.get( '/feed/' ) ).text() ).toContain(
			SEED.subscriberOnly
		);
		expect(
			await (
				await ctx.get( '/wp-json/wp/v2/posts?slug=gated-article' )
			).text()
		).toContain( SEED.subscriberOnly );
		await ctx.dispose();
	} );

	test( 'nested Groups enforce their own segment', async ( { baseURL } ) => {
		const sub = await reader( baseURL!, { subscriber: '1' } );
		const subBody = await ( await sub.get( SEED.nestedGates ) ).text();
		expect( subBody ).toContain( SEED.subscriberLayer );
		expect( subBody ).not.toContain( SEED.donorLayer );
		await sub.dispose();

		const both = await reader( baseURL!, { subscriber: '1', donor: '1' } );
		const bothBody = await ( await both.get( SEED.nestedGates ) ).text();
		expect( bothBody ).toContain( SEED.donorLayer );
		await both.dispose();

		const donor = await reader( baseURL!, { donor: '1' } );
		const donorBody = await ( await donor.get( SEED.nestedGates ) ).text();
		expect( donorBody ).not.toContain( SEED.subscriberLayer );
		expect( donorBody ).not.toContain( SEED.donorLayer );
		await donor.dispose();
	} );

	test( 'several segments on one Group are OR-ed', async ( { baseURL } ) => {
		const newsletter = await reader( baseURL!, { newsletter: '1' } );
		expect(
			await ( await newsletter.get( SEED.newsletterPerk ) ).text()
		).toContain( SEED.perkBody );
		await newsletter.dispose();

		const subscriber = await reader( baseURL!, { subscriber: '1' } );
		expect(
			await ( await subscriber.get( SEED.newsletterPerk ) ).text()
		).not.toContain( SEED.perkBody );
		await subscriber.dispose();
	} );

	test( 'a crawler value forwarded by the edge yields the paywall variant', async ( {
		baseURL,
	} ) => {
		// At origin the crawler value arrives in the cookie header the edge
		// rewrites; the edge simulator spec covers the user-agent step.
		const ctx = await reader( baseURL!, { 'crawlers-search': '1' } );
		const body = await ( await ctx.get( SEED.gatedArticle ) ).text();
		expect( body ).toContain( SEED.subscriberOnly );
		expect( body ).toContain( 'content-gates-gated-1' );
		expect( body ).toContain( 'id="content-gates-paywall"' );
		expect( body ).toContain( '"isAccessibleForFree":false' );
		expect( body ).toContain( '"cssSelector":".content-gates-gated-1"' );
		// The crawler is not a subscriber, so the not-in prompt still renders,
		// as free content: no marker, no second hasPart.
		expect( body ).toContain( SEED.subscribePrompt );
		expect( body ).not.toContain( 'content-gates-gated-2' );
		await ctx.dispose();
	} );

	test( 'meter states decide the metered Group and the meter header is sent', async ( {
		baseURL,
	} ) => {
		const open = await reader( baseURL!, { 'metered-articles': 'open' } );
		const openResponse = await open.get( SEED.meteredArticle );
		expect( await openResponse.text() ).toContain( SEED.meteredBody );
		expect( openResponse.headers()[ HEADERS.meter ] ).toMatch(
			/^metered-articles; id=\d+; limit=3; period=2592000$/
		);
		await open.dispose();

		const wall = await reader( baseURL!, { 'metered-articles': 'wall' } );
		const wallBody = await ( await wall.get( SEED.meteredArticle ) ).text();
		expect( wallBody ).not.toContain( SEED.meteredBody );
		expect( wallBody ).toContain( SEED.meteredWall );
		await wall.dispose();

		const none = await anonymous( baseURL! );
		const noneBody = await ( await none.get( SEED.meteredArticle ) ).text();
		expect( noneBody ).not.toContain( SEED.meteredBody );
		await none.dispose();
	} );
} );

test.describe( 'View As switcher', () => {
	test( 'an editor can preview a reader view, and the response is no-store', async ( {
		page,
	} ) => {
		await page.goto( SEED.gatedArticle );
		await expect(
			page.locator( '#wp-admin-bar-content-gates-view-as' )
		).toBeVisible();
		await page.locator( '#wp-admin-bar-content-gates-view-as' ).hover();
		await page.getByRole( 'checkbox', { name: 'Subscriber' } ).check();
		await page
			.getByRole( 'button', { name: 'View as this reader' } )
			.click();

		const response = await page.goto( SEED.gatedArticle );
		expect( response?.headers()[ 'cache-control' ] ).toContain(
			'no-store'
		);
		expect( response?.headers()[ 'x-wpvip-content-gates-view-as' ] ).toBe(
			'subscriber'
		);
		await expect( page.locator( 'body' ) ).toContainText(
			SEED.subscriberOnly
		);

		await page.locator( '#wp-admin-bar-content-gates-view-as' ).hover();
		await page.getByRole( 'button', { name: 'Reset' } ).click();
		const reset = await page.goto( SEED.gatedArticle );
		expect(
			reset?.headers()[ 'x-wpvip-content-gates-view-as' ]
		).toBeUndefined();
	} );
} );
