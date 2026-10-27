/**
 * Multi-request sequences through the in-memory edge: records are learned,
 * keys narrow, copies are shared or split, meters advance on hits, purges
 * shrink records on the next miss. See edge-harness.ts for the cache rules.
 */

import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';
import {
	EdgeHarness,
	header,
	setCookie,
	setCookies,
	type Headers,
	type OriginRequest,
} from './edge-harness.ts';

const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
const CHROME = 'Mozilla/5.0 Chrome/120';

/** What the fake origin declares per path. Tests mutate it like an editor would. */
const declaredByPath = new Map< string, string >();
let originHits = 0;

/**
 * Fake WordPress: answers like the plugin does and echoes what it received.
 *
 * @param request What origin receives.
 */
function origin( request: OriginRequest ) {
	originHits++;
	const path = request.url.split( '?' )[ 0 ];
	const headers: Headers = {
		'content-type': 'text/html; charset=UTF-8',
		'cache-control': 'max-age=300, must-revalidate',
		'x-origin-seg': request.headers[ 'x-vip-go-segmentation' ] ?? '',
		'x-origin-cookie': request.headers.cookie ?? '',
		'x-origin-edge': request.headers[ 'x-wpvip-content-gates-edge' ] ?? '',
		'x-origin-hits': String( originHits ),
	};
	if ( path === '/plain/' ) {
		// A page without Vary_Cache: no segmentation Vary, no declared header.
		return { headers, body: 'plain' };
	}
	if ( path === '/set-cookie/' ) {
		return {
			headers: { ...headers, 'set-cookie': 'session=abc' },
			body: 'cookie',
		};
	}
	headers.vary = 'X-VIP-Go-Segmentation';
	if ( path !== '/undeclared/' ) {
		headers[ 'x-wpvip-content-gates-declared' ] =
			declaredByPath.get( path ) ?? '';
	}
	if ( path === '/metered/' ) {
		headers[ 'x-wpvip-content-gates-meter' ] =
			'metered-articles; id=123; limit=2; period=2592000';
	}
	if ( path === '/metered-assign/' ) {
		// A provider assigned membership during this metered view.
		headers[ 'x-wpvip-content-gates-meter' ] =
			'metered-articles; id=124; limit=2; period=2592000';
		headers[ 'set-cookie' ] = [
			'session=abc; Path=/',
			'vip-go-seg=vc-v1__subscriber_--_1; Path=/; Max-Age=86400',
		];
	}
	if ( path.startsWith( '/wp-admin/' ) ) {
		headers[ 'x-wpvip-content-gates-declared' ] = '';
	}
	return {
		headers,
		body: 'seg=' + ( request.headers[ 'x-vip-go-segmentation' ] ?? '' ),
	};
}

let edge: EdgeHarness;

beforeEach( () => {
	edge = new EdgeHarness( origin );
	declaredByPath.clear();
	declaredByPath.set(
		'/article-1/',
		'crawlers-ai, crawlers-search, subscriber'
	);
	declaredByPath.set( '/about/', '' );
	declaredByPath.set(
		'/metered/',
		'crawlers-ai, crawlers-search, metered-articles'
	);
	originHits = 0;
} );

const KEY = 'x-wpvip-content-gates-key';

