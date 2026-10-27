/**
 * The Segments panel's rows, built from the segment list and a Group's
 * assignment. Pure: no WordPress runtime beyond i18n, so `tests/js`
 * exercises it under Node.
 */

import { __, sprintf } from '@wordpress/i18n';

import type { Segment } from './segments';

/**
 * One checkbox row in the Segments panel.
 */
export interface SegmentOption {
	key: string;
	/** Visible checkbox label: the segment name, plus "(inactive)" when needed. */
	label: string;
	checked: boolean;
	crawler: boolean;
	inactive: boolean;
	/** Sort type: `boolean`, `crawler`, `meter`, or `~` for an unknown key. */
	type: string;
}

/**
 * Build the checkbox rows for a Group.
 *
 * Active segments are always offered. An inactive segment appears only while
 * the Group still references it, labeled "(inactive)", so an editor can see
 * and clear the assignment. A referenced key the site does not know is shown
 * the same way, labeled "(not found)". Rows are sorted by type (`boolean`,
 * `crawler`, `meter`; unknown keys last), then by key, the same order as the
 * settings page.
 *
 * @param segments Segment list from the REST route.
 * @param assigned Segment keys stored on the Group.
 * @return Rows to render.
 */
export function buildOptions(
	segments: Segment[],
	assigned: string[]
): SegmentOption[] {
	const options: SegmentOption[] = [];
	const known = new Set< string >();

	for ( const segment of segments ) {
		known.add( segment.key );
		const checked = assigned.includes( segment.key );
		if ( ! segment.active && ! checked ) {
			continue;
		}

		const inactiveLabel = sprintf(
			/* translators: %s: segment name. */
			__( '%s (inactive)', 'content-gates' ),
			segment.name
		);

		options.push( {
			key: segment.key,
			label: segment.active ? segment.name : inactiveLabel,
			checked,
			crawler: segment.crawler,
			inactive: ! segment.active,
			type: segment.crawler ? 'crawler' : segment.type,
		} );
	}

	for ( const key of assigned ) {
		if ( known.has( key ) ) {
			continue;
		}
		options.push( {
			key,
			/* translators: %s: segment key. */
			label: sprintf( __( '%s (not found)', 'content-gates' ), key ),
			checked: true,
			crawler: false,
			inactive: true,
			type: '~',
		} );
	}

	return options.sort( compareOptions );
}

/**
 * Order two rows by type, then by key. Plain code-point order, like the
 * server's `Segment_Registry::compare()`.
 *
 * @param a First row.
 * @param b Second row.
 * @return Negative when `a` sorts first.
 */
function compareOptions( a: SegmentOption, b: SegmentOption ): number {
	if ( a.type !== b.type ) {
		return a.type < b.type ? -1 : 1;
	}
	if ( a.key === b.key ) {
		return 0;
	}
	return a.key < b.key ? -1 : 1;
}

/**
 * Add or remove one key from the assignment, keeping stored order.
 *
 * @param assigned Current keys.
 * @param key      Key to toggle.
 * @param checked  Whether the key should be present afterwards.
 * @return New key list.
 */
export function toggleKey(
	assigned: string[],
	key: string,
	checked: boolean
): string[] {
	const without = assigned.filter( ( item ) => item !== key );
	return checked ? [ ...without, key ] : without;
}
