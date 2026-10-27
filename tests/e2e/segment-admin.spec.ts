import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Settings > Content Gates: the segment list editor.
 */
test.describe( 'segment admin', () => {
	const name = `E2E Segment ${ Date.now() % 100000 }`;

	test( 'creates, renames, deactivates, and reactivates a segment without a delete action', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			'options-general.php',
			'page=content-gates'
		);
		await expect(
			page.getByRole( 'heading', { name: 'Content Gates', level: 1 } )
		).toBeVisible();

		// Seeded rows come by type, then key: readers, crawlers, meters.
		const seededKeys = await page
			.locator( '#content-gates-segments tr[data-key]' )
			.evaluateAll( ( rows ) =>
				rows.map( ( row ) => row.getAttribute( 'data-key' ) )
			);
		// Earlier runs of this spec leave their own segments behind.
		expect(
			seededKeys.filter( ( key ) => ! key?.startsWith( 'e2e-segment-' ) )
		).toEqual( [
			'donor',
			'newsletter',
			'subscriber',
			'crawlers-ai',
			'crawlers-search',
			'metered-articles',
		] );

		// Create.
		await page.getByLabel( 'Name', { exact: true } ).fill( name );
		await page.getByRole( 'button', { name: 'Add segment' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText(
			'Segment created.'
		);
		const row = page.locator( '#content-gates-segments tr', {
			hasText: name,
		} );
		await expect( row ).toBeVisible();
		const key = await row.getAttribute( 'data-key' );
		expect( key ).toMatch( /^e2e-segment-\d+$/ );
		await expect( row ).toContainText( 'Active' );
		await expect(
			row.getByRole( 'button', { name: /delete|trash/i } )
		).toHaveCount( 0 );

		// Rename keeps the key.
		await row.getByLabel( 'New name' ).fill( `${ name } renamed` );
		await row.getByRole( 'button', { name: 'Rename' } ).click();
		const renamed = page.locator(
			`#content-gates-segments tr[data-key="${ key }"]`
		);
		await expect( renamed ).toContainText( `${ name } renamed` );
		await expect( renamed.locator( 'code' ).first() ).toHaveText( key! );

		// Deactivate, reactivate.
		await renamed.getByRole( 'button', { name: 'Deactivate' } ).click();
		await expect(
			page.locator( `#content-gates-segments tr[data-key="${ key }"]` )
		).toHaveAttribute( 'data-active', '0' );
		await page
			.locator( `#content-gates-segments tr[data-key="${ key }"]` )
			.getByRole( 'button', { name: 'Activate' } )
			.click();
		await expect(
			page.locator( `#content-gates-segments tr[data-key="${ key }"]` )
		).toHaveAttribute( 'data-active', '1' );

		// Crawler segments are listed read-only.
		const crawler = page.locator(
			'#content-gates-segments tr[data-key="crawlers-search"]'
		);
		await expect( crawler ).toContainText( 'VIP-maintained' );
		await expect( crawler.getByRole( 'button' ) ).toHaveCount( 0 );
	} );

	test( 'the segments REST route is not public', async ( {
		requestUtils,
		baseURL,
	} ) => {
		const authed: { key: string }[] = await requestUtils.rest( {
			path: '/content-gates/v1/segments',
		} );
		expect( authed.map( ( s ) => s.key ) ).toContain( 'subscriber' );

		const response = await fetch(
			`${ baseURL }/wp-json/content-gates/v1/segments`
		);
		expect( response.status ).toBe( 401 );
	} );
} );
