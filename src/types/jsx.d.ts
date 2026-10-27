/**
 * Global JSX namespace for `tsc` (see ./wordpress/element.d.ts).
 *
 * Lists only the intrinsic elements `src/` renders.
 */

import type { ReactElement, ReactNode } from '@wordpress/element';

interface HTMLAttributes {
	className?: string;
	children?: ReactNode;
}

declare global {
	namespace JSX {
		type Element = ReactElement;

		interface ElementChildrenAttribute {
			children: unknown;
		}

		interface IntrinsicAttributes {
			key?: string | number;
		}

		interface IntrinsicElements {
			div: HTMLAttributes;
			p: HTMLAttributes;
			span: HTMLAttributes;
		}
	}
}
