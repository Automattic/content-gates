/**
 * Content Gates editor script.
 *
 * Adds the gating attributes to `core/group` on the client and the
 * "Segments" panel to its settings sidebar. Enforcement is server-side
 * (`inc/rendering/class-block-gate.php`); this script only edits the
 * attributes the server reads.
 */

import { addFilter } from '@wordpress/hooks';

import { GATE_ATTRIBUTES, GROUP_BLOCK } from './attributes';
import { withSegmentsControl } from './with-segments-control';

interface BlockSettings {
	attributes?: Record< string, unknown >;
}

/**
 * Add the gating attributes to the Group block type.
 *
 * The server registers the same attributes through
 * `register_block_type_args`; registering them here too keeps the editor's
 * block definition complete even before the server settings are merged.
 *
 * @param settings Block type settings.
 * @param name     Block name.
 * @return Settings with the attributes added for `core/group`.
 */
export function addGroupAttributes(
	settings: BlockSettings,
	name: string
): BlockSettings {
	if ( name !== GROUP_BLOCK ) {
		return settings;
	}

	return {
		...settings,
		attributes: {
			...( settings.attributes ?? {} ),
			...GATE_ATTRIBUTES,
		},
	};
}

addFilter(
	'blocks.registerBlockType',
	'content-gates/group-attributes',
	addGroupAttributes
);
addFilter(
	'editor.BlockEdit',
	'content-gates/segments-control',
	withSegmentsControl
);
