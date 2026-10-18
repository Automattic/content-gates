/**
 * Membership REST contract acceptance tests.
 *
 * Criteria covered:
 * - segment-admin.md Membership bullet 1: a client-side / MCP-style
 *   assignment through a documented, versioned contract takes effect on a
 *   later request (cookie on the POST response, membership on GET, gated
 *   Group visible on the next page view).
 * - Brief "Contract version": responses carry `"contract": "1"`.
 * - Brief Membership error order: unknown reference ->
 *   `content_gates_invalid_segment`; inactive ->
 *   `content_gates_inactive_segment`; both 400.
 * - Brief REST surface: every response from the route is uncacheable
 *   (`Cache-Control` contains `no-store`), success and error alike.
 * - Removal clears the membership.
 *
 * The public PHP functions are covered by PHPUnit and are not repeated here.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { APIResponse } from '@playwright/test';
import {
	MEMBERSHIP_ROUTE,
	type MembershipState,
	createPost,
	createSegment,
	group,
	jarSegmentCookie,
	membershipState,
	paragraph,
	parseSegmentCookie,
	pathOf,
	reader,
	readerGet,
	segmentCookie,
	setSegmentActive,
	unique,
} from './helpers';

/**
 * Assert the reader has no membership entries.
 *
 * Tolerates the PHP serialization of an empty array (`[]`); the strict
 * object-shape check lives in its own test so the finding stays visible.
 *
 * @param state Membership state.
 * @param label Label for failure output.
 */
function expectNoMembership( state: MembershipState, label: string ): void {
	expect( Object.keys( state.membership ), `${ label }: membership` ).toEqual(
		[]
	);
}

/**
 * Assert a membership-route response is uncacheable.
 *
 * @param response Response.
 * @param label    Label for failure output.
 */
function expectNoStore( response: APIResponse, label: string ): void {
	const cacheControl = response.headers()[ 'cache-control' ] ?? '';
	expect( cacheControl, `${ label }: cache-control` ).toContain( 'no-store' );
}

/**
 * The `vip-go-seg` value from a response's Set-Cookie header, or null.
 *
 * @param response Response.
 */
function setCookieValue( response: APIResponse ): string | null {
	const header = response
		.headersArray()
		.filter( ( h ) => h.name.toLowerCase() === 'set-cookie' )
		.map( ( h ) => h.value )
		.find( ( value ) => value.startsWith( 'vip-go-seg=' ) );
	if ( ! header ) {
		return null;
	}
	return decodeURIComponent(
		header.split( ';' )[ 0 ].slice( 'vip-go-seg='.length )
	);
}

