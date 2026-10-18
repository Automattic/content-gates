/**
 * Leak-surface acceptance tests: the headline suite.
 *
 * Criteria covered:
 * - content-gating-api.md "Zero gated bytes in a non-entitled render":
 *   page HTML fetched with no JavaScript (plain HTTP), REST
 *   `content.rendered` and `excerpt.rendered`, RSS feed, listing teasers
 *   (home and search), oEmbed JSON and the `<permalink>embed/` page.
 * - content-gating-api.md "Signal present on every output path": the
 *   `X-Content-Gates-Vary` header on singular, feed, search, listing,
 *   embed and REST responses; absent on a page with no gates.
 * - content-gating-api.md "Nested gates key on their own set": three
 *   readers of one nested-gate URL report three different consulted sets.
 * - editor-ui.md Delivery bullets 1, 2, 7, 8: entitled readers keep the
 *   full content; nested Groups; REST/feed/oEmbed/listing triage; crawler
 *   variant with `isAccessibleForFree` and `hasPart`/`cssSelector`.
 * - segment-admin.md Membership bullet 1: server-side (PRG) assignment
 *   takes effect on a later request.
 * - Brief decision "Deactivation semantics": a member of a deactivated
 *   segment is withheld the Group.
 * - Brief: the local adapter marks every response it handles with
 *   `X-Content-Gates-Simulation: local`.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext, APIResponse } from '@playwright/test';
import {
	KEY_HEADER,
	SIMULATION_HEADER,
	VARY_HEADER,
	createPost,
	createSegment,
	group,
	jarSegmentCookie,
	paragraph,
	pathOf,
	reader,
	readerGet,
	segmentCookie,
	setSegmentActive,
	tokens,
	unique,
} from './helpers';

/**
 * Parse `a=1, b=none` into a map.
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

/**
 * Every `<script type="application/ld+json">` payload on a page, parsed.
 *
 * @param html Page HTML.
 */
