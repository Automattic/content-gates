/**
 * Crawler classification against config/crawlers.json.
 *
 * The JSON file is compiled in through crawlers-config.ts, which
 * scripts/crawlers-config.mjs regenerates on every build, so the worker and the plugin
 * agree by construction and the worker ships no runtime configuration.
 *
 * Portability rules: see segmentation.ts.
 */

import {
	CRAWLER_KEYS,
	CRAWLER_PATTERNS,
	CRAWLER_VERSION,
} from './crawlers-config.ts';

/**
 * Configuration version as sent in `X-WPVIP-Content-Gates-Edge`.
 *
 * @return The version string.
 */
export function crawlerVersion(): string {
	return CRAWLER_VERSION.toString();
}

/**
 * Every crawler group name.
 *
 * @return A copy of the configured keys.
 */
export function crawlerKeys(): string[] {
	const out: string[] = [];
	for ( let i = 0; i < CRAWLER_KEYS.length; i++ ) {
		out.push( CRAWLER_KEYS[ i ] );
	}
	return out;
}

/**
 * Whether `name` is a crawler group.
 *
 * @param name Group name.
 * @return True for a configured crawler segment.
 */
export function isCrawlerKey( name: string ): bool {
	return CRAWLER_KEYS.indexOf( name ) >= 0;
}

/**
 * Crawler groups whose user-agent patterns match `userAgent`.
 *
 * Patterns are case-insensitive substrings, per the spec. The result is in
 * configuration order, which the generator keeps sorted by key.
 *
 * @param userAgent The `User-Agent` header, or null.
 * @return Matching group names, possibly empty.
 */
export function classifyCrawler( userAgent: string | null ): string[] {
	const out: string[] = [];
	if ( userAgent === null ) {
		return out;
	}
	const haystack = userAgent.toLowerCase();
	for ( let i = 0; i < CRAWLER_KEYS.length; i++ ) {
		const patterns = CRAWLER_PATTERNS[ i ];
		for ( let j = 0; j < patterns.length; j++ ) {
			if ( haystack.indexOf( patterns[ j ].toLowerCase() ) >= 0 ) {
				out.push( CRAWLER_KEYS[ i ] );
				break;
			}
		}
	}
	return out;
}
