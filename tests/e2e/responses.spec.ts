import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { request } from '@playwright/test';

test( 'real VIP cookie round trip protects raw page, REST, RSS and Atom', async ( {
	page,
	requestUtils,
	baseURL,
} ) => {
	await page.goto( '/wp-admin/options-general.php?page=content-gates' );
	const form = page.locator( 'form' ).filter( {
		has: page.locator( 'input[name="segment_id"][value="0"]' ),
	} );
	const name = `HTTP ${ Date.now() }`;
	await form.locator( '[name="segment_name"]' ).fill( name );
	await form.getByRole( 'button', { name: 'Add segment' } ).click();
	const segments = await requestUtils.rest( {
		path: '/content-gates/v1/segments',
	} );
	const id = segments.find(
		( segment: { name: string } ) => segment.name === name
	).id;
	const secret = `RESTRICTED_HTTP_${ id }`;
	const publicText = `PUBLIC_HTTP_${ id }`;
	const content = `<!-- wp:paragraph --><p>${ publicText }</p><!-- /wp:paragraph --><!-- wp:group {"contentGateSegmentId":${ id }} --><div class="wp-block-group"><!-- wp:paragraph --><p data-secret="${ secret }">${ secret }</p><!-- /wp:paragraph --></div><!-- /wp:group -->`;
	const post = await requestUtils.createPost( {
		title: 'Response protection',
		status: 'publish',
		content,
		excerpt: '',
	} );
	const anonymous = await request.newContext( { baseURL } );
	const paths = [
		`/?p=${ post.id }`,
		`/?rest_route=/wp/v2/posts/${ post.id }`,
		'/?feed=rss2',
		'/?feed=atom',
		'/?s=Response',
		'/',
	];
	for ( const path of paths ) {
		const response = await anonymous.get( path );
		expect( response.status(), path ).toBe( 200 );
		expect( await response.text(), path ).not.toContain( secret );
		expect( response.headers()[ 'cache-control' ], path ).toContain(
			'no-store'
		);
		expect(
			response.headers()[ 'x-content-gate-projection' ],
			path
		).toContain( `cg_1_${ id }` );
	}
	const anonymousEdit = await anonymous.get(
		`/?rest_route=/wp/v2/posts/${ post.id }&context=edit`
	);
	expect( anonymousEdit.status() ).toBe( 401 );
	expect( await anonymousEdit.text() ).not.toContain( secret );
	expect( anonymousEdit.headers()[ 'cache-control' ] ).toContain(
		'no-store'
	);
	const authorizedEdit = await requestUtils.rest( {
		path: `/wp/v2/posts/${ post.id }`,
		params: { context: 'edit' },
	} );
	expect( authorizedEdit.content.raw ).toBe( content );
	// oEmbed's description and embed page use the same protected excerpt path.
	for ( const path of [
		`/?rest_route=/oembed/1.0/embed&url=${ encodeURIComponent( `${ baseURL }/?p=${ post.id }` ) }`,
		`/?p=${ post.id }&embed=true`,
	] ) {
		const response = await anonymous.get( path );
		expect( response.status() ).toBe( 200 );
		expect( await response.text() ).not.toContain( secret );
	}
	const denied = await anonymous.post(
		'/?rest_route=/content-gates/v1/membership',
		{ data: { segment_id: id, member: true, version: 1 } }
	);
	expect( denied.status() ).toBe( 401 );
	// Authenticated administrators also need membership on public GETs.
	const authenticated = await page.request.get( `/?p=${ post.id }` );
	expect( await authenticated.text() ).not.toContain( secret );
	await requestUtils.rest( {
		path: '/content-gates/v1/membership',
		method: 'POST',
		data: { segment_id: id, member: true, version: 1 },
	} );
	// RequestUtils and page contexts differ; explicitly collect the real response cookie.
	const state = await requestUtils.request.storageState();
	const membership = state.cookies.find(
		( cookie ) => cookie.name === 'vip-go-seg'
	);
	expect( membership ).toBeDefined();
	const entitled = await request.newContext( {
		baseURL,
		storageState: {
			cookies: membership ? [ membership ] : [],
			origins: [],
		},
	} );
	for ( const path of paths.slice( 0, 4 ) ) {
		const response = await entitled.get( path );
		expect( await response.text(), path ).toContain( secret );
		expect( response.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		expect( response.headers()[ 'x-content-gate-projection' ] ).toContain(
			`"cg_1_${ id }":"1"`
		);
	}
	// Bad membership tokens do not grant access.
	const invalid = await request.newContext( {
		baseURL,
		extraHTTPHeaders: { Cookie: `vip-go-seg=vc-v1__cg_1_${ id }_--_other` },
	} );
	expect(
		await ( await invalid.get( `/?p=${ post.id }` ) ).text()
	).not.toContain( secret );
	await invalid.dispose();
	await entitled.dispose();
	await anonymous.dispose();
} );
