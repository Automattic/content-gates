/**
 * Vary_Cache segmentation values: parse, serialize, narrow.
 *
 * The browser cookie `vip-go-seg` and the request header
 * `X-VIP-Go-Segmentation` share one serialization, owned by WPVIP's
 * `Vary_Cache` class:
 *
 *     vc-v1__<group>_--_<value>---__<group>_--_<value>
 *
 * Groups are sorted by name, names and values use `[A-Za-z0-9_-]`, and an
 * empty value drops the group. This module reproduces that format exactly
 * so the worker can act as a second writer (see spec/api.md, "Second
 * cookie writers").
 *
 * Portability: this file compiles under AssemblyScript AND runs under Node
 * with type stripping. Stay inside the intersection: no Map/Set, no
 * closures, no `for...of`, no regex, no template literals, `==` on strings.
 */

export const VERSION_PREFIX: string = 'vc-v1__';
export const GROUP_SEPARATOR: string = '---__';
export const VALUE_SEPARATOR: string = '_--_';

/**
 * Whether `value` uses only the cookie-safe character set `[A-Za-z0-9_-]`.
 *
 * @param value Candidate group name or value.
 * @return True when non-empty and every character is allowed.
 */
export function isToken( value: string ): bool {
	if ( value.length == 0 ) {
		return false;
	}
	for ( let i = 0; i < value.length; i++ ) {
		const c = value.charCodeAt( i );
		const digit = c >= 48 && c <= 57;
		const upper = c >= 65 && c <= 90;
		const lower = c >= 97 && c <= 122;
		const dashOrUnderscore = c == 45 || c == 95;
		if ( ! ( digit || upper || lower || dashOrUnderscore ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Sort a copy of `names` ascending by UTF-16 code units.
 *
 * Written by hand because AssemblyScript's default array comparator is
 * not reliable for strings, and the same byte order is what PHP's `ksort`
 * produces for these ASCII keys.
 *
 * @param names Names to sort.
 * @return A new sorted array.
 */
export function sortNames( names: string[] ): string[] {
	const out: string[] = [];
	for ( let i = 0; i < names.length; i++ ) {
		out.push( names[ i ] );
	}
	for ( let i = 1; i < out.length; i++ ) {
		const current = out[ i ];
		let j = i - 1;
		while ( j >= 0 && current < out[ j ] ) {
			out[ j + 1 ] = out[ j ];
			j--;
		}
		out[ j + 1 ] = current;
	}
	return out;
}

/**
 * An ordered map of group name to value.
 *
 * Two parallel arrays instead of a Map: AssemblyScript's Map throws on a
 * missing key and its iteration API differs from JavaScript's, so arrays
 * are the only representation both runtimes treat the same way.
 */
export class Groups {
	names: string[] = [];
	values: string[] = [];

	/**
	 * Position of `name`, or -1.
	 *
	 * @param name Group name.
	 * @return Index into `names` and `values`.
	 */
	indexOf( name: string ): i32 {
		return this.names.indexOf( name );
	}

	/**
	 * The value for `name`, or null when absent.
	 *
	 * @param name Group name.
	 * @return The value or null.
	 */
	get( name: string ): string | null {
		const i = this.indexOf( name );
		return i < 0 ? null : this.values[ i ];
	}

	/**
	 * Set or replace the value for `name`.
	 *
	 * @param name  Group name.
	 * @param value Group value.
	 */
	set( name: string, value: string ): void {
		const i = this.indexOf( name );
		if ( i < 0 ) {
			this.names.push( name );
			this.values.push( value );
		} else {
			this.values[ i ] = value;
		}
	}
}

/**
 * Parse a `vip-go-seg` cookie value or `X-VIP-Go-Segmentation` header.
 *
 * Mirrors `Vary_Cache::parse_group_cookie()`: strip the version prefix,
 * split on the group separator, skip fragments without a value separator,
 * and let a later duplicate overwrite an earlier one. Fragments with
 * characters outside the cookie-safe set are dropped; the worker never
 * forwards values the origin would refuse.
 *
 * @param raw Serialized value, or null for none.
 * @return Parsed groups, possibly empty.
 */
export function parseSegmentation( raw: string | null ): Groups {
	const groups = new Groups();
	if ( raw === null ) {
		return groups;
	}
	let value = raw.trim();
	if ( value.startsWith( VERSION_PREFIX ) ) {
		value = value.substring( VERSION_PREFIX.length );
	}
	if ( value.length == 0 ) {
		return groups;
	}
	const parts = value.split( GROUP_SEPARATOR );
	for ( let i = 0; i < parts.length; i++ ) {
		const part = parts[ i ];
		const at = part.indexOf( VALUE_SEPARATOR );
		if ( at < 0 ) {
			continue;
		}
		const name = part.substring( 0, at );
		const groupValue = part.substring( at + VALUE_SEPARATOR.length );
		if ( ! isToken( name ) || ! isToken( groupValue ) ) {
			continue;
		}
		groups.set( name, groupValue );
	}
	return groups;
}

/**
 * Serialize groups in `Vary_Cache` format.
 *
 * Mirrors `Vary_Cache::stringify_groups()`: sorted by name, empty values
 * skipped, empty result when nothing remains.
 *
 * @param groups Groups to serialize.
 * @return The serialized value, or an empty string.
 */
export function serializeSegmentation( groups: Groups ): string {
	const names = sortNames( groups.names );
	const parts: string[] = [];
	for ( let i = 0; i < names.length; i++ ) {
		const value = groups.get( names[ i ] );
		if ( value === null || value.trim().length == 0 ) {
			continue;
		}
		parts.push( names[ i ] + VALUE_SEPARATOR + value );
	}
	if ( parts.length == 0 ) {
		return '';
	}
	return VERSION_PREFIX + parts.join( GROUP_SEPARATOR );
}

/**
 * Keep only the groups whose names are in `keys`.
 *
 * This is the narrowing step: the cache key becomes the reader's values for
 * the URL's declared set only, so readers who differ in other groups share
 * one copy.
 *
 * @param groups Reader groups.
 * @param keys   Group names to keep.
 * @return A new Groups instance.
 */
export function narrowGroups( groups: Groups, keys: string[] ): Groups {
	const out = new Groups();
	for ( let i = 0; i < groups.names.length; i++ ) {
		if ( keys.indexOf( groups.names[ i ] ) >= 0 ) {
			out.set( groups.names[ i ], groups.values[ i ] );
		}
	}
	return out;
}

/**
 * Drop the groups whose names are in `keys`.
 *
 * @param groups Reader groups.
 * @param keys   Group names to remove.
 * @return A new Groups instance.
 */
export function withoutGroups( groups: Groups, keys: string[] ): Groups {
	const out = new Groups();
	for ( let i = 0; i < groups.names.length; i++ ) {
		if ( keys.indexOf( groups.names[ i ] ) < 0 ) {
			out.set( groups.names[ i ], groups.values[ i ] );
		}
	}
	return out;
}
