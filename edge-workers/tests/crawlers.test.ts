import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { renderCrawlerConfig } from '../scripts/crawlers-config.mjs';
import {
	CRAWLER_KEYS,
	CRAWLER_PATTERNS,
	CRAWLER_VERSION,
} from '../lib/crawlers-config.ts';
import {
	classifyCrawler,
	crawlerKeys,
	crawlerVersion,
} from '../lib/crawlers.ts';

const here = dirname( fileURLToPath( import.meta.url ) );

test( 'crawlers-config.ts matches config/crawlers.json', () => {
	const json = readFileSync(
		resolve( here, '../../config/crawlers.json' ),
		'utf8'
	);
	const config = JSON.parse( json ) as {
		version: number;
		segments: Record< string, { user_agents: string[] } >;
	};
	const keys = Object.keys( config.segments ).sort();
	const stale =
		'edge-workers/lib/crawlers-config.ts is stale: run `npm run edge:build`';
	assert.equal( CRAWLER_VERSION, config.version, stale );
	assert.deepEqual( CRAWLER_KEYS, keys, stale );
	assert.deepEqual(
		CRAWLER_PATTERNS,
		keys.map( ( key ) => config.segments[ key ].user_agents ),
		stale
	);
	assert.equal( crawlerVersion(), String( config.version ) );
} );

test( 'the generator renders every key and pattern from the JSON', () => {
	const rendered = renderCrawlerConfig(
		JSON.stringify( {
			version: 7,
			segments: {
				b: { user_agents: [ "It's", 'x' ] },
				a: { user_agents: [] },
			},
		} )
	);
	assert.match( rendered, /CRAWLER_VERSION: i32 = 7;/ );
	assert.match( rendered, /\t'a',\n\t'b',/ );
	assert.match( rendered, /'It\\'s'/ );
} );

test( 'classification is a case-insensitive substring match', () => {
	assert.deepEqual(
		classifyCrawler(
			'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
		),
		[ 'crawlers-search' ]
	);
	assert.deepEqual( classifyCrawler( 'mozilla/5.0 gptbot/1.0' ), [
		'crawlers-ai',
	] );
	assert.deepEqual(
		classifyCrawler( 'Mozilla/5.0 (Windows NT 10.0) Chrome/120' ),
		[]
	);
	assert.deepEqual( classifyCrawler( null ), [] );
} );

test( 'a user agent can match more than one segment', () => {
	assert.deepEqual( classifyCrawler( 'Googlebot Google-Extended' ), [
		'crawlers-ai',
		'crawlers-search',
	] );
} );

test( 'crawler keys are known and sorted', () => {
	assert.deepEqual( crawlerKeys(), [ 'crawlers-ai', 'crawlers-search' ] );
} );