function jsonLd( html: string ): unknown[] {
	const out: unknown[] = [];
	const pattern =
		/<script[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g;
	let match: RegExpExecArray | null = pattern.exec( html );
	while ( match ) {
		out.push( JSON.parse( match[ 1 ] ) );
		match = pattern.exec( html );
	}
	return out;
}

/**
 * Assert the local adapter handled a response.
 *
 * @param response Response.
 * @param label    Label for the failure message.
 */
function expectLocalSimulation( response: APIResponse, label: string ): void {
	expect(
		response.headers()[ SIMULATION_HEADER ],
		`${ label }: ${ SIMULATION_HEADER }`
	).toBe( 'local' );
}

/**
 * Fetch a URL as a reader and return the body with the response.
 *
 * @param context Reader context.
 * @param url     URL.
 * @param headers Extra request headers.
 */
async function fetchText(
	context: APIRequestContext,
	url: string,
	headers: Record< string, string > = {}
): Promise< { response: APIResponse; body: string } > {
	const response = await readerGet( context, url, headers );
	return { response, body: await response.text() };
}

test.describe( 'leak surfaces', () => {
	test( 'anonymous page HTML has the public text, zero gated bytes, and the variance headers', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) + group( paragraph( SECRET ), segment.id )
		);

		const anon = await reader( baseURL as string );
		const { response, body } = await fetchText( anon, pathOf( post.link ) );
		expect( response.status() ).toBe( 200 );
		expect( body ).toContain( PUBLIC );
		expect( body ).not.toContain( SECRET );
		expect( body ).not.toContain( 'content-gates-paywall' );
		expectLocalSimulation( response, 'page' );

		const headers = response.headers();
		expect( tokens( headers[ VARY_HEADER ] ) ).toEqual(
			[ 'crawler', segment.key ].sort()
		);
		expect( keyMap( headers[ KEY_HEADER ] ) ).toEqual( {
			crawler: 'none',
			[ segment.key ]: 'none',
		} );
		// A plain GET must not write a cookie, or the edge could never store it.
		expect( headers[ 'set-cookie' ] ).toBeUndefined();
		await anon.dispose();
	} );

	test( 'member (edge-written cookie) receives the gated bytes and a member variant key', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) + group( paragraph( SECRET ), segment.id )
		);

		const member = await reader( baseURL as string, {
			[ segment.key ]: '1',
		} );
		const { response, body } = await fetchText(
			member,
			pathOf( post.link )
		);
		expect( response.status() ).toBe( 200 );
		expect( body ).toContain( PUBLIC );
		expect( body ).toContain( SECRET );
		expect( body ).not.toContain( 'content-gates-paywall' );
		expectLocalSimulation( response, 'member page' );

		const headers = response.headers();
		// Finding: `crawler` is also listed here because Crawler_Markup reads
		// it on wp_head for every singular gated post, entitled or not.
		expect( tokens( headers[ VARY_HEADER ] ) ).toContain( segment.key );
		expect( keyMap( headers[ KEY_HEADER ] )[ segment.key ] ).toBe( '1' );

		// REST content.rendered for the member carries the gated bytes too.
		const rest = await readerGet(
			member,
			`/wp-json/wp/v2/posts/${ post.id }`
		);
		const json = ( await rest.json() ) as {
			content: { rendered: string };
		};
		expect( json.content.rendered ).toContain( SECRET );
		expect( tokens( rest.headers()[ VARY_HEADER ] ) ).toContain(
			segment.key
		);
		await member.dispose();
	} );

	test( 'a post without gates carries no X-Content-Gates-Vary header', async ( {
		baseURL,
		requestUtils,
	} ) => {
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) + group( paragraph( 'ungated group' ) )
		);
		const anon = await reader( baseURL as string );
		const { response, body } = await fetchText( anon, pathOf( post.link ) );
		expect( response.status() ).toBe( 200 );
		expect( body ).toContain( PUBLIC );
		expect( response.headers()[ VARY_HEADER ] ).toBeUndefined();
		expect( keyMap( response.headers()[ KEY_HEADER ] ) ).toEqual( {} );
		expectLocalSimulation( response, 'ungated page' );

		// Same for a member: membership alone must not make a page vary.
		const member = await reader( baseURL as string, { newsletter: '1' } );
		const asMember = await readerGet( member, pathOf( post.link ) );
		expect( asMember.headers()[ VARY_HEADER ] ).toBeUndefined();
		await anon.dispose();
		await member.dispose();
	} );

	test( 'anonymous REST content.rendered and excerpt.rendered have zero gated bytes', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) + group( paragraph( SECRET ), segment.id )
		);

		const anon = await reader( baseURL as string );
		for ( const path of [
			`/wp-json/wp/v2/posts/${ post.id }`,
			`/wp-json/wp/v2/posts/${ post.id }?context=view`,
			`/wp-json/wp/v2/posts?slug=${ post.slug }`,
			`/wp-json/wp/v2/posts?search=${ encodeURIComponent( SECRET ) }`,
		] ) {
			const response = await readerGet( anon, path );
			expect( response.status(), path ).toBe( 200 );
			const text = await response.text();
			expect( text, path ).not.toContain( SECRET );
			const data = ( await response.json() ) as
				| {
						content: { rendered: string };
						excerpt: { rendered: string };
				  }
				| Array< {
						content: { rendered: string };
						excerpt: { rendered: string };
				  } >;
			const records = Array.isArray( data ) ? data : [ data ];
			expect( records.length, path ).toBeGreaterThan( 0 );
			for ( const record of records ) {
				expect( record.content.rendered, path ).toContain( PUBLIC );
				expect( record.content.rendered, path ).not.toContain( SECRET );
				expect( record.excerpt.rendered, path ).toContain( PUBLIC );
				expect( record.excerpt.rendered, path ).not.toContain( SECRET );
			}
			expect(
				tokens( response.headers()[ VARY_HEADER ] ),
				path
			).toContain( segment.key );
		}
		await anon.dispose();
	} );

	test( 'anonymous RSS feed has the public text and zero gated bytes', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) + group( paragraph( SECRET ), segment.id )
		);

		const anon = await reader( baseURL as string );
		for ( const path of [
			'/feed/',
			'/feed/atom/',
			'/feed/rdf/',
			`${ pathOf( post.link ) }feed/`,
		] ) {
			const { response, body } = await fetchText( anon, path );
			expect( response.status(), path ).toBe( 200 );
			expect( body, path ).not.toContain( SECRET );
			expectLocalSimulation( response, path );
			if ( path === '/feed/' || path === '/feed/atom/' ) {
				expect( body, path ).toContain( PUBLIC );
				expect(
					tokens( response.headers()[ VARY_HEADER ] ),
					path
				).toContain( segment.key );
			}
		}
		await anon.dispose();
	} );

	test( 'listing teasers (home, search) have the public text and zero gated bytes', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		// A second gated phrase that is never part of any search query, so a
		// reflected query string cannot be mistaken for leaked content.
		const SECRET_TAIL = unique( 'SECRET-TAIL-BYTES' );
		await createPost(
			requestUtils,
			paragraph( PUBLIC ) +
				group( paragraph( `${ SECRET } ${ SECRET_TAIL }` ), segment.id )
		);

		const anon = await reader( baseURL as string );
		const home = await fetchText( anon, '/' );
		expect( home.response.status() ).toBe( 200 );
		expect( home.body ).toContain( PUBLIC );
		expect( home.body ).not.toContain( SECRET );
		expect( home.body ).not.toContain( SECRET_TAIL );
		expectLocalSimulation( home.response, 'home' );
		expect( tokens( home.response.headers()[ VARY_HEADER ] ) ).toContain(
			segment.key
		);

		const search = await fetchText(
			anon,
			`/?s=${ encodeURIComponent( PUBLIC ) }`
		);
		expect( search.response.status() ).toBe( 200 );
		expect( search.body ).toContain( PUBLIC );
		expect( search.body ).not.toContain( SECRET );
		expect( search.body ).not.toContain( SECRET_TAIL );

		// Searching for the secret itself: WordPress matches post_content in
		// the database, so the result exists (an oracle, see the findings), but
		// its teaser must still carry zero gated bytes. The query is echoed in
		// the title and search box, so assert on the other gated phrase.
		const oracle = await fetchText(
			anon,
			`/?s=${ encodeURIComponent( SECRET ) }`
		);
		expect( oracle.response.status() ).toBe( 200 );
		expect( oracle.body ).toContain( PUBLIC );
		expect( oracle.body ).not.toContain( SECRET_TAIL );
		await anon.dispose();
	} );

	test( 'oEmbed JSON and the embed page have zero gated bytes', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) + group( paragraph( SECRET ), segment.id )
		);

		const anon = await reader( baseURL as string );
		const oembed = await readerGet(
			anon,
			`/wp-json/oembed/1.0/embed?url=${ encodeURIComponent( post.link ) }`
		);
		expect( oembed.status() ).toBe( 200 );
		const oembedText = await oembed.text();
		expect( oembedText ).not.toContain( SECRET );
		const oembedJson = ( await oembed.json() ) as { html: string };
		expect( oembedJson.html ).not.toContain( SECRET );

		const embed = await fetchText( anon, `${ pathOf( post.link ) }embed/` );
		expect( embed.response.status() ).toBe( 200 );
		expect( embed.body ).toContain( PUBLIC );
		expect( embed.body ).not.toContain( SECRET );
		expectLocalSimulation( embed.response, 'embed page' );
		expect( tokens( embed.response.headers()[ VARY_HEADER ] ) ).toContain(
			segment.key
		);
		await anon.dispose();
	} );

	test( 'nested gates: each reader sees exactly their Groups and reports their own consulted set', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const outer = await createSegment( request, unique( 'Outer' ) );
		const inner = await createSegment( request, unique( 'Inner' ) );
		const PUBLIC = unique( 'PUBLIC-TEXT' );
		const OUTER = unique( 'OUTER-BYTES' );
		const INNER = unique( 'INNER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( PUBLIC ) +
				group(
					paragraph( OUTER ) + group( paragraph( INNER ), inner.id ),
					outer.id
				)
		);
		const path = pathOf( post.link );

		// Reader in neither: outer denied, inner never evaluated.
		const none = await reader( baseURL as string );
		const asNone = await fetchText( none, path );
		expect( asNone.body ).toContain( PUBLIC );
		expect( asNone.body ).not.toContain( OUTER );
		expect( asNone.body ).not.toContain( INNER );
		expect( tokens( asNone.response.headers()[ VARY_HEADER ] ) ).toEqual(
			[ 'crawler', outer.key ].sort()
		);

		// Reader in outer only: outer shown, inner denied and consulted.
		const outerOnly = await reader( baseURL as string, {
			[ outer.key ]: '1',
		} );
		const asOuter = await fetchText( outerOnly, path );
		expect( asOuter.body ).toContain( OUTER );
		expect( asOuter.body ).not.toContain( INNER );
		expect( tokens( asOuter.response.headers()[ VARY_HEADER ] ) ).toEqual(
			[ 'crawler', outer.key, inner.key ].sort()
		);
		expect( keyMap( asOuter.response.headers()[ KEY_HEADER ] ) ).toEqual( {
			crawler: 'none',
			[ outer.key ]: '1',
			[ inner.key ]: 'none',
		} );

		// Reader in both: everything shown; both gates consulted (crawler is
		// listed too, see the finding in the member test above).
		const both = await reader( baseURL as string, {
			[ outer.key ]: '1',
			[ inner.key ]: '1',
		} );
		const asBoth = await fetchText( both, path );
		expect( asBoth.body ).toContain( OUTER );
		expect( asBoth.body ).toContain( INNER );
		const bothVary = tokens( asBoth.response.headers()[ VARY_HEADER ] );
		expect( bothVary ).toContain( outer.key );
		expect( bothVary ).toContain( inner.key );

		// Reader in inner only: the outer gate blocks everything, and the
		// inner segment is not consulted at all.
		const innerOnly = await reader( baseURL as string, {
			[ inner.key ]: '1',
		} );
		const asInner = await fetchText( innerOnly, path );
		expect( asInner.body ).not.toContain( OUTER );
		expect( asInner.body ).not.toContain( INNER );
		expect( tokens( asInner.response.headers()[ VARY_HEADER ] ) ).toEqual(
			[ 'crawler', outer.key ].sort()
		);

		for ( const context of [ none, outerOnly, both, innerOnly ] ) {
			await context.dispose();
		}
	} );

	test( 'a member of a deactivated segment is withheld the Group', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) ) +
				group( paragraph( SECRET ), segment.id )
		);
		const member = await reader( baseURL as string, {
			[ segment.key ]: '1',
		} );

		const before = await fetchText( member, pathOf( post.link ) );
		expect( before.body ).toContain( SECRET );

		await setSegmentActive( request, segment.id, false );
		const after = await fetchText( member, pathOf( post.link ) );
		expect( after.response.status() ).toBe( 200 );
		expect( after.body ).not.toContain( SECRET );
		// The read is still recorded, so the response varies on the segment.
		expect( tokens( after.response.headers()[ VARY_HEADER ] ) ).toContain(
			segment.key
		);

		await setSegmentActive( request, segment.id, true );
		const restored = await fetchText( member, pathOf( post.link ) );
		expect( restored.body ).toContain( SECRET );
		await member.dispose();
	} );

	test( 'crawler variant renders the Group inside the paywall wrapper with JSON-LD', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) ) +
				group( paragraph( SECRET ), segment.id )
		);
		const ungated = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) )
		);

		const crawler = await reader( baseURL as string, { crawler: '1' } );
		const { response, body } = await fetchText(
			crawler,
			pathOf( post.link )
		);
		expect( response.status() ).toBe( 200 );
		expect( body ).toContain( SECRET );
		const wrapper = body.match(
			/<div class="content-gates-paywall">([\s\S]*?)<\/div>\s*<\/div>/
		);
		expect( wrapper, 'paywall wrapper around the Group' ).not.toBeNull();
		expect( wrapper?.[ 0 ] ).toContain( SECRET );
		expect( body ).toContain( 'application/ld+json' );

		const paywall = jsonLd( body ).find( ( item ) => {
			const record = item as { isAccessibleForFree?: boolean };
			return record.isAccessibleForFree === false;
		} ) as
			| {
					isAccessibleForFree: boolean;
					hasPart: {
						isAccessibleForFree: boolean;
						cssSelector: string;
					};
			  }
			| undefined;
		expect( paywall ).toBeDefined();
		expect( paywall?.hasPart.isAccessibleForFree ).toBe( false );
		expect( paywall?.hasPart.cssSelector ).toBe( '.content-gates-paywall' );

		expect( tokens( response.headers()[ VARY_HEADER ] ) ).toEqual(
			[ 'crawler', segment.key ].sort()
		);
		expect( keyMap( response.headers()[ KEY_HEADER ] ) ).toEqual( {
			crawler: '1',
			[ segment.key ]: 'none',
		} );

		// No gates: no paywall JSON-LD even for the crawler.
		const plain = await fetchText( crawler, pathOf( ungated.link ) );
		expect( plain.body ).not.toContain( 'content-gates-paywall' );
		expect(
			jsonLd( plain.body ).some( ( item ) => {
				const record = item as { isAccessibleForFree?: boolean };
				return record.isAccessibleForFree === false;
			} )
		).toBe( false );
		expect( plain.response.headers()[ VARY_HEADER ] ).toBeUndefined();
		await crawler.dispose();
	} );

	test( 'POST-redirect-GET assignment sets the cookie and unlocks the Group on the next request', async ( {
		baseURL,
		request,
		requestUtils,
	} ) => {
		const segment = await createSegment( request );
		const SECRET = unique( 'SECRET-SUBSCRIBER-BYTES' );
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) ) +
				group( paragraph( SECRET ), segment.id )
		);
		const path = pathOf( post.link );

		const visitor = await reader( baseURL as string );
		const before = await fetchText( visitor, path );
		expect( before.body ).not.toContain( SECRET );

		const redirect = await visitor.post( path, {
			form: { content_gates_test_assign: String( segment.id ) },
			maxRedirects: 0,
		} );
		expect( redirect.status() ).toBe( 302 );
		expect( redirect.headers().location ).toContain(
			'content_gates_test=ok'
		);
		expect( redirect.headers()[ 'set-cookie' ] ).toContain(
			`vip-go-seg=${ segmentCookie( { [ segment.key ]: '1' } ) }`
		);
		expectLocalSimulation( redirect, 'PRG redirect' );

		expect( await jarSegmentCookie( visitor ) ).toBe(
			segmentCookie( { [ segment.key ]: '1' } )
		);
		const after = await fetchText( visitor, path );
		expect( after.response.status() ).toBe( 200 );
		expect( after.body ).toContain( SECRET );

		// Leave again through the same flow.
		const leave = await visitor.post( path, {
			form: { content_gates_test_remove: String( segment.id ) },
			maxRedirects: 0,
		} );
		expect( leave.status() ).toBe( 302 );
		const gone = await fetchText( visitor, path );
		expect( gone.body ).not.toContain( SECRET );
		await visitor.dispose();
	} );

	test( 'every front-end response is marked as the local simulation', async ( {
		baseURL,
		requestUtils,
	} ) => {
		const post = await createPost(
			requestUtils,
			paragraph( unique( 'PUBLIC-TEXT' ) )
		);
		const anon = await reader( baseURL as string );
		for ( const path of [
			'/',
			pathOf( post.link ),
			'/feed/',
			`/?s=${ unique( 'nothing' ) }`,
			`/${ unique( 'missing' ) }/`,
		] ) {
			const response = await readerGet( anon, path );
			expectLocalSimulation( response, path );
		}
		await anon.dispose();
	} );
} );
