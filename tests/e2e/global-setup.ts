import { request, type FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Authenticate once as admin, persist the storage state, and make sure the
 * plugin is active before any spec runs.
 *
 * @param config Resolved Playwright config.
 */
export default async function globalSetup(
	config: FullConfig
): Promise< void > {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
		baseURL,
	} );

	await requestUtils.setupRest();
	// PHPUnit's install step leaves the shared tests database pointing at the
	// test suite's nonexistent "default" theme, which 500s every page. Pin a
	// real theme so the suites can run back to back.
	await requestUtils.activateTheme( 'twentytwentyfive' );
	await requestUtils.activatePlugin( 'content-gates' );
	await setPrettyPermalinks( requestContext );
	await requestContext.dispose();
}

/**
 * Switch the site to `/%postname%/` permalinks.
 *
 * PHPUnit's install step resets the structure to "plain", which breaks
 * `/wp-json/`, `/feed/` and `<permalink>embed/` routing. The REST settings
 * endpoint does not expose `permalink_structure`, so this submits the
 * Permalinks admin form with the logged-in request context (the context
 * already carries the admin cookies after `setupRest()`).
 *
 * @param requestContext Logged-in request context.
 */
async function setPrettyPermalinks(
	requestContext: Awaited< ReturnType< typeof request.newContext > >
): Promise< void > {
	const formPage = await requestContext.get(
		'/wp-admin/options-permalink.php'
	);
	const html = await formPage.text();
	const nonce = html.match(
		/id="_wpnonce"[^>]*value="([^"]+)"|name="_wpnonce"[^>]*value="([^"]+)"/
	);
	const nonceValue = nonce?.[ 1 ] ?? nonce?.[ 2 ];

	if ( ! nonceValue ) {
		throw new Error(
			'global-setup: could not read the Permalinks form nonce'
		);
	}

	const response = await requestContext.post(
		'/wp-admin/options-permalink.php',
		{
			form: {
				_wpnonce: nonceValue,
				_wp_http_referer: '/wp-admin/options-permalink.php',
				selection: 'custom',
				permalink_structure: '/%postname%/',
				category_base: '',
				tag_base: '',
			},
		}
	);

	if ( ! response.ok() ) {
		throw new Error(
			`global-setup: Permalinks form returned ${ response.status() }`
		);
	}

	// Prove the rewrite rules are live before any spec depends on them.
	const probe = await requestContext.get( '/wp-json/', {
		maxRedirects: 0,
	} );
	if ( probe.status() !== 200 ) {
		throw new Error(
			`global-setup: /wp-json/ returned ${ probe.status() } after setting permalinks`
		);
	}
}
