/**
 * The worker's three phases as pure functions over plain inputs.
 *
 * index.ts (the real worker) and edge/sim/server.ts (the local simulator)
 * both marshal headers into these inputs and apply the outputs, so the
 * decision logic is written once and tested once. Nothing here touches
 * the SDK, KV, or the network.
 *
 * Phases run in isolation on the platform: module state does not survive
 * from one phase to the next. Phase 1 therefore records what later phases
 * need in request headers (`x-wpvip-content-gates-*`), and phase 3 reads
 * them back from the origin-socket request snapshot.
 *
 * Portability rules: see segmentation.ts.
 */

import { classifyCrawler, crawlerKeys, crawlerVersion } from './crawlers.ts';
import {
	buildSetCookie,
	expiresCookie,
	getCookie,
	parseSetCookie,
	withCookie,
} from './cookies.ts';
import { Crypto } from './crypto.ts';
import {
	decodeRecord,
	encodeRecord,
	isCovered,
	joinNames,
	parseNameList,
	sameNames,
} from './declared.ts';
import {
	METER_COOKIE,
	MeterRecord,
	advanceMeter,
	parseMeterCookie,
	parseMeterHeader,
	serializeMeterCookie,
} from './meter.ts';
import { parseNonNegativeInt } from './httpdate.ts';
import {
	Groups,
	isToken,
	narrowGroups,
	parseSegmentation,
	serializeSegmentation,
	withoutGroups,
} from './segmentation.ts';

/** Browser cookie `Vary_Cache` reads and writes. */
export const SEGMENT_COOKIE: string = 'vip-go-seg';

/** Request header the cache keys on; the worker rewrites it in phase 1. */
export const SEGMENTATION_HEADER: string = 'x-vip-go-segmentation';

/** Request header carrying the worker's crawlers.json version to origin. */
export const EDGE_VERSION_HEADER: string = 'x-wpvip-content-gates-edge';

/** Phase 1 -> 3 marker: `full` or `narrowed`. */
export const LOOKUP_HEADER: string = 'x-wpvip-content-gates-lookup';

/** Phase 1 -> 3 marker: the record's keys phase 1 narrowed with. */
export const RECORD_HEADER: string = 'x-wpvip-content-gates-record';

/** Phase 1 -> 3 marker: the KV key for this URL. */
export const KEY_HEADER: string = 'x-wpvip-content-gates-key';

/** Phase 1 marker: crawler groups the worker classified, comma separated. */
export const CRAWLER_HEADER: string = 'x-wpvip-content-gates-crawler';

/** Response header origin sends with the declared set. */
export const DECLARED_HEADER: string = 'x-wpvip-content-gates-declared';

/** Response header origin sends once per meter segment in the declared set. */
export const METER_HEADER: string = 'x-wpvip-content-gates-meter';

/**
 * Response header phase 3 sets with its decision. Stored with the copy, so
 * a hit shows the decision of the miss that created it (always `covered`).
 * Edge Workers expose no logs; this and origin's echo of the request
 * markers (`X-WPVIP-Content-Gates-Edge-Received`) are how a live failure
 * is localized from outside.
 */
export const COVERAGE_HEADER: string = 'x-wpvip-content-gates-edge-coverage';

/** Response header phase 4 sets per delivery on metered responses. */
export const METER_DIAGNOSTIC_HEADER: string =
	'x-wpvip-content-gates-edge-meter';

export const LOOKUP_FULL: string = 'full';
export const LOOKUP_NARROWED: string = 'narrowed';

/**
 * Lifetime of the `vip-go-seg` cookie the worker writes: one day, the
 * plugin's default (`content_gates_cookie_lifetime`). The worker rewrites
 * the whole cookie on every metered view, so a longer value here would
 * extend every provider-assigned group past the lifetime the spec bounds
 * a lapsed reader's entitled view with.
 */
export const SEGMENT_COOKIE_MAX_AGE: i32 = 86400;

/**
 * KV key for a URL: `cg:` plus the first 32 hex characters of
 * sha256(path + query). Hashing keeps the key inside KV's 64-byte limit
 * for any URL length.
 *
 * @param url    Origin-relative request target (path and query).
 * @param crypto Hash provider.
 * @return The KV key.
 */
export function kvKeyFor( url: string, crypto: Crypto ): string {
	const hash = crypto.sha256Hex;
	return 'cg:' + hash( url ).substring( 0, 32 );
}

/* ------------------------------------------------------------------------ */
/* Phase 1: client request, before cache lookup                              */
/* ------------------------------------------------------------------------ */

export class ClientRequestInput {
	url: string = '';
	cookie: string | null = null;
	userAgent: string | null = null;
	/** Raw KV record for this URL, or null when none. */
	record: string | null = null;
}

