/**
 * The declared set: header parsing, KV record encoding, coverage check.
 *
 * Origin sends `X-WPVIP-Content-Gates-Declared: donor, subscriber` on every
 * response that reached the plugin's `send_headers` callback. The worker
 * learns each URL's declared set from it and keeps it in edge KV so later
 * requests can be narrowed before cache lookup.
 *
 * Portability rules: see segmentation.ts.
 */

import { isToken, sortNames } from './segmentation.ts';

/**
 * Prefix on every KV record. Lets a future format change be detected
 * instead of mis-parsed, and makes an empty declared set (`v1:`) distinct
 * from an absent record.
 */
export const RECORD_PREFIX: string = 'v1:';

/**
 * Parse a comma-separated list of group names into a sorted, de-duplicated
 * list. Invalid names are dropped.
 *
 * @param value Header or record payload; may be empty.
 * @return Sorted unique group names.
 */
export function parseNameList( value: string ): string[] {
	const names: string[] = [];
	const parts = value.split( ',' );
	for ( let i = 0; i < parts.length; i++ ) {
		const name = parts[ i ].trim();
		if ( ! isToken( name ) || names.indexOf( name ) >= 0 ) {
			continue;
		}
		names.push( name );
	}
	return sortNames( names );
}

/**
 * Join names in the declared header's format: sorted, comma and space.
 *
 * @param names Group names.
 * @return The joined list, empty when none.
 */
export function joinNames( names: string[] ): string {
	return sortNames( names ).join( ', ' );
}

/**
 * Encode a declared set as a KV record.
 *
 * @param names Group names.
 * @return The record value.
 */
export function encodeRecord( names: string[] ): string {
	return RECORD_PREFIX + sortNames( names ).join( ',' );
}

/**
 * Decode a KV record. An absent, foreign, or malformed record decodes to
 * null, which callers treat as "no record": the lookup falls back to the
 * full segmentation value, which is always safe.
 *
 * @param raw Record value from KV, or null.
 * @return Group names, or null.
 */
export function decodeRecord( raw: string | null ): string[] | null {
	if ( raw === null || ! raw.startsWith( RECORD_PREFIX ) ) {
		return null;
	}
	return parseNameList( raw.substring( RECORD_PREFIX.length ) );
}

/**
 * Whether every name in `a` is also in `b`.
 *
 * @param a Names that must be present.
 * @param b Names available.
 * @return True when `a` is a subset of `b`.
 */
export function isSubset( a: string[], b: string[] ): bool {
	for ( let i = 0; i < a.length; i++ ) {
		if ( b.indexOf( a[ i ] ) < 0 ) {
			return false;
		}
	}
	return true;
}

/**
 * Whether the two lists hold the same names.
 *
 * @param a First list.
 * @param b Second list.
 * @return True when equal as sets.
 */
export function sameNames( a: string[], b: string[] ): bool {
	return a.length == b.length && isSubset( a, b );
}

/**
 * Coverage check from spec/page-cache.md, "Phase 3".
 *
 * A response may be stored only if the key it will be stored under
 * distinguishes every segment the render could depend on. When no record
 * existed, the lookup used the reader's full value, which covers every
 * group by definition. When the lookup was narrowed, the record's keys are
 * what the key preserved, so every declared group must be among them.
 *
 * The check is on the record's keys, not on the lookup value: a reader who
 * is simply not in a declared group has no value for it, and that copy is
 * still correctly keyed for every reader the record maps to the same key.
 *
 * @param declared   Group names the response declared.
 * @param lookupFull Whether phase 1 passed the full value through.
 * @param recordKeys Group names in the record phase 1 narrowed with.
 * @return True when the response may be stored.
 */
export function isCovered(
	declared: string[],
	lookupFull: bool,
	recordKeys: string[]
): bool {
	if ( lookupFull ) {
		return true;
	}
	return isSubset( declared, recordKeys );
}