test.describe( 'membership API', () => {
	test( 'GET returns contract 1, empty membership, and the segment list', async ( {
		baseURL,
		request,
	} ) => {
		const segment = await createSegment( request );
		const anon = await reader( baseURL as string );
		const response = await readerGet( anon, MEMBERSHIP_ROUTE );
		expect( response.status() ).toBe( 200 );
		expectNoStore( response, 'GET' );
		const state = ( await response.json() ) as MembershipState;
		expect( state.contract ).toBe( '1' );
		expectNoMembership( state, 'GET' );
		expect( state.segments ).toContainEqual( {
			id: segment.id,
			key: segment.key,
			name: segment.name,
			active: true,
		} );
		// Code-registered segments have no ID and are always active.
		expect( state.segments ).toContainEqual( {
			id: null,
			key: 'crawler',
			name: 'crawler',
			active: true,
		} );
		await anon.dispose();
	} );

	test( 'POST assign sets the cookie, a later GET and page view see the membership, remove clears it', async ( {
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

		const client = await reader( baseURL as string );
		const assign = await client.post( MEMBERSHIP_ROUTE, {
			data: { segment: segment.id, action: 'assign' },
		} );
		expect( assign.status() ).toBe( 200 );
		expectNoStore( assign, 'POST assign' );
		const assigned = ( await assign.json() ) as MembershipState;
		expect( assigned.contract ).toBe( '1' );
		expect( assigned.changed ).toBe( true );
		expect( assigned.membership ).toEqual( { [ segment.key ]: '1' } );
		expect( assigned.cookie ).toEqual( {
			name: 'vip-go-seg',
			value: segmentCookie( { [ segment.key ]: '1' } ),
		} );
		expect( setCookieValue( assign ) ).toBe(
			segmentCookie( { [ segment.key ]: '1' } )
		);

		// The context kept the Set-Cookie; a plain GET now reports membership.
		expect(
			parseSegmentCookie( await jarSegmentCookie( client ) )
		).toEqual( { [ segment.key ]: '1' } );
		const after = await membershipState( client );
		expect( after.membership ).toEqual( { [ segment.key ]: '1' } );

		// And the next page view carries the gated bytes.
		const page = await readerGet( client, pathOf( post.link ) );
		expect( await page.text() ).toContain( SECRET );

		// Assign by key is the same operation.
		const byKey = await client.post( MEMBERSHIP_ROUTE, {
			data: { segment: segment.key, action: 'assign' },
		} );
		expect( byKey.status() ).toBe( 200 );

		const remove = await client.post( MEMBERSHIP_ROUTE, {
			data: { segment: segment.id, action: 'remove' },
		} );
		expect( remove.status() ).toBe( 200 );
		expectNoStore( remove, 'POST remove' );
		const removed = ( await remove.json() ) as MembershipState;
		expect( removed.changed ).toBe( true );
		expectNoMembership( removed, 'POST remove' );
		// PHP writes `deleted` with a past expiry when clearing a cookie.
		expect( [ '', 'deleted' ] ).toContain( setCookieValue( remove ) );
		expect( await jarSegmentCookie( client ) ).toBe( '' );

		const cleared = await membershipState( client );
		expectNoMembership( cleared, 'GET after remove' );
		const locked = await readerGet( client, pathOf( post.link ) );
		expect( await locked.text() ).not.toContain( SECRET );
		await client.dispose();
	} );

	test( 'empty membership is a JSON object, as the contract documents', async ( {
		baseURL,
	} ) => {
		// The contract says `membership: { key: value }`. PHP serializes an
		// empty array as `[]`, which changes the JSON type for clients.
		const anon = await reader( baseURL as string );
		const response = await readerGet( anon, MEMBERSHIP_ROUTE );
		const raw = await response.text();
		expect( raw ).toContain( '"membership":{}' );
		await anon.dispose();
	} );

	test( 'unknown and inactive segments are refused with 400 and the documented codes', async ( {
		baseURL,
		request,
	} ) => {
		const inactive = await createSegment( request );
		await setSegmentActive( request, inactive.id, false );
		const client = await reader( baseURL as string );

		const unknown = await client.post( MEMBERSHIP_ROUTE, {
			data: { segment: unique( 'no-such-segment' ), action: 'assign' },
		} );
		expect( unknown.status() ).toBe( 400 );
		expectNoStore( unknown, 'POST unknown' );
		expect( ( ( await unknown.json() ) as { code: string } ).code ).toBe(
			'content_gates_invalid_segment'
		);

		const unknownId = await client.post( MEMBERSHIP_ROUTE, {
			data: { segment: 999999999, action: 'assign' },
		} );
		expect( unknownId.status() ).toBe( 400 );
		expect( ( ( await unknownId.json() ) as { code: string } ).code ).toBe(
			'content_gates_invalid_segment'
		);

		const refused = await client.post( MEMBERSHIP_ROUTE, {
			data: { segment: inactive.id, action: 'assign' },
		} );
		expect( refused.status() ).toBe( 400 );
		expectNoStore( refused, 'POST inactive' );
		expect( ( ( await refused.json() ) as { code: string } ).code ).toBe(
			'content_gates_inactive_segment'
		);
		expect( setCookieValue( refused ) ).toBeNull();

		// Nothing was written.
		expect( await jarSegmentCookie( client ) ).toBe( '' );
		const state = await membershipState( client );
		expectNoMembership( state, 'GET after refusals' );

		// An invalid action is a schema error, still uncacheable.
		const badAction = await client.post( MEMBERSHIP_ROUTE, {
			data: { segment: inactive.id, action: 'explode' },
		} );
		expect( badAction.status() ).toBe( 400 );
		expectNoStore( badAction, 'POST bad action' );
		await client.dispose();
	} );
} );
