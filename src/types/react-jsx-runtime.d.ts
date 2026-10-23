/**
 * Minimal `react/jsx-runtime` declarations (see ./wordpress/element.d.ts).
 *
 * `wp-scripts` compiles JSX with the automatic runtime, which WordPress
 * serves as the `react-jsx-runtime` script.
 */

import type {
	ComponentType,
	ReactElement,
	ReactNode,
} from '@wordpress/element';

export function jsx(
	type: unknown,
	props: unknown,
	key?: string
): ReactElement;
export function jsxs(
	type: unknown,
	props: unknown,
	key?: string
): ReactElement;
export const Fragment: ComponentType< { children?: ReactNode } >;
