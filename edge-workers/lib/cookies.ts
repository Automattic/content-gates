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

import { parseHttpDate, parseNonNegativeInt } from './httpdate.ts';

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
 * A `Set-Cookie` header value split into its parts.
 */
export class SetCookieParts {
	name: string = '';
	value: string = '';
	/** Everything after the first `;`, trimmed; empty when none. */
	attributes: string = '';
}

/**
 * Split a `Set-Cookie` value into name, value, and attribute string.
 *
 * @param header One `Set-Cookie` header value.
 * @return The parts, or null when there is no `name=`.
 */
export function parseSetCookie( header: string ): SetCookieParts | null {
	const semi = header.indexOf( ';' );
	const pair = semi < 0 ? header : header.substring( 0, semi );
	const eq = pair.indexOf( '=' );
	if ( eq < 0 ) {
		return null;
	}
	const parts = new SetCookieParts();
	parts.name = pair.substring( 0, eq ).trim();
	parts.value = pair.substring( eq + 1 ).trim();
	parts.attributes = semi < 0 ? '' : header.substring( semi + 1 ).trim();
	return parts.name.length == 0 ? null : parts;
}

/**
 * Whether a `Set-Cookie` removes the cookie rather than setting it.
 *
 * `Max-Age` of zero or less removes, and wins over `Expires` when both are
 * present (RFC 6265 section 5.3). Otherwise an `Expires` at or before
 * `now` removes. A malformed attribute is ignored, as a browser would.
 *
 * @param attributes Attribute string from `parseSetCookie()`.
 * @param now        Epoch seconds; negative when unknown, which disables the `Expires` test.
 * @return True when the cookie is being removed.
 */
export function expiresCookie( attributes: string, now: f64 ): bool {
	const parts = attributes.split( ';' );
	let expiresAt: f64 = -1;
	for ( let i = 0; i < parts.length; i++ ) {
		const part = parts[ i ].trim();
		const eq = part.indexOf( '=' );
		if ( eq < 0 ) {
			continue;
		}
		const key = part.substring( 0, eq ).trim().toLowerCase();
		const raw = part.substring( eq + 1 ).trim();
		if ( key == 'max-age' ) {
			if ( raw.startsWith( '-' ) ) {
				return true;
			}
			const seconds = parseNonNegativeInt( raw );
			if ( seconds >= 0 ) {
				return seconds == 0;
			}
		} else if ( key == 'expires' ) {
			expiresAt = parseHttpDate( raw );
		}
	}
	return expiresAt >= 0 && now >= 0 && expiresAt <= now;
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
