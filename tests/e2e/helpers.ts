import { request, type APIRequestContext } from '@playwright/test';

/**
 * Markers written into the seeded demo posts by `wp content-gates-dev seed`
 * (see tests/fixtures/mu-plugins/content-gates-dev/class-cli.php). Specs
 * assert on these bytes, not on visual output.
 */
export const SEED = {
	gatedArticle: '/gated-article/',
	meteredArticle: '/metered-article/',
	meteredArticle2: '/metered-article-2/',
	meteredArticle3: '/metered-article-3/',
	nestedGates: '/nested-gates/',
	newsletterPerk: '/newsletter-perk/',
	publicPost: '/public-post/',
	subscriberOnly: 'SUBSCRIBER-ONLY',
	subscribePrompt: 'Not a subscriber yet?',
	meteredBody: 'METERED-BODY',
	meteredWall: 'METERED-WALL',
	subscriberLayer: 'SUBSCRIBER-LAYER',
	donorLayer: 'DONOR-LAYER',
	perkBody: 'PERK-BODY',
};

/** Response header names the plugin sends. */
export const HEADERS = {
	declared: 'x-wpvip-content-gates-declared',
	meter: 'x-wpvip-content-gates-meter',
	vary: 'vary',
};

/**
 * A request context with no cookies, like a first-time anonymous reader.
 *
 * Redirects are not followed so Set-Cookie and 3xx responses stay visible.
 *
 * @param baseURL Site base URL.
 */
export async function anonymous(
	baseURL: string
): Promise< APIRequestContext > {
	return request.newContext( { baseURL, maxRedirects: 0 } );
}

/**
 * A request context that always sends a reader cookie.
 *
 * @param baseURL Site base URL.
 * @param groups  Group name to value, serialized in Vary_Cache format.
 */
export async function reader(
	baseURL: string,
	groups: Record< string, string >
): Promise< APIRequestContext > {
	return request.newContext( {
		baseURL,
		maxRedirects: 0,
		extraHTTPHeaders: { Cookie: `vip-go-seg=${ serialize( groups ) }` },
	} );
}

/**
 * Serialize groups the way Vary_Cache writes the cookie.
 *
 * @param groups Group name to value.
 */
export function serialize( groups: Record< string, string > ): string {
	const pairs = Object.keys( groups )
		.sort()
		.map( ( name ) => `${ name }_--_${ groups[ name ] }` );
	return 'vc-v1__' + pairs.join( '---__' );
}

/**
 * Parse a `vip-go-seg` value from a Set-Cookie header list.
 *
 * @param setCookie Set-Cookie header values.
 */
export function segFromSetCookie( setCookie: string[] ): string | null {
	for ( const line of setCookie ) {
		const match = line.match( /^vip-go-seg=([^;]*)/ );
		if ( match ) {
			return match[ 1 ];
		}
	}
	return null;
}

/**
 * Split a response's Set-Cookie header into one entry per cookie.
 *
 * @param headers Header map from `response.headersArray()`.
 */
export function setCookies(
	headers: { name: string; value: string }[]
): string[] {
	return headers
		.filter( ( h ) => h.name.toLowerCase() === 'set-cookie' )
		.map( ( h ) => h.value );
}
