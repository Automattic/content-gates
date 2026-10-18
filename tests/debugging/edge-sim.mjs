#!/usr/bin/env node
/* eslint-disable no-console -- This is a CLI; console is its output. */
/**
 * Local simulation of the WPVIP edge cache, for cache-key correctness tests.
 *
 * A small HTTP proxy in front of a WordPress origin. It mimics the parts of
 * VIP's page cache that matter to content gating: which requests bypass the
 * cache, how the `vip-go-seg` cookie becomes the `X-VIP-Go-Segmentation`
 * request header, and how a stored copy is keyed on the segments the origin
 * reports it consulted (`X-Content-Gates-Vary`), following RFC 9111 section
 * 4.1 per-stored-response Vary matching.
 *
 * Read tests/debugging/README.md for usage and the list of variances from
 * the real platform. Nothing here ships with the plugin.
 */

import http from 'node:http';
import { fileURLToPath } from 'node:url';

/** Name of the segmentation cookie written by `Vary_Cache`. */
export const SEGMENT_COOKIE = 'vip-go-seg';

/** Cookie format version prefix. */
export const COOKIE_PREFIX = 'vc-v1__';

/** Request header the CDN derives from the segmentation cookie. */
export const SEGMENTATION_HEADER = 'x-vip-go-segmentation';

/**
 * Response headers that carry the consulted set, in order of preference.
 * The plural form is what this plugin sends; the singular form is the draft
 * spelling in the spec and is accepted so both agree during the prototype.
 */
export const VARY_HEADERS = [ 'x-content-gates-vary', 'x-content-gate-vary' ];

/** Default TTL in seconds when the origin sends no max-age. */
export const DEFAULT_TTL = 300;

/** Value used in a variant key for a consulted segment the reader lacks. */
export const NONE = 'none';

const NOCACHE_COOKIE_PREFIXES = [
	'wordpress_logged_in_',
	'wp-postpass_',
	'comment_author_',
	'vip-go-cb',
];

const PASS_PATH_PREFIXES = [
	'/wp-admin/',
	'/wp-login.php',
	'/wp-json/',
	'/wp-cron.php',
];

const HOP_BY_HOP = new Set( [
	'connection',
	'keep-alive',
	'proxy-authenticate',
	'proxy-authorization',
	'te',
	'trailer',
	'transfer-encoding',
	'upgrade',
] );

const TOKEN = /^[A-Za-z0-9_-]+$/;

/**
 * Parse a `vip-go-seg` cookie value into a membership map.
 *
 * Format: `vc-v1__<group>_--_<value>---__<group>_--_<value>`. Pairs with an
 * empty or invalid name or value are dropped, which matches how `Vary_Cache`
 * removes a reader from a group. An unknown version prefix yields no
 * membership at all rather than a guess.
 *
 * @param {string|undefined|null} value Raw cookie value.
 * @return {Object<string,string>} Group name to value.
 */
export function parseSegmentCookie( value ) {
	const membership = {};
	if ( typeof value !== 'string' ) {
		return membership;
	}
	let raw = value.trim();
	try {
		raw = decodeURIComponent( raw );
	} catch {
		// Keep the raw value; it is probably not encoded.
	}
	if ( ! raw.startsWith( COOKIE_PREFIX ) ) {
		return membership;
	}
	for ( const pair of raw.slice( COOKIE_PREFIX.length ).split( '---__' ) ) {
		const [ group, groupValue, ...rest ] = pair.split( '_--_' );
		if (
			rest.length > 0 ||
			! group ||
			! groupValue ||
			! TOKEN.test( group ) ||
			! TOKEN.test( groupValue )
		) {
			continue;
		}
		membership[ group ] = groupValue;
	}
	return membership;
}

/**
 * Restrict membership to the consulted set, filling gaps with `none`.
 *
 * @param {string[]}              consulted  Segment names the render read.
 * @param {Object<string,string>} membership Reader's full membership.
 * @return {Object<string,string>} Variant key, sorted by segment name.
 */
