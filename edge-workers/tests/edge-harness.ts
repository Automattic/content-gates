/**
 * An in-memory edge for sequence tests: the three worker phases around a
 * cache that follows only the rules the design depends on.
 *
 * The rules, and nothing else:
 *
 * 1. A stored copy is keyed by URL, plus `X-VIP-Go-Segmentation` as WPVIP
 *    derives it from the forwarded `vip-go-seg` cookie after phase 1
 *    (observed 2026-10-27: the header the worker sets is ignored, and an
 *    empty value is dropped), when the response's `Vary` names that
 *    header. Otherwise one copy serves every reader.
 * 2. A response is stored only when it is a 200 to a GET, carries no
 *    `Set-Cookie`, and is not `no-store` after phase 3.
 * 3. Phase 1 runs on every request; phases 3 and 4 run on every origin
 *    response and every delivery, including passes.
 * 4. KV is a map; a purge drops copies and keeps records.
 *
 * There is no TTL, no pass list beyond the worker's own bypass rules, and
 * no emulation of anything else. Each rule was observed on WPVIP on
 * 2026-10-27 (docs/known-limitations.md); this harness states the
 * contract, it does not re-verify it.
 */

import { getCookie } from '../lib/cookies.ts';
import { responseTime } from '../lib/httpdate.ts';
import { bypassReason } from '../lib/bypass.ts';
import { crawlerVersion } from '../lib/crawlers.ts';
import { decodeRecord } from '../lib/declared.ts';
import {
	ClientRequestInput,
	ClientResponseInput,
	DECLARED_HEADER,
	EDGE_VERSION_HEADER,
	KEY_HEADER,
	KV_KEY_HEADER,
	LOOKUP_BYPASS,
	LOOKUP_HEADER,
	METER_HEADER,
	OriginResponseInput,
	RECORD_HEADER,
	SEGMENTATION_HEADER,
	SEGMENT_COOKIE,
	kvKeyFor,
	onClientRequestPure,
	onClientResponsePure,
	onOriginResponsePure,
	variesOnSegmentation,
} from '../lib/worker.ts';
import { nodeCrypto } from './helpers.ts';

/** Header map with lowercase names; `set-cookie` may repeat. */
export type Headers = Record< string, string | string[] >;

export interface OriginRequest {
	method: string;
	url: string;
	/** Headers as origin receives them, after phase 1's rewrite. */
	headers: Record< string, string >;
}

export interface OriginResponse {
	status?: number;
	headers?: Headers;
	body?: string;
}

/** A fake WordPress: answers from the request origin receives. */
export type Origin = ( request: OriginRequest ) => OriginResponse;

export interface Delivery {
	verdict: 'HIT' | 'MISS' | 'PASS';
	status: number;
	headers: Headers;
	body: string;
	/** What origin received, when origin was asked. */
	originRequest: OriginRequest | null;
}

interface Copy {
	segmentation: string;
	varies: boolean;
	status: number;
	headers: Headers;
	body: string;
}

function first( headers: Headers, name: string ): string | null {
	const value = headers[ name ];
	if ( value === undefined ) {
		return null;
	}
	return Array.isArray( value ) ? ( value[ 0 ] ?? null ) : value;
}

function all( headers: Headers, name: string ): string[] {
	const value = headers[ name ];
	if ( value === undefined ) {
		return [];
	}
	return Array.isArray( value ) ? value : [ value ];
}

export class EdgeHarness {
	readonly kv = new Map< string, string >();
	readonly copies = new Map< string, Copy[] >();
	readonly stats = { hits: 0, misses: 0, passes: 0 };
	private readonly kvUrls = new Map< string, string >();

	private readonly origin: Origin;
	private readonly secret: string;
	/** Epoch seconds the `Date` header carries; meters count from it. */
	now: number;

	constructor( origin: Origin, secret = 'test-secret', now = 1792670400 ) {
		this.origin = origin;
		this.secret = secret;
		this.now = now;
	}

