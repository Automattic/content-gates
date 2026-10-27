/**
 * Minimal `@wordpress/api-fetch` declarations (see ./element.d.ts).
 */

export interface APIFetchOptions {
	path: string;
	method?: string;
}

export default function apiFetch< T = unknown >(
	options: APIFetchOptions
): Promise< T >;