export class ClientRequestOutput {
	/** Value for `X-VIP-Go-Segmentation`; may be empty. */
	segmentation: string = '';
	/** `full` or `narrowed`. */
	lookup: string = LOOKUP_FULL;
	/** Record keys in declared-header format; empty unless narrowed. */
	recordKeys: string = '';
	/** Rewritten `Cookie` header for origin; empty means delete it. */
	cookie: string = '';
	/** Crawler groups matched, comma separated; empty when none. */
	crawler: string = '';
	/** Value for `X-WPVIP-Content-Gates-Edge`. */
	edgeVersion: string = '';
	/** Decoded record keys, for diagnostics; null when no record. */
	recordNames: string[] | null = null;
}

/**
 * Phase 1 decision logic.
 *
 * Order matters and follows the spec: strip crawler groups a browser may
 * have sent, classify the user agent, then narrow against the record.
 * Classification before narrowing lets a crawler value enter the key when
 * the URL declares it.
 *
 * @param input Request facts.
 * @return What to set on the request.
 */
export function onClientRequestPure(
	input: ClientRequestInput
): ClientRequestOutput {
	const out = new ClientRequestOutput();
	out.edgeVersion = crawlerVersion();

	// 1. The reader's groups, minus anything only the worker may set.
	const fromCookie = parseSegmentation(
		getCookie( input.cookie, SEGMENT_COOKIE )
	);
	const reader = withoutGroups( fromCookie, crawlerKeys() );

	// 2. The worker's own classification.
	const matched = classifyCrawler( input.userAgent );
	for ( let i = 0; i < matched.length; i++ ) {
		reader.set( matched[ i ], '1' );
	}
	out.crawler = matched.join( ',' );

	// 3. Narrow when a record exists; otherwise today's full-value keying.
	const record = decodeRecord( input.record );
	if ( record === null ) {
		out.segmentation = serializeSegmentation( reader );
		out.lookup = LOOKUP_FULL;
	} else {
		out.segmentation = serializeSegmentation(
			narrowGroups( reader, record )
		);
		out.lookup = LOOKUP_NARROWED;
		out.recordKeys = joinNames( record );
		out.recordNames = record;
	}

	// 4. The cookie origin sees: the reader's groups plus the worker's
	//    classification, never a browser-supplied crawler value.
	const forwarded = serializeSegmentation( reader );
	out.cookie = withCookie(
		input.cookie,
		SEGMENT_COOKIE,
		forwarded.length == 0 ? null : forwarded
	);

	return out;
}

/* ------------------------------------------------------------------------ */
/* Phase 3: origin response, on a cache miss                                 */
/* ------------------------------------------------------------------------ */

export class OriginResponseInput {
	/** All `Vary` values joined with commas, or null. */
	vary: string | null = null;
	/** `X-WPVIP-Content-Gates-Declared`, or null when missing. */
	declared: string | null = null;
	/** `x-wpvip-content-gates-lookup` from the origin-socket request. */
	lookup: string | null = null;
	/** `x-wpvip-content-gates-record` from the origin-socket request. */
	recordKeys: string | null = null;
}

export class OriginResponseOutput {
	/** Whether to set `Cache-Control: no-store`. */
	noStore: bool = false;
	/** New KV record to write, or null to leave the record alone. */
	record: string | null = null;
	/** Why, for logs and the simulator. */
	reason: string = '';
	/** Value for `COVERAGE_HEADER`: the reason and the markers as read. */
	diagnostic: string = '';
}

/**
 * Compose the phase 3 diagnostic: `<reason>; lookup=<marker>; record=<names>`.
 *
 * Markers are echoed as read, so the header says what phase 3 saw, not
 * what phase 1 meant: `-` is an absent marker, `none` a present but empty
 * one (a full lookup writes an empty record marker; a narrowed lookup
 * with an empty declared set does too), `?` a value that is not a token.
 *
 * @param reason     Decision reason.
 * @param lookup     Lookup marker, or null.
 * @param recordKeys Record marker, or null.
 * @return The header value.
 */
export function coverageDiagnostic(
	reason: string,
	lookup: string | null,
	recordKeys: string | null
): string {
	let lookupPart = '-';
	if ( lookup !== null ) {
		lookupPart = isToken( lookup ) ? lookup : '?';
	}
	let recordPart = '-';
	if ( recordKeys !== null ) {
		const names = parseNameList( recordKeys );
		recordPart = names.length == 0 ? 'none' : names.join( ',' );
	}
	return reason + '; lookup=' + lookupPart + '; record=' + recordPart;
}

