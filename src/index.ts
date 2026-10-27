/**
 * Content Gates editor script.
 *
 * Adds the "Segments" panel to the Group block's settings sidebar. The
 * attributes it edits are registered on the server
 * (`inc/rendering/class-block-gate.php`), which also enforces them; the
 * editor receives them with the server's block definition.
 */

import { addFilter } from '@wordpress/hooks';

import { withSegmentsControl } from './with-segments-control';

addFilter(
	'editor.BlockEdit',
	'content-gates/segments-control',
	withSegmentsControl
);
