/**
 * HTTP date parsing, so the worker knows "now" without a host clock.
 *
 * AssemblyScript's `Date.now()` compiles to an `env.Date.now` import that
 * the VIP host is not documented to provide; a missing import would stop
 * the whole worker from loading. Every HTTP/1.1 response carries a `Date`
 * header, and a cached copy adds `Age`, so the response itself tells the
 * worker the time to within the cache TTL. Meter periods are measured in
 * days, so that precision is enough.
 *
 * Only IMF-fixdate (`Sun, 06 Nov 1994 08:49:37 GMT`) is parsed. Origins and
 * caches emit that form; the obsolete RFC 850 and asctime forms return -1.
 *
 * Portability rules: see segmentation.ts.
 */

const MONTHS: string[] = [
	'jan',
	'feb',
	'mar',
	'apr',
	'may',
	'jun',
	'jul',
	'aug',
	'sep',
	'oct',
	'nov',
	'dec',
];

/**
 * Parse a non-negative decimal integer. Returns -1 on anything else.
 *
 * @param text Digits.
 * @return The value, or -1.
 */
export function parseNonNegativeInt( text: string ): f64 {
	const value = text.trim();
	if ( value.length == 0 ) {
		return -1;
	}
	let out: f64 = 0;
	for ( let i = 0; i < value.length; i++ ) {
		const c = value.charCodeAt( i );
		if ( c < 48 || c > 57 ) {
			return -1;
		}
		out = out * 10 + ( ( c - 48 ) as f64 );
	}
	return out;
}

/**
 * Days since 1970-01-01 for a proleptic Gregorian date.
 *
 * Howard Hinnant's `days_from_civil`, in floating point so it runs the
 * same way in both runtimes.
 *
 * @param year  Full year.
 * @param month 1-12.
 * @param day   1-31.
 * @return Day count, possibly negative.
 */
function daysFromCivil( year: f64, month: f64, day: f64 ): f64 {
	const y = month <= 2 ? year - 1 : year;
	const era = Math.floor( ( y >= 0 ? y : y - 399 ) / 400 );
	const yoe = y - era * 400;
	const mp = month > 2 ? month - 3 : month + 9;
	const doy = Math.floor( ( 153 * mp + 2 ) / 5 ) + day - 1;
	const doe =
		yoe * 365 + Math.floor( yoe / 4 ) - Math.floor( yoe / 100 ) + doy;
	return era * 146097 + doe - 719468;
}

/**
 * Parse an IMF-fixdate into epoch seconds.
 *
 * @param value The `Date` header, or null.
 * @return Epoch seconds, or -1 when absent or malformed.
 */
export function parseHttpDate( value: string | null ): f64 {
	if ( value === null ) {
		return -1;
	}
	const raw = value.trim().split( ' ' );
	const parts: string[] = [];
	for ( let i = 0; i < raw.length; i++ ) {
		if ( raw[ i ].length > 0 ) {
			parts.push( raw[ i ] );
		}
	}
	// Accept "Sun, 06 Nov 1994 08:49:37 GMT" and the same without a weekday.
	let at = 0;
	if ( parts.length == 6 ) {
		at = 1;
	} else if ( parts.length != 5 ) {
		return -1;
	}
	if ( parts[ at + 4 ].toUpperCase() != 'GMT' ) {
		return -1;
	}
	const day = parseNonNegativeInt( parts[ at ] );
	if ( day < 1 || day > 31 ) {
		return -1;
	}
	const monthIndex = MONTHS.indexOf( parts[ at + 1 ].toLowerCase() );
	if ( monthIndex < 0 ) {
		return -1;
	}
	const year = parseNonNegativeInt( parts[ at + 2 ] );
	if ( year < 1970 ) {
		return -1;
	}
	const clock = parts[ at + 3 ].split( ':' );
	if ( clock.length != 3 ) {
		return -1;
	}
	const hour = parseNonNegativeInt( clock[ 0 ] );
	if ( hour < 0 || hour > 23 ) {
		return -1;
	}
	const minute = parseNonNegativeInt( clock[ 1 ] );
	if ( minute < 0 || minute > 59 ) {
		return -1;
	}
	const second = parseNonNegativeInt( clock[ 2 ] );
	if ( second < 0 || second > 60 ) {
		return -1;
	}
	const days = daysFromCivil( year, ( monthIndex + 1 ) as f64, day );
	return days * 86400 + hour * 3600 + minute * 60 + second;
}

/**
 * The time a response was delivered, from its `Date` and `Age` headers.
 *
 * @param dateHeader The response `Date` header, or null.
 * @param ageHeader  The response `Age` header, or null.
 * @return Epoch seconds, or -1 when the date is unusable.
 */
export function responseTime(
	dateHeader: string | null,
	ageHeader: string | null
): f64 {
	const date = parseHttpDate( dateHeader );
	if ( date < 0 ) {
		return -1;
	}
	if ( ageHeader === null ) {
		return date;
	}
	const age = parseNonNegativeInt( ageHeader );
	return age < 0 ? date : date + age;
}
