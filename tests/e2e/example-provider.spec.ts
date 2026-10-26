import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import { request } from '@playwright/test';
import { SEED } from './helpers';

/**
 * The example provider in examples/segment-provider: a reader joins and
 * leaves a segment through its page, and the gated article follows.
 */

const PAGE = '/segments/';
const FORM = {
	action: 'content_gates_provider_action',
	segments: 'content_gates_segments[]',
};

function nonceFrom( html: string ): string {
	const match = /name="_wpnonce"[^>]*value="([0-9a-f]+)"/.exec( html );
	expect( match, 'the page carries a nonce' ).not.toBeNull();
	return match![ 1 ];
}

test.describe( 'example provider', () => {
	test( 'a reader joins and leaves a segment on the page', async ( {
		baseURL,
	} ) => {
		// One context: its cookie jar is the reader's browser.
		const ctx = await request.newContext( { baseURL, maxRedirects: 0 } );

		const page = await ctx.get( PAGE );
		expect( page.status() ).toBe( 200 );
		expect( page.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		const html = await page.text();
		expect( html ).toContain( 'value="subscriber"' );
		expect( html ).toMatch(
			/<input[^>]*value="metered-articles"[^>]*disabled=/
		);
		expect( html ).not.toContain( 'value="crawlers-search"' );
		expect( html ).not.toMatch(
			/<input[^>]*value="subscriber"[^>]*checked=/
		);

		const join = await ctx.post( PAGE, {
			form: {
				_wpnonce: nonceFrom( html ),
				[ FORM.action ]: 'save',
				[ FORM.segments ]: 'subscriber',
			},
		} );
		expect( join.status() ).toBe( 303 );
		expect( join.headers().location ).toContain(
			'content_gates_result=ok'
		);
		expect( join.headers()[ 'set-cookie' ] ).toContain( 'vip-go-seg=' );
		expect( join.headers()[ 'set-cookie' ] ).toContain( 'subscriber_--_1' );

		const after = await ctx.get( PAGE );
		expect( await after.text() ).toMatch(
			/<input[^>]*value="subscriber"[^>]*checked=/
		);
		const article = await ctx.get( SEED.gatedArticle );
		expect( await article.text() ).toContain( SEED.subscriberOnly );

		const leave = await ctx.post( PAGE, {
			form: {
				_wpnonce: nonceFrom( html ),
				[ FORM.action ]: 'save',
			},
		} );
		expect( leave.status() ).toBe( 303 );
		expect( leave.headers()[ 'set-cookie' ] ).toMatch(
			/vip-go-seg=(deleted)?;.*Max-Age=0/
		);
		const gone = await ctx.get( SEED.gatedArticle );
		expect( await gone.text() ).not.toContain( SEED.subscriberOnly );

		const forged = await ctx.post( PAGE, {
			form: {
				_wpnonce: 'not-a-nonce',
				[ FORM.action ]: 'save',
				[ FORM.segments ]: 'subscriber',
			},
		} );
		expect( forged.status() ).toBe( 303 );
		expect( forged.headers().location ).toContain(
			'content_gates_result=bad_nonce'
		);
		expect( forged.headers()[ 'set-cookie' ] ?? '' ).not.toContain(
			'subscriber_--_1'
		);
		await ctx.dispose();
	} );
} );
