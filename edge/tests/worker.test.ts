import assert from 'node:assert/strict';
import { test } from 'node:test';
import { getCookie, withCookie } from '../assembly/cookies.ts';
import { encodeRecord } from '../assembly/declared.ts';
import { parseMeterCookie } from '../assembly/meter.ts';
import {
	ClientRequestInput,
	ClientResponseInput,
	OriginResponseInput,
	kvKeyFor,
	onClientRequestPure,
	onClientResponsePure,
	onOriginResponsePure,
} from '../assembly/worker.ts';
import { DAY, nodeCrypto } from './helpers.ts';

const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
const CHROME = 'Mozilla/5.0 Chrome/120';

/**
 * Build a phase 1 input.
 *
 * @param cookie    Raw Cookie header.
 * @param userAgent User agent.
 * @param record    KV record.
 * @return The input.
 */
function request(
	cookie: string | null,
	userAgent: string,
	record: string | null
) {
	const input = new ClientRequestInput();
	input.url = '/article-1/';
	input.cookie = cookie;
	input.userAgent = userAgent;
	input.record = record;
	return input;
}

test( 'kvKeyFor is cg: plus 32 hex characters of sha256(path+query)', () => {
	const key = kvKeyFor( '/article-1/?p=1', nodeCrypto );
	assert.match( key, /^cg:[0-9a-f]{32}$/ );
	assert.equal( key.length, 35 );
	assert.notEqual( key, kvKeyFor( '/article-1/', nodeCrypto ) );
} );

test( 'phase 1 without a record passes the full value through', () => {
	const out = onClientRequestPure(
		request(
			'vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
			CHROME,
			null
		)
	);
	assert.equal(
		out.segmentation,
		'vc-v1__newsletter_--_1---__subscriber_--_1'
	);
	assert.equal( out.lookup, 'full' );
	assert.equal( out.recordKeys, '' );
	assert.equal( out.edgeVersion, '1' );
	assert.equal( out.crawler, '' );
} );

test( 'phase 1 with a record narrows to the recorded keys', () => {
	const out = onClientRequestPure(
		request(
			'other=x; vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
			CHROME,
			encodeRecord( [ 'subscriber' ] )
		)
	);
	assert.equal( out.segmentation, 'vc-v1__subscriber_--_1' );
	assert.equal( out.lookup, 'narrowed' );
	assert.equal( out.recordKeys, 'subscriber' );
	assert.equal(
		out.cookie,
		'other=x; vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
		'the forwarded cookie is never narrowed'
	);
} );

test( 'phase 1 with an empty record yields an empty key', () => {
	const out = onClientRequestPure(
		request(
			'vip-go-seg=vc-v1__subscriber_--_1',
			CHROME,
			encodeRecord( [] )
		)
	);
	assert.equal( out.segmentation, '' );
	assert.equal( out.lookup, 'narrowed' );
} );

test( 'phase 1 classifies a crawler and injects it into header and cookie', () => {
	const out = onClientRequestPure(
		request(
			null,
			GOOGLEBOT,
			encodeRecord( [ 'crawlers-search', 'subscriber' ] )
		)
	);
	assert.equal( out.segmentation, 'vc-v1__crawlers-search_--_1' );
	assert.equal( out.crawler, 'crawlers-search' );
	assert.equal( out.cookie, 'vip-go-seg=vc-v1__crawlers-search_--_1' );
} );

test( 'phase 1: a forged crawler cookie gains nothing', () => {
	const out = onClientRequestPure(
		request(
			'vip-go-seg=vc-v1__crawlers-search_--_1---__subscriber_--_1',
			CHROME,
			encodeRecord( [ 'crawlers-search', 'subscriber' ] )
		)
	);
	assert.equal( out.segmentation, 'vc-v1__subscriber_--_1' );
	assert.equal( out.cookie, 'vip-go-seg=vc-v1__subscriber_--_1' );
	const none = onClientRequestPure(
		request( 'vip-go-seg=vc-v1__crawlers-ai_--_1', CHROME, null )
	);
	assert.equal( none.segmentation, '' );
	assert.equal(
		none.cookie,
		'',
		'an empty vip-go-seg is dropped from the forwarded header'
	);
} );