/**
 * Whether a `Vary` value names the segmentation header.
 *
 * @param vary Joined `Vary` header values, or null.
 * @return True when the response is segmented.
 */
export function variesOnSegmentation( vary: string | null ): bool {
	if ( vary === null ) {
		return false;
	}
	const fields = vary.split( ',' );
	for ( let i = 0; i < fields.length; i++ ) {
		if ( fields[ i ].trim().toLowerCase() == SEGMENTATION_HEADER ) {
			return true;
		}
	}
	return false;
}

/**
 * Phase 3 decision logic: coverage check, then record update, then the
 * diagnostic that reports both.
 *
 * @param input Response and request facts.
 * @return What to do with the response and the record.
 */
export function onOriginResponsePure(
	input: OriginResponseInput
): OriginResponseOutput {
	const out = decideOriginResponse( input );
	out.diagnostic = coverageDiagnostic(
		out.reason,
		input.lookup,
		input.recordKeys
	);
	return out;
}

/**
 * The phase 3 decision without its diagnostic.
 *
 * @param input Response and request facts.
 * @return What to do with the response and the record.
 */
function decideOriginResponse(
	input: OriginResponseInput
): OriginResponseOutput {
	const out = new OriginResponseOutput();
	const lookup = input.lookup;
	const recordHeader = input.recordKeys;

	// Not segmented: `Vary_Cache` never ran, so nothing to check or learn.
	if ( ! variesOnSegmentation( input.vary ) ) {
		out.reason = 'unsegmented';
		return out;
	}

	// Phase 1's markers say how the cache key was built. Without them the
	// coverage check cannot run, so fail closed instead of assuming a full
	// lookup: if the platform ever drops the request snapshot, the cost is
	// origin requests, never a narrowed copy stored unchecked.
	if (
		lookup === null ||
		( lookup != LOOKUP_FULL && lookup != LOOKUP_NARROWED ) ||
		( lookup == LOOKUP_NARROWED && recordHeader === null )
	) {
		out.noStore = true;
		out.reason = 'no-marker';
		return out;
	}

	// Segmented but undeclared: unknown reads, so never store it.
	const declaredHeader = input.declared;
	if ( declaredHeader === null ) {
		out.noStore = true;
		out.reason = 'undeclared';
		return out;
	}

	const declared = parseNameList( declaredHeader );
	const lookupFull = lookup == LOOKUP_FULL;
	let recordKeys: string[] = [];
	if ( recordHeader !== null ) {
		recordKeys = parseNameList( recordHeader );
	}

	if ( ! isCovered( declared, lookupFull, recordKeys ) ) {
		// The key this response would be stored under was narrowed with a
		// record that lacks a declared group. Storing it would serve this
		// render to readers who differ in that group.
		out.noStore = true;
		out.reason = 'narrowed-too-far';
	} else {
		out.reason = 'covered';
	}

	// Replace, never merge, so the record shrinks when an editor removes a
	// segment. Skip the write when a narrowed lookup already used this set.
	if ( lookupFull || ! sameNames( declared, recordKeys ) ) {
		out.record = encodeRecord( declared );
	}

	return out;
}

/* ------------------------------------------------------------------------ */
/* Phase 4: client response, on every delivery                               */
/* ------------------------------------------------------------------------ */

export class ClientResponseInput {
	/** Every `X-WPVIP-Content-Gates-Meter` value on the response. */
	meterHeaders: string[] = [];
	/** The client's `User-Agent`, or null. */
	userAgent: string | null = null;
	/** The client's raw `Cookie` header, or null. */
	cookie: string | null = null;
	/** Every `Set-Cookie` value already on the response, in order. */
	setCookies: string[] = [];
	/** Meter signing secret, or null when metering is disabled. */
	secret: string | null = null;
	/** Epoch seconds at delivery, or -1 when unknown. */
	now: f64 = -1;
}

export class ClientResponseOutput {
	/** When true, replace the response's `Set-Cookie` fields with `setCookies`. */
	replace: bool = false;
	/** The complete `Set-Cookie` list for the response, in order. */
	setCookies: string[] = [];
	/** Why the response was left alone, when `replace` is false. */
	skipped: string = '';
	/** How many meter headers were advanced. */
	metersAdvanced: i32 = 0;
}

/**
 * Compose the phase 4 diagnostic for `METER_DIAGNOSTIC_HEADER`:
 * `advanced; meters=<n>; age=<seconds>` or `skipped; reason=<why>; age=…`.
 *
 * `age` echoes the response's `Age` header (`-` when absent, `?` when not
 * a number). A cache sets `Age` on hits, so this is the external evidence
 * that the meter advanced on a hit.
 *
 * @param out Phase 4 output.
 * @param age The response's `Age` header, or null.
 * @return The header value.
 */
