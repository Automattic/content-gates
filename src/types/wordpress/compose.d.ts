/**
 * Minimal `@wordpress/compose` declarations (see ./element.d.ts).
 */

import type { ComponentType } from '@wordpress/element';

export function createHigherOrderComponent< P >(
	mapComponent: ( Inner: ComponentType< P > ) => ComponentType< P >,
	modifierName: string
): ( Inner: ComponentType< P > ) => ComponentType< P >;