/**
 * Build a phase 3 input.
 *
 * @param vary       Vary header.
 * @param declared   Declared header.
 * @param lookup     Lookup marker.
 * @param recordKeys Record marker.
 * @return The input.
 */
function originResponse(
	vary: string | null,
	declared: string | null,
	lookup: string,
	recordKeys: string
) {
	const input = new OriginResponseInput();
	input.vary = vary;
	input.declared = declared;
	input.lookup = lookup;
	input.recordKeys = recordKeys;
	return input;
}

test( 'phase 3 ignores unsegmented responses', () => {
	const out = onOriginResponsePure(
		originResponse( null, null, 'full', '' )
	);
	assert.equal( out.noStore, false );
	assert.equal( out.record, null );
	const encoding = onOriginResponsePure(
		originResponse( 'Accept-Encoding', 'subscriber', 'full', '' )
	);
	assert.equal( encoding.record, null );
} );

test( 'phase 3 fails closed when the lookup markers are missing or malformed', () => {
	const missing = new OriginResponseInput();
	missing.vary = 'X-VIP-Go-Segmentation';
	missing.declared = 'subscriber';
	const out = onOriginResponsePure( missing );
	assert.equal( out.noStore, true );
	assert.equal( out.reason, 'no-marker' );
	assert.equal( out.record, null, 'nothing is learned without the markers' );

	const bogus = onOriginResponsePure(
		originResponse( 'X-VIP-Go-Segmentation', 'subscriber', 'maybe', '' )
	);
	assert.equal( bogus.noStore, true );
	assert.equal( bogus.reason, 'no-marker' );

	const narrowedWithoutRecord = originResponse(
		'X-VIP-Go-Segmentation',
		'subscriber',
		'narrowed',
		''
	);
	narrowedWithoutRecord.recordKeys = null;
	assert.equal( onOriginResponsePure( narrowedWithoutRecord ).noStore, true );

	// Unsegmented responses need no markers.
	const plain = new OriginResponseInput();
	assert.equal( onOriginResponsePure( plain ).noStore, false );
	assert.equal( onOriginResponsePure( plain ).reason, 'unsegmented' );
} );

test( 'phase 3: segmented but undeclared is never stored', () => {
	const out = onOriginResponsePure(
		originResponse(
			'Accept-Encoding, X-VIP-Go-Segmentation',
			null,
			'full',
			''
		)
	);
	assert.equal( out.noStore, true );
	assert.equal( out.record, null );
} );

test( 'phase 3 after a full lookup stores and learns the record', () => {
	const out = onOriginResponsePure(
		originResponse(
			'X-VIP-Go-Segmentation',
			'subscriber, donor',
			'full',
			''
		)
	);
	assert.equal( out.noStore, false );
	assert.equal( out.record, 'v1:donor,subscriber' );
} );

test( 'phase 3 learns an empty declared set as an empty record', () => {
	const out = onOriginResponsePure(
		originResponse( 'X-VIP-Go-Segmentation', '', 'full', '' )
	);
	assert.equal( out.noStore, false );
	assert.equal( out.record, 'v1:' );
} );

test( 'phase 3: a too-narrow record means no-store and a wider record', () => {
	const out = onOriginResponsePure(
		originResponse(
			'x-vip-go-segmentation',
			'newsletter, subscriber',
			'narrowed',
			'subscriber'
		)
	);
	assert.equal( out.noStore, true );
	assert.equal( out.record, 'v1:newsletter,subscriber' );
} );

test( 'phase 3: a covered narrowed lookup stores without rewriting the record', () => {
	const out = onOriginResponsePure(
		originResponse(
			'X-VIP-Go-Segmentation',
			'subscriber',
			'narrowed',
			'subscriber'
		)
	);
	assert.equal( out.noStore, false );
	assert.equal( out.record, null );
	const shrink = onOriginResponsePure(
		originResponse(
			'X-VIP-Go-Segmentation',
			'subscriber',
			'narrowed',
			'donor, subscriber'
		)
	);
	assert.equal( shrink.noStore, false );
	assert.equal( shrink.record, 'v1:subscriber', 'replacement, not merge' );
} );

