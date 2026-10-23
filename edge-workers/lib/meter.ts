/**
 * Anonymous metering: the meter header, the private cookie, and the
 * state machine from spec/page-cache.md, "Anonymous metering".
 *
 * All state lives in the reader's cookies. `vip-go-seg` carries only the
 * meter's state (`open`, `last`, `wall`) as a normal group value, so
 * narrowing and cache lookup need no meter logic. `vip-go-meter` is
 * private to the worker and signed, so a forged or edited cookie fails
 * verification and is treated as absent.
 *
 * `vip-go-meter` encoding (the spec leaves it open; this is the choice):
 *
 *     v1.<record>~<record>.<signature>
 *     record    = <group>:<periodStart>:<count>:<seen>
 *     seen      = <hash>_<hash>_...      (may be empty)
 *     hash      = first 8 hex chars of sha256(postId)
 *     signature = first 32 hex chars of hmacSha256(secret, "v1.<payload>")
 *
 * Every character is cookie-safe (RFC 6265 forbids `;`, `,`, space, `"`
 * and `\`), and a meter of ten costs under 100 bytes. Hashing the post IDs
 * keeps a reader's history out of the cookie while still letting repeat
 * views of one post count once.
 *
 * Portability rules: see segmentation.ts.
 */

import { Crypto, constantTimeEquals } from './crypto.ts';
import { parseNonNegativeInt } from './httpdate.ts';
import { isToken } from './segmentation.ts';

export const METER_COOKIE: string = 'vip-go-meter';
export const STATE_OPEN: string = 'open';
export const STATE_LAST: string = 'last';
export const STATE_WALL: string = 'wall';

const COOKIE_VERSION: string = 'v1';
const SIGNATURE_LENGTH: i32 = 32;
const SEEN_HASH_LENGTH: i32 = 8;

/**
 * One `X-WPVIP-Content-Gates-Meter` header, parsed.
 */
export class MeterSpec {
	group: string = '';
	postId: string = '';
	limit: i32 = 0;
	period: f64 = 0;
}

/**
 * One meter's record inside the `vip-go-meter` cookie.
 */
export class MeterRecord {
	group: string = '';
	start: f64 = 0;
	count: i32 = 0;
	seen: string[] = [];
}

/**
 * Parse `metered-articles; id=123; limit=5; period=2592000`.
 *
 * @param value Header value.
 * @return The spec, or null when malformed.
 */
export function parseMeterHeader( value: string ): MeterSpec | null {
	const parts = value.split( ';' );
	const spec = new MeterSpec();
	spec.group = parts[ 0 ].trim();
	if ( ! isToken( spec.group ) ) {
		return null;
	}
	let limit: f64 = -1;
	for ( let i = 1; i < parts.length; i++ ) {
		const part = parts[ i ].trim();
		const eq = part.indexOf( '=' );
		if ( eq < 0 ) {
			continue;
		}
		const key = part.substring( 0, eq ).trim();
		const raw = part.substring( eq + 1 ).trim();
		if ( key == 'id' ) {
			spec.postId = raw;
		} else if ( key == 'limit' ) {
			limit = parseNonNegativeInt( raw );
		} else if ( key == 'period' ) {
			spec.period = parseNonNegativeInt( raw );
		}
	}
	if ( parseNonNegativeInt( spec.postId ) < 0 ) {
		return null;
	}
	if ( limit < 0 || spec.period <= 0 ) {
		return null;
	}
	spec.limit = limit as i32;
	return spec;
}

/**
 * Serialize an integral non-negative f64 without AssemblyScript's `.0`
 * suffix, so cookies written by either runtime parse in the other.
 *
 * @param value Integral value.
 * @return Decimal digits.
 */
export function formatInt( value: f64 ): string {
	let n = Math.floor( value );
	if ( n <= 0 ) {
		return '0';
	}
	let out = '';
	while ( n > 0 ) {
		const q = Math.floor( n / 10 );
		const digit = ( n - q * 10 ) as i32;
		out = String.fromCharCode( 48 + digit ) + out;
		n = q;
	}
	return out;
}

/**
 * The signature over a payload.
 *
 * @param payload Cookie payload, without version or signature.
 * @param secret  Signing secret.
 * @param crypto  Hash provider.
 * @return Truncated hex HMAC.
 */
function sign( payload: string, secret: string, crypto: Crypto ): string {
	const hmac = crypto.hmacSha256Hex;
	return hmac( secret, COOKIE_VERSION + '.' + payload ).substring(
		0,
		SIGNATURE_LENGTH
	);
}

/**
 * Parse one `group:start:count:seen` record.
 *
 * @param text Record text.
 * @return The record, or null when malformed.
 */
