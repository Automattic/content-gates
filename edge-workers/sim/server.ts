/**
 * Local emulation of the WPVIP page cache plus the Content Gates worker.
 *
 * A dependency-free HTTP proxy that sits in front of a WordPress origin
 * (wp-env by default) and runs the same pure phase logic the real worker
 * runs (lib/worker.ts), around an in-memory cache and KV store.
 * It exists so the plugin's cache behaviour can be exercised locally and
 * in Playwright without a VIP deployment.
 *
 * Everything the simulator does that WPVIP might do differently is listed
 * under "Variances from WPVIP" in docs/edge-worker.md. Keep that list in
 * sync with this file.
 *
 * Runs on Node's native type stripping: types only, no enums, no
 * parameter properties.
 */

import { createHash, createHmac } from 'node:crypto';
import http from 'node:http';
import https from 'node:https';
import type { AddressInfo } from 'node:net';
import { Crypto } from '../lib/crypto.ts';
import { decodeRecord } from '../lib/declared.ts';
import { responseTime } from '../lib/httpdate.ts';
import {
	COVERAGE_HEADER,
	CRAWLER_HEADER,
	ClientRequestInput,
	ClientResponseInput,
	DECLARED_HEADER,
	EDGE_VERSION_HEADER,
	KEY_HEADER,
	LOOKUP_HEADER,
	METER_DIAGNOSTIC_HEADER,
	METER_HEADER,
	OriginResponseInput,
	RECORD_HEADER,
	SEGMENTATION_HEADER,
	kvKeyFor,
	meterDiagnostic,
	onClientRequestPure,
	onClientResponsePure,
	onOriginResponsePure,
	variesOnSegmentation,
} from '../lib/worker.ts';

export interface EdgeSimOptions {
	/** Origin base URL. Default http://localhost:8888. */
	origin?: string;
	/** Listen port. 0 picks a free port. Default 8890. */
	port?: number;
	/** Listen host. Default 127.0.0.1. */
	host?: string;
	/** Meter cookie signing secret. Default `local-dev-secret`. */
	secret?: string;
	/** Line logger for per-request traces; omit for silence. */
	log?: ( line: string ) => void;
}

export interface EdgeSim {
	/** Base URL of the simulator, e.g. http://127.0.0.1:8890. */
	url: string;
	/** Stop listening and drop all state. */
	close: () => Promise< void >;
}

/** Header pairs, names lowercased, duplicates preserved (like the SDK). */
type HeaderList = Array< [ string, string ] >;

interface CacheEntry {
	status: number;
	headers: HeaderList;
	body: Buffer;
	/** Whether the stored response varies on the segmentation header. */
	varies: boolean;
	/** Segmentation value the entry was stored under; '' when not varying. */
	segmentation: string;
	storedAt: number;
	expiresAt: number;
}

interface OriginResult {
	status: number;
	headers: HeaderList;
	body: Buffer;
}

