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
	// The example provider (examples/segment-provider, by its plugin name) is mapped by
	// .wp-env.tests.json; PHPUnit's reset deactivates it too.
	await requestUtils.activatePlugin( 'content-gates-example-provider' );
	await requestContext.dispose();

	// Seed through WP-CLI in the tests container. WP_BASE_URL alone only
	// moves the URL (autoPort may have moved our own site off :8889), so the
	// seed still runs. Only CONTENT_GATES_E2E_EXTERNAL=1 says the site is
	// not our container; it must then already carry the seed.
	if ( ! process.env.CONTENT_GATES_E2E_EXTERNAL ) {
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
