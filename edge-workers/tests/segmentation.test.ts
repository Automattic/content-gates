import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
	Groups,
	isToken,
	narrowGroups,
	parseSegmentation,
	serializeSegmentation,
	sortNames,
	withoutGroups,
} from '../lib/segmentation.ts';

test( 'parse and serialize round-trip in Vary_Cache format', () => {
	const raw = 'vc-v1__newsletter_--_1---__subscriber_--_1';
	const groups = parseSegmentation( raw );
	assert.deepEqual( groups.names, [ 'newsletter', 'subscriber' ] );
	assert.deepEqual( groups.values, [ '1', '1' ] );
	assert.equal( serializeSegmentation( groups ), raw );
} );

test( 'serialize sorts by name and skips empty values', () => {
	const groups = new Groups();
	groups.set( 'subscriber', '1' );
	groups.set( 'donor', 'yes' );
	groups.set( 'blank', '' );
	assert.equal(
		serializeSegmentation( groups ),
		'vc-v1__donor_--_yes---__subscriber_--_1'
	);
} );

test( 'parse tolerates null, empty, missing prefix, and junk fragments', () => {
	assert.equal( parseSegmentation( null ).names.length, 0 );
	assert.equal( parseSegmentation( '' ).names.length, 0 );
	assert.equal( parseSegmentation( 'vc-v1__' ).names.length, 0 );
	assert.equal( parseSegmentation( 'a_--_1' ).get( 'a' ), '1' );
	const junk = parseSegmentation(
		'vc-v1__nosep---__bad;name_--_1---__ok_--_1'
	);
	assert.deepEqual( junk.names, [ 'ok' ] );
} );

test( 'a later duplicate overwrites an earlier one, like Vary_Cache', () => {
	const groups = parseSegmentation( 'vc-v1__a_--_1---__a_--_2' );
	assert.equal( groups.get( 'a' ), '2' );
	assert.equal( groups.names.length, 1 );
} );

test( 'empty serialization when no groups remain', () => {
	assert.equal( serializeSegmentation( new Groups() ), '' );
} );

test( 'narrowGroups keeps only recorded keys', () => {
	const groups = parseSegmentation(
		'vc-v1__donor_--_1---__newsletter_--_1---__subscriber_--_1'
	);
	const narrowed = narrowGroups( groups, [
		'subscriber',
		'crawlers-search',
	] );
	assert.equal( serializeSegmentation( narrowed ), 'vc-v1__subscriber_--_1' );
	assert.equal( serializeSegmentation( narrowGroups( groups, [] ) ), '' );
} );

test( 'withoutGroups drops the named keys', () => {
	const groups = parseSegmentation(
		'vc-v1__crawlers-search_--_1---__subscriber_--_1'
	);
	const stripped = withoutGroups( groups, [ 'crawlers-search' ] );
	assert.deepEqual( stripped.names, [ 'subscriber' ] );
} );

test( 'isToken accepts the cookie-safe set only', () => {
	assert.equal( isToken( 'metered-articles_2' ), true );
	assert.equal( isToken( '' ), false );
	assert.equal( isToken( 'a b' ), false );
	assert.equal( isToken( 'a;b' ), false );
} );

test( 'sortNames orders by code unit without mutating input', () => {
	const input = [ 'b', 'B', 'a', '1-x' ];
	assert.deepEqual( sortNames( input ), [ '1-x', 'B', 'a', 'b' ] );
	assert.deepEqual( input, [ 'b', 'B', 'a', '1-x' ] );
} );
