/**
 * Minimal `@wordpress/block-editor` declarations (see ./element.d.ts).
 */

import type { ComponentType, ReactNode } from '@wordpress/element';

export const InspectorControls: ComponentType< {
	group?: string;
	children?: ReactNode;
} >;
