/**
 * Shared test helpers.
 */

import { createHash, createHmac } from 'node:crypto';
import { Crypto } from '../lib/crypto.ts';

/** Node-backed hash provider, the one the edge harness uses too. */
export const nodeCrypto = new Crypto(
	( input: string ) => createHash( 'sha256' ).update( input ).digest( 'hex' ),
	( secret: string, message: string ) =>
		createHmac( 'sha256', secret ).update( message ).digest( 'hex' )
);

/** A fixed "now" for meter tests: 2026-10-22T12:00:00Z. */
export const NOW = 1792670400;

export const DAY = 86400;
