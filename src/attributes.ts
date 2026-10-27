/**
 * Group block attributes for content gating.
 *
 * The names and modes must match the server registration in
 * `inc/rendering/class-block-gate.php`. The server registers and enforces
 * the attributes; the editor only reads and writes them.
 */

export const GROUP_BLOCK = 'core/group';

export const ATTR_SEGMENTS = 'contentGatesSegments';
export const ATTR_MODE = 'contentGatesMode';

export const MODE_IN = 'in';
export const MODE_NOT_IN = 'not-in';

export type GateMode = typeof MODE_IN | typeof MODE_NOT_IN;

export interface GateAttributes {
	[ ATTR_SEGMENTS ]: string[];
	[ ATTR_MODE ]: GateMode;
}

/**
 * Read the segment keys from a block's attributes, dropping anything that is
 * not a non-empty string. Mirrors `Block_Gate::segments_of()`.
 *
 * @param attributes Block attributes.
 * @return Segment keys, deduplicated, in stored order.
 */
export function readSegments(
	attributes: Record< string, unknown >
): string[] {
	const raw = attributes[ ATTR_SEGMENTS ];
	if ( ! Array.isArray( raw ) ) {
		return [];
	}

	const keys: string[] = [];
	for ( const key of raw ) {
		if ( typeof key === 'string' && key !== '' && ! keys.includes( key ) ) {
			keys.push( key );
		}
	}

	return keys;
}

/**
 * Read the show-to mode from a block's attributes. Mirrors `Block_Gate::mode_of()`.
 *
 * @param attributes Block attributes.
 * @return `in` or `not-in`.
 */
export function readMode( attributes: Record< string, unknown > ): GateMode {
	return attributes[ ATTR_MODE ] === MODE_NOT_IN ? MODE_NOT_IN : MODE_IN;
}