export function computeVariantKey( consulted, membership ) {
	const key = {};
	for ( const group of [ ...consulted ].sort() ) {
		key[ group ] = Object.hasOwn( membership, group )
			? membership[ group ]
			: NONE;
	}
	return key;
}

/**
 * Whether a stored entry may be served to a reader with this membership.
 *
 * Per RFC 9111 section 4.1, only the segments the entry itself reported as
 * consulted are compared. A legacy entry (keyed on the whole cookie, the
 * status quo `Vary: X-VIP-Go-Segmentation` behavior) matches only when the
 * reader's full membership is identical.
 *
 * @param {{consulted: string[], variantKey: Object<string,string>, legacy?: boolean}} entry      Stored entry.
 * @param {Object<string,string>}                                                      membership Reader's membership.
 * @return {boolean} True on a match.
 */
export function matches( entry, membership ) {
	if ( entry.legacy ) {
		const mine = Object.keys( membership ).sort();
		const theirs = [ ...entry.consulted ].sort();
		if ( mine.length !== theirs.length ) {
			return false;
		}
		if ( mine.some( ( group, i ) => group !== theirs[ i ] ) ) {
			return false;
		}
	}
	for ( const group of entry.consulted ) {
		const mine = Object.hasOwn( membership, group )
			? membership[ group ]
			: NONE;
		if ( mine !== entry.variantKey[ group ] ) {
			return false;
		}
	}
	return true;
}

/**
 * Format a variant key for the `X-Edge-Sim-Key` header.
 *
 * @param {Object<string,string>} variantKey Variant key.
 * @return {string} `group=value, group=value` or `{}` when empty.
 */
export function formatKey( variantKey ) {
	const parts = Object.keys( variantKey )
		.sort()
		.map( ( group ) => `${ group }=${ variantKey[ group ] }` );
	return parts.length ? parts.join( ', ' ) : '{}';
}

/**
 * Whether two variant keys are identical.
 *
 * @param {Object<string,string>} a First key.
 * @param {Object<string,string>} b Second key.
 * @return {boolean} True when equal.
 */
function sameKey( a, b ) {
	return formatKey( a ) === formatKey( b );
}

/**
 * Create an in-memory cache store keyed by URL (path + query).
 *
 * Each URL holds a list of stored responses in insertion order. Lookup
 * returns the first entry whose consulted segments match the reader; this
 * first-match rule is a simplification and is documented in the README.
 *
 * @param {{now?: () => number}} [options] Clock override for tests.
 * @return {Object} Store API.
 */
