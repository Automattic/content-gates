/**
 * Segment list for the editor: fetch, cache, and option building.
 *
 * The list is fetched once per editor session through
 * `GET /content-gates/v1/segments` and shared by every Group block panel.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export const SEGMENTS_PATH = '/content-gates/v1/segments';

export interface Segment {
	id: number;
	key: string;
	name: string;
	active: boolean;
	type: string;
	crawler: boolean;
}

export type SegmentsState =
	| { status: 'loading'; segments: Segment[] }
	| { status: 'ready'; segments: Segment[] }
	| { status: 'error'; segments: Segment[]; message: string };

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
}

let cached: Promise< Segment[] > | null = null;

function isSegment( value: unknown ): value is Segment {
	if ( typeof value !== 'object' || value === null ) {
		return false;
	}
	const item = value as Record< string, unknown >;
	return (
		typeof item.key === 'string' &&
		item.key !== '' &&
		typeof item.name === 'string' &&
		typeof item.active === 'boolean'
	);
}

function normalize( payload: unknown ): Segment[] {
	if ( ! Array.isArray( payload ) ) {
		throw new Error(
			__(
				'Unexpected response from the segments endpoint.',
				'content-gates'
			)
		);
	}

	return payload.filter( isSegment ).map( ( item ) => ( {
		id: typeof item.id === 'number' ? item.id : 0,
		key: item.key,
		name: item.name,
		active: item.active,
		type: typeof item.type === 'string' ? item.type : '',
		crawler: item.crawler === true,
	} ) );
}

/**
 * Fetch the segment list, once per editor session.
 *
 * A failed request is not cached, so the next panel mount retries.
 *
 * @return The segments, active and inactive.
 */
export function fetchSegments(): Promise< Segment[] > {
	if ( ! cached ) {
		cached = apiFetch< unknown >( { path: SEGMENTS_PATH } )
			.then( normalize )
			.catch( ( error: unknown ) => {
				cached = null;
				throw error;
			} );
	}

	return cached;
}

/**
 * Forget the cached list. Used by tests and by a future "refresh" action.
 */
export function resetSegmentsCache(): void {
	cached = null;
}

/**
 * React hook that exposes the shared segment list with loading and error
 * states.
 *
 * @return Current state of the list.
 */
export function useSegments(): SegmentsState {
	const [ state, setState ] = useState< SegmentsState >( {
		status: 'loading',
		segments: [],
	} );

	useEffect( () => {
		let mounted = true;

		fetchSegments().then(
			( segments ) => {
				if ( mounted ) {
					setState( { status: 'ready', segments } );
				}
			},
			( error: unknown ) => {
				if ( mounted ) {
					setState( {
						status: 'error',
						segments: [],
						message: errorMessage( error ),
					} );
				}
			}
		);

		return () => {
			mounted = false;
		};
	}, [] );

	return state;
}

function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null ) {
		const message = ( error as { message?: unknown } ).message;
		if ( typeof message === 'string' && message !== '' ) {
			return message;
		}
	}

	return __( 'Could not load segments.', 'content-gates' );
}

/**
 * Build the checkbox rows for a Group.
 *
 * Active segments are always offered. An inactive segment appears only while
 * the Group still references it, labeled "(inactive)", so an editor can see
 * and clear the assignment. A referenced key the site does not know is shown
 * the same way, labeled "(not found)". Rows are sorted by label.
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
		} );
	}

	return options.sort( ( a, b ) => a.label.localeCompare( b.label ) );
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
