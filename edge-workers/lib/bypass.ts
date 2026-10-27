/**
 * Requests the worker leaves alone.
 *
 * `worker.json` has no `location`, so the platform invokes phase 1 on every
 * request: narrowing cannot be scoped by path, because any URL may serve
 * gated content. The platform's only pre-invocation condition is one path
 * rule with no negation, so the "except" list lives here instead.
 *
 * A bypassed request skips the KV read and every rewrite. That is safe
 * because none of these responses can be stored under a segmented key:
 * static files never carry `Vary: X-VIP-Go-Segmentation`, and a shared
 * cache never stores responses to non-GET requests, authenticated
 * requests, or logged-in sessions. Phase 1 still sends the edge version
 * header, so origin can tell the worker ran, and marks the lookup as
 * `bypass`, so phase 3 refuses to store anything that arrives with that
 * mark (fail closed, as for a missing marker). Phase 4 skips metering for
 * the same requests.
 *
 * Portability rules: see segmentation.ts.
 */

/** Paths that never serve cacheable, segmented page content. */
export const BYPASS_PATH_PREFIXES: string[] = [
	'/wp-admin/',
	'/wp-login.php',
	'/wp-cron.php',
	'/xmlrpc.php',
	'/wp-content/',
	'/wp-includes/',
];

/** Cookie name fragments that mark a session the cache never stores. */
export const BYPASS_COOKIE_MARKERS: string[] = [
	'wordpress_logged_in_',
	'wp-postpass_',
	'comment_author_',
	'vip-go-cb',
];

/** Extensions of static files, which never carry the segmentation `Vary`. */
export const STATIC_EXTENSIONS: string[] = [
	'.css',
	'.js',
	'.mjs',
	'.map',
	'.png',
	'.jpg',
	'.jpeg',
	'.gif',
	'.webp',
	'.avif',
	'.svg',
	'.ico',
	'.woff',
	'.woff2',
	'.ttf',
	'.otf',
	'.eot',
	'.mp4',
	'.webm',
	'.mp3',
	'.pdf',
	'.txt',
	'.zip',
];

/**
 * Why the worker should leave a request alone, or empty to handle it.
 *
 * @param method           Request method.
 * @param url              Origin-relative request target (path and query).
 * @param cookie           Raw `Cookie` header, or null.
 * @param hasAuthorization Whether the request carries `Authorization`.
 * @return `method`, `authorization`, `path`, `static`, `session`, or ``.
 */
export function bypassReason(
	method: string,
	url: string,
	cookie: string | null,
	hasAuthorization: bool
): string {
	if ( method != 'GET' && method != 'HEAD' ) {
		return 'method';
	}
	if ( hasAuthorization ) {
		return 'authorization';
	}
	const q = url.indexOf( '?' );
	const path = ( q < 0 ? url : url.substring( 0, q ) ).toLowerCase();
	for ( let i = 0; i < BYPASS_PATH_PREFIXES.length; i++ ) {
		if ( path.startsWith( BYPASS_PATH_PREFIXES[ i ] ) ) {
			return 'path';
		}
	}
	for ( let i = 0; i < STATIC_EXTENSIONS.length; i++ ) {
		if ( path.endsWith( STATIC_EXTENSIONS[ i ] ) ) {
			return 'static';
		}
	}
	if ( cookie !== null ) {
		for ( let i = 0; i < BYPASS_COOKIE_MARKERS.length; i++ ) {
			if ( cookie.includes( BYPASS_COOKIE_MARKERS[ i ] ) ) {
				return 'session';
			}
		}
	}
	return '';
}