export function createStore( options = {} ) {
	const now = options.now ?? Date.now;
	/** @type {Map<string, Array<Object>>} */
	const urls = new Map();
	const counters = { hits: 0, misses: 0, passes: 0 };

	/**
	 * Whether an entry is still fresh.
	 *
	 * @param {Object} entry Stored entry.
	 * @return {boolean} True when fresh.
	 */
	function fresh( entry ) {
		return entry.storedAt + entry.maxAge * 1000 > now();
	}

	return {
		counters,

		/**
		 * Find a fresh stored response for the reader, or null.
		 *
		 * @param {string}                url        Path and query.
		 * @param {Object<string,string>} membership Reader's membership.
		 * @return {Object|null} Entry or null.
		 */
		lookup( url, membership ) {
			const entries = urls.get( url );
			if ( ! entries ) {
				return null;
			}
			const live = entries.filter( fresh );
			if ( live.length === 0 ) {
				urls.delete( url );
				return null;
			}
			if ( live.length !== entries.length ) {
				urls.set( url, live );
			}
			for ( const entry of live ) {
				if ( matches( entry, membership ) ) {
					entry.hitCount += 1;
					return entry;
				}
			}
			return null;
		},

		/**
		 * Store a response, replacing any entry with the same key.
		 *
		 * @param {string} url   Path and query.
		 * @param {Object} entry Entry fields (consulted, variantKey, legacy,
		 *                       status, headers, body, maxAge).
		 * @return {Object} The stored entry.
		 */
		put( url, entry ) {
			const stored = {
				legacy: false,
				hitCount: 0,
				storedAt: now(),
				maxAge: DEFAULT_TTL,
				...entry,
			};
			const entries = urls.get( url ) ?? [];
			const index = entries.findIndex(
				( existing ) =>
					existing.legacy === stored.legacy &&
					sameKey( existing.variantKey, stored.variantKey ) &&
					existing.consulted.join( ',' ) ===
						stored.consulted.join( ',' )
			);
			if ( index === -1 ) {
				entries.push( stored );
			} else {
				entries[ index ] = stored;
			}
			urls.set( url, entries );
			return stored;
		},

		/**
		 * Remove stored responses for one URL, or all of them.
		 *
		 * @param {string} [url] Path and query; omit to purge everything.
		 * @return {number} Number of entries removed.
		 */
		purge( url ) {
			let count = 0;
			if ( url === undefined ) {
				for ( const entries of urls.values() ) {
					count += entries.length;
				}
				urls.clear();
				return count;
			}
			count = urls.get( url )?.length ?? 0;
			urls.delete( url );
			return count;
		},

		/**
		 * Clear the store and the counters.
		 */
		reset() {
			urls.clear();
			counters.hits = 0;
			counters.misses = 0;
			counters.passes = 0;
		},

		/**
		 * Snapshot for the stats endpoint.
		 *
		 * @return {Object} Counters and per-URL entries.
		 */
		stats() {
			const out = {};
			for ( const [ url, entries ] of urls ) {
				out[ url ] = entries.map( ( entry ) => ( {
					consulted: entry.consulted,
					variantKey: entry.variantKey,
					legacy: entry.legacy,
					storedAt: new Date( entry.storedAt ).toISOString(),
					maxAge: entry.maxAge,
					hitCount: entry.hitCount,
				} ) );
			}
			return { ...counters, urls: out };
		},
	};
}

/**
 * Parse a Cache-Control header into the directives this simulator honors.
 *
 * @param {string|null|undefined} header Header value.
 * @return {{cacheable: boolean, maxAge: number|null}} Result.
 */
export function parseCacheControl( header ) {
	const result = { cacheable: true, maxAge: null };
	if ( ! header ) {
		return result;
	}
	for ( const raw of header.split( ',' ) ) {
		const [ name, value ] = raw.trim().toLowerCase().split( '=' );
		if ( [ 'no-store', 'no-cache', 'private' ].includes( name ) ) {
			result.cacheable = false;
		}
		if ( name === 'max-age' ) {
			const seconds = Number.parseInt( value, 10 );
			if ( Number.isNaN( seconds ) || seconds <= 0 ) {
				result.cacheable = false;
			} else {
				result.maxAge = seconds;
			}
		}
	}
	return result;
}

/**
 * Derive the consulted set from an origin response's headers.
 *
 * `X-Content-Gates-Vary` wins when present, even if the broad legacy
 * `Vary: X-VIP-Go-Segmentation` is also present. That is what the spec asks
 * the edge to do. Without the custom header, a legacy `Vary` keys on every
 * group in the cookie.
 *
 * @param {Headers}               headers    Origin response headers.
 * @param {Object<string,string>} membership Reader's membership.
 * @return {{consulted: string[], legacy: boolean}} Result.
 */
export function consultedFromResponse( headers, membership ) {
	for ( const name of VARY_HEADERS ) {
		const value = headers.get( name );
		if ( value !== null ) {
			const consulted = value
				.split( ',' )
				.map( ( s ) => s.trim() )
				.filter( Boolean )
				.sort();
			return { consulted: [ ...new Set( consulted ) ], legacy: false };
		}
	}
	const vary = ( headers.get( 'vary' ) ?? '' )
		.split( ',' )
		.map( ( s ) => s.trim().toLowerCase() );
	if ( vary.includes( SEGMENTATION_HEADER ) ) {
		return { consulted: Object.keys( membership ).sort(), legacy: true };
	}
	return { consulted: [], legacy: false };
}