	/**
	 * One request through the edge.
	 *
	 * @param url     Path and query.
	 * @param headers Client request headers, lowercase names.
	 * @param method  HTTP method.
	 * @return What the client receives.
	 */
	request(
		url: string,
		headers: Record< string, string > = {},
		method = 'GET'
	): Delivery {
		const toOrigin: Record< string, string > = { ...headers };
		toOrigin[ EDGE_VERSION_HEADER ] = crawlerVersion();

		const bypass = bypassReason(
			method,
			url,
			headers.cookie ?? null,
			headers.authorization !== undefined
		);
		if ( bypass.length > 0 ) {
			this.stats.passes++;
			toOrigin[ LOOKUP_HEADER ] = LOOKUP_BYPASS;
			const originRequest = { method, url, headers: toOrigin };
			const response = this.ask( originRequest );
			this.originPhase( response, LOOKUP_BYPASS, null, null );
			return this.deliver(
				'PASS',
				response,
				headers,
				method,
				url,
				originRequest
			);
		}

		const key = kvKeyFor( url, nodeCrypto );
		this.kvUrls.set( key, url );
		const input = new ClientRequestInput();
		input.url = url;
		input.cookie = headers.cookie ?? null;
		input.userAgent = headers[ 'user-agent' ] ?? null;
		input.record = this.kv.get( key ) ?? null;
		const phase1 = onClientRequestPure( input );

		toOrigin[ LOOKUP_HEADER ] = phase1.lookup;
		toOrigin[ RECORD_HEADER ] = phase1.recordKeys;
		toOrigin[ KV_KEY_HEADER ] = key;
		if ( phase1.cookie.length === 0 ) {
			delete toOrigin.cookie;
		} else {
			toOrigin.cookie = phase1.cookie;
		}
		// The platform's own derivation from the forwarded cookie; absent
		// when empty.
		const keyValue = getCookie( phase1.cookie, SEGMENT_COOKIE ) ?? '';
		if ( keyValue.length > 0 ) {
			toOrigin[ SEGMENTATION_HEADER ] = keyValue;
		}

		// Rule 1: lookup.
		const hit = ( this.copies.get( url ) ?? [] ).find(
			( copy ) => ! copy.varies || copy.segmentation === keyValue
		);
		if ( hit ) {
			this.stats.hits++;
			return this.deliver(
				'HIT',
				{
					status: hit.status,
					headers: { ...hit.headers },
					body: hit.body,
				},
				headers,
				method,
				url,
				null
			);
		}

		this.stats.misses++;
		const originRequest = { method, url, headers: toOrigin };
		const response = this.ask( originRequest );
		const phase3 = this.originPhase(
			response,
			phase1.lookup,
			phase1.recordKeys,
			keyValue.length > 0 ? keyValue : null
		);
		if ( phase3.record !== null ) {
			this.kv.set( key, phase3.record );
		}

		// Rule 2: store.
		const varies = variesOnSegmentation(
			first( response.headers, 'vary' )
		);
		const storable =
			method === 'GET' &&
			response.status === 200 &&
			all( response.headers, 'set-cookie' ).length === 0 &&
			! ( first( response.headers, 'cache-control' ) ?? '' ).includes(
				'no-store'
			);
		if ( storable ) {
			const kept = ( this.copies.get( url ) ?? [] ).filter(
				( copy ) =>
					copy.varies && varies && copy.segmentation !== keyValue
			);
			kept.push( {
				segmentation: varies ? keyValue : '',
				varies,
				status: response.status,
				headers: { ...response.headers },
				body: response.body,
			} );
			this.copies.set( url, kept );
		}

		return this.deliver(
			'MISS',
			response,
			headers,
			method,
			url,
			originRequest
		);
	}