function parseRecord( text: string ): MeterRecord | null {
	const fields = text.split( ':' );
	if ( fields.length != 4 ) {
		return null;
	}
	const record = new MeterRecord();
	record.group = fields[ 0 ];
	record.start = parseNonNegativeInt( fields[ 1 ] );
	const count = parseNonNegativeInt( fields[ 2 ] );
	if ( ! isToken( record.group ) || record.start < 0 || count < 0 ) {
		return null;
	}
	record.count = count as i32;
	if ( fields[ 3 ].length > 0 ) {
		const seen = fields[ 3 ].split( '_' );
		for ( let i = 0; i < seen.length; i++ ) {
			if ( seen[ i ].length == SEEN_HASH_LENGTH ) {
				record.seen.push( seen[ i ] );
			}
		}
	}
	return record;
}

/**
 * Verify and parse a `vip-go-meter` cookie.
 *
 * A missing, foreign, or badly signed cookie yields an empty list, which
 * the caller treats as a fresh start. That is the spec's "treated as
 * absent" rule: forging gains nothing beyond what deleting the cookie
 * already gives.
 *
 * @param raw    Cookie value, or null.
 * @param secret Signing secret.
 * @param crypto Hash provider.
 * @return Verified records, possibly empty.
 */
export function parseMeterCookie(
	raw: string | null,
	secret: string,
	crypto: Crypto
): MeterRecord[] {
	const records: MeterRecord[] = [];
	if ( raw === null ) {
		return records;
	}
	const parts = raw.trim().split( '.' );
	if ( parts.length != 3 || parts[ 0 ] != COOKIE_VERSION ) {
		return records;
	}
	const payload = parts[ 1 ];
	if ( ! constantTimeEquals( parts[ 2 ], sign( payload, secret, crypto ) ) ) {
		return records;
	}
	if ( payload.length == 0 ) {
		return records;
	}
	const items = payload.split( '~' );
	for ( let i = 0; i < items.length; i++ ) {
		const record = parseRecord( items[ i ] );
		if ( record !== null && findRecord( records, record.group ) === null ) {
			records.push( record );
		}
	}
	return records;
}

/**
 * Serialize and sign meter records.
 *
 * @param records Records to write.
 * @param secret  Signing secret.
 * @param crypto  Hash provider.
 * @return The cookie value.
 */
export function serializeMeterCookie(
	records: MeterRecord[],
	secret: string,
	crypto: Crypto
): string {
	const items: string[] = [];
	for ( let i = 0; i < records.length; i++ ) {
		const record = records[ i ];
		items.push(
			record.group +
				':' +
				formatInt( record.start ) +
				':' +
				record.count.toString() +
				':' +
				record.seen.join( '_' )
		);
	}
	const payload = items.join( '~' );
	return (
		COOKIE_VERSION + '.' + payload + '.' + sign( payload, secret, crypto )
	);
}

/**
 * The record for `group`, or null.
 *
 * @param records Records to search.
 * @param group   Meter group name.
 * @return The record, or null.
 */
export function findRecord(
	records: MeterRecord[],
	group: string
): MeterRecord | null {
	for ( let i = 0; i < records.length; i++ ) {
		if ( records[ i ].group == group ) {
			return records[ i ];
		}
	}
	return null;
}

/**
 * The state a record maps to under `spec`, from the remaining allowance.
 *
 * @param record The meter record.
 * @param spec   The meter's settings.
 * @return `open`, `last`, or `wall`.
 */
export function stateFor( record: MeterRecord, spec: MeterSpec ): string {
	const remaining = spec.limit - record.count;
	if ( remaining > 1 ) {
		return STATE_OPEN;
	}
	if ( remaining == 1 ) {
		return STATE_LAST;
	}
	return STATE_WALL;
}

/**
 * Advance one meter after a view, in the spec's order: reset on period
 * elapse, count the view when content was delivered and the post is new,
 * then compute the next state.
 *
 * A reader with no record gets a fresh one with count zero and is not
 * counted: with no state in the cookie the gate withheld the content, so
 * there was nothing to count. That is the decided first-visit trade-off.
 *
 * @param records     All verified records; mutated in place.
 * @param spec        The meter announced by the response.
 * @param lookupState The meter value the request carried, or null.
 * @param now         Epoch seconds at delivery.
 * @param crypto      Hash provider.
 * @return The next state to write into `vip-go-seg`.
 */
export function advanceMeter(
	records: MeterRecord[],
	spec: MeterSpec,
	lookupState: string | null,
	now: f64,
	crypto: Crypto
): string {
	let record = findRecord( records, spec.group );
	if ( record === null ) {
		record = new MeterRecord();
		record.group = spec.group;
		record.start = now;
		records.push( record );
	} else if ( now - record.start >= spec.period ) {
		record.start = now;
		record.count = 0;
		record.seen = [];
	}
	const delivered =
		lookupState !== null &&
		( lookupState == STATE_OPEN || lookupState == STATE_LAST );
	if ( delivered ) {
		const hash = crypto.sha256Hex;
		const seenHash = hash( spec.postId ).substring( 0, SEEN_HASH_LENGTH );
		if ( record.seen.indexOf( seenHash ) < 0 ) {
			record.seen.push( seenHash );
			record.count = record.count + 1;
		}
	}
	return stateFor( record, spec );
}
