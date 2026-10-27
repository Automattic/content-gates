/**
 * Segment list for the editor: fetch and cache. `options.ts` builds the rows.
 *
 * The list is fetched once per editor session through
 * `GET /content-gates/v1/segments` and shared by every Group block panel.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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