/**
 * Build a phase 4 input.
 *
 * @param cookie    Raw Cookie header.
 * @param userAgent User agent.
 * @param meters    Meter headers.
 * @return The input.
 */
function clientResponse(
	cookie: string | null,
	userAgent: string,
	meters: string[]
) {
	const input = new ClientResponseInput();
	input.meterHeaders = meters;
	input.userAgent = userAgent;
	input.cookie = cookie;
	input.secret = 'test-secret';
	input.now = 1792670400;
	return input;
}

const METER = 'metered-articles; id=123; limit=2; period=' + 30 * DAY;

test( 'phase 4 does nothing without meters, for crawlers, or without a secret', () => {
	assert.deepEqual(
		onClientResponsePure( clientResponse( null, CHROME, [] ), nodeCrypto )
			.setCookies,
		[]
	);
	const crawler = onClientResponsePure(
		clientResponse( null, GOOGLEBOT, [ METER ] ),
		nodeCrypto
	);
	assert.deepEqual( crawler.setCookies, [] );
	assert.equal( crawler.skipped, 'crawler' );
	const noSecret = clientResponse( null, CHROME, [ METER ] );
	noSecret.secret = null;
	assert.equal(
		onClientResponsePure( noSecret, nodeCrypto ).skipped,
		'no-secret'
	);
	const noDate = clientResponse( null, CHROME, [ METER ] );
	noDate.now = -1;
	assert.equal(
		onClientResponsePure( noDate, nodeCrypto ).skipped,
		'no-date'
	);
} );

test( 'phase 4 writes both cookies and merges the state into other groups', () => {
	const out = onClientResponsePure(
		clientResponse( 'vip-go-seg=vc-v1__subscriber_--_1', CHROME, [
			METER,
		] ),
		nodeCrypto
	);
	assert.equal( out.setCookies.length, 2 );
	assert.match(
		out.setCookies[ 0 ],
		/^vip-go-meter=v1\.[^;]+; Path=\/; Max-Age=2592000; HttpOnly$/
	);
	assert.equal(
		out.setCookies[ 1 ],
		'vip-go-seg=vc-v1__metered-articles_--_open---__subscriber_--_1; Path=/; Max-Age=86400'
	);
} );

test( 'phase 4 advances on the next view and drops browser-sent crawler values', () => {
	const first = onClientResponsePure(
		clientResponse( null, CHROME, [ METER ] ),
		nodeCrypto
	);
	const meterCookie = getCookie(
		first.setCookies[ 0 ].split( ';' )[ 0 ],
		'vip-go-meter'
	);
	const segCookie = getCookie(
		first.setCookies[ 1 ].split( ';' )[ 0 ],
		'vip-go-seg'
	);
	assert.ok( meterCookie );
	assert.ok( segCookie );
	const header = withCookie(
		'vip-go-meter=' + meterCookie,
		'vip-go-seg',
		segCookie.replace( 'vc-v1__', 'vc-v1__crawlers-search_--_1---__' )
	);
	const second = onClientResponsePure(
		clientResponse( header, CHROME, [ METER ] ),
		nodeCrypto
	);
	assert.equal(
		second.setCookies[ 1 ],
		'vip-go-seg=vc-v1__metered-articles_--_last; Path=/; Max-Age=86400'
	);
	const records = parseMeterCookie(
		getCookie( second.setCookies[ 0 ].split( ';' )[ 0 ], 'vip-go-meter' ),
		'test-secret',
		nodeCrypto
	);
	assert.equal( records[ 0 ].count, 1 );
} );

test( 'cookie helpers', () => {
	assert.equal( getCookie( 'a=1; b=2; a=3', 'a' ), '1' );
	assert.equal( getCookie( null, 'a' ), null );
	assert.equal( getCookie( 'flag; a=1', 'a' ), '1' );
	assert.equal( withCookie( 'a=1; b=2; a=3', 'a', '9' ), 'a=9; b=2' );
	assert.equal( withCookie( 'a=1', 'a', null ), '' );
	assert.equal( withCookie( null, 'a', '1' ), 'a=1' );
} );
