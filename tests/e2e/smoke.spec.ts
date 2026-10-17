import { test, expect } from '@wordpress/e2e-test-utils-playwright';

interface PluginRecord {
	plugin: string;
	status: string;
}

test.describe( 'smoke', () => {
	test( 'front end responds', async ( { page } ) => {
		const response = await page.goto( '/' );
		expect( response?.status() ).toBe( 200 );
	} );

	test( 'plugin is active', async ( { requestUtils } ) => {
		const plugins: PluginRecord[] = await requestUtils.rest( {
			path: '/wp/v2/plugins',
		} );
		const plugin = plugins.find(
			( record ) => record.plugin === 'content-gates/content-gates'
		);
		expect( plugin?.status ).toBe( 'active' );
	} );
} );
