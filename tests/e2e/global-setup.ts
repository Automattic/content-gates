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
	await requestContext.dispose();
}