export function meterDiagnostic(
	out: ClientResponseOutput,
	age: string | null
): string {
	let agePart = '-';
	if ( age !== null ) {
		agePart = parseNonNegativeInt( age ) >= 0 ? age.trim() : '?';
	}
	const head = out.replace
		? 'advanced; meters=' + out.metersAdvanced.toString()
		: 'skipped; reason=' + out.skipped;
	return head + '; age=' + agePart;
}

/**
 * Phase 4 decision logic: advance every announced meter and write both
 * cookies, or do nothing.
 *
 * Does nothing for crawlers (they must never carry state), when no secret
 * is configured (an unsigned cookie would be forgeable), and when the
 * response date is unreadable (a period reset needs a clock).
 *
 * The groups the meter state is merged into come from origin's own
 * `vip-go-seg` `Set-Cookie` on this response when there is one, and from
 * the request cookie otherwise. On a metered miss a provider may have
 * assigned or removed a group; reading the request cookie there would
 * write the stale membership back over origin's decision. The response's
 * `Set-Cookie` list is rewritten as a whole so exactly one `vip-go-seg`
 * reaches the browser; other cookies pass through untouched.
 *
 * @param input  Response and request facts.
 * @param crypto Hash provider.
 * @return Cookies to set.
 */
export function onClientResponsePure(
	input: ClientResponseInput,
	crypto: Crypto
): ClientResponseOutput {
	const out = new ClientResponseOutput();

	if ( input.meterHeaders.length == 0 ) {
		out.skipped = 'unmetered';
		return out;
	}
	if ( classifyCrawler( input.userAgent ).length > 0 ) {
		out.skipped = 'crawler';
		return out;
	}
	const secret = input.secret;
	if ( secret === null || secret.length == 0 ) {
		out.skipped = 'no-secret';
		return out;
	}
	if ( input.now < 0 ) {
		out.skipped = 'no-date';
		return out;
	}

	// Base segmentation: origin's decision on this response wins over the
	// request cookie. The last `vip-go-seg` field is it; one that expires
	// the cookie means "no groups". Every other cookie is kept as sent.
	let base: string | null = getCookie( input.cookie, SEGMENT_COOKIE );
	let originAttributes: string | null = null;
	const kept: string[] = [];
	for ( let i = 0; i < input.setCookies.length; i++ ) {
		const field = input.setCookies[ i ];
		const parts = parseSetCookie( field );
		if ( parts === null || parts.name != SEGMENT_COOKIE ) {
			kept.push( field );
			continue;
		}
		if (
			parts.value.length == 0 ||
			expiresCookie( parts.attributes, input.now )
		) {
			base = null;
			originAttributes = null;
		} else {
			base = parts.value;
			originAttributes = parts.attributes;
		}
	}

	const groups: Groups = withoutGroups(
		parseSegmentation( base ),
		crawlerKeys()
	);
	const records: MeterRecord[] = parseMeterCookie(
		getCookie( input.cookie, METER_COOKIE ),
		secret,
		crypto
	);

	let maxPeriod: f64 = 0;
	let advanced = 0;
	for ( let i = 0; i < input.meterHeaders.length; i++ ) {
		const spec = parseMeterHeader( input.meterHeaders[ i ] );
		if ( spec === null ) {
			continue;
		}
		const next = advanceMeter(
			records,
			spec,
			groups.get( spec.group ),
			input.now,
			crypto
		);
		groups.set( spec.group, next );
		if ( spec.period > maxPeriod ) {
			maxPeriod = spec.period;
		}
		advanced++;
	}
	if ( advanced == 0 ) {
		out.skipped = 'malformed-meter';
		return out;
	}

	out.replace = true;
	out.metersAdvanced = advanced;
	for ( let i = 0; i < kept.length; i++ ) {
		out.setCookies.push( kept[ i ] );
	}
	out.setCookies.push(
		buildSetCookie(
			METER_COOKIE,
			serializeMeterCookie( records, secret, crypto ),
			maxPeriod as i32,
			true
		)
	);
	// Keep origin's cookie attributes when it wrote the cookie itself, so
	// the two writers never disagree on path, domain, or lifetime.
	const segmentation = serializeSegmentation( groups );
	if ( originAttributes === null ) {
		out.setCookies.push(
			buildSetCookie(
				SEGMENT_COOKIE,
				segmentation,
				SEGMENT_COOKIE_MAX_AGE,
				false
			)
		);
	} else {
		out.setCookies.push(
			SEGMENT_COOKIE + '=' + segmentation + '; ' + originAttributes
		);
	}
	return out;
}