	/**
	 * Drop every copy of one URL, or of all URLs. Records stay.
	 * @param url
	 */
	purge( url?: string ): number {
		let removed = 0;
		for ( const [ key, copies ] of this.copies ) {
			if ( url === undefined || key === url ) {
				removed += copies.length;
				this.copies.delete( key );
			}
		}
		return removed;
	}

	/**
	 * Declared groups recorded for a URL, or null when none.
	 * @param url
	 */
	recordFor( url: string ): string[] | null {
		return decodeRecord(
			this.kv.get( kvKeyFor( url, nodeCrypto ) ) ?? null
		);
	}

	/** Stored copies as `[ url, segmentation ]` pairs. */
	stored(): Array< [ string, string ] > {
		const out: Array< [ string, string ] > = [];
		for ( const [ url, copies ] of this.copies ) {
			for ( const copy of copies ) {
				out.push( [ url, copy.segmentation ] );
			}
		}
		return out;
	}

	private ask( request: OriginRequest ): Required< OriginResponse > {
		const response = this.origin( request );
		const headers: Headers = {};
		for ( const [ name, value ] of Object.entries(
			response.headers ?? {}
		) ) {
			headers[ name.toLowerCase() ] = value;
		}
		if ( headers.date === undefined ) {
			headers.date = new Date( this.now * 1000 ).toUTCString();
		}
		return {
			status: response.status ?? 200,
			headers,
			body: response.body ?? '',
		};
	}

	private originPhase(
		response: Required< OriginResponse >,
		lookup: string,
		recordKeys: string | null,
		segmentation: string | null
	) {
		const input = new OriginResponseInput();
		input.vary = all( response.headers, 'vary' ).join( ', ' ) || null;
		input.declared = first( response.headers, DECLARED_HEADER );
		input.lookup = lookup;
		input.recordKeys = recordKeys;
		input.segmentation = segmentation;
		const out = onOriginResponsePure( input );
		response.headers[ KEY_HEADER ] = out.key;
		if ( out.noStore ) {
			response.headers[ 'cache-control' ] = 'no-store';
		}
		return out;
	}

	private deliver(
		verdict: Delivery[ 'verdict' ],
		response: Required< OriginResponse >,
		clientHeaders: Record< string, string >,
		method: string,
		url: string,
		originRequest: OriginRequest | null
	): Delivery {
		const headers: Headers = { ...response.headers };
		const meters = all( headers, METER_HEADER );
		if ( meters.length > 0 ) {
			const input = new ClientResponseInput();
			input.meterHeaders = meters;
			input.status = response.status;
			input.userAgent = clientHeaders[ 'user-agent' ] ?? null;
			input.cookie = clientHeaders.cookie ?? null;
			input.method = method;
			input.url = url;
			input.authorization = clientHeaders.authorization !== undefined;
			input.setCookies = all( headers, 'set-cookie' );
			input.secret = this.secret;
			input.now = responseTime(
				first( headers, 'date' ),
				first( headers, 'age' )
			);
			const out = onClientResponsePure( input, nodeCrypto );
			if ( out.replace ) {
				headers[ 'set-cookie' ] = [ ...out.setCookies ];
			}
		}
		return {
			verdict,
			status: response.status,
			headers,
			body: response.body,
			originRequest,
		};
	}
}

/**
 * The `name` cookie from a delivery's Set-Cookie list, or null.
 * @param delivery
 * @param name
 */
export function setCookie( delivery: Delivery, name: string ): string | null {
	for ( const line of all( delivery.headers, 'set-cookie' ) ) {
		if ( line.startsWith( name + '=' ) ) {
			return line.slice( name.length + 1 ).split( ';' )[ 0 ];
		}
	}
	return null;
}

/**
 * Every Set-Cookie line of a delivery.
 * @param delivery
 */
export function setCookies( delivery: Delivery ): string[] {
	return all( delivery.headers, 'set-cookie' );
}

/**
 * A delivery header, or null.
 * @param delivery
 * @param name
 */
export function header( delivery: Delivery, name: string ): string | null {
	return first( delivery.headers, name );
}
