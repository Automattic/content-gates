import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
	COVERAGE_HEADER,
	ClientResponseInput,
	ClientResponseOutput,
	METER_DIAGNOSTIC_HEADER,
	OriginResponseInput,
	coverageDiagnostic,
	meterDiagnostic,
	onClientResponsePure,
	onOriginResponsePure,
} from '../assembly/worker.ts';
import { DAY, NOW, nodeCrypto } from './helpers.ts';

/**
 * The diagnostic headers are the only window into the worker on a platform
 * without logs, so their exact text is part of the contract.
 */

test( 'diagnostic header names are stable', () => {
	assert.equal( COVERAGE_HEADER, 'x-wpvip-content-gates-edge-coverage' );
	assert.equal( METER_DIAGNOSTIC_HEADER, 'x-wpvip-content-gates-edge-meter' );
} );

test( 'phase 3 reports its reason and the markers it read', () => {
	const full = new OriginResponseInput();
	full.vary = 'X-VIP-Go-Segmentation';
	full.declared = 'subscriber';
	full.lookup = 'full';
	full.recordKeys = '';
	assert.equal(
		onOriginResponsePure( full ).diagnostic,
		'covered; lookup=full; record=none'
	);

	const narrowed = new OriginResponseInput();
	narrowed.vary = 'X-VIP-Go-Segmentation';
	narrowed.declared = 'newsletter, subscriber';
	narrowed.lookup = 'narrowed';
	narrowed.recordKeys = 'subscriber';
	assert.equal(
		onOriginResponsePure( narrowed ).diagnostic,
		'narrowed-too-far; lookup=narrowed; record=subscriber'
	);

	const missing = new OriginResponseInput();
	missing.vary = 'X-VIP-Go-Segmentation';
	missing.declared = 'subscriber';
	assert.equal(
		onOriginResponsePure( missing ).diagnostic,
		'no-marker; lookup=-; record=-',
		'absent markers read as "-", which answers the resp.request question'
	);

	const plain = new OriginResponseInput();
	assert.equal(
		onOriginResponsePure( plain ).diagnostic,
		'unsegmented; lookup=-; record=-'
	);
} );

test( 'coverageDiagnostic normalizes the record and rejects odd lookups', () => {
	assert.equal(
		coverageDiagnostic( 'covered', 'narrowed', 'subscriber, donor' ),
		'covered; lookup=narrowed; record=donor,subscriber'
	);
	assert.equal(
		coverageDiagnostic( 'no-marker', 'full lookup', null ),
		'no-marker; lookup=?; record=-'
	);
} );

test( 'phase 4 reports advanced meters or the skip reason, with the Age header', () => {
	const meter = 'metered-articles; id=123; limit=2; period=' + 30 * DAY;

	const human = new ClientResponseInput();
	human.meterHeaders = [ meter ];
	human.userAgent = 'Mozilla/5.0 Chrome/120';
	human.secret = 'test-secret';
	human.now = NOW;
	const advanced = onClientResponsePure( human, nodeCrypto );
	assert.equal(
		meterDiagnostic( advanced, '12' ),
		'advanced; meters=1; age=12'
	);
	assert.equal(
		meterDiagnostic( advanced, null ),
		'advanced; meters=1; age=-'
	);

	const crawler = new ClientResponseInput();
	crawler.meterHeaders = [ meter ];
	crawler.userAgent = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
	crawler.secret = 'test-secret';
	crawler.now = NOW;
	assert.equal(
		meterDiagnostic( onClientResponsePure( crawler, nodeCrypto ), '0' ),
		'skipped; reason=crawler; age=0'
	);

	const odd = new ClientResponseOutput();
	odd.skipped = 'no-date';
	assert.equal(
		meterDiagnostic( odd, 'soon' ),
		'skipped; reason=no-date; age=?'
	);
} );
