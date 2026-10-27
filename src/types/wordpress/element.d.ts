/**
 * Minimal `@wordpress/element` declarations.
 *
 * Why these shims exist: WordPress provides the `@wordpress/*` packages and
 * the React JSX runtime as browser globals (`wp.*`, `ReactJSXRuntime`).
 * `wp-scripts build` rewrites the imports to those globals, so the packages
 * are not npm dependencies of this plugin and their type declarations are
 * not installed. `tsconfig.json` maps `@wordpress/*` and `react/jsx-runtime`
 * to the files in `src/types/`. Each file declares only the surface `src/`
 * uses. If a `@wordpress/*` package is added to `package.json` later, delete
 * its shim and the `paths` entry so the real declarations take over.
 */

export interface ReactElement {
	type: unknown;
	props: unknown;
	key: string | null;
}

export type ReactNode =
	| ReactElement
	| string
	| number
	| boolean
	| null
	| undefined
	| Iterable< ReactNode >;

export type ComponentType< P = Record< string, never > > = (
	props: P
) => ReactElement | null;

export type SetStateAction< S > = S | ( ( previous: S ) => S );

export function useState< S >(
	initial: S | ( () => S )
): [ S, ( value: SetStateAction< S > ) => void ];

export function useEffect(
	effect: () => void | ( () => void ),
	deps?: readonly unknown[]
): void;

export const Fragment: ComponentType< { children?: ReactNode } >;
