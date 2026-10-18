/**
 * Shared helpers for the Content Gates e2e specs.
 *
 * Everything here talks to the tests site over HTTP only. Segments are
 * created through the real admin form (admin-post.php) because the plugin
 * exposes no REST write path for them on purpose; posts go through core's
 * REST API; readers are plain request contexts with or without the
 * `vip-go-seg` cookie that the local cookie adapter reads.
 */
import { request, type APIRequestContext } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

export const SEGMENT_COOKIE = 'vip-go-seg';
export const COOKIE_PREFIX = 'vc-v1__';
export const GROUP_SEPARATOR = '---__';
export const VALUE_SEPARATOR = '_--_';
export const MEMBERSHIP_ROUTE = '/wp-json/content-gates/v1/membership';
export const ADMIN_PAGE = '/wp-admin/options-general.php?page=content-gates';
export const SIMULATION_HEADER = 'x-content-gates-simulation';
export const VARY_HEADER = 'x-content-gates-vary';
export const KEY_HEADER = 'x-content-gates-key';
export const ATTRIBUTE = 'contentGatesSegmentId';

export interface SegmentRecord {
	id: number | null;
	key: string;
	name: string;
	active: boolean;
}

export interface CptSegment {
	id: number;
	key: string;
	name: string;
}

export interface MembershipState {
	contract: string;
	membership: Record< string, string >;
	segments: SegmentRecord[];
	changed?: boolean;
	cookie?: { name: string; value: string };
}

export interface CreatedPost {
	id: number;
	link: string;
	slug: string;
}

let counter = 0;

/**
 * A unique, cookie-safe token for names, slugs and content markers.
 *
 * @param prefix Human-readable prefix.
 * @return Unique token.
 */
export function unique( prefix: string ): string {
	counter += 1;
	return `${ prefix }-${ Date.now().toString( 36 ) }${ counter }`;
}

/**
 * Serialize a membership map in the `Vary_Cache` cookie format.
 *
 * Groups are sorted by name, exactly like `Local_Cookie_Adapter::serialize()`.
 *
 * @param membership Group name to value.
 * @return Cookie value.
 */
export function segmentCookie( membership: Record< string, string > ): string {
	const parts = Object.keys( membership )
		.sort()
		.filter( ( name ) => membership[ name ] !== '' )
		.map(
			( name ) => `${ name }${ VALUE_SEPARATOR }${ membership[ name ] }`
		);
	return parts.length ? COOKIE_PREFIX + parts.join( GROUP_SEPARATOR ) : '';
}

/**
 * Parse a `vip-go-seg` cookie value back into a membership map.
 *
 * @param value Cookie value.
 * @return Group name to value.
 */
export function parseSegmentCookie( value: string ): Record< string, string > {
	const out: Record< string, string > = {};
	const body = value.startsWith( COOKIE_PREFIX )
		? value.slice( COOKIE_PREFIX.length )
		: value;
	for ( const pair of body.split( GROUP_SEPARATOR ) ) {
		const index = pair.indexOf( VALUE_SEPARATOR );
		if ( index > 0 ) {
			out[ pair.slice( 0, index ) ] = pair.slice(
				index + VALUE_SEPARATOR.length
			);
		}
	}
	return out;
}

/**
 * A reader: a request context with no admin cookies and, optionally, a
 * `vip-go-seg` cookie written the way the edge would write it.
 *
 * @param baseURL    Site (or edge simulator) base URL.
 * @param membership Group name to value; omit for an anonymous non-member.
 * @return Request context. Dispose it when done.
 */
export async function reader(
	baseURL: string,
	membership: Record< string, string > = {}
): Promise< APIRequestContext > {
	const value = segmentCookie( membership );
	const cookies = value
		? [
				{
					name: SEGMENT_COOKIE,
					value,
					domain: new URL( baseURL ).hostname,
					path: '/',
					expires: -1,
					httpOnly: false,
					secure: false,
					sameSite: 'Lax' as const,
				},
			]
		: [];
	// The cookie is seeded in the jar, exactly as a browser would hold a
	// cookie the edge (or an earlier response) wrote. Set-Cookie responses
	// update the same jar, so one context can follow a membership change.
	return request.newContext( {
		baseURL,
		storageState: { cookies, origins: [] },
	} );
}

/**
 * GET a URL as a reader without following redirects.
 *
 * @param context Reader context.
 * @param url     Absolute or baseURL-relative URL.
 * @param headers Extra headers.
 */
export async function readerGet(
	context: APIRequestContext,
	url: string,
	headers: Record< string, string > = {}
) {
	return context.get( url, { headers, maxRedirects: 0 } );
}

/**
 * The `vip-go-seg` value currently in a reader's cookie jar, or '' when none.
 *
 * @param context Reader context.
 */
export async function jarSegmentCookie(
	context: APIRequestContext
): Promise< string > {
	const state = await context.storageState();
	const cookie = state.cookies.find( ( c ) => c.name === SEGMENT_COOKIE );
	return cookie ? cookie.value : '';
}

/**
 * Current membership state from the public GET endpoint.
 *
 * @param context Any request context.
 * @param headers Extra headers (for example the reader's cookie).
 */
export async function membershipState(
	context: APIRequestContext,
	headers: Record< string, string > = {}
): Promise< MembershipState > {
	const response = await context.get( MEMBERSHIP_ROUTE, { headers } );
	if ( ! response.ok() ) {
		throw new Error(
			`GET ${ MEMBERSHIP_ROUTE } returned ${ response.status() }: ${ await response.text() }`
		);
	}
	return ( await response.json() ) as MembershipState;
}

