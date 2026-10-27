/**
 * Content Gates Edge Worker: declared-set narrowing, crawler
 * classification, and anonymous metering for the WPVIP page cache.
 *
 * This file is the only one that talks to the SDK. It marshals request and
 * response headers into the pure functions in worker.ts and applies their
 * outputs. Read docs/edge-worker.md for the request flow and
 * spec/page-cache.md for the design.
 *
 * Phase isolation: each handler runs from a clean slate, so facts phase 1
 * learns travel to phase 3 as request headers and are read back from
 * `resp.request`, the host's read-only request snapshot. Phase 4's
 * snapshot is the client-socket request before phase 1 ran, so phase 4
 * re-derives what it needs (user agent, cookies) from the raw request.
 */

import {
	KV,
	Request,
	Response,
	getenv,
	hexEncode,
	hmacSha256Str,
	onClientRequest,
	onClientResponse,
	onOriginResponse,
	sha256,
} from '@automattic/vip-edge-workers-sdk';
import { bypassReason } from '../../../lib/bypass.ts';
import { crawlerVersion } from '../../../lib/crawlers.ts';
import { Crypto } from '../../../lib/crypto.ts';
import { responseTime } from '../../../lib/httpdate.ts';
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
	kvKeyFor,
	onClientRequestPure,
	onClientResponsePure,
	onOriginResponsePure,
} from '../../../lib/worker.ts';

// ABI symbols for the phases this worker handles. `alloc` is always
// required. No body markers: the worker never reads a body. The names are
// the host contract, so they cannot follow the camelCase rule.
/* eslint-disable camelcase */
export {
	alloc,
	on_client_request,
	on_client_response,
	on_origin_response,
} from '@automattic/vip-edge-workers-sdk/assembly/index';
/* eslint-enable camelcase */

/** Environment variable holding the meter cookie signing secret. */
const METER_SECRET_ENV = 'CONTENT_GATES_METER_SECRET';

/**
 * Host-executed SHA-256 as lowercase hex.
 *
 * @param input Text to hash.
 * @return Hex digest.
 */
function sha256Hex( input: string ): string {
	return hexEncode(
		sha256( Uint8Array.wrap( String.UTF8.encode( input ) ) )
	);
}

/**
 * Host-executed HMAC-SHA256 as lowercase hex.
 *
 * @param secret  Key.
 * @param message Text to sign.
 * @return Hex digest.
 */
function hmacSha256Hex( secret: string, message: string ): string {
	return hexEncode( hmacSha256Str( secret, message ) );
}

const crypto = new Crypto( sha256Hex, hmacSha256Hex );

/**
 * Every value of a header on a live message, joined with `, `, or null.
 * The host's `get()` returns only the first value, and `Vary` may arrive
 * as several lines.
 *
 * @param resp Response to read.
 * @param name Lowercase header name.
 * @return Joined values, or null when absent.
 */
function joinedHeader( resp: Response, name: string ): string | null {
	const values = headerValues( resp, name );
	return values.length == 0 ? null : values.join( ', ' );
}

/**
 * Every value of a header on a live message.
 *
 * @param resp Response to read.
 * @param name Lowercase header name.
 * @return Values in order, possibly empty.
 */
function headerValues( resp: Response, name: string ): string[] {
	const out: string[] = [];
	const entries = resp.headers.entries();
	for ( let i = 0; i < entries.length; i++ ) {
		if ( entries[ i ][ 0 ] == name ) {
			out.push( entries[ i ][ 1 ] );
		}
	}
	return out;
}

// Phase 1: rewrite the segmentation the cache keys on, before lookup.
onClientRequest( ( req: Request ): void => {
	// The version header goes on every request, bypassed or not, so the
	// plugin's skew notice sees it on admin requests too.
	req.headers.set( EDGE_VERSION_HEADER, crawlerVersion() );

	// Requests the cache can never store under a segmented key skip the KV
	// read and every rewrite (see lib/bypass.ts). The marker tells phase 3
	// to store nothing and to report the key as `bypass`.
	const cookie = req.headers.get( 'cookie' );
	const reason = bypassReason(
		req.method,
		req.url,
		cookie,
		req.headers.get( 'authorization' ) !== null
	);
	if ( reason.length > 0 ) {
		req.headers.set( LOOKUP_HEADER, LOOKUP_BYPASS );
		return;
	}

	const key = kvKeyFor( req.url, crypto );

	const input = new ClientRequestInput();
	input.url = req.url;
	input.cookie = cookie;
	input.userAgent = req.headers.get( 'user-agent' );
	input.record = KV.getText( key );

	const out = onClientRequestPure( input );

	req.headers.set( SEGMENTATION_HEADER, out.segmentation );
	req.headers.set( LOOKUP_HEADER, out.lookup );
	req.headers.set( RECORD_HEADER, out.recordKeys );
	req.headers.set( KV_KEY_HEADER, key );

	if ( out.cookie.length == 0 ) {
		req.headers.delete( 'cookie' );
	} else {
		req.headers.set( 'cookie', out.cookie );
	}
} );

// Phase 3: coverage check and record update, on a miss. Uses
// `resp.request`, so the runtime must support `request.*` host functions.
onOriginResponse( ( resp: Response ): void => {
	const snapshot = resp.request;

	const input = new OriginResponseInput();
	input.vary = joinedHeader( resp, 'vary' );
	input.declared = resp.headers.get( DECLARED_HEADER );
	input.lookup = snapshot.headers.get( LOOKUP_HEADER );
	input.recordKeys = snapshot.headers.get( RECORD_HEADER );
	input.segmentation = snapshot.headers.get( SEGMENTATION_HEADER );

	const out = onOriginResponsePure( input );

	resp.headers.set( KEY_HEADER, out.key ); // Whatever the decision; see KEY_HEADER.

	if ( out.noStore ) {
		resp.setCacheControl( 'no-store' );
	}

	const record = out.record;
	const key = snapshot.headers.get( KV_KEY_HEADER );
	if ( record !== null && key !== null ) {
		KV.setText( key, record );
	}
} );

// Phase 4: advance meters and write cookies, on hits and misses alike.
onClientResponse( ( resp: Response ): void => {
	const meters = headerValues( resp, METER_HEADER );
	if ( meters.length == 0 ) {
		return;
	}
	const snapshot = resp.request;

	const input = new ClientResponseInput();
	input.meterHeaders = meters;
	input.userAgent = snapshot.headers.get( 'user-agent' );
	input.cookie = snapshot.headers.get( 'cookie' );
	input.method = snapshot.method;
	input.url = snapshot.url;
	input.authorization = snapshot.headers.get( 'authorization' ) !== null;
	input.setCookies = resp.headers.getSetCookie();
	input.secret = getenv( METER_SECRET_ENV );
	input.now = responseTime(
		resp.headers.get( 'date' ),
		resp.headers.get( 'age' )
	);

	const out = onClientResponsePure( input, crypto );
	if ( ! out.replace ) {
		return;
	}
	// Replace the whole list; see onClientResponsePure.
	resp.headers.delete( 'set-cookie' );
	for ( let i = 0; i < out.setCookies.length; i++ ) {
		resp.headers.append( 'set-cookie', out.setCookies[ i ] );
	}
} );
