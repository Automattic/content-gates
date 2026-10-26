import assert from 'node:assert/strict';
import { test } from 'node:test';
import { bypassReason } from '../lib/bypass.ts';
import {
	ClientResponseInput,
	LOOKUP_BYPASS,
	OriginResponseInput,
	onClientResponsePure,
	onOriginResponsePure,
} from '../lib/worker.ts';
import { DAY, NOW, nodeCrypto } from './helpers.ts';

const CHROME = 'Mozilla/5.0 Chrome/120';
const METER = 'metered-articles; id=123; limit=2; period=' + 30 * DAY;

test( 'bypassReason names the requests the worker leaves alone', () => {
	assert.equal(
		bypassReason( 'POST', '/article-1/', null, false ),
		'method'
	);
	assert.equal(
		bypassReason( 'GET', '/wp-json/wp/v2/posts', null, true ),
		'authorization'
	);
	assert.equal(
		bypassReason( 'GET', '/wp-admin/edit.php', null, false ),
		'path'
	);
	assert.equal(
		bypassReason( 'GET', '/wp-login.php?redirect_to=x', null, false ),
		'path'
	);
	assert.equal(
		bypassReason( 'GET', '/wp-content/uploads/2026/10/a.png', null, false ),
		'path',
		'prefix wins over extension'
	);
	assert.equal(
		bypassReason( 'GET', '/assets/app.css?ver=1', null, false ),
		'static'
	);
	assert.equal( bypassReason( 'HEAD', '/Hero.JPG', null, false ), 'static' );
	assert.equal(
		bypassReason(
			'GET',
			'/article-1/',
			'a=1; wordpress_logged_in_abc=1',
			false
		),
		'session'
	);
	assert.equal(
		bypassReason( 'GET', '/article-1/', 'vip-go-cb=1', false ),
		'session'
	);
} );

test( 'bypassReason handles what the cache can store', () => {
	assert.equal( bypassReason( 'GET', '/article-1/', null, false ), '' );
	assert.equal(
		bypassReason( 'HEAD', '/article-1/?utm=1', null, false ),
		''
	);
	assert.equal( bypassReason( 'GET', '/feed/', null, false ), '' );
	assert.equal(
		bypassReason( 'GET', '/wp-json/wp/v2/posts?_embed=1', null, false ),
		''
	);
	assert.equal(
		bypassReason( 'GET', '/sitemap.xml', null, false ),
		'',
		'XML is not on the static list'
	);
	assert.equal(
		bypassReason(
			'GET',
			'/article-1/',
			'vip-go-seg=vc-v1__subscriber_--_1',
			false
		),
		''
	);
} );

test( 'phase 3 never stores a response phase 1 bypassed', () => {
	const input = new OriginResponseInput();
	input.vary = 'X-VIP-Go-Segmentation';
	input.declared = 'subscriber';
	input.lookup = LOOKUP_BYPASS;
	const out = onOriginResponsePure( input );
	assert.equal( out.noStore, true );
	assert.equal( out.reason, 'bypassed' );
	assert.equal( out.record, null );
	assert.equal( out.key, 'bypass' );

	// A static file is unsegmented: nothing to do, as before.
	const asset = new OriginResponseInput();
	asset.lookup = LOOKUP_BYPASS;
	assert.equal( onOriginResponsePure( asset ).noStore, false );
} );

test( 'phase 4 does not meter a bypassed request', () => {
	const post = new ClientResponseInput();
	post.meterHeaders = [ METER ];
	post.userAgent = CHROME;
	post.secret = 'test-secret';
	post.now = NOW;
	post.method = 'POST';
	post.url = '/metered/';
	const out = onClientResponsePure( post, nodeCrypto );
	assert.equal( out.replace, false );
	assert.equal( out.skipped, 'bypass' );

	const loggedIn = new ClientResponseInput();
	loggedIn.meterHeaders = [ METER ];
	loggedIn.userAgent = CHROME;
	loggedIn.secret = 'test-secret';
	loggedIn.now = NOW;
	loggedIn.url = '/metered/';
	loggedIn.cookie = 'wordpress_logged_in_abc=1';
	assert.equal(
		onClientResponsePure( loggedIn, nodeCrypto ).skipped,
		'bypass'
	);

	const reader = new ClientResponseInput();
	reader.meterHeaders = [ METER ];
	reader.userAgent = CHROME;
	reader.secret = 'test-secret';
	reader.now = NOW;
	reader.url = '/metered/';
	assert.equal( onClientResponsePure( reader, nodeCrypto ).replace, true );
} );
