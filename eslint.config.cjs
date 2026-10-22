/**
 * ESLint flat config: the `@wordpress/scripts` defaults plus project settings.
 */
const wpConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

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
];