/**
 * Read one cookie from a Cookie request header.
 *
 * @param {string|undefined} header Cookie header.
 * @param {string}           name   Cookie name.
 * @return {string|undefined} Value or undefined.
 */
function readCookie( header, name ) {
	if ( ! header ) {
		return undefined;
	}
	for ( const part of header.split( ';' ) ) {
		const eq = part.indexOf( '=' );
		if ( eq === -1 ) {
			continue;
		}
		if ( part.slice( 0, eq ).trim() === name ) {
			return part.slice( eq + 1 ).trim();
		}
	}
	return undefined;
}

/**
 * Why a request must bypass the cache, or null when it is cacheable.
 *
 * @param {http.IncomingMessage} req Request.
 * @return {string|null} Reason.
 */
export function passReason( req ) {
	if ( req.method !== 'GET' && req.method !== 'HEAD' ) {
		return `method ${ req.method }`;
	}
	const path = req.url.split( '?' )[ 0 ];
	for ( const prefix of PASS_PATH_PREFIXES ) {
		if (
			path === prefix.replace( /\/$/, '' ) ||
			path.startsWith( prefix )
		) {
			return `path ${ prefix }`;
		}
	}
	if ( req.headers.authorization ) {
		return 'authorization header';
	}
	const cookie = req.headers.cookie ?? '';
	for ( const part of cookie.split( ';' ) ) {
		const name = part.split( '=' )[ 0 ].trim();
		for ( const prefix of NOCACHE_COOKIE_PREFIXES ) {
			if ( name.startsWith( prefix ) ) {
				return `cookie ${ name }`;
			}
		}
	}
	return null;
}

/**
 * Build the headers to send to origin.
 *
 * @param {http.IncomingMessage} req Request.
 * @return {Headers} Headers.
 */
function originHeaders( req ) {
	const headers = new Headers();
	for ( const [ name, value ] of Object.entries( req.headers ) ) {
		if ( HOP_BY_HOP.has( name ) || name === 'host' ) {
			continue;
		}
		if ( name === 'accept-encoding' || name === 'content-length' ) {
			continue;
		}
		headers.set(
			name,
			Array.isArray( value ) ? value.join( ', ' ) : value
		);
	}
	headers.set( 'accept-encoding', 'identity' );
	headers.set( 'x-forwarded-host', req.headers.host ?? '' );
	const seg = readCookie( req.headers.cookie, SEGMENT_COOKIE );
	if ( seg !== undefined ) {
		headers.set( SEGMENTATION_HEADER, seg );
	}
	return headers;
}

/**
 * Forward a request to the origin and collect the full response.
 *
 * @param {string}               origin Origin base URL.
 * @param {http.IncomingMessage} req    Request.
 * @return {Promise<{status: number, headers: Headers, setCookie: string[], body: Buffer}>} Response.
 */
async function fetchOrigin( origin, req ) {
	const init = {
		method: req.method,
		headers: originHeaders( req ),
		redirect: 'manual',
	};
	if ( req.method !== 'GET' && req.method !== 'HEAD' ) {
		init.body = req;
		init.duplex = 'half';
	}
	const response = await fetch( new URL( req.url, origin ), init );
	return {
		status: response.status,
		headers: response.headers,
		setCookie: response.headers.getSetCookie(),
		body: Buffer.from( await response.arrayBuffer() ),
	};
}

/**
 * Convert origin headers into a Node response header object.
 *
 * @param {Headers}  headers   Origin headers.
 * @param {string[]} setCookie Set-Cookie values.
 * @return {Object<string, string|string[]>} Headers.
 */
function clientHeaders( headers, setCookie ) {
	const out = {};
	for ( const [ name, value ] of headers ) {
		if (
			HOP_BY_HOP.has( name ) ||
			name === 'set-cookie' ||
			name === 'content-encoding' ||
			name === 'content-length'
		) {
			continue;
		}
		out[ name ] = value;
	}
	if ( setCookie.length ) {
		out[ 'set-cookie' ] = setCookie;
	}
	return out;
}

