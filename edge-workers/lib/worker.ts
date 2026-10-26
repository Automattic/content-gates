/**
 * The worker's three phases as pure functions over plain inputs.
 *
 * workers/content-gates/assembly/index.ts (the worker) and sim/server.ts (the simulator)
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

import { bypassReason } from './bypass.ts';
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
export const KV_KEY_HEADER: string = 'x-wpvip-content-gates-kv';

/** Phase 1 marker: crawler groups the worker classified, comma separated. */
export const CRAWLER_HEADER: string = 'x-wpvip-content-gates-crawler';

/** Response header origin sends with the declared set. */
export const DECLARED_HEADER: string = 'x-wpvip-content-gates-declared';

/** Response header origin sends once per meter segment in the declared set. */
export const METER_HEADER: string = 'x-wpvip-content-gates-meter';

/**
 * Response header phase 3 sets with the key the request resolved to: the
 * narrowed `X-VIP-Go-Segmentation` value phase 1 built, `none` when that
 * value was empty, `bypass` when phase 1 left the request alone, and `-`
 * when phase 3 could not read phase 1's rewrite. It is stored with the
 * copy. A hit is by definition a request that resolved to the stored
 * copy's key, so the header reads the same on hits and misses. Edge
 * Workers expose no logs; with origin's declared header this is the
 * evidence of what the edge did.
 */
export const KEY_HEADER: string = 'x-wpvip-content-gates-key';

/** `KEY_HEADER` value for an empty narrowed key: the anonymous copy. */
export const KEY_NONE: string = 'none';

/** `KEY_HEADER` value when phase 3 saw no segmentation from phase 1. */
export const KEY_UNKNOWN: string = '-';

export const LOOKUP_FULL: string = 'full';
export const LOOKUP_NARROWED: string = 'narrowed';
/** Phase 1 left the request alone (see bypass.ts); phase 3 never stores it. */
export const LOOKUP_BYPASS: string = 'bypass';

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
	/** `x-vip-go-segmentation` from the origin-socket request. */
	segmentation: string | null = null;
}

export class OriginResponseOutput {
	/** Whether to set `Cache-Control: no-store`. */
	noStore: bool = false;
	/** New KV record to write, or null to leave the record alone. */
	record: string | null = null;
	/** Why, for tests and the simulator's log. */
	reason: string = '';
	/** Value for `KEY_HEADER`. */
	key: string = KEY_UNKNOWN;
}

/**
 * The `KEY_HEADER` value: the key phase 1 resolved the request to, as
 * phase 3 read it back from the origin-socket request.
 *
 * @param lookup       Lookup marker, or null.
 * @param segmentation Narrowed segmentation value, or null.
 * @return `bypass`, `-`, `none`, or the narrowed value.
 */
export function resolvedKey(
	lookup: string | null,
	segmentation: string | null
): string {
	if ( lookup !== null && lookup == LOOKUP_BYPASS ) {
		return LOOKUP_BYPASS;
	}
	if ( segmentation === null ) {
		return KEY_UNKNOWN;
	}
	const value = segmentation.trim();
	if ( value.length == 0 ) {
		return KEY_NONE;
	}
	return isToken( value ) ? value : KEY_UNKNOWN;
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
 * Phase 3 decision logic: coverage check, then record update. The key
 * header is reported whatever the decision, so a no-store response still
 * says what it was looked up under.
 *
 * @param input Response and request facts.
 * @return What to do with the response and the record.
 */
export function onOriginResponsePure(
	input: OriginResponseInput
): OriginResponseOutput {
	const out = new OriginResponseOutput();
	out.key = resolvedKey( input.lookup, input.segmentation );
	const lookup = input.lookup;
	const recordHeader = input.recordKeys;

	// Not segmented: `Vary_Cache` never ran, so nothing to check or learn.
	if ( ! variesOnSegmentation( input.vary ) ) {
		out.reason = 'unsegmented';
		return out;
	}

	// Phase 1 did not touch this request (logged-in session, non-GET, and
	// so on), so the key was never narrowed and never checked. The cache
	// would not store it anyway; say so rather than rely on that.
	if ( lookup == LOOKUP_BYPASS ) {
		out.noStore = true;
		out.reason = 'bypassed';
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
	/** The client's request method. */
	method: string = 'GET';
	/** The client's request target (path and query). */
	url: string = '';
	/** Whether the client's request carried `Authorization`. */
	authorization: bool = false;
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
	if (
		bypassReason(
			input.method,
			input.url,
			input.cookie,
			input.authorization
		).length > 0
	) {
		out.skipped = 'bypass';
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
