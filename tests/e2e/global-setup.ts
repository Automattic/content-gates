import { execFileSync } from 'node:child_process';
import { request, type FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Authenticate once as admin, persist the storage state, make sure the
 * plugin is active, and seed the demo segments and posts the specs assert on.
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

	// Seed through WP-CLI in the tests container. Skipped when the base URL
	// was pointed at another site (WP_BASE_URL), where the container is not
	// ours; that site must already carry the seed.
	if ( ! process.env.WP_BASE_URL ) {
		execFileSync(
			'npx',
			[
				'wp-env',
				'--config=.wp-env.tests.json',
				'run',
				'cli',
				'--env-cwd=wp-content/plugins/content-gates',
				'wp',
				'content-gates-dev',
				'seed',
			],
			{ stdio: 'inherit' }
		);
	}
}
