import assert from 'node:assert/strict';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import { after, before, beforeEach, test } from 'node:test';
import { startEdgeSim } from '../sim/server.ts';
import type { EdgeSim } from '../sim/server.ts';

const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
const CHROME = 'Mozilla/5.0 Chrome/120';

/**
 * What the fake origin declares per path. Tests mutate it to emulate an
 * editor changing content.
 */
const declaredByPath = new Map< string, string >();

let originServer: http.Server;
let originHits = 0;
let sim: EdgeSim;

/**
 * Fake WordPress origin: answers like the plugin does, echoing the request
 * facts the tests assert on.
 *
 * @param req Incoming request.
 * @param res Server response.
 */
function origin( req: http.IncomingMessage, res: http.ServerResponse ): void {
	originHits++;
	const url = req.url ?? '/';
	const path = url.split( '?' )[ 0 ];
	const headers: http.OutgoingHttpHeaders = {
		'content-type': 'text/html; charset=UTF-8',
		'cache-control': 'max-age=300, must-revalidate',
		'x-origin-seg': req.headers[ 'x-vip-go-segmentation' ] ?? '',
		'x-origin-cookie': req.headers.cookie ?? '',
		'x-origin-edge': req.headers[ 'x-wpvip-content-gates-edge' ] ?? '',
		'x-origin-hits': String( originHits ),
	};
	if ( path === '/static.css' ) {
		res.writeHead( 200, { ...headers, 'content-type': 'text/css' } );
		res.end( 'body{}' );
		return;
	}
	if ( path === '/plain/' ) {
		// A page without Vary_Cache: no segmentation Vary, no declared header.
		res.writeHead( 200, headers );
		res.end( 'plain' );
		return;
	}
	if ( path === '/set-cookie/' ) {
		res.writeHead( 200, { ...headers, 'set-cookie': 'session=abc' } );
		res.end( 'cookie' );
		return;
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
	res.writeHead( 200, headers );
	res.end( 'seg=' + ( req.headers[ 'x-vip-go-segmentation' ] ?? '' ) );
}

/**
 * GET through the simulator.
 *
 * @param path    Path and query.
 * @param headers Request headers.
 * @param method  HTTP method.
 * @return The response.
 */
function get(
	path: string,
	headers: Record< string, string > = {},
	method = 'GET'
) {
	return fetch( sim.url + path, { method, headers, redirect: 'manual' } );
}

/**
 * Cookie value from the response's Set-Cookie list.
 *
 * @param response Fetch response.
 * @param name     Cookie name.
 * @return The value or null.
 */
function setCookie( response: Response, name: string ): string | null {
	for ( const line of response.headers.getSetCookie() ) {
		if ( line.startsWith( name + '=' ) ) {
			return line.slice( name.length + 1 ).split( ';' )[ 0 ];
		}
	}
	return null;
}

/**
 * Simulator stats.
 *
 * @return Parsed JSON.
 */
async function stats() {
	const response = await get( '/__edge/stats' );
	return ( await response.json() ) as {
		hits: number;
		misses: number;
		passes: number;
		entries: Array< { url: string; key: string } >;
		kv: Array< { url: string; declared: string[] } >;
	};
}

before( async () => {
	originServer = http.createServer( origin );
	await new Promise< void >( ( resolve ) =>
		originServer.listen( 0, '127.0.0.1', resolve )
	);
	const port = ( originServer.address() as AddressInfo ).port;
	sim = await startEdgeSim( {
		origin: 'http://127.0.0.1:' + port,
		port: 0,
		secret: 'test-secret',
	} );
} );

after( async () => {
	await sim.close();
	originServer.closeAllConnections();
	await new Promise< void >( ( resolve ) =>
		originServer.close( () => resolve() )
	);
} );

beforeEach( async () => {
	await fetch( sim.url + '/__edge/reset', { method: 'POST' } );
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

test( 'two readers who differ only in an undeclared segment share one copy', async () => {
	// First request: no record, full value, origin learns [subscriber, ...].
	const first = await get( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( first.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal(
		first.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__subscriber_--_1'
	);
	assert.equal( first.headers.get( 'x-origin-edge' ), '1' );
	assert.equal(
		first.headers.get( 'x-edge-sim-record' ),
		'crawlers-ai, crawlers-search, subscriber'
	);

	// Second reader: also on the newsletter. Narrowed to the same key.
	const second = await get( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( second.headers.get( 'x-edge-sim' ), 'HIT' );
	assert.equal(
		second.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__subscriber_--_1'
	);
	assert.equal( await second.text(), 'seg=vc-v1__subscriber_--_1' );
	assert.equal(
		second.headers.get( 'x-origin-hits' ),
		'1',
		'served from the first copy'
	);

	// Anonymous and newsletter-only readers share the anonymous copy.
	const anon = await get( '/article-1/', { 'user-agent': CHROME } );
	assert.equal( anon.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal( anon.headers.get( 'x-wpvip-content-gates-key' ), 'none' );
	const newsletter = await get( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( newsletter.headers.get( 'x-edge-sim' ), 'HIT' );

	const summary = await stats();
	assert.equal( summary.entries.length, 2 );
	assert.equal( summary.hits, 2 );
	assert.equal( summary.misses, 2 );
} );

test( 'a page that declares nothing has one copy for everyone', async () => {
	// The first miss is keyed on the full value (no record yet) and teaches
	// the edge the empty set; from then on every reader shares one copy.
	await get( '/about/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );
	const learned = await get( '/about/', {
		cookie: 'vip-go-seg=vc-v1__donor_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( learned.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal( learned.headers.get( 'x-wpvip-content-gates-key' ), 'none' );
	const other = await get( '/about/', {
		cookie: 'vip-go-seg=vc-v1__donor_--_1---__newsletter_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( other.headers.get( 'x-edge-sim' ), 'HIT' );
	assert.equal( other.headers.get( 'x-wpvip-content-gates-key' ), 'none' );
	assert.equal( other.headers.get( 'x-edge-sim-record' ), '(empty)' );
} );

test( 'a too-narrow record causes no-store, then the record widens', async () => {
	// Learn [subscriber] first.
	declaredByPath.set( '/article-1/', 'subscriber' );
	await get( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );

	// An editor adds a newsletter gate; the save purges the URL.
	declaredByPath.set( '/article-1/', 'newsletter, subscriber' );
	await fetch( sim.url + '/__edge/purge', {
		method: 'POST',
		body: JSON.stringify( { url: '/article-1/' } ),
	} );

	const narrowed = await get( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( narrowed.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal(
		narrowed.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__subscriber_--_1'
	);
	assert.equal( narrowed.headers.get( 'cache-control' ), 'no-store' );
	assert.equal(
		narrowed.headers.get( 'x-edge-sim-record' ),
		'newsletter, subscriber'
	);

	let summary = await stats();
	assert.equal(
		summary.entries.length,
		0,
		'the too-narrow response was not stored'
	);
	assert.deepEqual( summary.kv[ 0 ].declared, [
		'newsletter',
		'subscriber',
	] );

	const widened = await get( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__newsletter_--_1---__subscriber_--_1',
		'user-agent': CHROME,
	} );
	assert.equal( widened.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal(
		widened.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__newsletter_--_1---__subscriber_--_1'
	);
	assert.equal(
		widened.headers.get( 'cache-control' ),
		'max-age=300, must-revalidate'
	);
	summary = await stats();
	assert.equal( summary.entries.length, 1 );

	// Removing the gate again shrinks the record on the next miss.
	declaredByPath.set( '/article-1/', 'subscriber' );
	await fetch( sim.url + '/__edge/purge', { method: 'POST' } );
	await get( '/article-1/', {
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
		'user-agent': CHROME,
	} );
	summary = await stats();
	assert.deepEqual( summary.kv[ 0 ].declared, [ 'subscriber' ] );
} );

test( 'segmented responses without a declared header are never stored', async () => {
	const first = await get( '/undeclared/', { 'user-agent': CHROME } );
	assert.equal( first.headers.get( 'cache-control' ), 'no-store' );
	const second = await get( '/undeclared/', { 'user-agent': CHROME } );
	assert.equal( second.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal( ( await stats() ).entries.length, 0 );
} );

test( 'a crawler gets the crawler-keyed copy and no Set-Cookie', async () => {
	// Learn the record with a human first.
	await get( '/article-1/', { 'user-agent': CHROME } );

	const bot = await get( '/article-1/', {
		'user-agent': GOOGLEBOT,
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
	} );
	assert.equal( bot.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal(
		bot.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__crawlers-search_--_1---__subscriber_--_1'
	);
	assert.equal(
		bot.headers.get( 'x-origin-cookie' ),
		'vip-go-seg=vc-v1__crawlers-search_--_1---__subscriber_--_1',
		'origin sees the worker classification in the cookie'
	);
	assert.deepEqual( bot.headers.getSetCookie(), [] );

	const again = await get( '/article-1/', { 'user-agent': GOOGLEBOT } );
	assert.equal(
		again.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__crawlers-search_--_1'
	);
	assert.equal( again.headers.get( 'x-edge-sim' ), 'MISS' );
	const third = await get( '/article-1/', {
		'user-agent': 'Googlebot-Image/1.0',
	} );
	assert.equal( third.headers.get( 'x-edge-sim' ), 'HIT' );
} );

test( 'a forged crawler cookie from a browser gains nothing', async () => {
	await get( '/article-1/', { 'user-agent': CHROME } );
	const forged = await get( '/article-1/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=vc-v1__crawlers-search_--_1',
	} );
	assert.equal(
		forged.headers.get( 'x-edge-sim' ),
		'HIT',
		'shares the anonymous copy'
	);
	assert.equal( forged.headers.get( 'x-wpvip-content-gates-key' ), 'none' );
	const miss = await get( '/article-1/?v=2', {
		'user-agent': CHROME,
		cookie: 'other=1; vip-go-seg=vc-v1__crawlers-ai_--_1',
	} );
	assert.equal(
		miss.headers.get( 'x-origin-cookie' ),
		'other=1',
		'the crawler value never reaches origin'
	);
} );

test( 'metered views advance the cookie on cache hits', async () => {
	// Reader A, first visit: anonymous copy, fresh record, state open.
	const first = await get( '/metered/', { 'user-agent': CHROME } );
	assert.equal( first.headers.get( 'x-edge-sim' ), 'MISS' );
	const segA = setCookie( first, 'vip-go-seg' );
	const meterA = setCookie( first, 'vip-go-meter' );
	assert.equal( segA, 'vc-v1__metered-articles_--_open' );
	assert.ok( meterA );
	assert.match( first.headers.getSetCookie()[ 0 ], /HttpOnly/ );

	// Reader A, second visit: keyed on open, counted, now last. Stored.
	const second = await get( '/metered/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=' + segA + '; vip-go-meter=' + meterA,
	} );
	assert.equal( second.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal(
		second.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__metered-articles_--_open'
	);
	assert.equal(
		setCookie( second, 'vip-go-seg' ),
		'vc-v1__metered-articles_--_last'
	);
	assert.equal(
		second.headers.get( 'x-origin-cookie' ),
		'vip-go-seg=' + segA + '; vip-go-meter=' + meterA
	);

	// Reader B, first visit then second visit: the second is a HIT on the
	// copy stored for reader A, and the meter still advances.
	const firstB = await get( '/metered/', { 'user-agent': CHROME } );
	assert.equal( firstB.headers.get( 'x-edge-sim' ), 'HIT' );
	const segB = setCookie( firstB, 'vip-go-seg' );
	const meterB = setCookie( firstB, 'vip-go-meter' );
	assert.ok( meterB );
	const secondB = await get( '/metered/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=' + segB + '; vip-go-meter=' + meterB,
	} );
	assert.equal( secondB.headers.get( 'x-edge-sim' ), 'HIT' );
	assert.equal(
		setCookie( secondB, 'vip-go-seg' ),
		'vc-v1__metered-articles_--_last'
	);
	assert.notEqual( setCookie( secondB, 'vip-go-meter' ), meterB );

	// Stored copies carry no Set-Cookie: the worker adds them per delivery.
	const summary = await stats();
	assert.equal( summary.entries.length, 2 );
} );

test( 'an origin assignment on a metered miss reaches the browser once, with the meter state', async () => {
	declaredByPath.set( '/metered-assign/', 'metered-articles, subscriber' );
	const response = await get( '/metered-assign/', { 'user-agent': CHROME } );
	assert.equal( response.headers.get( 'x-edge-sim' ), 'MISS' );
	const cookies = response.headers.getSetCookie();
	const segs = cookies.filter( ( c ) => c.startsWith( 'vip-go-seg=' ) );
	assert.equal( segs.length, 1 );
	assert.equal(
		segs[ 0 ],
		'vip-go-seg=vc-v1__metered-articles_--_open---__subscriber_--_1; Path=/; Max-Age=86400'
	);
	assert.equal( cookies[ 0 ], 'session=abc; Path=/' );
	assert.ok( setCookie( response, 'vip-go-meter' ) );

	// A response with Set-Cookie is never stored.
	const again = await get( '/metered-assign/', { 'user-agent': CHROME } );
	assert.equal( again.headers.get( 'x-edge-sim' ), 'MISS' );
} );

test( 'diagnostic headers travel with the copy (phase 3) and per delivery (phase 4)', async () => {
	const miss = await get( '/article-1/', { 'user-agent': CHROME } );
	assert.equal( miss.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal( miss.headers.get( 'x-wpvip-content-gates-key' ), 'none' );

	const hit = await get( '/article-1/', { 'user-agent': CHROME } );
	assert.equal( hit.headers.get( 'x-edge-sim' ), 'HIT' );
	assert.equal(
		hit.headers.get( 'x-wpvip-content-gates-key' ),
		'none',
		"a hit resolved to the stored copy's key by definition"
	);

	const narrowed = await get( '/article-1/', {
		'user-agent': CHROME,
		cookie: 'vip-go-seg=vc-v1__subscriber_--_1',
	} );
	assert.equal( narrowed.headers.get( 'x-edge-sim' ), 'MISS' );
	assert.equal(
		narrowed.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__subscriber_--_1'
	);

	const undeclared = await get( '/undeclared/', { 'user-agent': CHROME } );
	assert.equal(
		undeclared.headers.get( 'x-wpvip-content-gates-key' ),
		'none',
		'a no-store response still reports the key it resolved to'
	);
	assert.equal(
		undeclared.headers.get( 'x-wpvip-content-gates-declared' ),
		null,
		'the missing declared header is the evidence of the origin fault'
	);

	const bot = await get( '/article-1/', { 'user-agent': GOOGLEBOT } );
	assert.equal(
		bot.headers.get( 'x-wpvip-content-gates-key' ),
		'vc-v1__crawlers-search_--_1'
	);
} );

test( 'crawlers are never metered', async () => {
	const bot = await get( '/metered/', { 'user-agent': GOOGLEBOT } );
	assert.deepEqual( bot.headers.getSetCookie(), [] );
} );

test( 'pass-through requests are forwarded and never cached', async () => {
	const admin = await get( '/wp-admin/index.php', { 'user-agent': CHROME } );
	assert.equal( admin.headers.get( 'x-edge-sim' ), 'PASS' );
	assert.equal(
		admin.headers.get( 'x-origin-edge' ),
		'1',
		'phase 1 still runs'
	);
	const post = await get( '/article-1/', { 'user-agent': CHROME }, 'POST' );
	assert.equal( post.headers.get( 'x-edge-sim' ), 'PASS' );
	const loggedIn = await get( '/article-1/', {
		'user-agent': CHROME,
		cookie: 'wordpress_logged_in_abc=1; vip-go-seg=vc-v1__subscriber_--_1',
	} );
	assert.equal( loggedIn.headers.get( 'x-edge-sim' ), 'PASS' );
	assert.equal(
		loggedIn.headers.get( 'x-origin-seg' ),
		'',
		'no rewrite on a bypass: the segmentation header is not set'
	);
	assert.equal(
		loggedIn.headers.get( 'x-origin-cookie' ),
		'wordpress_logged_in_abc=1; vip-go-seg=vc-v1__subscriber_--_1',
		'the cookie is forwarded untouched'
	);
	const auth = await get( '/wp-json/wp/v2/posts', {
		authorization: 'Basic x',
	} );
	assert.equal( auth.headers.get( 'x-edge-sim' ), 'PASS' );
	const asset = await get( '/wp-content/themes/x/style.css', {
		'user-agent': CHROME,
	} );
	assert.equal( asset.headers.get( 'x-edge-sim' ), 'PASS' );
	const summary = await stats();
	assert.equal( summary.passes, 5 );
	assert.equal( summary.entries.length, 0 );
	assert.equal( summary.kv.length, 0, 'a bypass never reads or writes KV' );

	// A logged-in view of a metered page is not metered.
	const editor = await get( '/metered/', {
		'user-agent': CHROME,
		cookie: 'wordpress_logged_in_abc=1',
	} );
	assert.equal( editor.headers.get( 'x-edge-sim' ), 'PASS' );
	assert.deepEqual( editor.headers.getSetCookie(), [] );
	assert.equal( editor.headers.get( 'x-wpvip-content-gates-key' ), 'bypass' );
} );

test( 'REST responses are cacheable like any GET', async () => {
	declaredByPath.set( '/wp-json/wp/v2/posts', 'subscriber' );
	await get( '/wp-json/wp/v2/posts', { 'user-agent': CHROME } );
	const second = await get( '/wp-json/wp/v2/posts', {
		'user-agent': CHROME,
	} );
	assert.equal( second.headers.get( 'x-edge-sim' ), 'HIT' );
} );

test( 'responses with Set-Cookie are not stored; unsegmented ones are', async () => {
	await get( '/set-cookie/', { 'user-agent': CHROME } );
	const again = await get( '/set-cookie/', { 'user-agent': CHROME } );
	assert.equal( again.headers.get( 'x-edge-sim' ), 'MISS' );

	await get( '/plain/', { cookie: 'vip-go-seg=vc-v1__subscriber_--_1' } );
	const plain = await get( '/plain/', {
		cookie: 'vip-go-seg=vc-v1__donor_--_1',
	} );
	assert.equal(
		plain.headers.get( 'x-edge-sim' ),
		'HIT',
		'no Vary, so one copy for everyone'
	);

	// Static files are bypassed before the KV read and never stored here.
	const css = await get( '/static.css', { 'user-agent': CHROME } );
	assert.equal( css.headers.get( 'x-edge-sim' ), 'PASS' );
} );

test( 'purge removes variants of one URL; reset clears KV too', async () => {
	await get( '/article-1/', { 'user-agent': CHROME } );
	await get( '/about/', { 'user-agent': CHROME } );
	const purged = await fetch( sim.url + '/__edge/purge', {
		method: 'POST',
		body: JSON.stringify( { url: '/article-1/' } ),
	} );
	assert.deepEqual( await purged.json(), { purged: 1, url: '/article-1/' } );
	let summary = await stats();
	assert.equal( summary.entries.length, 1 );
	assert.equal( summary.kv.length, 2, 'purge keeps records' );

	await fetch( sim.url + '/__edge/reset', { method: 'POST' } );
	summary = await stats();
	assert.equal( summary.entries.length, 0 );
	assert.equal( summary.kv.length, 0 );
	assert.equal( summary.hits + summary.misses + summary.passes, 0 );
} );
