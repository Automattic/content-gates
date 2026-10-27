/**
 * Minimal `@wordpress/i18n` declarations (see ./element.d.ts).
 */

export function __( text: string, domain?: string ): string;
export function sprintf(
	format: string,
	...args: ( string | number )[]
): string;