/**
 * Write a buffered response to the client.
 *
 * @param {http.ServerResponse} res     Response.
 * @param {string}              method  Request method.
 * @param {number}              status  Status code.
 * @param {Object}              headers Header object.
 * @param {Buffer}              body    Body.
 */
function send( res, method, status, headers, body ) {
	res.writeHead( status, { ...headers, 'content-length': body.length } );
	res.end( method === 'HEAD' ? undefined : body );
}

/**
 * Write a JSON response.
 *
 * @param {http.ServerResponse} res    Response.
 * @param {number}              status Status code.
 * @param {Object}              data   Payload.
 */
function sendJson( res, status, data ) {
	const body = Buffer.from( JSON.stringify( data, null, 2 ) );
	send( res, 'GET', status, { 'content-type': 'application/json' }, body );
}

/**
 * Create the proxy server. The store is exposed for tests.
 *
 * @param {{origin: string, verbose?: boolean, store?: Object}} options Options.
 * @return {{server: http.Server, store: Object}} Server and store.
 */
export function createServer( options ) {
	const { origin, verbose = false } = options;
	const store = options.store ?? createStore();

	/**
	 * Log a line when verbose.
	 *
	 * @param {...string} parts Parts.
	 */
	function log( ...parts ) {
		if ( verbose ) {
			console.log( parts.join( ' ' ) );
		}
	}

	/**
	 * Handle a `/__edge-sim/*` control request.
	 *
	 * @param {http.IncomingMessage} req Request.
	 * @param {http.ServerResponse}  res Response.
	 */
	function control( req, res ) {
		const url = new URL( req.url, 'http://edge-sim' );
		if ( req.method === 'GET' && url.pathname === '/__edge-sim/stats' ) {
			sendJson( res, 200, store.stats() );
			return;
		}
		if ( req.method === 'POST' && url.pathname === '/__edge-sim/purge' ) {
			const target = url.searchParams.get( 'url' ) ?? undefined;
			sendJson( res, 200, { purged: store.purge( target ) } );
			return;
		}
		if ( req.method === 'POST' && url.pathname === '/__edge-sim/reset' ) {
			store.reset();
			sendJson( res, 200, { reset: true } );
			return;
		}
		sendJson( res, 404, { error: 'unknown control endpoint' } );
	}

	/**
	 * Handle one proxied request.
	 *
	 * @param {http.IncomingMessage} req Request.
	 * @param {http.ServerResponse}  res Response.
	 */
	async function handle( req, res ) {
		if ( req.url.startsWith( '/__edge-sim/' ) ) {
			control( req, res );
			return;
		}

		const reason = passReason( req );
		if ( reason ) {
			store.counters.passes += 1;
			const upstream = await fetchOrigin( origin, req );
			log( 'PASS', req.method, req.url, `(${ reason })` );
			send(
				res,
				req.method,
				upstream.status,
				{
					...clientHeaders( upstream.headers, upstream.setCookie ),
					'x-edge-sim': 'PASS',
					'x-edge-sim-reason': reason,
				},
				upstream.body
			);
			return;
		}

		const membership = parseSegmentCookie(
			readCookie( req.headers.cookie, SEGMENT_COOKIE )
		);
		const hit = store.lookup( req.url, membership );
		if ( hit ) {
			store.counters.hits += 1;
			const key = formatKey( hit.variantKey );
			log( 'HIT ', req.method, req.url, key );
			send(
				res,
				req.method,
				hit.status,
				{
					...hit.headers,
					age: String(
						Math.floor( ( Date.now() - hit.storedAt ) / 1000 )
					),
					'x-edge-sim': 'HIT',
					'x-edge-sim-key': key,
				},
				hit.body
			);
			return;
		}

		store.counters.misses += 1;
		const upstream = await fetchOrigin( origin, req );
		const { consulted, legacy } = consultedFromResponse(
			upstream.headers,
			membership
		);
		const variantKey = computeVariantKey( consulted, membership );
		const key = formatKey( variantKey );
		const headers = clientHeaders( upstream.headers, upstream.setCookie );
		const cacheControl = parseCacheControl(
			upstream.headers.get( 'cache-control' )
		);
		const storable =
			req.method === 'GET' &&
			upstream.status === 200 &&
			upstream.setCookie.length === 0 &&
			cacheControl.cacheable;
		if ( storable ) {
			store.put( req.url, {
				consulted,
				variantKey,
				legacy,
				status: upstream.status,
				headers,
				body: upstream.body,
				maxAge: cacheControl.maxAge ?? DEFAULT_TTL,
			} );
		}
		log(
			'MISS',
			req.method,
			req.url,
			key,
			storable ? '(stored)' : '(not stored)',
			legacy ? '(legacy vary)' : ''
		);
		send(
			res,
			req.method,
			upstream.status,
			{
				...headers,
				'x-edge-sim': 'MISS',
				'x-edge-sim-key': key,
				'x-edge-sim-stored': storable ? 'yes' : 'no',
			},
			upstream.body
		);
	}

	const server = http.createServer( ( req, res ) => {
		handle( req, res ).catch( ( error ) => {
			console.error(
				`edge-sim: ${ req.method } ${ req.url }: ${ error.message }`
			);
			if ( ! res.headersSent ) {
				sendJson( res, 502, {
					error: 'origin request failed',
					detail: error.message,
				} );
			} else {
				res.destroy();
			}
		} );
	} );

	return { server, store };
}

