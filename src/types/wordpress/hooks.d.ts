/**
 * Minimal `@wordpress/hooks` declarations (see ./element.d.ts).
 */

export function addFilter(
	hookName: string,
	namespace: string,
	callback: ( ...args: never[] ) => unknown,
	priority?: number
): void;
