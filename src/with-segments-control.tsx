/**
 * `editor.BlockEdit` filter: add the Segments panel to Group blocks.
 */

import { createHigherOrderComponent } from '@wordpress/compose';

import { GROUP_BLOCK } from './attributes';
import { SegmentsPanel } from './components/segments-panel';

/**
 * The subset of `BlockEdit` props this plugin reads.
 */
export interface BlockEditProps {
	name: string;
	attributes: Record< string, unknown >;
	setAttributes: ( attributes: Record< string, unknown > ) => void;
}

export const withSegmentsControl = createHigherOrderComponent< BlockEditProps >(
	( BlockEdit ) =>
		function GroupWithSegments( props ) {
			if ( props.name !== GROUP_BLOCK ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<>
					<BlockEdit { ...props } />
					<SegmentsPanel
						attributes={ props.attributes }
						setAttributes={ props.setAttributes }
					/>
				</>
			);
		},
	'withContentGatesSegments'
);