/**
 * Parse CLI arguments.
 *
 * @param {string[]} argv Arguments after the script name.
 * @return {{origin: string, port: number, verbose: boolean}} Options.
 */
export function parseArgs( argv ) {
	const options = {
		origin: 'http://localhost:8889',
		port: 8891,
		verbose: false,
	};
	for ( let i = 0; i < argv.length; i += 1 ) {
		const arg = argv[ i ];
		if ( arg === '--origin' ) {
			options.origin = argv[ ++i ];
		} else if ( arg.startsWith( '--origin=' ) ) {
			options.origin = arg.slice( '--origin='.length );
		} else if ( arg === '--port' ) {
			options.port = Number( argv[ ++i ] );
		} else if ( arg.startsWith( '--port=' ) ) {
			options.port = Number( arg.slice( '--port='.length ) );
		} else if ( arg === '--verbose' || arg === '-v' ) {
			options.verbose = true;
		} else {
			throw new Error( `Unknown argument: ${ arg }` );
		}
	}
	if ( ! options.origin || ! /^https?:\/\//.test( options.origin ) ) {
		throw new Error( '--origin must be an http(s) URL' );
	}
	if ( ! Number.isInteger( options.port ) || options.port <= 0 ) {
		throw new Error( '--port must be a positive integer' );
	}
	options.origin = options.origin.replace( /\/+$/, '' );
	return options;
}

const isMain =
	process.argv[ 1 ] && fileURLToPath( import.meta.url ) === process.argv[ 1 ];

if ( isMain ) {
	let options;
	try {
		options = parseArgs( process.argv.slice( 2 ) );
	} catch ( error ) {
		console.error( error.message );
		console.error(
			'Usage: node tests/debugging/edge-sim.mjs [--origin <url>] [--port <n>] [--verbose]'
		);
		process.exit( 2 );
	}
	const { server } = createServer( options );
	server.listen( options.port, '127.0.0.1', () => {
		console.log(
			`edge-sim listening on http://127.0.0.1:${ options.port }`
		);
		console.log( `origin: ${ options.origin }` );
		console.log(
			`stats: http://127.0.0.1:${ options.port }/__edge-sim/stats`
		);
	} );
	for ( const signal of [ 'SIGINT', 'SIGTERM' ] ) {
		process.on( signal, () => {
			server.close();
			process.exit( 0 );
		} );
	}
}
