/**
 * ESLint flat config: the `@wordpress/scripts` defaults plus project settings.
 */
const wpConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );
const path = require( 'node:path' );

/**
 * Packages WordPress serves as browser globals (`wp.*`, `ReactJSXRuntime`).
 * `wp-scripts build` rewrites these imports to the globals and lists them in
 * `build/index.asset.php`, so they are not npm dependencies of the plugin.
 */
const wordpressProvidedModules = [
	'@wordpress/api-fetch',
	'@wordpress/block-editor',
	'@wordpress/components',
	'@wordpress/compose',
	'@wordpress/element',
	'@wordpress/hooks',
	'@wordpress/i18n',
	'react/jsx-runtime',
];

module.exports = [
	...wpConfig,
	{
		files: [ 'src/**/*.{ts,tsx}' ],
		settings: {
			'import/core-modules': wordpressProvidedModules,
		},
		rules: {
			// The plugin's text domain (see content-gates.php).
			'@wordpress/i18n-text-domain': [
				'error',
				{ allowedTextDomain: 'content-gates' },
			],
		},
	},
	{
		// edge-workers/ is the VIP CLI's project layout (`vip edge-workers
		// init`). It is its own npm package: the SDK and AssemblyScript are
		// its dependencies, not the plugin's, so the rule resolves imports
		// against both manifests. The tests under it are Node code that
		// imports only node: builtins and the shared modules.
		files: [ 'edge-workers/**/*.{ts,mjs}' ],
		rules: {
			'import/no-extraneous-dependencies': [
				'error',
				{
					devDependencies: true,
					peerDependencies: true,
					includeTypes: true,
					packageDir: [
						path.resolve( __dirname ),
						path.resolve( __dirname, 'edge-workers' ),
					],
				},
			],
		},
	},
	{
		// Modules shared by the AssemblyScript worker and Node must compile
		// under both. AssemblyScript compares strings with `==` by value and
		// with `===` by identity, so `===` would be a bug there.
		files: [ 'edge-workers/lib/**/*.ts', 'edge-workers/workers/**/*.ts' ],
		rules: {
			eqeqeq: 'off',
		},
	},
	{
		// Edge and editor unit tests run on node:test, so they need no
		// framework beyond Node; the preset assumes vitest for *.test.ts.
		files: [ 'edge-workers/tests/**/*.ts', 'tests/js/**/*.ts' ],
		rules: {
			'vitest/no-import-node-test': 'off',
		},
	},
];