test( 'two readers who differ only in an undeclared segment share one copy', () => {
	const first = edge.request( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( first.verdict, 'MISS' );
	assert.equal( header( first, KEY ), 'vc-v1__subscriber_--_1' );
	assert.equal( header( first, 'x-origin-edge' ), '1' );
	assert.deepEqual( edge.recordFor( '/article-1/' ), [
		'crawlers-ai',
		'crawlers-search',
		'subscriber',
	] );

	const second = edge.request( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( second.verdict, 'HIT' );
	assert.equal( header( second, KEY ), 'vc-v1__subscriber_--_1' );
	assert.equal( second.body, 'seg=vc-v1__subscriber_--_1' );
	assert.equal(
		header( second, 'x-origin-hits' ),
		'1',
		'served from the first copy'
	);

	const anon = edge.request( '/article-1/', { 'user-agent': CHROME } );
	assert.equal( anon.verdict, 'MISS' );
	assert.equal( header( anon, KEY ), 'none' );
	const newsletter = edge.request( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( newsletter.verdict, 'HIT' );

	assert.equal( edge.stored().length, 2 );
	assert.deepEqual( edge.stats, { hits: 2, misses: 2, passes: 0 } );
} );

test( 'a page that declares nothing has one copy for everyone', () => {
	// The first miss is keyed on the full value (no record yet) and teaches
	// the edge the empty set; from then on every reader shares one copy.
	edge.request( '/about/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );
	const learned = edge.request( '/about/', {
		cookie: 'vip-go-seg=vc-v1__donor_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( learned.verdict, 'MISS' );
	assert.equal( header( learned, KEY ), 'none' );
	const other = edge.request( '/about/', {
		cookie: 'vip-go-seg=vc-v1__donor_--_1---__newsletter_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( other.verdict, 'HIT' );
	assert.equal( header( other, KEY ), 'none' );
	assert.deepEqual( edge.recordFor( '/about/' ), [] );
} );

test( 'a too-narrow record causes no-store, then the record widens, then shrinks', () => {
	declaredByPath.set( '/article-1/', 'subscriber' );
	edge.request( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );

	// An editor adds a newsletter gate; the save purges the URL.
	declaredByPath.set( '/article-1/', 'newsletter, subscriber' );
	edge.purge( '/article-1/' );

	const narrowed = edge.request( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( narrowed.verdict, 'MISS' );
	assert.equal( header( narrowed, KEY ), 'vc-v1__subscriber_--_1' );
	assert.equal( header( narrowed, 'cache-control' ), 'no-store' );
	assert.equal(
		edge.stored().length,
		0,
		'the too-narrow response was not stored'
	);
	assert.deepEqual( edge.recordFor( '/article-1/' ), [
		'newsletter',
		'subscriber',
	] );

	const widened = edge.request( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( widened.verdict, 'MISS' );
	assert.equal(
		header( widened, KEY ),
		'vc-v1__newsletter_--_1---__subscriber_--_1'
	);
	assert.equal(
		header( widened, 'cache-control' ),
		'max-age=300, must-revalidate'
	);
	assert.equal( edge.stored().length, 1 );

	// Removing the gate again shrinks the record on the next miss.
	declaredByPath.set( '/article-1/', 'subscriber' );
	edge.purge();
	edge.request( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.deepEqual( edge.recordFor( '/article-1/' ), [ 'subscriber' ] );
} );

test( 'segmented responses without a declared header are never stored', () => {
	const first = edge.request( '/undeclared/', { 'user-agent': CHROME } );
	assert.equal( header( first, 'cache-control' ), 'no-store' );
	assert.equal(
		header( first, KEY ),
		'none',
		'a no-store response still reports its key'
	);
	assert.equal( header( first, 'x-wpvip-content-gates-declared' ), null );
	const second = edge.request( '/undeclared/', { 'user-agent': CHROME } );
	assert.equal( second.verdict, 'MISS' );
	assert.equal( edge.stored().length, 0 );
} );

test( 'a crawler gets the crawler-keyed copy and no Set-Cookie', () => {
	edge.request( '/article-1/', { 'user-agent': CHROME } );

	const bot = edge.request( '/article-1/', {
		'user-agent': GOOGLEBOT,
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
	} );
	assert.equal( bot.verdict, 'MISS' );
	assert.equal(
		header( bot, KEY ),
		'vc-v1__crawlers-search_--_1---__subscriber_--_1'
	);
	assert.equal(
		bot.originRequest?.headers.cookie,
		'vip-go-seg=vc-v1__crawlers-search_--_1---__subscriber_--_1',
		'origin sees the worker classification in the cookie'
	);
	assert.deepEqual( setCookies( bot ), [] );

	const again = edge.request( '/article-1/', { 'user-agent': GOOGLEBOT } );
	assert.equal( header( again, KEY ), 'vc-v1__crawlers-search_--_1' );
	assert.equal( again.verdict, 'MISS' );
	const third = edge.request( '/article-1/', {
		'user-agent': 'Googlebot-Image/1.0',
	} );
	assert.equal( third.verdict, 'HIT' );
} );

test( 'a forged crawler cookie from a browser gains nothing', () => {
	edge.request( '/article-1/', { 'user-agent': CHROME } );
	const forged = edge.request( '/article-1/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=vc-v1__crawlers-search_--_1',
	} );
	assert.equal( forged.verdict, 'HIT', 'shares the anonymous copy' );
	assert.equal( header( forged, KEY ), 'none' );
	const miss = edge.request( '/article-1/?v=2', {
		'user-agent': CHROME,
		cookie: 'other=1; vip-go-seg=vc-v1__crawlers-ai_--_1',
	} );
	assert.equal(
		miss.originRequest?.headers.cookie,
		'other=1',
		'the crawler value never reaches origin'
	);
} );

test( 'metered views advance the cookie on cache hits', () => {
	// Reader A, first visit: anonymous copy, fresh record, state open.
	const first = edge.request( '/metered/', { 'user-agent': CHROME } );
	assert.equal( first.verdict, 'MISS' );
	const segA = setCookie( first, 'vip-go-seg' );
	const meterA = setCookie( first, 'vip-go-meter' );
	assert.equal( segA, 'vc-v1__metered-articles_--_open' );
	assert.ok( meterA );
	assert.match( setCookies( first )[ 0 ], /HttpOnly/ );

	// Reader A, second visit: keyed on open, counted, now last. Stored.
	const second = edge.request( '/metered/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=' + segA + '; vip-go-meter=' + meterA,
	} );
	assert.equal( second.verdict, 'MISS' );
	assert.equal( header( second, KEY ), 'vc-v1__metered-articles_--_open' );
	assert.equal(
		setCookie( second, 'vip-go-seg' ),
		'vc-v1__metered-articles_--_last'
	);
	assert.equal(
		second.originRequest?.headers.cookie,
		'vip-go-seg=' + segA + '; vip-go-meter=' + meterA
	);

	// Reader B: the second visit hits the copy stored for A; the meter still advances.
	const firstB = edge.request( '/metered/', { 'user-agent': CHROME } );
	assert.equal( firstB.verdict, 'HIT' );
	const segB = setCookie( firstB, 'vip-go-seg' );
	const meterB = setCookie( firstB, 'vip-go-meter' );
	assert.ok( meterB );
	const secondB = edge.request( '/metered/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=' + segB + '; vip-go-meter=' + meterB,
	} );
	assert.equal( secondB.verdict, 'HIT' );
	assert.equal(
		setCookie( secondB, 'vip-go-seg' ),
		'vc-v1__metered-articles_--_last'
	);
	assert.notEqual( setCookie( secondB, 'vip-go-meter' ), meterB );

	// Stored copies carry no Set-Cookie: the worker adds them per delivery.
	assert.equal( edge.stored().length, 2 );
	for ( const [ , copies ] of edge.copies ) {
		for ( const copy of copies ) {
			assert.equal( copy.headers[ 'set-cookie' ], undefined );
		}
	}
} );

test( 'an origin assignment on a metered miss reaches the browser once, with the meter state', () => {
	declaredByPath.set( '/metered-assign/', 'metered-articles, subscriber' );
	const response = edge.request( '/metered-assign/', {
		'user-agent': CHROME,
	} );
	assert.equal( response.verdict, 'MISS' );
	const cookies = setCookies( response );
	const segs = cookies.filter( ( c ) => c.startsWith( 'vip-go-seg=' ) );
	assert.equal( segs.length, 1 );
	assert.equal(
		segs[ 0 ],
		'vip-go-seg=vc-v1__metered-articles_--_open---__subscriber_--_1; Path=/; Max-Age=86400'
	);
	assert.equal( cookies[ 0 ], 'session=abc; Path=/' );
	assert.ok( setCookie( response, 'vip-go-meter' ) );

	// A response with Set-Cookie is never stored.
	const again = edge.request( '/metered-assign/', { 'user-agent': CHROME } );
	assert.equal( again.verdict, 'MISS' );
} );

test( 'the key header travels with the copy: a hit reads what the miss stored', () => {
	const miss = edge.request( '/article-1/', { 'user-agent': CHROME } );
	assert.equal( header( miss, KEY ), 'none' );
	const hit = edge.request( '/article-1/', { 'user-agent': CHROME } );
	assert.equal( hit.verdict, 'HIT' );
	assert.equal( header( hit, KEY ), 'none' );
	const narrowed = edge.request( '/article-1/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
	} );
	assert.equal( header( narrowed, KEY ), 'vc-v1__subscriber_--_1' );
	const bot = edge.request( '/article-1/', { 'user-agent': GOOGLEBOT } );
	assert.equal( header( bot, KEY ), 'vc-v1__crawlers-search_--_1' );
} );

test( 'crawlers are never metered', () => {
	const bot = edge.request( '/metered/', { 'user-agent': GOOGLEBOT } );
	assert.deepEqual( setCookies( bot ), [] );
} );

test( 'bypassed requests are forwarded untouched, never stored, and never touch KV', () => {
	const admin = edge.request( '/wp-admin/index.php', {
		'user-agent': CHROME,
	} );
	assert.equal( admin.verdict, 'PASS' );
	assert.equal( header( admin, 'x-origin-edge' ), '1', 'phase 1 still runs' );
	assert.equal( header( admin, KEY ), 'bypass' );
	const post = edge.request(
		'/article-1/',
		{ 'user-agent': CHROME },
		'POST'
	);
	assert.equal( post.verdict, 'PASS' );
	const loggedIn = edge.request( '/article-1/', {
		'user-agent': CHROME,
		cookie: 'wordpress_logged_in_abc=1; vip-go-seg=vc-v1__subscriber_--_1',
	} );
	assert.equal( loggedIn.verdict, 'PASS' );
	assert.equal(
		header( loggedIn, 'x-origin-seg' ),
		'',
		'no rewrite on a bypass'
	);
	assert.equal(
		header( loggedIn, 'x-origin-cookie' ),
		'wordpress_logged_in_abc=1; vip-go-seg=vc-v1__subscriber_--_1',
		'the cookie is forwarded untouched'
	);
	const auth = edge.request( '/wp-json/wp/v2/posts', {
		authorization: 'Basic x',
	} );
	assert.equal( auth.verdict, 'PASS' );
	const asset = edge.request( '/wp-content/themes/x/style.css', {
		'user-agent': CHROME,
	} );
	assert.equal( asset.verdict, 'PASS' );
	assert.equal( edge.stats.passes, 5 );
	assert.equal( edge.stored().length, 0 );
	assert.equal( edge.kv.size, 0, 'a bypass never reads or writes KV' );

	// A logged-in view of a metered page is not metered.
	const editor = edge.request( '/metered/', {
		'user-agent': CHROME,
		cookie: 'wordpress_logged_in_abc=1',
	} );
	assert.equal( editor.verdict, 'PASS' );
	assert.deepEqual( setCookies( editor ), [] );
} );

test( 'REST responses are cacheable like any GET', () => {
	declaredByPath.set( '/wp-json/wp/v2/posts', 'subscriber' );
	edge.request( '/wp-json/wp/v2/posts', { 'user-agent': CHROME } );
	const second = edge.request( '/wp-json/wp/v2/posts', {
		'user-agent': CHROME,
	} );
	assert.equal( second.verdict, 'HIT' );
} );

test( 'responses with Set-Cookie are not stored; unsegmented ones have one copy', () => {
	edge.request( '/set-cookie/', { 'user-agent': CHROME } );
	const again = edge.request( '/set-cookie/', { 'user-agent': CHROME } );
	assert.equal( again.verdict, 'MISS' );

	edge.request( '/plain/', { cookie: 'vip-go-seg=vc-v1__subscriber_--_1' } );
	const plain = edge.request( '/plain/', {
		cookie: 'vip-go-seg=vc-v1__donor_--_1',
	} );
	assert.equal( plain.verdict, 'HIT', 'no Vary, so one copy for everyone' );
} );

test( 'a purge removes the copies of one URL and keeps every record', () => {
	edge.request( '/article-1/', { 'user-agent': CHROME } );
	edge.request( '/about/', { 'user-agent': CHROME } );
	assert.equal( edge.purge( '/article-1/' ), 1 );
	assert.deepEqual( edge.stored(), [ [ '/about/', '' ] ] );
	assert.equal( edge.kv.size, 2, 'purge keeps records' );
} );