/**
 * Read the segment-admin nonce from the settings page.
 *
 * @param admin Logged-in (admin) request context.
 */
async function adminNonce( admin: APIRequestContext ): Promise< string > {
	const response = await admin.get( ADMIN_PAGE );
	const html = await response.text();
	const match = html.match(
		/name="_content_gates_nonce"[^>]*value="([^"]+)"|id="_content_gates_nonce"[^>]*value="([^"]+)"/
	);
	const nonce = match?.[ 1 ] ?? match?.[ 2 ];
	if ( ! nonce ) {
		throw new Error(
			`Could not read _content_gates_nonce from ${ ADMIN_PAGE } (status ${ response.status() })`
		);
	}
	return nonce;
}

/**
 * Submit one segment-admin operation through admin-post.php.
 *
 * @param admin     Logged-in (admin) request context.
 * @param operation create | rename | activate | deactivate.
 * @param fields    Extra form fields.
 * @return The notice code from the redirect target.
 */
async function adminOperation(
	admin: APIRequestContext,
	operation: 'create' | 'rename' | 'activate' | 'deactivate',
	fields: Record< string, string >
): Promise< string > {
	const nonce = await adminNonce( admin );
	const response = await admin.post( '/wp-admin/admin-post.php', {
		form: {
			action: 'content_gates_segment',
			operation,
			_content_gates_nonce: nonce,
			...fields,
		},
		maxRedirects: 0,
	} );
	const location = response.headers().location ?? '';
	const notice =
		new URL( location, 'http://localhost' ).searchParams.get(
			'content_gates_notice'
		) ?? '';
	if ( response.status() !== 302 || notice === 'error' || notice === '' ) {
		throw new Error(
			`admin-post ${ operation } failed: status ${ response.status() }, location ${ location }`
		);
	}
	return notice;
}

/**
 * Create an active segment through the admin form and return its record.
 *
 * @param admin Logged-in (admin) request context.
 * @param name  Segment name; defaults to a unique one.
 */
export async function createSegment(
	admin: APIRequestContext,
	name: string = unique( 'e2e-seg' )
): Promise< CptSegment > {
	await adminOperation( admin, 'create', { segment_name: name } );
	const state = await membershipState( admin );
	const record = state.segments.find(
		( segment ) => segment.name === name && segment.id !== null
	);
	if ( ! record || record.id === null ) {
		throw new Error( `Segment "${ name }" not listed after create` );
	}
	return { id: record.id, key: record.key, name: record.name };
}

/**
 * Activate or deactivate a segment through the admin form.
 *
 * @param admin  Logged-in (admin) request context.
 * @param id     Segment post ID.
 * @param active Desired state.
 */
export async function setSegmentActive(
	admin: APIRequestContext,
	id: number,
	active: boolean
): Promise< void > {
	await adminOperation( admin, active ? 'activate' : 'deactivate', {
		segment_id: String( id ),
	} );
}

/**
 * Rename a segment through the admin form.
 *
 * @param admin Logged-in (admin) request context.
 * @param id    Segment post ID.
 * @param name  New name.
 */
export async function renameSegment(
	admin: APIRequestContext,
	id: number,
	name: string
): Promise< void > {
	await adminOperation( admin, 'rename', {
		segment_id: String( id ),
		segment_name: name,
	} );
}

/**
 * Block markup for a paragraph.
 *
 * @param text Paragraph text.
 */
export function paragraph( text: string ): string {
	return `<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;
}

/**
 * Block markup for a Group, optionally gated on a segment.
 *
 * @param inner     Inner block markup.
 * @param segmentId Segment post ID, or null for an ungated Group.
 */
export function group(
	inner: string,
	segmentId: number | null = null
): string {
	const attrs =
		segmentId === null ? '' : ` {"${ ATTRIBUTE }":${ segmentId }}`;
	return `<!-- wp:group${ attrs } --><div class="wp-block-group">${ inner }</div><!-- /wp:group -->`;
}

/**
 * Create a published post through core's REST API.
 *
 * @param requestUtils WordPress request utils (admin).
 * @param content      Block markup.
 * @param title        Post title; defaults to a unique one.
 * @param excerpt      Manual excerpt; default empty so the excerpt is derived.
 */
export async function createPost(
	requestUtils: RequestUtils,
	content: string,
	title: string = unique( 'e2e-post' ),
	excerpt: string = ''
): Promise< CreatedPost > {
	const slug = unique( 'e2e' ).toLowerCase();
	const post = await requestUtils.rest< {
		id: number;
		link: string;
		slug: string;
	} >( {
		path: '/wp/v2/posts',
		method: 'POST',
		data: { title, content, status: 'publish', slug, excerpt },
	} );
	return { id: post.id, link: post.link, slug: post.slug };
}

/**
 * Path portion of a permalink, relative to the site root.
 *
 * @param link Absolute permalink.
 */
export function pathOf( link: string ): string {
	const url = new URL( link );
	return url.pathname + url.search;
}

/**
 * Split a comma-separated header value into trimmed tokens.
 *
 * @param value Header value, or undefined when absent.
 */
export function tokens( value: string | undefined ): string[] {
	return value
		? value
				.split( ',' )
				.map( ( token ) => token.trim() )
				.filter( Boolean )
		: [];
}