const DEFAULT_TTL_SECONDS = 300;
const PASS_PATH_PREFIXES = [ '/wp-admin/', '/wp-login.php', '/wp-cron.php' ];
const PASS_COOKIE_MARKERS = [
	'wordpress_logged_in_',
	'wp-postpass_',
	'comment_author_',
	'vip-go-cb',
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

const nodeCrypto = new Crypto(
	( input: string ) => createHash( 'sha256' ).update( input ).digest( 'hex' ),
	( secret: string, message: string ) =>
		createHmac( 'sha256', secret ).update( message ).digest( 'hex' )
);

/**
 * Convert Node's rawHeaders array into lowercase pairs.
 *
 * @param raw `IncomingMessage.rawHeaders`.
 * @return Header pairs.
 */
function toHeaderList( raw: string[] ): HeaderList {
	const out: HeaderList = [];
	for ( let i = 0; i + 1 < raw.length; i += 2 ) {
		out.push( [ raw[ i ].toLowerCase(), raw[ i + 1 ] ] );
	}
	return out;
}

/**
 * First value for a header, or null.
 *
 * @param headers Header pairs.
 * @param name    Lowercase name.
 * @return The value or null.
 */
function getHeader( headers: HeaderList, name: string ): string | null {
	for ( const [ key, value ] of headers ) {
		if ( key === name ) {
			return value;
		}
	}
	return null;
}

/**
 * All values for a header.
 *
 * @param headers Header pairs.
 * @param name    Lowercase name.
 * @return Values in order.
 */
function getHeaderValues( headers: HeaderList, name: string ): string[] {
	return headers
		.filter( ( [ key ] ) => key === name )
		.map( ( [ , v ] ) => v );
}

/**
 * Replace all values of a header with one value.
 *
 * @param headers Header pairs; mutated.
 * @param name    Lowercase name.
 * @param value   New value.
 */
function setHeader( headers: HeaderList, name: string, value: string ): void {
	deleteHeader( headers, name );
	headers.push( [ name, value ] );
}

/**
 * Remove every value of a header.
 *
 * @param headers Header pairs; mutated.
 * @param name    Lowercase name.
 */
function deleteHeader( headers: HeaderList, name: string ): void {
	for ( let i = headers.length - 1; i >= 0; i-- ) {
		if ( headers[ i ][ 0 ] === name ) {
			headers.splice( i, 1 );
		}
	}
}

/**
 * Build the object `res.writeHead` wants, with arrays for repeated names.
 *
 * @param headers Header pairs.
 * @return Outgoing headers.
 */
function toOutgoing( headers: HeaderList ): http.OutgoingHttpHeaders {
	const out: http.OutgoingHttpHeaders = {};
	for ( const [ key, value ] of headers ) {
		const existing = out[ key ];
		if ( existing === undefined ) {
			out[ key ] = value;
		} else if ( Array.isArray( existing ) ) {
			existing.push( value );
		} else {
			out[ key ] = [ String( existing ), value ];
		}
	}
	return out;
}

/**
 * Whether the cache must not store this origin response.
 *
 * Mirrors the common nginx rules: only 200 GET responses, no `Set-Cookie`,
 * and no `Cache-Control` directive that forbids a shared cache.
 *
 * @param method  Request method.
 * @param status  Response status.
 * @param headers Response headers after phase 3.
 * @return True when storable.
 */
function isStorable(
	method: string,
	status: number,
	headers: HeaderList
): boolean {
	if ( method !== 'GET' || status !== 200 ) {
		return false;
	}
	if ( getHeaderValues( headers, 'set-cookie' ).length > 0 ) {
		return false;
	}
	const cacheControl = getHeaderValues( headers, 'cache-control' )
		.join( ',' )
		.toLowerCase();
	const forbidden = [ 'no-store', 'no-cache', 'private', 'max-age=0' ];
	return ! forbidden.some( ( directive ) =>
		cacheControl.includes( directive )
	);
}

/**
 * TTL in seconds from `Cache-Control`, or the default.
 *
 * @param headers Response headers.
 * @return Seconds to keep the entry.
 */
function ttlSeconds( headers: HeaderList ): number {
	const cacheControl = getHeaderValues( headers, 'cache-control' )
		.join( ',' )
		.toLowerCase();
	for ( const directive of [ 's-maxage', 'max-age' ] ) {
		const at = cacheControl.indexOf( directive + '=' );
		if ( at < 0 ) {
			continue;
		}
		const value = Number.parseInt(
			cacheControl.slice( at + directive.length + 1 ),
			10
		);
		if ( Number.isFinite( value ) && value > 0 ) {
			return value;
		}
	}
	return DEFAULT_TTL_SECONDS;
}

/**
 * Whether the request bypasses the cache entirely.
 *
 * @param method  Request method.
 * @param path    Request path without query.
 * @param headers Request headers.
 * @return True for a pass-through request.
 */
function isPass( method: string, path: string, headers: HeaderList ): boolean {
	if ( method !== 'GET' && method !== 'HEAD' ) {
		return true;
	}
	if ( getHeader( headers, 'authorization' ) !== null ) {
		return true;
	}
	if ( PASS_PATH_PREFIXES.some( ( prefix ) => path.startsWith( prefix ) ) ) {
		return true;
	}
	const cookie = getHeader( headers, 'cookie' ) ?? '';
	return PASS_COOKIE_MARKERS.some( ( marker ) => cookie.includes( marker ) );
}

/**
 * Read a request body fully.
 *
 * @param req Incoming request.
 * @return The body.
 */
function readBody( req: http.IncomingMessage ): Promise< Buffer > {
	return new Promise( ( resolve, reject ) => {
		const chunks: Buffer[] = [];
		req.on( 'data', ( chunk: Buffer ) => chunks.push( chunk ) );
		req.on( 'end', () => resolve( Buffer.concat( chunks ) ) );
		req.on( 'error', reject );
	} );
}

/**
 * Start the simulator.
 *
 * @param options Listener, origin, and secret settings.
 * @return The running simulator.
 */
export async function startEdgeSim(
	options: EdgeSimOptions = {}
): Promise< EdgeSim > {
	const origin = new URL( options.origin ?? 'http://localhost:8888' );
	const secret = options.secret ?? 'local-dev-secret';
	const log = options.log ?? ( () => {} );

	const cache = new Map< string, CacheEntry[] >();
	const kv = new Map< string, string >();
	const kvUrls = new Map< string, string >();
	const stats = { hits: 0, misses: 0, passes: 0 };

	/**
	 * Forward a request to origin.
	 *
	 * @param method  Method.
	 * @param url     Path and query.
	 * @param headers Request headers to send.
	 * @param body    Request body.
	 * @return The origin response.
	 */
	function forward(
		method: string,
		url: string,
		headers: HeaderList,
		body: Buffer
	): Promise< OriginResult > {
		const outgoing: HeaderList = headers.filter(
			( [ key ] ) => ! HOP_BY_HOP.has( key ) && key !== 'host'
		);
		setHeader( outgoing, 'host', origin.host );
		setHeader( outgoing, 'accept-encoding', 'identity' );
		if ( body.length > 0 ) {
			setHeader( outgoing, 'content-length', String( body.length ) );
		}
		const transport = origin.protocol === 'https:' ? https : http;
		return new Promise( ( resolve, reject ) => {
			const request = transport.request(
				{
					protocol: origin.protocol,
					hostname: origin.hostname,
					port: origin.port || undefined,
					method,
					path: url,
					headers: toOutgoing( outgoing ),
				},
				( response ) => {
					const chunks: Buffer[] = [];
					response.on( 'data', ( chunk: Buffer ) =>
						chunks.push( chunk )
					);
					response.on( 'end', () =>
						resolve( {
							status: response.statusCode ?? 502,
							headers: toHeaderList( response.rawHeaders ),
							body: Buffer.concat( chunks ),
						} )
					);
					response.on( 'error', reject );
				}
			);
			request.on( 'error', reject );
			request.end( body );
		} );
	}

	/**
	 * Run phase 4 and append its cookies.
	 *
	 * @param headers        Response headers; mutated.
	 * @param requestHeaders Client request headers, before phase 1.
	 */
	function clientResponsePhase(
		headers: HeaderList,
		requestHeaders: HeaderList
	): void {
		const input = new ClientResponseInput();
		input.meterHeaders = getHeaderValues( headers, METER_HEADER );
		if ( input.meterHeaders.length === 0 ) {
			return;
		}
		input.userAgent = getHeader( requestHeaders, 'user-agent' );
		input.cookie = getHeader( requestHeaders, 'cookie' );
		input.setCookies = getHeaderValues( headers, 'set-cookie' );
		input.secret = secret;
		input.now = responseTime(
			getHeader( headers, 'date' ),
			getHeader( headers, 'age' )
		);
		const out = onClientResponsePure( input, nodeCrypto );
		setHeader(
			headers,
			METER_DIAGNOSTIC_HEADER,
			meterDiagnostic( out, getHeader( headers, 'age' ) )
		);
		if ( out.replace ) {
			deleteHeader( headers, 'set-cookie' );
			for ( const value of out.setCookies ) {
				headers.push( [ 'set-cookie', value ] );
			}
		}
		if ( out.skipped ) {
			log( '  phase 4: skipped (' + out.skipped + ')' );
		}
	}

	/**
	 * Human-readable record for the diagnostics header.
	 *
	 * @param key KV key.
	 * @return Declared groups, `(empty)`, or `-`.
	 */
	function describeRecord( key: string ): string {
		const names = decodeRecord( kv.get( key ) ?? null );
		if ( names === null ) {
			return '-';
		}
		return names.length === 0 ? '(empty)' : names.join( ', ' );
	}

	/**
	 * Send a JSON reply.
	 *
	 * @param res    Server response.
	 * @param status Status code.
	 * @param body   JSON-serializable body.
	 */
	function sendJson(
		res: http.ServerResponse,
		status: number,
		body: unknown
	): void {
		const text = JSON.stringify( body, null, 2 );
		res.writeHead( status, {
			'content-type': 'application/json',
			'content-length': Buffer.byteLength( text ),
		} );
		res.end( text );
	}

	/**
	 * Purge every variant of a URL, or everything.
	 *
	 * @param url Path and query, or undefined for all.
	 * @return Number of entries removed.
	 */
	function purge( url?: string ): number {
		let removed = 0;
		for ( const [ key, entries ] of cache ) {
			if ( url === undefined || key === url ) {
				removed += entries.length;
				cache.delete( key );
			}
		}
		return removed;
	}

	/**
	 * Handle the simulator's own control endpoints.
	 *
	 * @param req Incoming request.
	 * @param res Server response.
	 * @return True when handled.
	 */
	async function handleControl(
		req: http.IncomingMessage,
		res: http.ServerResponse
	): Promise< boolean > {
		const url = req.url ?? '/';
		if ( ! url.startsWith( '/__edge/' ) ) {
			return false;
		}
		if ( url === '/__edge/stats' && req.method === 'GET' ) {
			const entries: unknown[] = [];
			for ( const [ key, list ] of cache ) {
				for ( const entry of list ) {
					entries.push( {
						url: key,
						key: entry.segmentation,
						varies: entry.varies,
						storedAt: new Date(
							entry.storedAt * 1000
						).toISOString(),
						expiresAt: new Date(
							entry.expiresAt * 1000
						).toISOString(),
						declared: getHeader( entry.headers, DECLARED_HEADER ),
						meters: getHeaderValues( entry.headers, METER_HEADER ),
					} );
				}
			}
			const records: unknown[] = [];
			for ( const [ key, value ] of kv ) {
				records.push( {
					key,
					url: kvUrls.get( key ) ?? null,
					record: value,
					declared: decodeRecord( value ),
				} );
			}
			sendJson( res, 200, { ...stats, entries, kv: records } );
			return true;
		}
		if ( url === '/__edge/purge' && req.method === 'POST' ) {
			const body = ( await readBody( req ) ).toString( 'utf8' );
			let target: string | undefined;
			if ( body.trim().length > 0 ) {
				try {
					const parsed = JSON.parse( body ) as { url?: unknown };
					if ( typeof parsed.url === 'string' ) {
						target = parsed.url;
					}
				} catch {
					sendJson( res, 400, { error: 'invalid JSON' } );
					return true;
				}
			}
			sendJson( res, 200, {
				purged: purge( target ),
				url: target ?? null,
			} );
			return true;
		}
		if ( url === '/__edge/reset' && req.method === 'POST' ) {
			await readBody( req );
			const purged = purge();
			kv.clear();
			kvUrls.clear();
			stats.hits = 0;
			stats.misses = 0;
			stats.passes = 0;
			sendJson( res, 200, { purged, kv: 'cleared' } );
			return true;
		}
		sendJson( res, 404, { error: 'unknown control endpoint' } );
		return true;
	}

	/**
	 * Deliver a response to the client.
	 *
	 * @param res     Server response.
	 * @param method  Request method, for HEAD.
	 * @param status  Status code.
	 * @param headers Response headers.
	 * @param body    Body bytes.
	 */
	function deliver(
		res: http.ServerResponse,
		method: string,
		status: number,
		headers: HeaderList,
		body: Buffer
	): void {
		const outgoing = headers.filter(
			( [ key ] ) => ! HOP_BY_HOP.has( key )
		);
		deleteHeader( outgoing, 'content-length' );
		outgoing.push( [ 'content-length', String( body.length ) ] );
		res.writeHead( status, toOutgoing( outgoing ) );
		res.end( method === 'HEAD' ? undefined : body );
	}

	/**
	 * The proxy itself: phase 1, pass or lookup, origin + phase 3 on a miss,
	 * phase 4 on every delivery.
	 *
	 * @param req Incoming request.
	 * @param res Server response.
	 */
	async function handle(
		req: http.IncomingMessage,
		res: http.ServerResponse
	): Promise< void > {
		if ( await handleControl( req, res ) ) {
			return;
		}
		const method = req.method ?? 'GET';
		const url = req.url ?? '/';
		const path = url.split( '?' )[ 0 ];
		const clientHeaders = toHeaderList( req.rawHeaders );
		const body = await readBody( req );

		// Phase 1 runs on every request, as it does on the platform: the
		// worker sees the request before the cache decides anything.
		const key = kvKeyFor( url, nodeCrypto );
		kvUrls.set( key, url );
		const input = new ClientRequestInput();
		input.url = url;
		input.cookie = getHeader( clientHeaders, 'cookie' );
		input.userAgent = getHeader( clientHeaders, 'user-agent' );
		input.record = kv.get( key ) ?? null;
		const phase1 = onClientRequestPure( input );

		const originHeaders: HeaderList = clientHeaders.slice();
		setHeader( originHeaders, SEGMENTATION_HEADER, phase1.segmentation );
		setHeader( originHeaders, EDGE_VERSION_HEADER, phase1.edgeVersion );
		setHeader( originHeaders, LOOKUP_HEADER, phase1.lookup );
		setHeader( originHeaders, RECORD_HEADER, phase1.recordKeys );
		setHeader( originHeaders, KEY_HEADER, key );
		setHeader( originHeaders, CRAWLER_HEADER, phase1.crawler );
		if ( phase1.cookie.length === 0 ) {
			deleteHeader( originHeaders, 'cookie' );
		} else {
			setHeader( originHeaders, 'cookie', phase1.cookie );
		}

		const keyLabel =
			phase1.segmentation.length === 0 ? '-' : phase1.segmentation;
		log(
			method + ' ' + url + ' key=' + keyLabel + ' lookup=' + phase1.lookup
		);

		const simHeaders = ( verdict: string ): HeaderList => [
			[ 'x-edge-sim', verdict ],
			[ 'x-edge-sim-key', keyLabel ],
			[ 'x-edge-sim-record', describeRecord( key ) ],
		];

		// Pass-through: forwarded with phase 1's rewrites, never cached, no
		// response phases.
		if ( isPass( method, path, clientHeaders ) ) {
			stats.passes++;
			const result = await forward( method, url, originHeaders, body );
			log( '  PASS ' + result.status );
			deliver(
				res,
				method,
				result.status,
				[ ...result.headers, ...simHeaders( 'PASS' ) ],
				result.body
			);
			return;
		}

		// Cache lookup: an entry matches when it does not vary, or when it
		// was stored under this request's (narrowed) segmentation value.
		const now = Math.floor( Date.now() / 1000 );
		const variants = cache.get( url ) ?? [];
		const hit = variants.find(
			( entry ) =>
				entry.expiresAt > now &&
				( ! entry.varies || entry.segmentation === phase1.segmentation )
		);
		if ( hit ) {
			stats.hits++;
			const headers: HeaderList = hit.headers.map( ( pair ) => [
				...pair,
			] );
			setHeader( headers, 'age', String( now - hit.storedAt ) );
			clientResponsePhase( headers, clientHeaders );
			log( '  HIT ' + hit.status );
			deliver(
				res,
				method,
				hit.status,
				[ ...headers, ...simHeaders( 'HIT' ) ],
				hit.body
			);
			return;
		}

		// Miss: origin, then phase 3, then store, then phase 4.
		stats.misses++;
		const result = await forward( method, url, originHeaders, body );
		const headers: HeaderList = result.headers.slice();

		const phase3Input = new OriginResponseInput();
		phase3Input.vary =
			getHeaderValues( headers, 'vary' ).join( ', ' ) || null;
		phase3Input.declared = getHeader( headers, DECLARED_HEADER );
		phase3Input.lookup = phase1.lookup;
		phase3Input.recordKeys = phase1.recordKeys;
		const phase3 = onOriginResponsePure( phase3Input );
		setHeader( headers, COVERAGE_HEADER, phase3.diagnostic );
		if ( phase3.noStore ) {
			// The SDK's setCacheControl() writes both of these.
			setHeader( headers, 'cache-control', 'no-store' );
			setHeader( headers, 'x-original-cache-control', 'no-store' );
		}
		if ( phase3.record !== null ) {
			kv.set( key, phase3.record );
		}
		log( '  MISS ' + result.status + ' phase 3: ' + phase3.reason );

		if ( isStorable( method, result.status, headers ) ) {
			const varies = variesOnSegmentation( phase3Input.vary );
			const entry: CacheEntry = {
				status: result.status,
				headers: headers.map( ( pair ) => [ ...pair ] ),
				body: result.body,
				varies,
				segmentation: varies ? phase1.segmentation : '',
				storedAt: now,
				expiresAt: now + ttlSeconds( headers ),
			};
			const kept = variants.filter(
				( existing ) =>
					existing.varies &&
					varies &&
					existing.segmentation !== entry.segmentation
			);
			kept.push( entry );
			cache.set( url, kept );
		}

		clientResponsePhase( headers, clientHeaders );
		deliver(
			res,
			method,
			result.status,
			[ ...headers, ...simHeaders( 'MISS' ) ],
			result.body
		);
	}

	const server = http.createServer( ( req, res ) => {
		handle( req, res ).catch( ( error: unknown ) => {
			const message =
				error instanceof Error ? error.message : String( error );
			log( '  ERROR ' + message );
			if ( ! res.headersSent ) {
				res.writeHead( 502, { 'content-type': 'text/plain' } );
			}
			res.end( 'edge-sim: ' + message );
		} );
	} );

	await new Promise< void >( ( resolve, reject ) => {
		server.once( 'error', reject );
		server.listen(
			options.port ?? 8890,
			options.host ?? '127.0.0.1',
			() => {
				server.off( 'error', reject );
				resolve();
			}
		);
	} );

	const address = server.address() as AddressInfo;
	const host = address.address.includes( ':' )
		? '[' + address.address + ']'
		: address.address;
	return {
		url: 'http://' + host + ':' + address.port,
		close: () =>
			new Promise< void >( ( resolve, reject ) => {
				server.closeAllConnections();
				server.close( ( error ) =>
					error ? reject( error ) : resolve()
				);
			} ),
	};
}
