import { defineConfig, devices } from '@playwright/test';

/**
 * Base URL of the wp-env TESTS site.
 *
 * Pass WP_BASE_URL when autoPort moved the site off :8889, or when another
 * project's wp-env holds :8889 (run `npm run doctor` to find out). The
 * seed still runs against our container unless CONTENT_GATES_E2E_EXTERNAL=1
 * says the target is not ours; see tests/e2e/global-setup.ts.
 */
const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:8889';

export default defineConfig( {
	testDir: './tests/e2e',
	globalSetup: './tests/e2e/global-setup.ts',
	outputDir: './artifacts/test-results',
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : 'list',
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	// PHPUnit and E2E share one database per env; keep E2E serial for now.
	workers: 1,
	timeout: 60_000,
	use: {
		baseURL,
		storageState: 'artifacts/storage-states/admin.json',
		trace: 'retain-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	webServer: {
		command: 'npm run env:tests start',
		url: baseURL,
		timeout: 300_000,
		// If something already answers on baseURL, Playwright uses it as-is.
		reuseExistingServer: true,
	},
} );
