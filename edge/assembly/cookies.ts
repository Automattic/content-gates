/**
 * Cookie header helpers shared by the worker and the simulator.
 *
 * The SDK has a `Cookies` reader, but it is AssemblyScript-only and
 * read-only. The worker also has to rewrite the `Cookie` header it forwards
 * to origin, and the simulator needs identical behaviour under Node, so the
 * few operations needed live here, dependency-free.
 *
 * Portability rules: see segmentation.ts.
 */

/**
 * First value of cookie `name` in a `Cookie` request header.
 *
 * First occurrence wins, matching the SDK and browsers, which send the most
 * specific path first.
 *
 * @param header Raw `Cookie` header, or null.
 * @param name   Cookie name, case-sensitive.
 * @return The value, or null when absent.
 */
export function getCookie(
	header: string | null,
	name: string
): string | null {
	if ( header === null ) {
		return null;
	}
	const pairs = header.split( ';' );
	for ( let i = 0; i < pairs.length; i++ ) {
		const pair = pairs[ i ];
		const eq = pair.indexOf( '=' );
		if ( eq < 0 ) {
			continue;
		}
		if ( pair.substring( 0, eq ).trim() == name ) {
			return pair.substring( eq + 1 ).trim();
		}
	}
	return null;
}

/**
 * Return `header` with the `name` cookie replaced by `value` in place (or
 * appended when absent), or removed when `value` is null. Duplicates of
 * `name` are dropped.
 *
 * @param header Raw `Cookie` header, or null.
 * @param name   Cookie name.
 * @param value  New value, or null to remove the cookie.
 * @return The rewritten header; empty when no cookies remain.
 */
export function withCookie(
	header: string | null,
	name: string,
	value: string | null
): string {
	const kept: string[] = [];
	let replaced = false;
	if ( header !== null ) {
		const pairs = header.split( ';' );
		for ( let i = 0; i < pairs.length; i++ ) {
			const pair = pairs[ i ].trim();
			if ( pair.length == 0 ) {
				continue;
			}
			const eq = pair.indexOf( '=' );
			const pairName = eq < 0 ? pair : pair.substring( 0, eq ).trim();
			if ( pairName != name ) {
				kept.push( pair );
				continue;
			}
			// Keep the first occurrence in place; drop later duplicates.
			if ( value !== null && ! replaced ) {
				kept.push( name + '=' + value );
				replaced = true;
			}
		}
	}
	if ( value !== null && ! replaced ) {
		kept.push( name + '=' + value );
	}
	return kept.join( '; ' );
}

/**
 * Build a `Set-Cookie` value.
 *
 * `Path=/` and a `Max-Age` mirror what `Vary_Cache` sends for `vip-go-seg`
 * (it uses `COOKIEPATH`, which is `/` on VIP, and an expiry rather than a
 * session cookie). No `Domain`, `Secure`, or `SameSite`: `Vary_Cache` sets
 * none, and the worker should not make its cookie stricter than origin's
 * copy of the same cookie or the two writers would disagree.
 *
 * @param name     Cookie name.
 * @param value    Cookie value, already cookie-safe.
 * @param maxAge   Lifetime in seconds.
 * @param httpOnly Whether scripts must not read it.
 * @return The `Set-Cookie` header value.
 */
export function buildSetCookie(
	name: string,
	value: string,
	maxAge: i32,
	httpOnly: bool
): string {
	let out = name + '=' + value + '; Path=/; Max-Age=' + maxAge.toString();
	if ( httpOnly ) {
		out = out + '; HttpOnly';
	}
	return out;
}
